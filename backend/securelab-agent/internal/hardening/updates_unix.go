//go:build !windows

package hardening

import (
	"time"

	"securelab-agent/internal/audit"
)

// ApplyUpdates en Linux/macOS no tiene Windows Update, solo registra que no se aplica.
func (h *Hardener) ApplyUpdates() error {
	h.log.Info("Verificando actualizaciones (no aplicable en este sistema operativo).")

	if h.store != nil {
		h.report(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  "info",
			Title:     "Verificación de actualizaciones (no aplicable)",
			Detail:    "El sistema no es Windows, no se verifica Windows Update.",
			Source:    "hardening",
		})
	}
	return nil
}
