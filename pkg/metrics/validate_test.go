package metrics

import (
	"strings"
	"testing"
)

func validReport() *Report {
	return &Report{
		Hostname: "router",
		DeviceID: "sha256:device",
		Metrics: Metrics{
			Os:  OsInfo{DiskUsedPct: 42, IPv4: []string{"192.168.1.1@lan"}, IPv6: []string{"2001:db8::1@wan6"}},
			Cpu: CPUInfo{Load1: 1, Load5: 2, Load15: 3},
			Mem: MemInfo{UsedPct: 50},
		},
	}
}

func TestValidateReport(t *testing.T) {
	if err := ValidateReport(validReport()); err != nil {
		t.Fatalf("valid report rejected: %v", err)
	}

	tests := []struct {
		name   string
		mutate func(*Report)
	}{
		{"empty hostname", func(r *Report) { r.Hostname = " " }},
		{"control character", func(r *Report) { r.Hostname = "router\nforged" }},
		{"long device id", func(r *Report) { r.DeviceID = strings.Repeat("x", 513) }},
		{"memory percentage", func(r *Report) { r.Metrics.Mem.UsedPct = 101 }},
		{"negative load", func(r *Report) { r.Metrics.Cpu.Load1 = -1 }},
		{"wrong IP family", func(r *Report) { r.Metrics.Os.IPv4 = []string{"2001:db8::1@wan"} }},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			r := validReport()
			test.mutate(r)
			if err := ValidateReport(r); err == nil {
				t.Fatal("invalid report accepted")
			}
		})
	}
}
