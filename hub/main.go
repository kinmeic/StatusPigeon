// Package main: main.go — Hub（监控中心）入口。
//
// Hub 同时承担：
//   - POST /report 接收 agent push 上报
//   - 后台主动拉取配置的公网主机（pull）
//   - GET /api/* 查询接口（返回 JSON）
//   - GET / 静态状态页（embed 打包前端）
//
// 用法：
//
//	./statuspigeon-hub -c config.yaml        运行（默认）
//	./statuspigeon-hub install [-c config.yaml] [-user root]
//	./statuspigeon-hub uninstall
package main

import (
	"context"
	"embed"
	"flag"
	"log"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/statuspigeon/service"
)

//go:embed assets/*
var assetsFS embed.FS

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

	store, err := NewStore(cfg.DBPath)
	if err != nil {
		log.Fatalf("初始化数据库失败: %v", err)
	}
	defer store.Close()

	judge := &Judge{CPUThreshold: cfg.DegradedCPU, MemThreshold: cfg.DegradedMem}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// 后台：主动拉取循环 + 失联扫描/数据清理。用 WaitGroup 等待其退出，
	// 避免 main 返回后 store.Close() 与在途写库竞争。
	var wg sync.WaitGroup
	puller := NewPuller(cfg, store, judge)
	wg.Add(1)
	go func() {
		defer wg.Done()
		puller.Run(ctx)
	}()
	wg.Add(1)
	go func() {
		defer wg.Done()
		RunMaintenance(ctx, store, cfg)
	}()

	srv := NewServer(store, judge, cfg.Auth, cfg.AllowUnauthenticatedReports, cfg.UptimeBarDays, assetsFS)
	httpSrv := &http.Server{
		Addr:              cfg.HTTPAddr,
		Handler:           srv.Routes(),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      30 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    64 << 10,
	}

	// 优雅退出：先停后台循环，再平滑关闭 HTTP（等待在途请求完成）。
	go func() {
		sig := make(chan os.Signal, 1)
		signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)
		s := <-sig
		log.Printf("收到信号 %v，关闭中...", s)
		cancel()
		shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer shutdownCancel()
		_ = httpSrv.Shutdown(shutdownCtx)
	}()

	log.Printf("Status-Pigeon Hub 启动 | 地址=%s | 拉取间隔=%v | 保留=%d天",
		cfg.HTTPAddr, cfg.PullInterval, cfg.RetentionDays)
	if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("HTTP 服务失败: %v", err)
	}
	// HTTP 已停止；若由外部错误而非信号触发退出，也确保后台循环终止。
	cancel()
	wg.Wait()
}

// serviceInfo 返回用于服务安装的基础信息。
func serviceInfo() service.ServiceInfo {
	return service.ServiceInfo{
		Name:        "statuspigeon-hub",
		Description: "Status Pigeon Hub — 监控中心（接收/拉取/存储/状态页）",
	}
}
