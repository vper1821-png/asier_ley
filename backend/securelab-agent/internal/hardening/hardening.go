package hardening

import (
	"fmt"
	"runtime"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/ws"
)

type Hardener struct {
	store    *audit.Store
	wsClient *ws.Client
	log      *logger.Logger
}

func NewHardener(store *audit.Store, wsClient *ws.Client, log *logger.Logger) *Hardener {
	return &Hardener{store: store, wsClient: wsClient, log: log}
}

func (h *Hardener) report(ev audit.HostEvent) {
	if h.store != nil {
		if err := h.store.SaveHostEvent(ev); err != nil {
			h.log.Warn("SaveHostEvent: %v", err)
		}
	}
	if h.wsClient != nil {
		h.wsClient.SendHostEvent(ev)
	}
}

// ApplyAll aplica todas las auditorías de hardening (solo consulta, NO MODIFICA)
func (h *Hardener) ApplyAll() error {
	if runtime.GOOS != "windows" {
		h.log.Info("Hardening solo para Windows (por ahora)")
		return nil
	}

	h.log.Info("Iniciando auditoría de hardening del sistema...")
	var errs []error

	if err := h.ApplyPasswordPolicy(); err != nil {
		errs = append(errs, fmt.Errorf("password policy: %w", err))
	}
	if err := h.ApplyFirewall(); err != nil {
		errs = append(errs, fmt.Errorf("firewall: %w", err))
	}
	if err := h.ApplyEncryption(); err != nil {
		errs = append(errs, fmt.Errorf("encryption: %w", err))
	}
	if err := h.ApplyUpdates(); err != nil {
		errs = append(errs, fmt.Errorf("updates: %w", err))
	}

	if len(errs) > 0 {
		h.log.Warn("Auditoría completada con %d errores", len(errs))
		if h.store != nil {
			h.report(audit.HostEvent{
				Timestamp: time.Now(),
				Type:      "hardening",
				Severity:  "warning",
				Title:     "Auditoría de hardening con errores",
				Detail:    fmt.Sprintf("Se completó la auditoría con %d errores.", len(errs)),
				Source:    "hardening",
			})
		}
		return fmt.Errorf("hardening con errores: %v", errs)
	}

	h.log.Info("Auditoría de hardening completada correctamente")
	if h.store != nil {
		h.report(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  "info",
			Title:     "Auditoría de hardening completada",
			Detail:    "Todas las auditorías de hardening se completaron correctamente.",
			Source:    "hardening",
		})
	}
	return nil
}
