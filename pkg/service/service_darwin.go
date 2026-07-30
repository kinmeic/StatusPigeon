//go:build darwin

// service_darwin.go — macOS 平台服务安装（launchd）。
//
// 生成 /Library/LaunchDaemons/<name>.plist 并 launchctl load。
package service

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

func install(info ServiceInfo) error {
	bin, err := resolveBinary()
	if err != nil {
		return fmt.Errorf("解析二进制路径: %w", err)
	}
	info.Binary = bin
	if info.WorkDir == "" {
		info.WorkDir = filepath.Dir(bin)
	}

	plistPath := launchdPlistPath(info.Name)
	plist := buildPlist(info)
	if err := writeFile(plistPath, []byte(plist), 0o644); err != nil {
		return fmt.Errorf("写入 plist: %w", err)
	}
	if err := runCmd("launchctl", "load", "-w", plistPath); err != nil {
		return fmt.Errorf("launchctl load: %w", err)
	}
	return nil
}

func uninstall(info ServiceInfo) error {
	plistPath := launchdPlistPath(info.Name)
	_ = runCmd("launchctl", "unload", plistPath)
	if err := os.Remove(plistPath); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("删除 plist: %w", err)
	}
	return nil
}

func launchdPlistPath(name string) string {
	return "/Library/LaunchDaemons/" + name + ".plist"
}

func buildPlist(info ServiceInfo) string {
	// ProgramArguments: 二进制 + 启动参数。
	progArgs := append([]string{info.Binary}, info.Args...)
	var b strings.Builder
	b.WriteString(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>` + info.Name + `</string>
    <key>ProgramArguments</key>
    <array>
`)
	for _, a := range progArgs {
		b.WriteString("        <string>" + plistEscape(a) + "</string>\n")
	}
	b.WriteString(`    </array>
    <key>WorkingDirectory</key>
    <string>` + plistEscape(info.WorkDir) + `</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/tmp/` + info.Name + `.log</string>
    <key>StandardErrorPath</key>
    <string>/tmp/` + info.Name + `.err</string>
</dict>
</plist>
`)
	return b.String()
}

func plistEscape(s string) string {
	r := strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;")
	return r.Replace(s)
}
