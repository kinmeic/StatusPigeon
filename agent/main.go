// Package main: main.go — Agent 入口（双模式）。
//
// 用法：
//
//	./statuspigeon-agent -c /etc/statuspigeon/config.yaml   运行（默认）
//	./statuspigeon-agent install [-c config.yaml] [-user root]
//	./statuspigeon-agent uninstall
//
// 模式由 config.mode 决定：
//   - push   （默认）：定时采集并上报到 Hub。适合 NAT 后、无公网 IP 的机器。
//   - listen          ：暴露 GET /metrics 供 Hub 拉取。适合有公网域名的机器。
//
// 配置也可全部用环境变量 STATUSPIGEON_* 提供。
package main

import (
	"context"
	"flag"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/statuspigeon/service"
)

func main() {
	// 子命令分发：install / uninstall。其余参数走原运行流程。
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "install":
			service.RunInstall(serviceInfo())
			return
		case "uninstall":
			service.RunUninstall(serviceInfo())
			return
		}
	}

	configPath := flag.String("c", "config.yaml", "配置文件路径")
	flag.Parse()

	cfg, err := loadConfig(*configPath)
	if err != nil {
		log.Fatalf("加载配置失败: %v", err)
	}

	collector, err := NewCollector(cfg.Hostname)
	if err != nil {
		log.Fatalf("初始化采集器失败: %v", err)
	}

	switch cfg.Mode {
	case ModeListen:
		runListen(collector, cfg)
	case ModePush:
		runPush(collector, cfg)
	}
}

// serviceInfo 返回用于服务安装的基础信息。
func serviceInfo() service.ServiceInfo {
	return service.ServiceInfo{
		Name:        "statuspigeon-agent",
		Description: "Status Pigeon Agent — 服务器状态采集上报",
	}
}

// runPush 定时采集并上报。
func runPush(collector *Collector, cfg *Config) {
	reporter := NewReporter(cfg.ServerURL, cfg.Token)
	interval := time.Duration(cfg.Interval) * time.Second

	log.Printf("push 模式启动 | 主机=%s | 上报=%s | 间隔=%v",
		collector.Hostname(), cfg.ServerURL, interval)

	// 启动后立即上报一次。
	collectAndReport(collector, reporter)

	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)

	for {
		select {
		case <-ticker.C:
			collectAndReport(collector, reporter)
		case sig := <-stop:
			log.Printf("收到信号 %v，退出。", sig)
			return
		}
	}
}

// runListen 暴露 /metrics 等待 Hub 拉取。
func runListen(collector *Collector, cfg *Config) {
	listener := NewListener(collector, cfg.ListenAddr, cfg.Token)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		sig := <-stop
		log.Printf("收到信号 %v，退出。", sig)
		cancel() // 触发 listener 平滑关闭
	}()

	if err := listener.Start(ctx); err != nil {
		log.Fatalf("listen 服务启动失败: %v", err)
	}
}

// collectAndReport 采集并上报，失败只记录日志不退出。
func collectAndReport(c *Collector, r *Reporter) {
	report, err := c.Collect()
	if err != nil {
		log.Printf("采集失败: %v", err)
		return
	}
	if err := r.Send(report); err != nil {
		log.Printf("上报失败: %v", err)
		return
	}
	log.Printf("上报成功 | cpu=%.1f%% mem=%.1f%% load1=%.2f",
		report.Metrics.Cpu.Usage, report.Metrics.Mem.UsedPct, report.Metrics.Cpu.Load1)
}
