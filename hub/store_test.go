// Package main: store_lastinsertid_test.go — 防御性测试。
//
// 背景：SQLite UPSERT (ON CONFLICT DO UPDATE) 后 database/sql 的
// res.LastInsertId() 行为依驱动/版本而异：可能是冲突行 rowid、0，
// 或残留在同一连接上上一次 INSERT（其他表）的 rowid。
// upsertHost 曾依赖该值导致 host_id 错乱、metrics 外键失败。
// 现改为 `RETURNING id`，本测试锁定该行为，防回归。
package main

import (
	"database/sql"
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

func newDeviceReport(deviceID, host string, cpu float64) *pkgmetrics.Report {
	report := newTestReport(host, cpu)
	report.DeviceID = deviceID
	return report
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

func TestDeviceIDOwnsHostAndHostnameIsDisplayLabel(t *testing.T) {
	s, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatalf("NewStore: %v", err)
	}
	defer s.Close()

	judge := &Judge{CPUThreshold: 90, MemThreshold: 95}
	if err := Ingest(s, judge, newDeviceReport("device-a", "router", 10), "push"); err != nil {
		t.Fatalf("first ingest: %v", err)
	}
	if err := Ingest(s, judge, newDeviceReport("device-a", "living-room-router", 20), "push"); err != nil {
		t.Fatalf("rename ingest: %v", err)
	}
	if err := Ingest(s, judge, newDeviceReport("device-b", "living-room-router", 30), "push"); err != nil {
		t.Fatalf("same hostname ingest: %v", err)
	}

	var hostCount int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM hosts`).Scan(&hostCount); err != nil {
		t.Fatalf("host count: %v", err)
	}
	if hostCount != 2 {
		t.Fatalf("host count=%d, want 2", hostCount)
	}

	var hostname string
	if err := s.db.QueryRow(`SELECT hostname FROM hosts WHERE device_id=?`, "device-a").Scan(&hostname); err != nil {
		t.Fatalf("device-a lookup: %v", err)
	}
	if hostname != "living-room-router" {
		t.Fatalf("device-a hostname=%q, want renamed display label", hostname)
	}
}

func TestMigrateHostnameKeyedSchema(t *testing.T) {
	path := filepath.Join(t.TempDir(), "legacy.db")
	db, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatalf("open legacy db: %v", err)
	}
	legacySchema := `
CREATE TABLE hosts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT UNIQUE NOT NULL,
    agent_version TEXT,
    os TEXT,
    kernel TEXT,
    arch TEXT,
    last_seen INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_status TEXT,
    last_summary TEXT,
    source TEXT NOT NULL DEFAULT 'push'
);
CREATE TABLE metrics_raw (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id INTEGER NOT NULL,
    ts INTEGER NOT NULL,
    cpu_usage REAL,
    mem_usage REAL,
    load1 REAL,
    payload TEXT NOT NULL,
    FOREIGN KEY(host_id) REFERENCES hosts(id)
);
CREATE TABLE uptime_daily (
    host_id INTEGER NOT NULL,
    date TEXT NOT NULL,
    status TEXT NOT NULL,
    uptime_pct REAL NOT NULL DEFAULT 0,
    total_samples INTEGER NOT NULL DEFAULT 0,
    degraded_samples INTEGER NOT NULL DEFAULT 0,
    down_samples INTEGER NOT NULL DEFAULT 0,
    UNIQUE(host_id, date),
    FOREIGN KEY(host_id) REFERENCES hosts(id)
);`
	if _, err := db.Exec(legacySchema); err != nil {
		db.Close()
		t.Fatalf("create legacy schema: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO hosts(hostname, created_at) VALUES ('router', 1)`); err != nil {
		db.Close()
		t.Fatalf("insert legacy host: %v", err)
	}
	if _, err := db.Exec(`INSERT INTO metrics_raw(host_id, ts, payload) VALUES (1, 1, '{}')`); err != nil {
		db.Close()
		t.Fatalf("insert legacy metric: %v", err)
	}
	if err := db.Close(); err != nil {
		t.Fatalf("close legacy db: %v", err)
	}

	store, err := NewStore(path)
	if err != nil {
		t.Fatalf("migrate legacy db: %v", err)
	}
	defer store.Close()

	var deviceID string
	if err := store.db.QueryRow(`SELECT device_id FROM hosts WHERE hostname='router'`).Scan(&deviceID); err != nil {
		t.Fatalf("read migrated identity: %v", err)
	}
	if deviceID != "legacy-hostname:router" {
		t.Fatalf("migrated device_id=%q", deviceID)
	}

	judge := &Judge{CPUThreshold: 90, MemThreshold: 95}
	if err := Ingest(store, judge, newDeviceReport("device-a", "renamed-router", 10), "push"); err != nil {
		t.Fatalf("ingest migrated device: %v", err)
	}
	var hostCount, metricCount int
	_ = store.db.QueryRow(`SELECT COUNT(*) FROM hosts`).Scan(&hostCount)
	_ = store.db.QueryRow(`SELECT COUNT(*) FROM metrics_raw`).Scan(&metricCount)
	if hostCount != 2 || metricCount != 2 {
		t.Fatalf("after migration host_count=%d metric_count=%d, want 2/2", hostCount, metricCount)
	}
}
