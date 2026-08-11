// Package main: collector.go — collect system, CPU and memory metrics.
//
// Collected values include:
//   - system: OS, kernel, architecture, uptime and IP addresses
//   - CPU: load averages and usage percentage
//   - memory: total, used, available and swap
//
// Disk and network throughput are deliberately not sampled: a single
// instantaneous rate is not useful as a long-term status trend.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net"
	"os"
	"runtime"
	"sort"
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

// Collector collects the stable system, CPU and memory metrics.
type Collector struct {
	hostname string
	deviceID string
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
	return &Collector{hostname: hostname, deviceID: stableDeviceID(hostname)}, nil
}

// Hostname returns the collector hostname.
func (c *Collector) Hostname() string { return c.hostname }

// Collect gathers one complete report.
func (c *Collector) Collect() (*pkgmetrics.Report, error) {
	report := &pkgmetrics.Report{
		AgentVersion: AgentVersion,
		DeviceID:     c.deviceID,
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

// stableDeviceID returns a non-secret hash of the most hardware-specific
// identity available on the host.  Device-tree/DMI identifiers are preferred;
// machine-id and a stable interface MAC are portable fallbacks.  The Hub uses
// this value as the identity key while keeping Hostname user-editable.
func stableDeviceID(hostname string) string {
	paths := []string{
		"/sys/firmware/devicetree/base/serial-number",
		"/proc/device-tree/serial-number",
		"/sys/class/dmi/id/product_uuid",
		"/sys/devices/virtual/dmi/id/product_uuid",
		"/etc/machine-id",
	}
	for _, path := range paths {
		contents, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		value := strings.Trim(strings.TrimSpace(string(contents)), "\x00")
		if value != "" {
			return hashDeviceIdentity(path + ":" + value)
		}
	}

	var macs []string
	if interfaces, err := net.Interfaces(); err == nil {
		for _, iface := range interfaces {
			if iface.Flags&net.FlagLoopback != 0 || len(iface.HardwareAddr) == 0 {
				continue
			}
			macs = append(macs, strings.ToLower(iface.HardwareAddr.String()))
		}
	}
	if len(macs) > 0 {
		sort.Strings(macs)
		return hashDeviceIdentity("mac:" + macs[0])
	}

	// This last-resort path is stable for a configured hostname but is not
	// expected on normal OpenWrt/Linux systems with one of the sources above.
	return hashDeviceIdentity("hostname:" + hostname)
}

func hashDeviceIdentity(value string) string {
	sum := sha256.Sum256([]byte(value))
	return "sha256:" + hex.EncodeToString(sum[:])
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
	out.Version = info.PlatformVersion
	out.Kernel = info.KernelVersion
	out.Arch = runtime.GOARCH
	out.Uptime = info.Uptime
	out.IPv4, out.IPv6 = localIPs()
	return nil
}

// localIPs returns non-loopback, non-link-local IPv4 and IPv6 addresses.
// Values include the logical interface name (for example
// "192.168.0.68@usbwan") and WAN-like interfaces are ordered first.
func localIPs() (ipv4, ipv6 []string) {
	interfaces, err := net.Interfaces()
	if err != nil {
		return nil, nil
	}
	type interfaceIP struct {
		name     string
		address  string
		priority int
		ipv4     bool
	}
	entries := make([]interfaceIP, 0)
	for _, iface := range interfaces {
		if iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, addrErr := iface.Addrs()
		if addrErr != nil {
			continue
		}
		for _, addr := range addrs {
			ip := interfaceAddressIP(addr)
			if ip == nil || ip.IsLoopback() || ip.IsLinkLocalUnicast() {
				continue // 排除 loopback/link-local（IPv4 169.254/IPv6 fe80::）
			}
			if v4 := ip.To4(); v4 != nil {
				entries = append(entries, interfaceIP{
					name: iface.Name, address: v4.String(),
					priority: interfacePriority(iface.Name), ipv4: true,
				})
				continue
			}
			entries = append(entries, interfaceIP{
				name: iface.Name, address: ip.String(),
				priority: interfacePriority(iface.Name),
			})
		}
	}
	sort.SliceStable(entries, func(i, j int) bool {
		return entries[i].priority < entries[j].priority
	})
	seenIPv4 := make(map[string]bool)
	seenIPv6 := make(map[string]bool)
	for _, entry := range entries {
		value := entry.address
		if entry.name != "" {
			value += "@" + entry.name
		}
		if entry.ipv4 {
			if !seenIPv4[entry.address] {
				seenIPv4[entry.address] = true
				ipv4 = append(ipv4, value)
			}
		} else {
			key := strings.ToLower(entry.address)
			if !seenIPv6[key] {
				seenIPv6[key] = true
				ipv6 = append(ipv6, value)
			}
		}
	}
	return ipv4, ipv6
}

func interfaceAddressIP(addr net.Addr) net.IP {
	switch value := addr.(type) {
	case *net.IPNet:
		return value.IP
	case *net.IPAddr:
		return value.IP
	default:
		text := addr.String()
		if slash := strings.IndexByte(text, '/'); slash >= 0 {
			text = text[:slash]
		}
		return net.ParseIP(text)
	}
}

func interfacePriority(name string) int {
	name = strings.ToLower(name)
	if strings.Contains(name, "wan") || strings.Contains(name, "wwan") {
		return 0
	}
	if strings.Contains(name, "lan") {
		return 1
	}
	return 2
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
