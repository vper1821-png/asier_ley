package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

type OllamaConfig struct {
	BaseURL  string `json:"base_url"`
	Model    string `json:"model"`
	Enabled  bool   `json:"enabled"`
}

type OllamaRequest struct {
	Model    string        `json:"model"`
	Prompt   string        `json:"prompt"`
	Stream   bool          `json:"stream"`
	Options  OllamaOptions `json:"options,omitempty"`
}

type OllamaOptions struct {
	Temperature float64 `json:"temperature"`
	NumCtx      int     `json:"num_ctx"`
}

type OllamaResponse struct {
	Response string `json:"response"`
	Done     bool   `json:"done"`
	Error    string `json:"error,omitempty"`
}

var ollamaClient = &http.Client{Timeout: 120 * time.Second}

func getOllamaConfig() OllamaConfig {
	return OllamaConfig{
		BaseURL: envOrDefault("OLLAMA_URL", "http://localhost:11434"),
		Model:   envOrDefault("OLLAMA_MODEL", "llama3.2"),
		Enabled: os.Getenv("OLLAMA_ENABLED") != "false",
	}
}

func ollamaAnalyze(prompt string) (string, error) {
	cfg := getOllamaConfig()
	if !cfg.Enabled {
		return "", fmt.Errorf("ollama is disabled")
	}

	reqBody := OllamaRequest{
		Model:  cfg.Model,
		Prompt: prompt,
		Stream: false,
		Options: OllamaOptions{
			Temperature: 0.1,
			NumCtx:      4096,
		},
	}

	body, _ := json.Marshal(reqBody)
	resp, err := ollamaClient.Post(cfg.BaseURL+"/api/generate", "application/json", bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("ollama request failed: %w", err)
	}
	defer resp.Body.Close()

	respData, _ := io.ReadAll(resp.Body)
	var result OllamaResponse
	if err := json.Unmarshal(respData, &result); err != nil {
		return "", fmt.Errorf("ollama response decode: %w", err)
	}

	if result.Error != "" {
		return "", fmt.Errorf("ollama error: %s", result.Error)
	}

	return result.Response, nil
}

func ollamaAnalyzeScan(scan *ScanResult) (*AIAnalysis, error) {
	prompt := buildScanPrompt(scan)
	response, err := ollamaAnalyze(prompt)
	if err != nil {
		return nil, err
	}

	analysis := &AIAnalysis{
		Engine:      scan.Engine,
		Database:    scan.Database,
		AnalyzedAt:  time.Now().UTC().Format(time.RFC3339),
		RawResponse: response,
	}

	analysis.Summary = extractSummary(response)
	analysis.Recommendations = extractRecommendations(response)
	analysis.RiskLevel = assessRisk(scan)

	return analysis, nil
}

type AIAnalysis struct {
	Engine          string   `json:"engine"`
	Database        string   `json:"database"`
	AnalyzedAt      string   `json:"analyzedAt"`
	Summary         string   `json:"summary"`
	RawResponse     string   `json:"rawResponse"`
	Recommendations []string `json:"recommendations"`
	RiskLevel       string   `json:"riskLevel"`
}

func buildScanPrompt(scan *ScanResult) string {
	var b strings.Builder
	b.WriteString(fmt.Sprintf(`Analyze the following database scan results for compliance with Chile's Ley 21.719 (Personal Data Protection Law) and provide a professional assessment in Spanish.

Database Engine: %s
Database Name: %s
Total Tables: %d
Total Rows: %d
Personal Data Columns Found: %d
Sensitive Data Columns: %d

Tables found:
`, scan.Engine, scan.Database, scan.TotalTables, scan.TotalRows, scan.PersonalDataColumns, scan.SensitiveDataColumns))

	for _, t := range scan.Tables {
		b.WriteString(fmt.Sprintf("\n- Table: %s (%d rows)\n", t.Name, t.RowCount))
		for _, c := range t.Columns {
			if c.IsPersonal {
				b.WriteString(fmt.Sprintf("  • %s (%s) -> PERSONAL DATA: %s\n", c.Name, c.Type, c.Category))
			}
		}
	}

	b.WriteString(`
Provide:
1. A brief summary of the data protection compliance status (2-3 sentences)
2. Specific recommendations for this database to comply with Ley 21.719
3. Identify which columns need special protection (sensitive data)
4. Suggest retention periods and access control measures

Format as:
SUMMARY: ...
RECOMMENDATIONS: 
- ...
- ...
RISK: [BAJO/MEDIO/ALTO/CRITICO]`)

	return b.String()
}

func extractSummary(response string) string {
	if idx := strings.Index(response, "SUMMARY:"); idx >= 0 {
		end := strings.Index(response[idx:], "RECOMMENDATIONS:")
		if end < 0 {
			end = len(response) - idx
		}
		return strings.TrimSpace(response[idx+8 : idx+end])
	}
	if len(response) > 200 {
		return response[:200] + "..."
	}
	return response
}

func extractRecommendations(response string) []string {
	var recs []string
	if idx := strings.Index(response, "RECOMMENDATIONS:"); idx >= 0 {
		section := response[idx+16:]
		if end := strings.Index(section, "RISK:"); end >= 0 {
			section = section[:end]
		}
		for _, line := range strings.Split(section, "\n") {
			line = strings.TrimSpace(line)
			line = strings.TrimPrefix(line, "- ")
			line = strings.TrimPrefix(line, "* ")
			if line != "" && !strings.HasPrefix(line, "RISK:") {
				recs = append(recs, line)
			}
		}
	}
	return recs
}

func assessRisk(scan *ScanResult) string {
	if scan.TotalRows > 100000 || scan.SensitiveDataColumns > 10 {
		return "CRITICO"
	}
	if scan.TotalRows > 50000 || scan.SensitiveDataColumns > 5 {
		return "ALTO"
	}
	if scan.PersonalDataColumns > 10 {
		return "MEDIO"
	}
	return "BAJO"
}

func ollamaChat(message string) (string, error) {
	cfg := getOllamaConfig()
	if !cfg.Enabled {
		return "Ollama AI no está disponible (deshabilitado)", nil
	}

	prompt := fmt.Sprintf(`Eres un asistente experto en protección de datos y cumplimiento de la Ley 21.719 de Chile. 
Responde la siguiente consulta de forma clara, precisa y útil para una empresa que debe cumplir con la ley.

Consulta: %s

Responde en español, máximo 3 párrafos.`, message)

	return ollamaAnalyze(prompt)
}

func init() {
	cfg := getOllamaConfig()
	if cfg.Enabled {
		logMsg("Ollama AI enabled: %s (model: %s)", cfg.BaseURL, cfg.Model)
	}
}
