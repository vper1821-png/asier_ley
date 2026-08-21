//go:build !windows

package security

import "os/exec"

// En plataformas no Windows el bloqueo se aplica con firewall (sin overlay).

func applyLockdown(message string) {
	execCommand("iptables", "-P", "INPUT", "DROP")
	execCommand("iptables", "-P", "FORWARD", "DROP")
	execCommand("iptables", "-P", "OUTPUT", "DROP")
}

func removeLockdown() {
	execCommand("iptables", "-P", "INPUT", "ACCEPT")
	execCommand("iptables", "-P", "FORWARD", "ACCEPT")
	execCommand("iptables", "-P", "OUTPUT", "ACCEPT")
}

func PlayAlarm() {}
func StopAlarm() {}
func Speak(text string) {}

func execCommand(name string, args ...string) {
	cmd := exec.Command("sudo", append([]string{name}, args...)...)
	_ = cmd.Run()
}
