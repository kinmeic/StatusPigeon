//go:build linux

// service_linux.go — Linux 平台服务安装。
//
// 运行时判断：
//   - 若存在 /etc/openwrt_release → OpenWrt，生成 procd init 脚本。
//   - 否则 → 普通 Linux，生成 systemd unit。
package service

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// openwrtReleaseFile 存在即视为 OpenWrt 环境。
const openwrtReleaseFile = "/etc/openwrt_release"

func isOpenWrt() bool {
	_, err := os.Stat(openwrtReleaseFile)
	return err == nil
}

func install(info ServiceInfo) error {
	// 补全默认值。
	bin, err := resolveBinary()
	if err != nil {
		return fmt.Errorf("解析二进制路径: %w", err)
	}
	info.Binary = bin
	if info.WorkDir == "" {
		info.WorkDir = filepath.Dir(bin)
	}
	if info.User == "" {
		info.User = "root"
	}

	if isOpenWrt() {
		return installProcd(info)
	}
	return installSystemd(info)
}

func uninstall(info ServiceInfo) error {
	if isOpenWrt() {
		return uninstallProcd(info)
	}
	return uninstallSystemd(info)
}

// ====== systemd ======

func systemdUnitPath(name string) string {
	return "/etc/systemd/system/" + name + ".service"
}

func installSystemd(info ServiceInfo) error {
	unitPath := systemdUnitPath(info.Name)
	execStart := info.Binary
	if len(info.Args) > 0 {
		execStart += " " + strings.Join(info.Args, " ")
	}
	unit := fmt.Sprintf(`[Unit]
Description=%s
After=network.target

[Service]
Type=simple
User=%s
WorkingDirectory=%s
ExecStart=%s
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
`, info.Description, info.User, info.WorkDir, execStart)

	if err := writeFile(unitPath, []byte(unit), 0o644); err != nil {
		return fmt.Errorf("写入 unit 文件: %w", err)
	}
	if err := runCmd("systemctl", "daemon-reload"); err != nil {
		return fmt.Errorf("daemon-reload: %w", err)
	}
	if err := runCmd("systemctl", "enable", info.Name); err != nil {
		return fmt.Errorf("enable: %w", err)
	}
	if err := runCmd("systemctl", "start", info.Name); err != nil {
		return fmt.Errorf("start: %w", err)
	}
	return nil
}

func uninstallSystemd(info ServiceInfo) error {
	// 即使服务不存在也忽略错误继续清理。
	_ = runCmd("systemctl", "stop", info.Name)
	_ = runCmd("systemctl", "disable", info.Name)

	unitPath := systemdUnitPath(info.Name)
	if err := os.Remove(unitPath); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("删除 unit 文件: %w", err)
	}
	_ = runCmd("systemctl", "daemon-reload")
	return nil
}

// ====== OpenWrt procd ======

func procdInitPath(name string) string {
	return "/etc/init.d/" + name
}

// procdScript 生成 OpenWrt procd init 脚本。
func procdScript(info ServiceInfo) string {
	args := append([]string{info.Binary}, info.Args...)
	// 为 shell 安全转义，简单用双引号包裹。
	for i, a := range args {
		args[i] = `"` + strings.ReplaceAll(a, `"`, `\"`) + `"`
	}
	execLine := strings.Join(args, " ")

	return fmt.Sprintf(`#!/bin/sh /etc/rc.common

USE_PROCD=1
START=99
STOP=10

start_service() {
	procd_open_instance
	procd_set_param command %s
	procd_set_param working_directory %s
	procd_set_param respawn
	procd_set_param stdout 1
	procd_set_param stderr 1
	procd_close_instance
}
`, execLine, info.WorkDir)
}

func installProcd(info ServiceInfo) error {
	scriptPath := procdInitPath(info.Name)
	if err := writeFile(scriptPath, []byte(procdScript(info)), 0o755); err != nil {
		return fmt.Errorf("写入 init 脚本: %w", err)
	}
	if err := runCmd(scriptPath, "enable"); err != nil {
		return fmt.Errorf("enable: %w", err)
	}
	if err := runCmd(scriptPath, "start"); err != nil {
		return fmt.Errorf("start: %w", err)
	}
	return nil
}

func uninstallProcd(info ServiceInfo) error {
	scriptPath := procdInitPath(info.Name)
	_ = runCmd(scriptPath, "disable")
	_ = runCmd(scriptPath, "stop")
	if err := os.Remove(scriptPath); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("删除 init 脚本: %w", err)
	}
	return nil
}
