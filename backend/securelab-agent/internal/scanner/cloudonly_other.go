//go:build !windows

package scanner

import "os"

// IsCloudOnly no aplica en sistemas no-Windows.
func IsCloudOnly(info os.FileInfo) bool { return false }
