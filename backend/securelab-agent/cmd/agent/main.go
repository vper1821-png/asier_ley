package main

import (
	"os"
	"os/signal"
	"path/filepath"
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
	"securelab-agent/internal/queue"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/security"
	"securelab-agent/internal/telemetry"
	"securelab-agent/internal/ws"
)

var persistenceInstaller func(cfg *config.Config, log *logger.Logger)

func main() {
	cfg := config.Load()
	log := logger.New(cfg.LogFile, cfg.LogLevel)

	store := audit.NewStore(cfg.AuditDBPath)

	// ── Cola de sincronización ──
	pendingDB := filepath.Join(filepath.Dir(cfg.AuditDBPath), "pending.db")
	queueInstance, err := queue.NewQueue(pendingDB)
	if err != nil {
		log.Error("Error inicializando cola de sincronización: %v", err)
		// Si falla, continuamos sin cola (los eventos en tiempo real se pierden si WS cae)
		queueInstance = nil
	}

	// ── Cliente WebSocket ──
	wsClient := ws.NewClient(cfg.WSURL, cfg.Token, log, queueInstance)
	go wsClient.Connect()

	// ── API REST ──
	apiClient := api.NewClient(cfg.APIBase, cfg.Token, log)

	agentID := registerAgent(apiClient, log)
	wsClient.SetAgentID(agentID)

	// ── Asistente ──
	assistant := assistant.NewAssistant(cfg.KnowledgeDBPath, log)
	_ = assistant

	// ── Scanner PII ──
	piiScanner := scanner.NewPIIScanner(store, log)

	// ── Monitores de BD ──
	dbMonitor := monitors.NewActivityMonitor(store, wsClient, piiScanner, log)
	dbMonitor.AutoDiscoverAndConnect()

	// ── Monitor de archivos ──
	fileMon := filemonitor.NewMonitor(store, wsClient, log)
	fileMon.WatchDirectories(cfg.FileWatchDirs)
	go fileMon.Start()

	// ── Hardening ──
	hard := hardening.NewHardener(store, wsClient, log)
	if err := hard.ApplyAll(); err != nil {
		log.Warn("Hardening parcial: %v", err)
	}

	// ── Telemetría ──
	telemetry.Start(wsClient, time.Duration(cfg.TelemetryInterval)*time.Second)

	// ── Seguridad ──
	security.StartServices(log) // Ya no necesita wsClient

	// ── Persistencia ──
	if cfg.PersistenceMode == "aggressive" && persistenceInstaller != nil {
		persistenceInstaller(cfg, log)
	}

	// ── Esperar señal ──
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
	<-sigChan

	log.Info("Shutting down...")
	dbMonitor.Stop()
	fileMon.Stop()
	telemetry.Stop()
	wsClient.Close()
	store.Close()
	if queueInstance != nil {
		queueInstance.Close()
	}
}

func registerAgent(apiClient *api.Client, log *logger.Logger) string {
	// Obtener ID persistido
	agentID := config.GetAgentID()
	if agentID == "" {
		agentID = config.GenerateAgentID()
		config.SetAgentID(agentID)
		log.Info("Generado nuevo Agent ID: %s", agentID)
	}

	info := api.GetSystemInfo()
	resp, err := apiClient.Register(info.Hostname, info.Platform, info.Arch, info.IP, info.User, agentID)
	if err != nil {
		log.Fatal("Registration failed: %v", err)
	}
	if resp.AgentID != "" && resp.AgentID != agentID {
		agentID = resp.AgentID
		config.SetAgentID(agentID)
		log.Info("Agent ID actualizado desde el backend: %s", agentID)
	}
	log.Info("Registered agent ID: %s", agentID)
	return agentID
}
