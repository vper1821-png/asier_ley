package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
	"strings"
	"time"

	_ "github.com/denisenkom/go-mssqldb"
	"github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
	_ "modernc.org/sqlite"
)

func init() {
	mysql.RegisterDialContext("tunnel", func(ctx context.Context, addr string) (net.Conn, error) {
		host, port, err := net.SplitHostPort(addr)
		if err != nil {
			return nil, err
		}
		portNum := 3306
		fmt.Sscanf(port, "%d", &portNum)
		return dialTunnel(host, portNum)
	})
	personalDataRegex = make(map[string][]*regexp.Regexp, len(personalDataPatterns))
	for cat, patterns := range personalDataPatterns {
		compiled := make([]*regexp.Regexp, 0, len(patterns))
		for _, p := range patterns {
			re := regexp.MustCompile(`(?i)\b` + regexp.QuoteMeta(p) + `\b`)
			compiled = append(compiled, re)
		}
		personalDataRegex[cat] = compiled
	}
}

type DBConnection struct {
	Engine   string `json:"engine"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Database string `json:"database"`
	Username string `json:"username"`
	Password string `json:"password"`
	SSL      bool   `json:"ssl"`
}

type TableInfo struct {
	Name      string       `json:"name"`
	Columns   []ColumnInfo `json:"columns"`
	RowCount  int64        `json:"rowCount"`
	Encrypted bool         `json:"encrypted"`
}

type ColumnInfo struct {
	Name     string `json:"name"`
	Type     string `json:"type"`
	Nullable bool   `json:"nullable"`
	IsPK     bool   `json:"isPK"`
	IsPersonal bool `json:"isPersonal"`
	Category string `json:"category"`
}

type ScanResult struct {
	Engine      string      `json:"engine"`
	Host        string      `json:"host"`
	Database    string      `json:"database"`
	Tables      []TableInfo `json:"tables"`
	TotalTables int         `json:"totalTables"`
	TotalRows   int64       `json:"totalRows"`
	SizeBytes   int64       `json:"sizeBytes"`
	PersonalDataColumns int `json:"personalDataColumns"`
	SensitiveDataColumns int `json:"sensitiveDataColumns"`
	EncryptedTables int    `json:"encryptedTables"`
	UnencryptedTables int  `json:"unencryptedTables"`
	ScannedAt   string      `json:"scannedAt"`
	Error       string      `json:"error,omitempty"`
}

var personalDataPatterns = map[string][]string{
	"nombre":                 {"nombre", "name", "first_name", "last_name", "apellido", "full_name", "nombres", "nombre_completo", "legal_name", "display_name", "razon_social", "business_name", "nombre_legal", "alias", "apodo", "nickname"},
	"email":                  {"email", "e-mail", "mail", "correo", "email_address", "email_addr", "mail_addr", "correo_electronico", "correo_personal", "correo_laboral", "email_principal"},
	"rut":                    {"rut", "run", "dni", "cedula", "documento", "id_number", "national_id", "identificacion", "passport", "pasaporte", "num_documento", "nro_documento", "id_documento", "dv", "digito_verificador", "rol_unico_tributario", "rol_unico_nacional", "carnet_identidad", "documento_identidad", "identificador_unico", "id_card", "licencia_conducir", "driver_license", "documento_extranjeria"},
	"telefono":               {"telefono", "phone", "mobile", "celular", "phone_number", "contact", "movil", "whatsapp", "numero_contacto", "telefono_contacto", "contacto_emergencia", "nro_telefono", "telefono_celular", "tel_celular", "telefono_fijo", "emergency_phone", "mobile_phone"},
	"direccion":              {"direccion", "address", "domicilio", "street", "calle", "location", "comuna", "municipio", "provincia", "region", "ciudad", "localidad", "villa", "poblacion", "sector", "barrio", "distrito", "numero_casa", "nro_casa", "depto", "piso", "torre", "direccion_particular", "direccion_laboral", "direccion_facturacion", "domicilio_particular", "codigo_postal", "postal_code", "zip_code"},
	"fecha_nac":              {"fecha_nacimiento", "birth_date", "dob", "date_of_birth", "nacimiento", "fecha_nac", "ano_nacimiento", "birth_year", "birthday", "fecha_de_nacimiento"},
	"salud":                  {"salud", "health", "medical", "diagnostico", "enfermedad", "seguro_medico", "discapacidad", "historial_medico", "clinical", "paciente", "patient", "alergia", "allergy", "tipo_sangre", "blood", "sangre", "isapre", "fonasa", "prevision_salud", "prevision", "licencia_medica", "receta", "prescription", "hospital", "clinica", "diagnosis", "tratamiento", "tratamiento", "medicamento", "medicine", "vacuna", "vaccine", "grupo_sangre", "factor_rh", "enfermedad_previa", "condicion_medica"},
	"biometrico":             {"biometrico", "biometric", "fingerprint", "huella", "iris", "face_id", "firma_electronica", "dna", "genetic", "huella_dactilar", "reconocimiento_facial", "retina", "voz", "voice", "firma_autografa"},
	"bancario":               {"cuenta_bancaria", "bank_account", "credit_card", "tarjeta", "cvv", "iban", "account_number", "banco", "bank", "afp", "pension", "credito", "credit", "debito", "debit", "ahorros", "cuenta_corriente", "cheque", "hipoteca", "prestamo", "loan", "inversion", "investment", "saldo_bancario", "balance", "tarjeta_credito", "tarjeta_debito", "numero_cuenta", "clabe", "routing_number", "swift_code", "bic", "crypto", "cryptocurrency", "wallet_address"},
	"credencial":             {"password", "contraseña", "hash", "secret", "token", "auth_key", "api_key", "secret_key", "pwd", "pass", "clave_secreta", "access_token", "refresh_token", "jwt", "bearer", "authorization", "csrf", "otp", "2fa", "pin", "login", "credential", "private_key", "public_key", "firma_digital", "certificado", "certificate", "ssh_key"},
	"ip":                     {"ip_address", "ip", "direccion_ip", "client_ip", "remote_addr", "ip_addr", "ipv4", "ipv6", "mac_address", "mac", "ip_origen", "source_ip", "dest_ip"},
	"ubicacion":              {"ubicacion", "location", "gps", "latitud", "longitud", "coordinates", "coordenada", "altitud", "zona_horaria", "timezone", "geolocation", "position"},
	"genero":                 {"genero", "gender", "sexo", "sex", "orientacion", "identidad_genero", "sexual", "lgbt", "transgenero", "transexual"},
	"edad":                   {"edad", "age", "birth_year", "rango_edad", "tramo_etario", "years_old"},
	"religion":               {"religion", "religión", "credo", "faith", "catolico", "evangelico", "judio", "musulman", "ateo", "agnostico", "creencia", "culto"},
	"politico":               {"politico", "political", "partido", "voto", "vote", "militancia", "afiliacion_politica", "ideologia", "candidato", "eleccion"},
	"sindical":               {"sindical", "union", "sindicato", "gremio", "asociacion", "federacion", "confederacion", "negociacion_colectiva", "huelga"},
	"judicial":               {"judicial", "criminal", "delito", "antecedentes", "penal", "sentencia", "demanda", "denuncia", "condena", "causa", "tribunal", "corte", "fallo", "querella", "arresto", "detencion", "prision", "carcel", "juzgado", "causa_rol", "ruc", "audiencia", "formalizacion", "violencia_intrafamiliar", "vif", "alimentos", "filiacion", "adopcion"},
	"educacion":              {"educacion", "education", "school", "colegio", "universidad", "titulo", "grado_academico", "profesion", "carrera", "alumno", "student", "matricula", "nota", "calificacion", "promedio", "establecimiento", "nivel_educacional", "educacion_basica", "educacion_media", "educacion_superior", "postgrado", "magister", "doctorado", "diplomado", "beca"},
	"laboral":                {"laboral", "employment", "job", "trabajo", "salary", "salario", "sueldo", "renta", "ingreso", "cargo", "contrato", "empleador", "employer", "remuneracion", "honorario", "liquidacion", "finiquito", "indemnizacion", "aguinaldo", "comision", "gratificacion", "colacion", "movilizacion", "viatico", "horas_extras", "turno", "horario_laboral", "jornada", "asistencia", "permiso_laboral", "licencia", "vacaciones", "evaluacion_desempeno"},
	"conyuge":                {"conyuge", "spouse", "estado_civil", "marital", "casado", "divorcio", "pareja", "separacion", "viudo", "soltero", "union_civil", "conviviente", "pareja_conviviente"},
	"hijos":                  {"hijos", "children", "familia", "family", "carga_familiar", "dependents", "hijo_menor", "hijo_discapacitado", "padre", "madre", "padres", "hermano", "hermana", "tutor", "guardian", "abuelo", "abuela", "nieto"},
	"foto":                   {"foto", "photo", "picture", "image", "avatar", "fotografia", "imagen", "retrato", "foto_carnet", "selfie", "profile_pic", "foto_perfil", "foto_identificacion", "foto_rostro"},
	"nacionalidad":           {"nacionalidad", "nationality", "pais", "lugar_nacimiento", "ciudadania", "residencia", "pais_origen", "pais_nacimiento", "country_of_birth", "visa", "extranjeria", "inmigrante", "migrante", "pueblo_originario", "indigena", "etnia", "raza", "ascendencia", "origen_etnico"},
	"seguro":                 {"seguro", "insurance", "poliza", "aseguradora", "cobertura", "beneficiario", "siniestro", "seguro_vida", "seguro_salud", "seguro_auto", "numero_poliza", "prima_seguro", "deducible", "asegurado"},
	"vehiculo":               {"vehiculo", "vehicle", "car", "auto", "patente", "license_plate", "placa", "chasis", "motor", "modelo_vehiculo", "marca_vehiculo", "ano_vehiculo", "color_vehiculo", "automovil", "camioneta", "moto", "vin", "numero_serie", "permiso_circulacion", "revision_tecnica", "soap"},
	"patrimonio":             {"bienes", "property", "propiedad", "inmueble", "real_estate", "herencia", "sucesion", "patrimonio", "assets", "testamento", "inventario_bienes", "avalúo", "tasacion"},
	"financiero":             {"ingresos", "income", "egresos", "gastos", "budget", "presupuesto", "impuesto", "tax", "declaracion_renta", "iva", "factura", "boleta", "comprobante", "pago", "cobro", "contabilidad", "balance", "estado_resultado", "flujo_efectivo", "orden_compra", "gasto_operacional", "ingreso_anual", "sii", "tesoreria"},
	"digital":                {"user_agent", "browser", "navegador", "cookie", "session_id", "device_id", "dispositivo", "imei", "serial_number", "udid", "advertising_id", "idfa", "modelo_dispositivo", "os_version", "resolucion_pantalla", "tipo_conexion", "isp", "proveedor_internet", "operador_telefonia", "carrier", "wifi_ssid", "analytics_id"},
	"comunicacion":           {"correspondencia", "carta", "letter", "mensaje", "message", "sms", "chat", "conversacion", "llamada", "call", "call_log", "registro_llamada", "historial_chat", "comunicacion_cliente"},
	"caracteristicas_fisicas": {"estatura", "height", "altura", "peso", "weight", "talla", "complexion", "color_pelo", "hair_color", "color_ojos", "eye_color", "color_piel", "senas_particulares", "tatuajes", "cicatrices", "contextura", "indice_masa_corporal", "bmi", "imc", "calzado", "shoe_size", "talla_ropa", "talla_camisa"},
}

var sensitiveCategories = map[string]bool{
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
}

var personalDataRegex map[string][]*regexp.Regexp

func detectPersonalData(columnName string) (bool, string) {
	normalized := strings.ReplaceAll(columnName, "_", " ")
	for category, regexes := range personalDataRegex {
		for _, re := range regexes {
			if re.MatchString(normalized) {
				return true, category
			}
		}
	}
	return false, ""
}

func ScanDatabase(conn DBConnection) (*ScanResult, error) {
	result := &ScanResult{
		Engine:    conn.Engine,
		Host:      conn.Host,
		Database:  conn.Database,
		ScannedAt: time.Now().UTC().Format(time.RFC3339),
	}

	var db *sql.DB
	var err error

	switch conn.Engine {
	case "mongodb":
		return scanMongoDB(conn)
	default:
		db, err = openDBWithFallback(conn)
	}

	if err != nil {
		result.Error = fmt.Sprintf("all connection strategies failed: %v", err)
		return result, nil
	}
	defer db.Close()

	if err = db.Ping(); err != nil {
		result.Error = fmt.Sprintf("ping failed: %v", err)
		return result, nil
	}

	// Get database size
	result.SizeBytes = getDatabaseSize(db, conn.Engine, conn.Database)

	tables, err := getTables(db, conn.Engine)
	if err != nil {
		result.Error = fmt.Sprintf("listing tables failed: %v", err)
		return result, nil
	}

	// Get fast row count estimates
	rowCounts := getRowCounts(db, conn.Engine, tables)

	for _, table := range tables {
		ti, err := inspectTable(db, conn.Engine, table)
		if err != nil {
			continue
		}
		// Use estimated row count for display, exact count for reference
		if rc, ok := rowCounts[table]; ok && rc > 0 {
			ti.RowCount = rc
		}
		result.Tables = append(result.Tables, *ti)
		result.TotalRows += ti.RowCount
		for _, col := range ti.Columns {
			if col.IsPersonal {
				result.PersonalDataColumns++
				if sensitiveCategories[col.Category] {
					result.SensitiveDataColumns++
				}
			}
		}
	}
	result.TotalTables = len(result.Tables)

	for _, t := range result.Tables {
		if t.Encrypted {
			result.EncryptedTables++
		} else {
			result.UnencryptedTables++
		}
	}

	report := fmt.Sprintf("Scan complete: %d tables, %d rows (%d bytes), %d personal data columns (%d sensitive), %d encrypted / %d unencrypted",
		result.TotalTables, result.TotalRows, result.SizeBytes, result.PersonalDataColumns, result.SensitiveDataColumns,
		result.EncryptedTables, result.UnencryptedTables)
	logMsg(report)

	return result, nil
}

func getRowCounts(db *sql.DB, engine string, tables []string) map[string]int64 {
	counts := make(map[string]int64)
	switch engine {
	case "mysql", "mariadb":
		rows, err := db.Query("SHOW TABLE STATUS")
		if err != nil {
			logMsg("getRowCounts error: %v", err)
			return counts
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
			var rowsCount int64
			for i, col := range cols {
				switch col {
				case "Name":
					if v, ok := vals[i].([]byte); ok {
						name = string(v)
					} else if v, ok := vals[i].(string); ok {
						name = v
					}
				case "Rows":
					if v, ok := vals[i].(int64); ok {
						rowsCount = v
					}
				}
			}
			if name != "" {
				counts[name] = rowsCount
			}
		}
	case "postgresql":
		for _, t := range tables {
			var rc int64
			err := db.QueryRow(
				fmt.Sprintf("SELECT reltuples::int8 FROM pg_class WHERE relname = '%s'", t),
			).Scan(&rc)
			if err == nil && rc > 0 {
				counts[t] = rc
			}
		}
	case "mssql":
		for _, t := range tables {
			var rc int64
			err := db.QueryRow(
				fmt.Sprintf("SELECT SUM(p.rows) FROM sys.partitions p WHERE p.object_id = OBJECT_ID('%s') AND p.index_id < 2", t),
			).Scan(&rc)
			if err == nil && rc > 0 {
				counts[t] = rc
			}
		}
	case "sqlite":
		for _, t := range tables {
			var rc int64
			err := db.QueryRow(fmt.Sprintf("SELECT COUNT(*) FROM \"%s\"", t)).Scan(&rc)
			if err == nil {
				counts[t] = rc
			}
		}
	}
	return counts
}

func getDatabaseSize(db *sql.DB, engine, dbName string) int64 {
	switch engine {
	case "mysql", "mariadb":
		var size int64
		err := db.QueryRow(
			"SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?",
			dbName,
		).Scan(&size)
		if err != nil {
			logMsg("getDatabaseSize error: %v", err)
			return 0
		}
		return size
	case "postgresql":
		var size int64
		err := db.QueryRow(
			fmt.Sprintf("SELECT COALESCE(pg_database_size('%s'), 0)", dbName),
		).Scan(&size)
		if err != nil {
			logMsg("getDatabaseSize error: %v", err)
			return 0
		}
		return size
	case "mssql":
		var size int64
		err := db.QueryRow(
			"SELECT COALESCE(SUM(size * 8 * 1024), 0) FROM sys.database_files",
		).Scan(&size)
		if err != nil {
			logMsg("getDatabaseSize error: %v", err)
			return 0
		}
		return size
	case "sqlite":
		// SQLite: use page_count * page_size
		var pageCount, pageSize int64
		err := db.QueryRow("SELECT page_count FROM pragma_page_count").Scan(&pageCount)
		if err != nil {
			logMsg("sqlite page_count error: %v", err)
			return 0
		}
		err = db.QueryRow("SELECT page_size FROM pragma_page_size").Scan(&pageSize)
		if err != nil {
			logMsg("sqlite page_size error: %v", err)
			return 0
		}
		return pageCount * pageSize
	}
	return 0
}

func openDB(conn DBConnection) (*sql.DB, error) {
	return openDBWithStrategy(conn, "direct")
}

func openDBWithStrategy(conn DBConnection, strategy string) (*sql.DB, error) {
	var db *sql.DB
	var err error
	var driverName string

	switch conn.Engine {
	case "postgresql":
		driverName = "postgres"
		sslmode := "disable"
		if conn.SSL {
			sslmode = "require"
		}
		connStr := fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=%s",
			conn.Host, conn.Port, conn.Username, conn.Password, conn.Database, sslmode)
		db, err = sql.Open(driverName, connStr)
	case "mysql", "mariadb":
		driverName = "mysql"
		tlsSetting := "false"
		if conn.SSL {
			tlsSetting = "true"
		}
		var netProto string
		switch strategy {
		case "tunnel":
			netProto = "tunnel"
		case "proxy":
			netProto = "tcp" // proxy is handled at dialer level
		default:
			netProto = "tcp"
		}
		connStr := fmt.Sprintf("%s:%s@%s(%s:%d)/%s?tls=%s&timeout=10s&parseTime=true",
			conn.Username, conn.Password, netProto, conn.Host, conn.Port, conn.Database, tlsSetting)
		db, err = sql.Open(driverName, connStr)
	case "mssql":
		driverName = "sqlserver"
		encrypt := "false"
		if conn.SSL {
			encrypt = "true"
		}
		connStr := fmt.Sprintf("server=%s;port=%d;user id=%s;password=%s;database=%s;encrypt=%s;connection timeout=10",
			conn.Host, conn.Port, conn.Username, conn.Password, conn.Database, encrypt)
		db, err = sql.Open(driverName, connStr)
	case "sqlite":
		driverName = "sqlite"
		// For SQLite, the "host" field contains the file path to the .db file
		dbPath := conn.Database
		if dbPath == "" {
			dbPath = conn.Host
		}
		if dbPath == "" {
			return nil, fmt.Errorf("sqlite: database path is required")
		}
		connStr := fmt.Sprintf("file:%s?cache=shared&mode=rw", dbPath)
		db, err = sql.Open(driverName, connStr)
	default:
		return nil, fmt.Errorf("unsupported engine: %s", conn.Engine)
	}
	return db, err
}

func openDBWithFallback(conn DBConnection) (*sql.DB, error) {
	logMsg("Trying direct connection to %s:%d...", conn.Host, conn.Port)
	db, err := openDBWithStrategy(conn, "direct")
	if err == nil {
		err = db.Ping()
	}
	if err == nil {
		logMsg("Direct connection successful")
		return db, nil
	}
	logMsg("Direct connection failed: %v", err)

	if conn.Engine == "mysql" || conn.Engine == "mariadb" {
		logMsg("Trying WebSocket tunnel to %s:%d...", conn.Host, conn.Port)
		db2, err2 := openDBWithStrategy(conn, "tunnel")
		if err2 == nil {
			err2 = db2.Ping()
		}
		if err2 == nil {
			logMsg("Tunnel connection successful")
			return db2, nil
		}
		logMsg("Tunnel connection failed: %v", err2)
	}

	return nil, fmt.Errorf("all connection strategies failed for %s:%d (last error: %v)", conn.Host, conn.Port, err)
}

func getTables(db *sql.DB, engine string) ([]string, error) {
	var query string
	switch engine {
	case "postgresql":
		query = "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'"
	case "mysql", "mariadb":
		query = "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'"
	case "mssql":
		query = "SELECT table_name FROM information_schema.tables WHERE table_type='BASE TABLE'"
	case "sqlite":
		query = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
	default:
		query = "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'"
	}

	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var tables []string
	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err == nil {
			tables = append(tables, name)
		}
	}
	return tables, nil
}

func inspectTable(db *sql.DB, engine, table string) (*TableInfo, error) {
	ti := &TableInfo{Name: table}

	var colQuery string
	switch engine {
	case "postgresql":
		colQuery = fmt.Sprintf("SELECT column_name, data_type, is_nullable, 'NON' FROM information_schema.columns WHERE table_name='%s' AND table_schema='public'", table)
	case "mssql":
		colQuery = fmt.Sprintf("SELECT column_name, data_type, is_nullable, 'NON' FROM information_schema.columns WHERE table_name='%s'", table)
	case "mysql", "mariadb":
		colQuery = fmt.Sprintf("SELECT column_name, data_type, is_nullable, column_key FROM information_schema.columns WHERE table_name='%s' AND table_schema=DATABASE()", table)
	case "sqlite":
		colQuery = fmt.Sprintf("SELECT c.name, c.type, CASE WHEN c.\"notnull\"=0 THEN 'YES' ELSE 'NO' END, CASE WHEN pk>0 THEN 'PRI' ELSE '' END FROM pragma_table_info('%s') c", table)
	default:
		colQuery = fmt.Sprintf("SELECT column_name, data_type, is_nullable, column_key FROM information_schema.columns WHERE table_name='%s'", table)
	}

	rows, err := db.Query(colQuery)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	for rows.Next() {
		var col ColumnInfo
		var nullable string
		var colKey string
		if err := rows.Scan(&col.Name, &col.Type, &nullable, &colKey); err != nil {
			continue
		}
		col.Nullable = nullable == "YES"
		col.IsPK = colKey == "PRI"
		col.IsPersonal, col.Category = detectPersonalData(col.Name)
		ti.Columns = append(ti.Columns, col)
	}

	if engine == "mysql" || engine == "mariadb" {
		ti.Encrypted = checkTableEncryption(db, table)
	}

	return ti, nil
}

func checkTableEncryption(db *sql.DB, table string) bool {
	rows, err := db.Query(fmt.Sprintf("SELECT CREATE_OPTIONS FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='%s'", table))
	if err != nil {
		return false
	}
	defer rows.Close()

	for rows.Next() {
		var opts sql.NullString
		if err := rows.Scan(&opts); err != nil {
			return false
		}
		if opts.Valid && strings.Contains(strings.ToUpper(opts.String), "ENCRYPTED=YES") {
			return true
		}
	}

	// Check global encryption setting as fallback
	rows2, err := db.Query("SELECT @@innodb_encrypt_tables")
	if err == nil {
		defer rows2.Close()
		if rows2.Next() {
			var val string
			if err := rows2.Scan(&val); err == nil {
				return val == "1" || val == "ON" || strings.EqualFold(val, "FORCE")
			}
		}
	}

	return false
}

func scanMongoDB(conn DBConnection) (*ScanResult, error) {
	result := &ScanResult{
		Engine:    conn.Engine,
		Host:      conn.Host,
		Database:  conn.Database,
		ScannedAt: time.Now().UTC().Format(time.RFC3339),
	}

	logMsg("MongoDB scan not yet supported via Go driver, use the Ollama agent for AI-powered analysis")
	result.Error = "MongoDB requires the Ollama AI module for collection scanning"
	return result, nil
}

func mockScan(conn DBConnection) (*ScanResult, error) {
	result := &ScanResult{
		Engine:    conn.Engine,
		Host:      conn.Host,
		Database:  conn.Database,
		ScannedAt: time.Now().UTC().Format(time.RFC3339),
		TotalTables: 5,
		TotalRows:   12500,
		PersonalDataColumns: 15,
		SensitiveDataColumns: 3,
	}

	result.Tables = []TableInfo{
		{
			Name: "clientes", RowCount: 5000,
			Columns: []ColumnInfo{
				{Name: "id", Type: "int", IsPK: true},
				{Name: "rut", Type: "varchar", IsPersonal: true, Category: "rut"},
				{Name: "nombre", Type: "varchar", IsPersonal: true, Category: "nombre"},
				{Name: "email", Type: "varchar", IsPersonal: true, Category: "email"},
				{Name: "telefono", Type: "varchar", IsPersonal: true, Category: "telefono"},
				{Name: "direccion", Type: "text", IsPersonal: true, Category: "direccion"},
				{Name: "fecha_nacimiento", Type: "date", IsPersonal: true, Category: "fecha_nac"},
				{Name: "salud", Type: "text", IsPersonal: true, Category: "salud", IsPK: false},
			},
		},
		{
			Name: "empleados", RowCount: 2500,
			Columns: []ColumnInfo{
				{Name: "id", Type: "int", IsPK: true},
				{Name: "rut", Type: "varchar", IsPersonal: true, Category: "rut"},
				{Name: "nombre_completo", Type: "varchar", IsPersonal: true, Category: "nombre"},
				{Name: "email_corporativo", Type: "varchar", IsPersonal: true, Category: "email"},
				{Name: "salario", Type: "decimal"},
				{Name: "cuenta_bancaria", Type: "varchar", IsPersonal: true, Category: "bancario"},
			},
		},
		{
			Name: "proveedores", RowCount: 1000,
			Columns: []ColumnInfo{
				{Name: "id", Type: "int", IsPK: true},
				{Name: "razon_social", Type: "varchar"},
				{Name: "rut", Type: "varchar", IsPersonal: true, Category: "rut"},
				{Name: "contacto_nombre", Type: "varchar", IsPersonal: true, Category: "nombre"},
				{Name: "contacto_email", Type: "varchar", IsPersonal: true, Category: "email"},
			},
		},
		{
			Name: "usuarios_web", RowCount: 3000,
			Columns: []ColumnInfo{
				{Name: "user_id", Type: "int", IsPK: true},
				{Name: "email", Type: "varchar", IsPersonal: true, Category: "email"},
				{Name: "ip_address", Type: "varchar", IsPersonal: true, Category: "ip"},
				{Name: "password_hash", Type: "varchar", IsPersonal: true, Category: "credencial"},
				{Name: "ubicacion_gps", Type: "geometry", IsPersonal: true, Category: "ubicacion"},
			},
		},
		{
			Name: "logs_acceso", RowCount: 1000,
			Columns: []ColumnInfo{
				{Name: "log_id", Type: "bigint", IsPK: true},
				{Name: "user_id", Type: "int"},
				{Name: "ip_origen", Type: "varchar", IsPersonal: true, Category: "ip"},
				{Name: "timestamp", Type: "timestamp"},
				{Name: "accion", Type: "varchar"},
			},
		},
	}

	return result, nil
}

func init() {
	scanResultJSON, _ := json.Marshal(ScanResult{})
	_ = scanResultJSON
}
