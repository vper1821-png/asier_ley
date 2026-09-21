package scanner

import (
	"context"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/utils"
)

// InventorySender define la interfaz para enviar inventario (evita ciclo de importación)
type InventorySender interface {
	SendInitialInventory(item InitialInventoryItem)
}

// InitialScanConfig configura el escaneo inicial masivo
type InitialScanConfig struct {
	MaxFiles          int
	MaxDepth          int
	ScanTimeout       time.Duration
	FileTimeout       time.Duration
	MinFileSize       int64
	MaxFileSize       int64
	Extensions        []string
	SkipHidden        bool
	SkipSystem        bool
	ConcurrentWorkers int

	// CustomDirs: rutas explícitas. Si están seteadas, tienen prioridad
	// sobre el auto-descubrimiento.
	CustomDirs []string
}

// DefaultInitialScanConfig retorna configuración por defecto
func DefaultInitialScanConfig() *InitialScanConfig {
	return &InitialScanConfig{
		MaxFiles:          200000,
		MaxDepth:          30,
		ScanTimeout:       30 * time.Minute,
		FileTimeout:       60 * time.Second,
		MinFileSize:       10,
		MaxFileSize:       500 * 1024 * 1024,
		Extensions:        []string{".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx"},
		SkipHidden:        true,
		SkipSystem:        true,
		ConcurrentWorkers: runtime.NumCPU(),
	}
}

// ScanResult contiene el resultado de un archivo escaneado
type ScanResult struct {
	Path         string
	RelativePath string
	Size         int64
	Extension    string
	Categories   map[string]bool
	HasSensitive bool
	PersonalData map[string][]string
	Hash         string
	RowCount     int
	Error        string
	ScannedAt    time.Time
	ModifiedAt   time.Time
}

// InitialInventoryItem representa un archivo en el inventario de la empresa
type InitialInventoryItem struct {
	AgentID      string              `json:"agentId"`
	UserID       string              `json:"userId"`
	CompanyID    string              `json:"companyId"`
	Hostname     string              `json:"hostname"`
	Path         string              `json:"path"`
	RelativePath string              `json:"relativePath"`
	Size         int64               `json:"size"`
	Extension    string              `json:"extension"`
	Categories   []string            `json:"categories"`
	Sensitive    bool                `json:"sensitive"`
	PersonalData map[string][]string `json:"personalData"`
	Hash         string              `json:"hash"`
	RowCount     int                 `json:"rowCount"`
	FirstSeen    time.Time           `json:"firstSeen"`
	LastScanned  time.Time           `json:"lastScanned"`
	LastModified time.Time           `json:"lastModified"`
	ScanCount    int                 `json:"scanCount"`
	Status       string              `json:"status"`
}

var supportedExts = []string{".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx"}

// RunInitialMassiveScan ejecuta el escaneo inicial masivo al arrancar el agente
func RunInitialMassiveScan(
	ctx context.Context,
	log *logger.Logger,
	store *audit.Store,
	sender InventorySender,
	config *InitialScanConfig,
) (int, int, error) {

	log.Info("🔍 INICIANDO ESCANEO INICIAL MASIVO DE DATOS SENSIBLES")
	log.Info("Config: MaxFiles=%d, MaxDepth=%d, Workers=%d, Timeout=%v",
		config.MaxFiles, config.MaxDepth, config.ConcurrentWorkers, config.ScanTimeout)

	scanDirs := resolveScanDirs(config, log)
	if len(scanDirs) == 0 {
		log.Warn("No hay directorios válidos para escanear")
		return 0, 0, nil
	}
	log.Info("Directorios a escanear (%d): %v", len(scanDirs), scanDirs)

	jobs := make(chan string, config.ConcurrentWorkers*2)
	results := make(chan *ScanResult, config.ConcurrentWorkers*2)

	scanCtx, cancel := context.WithTimeout(ctx, config.ScanTimeout)
	defer cancel()

	var wg sync.WaitGroup
	sem := make(chan struct{}, config.ConcurrentWorkers)

	for i := 0; i < config.ConcurrentWorkers; i++ {
		wg.Add(1)
		go func(workerID int) {
			defer wg.Done()
			for path := range jobs {
				select {
				case <-scanCtx.Done():
					return
				default:
				}
				select {
				case sem <- struct{}{}:
					result := scanFileWithTimeout(path, config)
					results <- result
					<-sem
				case <-scanCtx.Done():
					return
				}
			}
		}(i)
	}

	go func() {
		wg.Wait()
		close(results)
	}()

	go func() {
		walkAndSend(scanCtx, scanDirs, config, log, jobs)
		close(jobs)
	}()

	var (
		totalScanned   int
		totalSensitive int
		inventoryItems []InitialInventoryItem
	)

	for result := range results {
		totalScanned++
		if totalScanned%100 == 0 {
			log.Info("Progreso: %d archivos escaneados, %d con datos sensibles",
				totalScanned, totalSensitive)
		}

		if result.Error != "" {
			log.Debug("Error escaneando %s: %s", result.Path, result.Error)
			continue
		}

		if result.HasSensitive {
			totalSensitive++

			item := InitialInventoryItem{
				AgentID:      getAgentID(),
				UserID:       getUserID(),
				CompanyID:    getCompanyID(),
				Hostname:     getHostname(),
				Path:         result.Path,
				RelativePath: result.RelativePath,
				Size:         result.Size,
				Extension:    result.Extension,
				Categories:   getCategoriesList(result.Categories),
				Sensitive:    result.HasSensitive,
				PersonalData: result.PersonalData,
				Hash:         result.Hash,
				RowCount:     result.RowCount,
				FirstSeen:    time.Now(),
				LastScanned:  time.Now(),
				LastModified: result.ModifiedAt,
				ScanCount:    1,
				Status:       "active",
			}

			if store != nil {
				auditItem := audit.SensitiveInventoryItem{
					AgentID:      item.AgentID,
					UserID:       item.UserID,
					CompanyID:    item.CompanyID,
					Hostname:     item.Hostname,
					Path:         item.Path,
					RelativePath: item.RelativePath,
					Size:         item.Size,
					Extension:    item.Extension,
					Categories:   item.Categories,
					Sensitive:    item.Sensitive,
					PersonalData: item.PersonalData,
					Hash:         item.Hash,
					FirstSeen:    item.FirstSeen,
					LastScanned:  item.LastScanned,
					LastModified: item.LastModified,
					ScanCount:    item.ScanCount,
					Status:       item.Status,
				}
				if err := store.SaveInitialInventory(auditItem); err != nil {
					log.Error("Error guardando inventario local: %v", err)
				}
			}

			if sender != nil {
				sender.SendInitialInventory(item)
			}

			inventoryItems = append(inventoryItems, item)
			log.Info("📁 DATOS SENSIBLES ENCONTRADOS: %s (cats: %v)", result.RelativePath, getCategoriesList(result.Categories))
		} else {
			if sender != nil {
				sender.SendInitialInventory(InitialInventoryItem{
					AgentID:      getAgentID(),
					UserID:       getUserID(),
					CompanyID:    getCompanyID(),
					Hostname:     getHostname(),
					Path:         result.Path,
					RelativePath: result.RelativePath,
					Size:         result.Size,
					Extension:    result.Extension,
					Categories:   getCategoriesList(result.Categories),
					Sensitive:    false,
					PersonalData: result.PersonalData,
					Hash:         result.Hash,
					RowCount:     result.RowCount,
					FirstSeen:    time.Now(),
					LastScanned:  time.Now(),
					LastModified: result.ModifiedAt,
					ScanCount:    1,
					Status:       "active",
				})
			}
		}
	}

	log.Info("============================================")
	log.Info("ESCANEO INICIAL COMPLETADO")
	log.Info("Total archivos escaneados: %d", totalScanned)
	log.Info("Archivos con datos sensibles: %d", totalSensitive)
	log.Info("Inventario creado: %d items", len(inventoryItems))
	log.Info("============================================")

	return totalScanned, totalSensitive, scanCtx.Err()
}

// resolveScanDirs decide qué directorios escanear, priorizando CustomDirs.
func resolveScanDirs(cfg *InitialScanConfig, log *logger.Logger) []string {
	if len(cfg.CustomDirs) > 0 {
		var valid []string
		seen := make(map[string]bool)
		for _, d := range cfg.CustomDirs {
			d = filepath.Clean(d)
			if d == "" {
				continue
			}
			key := d
			if runtime.GOOS == "windows" {
				key = strings.ToLower(key)
			}
			if seen[key] {
				continue
			}
			if info, err := os.Stat(d); err == nil && info.IsDir() {
				seen[key] = true
				valid = append(valid, d)
			} else if log != nil {
				log.Warn("CustomDir no accesible: %s (%v)", d, err)
			}
		}
		if len(valid) > 0 {
			return valid
		}
		if log != nil {
			log.Warn("Ninguno de los %d CustomDirs existe; usando auto-descubrimiento", len(cfg.CustomDirs))
		}
	}

	return getScanDirectories(log)
}

func walkAndSend(scanCtx context.Context, scanDirs []string, config *InitialScanConfig, log *logger.Logger, jobs chan<- string) {
	sentFiles := 0
	stopAll := false

	for _, baseDir := range scanDirs {
		if stopAll {
			break
		}

		baseDir = filepath.Clean(baseDir)
		baseDepth := strings.Count(baseDir, string(filepath.Separator))

		err := filepath.WalkDir(baseDir, func(path string, d fs.DirEntry, err error) error {
			if stopAll {
				return filepath.SkipAll
			}

			select {
			case <-scanCtx.Done():
				return filepath.SkipAll
			default:
			}

			if err != nil {
				return nil
			}

			if d.IsDir() {
				depth := strings.Count(path, string(filepath.Separator)) - baseDepth
				if depth > config.MaxDepth {
					return fs.SkipDir
				}
				if shouldSkipDir(path, config) {
					return fs.SkipDir
				}
				return nil
			}

			if config.MaxFiles > 0 && sentFiles >= config.MaxFiles {
				stopAll = true
				return filepath.SkipAll
			}

			if config.SkipHidden && isHidden(path) {
				return nil
			}

			ext := strings.ToLower(filepath.Ext(path))
			if !isSupportedExt(ext) {
				return nil
			}

			info, err := d.Info()
			if err != nil {
				return nil
			}
			if info.Size() < config.MinFileSize || info.Size() > config.MaxFileSize {
				return nil
			}

			select {
			case jobs <- path:
				sentFiles++
			case <-scanCtx.Done():
				return filepath.SkipAll
			}

			return nil
		})

		if err != nil && err != filepath.SkipAll {
			log.Error("Error recorriendo %s: %v", baseDir, err)
		}
	}

	log.Info("Caminata finalizada. Archivos encolados: %d", sentFiles)
}

func isSupportedExt(ext string) bool {
	for _, e := range supportedExts {
		if ext == e {
			return true
		}
	}
	return false
}

func isHidden(path string) bool {
	name := filepath.Base(path)
	if strings.HasPrefix(name, ".") || strings.HasPrefix(name, "~$") {
		return true
	}
	return false
}

// shouldSkipDir decide si un directorio debe excluirse del escaneo.
// Excluye por PATH ABSOLUTO, no por basename.
func shouldSkipDir(path string, config *InitialScanConfig) bool {
	if !config.SkipSystem && !config.SkipHidden {
		return false
	}

	base := filepath.Base(path)
	lowerBase := strings.ToLower(base)
	lowerPath := strings.ToLower(filepath.Clean(path))

	if config.SkipHidden && strings.HasPrefix(base, ".") {
		return true
	}

	if !config.SkipSystem {
		return false
	}

	if runtime.GOOS == "windows" {
		p := lowerPath
		if !strings.HasSuffix(p, `\`) {
			p += `\`
		}

		systemPrefixes := []string{
			`c:\windows\`,
			`c:\winnt\`,
			`c:\program files\`,
			`c:\program files (x86)\`,
			`c:\program files (arm)\`,
			`c:\programdata\`,
			`c:\$recycle.bin\`,
			`c:\system volume information\`,
			`c:\recovery\`,
			`c:\perflogs\`,
		}
		for _, prefix := range systemPrefixes {
			if strings.HasPrefix(p, prefix) {
				return true
			}
		}

		if strings.HasPrefix(p, `c:\users\`) {
			if strings.Contains(p, `\appdata\`) {
				return true
			}
			if strings.Contains(p, `\configuración local\`) ||
				strings.Contains(p, `\configuracion local\`) ||
				strings.Contains(p, `\datos de programa\`) {
				return true
			}
		}

		switch lowerBase {
		case "node_modules",
			"__pycache__",
			"system volume information",
			"$recycle.bin",
			"$windows.~bt",
			"$windows.~ws",
			"$sysreset",
			"windows.old":
			return true
		}

		if strings.HasPrefix(p, `c:\users\`) {
			switch lowerBase {
			case "all users", "default user", "defaultaccount", "wdagutilityaccount":
				return true
			}
		}
	}

	if runtime.GOOS != "windows" {
		p := lowerPath
		if !strings.HasSuffix(p, "/") {
			p += "/"
		}

		systemPrefixes := []string{
			"/proc/",
			"/sys/",
			"/dev/",
			"/run/",
			"/boot/",
			"/var/lib/docker/",
			"/var/lib/containers/",
		}
		for _, prefix := range systemPrefixes {
			if strings.HasPrefix(p, prefix) {
				return true
			}
		}

		switch lowerBase {
		case "node_modules", "__pycache__", "snap", "lost+found":
			return true
		}
	}

	return false
}

func scanFileWithTimeout(path string, config *InitialScanConfig) *ScanResult {
	ctx, cancel := context.WithTimeout(context.Background(), config.FileTimeout)
	defer cancel()

	resultCh := make(chan *ScanResult, 1)
	go func() {
		resultCh <- scanSingleFile(path)
	}()

	select {
	case result := <-resultCh:
		return result
	case <-ctx.Done():
		return &ScanResult{Path: path, Error: "timeout", ScannedAt: time.Now()}
	}
}

func scanSingleFile(path string) *ScanResult {
	info, err := os.Stat(path)
	if err != nil {
		return &ScanResult{Path: path, Error: err.Error()}
	}

	if info.Size() < 10 || info.Size() > 500*1024*1024 {
		return &ScanResult{Path: path, Error: "tamaño fuera de rango"}
	}

	ext := strings.ToLower(filepath.Ext(path))
	if !isSupportedExt(ext) {
		return &ScanResult{Path: path, Error: "extensión no soportada"}
	}

	hash, _ := utils.HashFile(path)

	personalData, err := ScanFile(path)
	if err != nil {
		return &ScanResult{Path: path, Error: err.Error(), Hash: hash}
	}

	cats := DetectPersonalDataFromMap(personalData)
	hasSensitive := HasSensitiveData(cats)

	rowCount := CountRows(path)

	relPath := path
	for _, base := range getScanDirectories(nil) {
		if rel, err := filepath.Rel(base, path); err == nil && !strings.HasPrefix(rel, "..") {
			relPath = rel
			break
		}
	}

	return &ScanResult{
		Path:         path,
		RelativePath: relPath,
		Size:         info.Size(),
		Extension:    ext,
		Categories:   cats,
		HasSensitive: hasSensitive,
		PersonalData: personalData,
		Hash:         hash,
		RowCount:     rowCount,
		ScannedAt:    time.Now(),
		ModifiedAt:   info.ModTime(),
	}
}

// DetectPersonalDataFromMap detecta categorías desde el mapa de datos personales
func DetectPersonalDataFromMap(personalData map[string][]string) map[string]bool {
	allText := ""
	for _, cats := range personalData {
		for _, cat := range cats {
			allText += cat + " "
		}
	}
	return DetectPersonalData(allText)
}

func getCategoriesList(cats map[string]bool) []string {
	list := make([]string, 0, len(cats))
	for cat := range cats {
		list = append(list, cat)
	}
	return list
}

// getScanDirectories retorna directorios a escanear según SO.
func getScanDirectories(log *logger.Logger) []string {
	return computeScanDirectories(log)
}

func computeScanDirectories(log *logger.Logger) []string {
	var baseDirs []string

	home, _ := os.UserHomeDir()

	if home != "" && !isSystemProfilePath(home) {
		baseDirs = append(baseDirs,
			filepath.Join(home, "Documents"),
			filepath.Join(home, "Desktop"),
			filepath.Join(home, "Downloads"),
			filepath.Join(home, "OneDrive"),
			filepath.Join(home, "Google Drive"),
			filepath.Join(home, "Dropbox"),
		)
	}

	if runtime.GOOS == "windows" {
		if up := os.Getenv("USERPROFILE"); up != "" && !isSystemProfilePath(up) {
			baseDirs = append(baseDirs,
				filepath.Join(up, "Documents"),
				filepath.Join(up, "Desktop"),
				filepath.Join(up, "Downloads"),
			)
		}
		if pub := os.Getenv("PUBLIC"); pub != "" {
			baseDirs = append(baseDirs,
				filepath.Join(pub, "Documents"),
				filepath.Join(pub, "Downloads"),
			)
		}
		baseDirs = append(baseDirs, `C:\Users`)
	} else {
		baseDirs = append(baseDirs,
			"/opt",
			"/var/www",
			"/home",
			"/srv",
		)
	}

	seen := make(map[string]bool)
	var dirs []string
	for _, d := range baseDirs {
		if d == "" {
			continue
		}
		d = filepath.Clean(d)

		key := d
		if runtime.GOOS == "windows" {
			key = strings.ToLower(key)
		}
		if seen[key] {
			continue
		}

		if info, err := os.Stat(d); err == nil && info.IsDir() {
			seen[key] = true
			dirs = append(dirs, d)
		} else if log != nil {
			log.Debug("Directorio no accesible: %s", d)
		}
	}

	return dirs
}

func isSystemProfilePath(p string) bool {
	l := strings.ToLower(p)
	return strings.Contains(l, "systemprofile") ||
		strings.Contains(l, `windows\system32\config`) ||
		strings.Contains(l, `windows\syswow64`)
}

func getAgentID() string   { return "unknown" }
func getUserID() string    { return "unknown" }
func getCompanyID() string { return "unknown" }
func getHostname() string  { return "unknown" }
