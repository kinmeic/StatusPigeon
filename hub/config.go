// Package main: config.go — Hub 配置加载。
//
// 优先级：环境变量 > config.yaml > 默认值。
// 配置文件路径默认 ./config.yaml，可由 -c 参数覆盖。
package main

import (
	"fmt"
	"log"
	"os"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

// HostTarget 是一个被监控目标的配置。
type HostTarget struct {
	Name     string `yaml:"name"`     // 显示名/主机名（用于匹配或展示）
	Endpoint string `yaml:"endpoint"` // listen 模式地址，如 http://1.2.3.4:9527
	Token    string `yaml:"token"`    // 拉取鉴权 token（对应 agent listen token）
	Enabled  bool   `yaml:"enabled"`  // 是否启用此目标
}

// Config 是 Hub 运行配置。
type Config struct {
	// HTTP 服务
	HTTPAddr string `yaml:"http_addr"` // 监听地址，如 :9527
	Auth     string `yaml:"auth"`      // push 上报鉴权 token

	// 主动监控（pull）
	PullInterval time.Duration `yaml:"pull_interval"` // 拉取间隔，默认 5m
	PullTimeout  time.Duration `yaml:"pull_timeout"`  // 单次拉取超时，默认 10s
	PullTargets  []HostTarget  `yaml:"pull_targets"`  // 主动拉取的目标列表

	// 数据保留
	RetentionDays int `yaml:"retention_days"`  // metrics_raw/uptime_daily 保留天数，默认 90
	UptimeBarDays int `yaml:"uptime_bar_days"` // 状态页色块条天数，默认 90

	// 状态判定阈值
	DegradedCPU float64 `yaml:"degraded_cpu"` // CPU % ，默认 90
	DegradedMem float64 `yaml:"degraded_mem"` // 内存 % ，默认 95

	// 失联判定（用于 push 主机）
	ReportInterval time.Duration `yaml:"report_interval"` // 上报周期，默认 5m
	OfflinePeriods int           `yaml:"offline_periods"` // 超过 N 周期无数据判 down，默认 3

	// 清理
	CleanupInterval time.Duration `yaml:"cleanup_interval"` // 清理循环间隔，默认 1h

	// 存储
	DBPath string `yaml:"db_path"` // SQLite 文件路径，默认 ./data/statuspigeon.db
}

func loadConfig(path string) (*Config, error) {
	cfg := &Config{}

	if data, err := os.ReadFile(path); err == nil {
		if err := yaml.Unmarshal(data, cfg); err != nil {
			return nil, fmt.Errorf("解析 %s: %w", path, err)
		}
	} else if !os.IsNotExist(err) {
		return nil, fmt.Errorf("读取 %s: %w", path, err)
	}

	// 环境变量覆盖（仅关键项）。
	if v := os.Getenv("STATUSPIGEON_HTTP_ADDR"); v != "" {
		cfg.HTTPAddr = v
	}
	if v := os.Getenv("STATUSPIGEON_AUTH"); v != "" {
		cfg.Auth = v
	}
	if v := os.Getenv("STATUSPIGEON_DB_PATH"); v != "" {
		cfg.DBPath = v
	}
	if v := os.Getenv("STATUSPIGEON_PULL_INTERVAL"); v != "" {
		if d, err := time.ParseDuration(v); err == nil {
			cfg.PullInterval = d
		}
	}

	// 默认值。
	if cfg.HTTPAddr == "" {
		cfg.HTTPAddr = ":9527"
	}
	if cfg.PullInterval <= 0 {
		cfg.PullInterval = 5 * time.Minute
	}
	if cfg.PullTimeout <= 0 {
		cfg.PullTimeout = 10 * time.Second
	}
	if cfg.ReportInterval <= 0 {
		cfg.ReportInterval = 5 * time.Minute
	}
	if cfg.OfflinePeriods <= 0 {
		cfg.OfflinePeriods = 3
	}
	if cfg.RetentionDays <= 0 {
		cfg.RetentionDays = 90
	}
	if cfg.UptimeBarDays <= 0 {
		cfg.UptimeBarDays = 90
	}
	if cfg.DegradedCPU <= 0 {
		cfg.DegradedCPU = 90
	}
	if cfg.DegradedMem <= 0 {
		cfg.DegradedMem = 95
	}
	if cfg.CleanupInterval <= 0 {
		cfg.CleanupInterval = time.Hour
	}
	if cfg.DBPath == "" {
		cfg.DBPath = "data/statuspigeon.db"
	}
	cfg.Auth = strings.TrimSpace(cfg.Auth)

	// 安全提示：无鉴权运行 /report 意味着任何人都能伪造上报。
	if cfg.Auth == "" {
		log.Println("警告: 未配置 auth，POST /report 将无鉴权接收上报（公网环境务必配置）")
	}

	// 校验主动拉取目标：name 是 hostname 的回退值，endpoint 是拉取地址，均不可缺。
	for i := range cfg.PullTargets {
		t := &cfg.PullTargets[i]
		if !t.Enabled {
			continue
		}
		t.Name = strings.TrimSpace(t.Name)
		t.Endpoint = strings.TrimSpace(t.Endpoint)
		if t.Name == "" {
			return nil, fmt.Errorf("pull_targets[%d] 已启用但缺少 name（用于主机标识）", i)
		}
		if t.Endpoint == "" {
			return nil, fmt.Errorf("pull_targets[%d] (%s) 已启用但缺少 endpoint", i, t.Name)
		}
	}

	return cfg, nil
}
