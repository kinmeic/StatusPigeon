// Package main: judge.go — 状态判定。
//
// 根据指标判定单次样本状态：
//   - degraded：mem > 阈值
//   - operational：其余
//
// down（失联）由 store.MarkOffline 在后台扫描时统一处理。
package main

import (
	pkgmetrics "github.com/statuspigeon/metrics"
)

// Judge 持有阈值配置。
type Judge struct {
	CPUThreshold float64
	MemThreshold float64
}

// Status 根据单次指标判定状态。
func (j *Judge) Status(m pkgmetrics.Metrics) string {
	// CPU percentage is no longer sampled or transmitted. Keep the legacy
	// CPUThreshold field on Judge for configuration compatibility, but do not
	// use it in status decisions.
	if m.Mem.UsedPct > j.MemThreshold {
		return statusDegraded
	}
	return statusOperational
}
