//go:build windows

package security

import (
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
)

const (
	CREATE_NEW_CONSOLE           = 0x00000010
	CREATE_UNICODE_ENVIRONMENT   = 0x00000400
	WTS_CURRENT_SERVER_HANDLE    = 0
)

// CurrentSessionID returns the Windows session ID of the current process.
func CurrentSessionID() uint32 {
	var sessionID uint32
	err := windows.ProcessIdToSessionId(uint32(os.Getpid()), &sessionID)
	if err != nil {
		return 0
	}
	return sessionID
}

// IsServiceSession returns true if the current process is in session 0.
func IsServiceSession() bool {
	return CurrentSessionID() == 0
}

// IsServiceProcess returns true if the current process is running as a service or in session 0.
func IsServiceProcess() bool {
	return IsServiceSession()
}

// EnumerateActiveUserSessions returns a list of session IDs that have a logged-on user
// and are active or connected (console or RDP).
func EnumerateActiveUserSessions() []uint32 {
	var sessions *windows.WTS_SESSION_INFO
	var count uint32
	err := windows.WTSEnumerateSessions(WTS_CURRENT_SERVER_HANDLE, 0, 1, &sessions, &count)
	if err != nil || sessions == nil {
		return nil
	}
	defer windows.WTSFreeMemory(uintptr(unsafe.Pointer(sessions)))

	// WTSEnumerateSessions returns a pointer to the first element of an array.
	// We cast it to a Go slice.
	slice := unsafe.Slice(sessions, count)

	var result []uint32
	for _, s := range slice {
		if s.SessionID == 0 {
			continue
		}
		switch s.State {
		case windows.WTSActive, windows.WTSConnected:
			result = append(result, s.SessionID)
		}
	}
	return result
}

func spawnOverlayInSession(sessionID uint32) error {
	var token windows.Token
	err := windows.WTSQueryUserToken(sessionID, &token)
	if err != nil {
		return fmt.Errorf("WTSQueryUserToken falló para sesión %d: %v", sessionID, err)
	}
	defer token.Close()

	exe, err := os.Executable()
	if err != nil {
		return err
	}

	var envBlock *uint16
	if err := windows.CreateEnvironmentBlock(&envBlock, token, false); err == nil && envBlock != nil {
		defer windows.DestroyEnvironmentBlock(envBlock)
	}

	cmdLine := fmt.Sprintf(`"%s" --overlay-ui`, exe)
	cmdPtr, err := syscall.UTF16PtrFromString(cmdLine)
	if err != nil {
		return err
	}
	exePtr, err := syscall.UTF16PtrFromString(exe)
	if err != nil {
		return err
	}

	// Pointer to the user desktop.
	desktopName, _ := syscall.UTF16PtrFromString("WinSta0\\Default")

	var si windows.StartupInfo
	si.Cb = uint32(unsafe.Sizeof(si))
	si.Desktop = desktopName
	si.Flags = 0
	si.ShowWindow = 0

	var pi windows.ProcessInformation

	err = windows.CreateProcessAsUser(
		token,
		exePtr,
		cmdPtr,
		nil,
		nil,
		false,
		CREATE_NEW_CONSOLE|CREATE_UNICODE_ENVIRONMENT,
		envBlock,
		nil,
		&si,
		&pi,
	)
	if err != nil {
		return fmt.Errorf("CreateProcessAsUser falló: %v", err)
	}

	_ = windows.CloseHandle(pi.Thread)
	_ = windows.CloseHandle(pi.Process)

	recordOverlayProcess(uint32(pi.ProcessId))
	return nil
}

func overlayPidsFile() string {
	return filepath.Join(agentDir(), ".securelab-overlay-pids")
}

func recordOverlayProcess(pid uint32) {
	f, err := os.OpenFile(overlayPidsFile(), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0600)
	if err != nil {
		return
	}
	defer f.Close()
	fmt.Fprintln(f, pid)
}

// KillOverlayProcesses kills all overlay child processes launched by the service.
func KillOverlayProcesses() {
	data, err := os.ReadFile(overlayPidsFile())
	if err != nil {
		return
	}
	_ = os.Remove(overlayPidsFile())

	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		pid, err := strconv.Atoi(line)
		if err != nil {
			continue
		}
		h, err := windows.OpenProcess(windows.PROCESS_TERMINATE, false, uint32(pid))
		if err == nil {
			_ = windows.TerminateProcess(h, 0)
			_ = windows.CloseHandle(h)
		}
	}
}

// SpawnOverlayForAllSessions spawns the overlay process in every active user session.
func SpawnOverlayForAllSessions() {
	sessions := EnumerateActiveUserSessions()
	if len(sessions) == 0 {
		// Fallback to active console session
		if sid := windows.WTSGetActiveConsoleSessionId(); sid != 0xFFFFFFFF && sid != 0 {
			sessions = []uint32{sid}
		}
	}
	for _, sid := range sessions {
		if err := spawnOverlayInSession(sid); err != nil {
			// Silently ignore sessions that cannot be locked (e.g. disconnected RDP)
		}
	}
}
