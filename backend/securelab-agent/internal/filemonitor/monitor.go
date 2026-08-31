package filemonitor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/utils"
	"securelab-agent/internal/ws"
)

// Monitor maneja la vigilancia y escaneo de archivos
type Monitor struct {
	ctx       context.Context
	cancel    context.CancelFunc
	wg        sync.WaitGroup
	store     *audit.Store
	wsClient  *ws.Client
	log       *logger.Logger
	watchers  []*fileWatcher
	eventChan chan audit.FileEvent
}

// NewMonitor crea un nuevo monitor de archivos
func NewMonitor(store *audit.Store, wsClient *ws.Client, log *logger.Logger) *Monitor {
	ctx, cancel := context.WithCancel(context.Background())
	return &Monitor{
		ctx:       ctx,
		cancel:    cancel,
		store:     store,
		wsClient:  wsClient,
		log:       log,
		eventChan: make(chan audit.FileEvent, 1000),
	}
}

// WatchDirectories añade directorios a vigilar
func (m *Monitor) WatchDirectories(dirs []string) {
	for _, dir := range dirs {
		if info, err := os.Stat(dir); err != nil || !info.IsDir() {
			m.log.Warn("Directorio no existe o no es válido: %s", dir)
			continue
		}
		w := newFileWatcher(dir, m.eventChan, m.log)
		m.watchers = append(m.watchers, w)
		m.log.Debug("FileMonitor: directorio añadido: %s", dir)
	}
}

// Start inicia la vigilancia y el procesamiento de eventos
func (m *Monitor) Start() {
	if len(m.watchers) == 0 {
		m.log.Warn("FileMonitor: no hay directorios válidos para vigilar")
		return
	}
	m.log.Info("FileMonitor: iniciando vigilancia sobre %d directorios", len(m.watchers))

	for _, w := range m.watchers {
		m.wg.Add(1)
		go func(w *fileWatcher) {
			defer m.wg.Done()
			if err := w.watch(m.ctx); err != nil {
				m.log.Error("FileMonitor: error en watcher para %s: %v", w.dir, err)
			}
		}(w)
	}

	m.wg.Add(1)
	go m.processEvents()
}

// AddSensitiveFilePaths añade rutas específicas de archivos sensibles para monitoreo prioritario
func (m *Monitor) AddSensitiveFilePaths(paths []string) {
	for _, p := range paths {
		// Verificar si ya está siendo vigilado
		found := false
		for _, w := range m.watchers {
			if strings.HasPrefix(p, w.dir) {
				found = true
				break
			}
		}
		if !found {
			// Añadir el directorio padre
			dir := filepath.Dir(p)
			if info, err := os.Stat(dir); err == nil && info.IsDir() {
				m.WatchDirectories([]string{dir})
				m.log.Info("🔴 Monitoreo prioritario activado para: %s (dir: %s)", p, dir)
			}
		}
	}
}

// processEvents procesa los eventos entrantes y los guarda en la base de datos
func (m *Monitor) processEvents() {
	defer m.wg.Done()
	for {
		select {
		case ev := <-m.eventChan:
			m.log.Debug("FileMonitor: evento recibido: %s - %s", ev.Path, ev.EventType)

			// Guardar localmente
			if err := m.store.SaveFileEvent(ev); err != nil {
				m.log.Error("FileMonitor: error guardando evento local: %v", err)
			} else {
				m.log.Debug("FileMonitor: evento guardado localmente: %s", ev.Path)
			}

			// Determinar si el archivo es conocido como sensible (por inventario previo)
			var knownItem *audit.SensitiveInventoryItem
			if m.store != nil {
				if item, err := m.store.GetSensitiveInventoryByPath(ev.Path); err == nil && item != nil {
					knownItem = item
				}
			}

			// Decidir si el evento debe enviarse al panel
			sendEvent := false
			reason := ""

			// 1. Archivo ya conocido como sensible en el inventario
			if knownItem != nil && knownItem.Sensitive {
				sendEvent = true
				ev.Sensitive = true
				ev.PersonalData = knownItem.PersonalData
				reason = "inventario sensible"
			}

			// 2. Nombre/ruta contiene datos críticos/sensibles
			if !sendEvent && matchesSensitiveKeywords(ev.Path) {
				sendEvent = true
				ev.Sensitive = true
				reason = "ruta sensible"
			}

			// 3. Extensión crítica + evento crítico (copia/mover/borrar)
			if !sendEvent && isCriticalEvent(ev) && hasCriticalExtension(ev.Path) {
				sendEvent = true
				ev.Sensitive = true
				reason = "archivo crítico con operación crítica"
			}

			// Enviar inmediatamente si cumple criterios
			if sendEvent {
				m.wsClient.SendFileEvent(ev)
				m.log.Info("FileMonitor: evento enviado al panel: %s (%s) - motivo: %s", ev.Path, ev.EventType, reason)
			}

			// Si es scaneable, analizar en background
			if isScannableFile(ev.Path) {
				m.log.Debug("FileMonitor: archivo scaneable, iniciando análisis: %s", ev.Path)
				go m.scanFileAndReport(ev, knownItem != nil)
			}

			// Logs de eventos críticos (solo si es sensible o crítico)
			if ev.Sensitive && (ev.EventType == "copy" || ev.EventType == "delete" || ev.EventType == "move") {
				m.log.Warn("Archivo crítico/sensible %s: %s por %s (PID %d)", ev.Path, ev.EventType, ev.ProcessName, ev.PID)
			}

			// Si es un archivo sensible conocido, marcar como modificado en inventario
			if m.store != nil {
				if err := m.store.UpdateInventoryOnFileEvent(ev); err != nil {
					m.log.Debug("FileMonitor: error actualizando inventario: %v", err)
				}
			}

		case <-m.ctx.Done():
			m.log.Info("FileMonitor: deteniendo procesamiento de eventos")
			return
		}
	}
}

// matchesSensitiveKeywords detecta si el nombre o ruta contiene palabras clave sensibles/críticas
func matchesSensitiveKeywords(path string) bool {
	sensitiveKeywords := []string{
		"password", "passwd", "contrasena", "contraseña", "clave", "secret", "secrets",
		"token", "api_key", "apikey", "private", "confidencial", "confidential", "sensible",
		"dni", "rut", "nie", "pasaporte", "passport", "tarjeta", "credito", "credit",
		"debito", "debit", "banco", "bank", "iban", "swift", "salud", "medica", "historial",
		"nomina", "nominas", "payroll", "empleado", "cliente", "clientes", "customer",
		"facturacion", "factura", "invoice", "impuestos", "declaracion", "renta", "tax",
		"seguro", "poliza", "judicial", "denuncia", "abogado", "legal", "juicio",
		"licitacion", "contrato", "contract", "patente", "proyecto", "investigacion",
		"backup", "bak", "copia", "dump", "export", "wallet", "crypto", "bitcoin",
		"credential", "credencial", "auth", "login", "session",
	}
	lower := strings.ToLower(path)
	for _, kw := range sensitiveKeywords {
		if strings.Contains(lower, kw) {
			return true
		}
	}
	return false
}

// isCriticalEvent devuelve true para eventos que indican exfiltración o manipulación crítica
func isCriticalEvent(ev audit.FileEvent) bool {
	return ev.EventType == "copy" || ev.EventType == "delete" || ev.EventType == "move" || ev.EventType == "rename"
}

// hasCriticalExtension detecta extensiones de archivos que suelen contener datos críticos
func hasCriticalExtension(path string) bool {
	ext := strings.ToLower(filepath.Ext(path))
	criticalExts := map[string]bool{
		".xlsx": true, ".xls": true, ".csv": true, ".txt": true,
		".db": true, ".mdb": true, ".accdb": true, ".sqlite": true, ".sql": true,
		".bak": true, ".backup": true, ".zip": true, ".7z": true, ".rar": true, ".tar": true, ".gz": true,
		".pdf": true, ".doc": true, ".docx": true, ".odt": true, ".rtf": true,
		".pem": true, ".crt": true, ".key": true, ".pfx": true, ".p12": true,
		".env": true, ".ini": true, ".conf": true, ".config": true, ".yaml": true, ".yml": true,
	}
	return criticalExts[ext]
}

// scanFileAndReport escanea el archivo en busca de PII y guarda los resultados
func (m *Monitor) scanFileAndReport(ev audit.FileEvent, alreadySent bool) {
	// Verificar que el archivo aún existe
	if _, err := os.Stat(ev.Path); os.IsNotExist(err) {
		m.log.Debug("FileMonitor: archivo eliminado antes de escanear: %s", ev.Path)
		return
	}

	// Calcular hash si es necesario
	if ev.Hash == "" {
		hash, err := utils.HashFile(ev.Path)
		if err != nil {
			m.log.Warn("FileMonitor: error calculando hash de %s: %v", ev.Path, err)
		} else {
			ev.Hash = hash
			m.log.Debug("FileMonitor: hash calculado para %s: %s", ev.Path, hash[:8])
		}
	}

	// Escanear contenido
	result, err := scanner.ScanFile(ev.Path)
	if err != nil {
		m.log.Warn("FileMonitor: error escaneando %s: %v", ev.Path, err)
		return
	}

	if len(result) == 0 {
		return
	}

	// Actualizar evento con PII
	ev.PersonalData = result
	ev.Sensitive = hasSensitiveData(result)

	if !ev.Sensitive {
		return
	}

	// Guardar evento actualizado localmente
	if err := m.store.SaveFileEvent(ev); err != nil {
		m.log.Error("FileMonitor: error guardando evento con PII local: %v", err)
	} else {
		m.log.Debug("FileMonitor: evento con PII guardado localmente: %s", ev.Path)
	}

	// Enviar detección al backend
	m.wsClient.SendFileDetection(ev)

	// Enviar evento de archivo solo si aún no se envió por inventario conocido
	if !alreadySent {
		m.wsClient.SendFileEvent(ev)
	}

	m.log.Info("PII detectada en %s: %v", ev.Path, result)
}

// isScannableFile comprueba si el archivo puede contener PII según su extensión
func isScannableFile(path string) bool {
	ext := strings.ToLower(filepath.Ext(path))
	switch ext {
	case ".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx", ".ods", ".rtf":
		return true
	}
	return false
}

// hasSensitiveData determina si los datos personales incluyen categorías sensibles
func hasSensitiveData(data map[string][]string) bool {
	sensitiveCategories := map[string]bool{
		"salud":        true,
		"biometrico":   true,
		"bancario":     true,
		"credencial":   true,
		"genero":       true,
		"religion":     true,
		"politico":     true,
		"sindical":     true,
		"judicial":     true,
		"conyuge":      true,
		"hijos":        true,
		"seguro":       true,
		"foto":         true,
		"nacionalidad": true,
		"patrimonio":   true,
		"financiero":   true,
	}
	for _, cats := range data {
		for _, cat := range cats {
			if sensitiveCategories[cat] {
				return true
			}
		}
	}
	return false
}

// Stop detiene el monitor y espera a que terminen todas las goroutines
func (m *Monitor) Stop() {
	m.cancel()
	m.wg.Wait()
	close(m.eventChan)
	m.log.Info("FileMonitor: detenido")
}
