package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
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
	"securelab-agent/platform/unix"
)

func main() {
	// ── Subcommands ──
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "install":
			if err := unix.InstallService(); err != nil {
				fmt.Fprintf(os.Stderr, "Error instalando servicio: %v\n", err)
				os.Exit(1)
			}
			fmt.Println("Servicio SecureLabAgent instalado.")
			os.Exit(0)
		case "uninstall":
			if err := unix.RemoveService(); err != nil {
				fmt.Fprintf(os.Stderr, "Error eliminando servicio: %v\n", err)
				os.Exit(1)
			}
			fmt.Println("Servicio SecureLabAgent eliminado.")
			os.Exit(0)
		}
	}

	// ── Run as Unix service (or foreground) ──
	unix.RunService(func(ctx context.Context) {
		runAgent(ctx)
	})
}

func runAgent(ctx context.Context) {
	cfg := config.Load()
	log := logger.New(cfg.LogFile, cfg.LogLevel)

	store := audit.NewStore(cfg.AuditDBPath)
	defer store.Close()

	// ── Cola de sincronización ──
	pendingDB := filepath.Join(filepath.Dir(cfg.AuditDBPath), "pending.db")
	queueInstance, err := queue.NewQueue(pendingDB)
	if err != nil {
		log.Error("Error inicializando cola de sincronización: %v", err)
		queueInstance = nil
	}
	defer func() {
		if queueInstance != nil {
			queueInstance.Close()
		}
	}()

	// ── API REST ──
	apiClient := api.NewClient(cfg.APIBase, cfg.Token, log)

	// ── 1. REGISTRAR (no fatal: reintentar en background) ──
	agentID := getOrRegisterAgent(apiClient, log)
	log.Info("Agent ID obtenido: %s", agentID)
	log.Flush()

	// ── 2. CREAR CLIENTE WS y asignar agentID ──
	wsClient := ws.NewClient(cfg.WSURL, cfg.Token, log, queueInstance)
	wsClient.SetAgentID(agentID)

	// ── Iniciar telemetría inmediatamente ──
	log.Info("Iniciando telemetría con intervalo: %d segundos", cfg.TelemetryInterval)
	telemetry.Start(wsClient, time.Duration(cfg.TelemetryInterval)*time.Second)
	defer telemetry.Stop()

	// ── 3. CONECTAR WS ──
	go wsClient.Connect()
	defer wsClient.Close()

	// Re-aplicar bloqueo persistente si estaba activo
	security.ApplyLockdownIfFlagged()

	// ── 3.1 Sync loop: comandos pendientes + estado de bloqueo ──
	syncInterval := time.Duration(cfg.SyncInterval) * time.Millisecond
	if syncInterval < 100*time.Millisecond {
		syncInterval = 100 * time.Millisecond
	}
	wsClient.StartSyncLoop(syncInterval)

	// ── Resto de servicios ──
	assistant := assistant.NewAssistant(cfg.KnowledgeDBPath, log)
	_ = assistant

	piiScanner := scanner.NewPIIScanner(store, log)

	dbMonitor := monitors.NewActivityMonitor(store, wsClient, piiScanner, log)
	dbMonitor.AutoDiscoverAndConnect()
	defer dbMonitor.Stop()

	fileMon := filemonitor.NewMonitor(store, wsClient, log)
	fileMon.WatchDirectories(cfg.FileWatchDirs)
	go fileMon.Start()
	defer fileMon.Stop()

	// Ejecutar hardening en goroutine para no bloquear
	go func() {
		hard := hardening.NewHardener(store, wsClient, log)
		if err := hard.ApplyAll(); err != nil {
			log.Warn("Hardening parcial: %v", err)
		}
	}()

	security.StartServices(log)

	if cfg.PersistenceMode == "aggressive" && persistenceInstaller != nil {
		persistenceInstaller(cfg, log)
	}

	// ── Esperar señal de apagado ──
	<-ctx.Done()

	log.Info("Shutting down...")
}

func getOrRegisterAgent(apiClient *api.Client, log *logger.Logger) string {
	agentID := config.GetAgentID()
	if agentID != "" {
		return agentID
	}

	info := api.GetSystemInfo()
	for i := 0; i < 3; i++ {
		resp, err := apiClient.Register(info.Hostname, info.Platform, info.Arch, info.IP, info.User, "")
		if err == nil && resp.AgentID != "" {
			config.SetAgentID(resp.AgentID)
			log.Info("Generado/actualizado Agent ID: %s", resp.AgentID)
			return resp.AgentID
		}
		log.Warn("Registro fallido (intento %d): %v", i+1, err)
		time.Sleep(2 * time.Second)
	}

	// Si todo falla, generar uno local para que el servicio pueda iniciar
	agentID = config.GenerateAgentID()
	config.SetAgentID(agentID)
	log.Warn("Registro offline. Usando Agent ID local: %s", agentID)
	return agentID
}
