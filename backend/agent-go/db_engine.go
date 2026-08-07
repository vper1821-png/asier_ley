package main

import (
	"database/sql"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"

	_ "github.com/denisenkom/go-mssqldb"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
	_ "modernc.org/sqlite"
)

type DBEngine struct {
	mu          sync.RWMutex
	connections map[string]*ManagedDB
	discovered  []DBInstance
	lastScan    time.Time
	scanRunning bool
}

type DBInstance struct {
	Engine     string
	Host       string
	Port       int
	Version    string
	DataDir    string
	ConfigFile string
	Service    string
	Status     string
	Databases  []string
	Source     string
}

type ManagedDB struct {
	Instance   DBInstance
	DB         *sql.DB
	Connected  bool
	LastPing   time.Time
	ErrorCount int
	Tables     []string
}

type DBTableInfo struct {
	Name     string
	Rows     int64
	SizeKB   float64
	Engine   string
	Comment  string
}

type DBScanResult struct {
	Instance    DBInstance
	Databases   []string
	Tables      map[string][]DBTableInfo
	TotalTables int
	TotalRows   int64
	ScannedAt   time.Time
}

var dbEngineInstance *DBEngine
var dbEngineOnce sync.Once

var wellKnownPorts = []int{
	3306, 3307, 3308,
	5432, 5433,
	1433, 1434,
	27017, 27018,
	6379, 6380,
	1521, 1522,
	9042, 9043,
	9000, 9001,
	8529,
	5984,
	11211,
	9200, 9300,
	8086,
}

var engineByPort = map[int]string{
	3306: "mysql", 3307: "mysql", 3308: "mysql",
	5432: "postgresql", 5433: "postgresql",
	1433: "mssql", 1434: "mssql",
	27017: "mongodb", 27018: "mongodb",
	6379: "redis", 6380: "redis",
	1521: "oracle", 1522: "oracle",
	9042: "cassandra", 9043: "cassandra",
	9000: "clickhouse", 9001: "clickhouse",
	8529: "arangodb",
	5984: "couchdb",
	11211: "memcached",
	9200: "elasticsearch", 9300: "elasticsearch",
	8086: "influxdb",
}

var mysqlDSNFormats = []string{
	"%s:%s@tcp(%s:%d)/%s?timeout=5s",
	"%s@tcp(%s:%d)/%s?timeout=5s",
}

var pgDSNFormats = []string{
	"host=%s port=%d user=%s password=%s dbname=%s sslmode=disable connect_timeout=5",
	"postgres://%s:%s@%s:%d/%s?sslmode=disable&connect_timeout=5",
}

func GetDBEngine() *DBEngine {
	dbEngineOnce.Do(func() {
		dbEngineInstance = &DBEngine{
			connections: make(map[string]*ManagedDB),
		}
	})
	return dbEngineInstance
}

func (e *DBEngine) RunFullDiscovery() []DBInstance {
	e.mu.Lock()
	if e.scanRunning {
		e.mu.Unlock()
		return e.discovered
	}
	e.scanRunning = true
	e.mu.Unlock()

	defer func() {
		e.mu.Lock()
		e.scanRunning = false
		e.mu.Unlock()
	}()

	var instances []DBInstance

	instances = append(instances, e.scanTCPPorts("127.0.0.1")...)

	instances = append(instances, e.scanTCPPorts("localhost")...)

	instances = append(instances, e.discoverFromConfigFiles()...)

	instances = append(instances, e.discoverFromRegistry()...)

	merged := e.mergeInstances(instances)

	for i := range merged {
		merged[i].Status = e.probePort(merged[i].Host, strconv.Itoa(merged[i].Port))
	}

	e.mu.Lock()
	e.discovered = merged
	e.lastScan = time.Now()
	e.mu.Unlock()

	return merged
}

func (e *DBEngine) scanTCPPorts(host string) []DBInstance {
	var results []DBInstance
	type portResult struct {
		port int
		open bool
	}

	ch := make(chan portResult, len(wellKnownPorts))
	timeout := 300 * time.Millisecond

	for _, port := range wellKnownPorts {
		go func(p int) {
			addr := fmt.Sprintf("%s:%d", host, p)
			conn, err := net.DialTimeout("tcp", addr, timeout)
			if err == nil {
				conn.Close()
				ch <- portResult{port: p, open: true}
			} else {
				ch <- portResult{port: p, open: false}
			}
		}(port)
	}

	for range wellKnownPorts {
		r := <-ch
		if r.open {
			engine := engineByPort[r.port]
			if engine == "" {
				engine = "unknown"
			}
			results = append(results, DBInstance{
				Engine: engine,
				Host:   host,
				Port:   r.port,
				Status: "running",
				Source: "port_scan",
			})
		}
	}

	return results
}

func (e *DBEngine) probePort(host, port string) string {
	addr := fmt.Sprintf("%s:%s", host, port)
	conn, err := net.DialTimeout("tcp", addr, 500*time.Millisecond)
	if err != nil {
		return "unreachable"
	}
	conn.Close()
	return "running"
}

func (e *DBEngine) discoverFromConfigFiles() []DBInstance {
	var results []DBInstance

	knownPaths := []struct {
		engine string
		paths  []string
	}{
		{"mysql", []string{
			`C:\xampp\mysql\bin\my.ini`,
			`C:\wamp64\bin\mysql\mysql8.0.31\my.ini`,
			`C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`,
			`C:\ProgramData\MySQL\MySQL Server 5.7\my.ini`,
			`/etc/mysql/my.cnf`,
			`/etc/my.cnf`,
		}},
		{"mariadb", []string{
			`C:\xampp\mariadb\bin\my.ini`,
			`C:\Program Files\MariaDB 10.11\bin\my.ini`,
			`C:\Program Files\MariaDB 11.0\bin\my.ini`,
			`C:\Program Files\MariaDB 12.3\bin\my.ini`,
		}},
		{"postgresql", []string{
			`C:\Program Files\PostgreSQL\16\data\postgresql.conf`,
			`C:\Program Files\PostgreSQL\15\data\postgresql.conf`,
			`C:\Program Files\PostgreSQL\14\data\postgresql.conf`,
			`/etc/postgresql/16/main/postgresql.conf`,
			`/var/lib/pgsql/data/postgresql.conf`,
		}},
		{"mongodb", []string{
			`C:\Program Files\MongoDB\Server\7.0\bin\mongod.cfg`,
			`C:\Program Files\MongoDB\Server\6.0\bin\mongod.cfg`,
			`C:\data\mongod.conf`,
			`/etc/mongod.conf`,
		}},
		{"mssql", []string{
			`C:\Program Files\Microsoft SQL Server\MSSQL16.MSSQLSERVER\MSSQL\Binn\mssql.conf`,
			`C:\Program Files\Microsoft SQL Server\MSSQL15.MSSQLSERVER\MSSQL\Binn\mssql.conf`,
		}},
	}

	for _, group := range knownPaths {
		for _, path := range group.paths {
			if _, err := os.Stat(path); err == nil {
				port := e.extractPortFromConfig(path, group.engine)
				if port == 0 {
					port = defaultPortForEngine(group.engine)
				}
				results = append(results, DBInstance{
					Engine:     group.engine,
					Host:       "127.0.0.1",
					Port:       port,
					ConfigFile: path,
					Status:     "detected",
					Source:     "config_file",
				})
			}
		}
	}

	return results
}

func (e *DBEngine) extractPortFromConfig(path, engine string) int {
	data, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	content := string(data)

	var portPatterns []*regexp.Regexp
	switch engine {
	case "mysql", "mariadb":
		portPatterns = []*regexp.Regexp{
			regexp.MustCompile(`(?i)port\s*=\s*(\d+)`),
			regexp.MustCompile(`(?i)port\s+(\d+)`),
		}
	case "postgresql":
		portPatterns = []*regexp.Regexp{
			regexp.MustCompile(`(?i)^port\s*=\s*(\d+)`),
		}
	case "mongodb":
		portPatterns = []*regexp.Regexp{
			regexp.MustCompile(`(?i)"port"\s*:\s*(\d+)`),
			regexp.MustCompile(`(?i)port\s*=\s*(\d+)`),
		}
	case "mssql":
		portPatterns = []*regexp.Regexp{
			regexp.MustCompile(`(?i)tcp\s*port\s*=\s*(\d+)`),
			regexp.MustCompile(`(?i)"port"\s*:\s*(\d+)`),
		}
	}

	for _, pat := range portPatterns {
		if matches := pat.FindStringSubmatch(content); len(matches) > 1 {
			p, err := strconv.Atoi(matches[1])
			if err == nil && p > 0 && p < 65536 {
				return p
			}
		}
	}
	return 0
}

func defaultPortForEngine(engine string) int {
	switch engine {
	case "mysql", "mariadb":
		return 3306
	case "postgresql":
		return 5432
	case "mongodb":
		return 27017
	case "mssql":
		return 1433
	case "redis":
		return 6379
	default:
		return 0
	}
}

func (e *DBEngine) discoverFromRegistry() []DBInstance {
	var results []DBInstance

	servicePatterns := []struct {
		engine  string
		pattern string
	}{
		{"mysql", "MySQL"},
		{"mariadb", "MariaDB"},
		{"postgresql", "postgresql"},
		{"mongodb", "MongoDB"},
		{"mssql", "MSSQLSERVER"},
		{"redis", "Redis"},
	}

	for _, sp := range servicePatterns {
		cmd := fmt.Sprintf(`Get-Service -Name "*%s*" -ErrorAction SilentlyContinue | Select-Object Name, Status, DisplayName | ConvertTo-Json`, sp.pattern)
		out, err := runPowerShell(cmd)
		if err != nil || strings.TrimSpace(out) == "" || strings.TrimSpace(out) == "null" {
			continue
		}

		status := "unknown"
		if strings.Contains(strings.ToLower(out), `"Status":"Running"`) {
			status = "running"
		} else if strings.Contains(strings.ToLower(out), `"Status":"Stopped"`) {
			status = "stopped"
		}

		results = append(results, DBInstance{
			Engine:  sp.engine,
			Host:    "127.0.0.1",
			Port:    defaultPortForEngine(sp.engine),
			Status:  status,
			Source:  "service_registry",
			Service: sp.pattern,
		})
	}

	return results
}

func (e *DBEngine) mergeInstances(instances []DBInstance) []DBInstance {
	type key struct {
		engine string
		port   int
	}
	seen := make(map[key]*DBInstance)

	for i := range instances {
		k := key{engine: instances[i].Engine, port: instances[i].Port}
		if existing, ok := seen[k]; ok {
			if existing.Status != "running" && instances[i].Status == "running" {
				existing.Status = "running"
			}
			if existing.ConfigFile == "" && instances[i].ConfigFile != "" {
				existing.ConfigFile = instances[i].ConfigFile
			}
			if existing.Service == "" && instances[i].Service != "" {
				existing.Service = instances[i].Service
			}
		} else {
			clone := instances[i]
			seen[k] = &clone
		}
	}

	var merged []DBInstance
	for _, v := range seen {
		merged = append(merged, *v)
	}
	return merged
}

func (e *DBEngine) ConnectToInstance(inst DBInstance, username, password string) error {
	e.mu.Lock()
	defer e.mu.Unlock()

	key := fmt.Sprintf("%s://%s:%d", inst.Engine, inst.Host, inst.Port)

	if existing, ok := e.connections[key]; ok && existing.Connected {
		return nil
	}

	var dsn string
	switch inst.Engine {
	case "mysql", "mariadb":
		dsn = fmt.Sprintf("%s:%s@tcp(%s:%d)/?timeout=5s&multiStatements=true", username, password, inst.Host, inst.Port)
	case "postgresql":
		dsn = fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=postgres sslmode=disable connect_timeout=5", inst.Host, inst.Port, username, password)
	case "mssql":
		dsn = fmt.Sprintf("sqlserver://%s:%s@%s:%d?database=master&connection+timeout=5", username, password, inst.Host, inst.Port)
	case "sqlite":
		dsn = inst.DataDir
	default:
		return fmt.Errorf("unsupported engine: %s", inst.Engine)
	}

	db, err := sql.Open(inst.Engine, dsn)
	if err != nil {
		return fmt.Errorf("open %s: %w", inst.Engine, err)
	}

	db.SetMaxOpenConns(2)
	db.SetMaxIdleConns(1)
	db.SetConnMaxLifetime(10 * time.Minute)

	if err := db.Ping(); err != nil {
		db.Close()
		return fmt.Errorf("ping %s: %w", inst.Engine, err)
	}

	e.connections[key] = &ManagedDB{
		Instance:  inst,
		DB:        db,
		Connected: true,
		LastPing:  time.Now(),
	}

	databases := e.enumerateDatabases(db, inst.Engine)
	inst.Databases = databases
	if len(databases) > 0 {
		e.connections[key].Instance.Databases = databases
	}

	return nil
}

func (e *DBEngine) ConnectXAMPPMySQL() error {
	inst := DBInstance{
		Engine: "mysql",
		Host:   "127.0.0.1",
		Port:   3306,
		Status: "running",
	}

	return e.ConnectToInstance(inst, "root", "")
}

func (e *DBEngine) AutoConnectAll() []string {
	instances := e.RunFullDiscovery()

	var connected []string
	connectedEngines := make(map[string]bool)

	for _, inst := range instances {
		if inst.Status != "running" {
			continue
		}
		if connectedEngines[inst.Engine] {
			continue
		}

		err := e.ConnectToInstance(inst, "root", "")
		if err != nil {
			err = e.ConnectToInstance(inst, "root", "")
		}
		if err != nil {
			err = e.ConnectToInstance(inst, "sa", "")
		}

		if err == nil {
			key := fmt.Sprintf("%s://%s:%d", inst.Engine, inst.Host, inst.Port)
			connected = append(connected, key)
			connectedEngines[inst.Engine] = true
		}
	}

	return connected
}

func (e *DBEngine) enumerateDatabases(db *sql.DB, engine string) []string {
	var databases []string

	switch engine {
	case "mysql", "mariadb":
		rows, err := db.Query("SHOW DATABASES")
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var name string
			if rows.Scan(&name) == nil {
				databases = append(databases, name)
			}
		}

	case "postgresql":
		rows, err := db.Query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname")
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var name string
			if rows.Scan(&name) == nil {
				databases = append(databases, name)
			}
		}

	case "mssql":
		rows, err := db.Query("SELECT name FROM sys.databases ORDER BY name")
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var name string
			if rows.Scan(&name) == nil {
				databases = append(databases, name)
			}
		}
	}

	return databases
}

func (e *DBEngine) GetTables(dbName string, engine string) []DBTableInfo {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Instance.Engine == engine || engine == "" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil || !mdb.Connected {
		return nil
	}

	var tables []DBTableInfo

	switch mdb.Instance.Engine {
	case "mysql", "mariadb":
		if dbName != "" {
			mdb.DB.Exec("USE " + dbName)
		}
		rows, err := mdb.DB.Query(`
			SELECT TABLE_NAME, TABLE_ROWS, ROUND(DATA_LENGTH/1024, 2), ENGINE, TABLE_COMMENT
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
			ORDER BY TABLE_NAME
		`)
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var t DBTableInfo
			if rows.Scan(&t.Name, &t.Rows, &t.SizeKB, &t.Engine, &t.Comment) == nil {
				tables = append(tables, t)
			}
		}

	case "postgresql":
		query := `
			SELECT schemaname || '.' || tablename,
				   COALESCE(n_live_tup, 0),
				   pg_size_total_relation_size(schemaname || '.' || tablename) / 1024.0,
				   '',
				   ''
			FROM pg_stat_user_tables
			ORDER BY schemaname, tablename
		`
		if dbName != "" {
			query = fmt.Sprintf(`
				SELECT tablename,
					   0,
					   pg_size_total_relation_size(tablename) / 1024.0,
					   '',
					   ''
				FROM pg_tables
				WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
				ORDER BY tablename
			`)
		}
		rows, err := mdb.DB.Query(query)
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var t DBTableInfo
			if rows.Scan(&t.Name, &t.Rows, &t.SizeKB, &t.Engine, &t.Comment) == nil {
				tables = append(tables, t)
			}
		}

	case "mssql":
		rows, err := mdb.DB.Query(`
			SELECT t.NAME AS TableName,
				   p.rows AS RowCounts,
				   SUM(a.total_pages) * 8 / 1024.0 AS TotalSpaceKB,
				   '',
				   ''
			FROM sys.tables t
			INNER JOIN sys.indexes i ON t.OBJECT_ID = i.object_id
			INNER JOIN sys.partitions p ON i.object_id = p.OBJECT_ID AND i.index_id = p.index_id
			INNER JOIN sys.allocation_units a ON p.partition_id = a.container_id
			WHERE t.is_ms_shipped = 0 AND i.OBJECT_ID > 255
			GROUP BY t.Name, p.Rows
			ORDER BY t.Name
		`)
		if err != nil {
			return nil
		}
		defer rows.Close()
		for rows.Next() {
			var t DBTableInfo
			if rows.Scan(&t.Name, &t.Rows, &t.SizeKB, &t.Engine, &t.Comment) == nil {
				tables = append(tables, t)
			}
		}
	}

	return tables
}

func (e *DBEngine) GetAllConnections() map[string]*ManagedDB {
	e.mu.RLock()
	defer e.mu.RUnlock()

	result := make(map[string]*ManagedDB, len(e.connections))
	for k, v := range e.connections {
		result[k] = v
	}
	return result
}

func (e *DBEngine) GetDiscovered() []DBInstance {
	e.mu.RLock()
	defer e.mu.RUnlock()

	result := make([]DBInstance, len(e.discovered))
	copy(result, e.discovered)
	return result
}

func (e *DBEngine) RunHealthChecks() map[string]bool {
	e.mu.Lock()
	defer e.mu.Unlock()

	status := make(map[string]bool)
	for key, mdb := range e.connections {
		if err := mdb.DB.Ping(); err != nil {
			mdb.Connected = false
			mdb.ErrorCount++
			status[key] = false
		} else {
			mdb.Connected = true
			mdb.LastPing = time.Now()
			status[key] = true
		}
	}
	return status
}

func (e *DBEngine) FullScanAllDatabases() []DBScanResult {
	instances := e.RunFullDiscovery()

	var results []DBScanResult
	for _, inst := range instances {
		if inst.Status != "running" {
			continue
		}

		key := fmt.Sprintf("%s://%s:%d", inst.Engine, inst.Host, inst.Port)
		e.mu.RLock()
		mdb, ok := e.connections[key]
		e.mu.RUnlock()

		if !ok || !mdb.Connected {
			continue
		}

		result := DBScanResult{
			Instance:  inst,
			Tables:    make(map[string][]DBTableInfo),
			ScannedAt: time.Now(),
		}

		for _, dbName := range inst.Databases {
			if dbName == "information_schema" || dbName == "mysql" || dbName == "performance_schema" || dbName == "sys" {
				continue
			}
			if inst.Engine == "postgresql" && (dbName == "pg_catalog" || dbName == "information_schema") {
				continue
			}

			tables := e.GetTables(dbName, inst.Engine)
			result.Tables[dbName] = tables
			result.TotalTables += len(tables)
			for _, t := range tables {
				result.TotalRows += t.Rows
			}
		}

		result.Databases = inst.Databases
		results = append(results, result)
	}

	return results
}

func (e *DBEngine) ExecQuery(engine, dbName, query string) (*QueryResult, error) {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == engine || engine == "") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil, fmt.Errorf("no connection found for engine %s", engine)
	}

	start := time.Now()
	rows, err := mdb.DB.Query(query)
	elapsed := time.Since(start)

	if err != nil {
		return &QueryResult{
			Error: err.Error(),
			TookMs:  elapsed.Milliseconds(),
		}, nil
	}
	defer rows.Close()

	columns, err := rows.Columns()
	if err != nil {
		return &QueryResult{
			Error: err.Error(),
			TookMs:  elapsed.Milliseconds(),
		}, nil
	}

	var rowsData []map[string]interface{}
	count := 0
	const maxRows = 1000

	for rows.Next() && count < maxRows {
		values := make([]interface{}, len(columns))
		valuePtrs := make([]interface{}, len(columns))
		for i := range values {
			valuePtrs[i] = &values[i]
		}
		if err := rows.Scan(valuePtrs...); err != nil {
			continue
		}

		row := make(map[string]interface{}, len(columns))
		for i, col := range columns {
			val := values[i]
			switch v := val.(type) {
			case []byte:
				row[col] = string(v)
			case time.Time:
				row[col] = v.Format(time.RFC3339)
			default:
				row[col] = v
			}
		}
		rowsData = append(rowsData, row)
		count++
	}

	return &QueryResult{
		Columns: columns,
		Rows:    rowsData,
		TookMs:    elapsed.Milliseconds(),
	}, nil
}

func (e *DBEngine) GetMySQLGeneralLogEntries(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT event_time, user_host, thread_id, command_type, argument
		FROM mysql.general_log
		WHERE command_type = 'Query'
		  AND argument IS NOT NULL
		  AND argument != ''
		ORDER BY event_time DESC
		LIMIT %d
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var eventTimeRaw []byte
		var userHost, cmdType, argument string
		var threadID int64

		if err := rows.Scan(&eventTimeRaw, &userHost, &threadID, &cmdType, &argument); err != nil {
			continue
		}
		eventTime, err := time.ParseInLocation("2006-01-02 15:04:05.000000", string(eventTimeRaw), time.Local)
		if err != nil {
			eventTime, err = time.ParseInLocation("2006-01-02 15:04:05", string(eventTimeRaw), time.Local)
			if err != nil {
				eventTime = time.Now()
			}
		}

		parts := strings.SplitN(userHost, "@", 2)
		user := strings.Trim(parts[0], " []")
		host := ""
		if len(parts) > 1 {
			host = strings.Trim(parts[1], " []")
		}

		entries = append(entries, map[string]interface{}{
			"event_time":   eventTime,
			"user":         user,
			"host":         host,
			"thread_id":    threadID,
			"command_type": cmdType,
			"argument":     argument,
		})
	}

	return entries
}

func (e *DBEngine) GetMySQLSlowQueryLogEntries(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT start_time, query_time, lock_time, rows_sent, rows_examined, sql_text
		FROM mysql.slow_log
		ORDER BY start_time DESC
		LIMIT %d
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var startTime, queryTime, lockTime, sqlText string
		var rowsSent, rowsExamined int64

		if err := rows.Scan(&startTime, &queryTime, &lockTime, &rowsSent, &rowsExamined, &sqlText); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"start_time":    startTime,
			"query_time":    queryTime,
			"lock_time":     lockTime,
			"rows_sent":     rowsSent,
			"rows_examined": rowsExamined,
			"sql_text":      sqlText,
		})
	}

	return entries
}

func (e *DBEngine) GetPerformanceSchemaQueries(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT DIGEST_TEXT, COUNT_STAR, AVG_TIMER_WAIT/1000000000 as avg_time_ms,
			   SUM_ROWS_EXAMINED, SUM_ROWS_SENT, FIRST_SEEN, LAST_SEEN
		FROM performance_schema.events_statements_summary_by_digest
		WHERE DIGEST_TEXT IS NOT NULL
		ORDER BY COUNT_STAR DESC
		LIMIT %d
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var digestText, firstSeen, lastSeen string
		var countStar int64
		var avgTimeMs, sumRowsExamined, sumRowsSent float64

		if err := rows.Scan(&digestText, &countStar, &avgTimeMs, &sumRowsExamined, &sumRowsSent, &firstSeen, &lastSeen); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"digest_text":       digestText,
			"count":             countStar,
			"avg_time_ms":       avgTimeMs,
			"sum_rows_examined": sumRowsExamined,
			"sum_rows_sent":     sumRowsSent,
			"first_seen":        firstSeen,
			"last_seen":         lastSeen,
		})
	}

	return entries
}

func (e *DBEngine) GetMySQLProcessList() []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query("SHOW FULL PROCESSLIST")
	if err != nil {
		return nil
	}
	defer rows.Close()

	var processes []map[string]interface{}
	for rows.Next() {
		var id int64
		var user, host, db, command, time_val, state sql.NullString
		var info sql.NullString

		if err := rows.Scan(&id, &user, &host, &db, &command, &time_val, &state, &info); err != nil {
			continue
		}

		processes = append(processes, map[string]interface{}{
			"id":      id,
			"user":    user.String,
			"host":    host.String,
			"db":      db.String,
			"command": command.String,
			"time":    time_val.String,
			"state":   state.String,
			"info":    info.String,
		})
	}

	return processes
}

func (e *DBEngine) GetMySQLUsers() []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query(`
		SELECT User, Host, plugin, Select_priv, Insert_priv, Update_priv, Delete_priv,
			   Create_priv, Drop_priv, Alter_priv, Grant_priv, Super_priv
		FROM mysql.user
		ORDER BY User
	`)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var users []map[string]interface{}
	for rows.Next() {
		var u, h, plugin string
		var sp, ip, up, dp, cp, dp2, ap, gp, sup string

		if err := rows.Scan(&u, &h, &plugin, &sp, &ip, &up, &dp, &cp, &dp2, &ap, &gp, &sup); err != nil {
			continue
		}

		users = append(users, map[string]interface{}{
			"user":      u,
			"host":      h,
			"plugin":    plugin,
			"select":    sp,
			"insert":    ip,
			"update":    up,
			"delete":    dp,
			"create":    cp,
			"drop":      dp2,
			"alter":     ap,
			"grant":     gp,
			"super":     sup,
		})
	}

	return users
}

func (e *DBEngine) GetMySQLVariables() map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query("SHOW GLOBAL VARIABLES")
	if err != nil {
		return nil
	}
	defer rows.Close()

	vars := make(map[string]interface{})
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err != nil {
			continue
		}
		vars[name] = value
	}

	return vars
}

func (e *DBEngine) GetMySQLStatus() map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && (v.Instance.Engine == "mysql" || v.Instance.Engine == "mariadb") {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query("SHOW GLOBAL STATUS")
	if err != nil {
		return nil
	}
	defer rows.Close()

	status := make(map[string]interface{})
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err != nil {
			continue
		}
		status[name] = value
	}

	return status
}

func runPowerShell(cmd string) (string, error) {
	proc, err := os.StartProcess("powershell.exe", []string{"-NoProfile", "-NonInteractive", "-Command", cmd}, &os.ProcAttr{
		Files: []*os.File{nil, nil, nil},
	})
	if err != nil {
		return "", err
	}
	done := make(chan error, 1)
	go func() {
		_, err := proc.Wait()
		done <- err
	}()

	select {
	case <-done:
	case <-time.After(10 * time.Second):
		proc.Kill()
		return "", fmt.Errorf("timeout")
	}

	return "", nil
}

func getLocalIPs() []string {
	var ips []string
	ifaces, err := net.Interfaces()
	if err != nil {
		return []string{"127.0.0.1"}
	}
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			if ipnet, ok := addr.(*net.IPNet); ok && !ipnet.IP.IsLoopback() {
				if ipnet.IP.To4() != nil {
					ips = append(ips, ipnet.IP.String())
				}
			}
		}
	}
	if len(ips) == 0 {
		ips = append(ips, "127.0.0.1")
	}
	return ips
}

func (e *DBEngine) DiscoverSQLiteFiles() []string {
	var files []string

	searchDirs := []string{
		os.ExpandEnv("%USERPROFILE%"),
		`C:\`,
	}

	seen := make(map[string]bool)
	for _, dir := range searchDirs {
		filepath.Walk(dir, func(path string, info os.FileInfo, err error) error {
			if err != nil {
				return nil
			}
			if info.IsDir() {
				name := strings.ToLower(info.Name())
				if name == "windows" || name == "program files" || name == "program files (x86)" || name == "$recycle.bin" || name == "system volume information" {
					return filepath.SkipDir
				}
				return nil
			}
			ext := strings.ToLower(filepath.Ext(path))
			if ext == ".db" || ext == ".sqlite" || ext == ".sqlite3" || ext == ".s3db" {
				if !seen[path] && info.Size() > 1024 {
					seen[path] = true
					files = append(files, path)
				}
			}
			return nil
		})
	}

	return files
}

func (e *DBEngine) ProbeSQLiteFile(path string) bool {
	db, err := sql.Open("sqlite", path+"?mode=ro")
	if err != nil {
		return false
	}
	defer db.Close()
	return db.Ping() == nil
}

func (e *DBEngine) GetSQLiteTables(path string) []DBTableInfo {
	db, err := sql.Open("sqlite", path+"?mode=ro")
	if err != nil {
		return nil
	}
	defer db.Close()

	rows, err := db.Query(`
		SELECT name, 0, 0, '', ''
		FROM sqlite_master
		WHERE type='table' AND name NOT LIKE 'sqlite_%'
		ORDER BY name
	`)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var tables []DBTableInfo
	for rows.Next() {
		var t DBTableInfo
		if rows.Scan(&t.Name, &t.Rows, &t.SizeKB, &t.Engine, &t.Comment) == nil {
			countQuery := fmt.Sprintf("SELECT COUNT(*) FROM [%s]", t.Name)
			db.QueryRow(countQuery).Scan(&t.Rows)
			tables = append(tables, t)
		}
	}
	return tables
}

var reservedDBSchemas = map[string]bool{
	"information_schema": true,
	"mysql":              true,
	"performance_schema": true,
	"sys":                true,
	"pg_catalog":         true,
	"pg_toast":           true,
}

func isReservedSchema(name string) bool {
	return reservedDBSchemas[strings.ToLower(name)]
}

func (e *DBEngine) GetPostgreSQLActivityLog(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "postgresql" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT pid, usename, COALESCE(host(client_addr)::text, 'local') as client_host,
			   state, query, query_start, wait_event, pg_blocking_pids(pid) as blockers
		FROM pg_stat_activity
		WHERE state != 'idle' AND pid != pg_backend_pid()
		ORDER BY query_start DESC NULLS LAST
		LIMIT %d
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var pid int64
		var usename, clientHost, state, query, waitEvent string
		var queryStart time.Time
		var blockers string

		if err := rows.Scan(&pid, &usename, &clientHost, &state, &query, &queryStart, &waitEvent, &blockers); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"event_time":   queryStart,
			"user":         usename,
			"host":         clientHost,
			"argument":     query,
			"command_type": "Query",
			"database":     "",
			"thread_id":    pid,
			"engine":       "postgresql",
			"duration":     time.Since(queryStart),
		})
	}
	return entries
}

func (e *DBEngine) GetPGSlowQueries(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "postgresql" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT query, calls, total_exec_time, mean_exec_time, rows, userid, dbid
		FROM pg_stat_statements
		ORDER BY total_exec_time DESC
		LIMIT %d
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var q string
		var calls int64
		var totalTime, meanTime float64
		var rowCount int64
		var userID, dbID int64

		if err := rows.Scan(&q, &calls, &totalTime, &meanTime, &rowCount, &userID, &dbID); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"argument":    q,
			"duration_ms": meanTime,
			"user":        fmt.Sprintf("uid:%d", userID),
			"engine":      "postgresql",
			"count":       calls,
			"rows_sent":   rowCount,
			"total_ms":    totalTime,
		})
	}
	return entries
}

func (e *DBEngine) GetPGActiveQueries() []map[string]interface{} {
	return e.GetPostgreSQLActivityLog(100)
}

func (e *DBEngine) GetMSSQLQueryStats(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "mssql" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	query := fmt.Sprintf(`
		SELECT TOP %d
			qs.execution_count,
			qs.total_worker_time/qs.execution_count AS avg_cpu_time,
			qs.total_elapsed_time/qs.execution_count AS avg_elapsed_time,
			qs.total_logical_reads/qs.execution_count AS avg_logical_reads,
			qs.last_execution_time,
			SUBSTRING(st.text, (qs.statement_start_offset/2)+1,
				((CASE qs.statement_end_offset
					WHEN -1 THEN DATALENGTH(st.text)
					ELSE qs.statement_end_offset
				END - qs.statement_start_offset)/2)+1) AS query_text,
			DB_NAME(st.dbid) AS database_name,
			COALESCE(s.login_name, '') AS login_name,
			COALESCE(s.host_name, '') AS host_name
		FROM sys.dm_exec_query_stats qs
		CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
		LEFT JOIN sys.dm_exec_sessions s ON qs.plan_handle IN (
			SELECT plan_handle FROM sys.dm_exec_cached_plans
		)
		ORDER BY qs.total_elapsed_time DESC
	`, limit)

	rows, err := mdb.DB.Query(query)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var execCount int64
		var avgCpu, avgElapsed, avgReads float64
		var lastExec time.Time
		var queryText, dbName, loginName, hostName string

		if err := rows.Scan(&execCount, &avgCpu, &avgElapsed, &avgReads, &lastExec, &queryText, &dbName, &loginName, &hostName); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"event_time":   lastExec,
			"user":         loginName,
			"host":         hostName,
			"argument":     queryText,
			"command_type": "Query",
			"database":     dbName,
			"duration_ms":  avgElapsed / 1000.0,
			"engine":       "mssql",
			"count":        execCount,
		})
	}
	return entries
}

func (e *DBEngine) GetMSSQLActiveSessions() []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "mssql" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query(`
		SELECT r.session_id, s.login_name, ISNULL(s.host_name, '') as host_name,
			   ISNULL(r.command, '') as command, r.status,
			   ISNULL(DB_NAME(r.database_id), '') as database_name,
			   ISNULL(SUBSTRING(st.text,
					(r.statement_start_offset/2)+1,
					((CASE WHEN r.statement_end_offset = -1
						THEN DATALENGTH(st.text)
						ELSE r.statement_end_offset
					END - r.statement_start_offset)/2
				), '') as query_text,
			   r.start_time
		FROM sys.dm_exec_requests r
		JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
		OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) st
		WHERE s.is_user_process = 1
		ORDER BY r.start_time DESC
	`)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var sessionID int64
		var loginName, hostName, command, status, dbName, queryText string
		var startTime time.Time

		if err := rows.Scan(&sessionID, &loginName, &hostName, &command, &status, &dbName, &queryText, &startTime); err != nil {
			continue
		}

		entries = append(entries, map[string]interface{}{
			"event_time":   startTime,
			"user":         loginName,
			"host":         hostName,
			"argument":     queryText,
			"command_type": command,
			"database":     dbName,
			"thread_id":    sessionID,
			"engine":       "mssql",
			"duration":     time.Since(startTime),
		})
	}
	return entries
}

func (e *DBEngine) GetMongoDBRecentOps(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "mongodb" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query("SELECT * FROM currentOp() LIMIT ?", limit)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var raw string
		if err := rows.Scan(&raw); err != nil {
			continue
		}
		entries = append(entries, map[string]interface{}{
			"argument":    raw,
			"command_type": "command",
			"engine":      "mongodb",
		})
	}
	return entries
}

func (e *DBEngine) GetRedisSlowLogEntries(limit int) []map[string]interface{} {
	e.mu.RLock()
	var mdb *ManagedDB
	for _, v := range e.connections {
		if v.Connected && v.Instance.Engine == "redis" {
			mdb = v
			break
		}
	}
	e.mu.RUnlock()

	if mdb == nil {
		return nil
	}

	rows, err := mdb.DB.Query("SLOWLOG GET ?", limit)
	if err != nil {
		return nil
	}
	defer rows.Close()

	var entries []map[string]interface{}
	for rows.Next() {
		var id, durationUs, timestamp int64
		var command string
		var args []string

		if err := rows.Scan(&id, &durationUs, &timestamp, &command, &args); err != nil {
			continue
		}

		fullCmd := command
		for _, a := range args {
			fullCmd += " " + a
		}

		ts := time.Unix(timestamp, 0)
		entries = append(entries, map[string]interface{}{
			"event_time":   ts,
			"user":         "redis",
			"argument":     fullCmd,
			"command_type": "slow_query",
			"duration_ms":  float64(durationUs) / 1000.0,
			"engine":       "redis",
		})
	}
	return entries
}
