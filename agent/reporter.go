// Package main: reporter.go — 通过 HTTP 将采集结果上报到服务端。
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

// Reporter 负责上报。
type Reporter struct {
	serverURL string
	token     string
	client    *http.Client
}

// NewReporter 创建上报器。serverURL 已去除尾部斜杠。
func NewReporter(serverURL, token string) *Reporter {
	return &Reporter{
		serverURL: serverURL,
		token:     token,
		client: &http.Client{
			Timeout: 15 * time.Second,
		},
	}
}

// Send 上报一次。返回服务端响应体（用于日志）。
func (r *Reporter) Send(report *pkgmetrics.Report) error {
	body, err := json.Marshal(report)
	if err != nil {
		return fmt.Errorf("序列化上报数据: %w", err)
	}

	req, err := http.NewRequest(http.MethodPost, r.serverURL, bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("构造请求: %w", err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+r.token)

	resp, err := r.client.Do(req)
	if err != nil {
		return fmt.Errorf("上报请求失败: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("服务端拒绝 (HTTP %d): %s", resp.StatusCode, string(respBody))
	}
	return nil
}
