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
	monitorThreadID    int64
	lastLogTime        time.Time
	lastTimerEnd       uint64
	generalLogEnabled  bool
	perfSchemaEnabled  bool
	generalLogDeletable bool
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
	m.generalLogEnabled = false
	m.perfSchemaEnabled = false
	m.generalLogDeletable = false
	m.lastLogTime = time.Time{}
	m.lastTimerEnd = 0
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

	// Obtener ID del hilo de esta conexión para excluir nuestras propias consultas
	var threadID int64
	_ = db.QueryRow("SELECT CONNECTION_ID()").Scan(&threadID)

	// Intentar activar general_log en tabla para capturar TODO
	if _, err := db.Exec("SET GLOBAL log_output = 'TABLE'"); err == nil {
		if _, err := db.Exec("SET GLOBAL general_log = 'ON'"); err == nil {
			m.log.Info("MySQL general_log habilitado para captura completa")
		} else {
			m.log.Warn("MySQL: no se pudo habilitar general_log: %v", err)
		}
	} else {
		m.log.Warn("MySQL: no se pudo cambiar log_output: %v", err)
	}

	m.mu.Lock()
	m.db = db
	m.monitorThreadID = threadID
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	m.log.Info("MySQL monitor conectado a %s:%d (thread %d)", m.conn.Host, m.conn.Port, threadID)

	m.wg.Add(1)
	go m.loop()
	return nil
}

func (m *MySQLMonitor) loop() {
	defer m.wg.Done()
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()

	// Primera captura inmediata
	m.checkActivity()

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

	if !m.generalLogEnabled && !m.perfSchemaEnabled {
		m.detectLogMode(db)
	}

	if m.generalLogEnabled {
		m.checkGeneralLog(db)
		return
	}

	if m.perfSchemaEnabled {
		m.checkPerfSchema(db)
		return
	}

	// Fallback: SHOW FULL PROCESSLIST (solo activas)
	m.checkProcessList(db)
}

func (m *MySQLMonitor) detectLogMode(db *sql.DB) {
	// Verificar si general_log está activo y en modo TABLE
	var genLogName, genLog, logOutName, logOutput string
	_ = db.QueryRow("SHOW VARIABLES LIKE 'general_log'").Scan(&genLogName, &genLog)
	_ = db.QueryRow("SHOW VARIABLES LIKE 'log_output'").Scan(&logOutName, &logOutput)

	if strings.EqualFold(logOutput, "TABLE") {
		var dummy int
		err := db.QueryRow("SELECT 1 FROM mysql.general_log LIMIT 1").Scan(&dummy)
		if err == nil || err == sql.ErrNoRows {
			m.generalLogEnabled = true
			m.log.Info("MySQL: usando mysql.general_log para captura completa")
			return
		}
	}

	// Probar performance_schema
	var dummy int
	err := db.QueryRow("SELECT 1 FROM performance_schema.events_statements_history_long LIMIT 1").Scan(&dummy)
	if err == nil {
		m.perfSchemaEnabled = true
		m.log.Info("MySQL: usando performance_schema para captura de consultas")
		return
	}

	m.log.Warn("MySQL: ni general_log ni performance_schema disponibles, usando processlist")
}

func (m *MySQLMonitor) checkGeneralLog(db *sql.DB) {
	m.mu.RLock()
	last := m.lastLogTime
	threadID := m.monitorThreadID
	m.mu.RUnlock()

	start := time.Now()
	since := last
	if since.IsZero() {
		// Primera vez: leer últimos 2 minutos
		since = start.Add(-2 * time.Minute)
	}

	// Leer consultas posteriores a la última ejecución, excluyendo nuestro propio hilo
	query := `
		SELECT event_time, user_host, thread_id, command_type, argument, db
		FROM mysql.general_log
		WHERE event_time > ? AND thread_id != ?
		  AND (command_type = 'Query' OR command_type = 'Execute' OR command_type = 'Prepare')
		ORDER BY event_time ASC
		LIMIT 1000
	`
	rows, err := db.Query(query, since, threadID)
	if err != nil {
		m.log.Warn("MySQL: error leyendo general_log: %v", err)
		// Si la tabla deja de existir, volver a detectar
		m.mu.Lock()
		m.generalLogEnabled = false
		m.mu.Unlock()
		return
	}
	defer rows.Close()

	var maxTime time.Time
	count := 0
	for rows.Next() {
		var eventTime time.Time
		var userHost, commandType, argument, dbName sql.NullString
		var tid int64
		if err := rows.Scan(&eventTime, &userHost, &tid, &commandType, &argument, &dbName); err != nil {
			continue
		}
		if argument.String == "" {
			continue
		}
		user, host := parseUserHost(userHost.String)
		entry := audit.DBQueryEntry{
			Timestamp: eventTime,
			Engine:    "mysql",
			Database:  dbName.String,
			User:      user,
			Host:      host,
			Query:     argument.String,
			Operation: "",
		}
		reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
		count++
		if eventTime.After(maxTime) {
			maxTime = eventTime
		}
	}

	if err := rows.Err(); err != nil {
		m.log.Warn("MySQL: error iterando general_log: %v", err)
	}

	if !maxTime.IsZero() {
		m.mu.Lock()
		m.lastLogTime = maxTime
		m.mu.Unlock()
	}

	// Limpiar entradas ya leídas para evitar crecimiento
	if count > 0 && m.generalLogDeletable {
		_, _ = db.Exec("DELETE FROM mysql.general_log WHERE event_time <= ?", maxTime)
	} else if count > 0 {
		// Comprobar si podemos borrar
		_, err := db.Exec("DELETE FROM mysql.general_log WHERE event_time <= ?", maxTime)
		if err == nil {
			m.mu.Lock()
			m.generalLogDeletable = true
			m.mu.Unlock()
		}
	}

	m.log.Debug("MySQL: %d consultas enviadas desde general_log", count)
}

func (m *MySQLMonitor) checkPerfSchema(db *sql.DB) {
	m.mu.RLock()
	lastTimer := m.lastTimerEnd
	threadID := m.monitorThreadID
	m.mu.RUnlock()

	query := `
		SELECT h.SQL_TEXT, t.PROCESSLIST_USER, t.PROCESSLIST_HOST, t.PROCESSLIST_DB, h.TIMER_END
		FROM performance_schema.events_statements_history_long h
		LEFT JOIN performance_schema.threads t ON h.THREAD_ID = t.THREAD_ID
		WHERE h.TIMER_END > ? AND (t.PROCESSLIST_ID IS NULL OR t.PROCESSLIST_ID != ?)
		ORDER BY h.TIMER_END ASC
		LIMIT 1000
	`
	rows, err := db.Query(query, lastTimer, threadID)
	if err != nil {
		m.log.Warn("MySQL: error leyendo performance_schema: %v", err)
		m.mu.Lock()
		m.perfSchemaEnabled = false
		m.mu.Unlock()
		return
	}
	defer rows.Close()

	var maxTimer uint64
	count := 0
	for rows.Next() {
		var sqlText, user, host, dbName sql.NullString
		var timerEnd int64
		if err := rows.Scan(&sqlText, &user, &host, &dbName, &timerEnd); err != nil {
			continue
		}
		if sqlText.String == "" {
			continue
		}
		if timerEnd < 0 {
			continue
		}
		entry := audit.DBQueryEntry{
			Timestamp: time.Now(),
			Engine:    "mysql",
			Database:  dbName.String,
			User:      user.String,
			Host:      host.String,
			Query:     sqlText.String,
			Operation: "",
		}
		reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
		count++
		if uint64(timerEnd) > maxTimer {
			maxTimer = uint64(timerEnd)
		}
	}

	if err := rows.Err(); err != nil {
		m.log.Warn("MySQL: error iterando performance_schema: %v", err)
	}

	if maxTimer > 0 {
		m.mu.Lock()
		m.lastTimerEnd = maxTimer
		m.mu.Unlock()
	}

	m.log.Debug("MySQL: %d consultas enviadas desde performance_schema", count)
}

func (m *MySQLMonitor) checkProcessList(db *sql.DB) {
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
		if user.String == "" {
			continue
		}
		if info.String != "" && command.String == "Query" {
			entry := audit.DBQueryEntry{
				Timestamp: time.Now(),
				Engine:    "mysql",
				Database:  dbName.String,
				User:      user.String,
				Host:      host.String,
				Query:     info.String,
				Operation: "",
			}
			reportDBQuery(m.store, m.wsClient, m.piiScanner, m.log, entry)
		}
	}
	if err := rows.Err(); err != nil {
		m.log.Warn("MySQL: error al iterar processlist: %v", err)
	}
}

func parseUserHost(userHost string) (string, string) {
	parts := strings.SplitN(userHost, "@", 2)
	if len(parts) == 2 {
		return parts[0], parts[1]
	}
	return userHost, ""
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
