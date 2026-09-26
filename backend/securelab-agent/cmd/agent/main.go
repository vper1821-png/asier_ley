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
)

func main() {
	if len(os.Args) > 1 {
		switch os.Args[1] {
		case "install":
			if err := installPlatformService(); err != nil {
				fmt.Fprintf(os.Stderr, "Error instalando servicio: %v\n", err)
				os.Exit(1)
			}
			fmt.Println("Servicio SecureLabAgent instalado correctamente.")
			os.Exit(0)
		case "uninstall":
			if err := removePlatformService(); err != nil {
				fmt.Fprintf(os.Stderr, "Error eliminando servicio: %v\n", err)
				os.Exit(1)
			}
			fmt.Println("Servicio SecureLabAgent eliminado correctamente.")
			os.Exit(0)
		case "version", "-v", "--version":
			cfg := config.Load()
			fmt.Printf("SecureLab Agent v%s (%s/%s)\n", cfg.AgentVersion, cfg.Platform, os.Getenv("GOARCH"))
			os.Exit(0)
		case "--check-lockdown":
			if security.IsLockdownActive() {
				security.ApplyLockdownIfFlagged()
				select {}
			}
			os.Exit(0)
		case "--overlay-ui":
			security.RunOverlayUI()
			os.Exit(0)
		}
	}

	if err := runPlatformService(func(ctx context.Context) {
		runAgent(ctx)
	}); err != nil {
		fmt.Fprintf(os.Stderr, "Error ejecutando servicio: %v\n", err)
		os.Exit(1)
	}
}

func runAgent(ctx context.Context) {
	cfg := config.Load()
	log := logger.New(cfg.LogFile, cfg.LogLevel)

	log.Info("==================================================")
	log.Info("  SecureLab Agent v%s Iniciando", cfg.AgentVersion)
	log.Info("==================================================")
	log.Debug("Configuración cargada desde: %s", config.GetConfigFilePath())
	log.Debug("API Base: %s", cfg.APIBase)
	log.Debug("WS URL: %s", cfg.WSURL)
	log.Debug("Log File: %s (Nivel: %s)", cfg.LogFile, cfg.LogLevel)
	log.Debug("Audit DB: %s", cfg.AuditDBPath)
	log.Debug("Knowledge DB: %s", cfg.KnowledgeDBPath)
	log.Debug("State File: %s", cfg.StateFile)
	log.Debug("Token configurado: %t (longitud: %d)", cfg.Token != "", len(cfg.Token))
	log.Debug("Intervalos: Heartbeat=%ds, Telemetría=%ds, Sync=%dms", cfg.HeartbeatInterval, cfg.TelemetryInterval, cfg.SyncInterval)
	log.Debug("FileWatchDirs: %v", cfg.FileWatchDirs)
	log.Debug("Template ID (hint de pack): %q", cfg.TemplateID) // ← NUEVO

	store := audit.NewStore(cfg.AuditDBPath)
	defer store.Close()

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

	apiClient := api.NewClient(cfg.APIBase, cfg.Token, log)

	agentID := getOrRegisterAgent(apiClient, log)
	log.Info("Agent ID obtenido: %s", agentID)
	log.Flush()

	wsClient := ws.NewClient(cfg.WSURL, cfg.Token, log, queueInstance)
	wsClient.SetAgentID(agentID)

	log.Info("Iniciando telemetría con intervalo: %d segundos", cfg.TelemetryInterval)
	telemetry.Start(wsClient, time.Duration(cfg.TelemetryInterval)*time.Second)
	defer telemetry.Stop()

	go wsClient.Connect()
	defer wsClient.Close()

	security.ApplyLockdownIfFlagged()
	security.StartLockdownMonitor()

	syncInterval := time.Duration(cfg.SyncInterval) * time.Millisecond
	if syncInterval < 100*time.Millisecond {
		syncInterval = 100 * time.Millisecond
	}
	wsClient.StartSyncLoop(syncInterval)

	// ══════════════════════════════════════════════════════════════════
	// ORDEN: 1) FileMonitor  2) WaitReady  3) Escáner (solo 1ª vez)
	// ══════════════════════════════════════════════════════════════════

	// 1) FileMonitor PRIMERO — arranca el event loop inmediatamente
	fileMon := filemonitor.NewMonitor(store, wsClient, log)

	watchDirs := cfg.FileWatchDirs
	if len(watchDirs) == 0 {
		// Fallback: usar los mismos directorios que el escáner auto-descubre
		watchDirs = scanner.GetDefaultScanDirectories(log)
		if len(watchDirs) > 0 {
			log.Warn("FileWatchDirs vacío — usando auto-descubrimiento (%d dirs): %v", len(watchDirs), watchDirs)
		}
	}
	fileMon.WatchDirectories(watchDirs)
	go fileMon.Start()
	defer fileMon.Stop()

	// 2) Esperar a que los watchers terminen su Walk inicial
	if !fileMon.WaitReady(3 * time.Minute) {
		log.Warn("FileMonitor: arrancando escáner con watchers aún registrando")
	}

	// 3) Escaneo inicial masivo — SOLO si nunca se hizo
	go func() {
		if store.InitialScanCompleted() {
			info := store.InitialScanInfo()
			log.Info("✅ Escaneo inicial ya realizado previamente — NO se re-escanea")
			if v, ok := info["completed_at"].(string); ok {
				log.Info("   Completado el: %s", v)
			}
			if v, ok := info["total_files"].(int64); ok {
				log.Info("   Archivos escaneados en su momento: %d", v)
			}
			if v, ok := info["sensitive_files"].(int64); ok {
				log.Info("   Archivos sensibles detectados: %d", v)
			}
			log.Info("   → El FileMonitor cubre cambios en tiempo real")
			return
		}

		if err := store.MarkInitialScanStarted(); err != nil {
			log.Warn("No se pudo guardar el estado de inicio: %v", err)
		}

		scanCfg := scanner.DefaultInitialScanConfig()
		scanCfg.CustomDirs = cfg.FileWatchDirs

		startTime := time.Now()
		log.Info("🚀 PRIMER escaneo masivo de datos sensibles...")
		log.Info("   Timeout: %v | MaxFiles: %d | MaxDepth: %d",
			scanCfg.ScanTimeout, scanCfg.MaxFiles, scanCfg.MaxDepth)
		log.Info("   CustomDirs: %v", scanCfg.CustomDirs)

		scanned, sensitive, err := scanner.RunInitialMassiveScan(ctx, log, store, wsClient, scanCfg)

		if err == context.Canceled {
			log.Warn("⚠️  Escaneo cancelado — se reintentará en el próximo arranque")
			return
		}
		if err != nil {
			log.Error("❌ Escaneo inicial falló: %v — se reintentará en el próximo arranque", err)
			return
		}

		duration := int64(time.Since(startTime).Seconds())
		if err := store.MarkInitialScanCompleted(scanned, sensitive, duration); err != nil {
			log.Error("Error guardando estado de completado: %v", err)
		}

		log.Info("=============================================================")
		log.Info("✅ ESCANEO INICIAL COMPLETADO Y MARCADO")
		log.Info("   Archivos escaneados: %d", scanned)
		log.Info("   Con datos sensibles: %d", sensitive)
		log.Info("   Duración: %d segundos", duration)
		log.Info("   → No se volverá a escanear en futuros arranques")
		log.Info("=============================================================")
	}()

	assistantInstance := assistant.NewAssistant(cfg.KnowledgeDBPath, log)
	_ = assistantInstance

	piiScanner := scanner.NewPIIScanner(store, log)

	dbMonitor := monitors.NewActivityMonitor(store, wsClient, piiScanner, log)
	dbMonitor.AutoDiscoverAndConnect()

	wsClient.SetDBConnectionsChan(dbMonitor.GetDBConnectionsChan())
	dbMonitor.StartDBConnectionsListener()
	defer dbMonitor.Stop()

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

	agentID = config.GenerateAgentID()
	config.SetAgentID(agentID)
	log.Warn("Registro offline. Usando Agent ID local: %s", agentID)
	return agentID
}
