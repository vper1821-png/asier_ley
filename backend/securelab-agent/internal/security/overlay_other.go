//go:build !windows

package security

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

var (
	linuxOverlayActive bool
	linuxMu            sync.Mutex
	wallpaperCmd       string
	wallpaperSaved     string
	inputDevices       []string
)

func agentDir() string {
	exe, _ := os.Executable()
	return filepath.Dir(exe)
}

func overlayPidFile() string      { return filepath.Join(agentDir(), ".securelab-overlay-pid") }
func alarmPidFile() string        { return filepath.Join(agentDir(), ".securelab-alarm-pid") }
func wallpaperBackupFile() string { return filepath.Join(agentDir(), ".wallpaper-backup.txt") }
func msgPath() string             { return filepath.Join(agentDir(), ".lockdown-msg.txt") }

func execCommand(name string, args ...string) {
	cmd := exec.Command(name, args...)
	cmd.Stdout = nil
	cmd.Stderr = nil
	_ = cmd.Run()
}

func execCommandOutput(name string, args ...string) string {
	cmd := exec.Command(name, args...)
	out, err := cmd.Output()
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(out))
}

// ─── Linux Wallpaper ────────────────────────────────────────────────────────

func detectWallpaperTool() string {
	for _, tool := range []string{"gsettings", "feh", "nitrogen", "xfconf-query", "pcmanfm"} {
		if _, err := exec.LookPath(tool); err == nil {
			return tool
		}
	}
	return ""
}

func saveWallpaper() {
	wallpaperCmd = detectWallpaperTool()
	switch wallpaperCmd {
	case "gsettings":
		uri := execCommandOutput("gsettings", "get", "org.gnome.desktop.background", "picture-uri")
		wallpaperSaved = strings.Trim(uri, "'")
	case "feh":
		wallpaperSaved = execCommandOutput("cat", filepath.Join(os.Getenv("HOME"), ".fehbg"))
	case "xfconf-query":
		// XFCE
		wallpaperSaved = "xfce"
	default:
		wallpaperSaved = ""
	}
	if wallpaperSaved != "" {
		os.WriteFile(wallpaperBackupFile(), []byte(wallpaperSaved), 0600)
	}
}

func setLockdownWallpaper() {
	lockImg := filepath.Join(agentDir(), "lockdown-logo.png")
	if _, err := os.Stat(lockImg); err != nil {
		return
	}
	tool := detectWallpaperTool()
	switch tool {
	case "gsettings":
		execCommand("gsettings", "set", "org.gnome.desktop.background", "picture-uri", "file://"+lockImg)
		execCommand("gsettings", "set", "org.gnome.desktop.background", "picture-options", "centered")
		execCommand("gsettings", "set", "org.gnome.desktop.background", "primary-color", "#08080E")
	case "feh":
		execCommand("feh", "--bg-center", lockImg)
	case "nitrogen":
		execCommand("nitrogen", "--set-centered", "--save", lockImg)
	default:
		execCommand("feh", "--bg-center", lockImg)
	}
}

func restoreWallpaper() {
	data, err := os.ReadFile(wallpaperBackupFile())
	if err != nil {
		return
	}
	saved := strings.TrimSpace(string(data))
	if saved == "" || saved == "xfce" {
		return
	}
	tool := detectWallpaperTool()
	switch tool {
	case "gsettings":
		execCommand("gsettings", "set", "org.gnome.desktop.background", "picture-uri", saved)
	case "feh":
		if strings.HasPrefix(saved, "feh") {
			execCommand("sh", "-c", saved)
		}
	default:
		// Best effort
	}
}

// ─── Linux Input Blocking ──────────────────────────────────────────────────

func saveAndDisableInputDevices() {
	inputDevices = nil
	out := execCommandOutput("xinput", "list", "--name-only")
	if out == "" {
		return
	}
	for _, name := range strings.Split(out, "\n") {
		name = strings.TrimSpace(name)
		if name == "" || strings.Contains(name, "Virtual") {
			continue
		}
		// Check if it's a keyboard or pointer
		props := execCommandOutput("xinput", "list-props", name)
		if strings.Contains(props, "Device Node") {
			execCommand("xinput", "disable", name)
			inputDevices = append(inputDevices, name)
		}
	}
}

func restoreInputDevices() {
	for _, name := range inputDevices {
		execCommand("xinput", "enable", name)
	}
	inputDevices = nil
}

// ─── Linux VT / TTY Blocking ───────────────────────────────────────────────

func blockVirtualTerminals() {
	// Disable VT switching via Ctrl+Alt+F1-F12
	for i := 1; i <= 12; i++ {
		execCommand("chvt", fmt.Sprintf("%d", i))
	}
	execCommand("setleds", "+num")
}

// ─── Linux Fullscreen Overlay ──────────────────────────────────────────────

func startOverlay(message string) {
	if message == "" {
		message = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}
	lockImg := filepath.Join(agentDir(), "lockdown-logo.png")
	msgFile := msgPath()
	os.WriteFile(msgFile, []byte(message), 0600)

	// Try Python Tkinter first (most universal), then feh, then xmessage
	if _, err := exec.LookPath("python3"); err == nil {
		script := fmt.Sprintf(`import tkinter as tk, os
root = tk.Tk()
root.attributes('-fullscreen', True)
root.attributes('-topmost', True)
root.overrideredirect(True)
root.configure(bg='#08080E')
root.bind('<Key>', lambda e: 'break')
root.bind('<Button>', lambda e: 'break')
root.protocol('WM_DELETE_WINDOW', lambda: None)
msg = open('%s').read().strip()
tk.Label(root, text=msg, fg='white', bg='#08080E', font=('DejaVu Sans', 24, 'bold'), wraplength=800).pack(expand=True)
try:
    img = tk.PhotoImage(file='%s')
    tk.Label(root, image=img, bg='#08080E').pack(expand=True)
except: pass
sub = tk.Label(root, text='SecureLab - Cumplimiento Ley 21.719 | Equipo bloqueado por el DPO', fg='#94a3b8', bg='#08080E', font=('DejaVu Sans', 12)).pack(side='bottom', pady=20)
root.mainloop()`, msgFile, lockImg)
		cmd := exec.Command("python3", "-c", script)
		cmd.Env = append(os.Environ(), "DISPLAY=:0")
		if err := cmd.Start(); err == nil {
			os.WriteFile(overlayPidFile(), []byte(fmt.Sprintf("%d", cmd.Process.Pid)), 0600)
		}
	} else {
		// Fallback: feh fullscreen + xdotool to block input
		execCommand("feh", "-F", "-Z", "-x", "-Y", lockImg)
	}
}

func stopOverlay() {
	data, err := os.ReadFile(overlayPidFile())
	if err != nil {
		return
	}
	pid := strings.TrimSpace(string(data))
	if pid != "" {
		execCommand("kill", "-9", pid)
	}
	os.Remove(overlayPidFile())
	os.Remove(msgPath())
}

// ─── Lockdown / Unlock ─────────────────────────────────────────────────────

func applyLockdown(message string, silent bool) {
	linuxMu.Lock()
	defer linuxMu.Unlock()
	if linuxOverlayActive {
		return
	}

	if message == "" {
		message = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}

	// 1. Block all network traffic
	execCommand("iptables", "-P", "INPUT", "DROP")
	execCommand("iptables", "-P", "FORWARD", "DROP")
	execCommand("iptables", "-P", "OUTPUT", "DROP")

	// 2. Block input devices
	saveAndDisableInputDevices()

	// 3. Show fullscreen overlay
	startOverlay(message)

	// 4. Block shutdown/restart via systemd
	execCommand("systemctl", "mask", "--now", "reboot.target", "poweroff.target", "halt.target", "shutdown.target")

	// 5. Write lockdown state file for persistence
	stateData, _ := json.Marshal(map[string]interface{}{
		"message": message,
		"since":   time.Now().UTC().Format(time.RFC3339),
		"silent":  silent,
	})
	os.WriteFile(filepath.Join(agentDir(), ".securelab-lockdown-state"), stateData, 0600)

	linuxOverlayActive = true
}

func removeLockdown() {
	linuxMu.Lock()
	defer linuxMu.Unlock()
	if !linuxOverlayActive {
		return
	}

	// 1. Restore network
	execCommand("iptables", "-P", "INPUT", "ACCEPT")
	execCommand("iptables", "-P", "FORWARD", "ACCEPT")
	execCommand("iptables", "-P", "OUTPUT", "ACCEPT")

	// 2. Restore input devices
	restoreInputDevices()

	// 3. Stop overlay
	stopOverlay()

	// 4. Re-enable shutdown
	execCommand("systemctl", "unmask", "reboot.target", "poweroff.target", "halt.target", "shutdown.target")

	// 5. Cleanup state
	os.Remove(filepath.Join(agentDir(), ".securelab-lockdown-state"))
	os.Remove(wallpaperBackupFile())

	linuxOverlayActive = false
}

// ─── Audio / TTS (stubs for Linux) ─────────────────────────────────────────

func PlayAlarm() error { return nil }
func StopAlarm()       {}
func Speak(text string) {}

// ─── Session detection (Linux always has desktop when X11 is running) ──────

func IsInteractiveSession() bool {
	return os.Getenv("DISPLAY") != ""
}

func CanAccessDesktop() bool {
	return IsInteractiveSession()
}

func CurrentSessionID() uint32    { return 0 }
func IsServiceSession() bool      { return os.Getuid() == 0 }
func IsServiceProcess() bool      { return IsServiceSession() }
func SpawnOverlayForAllSessions() {}
func KillOverlayProcesses()       {}

func ensureOverlay() {
	if !IsLockdownActive() || linuxOverlayActive {
		return
	}
	st := readState()
	applyLockdown(st.Message, st.Silent)
}

// RunOverlayUI runs the visual lockdown in the current interactive session (Linux).
func RunOverlayUI() {
	st := readState()
	applyLockdown(st.Message, st.Silent)
}
