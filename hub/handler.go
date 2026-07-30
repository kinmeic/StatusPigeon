// Package main: handler.go — HTTP 路由与处理器。
//
// 端点：
//   POST /report            接收 push 上报（Bearer 鉴权）
//   GET  /api/hosts         主机列表 + 最新状态
//   GET  /api/status        全部主机最近 N 天聚合（色块条）
//   GET  /api/metrics?id=&range=  单主机指标序列（趋势图）
//   GET  /                  静态状态页（embed）
package main

import (
	"embed"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"strconv"
	"strings"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// Server 持有运行期依赖。
type Server struct {
	store   *Store
	judge   *Judge
	auth    string
	barDays int
	assets  embed.FS
}

// NewServer 创建 HTTP 服务。
func NewServer(store *Store, judge *Judge, auth string, barDays int, assets embed.FS) *Server {
	return &Server{store: store, judge: judge, auth: auth, barDays: barDays, assets: assets}
}

// Routes 返回路由 mux。
func (s *Server) Routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/report", s.handleReport)
	mux.HandleFunc("/api/hosts", s.handleHosts)
	mux.HandleFunc("/api/status", s.handleStatus)
	mux.HandleFunc("/api/metrics", s.handleMetrics)
	// 静态资源（前端）。/ 映射到 assets/index.html。
	mux.HandleFunc("/", s.handleStatic)
	return mux
}

// ====== POST /report ======

func (s *Server) handleReport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	// 鉴权。
	if s.auth != "" {
		got := r.Header.Get("Authorization")
		if got != "Bearer "+s.auth {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
	}

	body, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "read body", http.StatusBadRequest)
		return
	}
	var report pkgmetrics.Report
	if err := json.Unmarshal(body, &report); err != nil {
		http.Error(w, "invalid json", http.StatusBadRequest)
		return
	}
	if report.Hostname == "" || report.Timestamp <= 0 {
		http.Error(w, "missing hostname/timestamp", http.StatusBadRequest)
		return
	}
	// 时间窗口校验（防重放）。
	if abs(time.Now().Unix()-report.Timestamp) > 300 {
		http.Error(w, "timestamp out of range", http.StatusBadRequest)
		return
	}

	if err := Ingest(s.store, s.judge, &report); err != nil {
		log.Printf("ingest 失败: %v", err)
		http.Error(w, "ingest failed", http.StatusInternalServerError)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// ====== GET /api/hosts ======

func (s *Server) handleHosts(w http.ResponseWriter, r *http.Request) {
	hosts, err := s.store.ListHosts()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, errMap(err))
		return
	}
	writeJSON(w, http.StatusOK, hosts)
}

// ====== GET /api/status?days= ======

func (s *Server) handleStatus(w http.ResponseWriter, r *http.Request) {
	days := s.barDays
	if v := r.URL.Query().Get("days"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 && n <= 365 {
			days = n
		}
	}
	hosts, err := s.store.ListHosts()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, errMap(err))
		return
	}

	type hostStatus struct {
		HostRow
		Daily  []DailyPoint `json:"daily"`
		Uptime float64      `json:"uptime_pct"` // 区间总可用率
	}
	out := make([]hostStatus, 0, len(hosts))
	for _, h := range hosts {
		daily, err := s.store.DailyStatus(h.ID, days)
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, errMap(err))
			return
		}
		out = append(out, hostStatus{HostRow: h, Daily: daily, Uptime: rangeUptime(daily)})
	}
	writeJSON(w, http.StatusOK, out)
}

// ====== GET /api/metrics?id=&range=1h|24h|7d ======

func (s *Server) handleMetrics(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil || id <= 0 {
		http.Error(w, "invalid id", http.StatusBadRequest)
		return
	}
	rng := parseRange(r.URL.Query().Get("range"))
	fromTs := time.Now().Add(-rng).Unix()

	pts, err := s.store.MetricsSeries(id, fromTs)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, errMap(err))
		return
	}
	writeJSON(w, http.StatusOK, pts)
}

// ====== 静态资源 ======

func (s *Server) handleStatic(w http.ResponseWriter, r *http.Request) {
	p := strings.TrimPrefix(r.URL.Path, "/")
	if p == "" {
		p = "index.html"
	}
	// 安全：禁止路径穿越。
	if strings.Contains(p, "..") {
		http.NotFound(w, r)
		return
	}
	data, err := s.assets.ReadFile("assets/" + p)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", contentTypeFor(p))
	_, _ = w.Write(data)
}

// ====== 辅助 ======

func writeJSON(w http.ResponseWriter, code int, v interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func errMap(err error) map[string]string {
	return map[string]string{"error": err.Error()}
}

func abs(x int64) int64 {
	if x < 0 {
		return -x
	}
	return x
}

// rangeUptime 计算一组日聚合的总可用率（忽略 no-data 天）。
func rangeUptime(daily []DailyPoint) float64 {
	var total, ok float64
	for _, d := range daily {
		if d.Status == statusNoData {
			continue
		}
		total++
		if d.Status == statusOperational {
			ok++
		} else if d.Status == statusDegraded {
			ok += d.Uptime / 100.0 // 降级天按其部分可用率计
		}
	}
	if total == 0 {
		return 0
	}
	return ok / total * 100.0
}

func parseRange(v string) time.Duration {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1h":
		return time.Hour
	case "7d":
		return 7 * 24 * time.Hour
	default: // 含 24h
		return 24 * time.Hour
	}
}

func contentTypeFor(p string) string {
	switch {
	case strings.HasSuffix(p, ".html"):
		return "text/html; charset=utf-8"
	case strings.HasSuffix(p, ".css"):
		return "text/css; charset=utf-8"
	case strings.HasSuffix(p, ".js"):
		return "application/javascript; charset=utf-8"
	case strings.HasSuffix(p, ".json"):
		return "application/json; charset=utf-8"
	default:
		return "application/octet-stream"
	}
}
