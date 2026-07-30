// Package metrics 定义 Agent 与 Hub 共享的指标数据结构。
//
// 这套结构既是 push 上报体，也是 pull（GET /metrics）响应体，
// 保证两端契约一致。
package metrics

// OsInfo 基础系统信息。
type OsInfo struct {
	Os     string   `json:"os"`
	Kernel string   `json:"kernel"`
	Arch   string   `json:"arch"`
	Uptime uint64   `json:"uptime"` // 秒
	IPv4   []string `json:"ipv4"`   // 本机非回环 IPv4 地址（获取不到为空）
	IPv6   []string `json:"ipv6"`   // 本机非回环 IPv6 地址（获取不到为空）
}

// CPUInfo CPU 与负载。
type CPUInfo struct {
	Load1  float64 `json:"load1"`
	Load5  float64 `json:"load5"`
	Load15 float64 `json:"load15"`
	Usage  float64 `json:"usage"` // %
}

// MemInfo 内存。
type MemInfo struct {
	Total     uint64  `json:"total"`     // 字节
	Used      uint64  `json:"used"`      // 字节
	Available uint64  `json:"available"` // 字节
	UsedPct   float64 `json:"used_pct"`  // %
	SwapTotal uint64  `json:"swap_total"`
	SwapUsed  uint64  `json:"swap_used"`
}

// Metrics 汇总三类指标。
type Metrics struct {
	Os  OsInfo  `json:"os"`
	Cpu CPUInfo `json:"cpu"`
	Mem MemInfo `json:"mem"`
}

// Report 是 push 模式单次上报的完整 JSON 结构；
// pull 模式（GET /metrics）同样返回此结构。
type Report struct {
	AgentVersion string  `json:"agent_version"`
	Hostname     string  `json:"hostname"`
	Timestamp    int64   `json:"timestamp"` // Unix 秒
	Metrics      Metrics `json:"metrics"`
}
