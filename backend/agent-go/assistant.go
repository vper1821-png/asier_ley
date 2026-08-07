package main

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
	"unicode"

	_ "modernc.org/sqlite"
)

type Assistant struct {
	db        *sql.DB
	mu        sync.RWMutex
	dict      []DictEntry
	categories []Category
}

type Category struct {
	ID          int    `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
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
	Answer      string             `json:"answer"`
	Confidence  float64            `json:"confidence"`
	Category    string             `json:"category"`
	Source      string             `json:"source"`
	Categories  map[string]float64 `json:"categories,omitempty"`
}

type StatsResult struct {
	TotalKnowledge int              `json:"totalKnowledge"`
	TotalLearned   int              `json:"totalLearned"`
	TopQuestions   []KnowledgeRow   `json:"topQuestions"`
	Categories     []CategoryStat   `json:"categories"`
}

type CategoryStat struct {
	Name        string `json:"name"`
	Description string `json:"description"`
	Count       int    `json:"count"`
}

var globalAssistant *Assistant
var assistantOnce sync.Once

func GetAssistant() *Assistant {
	assistantOnce.Do(func() {
		a := &Assistant{}
		if err := a.init(); err != nil {
			logMsg("[ASSISTANT] Init error: %v", err)
			return
		}
		globalAssistant = a
	})
	return globalAssistant
}

func (a *Assistant) init() error {
	exe, err := os.Executable()
	if err != nil {
		exe, _ = os.Getwd()
	}
	dir := filepath.Dir(exe)
	dbPath := filepath.Join(dir, "securelab_assistant.db")

	a.db, err = sql.Open("sqlite", dbPath+"?_journal_mode=WAL&_foreign_keys=on")
	if err != nil {
		return fmt.Errorf("open db: %w", err)
	}

	if err := a.createSchema(); err != nil {
		return fmt.Errorf("schema: %w", err)
	}

	a.seedCategories()
	a.seedDictionary()
	a.seedKnowledge()

	logMsg("[ASSISTANT] SQLite knowledge base ready: %s", dbPath)
	return nil
}

func (a *Assistant) createSchema() error {
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
	_, err := a.db.Exec(schema)
	return err
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

	entries := []struct{ cat, kw, group string; weight float64 }{
		{"ley_21719", "ley 21719", "normativa", 1.0},
		{"ley_21719", "ley 21.719", "normativa", 1.0},
		{"ley_21719", "ley de proteccion de datos", "normativa", 0.9},
		{"ley_21719", "nueva ley", "normativa", 0.8},
		{"ley_21719", "normativa chilena", "normativa", 0.8},
		{"ley_21719", "datos personales chile", "normativa", 0.9},

		{"proteccion_datos", "proteccion de datos", "proteccion", 1.0},
		{"proteccion_datos", "datos personales", "proteccion", 1.0},
		{"proteccion_datos", "privacidad", "proteccion", 0.8},
		{"proteccion_datos", "datos sensibles", "proteccion", 0.9},
		{"proteccion_datos", "tratamiento de datos", "proteccion", 0.9},

		{"consentimiento", "consentimiento", "consent", 1.0},
		{"consentimiento", "autorizacion", "consent", 0.9},
		{"consentimiento", "revocar", "consent", 0.9},
		{"consentimiento", "permiso", "consent", 0.8},
		{"consentimiento", "politica de privacidad", "consent", 0.7},

		{"derechos_arco", "derechos arco", "arco", 1.0},
		{"derechos_arco", "arco", "arco", 0.9},
		{"derechos_arco", "acceso", "arco", 0.8},
		{"derechos_arco", "rectificacion", "arco", 0.9},
		{"derechos_arco", "cancelacion", "arco", 0.9},
		{"derechos_arco", "oposicion", "arco", 0.9},
		{"derechos_arco", "portabilidad", "arco", 0.8},

		{"brechas", "brecha de seguridad", "breach", 1.0},
		{"brechas", "filtracion", "breach", 0.9},
		{"brechas", "fuga de datos", "breach", 0.9},
		{"brechas", "incidente de seguridad", "breach", 0.9},
		{"brechas", "notificar brecha", "breach", 0.8},

		{"dpd", "delegado proteccion datos", "dpd", 1.0},
		{"dpd", "delegado", "dpd", 0.9},
		{"dpd", "dpo", "dpd", 0.9},
		{"dpd", "oficial proteccion", "dpd", 0.8},

		{"apdp", "agencia proteccion datos", "apdp", 1.0},
		{"apdp", "apdp", "apdp", 1.0},
		{"apdp", "registro apdp", "apdp", 0.9},

		{"sanciones", "sancion", "sanction", 1.0},
		{"sanciones", "multa", "sanction", 1.0},
		{"sanciones", "incumplimiento", "sanction", 0.8},
		{"sanciones", "utm", "sanction", 0.7},
		{"sanciones", "infraccion", "sanction", 0.8},

		{"inventario_datos", "inventario datos", "inventory", 1.0},
		{"inventario_datos", "registro datos", "inventory", 0.8},
		{"inventario_datos", "mapeo datos", "inventory", 0.8},
		{"inventario_datos", "clasificacion datos", "inventory", 0.8},

		{"transferencia", "transferencia internacional", "transfer", 1.0},
		{"transferencia", "datos al extranjero", "transfer", 0.9},
		{"transferencia", "datos fuera de chile", "transfer", 0.9},

		{"seguridad", "seguridad", "security", 0.8},
		{"seguridad", "medidas seguridad", "security", 1.0},
		{"seguridad", "encriptacion", "security", 0.8},
		{"seguridad", "cifrado", "security", 0.8},
		{"seguridad", "firewall", "security", 0.6},
		{"seguridad", "control acceso", "security", 0.8},

		{"escaneo", "escanear", "scan", 1.0},
		{"escaneo", "escaneo", "scan", 1.0},
		{"escaneo", "analisis dominio", "scan", 0.9},
		{"escaneo", "vulnerabilidad", "scan", 0.8},
		{"escaneo", "puertos", "scan", 0.7},
		{"escaneo", "sql injection", "scan", 0.8},
		{"escaneo", "xss", "scan", 0.8},
		{"escaneo", "subdominios", "scan", 0.8},

		{"plataforma", "invisia", "platform", 0.9},
		{"plataforma", "securelab", "platform", 0.9},
		{"plataforma", "plataforma", "platform", 0.8},
		{"plataforma", "como usar", "platform", 0.7},
		{"plataforma", "reporte", "platform", 0.7},
		{"plataforma", "informe", "platform", 0.7},

		{"saludo", "hola", "greeting", 1.0},
		{"saludo", "buenos dias", "greeting", 1.0},
		{"saludo", "buenas tardes", "greeting", 1.0},
		{"saludo", "gracias", "greeting", 0.5},
		{"saludo", "como estas", "greeting", 0.9},
		{"saludo", "quien eres", "greeting", 0.8},

		{"general", "que es", "general", 0.3},
		{"general", "ayuda", "general", 0.3},
		{"general", "duda", "general", 0.3},
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
		{cat: "ley_21719", question: "¿A quiénes aplica la Ley 21.719?", answer: "Aplica a todas las personas naturales o jurídicas, públicas o privadas, que realicen tratamiento de datos personales en Chile, incluyendo entidades extranjeras que traten datos de personas en Chile.", keywords: "aplica alcance obligados"},
		{cat: "ley_21719", question: "¿Cuándo entra en vigencia?", answer: "La Ley 21.719 fue publicada en 2024 con un período de vacancia legal de 24 meses para que las empresas se adapten. Las obligaciones comenzarán a ser exigibles aproximadamente en 2026.", keywords: "vigencia cuando plazo adaptacion"},

		{cat: "proteccion_datos", question: "¿Qué son los datos personales?", answer: "Son cualquier información relativa a una persona natural identificada o identificable: nombre, RUT, dirección, email, teléfono, salud, datos biométricos, geolocalización, IP, etc.", keywords: "que son definicion datos personales"},
		{cat: "proteccion_datos", question: "¿Qué son los datos sensibles?", answer: "Son datos que requieren mayor protección: origen racial, creencias religiosas, opiniones políticas, salud, datos biométricos, genéticos, vida sexual, y datos de niños. Su tratamiento está prohibido salvo excepciones legales.", keywords: "sensibles especiales categoria"},

		{cat: "consentimiento", question: "¿Cómo debe ser el consentimiento?", answer: "El consentimiento debe ser: libre, específico, informado, inequívoco y revocable. Para datos sensibles debe ser explícito y por escrito.", keywords: "requisitos valido libre informado"},
		{cat: "consentimiento", question: "¿Se puede revocar el consentimiento?", answer: "Sí, el titular puede revocar su consentimiento en cualquier momento sin expresión de causa, y debe ser tan fácil como otorgarlo.", keywords: "revocar retirar cancelar"},

		{cat: "derechos_arco", question: "¿Qué son los derechos ARCO?", answer: "Son: Acceso (saber qué datos tratan), Rectificación (corregir datos incorrectos), Cancelación (eliminar datos) y Oposición (negarse al tratamiento). La Ley 21.719 agrega la Portabilidad.", keywords: "arco acceso rectificacion cancelacion oposicion"},
		{cat: "derechos_arco", question: "¿Cómo ejercer los derechos ARCO?", answer: "Debes presentar una solicitud formal al responsable del tratamiento. Debe responder en 10 días hábiles (prorrogable por 10 más). Si no recibes respuesta, puedes recurrir a la APDP.", keywords: "ejercer solicitar procedimiento"},

		{cat: "brechas", question: "¿Qué hacer ante una brecha?", answer: "1) Contener la brecha, 2) Evaluar el alcance, 3) Notificar a la APDP dentro de 72 horas, 4) Notificar a titulares si hay alto riesgo, 5) Documentar, 6) Implementar medidas correctivas.", keywords: "procedimiento que hacer notificar reportar"},
		{cat: "brechas", question: "¿Cuándo notificar una brecha?", answer: "Debes notificar a la APDP dentro de 72 horas cuando la brecha pueda afectar derechos y libertades, especialmente si involucra datos sensibles, niños, o gran número de titulares.", keywords: "cuando notificar 72 horas obligacion"},

		{cat: "dpd", question: "¿Es obligatorio tener DPD?", answer: "Sí, toda organización que trate datos personales debe designar un DPD/DPO, interno o externo, con conocimientos en protección de datos.", keywords: "obligatorio delegado dpo designar"},
		{cat: "dpd", question: "¿Quién puede ser DPD?", answer: "Cualquier persona con conocimientos especializados en protección de datos, interna o externa, sin conflicto de intereses.", keywords: "quien puede requisitos perfil"},

		{cat: "apdp", question: "¿Qué es la APDP?", answer: "La Agencia de Protección de Datos Personales es el organismo público que fiscaliza el cumplimiento de la Ley 21.719, resuelve reclamos, impone sanciones y mantiene registros públicos.", keywords: "agencia apdp que es fiscalizador"},
		{cat: "apdp", question: "¿Debo registrarme en la APDP?", answer: "Sí, los responsables deben registrarse ante la APDP indicando: identificación, tipos de datos, finalidades, medidas de seguridad y DPD designado.", keywords: "registro inscripcion obligatorio"},

		{cat: "sanciones", question: "¿Cuáles son las sanciones?", answer: "Amonestación escrita, multas de hasta 20.000 UTM (~$1.300.000 USD), prohibición de tratar datos, y clausura del banco de datos.", keywords: "multas penalidades utm cuanto pagan"},
		{cat: "sanciones", question: "¿Qué factores agravan las sanciones?", answer: "Datos sensibles, afectación de niños, gran volumen, reincidencia, obstrucción, beneficio económico, y no notificar brechas oportunamente.", keywords: "agravantes aumentan factores"},

		{cat: "inventario_datos", question: "¿Cómo hacer un inventario de datos?", answer: "1) Identifica procesos con datos, 2) Mapea el flujo, 3) Clasifica por tipo, 4) Identifica base legal, 5) Determina plazos, 6) Evalúa riesgos. La plataforma Invisia automatiza este proceso.", keywords: "como hacer crear elaborar registro"},
		{cat: "inventario_datos", question: "¿Qué debe incluir el inventario?", answer: "Categorías de datos, finalidades, base legal, titulares, origen, transferencias, plazos, medidas de seguridad y evaluación de riesgos.", keywords: "que incluye contenido campos"},

		{cat: "transferencia", question: "¿Puedo transferir datos fuera de Chile?", answer: "Sí, a países con nivel adecuado de protección (según APDP), o con consentimiento explícito, contratos, o normas corporativas vinculantes.", keywords: "transferir enviar extranjero fuera chile"},

		{cat: "seguridad", question: "¿Qué medidas de seguridad implementar?", answer: "Cifrado, control de acceso, autenticación multifactor, logs, backups cifrados, firewalls, evaluación de vulnerabilidades y plan de respuesta a incidentes.", keywords: "medidas implementar cifrado acceso"},

		{cat: "escaneo", question: "¿Qué es un escaneo de dominio?", answer: "Es un análisis de seguridad web que incluye detección de vulnerabilidades, puertos, subdominios, SSL, DNS y tecnologías. Invisia automatiza todo el proceso.", keywords: "escaneo dominio analisis que es"},
		{cat: "escaneo", question: "¿Qué tipos de escaneo ofrece la plataforma?", answer: "Completo (SQLi, XSS, SSRF, etc.), puertos, subdominios, SSL, DNS, tecnologías, WAF, OWASP Top 10, cumplimiento Ley 21.719, y bases de datos.", keywords: "tipos funcionalidades"},

		{cat: "plataforma", question: "¿Qué es Invisia?", answer: "Plataforma integral de ciberseguridad y cumplimiento normativo para empresas chilenas. Ofrece escaneo, monitoreo, gestión de consentimientos, inventario de datos y reportes.", keywords: "invisia plataforma que es securelab"},
		{cat: "plataforma", question: "¿Cómo empezar?", answer: "Regístrate, configura tu empresa y dominio, selecciona el tipo de escaneo, revisa resultados. Opcionalmente instala SecureLab Agent para monitoreo 24/7.", keywords: "empezar registrarse primeros pasos onboarding"},
		{cat: "plataforma", question: "¿Qué es SecureLab Agent?", answer: "Agente endpoint en Go que se instala como servicio Windows 24/7. Ofrece firewall, bloqueo, telemetría, escaneo de DBs, integración con Ollama y WebSocket.", keywords: "securelab agente endpoint servicio"},

		{cat: "saludo", question: "hola", answer: "¡Hola! Soy el Asistente SecureLab, experto en la Ley 21.719 de Chile y la plataforma Invisia. ¿En qué puedo ayudarte?", keywords: "saludo"},
		{cat: "saludo", question: "gracias", answer: "¡De nada! Estoy aquí para ayudarte con la Ley 21.719 y ciberseguridad. ¡Que tengas un excelente día!", keywords: "agradecimiento"},
		{cat: "saludo", question: "¿quién eres?", answer: "Soy el Asistente SecureLab, guía experto en la Ley 21.719 de Protección de Datos y la plataforma Invisia. Aprendo y mejoro mis respuestas con el tiempo.", keywords: "quien eres presentacion"},
	}

	stmt, _ := a.db.Prepare("INSERT INTO knowledge (category_id, question, answer, keywords, source) VALUES (?, ?, ?, ?, 'seed')")
	for _, s := range seed {
		stmt.Exec(catID(s.cat), s.question, s.answer, s.keywords)
	}
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
	"ese": true, "eso": true, "muy": true, "sin": true, "sobre": true, "tambien": true,
	"fue": true, "ha": true, "han": true, "hay": true, "ser": true, "sido": true,
	"son": true, "era": true, "estan": true, "esta": true, "cada": true, "solo": true,
	"su": true, "mi": true, "tu": true, "me": true, "te": true, "nos": true,
	"cual": true, "cuando": true, "donde": true, "quien": true,
	"si": true, "es": true, "porque": true,
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

func (a *Assistant) categorize(question string) (string, float64, map[string]float64) {
	tokens := tokenize(question)
	if len(tokens) == 0 {
		return "general", 0.1, nil
	}

	bigrams := make([]string, 0)
	for i := 0; i < len(tokens)-1; i++ {
		bigrams = append(bigrams, tokens[i]+" "+tokens[i+1])
	}

	rows, _ := a.db.Query("SELECT category, keyword, weight FROM dictionary")
	if rows != nil {
		defer rows.Close()
	}

	scores := make(map[string]float64)

	for rows != nil && rows.Next() {
		var cat, kw string
		var weight float64
		if err := rows.Scan(&cat, &kw, &weight); err != nil {
			continue
		}

		kwNorm := normalizeText(kw)
		kwTokens := strings.Fields(kwNorm)
		var matchScore float64

		if len(kwTokens) >= 2 {
			kwBigram := kwTokens[0] + " " + kwTokens[1]
			for _, big := range bigrams {
				if big == kwBigram {
					matchScore = max(matchScore, weight*1.3)
				}
			}
		}

		for _, token := range tokens {
			if token == kwNorm {
				matchScore = max(matchScore, weight)
			} else if contains(kwTokens, token) {
				matchScore = max(matchScore, weight*0.8)
			} else if len(token) > 3 && (strings.Contains(token, kwNorm) || strings.Contains(kwNorm, token)) {
				matchScore = max(matchScore, weight*0.4)
			}
		}

		if matchScore > 0 {
			scores[cat] += matchScore
		}
	}

	if len(scores) == 0 {
		return "general", 0.1, scores
	}

	var total float64
	for _, v := range scores {
		total += v
	}

	var best string
	var bestScore float64
	for k, v := range scores {
		if v > bestScore {
			best, bestScore = k, v
		}
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

		kwText := keywords + " " + row.Question
		kwTokens := tokenize(kwText)

		matches := 0
		for _, t := range tokens {
			if contains(kwTokens, t) {
				matches++
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

func (a *Assistant) Ask(question string) AskResult {
	cat, _, scores := a.categorize(question)

	threshold := 0.25
	if cat == "general" {
		threshold = 0.15
	}

	if match := a.findAnswer(question, cat, threshold); match != nil {
		a.logLearning(question, match.Answer, cat, match.Confidence, "matched")
		return AskResult{Answer: match.Answer, Confidence: match.Confidence, Category: cat, Source: "matched", Categories: scores}
	}

	if cat != "general" {
		if match := a.findAnswer(question, "general", 0.1); match != nil {
			a.logLearning(question, match.Answer, "general", match.Confidence, "matched")
			return AskResult{Answer: match.Answer, Confidence: match.Confidence, Category: "general", Source: "matched", Categories: scores}
		}
	}

	if ollamaCfg := getOllamaConfig(); ollamaCfg.Enabled {
		prompt := fmt.Sprintf(`Eres un asistente experto en la Ley 21.719 de Protección de Datos Personales de Chile y ciberseguridad. Responde en español de forma clara, precisa y concisa (máximo 3 párrafos).

Pregunta: %s`, question)

		if answer, err := ollamaAnalyze(prompt); err == nil && answer != "" {
			a.storeLearned(question, answer, cat)
			a.logLearning(question, answer, cat, 0.5, "ollama")
			return AskResult{Answer: answer, Confidence: 0.5, Category: cat, Source: "ollama", Categories: scores}
		}
	}

	fallback := a.getFallback(cat)
	a.logLearning(question, fallback, cat, 0.1, "fallback")
	return AskResult{Answer: fallback, Confidence: 0.1, Category: cat, Source: "fallback", Categories: scores}
}

func (a *Assistant) getFallback(category string) string {
	desc := "tu consulta"
	var d string
	a.db.QueryRow("SELECT description FROM categories WHERE name = ?", category).Scan(&d)
	if d != "" {
		desc = d
	}
	return fmt.Sprintf(`Entiendo que tienes una consulta sobre **%s**. 

Puedes reformular tu pregunta o consultarme sobre los requisitos de la Ley 21.719, cómo implementar protección de datos, el funcionamiento de Invisia, o escaneo de seguridad.`, desc)
}

func (a *Assistant) storeLearned(question, answer, category string) {
	var catID int
	err := a.db.QueryRow("SELECT id FROM categories WHERE name = ?", category).Scan(&catID)
	if err != nil {
		return
	}
	a.db.Exec("INSERT OR IGNORE INTO knowledge (category_id, question, answer, keywords, source) VALUES (?, ?, ?, ?, 'learned')",
		catID, question, answer, normalizeText(question))
}

func (a *Assistant) logLearning(question, answer, category string, confidence float64, source string) {
	a.db.Exec("INSERT INTO learning_log (question, answer, category, confidence, source) VALUES (?, ?, ?, ?, ?)",
		question, answer, category, confidence, source)
}

func (a *Assistant) Learn(question, answer, category string) {
	var catID int
	if category != "" {
		a.db.QueryRow("SELECT id FROM categories WHERE name = ?", category).Scan(&catID)
	}
	if catID == 0 {
		var cat string
		if c, _, _ := a.categorize(question); c != "" {
			cat = c
		} else {
			cat = "general"
		}
		a.db.QueryRow("SELECT id FROM categories WHERE name = ?", cat).Scan(&catID)
	}

	var existing int
	a.db.QueryRow("SELECT id FROM knowledge WHERE question = ?", question).Scan(&existing)
	if existing > 0 {
		a.db.Exec("UPDATE knowledge SET answer = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", answer, existing)
	} else {
		a.db.Exec("INSERT INTO knowledge (category_id, question, answer, keywords, source) VALUES (?, ?, ?, ?, 'manual')",
			catID, question, answer, normalizeText(question))
	}
}

func (a *Assistant) Stats() StatsResult {
	var totalK, totalL int
	a.db.QueryRow("SELECT COUNT(*) FROM knowledge").Scan(&totalK)
	a.db.QueryRow("SELECT COUNT(*) FROM knowledge WHERE source = 'learned'").Scan(&totalL)

	topRows, _ := a.db.Query("SELECT id, category_id, question, answer, confidence, access_count FROM knowledge WHERE enabled = 1 ORDER BY access_count DESC LIMIT 10")
	var top []KnowledgeRow
	if topRows != nil {
		for topRows.Next() {
			var r KnowledgeRow
			if topRows.Scan(&r.ID, &r.CategoryID, &r.Question, &r.Answer, &r.Confidence, &r.AccessCount) == nil {
				top = append(top, r)
			}
		}
		topRows.Close()
	}

	catRows, _ := a.db.Query(`
		SELECT c.name, c.description, COUNT(k.id) as cnt
		FROM categories c LEFT JOIN knowledge k ON k.category_id = c.id
		GROUP BY c.id ORDER BY c.name`)
	var cats []CategoryStat
	if catRows != nil {
		for catRows.Next() {
			var cs CategoryStat
			if catRows.Scan(&cs.Name, &cs.Description, &cs.Count) == nil {
				cats = append(cats, cs)
			}
		}
		catRows.Close()
	}

	return StatsResult{TotalKnowledge: totalK, TotalLearned: totalL, TopQuestions: top, Categories: cats}
}

func (a *Assistant) Search(term string) []KnowledgeRow {
	norm := normalizeText(term)
	like := "%" + norm + "%"
	rows, _ := a.db.Query(`
		SELECT k.id, k.category_id, k.question, k.answer, k.confidence, k.access_count
		FROM knowledge k
		WHERE k.enabled = 1 AND (k.question LIKE ? OR k.answer LIKE ? OR k.keywords LIKE ?)
		ORDER BY k.access_count DESC, k.confidence DESC LIMIT 20`, like, like, like)
	var results []KnowledgeRow
	if rows != nil {
		for rows.Next() {
			var r KnowledgeRow
			if rows.Scan(&r.ID, &r.CategoryID, &r.Question, &r.Answer, &r.Confidence, &r.AccessCount) == nil {
				results = append(results, r)
			}
		}
		rows.Close()
	}
	return results
}

func (a *Assistant) Close() {
	if a.db != nil {
		a.db.Close()
	}
}

func AssistantAsk(question string) AskResult {
	asst := GetAssistant()
	if asst == nil || asst.db == nil {
		return AskResult{Answer: "El asistente no está disponible en este momento.", Confidence: 0, Category: "error", Source: "unavailable"}
	}
	return asst.Ask(question)
}

func max(a, b float64) float64 {
	if a > b {
		return a
	}
	return b
}

func contains(slice []string, item string) bool {
	for _, s := range slice {
		if s == item {
			return true
		}
	}
	return false
}

func init() {
	_ = json.Marshal
	_ = time.Now
}
