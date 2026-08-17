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

			// Enviar por WebSocket
			m.wsClient.SendFileEvent(ev)

			// Si es scaneable, analizar en background
			if isScannableFile(ev.Path) {
				m.log.Debug("FileMonitor: archivo scaneable, iniciando análisis: %s", ev.Path)
				go m.scanFileAndReport(ev)
			}

			// Logs de eventos críticos
			if ev.EventType == "copy" || ev.EventType == "delete" || ev.EventType == "move" {
				m.log.Warn("File %s: %s by %s (PID %d)", ev.Path, ev.EventType, ev.ProcessName, ev.PID)
			}

		case <-m.ctx.Done():
			m.log.Info("FileMonitor: deteniendo procesamiento de eventos")
			return
		}
	}
}

// scanFileAndReport escanea el archivo en busca de PII y guarda los resultados
func (m *Monitor) scanFileAndReport(ev audit.FileEvent) {
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

	// Guardar evento actualizado localmente
	if err := m.store.SaveFileEvent(ev); err != nil {
		m.log.Error("FileMonitor: error guardando evento con PII local: %v", err)
	} else {
		m.log.Debug("FileMonitor: evento con PII guardado localmente: %s", ev.Path)
	}

	// Enviar detección al backend
	m.wsClient.SendFileDetection(ev)

	m.log.Info("PII detectada en %s: %v", ev.Path, result)
}

// isScannableFile comprueba si el archivo puede contener PII según su extensión
func isScannableFile(path string) bool {
	ext := strings.ToLower(filepath.Ext(path))
	return ext == ".xlsx" || ext == ".xls" || ext == ".csv" || ext == ".txt"
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
