package telemetry

import (
	"time"

	"securelab-agent/internal/ws"
)

func Start(wsClient *ws.Client, interval time.Duration) {
	ticker := time.NewTicker(interval)
	go func() {
		for range ticker.C {
			// Recolectar y enviar telemetría
			wsClient.SendTelemetry(collect())
		}
	}()
}

func Stop() {
	// detener
}

func collect() interface{} {
	// Implementar recolección de métricas
	return nil
}
