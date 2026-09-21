package filemonitor

import (
	"context"
	"os"
	"path/filepath"
	"runtime"
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

// Directorios del sistema que se deben excluir del monitoreo.
// IMPORTANTE: solo basenames que JAMÁS son usernames válidos.
var excludedDirs = map[string]bool{
	"appdata":                   true,
	"localappdata":              true,
	"roamingappdata":            true,
	"system volume information": true,
	"recycle.bin":               true,
	"$recycle.bin":              true,
	"system32":                  true,
	"syswow64":                  true,
	"windows":                   true,
	"program files":             true,
	"program files (x86)":       true,
	"program files (arm)":       true,
	"programdata":               true,
	"node_modules":              true,
	"__pycache__":               true,
	"$windows.~bt":              true,
	"$windows.~ws":              true,
	"$sysreset":                 true,
	"windows.old":               true,
}

func isExcludedDir(path string) bool {
	base := strings.ToLower(filepath.Base(path))
	return excludedDirs[base]
}

// getUserForFile intenta determinar el usuario dueño del archivo a partir
// del path, evitando mandar "unknown" o "DESKTOP-XXX$" cuando el agente
// corre como LocalSystem.
func getUserForFile(path string) string {
	// ── Windows: derivar de C:\Users\<user>\... ──
	if runtime.GOOS == "windows" {
		lower := strings.ToLower(path)
		if idx := strings.Index(lower, `\users\`); idx >= 0 {
			rest := path[idx+len(`\users\`):]
			if end := strings.IndexAny(rest, `\/`); end > 0 {
				name := rest[:end]
				lowName := strings.ToLower(name)
				// Excluir cuentas especiales del sistema
				if lowName != "public" &&
					lowName != "default" &&
					lowName != "default user" &&
					lowName != "all users" &&
					!strings.HasSuffix(name, "$") {
					return name
				}
			}
		}
	}

	// ── Unix: derivar de /home/<user>/ o /Users/<user>/ ──
	if runtime.GOOS != "windows" {
		for _, prefix := range []string{"/home/", "/Users/"} {
			if strings.HasPrefix(path, prefix) {
				rest := path[len(prefix):]
				if idx := strings.Index(rest, "/"); idx > 0 {
					return rest[:idx]
				}
			}
		}
	}

	// ── Fallback: variable de entorno ──
	if u := os.Getenv("USERNAME"); u != "" && u != "SYSTEM" && !strings.HasSuffix(u, "$") {
		return u
	}
	if u := os.Getenv("USER"); u != "" && u != "root" {
		return u
	}

	return "unknown"
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

	var addedCount int
	err = filepath.Walk(w.dir, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			w.log.Warn("FileWatcher: error accediendo a %s: %v", path, err)
			return nil
		}
		if info.IsDir() {
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
	w.log.Info("FileWatcher: %d directorios añadidos en %s", addedCount, w.dir)

	hostname, _ := os.Hostname()

	for {
		select {
		case event, ok := <-watcher.Events:
			if !ok {
				w.log.Info("FileWatcher: canal de eventos cerrado para %s", w.dir)
				return nil
			}

			// ── FIX: si se creó un subdirectorio, añadirlo al watcher ──
			if event.Op&fsnotify.Create == fsnotify.Create {
				if info, err := os.Stat(event.Name); err == nil && info.IsDir() {
					if !isExcludedDir(event.Name) {
						if err := watcher.Add(event.Name); err != nil {
							w.log.Warn("FileWatcher: no se pudo añadir subdir nuevo %s: %v", event.Name, err)
						} else {
							w.log.Debug("FileWatcher: subdir nuevo añadido: %s", event.Name)
						}
					}
				}
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
				User:        getUserForFile(event.Name),
				Hostname:    hostname,
				Extension:   strings.ToLower(filepath.Ext(event.Name)),
			}
			w.log.Debug("FileWatcher: evento %s en %s (user=%s)", evType, event.Name, ev.User)
			select {
			case w.events <- ev:
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
