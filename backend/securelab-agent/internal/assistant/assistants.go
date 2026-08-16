package assistant

import (
	"database/sql"
	"strings"
	"sync"
	"unicode"

	"securelab-agent/internal/logger"

	_ "modernc.org/sqlite"
)

type Assistant struct {
	db   *sql.DB
	mu   sync.RWMutex
	log  *logger.Logger
	dict []DictEntry
}

type DictEntry struct {
	Category     string  `json:"category"`
	Keyword      string  `json:"keyword"`
	SynonymGroup string  `json:"synonymGroup"`
	Weight       float64 `json:"weight"`
}

type KnowledgeRow struct {
	ID          int     `json:"id"`
	CategoryID  int     `json:"categoryId"`
	Question    string  `json:"question"`
	Answer      string  `json:"answer"`
	Keywords    string  `json:"keywords"`
	Confidence  float64 `json:"confidence"`
	AccessCount int     `json:"accessCount"`
}

type AskResult struct {
	Answer     string             `json:"answer"`
	Confidence float64            `json:"confidence"`
	Category   string             `json:"category"`
	Source     string             `json:"source"`
	Categories map[string]float64 `json:"categories,omitempty"`
}

func NewAssistant(dbPath string, log *logger.Logger) *Assistant {
	a := &Assistant{log: log}
	a.init(dbPath)
	return a
}

func (a *Assistant) init(dbPath string) {
	var err error
	a.db, err = sql.Open("sqlite", dbPath+"?_journal_mode=WAL&_foreign_keys=on")
	if err != nil {
		a.log.Error("Assistant: open db failed: %v", err)
		return
	}
	a.createSchema()
	a.seedCategories()
	a.seedDictionary()
	a.seedKnowledge()
	a.log.Info("Assistant: knowledge base ready")
}

func (a *Assistant) createSchema() {
	schema := `
		CREATE TABLE IF NOT EXISTS categories (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			name TEXT UNIQUE NOT NULL,
			description TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		);
		CREATE TABLE IF NOT EXISTS knowledge (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			category_id INTEGER,
			question TEXT NOT NULL,
			answer TEXT NOT NULL,
			keywords TEXT,
			confidence REAL DEFAULT 1.0,
			enabled INTEGER DEFAULT 1,
			source TEXT DEFAULT 'seed',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			access_count INTEGER DEFAULT 0,
			FOREIGN KEY (category_id) REFERENCES categories(id)
		);
		CREATE TABLE IF NOT EXISTS learning_log (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			question TEXT NOT NULL,
			answer TEXT,
			category TEXT,
			confidence REAL,
			source TEXT,
			user_feedback INTEGER,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		);
		CREATE TABLE IF NOT EXISTS dictionary (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			category TEXT NOT NULL,
			keyword TEXT NOT NULL,
			synonym_group TEXT,
			weight REAL DEFAULT 1.0
		);
		CREATE INDEX IF NOT EXISTS idx_knowledge_category ON knowledge(category_id);
		CREATE INDEX IF NOT EXISTS idx_dictionary_category ON dictionary(category);
	`
	if _, err := a.db.Exec(schema); err != nil {
		a.log.Error("Assistant: schema error: %v", err)
	}
}

func (a *Assistant) seedCategories() {
	cats := []struct{ name, desc string }{
		{"ley_21719", "Ley 21.719 de Protección de Datos Personales de Chile"},
		{"proteccion_datos", "Protección de datos personales en general"},
		{"consentimiento", "Consentimiento del titular de datos"},
		{"derechos_arco", "Derechos ARCO (Acceso, Rectificación, Cancelación, Oposición)"},
		{"brechas", "Brechas de seguridad y notificación"},
		{"dpd", "Delegado de Protección de Datos (DPD/DPO)"},
		{"apdp", "Agencia de Protección de Datos Personales"},
		{"sanciones", "Sanciones y multas por incumplimiento"},
		{"inventario_datos", "Inventario de datos personales"},
		{"transferencia", "Transferencia internacional de datos"},
		{"seguridad", "Seguridad de la información"},
		{"escaneo", "Escaneo y análisis de dominios"},
		{"plataforma", "Funcionalidades de la plataforma"},
		{"saludo", "Saludos y bienvenida"},
		{"general", "Preguntas generales"},
	}
	for _, c := range cats {
		a.db.Exec("INSERT OR IGNORE INTO categories (name, description) VALUES (?, ?)", c.name, c.desc)
	}
}

func (a *Assistant) seedDictionary() {
	var count int
	a.db.QueryRow("SELECT COUNT(*) FROM dictionary").Scan(&count)
	if count > 0 {
		return
	}
	entries := []struct {
		cat, kw, group string
		weight         float64
	}{
		{"ley_21719", "ley 21719", "normativa", 1.0},
		{"ley_21719", "ley 21.719", "normativa", 1.0},
		{"ley_21719", "ley de proteccion de datos", "normativa", 0.9},
		{"proteccion_datos", "proteccion de datos", "proteccion", 1.0},
		{"proteccion_datos", "datos personales", "proteccion", 1.0},
		{"consentimiento", "consentimiento", "consent", 1.0},
		{"derechos_arco", "derechos arco", "arco", 1.0},
		{"brechas", "brecha de seguridad", "breach", 1.0},
		{"dpd", "delegado proteccion datos", "dpd", 1.0},
		{"apdp", "agencia proteccion datos", "apdp", 1.0},
		{"sanciones", "sancion", "sanction", 1.0},
		{"inventario_datos", "inventario datos", "inventory", 1.0},
		{"transferencia", "transferencia internacional", "transfer", 1.0},
		{"seguridad", "seguridad", "security", 0.8},
		{"escaneo", "escanear", "scan", 1.0},
		{"plataforma", "invisia", "platform", 0.9},
		{"saludo", "hola", "greeting", 1.0},
		{"general", "que es", "general", 0.3},
	}
	stmt, _ := a.db.Prepare("INSERT OR IGNORE INTO dictionary (category, keyword, synonym_group, weight) VALUES (?, ?, ?, ?)")
	for _, e := range entries {
		stmt.Exec(e.cat, e.kw, e.group, e.weight)
	}
}

func (a *Assistant) seedKnowledge() {
	var count int
	a.db.QueryRow("SELECT COUNT(*) FROM knowledge").Scan(&count)
	if count > 0 {
		return
	}
	catID := func(name string) int {
		var id int
		a.db.QueryRow("SELECT id FROM categories WHERE name = ?", name).Scan(&id)
		return id
	}
	seed := []struct {
		cat      string
		question string
		answer   string
		keywords string
	}{
		{cat: "ley_21719", question: "¿Qué es la Ley 21.719?", answer: "La Ley 21.719 es la nueva Ley de Protección de Datos Personales de Chile, publicada en 2024. Reemplaza la antigua Ley 19.628 y establece un marco normativo moderno que regula el tratamiento de datos personales, crea la Agencia de Protección de Datos Personales (APDP), exige el consentimiento del titular, establece los derechos ARCO, y contempla sanciones de hasta 20.000 UTM.", keywords: "ley 21719 proteccion datos chile"},
		{cat: "proteccion_datos", question: "¿Qué son los datos personales?", answer: "Son cualquier información relativa a una persona natural identificada o identificable: nombre, RUT, dirección, email, teléfono, salud, datos biométricos, geolocalización, IP, etc.", keywords: "que son definicion datos personales"},
		{cat: "consentimiento", question: "¿Cómo debe ser el consentimiento?", answer: "El consentimiento debe ser: libre, específico, informado, inequívoco y revocable. Para datos sensibles debe ser explícito y por escrito.", keywords: "requisitos valido libre informado"},
		{cat: "derechos_arco", question: "¿Qué son los derechos ARCO?", answer: "Son: Acceso (saber qué datos tratan), Rectificación (corregir datos incorrectos), Cancelación (eliminar datos) y Oposición (negarse al tratamiento). La Ley 21.719 agrega la Portabilidad.", keywords: "arco acceso rectificacion cancelacion oposicion"},
		{cat: "brechas", question: "¿Qué hacer ante una brecha?", answer: "1) Contener la brecha, 2) Evaluar el alcance, 3) Notificar a la APDP dentro de 72 horas, 4) Notificar a titulares si hay alto riesgo, 5) Documentar, 6) Implementar medidas correctivas.", keywords: "procedimiento que hacer notificar reportar"},
		{cat: "dpd", question: "¿Es obligatorio tener DPD?", answer: "Sí, toda organización que trate datos personales debe designar un DPD/DPO, interno o externo, con conocimientos en protección de datos.", keywords: "obligatorio delegado dpo designar"},
		{cat: "apdp", question: "¿Qué es la APDP?", answer: "La Agencia de Protección de Datos Personales es el organismo público que fiscaliza el cumplimiento de la Ley 21.719, resuelve reclamos, impone sanciones y mantiene registros públicos.", keywords: "agencia apdp que es fiscalizador"},
		{cat: "sanciones", question: "¿Cuáles son las sanciones?", answer: "Amonestación escrita, multas de hasta 20.000 UTM (~$1.300.000 USD), prohibición de tratar datos, y clausura del banco de datos.", keywords: "multas penalidades utm cuanto pagan"},
		{cat: "saludo", question: "hola", answer: "¡Hola! Soy el Asistente SecureLab, experto en la Ley 21.719 de Chile y la plataforma Invisia. ¿En qué puedo ayudarte?", keywords: "saludo"},
	}
	stmt, _ := a.db.Prepare("INSERT INTO knowledge (category_id, question, answer, keywords, source) VALUES (?, ?, ?, ?, 'seed')")
	for _, s := range seed {
		stmt.Exec(catID(s.cat), s.question, s.answer, s.keywords)
	}
}

func (a *Assistant) Ask(question string) AskResult {
	cat, _, scores := a.categorize(question)
	threshold := 0.25
	if cat == "general" {
		threshold = 0.15
	}
	if match := a.findAnswer(question, cat, threshold); match != nil {
		return AskResult{Answer: match.Answer, Confidence: match.Confidence, Category: cat, Source: "matched", Categories: scores}
	}
	return AskResult{
		Answer:     "No tengo una respuesta específica para esa consulta. Puedes reformularla o consultarme sobre la Ley 21.719, protección de datos, o el funcionamiento de la plataforma.",
		Confidence: 0.1,
		Category:   "general",
		Source:     "fallback",
		Categories: scores,
	}
}

func (a *Assistant) categorize(question string) (string, float64, map[string]float64) {
	tokens := tokenize(question)
	if len(tokens) == 0 {
		return "general", 0.1, nil
	}
	scores := make(map[string]float64)
	rows, _ := a.db.Query("SELECT category, keyword, weight FROM dictionary")
	if rows != nil {
		defer rows.Close()
	}
	for rows != nil && rows.Next() {
		var cat, kw string
		var weight float64
		if err := rows.Scan(&cat, &kw, &weight); err != nil {
			continue
		}
		kwNorm := normalizeText(kw)
		for _, token := range tokens {
			if token == kwNorm {
				scores[cat] += weight
				break
			}
		}
	}
	if len(scores) == 0 {
		return "general", 0.1, scores
	}
	var best string
	var bestScore float64
	for k, v := range scores {
		if v > bestScore {
			best, bestScore = k, v
		}
	}
	var total float64
	for _, v := range scores {
		total += v
	}
	confidence := bestScore / total
	if confidence > 0.98 {
		confidence = 0.98
	}
	return best, confidence, scores
}

func (a *Assistant) findAnswer(question, category string, minConfidence float64) *KnowledgeRow {
	tokens := tokenize(question)
	if len(tokens) == 0 {
		return nil
	}
	var rows *sql.Rows
	var err error
	if category == "general" {
		rows, err = a.db.Query("SELECT id, category_id, question, answer, keywords, confidence, access_count FROM knowledge WHERE enabled = 1 ORDER BY access_count DESC, confidence DESC LIMIT 50")
	} else {
		rows, err = a.db.Query(`
			SELECT k.id, k.category_id, k.question, k.answer, k.keywords, k.confidence, k.access_count
			FROM knowledge k JOIN categories c ON k.category_id = c.id
			WHERE c.name = ? AND k.enabled = 1
			ORDER BY k.access_count DESC, k.confidence DESC`, category)
	}
	if err != nil || rows == nil {
		return nil
	}
	defer rows.Close()
	var best *KnowledgeRow
	var bestScore float64
	for rows.Next() {
		var row KnowledgeRow
		var keywords string
		if err := rows.Scan(&row.ID, &row.CategoryID, &row.Question, &row.Answer, &keywords, &row.Confidence, &row.AccessCount); err != nil {
			continue
		}
		row.Keywords = keywords
		kwTokens := tokenize(keywords + " " + row.Question)
		matches := 0
		for _, t := range tokens {
			for _, kw := range kwTokens {
				if t == kw {
					matches++
					break
				}
			}
		}
		var score float64
		if len(tokens) > 0 && len(kwTokens) > 0 {
			denom := len(tokens)
			if len(kwTokens) > denom {
				denom = len(kwTokens)
			}
			score = float64(matches) / float64(denom) * row.Confidence
		}
		if score > bestScore {
			bestScore = score
			best = &row
		}
	}
	if best != nil && bestScore >= minConfidence {
		a.db.Exec("UPDATE knowledge SET access_count = access_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?", best.ID)
		return best
	}
	return nil
}

func normalizeText(text string) string {
	var b strings.Builder
	text = strings.ToLower(text)
	for _, r := range text {
		if r == 'ñ' {
			b.WriteRune('n')
		} else if r == 'á' || r == 'é' || r == 'í' || r == 'ó' || r == 'ú' {
			switch r {
			case 'á':
				b.WriteRune('a')
			case 'é':
				b.WriteRune('e')
			case 'í':
				b.WriteRune('i')
			case 'ó':
				b.WriteRune('o')
			case 'ú':
				b.WriteRune('u')
			}
		} else if unicode.IsLetter(r) || unicode.IsDigit(r) || r == ' ' {
			b.WriteRune(r)
		} else {
			b.WriteRune(' ')
		}
	}
	return strings.Join(strings.Fields(b.String()), " ")
}

var stopWords = map[string]bool{
	"de": true, "la": true, "que": true, "el": true, "en": true, "y": true,
	"a": true, "los": true, "del": true, "se": true, "las": true, "por": true,
	"un": true, "una": true, "para": true, "con": true, "no": true, "al": true,
	"lo": true, "como": true, "mas": true, "o": true, "pero": true, "sus": true,
	"le": true, "ya": true, "este": true, "entre": true, "todo": true, "esa": true,
}

func tokenize(text string) []string {
	norm := normalizeText(text)
	parts := strings.Fields(norm)
	var tokens []string
	for _, p := range parts {
		if len(p) > 1 && !stopWords[p] {
			tokens = append(tokens, p)
		}
	}
	return tokens
}
