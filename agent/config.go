// Package main: config.go — Agent 配置加载。
//
// 优先级：环境变量 > config.yaml > 默认值。
// 配置文件路径默认 ./config.yaml，可由 -c 参数覆盖。
package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// Mode 运行模式。
const (
	ModePush   = "push"   // 主动上报到 Hub（NAT 后机器）
	ModeListen = "listen" // 暴露 /metrics 供 Hub 拉取（有公网域名机器）
)

// Config 是 Agent 运行配置。
type Config struct {
	// 公共
	Mode     string `yaml:"mode"`     // push | listen，默认 push
	Hostname string `yaml:"hostname"` // 留空则取系统主机名

	// push 模式
	ServerURL string `yaml:"server_url"` // 上报地址，如 http://hub/report
	Token     string `yaml:"token"`      // 与 Hub AUTH_TOKEN 一致
	Interval  int    `yaml:"interval"`   // 上报间隔（秒），默认 300

	// listen 模式
	ListenAddr string `yaml:"listen_addr"` // 监听地址，如 :9527
}

// loadConfig 从 path 读取 YAML，再以环境变量覆盖，最后补默认值。
func loadConfig(path string) (*Config, error) {
	cfg := &Config{}

	if data, err := os.ReadFile(path); err == nil {
		if err := yaml.Unmarshal(data, cfg); err != nil {
			return nil, fmt.Errorf("解析 %s: %w", path, err)
		}
	} else if !os.IsNotExist(err) {
		return nil, fmt.Errorf("读取 %s: %w", path, err)
	}

	// 环境变量覆盖。
	if v := os.Getenv("STATUSPIGEON_MODE"); v != "" {
		cfg.Mode = v
	}
	if v := os.Getenv("STATUSPIGEON_HOSTNAME"); v != "" {
		cfg.Hostname = v
	}
	if v := os.Getenv("STATUSPIGEON_SERVER_URL"); v != "" {
		cfg.ServerURL = v
	}
	if v := os.Getenv("STATUSPIGEON_TOKEN"); v != "" {
		cfg.Token = v
	}
	if v := os.Getenv("STATUSPIGEON_INTERVAL"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 {
			cfg.Interval = n
		}
	}
	if v := os.Getenv("STATUSPIGEON_LISTEN_ADDR"); v != "" {
		cfg.ListenAddr = v
	}

	// 默认值与校验。
	if cfg.Mode == "" {
		cfg.Mode = ModePush
	}
	cfg.Mode = strings.ToLower(strings.TrimSpace(cfg.Mode))
	if cfg.Mode != ModePush && cfg.Mode != ModeListen {
		return nil, fmt.Errorf("mode 必须是 push 或 listen，当前: %s", cfg.Mode)
	}
	if cfg.Interval <= 0 {
		cfg.Interval = 300
	}
	cfg.ServerURL = strings.TrimRight(strings.TrimSpace(cfg.ServerURL), "/")

	switch cfg.Mode {
	case ModePush:
		if cfg.ServerURL == "" {
			return nil, fmt.Errorf("push 模式需配置 server_url")
		}
		if cfg.Token == "" {
			return nil, fmt.Errorf("push 模式需配置 token")
		}
	case ModeListen:
		if cfg.ListenAddr == "" {
			cfg.ListenAddr = ":9527"
		}
	}
	return cfg, nil
}
