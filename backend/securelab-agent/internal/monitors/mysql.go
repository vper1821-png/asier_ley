package monitors

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"

	_ "github.com/go-sql-driver/mysql"
)

type MySQLMonitor struct {
	mu         sync.RWMutex
	db         *sql.DB
	conn       DBConnection
	connected  bool
	store      *audit.Store
	wsClient   *ws.Client
	piiScanner *scanner.PIIScanner
	log        *logger.Logger
	wg         sync.WaitGroup
	ctx        context.Context
	cancel     context.CancelFunc
}

func NewMySQLMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *MySQLMonitor {
	return &MySQLMonitor{
		store:      store,
		wsClient:   wsClient,
		piiScanner: piiScanner,
		log:        log,
	}
}

func (m *MySQLMonitor) Name() string { return "mysql" }

func (m *MySQLMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
}

func (m *MySQLMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?timeout=5s&multiStatements=true",
		m.conn.Username, m.conn.Password, m.conn.Host, m.conn.Port, m.conn.Database)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return fmt.Errorf("open: %w", err)
	}
	db.SetMaxOpenConns(2)
	db.SetMaxIdleConns(1)
	if err := db.Ping(); err != nil {
		db.Close()
		return fmt.Errorf("ping: %w", err)
	}
	m.mu.Lock()
	m.db = db
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	m.log.Info("MySQL monitor conectado a %s:%d", m.conn.Host, m.conn.Port)

	m.wg.Add(1)
	go m.loop()
	return nil
}

func (m *MySQLMonitor) loop() {
	defer m.wg.Done()
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ticker.C:
			m.checkActivity()
		case <-m.ctx.Done():
			m.mu.Lock()
			if m.db != nil {
				m.db.Close()
				m.db = nil
			}
			m.connected = false
			m.mu.Unlock()
			return
		}
	}
}

func (m *MySQLMonitor) checkActivity() {
	m.mu.RLock()
	db := m.db
	if !m.connected || db == nil {
		m.mu.RUnlock()
		return
	}
	m.mu.RUnlock()

	rows, err := db.Query("SHOW FULL PROCESSLIST")
	if err != nil {
		m.log.Warn("MySQL: error en processlist: %v", err)
		return
	}
	defer rows.Close()

	for rows.Next() {
		var id int64
		var user, host, dbName, command, state, info sql.NullString
		if err := rows.Scan(&id, &user, &host, &dbName, &command, &state, &info); err != nil {
			continue
		}
		if user.String == "" || strings.Contains(user.String, "root") {
			continue
		}
		if info.String != "" && command.String == "Query" {
			query := info.String
			piiDetected := m.piiScanner.AnalyzeQuery(query)
			if len(piiDetected) > 0 {
				m.log.Warn("PII detectada en consulta de %s: %s", user.String, query)
				m.wsClient.SendEvent("PII Detectada",
					fmt.Sprintf("Usuario %s ejecutó consulta con datos personales: %s", user.String, query),
					"db_activity", "high")
			}
			entry := audit.DBQueryEntry{
				Timestamp: time.Now(),
				Engine:    "mysql",
				Database:  dbName.String,
				User:      user.String,
				Host:      host.String,
				Query:     query,
				Operation: "SELECT",
				RiskScore: float64(len(piiDetected)),
			}
			m.store.SaveDBQuery(entry)
		}
	}
	// Verificar errores después del bucle
	if err := rows.Err(); err != nil {
		m.log.Warn("MySQL: error al iterar processlist: %v", err)
	}
}

func (m *MySQLMonitor) Stop() error {
	if m.cancel != nil {
		m.cancel()
	}
	m.wg.Wait()
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
	m.connected = false
	return nil
}

// Métodos requeridos por la interfaz
func (m *MySQLMonitor) GetActiveQueries() []QueryInfo                { return []QueryInfo{} }
func (m *MySQLMonitor) GetSlowQueries() []SlowQuery                  { return []SlowQuery{} }
func (m *MySQLMonitor) GetLogEntries(limit int) []audit.DBQueryEntry { return []audit.DBQueryEntry{} }
func (m *MySQLMonitor) GetSummary() *ActivitySummary                 { return &ActivitySummary{} }
