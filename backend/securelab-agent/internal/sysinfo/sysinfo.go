package sysinfo

import (
	"os"
	"runtime"
	"time"
)

type Process struct {
	PID   int     `json:"pid"`
	Name  string  `json:"name"`
	MemMB int     `json:"memMB"`
	CPU   float64 `json:"cpu"`
}

type Health struct {
	Hostname    string `json:"hostname"`
	Platform    string `json:"platform"`
	CPU         int    `json:"cpu"`
	Memory      int    `json:"memory"`
	DiskFree    int64  `json:"diskFree"`
	DiskTotal   int64  `json:"diskTotal"`
	Processes   int    `json:"processes"`
	Connections int    `json:"connections"`
	Uptime      int64  `json:"uptime"`
	TopProcesses []Process `json:"topProcesses"`
}

type Defender struct {
	AntivirusEnabled     bool   `json:"antivirusEnabled"`
	RealTimeProtection   bool   `json:"realTimeProtection"`
	SignatureVersion     string `json:"signatureVersion"`
	SignatureUpdated     string `json:"signatureUpdated"`
	FirewallEnabled      bool   `json:"firewallEnabled"`
	FirewallProfiles     string `json:"firewallProfiles"`
}

func hostname() string {
	h, _ := os.Hostname()
	return h
}

func platform() string {
	return runtime.GOOS
}

func now() int64 {
	return time.Now().Unix()
}
