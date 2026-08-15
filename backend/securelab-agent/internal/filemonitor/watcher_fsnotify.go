package filemonitor

import (
	"context"
	"os"
	"path/filepath"
	"time"

	"securelab-agent/internal/audit"

	"github.com/fsnotify/fsnotify"
)

// newFileWatcher crea un watcher usando fsnotify (multiplataforma)
func newFileWatcher(dir string, eventChan chan audit.FileEvent) *fileWatcher {
	return &fileWatcher{
		dir:    dir,
		events: eventChan,
		done:   make(chan struct{}),
	}
}

// watch inicia el monitoreo del directorio con fsnotify
func (w *fileWatcher) watch(ctx context.Context) error {
	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		return err
	}
	defer watcher.Close()

	// Añadir el directorio y todos sus subdirectorios recursivamente
	err = filepath.Walk(w.dir, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return nil // ignorar errores de permisos
		}
		if info.IsDir() {
			return watcher.Add(path)
		}
		return nil
	})
	if err != nil {
		return err
	}

	// Bucle principal de eventos
	for {
		select {
		case event, ok := <-watcher.Events:
			if !ok {
				return nil
			}
			// Mapear evento fsnotify a nuestros tipos
			evType := mapEventType(event.Op)
			// Obtener PID y nombre del proceso
			pid := os.Getpid()
			procName := filepath.Base(os.Args[0]) // nombre del ejecutable
			ev := audit.FileEvent{
				Timestamp:   time.Now(),
				Path:        event.Name,
				EventType:   evType,
				ProcessName: procName,
				PID:         pid,
				User:        os.Getenv("USERNAME"),
			}
			w.events <- ev
		case err, ok := <-watcher.Errors:
			if !ok {
				return nil
			}
			// Loggear el error (no podemos usar el logger aquí directamente)
			// Podríamos usar un logger global o simplemente ignorar
			_ = err // evitar warning de variable no usada
		case <-ctx.Done():
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
		return "chmod" // cambios de permisos (no crítico)
	default:
		return "modify"
	}
}
