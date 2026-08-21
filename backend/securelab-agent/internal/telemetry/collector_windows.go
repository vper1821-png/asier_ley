//go:build windows

package telemetry

import (
	"encoding/json"
	"os"
	"os/exec"
	"runtime"
	"time"

	"securelab-agent/internal/models"
)

type winSample struct {
	CPU       int   `json:"cpu"`
	Mem       int   `json:"mem"`
	DiskTotal int64 `json:"diskTotal"`
	DiskFree  int64 `json:"diskFree"`
	Processes int   `json:"processes"`
	Up        int64 `json:"up"`
}

func collect() models.TelemetryData {
	host, _ := os.Hostname()
	data := models.TelemetryData{
		Timestamp:   time.Now().Unix(),
		Hostname:    host,
		Platform:    runtime.GOOS,
		Arch:        runtime.GOARCH,
		Connections: 0,
	}
	sample := readWinSample()
	if sample == nil {
		data.CPU = 0
		data.Memory = 0
		data.DiskFree = 0
		data.DiskTotal = 0
		data.Processes = 0
		data.Uptime = 0
		return data
	}
	data.CPU = sample.CPU
	data.Memory = sample.Mem
	data.DiskFree = sample.DiskFree
	data.DiskTotal = sample.DiskTotal
	data.Processes = sample.Processes
	data.Uptime = sample.Up
	return data
}

func readWinSample() *winSample {
	ps := `$ErrorActionPreference='SilentlyContinue'
$os=Get-CimInstance Win32_OperatingSystem
$cpu=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average
$d=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
[PSCustomObject]@{
 cpu=[int][math]::Round($cpu,0)
 mem=[int][math]::Round((($os.TotalVisibleMemorySize-$os.FreePhysicalMemory)/$os.TotalVisibleMemorySize)*100,0)
 diskTotal=[long]$d.Size
 diskFree=[long]$d.FreeSpace
 processes=(Get-Process).Count
 up=[long](((Get-Date)-$os.LastBootUpTime).TotalSeconds)
} | ConvertTo-Json -Compress`
	out, err := exec.Command("powershell", "-NoProfile", "-NonInteractive", "-Command", ps).Output()
	if err != nil {
		return nil
	}
	var s winSample
	if err := json.Unmarshal(out, &s); err != nil {
		return nil
	}
	return &s
}
