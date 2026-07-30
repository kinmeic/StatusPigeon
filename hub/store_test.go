// Package main: store_lastinsertid_test.go — 防御性测试。
//
// 背景：SQLite UPSERT (ON CONFLICT DO UPDATE) 后 database/sql 的
// res.LastInsertId() 行为依驱动/版本而异：可能是冲突行 rowid、0，
// 或残留在同一连接上上一次 INSERT（其他表）的 rowid。
// upsertHost 曾依赖该值导致 host_id 错乱、metrics 外键失败。
// 现改为 `RETURNING id`，本测试锁定该行为，防回归。
package main

import (
	"path/filepath"
	"testing"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

func newTestReport(host string, cpu float64) *pkgmetrics.Report {
	return &pkgmetrics.Report{
		AgentVersion: "test",
		Hostname:     host,
		Timestamp:    time.Now().Unix(),
		Metrics: pkgmetrics.Metrics{
			Os:  pkgmetrics.OsInfo{Os: "linux", Kernel: "6.1", Arch: "amd64"},
			Cpu: pkgmetrics.CPUInfo{Usage: cpu, Load1: 1.5},
			Mem: pkgmetrics.MemInfo{UsedPct: 50},
		},
	}
}

func TestUpsertHostIDStability(t *testing.T) {
	s, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("NewStore: %v", err)
	}
	defer s.Close()

	judge := &Judge{CPUThreshold: 90, MemThreshold: 95}

	// 两台主机交错上报多轮：任何一轮 host_id 都不能错乱，
	// 否则 metrics 外键失败或数据挂到错误主机。
	hosts := []string{"web-1", "web-2"}
	ids := map[string]int64{}
	for round := 0; round < 5; round++ {
		for _, h := range hosts {
			if err := Ingest(s, judge, newTestReport(h, 10), "push"); err != nil {
				t.Fatalf("round %d host %s ingest: %v", round, h, err)
			}
			var id int64
			if err := s.db.QueryRow(`SELECT id FROM hosts WHERE hostname=?`, h).Scan(&id); err != nil {
				t.Fatalf("query id: %v", err)
			}
			if prev, ok := ids[h]; ok && prev != id {
				t.Fatalf("round %d host %s id 漂移: %d -> %d", round, h, prev, id)
			}
			ids[h] = id
		}
	}

	// 每台主机恰好 5 条指标，且 host_id 归属正确。
	for h, id := range ids {
		var n int
		if err := s.db.QueryRow(`SELECT COUNT(*) FROM metrics_raw WHERE host_id=?`, id).Scan(&n); err != nil {
			t.Fatalf("count metrics: %v", err)
		}
		if n != 5 {
			t.Fatalf("host %s 指标数=%d，期望 5", h, n)
		}
	}
}

func TestMarkOfflineAndRecover(t *testing.T) {
	s, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("NewStore: %v", err)
	}
	defer s.Close()

	judge := &Judge{CPUThreshold: 90, MemThreshold: 95}
	if err := Ingest(s, judge, newTestReport("db-1", 10), "push"); err != nil {
		t.Fatalf("ingest: %v", err)
	}

	// 模拟失联：last_seen 拨到过去，阈值判 down。
	old := time.Now().Add(-time.Hour).Unix()
	if _, err := s.db.Exec(`UPDATE hosts SET last_seen=?`, old); err != nil {
		t.Fatalf("backdate last_seen: %v", err)
	}
	threshold := time.Now().Add(-time.Minute).Unix()
	n, err := s.MarkOffline(threshold)
	if err != nil || n != 1 {
		t.Fatalf("MarkOffline=(%d,%v)，期望 (1,nil)", n, err)
	}
	var status string
	_ = s.db.QueryRow(`SELECT last_status FROM hosts`).Scan(&status)
	if status != statusDown {
		t.Fatalf("last_status=%s，期望 down", status)
	}
	// 再次扫描：已 down 的不重复计数，但当天聚合仍保持 down。
	n, _ = s.MarkOffline(threshold)
	if n != 0 {
		t.Fatalf("重复扫描新标记=%d，期望 0", n)
	}

	// 恢复上报：状态回到 operational，last_seen 刷新为服务端时间。
	before := time.Now().Unix()
	if err := Ingest(s, judge, newTestReport("db-1", 10), "push"); err != nil {
		t.Fatalf("recover ingest: %v", err)
	}
	var lastSeen int64
	_ = s.db.QueryRow(`SELECT last_status, last_seen FROM hosts`).Scan(&status, &lastSeen)
	if status != statusOperational {
		t.Fatalf("恢复后 last_status=%s，期望 operational", status)
	}
	if lastSeen < before {
		t.Fatalf("last_seen=%d 未刷新到服务端时间 (>=%d)", lastSeen, before)
	}
}
