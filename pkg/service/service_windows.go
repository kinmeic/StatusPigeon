//go:build windows

// service_windows.go — Windows 平台服务安装。
//
// 通过 sc.exe 注册/删除 Windows 服务。
// 注意：Windows 服务需程序本身实现 Service Main 入口才能被 SCM 正常托管运行；
// 此处仅完成注册/删除。完整 Windows 服务运行支持如需可后续用
// golang.org/x/sys/windows/svc 扩展。
package service

import (
	"fmt"
	"path/filepath"
	"strings"
)

func install(info ServiceInfo) error {
	bin, err := resolveBinary()
	if err != nil {
		return fmt.Errorf("解析二进制路径: %w", err)
	}
	info.Binary = bin

	// sc create 需要 binPath= 形式（带等号），参数拼入。
	args := append([]string{info.Binary}, info.Args...)
	for i, a := range args {
		args[i] = `"` + strings.ReplaceAll(a, `"`, `\"`) + `"`
	}
	binPath := strings.Join(args, " ")

	if err := runCmd("sc", "create", info.Name, "binPath=", binPath,
		"start=", "auto", "DisplayName=", info.Description); err != nil {
		return fmt.Errorf("sc create: %w", err)
	}
	if err := runCmd("sc", "description", info.Name, info.Description); err != nil {
		return fmt.Errorf("sc description: %w", err)
	}
	if err := runCmd("sc", "start", info.Name); err != nil {
		return fmt.Errorf("sc start: %w", err)
	}
	return nil
}

func uninstall(info ServiceInfo) error {
	_ = runCmd("sc", "stop", info.Name)
	if err := runCmd("sc", "delete", info.Name); err != nil {
		return fmt.Errorf("sc delete: %w", err)
	}
	return nil
}

// 避免未用导入（filepath 在 Windows 分支可能未直接使用，保留以备扩展）。
var _ = filepath.Dir
