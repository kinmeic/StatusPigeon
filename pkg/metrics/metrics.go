// Package metrics defines the Agent and Hub shared metric data structures.
//
// The same structures are used for push reports and pull (GET /metrics)
// responses so both transport modes share one contract.
package metrics

// OsInfo contains basic system information.
type OsInfo struct {
	Os     string   `json:"os"`
	Kernel string   `json:"kernel"`
	Arch   string   `json:"arch"`
	Uptime uint64   `json:"uptime"` // 秒
	IPv4   []string `json:"ipv4"`   // Non-loopback IPv4 addresses.
	IPv6   []string `json:"ipv6"`   // Non-loopback IPv6 addresses.
}

// CPUInfo contains CPU usage and load averages.
type CPUInfo struct {
	Load1  float64 `json:"load1"`
	Load5  float64 `json:"load5"`
	Load15 float64 `json:"load15"`
	Usage  float64 `json:"usage"` // %
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

// DiskIOInfo contains cumulative disk counters and their sampling rate.
type DiskIOInfo struct {
	ReadBytes  uint64  `json:"read_bytes"`
	WriteBytes uint64  `json:"write_bytes"`
	ReadBps    float64 `json:"read_bps"`
	WriteBps   float64 `json:"write_bps"`
}

// NetworkIOInfo contains cumulative network counters and their sampling rate.
type NetworkIOInfo struct {
	RxBytes uint64  `json:"rx_bytes"`
	TxBytes uint64  `json:"tx_bytes"`
	RxBps   float64 `json:"rx_bps"`
	TxBps   float64 `json:"tx_bps"`
}

// Metrics is the complete metric set for one report.
type Metrics struct {
	Os      OsInfo        `json:"os"`
	Cpu     CPUInfo       `json:"cpu"`
	Mem     MemInfo       `json:"mem"`
	Disk    DiskIOInfo    `json:"disk"`
	Network NetworkIOInfo `json:"network"`
}

// Report is the complete JSON structure for one push or pull report.
type Report struct {
	AgentVersion string  `json:"agent_version"`
	Hostname     string  `json:"hostname"`
	Timestamp    int64   `json:"timestamp"` // Unix 秒
	Metrics      Metrics `json:"metrics"`
}
