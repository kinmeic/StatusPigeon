// Package main: puller.go — 主动监控（pull）循环。
//
// 对 config.pull_targets 中启用的公网主机，按 pull_interval 周期
// GET 其 /metrics 拉取详细指标，拉到的数据走与 push 相同的入库流程。
//
// 拉取失败（连接超时/拒绝/鉴权失败）会记录日志；连续失联由
// MarkOffline 基于该主机上一次成功的 last_seen 判定 down。
package main

import (
	"context"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"sync"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// maxPullBodyBytes 单次拉取响应体上限，防异常端点返回超大响应耗尽内存。
const maxPullBodyBytes = 1 << 20

// Puller 主动拉取器。
type Puller struct {
	targets []HostTarget
	client  *http.Client
	store   *Store
	judge   *Judge
	tick    time.Duration
}

// NewPuller 创建拉取器。
func NewPuller(cfg *Config, store *Store, judge *Judge) *Puller {
	var enabled []HostTarget
	for _, t := range cfg.PullTargets {
		if t.Enabled && t.Endpoint != "" {
			enabled = append(enabled, t)
		}
	}
	return &Puller{
		targets: enabled,
		client: &http.Client{
			Timeout: cfg.PullTimeout,
		},
		store: store,
		judge: judge,
		tick:  cfg.PullInterval,
	}
}

// Run 阻塞运行拉取循环。首次立即拉取，之后按 tick 周期。
func (p *Puller) Run(ctx context.Context) {
	if len(p.targets) == 0 {
		log.Println("puller: 无启用的主动监控目标，跳过。")
		return
	}
	log.Printf("puller: 启动 | 目标数=%d | 间隔=%v", len(p.targets), p.tick)

	// 立即拉一次。
	p.pullAll(ctx)

	ticker := time.NewTicker(p.tick)
	defer ticker.Stop()
	for {
		select {
		case <-ticker.C:
			p.pullAll(ctx)
		case <-ctx.Done():
			return
		}
	}
}

// pullAll 并行拉取全部目标（网络并发，入库经 SQLite 单连接串行化）。
func (p *Puller) pullAll(ctx context.Context) {
	var wg sync.WaitGroup
	for _, t := range p.targets {
		wg.Add(1)
		go func(t HostTarget) {
			defer wg.Done()
			p.pullOne(ctx, t)
		}(t)
	}
	wg.Wait()
}

func (p *Puller) pullOne(ctx context.Context, t HostTarget) {
	// 绑定 ctx：Hub 关闭时立即中断在途拉取。
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, t.Endpoint, nil)
	if err != nil {
		log.Printf("puller[%s]: 构造请求失败: %v", t.Name, err)
		return
	}
	if t.Token != "" {
		req.Header.Set("Authorization", "Bearer "+t.Token)
	}

	resp, err := p.client.Do(req)
	if err != nil {
		log.Printf("puller[%s]: 拉取失败: %v", t.Name, err)
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		log.Printf("puller[%s]: 非 200 响应 (HTTP %d)", t.Name, resp.StatusCode)
		return
	}

	// 限制响应体大小。
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxPullBodyBytes))
	if err != nil {
		log.Printf("puller[%s]: 读取响应失败: %v", t.Name, err)
		return
	}
	var r pkgmetrics.Report
	if err := json.Unmarshal(body, &r); err != nil {
		log.Printf("puller[%s]: 解析响应失败: %v", t.Name, err)
		return
	}
	// 若返回体未带 hostname，以 target 名补齐（name 已经配置校验非空）。
	if r.Hostname == "" {
		r.Hostname = t.Name
	}
	// 若返回体时间戳缺失/异常，用当前时间。
	if r.Timestamp <= 0 {
		r.Timestamp = time.Now().Unix()
	}

	// 与 push 共用同一入库流程（source=pull）。
	if err := Ingest(p.store, p.judge, &r, "pull"); err != nil {
		log.Printf("puller[%s]: 入库失败: %v", t.Name, err)
		return
	}
	log.Printf("puller[%s]: 拉取成功 | mem=%.1f%% load1=%.2f",
		r.Hostname, r.Metrics.Mem.UsedPct, r.Metrics.Cpu.Load1)
}
