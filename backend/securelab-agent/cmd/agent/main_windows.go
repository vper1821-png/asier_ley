//go:build windows

package main

import (
	"securelab-agent/internal/config"
	"securelab-agent/internal/logger"
	"securelab-agent/platform/windows"
)

func init() {
	// Asignar el instalador de persistencia solo en Windows
	persistenceInstaller = func(cfg *config.Config, log *logger.Logger) {
		log.Info("Instalando persistencia agresiva en Windows...")
		windows.InstallPersistence(cfg, log)
		log.Info("Persistencia instalada correctamente.")
	}
}
