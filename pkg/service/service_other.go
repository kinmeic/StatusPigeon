//go:build !linux && !darwin && !windows

// service_other.go — 不支持的平台占位实现，保证构建不报错。
package service

import "fmt"

func install(info ServiceInfo) error {
	return fmt.Errorf("当前平台不支持服务安装")
}

func uninstall(info ServiceInfo) error {
	return fmt.Errorf("当前平台不支持服务卸载")
}
