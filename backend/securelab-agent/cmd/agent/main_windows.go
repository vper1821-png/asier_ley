//go:build windows

package main

import (
	"context"

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

func runPlatformService(runFunc func(context.Context)) error {
	return windows.RunService(runFunc)
}

func installPlatformService() error {
	return windows.InstallService()
}

func removePlatformService() error {
	return windows.RemoveService()
}
