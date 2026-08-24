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
	cats := DetectPersonalData(query)
	categories := make([]string, 0, len(cats))
	for cat := range cats {
		categories = append(categories, cat)
	}
	return categories
}

// ScanTable escanea una tabla en busca de columnas con PII
func (s *PIIScanner) ScanTable(db *sql.DB, engine, table string) (map[string][]string, error) {
	result := make(map[string][]string)
	// Implementación usando information_schema o consultas específicas
	return result, nil
}
