package main

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"

	_ "modernc.org/sqlite"
)

// ──────────────────────────────────────────────
// Persistent Audit Store (SQLite)
// Stores host events, query logs, and connection
// events so they survive agent restarts.
// ──────────────────────────────────────────────

type AuditStore struct {
	mu     sync.RWMutex
	db     *sql.DB
	dbPath string
}

var globalAuditStore *AuditStore
var auditStoreOnce sync.Once

func GetAuditStore() *AuditStore {
	auditStoreOnce.Do(func() {
		exe, _ := os.Executable()
		dir := filepath.Dir(exe)
		dbPath := filepath.Join(dir, "audit_log.db")
		globalAuditStore = NewAuditStore(dbPath)
	})
	return globalAuditStore
}

func NewAuditStore(dbPath string) *AuditStore {
	s := &AuditStore{dbPath: dbPath}
	if err := s.init(); err != nil {
		logMsg("AuditStore: init failed: %v", err)
		return s
	}
	logMsg("AuditStore: initialized at %s", dbPath)
	return s
}

func (s *AuditStore) init() error {
	var err error
	s.db, err = sql.Open("sqlite", s.dbPath+"?_journal_mode=WAL&_busy_timeout=5000")
	if err != nil {
		return fmt.Errorf("open: %w", err)
	}

	s.db.SetMaxOpenConns(1) // SQLite = single writer

	tables := []string{
		`CREATE TABLE IF NOT EXISTS host_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			event_type TEXT NOT NULL,
			severity TEXT NOT NULL,
			title TEXT NOT NULL,
			detail TEXT NOT NULL,
			source TEXT NOT NULL,
			created_at TEXT DEFAULT (datetime('now'))
		)`,
		`CREATE TABLE IF NOT EXISTS query_logs (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			database_name TEXT,
			username TEXT,
			host TEXT,
			query_text TEXT NOT NULL,
			engine TEXT,
			log_type TEXT,
			risk_score REAL DEFAULT 0,
			created_at TEXT DEFAULT (datetime('now'))
		)`,
		`CREATE TABLE IF NOT EXISTS connection_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			event_type TEXT NOT NULL,
			pid INTEGER,
			process_name TEXT,
			local_addr TEXT,
			remote_addr TEXT,
			port INTEGER,
			db_type TEXT,
			is_remote INTEGER DEFAULT 0,
			severity TEXT DEFAULT 'low',
			created_at TEXT DEFAULT (datetime('now'))
		)`,
		`CREATE TABLE IF NOT EXISTS windows_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			channel TEXT NOT NULL,
			provider TEXT,
			event_id INTEGER,
			level TEXT,
			message TEXT NOT NULL,
			created_at TEXT DEFAULT (datetime('now'))
		)`,
		`CREATE INDEX IF NOT EXISTS idx_host_events_ts ON host_events(timestamp)`,
		`CREATE INDEX IF NOT EXISTS idx_host_events_type ON host_events(event_type)`,
		`CREATE INDEX IF NOT EXISTS idx_query_logs_ts ON query_logs(timestamp)`,
		`CREATE INDEX IF NOT EXISTS idx_connection_events_ts ON connection_events(timestamp)`,
		`CREATE INDEX IF NOT EXISTS idx_windows_events_ts ON windows_events(timestamp)`,
	}

	for _, ddl := range tables {
		if _, err := s.db.Exec(ddl); err != nil {
			return fmt.Errorf("exec DDL: %w", err)
		}
	}

	return nil
}

// StoreHostEvent persists a host event to SQLite
func (s *AuditStore) StoreHostEvent(ev HostEvent) {
	if s == nil || s.db == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	_, err := s.db.Exec(
		`INSERT INTO host_events (timestamp, event_type, severity, title, detail, source) VALUES (?, ?, ?, ?, ?, ?)`,
		ev.Timestamp.Format(time.RFC3339), ev.Type, ev.Severity, ev.Title, ev.Detail, ev.Source,
	)
	if err != nil {
		logMsg("AuditStore: insert host_event failed: %v", err)
	}
}

// StoreQueryLog persists a query log entry
func (s *AuditStore) StoreQueryLog(entry QueryLogEntry) {
	if s == nil || s.db == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	_, err := s.db.Exec(
		`INSERT INTO query_logs (timestamp, database_name, username, host, query_text, engine, log_type, risk_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
		entry.Timestamp.Format(time.RFC3339), entry.Database, entry.User, entry.Host,
		entry.Query, entry.Engine, entry.Operation, 0,
	)
	if err != nil {
		logMsg("AuditStore: insert query_log failed: %v", err)
	}
}

// StoreConnectionEvent persists a process connection event
func (s *AuditStore) StoreConnectionEvent(ts time.Time, eventType, procName, localAddr, remoteAddr, dbType string, pid, port int, isRemote bool, severity string) {
	if s == nil || s.db == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	isRemoteInt := 0
	if isRemote {
		isRemoteInt = 1
	}

	_, err := s.db.Exec(
		`INSERT INTO connection_events (timestamp, event_type, pid, process_name, local_addr, remote_addr, port, db_type, is_remote, severity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		ts.Format(time.RFC3339), eventType, pid, procName, localAddr, remoteAddr, port, dbType, isRemoteInt, severity,
	)
	if err != nil {
		logMsg("AuditStore: insert connection_event failed: %v", err)
	}
}

// StoreWindowsEvent persists a Windows Event Log entry
func (s *AuditStore) StoreWindowsEvent(ts time.Time, channel, provider, message string, eventID int, level string) {
	if s == nil || s.db == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	_, err := s.db.Exec(
		`INSERT INTO windows_events (timestamp, channel, provider, event_id, level, message) VALUES (?, ?, ?, ?, ?, ?)`,
		ts.Format(time.RFC3339), channel, provider, eventID, level, message,
	)
	if err != nil {
		logMsg("AuditStore: insert windows_event failed: %v", err)
	}
}

// GetHostEvents retrieves host events from the store
func (s *AuditStore) GetHostEvents(limit int, since time.Duration) []HostEvent {
	if s == nil || s.db == nil {
		return nil
	}
	s.mu.RLock()
	defer s.mu.RUnlock()

	cutoff := time.Now().Add(-since)
	rows, err := s.db.Query(
		`SELECT timestamp, event_type, severity, title, detail, source FROM host_events WHERE timestamp > ? ORDER BY timestamp DESC LIMIT ?`,
		cutoff.Format(time.RFC3339), limit,
	)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var events []HostEvent
	for rows.Next() {
		var ev HostEvent
		var ts string
		if err := rows.Scan(&ts, &ev.Type, &ev.Severity, &ev.Title, &ev.Detail, &ev.Source); err != nil {
			continue
		}
		ev.Timestamp, _ = time.Parse(time.RFC3339, ts)
		events = append(events, ev)
	}
	return events
}

// GetStats returns event counts by type and severity
func (s *AuditStore) GetStats() map[string]interface{} {
	if s == nil || s.db == nil {
		return nil
	}
	s.mu.RLock()
	defer s.mu.RUnlock()

	stats := map[string]interface{}{}

	var totalHost, totalQuery, totalConn, totalWin int
	s.db.QueryRow(`SELECT COUNT(*) FROM host_events`).Scan(&totalHost)
	s.db.QueryRow(`SELECT COUNT(*) FROM query_logs`).Scan(&totalQuery)
	s.db.QueryRow(`SELECT COUNT(*) FROM connection_events`).Scan(&totalConn)
	s.db.QueryRow(`SELECT COUNT(*) FROM windows_events`).Scan(&totalWin)

	stats["host_events"] = totalHost
	stats["query_logs"] = totalQuery
	stats["connection_events"] = totalConn
	stats["windows_events"] = totalWin
	stats["total"] = totalHost + totalQuery + totalConn + totalWin

	// Severity breakdown
	rows, err := s.db.Query(`SELECT severity, COUNT(*) FROM host_events GROUP BY severity`)
	if err == nil {
		defer rows.Close()
		bySev := map[string]int{}
		for rows.Next() {
			var sev string
			var cnt int
			if rows.Scan(&sev, &cnt) == nil {
				bySev[sev] = cnt
			}
		}
		stats["by_severity"] = bySev
	}

	// Remote connection count
	var remoteConns int
	s.db.QueryRow(`SELECT COUNT(*) FROM connection_events WHERE is_remote = 1`).Scan(&remoteConns)
	stats["remote_connections"] = remoteConns

	return stats
}

// CleanupOld removes events older than the retention period
func (s *AuditStore) CleanupOld(retentionDays int) {
	if s == nil || s.db == nil {
		return
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	cutoff := time.Now().AddDate(0, 0, -retentionDays).Format(time.RFC3339)
	tables := []string{"host_events", "query_logs", "connection_events", "windows_events"}
	for _, t := range tables {
		s.db.Exec(fmt.Sprintf(`DELETE FROM %s WHERE timestamp < ?`, t), cutoff)
	}
}

func (s *AuditStore) Close() {
	if s != nil && s.db != nil {
		s.db.Close()
	}
}
