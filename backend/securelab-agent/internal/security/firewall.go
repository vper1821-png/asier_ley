package security

import (
	"os/exec"
	"runtime"
)

func ApplyFirewallRule(action, protocol, port, direction string) error {
	if runtime.GOOS != "windows" {
		return nil
	}
	dir := "in"
	if direction == "outbound" {
		dir = "out"
	}
	args := []string{"advfirewall", "firewall", "add", "rule",
		"name=SecureLabRule", "dir=" + dir, "action=" + action,
		"protocol=" + protocol, "localport=" + port}
	return exec.Command("netsh", args...).Run()
}
