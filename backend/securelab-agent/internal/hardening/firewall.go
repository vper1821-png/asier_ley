package hardening

import (
	"fmt"
	"os/exec"
	"strings"
	"time"

	"securelab-agent/internal/audit"
)

// ApplyFirewall consulta el estado actual del firewall SIN modificarlo
func (h *Hardener) ApplyFirewall() error {
	h.log.Info("Consultando estado del firewall (solo auditoría)...")

	// Obtener estado de todos los perfiles
	cmd := exec.Command("netsh", "advfirewall", "show", "allprofiles")
	out, err := cmd.CombinedOutput()
	if err != nil {
		h.log.Warn("Error ejecutando netsh advfirewall: %v", err)
		if h.store != nil {
			h.store.SaveHostEvent(audit.HostEvent{
				Timestamp: time.Now(),
				Type:      "hardening",
				Severity:  "warning",
				Title:     "Error consultando firewall",
				Detail:    fmt.Sprintf("Error: %v", err),
				Source:    "hardening",
			})
		}
		return err
	}

	output := string(out)
	h.log.Info("Estado del firewall:\n%s", output)

	// Extraer información relevante
	lines := strings.Split(output, "\n")
	var relevant []string
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if strings.Contains(line, "State") ||
			strings.Contains(line, "Firewall Policy") ||
			strings.Contains(line, "Inbound") ||
			strings.Contains(line, "Outbound") {
			relevant = append(relevant, line)
		}
	}
	detail := strings.Join(relevant, " | ")
	if detail == "" {
		detail = "No se pudo extraer información relevante del firewall"
	}

	// Guardar en la base de datos local
	if h.store != nil {
		h.store.SaveHostEvent(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  "info",
			Title:     "Estado del firewall",
			Detail:    detail,
			Source:    "hardening",
		})
	}

	return nil
}
