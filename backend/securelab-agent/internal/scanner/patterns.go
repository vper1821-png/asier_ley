package scanner

import (
	"regexp"
	"strings"
)

// Patrones de datos personales según Ley 21.719 (Chile) y formatos SII
// (facturas, guías de despacho, boletas, notas de crédito/débito, DTE).
var PersonalDataPatterns = map[string][]string{
	// ── Identificación ─────────────────────────────────────────────────
	"rut": {
		// Formatos con puntos y guión: 12.345.678-9
		`\b\d{1,2}\.\d{3}\.\d{3}[-][0-9kK]\b`,
		`\b\d{1,2}\.\d{3}\.\d{3}\.\d{3}[-][0-9kK]\b`,
		// Sin puntos con guión: 12345678-9
		`\b\d{7,8}[-][0-9kK]\b`,
		// Con espacios alrededor del guión: 12.345.678 - 9 / 12345678 - 9
		`\b\d{1,2}\.\d{3}\.\d{3}\s*-\s*[0-9kK]\b`,
		`\b\d{7,8}\s*-\s*[0-9kK]\b`,
		// SII: RUT sin guión con K al final (extracción imperfecta de PDF)
		`\b\d{7,8}[kK]\b`,
		// SII: RUT sin guión sin K: 76351870 (7-8 dígitos sueltos precedidos de contexto)
		`(?i)\brut\s*:?\s*\d{7,8}\b`,
		// Keywords
		`(?i)\brut\b`, `(?i)\brun\b`, `(?i)\bdni\b`, `(?i)\bcedula\b`,
		`(?i)\bdocumento\b`, `(?i)\bid_number\b`,
		// R.U.T. / R.U.N. con puntos (formato típico SII)
		`(?i)r\s*\.\s*u\s*\.\s*t`,
		`(?i)r\s*\.\s*u\s*\.\s*n`,
	},
	"pasaporte": {
		`\b[A-Z]{1,2}\d{6,9}\b`,
		`(?i)\bpasaporte\b`,
		`(?i)\bpassport\b`,
	},
	"licencia_conducir": {
		`\b[A-Z]{1,2}\d{6,8}\b`,
		`(?i)\blicencia\b.*\bconducir\b`,
		`(?i)\blicencia\s+de\s+conducir\b`,
	},

	// ── Contacto ───────────────────────────────────────────────────────
	"email": {
		`\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b`,
		`(?i)\bemail\b`, `(?i)\bmail\b`, `(?i)\bcorreo\b`, `(?i)\be-?mail\b`,
	},
	"telefono_chile": {
		`(?i)(\+?56[\s.-]?)?9[\s.-]?\d{4}[\s.-]?\d{4}`,     // Celular flexible
		`(?i)(\+?56[\s.-]?)?[2-7][\s.-]?\d{3}[\s.-]?\d{4}`, // Fijo flexible
		`\b\d{4}[-\s]\d{4}\b`,
		`(?i)\btelefono\b`, `(?i)\btel[eé]fono\b`, `(?i)\bphone\b`,
		`(?i)\bmobile\b`, `(?i)\bcelular\b`, `(?i)\bcontacto\b`,
	},
	"direccion": {
		`(?i)\b(calle|av|avenida|avda|pasaje|pje|prolongaci[oó]n)\s+[a-záéíóúñ\s]{3,40}\d+\b`,
		`\b\d{3,5}\s+[a-záéíóúñ\s]{5,}\b`,
		// Keywords de dirección en documentos SII
		`(?i)\bdirecci[oó]n\b`,
		`(?i)\bdomicilio\b`,
		`(?i)\baddress\b`,
		`(?i)\bcomuna\b`,
		`(?i)\bprovincia\b`,
		`(?i)\bregi[oó]n\b`,
		`(?i)\bciudad\b`,
		`(?i)\bc[oó]digo\s+postal\b`,
		`(?i)\bcp\b\s*\d{7}`,
		`\b\d{7}\b`,
	},

	// ── Fechas ─────────────────────────────────────────────────────────
	"fecha_nacimiento": {
		`\b\d{1,2}[-/]\d{1,2}[-/]\d{2,4}\b`,
		`\b\d{4}[-/]\d{1,2}[-/]\d{1,2}\b`,
		`(?i)\bfecha\s+de\s+nacimiento\b`,
		`(?i)\bfecha_nacimiento\b`,
		`(?i)\bbirth_date\b`, `(?i)\bdob\b`,
	},

	// ── Salud ──────────────────────────────────────────────────────────
	"salud": {
		`(?i)\bsalud\b`, `(?i)\bhealth\b`, `(?i)\bmedical\b`, `(?i)\bdiagn[oó]stico\b`,
		`(?i)\benfermedad\b`, `(?i)\balergia\b`, `(?i)\bmedicamento\b`, `(?i)\breceta\b`,
		`(?i)\bhistoria\s+cl[ií]nica\b`, `(?i)\bficha\s+m[eé]dica\b`, `(?i)\btratamiento\b`,
		`(?i)\bhospital\b`, `(?i)\bcl[ií]nica\b`, `(?i)\bprevisi[oó]n\b`,
		`(?i)\bprevisional\b`, `(?i)\bafp\b`, `(?i)\bisf\b`, `(?i)\bfonasa\b`,
	},
	"biometrico": {
		`(?i)\bbiom[eé]trico\b`, `(?i)\bfingerprint\b`, `(?i)\bhuella\b`,
		`(?i)\biris\b`, `(?i)\bface_id\b`, `(?i)\bgeometr[ií]a\s+facial\b`,
		`(?i)\bretina\b`,
	},

	// ── Financiero ─────────────────────────────────────────────────────
	"bancario": {
		`\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b`,
		`\b\d{4}[-\s]?\d{6}[-\s]?\d{5}\b`,
		`\b(?:4|5|3[47]|6)\d{12,15}\b`,
		`(?i)\bcuenta\b.*\bcorriente\b`,
		`(?i)\bcuenta\b.*\bvista\b`,
		`(?i)\bbanco\b`, `(?i)\bbank\b`, `(?i)\bcredit_card\b`,
		`(?i)\btarjeta\b`, `(?i)\biban\b`, `(?i)\bswift\b`, `(?i)\bclabe\b`,
		`\b\d{11,17}\b`,
	},

	// ── Credenciales ───────────────────────────────────────────────────
	"credencial": {
		`(?i)\bpassword\b`, `(?i)\bcontrase[ñn]a\b`, `(?i)\bpasswd\b`,
		`(?i)\bsecret\b`, `(?i)\btoken\b`, `(?i)\bapi_key\b`, `(?i)\bapikey\b`,
		`(?i)\bprivate_key\b`, `(?i)\bssh_key\b`,
		`(?i)\bhash\b`, `(?i)\bmd5\b`, `(?i)\bsha256\b`, `(?i)\bbcrypt\b`,
		`\b[A-Za-z0-9+/]{40,}\b`,
	},

	// ── Identidad personal ─────────────────────────────────────────────
	"nombre": {
		`(?i)\bnombre\b`, `(?i)\bname\b`, `(?i)\bfirst_name\b`, `(?i)\blast_name\b`,
		`(?i)\bapellido\b`, `(?i)\bfull_name\b`, `(?i)\bnombre_completo\b`,
		`(?i)\bse[ñn]or(?:\(es\)|es)?\b`, // "SEÑOR(ES)" en facturas
		`(?i)\btitular\b`,
	},
	"razon_social": {
		`(?i)\raz[oó]n\s+social\b`,
		`(?i)\braz[oó]n_social\b`,
		`(?i)\bempresa\b`,
		`(?i)\bcontribuyente\b`,
		`(?i)\bvendedor\b`,
		`(?i)\bcomprador\b`,
		`(?i)\bcliente\b`,
		`(?i)\bgiro\b`,
	},
	"nacionalidad": {
		`(?i)\bnacionalidad\b`, `(?i)\bnationality\b`,
		`(?i)\bchileno\b`, `(?i)\bchilena\b`, `(?i)\bextranjero\b`,
	},
	"genero": {
		`(?i)\bg[eé]nero\b`, `(?i)\bsexo\b`, `(?i)\bgender\b`,
		`(?i)\bfemenino\b`, `(?i)\bmasculino\b`, `(?i)\bno_binario\b`,
	},
	"estado_civil": {
		`(?i)\bestado_civil\b`, `(?i)\bmarried\b`, `(?i)\bsoltero\b`,
		`(?i)\bdivorciado\b`, `(?i)\bviudo\b`,
	},
	"hijos": {
		`(?i)\bhijos\b`, `(?i)\bchildren\b`, `(?i)\bdependientes\b`,
		`(?i)\bmenores_a_cargo\b`,
	},

	// ── Laboral ────────────────────────────────────────────────────────
	"laboral": {
		`(?i)\bempleador\b`, `(?i)\bempleo\b`, `(?i)\boccupation\b`,
		`(?i)\bcargo\b`, `(?i)\bprofesi[oó]n\b`, `(?i)\bremuneraci[oó]n\b`,
		`(?i)\bsueldo\b`, `(?i)\bsalary\b`, `(?i)\bingresos\b`,
		`(?i)\bcontrato\b`, `(?i)\bfecha_ingreso\b`, `(?i)\bantig[uü]edad\b`,
		`(?i)\bafp\b`, `(?i)\bafp_capital\b`, `(?i)\bafp_habitat\b`,
		`(?i)\bafp_modelo\b`, `(?i)\bafp_planvital\b`, `(?i)\bafp_provida\b`,
		`(?i)\bafp_uno\b`,
	},

	// ── Vehicular (guías de despacho) ─────────────────────────────────
	"vehicular": {
		`\b[A-Z]{2}[-\s]\d{2}[-\s][A-Z]{2}\b`, // Patente antigua AA-12-BB
		`\b[A-Z]{4}[-\s]\d{2}\b`,              // Patente nueva ABCD-12
		`(?i)\bpatente\b`,
		`(?i)\bveh[ií]culo\b`,
		`(?i)\bautom[oó]vil\b`,
		`(?i)\bauto\b`,
		`(?i)\bmoto\b`,
		`(?i)\bcami[oó]n\b`,
	},
	"chofer": {
		`(?i)\bchofer\b`,
		`(?i)\bconductor\b`,
		`(?i)\btransportista\b`,
		`(?i)\brut\s+chofer\b`,
		`(?i)\brut\s+conductor\b`,
		`(?i)\brut\s+transportista\b`,
		`(?i)\bnombre\s+chofer\b`,
	},

	// ── Educación ──────────────────────────────────────────────────────
	"educacion": {
		`(?i)\beducaci[oó]n\b`, `(?i)\bestudios\b`, `(?i)\bgrado\b`,
		`(?i)\bt[ií]tulo\b`, `(?i)\buniversidad\b`, `(?i)\binstituto\b`,
		`(?i)\bcolegio\b`, `(?i)\blescolaridad\b`,
	},

	// ── Legal / Judicial ───────────────────────────────────────────────
	"legal": {
		`(?i)\bjudicial\b`, `(?i)\bdemanda\b`, `(?i)\bjuicio\b`,
		`(?i)\bdenuncia\b`, `(?i)\bcondena\b`, `(?i)\bsentencia\b`,
		`(?i)\bantecedentes\b`, `(?i)\bcertificado\s+de\s+antecedentes\b`,
		`(?i)\bcarabinero\b`, `(?i)\bpdi\b`, `(?i)\bfiscal[ií]a\b`, `(?i)\bjuez\b`,
	},

	// ── Seguros ────────────────────────────────────────────────────────
	"seguro": {
		`(?i)\bseguro\b`, `(?i)\bp[oó]liza\b`, `(?i)\bpolicy\b`,
		`(?i)\bcobertura\b`, `(?i)\bvida\b`, `(?i)\baccidentes\b`,
		`(?i)\bsalud\s+complementaria\b`,
	},

	// ── Patrimonio ─────────────────────────────────────────────────────
	"patrimonio": {
		`(?i)\bpropiedad\b`, `(?i)\binmueble\b`, `(?i)\bterreno\b`,
		`(?i)\bdepartamento\b`, `(?i)\bcasa\b`, `(?i)\bhipoteca\b`,
		`(?i)\bcr[eé]dito\s+hipotecario\b`, `(?i)\baval[uú]o\b`,
		`(?i)\barrendamiento\b`, `(?i)\balquiler\b`,
	},

	// ── Documento tributario SII ───────────────────────────────────────
	"documento_tributario": {
		`(?i)\bfactura\b`,
		`(?i)\bfactura\s+electr[oó]nica\b`,
		`(?i)\bboleta\b`,
		`(?i)\bboleta\s+electr[oó]nica\b`,
		`(?i)\bgu[ií]a\s+de\s+despacho\b`,
		`(?i)\bgu[ií]a\s+de\s+despacho\s+electr[oó]nica\b`,
		`(?i)\bnota\s+de\s+cr[eé]dito\b`,
		`(?i)\bnota\s+de\s+d[eé]bito\b`,
		`(?i)\bmanifiesto\s+de\s+despacho\b`,
		`(?i)\bdocumento\s+tributario\s+electr[oó]nico\b`,
		`(?i)\bdte\b`,
		`(?i)\btimbre\s+electr[oó]nico\s+sii\b`,
		`(?i)\bsii\b`,
		`(?i)\bservicio\s+de\s+impuestos\s+internos\b`,
		`(?i)\bgiro\b`,
		`(?i)\bvendedor\b`,
		`(?i)\bcomprador\b`,
		`(?i)\bforma\s+de\s+pago\b`,
		`(?i)\breferencia\b`,
		`(?i)\bvencimiento\b`,
		`(?i)\bsub-?total\b`,
		`(?i)\bneto\b`,
		`(?i)\biva\b`,
		`(?i)\bexento\b`,
		`(?i)\btotal\b`,
	},
}

var (
	compiledRegexPatterns map[string][]*regexp.Regexp
	simpleKeywords        map[string][]string
)

func init() {
	compiledRegexPatterns = make(map[string][]*regexp.Regexp)
	simpleKeywords = make(map[string][]string)

	for cat, patterns := range PersonalDataPatterns {
		var regexes []*regexp.Regexp
		var keywords []string
		for _, p := range patterns {
			// Si parece un regex (contiene metacaracteres), compilarlo
			if strings.ContainsAny(p, ".[](){}*+?^$|\\") && !strings.HasPrefix(p, "(?i)") {
				if re, err := regexp.Compile(p); err == nil {
					regexes = append(regexes, re)
				} else {
					keywords = append(keywords, p)
				}
			} else if strings.HasPrefix(p, "(?i)") {
				if re, err := regexp.Compile(p); err == nil {
					regexes = append(regexes, re)
				}
			} else {
				keywords = append(keywords, p)
			}
		}
		compiledRegexPatterns[cat] = regexes
		simpleKeywords[cat] = keywords
	}
}

// DetectPersonalData escanea texto y retorna mapa de categorías encontradas.
// Aplica los patrones al texto original y a una versión "compactada" en la
// que se eliminan espacios entre dígitos (habituales en extracción de PDFs
// generados por el SII: "7 6 3 5 1 8 7 0 - 1" → "76351870-1").
func DetectPersonalData(text string) map[string]bool {
	results := make(map[string]bool)
	lowerText := strings.ToLower(text)
	compact := collapseDigits(text)
	lowerCompact := strings.ToLower(compact)

	for cat, regexes := range compiledRegexPatterns {
		matched := false
		for _, re := range regexes {
			if re.MatchString(text) || re.MatchString(compact) {
				matched = true
				break
			}
		}
		if !matched {
			for _, kw := range simpleKeywords[cat] {
				kwLower := strings.ToLower(kw)
				if strings.Contains(lowerText, kwLower) || strings.Contains(lowerCompact, kwLower) {
					matched = true
					break
				}
			}
		}
		if matched {
			results[cat] = true
		}
	}
	return results
}

// collapseDigits elimina espacios entre dígitos y alrededor de guiones que
// aparecen entre dígitos. Útil para PDFs del SII donde el extractor separa
// cada carácter del RUT.
//
//	"7 6 3 5 1 8 7 0 - 1"    → "76351870-1"
//	"12.345.678 - 9"          → "12.345.678-9"
//	"15 738 630 - 1"          → "15738630-1"
func collapseDigits(s string) string {
	var b strings.Builder
	b.Grow(len(s))
	runes := []rune(s)

	isDigit := func(r rune) bool { return r >= '0' && r <= '9' }

	for i := 0; i < len(runes); i++ {
		r := runes[i]
		if r == ' ' || r == '\t' {
			prevDigit := i > 0 && isDigit(runes[i-1])
			nextDigit := i+1 < len(runes) && isDigit(runes[i+1])
			prevHyphen := i > 0 && runes[i-1] == '-'
			nextHyphen := i+1 < len(runes) && runes[i+1] == '-'
			prevDot := i > 0 && runes[i-1] == '.'
			nextDot := i+1 < len(runes) && runes[i+1] == '.'

			// Espacio entre dígitos → colapsar
			if prevDigit && nextDigit {
				continue
			}
			// Espacio entre dígito y guión, o guión y dígito → colapsar
			if (prevDigit && nextHyphen) || (prevHyphen && nextDigit) {
				continue
			}
			// Espacio entre dígito y punto de miles, o punto y dígito
			if (prevDigit && nextDot) || (prevDot && nextDigit) {
				continue
			}
		}
		b.WriteRune(r)
	}
	return b.String()
}

// GetDetectedCategories retorna lista de categorías detectadas
func GetDetectedCategories(text string) []string {
	m := DetectPersonalData(text)
	cats := make([]string, 0, len(m))
	for cat := range m {
		cats = append(cats, cat)
	}
	return cats
}

// HasSensitiveData verifica si hay datos sensibles (categorías críticas)
func HasSensitiveData(cats map[string]bool) bool {
	sensitiveCats := map[string]bool{
		// Datos sensibles en sentido estricto (Ley 21.719)
		"salud": true, "biometrico": true,

		// Identificación directa
		"rut": true, "pasaporte": true, "licencia_conducir": true,

		// Contacto
		"email": true, "telefono_chile": true, "direccion": true,

		// Financiero y credenciales
		"bancario": true, "credencial": true,

		// Judicial
		"legal": true,

		// Identidad personal
		"nombre": true, "razon_social": true,
		"nacionalidad": true, "hijos": true, "seguro": true, "patrimonio": true,

		// Vehicular y chofer
		"vehicular": true, "chofer": true,

		// Documentos tributarios
		"documento_tributario": true,

		// PDFs especiales que requieren revisión
		"pdf_cifrado":             true, // con contraseña, no auditable
		"pdf_escaneado":           true, // solo imágenes, requiere OCR
		"documento_no_analizable": true, // falló la extracción por otra razón
	}
	for cat := range cats {
		if sensitiveCats[cat] {
			return true
		}
	}
	return false
}
