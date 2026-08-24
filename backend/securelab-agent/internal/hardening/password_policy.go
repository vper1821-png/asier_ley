package hardening

import (
	"fmt"
	"os/exec"
	"strings"
	"time"

	"securelab-agent/internal/audit"
)

// ApplyPasswordPolicy consulta el estado actual de la política de contraseñas SIN modificarla
func (h *Hardener) ApplyPasswordPolicy() error {
	h.log.Info("Consultando estado de política de contraseñas (solo auditoría)...")

	// Ejecutar net accounts para obtener la configuración actual
	cmd := exec.Command("cmd", "/c", "net accounts")
	out, err := cmd.CombinedOutput()
	if err != nil {
		h.log.Warn("Error ejecutando net accounts: %v", err)
		if h.store != nil {
			h.store.SaveHostEvent(audit.HostEvent{
				Timestamp: time.Now(),
				Type:      "hardening",
				Severity:  "warning",
				Title:     "Error consultando política de contraseñas",
				Detail:    fmt.Sprintf("Error: %v", err),
				Source:    "hardening",
			})
		}
		return err
	}

	output := string(out)
	h.log.Info("Política de contraseñas actual:\n%s", output)

	// Extraer información relevante para guardar en DB
	lines := strings.Split(output, "\n")
	var relevant []string
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.Contains(line, "Minimum password length") ||
			strings.Contains(line, "Maximum password age") ||
			strings.Contains(line, "Lockout threshold") ||
			strings.Contains(line, "Lockout duration") {
			relevant = append(relevant, line)
		}
	}
	detail := strings.Join(relevant, " | ")
	if detail == "" {
		detail = "No se pudo extraer información relevante de net accounts"
	}

	// Guardar en la base de datos local
	if h.store != nil {
		h.store.SaveHostEvent(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  "info",
			Title:     "Estado de política de contraseñas",
			Detail:    detail,
			Source:    "hardening",
		})
	}

	return nil
}
