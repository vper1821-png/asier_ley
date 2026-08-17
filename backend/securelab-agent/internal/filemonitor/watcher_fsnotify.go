package filemonitor

import (
	"context"
	"os"
	"path/filepath"
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

// watch inicia el monitoreo del directorio con fsnotify
func (w *fileWatcher) watch(ctx context.Context) error {
	w.log.Debug("FileWatcher: iniciando vigilancia en %s", w.dir)

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		w.log.Error("FileWatcher: error creando watcher en %s: %v", w.dir, err)
		return err
	}
	defer watcher.Close()

	// Añadir el directorio y todos sus subdirectorios recursivamente
	var addedCount int
	err = filepath.Walk(w.dir, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			w.log.Warn("FileWatcher: error accediendo a %s: %v", path, err)
			return nil // ignorar errores de permisos
		}
		if info.IsDir() {
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
	w.log.Info("FileWatcher: %d directorios añadidos en %s", addedCount, w.dir)

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
