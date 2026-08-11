package service

import "testing"

func TestValidateServiceInfo(t *testing.T) {
	for _, name := range []string{"statuspigeon-agent", "status.pigeon_1"} {
		if err := validateServiceInfo(ServiceInfo{Name: name, User: "root"}); err != nil {
			t.Fatalf("valid service %q rejected: %v", name, err)
		}
	}
	for _, name := range []string{"../statuspigeon", "status/pigeon", "status\npigeon", ""} {
		if err := validateServiceInfo(ServiceInfo{Name: name, User: "root"}); err == nil {
			t.Fatalf("unsafe service name %q accepted", name)
		}
	}
	if err := validateServiceInfo(ServiceInfo{Name: "statuspigeon", User: "root\nExecStart=/bin/sh"}); err == nil {
		t.Fatal("unsafe systemd user accepted")
	}
}
