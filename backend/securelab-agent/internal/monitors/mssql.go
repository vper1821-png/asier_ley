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

	_ "github.com/denisenkom/go-mssqldb"
)

type MSSQLMonitor struct {
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

func NewMSSQLMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *MSSQLMonitor {
	return &MSSQLMonitor{
		store:      store,
		wsClient:   wsClient,
		piiScanner: piiScanner,
		log:        log,
	}
}

func (m *MSSQLMonitor) Name() string { return "mssql" }

func (m *MSSQLMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
}

func (m *MSSQLMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()

	dsn := fmt.Sprintf("sqlserver://%s:%s@%s:%d?database=%s&connection+timeout=5&encrypt=disable&trustservercertificate=true",
		m.conn.Username, m.conn.Password, m.conn.Host, m.conn.Port, m.conn.Database)
	db, err := sql.Open("sqlserver", dsn)
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
	m.log.Info("MSSQL monitor conectado a %s:%d", m.conn.Host, m.conn.Port)

	m.wg.Add(1)
	go m.loop()
	return nil
}

func (m *MSSQLMonitor) loop() {
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

func (m *MSSQLMonitor) checkActivity() {
	m.mu.RLock()
	db := m.db
	if !m.connected || db == nil {
		m.mu.RUnlock()
		return
	}
	m.mu.RUnlock()

	rows, err := db.Query(`
		SELECT r.session_id, s.login_name, s.host_name, r.command, r.status,
		       DB_NAME(r.database_id) as db_name,
		       SUBSTRING(st.text, (r.statement_start_offset/2)+1,
		           ((CASE WHEN r.statement_end_offset = -1
		               THEN DATALENGTH(st.text)
		               ELSE r.statement_end_offset
		           END - r.statement_start_offset)/2)) as query_text,
		       r.start_time
		FROM sys.dm_exec_requests r
		JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
		OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) st
		WHERE s.is_user_process = 1
	`)
	if err != nil {
		m.log.Warn("MSSQL: error en dm_exec_requests: %v", err)
		return
	}
	defer rows.Close()

	for rows.Next() {
		var sessionID int64
		var loginName, hostName, command, status, dbName, queryText string
		var startTime time.Time
		if err := rows.Scan(&sessionID, &loginName, &hostName, &command, &status, &dbName, &queryText, &startTime); err != nil {
			continue
		}
		// Loguear todas las consultas activas, incluidas las del usuario sa.
		if loginName == "" || queryText == "" {
			continue
		}
		entry := audit.DBQueryEntry{
			Timestamp: time.Now(),
			Engine:    "mssql",
			Database:  dbName,
			User:      loginName,
			Host:      hostName,
			Query:     queryText,
			Operation: command, // MSSQL devuelve el tipo de comando real
		}
		reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
	}
	// Verificar errores después del bucle
	if err := rows.Err(); err != nil {
		m.log.Warn("MSSQL: error al iterar dm_exec_requests: %v", err)
	}
}

func (m *MSSQLMonitor) Stop() error {
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
func (m *MSSQLMonitor) GetActiveQueries() []QueryInfo                { return []QueryInfo{} }
func (m *MSSQLMonitor) GetSlowQueries() []SlowQuery                  { return []SlowQuery{} }
func (m *MSSQLMonitor) GetLogEntries(limit int) []audit.DBQueryEntry { return []audit.DBQueryEntry{} }
func (m *MSSQLMonitor) GetSummary() *ActivitySummary                 { return &ActivitySummary{} }
