package monitors

import (
	"context"
	"database/sql"
	"fmt"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/ws"

	_ "modernc.org/sqlite"
)

type SQLiteMonitor struct {
	mu        sync.RWMutex
	db        *sql.DB
	conn      DBConnection
	connected bool
	store     *audit.Store
	wsClient  *ws.Client
	log       *logger.Logger
	wg        sync.WaitGroup
	ctx       context.Context
	cancel    context.CancelFunc
}

func NewSQLiteMonitor(store *audit.Store, wsClient *ws.Client, log *logger.Logger) *SQLiteMonitor {
	return &SQLiteMonitor{
		store:    store,
		wsClient: wsClient,
		log:      log,
	}
}

func (m *SQLiteMonitor) Name() string { return "sqlite" }

func (m *SQLiteMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
	if m.db != nil {
		m.db.Close()
		m.db = nil
	}
}

func (m *SQLiteMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()

	dbPath := m.conn.Database
	if dbPath == "" {
		dbPath = m.conn.Host
	}
	db, err := sql.Open("sqlite", dbPath+"?mode=ro")
	if err != nil {
		return fmt.Errorf("open: %w", err)
	}
	if err := db.Ping(); err != nil {
		db.Close()
		return fmt.Errorf("ping: %w", err)
	}
	m.mu.Lock()
	m.db = db
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	m.log.Info("SQLite monitor conectado a %s", dbPath)
	return nil
}

func (m *SQLiteMonitor) Stop() error {
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

func (m *SQLiteMonitor) GetActiveQueries() []QueryInfo                { return []QueryInfo{} }
func (m *SQLiteMonitor) GetSlowQueries() []SlowQuery                  { return []SlowQuery{} }
func (m *SQLiteMonitor) GetLogEntries(limit int) []audit.DBQueryEntry { return []audit.DBQueryEntry{} }
func (m *SQLiteMonitor) GetSummary() *ActivitySummary                 { return &ActivitySummary{} }
