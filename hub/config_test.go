package main

import (
	"os"
	"path/filepath"
	"testing"
)

func writeHubConfig(t *testing.T, contents string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "config.yaml")
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestHubReportAuthFailsClosed(t *testing.T) {
	if _, err := loadConfig(writeHubConfig(t, "")); err == nil {
		t.Fatal("hub accepted an empty report token")
	}
	if _, err := loadConfig(writeHubConfig(t, "allow_unauthenticated_reports: true\n")); err != nil {
		t.Fatalf("explicit development opt-out rejected: %v", err)
	}
}

func TestHubRejectsInvalidPullEndpoint(t *testing.T) {
	path := writeHubConfig(t, "auth: secret\npull_targets:\n  - name: target\n    endpoint: file:///etc/passwd\n    enabled: true\n")
	if _, err := loadConfig(path); err == nil {
		t.Fatal("hub accepted a non-HTTP pull endpoint")
	}
}
