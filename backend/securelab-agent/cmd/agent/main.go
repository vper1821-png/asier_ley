package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"time"

	// build refresh 2026-08-31 to force new agent binary
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
	// ── Subcommands ──
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
			// Modo overlay: se ejecuta dentro de la sesión de usuario.
			// Lee el mensaje del archivo de estado y muestra el bloqueo.
			security.RunOverlayUI()
			os.Exit(0)
		}
	}

	// ── Run as platform service or foreground process ──
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
	security.StartLockdownMonitor()

	// ── 3.1 Sync loop: comandos pendientes + estado de bloqueo ──
	syncInterval := time.Duration(cfg.SyncInterval) * time.Millisecond
	if syncInterval < 100*time.Millisecond {
		syncInterval = 100 * time.Millisecond
	}
	wsClient.StartSyncLoop(syncInterval)

	// ── ESCANEO INICIAL MASIVO DE DATOS SENSIBLES ──
	// Ejecutar en background para no bloquear el arranque
	go func() {
		// Esperar a que el WS esté conectado
		time.Sleep(10 * time.Second)
		
		scanCfg := scanner.DefaultInitialScanConfig()
		// Ajustar según configuración del agente
		if cfg.HeartbeatInterval > 0 {
			scanCfg.ScanTimeout = time.Duration(cfg.HeartbeatInterval) * time.Minute
		}
		
		log.Info("🚀 Iniciando escaneo masivo inicial de datos sensibles en background...")
		scanned, sensitive, err := scanner.RunInitialMassiveScan(ctx, log, store, wsClient, scanCfg)
		if err != nil && err != context.Canceled {
			log.Error("Error en escaneo inicial masivo: %v", err)
		} else {
			log.Info("✅ Escaneo inicial completado: %d archivos, %d con datos sensibles", scanned, sensitive)
		}
	}()

	// ── Resto de servicios ──
	assistant := assistant.NewAssistant(cfg.KnowledgeDBPath, log)
	_ = assistant

	piiScanner := scanner.NewPIIScanner(store, log)

	dbMonitor := monitors.NewActivityMonitor(store, wsClient, piiScanner, log)
	dbMonitor.AutoDiscoverAndConnect()
	
	// Conectar canal de conexiones de BD del WS client al ActivityMonitor
	wsClient.SetDBConnectionsChan(dbMonitor.GetDBConnectionsChan())
	dbMonitor.StartDBConnectionsListener()
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
