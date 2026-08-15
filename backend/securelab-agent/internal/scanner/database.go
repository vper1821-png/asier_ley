package scanner

import (
	"database/sql"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
)

type PIIScanner struct {
	store *audit.Store
	log   *logger.Logger
}

func NewPIIScanner(store *audit.Store, log *logger.Logger) *PIIScanner {
	return &PIIScanner{store: store, log: log}
}

// AnalyzeQuery analiza una consulta SQL en busca de PII
func (s *PIIScanner) AnalyzeQuery(query string) []string {
	var categories []string
	for cat, regexes := range compiledPatterns {
		for _, re := range regexes {
			if re.MatchString(query) {
				categories = append(categories, cat)
				break
			}
		}
	}
	return categories
}

// ScanTable escanea una tabla en busca de columnas con PII
func (s *PIIScanner) ScanTable(db *sql.DB, engine, table string) (map[string][]string, error) {
	result := make(map[string][]string)
	// Implementación usando information_schema o consultas específicas
	return result, nil
}
