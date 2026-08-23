package monitors

import (
	"context"
	"database/sql"
	"fmt"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"

	_ "github.com/lib/pq"
)

type PostgresMonitor struct {
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

func NewPostgresMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *PostgresMonitor {
	return &PostgresMonitor{
		store:      store,
		wsClient:   wsClient,
		piiScanner: piiScanner,
		log:        log,
	}
}

func (m *PostgresMonitor) Name() string { return "postgres" }

func (m *PostgresMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
}

func (m *PostgresMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()

	dsn := fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=disable connect_timeout=5",
		m.conn.Host, m.conn.Port, m.conn.Username, m.conn.Password, m.conn.Database)
	db, err := sql.Open("postgres", dsn)
	if err != nil {
		return fmt.Errorf("open: %w", err)
	}
	db.SetMaxOpenConns(2)
	if err := db.Ping(); err != nil {
		db.Close()
		return fmt.Errorf("ping: %w", err)
	}
	m.mu.Lock()
	m.db = db
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	m.log.Info("PostgreSQL monitor conectado a %s:%d", m.conn.Host, m.conn.Port)

	m.wg.Add(1)
	go m.loop()
	return nil
}

func (m *PostgresMonitor) loop() {
	defer m.wg.Done()
	ticker := time.NewTicker(10 * time.Second)
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

func (m *PostgresMonitor) checkActivity() {
	m.mu.RLock()
	db := m.db
	if !m.connected || db == nil {
		m.mu.RUnlock()
		return
	}
	m.mu.RUnlock()

	rows, err := db.Query(`
		SELECT pid, usename, client_addr, query, query_start
		FROM pg_stat_activity
		WHERE state = 'active' AND pid != pg_backend_pid()
	`)
	if err != nil {
		m.log.Warn("PostgreSQL: error en pg_stat_activity: %v", err)
		return
	}
	defer rows.Close()

	for rows.Next() {
		var pid int64
		var usename, clientAddr, query string
		var queryStart time.Time
		if err := rows.Scan(&pid, &usename, &clientAddr, &query, &queryStart); err != nil {
			continue
		}
		// Loguear todas las consultas activas, incluidas las del superusuario postgres.
		if usename == "" || query == "" {
			continue
		}
		entry := audit.DBQueryEntry{
			Timestamp: time.Now(),
			Engine:    "postgres",
			Database:  m.conn.Database,
			User:      usename,
			Host:      clientAddr,
			Query:     query,
			Operation: "", // se clasifica en reportDBQuery
		}
		reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
	}
	// Verificar errores después del bucle
	if err := rows.Err(); err != nil {
		m.log.Warn("PostgreSQL: error al iterar pg_stat_activity: %v", err)
	}
}

func (m *PostgresMonitor) Stop() error {
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
func (m *PostgresMonitor) GetActiveQueries() []QueryInfo { return []QueryInfo{} }
func (m *PostgresMonitor) GetSlowQueries() []SlowQuery   { return []SlowQuery{} }
func (m *PostgresMonitor) GetLogEntries(limit int) []audit.DBQueryEntry {
	return []audit.DBQueryEntry{}
}
func (m *PostgresMonitor) GetSummary() *ActivitySummary { return &ActivitySummary{} }
