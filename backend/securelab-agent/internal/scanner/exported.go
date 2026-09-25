package scanner

import "securelab-agent/internal/logger"

// GetDefaultScanDirectories expone el auto-descubrimiento de directorios
// para que el FileMonitor pueda usar los mismos que el escáner cuando
// cfg.FileWatchDirs está vacío.
func GetDefaultScanDirectories(log *logger.Logger) []string {
	return computeScanDirectories(log)
}
