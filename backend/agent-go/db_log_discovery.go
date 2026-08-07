package main

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type DBLogFile struct {
	Path         string `json:"path"`
	SizeBytes    int64  `json:"sizeBytes"`
	LastModified string `json:"lastModified"`
	Engine       string `json:"engine"`
	LogType      string `json:"logType"` // error_log, slow_query, general_log, binary_log, transaction_log, install_log
	Source       string `json:"source"`  // xampp, wamp, mariadb, mysql, postgres, mongodb, mssql, custom
}

type DBInstallation struct {
	Engine     string `json:"engine"`
	Version    string `json:"version"`
	InstallDir string `json:"installDir"`
	DataDir    string `json:"dataDir"`
	Port       int    `json:"port"`
	ServiceName string `json:"serviceName"`
	ConfigPath  string `json:"configPath"`
}

var dbLogExtensions = []string{".log", ".err", ".txt", ".out"}

var dbLogPatterns = []string{
	"*.err", "*.log", "error.log", "slow.log", "slow_query.log",
	"general.log", "general_query.log", "mysql.log", "mysqld.log",
	"postgresql-*.log", "postgresql.log", "pg.log", "pg_log",
	"mongod.log", "mongo.log", "mongodb.log",
	"sqlserver.log", "errorlog", "ERRORLOG",
	"ib_logfile*", "binlog.*", "relay-log.*",
}

func discoverDBInstallations() []DBInstallation {
	var found []DBInstallation

	if runtime.GOOS != "windows" {
		return found
	}

	// Common install locations to scan
	type searchPath struct {
		engine string
		base   string
		versionGlob string
	}

	searches := []searchPath{
		// XAMPP
		{"mariadb", `C:\xampp\mysql`, ""},
		{"mariadb", `C:\xampp\mariadb`, ""},
		{"mariadb", `D:\xampp\mysql`, ""},
		{"mariadb", `E:\xampp\mysql`, ""},

		// WAMP
		{"mariadb", `C:\wamp64\bin\mysql`, "mysql*"},
		{"mariadb", `C:\wamp\bin\mysql`, "mysql*"},
		{"mariadb", `C:\wamp64\bin\mariadb`, "mariadb*"},
		{"mariadb", `C:\wamp\bin\mariadb`, "mariadb*"},

		// Uniform Server / Portable
		{"mariadb", `C:\uniformserver\db`, ""},
		{"mariadb", `C:\uniserver\core\mysql`, ""},
		{"mariadb", `C:\usr\local\mysql`, ""},

		// MariaDB official installs
		{"mariadb", `C:\Program Files\MariaDB`, "MariaDB *"},
		{"mariadb", `C:\Program Files\MariaDB`, "mariadb-*"},
		{"mariadb", `C:\Program Files (x86)\MariaDB`, "MariaDB *"},
		{"mariadb", `C:\Program Files (x86)\MariaDB`, "mariadb-*"},
		{"mariadb", `C:\ProgramData\MariaDB`, ""},
		{"mariadb", `C:\ProgramData\MariaDB`, "MariaDB *"},

		// MySQL official installs
		{"mysql", `C:\Program Files\MySQL`, "MySQL Server *"},
		{"mysql", `C:\Program Files (x86)\MySQL`, "MySQL Server *"},
		{"mysql", `C:\ProgramData\MySQL`, "MySQL Server *"},
		{"mysql", `C:\ProgramData\MySQL`, ""},
		{"mysql", `C:\Program Files\MySQL\MySQL Server *\data`, ""},
		{"mysql", `C:\ProgramData\MySQL\MySQL Server *\data`, ""},

		// PostgreSQL
		{"postgresql", `C:\Program Files\PostgreSQL`, "*"},
		{"postgresql", `C:\Program Files (x86)\PostgreSQL`, "*"},
		{"postgresql", `C:\ProgramData\PostgreSQL`, ""},
		{"postgresql", `C:\ProgramFiles\PostgreSQL`, "*"},

		// MongoDB
		{"mongodb", `C:\Program Files\MongoDB`, "Server/*"},
		{"mongodb", `C:\Program Files (x86)\MongoDB`, "Server/*"},
		{"mongodb", `C:\ProgramData\MongoDB`, ""},
		{"mongodb", `C:\data\db`, ""},
		{"mongodb", `C:\data\log`, ""},

		// SQL Server
		{"mssql", `C:\Program Files\Microsoft SQL Server`, "MSSQL*"},
		{"mssql", `C:\Program Files (x86)\Microsoft SQL Server`, "MSSQL*"},
		{"mssql", `C:\ProgramData\Microsoft\SQL Server`, ""},
		{"mssql", `C:\ProgramData\Microsoft SQL Server`, ""},

		// SQLite (anywhere .db/.sqlite files)
		{"sqlite", `C:\ProgramData`, ""},
		{"sqlite", `C:\Users`, ""},

		// Firebase Emulator
		{"firebase", `C:\Users\*\AppData\Local\firebase`, ""},

		// Redis
		{"redis", `C:\Program Files\Redis`, ""},
		{"redis", `C:\Program Files (x86)\Redis`, ""},

		// Apache friends / Bitnami
		{"mariadb", `C:\Bitnami\*\mariadb`, ""},
		{"mariadb", `C:\Bitnami\*\mysql`, ""},
		{"postgresql", `C:\Bitnami\*\postgresql`, ""},

		// Laragon
		{"mariadb", `C:\laragon\bin\mysql`, ""},
		{"mariadb", `C:\laragon\bin\mariadb`, ""},
		{"postgresql", `C:\laragon\bin\postgresql`, ""},

		// EasyPHP / AMPPS
		{"mariadb", `C:\Program Files (x86)\EasyPHP-*\binaries\mysql`, ""},
		{"mariadb", `C:\AMPPS\mysql`, ""},

		// USBWebserver / Portable
		{"mariadb", `C:\USBWebserver\mysql`, ""},
		{"mariadb", `C:\Server\mysql`, ""},
		{"mariadb", `C:\home\mysql`, ""},

		// Docker volumes (common bind mounts)
		{"mysql", `C:\docker\mysql`, ""},
		{"mariadb", `C:\docker\mariadb`, ""},
		{"postgresql", `C:\docker\postgres`, ""},
		{"mongodb", `C:\docker\mongo`, ""},

		// Windows subsystem data
		{"mysql", `C:\Users\*\AppData\Local\MySQL`, ""},
		{"mariadb", `C:\Users\*\AppData\Local\MariaDB`, ""},
		{"postgresql", `C:\Users\*\AppData\Local\PostgreSQL`, ""},
		{"mongodb", `C:\Users\*\AppData\Local\MongoDB`, ""},
		{"mysql", `C:\Users\*\AppData\Roaming\MySQL`, ""},
		{"mariadb", `C:\Users\*\AppData\Roaming\MariaDB`, ""},
	}

	checked := make(map[string]bool)

	for _, s := range searches {
		// Expand wildcards in path
		matches, err := filepath.Glob(s.base)
		if err != nil {
			continue
		}
		for _, base := range matches {
			if checked[base] {
				continue
			}
			checked[base] = true

			if s.versionGlob != "" {
				subs, err := filepath.Glob(filepath.Join(base, s.versionGlob))
				if err != nil {
					continue
				}
				for _, sub := range subs {
					inst := inspectDBDir(s.engine, sub)
					if inst != nil {
						found = append(found, *inst)
					}
				}
			} else {
				inst := inspectDBDir(s.engine, base)
				if inst != nil {
					found = append(found, *inst)
				}
			}
		}
	}

	// Also check for running services to find installs
	serviceInstalls := findServiceInstalls()
	found = append(found, serviceInstalls...)

	return found
}

func inspectDBDir(engine, dir string) *DBInstallation {
	info, err := os.Stat(dir)
	if err != nil || !info.IsDir() {
		return nil
	}

	inst := &DBInstallation{
		Engine:     engine,
		InstallDir: dir,
	}

	// Try to find version
	version, _ := findVersionFile(dir)
	inst.Version = version

	// Find data directory
	dataDir := findDataDir(engine, dir)
	inst.DataDir = dataDir

	// Find config
	configPath := findConfigFile(engine, dir)
	inst.ConfigPath = configPath

	// Try to determine port
	inst.Port = detectPort(configPath, engine)

	// Try to find service name
	inst.ServiceName = findServiceName(engine, dir)

	// Only return if it looks like a real DB install
	if version != "" || dataDir != "" || configPath != "" {
		return inst
	}
	return nil
}

func findVersionFile(dir string) (string, error) {
	// Check various version indicator files
	candidates := []string{"version", "VERSION", "version.txt", "VERSION.txt", "release"}
	for _, c := range candidates {
		p := filepath.Join(dir, c)
		if data, err := os.ReadFile(p); err == nil {
			return strings.TrimSpace(string(data)), nil
		}
	}
	// Check subdirectory names for version patterns
	entries, err := os.ReadDir(dir)
	if err != nil {
		return "", err
	}
	for _, e := range entries {
		if e.IsDir() {
			if strings.Contains(e.Name(), "mysql") || strings.Contains(e.Name(), "mariadb") ||
				strings.Contains(e.Name(), "postgres") || strings.Contains(e.Name(), "mongo") {
				ver := extractVersion(e.Name())
				if ver != "" {
					return ver, nil
				}
			}
		}
	}
	// Check parent dir name
	parent := filepath.Base(dir)
	ver := extractVersion(parent)
	if ver != "" {
		return ver, nil
	}
	return "", nil
}

func extractVersion(s string) string {
	parts := strings.Fields(s)
	for _, p := range parts {
		p = strings.TrimPrefix(p, "v")
		if len(p) > 0 && (p[0] >= '0' && p[0] <= '9') {
			// Check if it looks like a version number
			dotCount := strings.Count(p, ".")
			if dotCount >= 1 && dotCount <= 3 {
				return p
			}
		}
	}
	return ""
}

func findDataDir(engine, installDir string) string {
	// Common data directory names per engine
	dataDirs := map[string][]string{
		"mariadb":    {"data", "var", "database", "db", "datadir"},
		"mysql":      {"data", "var", "database", "db", "datadir"},
		"postgresql": {"data", "pgdata", "db", "database", "pg_log"},
		"mongodb":    {"data", "db", "data\\db"},
		"mssql":      {"data", "DATA", "MSSQL\\DATA"},
	}

	for _, name := range dataDirs[engine] {
		p := filepath.Join(installDir, name)
		if info, err := os.Stat(p); err == nil && info.IsDir() {
			return p
		}
	}

	return ""
}

func findConfigFile(engine, dir string) string {
	configNames := map[string][]string{
		"mariadb":    {"my.ini", "my.cnf", "mariadb.ini", "mariadb.cnf"},
		"mysql":      {"my.ini", "my.cnf", "mysql.ini", "mysql.cnf"},
		"postgresql": {"postgresql.conf", "pg_hba.conf", "pg_ident.conf"},
		"mongodb":    {"mongod.cfg", "mongod.conf", "mongo.cfg"},
		"mssql":      {"sqlserver.conf"},
	}

	for _, name := range configNames[engine] {
		p := filepath.Join(dir, name)
		if _, err := os.Stat(p); err == nil {
			return p
		}
		// Also check in etc/ subdirectory
		p2 := filepath.Join(dir, "etc", name)
		if _, err := os.Stat(p2); err == nil {
			return p2
		}
	}

	return ""
}

func detectPort(configPath, engine string) int {
	defaultPorts := map[string]int{
		"mariadb":    3306,
		"mysql":      3306,
		"postgresql": 5432,
		"mongodb":    27017,
		"mssql":      1433,
		"redis":      6379,
		"firebase":   9000,
	}
	return defaultPorts[engine]
}

func findServiceName(engine, dir string) string {
	lower := strings.ToLower(dir)
	if strings.Contains(lower, "xampp") {
		return "xampp"
	}
	if strings.Contains(lower, "wamp") {
		return "wamp"
	}
	if strings.Contains(lower, "laragon") {
		return "laragon"
	}
	// Default service names
	serviceNames := map[string]string{
		"mariadb":    "MariaDB",
		"mysql":      "MySQL",
		"postgresql": "postgresql",
		"mongodb":    "MongoDB",
		"mssql":      "MSSQLSERVER",
	}
	return serviceNames[engine]
}

func findServiceInstalls() []DBInstallation {
	var found []DBInstallation

	// Check Windows registry for installed services
	regPaths := []string{
		`HKLM\SYSTEM\CurrentControlSet\Services\MariaDB`,
		`HKLM\SYSTEM\CurrentControlSet\Services\MySQL`,
		`HKLM\SYSTEM\CurrentControlSet\Services\postgresql`,
		`HKLM\SYSTEM\CurrentControlSet\Services\MongoDB`,
		`HKLM\SYSTEM\CurrentControlSet\Services\MSSQLSERVER`,
		`HKLM\SYSTEM\CurrentControlSet\Services\Redis`,
	}

	for _, regPath := range regPaths {
		cmd := execCommand("reg", "query", regPath, "/v", "ImagePath")
		if cmd == "" {
			continue
		}
		// Parse out the binary path
		parts := strings.Fields(cmd)
		for _, p := range parts {
			if strings.Contains(p, ".exe") && (strings.Contains(p, "mysql") || strings.Contains(p, "mariadb") ||
				strings.Contains(p, "postgres") || strings.Contains(p, "mongod") ||
				strings.Contains(p, "sqlservr") || strings.Contains(p, "redis")) {
				dir := filepath.Dir(p)
				var engine string
				switch {
				case strings.Contains(p, "mariadbd"):
					engine = "mariadb"
				case strings.Contains(p, "mysqld"):
					engine = "mysql"
				case strings.Contains(p, "postgres"):
					engine = "postgresql"
				case strings.Contains(p, "mongod"):
					engine = "mongodb"
				case strings.Contains(p, "sqlservr"):
					engine = "mssql"
				case strings.Contains(p, "redis"):
					engine = "redis"
				}
				if engine != "" {
					inst := inspectDBDir(engine, dir)
					if inst != nil {
						found = append(found, *inst)
					}
				}
				break
			}
		}
	}
	return found
}

func discoverDBLogs(installations []DBInstallation) []DBLogFile {
	var logs []DBLogFile
	checked := make(map[string]bool)

	// Directories to scan for log files
	logDirs := []string{}

	for _, inst := range installations {
		logDirs = append(logDirs, inst.InstallDir)
		if inst.DataDir != "" {
			logDirs = append(logDirs, inst.DataDir)
		}
		// Common log subdirectories
		logDirs = append(logDirs, filepath.Join(inst.InstallDir, "log"))
		logDirs = append(logDirs, filepath.Join(inst.InstallDir, "logs"))
		logDirs = append(logDirs, filepath.Join(inst.InstallDir, "data"))
		if inst.DataDir != "" {
			logDirs = append(logDirs, filepath.Join(inst.DataDir, "log"))
			logDirs = append(logDirs, filepath.Join(inst.DataDir, "logs"))
		}
	}

	// Additional common log locations
	extraDirs := []string{
		`C:\xampp\mysql\data`,
		`C:\xampp\mysql\logs`,
		`C:\xampp\mariadb\data`,
		`C:\xampp\mariadb\logs`,
		`C:\wamp64\logs`,
		`C:\wamp\logs`,
		`C:\ProgramData\MySQL\MySQL Server 5.7\Data`,
		`C:\ProgramData\MySQL\MySQL Server 8.0\Data`,
		`C:\ProgramData\MySQL\MySQL Server 8.4\Data`,
		`C:\Program Files\MySQL\MySQL Server 5.7\data`,
		`C:\Program Files\MySQL\MySQL Server 8.0\data`,
		`C:\Program Files\MySQL\MySQL Server 8.4\data`,
		`C:\Program Files\MariaDB 10.11\data`,
		`C:\Program Files\MariaDB 11.0\data`,
		`C:\Program Files\MariaDB 11.1\data`,
		`C:\Program Files\MariaDB 11.2\data`,
		`C:\Program Files\MariaDB 11.3\data`,
		`C:\Program Files\MariaDB 11.4\data`,
		`C:\Program Files\MariaDB 11.5\data`,
		`C:\Program Files\MariaDB 11.6\data`,
		`C:\Program Files\MariaDB 11.7\data`,
		`C:\Program Files\MariaDB 12.0\data`,
		`C:\Program Files\MariaDB 12.1\data`,
		`C:\Program Files\MariaDB 12.2\data`,
		`C:\Program Files\MariaDB 12.3\data`,
		`C:\Program Files\MariaDB 12.4\data`,
		`C:\Program Files\MariaDB 12.5\data`,
		`C:\Program Files\PostgreSQL\16\data`,
		`C:\Program Files\PostgreSQL\15\data`,
		`C:\Program Files\PostgreSQL\14\data`,
		`C:\Program Files\PostgreSQL\13\data`,
		`C:\Program Files\PostgreSQL\12\data`,
		`C:\Program Files\PostgreSQL\17\data`,
		`C:\Program Files\PostgreSQL\18\data`,
		`C:\Program Files\PostgreSQL\19\data`,
		`C:\Program Files\MongoDB\Server\7.0\log`,
		`C:\Program Files\MongoDB\Server\8.0\log`,
		`C:\Program Files\MongoDB\Server\6.0\log`,
		`C:\Program Files\MongoDB\Server\5.0\log`,
		`C:\ProgramFiles\MongoDB\Server\7.0\log`,
		`C:\ProgramData\MongoDB\Server\7.0\log`,
		`C:\ProgramData\MongoDB\Server\8.0\log`,
		`C:\ProgramData\MongoDB\Server\6.0\log`,
		`C:\ProgramData\MongoDB\Server\5.0\log`,
		`C:\Users\All Users\MySQL\MySQL Server 5.7\Data`,
		`C:\Users\All Users\MySQL\MySQL Server 8.0\Data`,
		`C:\Users\All Users\MySQL\MySQL Server 8.4\Data`,
		`C:\Users\Default\AppData\Local\MySQL`,
		`C:\Users\Public\Documents\MySQL`,
		`C:\laragon\bin\mysql\mysql-8.0.30-winx64\data`,
		`C:\laragon\bin\mariadb\mariadb-10.11.2\data`,
		`C:\laragon\data\mysql`,
		`C:\laragon\logs`,
		`C:\Bitnami\wamp\apache2\htdocs`,
		`C:\Bitnami\wamp\mysql\data`,
		`C:\Bitnami\wamp\app`,
		`C:\ApacheFriends\xampp\mysql\data`,
		`C:\ApacheFriends\xampp\mariadb\data`,
		`C:\Apache24\htdocs`,
		`C:\nginx\html`,
		`C:\USR\local\mysql\data`,
		`C:\USR\local\mariadb\data`,
		`C:\USR\local\pgsql\data`,
		`C:\opt\mysql\data`,
		`C:\opt\mariadb\data`,
		`C:\opt\postgres\data`,
		`C:\opt\mongodb\data`,
		`C:\docker\volumes\mysql`,
		`C:\docker\volumes\mariadb`,
		`C:\docker\volumes\postgres`,
		`C:\docker\volumes\mongo`,
		`D:\MySQL\Data`,
		`D:\MariaDB\Data`,
		`D:\xampp\mysql\data`,
		`D:\xampp\mariadb\data`,
		`E:\MySQL\Data`,
		`E:\MariaDB\Data`,
		`E:\xampp\mysql\data`,
		`F:\MySQL\Data`,
		`F:\MariaDB\Data`,
	}

	logDirs = append(logDirs, extraDirs...)

	for _, dir := range logDirs {
		if checked[dir] {
			continue
		}
		checked[dir] = true

		entries, err := os.ReadDir(dir)
		if err != nil {
			continue
		}

		for _, entry := range entries {
			if entry.IsDir() {
				continue
			}

			fullPath := filepath.Join(dir, entry.Name())
			name := strings.ToLower(entry.Name())

			// Check if it matches known DB log patterns
			if !isDBLogFile(name) {
				continue
			}

			info, err := entry.Info()
			if err != nil {
				continue
			}

			engine := detectEngineFromPath(dir, name)
			logType := detectLogType(name)

			logs = append(logs, DBLogFile{
				Path:         fullPath,
				SizeBytes:    info.Size(),
				LastModified: info.ModTime().Format(time.RFC3339),
				Engine:       engine,
				LogType:      logType,
				Source:       detectSource(dir),
			})
		}
	}

	return logs
}

func isDBLogFile(name string) bool {
	// Known DB log file patterns
	patterns := []string{
		".err", ".log", ".txt",
		"error.log", "slow.log", "slow_query.log",
		"general.log", "general_query.log",
		"mysql.log", "mysqld.log", "mariadb.log", "mariadbd.log",
		"postgresql", "pg_log",
		"mongod.log", "mongo.log", "mongodb.log",
		"sqlserver.log", "errorlog", "error_log",
		"binlog.", "relay-log.", "ib_logfile",
		"ibdata", "undo_",
		"mysql-bin.", "mysql-relay.",
		"mariadb-bin.", "mariadb-relay.",
		"postgresql-", "pg_stat",
		"wal.log", "xlog",
		"audit.log", "audit.",
		"redolog", "redo.log",
		"innodb_status", "innodb_data",
	}

	for _, p := range patterns {
		if strings.Contains(name, p) {
			return true
		}
	}
	return false
}

func detectEngineFromPath(path, name string) string {
	lower := strings.ToLower(path + " " + name)
	switch {
	case strings.Contains(lower, "mariadb") || strings.Contains(lower, "mariadbd"):
		return "mariadb"
	case strings.Contains(lower, "mysql") || strings.Contains(lower, "mysqld"):
		return "mysql"
	case strings.Contains(lower, "postgres") || strings.Contains(lower, "pgsql") || strings.Contains(lower, "pg_"):
		return "postgresql"
	case strings.Contains(lower, "mongo") || strings.Contains(lower, "mongod"):
		return "mongodb"
	case strings.Contains(lower, "sqlserver") || strings.Contains(lower, "mssql") || strings.Contains(lower, "errorlog"):
		return "mssql"
	case strings.Contains(lower, "redis"):
		return "redis"
	case strings.Contains(lower, "sqlite"):
		return "sqlite"
	case strings.Contains(lower, "oracle"):
		return "oracle"
	case strings.Contains(lower, "cassandra"):
		return "cassandra"
	case strings.Contains(lower, "neo4j"):
		return "neo4j"
	default:
		return "unknown"
	}
}

func detectLogType(name string) string {
	lower := strings.ToLower(name)
	switch {
	case strings.Contains(lower, "error") || strings.Contains(lower, ".err"):
		return "error_log"
	case strings.Contains(lower, "slow"):
		return "slow_query"
	case strings.Contains(lower, "general"):
		return "general_log"
	case strings.Contains(lower, "bin") || strings.Contains(lower, "relay"):
		return "binary_log"
	case strings.Contains(lower, "redo") || strings.Contains(lower, "undo") || strings.Contains(lower, "xlog"):
		return "transaction_log"
	case strings.Contains(lower, "ib_log") || strings.Contains(lower, "ibdata"):
		return "innodb_log"
	case strings.Contains(lower, "audit"):
		return "audit_log"
	case strings.Contains(lower, "install"):
		return "install_log"
	case strings.Contains(lower, "wal"):
		return "wal_log"
	default:
		return "general_log"
	}
}

func detectSource(path string) string {
	lower := strings.ToLower(path)
	switch {
	case strings.Contains(lower, "xampp"):
		return "xampp"
	case strings.Contains(lower, "wamp"):
		return "wamp"
	case strings.Contains(lower, "laragon"):
		return "laragon"
	case strings.Contains(lower, "bitnami"):
		return "bitnami"
	case strings.Contains(lower, "uniform"):
		return "uniform_server"
	case strings.Contains(lower, "ampps"):
		return "ampps"
	case strings.Contains(lower, "easyphp"):
		return "easyphp"
	case strings.Contains(lower, "docker"):
		return "docker"
	case strings.Contains(lower, "program files") || strings.Contains(lower, "programfiles"):
		return "official_install"
	case strings.Contains(lower, "programdata"):
		return "official_install"
	case strings.Contains(lower, "users") && strings.Contains(lower, "appdata"):
		return "user_appdata"
	default:
		return "custom"
	}
}

func RunDBLogDiscovery() (map[string]interface{}, error) {
	logMsg("Starting DB log discovery...")

	installations := discoverDBInstallations()
	logMsg("Found %d database installations", len(installations))

	logFiles := discoverDBLogs(installations)
	logMsg("Found %d database log files", len(logFiles))

	// Group by engine
	byEngine := make(map[string]int)
	bySource := make(map[string]int)
	byType := make(map[string]int)

	for _, lf := range logFiles {
		byEngine[lf.Engine]++
		bySource[lf.Source]++
		byType[lf.LogType]++
	}

	result := map[string]interface{}{
		"installations": installations,
		"logFiles":      logFiles,
		"summary": map[string]interface{}{
			"totalLogFiles": len(logFiles),
			"byEngine":      byEngine,
			"bySource":      bySource,
			"byType":        byType,
		},
		"discoveredAt": time.Now().Format(time.RFC3339),
	}

	logMsg("DB log discovery complete: %d files across %d engines",
		len(logFiles), len(byEngine))

	return result, nil
}

func init() {
	// Register as a command that can be triggered via WebSocket
	registerCommandHandler("discover_db_logs", func(msg WSMessage) {
		go func() {
			result, err := RunDBLogDiscovery()
			if err != nil {
				wsSend(WSMessage{
					Type:    "db_log_discovery_result",
					Success: false,
					Output:  err.Error(),
				})
				return
			}
			wsSend(WSMessage{
				Type:       "db_log_discovery_result",
				Success:    true,
				DBLogDiscovery: result,
			})
		}()
	})
}

var commandHandlers = make(map[string]func(WSMessage))

func registerCommandHandler(cmdType string, handler func(WSMessage)) {
	commandHandlers[cmdType] = handler
}

func init() {
	// Ensure map is initialized
	if commandHandlers == nil {
		commandHandlers = make(map[string]func(WSMessage))
	}
}
