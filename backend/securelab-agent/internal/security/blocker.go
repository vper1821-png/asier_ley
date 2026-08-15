package security

import (
	"os/exec"
	"runtime"
)

func BlockUser(username string) error {
	if runtime.GOOS == "windows" {
		return exec.Command("net", "user", username, "/active:no").Run()
	}
	return exec.Command("sudo", "usermod", "--expiredate", "1", username).Run()
}

func UnblockUser(username string) error {
	if runtime.GOOS == "windows" {
		return exec.Command("net", "user", username, "/active:yes").Run()
	}
	return exec.Command("sudo", "usermod", "--expiredate", "", username).Run()
}

func BlockIP(ip string) error {
	if runtime.GOOS == "windows" {
		name := "Block_" + ip
		return exec.Command("netsh", "advfirewall", "firewall", "add", "rule",
			"name="+name, "dir=in", "action=block", "remoteip="+ip).Run()
	}
	return exec.Command("sudo", "iptables", "-A", "INPUT", "-s", ip, "-j", "DROP").Run()
}

func UnblockIP(ip string) error {
	if runtime.GOOS == "windows" {
		name := "Block_" + ip
		return exec.Command("netsh", "advfirewall", "firewall", "delete", "rule", "name="+name).Run()
	}
	return exec.Command("sudo", "iptables", "-D", "INPUT", "-s", ip, "-j", "DROP").Run()
}
