package monitors

import (
	"context"
	"fmt"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"
)

type ActivityMonitor struct {
	mu                sync.RWMutex
	monitors          map[string]DBMonitor
	lastConns         map[string]DBConnection
	store             *audit.Store
	wsClient          *ws.Client
	piiScanner        *scanner.PIIScanner
	log               *logger.Logger
	ctx               context.Context
	cancel            context.CancelFunc
	wg                sync.WaitGroup
	dbConnectionsChan chan []map[string]interface{}
}

func NewActivityMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *ActivityMonitor {
	ctx, cancel := context.WithCancel(context.Background())
	return &ActivityMonitor{
		monitors:          make(map[string]DBMonitor),
		lastConns:         make(map[string]DBConnection),
		store:             store,
		wsClient:          wsClient,
		piiScanner:        piiScanner,
		log:               log,
		ctx:               ctx,
		cancel:            cancel,
		dbConnectionsChan: make(chan []map[string]interface{}, 10),
	}
}

func (am *ActivityMonitor) newMonitor(engine string) DBMonitor {
	switch engine {
	case "mysql":
		return NewMySQLMonitor(am.store, am.wsClient, am.piiScanner, am.log)
	case "postgres":
		return NewPostgresMonitor(am.store, am.wsClient, am.piiScanner, am.log)
	case "mssql":
		return NewMSSQLMonitor(am.store, am.wsClient, am.piiScanner, am.log)
	case "mongodb":
		return NewMongoDBMonitor(am.store, am.wsClient, am.piiScanner, am.log)
	case "redis":
		return NewRedisMonitor(am.store, am.wsClient, am.log)
	case "sqlite":
		return NewSQLiteMonitor(am.store, am.wsClient, am.log)
	}
	return nil
}

func (am *ActivityMonitor) SetDBConnectionsChan(ch chan []map[string]interface{}) {
	am.dbConnectionsChan = ch
}

func (am *ActivityMonitor) GetDBConnectionsChan() chan []map[string]interface{} {
	return am.dbConnectionsChan
}

func (am *ActivityMonitor) connKey(conn DBConnection) string {
	return fmt.Sprintf("%s://%s:%d/%s/%s/%s/%v", conn.Engine, conn.Host, conn.Port, conn.Database, conn.Username, conn.Password, conn.SSL)
}

func (am *ActivityMonitor) startOne(conn DBConnection) {
	if conn.Engine == "" || conn.Host == "" || conn.Port <= 0 || conn.Username == "" {
		am.log.Warn("Invalid connection, skipping: engine=%s host=%s port=%d", conn.Engine, conn.Host, conn.Port)
		return
	}
	key := am.connKey(conn)
	if last, exists := am.lastConns[key]; exists && last == conn {
		am.log.Debug("Monitor %s already connected to %s:%d/%s, skipping", conn.Engine, conn.Host, conn.Port, conn.Database)
		return
	}
	if mon, exists := am.monitors[key]; exists {
		am.log.Info("Updating monitor %s", key)
		mon.Stop()
	} else {
		am.log.Info("Creating monitor %s for %s:%d/%s", conn.Engine, conn.Host, conn.Port, conn.Database)
	}
	mon := am.newMonitor(conn.Engine)
	if mon == nil {
		am.log.Warn("Monitor for engine %s not available", conn.Engine)
		return
	}
	am.monitors[key] = mon
	am.lastConns[key] = conn
	mon.SetConnection(conn)
	am.wg.Add(1)
	go func(m DBMonitor) {
		defer am.wg.Done()
		if err := m.Start(am.ctx); err != nil {
			am.log.Error("Monitor %s error: %v", m.Name(), err)
		}
	}(mon)
}

func (am *ActivityMonitor) StartDBConnectionsListener() {
	am.wg.Add(1)
	go func() {
		defer am.wg.Done()
		for {
			select {
			case conns := <-am.dbConnectionsChan:
				am.log.Info("ActivityMonitor: received %d connections", len(conns))
				desired := make(map[string]DBConnection)
				for _, connMap := range conns {
					conn := DBConnection{
						Engine:   getString(connMap, "engine"),
						Host:     getString(connMap, "host"),
						Port:     getInt(connMap, "port"),
						Database: getString(connMap, "database"),
						Username: getString(connMap, "username"),
						Password: getString(connMap, "password"),
						SSL:      getBool(connMap, "ssl"),
					}
					if conn.Engine != "" && conn.Host != "" && conn.Port > 0 && conn.Username != "" {
						desired[am.connKey(conn)] = conn
					} else {
						am.log.Warn("Monitor for engine %s not available", conn.Engine)
					}
				}

				// Detener monitores que ya no están en la lista deseada
				for key, mon := range am.monitors {
					if _, ok := desired[key]; !ok {
						am.log.Info("ActivityMonitor: stopping monitor %s", key)
						mon.Stop()
						delete(am.monitors, key)
						delete(am.lastConns, key)
					}
				}

				// Arrancar o actualizar los que faltan
				for _, conn := range desired {
					am.startOne(conn)
				}
			case <-am.ctx.Done():
				return
			}
		}
	}()
}

func (am *ActivityMonitor) AutoDiscoverAndConnect() {
	am.log.Info("AutoDiscoverAndConnect")
	conn := DBConnection{
		Engine:   "mysql",
		Host:     "127.0.0.1",
		Port:     3306,
		Username: "root",
		Password: "",
		Database: "",
	}
	am.startOne(conn)
}

func (am *ActivityMonitor) Stop() {
	am.cancel()
	am.wg.Wait()
	for key, mon := range am.monitors {
		am.log.Info("ActivityMonitor: stopping monitor %s", key)
		mon.Stop()
	}
	am.log.Info("Stopped")
}

func getString(m map[string]interface{}, k string) string {
	if v, ok := m[k].(string); ok {
		return v
	}
	return ""
}

func getInt(m map[string]interface{}, k string) int {
	if v, ok := m[k].(float64); ok {
		return int(v)
	}
	if v, ok := m[k].(int); ok {
		return v
	}
	return 0
}

func getBool(m map[string]interface{}, k string) bool {
	if v, ok := m[k].(bool); ok {
		return v
	}
	return false
}
