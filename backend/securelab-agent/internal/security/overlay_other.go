//go:build !windows

package security

import "os/exec"

// En plataformas no Windows el bloqueo se aplica con firewall (sin overlay).
// El parametro silent se ignora porque no hay sonido en este fallback.

func applyLockdown(message string, silent bool) {
	execCommand("iptables", "-P", "INPUT", "DROP")
	execCommand("iptables", "-P", "FORWARD", "DROP")
	execCommand("iptables", "-P", "OUTPUT", "DROP")
}

func removeLockdown() {
	execCommand("iptables", "-P", "INPUT", "ACCEPT")
	execCommand("iptables", "-P", "FORWARD", "ACCEPT")
	execCommand("iptables", "-P", "OUTPUT", "ACCEPT")
}

func PlayAlarm() error { return nil }
func StopAlarm() {}
func Speak(text string) {}

func execCommand(name string, args ...string) {
	cmd := exec.Command("sudo", append([]string{name}, args...)...)
	_ = cmd.Run()
}
