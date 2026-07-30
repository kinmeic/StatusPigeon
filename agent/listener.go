// Package main: listener.go — pull 模式。
//
// Agent 暴露 GET /metrics 供 Hub 主动拉取。
// 适合有公网域名、Hub 可直接访问的主机：这类主机无需主动 push，
// 由 Hub 按配置的「主动监控间隔」来拉取。
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"time"
)

// Listener pull 模式服务端，按需采集并返回最新 Report。
type Listener struct {
	collector *Collector
	addr      string
	token     string
}

// NewListener 创建拉取服务。addr 形如 ":9527"；token 为空则不鉴权。
func NewListener(collector *Collector, addr, token string) *Listener {
	return &Listener{collector: collector, addr: addr, token: token}
}

// Start 阻塞运行 HTTP 服务。
func (l *Listener) Start() error {
	mux := http.NewServeMux()
	mux.HandleFunc("/metrics", l.handleMetrics)
	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok"))
	})

	srv := &http.Server{
		Addr:              l.addr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}
	log.Printf("listen 模式启动 | 主机=%s | 地址=%s", l.collector.Hostname(), l.addr)
	return srv.ListenAndServe()
}

func (l *Listener) handleMetrics(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	// token 鉴权（Bearer）。token 为空时跳过。
	if l.token != "" {
		if r.Header.Get("Authorization") != "Bearer "+l.token {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
	}

	report, err := l.collector.Collect()
	if err != nil {
		log.Printf("采集失败: %v", err)
		http.Error(w, "collect failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(report)
}
