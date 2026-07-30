// Package main: ingest.go — push 上报数据的入库流程。
package main

import (
	"fmt"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// Ingest 处理一次 push 上报：upsert host → 写原始指标 → 更新当天聚合。
// status 由 Judge 判定后写入；source 标记 "push"。
func Ingest(store *Store, judge *Judge, r *pkgmetrics.Report) error {
	hostID, err := store.UpsertHost(r, "push")
	if err != nil {
		return fmt.Errorf("upsert host: %w", err)
	}
	status := judge.Status(r.Metrics)

	// 同步刷新 hosts.last_status/last_summary（UpsertHost 默认写 operational，此处按判定校正）。
	if err := store.SetHostLive(hostID, status, r); err != nil {
		return err
	}
	if err := store.InsertMetrics(hostID, r, status); err != nil {
		return fmt.Errorf("insert metrics: %w", err)
	}
	if err := store.UpdateDailyAgg(hostID, r.Timestamp, status); err != nil {
		return fmt.Errorf("update daily: %w", err)
	}
	return nil
}
