package scanner

import (
	"regexp"
	"strings"
)

// Patrones de datos personales según Ley 21.719 (Chile)
// Categorías: identificación, contacto, financiero, salud, biométrico, credenciales, etc.
var PersonalDataPatterns = map[string][]string{
	// Identificación
	"rut": {
		`\b\d{1,2}\.\d{3}\.\d{3}[-][0-9kK]\b`,                              // RUT estándar: 12.345.678-9
		`\b\d{7,8}[-][0-9kK]\b`,                                             // RUT sin puntos: 12345678-9
		`\b\d{1,2}\.\d{3}\.\d{3}\.\d{3}[-][0-9kK]\b`,                        // RUT con 4 grupos
		`(?i)\brut\b`, `(?i)\brun\b`, `(?i)\bdni\b`, `(?i)\bcedula\b`, `(?i)\bdocumento\b`, `(?i)\bid_number\b`,
	},
	"pasaporte": {
		`\b[A-Z]{1,2}\d{6,9}\b`, // Formato genérico pasaporte
		`(?i)\bpasaporte\b`,
	},
	"licencia_conducir": {
		`\b[A-Z]{1,2}\d{6,8}\b`, // Licencia chilena
		`(?i)\blicencia\b.*\bconducir\b`,
	},

	// Contacto
	"email": {
		`\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b`,
		`(?i)\bemail\b`, `(?i)\bmail\b`, `(?i)\bcorreo\b`, `(?i)\bemail_address\b`,
	},
	"telefono_chile": {
		`\b(\+56|0056)?\s*9\s*\d{4}\s*\d{4}\b`,      // Celular: +56 9 XXXX XXXX
		`\b(\+56|0056)?\s*[2-7]\s*\d{3}\s*\d{4}\b`,   // Fijo: +56 2 XXXX XXXX
		`\b\d{4}[-\s]\d{4}\b`,                         // XXXX-XXXX
		`(?i)\btelefono\b`, `(?i)\bphone\b`, `(?i)\bmobile\b`, `(?i)\bcelular\b`,
	},

	// Dirección
	"direccion": {
		`(?i)\b(calle|av|avenida|pasaje|pje|pasaje)\s+[a-záéíóúñ]+\s+\d+\b`,
		`(?i)\b(calle|av|avenida|pasaje|pje)\s+\d+\s+[a-záéíóúñ]+\b`,
		`\b\d{3,5}\s+[a-záéíóúñ\s]{5,}\b`,            // Número + nombre calle
		`(?i)\b(region|región|comuna|ciudad)\s+[a-záéíóúñ]+\b`,
		`(?i)\bdireccion\b`, `(?i)\baddress\b`, `(?i)\bdomicilio\b`,
		`(?i)\bcp\b\s*\d{7}`,                          // Código postal Chile: 7 dígitos
		`\b\d{7}\b`,
	},

	// Fechas
	"fecha_nacimiento": {
		`\b\d{1,2}[-/]\d{1,2}[-/]\d{2,4}\b`,
		`\b\d{4}[-/]\d{1,2}[-/]\d{1,2}\b`,
		`(?i)\bfecha_nacimiento\b`, `(?i)\bbirth_date\b`, `(?i)\bdob\b`,
	},

	// Salud
	"salud": {
		`(?i)\bsalud\b`, `(?i)\bhealth\b`, `(?i)\bmedical\b`, `(?i)\bdiagnostico\b`, `(?i)\benfermedad\b`,
		`(?i)\balergia\b`, `(?i)\bmedicamento\b`, `(?i)\breceta\b`, `(?i)\bhistoria_clinica\b`,
		`(?i)\bficha_medica\b`, `(?i)\btratamiento\b`, `(?i)\bhospital\b`, `(?i)\bclinica\b`,
		`(?i)\bprevision\b`, `(?i)\bprevisional\b`, `(?i)\bafp\b`, `(?i)\bisf\b`, `(?i)\bfonasa\b`,
	},
	"biometrico": {
		`(?i)\bbiometrico\b`, `(?i)\bfingerprint\b`, `(?i)\bhuella\b`, `(?i)\biris\b`, `(?i)\bface_id\b`,
		`(?i)\bgeometria_facial\b`, `(?i)\bvoz\b`, `(?i)\bretina\b`,
	},

	// Financiero
	"bancario": {
		`\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b`, // Tarjeta 16 dígitos
		`\b\d{4}[-\s]?\d{6}[-\s]?\d{5}\b`,             // AMEX
		`\b(?:4|5|3[47]|6)\d{12,15}\b`,                // Visa/MC/Amex/Discover
		`(?i)\bcuenta\b.*\bcorriente\b`, `(?i)\bcuenta\b.*\bvista\b`,
		`(?i)\bbanco\b`, `(?i)\bbank\b`, `(?i)\bcredit_card\b`, `(?i)\btarjeta\b`,
		`(?i)\biban\b`, `(?i)\bswift\b`, `(?i)\bclabe\b`,
		`\b\d{11,17}\b`, // Cuenta bancaria genérica
	},

	// Credenciales
	"credencial": {
		`(?i)\bpassword\b`, `(?i)\bcontraseña\b`, `(?i)\bpasswd\b`, `(?i)\bsecret\b`,
		`(?i)\btoken\b`, `(?i)\bapi_key\b`, `(?i)\bprivate_key\b`, `(?i)\bssh_key\b`,
		`(?i)\bhash\b`, `(?i)\bmd5\b`, `(?i)\bsha256\b`, `(?i)\bbcrypt\b`,
		`\b[A-Za-z0-9+/]{40,}\b`, // Base64 largo
	},

	// Identidad personal
	"nombre": {
		`(?i)\bnombre\b`, `(?i)\bname\b`, `(?i)\bfirst_name\b`, `(?i)\blast_name\b`,
		`(?i)\bapellido\b`, `(?i)\bfull_name\b`, `(?i)\bnombre_completo\b`,
	},
	"nacionalidad": {
		`(?i)\bnacionalidad\b`, `(?i)\bnationality\b`, `(?i)\bchileno\b`, `(?i)\bchilena\b`,
		`(?i)\bextranjero\b`,
	},
	"genero": {
		`(?i)\bgenero\b`, `(?i)\bsexo\b`, `(?i)\bgender\b`, `(?i)\bfemenino\b`, `(?i)\bmasculino\b`,
		`(?i)\bno_binario\b`,
	},
	"estado_civil": {
		`(?i)\bestado_civil\b`, `(?i)\bmarried\b`, `(?i)\bsoltero\b`, `(?i)\bdivorciado\b`, `(?i)\bviudo\b`,
	},
	"hijos": {
		`(?i)\bhijos\b`, `(?i)\bchildren\b`, `(?i)\bdependientes\b`, `(?i)\bmenores_a_cargo\b`,
	},

	// Laboral
	"laboral": {
		`(?i)\bempleador\b`, `(?i)\bempleo\b`, `(?i)\boccupation\b`, `(?i)\bcargo\b`, `(?i)\bprofesion\b`,
		`(?i)\bremuneracion\b`, `(?i)\bsueldo\b`, `(?i)\bsalary\b`, `(?i)\bingresos\b`,
		`(?i)\bcontrato\b`, `(?i)\bfecha_ingreso\b`, `(?i)\bantiguedad\b`,
		`(?i)\bafp\b`, `(?i)\bafp_capital\b`, `(?i)\bafp_habitat\b`, `(?i)\bafp_modelo\b`, `(?i)\bafp_planvital\b`,
		`(?i)\bafp_provida\b`, `(?i)\bafp_uno\b`,
	},

	// Vehicular
	"vehicular": {
		`\b[A-Z]{2}[-\s]\d{2}[-\s][A-Z]{2}\b`,   // Patente antigua: AA-12-BB
		`\b[A-Z]{4}[-\s]\d{2}\b`,                  // Patente nueva: ABCD-12
		`(?i)\bpatente\b`, `(?i)\bvehiculo\b`, `(?i)\bauto\b`, `(?i)\bmoto\b`,
	},

	// Educación
	"educacion": {
		`(?i)\beducacion\b`, `(?i)\bestudios\b`, `(?i)\bgrado\b`, `(?i)\btitulo\b`, `(?i)\buniversidad\b`,
		`(?i)\binstituto\b`, `(?i)\bcolegio\b`, `(?i)\blescolaridad\b`,
	},

	// Legal / Judicial
	"legal": {
		`(?i)\bjudicial\b`, `(?i)\bdemanda\b`, `(?i)\bjuicio\b`, `(?i)\bdenuncia\b`,
		`(?i)\bcondena\b`, `(?i)\bsentencia\b`, `(?i)\bantecedentes\b`, `(?i)\bcertificado_antecedentes\b`,
		`(?i)\bcarabinero\b`, `(?i)\bpdi\b`, `(?i)\bfiscalia\b`, `(?i)\bjuez\b`,
	},

	// Seguros
	"seguro": {
		`(?i)\bseguro\b`, `(?i)\bpoliza\b`, `(?i)\bpolicy\b`, `(?i)\bcobertura\b`,
		`(?i)\bvida\b`, `(?i)\baccidentes\b`, `(?i)\bsalud_complementaria\b`,
	},

	// Patrimonio
	"patrimonio": {
		`(?i)\bpropiedad\b`, `(?i)\binmueble\b`, `(?i)\bterreno\b`, `(?i)\bdepartamento\b`, `(?i)\bcasa\b`,
		`(?i)\bhipoteca\b`, `(?i)\bcredito_hipotecario\b`, `(?i)\bavaluo\b`,
		`(?i)\barrendamiento\b`, `(?i)\balquiler\b`,
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

// DetectPersonalData escanea texto y retorna mapa de categorías encontradas
func DetectPersonalData(text string) map[string]bool {
	results := make(map[string]bool)
	lowerText := strings.ToLower(text)

	for cat, regexes := range compiledRegexPatterns {
		for _, re := range regexes {
			if re.MatchString(text) {
				results[cat] = true
				break
			}
		}
		if !results[cat] {
			for _, kw := range simpleKeywords[cat] {
				if strings.Contains(lowerText, strings.ToLower(kw)) {
					results[cat] = true
					break
				}
			}
		}
	}
	return results
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
		"rut": true, "pasaporte": true, "licencia_conducir": true,
		"email": true, "telefono_chile": true, "direccion": true,
		"salud": true, "biometrico": true,
		"bancario": true, "credencial": true,
		"legal": true,
	}
	for cat := range cats {
		if sensitiveCats[cat] {
			return true
		}
	}
	return false
}
