// Package main: handler.go — HTTP 路由与处理器。
//
// 端点：
//
//	POST /report            接收 push 上报（Bearer 鉴权）
//	GET  /api/hosts         主机列表 + 最新状态
//	GET  /api/status        全部主机最近 N 天聚合（色块条）
//	GET  /api/metrics?id=&range=  单主机指标序列（趋势图）
//	GET  /                  静态状态页（embed）
package main

import (
	"crypto/subtle"
	"embed"
	"encoding/json"
	"errors"
	"io"
	"log"
	"mime"
	"net/http"
	"strconv"
	"strings"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// maxReportBodyBytes /report 请求体上限，防恶意大请求耗尽内存。
// 正常上报体仅数 KB，1MB 已非常宽裕。
const maxReportBodyBytes = 1 << 20

// Server 持有运行期依赖。
type Server struct {
	store                *Store
	judge                *Judge
	auth                 string
	allowUnauthenticated bool
	barDays              int
	assets               embed.FS
}

// NewServer 创建 HTTP 服务。
func NewServer(store *Store, judge *Judge, auth string, allowUnauthenticated bool, barDays int, assets embed.FS) *Server {
	return &Server{store: store, judge: judge, auth: auth, allowUnauthenticated: allowUnauthenticated, barDays: barDays, assets: assets}
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
	return securityHeaders(mux)
}

// ====== POST /report ======

func (s *Server) handleReport(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	// 鉴权（恒定时间比较，防时序侧信道）。
	if s.auth == "" && !s.allowUnauthenticated {
		http.Error(w, "report authentication is not configured", http.StatusServiceUnavailable)
		return
	}
	if s.auth != "" && !bearerOK(r.Header.Get("Authorization"), s.auth) {
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}
	mediaType, _, err := mime.ParseMediaType(r.Header.Get("Content-Type"))
	if err != nil || mediaType != "application/json" {
		http.Error(w, "content type must be application/json", http.StatusUnsupportedMediaType)
		return
	}

	// 限制请求体大小。
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, maxReportBodyBytes))
	if err != nil {
		var tooLarge *http.MaxBytesError
		if errors.As(err, &tooLarge) {
			http.Error(w, "request body too large", http.StatusRequestEntityTooLarge)
			return
		}
		http.Error(w, "read body", http.StatusBadRequest)
		return
	}
	var report pkgmetrics.Report
	if err := json.Unmarshal(body, &report); err != nil {
		http.Error(w, "invalid json", http.StatusBadRequest)
		return
	}
	if report.Timestamp <= 0 {
		http.Error(w, "missing timestamp", http.StatusBadRequest)
		return
	}
	if err := pkgmetrics.ValidateReport(&report); err != nil {
		http.Error(w, "invalid report: "+err.Error(), http.StatusBadRequest)
		return
	}
	// 时间窗口校验（防重放）。
	if abs(time.Now().Unix()-report.Timestamp) > 300 {
		http.Error(w, "timestamp out of range", http.StatusBadRequest)
		return
	}

	if err := Ingest(s.store, s.judge, &report, "push"); err != nil {
		log.Printf("ingest 失败: %v", err)
		http.Error(w, "ingest failed", http.StatusInternalServerError)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// bearerOK 恒定时间比较 Authorization 头与期望 token。
func bearerOK(got, token string) bool {
	want := "Bearer " + token
	return subtle.ConstantTimeCompare([]byte(got), []byte(want)) == 1
}

// requireGET 仅允许 GET，否则 405 并返回 false。
func requireGET(w http.ResponseWriter, r *http.Request) bool {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return false
	}
	return true
}

// ====== GET /api/hosts ======

func (s *Server) handleHosts(w http.ResponseWriter, r *http.Request) {
	if !requireGET(w, r) {
		return
	}
	hosts, err := s.store.ListHosts()
	if err != nil {
		log.Printf("list hosts 失败: %v", err)
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "query failed"})
		return
	}
	type hostDetail struct {
		ID          int64  `json:"id"`
		Hostname    string `json:"hostname"`
		OS          string `json:"os"`
		Kernel      string `json:"kernel"`
		Arch        string `json:"arch"`
		LastSeen    int64  `json:"last_seen"`
		LastStatus  string `json:"last_status"`
		LastSummary string `json:"last_summary"`
		Source      string `json:"source"`
	}
	out := make([]hostDetail, 0, len(hosts))
	for _, h := range hosts {
		out = append(out, hostDetail{h.ID, h.Hostname, h.OS, h.Kernel, h.Arch, h.LastSeen, h.LastStatus, h.LastSummary, h.Source})
	}
	writeJSON(w, http.StatusOK, out)
}

// ====== GET /api/status?days= ======

func (s *Server) handleStatus(w http.ResponseWriter, r *http.Request) {
	if !requireGET(w, r) {
		return
	}
	days := s.barDays
	if v := r.URL.Query().Get("days"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 && n <= 365 {
			days = n
		}
	}
	hosts, err := s.store.ListHosts()
	if err != nil {
		log.Printf("list status hosts 失败: %v", err)
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "query failed"})
		return
	}

	type hostStatus struct {
		ID          int64        `json:"id"`
		Hostname    string       `json:"hostname"`
		LastSeen    int64        `json:"last_seen"`
		LastStatus  string       `json:"last_status"`
		LastSummary string       `json:"last_summary"`
		Daily       []DailyPoint `json:"daily"`
		Uptime      float64      `json:"uptime_pct"` // 区间总可用率
	}
	out := make([]hostStatus, 0, len(hosts))
	for _, h := range hosts {
		daily, err := s.store.DailyStatus(h.ID, days)
		if err != nil {
			log.Printf("daily status host=%d 失败: %v", h.ID, err)
			writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "query failed"})
			return
		}
		out = append(out, hostStatus{
			ID: h.ID, Hostname: h.Hostname, LastSeen: h.LastSeen,
			LastStatus: h.LastStatus, LastSummary: publicSummary(h.LastSummary),
			Daily: daily, Uptime: rangeUptime(daily),
		})
	}
	writeJSON(w, http.StatusOK, out)
}

// ====== GET /api/metrics?id=&range=1h|24h|7d ======

func (s *Server) handleMetrics(w http.ResponseWriter, r *http.Request) {
	if !requireGET(w, r) {
		return
	}
	id, err := strconv.ParseInt(r.URL.Query().Get("id"), 10, 64)
	if err != nil || id <= 0 {
		http.Error(w, "invalid id", http.StatusBadRequest)
		return
	}
	rng := parseRange(r.URL.Query().Get("range"))
	fromTs := time.Now().Add(-rng).Unix()

	pts, err := s.store.MetricsSeries(id, fromTs)
	if err != nil {
		log.Printf("metrics host=%d 失败: %v", id, err)
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "query failed"})
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

func abs(x int64) int64 {
	if x < 0 {
		return -x
	}
	return x
}

func publicSummary(raw string) string {
	var input struct {
		Mem   float64 `json:"mem"`
		Load1 float64 `json:"load1"`
		OS    string  `json:"os"`
	}
	if json.Unmarshal([]byte(raw), &input) != nil {
		return `{}`
	}
	data, err := json.Marshal(input)
	if err != nil {
		return `{}`
	}
	return string(data)
}

func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("X-Frame-Options", "DENY")
		w.Header().Set("Referrer-Policy", "same-origin")
		w.Header().Set("Permissions-Policy", "camera=(), microphone=(), geolocation=()")
		next.ServeHTTP(w, r)
	})
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
		} else if d.Status == statusDegraded || d.Status == statusDown {
			ok += d.Uptime / 100.0 // 异常天按其样本可用率计
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
