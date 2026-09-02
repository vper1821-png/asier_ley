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
	mu                 sync.RWMutex
	db                 *sql.DB
	conn               DBConnection
	connected          bool
	store              *audit.Store
	wsClient           *ws.Client
	piiScanner         *scanner.PIIScanner
	log                *logger.Logger
	wg                 sync.WaitGroup
	ctx                context.Context
	cancel             context.CancelFunc
	lastCheckTime      time.Time
	pgStatEnabled     bool
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
	m.pgStatEnabled = false
	m.lastCheckTime = time.Time{}
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
	db.SetMaxOpenConns(1)
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
	m.log.Info("PostgreSQL monitor conectado a %s:%d", m.conn.Host, m.conn.Port)

	// Intentar habilitar pg_stat_statements para captura de historial
	if _, err := db.Exec("CREATE EXTENSION IF NOT EXISTS pg_stat_statements"); err == nil {
		m.log.Info("PostgreSQL: pg_stat_statements habilitado para captura de historial")
		m.mu.Lock()
		m.pgStatEnabled = true
		m.mu.Unlock()
	} else {
		m.log.Warn("PostgreSQL: no se pudo habilitar pg_stat_statements: %v", err)
	}

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

	// Primero intentar usar pg_stat_statements para historial
	if m.pgStatEnabled {
		m.checkPgStatStatements(db)
	}

	// Luego consultar pg_stat_activity para consultas activas
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

func (m *PostgresMonitor) checkPgStatStatements(db *sql.DB) {
	m.mu.RLock()
	last := m.lastCheckTime
	m.mu.RUnlock()

	start := time.Now()
	since := last
	if since.IsZero() {
		// Primera vez: leer últimos 5 minutos
		since = start.Add(-5 * time.Minute)
	}

	// Consultar pg_stat_statements para historial de consultas
	query := `
		SELECT s.query, s.calls, s.total_exec_time, u.usename, d.datname
		FROM pg_stat_statements s
		JOIN pg_user u ON s.userid = u.usesysid
		JOIN pg_database d ON s.dbid = d.oid
		WHERE s.query != '<insufficient privilege>'
		ORDER BY s.calls DESC
		LIMIT 100
	`
	rows, err := db.Query(query)
	if err != nil {
		m.log.Warn("PostgreSQL: error en pg_stat_statements: %v", err)
		m.mu.Lock()
		m.pgStatEnabled = false
		m.mu.Unlock()
		return
	}
	defer rows.Close()

	count := 0
	for rows.Next() {
		var queryText, userName, dbName string
		var calls int64
		var totalTime float64
		if err := rows.Scan(&queryText, &calls, &totalTime, &userName, &dbName); err != nil {
			continue
		}
		if queryText == "" {
			continue
		}
		entry := audit.DBQueryEntry{
			Timestamp: time.Now(),
			Engine:    "postgres",
			Database:  dbName,
			User:      userName,
			Host:      "",
			Query:     queryText,
			Operation: fmt.Sprintf("ejecuciones: %d, tiempo total: %.2fms", calls, totalTime),
		}
		reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
		count++
	}

	if err := rows.Err(); err != nil {
		m.log.Warn("PostgreSQL: error al iterar pg_stat_statements: %v", err)
	}

	if count > 0 {
		m.mu.Lock()
		m.lastCheckTime = start
		m.mu.Unlock()
	}

	m.log.Debug("PostgreSQL: %d consultas enviadas desde pg_stat_statements", count)
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
