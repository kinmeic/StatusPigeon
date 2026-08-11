// Package main: store.go — SQLite 存储层。
//
// 职责：建表、写入上报/拉取数据、更新当天聚合、查询主机列表与趋势。
// 使用 modernc.org/sqlite（纯 Go，无 cgo，便于交叉编译）。
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
	_ "modernc.org/sqlite"
)

// Store 封装数据库句柄。
type Store struct {
	db *sql.DB
}

// NewStore 打开数据库并建表。
func NewStore(path string) (*Store, error) {
	if dir := filepath.Dir(path); dir != "" && dir != "." {
		if err := os.MkdirAll(dir, 0o775); err != nil {
			return nil, fmt.Errorf("创建数据目录: %w", err)
		}
	}
	// modernc 驱动名 "sqlite"；busy_timeout 防并发锁等待失败。
	dsn := fmt.Sprintf("file:%s?_pragma=busy_timeout(5000)&_pragma=journal_mode(WAL)&_pragma=synchronous(NORMAL)&_pragma=foreign_keys(ON)", path)
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("打开数据库: %w", err)
	}
	// SQLite 写入串行，连接池不宜过大。
	db.SetMaxOpenConns(1)

	s := &Store{db: db}
	if err := s.createSchema(); err != nil {
		return nil, err
	}
	return s, nil
}

// Close 关闭数据库。
func (s *Store) Close() error { return s.db.Close() }

func (s *Store) createSchema() error {
	if _, err := s.db.Exec(schemaSQL); err != nil {
		return err
	}
	// Migrate databases created before disk/network rates were added. The
	// column names are compile-time constants, not user input.
	for _, column := range []string{
		"disk_read_bps",
		"disk_write_bps",
		"network_rx_bps",
		"network_tx_bps",
	} {
		if err := ensureMetricsColumn(s.db, column); err != nil {
			return err
		}
	}
	return nil
}

func ensureMetricsColumn(db *sql.DB, column string) error {
	rows, err := db.Query(`PRAGMA table_info(metrics_raw)`)
	if err != nil {
		return err
	}
	found := false
	for rows.Next() {
		var cid, notNull, primaryKey int
		var name, columnType string
		var defaultValue interface{}
		if err := rows.Scan(&cid, &name, &columnType, &notNull, &defaultValue, &primaryKey); err != nil {
			rows.Close()
			return err
		}
		if name == column {
			found = true
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	rows.Close()
	if found {
		return nil
	}
	_, err = db.Exec("ALTER TABLE metrics_raw ADD COLUMN " + column + " REAL")
	return err
}

const schemaSQL = `
CREATE TABLE IF NOT EXISTS hosts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname      TEXT UNIQUE NOT NULL,
    agent_version TEXT,
    os            TEXT,
    kernel        TEXT,
    arch          TEXT,
    last_seen     INTEGER NOT NULL DEFAULT 0,
    created_at    INTEGER NOT NULL,
    last_status   TEXT,
    last_summary  TEXT,
    source        TEXT NOT NULL DEFAULT 'push'   -- push | pull
);

CREATE TABLE IF NOT EXISTS metrics_raw (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    host_id         INTEGER NOT NULL,
    ts              INTEGER NOT NULL,
    cpu_usage       REAL,
    mem_usage       REAL,
    load1           REAL,
    disk_read_bps  REAL,
    disk_write_bps REAL,
    network_rx_bps REAL,
    network_tx_bps REAL,
    payload         TEXT NOT NULL,
    FOREIGN KEY(host_id) REFERENCES hosts(id)
);
CREATE INDEX IF NOT EXISTS idx_raw_host_ts ON metrics_raw(host_id, ts);

CREATE TABLE IF NOT EXISTS uptime_daily (
    host_id          INTEGER NOT NULL,
    date             TEXT NOT NULL,
    status           TEXT NOT NULL,
    uptime_pct       REAL NOT NULL DEFAULT 0,
    total_samples    INTEGER NOT NULL DEFAULT 0,
    degraded_samples INTEGER NOT NULL DEFAULT 0,
    down_samples     INTEGER NOT NULL DEFAULT 0,
    UNIQUE(host_id, date),
    FOREIGN KEY(host_id) REFERENCES hosts(id)
);
CREATE INDEX IF NOT EXISTS idx_daily_host_date ON uptime_daily(host_id, date);
`

// HostRow 主机列表行。
type HostRow struct {
	ID          int64  `json:"id"`
	Hostname    string `json:"hostname"`
	OS          string `json:"os"`
	Kernel      string `json:"kernel"`
	Arch        string `json:"arch"`
	AgentVer    string `json:"agent_version"`
	LastSeen    int64  `json:"last_seen"`
	LastStatus  string `json:"last_status"`
	LastSummary string `json:"last_summary"`
	Source      string `json:"source"`
}

// sqlRunner 抽象 *sql.DB 与 *sql.Tx 的公共执行接口，便于事务复用子步骤。
type sqlRunner interface {
	Exec(query string, args ...interface{}) (sql.Result, error)
	QueryRow(query string, args ...interface{}) *sql.Row
}

// Ingest 在单个事务中完成一次上报入库：
// upsert host → 刷新存活状态 → 写原始指标 → 更新当天聚合。
// status 由 Judge 预先判定；source 标记 "push" / "pull"。
func (s *Store) Ingest(r *pkgmetrics.Report, source, status string) error {
	tx, err := s.db.Begin()
	if err != nil {
		return fmt.Errorf("开启事务: %w", err)
	}
	defer tx.Rollback()

	hostID, err := upsertHost(tx, r, source)
	if err != nil {
		return fmt.Errorf("upsert host: %w", err)
	}
	if err := setHostLive(tx, hostID, status, r); err != nil {
		return fmt.Errorf("set host live: %w", err)
	}
	if err := insertMetrics(tx, hostID, r); err != nil {
		return fmt.Errorf("insert metrics: %w", err)
	}
	if err := updateDailyAgg(tx, hostID, r.Timestamp, status); err != nil {
		return fmt.Errorf("update daily: %w", err)
	}
	return tx.Commit()
}

// upsertHost 创建或更新主机记录，返回 host_id。
// last_seen 使用服务端接收时间，避免 agent 时钟偏差干扰失联判定。
//
// 注意：必须用 RETURNING 取 id，而非 res.LastInsertId() ——
// UPSERT 冲突更新后该值依驱动/版本语义不定（可能残留同连接上
// 其他表上一次 INSERT 的 rowid），曾导致 host_id 错乱、metrics 外键失败。
func upsertHost(run sqlRunner, r *pkgmetrics.Report, source string) (int64, error) {
	osName, kernel, arch := r.Metrics.Os.Os, r.Metrics.Os.Kernel, r.Metrics.Os.Arch
	summary := hostSummary(r.Metrics)
	now := time.Now().Unix()

	var id int64
	err := run.QueryRow(
		`INSERT INTO hosts (hostname, agent_version, os, kernel, arch, last_seen, created_at, last_status, last_summary, source)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		 ON CONFLICT(hostname) DO UPDATE SET
		    agent_version=excluded.agent_version,
		    os=excluded.os, kernel=excluded.kernel, arch=excluded.arch,
		    last_seen=excluded.last_seen, last_status=excluded.last_status,
		    last_summary=excluded.last_summary, source=excluded.source
		 RETURNING id`,
		r.Hostname, r.AgentVersion, osName, kernel, arch, now, now, statusOperational, summary, source,
	).Scan(&id)
	if err != nil {
		return 0, err
	}
	return id, nil
}

// setHostLive 主机存活时按判定状态刷新 last_status/last_summary/last_seen。
func setHostLive(run sqlRunner, hostID int64, status string, r *pkgmetrics.Report) error {
	summary := hostSummary(r.Metrics)
	_, err := run.Exec(
		`UPDATE hosts SET last_status=?, last_summary=?, last_seen=? WHERE id=?`,
		status, summary, time.Now().Unix(), hostID,
	)
	return err
}

// insertMetrics 写入原始指标。
func insertMetrics(run sqlRunner, hostID int64, r *pkgmetrics.Report) error {
	cpu, mem, load1 := r.Metrics.Cpu.Usage, r.Metrics.Mem.UsedPct, r.Metrics.Cpu.Load1
	payload, _ := jsonMarshal(r)
	_, err := run.Exec(
		`INSERT INTO metrics_raw
		 (host_id, ts, cpu_usage, mem_usage, load1, disk_read_bps, disk_write_bps,
		  network_rx_bps, network_tx_bps, payload)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		hostID, r.Timestamp, cpu, mem, load1,
		r.Metrics.Disk.ReadBps, r.Metrics.Disk.WriteBps,
		r.Metrics.Network.RxBps, r.Metrics.Network.TxBps, payload,
	)
	return err
}

// updateDailyAgg 更新某主机当天聚合。
// status 为本次样本状态（operational/degraded）。down 由失联扫描单独处理。
func updateDailyAgg(run sqlRunner, hostID int64, ts int64, status string) error {
	date := time.Unix(ts, 0).Format("2006-01-02")
	isDegraded := status == statusDegraded

	// UPSERT 当天聚合行，累加样本计数。
	_, err := run.Exec(
		`INSERT INTO uptime_daily (host_id, date, status, uptime_pct, total_samples, degraded_samples, down_samples)
		 VALUES (?, ?, ?, 0, 1, ?, 0)
		 ON CONFLICT(host_id, date) DO UPDATE SET
		    total_samples = uptime_daily.total_samples + 1,
		    degraded_samples = uptime_daily.degraded_samples + excluded.degraded_samples`,
		hostID, date, status, boolToInt(isDegraded),
	)
	if err != nil {
		return err
	}
	// 重算当天 status（取最差）与 uptime_pct。
	return recomputeDaily(run, hostID, date)
}

// recomputeDaily 根据当天样本重算 status/uptime_pct。
func recomputeDaily(run sqlRunner, hostID int64, date string) error {
	var total, degraded int
	err := run.QueryRow(
		`SELECT total_samples, degraded_samples FROM uptime_daily WHERE host_id=? AND date=?`,
		hostID, date,
	).Scan(&total, &degraded)
	if err != nil || total == 0 {
		return err
	}
	dayStatus := statusOperational
	if degraded > 0 {
		dayStatus = statusDegraded
	}
	// uptime_pct = 非降级样本占比。
	uptime := float64(total-degraded) / float64(total) * 100.0
	_, err = run.Exec(
		`UPDATE uptime_daily SET status=?, uptime_pct=? WHERE host_id=? AND date=?`,
		dayStatus, uptime, hostID, date,
	)
	return err
}

// MarkOffline 失联判定：将 last_seen 超期的主机标记为 down，并给失联当天
// 写入/更新一条 down 聚合。整个离线期间每一天都会标记为 down（而非仅首日），
// 保证状态页色块条在长时间故障中持续显示红色。
// 返回本次新转为 down 的主机数（不含已处于 down 的）。
func (s *Store) MarkOffline(thresholdTs int64) (int, error) {
	tx, err := s.db.Begin()
	if err != nil {
		return 0, fmt.Errorf("开启事务: %w", err)
	}
	defer tx.Rollback()

	// 取全部失联主机（含已 down 的：后续每一天都要持续标记）。
	rows, err := tx.Query(`SELECT id, COALESCE(last_status,'') FROM hosts WHERE last_seen < ?`, thresholdTs)
	if err != nil {
		return 0, err
	}
	type offlineHost struct {
		id     int64
		status string
	}
	var hosts []offlineHost
	for rows.Next() {
		var h offlineHost
		_ = rows.Scan(&h.id, &h.status)
		hosts = append(hosts, h)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, err
	}

	date := time.Now().Format("2006-01-02")
	newly := 0
	for _, h := range hosts {
		// 状态迁移：仅非 down → down 时更新 hosts 表。
		if h.status != statusDown {
			if _, err := tx.Exec(`UPDATE hosts SET last_status=? WHERE id=?`, statusDown, h.id); err != nil {
				return newly, err
			}
			newly++
		}
		// 当天聚合标记为 down（幂等：每次扫描重复写同一状态）。
		if _, err := tx.Exec(
			`INSERT INTO uptime_daily (host_id, date, status, uptime_pct, total_samples, degraded_samples, down_samples)
			 VALUES (?, ?, ?, 0, 1, 0, 1)
			 ON CONFLICT(host_id, date) DO UPDATE SET
			    down_samples = uptime_daily.down_samples + 1, status = ?`,
			h.id, date, statusDown, statusDown,
		); err != nil {
			return newly, err
		}
	}
	return newly, tx.Commit()
}

// ListHosts 返回全部主机。
func (s *Store) ListHosts() ([]HostRow, error) {
	rows, err := s.db.Query(
		`SELECT id, hostname, COALESCE(os,''), COALESCE(kernel,''), COALESCE(arch,''),
		        COALESCE(agent_version,''), last_seen, COALESCE(last_status,''),
		        COALESCE(last_summary,'{}'), source
		 FROM hosts ORDER BY hostname`,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []HostRow
	for rows.Next() {
		var h HostRow
		if err := rows.Scan(&h.ID, &h.Hostname, &h.OS, &h.Kernel, &h.Arch,
			&h.AgentVer, &h.LastSeen, &h.LastStatus, &h.LastSummary, &h.Source); err != nil {
			return nil, err
		}
		out = append(out, h)
	}
	return out, rows.Err()
}

// DailyStatus 返回某主机最近 days 天的聚合（含无数据天，status='no-data'）。
func (s *Store) DailyStatus(hostID int64, days int) ([]DailyPoint, error) {
	start := time.Now().AddDate(0, 0, -(days - 1)).Format("2006-01-02")
	rows, err := s.db.Query(
		`SELECT date, status, uptime_pct FROM uptime_daily
		 WHERE host_id=? AND date >= ? ORDER BY date`,
		hostID, start,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	byDate := map[string]DailyPoint{}
	for rows.Next() {
		var d DailyPoint
		if err := rows.Scan(&d.Date, &d.Status, &d.Uptime); err != nil {
			return nil, err
		}
		byDate[d.Date] = d
	}

	// 填充缺失天为 no-data。
	out := make([]DailyPoint, 0, days)
	t := time.Now().AddDate(0, 0, -(days - 1))
	end := time.Now()
	for !t.After(end) {
		ds := t.Format("2006-01-02")
		if p, ok := byDate[ds]; ok {
			out = append(out, p)
		} else {
			out = append(out, DailyPoint{Date: ds, Status: statusNoData, Uptime: 0})
		}
		t = t.AddDate(0, 0, 1)
	}
	return out, nil
}

// DailyPoint 单日聚合点。
type DailyPoint struct {
	Date   string  `json:"date"`
	Status string  `json:"status"`
	Uptime float64 `json:"uptime"`
}

// maxSeriesPoints 趋势查询单次返回的最大点数，防高频上报时响应体过大。
const maxSeriesPoints = 10000

// MetricsSeries 趋势查询：返回时间范围内的 cpu/mem/load 序列（升序）。
// 超过 maxSeriesPoints 时取最新的 N 条。
func (s *Store) MetricsSeries(hostID int64, fromTs int64) ([]MetricPoint, error) {
	rows, err := s.db.Query(
		`SELECT ts, cpu_usage, mem_usage, load1, disk_read_bps, disk_write_bps,
		        network_rx_bps, network_tx_bps FROM (
		    SELECT ts, cpu_usage, mem_usage, load1, disk_read_bps, disk_write_bps,
		           network_rx_bps, network_tx_bps FROM metrics_raw
		    WHERE host_id=? AND ts >= ? ORDER BY ts DESC LIMIT ?
		) ORDER BY ts`,
		hostID, fromTs, maxSeriesPoints,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var out []MetricPoint
	for rows.Next() {
		var p MetricPoint
		if err := rows.Scan(&p.Ts, &p.CPU, &p.Mem, &p.Load,
			&p.DiskRead, &p.DiskWrite, &p.NetworkRx, &p.NetworkTx); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// MetricPoint 单个指标样本。
type MetricPoint struct {
	Ts        int64    `json:"ts"`
	CPU       *float64 `json:"cpu"`
	Mem       *float64 `json:"mem"`
	Load      *float64 `json:"load1"`
	DiskRead  *float64 `json:"disk_read_bps"`
	DiskWrite *float64 `json:"disk_write_bps"`
	NetworkRx *float64 `json:"network_rx_bps"`
	NetworkTx *float64 `json:"network_tx_bps"`
}

// Cleanup 删除早于 cutoffTs 的原始与聚合数据，返回两类各自的删除条数。
func (s *Store) Cleanup(cutoffTs int64) (rawDeleted, dailyDeleted int64, err error) {
	cutoffDate := time.Unix(cutoffTs, 0).Format("2006-01-02")
	res, err := s.db.Exec(`DELETE FROM metrics_raw WHERE ts < ?`, cutoffTs)
	if err != nil {
		return 0, 0, err
	}
	rawDeleted, _ = res.RowsAffected()
	res, err = s.db.Exec(`DELETE FROM uptime_daily WHERE date < ?`, cutoffDate)
	if err != nil {
		return rawDeleted, 0, err
	}
	dailyDeleted, _ = res.RowsAffected()
	return rawDeleted, dailyDeleted, nil
}

// ====== 状态常量 ======

const (
	statusOperational = "operational"
	statusDegraded    = "degraded"
	statusDown        = "down"
	statusNoData      = "no-data"
)

// ====== 辅助 ======

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// hostSummary 生成状态页徽章用的精简摘要 JSON。
func hostSummary(m pkgmetrics.Metrics) string {
	cpu, mem, load1 := m.Cpu.Usage, m.Mem.UsedPct, m.Cpu.Load1
	return fmt.Sprintf(`{"cpu":%.1f,"mem":%.1f,"load1":%.2f,"uptime":%d,"os":%q,"os_version":%q,"ipv4":%s,"ipv6":%s}`,
		cpu, mem, load1, m.Os.Uptime, m.Os.Os, m.Os.Version,
		jsonMarshalString(m.Os.IPv4), jsonMarshalString(m.Os.IPv6))
}

// jsonMarshalString 序列化为 JSON 文本（用于嵌入摘要），失败返回 "null"。
func jsonMarshalString(v interface{}) string {
	b, err := json.Marshal(v)
	if err != nil {
		return "null"
	}
	return string(b)
}

// jsonMarshal 序列化为 JSON 字符串。
func jsonMarshal(v interface{}) (string, error) {
	b, err := json.Marshal(v)
	return string(b), err
}
