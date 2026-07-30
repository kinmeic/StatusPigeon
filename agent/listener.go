// Package main: listener.go — pull 模式。
//
// Agent 暴露 GET /metrics 供 Hub 主动拉取。
// 适合有公网域名、Hub 可直接访问的主机：这类主机无需主动 push，
// 由 Hub 按配置的「主动监控间隔」来拉取。
package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"log"
	"net/http"
	"sync"
	"time"
)

// metricsCacheTTL 采集结果缓存时长。CPU 使用率采样本身阻塞约 500ms，
// 缓存可避免公网上的并发请求触发采集放大（DoS 缓解）。
const metricsCacheTTL = 5 * time.Second

// Listener pull 模式服务端，按需采集并返回最新 Report。
type Listener struct {
	collector *Collector
	addr      string
	token     string

	// 最近一次采集结果的缓存（JSON 序列化后）。
	mu       sync.Mutex
	cached   []byte
	cachedAt time.Time
}

// NewListener 创建拉取服务。addr 形如 ":9527"；token 为空则不鉴权。
func NewListener(collector *Collector, addr, token string) *Listener {
	return &Listener{collector: collector, addr: addr, token: token}
}

// Start 阻塞运行 HTTP 服务；ctx 取消时平滑关闭（等待在途请求完成）。
func (l *Listener) Start(ctx context.Context) error {
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
	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(shutdownCtx)
	}()

	log.Printf("listen 模式启动 | 主机=%s | 地址=%s", l.collector.Hostname(), l.addr)
	if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		return err
	}
	return nil
}

func (l *Listener) handleMetrics(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	// token 鉴权（Bearer，恒定时间比较）。token 为空时跳过。
	if l.token != "" {
		got := r.Header.Get("Authorization")
		if subtle.ConstantTimeCompare([]byte(got), []byte("Bearer "+l.token)) != 1 {
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}
	}

	data, err := l.latest()
	if err != nil {
		log.Printf("采集失败: %v", err)
		http.Error(w, "collect failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(data)
}

// latest 返回最近一次采集结果（JSON）。TTL 内直接复用缓存，
// 过期后重新采集；互斥保证并发请求至多触发一次采集。
func (l *Listener) latest() ([]byte, error) {
	l.mu.Lock()
	defer l.mu.Unlock()

	if l.cached != nil && time.Since(l.cachedAt) < metricsCacheTTL {
		return l.cached, nil
	}
	report, err := l.collector.Collect()
	if err != nil {
		return nil, err
	}
	data, err := json.Marshal(report)
	if err != nil {
		return nil, err
	}
	l.cached, l.cachedAt = data, time.Now()
	return data, nil
}
