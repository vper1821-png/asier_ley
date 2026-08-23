package filemonitor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"

	"github.com/fsnotify/fsnotify"
)

// newFileWatcher crea un watcher usando fsnotify con logger
func newFileWatcher(dir string, eventChan chan audit.FileEvent, log *logger.Logger) *fileWatcher {
	return &fileWatcher{
		dir:    dir,
		events: eventChan,
		done:   make(chan struct{}),
		log:    log,
	}
}

// Directorios del sistema que se deben excluir del monitoreo
var excludedDirs = map[string]bool{
	"appdata":           true,
	"localappdata":      true,
	"roamingappdata":    true,
	"temp":              true,
	"tmp":               true,
	"cache":             true,
	"caches":            true,
	".git":              true,
	".vs":               true,
	"node_modules":      true,
	"bin":               true,
	"obj":               true,
	"packages":          true,
	"packages.config":   true,
	"app_packages":      true,
	"__pycache__":       true,
	"venv":              true,
	".venv":             true,
	"env":               true,
	".env":              true,
	"system volume information": true,
	"recycle.bin":       true,
	"$recycle.bin":      true,
	"system32":          true,
	"syswow64":          true,
	"windows":           true,
	"program files":     true,
	"program files (x86)": true,
	"programdata":       true,
}

func isExcludedDir(path string) bool {
	base := strings.ToLower(filepath.Base(path))
	return excludedDirs[base]
}

// watch inicia el monitoreo del directorio con fsnotify
func (w *fileWatcher) watch(ctx context.Context) error {
	w.log.Debug("FileWatcher: iniciando vigilancia en %s", w.dir)

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		w.log.Error("FileWatcher: error creando watcher en %s: %v", w.dir, err)
		return err
	}
	defer watcher.Close()

	// Añadir el directorio y sus subdirectorios recursivamente, excluyendo directorios del sistema
	var addedCount int
	err = filepath.Walk(w.dir, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			w.log.Warn("FileWatcher: error accediendo a %s: %v", path, err)
			return nil // ignorar errores de permisos
		}
		if info.IsDir() {
			// Saltar directorios excluidos
			if isExcludedDir(path) {
				w.log.Debug("FileWatcher: excluyendo directorio del sistema: %s", path)
				return filepath.SkipDir
			}
			if err := watcher.Add(path); err != nil {
				w.log.Warn("FileWatcher: no se pudo añadir %s: %v", path, err)
			} else {
				addedCount++
			}
		}
		return nil
	})
	if err != nil {
		w.log.Error("FileWatcher: error en Walk para %s: %v", w.dir, err)
		return err
	}
	w.log.Info("FileWatcher: %d directorios añadidos en %s (excluyendo directorios del sistema)", addedCount, w.dir)

	// Bucle principal de eventos
	for {
		select {
		case event, ok := <-watcher.Events:
			if !ok {
				w.log.Info("FileWatcher: canal de eventos cerrado para %s", w.dir)
				return nil
			}
			evType := mapEventType(event.Op)
			pid := os.Getpid()
			procName := filepath.Base(os.Args[0])
			ev := audit.FileEvent{
				Timestamp:   time.Now(),
				Path:        event.Name,
				EventType:   evType,
				ProcessName: procName,
				PID:         pid,
				User:        os.Getenv("USERNAME"),
			}
			w.log.Debug("FileWatcher: evento %s en %s", evType, event.Name)
			select {
			case w.events <- ev:
				// enviado correctamente
			default:
				w.log.Warn("FileWatcher: canal de eventos lleno, descartando evento en %s", event.Name)
			}
		case err, ok := <-watcher.Errors:
			if !ok {
				return nil
			}
			w.log.Error("FileWatcher: error en %s: %v", w.dir, err)
		case <-ctx.Done():
			w.log.Info("FileWatcher: contexto cancelado para %s", w.dir)
			return nil
		}
	}
}

// mapEventType convierte eventos de fsnotify a nuestros tipos
func mapEventType(op fsnotify.Op) string {
	switch {
	case op&fsnotify.Create == fsnotify.Create:
		return "create"
	case op&fsnotify.Write == fsnotify.Write:
		return "modify"
	case op&fsnotify.Remove == fsnotify.Remove:
		return "delete"
	case op&fsnotify.Rename == fsnotify.Rename:
		return "move"
	case op&fsnotify.Chmod == fsnotify.Chmod:
		return "chmod"
	default:
		return "modify"
	}
}
