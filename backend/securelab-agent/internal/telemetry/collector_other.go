//go:build !windows

package telemetry

import (
	"os"
	"runtime"
	"time"

	"securelab-agent/internal/models"
)

func collect() models.TelemetryData {
	host, _ := os.Hostname()
	return models.TelemetryData{
		Timestamp:   time.Now().Unix(),
		CPU:         5,
		Memory:      40,
		DiskFree:    500000000000,
		DiskTotal:   1000000000000,
		Processes:   50,
		Connections: 5,
		Hostname:    host,
		Platform:    runtime.GOOS,
		Arch:        runtime.GOARCH,
	}
}
