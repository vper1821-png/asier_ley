package scanner

import (
	"context"
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
	MaxFiles          int           // Límite de archivos a escanear (0 = sin límite)
	MaxDepth          int           // Profundidad máxima de directorios
	ScanTimeout       time.Duration // Timeout total
	FileTimeout       time.Duration // Timeout por archivo
	MinFileSize       int64         // Tamaño mínimo (bytes)
	MaxFileSize       int64         // Tamaño máximo (bytes)
	Extensions        []string      // Extensiones a escanear (vacío = todas soportadas)
	SkipHidden        bool          // Saltar archivos/directorios ocultos
	SkipSystem        bool          // Saltar directorios del sistema
	ConcurrentWorkers int           // Workers concurrentes
}

// DefaultInitialScanConfig retorna configuración por defecto agresiva
func DefaultInitialScanConfig() *InitialScanConfig {
	return &InitialScanConfig{
		MaxFiles:          50000,
		MaxDepth:          10,
		ScanTimeout:       30 * time.Minute,
		FileTimeout:       30 * time.Second,
		MinFileSize:       10,           // 10 bytes mínimo
		MaxFileSize:       100 * 1024 * 1024, // 100 MB
		Extensions:        []string{".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx"},
		SkipHidden:        true,
		SkipSystem:        true,
		ConcurrentWorkers: runtime.NumCPU() * 2,
	}
}

// ScanResult contiene el resultado de un archivo escaneado
type ScanResult struct {
	Path           string
	RelativePath   string
	Size           int64
	Extension      string
	Categories     map[string]bool
	HasSensitive   bool
	PersonalData   map[string][]string
	Hash           string
	Error          string
	ScannedAt      time.Time
	ModifiedAt     time.Time
}

// InitialInventoryItem representa un archivo en el inventario de la empresa
type InitialInventoryItem struct {
	AgentID        string                 `json:"agentId"`
	UserID         string                 `json:"userId"`
	CompanyID      string                 `json:"companyId"`
	Hostname       string                 `json:"hostname"`
	Path           string                 `json:"path"`
	RelativePath   string                 `json:"relativePath"`
	Size           int64                  `json:"size"`
	Extension      string                 `json:"extension"`
	Categories     []string               `json:"categories"`
	Sensitive      bool                   `json:"sensitive"`
	PersonalData   map[string][]string    `json:"personalData"`
	Hash           string                 `json:"hash"`
	FirstSeen      time.Time              `json:"firstSeen"`
	LastScanned    time.Time              `json:"lastScanned"`
	LastModified   time.Time              `json:"lastModified"`
	ScanCount      int                    `json:"scanCount"`
	Status         string                 `json:"status"` // "active", "deleted", "moved", "modified"
}

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

	// Obtener directorios a escanear
	scanDirs := getScanDirectories(log)
	if len(scanDirs) == 0 {
		log.Warn("No hay directorios válidos para escanear")
		return 0, 0, nil
	}

	log.Info("Directorios a escanear: %v", scanDirs)

	// Canal de trabajo
	jobs := make(chan string, config.ConcurrentWorkers*2)
	results := make(chan *ScanResult, config.ConcurrentWorkers*2)

	// Contexto con timeout
	scanCtx, cancel := context.WithTimeout(ctx, config.ScanTimeout)
	defer cancel()

	// Workers
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

	// Colector de resultados
	go func() {
		wg.Wait()
		close(results)
	}()

	// Procesar resultados
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

			// Crear item de inventario
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
				FirstSeen:    time.Now(),
				LastScanned:  time.Now(),
				LastModified: result.ModifiedAt,
				ScanCount:    1,
				Status:       "active",
			}

			// Guardar en base de datos local
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

			// Enviar al backend
			if sender != nil {
				sender.SendInitialInventory(item)
			}

			inventoryItems = append(inventoryItems, item)
			log.Info("📁 DATOS SENSIBLES ENCONTRADOS: %s (cats: %v)", result.RelativePath, getCategoriesList(result.Categories))
		}
	}

	// Resumen final
	log.Info("============================================")
	log.Info("ESCANEO INICIAL COMPLETADO")
	log.Info("Total archivos escaneados: %d", totalScanned)
	log.Info("Archivos con datos sensibles: %d", totalSensitive)
	log.Info("Inventario creado: %d items", len(inventoryItems))
	log.Info("============================================")

	return totalScanned, totalSensitive, scanCtx.Err()
}

// scanFileWithTimeout escanea un archivo con timeout
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

// scanSingleFile escanea un archivo individual
func scanSingleFile(path string) *ScanResult {
	info, err := os.Stat(path)
	if err != nil {
		return &ScanResult{Path: path, Error: err.Error()}
	}

	// Verificar tamaño
	if info.Size() < 10 || info.Size() > 100*1024*1024 {
		return &ScanResult{Path: path, Error: "tamaño fuera de rango"}
	}

	// Verificar extensión
	ext := strings.ToLower(filepath.Ext(path))
	allowedExts := []string{".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx", ".ods", ".rtf"}
	allowed := false
	for _, e := range allowedExts {
		if ext == e {
			allowed = true
			break
		}
	}
	if !allowed && ext != "" {
		// Permitir otros si no hay extensión explícita permitida
	}

	// Calcular hash
	hash, _ := utils.HashFile(path)

	// Escanear contenido
	personalData, err := ScanFile(path)
	if err != nil {
		return &ScanResult{Path: path, Error: err.Error(), Hash: hash}
	}

	// Detectar categorías
	cats := DetectPersonalDataFromMap(personalData)
	hasSensitive := HasSensitiveData(cats)

	// Obtener directorio base para ruta relativa
	relPath := path
	for _, base := range getScanDirectories(nil) {
		if rel, err := filepath.Rel(base, path); err == nil && !strings.HasPrefix(rel, "..") {
			relPath = rel
			break
		}
	}

	return &ScanResult{
		Path:          path,
		RelativePath:  relPath,
		Size:          info.Size(),
		Extension:     ext,
		Categories:    cats,
		HasSensitive:  hasSensitive,
		PersonalData:  personalData,
		Hash:          hash,
		ScannedAt:     time.Now(),
		ModifiedAt:    info.ModTime(),
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

// getScanDirectories retorna directorios a escanear según SO
func getScanDirectories(log *logger.Logger) []string {
	var dirs []string

	home, _ := os.UserHomeDir()
	baseDirs := []string{
		filepath.Join(home, "Documents"),
		filepath.Join(home, "Desktop"),
		filepath.Join(home, "Downloads"),
		filepath.Join(home, "OneDrive"),
		filepath.Join(home, "Google Drive"),
		filepath.Join(home, "Dropbox"),
	}

	if runtime.GOOS == "windows" {
		// En Windows, buscar en C:\Users\ para todos los usuarios
		baseDirs = append(baseDirs, `C:\Users\`)
		// También directorio del agente
		if exe, err := os.Executable(); err == nil {
			baseDirs = append(baseDirs, filepath.Dir(exe))
		}
		if userProfile := os.Getenv("USERPROFILE"); userProfile != "" {
			baseDirs = append(baseDirs,
				filepath.Join(userProfile, "Documents"),
				filepath.Join(userProfile, "Desktop"),
				filepath.Join(userProfile, "Downloads"),
			)
		}
		if publicProfile := os.Getenv("PUBLIC"); publicProfile != "" {
			baseDirs = append(baseDirs, filepath.Join(publicProfile, "Documents"))
		}
	} else {
		// Linux/macOS
		baseDirs = append(baseDirs,
			"/opt",
			"/var/www",
			"/home",
			"/srv",
		)
	}

	// Filtrar existentes y legibles
	for _, d := range baseDirs {
		if info, err := os.Stat(d); err == nil && info.IsDir() {
			dirs = append(dirs, d)
		} else if log != nil {
			log.Debug("Directorio no accesible: %s", d)
		}
	}

	return dirs
}

// Funciones auxiliares (deben estar en config.go o agente)
func getAgentID() string {
	// TODO: obtener del config
	return "unknown"
}
func getUserID() string       { return "unknown" }
func getCompanyID() string    { return "unknown" }
func getHostname() string     { return "unknown" }
