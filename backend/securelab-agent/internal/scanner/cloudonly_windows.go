//go:build windows

package scanner

import (
	"os"
	"syscall"
)

const (
	fileAttributeOffline            = 0x00001000
	fileAttributeRecallOnDataAccess = 0x00400000
	fileAttributeRecallOnOpen       = 0x00040000
)

// IsCloudOnly detecta archivos placeholder de OneDrive / Dropbox / Google Drive.
// NO dispara la hidratación: solo lee metadata del sistema.
func IsCloudOnly(info os.FileInfo) bool {
	if info == nil {
		return false
	}
	data, ok := info.Sys().(*syscall.Win32FileAttributeData)
	if !ok {
		return false
	}
	attrs := data.FileAttributes
	return attrs&fileAttributeOffline != 0 ||
		attrs&fileAttributeRecallOnDataAccess != 0 ||
		attrs&fileAttributeRecallOnOpen != 0
}
