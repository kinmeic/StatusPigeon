// Package main: collector.go — 采集基础 / CPU / 内存指标。
//
// 采集项（精简版）：
//   - 基础：os, kernel_version, arch, uptime
//   - CPU：load_avg(1/5/15min), usage_percent
//   - 内存：total/used/available/used_pct, swap_total/swap_used
package main

import (
	"fmt"
	"net"
	"runtime"
	"strings"
	"time"

	"github.com/shirou/gopsutil/v3/cpu"
	"github.com/shirou/gopsutil/v3/host"
	"github.com/shirou/gopsutil/v3/load"
	"github.com/shirou/gopsutil/v3/mem"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// AgentVersion 编译期注入（见 Makefile -ldflags）。
var AgentVersion = "dev"

// Collector 负责采集。
type Collector struct {
	hostname string
}

// NewCollector 创建采集器。hostname 为空时取系统主机名。
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

// Hostname 返回采集器绑定的主机名。
func (c *Collector) Hostname() string { return c.hostname }

// Collect 采集一次完整指标并组装成 Report。
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

// localIPs 返回本机非回环、非 link-local 的 IPv4 与 IPv6 地址（各一组）。
// 用 net 标准库，跨平台（Linux/OpenWrt/macOS/Windows 通用）。
// 获取不到时对应分组为空切片。
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
