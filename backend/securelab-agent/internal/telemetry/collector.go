package telemetry

import (
	"time"

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
