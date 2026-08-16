package telemetry

import (
	"time"

	"securelab-agent/internal/models"
	"securelab-agent/internal/ws"
)

func Start(wsClient *ws.Client, interval time.Duration) {
	ticker := time.NewTicker(interval)
	go func() {
		for range ticker.C {
			data := collect()
			wsClient.SendTelemetry(data)
		}
	}()
}

func Stop() {
	// placeholder
}

func collect() models.TelemetryData {
	// TODO: Recolectar métricas reales del sistema
	return models.TelemetryData{
		Timestamp:   time.Now().Unix(),
		CPU:         10,
		Memory:      50,
		DiskFree:    1000000000,
		DiskTotal:   2000000000,
		Processes:   100,
		Connections: 10,
	}
}
