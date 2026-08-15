package scanner

import "regexp"

// Patrones de datos personales según Ley 21.719
var PersonalDataPatterns = map[string][]string{
	"rut":        {"rut", "run", "dni", "cedula", "documento", "id_number", "national_id"},
	"nombre":     {"nombre", "name", "first_name", "last_name", "apellido", "full_name"},
	"email":      {"email", "mail", "correo", "email_address"},
	"telefono":   {"telefono", "phone", "mobile", "celular", "phone_number"},
	"direccion":  {"direccion", "address", "domicilio", "street", "calle"},
	"fecha_nac":  {"fecha_nacimiento", "birth_date", "dob", "date_of_birth"},
	"salud":      {"salud", "health", "medical", "diagnostico", "enfermedad"},
	"biometrico": {"biometrico", "fingerprint", "huella", "iris", "face_id"},
	"bancario":   {"cuenta_bancaria", "bank_account", "credit_card", "tarjeta"},
	"credencial": {"password", "contraseña", "hash", "secret", "token"},
}

var compiledPatterns map[string][]*regexp.Regexp

func init() {
	compiledPatterns = make(map[string][]*regexp.Regexp)
	for cat, patterns := range PersonalDataPatterns {
		for _, p := range patterns {
			re := regexp.MustCompile(`(?i)\b` + regexp.QuoteMeta(p) + `\b`)
			compiledPatterns[cat] = append(compiledPatterns[cat], re)
		}
	}
}

func DetectPersonalData(text string) (bool, string) {
	for cat, regexes := range compiledPatterns {
		for _, re := range regexes {
			if re.MatchString(text) {
				return true, cat
			}
		}
	}
	return false, ""
}
