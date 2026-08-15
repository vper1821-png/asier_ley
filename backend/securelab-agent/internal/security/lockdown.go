package security

import (
	"os/exec"
	"runtime"
)

func Lockdown() {
	if runtime.GOOS == "windows" {
		exec.Command("netsh", "advfirewall", "set", "allprofiles", "firewallpolicy", "blockinbound,allowoutbound").Run()
	} else {
		exec.Command("sudo", "iptables", "-P", "INPUT", "DROP").Run()
		exec.Command("sudo", "iptables", "-P", "FORWARD", "DROP").Run()
	}
}
