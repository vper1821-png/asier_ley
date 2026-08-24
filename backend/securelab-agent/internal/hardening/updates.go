//go:build windows

package hardening

import (
	"fmt"
	"strings"
	"time"

	"securelab-agent/internal/audit"

	"golang.org/x/sys/windows/registry"
)

// ApplyUpdates verifica el estado de Windows Update SIN forzar la búsqueda.
// Solo AUDITA y REGISTRA el estado actual del sistema.
func (h *Hardener) ApplyUpdates() error {
	h.log.Info("Verificando estado de Windows Update (solo auditoría)...")

	// 1. Verificar si hay un reinicio pendiente
	rebootPending := isRebootPending()

	// 2. Obtener la fecha de la última actualización exitosa
	lastUpdateTime := getLastUpdateSuccessTime()

	// 3. Verificar si hay actualizaciones descargadas esperando instalación
	updatesPending := areUpdatesPending()

	// Construir detalle para el log y la base de datos
	var details []string
	if rebootPending {
		details = append(details, "⚠️ REINICIO PENDIENTE: Se requiere reiniciar para completar la instalación de actualizaciones.")
	} else {
		details = append(details, "✅ No hay reinicio pendiente por actualizaciones.")
	}

	if updatesPending {
		details = append(details, "📦 Hay actualizaciones descargadas pendientes de instalación.")
	} else {
		details = append(details, "✅ No hay actualizaciones pendientes de instalación (o no se han descargado).")
	}

	if lastUpdateTime != "" {
		details = append(details, fmt.Sprintf("📅 Última instalación exitosa: %s", lastUpdateTime))
	} else {
		details = append(details, "📅 No se encontró registro de instalaciones exitosas previas.")
	}

	detailMsg := strings.Join(details, " | ")

	// Guardar en la base de datos local
	if h.store != nil {
		severity := "info"
		title := "Estado de Windows Update verificado"
		if rebootPending || updatesPending {
			severity = "warning"
			title = "⚠️ Actualizaciones pendientes en el sistema"
		}
		h.store.SaveHostEvent(audit.HostEvent{
			Timestamp: time.Now(),
			Type:      "hardening",
			Severity:  severity,
			Title:     title,
			Detail:    detailMsg,
			Source:    "hardening",
		})
	}

	// Enviar evento por WebSocket al backend
	if h.wsClient != nil {
		h.wsClient.SendEvent("Estado de Windows Update", detailMsg, "hardening", "info")
	}

	// Mostrar en el log local
	h.log.Info("Estado de Windows Update: %s", detailMsg)

	return nil
}

// isRebootPending verifica si hay un reinicio pendiente en el registro
func isRebootPending() bool {
	// Revisar la clave de operaciones pendientes
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SYSTEM\CurrentControlSet\Control\Session Manager`, registry.QUERY_VALUE)
	if err == nil {
		defer k.Close()
		// Si existe "PendingFileRenameOperations", hay reinicio pendiente
		_, _, err := k.GetStringValue("PendingFileRenameOperations")
		if err == nil {
			return true
		}
	}

	// Revisar si hay un reinicio pendiente por Windows Update
	k2, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired`, registry.QUERY_VALUE)
	if err == nil {
		defer k2.Close()
		return true
	}

	return false
}

// getLastUpdateSuccessTime obtiene la fecha de la última instalación exitosa
func getLastUpdateSuccessTime() string {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\Results\Install`, registry.QUERY_VALUE)
	if err != nil {
		return ""
	}
	defer k.Close()

	val, _, err := k.GetStringValue("LastSuccessTime")
	if err == nil && val != "" {
		return val
	}
	return ""
}

// areUpdatesPending verifica si hay actualizaciones descargadas listas para instalar
func areUpdatesPending() bool {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update`, registry.QUERY_VALUE)
	if err != nil {
		return false
	}
	defer k.Close()

	// Si existe "UpdatePending" o "DownloadPending", hay actualizaciones pendientes
	val, _, err := k.GetIntegerValue("UpdatePending")
	if err == nil && val == 1 {
		return true
	}

	val, _, err = k.GetIntegerValue("DownloadPending")
	if err == nil && val == 1 {
		return true
	}

	return false
}
