//go:build windows

package telemetry

import (
	"os"
	"os/exec"
	"runtime"
	"strconv"
	"strings"
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
	// Usar WMIC para obtener CPU más simple
	cpuCmd := exec.Command("wmic", "cpu", "get", "loadpercentage", "/value")
	cpuOut, err := cpuCmd.Output()
	if err != nil {
		return nil
	}
	cpuStr := strings.TrimSpace(string(cpuOut))
	cpuStr = strings.ReplaceAll(cpuStr, "LoadPercentage=", "")
	cpuStr = strings.TrimSpace(cpuStr)
	cpu, _ := strconv.Atoi(cpuStr)
	if cpu < 0 || cpu > 100 {
		cpu = 0
	}
	
	// Uso de memoria aproximado
	memCmd := exec.Command("wmic", "OS", "get", "TotalVisibleMemorySize,FreePhysicalMemory", "/value")
	memOut, err := memCmd.Output()
	if err != nil {
		return nil
	}
	memStr := strings.TrimSpace(string(memOut))
	lines := strings.Split(memStr, "\n")
	var totalMem, freeMem int64
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "TotalVisibleMemorySize=") {
			val, _ := strconv.ParseInt(strings.ReplaceAll(line, "TotalVisibleMemorySize=", ""), 10, 64)
			totalMem = val
		}
		if strings.HasPrefix(line, "FreePhysicalMemory=") {
			val, _ := strconv.ParseInt(strings.ReplaceAll(line, "FreePhysicalMemory=", ""), 10, 64)
			freeMem = val
		}
	}
	memPct := 0
	if totalMem > 0 {
		memPct = int(((totalMem - freeMem) * 100) / totalMem)
	}
	
	// Disco
	diskCmd := exec.Command("wmic", "LogicalDisk", "where", "DeviceID='C:'", "get", "Size,FreeSpace", "/value")
	diskOut, err := diskCmd.Output()
	if err != nil {
		return nil
	}
	diskStr := strings.TrimSpace(string(diskOut))
	lines = strings.Split(diskStr, "\n")
	var diskSize, diskFree int64
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "Size=") {
			val, _ := strconv.ParseInt(strings.ReplaceAll(line, "Size=", ""), 10, 64)
			diskSize = val
		}
		if strings.HasPrefix(line, "FreeSpace=") {
			val, _ := strconv.ParseInt(strings.ReplaceAll(line, "FreeSpace=", ""), 10, 64)
			diskFree = val
		}
	}
	
	// Procesos
	procCmd := exec.Command("wmic", "process", "get", "count")
	procOut, err := procCmd.Output()
	if err != nil {
		return nil
	}
	procStr := strings.TrimSpace(string(procOut))
	procStr = strings.ReplaceAll(procStr, "Count", "")
	procStr = strings.TrimSpace(procStr)
	processes, _ := strconv.Atoi(procStr)
	
	// Uptime - usar uptime del sistema
	uptimeCmd := exec.Command("wmic", "OS", "get", "LastBootUpTime", "/value")
	uptimeOut, err := uptimeCmd.Output()
	if err != nil {
		return nil
	}
	uptimeStr := strings.TrimSpace(string(uptimeOut))
	uptimeStr = strings.ReplaceAll(uptimeStr, "LastBootUpTime=", "")
	uptimeStr = strings.TrimSpace(uptimeStr)
	// Parsear fecha WMIC y calcular uptime
	layout := "20060102150405.000000+000"
	bootTime, err := time.Parse(layout, uptimeStr)
	var uptime int64
	if err == nil {
		uptime = int64(time.Since(bootTime).Seconds())
	}
	
	return &winSample{
		CPU:       cpu,
		Mem:       memPct,
		DiskTotal: diskSize,
		DiskFree:  diskFree,
		Processes: processes,
		Up:        uptime,
	}
}
