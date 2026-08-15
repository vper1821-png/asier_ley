package monitors

import (
	"context"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"
)

type ActivityMonitor struct {
	mu         sync.RWMutex
	monitors   map[string]DBMonitor
	store      *audit.Store
	wsClient   *ws.Client
	piiScanner *scanner.PIIScanner
	log        *logger.Logger
	ctx        context.Context
	cancel     context.CancelFunc
	wg         sync.WaitGroup
}

func NewActivityMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *ActivityMonitor {
	ctx, cancel := context.WithCancel(context.Background())
	am := &ActivityMonitor{
		monitors:   make(map[string]DBMonitor),
		store:      store,
		wsClient:   wsClient,
		piiScanner: piiScanner,
		log:        log,
		ctx:        ctx,
		cancel:     cancel,
	}
	am.monitors["mysql"] = NewMySQLMonitor(store, wsClient, piiScanner, log)
	am.monitors["postgres"] = NewPostgresMonitor(store, wsClient, piiScanner, log)
	am.monitors["mssql"] = NewMSSQLMonitor(store, wsClient, piiScanner, log)
	am.monitors["mongodb"] = NewMongoDBMonitor(store, wsClient, piiScanner, log)
	am.monitors["redis"] = NewRedisMonitor(store, wsClient, log)
	am.monitors["sqlite"] = NewSQLiteMonitor(store, wsClient, log)
	return am
}

func (am *ActivityMonitor) AutoDiscoverAndConnect() {
	am.log.Info("Descubriendo bases de datos locales...")
	// Simulación: conectar a MySQL por defecto
	conn := DBConnection{
		Engine:   "mysql",
		Host:     "127.0.0.1",
		Port:     3306,
		Username: "root",
		Password: "",
		Database: "",
	}
	if mon, ok := am.monitors["mysql"]; ok {
		mon.SetConnection(conn)
		am.wg.Add(1)
		go func(m DBMonitor) {
			defer am.wg.Done()
			if err := m.Start(am.ctx); err != nil {
				am.log.Error("Monitor %s error: %v", m.Name(), err)
			}
		}(mon)
	}
}

func (am *ActivityMonitor) Stop() {
	am.cancel()
	am.wg.Wait()
	am.log.Info("ActivityMonitor detenido")
}
