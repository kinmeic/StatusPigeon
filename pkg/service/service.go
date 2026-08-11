// Package service 提供将二进制安装/卸载为系统服务的能力。
//
// 命令形式：
//
//	<binary> install   [-c config.yaml] [-user root] [-name service-name]
//	<binary> uninstall [-name service-name]
//	<binary>            （无子命令 → 原运行行为，本包不介入）
//
// 平台支持：Linux(systemd) / OpenWrt(procd) / macOS(launchd) / Windows。
// 具体实现在各 service_<os>.go 中按构建标签隔离。
package service

import (
	"flag"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"unicode"
)

var (
	serviceNamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	serviceUserPattern = regexp.MustCompile(`^[A-Za-z0-9_.@-]{1,128}$`)
)

// ServiceInfo 描述一个待安装/卸载的服务。
type ServiceInfo struct {
	Name        string   // 服务名，如 statuspigeon-agent
	Description string   // 服务描述
	Binary      string   // 二进制绝对路径（os.Executable 解析）
	ConfigPath  string   // -c 配置文件绝对路径（可选）
	WorkDir     string   // 工作目录（默认二进制所在目录）
	User        string   // 运行用户（systemd 用，默认 root）
	Args        []string // 启动参数，如 ["-c","/etc/statuspigeon/config.yaml"]
}

// RunInstall 解析 install 子命令的 flag 并调用平台实现。
// info 应已填好 Name/Description/Binary 等；ConfigPath/User 可被 flag 覆盖。
func RunInstall(info ServiceInfo) {
	fs := flag.NewFlagSet("install", flag.ExitOnError)
	configPath := fs.String("c", info.ConfigPath, "配置文件路径（将被转为绝对路径写入服务配置）")
	user := fs.String("user", info.User, "运行服务的系统用户（仅 Linux systemd 生效）")
	name := fs.String("name", info.Name, "服务名")
	if err := fs.Parse(os.Args[2:]); err != nil {
		os.Exit(2)
	}

	info.Name = *name
	info.User = *user
	info.ConfigPath = *configPath
	if err := validateServiceInfo(info); err != nil {
		fmt.Fprintf(os.Stderr, "安装失败: %v\n", err)
		os.Exit(1)
	}

	// 转绝对路径，确保系统服务（CWD 不同）能找到。
	if info.ConfigPath != "" {
		if abs, err := filepath.Abs(info.ConfigPath); err == nil {
			info.ConfigPath = abs
		}
	}

	info.Args = buildArgs(info)
	if err := install(info); err != nil {
		fmt.Fprintf(os.Stderr, "安装失败: %v\n", err)
		os.Exit(1)
	}
	fmt.Printf("服务 %s 已安装并启动。\n", info.Name)
}

// RunUninstall 解析 uninstall 子命令的 flag 并调用平台实现。
func RunUninstall(info ServiceInfo) {
	fs := flag.NewFlagSet("uninstall", flag.ExitOnError)
	name := fs.String("name", info.Name, "服务名")
	if err := fs.Parse(os.Args[2:]); err != nil {
		os.Exit(2)
	}
	info.Name = *name
	if !serviceNamePattern.MatchString(info.Name) {
		fmt.Fprintln(os.Stderr, "卸载失败: 服务名只能包含字母、数字、点、下划线和连字符")
		os.Exit(1)
	}

	if err := uninstall(info); err != nil {
		fmt.Fprintf(os.Stderr, "卸载失败: %v\n", err)
		os.Exit(1)
	}
	fmt.Printf("服务 %s 已卸载。\n", info.Name)
}

func validateServiceInfo(info ServiceInfo) error {
	if !serviceNamePattern.MatchString(info.Name) {
		return fmt.Errorf("服务名只能包含字母、数字、点、下划线和连字符")
	}
	if info.User != "" && !serviceUserPattern.MatchString(info.User) {
		return fmt.Errorf("运行用户包含不允许的字符")
	}
	if strings.IndexFunc(info.ConfigPath, unicode.IsControl) >= 0 {
		return fmt.Errorf("配置文件路径包含控制字符")
	}
	return nil
}

// buildArgs 组装服务的启动参数。当前仅 -c <config>。
func buildArgs(info ServiceInfo) []string {
	var args []string
	if info.ConfigPath != "" {
		args = append(args, "-c", info.ConfigPath)
	}
	return args
}

// resolveBinary 解析当前可执行文件的绝对路径。
func resolveBinary() (string, error) {
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	return filepath.EvalSymlinks(exe)
}

// writeFile 写文件并设置权限。
func writeFile(path string, data []byte, perm os.FileMode) error {
	return os.WriteFile(path, data, perm)
}

// runCmd 运行命令，合并输出到当前进程。
func runCmd(name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	return cmd.Run()
}
