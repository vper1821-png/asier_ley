package main

import (
	"bufio"
	"database/sql"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	_ "github.com/denisenkom/go-mssqldb"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
)

// ──────────────────────────────────────────────
// Constants & Configuration
// ──────────────────────────────────────────────

const (
	monitorInterval     = 5 * time.Second
	logDiscoveryIntvl   = 5 * time.Minute
	userStaleTimeout    = 5 * time.Minute
	alertDedupCritical  = 30 * time.Second
	alertDedupHigh      = 1 * time.Minute
	alertDedupMedium    = 3 * time.Minute
	alertDedupDefault   = 5 * time.Minute
	queryResetThreshold = 10000
	maxQueryHistory     = 5000
)

// ──────────────────────────────────────────────
// Data Structures
// ──────────────────────────────────────────────

type ActivityMonitor struct {
	mu                sync.RWMutex
	connection        *DBConnection
	connections       map[string]*DBConnection
	stopCh            chan struct{}
	lastCheckTime     time.Time

	// Discovery
	discoveredLogs    []DBLogFileInfo
	sensitiveTables   []SensitiveTableDef
	personalDataCols  map[string][]string

	// Tracking
	activeUsers       map[string]*UserTrack
	activeQueries     map[string]*QueryTrack
	tableAccess       map[string]*TableAccessStats
	userProfiles      map[string]*UserBehavior
	knownProcesses    map[int]ProcessTrack
	queryHistory      []HistoricalQuery
	connectionRecords []ConnectionRecord

	// Alert dedup
	lastAlerts        map[string]time.Time
	lastUserEvents    map[string]time.Time
	summaryTicker     int64

	// Host-level
	lastProcessScan   time.Time
	lastLogScan       time.Time
	lastLogRead       time.Time
	detectedDumps     []DumpDetection
	startTime         time.Time

	// Config
	dbDirs            []DBInstallationInfo

	// Query log buffer for compliance logging
	pendingQueryLogs  []QueryLogEntry

	// Performance schema tracking
	lastHistoryID       int64
	lastHistoryTime     time.Time
	lastHistoryThreadID int64

	// General log table polling (mysql.general_log)
	generalLogTailing   bool
	lastTablePollTime   time.Time
	lastTableMaxTime    time.Time
	seenGeneralQueries  map[string]bool
	generalPollCount    int

	// PostgreSQL audit tracking
	lastPGActivityID    int64
	seenPGQueries       map[string]bool

	// MSSQL audit tracking
	lastMSSQLSessionID  int64
	seenMSSQLQueries    map[string]bool

	// MongoDB native monitor
	mongoNative *MongoDBMonitor
}

type SensitiveTableDef struct {
	Name    string
	Columns []SensitiveColumn
}

type SensitiveColumn struct {
	Name     string
	Category string
	Sensitive bool
}

type UserTrack struct {
	User        string
	Host        string
	FirstSeen   time.Time
	LastSeen    time.Time
	QueryCount  int64
	TablesTouched map[string]int
	Anomalies     int
}

type QueryTrack struct {
	Hash        string
	Normalized  string
	User        string
	FirstSeen   time.Time
	LastSeen    time.Time
	Count       int64
	AvgInterval float64
	LastTiming  []time.Time
}

type TableAccessStats struct {
	Name          string
	ReadCount     int64
	WriteCount    int64
	DDLCount      int64
	LastAccess    time.Time
	LastReadQuery string
	IsSensitive   bool
	Users         map[string]int
}

type UserBehavior struct {
	User           string
	TypicalHours   map[int]int
	TypicalQueries map[string]int
	TotalQueries   int64
	FirstActivity  time.Time
	LastActivity   time.Time
	AnomalyCount   int
	TablesUsed     map[string]int
	HostsUsed      map[string]int
}

type HistoricalQuery struct {
	Timestamp   time.Time
	User        string
	Host        string
	Database    string
	Query       string
	Normalized  string
	Duration    float64
	RowsExamined int64
	Type        QueryClass
	RiskScore   int
}

type ConnectionRecord struct {
	User      string
	Host      string
	StartTime time.Time
	EndTime   time.Time
	Queries   int
}

type DumpDetection struct {
	Type        string
	Description string
	Command     string
	PID         int
	User        string
	DetectedAt  time.Time
	Severity    string
}

type QueryLogEntry struct {
	Timestamp time.Time `json:"Timestamp"`
	User      string    `json:"User"`
	Host      string    `json:"Host"`
	Database  string    `json:"Database"`
	Query     string    `json:"Query"`
	Engine    string    `json:"Engine"`
	Operation string    `json:"Operation"`
	Tables    []string  `json:"Tables,omitempty"`
}

type DBLogFileInfo struct {
	Path         string
	SizeBytes    int64
	ModTime      time.Time
	Engine       string
	LogType      string
	Accessible   bool
	Lines        int64
	LastEntries  []string
}

type DBInstallationInfo struct {
	Engine      string
	Version     string
	InstallDir  string
	DataDir     string
	ConfigPath  string
	Port        int
	ServiceName string
	LogDirs     []string
	Running     bool
}

type ActivitySummary struct {
	Uptime          string          `json:"uptime"`
	TotalQueries    int64           `json:"totalQueries"`
	ActiveUsers     int             `json:"activeUsers"`
	TablesTracked   int             `json:"tablesTracked"`
	AnomaliesFound  int             `json:"anomaliesFound"`
	DumpsDetected   int             `json:"dumpsDetected"`
	LogsDiscovered  int             `json:"logsDiscovered"`
	Connections     int             `json:"connections"`
	TopUsers        []UserStat      `json:"topUsers"`
	TopTables       []TableStat     `json:"topTables"`
	RecentAlerts    []string        `json:"recentAlerts"`
}

type UserStat struct {
	User        string `json:"user"`
	Queries     int64  `json:"queries"`
	Tables      int    `json:"tables"`
	Anomalies   int    `json:"anomalies"`
	LastActive  string `json:"lastActive"`
}

type TableStat struct {
	Name       string `json:"name"`
	Accesses   int64  `json:"accesses"`
	Users      int    `json:"users"`
	Sensitive  bool   `json:"sensitive"`
	LastAccess string `json:"lastAccess"`
}

type QueryClass int

const (
	QueryUnknown QueryClass = iota
	QuerySelect
	QueryInsert
	QueryUpdate
	QueryDelete
	QueryDDLAlter
	QueryDDLDrop
	QueryDDLCreate
	QueryDCLGrant
	QueryDCLRevoke
	QueryDMLTruncate
	QueryUtility
	QueryShow
	QuerySet
	QueryTransaction
	QueryExport
	QueryImport
)

func (q QueryClass) String() string {
	if name, ok := queryClassNames[q]; ok {
		return name
	}
	return "UNKNOWN"
}

var queryClassNames = map[QueryClass]string{
	QueryUnknown:     "UNKNOWN",
	QuerySelect:      "SELECT",
	QueryInsert:      "INSERT",
	QueryUpdate:      "UPDATE",
	QueryDelete:      "DELETE",
	QueryDDLAlter:    "DDL_ALTER",
	QueryDDLDrop:     "DDL_DROP",
	QueryDDLCreate:   "DDL_CREATE",
	QueryDCLGrant:    "DCL_GRANT",
	QueryDCLRevoke:   "DCL_REVOKE",
	QueryDMLTruncate: "DML_TRUNCATE",
	QueryUtility:     "UTILITY",
	QueryShow:        "SHOW",
	QuerySet:         "SET",
	QueryTransaction: "TRANSACTION",
	QueryExport:      "EXPORT",
	QueryImport:      "IMPORT",
}

// ──────────────────────────────────────────────
// Personal Data Patterns (extended from db_scanner.go)
// ──────────────────────────────────────────────

var extendedPersonalPatterns = map[string][]string{
	"nombre":       {"nombre", "name", "first_name", "last_name", "apellido", "full_name", "nombres", "nombre_completo", "legal_name", "display_name", "nickname", "razon_social", "business_name", "representante", "nombres_apellidos", "nombre_legal", "alias", "apodo", "sobrenombre", "nombre_social", "nombre_fantasia", "name_complete"},
	"email":        {"email", "e-mail", "mail", "correo", "email_address", "email_addr", "mail_addr", "electronic_mail", "correo_electronico", "correo_personal", "correo_laboral", "email_contacto", "email_principal", "email_secundario", "email_alternativo", "personal_email", "work_email", "business_email"},
	"rut":          {"rut", "run", "dni", "cedula", "documento", "id_number", "national_id", "passport", "identificacion", "tax_id", "social_security", "ssn", "nif", "nie", "nit", "cuil", "cuit", "num_documento", "nro_documento", "id_documento", "tipo_documento", "dv", "digito_verificador", "rol_unico_tributario", "rol_unico_nacional", "carnet_identidad", "cedula_identidad", "documento_identidad", "identificador_unico", "id_card", "identity_card", "licencia_conducir", "driver_license", "nro_licencia", "pasaporte", "passport_number", "documento_extranjeria", "idext", "num_identificacion"},
	"telefono":     {"telefono", "phone", "mobile", "celular", "phone_number", "contact", "movil", "teléfono", "telefono_movil", "whatsapp", "fax", "numero_contacto", "telefono_contacto", "contacto_emergencia", "nro_telefono", "num_telefono", "telefono_celular", "tel_celular", "telefono_fijo", "tel_fijo", "telefono_particular", "tel_particular", "telefono_laboral", "tel_laboral", "telefono_emergencia", "emergency_phone", "mobile_phone", "home_phone", "work_phone", "telefono_alternativo"},
	"direccion":    {"direccion", "address", "domicilio", "street", "calle", "location", "dirección", "residencia", "vivienda", "zip_code", "codigo_postal", "postal_code", "city", "ciudad", "provincia", "state", "region", "comuna", "municipio", "distrito", "barrio", "sector", "poblacion", "villa", "localidad", "aldea", "caserio", "departamento", "oficina", "numero_casa", "nro_casa", "block", "torre", "piso", "depto", "direccion_particular", "direccion_laboral", "direccion_comercial", "direccion_facturacion", "billing_address", "shipping_address", "envio", "despacho", "direccion_residencia", "domicilio_particular", "domicilio_laboral", "direccion_legal", "calle_numero"},
	"fecha_nac":    {"fecha_nacimiento", "birth_date", "dob", "date_of_birth", "nacimiento", "birthday", "birth", "fecha_nac", "birth_year", "ano_nacimiento", "fecha_nacimiento", "fecha_nac", "ano_nac", "dia_nacimiento", "mes_nacimiento", "fecha_de_nacimiento", "fec_nac", "birthdate", "fecha_birth", "nac", "nacio", "born", "fecha_nacimiento_personal"},
	"salud":        {"salud", "health", "medical", "diagnostico", "enfermedad", "seguro_medico", "discapacidad", "historial_medico", "clinical", "patient", "paciente", "alergia", "allergy", "blood", "sangre", "tipo_sangre", "isapre", "fonasa", "prevision_salud", "prevision", "licencia_medica", "receta", "prescription", "hospital", "clinica", "diagnosis", "tratamiento", "treatment", "enfermedad_previa", "condicion_medica", "impedimento", "medicamento", "medicine", "droga", "drug", "vacuna", "vaccine", "grupo_sangre", "factor_rh", "alergia_medicamento", "bonos", "copago", "prestaciones_salud", "prestacion", "doctor", "medico_titulo", "medico_tratante", "cirugia", "surgery", "operacion", "operation", "hospitalizacion", "hospitalization", "examen_medico", "exam", "laboratorio", "lab", "radiografia", "xray", "ecografia", "ultrasound", "scanner_medico", "resonancia", "mri", "terapia", "therapy", "rehabilitacion", "rehabilitation", "kinesiologia", "kinesiologo", "psicologia", "psicologo", "psiquiatria", "psiquiatra", "dental", "dentista", "odontologia", "oftalmologia", "oftalmologo", "traumatologia", "cardiologia", "neurologia", "oncologia", "pediatria", "ginecologia", "obstetricia", "dermatologia", "urgencia", "emergencia", "emergency", "internacion", "alta_medica", "fallecimiento", "defuncion", "death", "causa_muerte", "autopsia", "morbid", "secuela", "complicacion", "enfermedad_cronica", "enfermedad_terminal", "discapacidad_fisica", "discapacidad_mental", "discapacidad_sensorial", "sordera", "ceguera", "paralisis", "amputacion", "transfusion", "donacion_organos", "organ_donation", "seguro_salud", "health_insurance", "seguro_medico_proveedor"},
	"biometrico":   {"biometrico", "biometric", "fingerprint", "huella", "iris", "face_id", "biometria", "dna", "genetic", "genetica", "voice_print", "facial", "reconocimiento_facial", "huella_dactilar", "geometria_mano", "firma_autografa", "firma_electronica", "electronica", "retina", "vena", "huella_digital", "dactilar", "palm_print", "mano", "geometria", "voz", "voice", "iris_pattern", "retina_pattern", "dna_profile", "perfil_genetico", "genetico", "cromosoma", "chromosome", "gene", "gen", "herencia_genetica", "rastreo_biometrico", "biometric_template", "biometric_data"},
	"bancario":     {"cuenta_bancaria", "bank_account", "credit_card", "tarjeta", "cvv", "iban", "account_number", "routing", "swift", "bic", "card_number", "cc_number", "paypal", "financial", "banco", "bank", "ahorros", "corriente", "cheque", "check", "inversion", "investment", "hipoteca", "mortgage", "prestamo", "loan", "credito", "credit", "debito", "debit", "afp", "administradora_fondo", "pension", "jubilacion", "retiro", "deposito", "deposit", "transferencia", "transfer", "abono", "cargo_bancario", "saldo_bancario", "balance", "deuda", "debt", "morosidad", "default", "dividendo", "dividend", "cuota", "installment", "interes_bancario", "interest_rate", "tasa_interes", "comision_bancaria", "fee", "giro_bancario", "draft", "efectivo", "cash", "cajero", "atm", "tarjeta_debito", "debit_card", "tarjeta_credito", "credit_card_number", "codigo_seguridad_tarjeta", "card_cvv", "fecha_vencimiento_tarjeta", "card_expiry", "titular_tarjeta", "card_holder", "tipo_cuenta", "account_type", "numero_cuenta", "bank_account_number", "clabe", "clave_bancaria", "routing_number", "swift_code", "iban_code", "cripto", "crypto", "cryptocurrency", "wallet_address", "billetera_digital", "bitcoin", "ethereum", "exchange"},
	"credencial":   {"password", "contraseña", "hash", "secret", "token", "auth_key", "api_key", "secret_key", "pwd", "pass", "credential", "login", "pin", "otp", "2fa", "access_key", "private_key", "public_key", "certificate", "certificado", "firma_digital", "digital_signature", "clave_secreta", "secret_token", "refresh_token", "jwt", "bearer_token", "authorization", "authorization_code", "access_token", "session_token", "csrf_token", "csrf", "auth_token", "authentication_key", "clave_api", "api_secret", "app_secret", "app_key", "consumer_key", "consumer_secret", "signing_key", "encryption_key", "llave_privada", "llave_publica", "cert_thumbprint", "fingerprint_ssl", "ssh_key", "private_ssh"},
	"ip":           {"ip_address", "ip", "direccion_ip", "client_ip", "remote_addr", "ip_addr", "ipv4", "ipv6", "mac_address", "mac", "direccion_mac", "direccion_ip", "ip_origen", "source_ip", "dest_ip", "ip_destino", "network_address", "subnet", "mascara_red", "netmask", "gateway", "dns_address", "dns_server"},
	"ubicacion":    {"ubicacion", "location", "gps", "latitud", "longitud", "coordinates", "latitude", "longitude", "geo", "geolocation", "position", "coordenada", "coordenadas_gps", "lat", "lon", "altitud", "altitude", "punto_referencia", "landmark", "zona_horaria", "timezone", "huso_horario", "mapa", "map", "cartografia", "navigation", "navegacion", "geocerca", "geofence", "tracking_location"},
	"genero":       {"genero", "gender", "sexo", "sex", "sexual", "orientacion", "lgbt", "identidad_genero", "transgenero", "intersexual", "transexual", "cisgenero", "no_binario", "gender_identity", "sexual_orientation", "expresion_genero", "gender_expression", "rol_genero", "gender_role", "cambio_sexo", "reasignacion_sexo", "lgbtiq", "lgbtq"},
	"edad":         {"edad", "age", "birth_year", "años", "years_old", "rango_edad", "age_range", "mayor_edad", "edad_actual", "tramo_etario", "grupo_edad", "age_group", "edad_paciente", "edad_cliente", "edad_usuario", "edad_empleado", "rango_etario", "fecha_nacimiento_edad", "edad_calculada"},
	"religion":     {"religion", "religión", "credo", "belief", "faith", "catholic", "christian", "evangelico", "catolico", "judio", "musulman", "ateo", "agnostico", "budista", "hindu", "islamico", "mormon", "testigo_jehova", "religioso", "practica_religiosa", "creencia", "belief_system", "conviccion_religiosa", "confesion_religiosa", "culto", "worship", "iglesia", "church", "templo", "sinagoga", "mezquita"},
	"politico":     {"politico", "political", "partido", "voto", "vote", "ideologia", "ideology", "militancia", "afiliacion_politica", "cargo_publico", "opinion_politica", "partido_politico", "political_party", "preferencia_politica", "votacion", "eleccion", "election", "candidato", "candidate", "campania_politica", "political_campaign", "opinion_publica", "public_opinion", "tendencia_politica", "movimiento_politico", "postura_politica"},
	"sindical":     {"sindical", "union", "labor_union", "sindicato", "gremio", "asociacion", "colegio_profesional", "organizacion_sindical", "federacion", "confederacion", "trabajador_sindicalizado", "sindicalizado", "union_member", "negociacion_colectiva", "collective_bargaining", "huelga", "strike", "paro", "paralizacion", "movilizacion_sindical", "dirigente_sindical", "union_leader", "convenio_colectivo"},
	"judicial":     {"judicial", "criminal", "delito", "crime", "record", "antecedentes", "penal", "sentencia", "condena", "proceso_judicial", "litigio", "demanda", "causa", "tribunal", "corte", "fallo", "resolucion_judicial", "caso_policial", "denuncia", "querella", "arresto", "detencion", "prision", "carcel", "juzgado", "causa_rol", "ruc", "rut_detective", "parte_policial", "citacion", "notificacion", "audiencia", "formalizacion", "condena_anterior", "medida_cautelar", "prision_preventiva", "libertad_condicional", "reincidencia", "peritaje", "informe_social_j", "informe_psicologico_j", "mediacion", "arbitraje", "conciliation", "custodia", "custody", "tribunal_familia", "juzgado_familia", "violencia_intrafamiliar", "vif", "alimentos", "pension_alimenticia_j", "divorcio_judicial", "nulidad_matrimonial", "filiacion", "adopcion", "adoption", "menor_infractor", "responsabilidad_penal", "sistema_penal", "recluso", "inmate", "prisionero", "prisoner", "libertad_ vigilada", "probation", "medida_alternativa"},
	"educacion":    {"educacion", "education", "school", "colegio", "universidad", "university", "degree", "titulo", "academic", "estudio", "study", "curso", "course", "certificacion", "certification", "grado_academico", "profesion", "profession", "carrera", "alumno", "student", "matricula", "enrollment", "nota", "grade", "calificacion", "promedio", "promedio_general", "establecimiento", "institucion_educativa", "nivel_educacional", "educacion_basica", "educacion_media", "educacion_superior", "preescolar", "kinder", "tecnico", "profesional_universitario", "postgrado", "magister", "master", "doctorado", "phd", "diplomado", "especializacion", "practica_profesional", "pasantia", "internship", "intercambio_academico", "exchange", "beca", "scholarship", "ayudantia", "asistente_academico", "rendimiento_academico", "academic_performance", "expulsion", "suspension_academica", "anotaciones_academicas", "liceo", "instituto_profesional", "centro_formacion_tecnica", "cft", "ip", "hoja_vida_academica", "record_academico", "transcript"},
	"laboral":      {"laboral", "employment", "job", "trabajo", "salary", "salario", "employer", "empleador", "position", "cargo", "occupation", "sueldo", "renta", "ingreso", "income", "remuneracion", "honorario", "contrato", "contract", "fecha_ingreso", "fecha_contrato", "antiguedad", "seniority", "departamento", "department", "area", "jefatura", "supervisor", "evaluacion_desempeno", "vacaciones", "holiday", "ausencia", "absence", "inasistencia", "liquidacion_sueldo", "finiquito", "indemnizacion", "severance", "aguinaldo", "bonificacion", "comision", "gratificacion", "colacion", "movilizacion", "viatico", "per_diem", "asignacion", "descuento_laboral", "anticipo", "prestamo_laboral", "seguro_cesantia", "unemployment", "desempleo", "cesante", "sueldo_base", "base_salary", "sueldo_bruto", "gross_salary", "sueldo_liquido", "net_salary", "horas_extras", "overtime", "turno", "shift", "horario_laboral", "work_schedule", "jornada", "work_day", "asistencia", "attendance", "atraso", "late", "permiso_laboral", "leave", "licencia", "cargo_anterior", "previous_position", "fecha_termino_contrato", "end_date_contract", "renuncia", "resignation", "despido", "termination", "tipo_contrato", "contract_type", "trabajador", "empleado", "employee", "funcionario", "trabajador_dependiente", "trabajador_independiente", "boleta_honorarios", "prestador_servicios"},
	"conyuge":      {"conyuge", "spouse", "married", "casado", "estado_civil", "marital", "divorcio", "divorce", "separacion", "viudo", "widow", "soltero", "single", "union_civil", "pareja", "pareja_conviviente", "conviviente", "convivencia", "civil_union", "acuerdo_union_civil", "auc", "separado_judicial", "nunca_casado", "estado_civil_actual", "relacion_sentimental", "partner", "domestic_partner", "married_name", "apellido_casada"},
	"hijos":        {"hijos", "children", "child", "son", "daughter", "kids", "dependents", "familia", "familiares", "family", "hermano", "brother", "hermana", "sister", "padre", "father", "madre", "mother", "padres", "parents", "tutor", "guardian", "carga_familiar", "hijo_menor", "hijo_discapacitado", "hijo_estudiante", "hijos_matrimonio", "hijos_anteriores", "hijos_comunes", "paternidad", "maternidad", "parentesco", "relatives", "abuelo", "grandfather", "abuela", "grandmother", "nieto", "grandchild", "tio", "uncle", "tia", "aunt", "primo", "cousin", "sobrino", "nephew", "sobrina", "niece", "suegro", "mother_in_law", "suegra", "father_in_law", "cuñado", "brother_in_law", "cuñada", "sister_in_law", "familiar_contacto", "family_contact", "pariente", "relative", "grupo_familiar", "family_group", "nucleo_familiar", "carga_familiar_valida", "asignacion_familiar", "familiar_cargo"},
	"foto":         {"foto", "photo", "picture", "image", "avatar", "profile_pic", "fotografia", "imagen", "retrato", "foto_carnet", "selfie", "profile_photo", "profile_image", "user_image", "user_photo", "imagen_perfil", "foto_perfil", "thumbnail", "miniatura", "foto_identificacion", "foto_documento", "foto_rostro", "foto_cara", "face_photo", "foto_empleado", "employee_photo", "foto_cliente", "client_photo", "firma_imagen", "signature_image", "scan_documento", "document_scan", "copia_cedula", "carnet_foto"},
	"nacionalidad": {"nacionalidad", "nationality", "country", "pais", "origin", "nacimiento_lugar", "birth_place", "lugar_nacimiento", "ciudadania", "citizenship", "residencia", "migrante", "inmigrante", "visa", "extranjeria", "nacional", "naturalizado", "extranjero", "foreigner", "pais_origen", "pais_nacimiento", "pais_residencia", "country_of_birth", "country_of_residence", "lugar_residencia", "permiso_residencia", "residence_permit", "residencia_definitiva", "residencia_temporal", "visa_type", "tipo_visa", "migratorio", "immigration_status", "situacion_migratoria", "pueblo_originario", "indigena", "indigenous", "mapuche", "aymara", "rapa_nui", "quechua", "colla", "diaguita", "kawashkar", "yagan", "etnia", "ethnicity", "raza", "race", "ascendencia", "ancestry", "origen_etnico", "grupo_etnico"},
	"seguro":       {"seguro", "insurance", "poliza", "policy", "aseguradora", "cobertura", "coverage", "beneficiario", "beneficiary", "siniestro", "claim", "seguro_vida", "life_insurance", "seguro_auto", "car_insurance", "seguro_hogar", "home_insurance", "seguro_salud", "health_insurance", "seguro_complementario", "seguro_catastronfico", "seguro_educacion", "education_insurance", "seguro_desgravamen", "seguro_incendio", "fire_insurance", "seguro_robo", "theft_insurance", "seguro_accidentes", "accident_insurance", "seguro_viaje", "travel_insurance", "prima_seguro", "insurance_premium", "deducible", "deductible", "poliza_seguro", "insurance_policy", "numero_poliza", "policy_number", "vigencia_poliza", "policy_effective", "vencimiento_poliza", "asegurado", "insured_person", "tomador_seguro", "policy_holder", "contratante_seguro"},
	"vehiculo":     {"vehiculo", "vehicle", "car", "auto", "patente", "license_plate", "placa", "chasis", "motor", "modelo_vehiculo", "marca_vehiculo", "ano_vehiculo", "color_vehiculo", "automovil", "camioneta", "moto", "motorcycle", "camion", "truck", "bus", "microbus", "furgon", "van", "maquinaria", "equipo_pesado", "tractor", "remolque", "trailer", "semiremolque", "acoplado", "vehiculo_motor", "numero_motor", "numero_chasis", "vin_number", "vin", "numero_serie", "serial_number", "vehiculo_patente", "dominio_vehiculo", "registro_vehicular", "automotive_registry", "permiso_circulacion", "revision_tecnica", "seguro_obligatorio", "soap", "pago_permiso", "multa_transito", "traffic_ticket", "infraccion_transito", "licencia_conducir_vehiculo", "clase_licencia", "tipo_vehiculo", "capacidad_vehiculo", "cilindrada", "engine_displacement"},
	"patrimonio":   {"bienes", "property", "propiedad", "inmueble", "real_estate", "herencia", "inheritance", "sucesion", "succession", "patrimonio", "assets", "activo_financiero", "financial_asset", "inversion_financiera", "financial_investment", "fondo_mutuo", "mutual_fund", "accion", "stock", "share", "bono_financiero", "bond", "instrumento_financiero", "cartera_inversion", "portfolio", "valor_cuota", "nav", "participacion", "holding", "testamento", "will", "legado", "legacy", "albacea", "executor", "inventario_bienes", "property_inventory", "avalúo", "appraisal", "tasacion", "valuation"},
	"digital":      {"user_agent", "browser", "navegador", "cookie", "session_id", "device_id", "dispositivo", "device", "imei", "serial_number", "udid", "advertising_id", "idfa", "fingerprint_browser", "browser_fingerprint", "dispositivo_movil", "mobile_device", "so_dispositivo", "os_device", "os_version", "version_sistema", "modelo_dispositivo", "device_model", "marca_dispositivo", "device_brand", "resolucion_pantalla", "screen_resolution", "idioma_navegador", "browser_language", "tiempo_sesion", "session_duration", "tipo_conexion", "connection_type", "proveedor_internet", "isp", "operador_telefonia", "carrier", "torre_celular", "cell_tower", "antena", "wifi_ssid", "bluetooth_mac", "nfc", "beacon", "tracking_pixel", "analytics_id", "google_analytics", "ga_client_id", "facebook_pixel", "cookie_id", "tracking_cookie"},
	"financiero":   {"ingresos", "income", "egresos", "expenses", "gastos", "budget", "presupuesto", "impuesto", "tax", "taxes", "declaracion_renta", "tax_return", "iva", "vat", "ppm", "pago_provisional", "f29", "f22", "sii", "servicio_impuestos", "tesoreria", "treasury", "contabilidad", "accounting", "factura", "invoice", "boleta", "receipt", "comprobante", "voucher", "recibo", "pago", "payment", "cobro", "charge", "fee_financiero", "gasto_comun", "common_expense", "dividendo_hipoteca", "cuota_credito", "balance_general", "balance_sheet", "estado_resultado", "income_statement", "flujo_efectivo", "cashflow", "cuenta_contable", "account_accounting", "centro_costo", "cost_center", "orden_compra", "purchase_order", "requisicion", "gasto_operacional", "operating_expense", "capital", "equity", "pasivo", "liability", "activo_circulante", "activo_fijo", "ingreso_anual", "annual_income", "rango_ingreso", "income_range", "fuente_ingreso", "income_source"},
	"comunicacion": {"correspondencia", "mail_phys", "carta", "letter", "mensaje", "message", "sms", "chat", "conversacion", "conversation", "llamada", "call", "call_log", "registro_llamada", "call_record", "duracion_llamada", "call_duration", "destino_llamada", "call_destination", "origen_llamada", "call_origin", "historial_chat", "chat_history", "mensaje_privado", "private_message", "comunicacion_interna", "internal_communication", "comunicacion_externa", "mensaje_texto", "text_message", "mms", "multimedia_message", "correo_interno", "internal_email", "comunicacion_cliente", "client_communication"},
	"caracteristicas_fisicas": {"estatura", "height", "altura", "peso", "weight", "talla", "size_clothing", "complexion", "build", "color_pelo", "hair_color", "color_ojos", "eye_color", "color_piel", "skin_color", "senas_particulares", "distinctive_marks", "tatuajes", "tattoos", "cicatrices", "scars", "lunares", "moles", "pecas", "freckles", "tipo_cuerpo", "body_type", "contextura", "indice_masa_corporal", "bmi", "imc", "porcentaje_grasa", "body_fat", "perimetro_cintura", "waist_circumference", "calzado", "shoe_size", "talla_zapato", "talla_ropa", "clothing_size", "talla_camisa", "shirt_size", "talla_pantalon", "pants_size"},
}

var extendedSensitiveCat = map[string]bool{
	"salud":                  true,
	"biometrico":             true,
	"bancario":               true,
	"credencial":             true,
	"genero":                 true,
	"religion":               true,
	"politico":               true,
	"sindical":               true,
	"judicial":               true,
	"conyuge":                true,
	"hijos":                  true,
	"seguro":                 true,
	"foto":                   true,
	"nacionalidad":           true,
	"patrimonio":             true,
	"financiero":             true,
	"digital":                true,
	"comunicacion":           true,
	"caracteristicas_fisicas": true,
	"edad":                   false,
	"nombre":                 false,
	"email":                  false,
	"rut":                    false,
	"telefono":               false,
	"direccion":              false,
	"fecha_nac":              false,
	"ip":                     false,
	"ubicacion":              false,
	"educacion":              false,
	"laboral":                false,
	"vehiculo":               false,
}

// Compiled regex patterns from extendedPersonalPatterns
// Uses \b word boundaries for accurate token matching
// Underscores in column names are normalized to spaces before matching
var extendedPersonalRegex map[string][]*regexp.Regexp

func init() {
	extendedPersonalRegex = make(map[string][]*regexp.Regexp, len(extendedPersonalPatterns))
	for cat, patterns := range extendedPersonalPatterns {
		compiled := make([]*regexp.Regexp, 0, len(patterns))
		for _, p := range patterns {
			// Escape regex special chars, then wrap with word boundaries
			re := regexp.MustCompile(`(?i)\b` + regexp.QuoteMeta(p) + `\b`)
			compiled = append(compiled, re)
		}
		extendedPersonalRegex[cat] = compiled
	}
}

// ──────────────────────────────────────────────
// Initialization
// ──────────────────────────────────────────────

var activityMon = &ActivityMonitor{
	stopCh:           make(chan struct{}),
	connections:      make(map[string]*DBConnection),
	activeUsers:      make(map[string]*UserTrack),
	activeQueries:    make(map[string]*QueryTrack),
	tableAccess:      make(map[string]*TableAccessStats),
	userProfiles:     make(map[string]*UserBehavior),
	knownProcesses:   make(map[int]ProcessTrack),
	personalDataCols: make(map[string][]string),
	lastAlerts:       make(map[string]time.Time),
	lastUserEvents:   make(map[string]time.Time),
	seenPGQueries:    make(map[string]bool),
	seenMSSQLQueries: make(map[string]bool),
	startTime:        time.Now(),
}

func StartActivityMonitor() {
	go activityMon.loop()
}

func StopActivityMonitor() {
	close(activityMon.stopCh)
}

func (m *ActivityMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.connection = &conn
	key := fmt.Sprintf("%s://%s:%d", conn.Engine, conn.Host, conn.Port)
	m.connections[key] = &conn
	m.lastLogScan = time.Time{}
	m.discoveredLogs = nil
	m.dbDirs = nil
	logMsg("Activity Monitor: connection active for %s/%s", conn.Engine, conn.Database)
}

func (m *ActivityMonitor) SetSensitiveTables(tables []string, columns map[string][]string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.sensitiveTables = nil
	for _, t := range tables {
		def := SensitiveTableDef{Name: t}
		if cols, ok := columns[t]; ok {
			for _, c := range cols {
				_, cat := detectPersonalDataAdvanced(c)
				def.Columns = append(def.Columns, SensitiveColumn{
					Name:      c,
					Category:  cat,
					Sensitive: extendedSensitiveCat[cat],
				})
			}
		}
		m.sensitiveTables = append(m.sensitiveTables, def)
	}
	if columns != nil {
		m.personalDataCols = columns
	}
	logMsg("Activity Monitor: loaded %d sensitive tables with personal data tracking", len(tables))
}

// ──────────────────────────────────────────────
// Main Loop
// ──────────────────────────────────────────────

func (m *ActivityMonitor) loop() {
	logMsg("Activity Monitor: ADVANCED MODE started (interval: %v)", monitorInterval)
	time.Sleep(3 * time.Second)
	ticker := time.NewTicker(monitorInterval)

	// Immediate log discovery on start
	m.discoverDBLogsOnHost()

	// Auto-discover and connect to all local databases
	go m.autoDiscoverAndConnectAllDBs()

	for {
		select {
		case <-ticker.C:
			m.mu.RLock()
			conn := m.connection
			allConns := make([]*DBConnection, 0, len(m.connections))
			for _, c := range m.connections {
				allConns = append(allConns, c)
			}
			m.mu.RUnlock()

			// Check the primary connection (from test_connection or auto-init)
			if conn != nil {
				m.check(conn)
			}

			// Check all auto-discovered connections
			for _, c := range allConns {
				if conn != nil && c.Engine == conn.Engine && c.Host == conn.Host && c.Port == conn.Port {
					continue
				}
				m.check(c)
			}

			m.flushQueryLogs()
			m.scanHostForDumps()
			m.cleanupStale()

			if time.Since(m.lastLogScan) > logDiscoveryIntvl {
				m.discoverDBLogsOnHost()
			}

		// Periodic compliance status (antivirus, firewall, etc.)
			if m.summaryTicker%60 == 0 {
				sendComplianceStatus()
			}

			// Periodic multi-DB health check (every 5 minutes = 600 ticks at 500ms)
			if m.summaryTicker%600 == 0 {
				go m.periodicDBHealthCheck()
			}

			// Periodic AI deep analysis (every 10 minutes)
			if m.summaryTicker%1200 == 0 {
				go m.runDeepAIAnalysis()
			}

			// Read discovered log file contents periodically (every 30 iterations = ~2.5min)
			if m.summaryTicker%30 == 0 {
				m.readDiscoveredLogContents()
			}

			m.summaryTicker++
			if m.summaryTicker%60 == 0 {
				m.sendActivitySummary()
			}

		case <-m.stopCh:
			logMsg("Activity Monitor: stopped")
			return
		}
	}
}

func (m *ActivityMonitor) check(conn *DBConnection) {
	m.lastCheckTime = time.Now()

	// MongoDB uses native driver, not database/sql
	if conn.Engine == "mongodb" {
		m.monitorMongoDB(conn)
		return
	}

	// Try to use the DBEngine's managed connection pool first
	var db *sql.DB
	engine := GetDBEngine()
	engine.mu.RLock()
	for _, mdb := range engine.connections {
		if mdb.Connected && mdb.Instance.Engine == conn.Engine && mdb.Instance.Host == conn.Host && mdb.Instance.Port == conn.Port {
			db = mdb.DB
			break
		}
	}
	engine.mu.RUnlock()

	// Fallback: open a new connection only if DBEngine doesn't have one
	if db == nil {
		var err error
		db, err = openDBWithStrategy(*conn, "direct")
		if err != nil {
			logMsg("Activity Monitor: openDB failed for %s/%s: %v", conn.Engine, conn.Database, err)
			return
		}
		defer db.Close()
	}

	if err := db.Ping(); err != nil {
		logMsg("Activity Monitor: ping failed for %s/%s: %v", conn.Engine, conn.Database, err)
		return
	}

	switch conn.Engine {
	case "mysql", "mariadb":
		m.monitorMySQL(db, conn)
	case "postgresql":
		m.monitorPostgreSQL(db, conn)
	case "mssql":
		m.monitorMSSQL(db, conn)
	case "sqlite":
		m.monitorSQLite()
	default:
		m.monitorGeneric(db, conn)
	}
}

// ──────────────────────────────────────────────
// MySQL / MariaDB Monitor
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorMySQL(db *sql.DB, conn *DBConnection) {
	// ── Full process list ──
	m.trackMySQLProcesses(db, conn)

	// ── General log enabler + tailer (100% query capture) — run BEFORE history
	m.enableAndTailMySQLGeneralLog(db, conn)

	// ── Performance schema events_statements_history (individual completed queries) ──
	m.trackMySQLHistory(db, conn)

	// ── Performance schema (if available) ──
	m.checkMySQLPerformanceSchema(db, conn)

	// ── Table status for size/row changes ──
	m.checkMySQLTableStatus(db, conn)

	// ── Binary log status ──
	m.checkMySQLBinlogs(db, conn)

	// ── Variable check for logging config ──
	m.checkMySQLVariables(db, conn)

	// ── Poll mysql.general_log TABLE for any queries captured since last poll ──
	m.pollMySQLGeneralLogTable(db, conn)

	// ── Auto-enable performance_schema (only once, on Windows) ──
	m.autoEnablePerformanceSchema(db, conn)
}

func (m *ActivityMonitor) trackMySQLProcesses(db *sql.DB, conn *DBConnection) {
	// Get our own connection ID to exclude from logging
	var myConnID int64
	err := db.QueryRow("SELECT CONNECTION_ID()").Scan(&myConnID)
	myConnKnown := err == nil

	rows, err := db.Query("SHOW FULL PROCESSLIST")
	if err != nil {
		logMsg("MySQL: SHOW FULL PROCESSLIST failed: %v", err)
		return
	}
	defer rows.Close()

	cols, _ := rows.Columns()
	var idCol, userCol, hostCol, dbCol, cmdCol, stateCol, infoCol int
	idCol = -1
	userCol = -1
	hostCol = -1
	dbCol = -1
	cmdCol = -1
	stateCol = -1
	infoCol = -1
	for i, c := range cols {
		switch c {
		case "ID", "Id":
			idCol = i
		case "USER", "User":
			userCol = i
		case "HOST", "Host":
			hostCol = i
		case "db", "DB":
			dbCol = i
		case "COMMAND", "Command":
			cmdCol = i
		case "TIME", "Time":
			_ = i
		case "STATE", "State":
			stateCol = i
		case "INFO", "Info":
			infoCol = i
		}
	}

	for rows.Next() {
		vals := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			continue
		}

		var connID int64
		var user, host, dbName, command, state, info string
		for i, v := range vals {
			s := fmt.Sprintf("%v", v)
			if b, ok := v.([]byte); ok {
				s = string(b)
			}
			switch i {
			case idCol:
				connID, _ = strconv.ParseInt(s, 10, 64)
			case userCol:
				user = s
			case hostCol:
				host = s
			case dbCol:
				dbName = s
			case cmdCol:
				command = s
			case stateCol:
				state = s
			case infoCol:
				info = s
			}
		}

		// Skip agent's own monitoring connection (by thread ID, not by username)
		if myConnKnown && connID == myConnID {
			continue
		}

		// Skip truly internal system users (never generate user queries)
		if user == "" || isInternalUserAdvanced(user) {
			continue
		}

		m.recordUserActivity(user, host, conn)

		if info != "" && command != "Sleep" && command != "Binlog Dump" && command != "Daemon" {
			m.processQuery(info, user, host, dbName, conn)
		}

		// Detect long-running queries
		if command == "Query" && state == "Sending data" && info != "" {
			m.detectLongRunning(info, user, host, conn)
		}
	}
}

func (m *ActivityMonitor) trackMySQLHistory(db *sql.DB, conn *DBConnection) {
	// Detect MySQL vs MariaDB – they have different performance_schema columns
	var versionComment string
	db.QueryRow("SELECT @@version_comment").Scan(&versionComment)
	isMariaDB := strings.Contains(strings.ToLower(versionComment), "mariadb")

	// Try each query source in order until one returns rows
	var rows *sql.Rows
	var err error

	type querySource struct {
		name  string
		query string
	}

	var sources []querySource
	if isMariaDB {
		// MariaDB: events_statements_history has no USER/HOST columns and is always empty
		// Skip it entirely — go straight to events_statements_current then mysql.general_log
		sources = []querySource{
			{"events_statements_current", `
				SELECT THREAD_ID, EVENT_ID, EVENT_NAME, DIGEST_TEXT,
					SQL_TEXT, CURRENT_SCHEMA, 'unknown' as USER_HOST,
					ROWS_EXAMINED, ROWS_AFFECTED, ROWS_SENT,
					TIMER_WAIT/1000000000000 as duration_sec
				FROM performance_schema.events_statements_current
				WHERE SQL_TEXT IS NOT NULL AND DIGEST_TEXT IS NOT NULL
				ORDER BY EVENT_ID DESC
				LIMIT 50
			`},
			{"mysql.general_log", `
				SELECT thread_id, UNIX_TIMESTAMP(event_time) as event_id, 'Query' as event_name,
					'' as digest, argument as sql_text, '' as current_schema,
					user_host, 0 as rows_examined, 0 as rows_affected, 0 as rows_sent,
					0 as duration_sec
				FROM mysql.general_log
				WHERE command_type = 'Query' AND argument IS NOT NULL AND argument != ''
				  AND event_time > NOW() - INTERVAL 1 HOUR
				ORDER BY event_time DESC
				LIMIT 200
			`},
		}
	} else {
		// MySQL: use USER_HOST column which exists in events_statements_history
		sources = []querySource{
			{"events_statements_history", `
				SELECT THREAD_ID, EVENT_ID, EVENT_NAME, DIGEST_TEXT,
					SQL_TEXT, CURRENT_SCHEMA, USER_HOST,
					ROWS_EXAMINED, ROWS_AFFECTED, ROWS_SENT,
					TIMER_WAIT/1000000000000 as duration_sec
				FROM performance_schema.events_statements_history
				WHERE SQL_TEXT IS NOT NULL AND DIGEST_TEXT IS NOT NULL
				ORDER BY EVENT_ID DESC
				LIMIT 50
			`},
			{"events_statements_current", `
				SELECT THREAD_ID, EVENT_ID, EVENT_NAME, DIGEST_TEXT,
					SQL_TEXT, CURRENT_SCHEMA, USER_HOST,
					ROWS_EXAMINED, ROWS_AFFECTED, ROWS_SENT,
					TIMER_WAIT/1000000000000 as duration_sec
				FROM performance_schema.events_statements_current
				WHERE SQL_TEXT IS NOT NULL AND DIGEST_TEXT IS NOT NULL
				ORDER BY EVENT_ID DESC
				LIMIT 50
			`},
			{"mysql.general_log", `
				SELECT thread_id, UNIX_TIMESTAMP(event_time) as event_id, 'Query' as event_name,
					'' as digest, argument as sql_text, '' as current_schema,
					user_host, 0 as rows_examined, 0 as rows_affected, 0 as rows_sent,
					0 as duration_sec
				FROM mysql.general_log
				WHERE command_type = 'Query' AND argument IS NOT NULL AND argument != ''
				  AND event_time > NOW() - INTERVAL 1 HOUR
				ORDER BY event_time DESC
				LIMIT 200
			`},
		}
	}

	for _, src := range sources {
		rows, err = db.Query(src.query)
		if err != nil {
			logMsg("MySQL: %s query failed: %v", src.name, err)
			continue
		}
		// Check if there are any rows
		if rows.Next() {
			break
		}
		rows.Close()
		rows = nil
		logMsg("MySQL: %s returned 0 rows, trying next source", src.name)
	}

	if rows == nil {
		logMsg("MySQL: all query history sources returned no data")
		return
	}
	defer rows.Close()

	var lastID int64
	m.mu.RLock()
	if m.lastHistoryID > 0 {
		lastID = m.lastHistoryID
	}
	m.mu.RUnlock()

	// Process first row (already advanced by the peek)
	func() {
		var threadID, eventID int64
		var eventName, digest, sqlText, schema, userHost string
		var rowsExam, rowsAff, rowsSent, dur float64
		if err := rows.Scan(&threadID, &eventID, &eventName, &digest,
			&sqlText, &schema, &userHost,
			&rowsExam, &rowsAff, &rowsSent, &dur); err == nil {
			if eventID > lastID {
				user := userHost
				host := ""
				if idx := strings.Index(userHost, "@"); idx >= 0 {
					user = strings.Trim(strings.TrimSpace(userHost[:idx]), "'")
					host = strings.Trim(strings.TrimSpace(userHost[idx+1:]), "'")
				}
				if !isInternalSQL(sqlText) {
					m.processQuery(sqlText, user, host, schema, conn)
				}
				m.mu.Lock()
				m.lastHistoryID = eventID
				m.mu.Unlock()
			}
		}
	}()

	for rows.Next() {
		var threadID, eventID int64
		var eventName, digest, sqlText, schema, userHost string
		var rowsExam, rowsAff, rowsSent, dur float64
		if err := rows.Scan(&threadID, &eventID, &eventName, &digest,
			&sqlText, &schema, &userHost,
			&rowsExam, &rowsAff, &rowsSent, &dur); err != nil {
			continue
		}
		if eventID <= lastID {
			continue
		}
		// Parse user/host from USER_HOST format: 'user'@'host'
		user := userHost
		host := ""
		if idx := strings.Index(userHost, "@"); idx >= 0 {
			user = strings.Trim(strings.TrimSpace(userHost[:idx]), "'")
			host = strings.Trim(strings.TrimSpace(userHost[idx+1:]), "'")
		}

		// Skip agent's own monitoring queries
		if isInternalSQL(sqlText) {
			continue
		}

		m.processQuery(sqlText, user, host, schema, conn)

		m.mu.Lock()
		if eventID > m.lastHistoryID {
			m.lastHistoryID = eventID
		}
		m.mu.Unlock()
	}
}

func (m *ActivityMonitor) checkMySQLPerformanceSchema(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT DIGEST_TEXT, COUNT_STAR, SUM_TIMER_WAIT/1000000000000 as total_sec,
			AVG_TIMER_WAIT/1000000000000 as avg_sec, SUM_ROWS_EXAMINED, SUM_ROWS_SENT,
			IFNULL(SCHEMA_NAME, 'N/A') as db_schema
		FROM performance_schema.events_statements_summary_by_digest
		WHERE DIGEST_TEXT IS NOT NULL AND SCHEMA_NAME IS NOT NULL
		ORDER BY SUM_TIMER_WAIT DESC
		LIMIT 20
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var digest, schema string
		var countStar, totalSec, avgSec, rowsExam, rowsSent float64
		if err := rows.Scan(&digest, &countStar, &totalSec, &avgSec, &rowsExam, &rowsSent, &schema); err != nil {
			continue
		}
		if countStar > 100 && avgSec > 1.0 {
			m.reportAnomaly(AnomalyReport{
				Type:        "slow_query",
				Severity:    "medium",
				Description: fmt.Sprintf("Consulta lenta detectada (x%.0f, avg %.2fs, %s): %.100s", countStar, avgSec, schema, digest),
				Query:       digest,
				User:        "performance_schema",
				Host:        schema,
				AutoBlock:   false,
			})
		}
		if rowsExam > 0 && rowsSent > 0 && rowsExam/rowsSent > 10000 {
			m.reportAnomaly(AnomalyReport{
				Type:        "high_scan",
				Severity:    "high",
				Description: fmt.Sprintf("Escaneo masivo: %.0f filas examinadas, %.0f enviadas (ratio %.0f:1)", rowsExam, rowsSent, rowsExam/rowsSent),
				Query:       digest,
				User:        "performance_schema",
				Host:        schema,
				AutoBlock:   false,
			})
		}
	}
}

func (m *ActivityMonitor) checkMySQLTableStatus(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query("SHOW TABLE STATUS")
	if err != nil {
		return
	}
	defer rows.Close()
	cols, _ := rows.Columns()
	for rows.Next() {
		vals := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			continue
		}

		var name string
		for i, col := range cols {
			v := vals[i]
			switch col {
			case "Name":
				if b, ok := v.([]byte); ok {
					name = string(b)
				} else if s, ok := v.(string); ok {
					name = s
				}
			}
		}
		if name == "" {
			continue
		}
		m.mu.Lock()
		stats, exists := m.tableAccess[name]
		if !exists {
			stats = &TableAccessStats{
				Name:    name,
				Users:   make(map[string]int),
			}
			m.tableAccess[name] = stats
		}
		stats.LastAccess = time.Now()
		m.mu.Unlock()
	}
}

func (m *ActivityMonitor) checkMySQLBinlogs(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query("SHOW BINARY LOGS")
	if err != nil {
		return
	}
	defer rows.Close()
	var totalSize int64
	var logCount int
	var latestBinlog string
	for rows.Next() {
		var name string
		var size int64
		if err := rows.Scan(&name, &size); err == nil {
			totalSize += size
			logCount++
			latestBinlog = name
		}
	}
	if logCount > 0 && totalSize > 0 {
		logMsg("Activity Monitor: %d binary logs, total size %d MB", logCount, totalSize/1048576)
	}

	// Try to read latest binlog content with mysqlbinlog (periodically)
	if latestBinlog != "" {
		var dataDir string
		if err := db.QueryRow("SELECT @@datadir").Scan(&dataDir); err == nil {
			binlogPath := filepath.Join(dataDir, latestBinlog)
			if queries, err := ReadBinlogContent(binlogPath); err == nil && len(queries) > 0 {
				logMsg("MySQL: extracted %d queries from binlog %s", len(queries), latestBinlog)
				for _, q := range queries {
					m.addQueryLog(QueryLogEntry{
						Timestamp: time.Now(),
						Query:     q,
						Engine:    conn.Engine,
						Operation: extractOperation(q),
					})
				}
			}
		}
	}
}

func extractOperation(query string) string {
	upper := strings.TrimSpace(strings.ToUpper(query))
	switch {
	case strings.HasPrefix(upper, "INSERT"):
		return "INSERT"
	case strings.HasPrefix(upper, "UPDATE"):
		return "UPDATE"
	case strings.HasPrefix(upper, "DELETE"):
		return "DELETE"
	case strings.HasPrefix(upper, "SELECT"):
		return "SELECT"
	case strings.HasPrefix(upper, "CREATE"):
		return "CREATE"
	case strings.HasPrefix(upper, "ALTER"):
		return "ALTER"
	case strings.HasPrefix(upper, "DROP"):
		return "DROP"
	case strings.HasPrefix(upper, "TRUNCATE"):
		return "TRUNCATE"
	default:
		return "OTHER"
	}
}

func (m *ActivityMonitor) checkMySQLVariables(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query("SHOW VARIABLES WHERE Variable_name IN (" +
		"'general_log','general_log_file','slow_query_log','slow_query_log_file'," +
		"'log_error','log_bin','binlog_format','log_queries_not_using_indexes','log_slow_admin_statements')")
	if err != nil {
		return
	}
	defer rows.Close()
	var foundLogs []DBLogFileInfo
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err == nil {
			if value != "" && value != "OFF" && value != "0" {
				if strings.Contains(name, "file") || strings.Contains(name, "log_error") {
					logPath := value
					if !filepath.IsAbs(logPath) {
						if conn.Engine == "mysql" || conn.Engine == "mariadb" {
							var dataDir string
							db.QueryRow("SELECT @@datadir").Scan(&dataDir)
							logPath = filepath.Join(dataDir, logPath)
						}
					}
					if info, err := os.Stat(logPath); err == nil {
						foundLogs = append(foundLogs, DBLogFileInfo{
							Path:       logPath,
							SizeBytes:  info.Size(),
							ModTime:    info.ModTime(),
							Engine:     conn.Engine,
							LogType:    name,
							Accessible: true,
						})
					}
				}
				if name == "general_log" && value == "ON" {
					logMsg("Activity Monitor: GENERAL LOG está ACTIVADO — todas las consultas se registran")
				}
				if name == "slow_query_log" && value == "ON" {
					logMsg("Activity Monitor: SLOW QUERY LOG está ACTIVADO")
				}
				if name == "audit_log" && value == "ON" {
					logMsg("Activity Monitor: AUDIT LOG está ACTIVADO")
				}
			}
		}
	}
	if len(foundLogs) > 0 {
		m.mu.Lock()
		m.discoveredLogs = append(m.discoveredLogs, foundLogs...)
		m.mu.Unlock()
	}
}

func (m *ActivityMonitor) enableAndTailMySQLGeneralLog(db *sql.DB, conn *DBConnection) {
	// Check + auto-fix general_log and log_output
	var genLogVar, genLogFile, logOutput string
	err := db.QueryRow("SELECT @@general_log, @@general_log_file, @@log_output").Scan(&genLogVar, &genLogFile, &logOutput)
	if err != nil {
		logMsg("MySQL: cannot read log variables: %v", err)
		return
	}
	logMsg("MySQL: general_log=%s log_output=%s file=%s", genLogVar, logOutput, genLogFile)

	// Enable general_log if not already on
	if genLogVar != "ON" && genLogVar != "1" {
		_, err := db.Exec("SET GLOBAL general_log = ON")
		if err != nil {
			logMsg("MySQL: could not enable general_log (need SUPER privileges): %v", err)
		} else {
			logMsg("MySQL: general_log ENABLED for 100% query capture")
		}
	}

	// Ensure log_output includes both FILE (for tailing) and TABLE (for history queries)
	upperLog := strings.ToUpper(logOutput)
	if !strings.Contains(upperLog, "FILE") || !strings.Contains(upperLog, "TABLE") {
		logMsg("MySQL: log_output=%s — trying to set FILE,TABLE", logOutput)
		_, err := db.Exec("SET GLOBAL log_output = 'FILE,TABLE'")
		if err != nil {
			logMsg("MySQL: could not set log_output=FILE,TABLE: %v", err)
		} else {
			logMsg("MySQL: log_output set to FILE,TABLE (file + table)")
		}
	}

	// Resolve log file path
	logPath := genLogFile
	if !filepath.IsAbs(logPath) {
		var dataDir string
		if err := db.QueryRow("SELECT @@datadir").Scan(&dataDir); err == nil {
			logPath = filepath.Join(dataDir, logPath)
		} else {
			// Fallback: try common XAMPP/WAMP paths
			hostname, _ := os.Hostname()
			candidates := []string{
				filepath.Join("C:\\xampp\\mysql\\data", logPath),
				filepath.Join("C:\\xampp\\mysql\\data", hostname+".log"),
				filepath.Join("C:\\wamp64\\bin\\mysql\\mysql*\\data", logPath),
				filepath.Join("C:\\ProgramData\\MySQL\\MySQL Server *\\Data", logPath),
			}
			for _, c := range candidates {
				if _, err := os.Stat(c); err == nil {
					logPath = c
					break
				}
			}
		}
	}

	// Start tailing the general log in a goroutine (only once)
	m.mu.Lock()
	alreadyTailing := m.generalLogTailing
	if !alreadyTailing {
		m.generalLogTailing = true
	}
	m.mu.Unlock()
	if alreadyTailing {
		return
	}

	go m.tailGeneralLog(logPath, conn)
}

func (m *ActivityMonitor) tailGeneralLog(logPath string, conn *DBConnection) {
	logMsg("MySQL: starting general_log tail: %s", logPath)

	// Track thread -> user mapping from Connect entries
	threadUserMap := make(map[string]string)

	// Retry opening file with backoff
	var file *os.File
	var err error
	for attempts := 0; attempts < 10; attempts++ {
		file, err = os.Open(logPath)
		if err == nil {
			break
		}
		logMsg("MySQL: cannot open general_log (attempt %d/10): %v", attempts+1, err)
		time.Sleep(3 * time.Second)
	}
	if err != nil {
		logMsg("MySQL: giving up on general_log tail after 10 attempts")
		m.mu.Lock()
		m.generalLogTailing = false
		m.mu.Unlock()
		return
	}
	defer file.Close()

	// Seek to end so we only catch new queries
	_, _ = file.Seek(0, 2)

	buf := bufio.NewReader(file)
	var tailLineCount, tailQueryCount int64
	lastTailLog := time.Now()
	lastRotationCheck := time.Now()

	for {
		// Periodic rotation check (every 30s)
		if time.Since(lastRotationCheck) > 30*time.Second {
			lastRotationCheck = time.Now()
			if f, err2 := os.Open(logPath); err2 == nil {
				fiOld, _ := file.Stat()
				fiNew, _ := f.Stat()
				rotated := fiOld == nil || fiNew == nil || !os.SameFile(fiOld, fiNew)
				if rotated {
					file.Close()
					file = f
					_, _ = file.Seek(0, 2)
					buf = bufio.NewReader(file)
					logMsg("MySQL: general_log file rotated, re-opened")
				} else {
					f.Close()
				}
			}
		}

		line, err := buf.ReadString('\n')
		if err != nil {
			time.Sleep(1 * time.Second)
			// Re-open if file was truly rotated (non-EOF error)
			if err.Error() != "EOF" {
				if f, err2 := os.Open(logPath); err2 == nil {
					file.Close()
					file = f
					_, _ = file.Seek(0, 2)
					buf = bufio.NewReader(file)
				}
			} else if tailLineCount == 0 {
				_, _ = file.Seek(0, 2)
			}
			continue
		}
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}

		// Skip header line
		if strings.Contains(line, "Id Command") && strings.Contains(line, "Argument") {
			continue
		}

		tailLineCount++

		// Log stats periodically
		if time.Since(lastTailLog) > 30*time.Second {
			logMsg("MySQL: general_log tail stats — lines: %d, queries captured: %d", tailLineCount, tailQueryCount)
			lastTailLog = time.Now()
		}

		var threadID, commandInfo, argument string

		// Try tab-separated first (standard MariaDB/MySQL format)
		parts := strings.Split(line, "\t")

		// If no tabs, try space-separated
		if len(parts) <= 1 {
			parts = strings.Fields(line)
		}

		// Detect format: first field is a number = threadID is first
		// First field is a date/time = threadID is at position 1 or 2
		firstFieldIsNum := len(parts) > 0
		if firstFieldIsNum {
			_, err := strconv.Atoi(parts[0])
			firstFieldIsNum = err == nil
		}

		if firstFieldIsNum && len(parts) >= 2 {
			// Format: ThreadId Command [Argument...]
			threadID = parts[0]
			if len(parts) >= 3 {
				commandInfo = parts[1]
				argument = strings.Join(parts[2:], " ")
			} else if len(parts) == 2 {
				commandInfo = parts[1]
			}
		} else if len(parts) == 2 && !firstFieldIsNum {
			// Tab-separated: "ThreadId Command"<TAB>"Argument"
			parts0Fields := strings.Fields(parts[0])
			if len(parts0Fields) >= 2 {
				if _, err := strconv.Atoi(parts0Fields[0]); err == nil {
					threadID = parts0Fields[0]
					commandInfo = parts0Fields[1]
					if len(parts0Fields) > 2 {
						argument = strings.Join(parts0Fields[2:], " ") + " " + parts[1]
					} else {
						argument = parts[1]
					}
				}
			}
		} else if len(parts) >= 4 {
			// Format: DateTime ThreadId Command Argument (or extra fields)
			// Find the field that looks like a thread ID (numeric)
			for i := 1; i < len(parts)-1; i++ {
				if _, err := strconv.Atoi(parts[i]); err == nil {
					threadID = parts[i]
					if i+1 < len(parts) {
						commandInfo = parts[i+1]
						if i+2 < len(parts) {
							argument = strings.Join(parts[i+2:], " ")
						}
					}
					break
				}
			}
		}

		// Validate we got a valid command (must be known)
		if threadID == "" || commandInfo == "" || !isKnownCommand(commandInfo) {
			if tailLineCount <= 10 {
				logMsg("MySQL: general_log tail — unparseable: %.100s", line)
			}
			continue
		}

	// Track Connect entries to map thread_id -> user
	if commandInfo == "Connect" || commandInfo == "Change user" {
		if argument != "" {
			// Format: user@host on db
			if userPart := strings.Split(argument, "@"); len(userPart) > 0 {
				threadUserMap[threadID] = strings.TrimSpace(userPart[0])
			}
		}
		continue
	}

	// Skip non-query commands
	if commandInfo != "Query" && commandInfo != "Execute" && commandInfo != "Init DB" {
		continue
	}

	if argument == "" {
		continue
	}

	queryText := argument

	// Init DB sets the current database for this thread
	if commandInfo == "Init DB" {
		threadUserMap[threadID+"_db"] = queryText
		continue
	}

	if queryText == "" || isInternalSQL(queryText) {
		continue
	}

	// Determine actual user who ran the query (from Connect tracking)
	actualUser := threadUserMap[threadID]
	if actualUser == "" {
		actualUser = conn.Username
	}

	// Determine database from thread's Init DB or connection default
	db := threadUserMap[threadID+"_db"]
	if db == "" {
		db = conn.Database
	}

	m.processQuery(queryText, actualUser, conn.Host, db, conn)
	}
}

func isKnownCommand(cmd string) bool {
	known := map[string]bool{
		"Query": true, "Execute": true, "Connect": true, "Quit": true,
		"Init DB": true, "Change user": true, "Prepare": true,
		"Close stmt": true, "Fetch": true, "Daemon": true,
		"Sleep": true, "Ping": true, "Binlog Dump": true,
		"Table Dump": true, "Error": true, "Killed": true,
		"Field List": true, "Set option": true, "Register": true,
		"Create DB": true, "Drop DB": true, "Alter DB": true,
		"Debug": true, "Shutdown": true, "Statistics": true,
		"Processlist": true, "Connect Out": true, "Delayed": true,
	}
	return known[cmd]
}

func isInternalSQL(q string) bool {
	lower := strings.TrimSpace(strings.ToUpper(q))
	if strings.HasPrefix(lower, "SET") ||
		strings.HasPrefix(lower, "SHOW") ||
		strings.HasPrefix(lower, "SELECT @@") ||
		strings.HasPrefix(lower, "SELECT DATABASE()") ||
		strings.HasPrefix(lower, "SELECT CURRENT_USER") ||
		strings.HasPrefix(lower, "SELECT VERSION") ||
		strings.HasPrefix(lower, "SELECT CONNECTION_ID") ||
		q == "SELECT 1" || q == "SELECT 1 AS 1" {
		return true
	}
	// Filter system schema queries (always internal/monitoring)
	if strings.Contains(lower, " FROM INFORMATION_SCHEMA.") ||
		strings.Contains(lower, " FROM PERFORMANCE_SCHEMA.") ||
		strings.Contains(lower, "FROM MYSQL.GENERAL_LOG") ||
		strings.Contains(lower, "FROM MYSQL.SLOW_LOG") ||
		strings.HasPrefix(lower, "DESCRIBE ") ||
		strings.HasPrefix(lower, "EXPLAIN ") {
		return true
	}
	// Filter the agent's own monitoring queries
	if strings.Contains(lower, "SHOW FULL PROCESSLIST") ||
		strings.Contains(lower, "SHOW BINARY LOGS") ||
		strings.Contains(lower, "SHOW GLOBAL VARIABLES") ||
		strings.Contains(lower, "SHOW GLOBAL STATUS") ||
		strings.Contains(lower, "SHOW TABLE STATUS") ||
		strings.Contains(lower, "SHOW DATABASES") ||
		strings.Contains(lower, "CONNECTION_ID()") ||
		strings.Contains(lower, "PG_STAT_ACTIVITY") ||
		strings.Contains(lower, "PG_STAT_STATEMENTS") ||
		strings.Contains(lower, "PG_STAT_USER_TABLES") ||
		strings.Contains(lower, "PG_ROLES") ||
		strings.Contains(lower, "SYS.DM_EXEC") ||
		strings.Contains(lower, "SERVERPROPERTY") ||
		strings.Contains(lower, "SLOWLOG") ||
		strings.Contains(lower, "CURRENTOP()") {
		return true
	}
	return false
}

// ──────────────────────────────────────────────
// Poll mysql.general_log TABLE for queries (robust fallback)
// ──────────────────────────────────────────────

func (m *ActivityMonitor) pollMySQLGeneralLogTable(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT event_time, thread_id, command_type, argument, user_host
		FROM mysql.general_log
		WHERE command_type = 'Query'
		  AND argument IS NOT NULL
		  AND argument != ''
		ORDER BY event_time DESC
		LIMIT 200
	`)
	if err != nil {
		logMsg("MySQL: general_log table poll FAILED: %v", err)
		return
	}
	defer rows.Close()

	m.mu.RLock()
	lastMaxTime := m.lastTableMaxTime
	m.mu.RUnlock()

	// Clean the dedup map if it's gotten large
	m.mu.Lock()
	if m.seenGeneralQueries == nil {
		m.seenGeneralQueries = make(map[string]bool)
	}
	m.generalPollCount++
	if m.generalPollCount%20 == 0 && len(m.seenGeneralQueries) > 500 {
		m.seenGeneralQueries = make(map[string]bool)
	}
	m.mu.Unlock()

	var count int
	for rows.Next() {
		var eventTimeRaw []byte
		var threadID int64
		var cmdType, argument, userHost string
		if err := rows.Scan(&eventTimeRaw, &threadID, &cmdType, &argument, &userHost); err != nil {
			logMsg("MySQL: general_log table poll scan error: %v", err)
			continue
		}
		eventTime, err := time.ParseInLocation("2006-01-02 15:04:05.000000", string(eventTimeRaw), time.Local)
		if err != nil {
			eventTime, err = time.ParseInLocation("2006-01-02 15:04:05", string(eventTimeRaw), time.Local)
			if err != nil {
				eventTime = time.Now()
			}
		}

		// Composite cursor: skip if this row is clearly before our last max
		// (accept a 2-second overlap to handle second-precision edge cases)
		if !lastMaxTime.IsZero() && eventTime.Before(lastMaxTime.Add(-2*time.Second)) {
			continue
		}

		if isInternalSQL(argument) {
			continue
		}

		// Dedup: same event_time + thread_id + query text → already processed
		key := fmt.Sprintf("%s/%d/%.150s", eventTime.Format("20060102150405"), threadID, argument)
		if m.seenGeneralQueries[key] {
			continue
		}
		m.seenGeneralQueries[key] = true

		user := userHost
		host := ""
		if idx := strings.Index(userHost, "@"); idx >= 0 {
			user = strings.TrimSpace(userHost[:idx])
			host = strings.TrimSpace(userHost[idx+1:])
		}

		logMsg("MySQL: general_log table poll ➜ [%s@%s] %.180s", user, host, argument)
		m.queueQueryLog(conn.Database, user, host, argument, conn.Engine, "query", nil)
		count++

		// Track last max event_time
		m.mu.Lock()
		if eventTime.After(m.lastTableMaxTime) {
			m.lastTableMaxTime = eventTime
		}
		m.mu.Unlock()
	}

	if count > 0 {
		logMsg("MySQL: general_log table poll: %d new queries captured ✅", count)
	}

	m.mu.Lock()
	m.lastTablePollTime = time.Now()
	m.mu.Unlock()

	if count > 0 {
		go m.runAIAnalysisOnPoll()
	}
}

func (m *ActivityMonitor) runAIAnalysisOnPoll() {
	defer func() {
		if r := recover(); r != nil {
			logMsg("AI Analyzer: recovered from panic: %v", r)
		}
	}()

	engine := GetDBEngine()
	analyzer := GetAIAnalyzer()

	// MySQL general log
	mysqlEntries := engine.GetMySQLGeneralLogEntries(200)
	if len(mysqlEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(mysqlEntries)
		if result != nil && len(result.Findings) > 0 {
			logMsg("AI Analyzer [MySQL]: %d hallazgos (riesgo: %.1f/100)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					logMsg("AI Analyzer [MySQL] [%s] %s: %s", strings.ToUpper(f.Severity), f.Title, f.Description)
					wsSendEvent("[MySQL] "+f.Title, f.Description, "ai_analyzer", f.Severity, false)
				}
			}
		}
	}

	// PostgreSQL activity
	pgEntries := engine.GetPostgreSQLActivityLog(100)
	if len(pgEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(pgEntries)
		if result != nil && len(result.Findings) > 0 {
			logMsg("AI Analyzer [PG]: %d hallazgos (riesgo: %.1f/100)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[PG] "+f.Title, f.Description, "ai_analyzer", f.Severity, false)
				}
			}
		}
	}

	// MSSQL activity
	mssqlEntries := engine.GetMSSQLActiveSessions()
	if len(mssqlEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(mssqlEntries)
		if result != nil && len(result.Findings) > 0 {
			logMsg("AI Analyzer [MSSQL]: %d hallazgos (riesgo: %.1f/100)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[MSSQL] "+f.Title, f.Description, "ai_analyzer", f.Severity, false)
				}
			}
		}
	}

	// Redis slow log
	redisEntries := engine.GetRedisSlowLogEntries(50)
	if len(redisEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(redisEntries)
		if result != nil && len(result.Findings) > 0 {
			logMsg("AI Analyzer [Redis]: %d hallazgos (riesgo: %.1f/100)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[Redis] "+f.Title, f.Description, "ai_analyzer", f.Severity, false)
				}
			}
		}
	}
}

// ──────────────────────────────────────────────
// Auto-enable performance_schema by editing my.ini + restarting MySQL
// ──────────────────────────────────────────────

func (m *ActivityMonitor) autoEnablePerformanceSchema(db *sql.DB, conn *DBConnection) {
	if runtime.GOOS != "windows" {
		return
	}

	var varName, varValue string
	err := db.QueryRow("SHOW VARIABLES LIKE 'performance_schema'").Scan(&varName, &varValue)
	if err != nil {
		return
	}
	if varValue == "ON" {
		return
	}

	logMsg("MySQL: performance_schema está OFF — intentando activarlo automáticamente")

	// Try to find the config file
	configFiles := []string{
		`C:\xampp\mysql\bin\my.ini`,
		`C:\xampp\mariadb\bin\my.ini`,
		`C:\ProgramData\MySQL\MySQL Server 8.0\my.ini`,
		`C:\ProgramData\MySQL\MySQL Server 5.7\my.ini`,
		`C:\ProgramData\MariaDB\MariaDB 10.*\my.ini`,
	}

	// Also query @@basedir for XAMPP
	var baseDir string
	if err := db.QueryRow("SELECT @@basedir").Scan(&baseDir); err == nil {
		baseDir = filepath.Clean(baseDir)
		configFiles = append([]string{
			filepath.Join(baseDir, "bin", "my.ini"),
			filepath.Join(baseDir, "my.ini"),
		}, configFiles...)
	}

	var configPath string
	for _, p := range configFiles {
		if fi, err := os.Stat(p); err == nil && !fi.IsDir() {
			configPath = p
			break
		}
	}
	if configPath == "" {
		logMsg("MySQL: no se encontró my.ini — no se puede activar performance_schema automáticamente")
		return
	}

	logMsg("MySQL: config file encontrado: %s", configPath)

	// Read the file
	data, err := os.ReadFile(configPath)
	if err != nil {
		logMsg("MySQL: error al leer %s: %v", configPath, err)
		return
	}

	content := string(data)

	// Check if performance_schema is already in the file
	re := regexp.MustCompile(`(?m)^\s*performance_schema\s*=\s*(OFF|0|ON|1)\s*$`)
	matches := re.FindStringSubmatch(content)
	if len(matches) == 2 && (matches[1] == "ON" || matches[1] == "1") {
		return // already on in config
	}

	if len(matches) == 2 && (matches[1] == "OFF" || matches[1] == "0") {
		// Change OFF → ON
		content = re.ReplaceAllString(content, "performance_schema=ON")
		logMsg("MySQL: cambiando performance_schema=OFF → ON en %s", configPath)
	} else {
		// Not present — add it under [mysqld]
		sectionRe := regexp.MustCompile(`(?m)^\[mysqld\]\s*$`)
		if loc := sectionRe.FindStringIndex(content); loc != nil {
			insertAt := loc[1]
			content = content[:insertAt] + "\nperformance_schema=ON\n" + content[insertAt:]
		} else {
			content += "\n[mysqld]\nperformance_schema=ON\n"
		}
		logMsg("MySQL: añadiendo performance_schema=ON en %s", configPath)
	}

	// Write the file
	if err := os.WriteFile(configPath, []byte(content), 0644); err != nil {
		logMsg("MySQL: error al escribir %s: %v", configPath, err)
		return
	}
	logMsg("MySQL: %s actualizado — reiniciando MySQL/MariaDB...", configPath)

	// Restart MySQL
	serviceNames := []string{"MySQL", "MariaDB", "xammysql", "mariadb"}
	for _, svc := range serviceNames {
		stopOut, stopErr := exec.Command("net", "stop", svc).CombinedOutput()
		if stopErr != nil {
			continue // try next service name
		}
		logMsg("MySQL: servicio %s detenido: %s", svc, strings.TrimSpace(string(stopOut)))
		time.Sleep(3 * time.Second)
		startOut, startErr := exec.Command("net", "start", svc).CombinedOutput()
		if startErr == nil {
			logMsg("MySQL: servicio %s reiniciado correctamente con performance_schema=ON", svc)
			return
		}
		logMsg("MySQL: fallo al iniciar servicio %s: %s", svc, strings.TrimSpace(string(startOut)))
	}

	// Fallback: try mysqladmin shutdown + mysqld
	logMsg("MySQL: intentando reinicio vía mysqladmin shutdown...")
	baseDir = filepath.Dir(filepath.Dir(configPath))
	mysqladminPath := filepath.Join(baseDir, "bin", "mysqladmin.exe")
	if _, err := os.Stat(mysqladminPath); err == nil {
		exec.Command(mysqladminPath, "-u", "root", "shutdown").Run()
		time.Sleep(5 * time.Second)
		mysqldPath := filepath.Join(baseDir, "bin", "mysqld.exe")
		if _, err := os.Stat(mysqldPath); err == nil {
			exec.Command(mysqldPath, "--defaults-file="+configPath).Start()
			logMsg("MySQL: mysqld reiniciado con performance_schema=ON")
		}
	}
}

// ──────────────────────────────────────────────
// PostgreSQL Monitor
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorPostgreSQL(db *sql.DB, conn *DBConnection) {
	m.trackPGActivity(db, conn)
	m.pollPGAuditLog(db, conn)
	m.checkPGSlowQueries(db, conn)
	m.checkPGPermissions(db, conn)
	m.checkPGLogs(db, conn)
	m.checkPGReplication(db, conn)
	m.checkPGTableStats(db, conn)
}

func (m *ActivityMonitor) trackPGActivity(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT
			pid, usename, application_name,
			COALESCE(host(client_addr)::text, 'local') as client_host,
			state, query, query_start, state_change,
			wait_event, backend_start, pg_blocking_pids(pid) as blockers
		FROM pg_stat_activity
		WHERE state != 'idle' AND pid != pg_backend_pid()
		ORDER BY query_start DESC NULLS LAST
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	cols, _ := rows.Columns()
	for rows.Next() {
		vals := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			continue
		}

		var usename, clientHost, query, waitEvent string
		var queryStart time.Time
		var pid int64
		for i, col := range cols {
			v := vals[i]
			s := fmt.Sprintf("%v", v)
			if b, ok := v.([]byte); ok {
				s = string(b)
			}
			switch col {
			case "pid":
				pid, _ = v.(int64)
			case "usename":
				usename = s
			case "client_host":
				clientHost = s
			case "query":
				query = s
			case "wait_event":
				waitEvent = s
			case "query_start":
				queryStart, _ = v.(time.Time)
			}
		}

		if usename == "" || isInternalUserAdvanced(usename) {
			continue
		}

		m.recordUserActivity(usename, clientHost, conn)

		if query != "" && !isInternalQueryAdvanced(query) {
			m.processQuery(query, usename, clientHost, conn.Database, conn)
		}

		if waitEvent != "" && waitEvent != "NULL" {
			logMsg("PG Activity: user=%s wait_event=%s query=%.80s", usename, waitEvent, query)
		}

		if !queryStart.IsZero() && time.Since(queryStart) > 30*time.Second && query != "" {
			m.reportAnomaly(AnomalyReport{
				Type:        "long_running",
				Severity:    "medium",
				Description: fmt.Sprintf("Consulta PostgreSQL de larga duración (>30s): usuario=%s pid=%d", usename, pid),
				Query:       query,
				User:        usename,
				Host:        clientHost,
				AutoBlock:   false,
			})
		}
	}
}

func (m *ActivityMonitor) checkPGLogs(db *sql.DB, conn *DBConnection) {
	var logDir string
	err := db.QueryRow("SHOW log_directory").Scan(&logDir)
	if err != nil {
		return
	}
	if logDir != "" {
		var dataDir string
		db.QueryRow("SHOW data_directory").Scan(&dataDir)
		fullLogDir := logDir
		if !filepath.IsAbs(logDir) && dataDir != "" {
			fullLogDir = filepath.Join(dataDir, logDir)
		}
		if entries, err := os.ReadDir(fullLogDir); err == nil {
			m.mu.Lock()
			for _, e := range entries {
				if !e.IsDir() {
					path := filepath.Join(fullLogDir, e.Name())
					if info, err := e.Info(); err == nil {
						m.discoveredLogs = append(m.discoveredLogs, DBLogFileInfo{
							Path:      path,
							SizeBytes: info.Size(),
							ModTime:   info.ModTime(),
							Engine:    "postgresql",
							LogType:   "pg_log",
							Accessible: true,
						})
					}
				}
			}
			m.mu.Unlock()
			logMsg("Activity Monitor: discovered %d PostgreSQL log files in %s", len(entries), fullLogDir)
		}

		// Also discover WAL files in pg_wal/pg_xlog
		walDirs := []string{
			filepath.Join(dataDir, "pg_wal"),
			filepath.Join(dataDir, "pg_xlog"),
			filepath.Join(dataDir, "wal"),
		}
		for _, walDir := range walDirs {
			if walEntries, err := os.ReadDir(walDir); err == nil {
				m.mu.Lock()
				for _, e := range walEntries {
					if !e.IsDir() {
						path := filepath.Join(walDir, e.Name())
						if info, err := e.Info(); err == nil {
							m.discoveredLogs = append(m.discoveredLogs, DBLogFileInfo{
								Path:      path,
								SizeBytes: info.Size(),
								ModTime:   info.ModTime(),
								Engine:    "postgresql",
								LogType:   "wal_log",
								Accessible: true,
							})
						}
					}
				}
				m.mu.Unlock()
				logMsg("Activity Monitor: discovered %d WAL files in %s", len(walEntries), walDir)
			}
		}
	}
}

func (m *ActivityMonitor) checkPGReplication(db *sql.DB, conn *DBConnection) {
	var isReplica string
	err := db.QueryRow("SELECT pg_is_in_recovery()::text").Scan(&isReplica)
	if err != nil {
		return
	}
	if isReplica == "true" {
		logMsg("Activity Monitor: PostgreSQL is a REPLICA (read-only)")
	}
}

func (m *ActivityMonitor) pollPGAuditLog(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT pid, usename, COALESCE(query, ''), query_start, state
		FROM pg_stat_activity
		WHERE query IS NOT NULL
		  AND query != ''
		  AND state != 'idle'
		  AND pid != pg_backend_pid()
		ORDER BY query_start DESC
		LIMIT 200
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	count := 0
	for rows.Next() {
		var pid int64
		var usename, query, state string
		var queryStart time.Time

		if err := rows.Scan(&pid, &usename, &query, &queryStart, &state); err != nil {
			continue
		}

		if usename == "" || isInternalUserAdvanced(usename) {
			continue
		}
		if isInternalQueryAdvanced(query) {
			continue
		}

		key := fmt.Sprintf("pg/%d/%.150s", pid, query)
		m.mu.RLock()
		seen := m.seenPGQueries[key]
		m.mu.RUnlock()
		if seen {
			continue
		}
		m.mu.Lock()
		m.seenPGQueries[key] = true
		if len(m.seenPGQueries) > 1000 {
			m.seenPGQueries = make(map[string]bool)
		}
		m.mu.Unlock()

		m.processQuery(query, usename, "", conn.Database, conn)
		count++
	}

	if count > 0 {
		logMsg("PG Audit: %d new queries captured", count)
	}
}

func (m *ActivityMonitor) checkPGSlowQueries(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT pid, usename, COALESCE(query, ''), query_start, state,
			   EXTRACT(EPOCH FROM (now() - query_start)) as duration_sec
		FROM pg_stat_activity
		WHERE state != 'idle'
		  AND pid != pg_backend_pid()
		  AND query_start IS NOT NULL
		  AND EXTRACT(EPOCH FROM (now() - query_start)) > 5
		ORDER BY duration_sec DESC
		LIMIT 50
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var pid int64
		var usename, query, state string
		var queryStart time.Time
		var durationSec float64

		if err := rows.Scan(&pid, &usename, &query, &queryStart, &state, &durationSec); err != nil {
			continue
		}

		if usename == "" || isInternalUserAdvanced(usename) {
			continue
		}

		severity := "medium"
		if durationSec > 30 {
			severity = "high"
		}
		if durationSec > 120 {
			severity = "critical"
		}

		m.reportAnomaly(AnomalyReport{
			Type:        "pg_slow_query",
			Severity:    severity,
			Description: fmt.Sprintf("PG consulta lenta (%.1fs): usuario=%s pid=%d", durationSec, usename, pid),
			Query:       query,
			User:        usename,
		})
	}
}

func (m *ActivityMonitor) checkPGPermissions(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolcanlogin, rolreplication
		FROM pg_roles
		WHERE rolname NOT LIKE 'pg_%'
		  AND rolname != 'postgres'
		ORDER BY rolname
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var name string
		var super, createRole, createDB, canLogin, replication bool

		if err := rows.Scan(&name, &super, &createRole, &createDB, &canLogin, &replication); err != nil {
			continue
		}

		if super && !isInternalUserAdvanced(name) {
			logMsg("PG Security: user %s has SUPERUSER privileges", name)
		}
		if createRole && canLogin && !isInternalUserAdvanced(name) {
			logMsg("PG Security: user %s can CREATE ROLE + LOGIN", name)
		}
	}
}

func (m *ActivityMonitor) checkPGTableStats(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT schemaname, relname, n_tup_ins, n_tup_upd, n_tup_del, n_live_tup, n_dead_tup,
			   last_vacuum, last_autovacuum, last_analyze
		FROM pg_stat_user_tables
		ORDER BY n_live_tup DESC
		LIMIT 100
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var schema, relname string
		var ins, upd, del, live, dead int64
		var lastVacuum, lastAutoVacuum, lastAnalyze sql.NullTime

		if err := rows.Scan(&schema, &relname, &ins, &upd, &del, &live, &dead, &lastVacuum, &lastAutoVacuum, &lastAnalyze); err != nil {
			continue
		}

		tableKey := fmt.Sprintf("%s.%s", schema, relname)
		m.mu.Lock()
		m.tableAccess[tableKey] = &TableAccessStats{
			Name:       tableKey,
			ReadCount:  live,
			WriteCount: ins + upd + del,
			LastAccess: time.Now(),
		}
		m.mu.Unlock()

		if dead > live/10 && live > 1000 {
			logMsg("PG Vacuum: table %s has %d dead tuples (%.1f%% of live)", tableKey, dead, float64(dead)*100/float64(live))
		}
	}
}

// ──────────────────────────────────────────────
// MSSQL Monitor
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorMSSQL(db *sql.DB, conn *DBConnection) {
	m.trackMSSQLActivity(db, conn)
	m.pollMSSQLAuditLog(db, conn)
	m.checkMSSQLPermissions(db, conn)
	m.checkMSSQLPerformance(db, conn)
	m.checkMSSQLLogs(db, conn)
}

func (m *ActivityMonitor) trackMSSQLActivity(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT
			r.session_id, s.login_name, ISNULL(s.host_name, '') as host_name,
			ISNULL(r.command, '') as command, r.status,
			ISNULL(DB_NAME(r.database_id), '') as database_name,
			ISNULL(SUBSTRING(st.text,
				(r.statement_start_offset/2)+1,
				(CASE WHEN r.statement_end_offset = -1
					THEN DATALENGTH(st.text)
					ELSE r.statement_end_offset
				END - r.statement_start_offset)/2
			), '') as query_text,
			r.percent_complete, r.start_time, r.estimated_completion_time,
			s.program_name, s.client_interface_name
		FROM sys.dm_exec_requests r
		JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
		OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) st
		WHERE s.is_user_process = 1
		UNION
		SELECT
			s.session_id, s.login_name, ISNULL(s.host_name, ''),
			'AWAITING_COMMAND', s.status,
			ISNULL(DB_NAME(s.database_id), ''),
			'', 0, NULL, 0,
			s.program_name, s.client_interface_name
		FROM sys.dm_exec_sessions s
		WHERE s.is_user_process = 1
			AND s.session_id NOT IN (SELECT session_id FROM sys.dm_exec_requests)
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	cols, _ := rows.Columns()
	for rows.Next() {
		vals := make([]interface{}, len(cols))
		ptrs := make([]interface{}, len(cols))
		for i := range vals {
			ptrs[i] = &vals[i]
		}
		if err := rows.Scan(ptrs...); err != nil {
			continue
		}

		var loginName, hostName, queryText, dbName, progName string
		for i, col := range cols {
			v := vals[i]
			s := fmt.Sprintf("%v", v)
			if b, ok := v.([]byte); ok {
				s = string(b)
			}
			switch col {
			case "login_name":
				loginName = s
			case "host_name":
				hostName = s
			case "query_text":
				queryText = s
			case "database_name":
				dbName = s
			case "program_name":
				progName = s
			}
		}

		if loginName == "" || isInternalUserAdvanced(loginName) {
			continue
		}
		m.recordUserActivity(loginName, hostName+"|"+progName, conn)

		if queryText != "" && !isInternalQueryAdvanced(queryText) {
			m.processQuery(queryText, loginName, hostName, dbName, conn)
		}
	}
}

func (m *ActivityMonitor) checkMSSQLLogs(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT SERVERPROPERTY('ErrorLogFileName') as error_log
	`)
	if err != nil {
		return
	}
	defer rows.Close()
	if rows.Next() {
		var errorLogPath string
		if err := rows.Scan(&errorLogPath); err == nil && errorLogPath != "" {
			if info, err := os.Stat(errorLogPath); err == nil {
				m.mu.Lock()
				m.discoveredLogs = append(m.discoveredLogs, DBLogFileInfo{
					Path:       errorLogPath,
					SizeBytes:  info.Size(),
					ModTime:    info.ModTime(),
					Engine:     "mssql",
					LogType:    "error_log",
					Accessible: true,
				})
				m.mu.Unlock()
			}
		}
	}
}

func (m *ActivityMonitor) pollMSSQLAuditLog(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT TOP 200
			r.session_id, s.login_name, ISNULL(s.host_name, '') as host_name,
			ISNULL(DB_NAME(r.database_id), '') as database_name,
			ISNULL(SUBSTRING(st.text,
				(r.statement_start_offset/2)+1,
				((CASE WHEN r.statement_end_offset = -1
					THEN DATALENGTH(st.text)
					ELSE r.statement_end_offset
				END - r.statement_start_offset)/2
			), '') as query_text,
			r.start_time, r.command
		FROM sys.dm_exec_requests r
		JOIN sys.dm_exec_sessions s ON r.session_id = s.session_id
		OUTER APPLY sys.dm_exec_sql_text(r.sql_handle) st
		WHERE s.is_user_process = 1
		ORDER BY r.start_time DESC
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	count := 0
	for rows.Next() {
		var sessionID int64
		var loginName, hostName, dbName, queryText, command string
		var startTime time.Time

		if err := rows.Scan(&sessionID, &loginName, &hostName, &dbName, &queryText, &startTime, &command); err != nil {
			continue
		}

		if loginName == "" || isInternalUserAdvanced(loginName) {
			continue
		}
		if queryText == "" || isInternalQueryAdvanced(queryText) {
			continue
		}

		key := fmt.Sprintf("mssql/%d/%.150s", sessionID, queryText)
		m.mu.RLock()
		seen := m.seenMSSQLQueries[key]
		m.mu.RUnlock()
		if seen {
			continue
		}
		m.mu.Lock()
		m.seenMSSQLQueries[key] = true
		if len(m.seenMSSQLQueries) > 1000 {
			m.seenMSSQLQueries = make(map[string]bool)
		}
		m.mu.Unlock()

		m.processQuery(queryText, loginName, hostName, dbName, conn)
		count++
	}

	if count > 0 {
		logMsg("MSSQL Audit: %d new queries captured", count)
	}
}

func (m *ActivityMonitor) checkMSSQLPermissions(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT dp.name as principal_name, dp.type_desc, p.permission_name, p.state_desc
		FROM sys.database_permissions p
		JOIN sys.database_principals dp ON p.grantee_principal_id = dp.principal_id
		WHERE p.permission_name IN ('ALTER', 'CONTROL', 'IMPERSONATE', 'TAKE OWNERSHIP')
		  AND dp.name != 'dbo'
		ORDER BY dp.name
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var name, typeDesc, perm, state string
		if err := rows.Scan(&name, &typeDesc, &perm, &state); err != nil {
			continue
		}
		logMsg("MSSQL Security: user %s (%s) has %s %s", name, typeDesc, state, perm)
	}
}

func (m *ActivityMonitor) checkMSSQLPerformance(db *sql.DB, conn *DBConnection) {
	rows, err := db.Query(`
		SELECT TOP 20
			qs.execution_count,
			qs.total_worker_time/qs.execution_count AS avg_cpu,
			qs.total_elapsed_time/qs.execution_count AS avg_elapsed,
			qs.total_logical_reads/qs.execution_count AS avg_reads,
			SUBSTRING(st.text,
				(qs.statement_start_offset/2)+1,
				((CASE qs.statement_end_offset
					WHEN -1 THEN DATALENGTH(st.text)
					ELSE qs.statement_end_offset
				END - qs.statement_start_offset)/2)+1) AS query_text
		FROM sys.dm_exec_query_stats qs
		CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
		WHERE qs.execution_count > 100
		ORDER BY qs.total_elapsed_time DESC
	`)
	if err != nil {
		return
	}
	defer rows.Close()

	for rows.Next() {
		var execCount int64
		var avgCpu, avgElapsed, avgReads float64
		var queryText string

		if err := rows.Scan(&execCount, &avgCpu, &avgElapsed, &avgReads, &queryText); err != nil {
			continue
		}

		if avgElapsed > 1000 {
			severity := "medium"
			if avgElapsed > 5000 {
				severity = "high"
			}
			m.reportAnomaly(AnomalyReport{
				Type:        "mssql_slow_query",
				Severity:    severity,
				Description: fmt.Sprintf("MSSQL consulta lenta (avg %.0fms, %d ejecuciones)", avgElapsed, execCount),
				Query:       queryText,
			})
		}

		if avgReads > 0 && avgReads/avgElapsed > 10000 {
			logMsg("MSSQL Perf: query with high I/O ratio (%.0f reads/ms): %.100s", avgReads/avgElapsed, queryText)
		}
	}
}

// ──────────────────────────────────────────────
// MongoDB Monitor
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorMongoDB(conn *DBConnection) {
	logMsg("Activity Monitor: MongoDB monitoring via host-level analysis")

	// Try native driver connection for oplog reading
	if m.mongoNative == nil || !m.mongoNative.connected {
		native := m.connectMongoNative(conn)
		if native != nil {
			m.mu.Lock()
			m.mongoNative = native
			m.mu.Unlock()
			go native.watchOplog(m)
		}
	}

	hostDirs := []string{
		`C:\Program Files\MongoDB\Server`,
		`C:\ProgramData\MongoDB`,
		`C:\data\db`,
		`C:\data\log`,
		`C:\mongodb\log`,
		`C:\mongodb\data`,
		filepath.Join(os.Getenv("PROGRAMDATA"), "MongoDB"),
		filepath.Join(os.Getenv("APPDATA"), "MongoDB"),
		filepath.Join(os.Getenv("LOCALAPPDATA"), "MongoDB"),
	}
	for _, dir := range hostDirs {
		if dir == "" {
			continue
		}
		m.scanDirForDBLogs(dir, "mongodb")
	}
	m.scanDirForDBLogs(`C:\Program Files\MongoDB`, "mongodb")
	m.scanDirForDBLogs(filepath.Join(os.Getenv("PROGRAMFILES"), "MongoDB"), "mongodb")

	// Parse discovered MongoDB log files for operations
	m.parseMongoLogs()
}

func (m *ActivityMonitor) parseMongoLogs() {
	m.mu.RLock()
	logs := make([]DBLogFileInfo, 0)
	for _, l := range m.discoveredLogs {
		if l.Engine == "mongodb" && (l.LogType == "server_log" || l.LogType == "general_log") {
			logs = append(logs, l)
		}
	}
	m.mu.RUnlock()

	for _, lf := range logs {
		content := globalLogReader.ReadLogFile(lf.Path, "mongodb", lf.LogType)
		for _, line := range content.Lines {
			if line.Query != "" || strings.Contains(line.Content, "COMMAND") ||
				strings.Contains(line.Content, "query") || strings.Contains(line.Content, "insert") ||
				strings.Contains(line.Content, "update") || strings.Contains(line.Content, "delete") ||
				strings.Contains(line.Content, "find") || strings.Contains(line.Content, "aggregate") {

				entry := QueryLogEntry{
					Timestamp: time.Now(),
					User:      line.User,
					Query:     line.Content,
					Engine:    "mongodb",
					Operation: extractMongoOperation(line.Content),
				}
				if line.Timestamp != "" {
					if t, err := time.Parse(time.RFC3339, line.Timestamp); err == nil {
						entry.Timestamp = t
					}
				}
				m.addQueryLog(entry)
			}
		}
	}
}

func extractMongoOperation(content string) string {
	lower := strings.ToLower(content)
	switch {
	case strings.Contains(lower, "insert"):
		return "INSERT"
	case strings.Contains(lower, "update"):
		return "UPDATE"
	case strings.Contains(lower, "delete"):
		return "DELETE"
	case strings.Contains(lower, "find"), strings.Contains(lower, "query"):
		return "SELECT"
	case strings.Contains(lower, "aggregate"):
		return "AGGREGATE"
	case strings.Contains(lower, "create"):
		return "CREATE"
	case strings.Contains(lower, "drop"):
		return "DROP"
	default:
		return "COMMAND"
	}
}

// ──────────────────────────────────────────────
// SQLite Monitor
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorSQLite() {
	hostDirs := []string{
		`C:\ProgramData`,
		os.Getenv("APPDATA"),
		os.Getenv("LOCALAPPDATA"),
		os.Getenv("USERPROFILE"),
		filepath.Join(os.Getenv("USERPROFILE"), "Documents"),
		filepath.Join(os.Getenv("USERPROFILE"), "Downloads"),
		filepath.Join(os.Getenv("USERPROFILE"), "Desktop"),
		`C:\Users`,
	}
	for _, dir := range hostDirs {
		if dir == "" {
			continue
		}
		m.scanDirForDBLogs(dir, "sqlite")
	}

	// Try to connect to discovered SQLite databases and monitor activity
	m.mu.RLock()
	var sqliteFiles []string
	for _, l := range m.discoveredLogs {
		if l.Engine == "sqlite" {
			sqliteFiles = append(sqliteFiles, l.Path)
		}
	}
	m.mu.RUnlock()

	for _, dbPath := range sqliteFiles {
		m.trackSQLiteActivity(dbPath)
	}
}

func (m *ActivityMonitor) trackSQLiteActivity(dbPath string) {
	// Skip WAL, SHM, journal files
	lower := strings.ToLower(filepath.Ext(dbPath))
	if lower == ".wal" || lower == ".shm" || strings.Contains(strings.ToLower(dbPath), "-journal") {
		return
	}
	if !strings.HasSuffix(strings.ToLower(dbPath), ".db") &&
		!strings.HasSuffix(strings.ToLower(dbPath), ".sqlite") &&
		!strings.HasSuffix(strings.ToLower(dbPath), ".sqlite3") {
		return
	}

	conn := DBConnection{
		Engine:   "sqlite",
		Host:     dbPath,
		Database: dbPath,
	}

	db, err := openDBWithStrategy(conn, "direct")
	if err != nil {
		return
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		return
	}

	// Read schema
	tables, err := getTables(db, "sqlite")
	if err != nil {
		return
	}

	// Check row counts and sizes for each table
	for _, table := range tables {
		var rowCount int64
		err := db.QueryRow(fmt.Sprintf("SELECT COUNT(*) FROM \"%s\"", table)).Scan(&rowCount)
		if err != nil {
			continue
		}

		m.mu.Lock()
		stats, exists := m.tableAccess[table]
		if !exists {
			stats = &TableAccessStats{
				Name:  table,
				Users: make(map[string]int),
			}
			m.tableAccess[table] = stats
		}
		stats.ReadCount++
		stats.LastAccess = time.Now()
		stats.Users["local"]++
		m.mu.Unlock()

		// Log the schema read as a query entry
		entry := QueryLogEntry{
			Timestamp: time.Now(),
			User:      "system",
			Database:  filepath.Base(dbPath),
			Query:     fmt.Sprintf("SELECT COUNT(*) FROM %s", table),
			Engine:    "sqlite",
			Operation: "SELECT",
			Tables:    []string{table},
		}
		m.addQueryLog(entry)
	}

	// Check for WAL mode - indicates active writes
	var journalMode string
	_ = db.QueryRow("PRAGMA journal_mode").Scan(&journalMode)
	if journalMode == "wal" {
		logMsg("SQLite: %s in WAL mode - active writes possible", dbPath)
	}

	// Check for read-only mode
	var readOnly bool
	_ = db.QueryRow("PRAGMA read_uncommitted").Scan(&readOnly)
}

// ──────────────────────────────────────────────
// Generic Monitor (fallback for any engine)
// ──────────────────────────────────────────────

func (m *ActivityMonitor) monitorGeneric(db *sql.DB, conn *DBConnection) {
	// Try information_schema
	rows, err := db.Query(`
		SELECT table_schema, table_name, table_type
		FROM information_schema.tables
		WHERE table_type = 'BASE TABLE'
		LIMIT 200
	`)
	if err != nil {
		return
	}
	defer rows.Close()
	for rows.Next() {
		var schema, name, ttype string
		if err := rows.Scan(&schema, &name, &ttype); err == nil {
			m.mu.Lock()
			stats, exists := m.tableAccess[name]
			if !exists {
				stats = &TableAccessStats{
					Name:  name,
					Users: make(map[string]int),
				}
				m.tableAccess[name] = stats
			}
			stats.LastAccess = time.Now()
			m.mu.Unlock()
		}
	}
}

// ──────────────────────────────────────────────
// Query Processing & Analysis
// ──────────────────────────────────────────────

func (m *ActivityMonitor) processQuery(query, user, host, dbName string, conn *DBConnection) {
	normalized := normalizeQuery(query)
	lower := strings.TrimSpace(strings.ToLower(query))
	if lower == "" {
		return
	}

	hash := simpleHash(normalized)
	qClass := classifyQuery(lower)

	// Track query
	m.mu.Lock()
	qt, exists := m.activeQueries[hash]
	if !exists {
		qt = &QueryTrack{
			Hash:       hash,
			Normalized: normalized,
			User:       user,
			FirstSeen:  time.Now(),
		}
		m.activeQueries[hash] = qt
	}
	qt.Count++
	qt.LastSeen = time.Now()
	qt.LastTiming = append(qt.LastTiming, time.Now())
	if len(qt.LastTiming) > 100 {
		qt.LastTiming = qt.LastTiming[len(qt.LastTiming)-100:]
	}
	intervalCount := len(qt.LastTiming)
	if intervalCount > 1 {
		totalInterval := qt.LastTiming[intervalCount-1].Sub(qt.LastTiming[0]).Seconds()
		if totalInterval > 0 {
			qt.AvgInterval = totalInterval / float64(intervalCount-1)
		}
	}

	// Track table-level access
	tables := extractTableNames(query)
	detectedSensitive := false
	for _, tbl := range tables {
		stats, exists := m.tableAccess[tbl]
		if !exists {
			stats = &TableAccessStats{
				Name:  tbl,
				Users: make(map[string]int),
			}
			m.tableAccess[tbl] = stats
		}
		stats.ReadCount++
		stats.LastAccess = time.Now()
		stats.LastReadQuery = query
		stats.Users[user]++

		// Check if sensitive
		for _, st := range m.sensitiveTables {
			if strings.EqualFold(tbl, st.Name) {
				stats.IsSensitive = true
				detectedSensitive = true
				break
			}
		}
	}

	// Track user profile
	profile, exists := m.userProfiles[user]
	if !exists {
		profile = &UserBehavior{
			User:           user,
			TypicalHours:   make(map[int]int),
			TypicalQueries: make(map[string]int),
			TablesUsed:     make(map[string]int),
			HostsUsed:      make(map[string]int),
			FirstActivity:  time.Now(),
		}
		m.userProfiles[user] = profile
	}
	profile.LastActivity = time.Now()
	profile.TotalQueries++
	profile.TypicalQueries[normalized]++
	profile.TypicalHours[time.Now().Hour()]++
	for _, tbl := range tables {
		profile.TablesUsed[tbl]++
	}
	if host != "" {
		profile.HostsUsed[host]++
	}
	m.mu.Unlock()

	// User tracking
	m.recordUserActivity(user, host, conn)

	// ── Anomaly Detection ──

	riskScore := 0

	// 1. Dump detection
	dumpType, isDump := m.detectDumpPatterns(lower, query, conn)
	if isDump {
		riskScore += 50
		m.reportAnomaly(AnomalyReport{
			Type:        "dump_detected",
			Severity:    "high",
			Description: fmt.Sprintf("⚠️ POSIBLE DUMPEO: %s — usuario '%s' desde %s en %s/%s",
				dumpType, user, host, conn.Engine, dbName),
			Query:     query,
			User:      user,
			Host:      host,
			AutoBlock: true,
		})
	}

	// 2. Export detection
	if exportType := m.detectExport(lower); exportType != "" {
		riskScore += 80
		m.reportAnomaly(AnomalyReport{
			Type:        "data_export",
			Severity:    "critical",
			Description: fmt.Sprintf("🚨 EXPORTACIÓN DE DATOS: %s por usuario '%s' desde %s",
				exportType, user, host),
			Query:     query,
			User:      user,
			Host:      host,
			AutoBlock: true,
		})
	}

	// 3. Sensitive table access
	if detectedSensitive {
		riskScore += 20
		m.reportAnomaly(AnomalyReport{
			Type:        "sensitive_access",
			Severity:    "medium",
			Description: fmt.Sprintf("🔍 Acceso a tabla SENSIBLE por usuario '%s' desde %s",
				user, host),
			Query:     query,
			User:      user,
			Host:      host,
			AutoBlock: false,
		})
	}

	// 4. DDL detection (schema changes)
	if qClass == QueryDDLAlter || qClass == QueryDDLDrop || qClass == QueryDDLCreate {
		riskScore += 30
		m.reportAnomaly(AnomalyReport{
			Type:        "schema_change",
			Severity:    "high",
			Description: fmt.Sprintf("⚠️ Cambio de esquema DDL por usuario '%s': %.120s", user, query),
			Query:       query,
			User:        user,
			Host:        host,
			AutoBlock:   false,
		})
		m.mu.Lock()
		for _, tbl := range tables {
			if stats, ok := m.tableAccess[tbl]; ok {
				stats.DDLCount++
			}
		}
		m.mu.Unlock()
	}

	// 5. Privilege changes
	if qClass == QueryDCLGrant || qClass == QueryDCLRevoke {
		riskScore += 90
		m.reportAnomaly(AnomalyReport{
			Type:        "privilege_change",
			Severity:    "critical",
			Description: fmt.Sprintf("🚨 Cambio de privilegios por usuario '%s': %.150s", user, query),
			Query:       query,
			User:        user,
			Host:        host,
			AutoBlock:   true,
		})
	}

	// 6. Truncate detection
	if qClass == QueryDMLTruncate {
		riskScore += 50
		m.reportAnomaly(AnomalyReport{
			Type:        "truncate",
			Severity:    "critical",
			Description: fmt.Sprintf("🚨 TRUNCATE ejecutado por usuario '%s' en %s: %.120s", user, dbName, query),
			Query:       query,
			User:        user,
			Host:        host,
			AutoBlock:   true,
		})
	}

	// 7. Personal data query detection
	if category := m.detectPersonalDataQuery(lower); category != "" {
		riskScore += 15 + map[string]int{
			"salud": 30, "bancario": 30, "biometrico": 35, "credencial": 35,
		}[category]
		m.reportAnomaly(AnomalyReport{
			Type:        "personal_data_access",
			Severity:    "medium",
			Description: fmt.Sprintf("📋 Consulta de datos personales (%s) por usuario '%s'", category, user),
			Query:       query,
			User:        user,
			Host:        host,
			AutoBlock:   false,
		})
	}

	// 8. High-frequency query detection
	m.mu.RLock()
	qtCount := int64(0)
	if qt, ok := m.activeQueries[hash]; ok {
		qtCount = qt.Count
	}
	m.mu.RUnlock()
	if qtCount == 100 || qtCount == 500 || qtCount == 1000 || qtCount%1000 == 0 {
		sev := "medium"
		if qtCount >= 1000 {
			sev = "high"
		}
		m.reportAnomaly(AnomalyReport{
			Type:        "high_frequency",
			Severity:    sev,
			Description: fmt.Sprintf("📊 Consulta de alta frecuencia: %dx por usuario '%s'", qtCount, user),
			Query:       query,
			User:        user,
			Host:        host,
			AutoBlock:   false,
		})
	}

	// 9. Unusual time detection
	hour := time.Now().Hour()
	m.mu.RLock()
	profileExists := m.userProfiles[user]
	m.mu.RUnlock()
	if profileExists != nil && profileExists.TotalQueries > 20 {
		totalTypical := 0
		for h, cnt := range profileExists.TypicalHours {
			if h >= 8 && h <= 18 {
				totalTypical += cnt
			}
		}
		totalOff := profileExists.TotalQueries - int64(totalTypical)
		if totalOff > 0 && profileExists.TotalQueries > 0 {
			offPct := float64(totalOff) / float64(profileExists.TotalQueries) * 100
			if (hour < 6 || hour > 22) && offPct < 10 && qtCount < 5 {
				m.reportAnomaly(AnomalyReport{
					Type:        "unusual_hours",
					Severity:    "low",
					Description: fmt.Sprintf("🌙 Actividad en horario inusual (%d:00) por usuario '%s'", hour, user),
					Query:       query,
					User:        user,
					Host:        host,
					AutoBlock:   false,
				})
			}
		}
	}

	// 10. Sequential table scan pattern (potential iteration dumping)
	if strings.Contains(lower, "offset") && strings.Contains(lower, "limit") && strings.Contains(lower, "order by") {
		m.mu.Lock()
		iterKey := "iter:" + user + ":" + hash
		m.lastAlerts[iterKey] = time.Now()
		iterCount := 0
		cutoff := time.Now().Add(-2 * time.Minute)
		for k, t := range m.lastAlerts {
			if strings.HasPrefix(k, "iter:"+user+":") && t.After(cutoff) {
				iterCount++
			}
		}
		m.mu.Unlock()
		if iterCount > 30 {
			m.reportAnomaly(AnomalyReport{
				Type:        "sequential_scan",
				Severity:    "high",
				Description: fmt.Sprintf("🔄 Escaneo secuencial detectado (paginación): usuario '%s' — posible dumpeo iterativo", user),
				Query:       query,
				User:        user,
				Host:        host,
				AutoBlock:   false,
			})
		}
	}

	// Record history
	m.mu.Lock()
	if len(m.queryHistory) >= maxQueryHistory {
		m.queryHistory = m.queryHistory[len(m.queryHistory)/2:]
	}
	m.queryHistory = append(m.queryHistory, HistoricalQuery{
		Timestamp:  time.Now(),
		User:       user,
		Host:       host,
		Database:   dbName,
		Query:      query,
		Normalized: normalized,
		Type:       qClass,
		RiskScore:  riskScore,
	})
	m.mu.Unlock()

	// Queue for compliance logging (Ley 21.719)
	// Safety net: skip internal monitoring queries
	if isInternalSQL(query) {
		return
	}
	engine := ""
	if conn != nil {
		engine = conn.Engine
	}
	m.queueQueryLog(dbName, user, host, query, engine, qClass.String(), tables)
}

// ──────────────────────────────────────────────
// Pattern Detection Functions
// ──────────────────────────────────────────────

func (m *ActivityMonitor) detectDumpPatterns(lower, original string, conn *DBConnection) (string, bool) {
	// 1. SELECT * without filters on large tables
	if strings.Contains(lower, "select *") || strings.Contains(lower, "select\t*") || strings.Contains(lower, "select\n*") {
		noWhere := !strings.Contains(lower, "where") && !strings.Contains(lower, "limit") &&
			!strings.Contains(lower, "having") && !strings.Contains(lower, "offset")
		if noWhere {
			for _, tbl := range m.sensitiveTables {
				if strings.Contains(lower, strings.ToLower(tbl.Name)) {
					return fmt.Sprintf("SELECT * masivo en tabla sensible '%s' (posible robo de datos)", tbl.Name), true
				}
			}
			return "SELECT * completo sin filtro (todas las filas de todas las tablas)", true
		}
	}

	// 2. SELECT with many columns
	colCount := strings.Count(original, ",")
	if colCount > 20 {
		noWhere := !strings.Contains(lower, "where") && !strings.Contains(lower, "limit") && !strings.Contains(lower, "having")
		if noWhere {
			return fmt.Sprintf("SELECT con %d columnas sin filtro (extracción masiva)", colCount+1), true
		}
	}

	// 3. Information schema dump
	if strings.Contains(lower, "select") && strings.Contains(lower, "information_schema") {
		if strings.Contains(lower, "tables") || strings.Contains(lower, "columns") || strings.Contains(lower, "schemata") {
			if !strings.Contains(lower, "where") && !strings.Contains(lower, "limit") {
				return "Dumpeo del esquema completo (information_schema)", true
			}
		}
	}

	// 4. MySQL / PostgreSQL system catalog dump
	if strings.Contains(lower, "select") && strings.Contains(lower, "pg_catalog") {
		if !strings.Contains(lower, "where") && !strings.Contains(lower, "limit") {
			return "Dumpeo del catálogo PostgreSQL (pg_catalog)", true
		}
	}
	if strings.Contains(lower, "select") && strings.Contains(lower, "mysql.") {
		if strings.Contains(lower, "`mysql`.") || strings.Contains(lower, "mysql.user") || strings.Contains(lower, "mysql.db") {
			return "Acceso a tablas del sistema MySQL (credenciales)", true
		}
	}

	// 5. Schema enumeration on sensitive tables
	if strings.Contains(lower, "information_schema.columns") && len(m.sensitiveTables) > 0 {
		for _, st := range m.sensitiveTables {
			if strings.Contains(lower, strings.ToLower(st.Name)) {
				return fmt.Sprintf("Enumeración de esquema en tabla sensible '%s'", st.Name), true
			}
		}
	}

	// 6. SELECT COUNT(*) full scan
	if strings.Contains(lower, "count(*)") && !strings.Contains(lower, "where") && !strings.Contains(lower, "limit") {
		for _, st := range m.sensitiveTables {
			if strings.Contains(lower, strings.ToLower(st.Name)) {
				return fmt.Sprintf("Conteo masivo en tabla sensible '%s' (reconocimiento de volumen)", st.Name), true
			}
		}
	}

	// 7. UNION-based data extraction
	if strings.Contains(lower, "union select") || strings.Contains(lower, "union all select") {
		return "UNION SELECT detectado — posible inyección SQL o extracción", true
	}

	// 8. Subquery data extraction
	if strings.Contains(lower, "select") && strings.Contains(lower, "in (select") {
		if !strings.Contains(lower, "where") {
			return "Subconsulta masiva sin filtro", true
		}
	}

	// 9. Detect large IN clauses (credential stuffing / data lookup)
	if strings.Contains(lower, "in (") {
		inCount := strings.Count(lower, ",")
		if inCount > 100 && strings.Contains(lower, "select") {
			return fmt.Sprintf("Cláusula IN con %d valores — posible enumeración de datos", inCount+1), true
		}
	}

	return "", false
}

func (m *ActivityMonitor) detectExport(lower string) string {
	exports := []struct {
		pattern string
		label   string
		critical bool
	}{
		{"into outfile", "INTO OUTFILE — exportación a archivo", true},
		{"into dumpfile", "INTO DUMPFILE — exportación binaria", true},
		{"select * into ", "SELECT INTO — copia masiva entre tablas", true},
		{"copy ", "COPY — exportación PostgreSQL", true},
		{`\copy `, "\\COPY — exportación local PostgreSQL", true},
		{"copy (", "COPY WITH QUERY — exportación avanzada", true},
		{"export ", "EXPORT — comando de exportación", true},
		{"bcp ", "BCP — bulk copy program (MSSQL)", true},
		{"xp_cmdshell", "xp_cmdshell — ejecución de comandos (MSSQL)", true},
		{"xp_delete_file", "xp_delete_file — manipulación de archivos", true},
		{"sendmail", "sp_sendmail — posible exfiltración por email", true},
		{"sp_send_dbmail", "sp_send_dbmail — exfiltración por email (MSSQL)", true},
		{"bulk insert", "BULK INSERT — importación masiva", false},
		{"bulk collect", "BULK COLLECT — recolección masiva Oracle", false},
		{"utl_file", "UTL_FILE — escritura de archivos Oracle", true},
		{"utl_http", "UTL_HTTP — peticiones HTTP desde DB (exfiltración)", true},
		{"utl_mail", "UTL_MAIL — envío de email desde DB", true},
		{"dbms_export", "DBMS_EXPORT — exportación Oracle", true},
		{"dbms_datapump", "DBMS_DATAPUMP — datapump Oracle", true},
		{"pg_read_file", "pg_read_file — lectura de archivos (PostgreSQL)", true},
		{"pg_write_file", "pg_write_file — escritura de archivos (PostgreSQL)", true},
		{"lo_export", "lo_export — exportación de objetos grandes", true},
		{"amazon_redshift", "UNLOAD — exportación Redshift", true},
		{"s3://", "S3 URI — posible exportación a S3", true},
		{"azure://", "Azure URI — posible exportación a Azure", true},
		{"gcs://", "GCS URI — posible exportación a GCS", true},
		{"openrowset", "OPENROWSET — acceso externo (MSSQL)", true},
		{"openquery", "OPENQUERY — consulta remota (MSSQL)", true},
		{"opendatasource", "OPENDATASOURCE — fuente de datos externa", true},
		{"linked server", "Linked Server — servidor enlazado", true},
		{"sp_addlinkedserver", "sp_addlinkedserver — creación de linked server", true},
		{"backup database", "BACKUP DATABASE — respaldo completo de BD", true},
		{"backup log", "BACKUP LOG — respaldo de log", false},
		{"restore database", "RESTORE DATABASE — restauración de respaldo", true},
		{"restore log", "RESTORE LOG — restauración de log", false},
		{"dbcc shrinkfile", "DBCC SHRINKFILE — reducción de archivo", false},
		{"dbcc traceon", "DBCC TRACEON — habilitación de trace flags", false},
		{"alter database", "ALTER DATABASE — modificación de BD", false},
		{"sp_configure", "sp_configure — cambio de configuración", true},
		{"reconfigure", "RECONFIGURE — aplicación de configuración", false},
	}

	for _, e := range exports {
		if strings.Contains(lower, e.pattern) {
			return e.label
		}
	}
	return ""
}

func (m *ActivityMonitor) detectPersonalDataQuery(lower string) string {
	keywords := []struct {
		word     string
		category string
		weight   int
	}{
		{"rut", "RUT/identificador nacional", 8},
		{"run", "RUT/identificador nacional", 8},
		{"password", "credenciales de acceso", 10},
		{"contraseña", "credenciales de acceso", 10},
		{"passwd", "credenciales de acceso", 10},
		{"hash", "hash de contraseña", 10},
		{"auth_key", "llave de autenticación", 10},
		{"api_key", "API key", 10},
		{"secret", "secreto/llave", 10},
		{"token", "token de autenticación", 8},
		{"credit_card", "tarjeta de crédito", 10},
		{"tarjeta", "tarjeta bancaria", 9},
		{"cvv", "código de seguridad", 10},
		{"iban", "cuenta bancaria IBAN", 10},
		{"swift", "código SWIFT bancario", 9},
		{"bank_account", "cuenta bancaria", 10},
		{"cuenta_bancaria", "cuenta bancaria", 10},
		{"salud", "datos de salud", 10},
		{"medical", "datos médicos", 10},
		{"diagnostico", "diagnóstico médico", 10},
		{"enfermedad", "enfermedad", 10},
		{"seguro_medico", "seguro médico", 9},
		{"biometrico", "datos biométricos", 10},
		{"huella", "huella digital", 10},
		{"fingerprint", "huella digital", 10},
		{"face_id", "reconocimiento facial", 10},
		{"iris", "escaneo de iris", 10},
		{"dna", "información genética", 10},
		{"genero", "género/orientación", 8},
		{"gender", "género/orientación", 8},
		{"sexo", "género/orientación", 8},
		{"orientacion", "orientación sexual", 9},
		{"religion", "creencias religiosas", 9},
		{"partido_politico", "afiliación política", 9},
		{"email", "correo electrónico", 6},
		{"correo", "correo electrónico", 6},
		{"telefono", "número telefónico", 6},
		{"celular", "teléfono móvil", 6},
		{"direccion", "dirección física", 5},
		{"domicilio", "domicilio", 5},
		{"fecha_nacimiento", "fecha de nacimiento", 6},
		{"birth_date", "fecha de nacimiento", 6},
		{"edad", "edad", 3},
		{"ip_address", "dirección IP", 4},
		{"ip", "dirección IP", 4},
		{"ubicacion", "ubicación GPS", 5},
		{"latitude", "coordenadas GPS", 5},
		{"longitude", "coordenadas GPS", 5},
		{"salario", "información salarial", 7},
		{"salary", "información salarial", 7},
		{"ingresos", "información de ingresos", 7},
		{"sueldo", "información salarial", 7},
		{"discapacidad", "discapacidad", 9},
		{"criminal", "antecedentes penales", 9},
		{"delito", "antecedentes penales", 9},
		{"sindical", "afiliación sindical", 8},
		{"sindicato", "afiliación sindical", 8},
		{"estado_civil", "estado civil", 4},
		{"conyuge", "información del cónyuge", 5},
		{"hijos", "información de hijos", 4},
		{"nacionalidad", "nacionalidad", 3},
		{"passport", "pasaporte", 8},
		{"documento", "documento de identidad", 7},
		{"identificacion", "identificación", 7},
		{"ssn", "social security number", 10},
		{"social_security", "seguridad social", 9},
		{"nif", "identificador fiscal", 7},
		{"nie", "identificador extranjero", 7},
		{"nit", "identificador tributario", 7},
		{"cuil", "identificador argentino", 7},
		{"cuit", "identificador argentino", 7},
	}

	bestCategory := ""
	bestWeight := 0
	for _, kw := range keywords {
		if strings.Contains(lower, kw.word) {
			if kw.weight > bestWeight {
				bestWeight = kw.weight
				bestCategory = kw.category
			}
		}
	}
	return bestCategory
}

// ──────────────────────────────────────────────
// Host-Level Dump Detection
// ──────────────────────────────────────────────

func (m *ActivityMonitor) scanHostForDumps() {
	if time.Since(m.lastProcessScan) < 30*time.Second {
		return
	}
	m.lastProcessScan = time.Now()

	if runtime.GOOS != "windows" {
		return
	}

	dumpTools := []struct {
		name     string
		severity string
		desc     string
	}{
		{"mysqldump.exe", "high", "mysqldump — dump completo MySQL/MariaDB"},
		{"mysql.exe", "low", "cliente MySQL"},
		{"mariadb-dump.exe", "high", "mariadb-dump — dump completo MariaDB"},
		{"mariadb.exe", "low", "cliente MariaDB"},
		{"pg_dump.exe", "high", "pg_dump — dump completo PostgreSQL"},
		{"pg_dumpall.exe", "high", "pg_dumpall — dump completo de todo PostgreSQL"},
		{"psql.exe", "low", "cliente PostgreSQL"},
		{"sqlcmd.exe", "low", "sqlcmd — cliente MSSQL"},
		{"sqlservermanager.exe", "low", "SQL Server Manager"},
		{"mongodump.exe", "high", "mongodump — dump completo MongoDB"},
		{"mongoexport.exe", "high", "mongoexport — exportación MongoDB"},
		{"mongosh.exe", "low", "cliente MongoDB"},
		{"sqlite3.exe", "low", "cliente SQLite"},
		{"sqlitebrowser.exe", "low", "SQLite Browser"},
		{"dbeaver.exe", "low", "DBeaver — cliente multi-BD"},
		{"heidisql.exe", "low", "HeidiSQL — cliente MySQL/MariaDB"},
		{"tableplus.exe", "low", "TablePlus — cliente multi-BD"},
		{"datagrip.exe", "low", "DataGrip — IDE de bases de datos"},
		{"navicat.exe", "low", "Navicat — cliente multi-BD"},
		{"workbench.exe", "low", "MySQL Workbench"},
		{"azure-data-studio.exe", "low", "Azure Data Studio"},
	}

	cmd := exec.Command("tasklist.exe", "/FO", "CSV", "/NH")
	output, err := cmd.Output()
	if err != nil {
		return
	}

	scanner := bufio.NewScanner(strings.NewReader(string(output)))
	for scanner.Scan() {
		line := scanner.Text()
		parts := strings.Split(line, "\",\"")
		if len(parts) < 2 {
			continue
		}
		procName := strings.Trim(parts[0], "\"")
		pidStr := strings.Trim(parts[1], "\"")
		pid := 0
		fmt.Sscanf(pidStr, "%d", &pid)

		for _, dt := range dumpTools {
			if !strings.EqualFold(procName, dt.name) {
				continue
			}
			if _, exists := m.knownProcesses[pid]; exists {
				continue
			}
			m.mu.Lock()
			m.knownProcesses[pid] = ProcessTrack{
				Name:      procName,
				PID:       pid,
				Detected:  time.Now(),
				LastSeen:  time.Now(),
			}
			m.mu.Unlock()

			det := DumpDetection{
				Type:        "db_tool",
				Description: dt.desc,
				Command:     procName,
				PID:         pid,
				DetectedAt:  time.Now(),
				Severity:    dt.severity,
			}
			m.mu.Lock()
			m.detectedDumps = append(m.detectedDumps, det)
			m.mu.Unlock()

			if dt.severity == "high" {
				m.reportAnomaly(AnomalyReport{
					Type:        "dump_tool_detected",
					Severity:    "high",
					Description: fmt.Sprintf("🛠️ Herramienta de dump detectada en el sistema: %s (PID %d)", dt.desc, pid),
					Query:       procName,
					User:        "SYSTEM",
					Host:        "localhost",
					AutoBlock:   false,
				})
			}
		}
	}

	// Clean up stale processes
	m.mu.Lock()
	for pid, pt := range m.knownProcesses {
		if time.Since(pt.LastSeen) > 5*time.Minute {
			delete(m.knownProcesses, pid)
		}
	}
	m.mu.Unlock()
}

type ProcessTrack struct {
	Name     string
	PID      int
	Detected time.Time
	LastSeen time.Time
}

// ──────────────────────────────────────────────
// Log Discovery on Host
// ──────────────────────────────────────────────

func (m *ActivityMonitor) discoverDBLogsOnHost() {
	m.lastLogScan = time.Now()
	logMsg("Activity Monitor: scanning host for database logs...")

	searchDirs := []struct {
		dir    string
		engine string
	}{
		// XAMPP
		{`C:\xampp\mysql\data`, "mariadb"},
		{`C:\xampp\mysql\logs`, "mariadb"},
		{`C:\xampp\mariadb\data`, "mariadb"},
		{`C:\xampp\mariadb\logs`, "mariadb"},
		{`C:\xampp\mysql`, "mariadb"},
		{`C:\xampp\mariadb`, "mariadb"},
		// WAMP
		{`C:\wamp64\bin\mysql`, "mariadb"},
		{`C:\wamp\bin\mysql`, "mariadb"},
		{`C:\wamp64\logs`, "mariadb"},
		{`C:\wamp\logs`, "mariadb"},
		// Laragon
		{`C:\laragon\bin\mysql`, "mariadb"},
		{`C:\laragon\bin\mariadb`, "mariadb"},
		{`C:\laragon\data\mysql`, "mariadb"},
		{`C:\laragon\logs`, "mariadb"},
		// Program Files MySQL
		{`C:\Program Files\MySQL`, "mysql"},
		{`C:\Program Files (x86)\MySQL`, "mysql"},
		{`C:\ProgramData\MySQL`, "mysql"},
		// Program Files MariaDB
		{`C:\Program Files\MariaDB`, "mariadb"},
		{`C:\Program Files (x86)\MariaDB`, "mariadb"},
		{`C:\ProgramData\MariaDB`, "mariadb"},
		// PostgreSQL
		{`C:\Program Files\PostgreSQL`, "postgresql"},
		{`C:\Program Files (x86)\PostgreSQL`, "postgresql"},
		{`C:\ProgramData\PostgreSQL`, "postgresql"},
		// MongoDB
		{`C:\Program Files\MongoDB`, "mongodb"},
		{`C:\ProgramData\MongoDB`, "mongodb"},
		{`C:\data\db`, "mongodb"},
		{`C:\data\log`, "mongodb"},
		// MSSQL
		{`C:\Program Files\Microsoft SQL Server`, "mssql"},
		{`C:\ProgramData\Microsoft`, "mssql"},
		// Docker volumes
		{`C:\docker\mysql`, "mysql"},
		{`C:\docker\mariadb`, "mariadb"},
		{`C:\docker\postgres`, "postgresql"},
		{`C:\docker\mongo`, "mongodb"},
		{`C:\docker\volumes`, "unknown"},
		// AppData
		{filepath.Join(os.Getenv("LOCALAPPDATA"), "MySQL"), "mysql"},
		{filepath.Join(os.Getenv("LOCALAPPDATA"), "MariaDB"), "mariadb"},
		{filepath.Join(os.Getenv("LOCALAPPDATA"), "PostgreSQL"), "postgresql"},
		{filepath.Join(os.Getenv("LOCALAPPDATA"), "MongoDB"), "mongodb"},
		{filepath.Join(os.Getenv("APPDATA"), "MySQL"), "mysql"},
		{filepath.Join(os.Getenv("APPDATA"), "MariaDB"), "mariadb"},
		{filepath.Join(os.Getenv("APPDATA"), "PostgreSQL"), "postgresql"},
	}

	var allFound []DBLogFileInfo
	for _, sd := range searchDirs {
		found := m.scanDirForDBLogs(sd.dir, sd.engine)
		allFound = append(allFound, found...)
	}

	// Also scan common hidden locations
	hiddenDirs := []string{
		`C:\Windows\Temp`,
		filepath.Join(os.Getenv("TEMP")),
		filepath.Join(os.Getenv("TMP")),
		`C:\tmp`,
		filepath.Join(os.Getenv("USERPROFILE"), "AppData", "Local", "Temp"),
		filepath.Join(os.Getenv("USERPROFILE"), "AppData", "LocalLow"),
		filepath.Join(os.Getenv("USERPROFILE"), "AppData", "Roaming"),
	}
	for _, dir := range hiddenDirs {
		if dir == "" {
			continue
		}
		m.scanDirForDBLogs(dir, "unknown")
	}

	m.mu.Lock()
	m.discoveredLogs = append(m.discoveredLogs, allFound...)
	count := len(m.discoveredLogs)
	m.mu.Unlock()

	if count > 0 {
		logMsg("Activity Monitor: total %d DB log files discovered on host", count)
	}
}

func (m *ActivityMonitor) readDiscoveredLogContents() {
	m.mu.RLock()
	logs := make([]DBLogFileInfo, len(m.discoveredLogs))
	copy(logs, m.discoveredLogs)
	m.mu.RUnlock()

	if len(logs) == 0 {
		return
	}

	m.lastLogRead = time.Now()

	var logContents []LogFileContent
	readCount := 0
	maxRead := 20

	for _, lf := range logs {
		if readCount >= maxRead {
			break
		}
		if lf.Path == "" {
			continue
		}
		if _, err := os.Stat(lf.Path); os.IsNotExist(err) {
			continue
		}

		content := globalLogReader.ReadLogFile(lf.Path, lf.Engine, lf.LogType)
		logContents = append(logContents, *content)
		readCount++
	}

	if len(logContents) > 0 {
		wsSend(WSMessage{
			Type:    "db_log_contents",
			Results: logContents,
		})
	}
}

func (m *ActivityMonitor) scanDirForDBLogs(dir, engine string) []DBLogFileInfo {
	var found []DBLogFileInfo
	entries, err := os.ReadDir(dir)
	if err != nil {
		return found
	}

	logPatterns := []string{
		".log", ".err", ".txt", ".out",
		"error", "slow", "general", "mysql", "mariadb",
		"postgresql", "mongod", "mongo",
		"sqlserver", "errorlog", "ERRORLOG",
		"binlog", "relay-log", "ib_logfile",
		"ibdata", "undo_", "redolog",
		"audit", "wal", "xlog",
		"pg_stat", "pg_log",
		"mysqld", "mariadbd",
		"trace", "trc",
	}

	for _, entry := range entries {
		if entry.IsDir() {
			// Recursively check data/log subdirectories
			if entry.Name() == "data" || entry.Name() == "log" || entry.Name() == "logs" {
				subDir := filepath.Join(dir, entry.Name())
				m.scanDirForDBLogs(subDir, engine)
			}
			continue
		}

		name := strings.ToLower(entry.Name())
		matched := false
		for _, p := range logPatterns {
			if strings.Contains(name, p) {
				matched = true
				break
			}
		}
		if !matched {
			continue
		}

		info, err := entry.Info()
		if err != nil {
			continue
		}
		if info.Size() == 0 {
			continue
		}

		eng := engine
		if eng == "unknown" {
			eng = detectEngineFromPath(dir, entry.Name())
		}
		logType := detectLogTypeFromPattern(name)

		found = append(found, DBLogFileInfo{
			Path:       filepath.Join(dir, entry.Name()),
			SizeBytes:  info.Size(),
			ModTime:    info.ModTime(),
			Engine:     eng,
			LogType:    logType,
			Accessible: true,
		})
	}
	return found
}

func detectLogTypeFromPattern(name string) string {
	lower := strings.ToLower(name)
	switch {
	case strings.Contains(lower, "error") || strings.HasSuffix(lower, ".err"):
		return "error_log"
	case strings.Contains(lower, "slow"):
		return "slow_query_log"
	case strings.Contains(lower, "general"):
		return "general_log"
	case strings.Contains(lower, "binlog") || strings.Contains(lower, "relay-log"):
		return "binary_log"
	case strings.Contains(lower, "ib_logfile") || strings.Contains(lower, "ibdata"):
		return "innodb_log"
	case strings.Contains(lower, "redo") || strings.Contains(lower, "undo"):
		return "transaction_log"
	case strings.Contains(lower, "audit"):
		return "audit_log"
	case strings.Contains(lower, "wal") || strings.Contains(lower, "xlog"):
		return "wal_log"
	case strings.Contains(lower, "postgresql") || strings.Contains(lower, "pg_log") || strings.Contains(lower, "pg_stat"):
		return "pg_log"
	case strings.Contains(lower, "trace") || strings.HasSuffix(lower, ".trc"):
		return "trace_log"
	default:
		return "general_log"
	}
}

// ──────────────────────────────────────────────
// User Activity Tracking
// ──────────────────────────────────────────────

func (m *ActivityMonitor) recordUserActivity(user, host string, conn *DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()

	key := user + "@" + host
	now := time.Now()

	track, exists := m.activeUsers[key]
	if !exists {
		track = &UserTrack{
			User:          user,
			Host:          host,
			FirstSeen:     now,
			TablesTouched: make(map[string]int),
		}
		m.activeUsers[key] = track

		// New user event (dedup 30 min)
		eventKey := "new_user:" + key
		if last, ok := m.lastUserEvents[eventKey]; !ok || now.Sub(last) > 30*time.Minute {
			m.lastUserEvents[eventKey] = now
			logMsg("Activity Monitor: 👤 NUEVO USUARIO DB: '%s' desde %s en %s/%s",
				user, host, conn.Engine, conn.Database)
			wsSendEvent("Nuevo Usuario DB",
				fmt.Sprintf("Usuario '%s' conectado desde %s a %s/%s",
					user, host, conn.Engine, conn.Database),
				"db_activity", "low", false)
		}
	}
	track.LastSeen = now
	track.QueryCount++
}

// ──────────────────────────────────────────────
// Long Running Query Detection
// ──────────────────────────────────────────────

func (m *ActivityMonitor) detectLongRunning(query, user, host string, conn *DBConnection) {
	m.reportAnomaly(AnomalyReport{
		Type:        "long_running",
		Severity:    "medium",
		Description: fmt.Sprintf("⏱ Consulta de larga duración (>5s) por usuario '%s' en %s/%s",
			user, conn.Engine, conn.Database),
		Query:     query,
		User:      user,
		Host:      host,
		AutoBlock: false,
	})
}

// ──────────────────────────────────────────────
// Anomaly Reporting
// ──────────────────────────────────────────────

type AnomalyReport struct {
	Type        string
	Severity    string
	Description string
	Query       string
	User        string
	Host        string
	AutoBlock   bool
}

func (m *ActivityMonitor) reportAnomaly(r AnomalyReport) {
	alertKey := r.Type + ":" + r.User + ":" + simpleHash(r.Description)

	m.mu.Lock()
	lastAlert, exists := m.lastAlerts[alertKey]
	now := time.Now()

	var dedupInterval time.Duration
	switch r.Severity {
	case "critical":
		dedupInterval = alertDedupCritical
	case "high":
		dedupInterval = alertDedupHigh
	case "medium":
		dedupInterval = alertDedupMedium
	default:
		dedupInterval = alertDedupDefault
	}

	if exists && now.Sub(lastAlert) < dedupInterval {
		m.mu.Unlock()
		return
	}
	m.lastAlerts[alertKey] = now
	m.mu.Unlock()

	title := r.Type
	switch r.Type {
	case "dump_detected":
		title = "⚠️ Posible Dumpeo de Datos"
	case "data_export":
		title = "🚨 Exportación de Datos Detectada"
	case "sensitive_access":
		title = "🔍 Acceso a Datos Sensibles"
	case "personal_data_access":
		title = "📋 Consulta de Datos Personales"
	case "high_frequency":
		title = "📊 Alta Frecuencia de Consultas"
	case "schema_change":
		title = "⚠️ Cambio de Esquema"
	case "privilege_change":
		title = "🚨 Cambio de Privilegios"
	case "truncate":
		title = "🚨 TRUNCATE Detectado"
	case "dump_tool_detected":
		title = "🛠️ Herramienta de Dump Detectada"
	case "long_running":
		title = "⏱ Consulta de Larga Duración"
	case "slow_query":
		title = "🐢 Consulta Lenta"
	case "high_scan":
		title = "📡 Escaneo Masivo de Datos"
	case "unusual_hours":
		title = "🌙 Actividad en Horario Inusual"
	case "sequential_scan":
		title = "🔄 Escaneo Secuencial (Posible Dumpeo)"
	default:
		title = "Anomalía DB: " + r.Type
	}

	logMsg("ACTIVITY ALERT [%s/%s] %s", r.Severity, r.Type, r.Description)
	wsSendEvent(title, r.Description, "db_activity", r.Severity, r.AutoBlock)

	// Send query data to backend for AI analysis (server-side Ollama)
	if (r.Severity == "medium" || r.Severity == "high" || r.Severity == "critical") && r.Query != "" {
		wsSend(WSMessage{
			Type:     "analyze_query",
			AgentID:  GetAgentID(),
			Title:    title,
			Severity: r.Severity,
			Payload:  r.Query,
			Source:   r.User,
			Host:     r.Host,
		})
	}
}

// ──────────────────────────────────────────────
// Cleanup & Maintenance
// ──────────────────────────────────────────────

func (m *ActivityMonitor) cleanupStale() {
	m.mu.Lock()
	defer m.mu.Unlock()

	cutoff := time.Now().Add(-userStaleTimeout)
	for key, track := range m.activeUsers {
		if track.LastSeen.Before(cutoff) {
			delete(m.activeUsers, key)
		}
	}

	for key, lastAlert := range m.lastAlerts {
		if time.Since(lastAlert) > 30*time.Minute {
			delete(m.lastAlerts, key)
		}
	}

	for key, lastEvent := range m.lastUserEvents {
		if time.Since(lastEvent) > 60*time.Minute {
			delete(m.lastUserEvents, key)
		}
	}

	if len(m.activeQueries) > 10000 {
		m.activeQueries = make(map[string]*QueryTrack)
	}

	if len(m.queryHistory) > maxQueryHistory {
		m.queryHistory = m.queryHistory[len(m.queryHistory)/2:]
	}

	// Clean detected dumps older than 1 hour
	var active []DumpDetection
	for _, d := range m.detectedDumps {
		if time.Since(d.DetectedAt) < 1*time.Hour {
			active = append(active, d)
		}
	}
	m.detectedDumps = active
}

// ──────────────────────────────────────────────
// Activity Summary
// ──────────────────────────────────────────────

func (m *ActivityMonitor) sendActivitySummary() {
	m.mu.RLock()
	totalQueries := int64(0)
	for _, qt := range m.activeQueries {
		totalQueries += qt.Count
	}

	var topUsers []UserStat
	for _, track := range m.activeUsers {
		topUsers = append(topUsers, UserStat{
			User:       track.User,
			Queries:    track.QueryCount,
			Tables:     len(track.TablesTouched),
			Anomalies:  track.Anomalies,
			LastActive: track.LastSeen.Format(time.RFC3339),
		})
	}
	sort.Slice(topUsers, func(i, j int) bool {
		return topUsers[i].Queries > topUsers[j].Queries
	})
	if len(topUsers) > 10 {
		topUsers = topUsers[:10]
	}

	var topTables []TableStat
	for _, stats := range m.tableAccess {
		topTables = append(topTables, TableStat{
			Name:       stats.Name,
			Accesses:   stats.ReadCount + stats.WriteCount,
			Users:      len(stats.Users),
			Sensitive:  stats.IsSensitive,
			LastAccess: stats.LastAccess.Format(time.RFC3339),
		})
	}
	sort.Slice(topTables, func(i, j int) bool {
		return topTables[i].Accesses > topTables[j].Accesses
	})
	if len(topTables) > 10 {
		topTables = topTables[:10]
	}

	var recentAlerts []string
	for key, t := range m.lastAlerts {
		if time.Since(t) < 5*time.Minute {
			recentAlerts = append(recentAlerts, key)
		}
	}

	dumpCount := len(m.detectedDumps)
	logCount := len(m.discoveredLogs)
	m.mu.RUnlock()

	summary := ActivitySummary{
		Uptime:         time.Since(m.startTime).Round(time.Second).String(),
		TotalQueries:   totalQueries,
		ActiveUsers:    len(topUsers),
		TablesTracked:  len(topTables),
		AnomaliesFound: len(m.lastAlerts),
		DumpsDetected:  dumpCount,
		LogsDiscovered: logCount,
		Connections:    len(m.connectionRecords),
		TopUsers:       topUsers,
		TopTables:      topTables,
		RecentAlerts:   recentAlerts,
	}

	data, _ := json.Marshal(summary)
	logMsg("Activity Summary: %s", string(data))
}

// ──────────────────────────────────────────────
// Query Log Flush (compliance logging — Ley 21.719)
// ──────────────────────────────────────────────

func (m *ActivityMonitor) queueQueryLog(database, user, host, query, engine, operation string, tables []string) {
	entry := QueryLogEntry{
		Timestamp: time.Now(),
		User:      user,
		Host:      host,
		Database:  database,
		Query:     query,
		Engine:    engine,
		Operation: operation,
		Tables:    tables,
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	m.pendingQueryLogs = append(m.pendingQueryLogs, entry)
	if len(m.pendingQueryLogs) > 500 {
		m.pendingQueryLogs = m.pendingQueryLogs[len(m.pendingQueryLogs)-500:]
	}

	// Persist to SQLite audit store
	go GetAuditStore().StoreQueryLog(entry)
}

func (m *ActivityMonitor) addQueryLog(entry QueryLogEntry) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.pendingQueryLogs = append(m.pendingQueryLogs, entry)
	if len(m.pendingQueryLogs) > maxQueryHistory {
		cutoff := len(m.pendingQueryLogs) - maxQueryHistory
		m.pendingQueryLogs = m.pendingQueryLogs[cutoff:]
	}
}

func (m *ActivityMonitor) flushQueryLogs() {
	m.mu.Lock()
	logs := m.pendingQueryLogs
	m.pendingQueryLogs = nil
	m.mu.Unlock()

	if len(logs) == 0 {
		return
	}

	logMsg("MySQL: flushing %d query logs via WebSocket", len(logs))
	wsSendQueryLogs(logs)
}

// ──────────────────────────────────────────────
// Public API
// ──────────────────────────────────────────────

func (m *ActivityMonitor) GetActiveUsers() []string {
	m.mu.RLock()
	defer m.mu.RUnlock()
	users := make([]string, 0, len(m.activeUsers))
	for key := range m.activeUsers {
		users = append(users, key)
	}
	return users
}

func (m *ActivityMonitor) GetDiscoveredLogs() []DBLogFileInfo {
	m.mu.RLock()
	defer m.mu.RUnlock()
	result := make([]DBLogFileInfo, len(m.discoveredLogs))
	copy(result, m.discoveredLogs)
	return result
}

func (m *ActivityMonitor) GetTableAccessStats() []TableStat {
	m.mu.RLock()
	defer m.mu.RUnlock()
	var stats []TableStat
	for _, t := range m.tableAccess {
		stats = append(stats, TableStat{
			Name:      t.Name,
			Accesses:  t.ReadCount + t.WriteCount,
			Users:     len(t.Users),
			Sensitive: t.IsSensitive,
		})
	}
	return stats
}

// ──────────────────────────────────────────────
// Utility Functions
// ──────────────────────────────────────────────

func normalizeQuery(query string) string {
	// Remove string literals
	re := regexp.MustCompile(`'[^']*'`)
	result := re.ReplaceAllString(query, "'?'")
	re = regexp.MustCompile(`"[^"]*"`)
	result = re.ReplaceAllString(result, "\"?\"")
	// Remove numbers
	re = regexp.MustCompile(`\b\d+\b`)
	result = re.ReplaceAllString(result, "?")
	// Collapse whitespace
	re = regexp.MustCompile(`\s+`)
	result = strings.TrimSpace(re.ReplaceAllString(result, " "))
	return result
}

func classifyQuery(lower string) QueryClass {
	lower = strings.TrimSpace(lower)
	switch {
	case strings.HasPrefix(lower, "select"):
		if strings.Contains(lower, "into outfile") || strings.Contains(lower, "into dumpfile") {
			return QueryExport
		}
		if strings.Contains(lower, "select * into") {
			return QueryExport
		}
		return QuerySelect
	case strings.HasPrefix(lower, "insert"):
		return QueryInsert
	case strings.HasPrefix(lower, "update"):
		return QueryUpdate
	case strings.HasPrefix(lower, "delete") || strings.HasPrefix(lower, "drop"):
		if strings.HasPrefix(lower, "drop") {
			return QueryDDLDrop
		}
		return QueryDelete
	case strings.HasPrefix(lower, "alter"):
		return QueryDDLAlter
	case strings.HasPrefix(lower, "create"):
		return QueryDDLCreate
	case strings.HasPrefix(lower, "truncate"):
		return QueryDMLTruncate
	case strings.HasPrefix(lower, "grant"):
		return QueryDCLGrant
	case strings.HasPrefix(lower, "revoke"):
		return QueryDCLRevoke
	case strings.HasPrefix(lower, "show"):
		return QueryShow
	case strings.HasPrefix(lower, "set"):
		return QuerySet
	case strings.HasPrefix(lower, "begin") || strings.HasPrefix(lower, "commit") ||
		strings.HasPrefix(lower, "rollback") || strings.HasPrefix(lower, "start transaction"):
		return QueryTransaction
	case strings.HasPrefix(lower, "copy") || strings.HasPrefix(lower, `\copy`):
		return QueryExport
	case strings.HasPrefix(lower, "backup") || strings.HasPrefix(lower, "restore"):
		return QueryUtility
	default:
		return QueryUnknown
	}
}

func extractTableNames(query string) []string {
	var tables []string
	seen := make(map[string]bool)
	lower := strings.ToLower(query)

	// FROM clause
	fromIdx := strings.Index(lower, "from")
	if fromIdx >= 0 {
		afterFrom := query[fromIdx+4:]
		for _, part := range strings.Fields(afterFrom) {
			part = strings.Trim(part, "`,;() \t\n")
			if part == "" || strings.HasPrefix(part, "--") {
				continue
			}
			if strings.HasPrefix(part, "information_schema") ||
				strings.HasPrefix(part, "performance_schema") ||
				strings.HasPrefix(part, "mysql.") ||
				strings.HasPrefix(part, "pg_") ||
				strings.HasPrefix(part, "sys.") {
				continue
			}
			keywords := []string{"where", "join", "inner", "outer", "left", "right",
				"on", "and", "or", "not", "in", "as", "order", "group", "having",
				"limit", "offset", "union", "all", "distinct", "select", "set",
				"values", "insert", "into", "update", "delete", "create", "alter",
				"drop", "grant", "revoke", "truncate", "table", "index", "view",
				"true", "false", "null", "is", "like", "between", "exists",
				"count", "sum", "avg", "min", "max", "coalesce", "ifnull", "nullif",
				"substring", "cast", "convert", "date", "time", "timestamp",
				"using", "for", "with", "check", "option", "cascade", "restrict",
				"=", "!=", "<>", ">", "<", ">=", "<=", ",", "(", ")", ";"}
			skip := false
			for _, kw := range keywords {
				if strings.EqualFold(part, kw) {
					skip = true
					break
				}
			}
			if !skip && !seen[part] && len(part) > 0 {
				tables = append(tables, part)
				seen[part] = true
			}
		}
	}

	// JOIN clause
	joinIdx := strings.Index(lower, "join")
	if joinIdx >= 0 {
		afterJoin := query[joinIdx+4:]
		for _, part := range strings.Fields(afterJoin) {
			part = strings.Trim(part, "`,;() \t\n")
			if part == "" {
				continue
			}
			joinKeywords := []string{"on", "using", "inner", "outer", "left", "right",
				"cross", "natural", "as", "where", "and", "or", "order", "group",
				"having", "limit", "offset", "select", "insert", "update", "delete"}
			skip := false
			for _, kw := range joinKeywords {
				if strings.EqualFold(part, kw) {
					skip = true
					break
				}
			}
			if !skip && !seen[part] && len(part) > 0 {
				tables = append(tables, part)
				seen[part] = true
			}
		}
	}

	// UPDATE / DELETE / INSERT / INTO clause
	if strings.HasPrefix(lower, "update") {
		parts := strings.Fields(query)
		if len(parts) > 1 {
			t := strings.Trim(parts[1], "`,;")
			if !seen[t] {
				tables = append(tables, t)
				seen[t] = true
			}
		}
	}
	if strings.HasPrefix(lower, "insert into") || strings.HasPrefix(lower, "insert ignore into") {
		parts := strings.Fields(query)
		for _, p := range parts {
			p = strings.Trim(p, "`,;(")
			insertKeywords := []string{"insert", "ignore", "into", "values", "set", "select"}
			skip := false
			for _, kw := range insertKeywords {
				if strings.EqualFold(p, kw) {
					skip = true
					break
				}
			}
			if !skip && !seen[p] && len(p) > 0 {
				tables = append(tables, p)
				seen[p] = true
				break
			}
		}
	}
	if strings.HasPrefix(lower, "delete from") || strings.HasPrefix(lower, "delete ") {
		if strings.Contains(lower, "from") {
			fromIdx := strings.Index(lower, "from")
			afterFrom := query[fromIdx+4:]
			for _, part := range strings.Fields(afterFrom) {
				part = strings.Trim(part, "`,;")
				if part != "" && !seen[part] {
					deleteKeywords := []string{"where", "using", "join", "and", "or",
						"in", "order", "limit", "offset", ";"}
					skip := false
					for _, kw := range deleteKeywords {
						if strings.EqualFold(part, kw) {
							skip = true
							break
						}
					}
					if !skip {
						tables = append(tables, part)
						seen[part] = true
						break
					}
				}
			}
		}
	}

	return tables
}

func detectPersonalDataAdvanced(columnName string) (bool, string) {
	// Normalize underscores to spaces so \b word boundaries work correctly
	// e.g. "direccion_ip" → "direccion ip" → \bip\b matches
	normalized := strings.ReplaceAll(columnName, "_", " ")
	for category, regexes := range extendedPersonalRegex {
		for _, re := range regexes {
			if re.MatchString(normalized) {
				return true, category
			}
		}
	}
	return false, ""
}

func isInternalUserAdvanced(user string) bool {
	internal := []string{
		"admin", "administrator", "system", "SYSTEM",
		"mysql", "mariadb.sys", "postgres", "sa",
		"rdsadmin", "cloudsql", "event_scheduler",
		"c##csvmig", "dbsnmp", "outln", "system",
		"dbadmin", "ops$oracle",
	}
	for _, i := range internal {
		if strings.EqualFold(user, i) {
			return true
		}
	}
	return false
}

func isInternalQueryAdvanced(query string) bool {
	lower := strings.TrimSpace(strings.ToLower(query))
	internal := []string{
		"select @@version", "select @@",
		"kill ", "show variables", "show status",
		"show processlist", "show slave", "show master",
		"show databases", "show schemas",
		"set autocommit", "commit", "rollback", "begin",
		"select 1", "select current", "select current_schema",
		"select session_user", "select current_user",
		"select current_database",
		"discard all", "listen", "unlisten",
		"select pg_", "select version",
		"show server_version", "show data_directory",
		"show log_directory", "show config_file",
		"show hba_file", "show ident_file",
		"select pg_stat_get_activity",
		"select * from pg_stat_activity",
		"select pid, usename",
		"show binary logs", "show master logs",
		"show table status", "show full processlist",
		"select table_name from information_schema.tables",
		"select column_name from information_schema.columns",
		"show warnings", "show errors", "show engines",
		"show plugins", "show privileges", "show grants",
		"show character set", "show collation",
		"select digest_text, count_star",
	}
	for _, i := range internal {
		if strings.HasPrefix(lower, i) {
			return true
		}
	}
	return false
}

func simpleHash(s string) string {
	h := 0
	for _, c := range s {
		h = h*31 + int(c)
	}
	if h < 0 {
		h = -h
	}
	return fmt.Sprintf("%x", h)
}

// ──────────────────────────────────────────────
// Multi-DB Auto-Discovery + AI Analysis Integration
// ──────────────────────────────────────────────

func (m *ActivityMonitor) autoDiscoverAndConnectAllDBs() {
	defer func() {
		if r := recover(); r != nil {
			logMsg("DB Engine: recovered from panic in auto-discovery: %v", r)
		}
	}()

	logMsg("DB Engine: starting auto-discovery of all local databases...")
	engine := GetDBEngine()

	instances := engine.RunFullDiscovery()
	logMsg("DB Engine: discovered %d database instances", len(instances))

	for _, inst := range instances {
		logMsg("DB Engine: [%s] %s:%d — %s (source: %s)", inst.Engine, inst.Host, inst.Port, inst.Status, inst.Source)
	}

	connected := engine.AutoConnectAll()
	logMsg("DB Engine: auto-connected to %d databases", len(connected))

	if len(connected) > 0 {
		for _, key := range connected {
			logMsg("DB Engine: connected → %s", key)
		}
	}

	// Auto-populate Activity Monitor connections from DBEngine
	allConns := engine.GetAllConnections()
	m.mu.Lock()
	autoCount := 0
	for key, mdb := range allConns {
		if !mdb.Connected {
			continue
		}
		if _, exists := m.connections[key]; !exists {
			conn := &DBConnection{
				Engine:   mdb.Instance.Engine,
				Host:     mdb.Instance.Host,
				Port:     mdb.Instance.Port,
				Database: "",
				Username: "root",
				Password: "",
			}
			if mdb.Instance.Engine == "mssql" {
				conn.Username = "sa"
			}
			m.connections[key] = conn
			autoCount++
			logMsg("Activity Monitor: auto-registered %s for audit", key)
		}
	}
	m.mu.Unlock()
	if autoCount > 0 {
		logMsg("Activity Monitor: %d auto-registered connections ready for audit", autoCount)
	}
}

func (m *ActivityMonitor) periodicDBHealthCheck() {
	defer func() {
		if r := recover(); r != nil {
			logMsg("DB Engine: recovered from panic in health check: %v", r)
		}
	}()

	engine := GetDBEngine()
	status := engine.RunHealthChecks()

	disconnected := 0
	for key, ok := range status {
		if !ok {
			disconnected++
			logMsg("DB Engine: DISCONNECTED — %s", key)
		}
	}

	if disconnected > 0 {
		logMsg("DB Engine: health check — %d/%d databases disconnected", disconnected, len(status))
	}
}

func (m *ActivityMonitor) runDeepAIAnalysis() {
	defer func() {
		if r := recover(); r != nil {
			logMsg("AI Analyzer: recovered from panic in deep analysis: %v", r)
		}
	}()

	analyzer := GetAIAnalyzer()
	engine := GetDBEngine()
	totalFindings := 0
	totalRisk := 0.0

	// ── MySQL/MariaDB Analysis ──
	perfEntries := engine.GetPerformanceSchemaQueries(100)
	if len(perfEntries) > 0 {
		result := analyzer.AnalyzePerformanceSchema(perfEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			logMsg("AI Analyzer [Deep] [MySQL] %d hallazgos de rendimiento (riesgo: %.1f)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					logMsg("AI Analyzer [Deep] [%s] %s", strings.ToUpper(f.Severity), f.Title)
					wsSendEvent(f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	generalEntries := engine.GetMySQLGeneralLogEntries(500)
	if len(generalEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(generalEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					logMsg("AI Analyzer [Deep] [MySQL] [%s] %s", strings.ToUpper(f.Severity), f.Title)
					wsSendEvent(f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	slowEntries := engine.GetMySQLSlowQueryLogEntries(200)
	if len(slowEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(slowEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent(f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	// ── PostgreSQL Analysis ──
	pgEntries := engine.GetPostgreSQLActivityLog(300)
	if len(pgEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(pgEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			logMsg("AI Analyzer [Deep] [PostgreSQL] %d hallazgos (riesgo: %.1f)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					logMsg("AI Analyzer [Deep] [PG] [%s] %s", strings.ToUpper(f.Severity), f.Title)
					wsSendEvent("[PG] "+f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	pgSlow := engine.GetPGSlowQueries(100)
	if len(pgSlow) > 0 {
		result := analyzer.AnalyzeLogBatch(pgSlow)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[PG Slow] "+f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	// ── MSSQL Analysis ──
	mssqlEntries := engine.GetMSSQLQueryStats(200)
	if len(mssqlEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(mssqlEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			logMsg("AI Analyzer [Deep] [MSSQL] %d hallazgos (riesgo: %.1f)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					logMsg("AI Analyzer [Deep] [MSSQL] [%s] %s", strings.ToUpper(f.Severity), f.Title)
					wsSendEvent("[MSSQL] "+f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	mssqlSessions := engine.GetMSSQLActiveSessions()
	if len(mssqlSessions) > 0 {
		result := analyzer.AnalyzeLogBatch(mssqlSessions)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
		}
	}

	// ── Redis Analysis ──
	redisEntries := engine.GetRedisSlowLogEntries(100)
	if len(redisEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(redisEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			logMsg("AI Analyzer [Deep] [Redis] %d hallazgos (riesgo: %.1f)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[Redis] "+f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	// ── MongoDB Analysis ──
	mongoEntries := engine.GetMongoDBRecentOps(200)
	if len(mongoEntries) > 0 {
		result := analyzer.AnalyzeLogBatch(mongoEntries)
		if result != nil && len(result.Findings) > 0 {
			totalFindings += len(result.Findings)
			totalRisk += result.RiskScore
			logMsg("AI Analyzer [Deep] [MongoDB] %d hallazgos (riesgo: %.1f)", len(result.Findings), result.RiskScore)
			for _, f := range result.Findings {
				if f.Severity == "critical" || f.Severity == "high" {
					wsSendEvent("[MongoDB] "+f.Title, f.Description, "ai_analyzer_deep", f.Severity, false)
				}
			}
		}
	}

	// ── Summary across all engines ──
	if totalFindings > 0 {
		avgRisk := totalRisk / float64(totalFindings)
		summary := fmt.Sprintf("Análisis IA completo: %d hallazgos en todos los motores. Riesgo promedio: %.1f/100", totalFindings, avgRisk)
		logMsg("AI Analyzer [Deep]: %s", summary)
		wsSendEvent("Reporte IA Multi-Engine", summary, "ai_analyzer_deep", "medium", false)
	} else {
		logMsg("AI Analyzer [Deep]: análisis completo — sin hallazgos críticos en ningún motor")
	}
}
