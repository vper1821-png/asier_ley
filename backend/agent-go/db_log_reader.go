package main

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

type LogFileContent struct {
	Path         string        `json:"path"`
	Engine       string        `json:"engine"`
	LogType      string        `json:"logType"`
	SizeBytes    int64         `json:"sizeBytes"`
	LastModified string        `json:"lastModified"`
	Lines        []LogLine     `json:"lines"`
	TotalLines   int           `json:"totalLines"`
	Parsed       bool          `json:"parsed"`
	Error        string        `json:"error,omitempty"`
}

type LogLine struct {
	LineNumber int    `json:"lineNumber"`
	Timestamp  string `json:"timestamp,omitempty"`
	Severity   string `json:"severity,omitempty"`
	Content    string `json:"content"`
	Query      string `json:"query,omitempty"`
	User       string `json:"user,omitempty"`
	Host       string `json:"host,omitempty"`
	Database   string `json:"database,omitempty"`
}

type DBLogReader struct {
	mu           sync.Mutex
	lastRead     map[string]int64
	maxLineLen   int
	readBuffer   int
}

var globalLogReader = &DBLogReader{
	lastRead:   make(map[string]int64),
	maxLineLen: 10000,
	readBuffer: 5000,
}

// MySQL/MariaDB log patterns
var mysqlErrorLogPattern = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}T?\d{2}:\d{2}:\d{2})\.?\d*\s+(\d+)\s+\[([A-Z]+)\]\s+(.*)`)
var mysqlGeneralLogPattern = regexp.MustCompile(`^(\d{6}\s+\d{1,2}:\d{2}:\d{2})\s+(\d+)\s+([A-Za-z])\s+(.*)`)
var mysqlSlowLogPattern = regexp.MustCompile(`#\s+Time:\s+(\d{4}-\d{2}-\d{2}T?\d{2}:\d{2}:\d{2})`)
var mysqlQueryPattern = regexp.MustCompile(`^(\d{6}\s+\d{1,2}:\d{2}:\d{2})\s+(\d+)\s+([A-Za-z])\s+(.*)`)

// PostgreSQL log patterns
var postgresLogPattern = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+[A-Z]+\s+\[(\d+)\]:?\s+\[(\d+)\]\s+(.*)`)
var postgresQueryLogPattern = regexp.MustCompile(`^(LOG|ERROR|WARNING|FATAL|PANIC):\s+(.*)`)
var postgresDurationPattern = regexp.MustCompile(`duration:\s+([\d.]+)\s+ms\s+(.*)`)

// MSSQL log patterns
var mssqlLogPattern = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(.*)`)
var mssqlErrorLogPattern = regexp.MustCompile(`^(Error|Warning|Errorlog)\s*:?\s*(.*)`)

// MongoDB log patterns
var mongoLogPattern = regexp.MustCompile(`^(\d{4}-\d{2}-\d{2}T?\d{2}:\d{2}:\d{2})\.?\d*([+-]\d{2}:\d{2})?\s+([A-Z])\s+([A-Z]+)\s+\[([^\]]+)\]\s+(.*)`)

func (r *DBLogReader) ReadLogFile(path string, engine string, logType string) *LogFileContent {
	result := &LogFileContent{
		Path:   path,
		Engine: engine,
		LogType: logType,
	}

	info, err := os.Stat(path)
	if err != nil {
		result.Error = fmt.Sprintf("cannot stat: %v", err)
		return result
	}
	result.SizeBytes = info.Size()
	result.LastModified = info.ModTime().UTC().Format(time.RFC3339)

	file, err := os.Open(path)
	if err != nil {
		result.Error = fmt.Sprintf("cannot open: %v", err)
		return result
	}
	defer file.Close()

	r.mu.Lock()
	lastPos := r.lastRead[path]
	r.mu.Unlock()

	if lastPos > 0 {
		file.Seek(lastPos, 0)
	}

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, r.maxLineLen*2), r.maxLineLen*2)
	lineNum := 0
	var lines []LogLine

	for scanner.Scan() {
		lineNum++
		text := scanner.Text()
		if len(text) > r.maxLineLen {
			text = text[:r.maxLineLen]
		}

		parsed := r.parseLine(engine, logType, text, lineNum)
		lines = append(lines, parsed)

		if len(lines) >= r.readBuffer {
			break
		}
	}

	result.Lines = lines
	result.TotalLines = lineNum
	result.Parsed = true

	if err := scanner.Err(); err != nil {
		result.Error = fmt.Sprintf("read error: %v", err)
	}

	// Track position for incremental reading
	pos, _ := file.Seek(0, 1)
	r.mu.Lock()
	r.lastRead[path] = pos
	r.mu.Unlock()

	return result
}

func (r *DBLogReader) parseLine(engine, logType, text string, lineNum int) LogLine {
	line := LogLine{LineNumber: lineNum, Content: text}

	switch engine {
	case "mysql", "mariadb":
		r.parseMySQLLine(logType, text, &line)
	case "postgresql":
		r.parsePostgresLine(text, &line)
	case "mssql":
		r.parseMSSQLLine(text, &line)
	case "mongodb":
		r.parseMongoLine(text, &line)
	}

	return line
}

func (r *DBLogReader) parseMySQLLine(logType, text string, line *LogLine) {
	switch logType {
	case "error_log", "error":
		if m := mysqlErrorLogPattern.FindStringSubmatch(text); m != nil {
			line.Timestamp = m[1]
			line.Severity = m[3]
		}
	case "general_log", "general":
		if m := mysqlGeneralLogPattern.FindStringSubmatch(text); m != nil {
			line.Timestamp = m[1]
			line.Content = m[4]
			if strings.HasPrefix(strings.ToUpper(m[4]), "SELECT") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "INSERT") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "UPDATE") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "DELETE") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "CREATE") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "ALTER") ||
				strings.HasPrefix(strings.ToUpper(m[4]), "DROP") {
				line.Query = m[4]
			}
		}
	case "slow_query", "slow":
		if strings.HasPrefix(text, "# Time:") {
			if m := mysqlSlowLogPattern.FindStringSubmatch(text); m != nil {
				line.Timestamp = m[1]
			}
		} else if strings.HasPrefix(text, "# User@Host:") {
			parts := strings.Split(text, ":")
			if len(parts) > 1 {
				line.User = strings.TrimSpace(parts[1])
			}
		} else if !strings.HasPrefix(text, "#") && len(text) > 0 {
			line.Query = text
		}
	case "binary_log", "binlog":
		// Binary logs are binary - just note the file info
		line.Content = fmt.Sprintf("[binary log entry at offset %d]", line.LineNumber)
	}
}

func (r *DBLogReader) parsePostgresLine(text string, line *LogLine) {
	if m := postgresLogPattern.FindStringSubmatch(text); m != nil {
		line.Timestamp = m[1]
		if strings.Contains(text, "LOG:") {
			line.Severity = "LOG"
		} else if strings.Contains(text, "ERROR:") {
			line.Severity = "ERROR"
		} else if strings.Contains(text, "WARNING:") {
			line.Severity = "WARNING"
		} else if strings.Contains(text, "FATAL:") {
			line.Severity = "FATAL"
		}
		if d := postgresDurationPattern.FindStringSubmatch(text); d != nil {
			line.Query = d[2]
		}
	} else if q := postgresQueryLogPattern.FindStringSubmatch(text); q != nil {
		line.Severity = q[1]
		if strings.Contains(strings.ToUpper(q[2]), "SELECT") ||
			strings.Contains(strings.ToUpper(q[2]), "INSERT") ||
			strings.Contains(strings.ToUpper(q[2]), "UPDATE") ||
			strings.Contains(strings.ToUpper(q[2]), "DELETE") {
			line.Query = q[2]
		}
	}
}

func (r *DBLogReader) parseMSSQLLine(text string, line *LogLine) {
	if m := mssqlLogPattern.FindStringSubmatch(text); m != nil {
		line.Timestamp = m[1]
		line.Content = m[2]
	}
	if strings.Contains(text, "Error:") {
		line.Severity = "ERROR"
	} else if strings.Contains(text, "Warning:") {
		line.Severity = "WARNING"
	}
	if strings.Contains(strings.ToUpper(text), "SELECT") ||
		strings.Contains(strings.ToUpper(text), "INSERT") ||
		strings.Contains(strings.ToUpper(text), "UPDATE") ||
		strings.Contains(strings.ToUpper(text), "DELETE") {
		line.Query = text
	}
}

func (r *DBLogReader) parseMongoLine(text string, line *LogLine) {
	if m := mongoLogPattern.FindStringSubmatch(text); m != nil {
		line.Timestamp = m[1]
		line.Severity = m[3]
		line.Content = m[6]
	}
}

func ReadAllDiscoveredLogs(discovered []DBLogFileInfo) []LogFileContent {
	var results []LogFileContent
	for _, d := range discovered {
		if d.Path == "" {
			continue
		}
		if _, err := os.Stat(d.Path); os.IsNotExist(err) {
			continue
		}
		content := globalLogReader.ReadLogFile(d.Path, d.Engine, d.LogType)
		results = append(results, *content)
	}
	return results
}

func ReadAllLogFilesFromInstallations(installations []DBInstallation) []LogFileContent {
	var results []LogFileContent
	for _, inst := range installations {
		// Scan installation directory for log files
		logDirs := []string{
			inst.DataDir,
			inst.InstallDir,
			filepath.Join(inst.InstallDir, "log"),
			filepath.Join(inst.InstallDir, "logs"),
			filepath.Join(inst.InstallDir, "data"),
		}
		for _, dir := range logDirs {
			if dir == "" {
				continue
			}
			entries, err := os.ReadDir(dir)
			if err != nil {
				continue
			}
			for _, entry := range entries {
				if entry.IsDir() {
					continue
				}
				name := strings.ToLower(entry.Name())
				if strings.HasSuffix(name, ".log") || strings.HasSuffix(name, ".err") || strings.HasSuffix(name, ".txt") {
					fullPath := filepath.Join(dir, entry.Name())
					logType := detectLogTypeFromName(name, inst.Engine)
					content := globalLogReader.ReadLogFile(fullPath, inst.Engine, logType)
					results = append(results, *content)
				}
			}
		}
	}
	return results
}

func detectLogTypeFromName(name string, engine string) string {
	n := strings.ToLower(name)
	switch {
	case strings.Contains(n, "error"), strings.Contains(n, "err."):
		return "error_log"
	case strings.Contains(n, "slow"):
		return "slow_query"
	case strings.Contains(n, "general"):
		return "general_log"
	case strings.Contains(n, "binlog"), strings.HasPrefix(n, "binlog"):
		return "binary_log"
	case strings.Contains(n, "ib_logfile"):
		return "transaction_log"
	case strings.Contains(n, "postgresql"), strings.Contains(n, "pg_"):
		return "server_log"
	case strings.Contains(n, "mongod"), strings.Contains(n, "mongo"):
		return "server_log"
	case strings.Contains(n, "errorlog"), n == "errorlog":
		return "error_log"
	case strings.Contains(n, "wal"):
		return "wal_log"
	case strings.Contains(n, "trc"):
		return "trace_log"
	default:
		return "generic_log"
	}
}

// ReadBinlogContent attempts to extract text from MySQL binary logs
// by using mysqlbinlog if available, or falling back to hex dump
func ReadBinlogContent(binlogPath string) ([]string, error) {
	// Try mysqlbinlog first
	lines, err := execCommandCapture("mysqlbinlog", binlogPath)
	if err == nil {
		return parseBinlogOutput(lines), nil
	}

	// Fallback: try strings command to extract readable content
	lines, err = execCommandCapture("strings", binlogPath)
	if err == nil {
		return parseBinlogOutput(lines), nil
	}

	return nil, fmt.Errorf("cannot read binlog %s: %v", binlogPath, err)
}

func parseBinlogOutput(output string) []string {
	var queries []string
	scanner := bufio.NewScanner(strings.NewReader(output))
	for scanner.Scan() {
		line := scanner.Text()
		upper := strings.ToUpper(strings.TrimSpace(line))
		if strings.HasPrefix(upper, "INSERT") ||
			strings.HasPrefix(upper, "UPDATE") ||
			strings.HasPrefix(upper, "DELETE") ||
			strings.HasPrefix(upper, "CREATE") ||
			strings.HasPrefix(upper, "ALTER") ||
			strings.HasPrefix(upper, "DROP") ||
			strings.HasPrefix(upper, "TRUNCATE") {
			queries = append(queries, line)
		}
	}
	return queries
}

// ReadPostgresWAL reads WAL files in the pg_wal directory
func ReadPostgresWAL(dataDir string) ([]LogFileContent, error) {
	var results []LogFileContent

	walDirs := []string{
		filepath.Join(dataDir, "pg_wal"),
		filepath.Join(dataDir, "pg_xlog"),
		filepath.Join(dataDir, "wal"),
	}

	for _, walDir := range walDirs {
		entries, err := os.ReadDir(walDir)
		if err != nil {
			continue
		}
		for _, entry := range entries {
			if entry.IsDir() {
				continue
			}
			fullPath := filepath.Join(walDir, entry.Name())
			info, _ := os.Stat(fullPath)
			content := &LogFileContent{
				Path:         fullPath,
				Engine:       "postgresql",
				LogType:      "wal_log",
				SizeBytes:    info.Size(),
				LastModified: info.ModTime().UTC().Format(time.RFC3339),
				Parsed:       true,
			}

			// Try pg_waldump if available
			output, err := execCommandCapture("pg_waldump", fullPath)
			if err == nil {
				scanner := bufio.NewScanner(strings.NewReader(output))
				lineNum := 0
				for scanner.Scan() {
					lineNum++
					text := scanner.Text()
					parsed := LogLine{LineNumber: lineNum, Content: text}
					if strings.Contains(text, "INSERT") || strings.Contains(text, "UPDATE") ||
						strings.Contains(text, "DELETE") {
						parsed.Query = text
					}
					content.Lines = append(content.Lines, parsed)
				}
				content.TotalLines = lineNum
			} else {
				content.Lines = append(content.Lines, LogLine{
					LineNumber: 1,
					Content:    fmt.Sprintf("[WAL file %s - %d bytes - use pg_waldump for full parsing]", entry.Name(), info.Size()),
				})
				content.TotalLines = 1
			}
			results = append(results, *content)
		}
	}
	return results, nil
}

// ReadMongoOplog reads the MongoDB oplog.rs collection via the local connection
func ReadMongoOplog() ([]LogLine, error) {
	// MongoDB oplog is read via the native driver in db_mongodb.go
	// This function provides a fallback via file-based discovery
	return nil, fmt.Errorf("use the MongoDB driver for oplog reading")
}
