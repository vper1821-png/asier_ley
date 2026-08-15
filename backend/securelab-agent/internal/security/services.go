package security

import (
	"securelab-agent/internal/logger"
	"securelab-agent/internal/ws"
)

// StartServices inicia los servicios de seguridad (bloqueo, firewall, etc.)
func StartServices(wsClient *ws.Client, log *logger.Logger) {
	log.Info("Starting security services...")
	// Aquí puedes añadir lógica de monitoreo de bloqueos, reglas, etc.
	// Por ahora es un placeholder.
}
