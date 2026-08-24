package security

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync/atomic"
	"time"
)

const lockdownFlag = ".securelab-lockdown"

type lockdownState struct {
	Message  string `json:"message"`
	Since    string `json:"since"`
	UnlockAt int64  `json:"unlockAt,omitempty"`
	Silent   bool   `json:"silent,omitempty"`
}

func stateFilePath() string {
	exe, _ := os.Executable()
	return filepath.Join(filepath.Dir(exe), lockdownFlag)
}

func readState() lockdownState {
	var st lockdownState
	data, err := os.ReadFile(stateFilePath())
	if err == nil {
		json.Unmarshal(data, &st)
	}
	return st
}

func IsLockdownActive() bool {
	_, err := os.Stat(stateFilePath())
	return err == nil
}

func setLockdownState(message string, unlockAt int64, silent ...bool) {
	if message == "" {
		os.Remove(stateFilePath())
		return
	}
	isSilent := false
	if len(silent) > 0 {
		isSilent = silent[0]
	}
	st := lockdownState{Message: message, Since: time.Now().UTC().Format(time.RFC3339), UnlockAt: unlockAt, Silent: isSilent}
	data, _ := json.Marshal(st)
	os.WriteFile(stateFilePath(), data, 0600)
}

// Lockdown bloquea completamente el equipo: overlay a pantalla completa,
// bloqueo total de teclado/ratón, cambio de wallpaper, hardening del sistema.
func Lockdown(message string) {
	if message == "" {
		message = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}
	setLockdownState(message, 0, false)
	applyLockdown(message, false)
}

// LockdownSilent bloquea sin reproducir sonido ni TTS.
func LockdownSilent(message string) {
	if message == "" {
		message = "ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD"
	}
	setLockdownState(message, 0, true)
	applyLockdown(message, true)
}

// LockdownTimed bloquea el equipo durante N minutos y se desbloquea solo.
func LockdownTimed(message string, minutes int) {
	if minutes < 1 {
		minutes = 1
	}
	if message == "" {
		message = "EQUIPO BLOQUEADO TEMPORALMENTE POR SEGURIDAD"
	}
	unlockAt := time.Now().Add(time.Duration(minutes) * time.Minute).Unix()
	setLockdownState(message, unlockAt, false)
	applyLockdown(message, false)
	go func() {
		time.Sleep(time.Duration(minutes) * time.Minute)
		Unlock()
	}()
}

// LockdownTimedSilent bloquea temporalmente el equipo sin sonido.
func LockdownTimedSilent(message string, minutes int) {
	if minutes < 1 {
		minutes = 1
	}
	if message == "" {
		message = "EQUIPO BLOQUEADO TEMPORALMENTE POR SEGURIDAD"
	}
	unlockAt := time.Now().Add(time.Duration(minutes) * time.Minute).Unix()
	setLockdownState(message, unlockAt, true)
	applyLockdown(message, true)
	go func() {
		time.Sleep(time.Duration(minutes) * time.Minute)
		Unlock()
	}()
}

// Unlock restaura el equipo completamente.
func Unlock() {
	removeLockdown()
	setLockdownState("", 0)
}

// TimedLockPending indica si hay un bloqueo temporizado vigente.
func TimedLockPending() bool {
	if !IsLockdownActive() {
		return false
	}
	st := readState()
	return st.UnlockAt > 0 && time.Now().Unix() < st.UnlockAt
}

// ApplyLockdownIfFlagged re-aplica el bloqueo persistente al arrancar.
// Esto garantiza que el equipo permanezca bloqueado incluso después de reiniciar.
func ApplyLockdownIfFlagged() {
	if !IsLockdownActive() {
		return
	}
	st := readState()
	if st.UnlockAt > 0 && time.Now().Unix() >= st.UnlockAt {
		Unlock()
		return
	}
	applyLockdown(st.Message, st.Silent)
}

var monitorStarted int32

// StartLockdownMonitor inicia un goroutine que asegura que los overlays
// de bloqueo sigan activos tras un reinicio, y que se creen en nuevas sesiones.
func StartLockdownMonitor() {
	if !atomic.CompareAndSwapInt32(&monitorStarted, 0, 1) {
		return
	}
	go func() {
		for {
			time.Sleep(5 * time.Second)
			if !IsLockdownActive() {
				continue
			}
			st := readState()
			if st.UnlockAt > 0 && time.Now().Unix() >= st.UnlockAt {
				Unlock()
				continue
			}
			ensureOverlay()
		}
	}()
}

// ensureOverlay es implementado en overlay_windows.go / overlay_other.go
