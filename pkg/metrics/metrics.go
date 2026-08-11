// Package metrics defines the Agent and Hub shared metric data structures.
//
// The same structures are used for push reports and pull (GET /metrics)
// responses so both transport modes share one contract.
package metrics

// OsInfo contains basic system information.
type OsInfo struct {
	Os          string   `json:"os"`
	Version     string   `json:"version"`
	Kernel      string   `json:"kernel"`
	Arch        string   `json:"arch"`
	Uptime      uint64   `json:"uptime"` // 秒
	CPUModel    string   `json:"cpu_model"`
	MemoryTotal uint64   `json:"memory_total"`  // 字节
	DiskTotal   uint64   `json:"disk_total"`    // 字节
	DiskUsedPct float64  `json:"disk_used_pct"` // %
	IPv4        []string `json:"ipv4"`          // Non-loopback IPv4 addresses, optionally suffixed with @interface.
	IPv6        []string `json:"ipv6"`          // Non-loopback IPv6 addresses, optionally suffixed with @interface.
}

// CPUInfo contains system load averages. CPU percentage is intentionally not
// part of the report: a short instantaneous sample is too noisy to be useful.
type CPUInfo struct {
	Load1  float64 `json:"load1"`
	Load5  float64 `json:"load5"`
	Load15 float64 `json:"load15"`
}

// MemInfo contains memory and swap usage.
type MemInfo struct {
	Total     uint64  `json:"total"`     // 字节
	Used      uint64  `json:"used"`      // 字节
	Available uint64  `json:"available"` // 字节
	UsedPct   float64 `json:"used_pct"`  // %
	SwapTotal uint64  `json:"swap_total"`
	SwapUsed  uint64  `json:"swap_used"`
}

// Metrics is the complete metric set for one report.  Disk and network
// throughput are intentionally excluded: a single instantaneous sample is
// not meaningful as a long-term trend metric.
type Metrics struct {
	Os  OsInfo  `json:"os"`
	Cpu CPUInfo `json:"cpu"`
	Mem MemInfo `json:"mem"`
}

// Report is the complete JSON structure for one push or pull report.
type Report struct {
	AgentVersion string  `json:"agent_version"`
	DeviceID     string  `json:"device_id"` // Stable device identity; not the display name.
	Hostname     string  `json:"hostname"`
	Timestamp    int64   `json:"timestamp"` // Unix 秒
	Metrics      Metrics `json:"metrics"`
}
