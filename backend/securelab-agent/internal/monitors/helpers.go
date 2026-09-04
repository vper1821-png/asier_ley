package monitors

import (
	"os"
	"regexp"
	"strings"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"
)

// criticalTables contiene los nombres de tablas que, por definición, tienen datos críticos.
// Se puede sobrescribir con la variable de entorno CRITICAL_TABLES separada por comas.
var criticalTables = loadCriticalTables()

// tableNameRe extrae nombres de tabla en las cláusulas SQL más comunes.
// Soporta backticks y nombres con esquema (db.tabla).
var tableNameRe = regexp.MustCompile(`(?i)\b(?:FROM|INTO|UPDATE|JOIN|TABLE)\s+(?:` + "`" + `?)([\w.]+)(?:` + "`" + `?)\b`)

func loadCriticalTables() map[string]bool {
	m := make(map[string]bool)
	env := os.Getenv("CRITICAL_TABLES")
	if env != "" {
		for _, t := range strings.Split(env, ",") {
			t = strings.TrimSpace(strings.ToLower(t))
			if t != "" {
				m[t] = true
			}
		}
		return m
	}
	// Lista por defecto (español + inglés). Añade o quita según tus necesidades.
	defaults := []string{
		"clientes", "usuarios", "pacientes", "empleados", "proveedores",
		"contactos", "titulares", "sujetos_datos", "arco_requests",
		"consentimientos", "customers", "users", "persons", "people",
		"patients", "employees", "clients", "suppliers", "contacts",
	}
	for _, t := range defaults {
		m[t] = true
	}
	return m
}

// queryUsesCriticalTable comprueba si la consulta hace referencia a alguna tabla crítica.
func queryUsesCriticalTable(query string) bool {
	matches := tableNameRe.FindAllStringSubmatch(query, -1)
	for _, m := range matches {
		if len(m) < 2 {
			continue
		}
		// Quitar backticks y tomar el último segmento (tabla sin esquema)
		name := strings.Trim(m[1], "`\"")
		parts := strings.Split(name, ".")
		table := strings.ToLower(parts[len(parts)-1])
		if criticalTables[table] {
			return true
		}
	}
	return false
}

// classifyOperation determina el tipo de operación SQL a partir del texto de la consulta
func classifyOperation(query string) string {
	q := strings.ToUpper(strings.TrimSpace(query))
	if strings.HasPrefix(q, "SELECT") {
		return "SELECT"
	}
	if strings.HasPrefix(q, "INSERT") {
		return "INSERT"
	}
	if strings.HasPrefix(q, "UPDATE") {
		return "UPDATE"
	}
	if strings.HasPrefix(q, "DELETE") {
		return "DELETE"
	}
	if strings.HasPrefix(q, "REPLACE") {
		return "REPLACE"
	}
	if strings.HasPrefix(q, "TRUNCATE") {
		return "TRUNCATE"
	}
	if strings.HasPrefix(q, "CREATE") {
		return "CREATE"
	}
	if strings.HasPrefix(q, "DROP") {
		return "DROP"
	}
	if strings.HasPrefix(q, "ALTER") {
		return "ALTER"
	}
	if strings.HasPrefix(q, "EXEC") || strings.HasPrefix(q, "EXECUTE") || strings.HasPrefix(q, "CALL") || strings.HasPrefix(q, "SP_") {
		return "EXEC"
	}
	if strings.HasPrefix(q, "SHOW") || strings.HasPrefix(q, "DESCRIBE") || strings.HasPrefix(q, "EXPLAIN") {
		return "INFO"
	}
	return "QUERY"
}

// isCriticalOperation indica si una operación modifica datos o es DDL
func isCriticalOperation(op string) bool {
	switch op {
	case "INSERT", "UPDATE", "DELETE", "TRUNCATE", "REPLACE", "CREATE", "DROP", "ALTER", "EXEC":
		return true
	}
	return false
}

// reportDBQuery guarda localmente y envía al panel solo consultas críticas, con PII
// o que toquen tablas con datos críticos. No envía SELECTs genéricos.
func reportDBQuery(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger, entry audit.DBQueryEntry) {
	// Clasificar operación real en lugar de poner SELECT por defecto
	entry.Operation = classifyOperation(entry.Query)

	// Calcular riesgo PII
	categories := piiScanner.AnalyzeQuery(entry.Query)
	entry.RiskScore = float64(len(categories))

	// Detectar si toca una tabla con datos críticos
	touchesCritical := queryUsesCriticalTable(entry.Query)

	if len(categories) > 0 && log != nil {
		log.Warn("PII detectada en consulta de %s: %s", entry.User, entry.Query)
		wsClient.SendEvent("PII Detectada",
			"Usuario "+entry.User+" ejecutó consulta con datos personales: "+entry.Query,
			"db_activity", "high")
	}

	// Solo reportar consultas con datos personales, modificación/DDL o tablas críticas
	if len(categories) == 0 && !isCriticalOperation(entry.Operation) && !touchesCritical {
		return
	}

	// Guardar localmente
	if err := store.SaveDBQuery(entry); err != nil && log != nil {
		log.Error("Error guardando consulta BD local: %v", err)
	}

	// Enviar al panel en tiempo real
	if wsClient != nil {
		wsClient.SendDBQuery(entry)
	}
}
