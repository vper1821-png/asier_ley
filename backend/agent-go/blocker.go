package main

import (
	"fmt"
	"os/exec"
	"runtime"
	"strings"
)

type BlockResult struct {
	Success bool   `json:"success"`
	Method  string `json:"method"`
	Error   string `json:"error,omitempty"`
}

func blockUser(username string) BlockResult {
	switch runtime.GOOS {
	case "windows":
		return blockWindowsUser(username)
	case "linux":
		return blockLinuxUser(username)
	case "darwin":
		return blockMacUser(username)
	}
	return BlockResult{Error: "unsupported_platform"}
}

func unblockUser(username string) BlockResult {
	switch runtime.GOOS {
	case "windows":
		return unblockWindowsUser(username)
	case "linux":
		return unblockLinuxUser(username)
	case "darwin":
		return unblockMacUser(username)
	}
	return BlockResult{Error: "unsupported_platform"}
}

func blockWindowsUser(username string) BlockResult {
	err := exec.Command("net", "user", username, "/active:no").Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "windows_account"}
}

func unblockWindowsUser(username string) BlockResult {
	err := exec.Command("net", "user", username, "/active:yes").Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "windows_account"}
}

func blockLinuxUser(username string) BlockResult {
	exec.Command("sudo", "usermod", "--expiredate", "1", username).Run()
	exec.Command("sudo", "pkill", "-KILL", "-u", username).Run()
	return BlockResult{Success: true, Method: "linux_account"}
}

func unblockLinuxUser(username string) BlockResult {
	err := exec.Command("sudo", "usermod", "--expiredate", "", username).Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "linux_account"}
}

func blockMacUser(username string) BlockResult {
	exec.Command("sudo", "pwpolicy", "-setpolicy", "isDisabled=1", "-u", username).Run()
	exec.Command("sudo", "killall", "-STOP", "-u", username).Run()
	return BlockResult{Success: true, Method: "macos_account"}
}

func unblockMacUser(username string) BlockResult {
	err := exec.Command("sudo", "pwpolicy", "-setpolicy", "isDisabled=0", "-u", username).Run()
	if err != nil {
		exec.Command("sudo", "dscl", ".", "create", "/Users/"+username, "IsDisabled", "0").Run()
	}
	return BlockResult{Success: true, Method: "macos_account"}
}

// --- IP blocking ---

func blockIP(ip string) BlockResult {
	switch runtime.GOOS {
	case "windows":
		return blockWindowsIP(ip)
	case "linux":
		return blockLinuxIP(ip)
	case "darwin":
		return blockMacIP(ip)
	}
	return BlockResult{Error: "unsupported_platform"}
}

func unblockIP(ip string) BlockResult {
	switch runtime.GOOS {
	case "windows":
		return unblockWindowsIP(ip)
	case "linux":
		return unblockLinuxIP(ip)
	case "darwin":
		return unblockMacIP(ip)
	}
	return BlockResult{Error: "unsupported_platform"}
}

func blockWindowsIP(ip string) BlockResult {
	name := fmt.Sprintf("Invisia_Block_%s", strings.ReplaceAll(ip, ".", "_"))
	err := exec.Command("netsh", "advfirewall", "firewall", "add", "rule",
		"name="+name, "dir=in", "interface=any", "action=block", "remoteip="+ip).Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "windows_firewall"}
}

func unblockWindowsIP(ip string) BlockResult {
	name := fmt.Sprintf("Invisia_Block_%s", strings.ReplaceAll(ip, ".", "_"))
	err := exec.Command("netsh", "advfirewall", "firewall", "delete", "rule", "name="+name).Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "windows_firewall"}
}

func blockLinuxIP(ip string) BlockResult {
	err := exec.Command("sudo", "iptables", "-A", "INPUT", "-s", ip, "-j", "DROP").Run()
	if err != nil {
		return BlockResult{Error: err.Error()}
	}
	return BlockResult{Success: true, Method: "iptables"}
}

func unblockLinuxIP(ip string) BlockResult {
	exec.Command("sudo", "iptables", "-D", "INPUT", "-s", ip, "-j", "DROP").Run()
	return BlockResult{Success: true, Method: "iptables"}
}

func blockMacIP(ip string) BlockResult {
	exec.Command("sudo", "pfctl", "-t", "blocked_hosts", "-T", "add", ip).Run()
	return BlockResult{Success: true, Method: "pfctl"}
}

func unblockMacIP(ip string) BlockResult {
	exec.Command("sudo", "pfctl", "-t", "blocked_hosts", "-T", "delete", ip).Run()
	return BlockResult{Success: true, Method: "pfctl"}
}

func getBlockedUsers() []string {
	switch runtime.GOOS {
	case "windows":
		return getWindowsBlocked()
	case "linux":
		return getLinuxBlocked()
	case "darwin":
		return getMacBlocked()
	}
	return nil
}

func getWindowsBlocked() []string {
	out, err := exec.Command("net", "user").Output()
	if err != nil {
		return nil
	}
	var blocked []string
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.Contains(line, "---") || strings.Contains(line, "accounts for") {
			continue
		}
		name := strings.Fields(line)
		if len(name) == 0 {
			continue
		}
		info, err := exec.Command("net", "user", name[0]).Output()
		if err == nil && strings.Contains(string(info), "Account active               No") {
			blocked = append(blocked, name[0])
		}
	}
	return blocked
}

func getLinuxBlocked() []string {
	out, err := exec.Command("sh", "-c", "awk -F: '{print $1}' /etc/passwd").Output()
	if err != nil {
		return nil
	}
	var blocked []string
	for _, u := range strings.Split(string(out), "\n") {
		u = strings.TrimSpace(u)
		if u == "" {
			continue
		}
		chage, err := exec.Command("sudo", "chage", "-l", u).Output()
		if err == nil && strings.Contains(string(chage), "Account expires") && !strings.Contains(string(chage), "never") {
			blocked = append(blocked, u)
		}
	}
	return blocked
}

func getMacBlocked() []string {
	out, err := exec.Command("sh", "-c", "dscl . list /Users IsDisabled 2>/dev/null | grep '1$' || true").Output()
	if err != nil {
		return nil
	}
	var blocked []string
	for _, line := range strings.Split(string(out), "\n") {
		fields := strings.Fields(line)
		if len(fields) > 0 {
			blocked = append(blocked, fields[0])
		}
	}
	return blocked
}
