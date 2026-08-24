package audit

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"time"

	_ "modernc.org/sqlite"
)

type Store struct {
	db *sql.DB
}

func NewStore(dbPath string) *Store {
	dir := filepath.Dir(dbPath)
	if err := os.MkdirAll(dir, 0755); err != nil {
		panic("no se pudo crear el directorio para la base de datos: " + err.Error())
	}

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
		CREATE TABLE IF NOT EXISTS sensitive_inventory (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			agent_id TEXT NOT NULL,
			user_id TEXT NOT NULL,
			company_id TEXT NOT NULL,
			hostname TEXT NOT NULL,
			path TEXT NOT NULL,
			relative_path TEXT,
			size INTEGER NOT NULL,
			extension TEXT,
			categories TEXT NOT NULL,
			sensitive INTEGER NOT NULL DEFAULT 1,
			personal_data TEXT,
			hash TEXT,
			first_seen TEXT NOT NULL,
			last_scanned TEXT NOT NULL,
			last_modified TEXT NOT NULL,
			scan_count INTEGER NOT NULL DEFAULT 1,
			status TEXT NOT NULL DEFAULT 'active',
			created_at TEXT DEFAULT (datetime('now')),
			updated_at TEXT DEFAULT (datetime('now'))
		);
		CREATE INDEX IF NOT EXISTS idx_sensitive_inventory_agent ON sensitive_inventory(agent_id);
		CREATE INDEX IF NOT EXISTS idx_sensitive_inventory_company ON sensitive_inventory(company_id);
		CREATE INDEX IF NOT EXISTS idx_sensitive_inventory_path ON sensitive_inventory(path);
		CREATE INDEX IF NOT EXISTS idx_sensitive_inventory_status ON sensitive_inventory(status);
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

// SaveFileEvent guarda un evento de archivo en la base de datos local
func (s *Store) SaveFileEvent(ev FileEvent) error {
	personalDataJSON, err := json.Marshal(ev.PersonalData)
	if err != nil {
		return fmt.Errorf("serializando personal_data: %w", err)
	}
	sensitive := 0
	if ev.Sensitive {
		sensitive = 1
	}
	_, err = s.db.Exec(`
		INSERT INTO file_events (timestamp, path, event_type, process_name, pid, user, size, hash, destination, personal_data, sensitive)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	`, ev.Timestamp.Format(time.RFC3339), ev.Path, ev.EventType, ev.ProcessName, ev.PID, ev.User, ev.Size, ev.Hash, ev.Destination, string(personalDataJSON), sensitive)
	if err != nil {
		return fmt.Errorf("insertando file_event: %w", err)
	}
	return nil
}

// SaveDBQuery guarda una consulta de base de datos
func (s *Store) SaveDBQuery(entry DBQueryEntry) error {
	_, err := s.db.Exec(`
		INSERT INTO db_queries (timestamp, engine, database, user, host, query, operation, risk_score)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)
	`, entry.Timestamp.Format(time.RFC3339), entry.Engine, entry.Database, entry.User, entry.Host, entry.Query, entry.Operation, entry.RiskScore)
	if err != nil {
		return fmt.Errorf("insertando db_query: %w", err)
	}
	return nil
}

// SaveHostEvent guarda un evento del sistema/hardening
func (s *Store) SaveHostEvent(ev HostEvent) error {
	_, err := s.db.Exec(`
		INSERT INTO host_events (timestamp, event_type, severity, title, detail, source)
		VALUES (?, ?, ?, ?, ?, ?)
	`, ev.Timestamp.Format(time.RFC3339), ev.Type, ev.Severity, ev.Title, ev.Detail, ev.Source)
	if err != nil {
		return fmt.Errorf("insertando host_event: %w", err)
	}
	return nil
}

func (s *Store) Close() {
	if s.db != nil {
		s.db.Close()
	}
}

// SensitiveInventoryItem representa un item en el inventario de datos sensibles
type SensitiveInventoryItem struct {
	ID           int
	AgentID      string
	UserID       string
	CompanyID    string
	Hostname     string
	Path         string
	RelativePath string
	Size         int64
	Extension    string
	Categories   []string
	Sensitive    bool
	PersonalData map[string][]string
	Hash         string
	FirstSeen    time.Time
	LastScanned  time.Time
	LastModified time.Time
	ScanCount    int
	Status       string
	CreatedAt    time.Time
	UpdatedAt    time.Time
}

// SaveInitialInventory guarda o actualiza un item en el inventario de datos sensibles
func (s *Store) SaveInitialInventory(item SensitiveInventoryItem) error {
	categoriesJSON, _ := json.Marshal(item.Categories)
	personalDataJSON, _ := json.Marshal(item.PersonalData)

	// Verificar si ya existe
	var existingID int
	err := s.db.QueryRow(`
		SELECT id FROM sensitive_inventory WHERE agent_id = ? AND path = ?
	`, item.AgentID, item.Path).Scan(&existingID)

	now := time.Now().Format(time.RFC3339)
	if err != nil && err != sql.ErrNoRows {
		return err
	}

	if existingID > 0 {
		// Actualizar
		_, err = s.db.Exec(`
			UPDATE sensitive_inventory SET
				user_id = ?, company_id = ?, hostname = ?, relative_path = ?,
				size = ?, extension = ?, categories = ?, sensitive = ?,
				personal_data = ?, hash = ?, last_scanned = ?,
				last_modified = ?, scan_count = scan_count + 1,
				status = ?, updated_at = ?
			WHERE id = ?
		`, item.UserID, item.CompanyID, item.Hostname, item.RelativePath,
			item.Size, item.Extension, string(categoriesJSON), boolToInt(item.Sensitive),
			string(personalDataJSON), item.Hash, item.LastScanned.Format(time.RFC3339),
			item.LastModified.Format(time.RFC3339), item.Status, now, existingID)
		return err
	}

	// Insertar nuevo
	_, err = s.db.Exec(`
		INSERT INTO sensitive_inventory (
			agent_id, user_id, company_id, hostname, path, relative_path,
			size, extension, categories, sensitive, personal_data, hash,
			first_seen, last_scanned, last_modified, scan_count, status, created_at, updated_at
		) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
	`, item.AgentID, item.UserID, item.CompanyID, item.Hostname, item.Path, item.RelativePath,
		item.Size, item.Extension, string(categoriesJSON), boolToInt(item.Sensitive),
		string(personalDataJSON), item.Hash,
		item.FirstSeen.Format(time.RFC3339), item.LastScanned.Format(time.RFC3339),
		item.LastModified.Format(time.RFC3339), item.ScanCount, item.Status, now, now)
	return err
}

// FindSensitiveInventory busca items en el inventario
func (s *Store) FindSensitiveInventory(agentID, companyID, status string, limit int) ([]SensitiveInventoryItem, error) {
	query := `SELECT id, agent_id, user_id, company_id, hostname, path, relative_path,
		size, extension, categories, sensitive, personal_data, hash,
		first_seen, last_scanned, last_modified, scan_count, status, created_at, updated_at
		FROM sensitive_inventory WHERE 1=1`
	args := []interface{}{}

	if agentID != "" {
		query += " AND agent_id = ?"
		args = append(args, agentID)
	}
	if companyID != "" {
		query += " AND company_id = ?"
		args = append(args, companyID)
	}
	if status != "" {
		query += " AND status = ?"
		args = append(args, status)
	}
	query += " ORDER BY last_scanned DESC"
	if limit > 0 {
		query += " LIMIT ?"
		args = append(args, limit)
	}

	rows, err := s.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []SensitiveInventoryItem
	for rows.Next() {
		var item SensitiveInventoryItem
		var catsJSON, pdJSON string
		var firstSeenStr, lastScannedStr, lastModStr, createdStr, updatedStr string
		if err := rows.Scan(&item.ID, &item.AgentID, &item.UserID, &item.CompanyID,
			&item.Hostname, &item.Path, &item.RelativePath, &item.Size, &item.Extension,
			&catsJSON, &item.Sensitive, &pdJSON, &item.Hash,
			&firstSeenStr, &lastScannedStr, &lastModStr, &item.ScanCount, &item.Status,
			&createdStr, &updatedStr); err != nil {
			continue
		}
		json.Unmarshal([]byte(catsJSON), &item.Categories)
		json.Unmarshal([]byte(pdJSON), &item.PersonalData)
		item.FirstSeen, _ = time.Parse(time.RFC3339, firstSeenStr)
		item.LastScanned, _ = time.Parse(time.RFC3339, lastScannedStr)
		item.LastModified, _ = time.Parse(time.RFC3339, lastModStr)
		item.CreatedAt, _ = time.Parse(time.RFC3339, createdStr)
		item.UpdatedAt, _ = time.Parse(time.RFC3339, updatedStr)
		items = append(items, item)
	}
	return items, nil
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// GetSensitiveInventoryByPath busca un item del inventario por ruta exacta
func (s *Store) GetSensitiveInventoryByPath(path string) (*SensitiveInventoryItem, error) {
	query := `SELECT id, agent_id, user_id, company_id, hostname, path, relative_path,
		size, extension, categories, sensitive, personal_data, hash,
		first_seen, last_scanned, last_modified, scan_count, status, created_at, updated_at
		FROM sensitive_inventory WHERE path = ? AND status = 'active' LIMIT 1`

	var item SensitiveInventoryItem
	var catsJSON, pdJSON string
	var firstSeenStr, lastScannedStr, lastModStr, createdStr, updatedStr string

	err := s.db.QueryRow(query, path).Scan(
		&item.ID, &item.AgentID, &item.UserID, &item.CompanyID, &item.Hostname,
		&item.Path, &item.RelativePath, &item.Size, &item.Extension,
		&catsJSON, &item.Sensitive, &pdJSON, &item.Hash,
		&firstSeenStr, &lastScannedStr, &lastModStr, &item.ScanCount, &item.Status,
		&createdStr, &updatedStr)

	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}

	item.FirstSeen, _ = time.Parse(time.RFC3339, firstSeenStr)
	item.LastScanned, _ = time.Parse(time.RFC3339, lastScannedStr)
	item.LastModified, _ = time.Parse(time.RFC3339, lastModStr)
	item.CreatedAt, _ = time.Parse(time.RFC3339, createdStr)
	item.UpdatedAt, _ = time.Parse(time.RFC3339, updatedStr)
	_ = json.Unmarshal([]byte(catsJSON), &item.Categories)
	_ = json.Unmarshal([]byte(pdJSON), &item.PersonalData)
	return &item, nil
}

// UpdateInventoryOnFileEvent actualiza el inventario cuando un archivo sensible tiene un evento
func (s *Store) UpdateInventoryOnFileEvent(ev FileEvent) error {
	if ev.Path == "" {
		return nil
	}

	// Verificar si el archivo está en el inventario
	var itemID int
	err := s.db.QueryRow(`
		SELECT id FROM sensitive_inventory WHERE path = ? AND status = 'active'
	`, ev.Path).Scan(&itemID)

	if err == sql.ErrNoRows {
		// No está en inventario, no hacer nada
		return nil
	}
	if err != nil {
		return err
	}

	// Actualizar según tipo de evento
	now := time.Now().Format(time.RFC3339)
	status := "active"
	scanCountInc := 0

	switch ev.EventType {
	case "delete":
		status = "deleted"
	case "move":
		status = "moved"
	case "modify", "create":
		scanCountInc = 1
		status = "modified"
	}

	if scanCountInc > 0 {
		_, err = s.db.Exec(`
			UPDATE sensitive_inventory SET
				last_scanned = ?, last_modified = ?, scan_count = scan_count + 1,
				status = ?, updated_at = ?
			WHERE id = ?
		`, now, now, status, now, itemID)
	} else {
		_, err = s.db.Exec(`
			UPDATE sensitive_inventory SET
				last_scanned = ?, status = ?, updated_at = ?
			WHERE id = ?
		`, now, status, now, itemID)
	}
	return err
}

type FileEventFilter struct {
	AgentId string
	Path    string
	Since   time.Time
	Limit   int
}

func (s *Store) FindFileEvents(filter FileEventFilter) []FileEvent {
	query := "SELECT timestamp, path, event_type, process_name, pid, user, size, hash, destination, personal_data, sensitive FROM file_events WHERE 1=1"
	args := []interface{}{}

	if filter.AgentId != "" {
		query += " AND agentId = ?"
		args = append(args, filter.AgentId)
	}
	if filter.Path != "" {
		query += " AND path = ?"
		args = append(args, filter.Path)
	}
	if !filter.Since.IsZero() {
		query += " AND timestamp >= ?"
		args = append(args, filter.Since.Format(time.RFC3339))
	}
	query += " ORDER BY timestamp DESC"
	if filter.Limit > 0 {
		query += " LIMIT ?"
		args = append(args, filter.Limit)
	}

	rows, err := s.db.Query(query, args...)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var events []FileEvent
	for rows.Next() {
		var ev FileEvent
		var pd string
		var sens int
		if err := rows.Scan(&ev.Timestamp, &ev.Path, &ev.EventType, &ev.ProcessName, &ev.PID, &ev.User, &ev.Size, &ev.Hash, &ev.Destination, &pd, &sens); err != nil {
			continue
		}
		if pd != "" {
			_ = json.Unmarshal([]byte(pd), &ev.PersonalData)
		}
		ev.Sensitive = sens == 1
		events = append(events, ev)
	}
	return events
}

