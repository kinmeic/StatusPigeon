// Package main: collector.go — collect system, CPU, memory and I/O metrics.
//
// Collected values include:
//   - system: OS, kernel, architecture, uptime and IP addresses
//   - CPU: load averages and usage percentage
//   - memory: total, used, available and swap
//   - disk and network counters plus rates calculated between samples
package main

import (
	"fmt"
	"net"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/disk"
	"github.com/shirou/gopsutil/v3/host"
	"github.com/shirou/gopsutil/v3/load"
	"github.com/shirou/gopsutil/v3/mem"
	gopsutilnet "github.com/shirou/gopsutil/v3/net"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// AgentVersion 编译期注入（见 Makefile -ldflags）。
var AgentVersion = "dev"

// Collector collects metrics. I/O counters are retained between samples so
// the report can include bytes-per-second values without adding a second
// blocking sampling interval.
type Collector struct {
	hostname string
	ioMu     sync.Mutex
	previous *ioSnapshot
}

type ioSnapshot struct {
	at        time.Time
	diskRead  uint64
	diskWrite uint64
	netRx     uint64
	netTx     uint64
}

// NewCollector creates a collector. An empty hostname uses the system name.
func NewCollector(hostname string) (*Collector, error) {
	if hostname == "" {
		info, err := host.Info()
		if err != nil || info.Hostname == "" {
			return nil, fmt.Errorf("无法获取主机名: %w", err)
		}
		hostname = info.Hostname
	}
	return &Collector{hostname: hostname}, nil
}

// Hostname returns the collector hostname.
func (c *Collector) Hostname() string { return c.hostname }

// Collect gathers one complete report.
func (c *Collector) Collect() (*pkgmetrics.Report, error) {
	report := &pkgmetrics.Report{
		AgentVersion: AgentVersion,
		Hostname:     c.hostname,
		Timestamp:    time.Now().Unix(),
	}
	if err := collectOs(&report.Metrics.Os); err != nil {
		return nil, err
	}
	if err := collectCPU(&report.Metrics.Cpu); err != nil {
		return nil, err
	}
	if err := collectMem(&report.Metrics.Mem); err != nil {
		return nil, err
	}
	c.collectIO(&report.Metrics.Disk, &report.Metrics.Network)
	return report, nil
}

func collectOs(out *pkgmetrics.OsInfo) error {
	info, err := host.Info()
	if err != nil {
		return fmt.Errorf("采集 host 信息: %w", err)
	}
	// Platform 是发行版名（如 "centos"/"openwrt"），更友好。
	osName := strings.TrimSpace(info.Platform)
	if osName == "" {
		osName = runtime.GOOS
	}
	out.Os = osName
	out.Kernel = info.KernelVersion
	out.Arch = runtime.GOARCH
	out.Uptime = info.Uptime
	out.IPv4, out.IPv6 = localIPs()
	return nil
}

// localIPs returns non-loopback, non-link-local IPv4 and IPv6 addresses.
func localIPs() (ipv4, ipv6 []string) {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil, nil
	}
	for _, a := range addrs {
		ipNet, ok := a.(*net.IPNet)
		if !ok || ipNet.IP.IsLoopback() {
			continue
		}
		ip := ipNet.IP
		if ip.IsLinkLocalUnicast() {
			continue // 排除 link-local（IPv4 169.254/IPv6 fe80::）
		}
		if v4 := ip.To4(); v4 != nil {
			ipv4 = append(ipv4, v4.String())
		} else {
			ipv6 = append(ipv6, ip.String())
		}
	}
	return ipv4, ipv6
}

func collectCPU(out *pkgmetrics.CPUInfo) error {
	// 负载均值（Linux/macOS；Windows 上 Avg() 返回错误，置零忽略）。
	if avg, err := load.Avg(); err == nil {
		out.Load1 = avg.Load1
		out.Load5 = avg.Load5
		out.Load15 = avg.Load15
	}
	// CPU 使用率：取 500ms 间隔两次采样的整体平均（false => 整体平均）。
	percent, err := cpu.Percent(500*time.Millisecond, false)
	if err != nil {
		return fmt.Errorf("采集 CPU 使用率: %w", err)
	}
	if len(percent) > 0 {
		out.Usage = percent[0]
	}
	return nil
}

func collectMem(out *pkgmetrics.MemInfo) error {
	vm, err := mem.VirtualMemory()
	if err != nil {
		return fmt.Errorf("采集内存: %w", err)
	}
	out.Total = vm.Total
	out.Used = vm.Used
	out.Available = vm.Available
	out.UsedPct = vm.UsedPercent
	if sm, err := mem.SwapMemory(); err == nil {
		out.SwapTotal = sm.Total
		out.SwapUsed = sm.Used
	}
	return nil
}

func (c *Collector) collectIO(diskOut *pkgmetrics.DiskIOInfo, networkOut *pkgmetrics.NetworkIOInfo) {
	snapshot := ioSnapshot{at: time.Now()}
	if counters, err := disk.IOCounters(); err == nil {
		for _, counter := range counters {
			snapshot.diskRead += counter.ReadBytes
			snapshot.diskWrite += counter.WriteBytes
		}
	}
	if counters, err := gopsutilnet.IOCounters(false); err == nil && len(counters) > 0 {
		snapshot.netRx = counters[0].BytesRecv
		snapshot.netTx = counters[0].BytesSent
	}

	diskOut.ReadBytes = snapshot.diskRead
	diskOut.WriteBytes = snapshot.diskWrite
	networkOut.RxBytes = snapshot.netRx
	networkOut.TxBytes = snapshot.netTx

	c.ioMu.Lock()
	if c.previous != nil {
		seconds := snapshot.at.Sub(c.previous.at).Seconds()
		if seconds > 0 {
			diskOut.ReadBps = counterRate(snapshot.diskRead, c.previous.diskRead, seconds)
			diskOut.WriteBps = counterRate(snapshot.diskWrite, c.previous.diskWrite, seconds)
			networkOut.RxBps = counterRate(snapshot.netRx, c.previous.netRx, seconds)
			networkOut.TxBps = counterRate(snapshot.netTx, c.previous.netTx, seconds)
		}
	}
	c.previous = &snapshot
	c.ioMu.Unlock()
}

func counterRate(current, previous uint64, seconds float64) float64 {
	if current <= previous || seconds <= 0 {
		return 0
	}
	return float64(current-previous) / seconds
}
