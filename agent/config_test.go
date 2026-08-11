package main

import (
	"os"
	"path/filepath"
	"testing"
)

func writeAgentConfig(t *testing.T, contents string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "config.yaml")
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestPushConfigPreservesReportDirectoryURL(t *testing.T) {
	path := writeAgentConfig(t, "mode: push\nserver_url: https://example.com/report/\ntoken: secret\n")
	cfg, err := loadConfig(path)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ServerURL != "https://example.com/report/" {
		t.Fatalf("server URL=%q", cfg.ServerURL)
	}
}

func TestListenConfigFailsClosed(t *testing.T) {
	path := writeAgentConfig(t, "mode: listen\n")
	if _, err := loadConfig(path); err == nil {
		t.Fatal("listen mode accepted an empty token")
	}
	path = writeAgentConfig(t, "mode: listen\nallow_unauthenticated_listen: true\n")
	if _, err := loadConfig(path); err != nil {
		t.Fatalf("explicit development opt-out rejected: %v", err)
	}
}
