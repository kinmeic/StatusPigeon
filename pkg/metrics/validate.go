package metrics

import (
	"fmt"
	"math"
	"net"
	"strings"
	"unicode"
	"unicode/utf8"
)

const (
	maxHostnameBytes     = 255
	maxDeviceIDBytes     = 512
	maxAgentVersionBytes = 128
	maxSystemFieldBytes  = 512
	maxIPEntries         = 64
	maxIPEntryBytes      = 192
	maxLoadAverage       = 1_000_000
)

// ValidateReport validates the untrusted portion of the Agent/Hub contract.
// Timestamp freshness is transport-specific and is checked by the receiver.
func ValidateReport(r *Report) error {
	if r == nil {
		return fmt.Errorf("report is required")
	}
	if err := validateRequiredString("hostname", r.Hostname, maxHostnameBytes); err != nil {
		return err
	}
	if err := validateOptionalString("device_id", r.DeviceID, maxDeviceIDBytes); err != nil {
		return err
	}
	if err := validateOptionalString("agent_version", r.AgentVersion, maxAgentVersionBytes); err != nil {
		return err
	}
	for name, value := range map[string]string{
		"metrics.os.os":        r.Metrics.Os.Os,
		"metrics.os.version":   r.Metrics.Os.Version,
		"metrics.os.kernel":    r.Metrics.Os.Kernel,
		"metrics.os.arch":      r.Metrics.Os.Arch,
		"metrics.os.cpu_model": r.Metrics.Os.CPUModel,
	} {
		if err := validateOptionalString(name, value, maxSystemFieldBytes); err != nil {
			return err
		}
	}
	if err := validatePercent("metrics.mem.used_pct", r.Metrics.Mem.UsedPct); err != nil {
		return err
	}
	if err := validatePercent("metrics.os.disk_used_pct", r.Metrics.Os.DiskUsedPct); err != nil {
		return err
	}
	for name, value := range map[string]float64{
		"metrics.cpu.load1":  r.Metrics.Cpu.Load1,
		"metrics.cpu.load5":  r.Metrics.Cpu.Load5,
		"metrics.cpu.load15": r.Metrics.Cpu.Load15,
	} {
		if math.IsNaN(value) || math.IsInf(value, 0) || value < 0 || value > maxLoadAverage {
			return fmt.Errorf("%s is out of range", name)
		}
	}
	if err := validateIPList("metrics.os.ipv4", r.Metrics.Os.IPv4, 4); err != nil {
		return err
	}
	if err := validateIPList("metrics.os.ipv6", r.Metrics.Os.IPv6, 6); err != nil {
		return err
	}
	return nil
}

func validateRequiredString(name, value string, max int) error {
	if strings.TrimSpace(value) == "" {
		return fmt.Errorf("%s is required", name)
	}
	return validateOptionalString(name, value, max)
}

func validateOptionalString(name, value string, max int) error {
	if len(value) > max {
		return fmt.Errorf("%s is too long", name)
	}
	if !utf8.ValidString(value) {
		return fmt.Errorf("%s is not valid UTF-8", name)
	}
	if strings.IndexFunc(value, unicode.IsControl) >= 0 {
		return fmt.Errorf("%s contains control characters", name)
	}
	return nil
}

func validatePercent(name string, value float64) error {
	if math.IsNaN(value) || math.IsInf(value, 0) || value < 0 || value > 100 {
		return fmt.Errorf("%s is out of range", name)
	}
	return nil
}

func validateIPList(name string, values []string, family int) error {
	if len(values) > maxIPEntries {
		return fmt.Errorf("%s has too many entries", name)
	}
	for _, value := range values {
		if err := validateOptionalString(name, value, maxIPEntryBytes); err != nil {
			return err
		}
		address := strings.TrimSpace(value)
		if index := strings.LastIndex(address, "@"); index > 0 {
			address = address[:index]
		}
		ip := net.ParseIP(address)
		if ip == nil || (family == 4 && ip.To4() == nil) || (family == 6 && ip.To4() != nil) {
			return fmt.Errorf("%s contains an invalid address", name)
		}
	}
	return nil
}
