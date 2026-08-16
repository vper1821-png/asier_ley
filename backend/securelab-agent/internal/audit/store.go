package audit

import (
	"database/sql"
	"encoding/json"
	"time"

	_ "modernc.org/sqlite"
)

type Store struct {
	db *sql.DB
}

func NewStore(dbPath string) *Store {
	dsn := dbPath + "?_journal_mode=WAL&_busy_timeout=5000"
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		panic(err)
	}
	s := &Store{db: db}
	s.init()
	s.migrateColumns()
	return s
}

func (s *Store) init() {
	_, err := s.db.Exec(`
		CREATE TABLE IF NOT EXISTS file_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			path TEXT NOT NULL,
			event_type TEXT NOT NULL,
			process_name TEXT,
			pid INTEGER,
			user TEXT,
			size INTEGER,
			hash TEXT,
			destination TEXT,
			personal_data TEXT,
			sensitive INTEGER DEFAULT 0,
			created_at TEXT DEFAULT (datetime('now'))
		);
		CREATE TABLE IF NOT EXISTS db_queries (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			engine TEXT,
			database TEXT,
			user TEXT,
			host TEXT,
			query TEXT,
			operation TEXT,
			risk_score REAL,
			created_at TEXT DEFAULT (datetime('now'))
		);
		CREATE TABLE IF NOT EXISTS host_events (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			timestamp TEXT NOT NULL,
			event_type TEXT NOT NULL,
			severity TEXT,
			title TEXT,
			detail TEXT,
			source TEXT,
			created_at TEXT DEFAULT (datetime('now'))
		);
		CREATE INDEX IF NOT EXISTS idx_file_events_path ON file_events(path);
		CREATE INDEX IF NOT EXISTS idx_file_events_ts ON file_events(timestamp);
		CREATE INDEX IF NOT EXISTS idx_db_queries_ts ON db_queries(timestamp);
		CREATE INDEX IF NOT EXISTS idx_host_events_ts ON host_events(timestamp);
	`)
	if err != nil {
		panic(err)
	}
}

func (s *Store) migrateColumns() {
	rows, err := s.db.Query("PRAGMA table_info(file_events)")
	if err != nil {
		return
	}
	defer rows.Close()

	columns := make(map[string]bool)
	for rows.Next() {
		var cid, notnull, pk int
		var name, ctype, dflt string
		if err := rows.Scan(&cid, &name, &ctype, &notnull, &dflt, &pk); err != nil {
			continue
		}
		columns[name] = true
	}
	if err := rows.Err(); err != nil {
		return
	}

	if !columns["personal_data"] {
		s.db.Exec("ALTER TABLE file_events ADD COLUMN personal_data TEXT;")
	}
	if !columns["sensitive"] {
		s.db.Exec("ALTER TABLE file_events ADD COLUMN sensitive INTEGER DEFAULT 0;")
	}
}

func (s *Store) SaveFileEvent(ev FileEvent) {
	personalDataJSON, _ := json.Marshal(ev.PersonalData)
	sensitive := 0
	if ev.Sensitive {
		sensitive = 1
	}
	_, err := s.db.Exec(`
		INSERT INTO file_events (timestamp, path, event_type, process_name, pid, user, size, hash, destination, personal_data, sensitive)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	`, ev.Timestamp.Format(time.RFC3339), ev.Path, ev.EventType, ev.ProcessName, ev.PID, ev.User, ev.Size, ev.Hash, ev.Destination, string(personalDataJSON), sensitive)
	if err != nil {
		// log interno
	}
}

func (s *Store) SaveDBQuery(entry DBQueryEntry) {
	_, err := s.db.Exec(`
		INSERT INTO db_queries (timestamp, engine, database, user, host, query, operation, risk_score)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)
	`, entry.Timestamp.Format(time.RFC3339), entry.Engine, entry.Database, entry.User, entry.Host, entry.Query, entry.Operation, entry.RiskScore)
	if err != nil {
		// log
	}
}

func (s *Store) SaveHostEvent(ev HostEvent) {
	_, err := s.db.Exec(`
		INSERT INTO host_events (timestamp, event_type, severity, title, detail, source)
		VALUES (?, ?, ?, ?, ?, ?)
	`, ev.Timestamp.Format(time.RFC3339), ev.Type, ev.Severity, ev.Title, ev.Detail, ev.Source)
	if err != nil {
		// log
	}
}

func (s *Store) Close() {
	if s.db != nil {
		s.db.Close()
	}
}
