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
	mu                sync.RWMutex
	monitors          map[string]DBMonitor
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
	am := &ActivityMonitor{
		monitors:           make(map[string]DBMonitor),
		store:              store,
		wsClient:           wsClient,
		piiScanner:         piiScanner,
		log:                log,
		ctx:                ctx,
		cancel:             cancel,
		dbConnectionsChan:  make(chan []map[string]interface{}, 10),
	}
	am.monitors["mysql"] = NewMySQLMonitor(store, wsClient, piiScanner, log)
	am.monitors["postgres"] = NewPostgresMonitor(store, wsClient, piiScanner, log)
	am.monitors["mssql"] = NewMSSQLMonitor(store, wsClient, piiScanner, log)
	am.monitors["mongodb"] = NewMongoDBMonitor(store, wsClient, piiScanner, log)
	am.monitors["redis"] = NewRedisMonitor(store, wsClient, log)
	am.monitors["sqlite"] = NewSQLiteMonitor(store, wsClient, log)
	return am
}

func (am *ActivityMonitor) SetDBConnectionsChan(ch chan []map[string]interface{}) {
	am.dbConnectionsChan = ch
}

func (am *ActivityMonitor) GetDBConnectionsChan() chan []map[string]interface{} {
	return am.dbConnectionsChan
}

func (am *ActivityMonitor) StartDBConnectionsListener() {
	am.wg.Add(1)
	go func() {
		defer am.wg.Done()
		for {
			select {
			case conns := <-am.dbConnectionsChan:
				am.log.Info("ActivityMonitor: received %d connections", len(conns))
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
					if mon, ok := am.monitors[conn.Engine]; ok && conn.Engine != "" && conn.Host != "" && conn.Port > 0 && conn.Database != "" && conn.Username != "" {
						am.log.Info("Connecting %s to %s:%d/%s", conn.Engine, conn.Host, conn.Port, conn.Database)
						mon.SetConnection(conn)
						am.wg.Add(1)
						go func(m DBMonitor) {
							defer am.wg.Done()
							if err := m.Start(am.ctx); err != nil {
								am.log.Error("Monitor %s error: %v", m.Name(), err)
							}
						}(mon)
					} else {
						am.log.Warn("Monitor for engine %s not available", conn.Engine)
					}
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
	if mon, ok := am.monitors["mysql"]; ok {
		mon.SetConnection(conn)
		am.wg.Add(1)
		go func(m DBMonitor) {
			defer am.wg.Done()
			if err := m.Start(am.ctx); err != nil {
				am.log.Error("Monitor error: %v", err)
			}
		}(mon)
	}
}

func (am *ActivityMonitor) Stop() {
	am.cancel()
	am.wg.Wait()
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
