package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"

	pkgmetrics "github.com/statuspigeon/metrics"
)

func TestReportAuthenticationAndBodyLimit(t *testing.T) {
	store, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	server := NewServer(store, &Judge{MemThreshold: 95}, "secret", false, 30, assetsFS)

	request := httptest.NewRequest(http.MethodPost, "/report", strings.NewReader(`{}`))
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()
	server.Routes().ServeHTTP(response, request)
	if response.Code != http.StatusUnauthorized {
		t.Fatalf("missing auth status=%d, want 401", response.Code)
	}

	request = httptest.NewRequest(http.MethodPost, "/report", strings.NewReader(strings.Repeat("x", maxReportBodyBytes+1)))
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Authorization", "Bearer secret")
	response = httptest.NewRecorder()
	server.Routes().ServeHTTP(response, request)
	if response.Code != http.StatusRequestEntityTooLarge {
		t.Fatalf("oversize status=%d, want 413", response.Code)
	}
}

func TestReportWithoutConfiguredAuthenticationFailsClosed(t *testing.T) {
	store, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	server := NewServer(store, &Judge{MemThreshold: 95}, "", false, 30, assetsFS)
	request := httptest.NewRequest(http.MethodPost, "/report", strings.NewReader(`{}`))
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()
	server.Routes().ServeHTTP(response, request)
	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("status=%d, want 503", response.Code)
	}
}

func TestPublicStatusRedactsDeviceDetails(t *testing.T) {
	store, err := NewStore(filepath.Join(t.TempDir(), "test.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	report := &pkgmetrics.Report{
		AgentVersion: "private-agent-version",
		DeviceID:     "private-device-id",
		Hostname:     "router",
		Timestamp:    time.Now().Unix(),
		Metrics: pkgmetrics.Metrics{
			Os:  pkgmetrics.OsInfo{Os: "OpenWrt", CPUModel: "private-model", IPv4: []string{"192.168.1.1@lan"}},
			Cpu: pkgmetrics.CPUInfo{Load1: 1},
			Mem: pkgmetrics.MemInfo{UsedPct: 50},
		},
	}
	if err := Ingest(store, &Judge{MemThreshold: 95}, report, "push"); err != nil {
		t.Fatal(err)
	}
	server := NewServer(store, &Judge{MemThreshold: 95}, "secret", false, 30, assetsFS)
	request := httptest.NewRequest(http.MethodGet, "/api/status?days=1", nil)
	response := httptest.NewRecorder()
	server.Routes().ServeHTTP(response, request)
	if response.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", response.Code, response.Body.String())
	}
	body := response.Body.String()
	for _, secret := range []string{"private-device-id", "private-agent-version", "private-model", "192.168.1.1"} {
		if strings.Contains(body, secret) {
			t.Fatalf("public status leaked %q: %s", secret, body)
		}
	}
	var decoded []map[string]interface{}
	if err := json.NewDecoder(bytes.NewBufferString(body)).Decode(&decoded); err != nil || len(decoded) != 1 {
		t.Fatalf("invalid response: %v", err)
	}
}
