// Package main: store.go — SQLite 存储层。
//
// 职责：建表、写入上报/拉取数据、更新当天聚合、查询主机列表与趋势。
// 使用 modernc.org/sqlite（纯 Go，无 cgo，便于交叉编译）。
package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"
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
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return nil, fmt.Errorf("创建数据目录: %w", err)
		}
	}
	// modernc 驱动名 "sqlite"；busy_timeout 防并发锁等待失败。
	absPath, err := filepath.Abs(path)
	if err != nil {
		return nil, fmt.Errorf("解析数据库路径: %w", err)
	}
	dsnURL := &url.URL{Scheme: "file", Path: filepath.ToSlash(absPath)}
	query := dsnURL.Query()
	query.Add("_pragma", "busy_timeout(5000)")
	query.Add("_pragma", "journal_mode(WAL)")
	query.Add("_pragma", "synchronous(NORMAL)")
	query.Add("_pragma", "foreign_keys(ON)")
	dsnURL.RawQuery = query.Encode()
	dsn := dsnURL.String()
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("打开数据库: %w", err)
	}
	// SQLite 写入串行，连接池不宜过大。
	db.SetMaxOpenConns(1)

	s := &Store{db: db}
	if err := s.createSchema(); err != nil {
		_ = db.Close()
		return nil, err
	}
	if err := s.migrateHostIdentity(); err != nil {
		_ = db.Close()
		return nil, err
	}
	if err := s.ensureMetricUniqueness(); err != nil {
		_ = db.Close()
		return nil, err
	}
	_ = os.Chmod(absPath, 0o640)
	return s, nil
}

func (s *Store) ensureMetricUniqueness() error {
	if _, err := s.db.Exec(`DROP INDEX IF EXISTS idx_raw_host_ts_unique`); err != nil {
		return fmt.Errorf("移除过严的旧指标索引: %w", err)
	}
	var exists int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM sqlite_master
		WHERE type='index' AND name='idx_raw_host_ts_payload_unique'`).Scan(&exists); err != nil {
		return fmt.Errorf("检查指标唯一索引: %w", err)
	}
	if exists > 0 {
		return nil
	}
	// Older versions accepted a retry as a new sample. Keep the oldest raw
	// record for each identical host/timestamp/payload before enforcing
	// idempotency in SQLite.
	if _, err := s.db.Exec(`DELETE FROM metrics_raw
		WHERE id NOT IN (SELECT MIN(id) FROM metrics_raw GROUP BY host_id, ts, payload)`); err != nil {
		return fmt.Errorf("清理重复指标: %w", err)
	}
	if _, err := s.db.Exec(`CREATE UNIQUE INDEX IF NOT EXISTS idx_raw_host_ts_payload_unique
		ON metrics_raw(host_id, ts, payload)`); err != nil {
		return fmt.Errorf("创建指标唯一索引: %w", err)
	}
	return nil
}

// Close 关闭数据库。
func (s *Store) Close() error { return s.db.Close() }

func (s *Store) createSchema() error {
	if _, err := s.db.Exec(schemaSQL); err != nil {
		return err
	}
	// Existing databases may contain legacy I/O columns.  They are left in
	// place for non-destructive upgrades, but no new report reads or writes
	// those columns.
	return nil
}

// migrateHostIdentity upgrades the original hostname-keyed hosts table.  A
// table rebuild is required because the original schema made hostname UNIQUE;
// keeping that constraint would still merge two devices that use the same
// display name.  Existing rows receive a deterministic legacy identity so an
// older agent without device_id can continue updating its old row.
func (s *Store) migrateHostIdentity() error {
	columns, err := s.hostColumns()
	if err != nil {
		return err
	}
	hasDeviceID := columns["device_id"]
	hasUniqueHostname, err := s.hostsHaveUniqueHostname()
	if err != nil {
		return err
	}
	if hasDeviceID && !hasUniqueHostname {
		return nil
	}

	if _, err := s.db.Exec(`PRAGMA foreign_keys=OFF`); err != nil {
		return err
	}
	defer s.db.Exec(`PRAGMA foreign_keys=ON`)

	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(`
CREATE TABLE hosts_new (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id     TEXT NOT NULL,
    hostname      TEXT NOT NULL,
    agent_version TEXT,
    os            TEXT,
    kernel        TEXT,
    arch          TEXT,
    last_seen     INTEGER NOT NULL DEFAULT 0,
    created_at    INTEGER NOT NULL,
    last_status   TEXT,
    last_summary  TEXT,
    source        TEXT NOT NULL DEFAULT 'push'
)`); err != nil {
		return err
	}
	deviceExpression := `('legacy-hostname:' || hostname)`
	if hasDeviceID {
		deviceExpression = `CASE WHEN device_id IS NULL OR device_id = ''
            THEN ('legacy-hostname:' || hostname) ELSE device_id END`
	}
	query := `INSERT INTO hosts_new
        (id, device_id, hostname, agent_version, os, kernel, arch, last_seen,
         created_at, last_status, last_summary, source)
        SELECT id, ` + deviceExpression + `, hostname, agent_version, os,
               kernel, arch, last_seen, created_at, last_status, last_summary,
               source FROM hosts`
	if _, err := tx.Exec(query); err != nil {
		return err
	}
	if _, err := tx.Exec(`DROP TABLE hosts`); err != nil {
		return err
	}
	if _, err := tx.Exec(`ALTER TABLE hosts_new RENAME TO hosts`); err != nil {
		return err
	}
	if _, err := tx.Exec(`CREATE UNIQUE INDEX idx_hosts_device_id ON hosts(device_id)`); err != nil {
		return err
	}
	if _, err := tx.Exec(`CREATE INDEX IF NOT EXISTS idx_hosts_hostname ON hosts(hostname)`); err != nil {
		return err
	}
	return tx.Commit()
}

func (s *Store) hostColumns() (map[string]bool, error) {
	rows, err := s.db.Query(`PRAGMA table_info(hosts)`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	columns := make(map[string]bool)
	for rows.Next() {
		var cid, notNull, primaryKey int
		var name, columnType string
		var defaultValue interface{}
		if err := rows.Scan(&cid, &name, &columnType, &notNull, &defaultValue, &primaryKey); err != nil {
			return nil, err
		}
		columns[name] = true
	}
	return columns, rows.Err()
}

func (s *Store) hostsHaveUniqueHostname() (bool, error) {
	rows, err := s.db.Query(`PRAGMA index_list(hosts)`)
	if err != nil {
		return false, err
	}
	var uniqueIndexes []string
	for rows.Next() {
		var seq int
		var name string
		var unique int
		var origin, partial string
		if err := rows.Scan(&seq, &name, &unique, &origin, &partial); err != nil {
			rows.Close()
			return false, err
		}
		if unique == 0 {
			continue
		}
		uniqueIndexes = append(uniqueIndexes, name)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return false, err
	}
	if err := rows.Close(); err != nil {
		return false, err
	}

	// Close index_list before issuing another query. The Store intentionally
	// has one SQLite connection, so nested PRAGMA queries would otherwise wait
	// for that same connection and can deadlock during startup migration.
	for _, name := range uniqueIndexes {
		indexRows, err := s.db.Query(`PRAGMA index_info(` + quoteSQLiteIdentifier(name) + `)`)
		if err != nil {
			return false, err
		}
		var columns []string
		for indexRows.Next() {
			var seqno, cid int
			var column string
			if err := indexRows.Scan(&seqno, &cid, &column); err != nil {
				indexRows.Close()
				return false, err
			}
			columns = append(columns, column)
		}
		if err := indexRows.Err(); err != nil {
			indexRows.Close()
			return false, err
		}
		indexRows.Close()
		if len(columns) == 1 && columns[0] == "hostname" {
			return true, nil
		}
	}
	return false, nil
}

func quoteSQLiteIdentifier(value string) string {
	return `"` + strings.ReplaceAll(value, `"`, `""`) + `"`
}

const schemaSQL = `
CREATE TABLE IF NOT EXISTS hosts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id     TEXT UNIQUE NOT NULL,
    hostname      TEXT NOT NULL,
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
	DeviceID    string `json:"device_id"`
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

	duplicate, err := reportAlreadyStored(tx, r)
	if err != nil {
		return fmt.Errorf("check duplicate report: %w", err)
	}
	if duplicate {
		return tx.Commit()
	}

	hostID, err := upsertHost(tx, r, source)
	if err != nil {
		return fmt.Errorf("upsert host: %w", err)
	}
	if err := setHostLive(tx, hostID, status, r); err != nil {
		return fmt.Errorf("set host live: %w", err)
	}
	inserted, err := insertMetrics(tx, hostID, r)
	if err != nil {
		return fmt.Errorf("insert metrics: %w", err)
	}
	if inserted {
		if err := updateDailyAgg(tx, hostID, r.Timestamp, status); err != nil {
			return fmt.Errorf("update daily: %w", err)
		}
	}
	return tx.Commit()
}

func reportAlreadyStored(run sqlRunner, r *pkgmetrics.Report) (bool, error) {
	payload, err := jsonMarshal(r)
	if err != nil {
		return false, err
	}
	var id int64
	err = run.QueryRow(
		`SELECT metrics_raw.id FROM metrics_raw
		 INNER JOIN hosts ON hosts.id=metrics_raw.host_id
		 WHERE hosts.device_id=? AND metrics_raw.ts=? AND metrics_raw.payload=? LIMIT 1`,
		reportDeviceID(r), r.Timestamp, payload,
	).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	return err == nil, err
}

// upsertHost 创建或更新主机记录，返回 host_id。DeviceID 是唯一身份键，
// Hostname 只是可修改的显示名称。没有 device_id 的旧客户端使用
// legacy-hostname:<hostname>，便于平滑兼容旧数据。
func upsertHost(run sqlRunner, r *pkgmetrics.Report, source string) (int64, error) {
	osName, kernel, arch := r.Metrics.Os.Os, r.Metrics.Os.Kernel, r.Metrics.Os.Arch
	summary := hostSummary(r.Metrics)
	now := time.Now().Unix()
	deviceID := reportDeviceID(r)

	var id int64
	err := run.QueryRow(`SELECT id FROM hosts WHERE device_id=?`, deviceID).Scan(&id)
	if err == sql.ErrNoRows {
		// Adopt a legacy row when the first device-aware report arrives after
		// an upgrade. This preserves its historical metrics and status bar.
		err = run.QueryRow(
			`SELECT id FROM hosts WHERE device_id=? AND hostname=?`,
			"legacy-hostname:"+r.Hostname, r.Hostname,
		).Scan(&id)
		if err == nil {
			if _, err = run.Exec(`UPDATE hosts SET device_id=? WHERE id=?`, deviceID, id); err != nil {
				return 0, err
			}
		}
	}
	if err != nil && err != sql.ErrNoRows {
		return 0, err
	}
	if err == sql.ErrNoRows {
		result, insertErr := run.Exec(
			`INSERT INTO hosts
			 (device_id, hostname, agent_version, os, kernel, arch, last_seen,
			  created_at, last_status, last_summary, source)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
			deviceID, r.Hostname, r.AgentVersion, osName, kernel, arch, now, now,
			statusOperational, summary, source,
		)
		if insertErr != nil {
			return 0, insertErr
		}
		id, insertErr = result.LastInsertId()
		if insertErr != nil {
			return 0, insertErr
		}
	}
	_, err = run.Exec(
		`UPDATE hosts SET hostname=?, agent_version=?, os=?, kernel=?, arch=?,
		 last_seen=?, last_status=?, last_summary=?, source=? WHERE id=?`,
		r.Hostname, r.AgentVersion, osName, kernel, arch, now, statusOperational,
		summary, source, id,
	)
	if err != nil {
		return 0, err
	}
	return id, nil
}

func reportDeviceID(r *pkgmetrics.Report) string {
	if value := strings.TrimSpace(r.DeviceID); value != "" {
		return value
	}
	return "legacy-hostname:" + strings.TrimSpace(r.Hostname)
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
func insertMetrics(run sqlRunner, hostID int64, r *pkgmetrics.Report) (bool, error) {
	// cpu_usage is a legacy column retained for non-destructive migrations.
	// New reports intentionally leave it NULL because CPU percentage is no
	// longer sampled or transmitted.
	var cpu interface{}
	mem, load1 := r.Metrics.Mem.UsedPct, r.Metrics.Cpu.Load1
	payload, _ := jsonMarshal(r)
	result, err := run.Exec(
		`INSERT OR IGNORE INTO metrics_raw
		 (host_id, ts, cpu_usage, mem_usage, load1, payload)
		 VALUES (?, ?, ?, ?, ?, ?)`,
		hostID, r.Timestamp, cpu, mem, load1,
		payload,
	)
	if err != nil {
		return false, err
	}
	rows, err := result.RowsAffected()
	return rows > 0, err
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
	var total, degraded, down int
	err := run.QueryRow(
		`SELECT total_samples, degraded_samples, down_samples FROM uptime_daily WHERE host_id=? AND date=?`,
		hostID, date,
	).Scan(&total, &degraded, &down)
	if err != nil || total == 0 {
		return err
	}
	dayStatus := statusOperational
	if degraded > 0 {
		dayStatus = statusDegraded
	}
	if down > 0 {
		dayStatus = statusDown
	}
	// uptime_pct = 既未降级也未离线的样本占比。
	uptime := float64(max(0, total-degraded-down)) / float64(total) * 100.0
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
		if err := rows.Scan(&h.id, &h.status); err != nil {
			rows.Close()
			return 0, err
		}
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
			    status = ?, uptime_pct = 0,
			    total_samples = CASE WHEN uptime_daily.down_samples < 1 THEN uptime_daily.total_samples + 1 ELSE uptime_daily.total_samples END,
			    down_samples = CASE WHEN uptime_daily.down_samples < 1 THEN 1 ELSE uptime_daily.down_samples END`,
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
		`SELECT id, COALESCE(device_id,''), hostname, COALESCE(os,''), COALESCE(kernel,''), COALESCE(arch,''),
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
		if err := rows.Scan(&h.ID, &h.DeviceID, &h.Hostname, &h.OS, &h.Kernel, &h.Arch,
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
	if err := rows.Err(); err != nil {
		return nil, err
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

// MetricsSeries 趋势查询：返回时间范围内的 mem/load 序列（升序）。
// 超过 maxSeriesPoints 时取最新的 N 条。
func (s *Store) MetricsSeries(hostID int64, fromTs int64) ([]MetricPoint, error) {
	rows, err := s.db.Query(
		`SELECT ts, mem_usage, load1 FROM (
		    SELECT ts, mem_usage, load1 FROM metrics_raw
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
		if err := rows.Scan(&p.Ts, &p.Mem, &p.Load); err != nil {
			return nil, err
		}
		out = append(out, p)
	}
	return out, rows.Err()
}

// MetricPoint 单个指标样本。
type MetricPoint struct {
	Ts   int64    `json:"ts"`
	Mem  *float64 `json:"mem"`
	Load *float64 `json:"load1"`
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
	summary := struct {
		Mem         float64  `json:"mem"`
		Load1       float64  `json:"load1"`
		Uptime      uint64   `json:"uptime"`
		OS          string   `json:"os"`
		OSVersion   string   `json:"os_version"`
		CPUModel    string   `json:"cpu_model"`
		MemoryTotal uint64   `json:"memory_total"`
		DiskTotal   uint64   `json:"disk_total"`
		DiskUsedPct float64  `json:"disk_used_pct"`
		IPv4        []string `json:"ipv4"`
		IPv6        []string `json:"ipv6"`
	}{
		Mem: m.Mem.UsedPct, Load1: m.Cpu.Load1, Uptime: m.Os.Uptime,
		OS: m.Os.Os, OSVersion: m.Os.Version, CPUModel: m.Os.CPUModel,
		MemoryTotal: m.Os.MemoryTotal, DiskTotal: m.Os.DiskTotal,
		DiskUsedPct: m.Os.DiskUsedPct, IPv4: m.Os.IPv4, IPv6: m.Os.IPv6,
	}
	b, err := json.Marshal(summary)
	if err != nil {
		return "{}"
	}
	return string(b)
}

// jsonMarshal 序列化为 JSON 字符串。
func jsonMarshal(v interface{}) (string, error) {
	b, err := json.Marshal(v)
	return string(b), err
}
