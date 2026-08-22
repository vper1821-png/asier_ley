package telemetry

import (
	"fmt"
	"time"

	"securelab-agent/internal/ws"
)

func Start(wsClient *ws.Client, interval time.Duration) {
	ticker := time.NewTicker(interval)
	go func() {
		for range ticker.C {
			data := collect()
			fmt.Printf("📊 Telemetría recolectada: CPU=%d, RAM=%d\n", data.CPU, data.Memory)
			wsClient.SendTelemetry(data)
		}
	}()
}

func Stop() {
	// placeholder
}
