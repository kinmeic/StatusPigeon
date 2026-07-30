// Package main: ingest.go — 上报数据的入库流程。
package main

import (
	pkgmetrics "github.com/statuspigeon/metrics"
)

// Ingest 处理一次上报（push 或 pull）：Judge 判定状态后，
// 在单个事务中完成 upsert host → 写原始指标 → 更新当天聚合。
// source 标记 "push" / "pull"。
func Ingest(store *Store, judge *Judge, r *pkgmetrics.Report, source string) error {
	return store.Ingest(r, source, judge.Status(r.Metrics))
}
