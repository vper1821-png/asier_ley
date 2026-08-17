package filemonitor

import (
	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
)

// fileWatcher representa un watcher para un directorio específico
type fileWatcher struct {
	dir    string
	events chan<- audit.FileEvent
	done   chan struct{}
	log    *logger.Logger // <-- NUEVO campo
}
