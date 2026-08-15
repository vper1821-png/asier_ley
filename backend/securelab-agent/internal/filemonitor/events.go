package filemonitor

import "securelab-agent/internal/audit"

// fileWatcher es la interfaz interna (definida en watcher_*.go)
type fileWatcher struct {
	dir    string
	events chan<- audit.FileEvent
	done   chan struct{}
}
