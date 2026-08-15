package main

import (
	"bufio"
	"crypto/sha256"
	"encoding/csv"
	"encoding/hex"
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
		// Buscar carpetas de todos los usuarios reales en C:\Users
		usersPath := "C:\\Users"
		entries, err := os.ReadDir(usersPath)
		if err == nil {
			for _, entry := range entries {
				if !entry.IsDir() {
					continue
				}
				name := entry.Name()
				// Saltar carpetas del sistema y cuentas especiales
				if name == "Public" || name == "Default" || name == "Default User" ||
					strings.HasPrefix(name, ".") || strings.HasPrefix(name, "All Users") ||
					strings.HasPrefix(name, "Administrator") {
					continue
				}
				userDir := filepath.Join(usersPath, name)
				// Añadir subcarpetas comunes
				subDirs := []string{
					filepath.Join(userDir, "Documents"),
					filepath.Join(userDir, "Desktop"),
					filepath.Join(userDir, "Downloads"),
					filepath.Join(userDir, "OneDrive"),
				}
				for _, d := range subDirs {
					if info, err := os.Stat(d); err == nil && info.IsDir() {
						dirs = append(dirs, d)
					}
				}
			}
		}
		// También añadir carpetas públicas por si acaso
		publicDirs := []string{
			"C:\\Users\\Public\\Documents",
			"C:\\Users\\Public\\Desktop",
			"C:\\Users\\Public\\Downloads",
		}
		for _, d := range publicDirs {
			if info, err := os.Stat(d); err == nil && info.IsDir() {
				dirs = append(dirs, d)
			}
		}
		// Eliminar duplicados
		seen := map[string]bool{}
		var unique []string
		for _, d := range dirs {
			if !seen[d] {
				seen[d] = true
				unique = append(unique, d)
			}
		}
		logMsg("FileScanner: directorios monitorizados: %v", unique)
		return unique
	}
	// Linux / macOS
	home := os.ExpandEnv("$HOME")
	return []string{
		filepath.Join(home, "Documents"),
		filepath.Join(home, "Desktop"),
		filepath.Join(home, "Downloads"),
		filepath.Join(home, "OneDrive"),
	}
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
			if info.Size() > 50*1024*1024 { // saltar archivos > 50 MB
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

// ─── Extraer datos personales (USA LAS VARIABLES GLOBALES DE db_scanner.go) ───
func (fs *FileScanner) extractPersonalDataFromColumns(headers []string, sampleRows [][]string, detected *DetectedFile) {
	// Usamos las variables globales definidas en db_scanner.go
	patterns := personalDataPatterns
	sensitiveMap := sensitiveCategories

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
			// Eliminar duplicados
			unique := []string{}
			seen := map[string]bool{}
			for _, cat := range matchedCategories {
				if !seen[cat] {
					seen[cat] = true
					unique = append(unique, cat)
				}
			}
			detected.PersonalData[header] = unique

			// Verificar si es sensible
			for _, cat := range unique {
				if sensitiveMap[cat] {
					detected.Sensitive = true
					break
				}
			}
		}
	}
}

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
