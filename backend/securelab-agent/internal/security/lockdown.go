package security

import (
	"encoding/json"
	"os"
	"path/filepath"
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

func lockdownMessage() string {
	if !IsLockdownActive() {
		return ""
	}
	st := readState()
	if st.Message == "" {
		return "ESTE EQUIPO ESTÁ BLOQUEADO POR SEGURIDAD"
	}
	return st.Message
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

// Lockdown bloquea visualmente el equipo (overlay a pantalla completa,
// audio de alarma y mensaje hablado). NO corta la conexión con el
// servidor para poder desbloquearlo de forma remota.
func Lockdown(message string) {
	if message == "" {
		message = "ESTE EQUIPO ESTÁ BLOQUEADO POR SEGURIDAD"
	}
	applyLockdown(message, false)
	setLockdownState(message, 0, false)
}

// LockdownSilent bloquea visualmente el equipo sin reproducir sonido ni TTS.
func LockdownSilent(message string) {
	if message == "" {
		message = "ESTE EQUIPO ESTÁ BLOQUEADO POR SEGURIDAD"
	}
	applyLockdown(message, true)
	setLockdownState(message, 0, true)
}

// LockdownTimed bloquea el equipo durante N minutos y se desbloquea solo.
func LockdownTimed(message string, minutes int) {
	if minutes < 1 {
		minutes = 1
	}
	if message == "" {
		message = "EQUIPO BLOQUEADO TEMPORALMENTE POR SEGURIDAD"
	}
	applyLockdown(message, false)
	unlockAt := time.Now().Add(time.Duration(minutes) * time.Minute).Unix()
	setLockdownState(message, unlockAt, false)
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
	applyLockdown(message, true)
	unlockAt := time.Now().Add(time.Duration(minutes) * time.Minute).Unix()
	setLockdownState(message, unlockAt, true)
	go func() {
		time.Sleep(time.Duration(minutes) * time.Minute)
		Unlock()
	}()
}

// Unlock restaura el equipo y borra el estado persistente.
func Unlock() {
	removeLockdown()
	setLockdownState("", 0)
}

// TimedLockPending indica si hay un bloqueo temporizado vigente (aún no vence).
// El sync loop del servidor no debe forzar desbloqueo mientras esté activo.
func TimedLockPending() bool {
	if !IsLockdownActive() {
		return false
	}
	st := readState()
	return st.UnlockAt > 0 && time.Now().Unix() < st.UnlockAt
}

// ApplyLockdownIfFlagged re-aplica el bloqueo persistente al arrancar.
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
