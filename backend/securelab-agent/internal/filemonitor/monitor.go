package filemonitor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/utils"
	"securelab-agent/internal/ws"
)

// scanDebounce es el tiempo de silencio requerido antes de procesar un archivo.
const scanDebounce = 2 * time.Second

type Monitor struct {
	ctx       context.Context
	cancel    context.CancelFunc
	wg        sync.WaitGroup
	readyWG   sync.WaitGroup // espera a que los watchers terminen el Walk inicial
	store     *audit.Store
	wsClient  *ws.Client
	log       *logger.Logger
	watchers  []*fileWatcher
	eventChan chan audit.FileEvent

	pending    map[string]*time.Timer
	pendingMu  sync.Mutex
	lastSent   map[string]string
	lastSentMu sync.RWMutex
}

func NewMonitor(store *audit.Store, wsClient *ws.Client, log *logger.Logger) *Monitor {
	ctx, cancel := context.WithCancel(context.Background())
	return &Monitor{
		ctx:       ctx,
		cancel:    cancel,
		store:     store,
		wsClient:  wsClient,
		log:       log,
		eventChan: make(chan audit.FileEvent, 10000),
		pending:   make(map[string]*time.Timer),
		lastSent:  make(map[string]string),
	}
}

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

func (m *Monitor) Start() {
	if len(m.watchers) == 0 {
		m.log.Warn("FileMonitor: no hay directorios válidos para vigilar")
		return
	}
	m.log.Info("FileMonitor: iniciando vigilancia sobre %d directorios", len(m.watchers))

	// Procesador de eventos PRIMERO — el canal se vacía desde el minuto 0
	m.wg.Add(1)
	go m.processEvents()

	// Watchers después; readyWG cuenta cuándo terminan de registrar subdirs
	for _, w := range m.watchers {
		m.wg.Add(1)
		m.readyWG.Add(1)
		go func(w *fileWatcher) {
			defer m.wg.Done()
			if err := w.watch(m.ctx, &m.readyWG); err != nil {
				m.log.Error("FileMonitor: error en watcher para %s: %v", w.dir, err)
			}
		}(w)
	}
}

// WaitReady bloquea hasta que todos los watchers terminaron su Walk inicial.
func (m *Monitor) WaitReady(timeout time.Duration) bool {
	if len(m.watchers) == 0 {
		return true
	}
	done := make(chan struct{})
	go func() {
		m.readyWG.Wait()
		close(done)
	}()
	select {
	case <-done:
		m.log.Info("FileMonitor: todos los watchers listos — el escáner puede arrancar")
		return true
	case <-time.After(timeout):
		m.log.Warn("FileMonitor: timeout (%v) esperando watchers — arrancando escáner igualmente", timeout)
		return false
	}
}

func (m *Monitor) AddSensitiveFilePaths(paths []string) {
	for _, p := range paths {
		found := false
		for _, w := range m.watchers {
			if strings.HasPrefix(p, w.dir) {
				found = true
				break
			}
		}
		if !found {
			dir := filepath.Dir(p)
			if info, err := os.Stat(dir); err == nil && info.IsDir() {
				m.WatchDirectories([]string{dir})
				m.log.Info("🔴 Monitoreo prioritario activado para: %s (dir: %s)", p, dir)
			}
		}
	}
}

func (m *Monitor) processEvents() {
	defer m.wg.Done()
	for {
		select {
		case ev := <-m.eventChan:
			m.log.Debug("FileMonitor: evento recibido: %s - %s", ev.Path, ev.EventType)

			if err := m.store.SaveFileEvent(ev); err != nil {
				m.log.Error("FileMonitor: error guardando evento local: %v", err)
			} else {
				m.log.Debug("FileMonitor: evento guardado localmente: %s", ev.Path)
			}

			// ── Manejo de DELETE ──
			if ev.EventType == "delete" {
				var deletedItem *audit.SensitiveInventoryItem
				if m.store != nil {
					if item, err := m.store.GetSensitiveInventoryByPath(ev.Path); err == nil {
						deletedItem = item
					}
				}

				if deletedItem != nil && deletedItem.Sensitive {
					ev.Sensitive = true
					ev.PersonalData = deletedItem.PersonalData
					if ev.Hash == "" {
						ev.Hash = deletedItem.Hash
					}
					m.wsClient.SendFileDeleted(ev)
					m.log.Info("🗑️  Archivo sensible eliminado, notificando backend: %s (hash: %s)",
						ev.Path, shortHash(ev.Hash))
				} else {
					m.log.Debug("FileMonitor: archivo eliminado (sin PII registrada): %s", ev.Path)
				}

				m.forgetHash(ev.Path)

				if m.store != nil {
					_ = m.store.UpdateInventoryOnFileEvent(ev)
				}
				continue
			}

			var knownItem *audit.SensitiveInventoryItem
			if m.store != nil {
				if item, err := m.store.GetSensitiveInventoryByPath(ev.Path); err == nil && item != nil {
					knownItem = item
				}
			}

			sendEvent := false
			reason := ""

			if knownItem != nil && knownItem.Sensitive {
				sendEvent = true
				ev.Sensitive = true
				ev.PersonalData = knownItem.PersonalData
				reason = "inventario sensible"
			}

			if !sendEvent && matchesSensitiveKeywords(ev.Path) {
				sendEvent = true
				ev.Sensitive = true
				reason = "ruta sensible"
			}

			if !sendEvent && isCriticalEvent(ev) && hasCriticalExtension(ev.Path) {
				sendEvent = true
				ev.Sensitive = true
				reason = "archivo crítico con operación crítica"
			}

			if sendEvent {
				m.wsClient.SendFileEvent(ev)
				m.log.Info("FileMonitor: evento enviado al panel: %s (%s) - motivo: %s", ev.Path, ev.EventType, reason)
			}

			if isScannableFile(ev.Path) {
				m.log.Debug("FileMonitor: agendando análisis con debounce: %s", ev.Path)
				m.scheduleScan(ev, knownItem != nil)
			}

			if ev.Sensitive && (ev.EventType == "copy" || ev.EventType == "delete" || ev.EventType == "move") {
				m.log.Warn("Archivo crítico/sensible %s: %s por %s (PID %d)", ev.Path, ev.EventType, ev.ProcessName, ev.PID)
			}

			if m.store != nil {
				if err := m.store.UpdateInventoryOnFileEvent(ev); err != nil {
					m.log.Debug("FileMonitor: error actualizando inventario: %v", err)
				}
			}

		case <-m.ctx.Done():
			m.log.Info("FileMonitor: deteniendo procesamiento de eventos")
			m.pendingMu.Lock()
			for _, t := range m.pending {
				t.Stop()
			}
			m.pending = make(map[string]*time.Timer)
			m.pendingMu.Unlock()
			return
		}
	}
}

// ═══════════════════════════════════════════════════════════════════════
// DEBOUNCE
// ═══════════════════════════════════════════════════════════════════════

func (m *Monitor) scheduleScan(ev audit.FileEvent, alreadySent bool) {
	path := ev.Path

	m.pendingMu.Lock()
	defer m.pendingMu.Unlock()

	if timer, exists := m.pending[path]; exists {
		timer.Stop()
	}

	evCopy := ev
	sent := alreadySent

	m.pending[path] = time.AfterFunc(scanDebounce, func() {
		m.pendingMu.Lock()
		delete(m.pending, path)
		m.pendingMu.Unlock()

		m.scanFileAndReport(evCopy, sent)
	})
}

// ═══════════════════════════════════════════════════════════════════════
// DEDUP
// ═══════════════════════════════════════════════════════════════════════

func (m *Monitor) wasAlreadySentWithHash(path, hash string) bool {
	if hash == "" {
		return false
	}
	m.lastSentMu.RLock()
	defer m.lastSentMu.RUnlock()
	return m.lastSent[path] == hash
}

func (m *Monitor) rememberHash(path, hash string) {
	if hash == "" {
		return
	}
	m.lastSentMu.Lock()
	m.lastSent[path] = hash
	m.lastSentMu.Unlock()
}

func (m *Monitor) forgetHash(path string) {
	m.lastSentMu.Lock()
	delete(m.lastSent, path)
	m.lastSentMu.Unlock()
}

// ═══════════════════════════════════════════════════════════════════════
// SCAN + REPORT
// ═══════════════════════════════════════════════════════════════════════

func (m *Monitor) scanFileAndReport(ev audit.FileEvent, alreadySent bool) {
	if _, err := os.Stat(ev.Path); os.IsNotExist(err) {
		m.forgetHash(ev.Path)
		m.log.Debug("FileMonitor: archivo eliminado antes de escanear: %s", ev.Path)
		return
	}

	if ev.Hash == "" {
		hash, err := utils.HashFile(ev.Path)
		if err != nil {
			m.log.Warn("FileMonitor: error calculando hash de %s: %v", ev.Path, err)
			return
		}
		ev.Hash = hash
		m.log.Debug("FileMonitor: hash calculado para %s: %s", ev.Path, shortHash(ev.Hash))
	}

	if m.wasAlreadySentWithHash(ev.Path, ev.Hash) {
		m.log.Debug("FileMonitor: hash sin cambios, omitiendo reenvío: %s", ev.Path)
		return
	}

	result, err := scanner.ScanFile(ev.Path)
	if err != nil {
		m.log.Warn("FileMonitor: error escaneando %s: %v", ev.Path, err)
		return
	}

	if len(result) == 0 {
		m.rememberHash(ev.Path, ev.Hash)
		return
	}

	ev.PersonalData = result
	ev.Sensitive = hasSensitiveData(result)

	if !ev.Sensitive {
		m.rememberHash(ev.Path, ev.Hash)
		return
	}

	if ev.RowCount == 0 {
		ev.RowCount = scanner.CountRows(ev.Path)
	}
	if ev.Hostname == "" {
		if h, err := os.Hostname(); err == nil {
			ev.Hostname = h
		}
	}
	if ev.Extension == "" {
		ev.Extension = strings.ToLower(filepath.Ext(ev.Path))
	}

	if err := m.store.SaveFileEvent(ev); err != nil {
		m.log.Error("FileMonitor: error guardando evento con PII local: %v", err)
	} else {
		m.log.Debug("FileMonitor: evento con PII guardado localmente: %s", ev.Path)
	}

	// Mantener el inventario actualizado en tiempo real (para RAT autofill).
	if m.store != nil {
		_ = m.store.UpdateInventoryFromEvent(ev)
	}

	m.wsClient.SendFileDetection(ev)

	if !alreadySent {
		m.wsClient.SendFileEvent(ev)
	}

	m.rememberHash(ev.Path, ev.Hash)

	m.log.Info("PII detectada en %s: %v (hash: %s, rows: %d)",
		ev.Path, result, shortHash(ev.Hash), ev.RowCount)
}

func shortHash(h string) string {
	if len(h) >= 8 {
		return h[:8] + "…"
	}
	return h
}

// ═══════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════

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

func isCriticalEvent(ev audit.FileEvent) bool {
	return ev.EventType == "copy" || ev.EventType == "delete" || ev.EventType == "move" || ev.EventType == "rename"
}

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

func isScannableFile(path string) bool {
	ext := strings.ToLower(filepath.Ext(path))
	switch ext {
	case ".xlsx", ".xls", ".csv", ".txt", ".json", ".xml", ".pdf", ".doc", ".docx", ".ods", ".rtf":
		return true
	}
	return false
}

func hasSensitiveData(data map[string][]string) bool {
	cats := make(map[string]bool)
	for _, list := range data {
		for _, c := range list {
			cats[c] = true
		}
	}
	return scanner.HasSensitiveData(cats)
}

func (m *Monitor) Stop() {
	m.cancel()
	m.wg.Wait()
	close(m.eventChan)
	m.log.Info("FileMonitor: detenido")
}
