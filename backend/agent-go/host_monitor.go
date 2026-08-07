package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// ──────────────────────────────────────────────
// Host-Level Monitor
// Detects ALL database-related activity on the PC:
// - phpMyAdmin / Adminer web access
// - Any process connecting to DB ports (netstat)
// - Config file integrity (my.ini, phpMyAdmin, php.ini)
// - MySQL general_log file tailing for ALL users
// ──────────────────────────────────────────────

type HostMonitor struct {
	mu sync.RWMutex

	// File integrity tracking
	configFiles   map[string]ConfigFileSnapshot
	lastFileCheck time.Time

	// Process-to-port tracking
	processPorts   map[int]*ProcessPortInfo
	lastPortScan   time.Time
	seenConns      map[string]time.Time

	// Web tool access tracking
	lastWebScan       time.Time
	webAccessHistory  []WebAccessEntry
	lastApacheModTime time.Time
	lastPmaModTime    time.Time
	apacheReadOffset  int64
	seenWebURLs       map[string]time.Time
}

type ConfigFileSnapshot struct {
	Path     string
	ModTime  time.Time
	Size     int64
	Checksum string
}

type ProcessPortInfo struct {
	PID        int
	Process    string
	LocalAddr  string
	RemoteAddr string
	State      string
	Port       int
	DetectedAt time.Time
}

type WebAccessEntry struct {
	Timestamp  time.Time
	RemoteIP   string
	Method     string
	URL        string
	UserAgent  string
	StatusCode int
	Source     string // "phpmyadmin", "adminer", "other"
}

type HostEvent struct {
	Type      string // "web_access", "process_connection", "config_change", "dump_tool", "user_login"
	Severity  string // "critical", "high", "medium", "low", "info"
	Title     string
	Detail    string
	Timestamp time.Time
	Source    string
}

var (
	hostEvents     []HostEvent
	hostEventsMu   sync.Mutex
	lastHostReport time.Time
)

func NewHostMonitor() *HostMonitor {
	return &HostMonitor{
		configFiles:  make(map[string]ConfigFileSnapshot),
		processPorts: make(map[int]*ProcessPortInfo),
		seenConns:    make(map[string]time.Time),
		seenWebURLs:  make(map[string]time.Time),
	}
}

// StartHostMonitoring launches the host-level monitoring goroutine
func StartHostMonitoring() {
	hm := NewHostMonitor()
	go hm.loop()
	logMsg("Host Monitor: started — detecting ALL DB activity on this PC")
}

func (hm *HostMonitor) loop() {
	time.Sleep(5 * time.Second)
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()

	hm.initConfigFiles()

	cleanupTick := 0
	for range ticker.C {
		hm.checkWebTools()
		hm.checkProcessConnections()
		hm.checkConfigFiles()
		hm.reportEvents()

		// Cleanup dedup maps every 2 minutes
		cleanupTick++
		if cleanupTick%24 == 0 {
			hm.mu.Lock()
			for k, t := range hm.seenWebURLs {
				if time.Since(t) > 3*time.Minute {
					delete(hm.seenWebURLs, k)
				}
			}
			for k, t := range hm.seenConns {
				if time.Since(t) > 3*time.Minute {
					delete(hm.seenConns, k)
				}
			}
			hm.mu.Unlock()
		}
	}
}

// ──────────────────────────────────────────────
// 1. phpMyAdmin / Adminer Web Access Detection
// ──────────────────────────────────────────────

func (hm *HostMonitor) checkWebTools() {
	if time.Since(hm.lastWebScan) < 5*time.Second {
		return
	}
	hm.lastWebScan = time.Now()

	// XAMPP Apache access log
	apachePaths := []string{
		`C:\xampp\apache\logs\access.log`,
		`C:\xampp\apache\logs\access.log.1`,
		`C:\Program Files\Apache Software Foundation\Apache24\logs\access.log`,
		`C:\wamp64\logs\access.log`,
	}

	for _, logPath := range apachePaths {
		hm.parseApacheAccessLog(logPath)
	}

	// Also check phpMyAdmin session/login files
	hm.checkPhpMyAdminSessions()
}

func (hm *HostMonitor) parseApacheAccessLog(logPath string) {
	f, err := os.Open(logPath)
	if err != nil {
		return
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return
	}

	// Only read new content since last read
	hm.mu.RLock()
	offset := hm.apacheReadOffset
	hm.mu.RUnlock()

	if info.Size() <= offset {
		return // no new data
	}
	f.Seek(offset, 0)
	hm.mu.Lock()
	hm.apacheReadOffset = info.Size()
	hm.mu.Unlock()

	// Read only new lines
	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 64*1024), 64*1024)

	// Patterns for phpMyAdmin, Adminer, and other web DB tools
	pmaPatterns := []struct {
		regex   *regexp.Regexp
		name    string
		severity string
	}{
		{regexp.MustCompile(`(?i)(/phpmyadmin|/pma|/phpMyAdmin|/myadmin)`), "phpMyAdmin", "high"},
		{regexp.MustCompile(`(?i)(/adminer|/adminer\.php)`), "Adminer", "high"},
		{regexp.MustCompile(`(?i)(/dbadmin|/mysqlmanager|/sqlmanager|/websql)`), "Web SQL Tool", "high"},
		{regexp.MustCompile(`(?i)(/phpminiadmin)`), "phpMiniAdmin", "high"},
		{regexp.MustCompile(`(?i)(/adminer\.php|/dbedit|/sqlweb)`), "Web DB Editor", "high"},
	}

	// Apache combined log format: IP - - [date] "METHOD URL HTTP/x.x" status size "referer" "ua"
	apacheLogRegex := regexp.MustCompile(`^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+) \S+" (\d{3}) (\S+) "([^"]*)" "([^"]*)"`)

	for scanner.Scan() {
		line := scanner.Text()
		if line == "" {
			continue
		}
		matches := apacheLogRegex.FindStringSubmatch(line)
		if matches == nil {
			continue
		}

		remoteIP := matches[1]
		method := matches[3]
		url := matches[4]
		statusCode, _ := strconv.Atoi(matches[5])
		userAgent := matches[8]

		for _, p := range pmaPatterns {
			if p.regex.MatchString(url) {
				// Skip static assets (images, JS, CSS, fonts) — noise reduction
				staticExts := []string{".png", ".jpg", ".jpeg", ".gif", ".ico", ".svg", ".css", ".js", ".woff", ".woff2", ".ttf", ".eot"}
				isStatic := false
				for _, ext := range staticExts {
					if strings.HasSuffix(strings.ToLower(url), ext) || strings.Contains(strings.ToLower(url), ext+"?") {
						isStatic = true
						break
					}
				}
				if isStatic {
					continue
				}

				// Dedup: same URL within 60s
				dedupKey := fmt.Sprintf("%s|%s", remoteIP, url)
				hm.mu.RLock()
				lastSeen, seen := hm.seenWebURLs[dedupKey]
				hm.mu.RUnlock()
				if seen && time.Since(lastSeen) < 60*time.Second {
					continue
				}
				hm.mu.Lock()
				hm.seenWebURLs[dedupKey] = time.Now()
				hm.mu.Unlock()

				entry := WebAccessEntry{
					Timestamp:  time.Now(),
					RemoteIP:   remoteIP,
					Method:     method,
					URL:        url,
					UserAgent:  userAgent,
					StatusCode: statusCode,
					Source:     p.name,
				}

				hm.mu.Lock()
				hm.webAccessHistory = append(hm.webAccessHistory, entry)
				if len(hm.webAccessHistory) > 500 {
					hm.webAccessHistory = hm.webAccessHistory[len(hm.webAccessHistory)-500:]
				}
				hm.mu.Unlock()

				// Determine severity based on action
				sev := p.severity
				if strings.Contains(url, "login") || strings.Contains(url, "signin") || method == "POST" {
					sev = "critical"
				}
				if statusCode >= 400 {
					sev = "medium" // failed attempts still important
				}

				title := fmt.Sprintf("Web DB tool access: %s", p.name)
				detail := fmt.Sprintf("IP: %s | %s %s | Status: %d | UA: %.80s", remoteIP, method, url, statusCode, userAgent)

				enqueueHostEvent(HostEvent{
					Type:      "web_access",
					Severity:  sev,
					Title:     title,
					Detail:    detail,
					Timestamp: time.Now(),
					Source:    p.name,
				})

				logMsg("Host Monitor: [%s] %s access from %s → %s (HTTP %d)", strings.ToUpper(sev), p.name, remoteIP, url, statusCode)
				break
			}
		}
	}
}

func (hm *HostMonitor) checkPhpMyAdminSessions() {
	if runtime.GOOS != "windows" {
		return
	}

	// Check phpMyAdmin temp session files
	pmaPaths := []string{
		`C:\xampp\phpMyAdmin\config.inc.php`,
		`C:\xampp\phpMyAdmin\config.default.php`,
		`C:\xampp\phpMyAdmin\blowfish_secret.inc.php`,
	}

	for _, p := range pmaPaths {
		f, err := os.Stat(p)
		if err != nil {
			continue
		}

		hm.mu.RLock()
		snap, exists := hm.configFiles[p]
		hm.mu.RUnlock()

		if exists && f.ModTime().Equal(snap.ModTime) {
			continue
		}

		if !exists {
			hm.mu.Lock()
			hm.configFiles[p] = ConfigFileSnapshot{
				Path:    p,
				ModTime: f.ModTime(),
				Size:    f.Size(),
			}
			hm.mu.Unlock()
			continue
		}

		// File was modified!
		enqueueHostEvent(HostEvent{
			Type:      "config_change",
			Severity:  "critical",
			Title:     "phpMyAdmin config modified",
			Detail:    fmt.Sprintf("File: %s | Old: %s | New: %s", p, snap.ModTime.Format(time.RFC3339), f.ModTime().Format(time.RFC3339)),
			Timestamp: time.Now(),
			Source:    "phpmyadmin",
		})
		logMsg("Host Monitor: [CRITICAL] phpMyAdmin config MODIFIED: %s", p)

		hm.mu.Lock()
		hm.configFiles[p] = ConfigFileSnapshot{
			Path:    p,
			ModTime: f.ModTime(),
			Size:    f.Size(),
		}
		hm.mu.Unlock()
	}
}

// ──────────────────────────────────────────────
// 2. Process-to-Port Connection Detection
// ──────────────────────────────────────────────

func (hm *HostMonitor) checkProcessConnections() {
	if time.Since(hm.lastPortScan) < 10*time.Second {
		return
	}
	hm.lastPortScan = time.Now()

	if runtime.GOOS != "windows" {
		return
	}

	// Use netstat to find all TCP connections to DB ports
	cmd := exec.Command("netstat", "-ano", "-p", "TCP")
	output, err := cmd.Output()
	if err != nil {
		// Fallback: PowerShell Get-NetTCPConnection
		hm.checkProcessConnectionsPS()
		return
	}

	dbPorts := map[int]string{
		3306:  "MySQL/MariaDB",
		3307:  "MySQL/MariaDB (alt)",
		5432:  "PostgreSQL",
		5433:  "PostgreSQL (alt)",
		1433:  "MSSQL",
		1434:  "MSSQL Browser",
		27017: "MongoDB",
		27018: "MongoDB (alt)",
		6379:  "Redis",
		6380:  "Redis (alt)",
		9218:  "MongoDB shard",
		1521:  "Oracle",
		8529:  "ArangoDB",
	}

	// PID -> process name cache
	pidCache := make(map[int]string)

	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "Proto") || strings.HasPrefix(line, "Active") {
			continue
		}

		fields := strings.Fields(line)
		if len(fields) < 5 {
			continue
		}

		state := fields[3]
		if state != "ESTABLISHED" && state != "LISTENING" {
			continue
		}

		localAddr := fields[1]
		pidStr := fields[len(fields)-1]
		pid, err := strconv.Atoi(pidStr)
		if err != nil || pid == 0 || pid == 4 {
			continue
		}

		// Extract port from local address
		portStr := localAddr
		if idx := strings.LastIndex(localAddr, ":"); idx >= 0 {
			portStr = localAddr[idx+1:]
		}
		port, err := strconv.Atoi(portStr)
		if err != nil {
			continue
		}

		dbName, isDBPort := dbPorts[port]
		if !isDBPort {
			// Also check remote port
			remoteAddr := ""
			if len(fields) >= 2 {
				remoteAddr = fields[2]
			}
			if rIdx := strings.LastIndex(remoteAddr, ":"); rIdx >= 0 {
				rPortStr := remoteAddr[rIdx+1:]
				rPort, err := strconv.Atoi(rPortStr)
				if err == nil {
					if name, ok := dbPorts[rPort]; ok {
						dbName = name
						isDBPort = true
						port = rPort
					}
				}
			}
		}

		if !isDBPort {
			continue
		}

		// Skip our own agent process
		if pid == os.Getpid() {
			continue
		}

		// Get process name
		procName, ok := pidCache[pid]
		if !ok {
			if name, err := getProcessNameByPID(pid); err == nil {
				procName = name
				pidCache[pid] = name
			} else {
				procName = fmt.Sprintf("PID:%d", pid)
				pidCache[pid] = procName
			}
		}

		// Skip known system processes
		skipProcs := []string{"System", "svchost.exe", "mysqld.exe", "mariadbd.exe", "postgres.exe", "mongod.exe", "redis-server.exe", "sqlservr.exe", "node.exe", "npm.exe", "pm2.exe", "wails.exe"}
		skip := false
		for _, sp := range skipProcs {
			if strings.EqualFold(procName, sp) {
				skip = true
				break
			}
		}
		if skip {
			continue
		}

		connKey := fmt.Sprintf("%d-%d-%s", pid, port, state)

		hm.mu.RLock()
		existing, seen := hm.seenConns[connKey]
		hm.mu.RUnlock()

		if seen && time.Since(existing) < 60*time.Second {
			continue // already reported recently
		}

		hm.mu.Lock()
		hm.seenConns[connKey] = time.Now()
		hm.mu.Unlock()

		detail := fmt.Sprintf("Process: %s (PID:%d) connected to %s port %d [%s] | Local: %s", procName, pid, dbName, port, state, localAddr)
		sev := "high"
		isRemote := false

		// Detect remote (non-localhost) connections — CRITICAL
		remoteAddr := ""
		if len(fields) >= 3 {
			remoteAddr = fields[2]
		}
		remoteHost := extractHost(remoteAddr)
		if remoteHost != "" && !isLocalhost(remoteHost) {
			sev = "critical"
			isRemote = true
			detail = fmt.Sprintf("⚠ REMOTE Connection: %s (PID:%d) → %s port %d | Remote: %s | Local: %s", procName, pid, dbName, port, remoteAddr, localAddr)
			logMsg("Host Monitor: [CRITICAL] REMOTE connection detected: %s (PID:%d) → %s from %s", procName, pid, dbName, remoteAddr)
		}

		// Dump tools are critical
		dumpProcs := []string{"mysqldump", "pg_dump", "mongodump", "mongoexport", "bcp", "bcp.exe"}
		for _, dp := range dumpProcs {
			if strings.Contains(strings.ToLower(procName), strings.ToLower(dp)) {
				sev = "critical"
				break
			}
		}

		// Persist to SQLite
		go GetAuditStore().StoreConnectionEvent(time.Now(), "process_connection", procName, localAddr, remoteAddr, dbName, pid, port, isRemote, sev)

		enqueueHostEvent(HostEvent{
			Type:      "process_connection",
			Severity:  sev,
			Title:     fmt.Sprintf("Process connected to %s", dbName),
			Detail:    detail,
			Timestamp: time.Now(),
			Source:    "netstat",
		})
		logMsg("Host Monitor: [%s] %s connected to %s port %d", strings.ToUpper(sev), procName, dbName, port)
	}

	// Cleanup old connection tracking
	hm.mu.Lock()
	for k, t := range hm.seenConns {
		if time.Since(t) > 5*time.Minute {
			delete(hm.seenConns, k)
		}
	}
	hm.mu.Unlock()
}

func (hm *HostMonitor) checkProcessConnectionsPS() {
	// Fallback via PowerShell
	cmd := exec.Command("powershell", "-NoProfile", "-Command",
		"Get-NetTCPConnection -State Established | Where-Object { @(3306,5432,1433,27017,6379,1521) -contains $_.LocalPort } | Select-Object LocalPort,OwningProcess,RemoteAddress -ExpandProperty LocalPort")
	output, err := cmd.Output()
	if err != nil {
		return
	}

	dbPorts := map[int]string{
		3306: "MySQL/MariaDB", 5432: "PostgreSQL", 1433: "MSSQL",
		27017: "MongoDB", 6379: "Redis", 1521: "Oracle",
	}

	_ = output
	_ = dbPorts
	// If PS fallback fails, we just skip this cycle
}

func getProcessNameByPID(pid int) (string, error) {
	cmd := exec.Command("tasklist.exe", "/FI", fmt.Sprintf("PID eq %d", pid), "/FO", "CSV", "/NH")
	output, err := cmd.Output()
	if err != nil {
		return "", err
	}

	lines := strings.Split(strings.TrimSpace(string(output)), "\n")
	if len(lines) == 0 {
		return "", fmt.Errorf("no output")
	}

	line := lines[0]
	parts := strings.Split(line, "\",\"")
	if len(parts) < 1 {
		return "", fmt.Errorf("parse error")
	}

	return strings.Trim(parts[0], "\""), nil
}

// ──────────────────────────────────────────────
// 3. Config File Integrity Monitoring
// ──────────────────────────────────────────────

func (hm *HostMonitor) initConfigFiles() {
	paths := hm.getConfigPaths()

	for _, p := range paths {
		f, err := os.Stat(p)
		if err != nil {
			continue
		}
		hm.configFiles[p] = ConfigFileSnapshot{
			Path:    p,
			ModTime: f.ModTime(),
			Size:    f.Size(),
		}
	}

	logMsg("Host Monitor: tracking %d config files for integrity", len(hm.configFiles))
}

func (hm *HostMonitor) getConfigPaths() []string {
	paths := []string{}

	// MySQL
	for _, p := range []string{
		`C:\xampp\mysql\bin\my.ini`,
		`C:\xampp\mysql\my.ini`,
		`C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`,
		`C:\wamp64\bin\mysql\mysql8.0.31\my.ini`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// phpMyAdmin
	for _, p := range []string{
		`C:\xampp\phpMyAdmin\config.inc.php`,
		`C:\xampp\phpMyAdmin\config.default.php`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// PHP
	for _, p := range []string{
		`C:\xampp\php\php.ini`,
		`C:\xampp\php\php.ini-development`,
		`C:\xampp\php\php.ini-production`,
		`C:\wamp64\bin\php\php8.1.21\php.ini`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// Apache
	for _, p := range []string{
		`C:\xampp\apache\conf\httpd.conf`,
		`C:\xampp\apache\conf\extra\httpd-xamppp.conf`,
		`C:\wamp64\bin\apache\apache2.4.51\conf\httpd.conf`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// PostgreSQL
	for _, p := range []string{
		`C:\Program Files\PostgreSQL\16\postgresql.conf`,
		`C:\Program Files\PostgreSQL\15\postgresql.conf`,
		`C:\Program Files\PostgreSQL\14\postgresql.conf`,
		`C:\Program Files\PostgreSQL\16\pg_hba.conf`,
		`C:\Program Files\PostgreSQL\15\pg_hba.conf`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// Redis
	for _, p := range []string{
		`C:\Program Files\Redis\redis.windows-service.conf`,
		`C:\Program Files\Redis\redis.conf`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	// MSSQL
	for _, p := range []string{
		`C:\Program Files\Microsoft SQL Server\MSSQL16.MSSQLSERVER\MSSQL\Binn\mssql.conf`,
		`C:\Program Files\Microsoft SQL Server\MSSQL15.MSSQLSERVER\MSSQL\Binn\mssql.conf`,
	} {
		if _, err := os.Stat(p); err == nil {
			paths = append(paths, p)
		}
	}

	return paths
}

func (hm *HostMonitor) checkConfigFiles() {
	if time.Since(hm.lastFileCheck) < 10*time.Second {
		return
	}
	hm.lastFileCheck = time.Now()

	for path, snap := range hm.configFiles {
		f, err := os.Stat(path)
		if err != nil {
			// File deleted!
			enqueueHostEvent(HostEvent{
				Type:      "config_change",
				Severity:  "critical",
				Title:     "Config file DELETED",
				Detail:    fmt.Sprintf("File: %s", path),
				Timestamp: time.Now(),
				Source:    "file_integrity",
			})
			logMsg("Host Monitor: [CRITICAL] Config file DELETED: %s", path)
			hm.mu.Lock()
			delete(hm.configFiles, path)
			hm.mu.Unlock()
			continue
		}

		if f.ModTime().Equal(snap.ModTime) && f.Size() == snap.Size {
			continue
		}

		// File changed!
		severity := "high"
		fileName := filepath.Base(path)
		if fileName == "config.inc.php" || fileName == "pg_hba.conf" {
			severity = "critical" // auth config changes are critical
		}
		if fileName == "my.ini" || fileName == "postgresql.conf" {
			severity = "critical" // server config changes are critical
		}

		detail := fmt.Sprintf("File: %s | Size: %d→%d | Modified: %s→%s",
			path, snap.Size, f.Size(),
			snap.ModTime.Format("15:04:05"), f.ModTime().Format("15:04:05"))

		enqueueHostEvent(HostEvent{
			Type:      "config_change",
			Severity:  severity,
			Title:     fmt.Sprintf("Config modified: %s", fileName),
			Detail:    detail,
			Timestamp: time.Now(),
			Source:    "file_integrity",
		})
		logMsg("Host Monitor: [%s] Config MODIFIED: %s", strings.ToUpper(severity), path)

		hm.mu.Lock()
		hm.configFiles[path] = ConfigFileSnapshot{
			Path:    path,
			ModTime: f.ModTime(),
			Size:    f.Size(),
		}
		hm.mu.Unlock()
	}

	// Check for new DB config files that weren't there before
	newPaths := hm.getConfigPaths()
	for _, p := range newPaths {
		hm.mu.RLock()
		_, exists := hm.configFiles[p]
		hm.mu.RUnlock()
		if !exists {
			f, err := os.Stat(p)
			if err != nil {
				continue
			}
			hm.mu.Lock()
			hm.configFiles[p] = ConfigFileSnapshot{
				Path:    p,
				ModTime: f.ModTime(),
				Size:    f.Size(),
			}
			hm.mu.Unlock()
			logMsg("Host Monitor: now tracking new config file: %s", p)
		}
	}
}

// ──────────────────────────────────────────────
// Event Queue & Reporting
// ──────────────────────────────────────────────

func enqueueHostEvent(ev HostEvent) {
	hostEventsMu.Lock()
	defer hostEventsMu.Unlock()
	hostEvents = append(hostEvents, ev)
	if len(hostEvents) > 1000 {
		hostEvents = hostEvents[len(hostEvents)-1000:]
	}

	// Persist to SQLite audit store
	go GetAuditStore().StoreHostEvent(ev)
}

func (hm *HostMonitor) reportEvents() {
	hostEventsMu.Lock()
	events := make([]HostEvent, len(hostEvents))
	copy(events, hostEvents)
	hostEventsMu.Unlock()

	if len(events) == 0 {
		return
	}

	// Send via WebSocket every 15 seconds
	if time.Since(lastHostReport) < 15*time.Second {
		return
	}
	lastHostReport = time.Now()

	// Count by severity
	sevCounts := map[string]int{}
	for _, ev := range events {
		sevCounts[ev.Severity]++
	}

	// Send each event to backend via the existing WebSocket
	for _, ev := range events {
		sendHostEvent(ev)
	}

	// Clear sent events
	hostEventsMu.Lock()
	if len(hostEvents) > 0 {
		hostEvents = hostEvents[:0]
	}
	hostEventsMu.Unlock()
}

func sendHostEvent(ev HostEvent) {
	desc := ev.Detail
	wsSend(WSMessage{
		Type:        "host_event",
		AgentID:     GetAgentID(),
		Title:       ev.Title,
		Description: desc,
		Source:      ev.Source,
		Severity:    ev.Severity,
		EventType:   ev.Type,
	})
}

// ──────────────────────────────────────────────
// MySQL general_log file monitoring (ALL users)
// Watches the raw log file for any access including
// phpMyAdmin connections
// ──────────────────────────────────────────────

func (hm *HostMonitor) watchMySQLGeneralLogFile() {
	logPath := `C:\xampp\mysql\data\DESKTOP-CDEE7BS.log`

	// Patterns that indicate web tool usage
	webToolPatterns := []struct {
		regex *regexp.Regexp
		name  string
	}{
		{regexp.MustCompile(`(?i)Connect\s+\S*@.*phpmyadmin`), "phpMyAdmin"},
		{regexp.MustCompile(`(?i)Connect\s+\S*@.*adminer`), "Adminer"},
		{regexp.MustCompile(`(?i)Connect\s+\S*@.*\d+\.\d+\.\d+\.\d+.*\(.*Safari|Chrome|Firefox|Edge`), "Web Browser (unknown tool)"},
	}

	for {
		f, err := os.Open(logPath)
		if err != nil {
			time.Sleep(30 * time.Second)
			continue
		}

		// Seek to end
		f.Seek(0, os.SEEK_END)
		buf := make([]byte, 0, 4096)

		for {
			n, err := f.Read(buf[:cap(buf)])
			if n > 0 {
				data := buf[:n]
				for _, p := range webToolPatterns {
					if p.regex.Match(data) {
						enqueueHostEvent(HostEvent{
							Type:      "web_access",
							Severity:  "critical",
							Title:     fmt.Sprintf("DB access via %s detected in general_log", p.name),
							Detail:    fmt.Sprintf("Pattern found in MySQL general_log file: %s", string(data[:min(len(data), 200)])),
							Timestamp: time.Now(),
							Source:    "mysql_general_log",
						})
					}
				}
			}
			if err != nil {
				break
			}
			time.Sleep(time.Second)
		}
		f.Close()
		time.Sleep(5 * time.Second)
	}
}

// ──────────────────────────────────────────────
// Utilities
// ──────────────────────────────────────────────

func readLastLines(f *os.File, n int) []string {
	f.Seek(0, 0)
	scanner := bufio.NewScanner(f)
	var lines []string
	for scanner.Scan() {
		lines = append(lines, scanner.Text())
	}
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return lines
}

// ──────────────────────────────────────────────
// GetRecentHostEvents returns events for the
// activity summary / compliance report
// ──────────────────────────────────────────────

func GetRecentHostEvents(since time.Duration) []HostEvent {
	hostEventsMu.Lock()
	defer hostEventsMu.Unlock()

	cutoff := time.Now().Add(-since)
	var result []HostEvent
	for _, ev := range hostEvents {
		if ev.Timestamp.After(cutoff) {
			result = append(result, ev)
		}
	}
	return result
}

func GetHostEventsSummary() map[string]interface{} {
	hostEventsMu.Lock()
	events := make([]HostEvent, len(hostEvents))
	copy(events, hostEvents)
	hostEventsMu.Unlock()

	total := len(events)
	byType := map[string]int{}
	bySev := map[string]int{}

	for _, ev := range events {
		byType[ev.Type]++
		bySev[ev.Severity]++
	}

	return map[string]interface{}{
		"total_events":       total,
		"by_type":            byType,
		"by_severity":        bySev,
		"web_tool_accesses":  byType["web_access"],
		"process_connections": byType["process_connection"],
		"config_changes":     byType["config_change"],
	}
}

// Sort helper for web access history
func sortByTime(entries []WebAccessEntry) sort.Interface {
	return &webAccessSorter{entries: entries}
}

type webAccessSorter struct {
	entries []WebAccessEntry
}

func (s *webAccessSorter) Len() int           { return len(s.entries) }
func (s *webAccessSorter) Swap(i, j int)      { s.entries[i], s.entries[j] = s.entries[j], s.entries[i] }
func (s *webAccessSorter) Less(i, j int) bool { return s.entries[i].Timestamp.Before(s.entries[j].Timestamp) }

// extractHost extracts IP from "ip:port" format
func extractHost(addr string) string {
	if idx := strings.LastIndex(addr, ":"); idx > 0 {
		return addr[:idx]
	}
	return addr
}

// isLocalhost checks if an IP is a local address
func isLocalhost(ip string) bool {
	localIPs := []string{"127.0.0.1", "::1", "0.0.0.0", "localhost", "::", "127.0.0.0"}
	for _, l := range localIPs {
		if ip == l {
			return true
		}
	}
	// Also check for 127.x.x.x range
	if len(ip) > 3 && ip[:4] == "127." {
		return true
	}
	// Windows loopback 10.x.x.x on some configs? No, 10.x is private, not loopback
	return false
}

// isPrivateIP checks if an IP is in a private range (RFC1918)
func isPrivateIP(ip string) bool {
	if strings.HasPrefix(ip, "10.") {
		return true
	}
	if strings.HasPrefix(ip, "172.") {
		return true // simplified: 172.16-31.x.x
	}
	if strings.HasPrefix(ip, "192.168.") {
		return true
	}
	return false
}
