// Package main: cleaner.go — 后台维护循环。
//
// 定期执行：
//   - MarkOffline：扫描失联主机，判 down 并更新当天聚合。
//   - Cleanup：删除超过保留期的数据。
package main

import (
	"context"
	"log"
	"time"
)

// RunMaintenance 运行后台维护循环。阻塞直至 ctx 取消。
func RunMaintenance(ctx context.Context, store *Store, cfg *Config) {
	offlineEvery := cfg.ReportInterval  // 与上报周期对齐检查失联
	cleanupEvery := cfg.CleanupInterval // 清理较稀疏
	retention := time.Duration(cfg.RetentionDays) * 24 * time.Hour

	offlineTicker := time.NewTicker(offlineEvery)
	defer offlineTicker.Stop()
	cleanupTicker := time.NewTicker(cleanupEvery)
	defer cleanupTicker.Stop()

	// 启动后先跑一次。
	doOffline(store, cfg)
	doCleanup(store, retention)

	for {
		select {
		case <-offlineTicker.C:
			doOffline(store, cfg)
		case <-cleanupTicker.C:
			doCleanup(store, retention)
		case <-ctx.Done():
			return
		}
	}
}

// doOffline 计算失联阈值并标记 down。
func doOffline(store *Store, cfg *Config) {
	// 失联阈值 = now - (周期 × 失联周期数)。
	threshold := time.Now().Add(-(cfg.ReportInterval * time.Duration(cfg.OfflinePeriods))).Unix()
	n, err := store.MarkOffline(threshold)
	if err != nil {
		log.Printf("失联扫描出错: %v", err)
		return
	}
	if n > 0 {
		log.Printf("失联扫描: %d 台主机标记为 down", n)
	}
}

// doCleanup 按保留期清理数据。
func doCleanup(store *Store, retention time.Duration) {
	cutoff := time.Now().Add(-retention).Unix()
	raw, daily, err := store.Cleanup(cutoff)
	if err != nil {
		log.Printf("数据清理出错: %v", err)
		return
	}
	// 仅在实际删除时记日志，避免空转噪音。
	if raw+daily > 0 {
		log.Printf("数据清理: 删除原始指标 %d 条 / 日聚合 %d 条 (保留期 %v)", raw, daily, retention)
	}
}
