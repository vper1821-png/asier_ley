package main

import (
    "bufio"
    "crypto/sha256"
    "encoding/csv"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "io"
    "os"
    "path/filepath"
    "runtime"
    "strings"
    "sync"
    "time"

    "github.com/xuri/excelize/v2"
)

// ─── Escáner de archivos para datos personales ───
type FileScanner struct {
    mu          sync.RWMutex
    watchedDirs []string
    knownFiles  map[string]FileSnapshot
    stopCh      chan struct{}
    interval    time.Duration
    active      bool
}

type FileSnapshot struct {
    Path      string
    ModTime   time.Time
    Size      int64
    Hash      string
    Processed bool
}

// DetectedFile es lo que se envía al backend
type DetectedFile struct {
    AgentID      string              `json:"agentId"`
    Hostname     string              `json:"hostname"`
    Path         string              `json:"path"`
    FileType     string              `json:"fileType"`
    Size         int64               `json:"size"`
    Hash         string              `json:"hash"`
    RowCount     int                 `json:"rowCount"`
    PersonalData map[string][]string `json:"personalData"` // columna -> categorías
    Sensitive    bool                `json:"sensitive"`
    MimeType     string              `json:"mimeType"`
}

var (
    personalDataPatterns = map[string][]string{
        "nombre":       {"nombre", "name", "first_name", "last_name", "apellido", "full_name", "nombres", "nombre_completo", "razon_social"},
        "email":        {"email", "e-mail", "mail", "correo", "email_address", "correo_electronico", "email_principal"},
        "rut":          {"rut", "run", "dni", "cedula", "documento", "id_number", "national_id", "identificacion", "passport", "num_documento", "dv", "rol_unico_tributario"},
        "telefono":     {"telefono", "phone", "mobile", "celular", "phone_number", "contact", "movil", "whatsapp", "telefono_movil"},
        "direccion":    {"direccion", "address", "domicilio", "street", "calle", "location", "domicilio", "ciudad", "provincia", "region", "comuna", "municipio", "codigo_postal"},
        "fecha_nac":    {"fecha_nacimiento", "birth_date", "dob", "date_of_birth", "nacimiento", "fecha_nac", "birthday", "fecha_de_nacimiento"},
        "salud":        {"salud", "health", "medical", "diagnostico", "enfermedad", "seguro_medico", "discapacidad", "historial_medico", "paciente", "alergia", "tipo_sangre", "isapre", "fonasa", "prevision", "receta"},
        "biometrico":   {"biometrico", "biometric", "fingerprint", "huella", "iris", "face_id", "dna", "genetic", "huella_dactilar", "reconocimiento_facial", "firma"},
        "bancario":     {"cuenta_bancaria", "bank_account", "credit_card", "tarjeta", "cvv", "iban", "account_number", "banco", "bank", "afp", "credito", "debito", "ahorros", "corriente", "prestamo"},
        "credencial":   {"password", "contraseña", "hash", "secret", "token", "auth_key", "api_key", "secret_key", "pwd", "pass", "clave_secreta", "access_token", "jwt"},
        "ip":           {"ip_address", "ip", "direccion_ip", "client_ip", "remote_addr", "ipv4", "ipv6", "mac_address"},
        "ubicacion":    {"ubicacion", "location", "gps", "latitud", "longitud", "coordinates", "coordenada", "altitud", "geolocation"},
        "genero":       {"genero", "gender", "sexo", "sex", "orientacion", "identidad_genero"},
        "religion":     {"religion", "religión", "credo", "faith", "catolico", "evangelico", "judio", "musulman", "ateo"},
        "politico":     {"politico", "political", "partido", "voto", "vote", "militancia", "afiliacion_politica"},
        "sindical":     {"sindical", "union", "sindicato", "gremio", "asociacion", "federacion"},
        "judicial":     {"judicial", "criminal", "delito", "antecedentes", "penal", "sentencia", "demanda", "denuncia", "condena", "causa", "tribunal", "corte"},
        "educacion":    {"educacion", "education", "school", "colegio", "universidad", "titulo", "grado_academico", "profesion", "carrera", "alumno", "matricula", "calificacion"},
        "laboral":      {"laboral", "employment", "job", "trabajo", "salary", "salario", "sueldo", "renta", "ingreso", "cargo", "contrato", "empleador", "remuneracion", "honorario"},
        "conyuge":      {"conyuge", "spouse", "estado_civil", "marital", "casado", "divorcio", "pareja", "separacion"},
        "hijos":        {"hijos", "children", "familia", "family", "carga_familiar", "dependents", "hijo_menor", "padre", "madre", "hermano", "hermana"},
        "foto":         {"foto", "photo", "picture", "image", "avatar", "fotografia", "imagen", "retrato", "foto_carnet"},
        "nacionalidad": {"nacionalidad", "nationality", "pais", "lugar_nacimiento", "ciudadania", "residencia", "pais_origen", "visa", "extranjeria", "inmigrante"},
        "seguro":       {"seguro", "insurance", "poliza", "aseguradora", "cobertura", "beneficiario", "siniestro", "seguro_vida", "seguro_salud"},
        "vehiculo":     {"vehiculo", "vehicle", "car", "auto", "patente", "license_plate", "placa", "chasis", "motor", "modelo_vehiculo", "marca_vehiculo"},
        "patrimonio":   {"bienes", "property", "propiedad", "inmueble", "real_estate", "herencia", "sucesion", "patrimonio", "assets", "testamento"},
        "financiero":   {"ingresos", "income", "egresos", "gastos", "budget", "presupuesto", "impuesto", "tax", "declaracion_renta", "iva", "factura", "boleta", "contabilidad"},
        "digital":      {"user_agent", "browser", "navegador", "cookie", "session_id", "device_id", "imei", "serial_number", "udid"},
        "comunicacion": {"correspondencia", "carta", "letter", "mensaje", "message", "sms", "chat", "conversacion", "llamada", "call_log"},
    }
)

// NOTA: sensitiveCategories YA ESTÁ DEFINIDA en db_scanner.go.
// NO LA DECLARES AQUÍ. Usa la función isSensitiveCategory() que ya existe.

var fileScanner *FileScanner
var fileScannerOnce sync.Once

func GetFileScanner() *FileScanner {
    fileScannerOnce.Do(func() {
        dirs := getDefaultScanDirs()
        fileScanner = NewFileScanner(dirs)
    })
    return fileScanner
}

func getDefaultScanDirs() []string {
    var dirs []string
    if runtime.GOOS == "windows" {
        dirs = []string{
            os.ExpandEnv("%USERPROFILE%\\Documents"),
            os.ExpandEnv("%USERPROFILE%\\Desktop"),
            os.ExpandEnv("%USERPROFILE%\\Downloads"),
            os.ExpandEnv("%USERPROFILE%\\OneDrive"),
        }
    } else {
        home := os.ExpandEnv("$HOME")
        dirs = []string{
            filepath.Join(home, "Documents"),
            filepath.Join(home, "Desktop"),
            filepath.Join(home, "Downloads"),
            filepath.Join(home, "OneDrive"),
        }
    }
    var valid []string
    for _, d := range dirs {
        if info, err := os.Stat(d); err == nil && info.IsDir() {
            valid = append(valid, d)
        }
    }
    return valid
}

func NewFileScanner(dirs []string) *FileScanner {
    return &FileScanner{
        watchedDirs: dirs,
        knownFiles:  make(map[string]FileSnapshot),
        interval:    10 * time.Minute,
        stopCh:      make(chan struct{}),
    }
}

func (fs *FileScanner) Start() {
    if fs.active {
        return
    }
    fs.active = true
    logMsg("FileScanner: iniciado, monitoreando %d directorios", len(fs.watchedDirs))
    go fs.loop()
}

func (fs *FileScanner) Stop() {
    if !fs.active {
        return
    }
    fs.active = false
    close(fs.stopCh)
}

func (fs *FileScanner) loop() {
    time.Sleep(10 * time.Second)
    fs.scan()
    ticker := time.NewTicker(fs.interval)
    defer ticker.Stop()

    for {
        select {
        case <-ticker.C:
            fs.scan()
        case <-fs.stopCh:
            logMsg("FileScanner: detenido")
            return
        }
    }
}

func (fs *FileScanner) scan() {
    fs.mu.RLock()
    dirs := make([]string, len(fs.watchedDirs))
    copy(dirs, fs.watchedDirs)
    fs.mu.RUnlock()

    if len(dirs) == 0 {
        return
    }

    logMsg("FileScanner: escaneando %d directorios...", len(dirs))

    for _, root := range dirs {
        filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
            if err != nil {
                return nil
            }
            if info.IsDir() {
                name := strings.ToLower(info.Name())
                if strings.HasPrefix(name, ".") ||
                    name == "windows" || name == "program files" ||
                    name == "program files (x86)" || name == "$recycle.bin" ||
                    name == "system volume information" {
                    return filepath.SkipDir
                }
                return nil
            }
            ext := strings.ToLower(filepath.Ext(path))
            if !isAllowedFileExt(ext) {
                return nil
            }
            if info.Size() > 50*1024*1024 {
                return nil
            }
            if fs.shouldProcess(path, info) {
                fs.processFile(path, info)
            }
            return nil
        })
    }
}

func isAllowedFileExt(ext string) bool {
    return ext == ".xlsx" || ext == ".xls" || ext == ".csv" || ext == ".txt"
}

func (fs *FileScanner) shouldProcess(path string, info os.FileInfo) bool {
    fs.mu.RLock()
    snap, exists := fs.knownFiles[path]
    fs.mu.RUnlock()

    if !exists {
        return true
    }
    if !info.ModTime().Equal(snap.ModTime) || info.Size() != snap.Size {
        return true
    }
    return false
}

func (fs *FileScanner) processFile(path string, info os.FileInfo) {
    logMsg("FileScanner: procesando %s", path)

    hash, err := computeFileHash(path)
    if err != nil {
        logMsg("FileScanner: error calculando hash de %s: %v", path, err)
        return
    }

    detected := fs.analyzeFile(path, info, hash)
    if detected == nil {
        fs.updateSnapshot(path, info, hash, true)
        return
    }

    if len(detected.PersonalData) > 0 || detected.Sensitive {
        detected.AgentID = GetAgentID()
        detected.Hostname, _ = os.Hostname()
        detected.Hash = hash
        detected.Size = info.Size()

        logMsg("FileScanner: datos personales detectados en %s (categorías: %v)", path, detected.PersonalData)
        wsSendFileDetection(detected)
        GetAuditStore().StoreFileEvent(detected)
    }

    fs.updateSnapshot(path, info, hash, true)
}

func (fs *FileScanner) updateSnapshot(path string, info os.FileInfo, hash string, processed bool) {
    fs.mu.Lock()
    defer fs.mu.Unlock()
    fs.knownFiles[path] = FileSnapshot{
        Path:      path,
        ModTime:   info.ModTime(),
        Size:      info.Size(),
        Hash:      hash,
        Processed: processed,
    }
}

// ─── Análisis de archivos ───
func (fs *FileScanner) analyzeFile(path string, info os.FileInfo, hash string) *DetectedFile {
    ext := strings.ToLower(filepath.Ext(path))
    detected := &DetectedFile{
        Path:         path,
        FileType:     ext[1:],
        PersonalData: make(map[string][]string),
    }

    switch ext {
    case ".xlsx", ".xls":
        return fs.analyzeExcel(path, detected)
    case ".csv":
        return fs.analyzeCSV(path, detected)
    case ".txt":
        return fs.analyzeTXT(path, detected)
    default:
        return nil
    }
}

func (fs *FileScanner) analyzeExcel(path string, detected *DetectedFile) *DetectedFile {
    f, err := excelize.OpenFile(path)
    if err != nil {
        logMsg("FileScanner: error abriendo Excel %s: %v", path, err)
        return nil
    }
    defer f.Close()

    sheets := f.GetSheetList()
    if len(sheets) == 0 {
        return nil
    }
    rows, err := f.GetRows(sheets[0])
    if err != nil || len(rows) == 0 {
        return nil
    }

    headers := rows[0]
    if len(headers) == 0 {
        return nil
    }

    sampleRows := rows
    if len(sampleRows) > 51 {
        sampleRows = sampleRows[:51]
    }
    sampleData := sampleRows[1:]

    detected.RowCount = len(rows) - 1
    if detected.RowCount < 0 {
        detected.RowCount = 0
    }

    fs.extractPersonalDataFromColumns(headers, sampleData, detected)
    return detected
}

func (fs *FileScanner) analyzeCSV(path string, detected *DetectedFile) *DetectedFile {
    file, err := os.Open(path)
    if err != nil {
        return nil
    }
    defer file.Close()

    reader := csv.NewReader(file)
    reader.LazyQuotes = true
    reader.TrimLeadingSpace = true

    headers, err := reader.Read()
    if err != nil {
        return nil
    }

    var sampleRows [][]string
    rowCount := 0
    for {
        row, err := reader.Read()
        if err == io.EOF {
            break
        }
        if err != nil {
            continue
        }
        rowCount++
        if len(sampleRows) < 50 {
            sampleRows = append(sampleRows, row)
        }
    }
    detected.RowCount = rowCount
    fs.extractPersonalDataFromColumns(headers, sampleRows, detected)
    return detected
}

func (fs *FileScanner) analyzeTXT(path string, detected *DetectedFile) *DetectedFile {
    file, err := os.Open(path)
    if err != nil {
        return nil
    }
    defer file.Close()

    scanner := bufio.NewScanner(file)
    scanner.Buffer(make([]byte, 1024*1024), 1024*1024)

    var lines []string
    for scanner.Scan() && len(lines) < 100 {
        lines = append(lines, scanner.Text())
    }
    if err := scanner.Err(); err != nil {
        return nil
    }
    if len(lines) == 0 {
        return nil
    }

    detected.RowCount = len(lines)

    headers := []string{"contenido"}
    sampleData := [][]string{}
    for _, line := range lines {
        sampleData = append(sampleData, []string{line})
    }

    fs.extractPersonalDataFromColumns(headers, sampleData, detected)
    return detected
}

func (fs *FileScanner) extractPersonalDataFromColumns(headers []string, sampleRows [][]string, detected *DetectedFile) {
    patterns := personalDataPatterns

    for colIdx, header := range headers {
        headerLower := strings.ToLower(strings.TrimSpace(header))
        headerNorm := strings.ReplaceAll(headerLower, "_", " ")
        matchedCategories := []string{}

        // 1. Buscar por nombre de columna
        for cat, keywords := range patterns {
            for _, kw := range keywords {
                if strings.Contains(headerNorm, kw) {
                    matchedCategories = append(matchedCategories, cat)
                    break
                }
            }
        }

        // 2. Si no se encontró por nombre, buscar en los valores de muestra
        if len(matchedCategories) == 0 && len(sampleRows) > 0 {
            for _, row := range sampleRows {
                if colIdx >= len(row) {
                    continue
                }
                val := strings.TrimSpace(row[colIdx])
                if val == "" {
                    continue
                }
                for cat, keywords := range patterns {
                    for _, kw := range keywords {
                        if strings.Contains(strings.ToLower(val), kw) {
                            matchedCategories = append(matchedCategories, cat)
                            break
                        }
                    }
                    if len(matchedCategories) > 0 {
                        break
                    }
                }
                if len(matchedCategories) > 0 {
                    break
                }
            }
        }

        if len(matchedCategories) > 0 {
            unique := []string{}
            seen := map[string]bool{}
            for _, cat := range matchedCategories {
                if !seen[cat] {
                    seen[cat] = true
                    unique = append(unique, cat)
                }
            }
            detected.PersonalData[header] = unique

            // Verificar si es sensible usando la función de db_scanner.go
            for _, cat := range unique {
                if isSensitiveCategory(cat) {
                    detected.Sensitive = true
                    break
                }
            }
        }
    }
}

// computeFileHash devuelve el hash SHA-256 del archivo (string, error)
func computeFileHash(path string) (string, error) {
    f, err := os.Open(path)
    if err != nil {
        return "", err
    }
    defer f.Close()

    hasher := sha256.New()
    if _, err := io.Copy(hasher, f); err != nil {
        return "", err
    }
    return hex.EncodeToString(hasher.Sum(nil)), nil
}
