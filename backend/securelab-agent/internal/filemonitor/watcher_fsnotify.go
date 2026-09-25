package filemonitor

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

	"github.com/fsnotify/fsnotify"
)

func newFileWatcher(dir string, eventChan chan audit.FileEvent, log *logger.Logger) *fileWatcher {
	return &fileWatcher{
		dir:    dir,
		events: eventChan,
		done:   make(chan struct{}),
		log:    log,
	}
}

// Directorios del sistema que se excluyen del monitoreo.
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
	"winsxs":                    true,
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
	".dropbox.cache":            true,
	".dropbox":                  true,
	".git":                      true,
	".svn":                      true,
	".hg":                       true,
}

// Basura generada por clientes de sync y temporales de Office.
var syncJunkMarkers = []string{
	"~$", "_$", ".~",
	".849c9593-",
	".tmp", ".temp", ".partial", ".crdownload", ".part",
	"desktop.ini", "thumbs.db", ".ds_store",
	".dropbox", "icon\r",
}

func isSyncJunk(name string) bool {
	base := strings.ToLower(filepath.Base(name))
	if base == "" || base == "." || base == ".." {
		return true
	}
	for _, m := range syncJunkMarkers {
		if strings.HasPrefix(base, m) || strings.HasSuffix(base, m) {
			return true
		}
	}
	for _, d := range []string{".git", ".svn", ".hg"} {
		if base == d {
			return true
		}
	}
	return false
}

func isExcludedDir(path string) bool {
	base := strings.ToLower(filepath.Base(path))
	return excludedDirs[base]
}

// getUserForFile determina el usuario dueño del archivo a partir del path.
func getUserForFile(path string) string {
	if runtime.GOOS == "windows" {
		lower := strings.ToLower(path)
		if idx := strings.Index(lower, `\users\`); idx >= 0 {
			rest := path[idx+len(`\users\`):]
			if end := strings.IndexAny(rest, `\/`); end > 0 {
				name := rest[:end]
				lowName := strings.ToLower(name)
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

	if u := os.Getenv("USERNAME"); u != "" && u != "SYSTEM" && !strings.HasSuffix(u, "$") {
		return u
	}
	if u := os.Getenv("USER"); u != "" && u != "root" {
		return u
	}
	return "unknown"
}

// watch arranca el event loop INMEDIATAMENTE y lanza el Walk en paralelo.
// readyWG se decrementa cuando el Walk termina (o si watch falla).
func (w *fileWatcher) watch(ctx context.Context, readyWG *sync.WaitGroup) error {
	var readyOnce sync.Once
	notifyReady := func() {
		if readyWG != nil {
			readyOnce.Do(func() { readyWG.Done() })
		}
	}
	defer notifyReady()

	w.log.Debug("FileWatcher: iniciando vigilancia en %s", w.dir)

	watcher, err := fsnotify.NewWatcher()
	if err != nil {
		w.log.Error("FileWatcher: error creando watcher en %s: %v", w.dir, err)
		return err
	}
	defer watcher.Close()

	// 1) Registrar la raíz AHORA → escuchamos desde el minuto 0
	if err := watcher.Add(w.dir); err != nil {
		w.log.Warn("FileWatcher: no se pudo añadir raíz %s: %v", w.dir, err)
	} else {
		w.log.Debug("FileWatcher: raíz registrada: %s", w.dir)
	}

	// 2) Walk en goroutine aparte — no bloquea el event loop
	go func() {
		defer notifyReady()
		addedCount := 1
		walkErr := filepath.Walk(w.dir, func(path string, info os.FileInfo, err error) error {
			if err != nil || info == nil || !info.IsDir() {
				return nil
			}
			if path == w.dir {
				return nil
			}
			if isExcludedDir(path) {
				return filepath.SkipDir
			}
			if err := watcher.Add(path); err != nil {
				w.log.Warn("FileWatcher: no se pudo añadir %s: %v", path, err)
			} else {
				addedCount++
			}
			return nil
		})
		if walkErr != nil {
			w.log.Error("FileWatcher: error en Walk para %s: %v", w.dir, walkErr)
		}
		w.log.Info("FileWatcher: %d directorios registrados en %s", addedCount, w.dir)
	}()

	hostname, _ := os.Hostname()

	// 3) EVENT LOOP — escucha desde el primer momento
	for {
		select {
		case event, ok := <-watcher.Events:
			if !ok {
				w.log.Info("FileWatcher: canal cerrado para %s", w.dir)
				return nil
			}

			if isSyncJunk(event.Name) {
				continue
			}

			// Subdirectorio nuevo → registrarlo y NO emitir evento de archivo
			if event.Op&fsnotify.Create == fsnotify.Create {
				if info, err := os.Stat(event.Name); err == nil && info.IsDir() {
					if !isExcludedDir(event.Name) {
						if err := watcher.Add(event.Name); err != nil {
							w.log.Warn("FileWatcher: no se pudo añadir subdir %s: %v", event.Name, err)
						} else {
							w.log.Debug("FileWatcher: subdir nuevo añadido: %s", event.Name)
						}
					}
					continue
				}
			}

			evType := mapEventType(event.Op)
			ev := audit.FileEvent{
				Timestamp:   time.Now(),
				Path:        event.Name,
				EventType:   evType,
				ProcessName: filepath.Base(os.Args[0]),
				PID:         os.Getpid(),
				User:        getUserForFile(event.Name),
				Hostname:    hostname,
				Extension:   strings.ToLower(filepath.Ext(event.Name)),
			}

			w.log.Debug("FileWatcher: evento %s en %s (user=%s)", evType, event.Name, ev.User)

			// Envío BLOQUEANTE con cancelación — nunca descarta en silencio
			select {
			case w.events <- ev:
			case <-ctx.Done():
				return nil
			case <-time.After(10 * time.Second):
				w.log.Warn("FileWatcher: timeout enviando evento (%s)", event.Name)
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
