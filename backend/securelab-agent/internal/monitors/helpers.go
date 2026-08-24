package monitors

import (
	"strings"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"
)

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

// reportDBQuery guarda localmente y envía al panel una consulta de base de datos.
// Envía TODAS las consultas, incluidas las del usuario root/admin.
func reportDBQuery(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger, entry audit.DBQueryEntry) {
	// Clasificar operación real en lugar de poner SELECT por defecto
	entry.Operation = classifyOperation(entry.Query)

	// Calcular riesgo PII
	categories := piiScanner.AnalyzeQuery(entry.Query)
	entry.RiskScore = float64(len(categories))

	if len(categories) > 0 && log != nil {
		log.Warn("PII detectada en consulta de %s: %s", entry.User, entry.Query)
		wsClient.SendEvent("PII Detectada",
			"Usuario "+entry.User+" ejecutó consulta con datos personales: "+entry.Query,
			"db_activity", "high")
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
