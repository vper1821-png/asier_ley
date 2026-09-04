package hardening

import (
	"fmt"
	"os/exec"
	"strings"
	"time"

	"securelab-agent/internal/audit"
)

func (h *Hardener) ApplyEncryption() error {
	h.log.Info("Verificando cifrado de disco (BitLocker)...")

	out, err := exec.Command("manage-bde", "-status", "C:").CombinedOutput()
	output := strings.TrimSpace(string(out))

	if err != nil {
		h.log.Warn("BitLocker no disponible o error: %v", err)
		if h.store != nil {
			h.report(audit.HostEvent{
				Timestamp: time.Now(),
				Type:      "hardening",
				Severity:  "warning",
				Title:     "BitLocker no disponible",
				Detail:    fmt.Sprintf("Error al consultar BitLocker: %v", err),
				Source:    "hardening",
			})
		}
		return nil
	}

	h.log.Info("Estado BitLocker: %s", output)

	if h.store != nil {
		status := "desconocido"
		if strings.Contains(output, "Protection Status: Protection Off") {
			status = "desactivado"
		} else if strings.Contains(output, "Protection Status: Protection On") {
			status = "activado"
		}
		detail := fmt.Sprintf("Estado BitLocker en C:: %s", status)
		if output != "" {
			detail = fmt.Sprintf("%s\n%s", detail, output[:min(len(output), 200)])
		}
		h.report(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  "info",
			Title:     "Estado BitLocker verificado",
			Detail:    detail,
			Source:    "hardening",
		})
	}
	return nil
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}
