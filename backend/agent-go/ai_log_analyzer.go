package main

import (
	"fmt"
	"math"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

type AIAnalyzer struct {
	mu             sync.RWMutex
	rules          []AnalysisRule
	userProfiles   map[string]*UserProfile
	queryFingerpts map[string]*QueryFingerprint
	alerts         []SecurityAlert
	riskScore      float64
	totalAnalyzed  int
	lastAnalysis   time.Time
	findingHistory map[string]time.Time
}

type AnalysisRule struct {
	Name         string
	Category     string
	Severity     string
	Pattern      *regexp.Regexp
	Weight       float64
	cooldown     time.Duration
	Condition    func(e *LogEntry) bool
	Recommend    string
}

type LogEntry struct {
	Timestamp    time.Time
	User         string
	Host         string
	Query        string
	Database     string
	Engine       string
	Duration     time.Duration
	RowsAffected int64
	Error        string
	CommandType  string
	ThreadID     int64
	Raw          string
}

type AnalysisResult struct {
	Timestamp       time.Time            `json:"timestamp"`
	RiskScore       float64              `json:"riskScore"`
	TotalAnalyzed   int                  `json:"totalAnalyzed"`
	Findings        []Finding            `json:"findings"`
	UserProfiles    map[string]*UserProfile `json:"userProfiles"`
	Summary         string               `json:"summary"`
	Recommendations []string             `json:"recommendations"`
	CategoryScores  map[string]float64   `json:"categoryScores"`
	TopUsers        []UserRiskSummary    `json:"topUsers"`
	TopQueries      []QueryRiskSummary   `json:"topQueries"`
}

type Finding struct {
	ID             string    `json:"id"`
	Rule           string    `json:"rule"`
	Category       string    `json:"category"`
	Severity       string    `json:"severity"`
	Title          string    `json:"title"`
	Description    string    `json:"description"`
	Recommendation string    `json:"recommendation"`
	AffectedUser   string    `json:"affectedUser"`
	AffectedQuery  string    `json:"affectedQuery"`
	Timestamp      time.Time `json:"timestamp"`
	RiskScore      float64   `json:"riskScore"`
	Evidence       []string  `json:"evidence"`
}

type UserProfile struct {
	Username         string         `json:"username"`
	TotalQueries     int            `json:"totalQueries"`
	ErrorCount       int            `json:"errorCount"`
	ErrorRate        float64        `json:"errorRate"`
	AvgQueryLength   float64        `json:"avgQueryLength"`
	UniqueDatabases  map[string]bool `json:"-"`
	DatabaseList     []string       `json:"databases"`
	QueryFrequency   float64        `json:"queriesPerMinute"`
	RiskScore        float64        `json:"riskScore"`
	LastSeen         time.Time      `json:"lastSeen"`
	FirstSeen        time.Time      `json:"firstSeen"`
	QueryTypes       map[string]int `json:"queryTypes"`
	SuspiciousCount  int            `json:"suspiciousCount"`
	PeakHour         int            `json:"peakHour"`
	HourDistribution [24]int        `json:"hourDistribution"`
}

type QueryFingerprint struct {
	Normalized string
	Count      int
	TotalTime  time.Duration
	MaxTime    time.Duration
	MinTime    time.Duration
	Users      map[string]bool
	Databases  map[string]bool
	IsSlow     bool
	AvgTime    time.Duration
}

type SecurityAlert struct {
	ID        string    `json:"id"`
	Severity  string    `json:"severity"`
	Title     string    `json:"title"`
	Details   string    `json:"details"`
	User      string    `json:"user"`
	Timestamp time.Time `json:"timestamp"`
	Category  string    `json:"category"`
}

type UserRiskSummary struct {
	Username  string  `json:"username"`
	RiskScore float64 `json:"riskScore"`
	Queries   int     `json:"queries"`
	Errors    int     `json:"errors"`
}

type QueryRiskSummary struct {
	Query      string  `json:"query"`
	Count      int     `json:"count"`
	AvgTimeMs  float64 `json:"avgTimeMs"`
	RiskScore  float64 `json:"riskScore"`
}

var globalAIAnalyzer *AIAnalyzer
var aiAnalyzerOnce sync.Once

func GetAIAnalyzer() *AIAnalyzer {
	aiAnalyzerOnce.Do(func() {
		globalAIAnalyzer = &AIAnalyzer{
			userProfiles:   make(map[string]*UserProfile),
			queryFingerpts: make(map[string]*QueryFingerprint),
			alerts:         make([]SecurityAlert, 0),
			findingHistory: make(map[string]time.Time),
		}
		globalAIAnalyzer.loadRules()
	})
	return globalAIAnalyzer
}

func (a *AIAnalyzer) loadRules() {
	a.rules = []AnalysisRule{
		{
			Name:     "sql_injection_union",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)(\bUNION\b\s+\bSELECT\b|UNION\s+ALL\s+SELECT)`),
			Weight:   25,
			cooldown: 5 * time.Minute,
			Condition: func(e *LogEntry) bool {
				return true
			},
			Recommend: "Verificar que la consulta proviene de código parametrizado. Implementar prepared statements.",
		},
		{
			Name:     "sql_injection_or_1eq1",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)(\bOR\b\s+['""]?\d+['""]?\s*=\s*['""]?\d+['""]?|\bOR\b\s+['""]?\w+['""]?\s*=\s*['""]?\w+['""]?|\bOR\b\s+1\s*=\s*1|\bOR\b\s+true)`),
			Weight:   20,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "sql_injection_comment",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(--\s*$|/\*[\s\S]*\*/|;\s*--|'\s*OR\s+'|"\s*OR\s+")`),
			Weight:   15,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "sql_injection_stacked",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i);\s*(DROP|DELETE|INSERT|UPDATE|ALTER|CREATE|TRUNCATE|GRANT|EXEC|EXECUTE)\b`),
			Weight:   30,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "brute_force_login",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)(Access\s+denied|Invalid\s+user|authentication\s+failed|login\s+failed)`),
			Weight:   15,
			cooldown: 10 * time.Minute,
		},
		{
			Name:     "privilege_escalation_grant",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\bGRANT\b\s+(ALL\s+PRIVILEGES|SUPER|CREATE\s+USER|ALTER\s+USER|DROP\s+USER)`),
			Weight:   25,
			cooldown: 15 * time.Minute,
		},
		{
			Name:     "privilege_escalation_password",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\b(SET\s+PASSWORD|ALTER\s+USER\s+.*IDENTIFIED\s+BY|CREATE\s+USER|DROP\s+USER)`),
			Weight:   20,
			cooldown: 15 * time.Minute,
		},
		{
			Name:     "data_exfiltration_into_outfile",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\bINTO\s+(OUTFILE|DUMPFILE)\b`),
			Weight:   30,
			cooldown: 30 * time.Minute,
		},
		{
			Name:     "data_exfiltration_load_file",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\bLOAD_FILE\s*\(`),
			Weight:   20,
			cooldown: 15 * time.Minute,
		},
		{
			Name:     "backdoor_exec",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\b(xp_cmdshell|xp_regread|xp_dirtree|sp_OACreate|EXEC\s+master|EXECUTE\s+AS\s+LOGIN)`),
			Weight:   30,
			cooldown: 30 * time.Minute,
		},
		{
			Name:     "backdoor_stored_proc",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\bCREATE\s+(PROCEDURE|FUNCTION|TRIGGER)\b.*\b(EXEC|EXECUTE|xp_|sp_|shell|cmd|system|passthru)\b`),
			Weight:   20,
			cooldown: 30 * time.Minute,
		},
		{
			Name:     "mass_data_export_select_star",
			Category: "security",
			Severity: "medium",
			Pattern:  regexp.MustCompile(`(?i)\bSELECT\s+\*\s+FROM\b`),
			Weight:   5,
			cooldown: 1 * time.Minute,
			Condition: func(e *LogEntry) bool {
				return e.RowsAffected > 10000 || e.Duration > 5*time.Second
			},
		},
		{
			Name:     "drop_table",
			Category: "security",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\b(DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE\s+TABLE)\b`),
			Weight:   25,
			cooldown: 30 * time.Minute,
		},
		{
			Name:     "alter_table",
			Category: "schema_change",
			Severity: "medium",
			Pattern:  regexp.MustCompile(`(?i)\bALTER\s+TABLE\b`),
			Weight:   8,
			cooldown: 10 * time.Minute,
		},
		{
			Name:     "create_database",
			Category: "schema_change",
			Severity: "low",
			Pattern:  regexp.MustCompile(`(?i)\bCREATE\s+(DATABASE|TABLE)\b`),
			Weight:   3,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "slow_query_5s",
			Category: "performance",
			Severity: "high",
			Pattern:  nil,
			Weight:   15,
			cooldown: 2 * time.Minute,
			Condition: func(e *LogEntry) bool {
				return e.Duration > 5*time.Second
			},
		},
		{
			Name:     "slow_query_1s",
			Category: "performance",
			Severity: "medium",
			Pattern:  nil,
			Weight:   5,
			cooldown: 1 * time.Minute,
			Condition: func(e *LogEntry) bool {
				return e.Duration > 1*time.Second && e.Duration <= 5*time.Second
			},
		},
		{
			Name:     "full_table_scan",
			Category: "performance",
			Severity: "medium",
			Pattern:  regexp.MustCompile(`(?i)\bSELECT\b.*\bFROM\b`),
			Weight:   5,
			cooldown: 5 * time.Minute,
			Condition: func(e *LogEntry) bool {
				lower := strings.ToLower(e.Query)
				return strings.Contains(lower, "select") &&
					strings.Contains(lower, "from") &&
					!strings.Contains(lower, "limit") &&
					e.Duration > 2*time.Second
			},
		},
		{
			Name:     "pii_access_rut",
			Category: "compliance",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\b(SELECT|INSERT|UPDATE)\b.*\b(rut|dni|cedula|passport|id_number|document_number|identificacion)\b`),
			Weight:   10,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "pii_access_health",
			Category: "compliance",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\b(SELECT|INSERT|UPDATE)\b.*\b(salud|diagnostico|tratamiento|medicamento|enfermedad|health|diagnosis|medical|hospital|clinica)\b`),
			Weight:   15,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "pii_access_financial",
			Category: "compliance",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\b(SELECT|INSERT|UPDATE)\b.*\b(credit_card|tarjeta|cuenta_bancaria|rut_banco|bank_account|salary|salario|sueldo|ingreso|credit)\b`),
			Weight:   12,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "pii_access_biometric",
			Category: "compliance",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)\b(SELECT|INSERT|UPDATE)\b.*\b(huella|biometrico|facial|iris|fingerprint|biometric|face_data|retina)\b`),
			Weight:   15,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "pii_access_location",
			Category: "compliance",
			Severity: "medium",
			Pattern:  regexp.MustCompile(`(?i)\b(SELECT|INSERT|UPDATE)\b.*\b(ubicacion|location|gps|coordenadas|latitude|longitude|latitud|longitud|ip_address|direccion_ip)\b`),
			Weight:   8,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "mass_select_no_where",
			Category: "compliance",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\bSELECT\b.*\bFROM\b`),
			Weight:   10,
			cooldown: 5 * time.Minute,
			Condition: func(e *LogEntry) bool {
				lower := strings.ToLower(e.Query)
				hasWhere := strings.Contains(lower, "where")
				hasLimit := strings.Contains(lower, "limit")
				isSelect := strings.HasPrefix(strings.TrimSpace(lower), "select")
				return isSelect && !hasWhere && !hasLimit && e.RowsAffected > 1000
			},
		},
		{
			Name:     "off_hours_access",
			Category: "anomaly",
			Severity: "medium",
			Pattern:  nil,
			Weight:   7,
			cooldown: 30 * time.Minute,
			Condition: func(e *LogEntry) bool {
				hour := e.Timestamp.Hour()
				return hour < 6 || hour > 22
			},
		},
		{
			Name:     "ddl_from_app_user",
			Category: "anomaly",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\b(DROP|ALTER|CREATE|TRUNCATE)\s+(TABLE|DATABASE|INDEX)\b`),
			Weight:   12,
			cooldown: 10 * time.Minute,
			Condition: func(e *LogEntry) bool {
				user := strings.ToLower(e.User)
				return user != "root" && user != "admin" && user != "sa" &&
					user != "mariadb.sys" && !strings.HasPrefix(user, "mysql.")
			},
		},
		{
			Name:     "connection_flood",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)(Connect|connection\s+refused|too\s+many\s+connections)`),
			Weight:   10,
			cooldown: 10 * time.Minute,
		},
		{
			Name:     "wildcard_delete",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\bDELETE\s+FROM\b`),
			Weight:   20,
			cooldown: 15 * time.Minute,
			Condition: func(e *LogEntry) bool {
				lower := strings.ToLower(e.Query)
				return strings.Contains(lower, "delete") &&
					!strings.Contains(lower, " where ")
			},
		},
		{
			Name:     "wildcard_update",
			Category: "security",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)\bUPDATE\b.*\bSET\b`),
			Weight:   15,
			cooldown: 10 * time.Minute,
			Condition: func(e *LogEntry) bool {
				lower := strings.ToLower(e.Query)
				return strings.Contains(lower, "update") &&
					strings.Contains(lower, " set ") &&
					!strings.Contains(lower, " where ")
			},
		},
		{
			Name:     "temp_table_overuse",
			Category: "performance",
			Severity: "low",
			Pattern:  regexp.MustCompile(`(?i)\bCREATE\s+TEMPORARY\s+TABLE\b`),
			Weight:   3,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "lock_wait_timeout",
			Category: "performance",
			Severity: "high",
			Pattern:  regexp.MustCompile(`(?i)(lock\s+wait\s+timeout|deadlock|InnoDB:\s+Lock\s+wait\s+timeout)`),
			Weight:   15,
			cooldown: 5 * time.Minute,
		},
		{
			Name:     "replication_error",
			Category: "availability",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)(replication\s+error|slave\s+(SQL|IO)\s+thread|replication\s+stopped|semi-sync)`),
			Weight:   20,
			cooldown: 15 * time.Minute,
		},
		{
			Name:     "disk_full",
			Category: "availability",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)(disk\s+full|no\s+space|table\s+is\s+full|out\s+of\s+space|quota\s+exceeded)`),
			Weight:   25,
			cooldown: 30 * time.Minute,
		},
		{
			Name:     "corruption_detected",
			Category: "availability",
			Severity: "critical",
			Pattern:  regexp.MustCompile(`(?i)(corrupt|crashed|table.*is\s+marked\s+as\s+crashed|check\s+table|repair\s+table)`),
			Weight:   20,
			cooldown: 30 * time.Minute,
		},
	}
}

func (a *AIAnalyzer) AnalyzeLogBatch(entries []map[string]interface{}) *AnalysisResult {
	start := time.Now()

	logEntries := make([]*LogEntry, 0, len(entries))
	for _, raw := range entries {
		entry := a.parseLogEntry(raw)
		if entry != nil {
			logEntries = append(logEntries, entry)
		}
	}

	a.mu.Lock()
	for _, entry := range logEntries {
		a.updateUserProfile(entry)
		a.updateQueryFingerprint(entry)
	}
	a.mu.Unlock()

	var findings []Finding
	for _, entry := range logEntries {
		newFindings := a.analyzeEntry(entry)
		findings = append(findings, newFindings...)
	}

	a.mu.Lock()
	a.totalAnalyzed += len(logEntries)
	a.lastAnalysis = time.Now()
	a.mu.Unlock()

	result := a.buildResult(findings, logEntries, start)
	return result
}

func (a *AIAnalyzer) AnalyzePerformanceSchema(entries []map[string]interface{}) *AnalysisResult {
	var findings []Finding
	for _, entry := range entries {
		digest, _ := entry["digest_text"].(string)
		count, _ := entry["count"].(int64)
		avgTimeMs, _ := entry["avg_time_ms"].(float64)
		sumExamined, _ := entry["sum_rows_examined"].(float64)
		sumSent, _ := entry["sum_rows_sent"].(float64)

		if avgTimeMs > 5000 {
			findings = append(findings, Finding{
				ID:             fmt.Sprintf("perf_digest_slow_%d", len(findings)),
				Rule:           "slow_digest_query",
				Category:       "performance",
				Severity:       "high",
				Title:          fmt.Sprintf("Query digest lenta: %.1fs promedio", avgTimeMs/1000),
				Description:    fmt.Sprintf("La query '%s' tiene un tiempo promedio de %.1fs con %d ejecuciones", truncate(digest, 200), avgTimeMs/1000, count),
				Recommendation: "Optimizar esta query con indices o reestructurar la consulta. Revisar execution plan con EXPLAIN.",
				AffectedQuery:  truncate(digest, 500),
				Timestamp:      time.Now(),
				RiskScore:      math.Min(avgTimeMs/100, 30),
			})
		}

		if count > 10000 && avgTimeMs > 100 {
			findings = append(findings, Finding{
				ID:             fmt.Sprintf("perf_digest_repetitive_%d", len(findings)),
				Rule:           "repetitive_expensive_query",
				Category:       "performance",
				Severity:       "medium",
				Title:          fmt.Sprintf("Query ejecutada %d veces con %.1fs promedio", count, avgTimeMs/1000),
				Description:    fmt.Sprintf("La query '%s' se ejecuta frecuentemente (%d veces) con un costo promedio alto", truncate(digest, 200), count),
				Recommendation: "Considerar cachear los resultados o agregar indices para reducir el costo por ejecución.",
				AffectedQuery:  truncate(digest, 500),
				Timestamp:      time.Now(),
				RiskScore:      math.Min(float64(count)/1000, 15),
			})
		}

		if sumExamined > 0 && sumSent > 0 {
			ratio := sumExamined / sumSent
			if ratio > 1000 && count > 100 {
				findings = append(findings, Finding{
					ID:             fmt.Sprintf("perf_digest_scan_ratio_%d", len(findings)),
					Rule:           "full_table_scan_ratio",
					Category:       "performance",
					Severity:       "medium",
					Title:          fmt.Sprintf("Full table scan detectado: ratio %.0fx", ratio),
					Description:    fmt.Sprintf("La query '%s' examina %.0fx más filas de las que retorna (%.0f examinadas vs %.0f retornadas)", truncate(digest, 200), ratio, sumExamined, sumSent),
					Recommendation: "Agregar índices en las columnas de WHERE/JOIN para reducir el escaneo completo de tablas.",
					AffectedQuery:  truncate(digest, 500),
					Timestamp:      time.Now(),
					RiskScore:      math.Min(ratio/100, 15),
				})
			}
		}
	}

	riskScore := 0.0
	for _, f := range findings {
		riskScore += f.RiskScore
	}
	riskScore = math.Min(riskScore, 100)

	cats := make(map[string]float64)
	for _, f := range findings {
		cats[f.Category] += f.RiskScore
	}

	return &AnalysisResult{
		Timestamp:       time.Now(),
		RiskScore:       riskScore,
		TotalAnalyzed:   len(entries),
		Findings:        findings,
		UserProfiles:    make(map[string]*UserProfile),
		Summary:         a.generateSummary(findings, 0),
		Recommendations: a.extractRecommendations(findings),
		CategoryScores:  cats,
	}
}

func (a *AIAnalyzer) parseLogEntry(raw map[string]interface{}) *LogEntry {
	entry := &LogEntry{
		Timestamp: time.Now(),
	}

	if t, ok := raw["event_time"].(time.Time); ok {
		entry.Timestamp = t
	} else if s, ok := raw["event_time"].(string); ok {
		if t, err := time.Parse("2006-01-02 15:04:05", s); err == nil {
			entry.Timestamp = t
		}
	}

	entry.User, _ = raw["user"].(string)
	entry.Host, _ = raw["host"].(string)
	entry.Query, _ = raw["argument"].(string)
	entry.CommandType, _ = raw["command_type"].(string)

	if entry.User == "" {
		entry.User = "unknown"
	}
	if entry.Query == "" {
		entry.Query, _ = raw["sql_text"].(string)
	}
	if entry.Query == "" {
		entry.Query, _ = raw["query"].(string)
	}
	entry.Database, _ = raw["database"].(string)

	if dur, ok := raw["duration"].(time.Duration); ok {
		entry.Duration = dur
	} else if ms, ok := raw["duration_ms"].(float64); ok {
		entry.Duration = time.Duration(ms * float64(time.Millisecond))
	}

	if entry.Query == "" && entry.CommandType == "" {
		return nil
	}

	return entry
}

func (a *AIAnalyzer) analyzeEntry(entry *LogEntry) []Finding {
	var findings []Finding

	for _, rule := range a.rules {
		matched := false

		if rule.Pattern != nil {
			matched = rule.Pattern.MatchString(entry.Query)
		}

		if !matched && rule.Condition != nil {
			matched = rule.Condition(entry)
		}

		if !matched {
			continue
		}

		findingKey := fmt.Sprintf("%s:%s", rule.Name, entry.User)
		a.mu.Lock()
		if lastSeen, ok := a.findingHistory[findingKey]; ok {
			if time.Since(lastSeen) < rule.cooldown {
				a.mu.Unlock()
				continue
			}
		}
		a.findingHistory[findingKey] = time.Now()
		a.mu.Unlock()

		title := a.generateTitle(rule, entry)
		desc := a.generateDescription(rule, entry)

		finding := Finding{
			ID:             fmt.Sprintf("f_%s_%d", rule.Name, time.Now().UnixNano()),
			Rule:           rule.Name,
			Category:       rule.Category,
			Severity:       rule.Severity,
			Title:          title,
			Description:    desc,
			Recommendation: rule.Recommend,
			AffectedUser:   entry.User,
			AffectedQuery:  truncate(entry.Query, 500),
			Timestamp:      entry.Timestamp,
			RiskScore:      rule.Weight,
			Evidence: []string{
				fmt.Sprintf("Usuario: %s@%s", entry.User, entry.Host),
				fmt.Sprintf("Query: %s", truncate(entry.Query, 300)),
				fmt.Sprintf("Tiempo: %v", entry.Duration),
				fmt.Sprintf("Timestamp: %s", entry.Timestamp.Format(time.RFC3339)),
			},
		}

		findings = append(findings, finding)
	}

	return findings
}

func (a *AIAnalyzer) generateTitle(rule AnalysisRule, entry *LogEntry) string {
	switch rule.Name {
	case "sql_injection_union":
		return fmt.Sprintf("Posible SQL Injection (UNION SELECT) por usuario '%s'", entry.User)
	case "sql_injection_or_1eq1":
		return fmt.Sprintf("Posible SQL Injection (OR bypass) por usuario '%s'", entry.User)
	case "sql_injection_comment":
		return fmt.Sprintf("SQL Injection con comentarios/bypass detectada por '%s'", entry.User)
	case "sql_injection_stacked":
		return fmt.Sprintf("SQL Injection con queries apiladas por '%s'", entry.User)
	case "brute_force_login":
		return fmt.Sprintf("Posible brute-force de acceso desde %s", entry.Host)
	case "privilege_escalation_grant":
		return fmt.Sprintf("Escalación de privilegios (GRANT) por '%s'", entry.User)
	case "privilege_escalation_password":
		return fmt.Sprintf("Cambio de contraseña/usuario detectado por '%s'", entry.User)
	case "data_exfiltration_into_outfile":
		return fmt.Sprintf("Exfiltración de datos a archivo por '%s'", entry.User)
	case "data_exfiltration_load_file":
		return fmt.Sprintf("Lectura de archivo del sistema por '%s'", entry.User)
	case "backdoor_exec":
		return fmt.Sprintf("Ejecución remota detectada (backdoor) por '%s'", entry.User)
	case "backdoor_stored_proc":
		return fmt.Sprintf("Stored procedure sospechosa creada por '%s'", entry.User)
	case "drop_table":
		return fmt.Sprintf("DROP/TRUNCATE ejecutado por '%s'", entry.User)
	case "wildcard_delete":
		return fmt.Sprintf("DELETE masivo sin WHERE por '%s'", entry.User)
	case "wildcard_update":
		return fmt.Sprintf("UPDATE masivo sin WHERE por '%s'", entry.User)
	case "slow_query_5s":
		return fmt.Sprintf("Query muy lenta (%.1fs) por '%s'", entry.Duration.Seconds(), entry.User)
	case "slow_query_1s":
		return fmt.Sprintf("Query lenta (%.1fs) por '%s'", entry.Duration.Seconds(), entry.User)
	case "full_table_scan":
		return fmt.Sprintf("Posible full table scan por '%s'", entry.User)
	case "pii_access_rut":
		return fmt.Sprintf("Acceso a datos de identificación (RUT/DNI) por '%s'", entry.User)
	case "pii_access_health":
		return fmt.Sprintf("Acceso a datos de salud por '%s'", entry.User)
	case "pii_access_financial":
		return fmt.Sprintf("Acceso a datos financieros por '%s'", entry.User)
	case "pii_access_biometric":
		return fmt.Sprintf("Acceso a datos biométricos por '%s'", entry.User)
	case "mass_select_no_where":
		return fmt.Sprintf("SELECT masivo sin filtros por '%s' (%d filas)", entry.User, entry.RowsAffected)
	case "off_hours_access":
		return fmt.Sprintf("Acceso fuera de horario laboral (%02d:00) por '%s'", entry.Timestamp.Hour(), entry.User)
	case "ddl_from_app_user":
		return fmt.Sprintf("DDL ejecutado por usuario no-DBA '%s'", entry.User)
	case "lock_wait_timeout":
		return "Lock wait timeout o deadlock detectado"
	case "replication_error":
		return "Error de replicación detectado"
	case "disk_full":
		return "Disco lleno o tabla sin espacio"
	case "corruption_detected":
		return "Corrupción de datos detectada"
	case "alter_table":
		return fmt.Sprintf("ALTER TABLE ejecutado por '%s'", entry.User)
	case "connection_flood":
		return "Posible flood de conexiones detectado"
	default:
		return fmt.Sprintf("%s detectado por '%s'", rule.Name, entry.User)
	}
}

func (a *AIAnalyzer) generateDescription(rule AnalysisRule, entry *LogEntry) string {
	querySnippet := truncate(entry.Query, 200)

	switch {
	case strings.Contains(rule.Name, "sql_injection"):
		return fmt.Sprintf(
			"Se detectó un patrón de SQL Injection en la consulta del usuario '%s' desde %s. "+
				"La consulta contiene patrones típicos de inyección SQL que podrían permitir "+
				"acceso no autorizado a la base de datos.\n\nQuery: %s",
			entry.User, entry.Host, querySnippet)

	case strings.Contains(rule.Name, "brute_force"):
		return fmt.Sprintf(
			"Se detectaron múltiples intentos de acceso fallidos desde %s. "+
				"Esto podría indicar un intento de brute-force contra la base de datos.",
			entry.Host)

	case strings.Contains(rule.Name, "privilege"):
		return fmt.Sprintf(
			"El usuario '%s' ejecutó una operación de cambio de privilegios o contraseñas. "+
				"Esto debe ser verificado para asegurar que no es acceso no autorizado.",
			entry.User)

	case strings.Contains(rule.Name, "exfiltration"):
		return fmt.Sprintf(
			"El usuario '%s' intentó exportar datos a un archivo del sistema o leer archivos del servidor. "+
				"Esto podría indicar exfiltración de datos.\n\nQuery: %s",
			entry.User, querySnippet)

	case strings.Contains(rule.Name, "backdoor"):
		return fmt.Sprintf(
			"Se detectó la creación o uso de procedimientos/funciones con capacidad de ejecución remota. "+
				"Esto podría indicar una backdoor instalada por '%s'.\n\nQuery: %s",
			entry.User, querySnippet)

	case strings.Contains(rule.Name, "slow"):
		return fmt.Sprintf(
			"La consulta tardó %.1fs en ejecutarse. Esto impacta el rendimiento del sistema y "+
				"podría indicar queries no optimizadas o falta de índices.\n\nQuery: %s",
			entry.Duration.Seconds(), querySnippet)

	case strings.Contains(rule.Name, "pii"):
		categoria := "datos personales"
		if strings.Contains(rule.Name, "health") {
			categoria = "datos de salud (sensibles según Ley 21.719)"
		} else if strings.Contains(rule.Name, "biometric") {
			categoria = "datos biométricos (sensibles según Ley 21.719)"
		} else if strings.Contains(rule.Name, "financial") {
			categoria = "datos financieros"
		} else if strings.Contains(rule.Name, "rut") {
			categoria = "identificación personal (RUT/DNI)"
		}
		return fmt.Sprintf(
			"El usuario '%s' accedió a %s. Según la Ley 21.719 de Protección de Datos Personales, "+
				"este acceso debe estar debidamente justificado y registrado.\n\nQuery: %s",
			entry.User, categoria, querySnippet)

	case strings.Contains(rule.Name, "mass_select"):
		return fmt.Sprintf(
			"El usuario '%s' ejecutó un SELECT sin cláusula WHERE que retornó %d filas. "+
				"Esto podría resultar en exposición masiva de datos personales.\n\nQuery: %s",
			entry.User, entry.RowsAffected, querySnippet)

	case strings.Contains(rule.Name, "wildcard"):
		tipo := "DELETE"
		if strings.Contains(rule.Name, "update") {
			tipo = "UPDATE"
		}
		return fmt.Sprintf(
			"El usuario '%s' ejecutó un %s sin cláusula WHERE. "+
				"Esto afectará TODAS las filas de la tabla, lo cual podría causar pérdida masiva de datos.\n\nQuery: %s",
			entry.User, tipo, querySnippet)

	case strings.Contains(rule.Name, "off_hours"):
		return fmt.Sprintf(
			"El usuario '%s' realizó una consulta a las %02d:%02d, fuera del horario laboral habitual. "+
				"Esto podría indicar acceso no autorizado.\n\nQuery: %s",
			entry.User, entry.Timestamp.Hour(), entry.Timestamp.Minute(), querySnippet)

	default:
		return fmt.Sprintf(
			"Se detectó la regla '%s' en la consulta del usuario '%s'.\n\nQuery: %s",
			rule.Name, entry.User, querySnippet)
	}
}

func (a *AIAnalyzer) updateUserProfile(entry *LogEntry) {
	profile, ok := a.userProfiles[entry.User]
	if !ok {
		profile = &UserProfile{
			Username:        entry.User,
			UniqueDatabases: make(map[string]bool),
			QueryTypes:      make(map[string]int),
			FirstSeen:       entry.Timestamp,
		}
		a.userProfiles[entry.User] = profile
	}

	profile.TotalQueries++
	profile.LastSeen = entry.Timestamp

	if entry.Error != "" {
		profile.ErrorCount++
	}

	profile.AvgQueryLength = ((profile.AvgQueryLength * float64(profile.TotalQueries-1)) + float64(len(entry.Query))) / float64(profile.TotalQueries)

	if entry.Database != "" {
		profile.UniqueDatabases[entry.Database] = true
	}

	cmdType := strings.ToUpper(strings.TrimSpace(entry.CommandType))
	if cmdType == "" {
		cmdType = aiClassifyQuery(entry.Query)
	}
	profile.QueryTypes[cmdType]++

	hour := entry.Timestamp.Hour()
	profile.HourDistribution[hour]++

	if profile.TotalQueries > 10 {
		elapsed := entry.Timestamp.Sub(profile.FirstSeen).Minutes()
		if elapsed > 0 {
			profile.QueryFrequency = float64(profile.TotalQueries) / elapsed
		}
	}

	profile.ErrorRate = float64(profile.ErrorCount) / float64(profile.TotalQueries) * 100

	if entry.Duration > 5*time.Second {
		profile.SuspiciousCount++
	}

	profile.RiskScore = a.calculateUserProfileRisk(profile)
}

func (a *AIAnalyzer) calculateUserProfileRisk(p *UserProfile) float64 {
	risk := 0.0

	if p.ErrorRate > 30 {
		risk += 15
	} else if p.ErrorRate > 15 {
		risk += 8
	}

	if p.QueryFrequency > 100 {
		risk += 15
	} else if p.QueryFrequency > 50 {
		risk += 8
	}

	if p.SuspiciousCount > 10 {
		risk += 10
	}

	if len(p.UniqueDatabases) > 5 {
		risk += 5
	}

	offHours := 0
	for h := 0; h < 6; h++ {
		offHours += p.HourDistribution[h]
	}
	for h := 22; h < 24; h++ {
		offHours += p.HourDistribution[h]
	}
	if p.TotalQueries > 0 {
		offHoursRatio := float64(offHours) / float64(p.TotalQueries)
		if offHoursRatio > 0.5 {
			risk += 15
		} else if offHoursRatio > 0.2 {
			risk += 8
		}
	}

	ddlCount := p.QueryTypes["DROP"] + p.QueryTypes["ALTER"] + p.QueryTypes["CREATE"] + p.QueryTypes["TRUNCATE"]
	if ddlCount > 5 {
		risk += 10
	}

	return math.Min(risk, 100)
}

func (a *AIAnalyzer) updateQueryFingerprint(entry *LogEntry) {
	fp := aiNormalizeQuery(entry.Query)
	f, ok := a.queryFingerpts[fp]
	if !ok {
		f = &QueryFingerprint{
			Normalized: fp,
			Users:      make(map[string]bool),
			Databases:  make(map[string]bool),
			MinTime:    entry.Duration,
		}
		a.queryFingerpts[fp] = f
	}

	f.Count++
	f.TotalTime += entry.Duration
	if entry.Duration > f.MaxTime {
		f.MaxTime = entry.Duration
	}
	if entry.Duration < f.MinTime {
		f.MinTime = entry.Duration
	}
	f.AvgTime = f.TotalTime / time.Duration(f.Count)
	f.Users[entry.User] = true
	if entry.Database != "" {
		f.Databases[entry.Database] = true
	}
	f.IsSlow = f.AvgTime > 1*time.Second
}

func (a *AIAnalyzer) buildResult(findings []Finding, entries []*LogEntry, start time.Time) *AnalysisResult {
	riskScore := 0.0
	for _, f := range findings {
		severityWeight := 1.0
		switch f.Severity {
		case "critical":
			severityWeight = 1.5
		case "high":
			severityWeight = 1.0
		case "medium":
			severityWeight = 0.6
		case "low":
			severityWeight = 0.3
		}
		riskScore += f.RiskScore * severityWeight
	}

	if len(entries) > 0 {
		avgRiskPerEntry := riskScore / float64(len(entries))
		if avgRiskPerEntry > 5 {
			riskScore *= 1.2
		}
	}

	riskScore = math.Min(riskScore, 100)

	cats := make(map[string]float64)
	for _, f := range findings {
		cats[f.Category] += f.RiskScore
	}

	a.mu.RLock()
	topUsers := make([]UserRiskSummary, 0)
	for _, p := range a.userProfiles {
		topUsers = append(topUsers, UserRiskSummary{
			Username:  p.Username,
			RiskScore: p.RiskScore,
			Queries:   p.TotalQueries,
			Errors:    p.ErrorCount,
		})
	}
	a.mu.RUnlock()

	sort.Slice(topUsers, func(i, j int) bool {
		return topUsers[i].RiskScore > topUsers[j].RiskScore
	})
	if len(topUsers) > 10 {
		topUsers = topUsers[:10]
	}

	a.mu.RLock()
	topQueries := make([]QueryRiskSummary, 0)
	for _, fp := range a.queryFingerpts {
		if fp.IsSlow || fp.Count > 100 {
			qrisk := 0.0
			if fp.IsSlow {
				qrisk += fp.AvgTime.Seconds() * 2
			}
			if fp.Count > 1000 {
				qrisk += 10
			} else if fp.Count > 100 {
				qrisk += 5
			}
			topQueries = append(topQueries, QueryRiskSummary{
				Query:     truncate(fp.Normalized, 200),
				Count:     fp.Count,
				AvgTimeMs: float64(fp.AvgTime.Microseconds()) / 1000,
				RiskScore: math.Min(qrisk, 30),
			})
		}
	}
	a.mu.RUnlock()

	sort.Slice(topQueries, func(i, j int) bool {
		return topQueries[i].RiskScore > topQueries[j].RiskScore
	})
	if len(topQueries) > 10 {
		topQueries = topQueries[:10]
	}

	a.mu.RLock()
	profiles := make(map[string]*UserProfile, len(a.userProfiles))
	for k, v := range a.userProfiles {
		dbList := make([]string, 0, len(v.UniqueDatabases))
		for db := range v.UniqueDatabases {
			dbList = append(dbList, db)
		}
		v.DatabaseList = dbList
		profiles[k] = v
	}
	a.mu.RUnlock()

	elapsed := time.Since(start)
	summary := a.generateFullSummary(findings, entries, riskScore, elapsed)
	recommendations := a.extractRecommendations(findings)

	return &AnalysisResult{
		Timestamp:       time.Now(),
		RiskScore:       riskScore,
		TotalAnalyzed:   len(entries),
		Findings:        findings,
		UserProfiles:    profiles,
		Summary:         summary,
		Recommendations: recommendations,
		CategoryScores:  cats,
		TopUsers:        topUsers,
		TopQueries:      topQueries,
	}
}

func (a *AIAnalyzer) generateFullSummary(findings []Finding, entries []*LogEntry, riskScore float64, elapsed time.Duration) string {
	var sb strings.Builder

	sb.WriteString("=== ANÁLISIS INTELIGENTE DE LOGS DE BASE DE DATOS ===\n\n")

	riskLabel := "BAJO"
	if riskScore > 70 {
		riskLabel = "CRÍTICO"
	} else if riskScore > 50 {
		riskLabel = "ALTO"
	} else if riskScore > 30 {
		riskLabel = "MEDIO"
	} else if riskScore > 15 {
		riskLabel = "MODERADO"
	}
	sb.WriteString(fmt.Sprintf("Nivel de Riesgo Global: %.1f/100 (%s)\n", riskScore, riskLabel))
	sb.WriteString(fmt.Sprintf("Queries analizadas: %d\n", len(entries)))
	sb.WriteString(fmt.Sprintf("Hallazgos detectados: %d\n", len(findings)))
	sb.WriteString(fmt.Sprintf("Tiempo de análisis: %v\n\n", elapsed.Round(time.Millisecond)))

	criticalCount := 0
	highCount := 0
	mediumCount := 0
	for _, f := range findings {
		switch f.Severity {
		case "critical":
			criticalCount++
		case "high":
			highCount++
		case "medium":
			mediumCount++
		}
	}

	if criticalCount > 0 {
		sb.WriteString(fmt.Sprintf("⚠ CRÍTICO: %d amenazas de seguridad crítica detectadas\n", criticalCount))
	}
	if highCount > 0 {
		sb.WriteString(fmt.Sprintf("⚠ ALTO: %d problemas de alto riesgo detectados\n", highCount))
	}
	if mediumCount > 0 {
		sb.WriteString(fmt.Sprintf("ℹ MEDIO: %d problemas de riesgo medio detectados\n", mediumCount))
	}

	if len(findings) == 0 {
		sb.WriteString("\n✓ No se detectaron amenazas ni anomalías significativas en los logs analizados.\n")
	}

	a.mu.RLock()
	userCount := len(a.userProfiles)
	a.mu.RUnlock()
	sb.WriteString(fmt.Sprintf("\nUsuarios activos: %d\n", userCount))

	return sb.String()
}

func (a *AIAnalyzer) generateSummary(findings []Finding, _ int) string {
	if len(findings) == 0 {
		return "No se detectaron anomalías en el análisis."
	}

	var secs, perfs, comps, anom [][]string
	for _, f := range findings {
		switch f.Category {
		case "security":
			secs = append(secs, []string{f.Title, f.Severity})
		case "performance":
			perfs = append(perfs, []string{f.Title, f.Severity})
		case "compliance":
			comps = append(comps, []string{f.Title, f.Severity})
		case "anomaly":
			anom = append(anom, []string{f.Title, f.Severity})
		}
	}

	var sb strings.Builder
	sb.WriteString(fmt.Sprintf("Análisis completado: %d hallazgos encontrados.\n", len(findings)))

	if len(secs) > 0 {
		sb.WriteString(fmt.Sprintf("\nSeguridad (%d):\n", len(secs)))
		for _, s := range secs {
			sb.WriteString(fmt.Sprintf("  [%s] %s\n", strings.ToUpper(s[1]), s[0]))
		}
	}
	if len(perfs) > 0 {
		sb.WriteString(fmt.Sprintf("\nRendimiento (%d):\n", len(perfs)))
		for _, p := range perfs {
			sb.WriteString(fmt.Sprintf("  [%s] %s\n", strings.ToUpper(p[1]), p[0]))
		}
	}
	if len(comps) > 0 {
		sb.WriteString(fmt.Sprintf("\nCumplimiento (%d):\n", len(comps)))
		for _, c := range comps {
			sb.WriteString(fmt.Sprintf("  [%s] %s\n", strings.ToUpper(c[1]), c[0]))
		}
	}
	if len(anom) > 0 {
		sb.WriteString(fmt.Sprintf("\nAnomalías (%d):\n", len(anom)))
		for _, n := range anom {
			sb.WriteString(fmt.Sprintf("  [%s] %s\n", strings.ToUpper(n[1]), n[0]))
		}
	}

	return sb.String()
}

func (a *AIAnalyzer) extractRecommendations(findings []Finding) []string {
	seen := make(map[string]bool)
	var recs []string

	severityOrder := map[string]int{"critical": 0, "high": 1, "medium": 2, "low": 3, "info": 4}
	sorted := make([]Finding, len(findings))
	copy(sorted, findings)
	sort.Slice(sorted, func(i, j int) bool {
		return severityOrder[sorted[i].Severity] < severityOrder[sorted[j].Severity]
	})

	for _, f := range sorted {
		if f.Recommendation != "" && !seen[f.Recommendation] {
			seen[f.Recommendation] = true
			recs = append(recs, fmt.Sprintf("[%s] %s", strings.ToUpper(f.Severity), f.Recommendation))
		}
	}

	if len(recs) == 0 {
		recs = append(recs, "No se requieren acciones inmediatas. Mantener el monitoreo continuo.")
	}

	return recs
}

func aiNormalizeQuery(query string) string {
	q := strings.TrimSpace(query)
	q = regexp.MustCompile(`'[^']*'`).ReplaceAllString(q, "'?'")
	q = regexp.MustCompile(`"[^"]*"`).ReplaceAllString(q, "\"?\"")
	q = regexp.MustCompile(`\b\d+\b`).ReplaceAllString(q, "?")
	q = regexp.MustCompile(`\s+`).ReplaceAllString(q, " ")
	return strings.ToUpper(strings.TrimSpace(q))
}

func aiClassifyQuery(query string) string {
	upper := strings.ToUpper(strings.TrimSpace(query))
	switch {
	case strings.HasPrefix(upper, "SELECT"):
		return "SELECT"
	case strings.HasPrefix(upper, "INSERT"):
		return "INSERT"
	case strings.HasPrefix(upper, "UPDATE"):
		return "UPDATE"
	case strings.HasPrefix(upper, "DELETE"):
		return "DELETE"
	case strings.HasPrefix(upper, "DROP"):
		return "DROP"
	case strings.HasPrefix(upper, "ALTER"):
		return "ALTER"
	case strings.HasPrefix(upper, "CREATE"):
		return "CREATE"
	case strings.HasPrefix(upper, "TRUNCATE"):
		return "TRUNCATE"
	case strings.HasPrefix(upper, "GRANT"):
		return "GRANT"
	case strings.HasPrefix(upper, "REVOKE"):
		return "REVOKE"
	case strings.HasPrefix(upper, "SET"):
		return "SET"
	case strings.HasPrefix(upper, "SHOW"):
		return "SHOW"
	case strings.HasPrefix(upper, "DESCRIBE") || strings.HasPrefix(upper, "DESC"):
		return "DESCRIBE"
	case strings.HasPrefix(upper, "EXPLAIN"):
		return "EXPLAIN"
	case strings.HasPrefix(upper, "FLUSH"):
		return "FLUSH"
	default:
		return "OTHER"
	}
}

func truncate(s string, maxLen int) string {
	if len(s) <= maxLen {
		return s
	}
	return s[:maxLen-3] + "..."
}

func (a *AIAnalyzer) GetLastResult() *AnalysisResult {
	a.mu.RLock()
	defer a.mu.RUnlock()

	return &AnalysisResult{
		Timestamp:     a.lastAnalysis,
		TotalAnalyzed: a.totalAnalyzed,
	}
}

func (a *AIAnalyzer) GetUserProfiles() map[string]*UserProfile {
	a.mu.RLock()
	defer a.mu.RUnlock()

	result := make(map[string]*UserProfile, len(a.userProfiles))
	for k, v := range a.userProfiles {
		dbList := make([]string, 0, len(v.UniqueDatabases))
		for db := range v.UniqueDatabases {
			dbList = append(dbList, db)
		}
		v.DatabaseList = dbList
		result[k] = v
	}
	return result
}

func (a *AIAnalyzer) GetSlowQueries() []QueryFingerprint {
	a.mu.RLock()
	defer a.mu.RUnlock()

	var slow []QueryFingerprint
	for _, fp := range a.queryFingerpts {
		if fp.IsSlow {
			slow = append(slow, *fp)
		}
	}

	sort.Slice(slow, func(i, j int) bool {
		return slow[i].AvgTime > slow[j].AvgTime
	})

	return slow
}

func (a *AIAnalyzer) GetTopQueries(limit int) []QueryFingerprint {
	a.mu.RLock()
	defer a.mu.RUnlock()

	var all []QueryFingerprint
	for _, fp := range a.queryFingerpts {
		all = append(all, *fp)
	}

	sort.Slice(all, func(i, j int) bool {
		return all[i].Count > all[j].Count
	})

	if len(all) > limit {
		all = all[:limit]
	}

	return all
}

func (a *AIAnalyzer) GetAlerts() []SecurityAlert {
	a.mu.RLock()
	defer a.mu.RUnlock()

	result := make([]SecurityAlert, len(a.alerts))
	copy(result, a.alerts)
	return result
}

func (a *AIAnalyzer) GetGlobalRiskScore() float64 {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.riskScore
}

func (a *AIAnalyzer) Reset() {
	a.mu.Lock()
	defer a.mu.Unlock()

	a.userProfiles = make(map[string]*UserProfile)
	a.queryFingerpts = make(map[string]*QueryFingerprint)
	a.alerts = make([]SecurityAlert, 0)
	a.findingHistory = make(map[string]time.Time)
	a.totalAnalyzed = 0
	a.riskScore = 0
}
