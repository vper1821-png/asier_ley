//go:build windows

package security

import (
	_ "embed"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

//go:embed assets/logo-nuevo.png
var logoPNG []byte

var (
	user32       = windows.NewLazySystemDLL("user32.dll")
	kernel32DLL  = windows.NewLazySystemDLL("kernel32.dll")
	gdi32        = windows.NewLazySystemDLL("gdi32.dll")

	procSetForegroundWindow     = user32.NewProc("SetForegroundWindow")
	procCreateWindowExW           = user32.NewProc("CreateWindowExW")
	procDefWindowProcW            = user32.NewProc("DefWindowProcW")
	procRegisterClassExW          = user32.NewProc("RegisterClassExW")
	procGetMessageW               = user32.NewProc("GetMessageW")
	procTranslateMessage          = user32.NewProc("TranslateMessage")
	procDispatchMessageW          = user32.NewProc("DispatchMessageW")
	procPostQuitMessage           = user32.NewProc("PostQuitMessage")
	procPostMessageW              = user32.NewProc("PostMessageW")
	procDestroyWindow             = user32.NewProc("DestroyWindow")
	procShowWindow                = user32.NewProc("ShowWindow")
	procSetWindowPos              = user32.NewProc("SetWindowPos")
	procGetSystemMetrics          = user32.NewProc("GetSystemMetrics")
	procSetWindowsHookExW         = user32.NewProc("SetWindowsHookExW")
	procUnhookWindowsHookEx       = user32.NewProc("UnhookWindowsHookEx")
	procCallNextHookEx            = user32.NewProc("CallNextHookEx")
	procBlockInput                = user32.NewProc("BlockInput")
	procSystemParametersInfoW     = user32.NewProc("SystemParametersInfoW")
	procCreateSolidBrush          = gdi32.NewProc("CreateSolidBrush")
	procShutdownBlockReasonCreate = user32.NewProc("ShutdownBlockReasonCreate")
	procShutdownBlockReasonDestroy = user32.NewProc("ShutdownBlockReasonDestroy")
	procWTSGetActiveConsoleSessionId = kernel32DLL.NewProc("WTSGetActiveConsoleSessionId")
	procOpenDesktopW              = user32.NewProc("OpenDesktopW")
	procCloseDesktop              = user32.NewProc("CloseDesktop")
)

const (
	WH_KEYBOARD_LL     = 13
	WH_MOUSE_LL        = 14
	WM_CLOSE           = 0x0010
	WM_QUIT            = 0x0012
	WM_DESTROY         = 0x0002
	WM_QUERYENDSESSION = 0x0011
	WM_ENDSESSION      = 0x0016
	WM_KEYDOWN         = 0x0100
	WM_SYSKEYDOWN      = 0x0104
	WM_SYSCOMMAND      = 0x0112
	SC_CLOSE           = 0xF060
	SC_SCREENSAVE      = 0xF140
	SC_MONITORPOWER    = 0xF170
	SM_CXSCREEN        = 0
	SM_CYSCREEN        = 1
	SM_CXVIRTUALSCREEN = 78
	SM_CYVIRTUALSCREEN = 79
	HWND_TOPMOST       = ^uintptr(0)
	SWP_NOMOVE         = 0x0002
	SWP_NOSIZE         = 0x0001
	SWP_SHOWWINDOW     = 0x0040
	WS_EX_TOPMOST      = 0x00000008
	WS_EX_TOOLWINDOW   = 0x00000080
	WS_POPUP           = 0x80000000
	WS_VISIBLE         = 0x10000000
	WS_CLIPCHILDREN    = 0x02000000
	CS_HREDRAW         = 0x0002
	CS_VREDRAW         = 0x0001
	SPI_SETDESKWALLPAPER = 0x0014
	SPI_GETDESKWALLPAPER = 0x0073
	SPIF_UPDATEINIFILE    = 0x0001
	SPIF_SENDCHANGE       = 0x0002
	SW_SHOWMAXIMIZED    = 3
)

type WNDCLASSEXW struct {
	CbSize        uint32
	Style         uint32
	LpfnWndProc   uintptr
	CbClsExtra    int32
	CbWndExtra    int32
	HInstance     uintptr
	HIcon         uintptr
	HCursor       uintptr
	HbrBackground uintptr
	LpszMenuName  *uint16
	LpszClassName *uint16
	HIconSm       uintptr
}

type MSG struct {
	Hwnd    uintptr
	Message uint32
	WParam  uintptr
	LParam  uintptr
	Time    uint32
	Pt      struct{ X, Y int32 }
}

var (
	overlayMu          sync.Mutex
	keyboardHookH      uintptr
	mouseHookH         uintptr
	overlayHWND        uintptr
	overlayActive      bool
	wallpaperOrig      string
	wallpaperOrigStyle string
	lockdownMsgText    string
)

func agentDir() string {
	exe, _ := os.Executable()
	return filepath.Dir(exe)
}

func overlayPidFile() string      { return filepath.Join(agentDir(), ".securelab-overlay-pid") }
func alarmPidFile() string        { return filepath.Join(agentDir(), ".securelab-alarm-pid") }
func logoPath() string            { return filepath.Join(agentDir(), "lockdown-logo.png") }
func alarmPath() string           { return filepath.Join(agentDir(), "alarm.wav") }
func msgPath() string             { return filepath.Join(agentDir(), ".lockdown-msg.txt") }
func wallpaperBackupFile() string { return filepath.Join(agentDir(), ".wallpaper-backup.txt") }

func ensureAssets() {
	if _, err := os.Stat(logoPath()); err != nil {
		_ = os.WriteFile(logoPath(), logoPNG, 0644)
	}
}

func init() {
	ensureAssets()
}

func syscallUTF16Ptr(s string) *uint16 {
	p, _ := syscall.UTF16PtrFromString(s)
	return p
}

// ─── Wallpaper Save / Set / Restore ──────────────────────────────────────────

func saveWallpaper() {
	k, err := registry.OpenKey(registry.CURRENT_USER, `Control Panel\Desktop`, registry.QUERY_VALUE)
	if err != nil {
		return
	}
	defer k.Close()
	wp, _, _ := k.GetStringValue("WallPaper")
	ws, _, _ := k.GetStringValue("WallpaperStyle")
	wallpaperOrig = wp
	wallpaperOrigStyle = ws
	backup := fmt.Sprintf("%s|%s", wp, ws)
	_ = os.WriteFile(wallpaperBackupFile(), []byte(backup), 0600)
}

func setLockdownWallpaper() {
	bmpPath := filepath.Join(agentDir(), "lockdown-wallpaper.bmp")
	if _, err := os.Stat(bmpPath); err != nil {
		_ = os.WriteFile(bmpPath, logoPNG, 0644)
	}
	pathPtr := syscallUTF16Ptr(bmpPath)
	_, _, _ = procSystemParametersInfoW.Call(SPI_SETDESKWALLPAPER, 0, uintptr(unsafe.Pointer(pathPtr)), SPIF_UPDATEINIFILE|SPIF_SENDCHANGE)
}

func restoreWallpaper() {
	if wallpaperOrig == "" {
		data, err := os.ReadFile(wallpaperBackupFile())
		if err == nil {
			parts := strings.SplitN(string(data), "|", 2)
			if len(parts) > 0 && parts[0] != "" {
				wallpaperOrig = parts[0]
			}
			if len(parts) > 1 {
				wallpaperOrigStyle = parts[1]
			}
		}
	}
	if wallpaperOrig == "" {
		return
	}
	pathPtr := syscallUTF16Ptr(wallpaperOrig)
	_, _, _ = procSystemParametersInfoW.Call(SPI_SETDESKWALLPAPER, 0, uintptr(unsafe.Pointer(pathPtr)), SPIF_UPDATEINIFILE|SPIF_SENDCHANGE)
	k, err := registry.OpenKey(registry.CURRENT_USER, `Control Panel\Desktop`, registry.SET_VALUE)
	if err == nil {
		defer k.Close()
		if wallpaperOrigStyle != "" {
			_ = k.SetStringValue("WallpaperStyle", wallpaperOrigStyle)
		}
	}
}

// ─── System Hardening via Registry ──────────────────────────────────────────

func applySystemHardening() {
	k, err := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Policies\System`, registry.SET_VALUE)
	if err == nil {
		_ = k.SetDWordValue("DisableTaskMgr", 1)
		_ = k.Close()
	}
	k2, err2 := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Policies\Explorer`, registry.SET_VALUE)
	if err2 == nil {
		_ = k2.SetDWordValue("NoLogoff", 1)
		_ = k2.SetDWordValue("NoClose", 1)
		_ = k2.Close()
	}
}

func removeSystemHardening() {
	k, err := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Policies\System`, registry.SET_VALUE)
	if err == nil {
		_ = k.DeleteValue("DisableTaskMgr")
		_ = k.Close()
	}
	k2, err2 := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Policies\Explorer`, registry.SET_VALUE)
	if err2 == nil {
		_ = k2.DeleteValue("NoLogoff")
		_ = k2.DeleteValue("NoClose")
		_ = k2.Close()
	}
}

// ─── Keyboard / Mouse Hooks ─────────────────────────────────────────────────

func keyboardHookCallback(nCode int32, wParam uintptr, lParam uintptr) uintptr {
	if nCode >= 0 {
		return 1
	}
	ret, _, _ := procCallNextHookEx.Call(keyboardHookH, uintptr(nCode), wParam, lParam)
	return ret
}

func mouseHookCallback(nCode int32, wParam uintptr, lParam uintptr) uintptr {
	if nCode >= 0 {
		return 1
	}
	ret, _, _ := procCallNextHookEx.Call(mouseHookH, uintptr(nCode), wParam, lParam)
	return ret
}

func installHooks() {
	kbCb := syscall.NewCallback(keyboardHookCallback)
	msCb := syscall.NewCallback(mouseHookCallback)
	ret, _, _ := procSetWindowsHookExW.Call(WH_KEYBOARD_LL, kbCb, 0, 0)
	keyboardHookH = ret
	ret2, _, _ := procSetWindowsHookExW.Call(WH_MOUSE_LL, msCb, 0, 0)
	mouseHookH = ret2
}

func removeHooks() {
	if keyboardHookH != 0 {
		_, _, _ = procUnhookWindowsHookEx.Call(keyboardHookH)
		keyboardHookH = 0
	}
	if mouseHookH != 0 {
		_, _, _ = procUnhookWindowsHookEx.Call(mouseHookH)
		mouseHookH = 0
	}
}

// ─── Session Detection ──────────────────────────────────────────────────────

func IsInteractiveSession() bool {
	defer func() { _ = recover() }()

	// Current process in session 0 -> not interactive
	if CurrentSessionID() == 0 {
		return false
	}

	// The active console session is interactive
	if procWTSGetActiveConsoleSessionId.Find() == nil {
		ret, _, _ := procWTSGetActiveConsoleSessionId.Call()
		if sid := uint32(ret); sid > 0 && sid != 0xFFFFFFFF {
			return true
		}
	}

	// We can open the user Default desktop -> interactive
	if procOpenDesktopW.Find() == nil && procCloseDesktop.Find() == nil {
		deskName, err := syscall.UTF16PtrFromString("Default")
		if err == nil {
			const DESKTOP_READOBJECTS = 0x0001
			dh, _, _ := procOpenDesktopW.Call(uintptr(unsafe.Pointer(deskName)), 0, 0, DESKTOP_READOBJECTS)
			if dh != 0 {
				_, _, _ = procCloseDesktop.Call(dh)
				return true
			}
		}
	}

	return false
}

func CanAccessDesktop() bool {
	return IsInteractiveSession()
}

// ─── Fullscreen Overlay Window ──────────────────────────────────────────────

func windowCallback(hwnd uintptr, msg uint32, wParam uintptr, lParam uintptr) uintptr {
	switch msg {
	case WM_CLOSE, WM_QUIT:
		return 0
	case WM_DESTROY:
		_, _, _ = procPostQuitMessage.Call(0)
		return 0
	case WM_QUERYENDSESSION:
		return 0
	case WM_ENDSESSION:
		return 0
	case WM_KEYDOWN, WM_SYSKEYDOWN:
		return 0
	case WM_SYSCOMMAND:
		cmd := wParam & 0xFFF0
		if cmd == SC_CLOSE || cmd == SC_SCREENSAVE || cmd == SC_MONITORPOWER {
			return 0
		}
		fallthrough
	default:
		ret, _, _ := procDefWindowProcW.Call(hwnd, uintptr(msg), wParam, lParam)
		return ret
	}
}

func createOverlayWindow() uintptr {
	className := syscallUTF16Ptr("SecureLabLockdownOv")
	windowName := syscallUTF16Ptr("SecureLab - Equipo Bloqueado")

	var wc WNDCLASSEXW
	wc.CbSize = uint32(unsafe.Sizeof(wc))
	wc.Style = CS_HREDRAW | CS_VREDRAW
	wc.LpfnWndProc = syscall.NewCallback(windowCallback)
	wc.HInstance = 0
	wc.HbrBackground, _, _ = procCreateSolidBrush.Call(0x0008080E)
	wc.LpszClassName = className
	_, _, _ = procRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))

	cx, _, _ := procGetSystemMetrics.Call(SM_CXSCREEN)
	cy, _, _ := procGetSystemMetrics.Call(SM_CYSCREEN)
	if cx == 0 {
		cx, _, _ = procGetSystemMetrics.Call(SM_CXVIRTUALSCREEN)
		cy, _, _ = procGetSystemMetrics.Call(SM_CYVIRTUALSCREEN)
	}

	hwnd, _, _ := procCreateWindowExW.Call(
		WS_EX_TOPMOST|WS_EX_TOOLWINDOW,
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(windowName)),
		WS_POPUP|WS_VISIBLE|WS_CLIPCHILDREN,
		0, 0, cx, cy,
		0, 0, 0, 0,
	)
	if hwnd != 0 {
		_, _, _ = procSetWindowPos.Call(hwnd, HWND_TOPMOST, 0, 0, 0, 0, SWP_NOMOVE|SWP_NOSIZE|SWP_SHOWWINDOW)
		_, _, _ = procShowWindow.Call(hwnd, SW_SHOWMAXIMIZED)
	}
	return hwnd
}

// renderMessageDC draws the message text on the overlay window.
func renderMessageDC(hwnd uintptr) {
	if lockdownMsgText == "" {
		return
	}
	// Simplified: create a static label over the window.
	className := syscallUTF16Ptr("STATIC")
	windowName := syscallUTF16Ptr(lockdownMsgText)
	_, _, _ = user32.NewProc("CreateWindowExW").Call(
		0,
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(windowName)),
		0x50000001,
		50, 50, 800, 100,
		hwnd, 0, 0, 0,
	)
}

// ─── Audio / TTS ────────────────────────────────────────────────────────────

func PlayAlarm() error {
	if !IsInteractiveSession() {
		return fmt.Errorf("alarma no disponible en modo servicio")
	}
	ensureAssets()
	StopAlarm()
	ps := "Add-Type -AssemblyName System.Media; $p=New-Object System.Media.SoundPlayer('" + alarmPath() + "'); $p.PlayLooping(); while ($true) { Start-Sleep -Seconds 1 }"
	cmd := exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command", ps)
	cmd.SysProcAttr = hiddenProcAttr()
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("no se pudo iniciar alarma: %w", err)
	}
	_ = os.WriteFile(alarmPidFile(), []byte(strconv.Itoa(cmd.Process.Pid)), 0600)
	setMaxVolume()
	return nil
}

func StopAlarm() {
	data, err := os.ReadFile(alarmPidFile())
	if err != nil {
		return
	}
	_ = exec.Command("taskkill", "/F", "/T", "/PID", string(data)).Run()
	_ = os.Remove(alarmPidFile())
}

func Speak(text string) {
	if text == "" || !IsInteractiveSession() {
		return
	}
	setMaxVolume()
	_ = exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command",
		"Add-Type -AssemblyName System.Speech; $s=New-Object System.Speech.Synthesis.SpeechSynthesizer; $s.Volume=100; $s.Rate=0; $s.Speak('"+text+"')",
	).Start()
}

func setMaxVolume() {
	_ = exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command",
		"Add-Type -TypeDefinition 'using System.Runtime.InteropServices; public class SV { [DllImport(\"winmm.dll\")] public static extern int waveOutSetVolume(IntPtr h, uint v); }'; [SV]::waveOutSetVolume([IntPtr]::Zero, 0xFFFFFFFF)",
	).Run()
}

func hiddenProcAttr() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
}

// ─── UI Process ─────────────────────────────────────────────────────────────

// RunOverlayUI runs the visual lockdown in the current interactive session.
// It is called by the securelab-agent.exe --overlay-ui child process.
func RunOverlayUI() {
	runtime.LockOSThread()

	// Re-read state from file
	st := readState()
	if st.Message == "" {
		st.Message = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}
	lockdownMsgText = st.Message

	// Apply hardening and wallpaper
	saveWallpaper()
	setLockdownWallpaper()
	applySystemHardening()

	// Audio feedback
	if !st.Silent {
		_ = PlayAlarm()
		Speak(st.Message)
	}

	// Block input and start overlay
	_, _, _ = procBlockInput.Call(1)

	overlayMu.Lock()
	overlayActive = true
	installHooks()
	hwnd := createOverlayWindow()
	overlayHWND = hwnd
	if hwnd != 0 {
		_, _, _ = procSetForegroundWindow.Call(hwnd)
	}
	renderMessageDC(hwnd)
	overlayMu.Unlock()

	// Watch the state file: when it is removed, exit overlay.
	done := make(chan struct{})
	go func() {
		for {
			select {
			case <-done:
				return
			case <-time.After(500 * time.Millisecond):
				if !IsLockdownActive() {
					_, _, _ = procPostMessageW.Call(hwnd, WM_QUIT, 0, 0)
					return
				}
			}
		}
	}()

	var msg MSG
	for {
		ret, _, _ := procGetMessageW.Call(uintptr(unsafe.Pointer(&msg)), 0, 0, 0)
		if ret == 0 || ret == ^uintptr(0) {
			break
		}
		_, _, _ = procTranslateMessage.Call(uintptr(unsafe.Pointer(&msg)))
		_, _, _ = procDispatchMessageW.Call(uintptr(unsafe.Pointer(&msg)))
	}

	close(done)

	// Cleanup
	_, _, _ = procBlockInput.Call(0)
	removeHooks()
	if overlayHWND != 0 {
		_, _, _ = procDestroyWindow.Call(overlayHWND)
		overlayHWND = 0
	}
	removeSystemHardening()
	restoreWallpaper()
	StopAlarm()
	overlayActive = false
}

// ─── Service-side Lockdown / Unlock ─────────────────────────────────────────

func applyLockdown(message string, silent bool) {
	if overlayActive {
		return
	}
	overlayMu.Lock()
	defer overlayMu.Unlock()
	if overlayActive {
		return
	}

	lockdownMsgText = message
	if lockdownMsgText == "" {
		lockdownMsgText = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}

	if !IsServiceProcess() && IsInteractiveSession() {
		// Agent is running in a user session (e.g. manual run). Lock this session directly.
		overlayActive = true
		go RunOverlayUI()
		return
	}

	// Service in session 0: spawn overlay UI in every active user session.
	overlayActive = true
	go func() {
		// Wait a moment to ensure the state file is written.
		time.Sleep(100 * time.Millisecond)
		SpawnOverlayForAllSessions()
	}()
}

func removeLockdown() {
	overlayMu.Lock()
	defer overlayMu.Unlock()
	if !overlayActive {
		return
	}

	removeSystemHardening()
	restoreWallpaper()
	_, _, _ = procBlockInput.Call(0)

	if overlayHWND != 0 {
		_, _, _ = procDestroyWindow.Call(overlayHWND)
		_, _, _ = procPostQuitMessage.Call(0)
		overlayHWND = 0
	}

	removeHooks()
	KillOverlayProcesses()
	StopAlarm()
	_ = os.Remove(msgPath())
	_ = os.Remove(wallpaperBackupFile())
	overlayActive = false
}

// ensureOverlay re-spawns overlays en sesiones activas si es necesario.
func ensureOverlay() {
	if !IsLockdownActive() {
		return
	}
	SpawnOverlayForAllSessions()
}
