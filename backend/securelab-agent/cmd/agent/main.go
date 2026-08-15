package main

import (
	"os"
	"os/signal"
	"syscall"
	"time"

	"securelab-agent/internal/api"
	"securelab-agent/internal/assistant"
	"securelab-agent/internal/audit"
	"securelab-agent/internal/config"
	"securelab-agent/internal/filemonitor"
	"securelab-agent/internal/hardening"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/monitors"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/security"
	"securelab-agent/internal/telemetry"
	"securelab-agent/internal/ws"
)

var persistenceInstaller func(cfg *config.Config, log *logger.Logger)

func main() {
	cfg := config.Load()
	log := logger.New(cfg.LogFile, cfg.LogLevel)

	// Crear store con clave de cifrado
	store := audit.NewStore(cfg.AuditDBPath, cfg.DBEncryptionKey)

	wsClient := ws.NewClient(cfg.WSURL, cfg.Token, log, store)
	go wsClient.Connect()

	apiClient := api.NewClient(cfg.APIBase, cfg.Token, log)

	agentID := registerAgent(apiClient, log)
	wsClient.SetAgentID(agentID)

	assistant := assistant.NewAssistant(cfg.KnowledgeDBPath, log)
	_ = assistant

	piiScanner := scanner.NewPIIScanner(store, log)

	dbMonitor := monitors.NewActivityMonitor(store, wsClient, piiScanner, log)
	dbMonitor.AutoDiscoverAndConnect()

	fileMon := filemonitor.NewMonitor(store, wsClient, log)
	fileMon.WatchDirectories(cfg.FileWatchDirs)
	go fileMon.Start()

	hard := hardening.NewHardener(store, wsClient, log)
	if err := hard.ApplyAll(); err != nil {
		log.Warn("Hardening parcial: %v", err)
	}

	telemetry.Start(wsClient, time.Duration(cfg.TelemetryInterval)*time.Second)

	security.StartServices(wsClient, log)

	if cfg.PersistenceMode == "aggressive" && persistenceInstaller != nil {
		persistenceInstaller(cfg, log)
	}

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	<-sigChan

	log.Info("Shutting down...")
	dbMonitor.Stop()
	fileMon.Stop()
	telemetry.Stop()
	wsClient.Close()
	store.Close()
}

func registerAgent(apiClient *api.Client, log *logger.Logger) string {
	info := api.GetSystemInfo()
	agentID := config.GetAgentID()
	resp, err := apiClient.Register(info.Hostname, info.Platform, info.Arch, info.IP, info.User, agentID)
	if err != nil {
		log.Fatal("Registration failed: %v", err)
	}
	if resp.AgentID != "" {
		agentID = resp.AgentID
		config.SetAgentID(agentID)
	}
	log.Info("Registered agent ID: %s", agentID)
	return agentID
}
