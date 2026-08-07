package main

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

type UserInfo struct {
	Username string `json:"username"`
	Session  string `json:"session"`
	Since    string `json:"since"`
}

type SystemInfo struct {
	Hostname string `json:"hostname"`
	Platform string `json:"platform"`
	Arch     string `json:"arch"`
	IP       string `json:"ip"`
	User     string `json:"user"`
}

type FirewallStatus struct {
	Enabled      bool     `json:"enabled"`
	ActiveRules  []string `json:"activeRules"`
	DefaultPolicy string  `json:"defaultPolicy"`
}

type SystemLoad struct {
	Uptime   int64   `json:"uptime"`
	LoadAvg  float64 `json:"loadAvg"`
	MemUsed  int     `json:"memUsed"`
	MemTotal int     `json:"memTotal"`
	CPUCores int     `json:"cpuCores"`
}

func getSystemInfo() SystemInfo {
	host, _ := os.Hostname()
	return SystemInfo{
		Hostname: host,
		Platform: runtime.GOOS,
		Arch:     runtime.GOARCH,
		IP:       getLocalIP(),
		User:     getCurrentUser(),
	}
}

func getLocalIP() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return "127.0.0.1"
	}
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			ipnet, ok := addr.(*net.IPNet)
			if ok && !ipnet.IP.IsLoopback() {
				ip := ipnet.IP.String()
				// Strip IPv6-mapped-IPv4 prefix
				ip = strings.TrimPrefix(ip, "::ffff:")
				if strings.Contains(ip, ".") {
					return ip
				}
			}
		}
	}
	return "127.0.0.1"
}

func getCurrentUser() string {
	u, _ := os.UserHomeDir()
	if u != "" {
		parts := strings.Split(u, string(os.PathSeparator))
		return parts[len(parts)-1]
	}
	return "unknown"
}

func getActiveUsers() []UserInfo {
	switch runtime.GOOS {
	case "windows":
		return getWindowsUsers()
	case "linux", "darwin":
		return getUnixUsers()
	}
	return nil
}

func getWindowsUsers() []UserInfo {
	out, err := exec.Command("query", "user").Output()
	if err != nil {
		return nil
	}
	lines := strings.Split(string(out), "\n")
	if len(lines) < 2 {
		return nil
	}
	var users []UserInfo
	for _, line := range lines[1:] {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 3 {
			continue
		}
		u := UserInfo{Username: parts[0]}
		if len(parts) > 2 {
			u.Session = parts[2]
		}
		if len(parts) > 3 {
			u.Since = strings.Join(parts[3:], " ")
		}
		users = append(users, u)
	}
	return users
}

func getUnixUsers() []UserInfo {
	out, err := exec.Command("who").Output()
	if err != nil {
		return nil
	}
	lines := strings.Split(string(out), "\n")
	var users []UserInfo
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 2 {
			continue
		}
		u := UserInfo{Username: parts[0], Session: parts[1]}
		if len(parts) > 2 {
			u.Since = strings.Join(parts[2:], " ")
		}
		users = append(users, u)
	}
	return users
}

func getFirewallStatus() FirewallStatus {
	switch runtime.GOOS {
	case "windows":
		return getWindowsFirewall()
	case "linux":
		return getLinuxFirewall()
	case "darwin":
		return getMacFirewall()
	}
	return FirewallStatus{Enabled: false}
}

func getWindowsFirewall() FirewallStatus {
	out, err := exec.Command("netsh", "advfirewall", "show", "allprofiles").Output()
	if err != nil {
		return FirewallStatus{Enabled: false}
	}
	output := string(out)
	enabled := strings.Contains(output, "State                         ON")
	policy := "allow"
	if strings.Contains(output, "Block") {
		policy = "block"
	}
	return FirewallStatus{
		Enabled:       enabled,
		ActiveRules:   getWindowsFWRules(),
		DefaultPolicy: policy,
	}
}

func getWindowsFWRules() []string {
	out, err := exec.Command("netsh", "advfirewall", "firewall", "show", "rule", "name=all").Output()
	if err != nil {
		return nil
	}
	var rules []string
	for _, line := range strings.Split(string(out), "\n") {
		if strings.Contains(line, "Invisia_") {
			if parts := strings.SplitN(line, ":", 2); len(parts) == 2 {
				rules = append(rules, strings.TrimSpace(parts[1]))
			}
		}
	}
	return rules
}

func getLinuxFirewall() FirewallStatus {
	out, err := exec.Command("sudo", "iptables", "-L", "-n").Output()
	if err != nil {
		if out2, err2 := exec.Command("ufw", "status").Output(); err2 == nil {
			return FirewallStatus{Enabled: strings.Contains(string(out2), "active")}
		}
		return FirewallStatus{Enabled: false}
	}
	output := string(out)
	policy := "allow"
	if strings.Contains(output, "Chain INPUT (policy DROP)") {
		policy = "block"
	}
	var rules []string
	for _, line := range strings.Split(output, "\n") {
		if strings.Contains(line, "Invisia_") {
			rules = append(rules, strings.TrimSpace(line))
		}
	}
	return FirewallStatus{Enabled: true, ActiveRules: rules, DefaultPolicy: policy}
}

func getMacFirewall() FirewallStatus {
	out, err := exec.Command("sudo", "pfctl", "-s", "rules").Output()
	if err != nil {
		return FirewallStatus{Enabled: false}
	}
	var rules []string
	for _, line := range strings.Split(string(out), "\n") {
		if strings.Contains(line, "Invisia_") {
			rules = append(rules, strings.TrimSpace(line))
		}
	}
	return FirewallStatus{Enabled: true, ActiveRules: rules, DefaultPolicy: "allow"}
}

func getSystemLoad() SystemLoad {
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	loadAvg := 0.0
	switch runtime.GOOS {
	case "linux":
		if out, err := exec.Command("sh", "-c", "cat /proc/loadavg | awk '{print $1}'").Output(); err == nil {
			fmt.Sscanf(string(out), "%f", &loadAvg)
		}
	case "darwin":
		if out, err := exec.Command("sh", "-c", "sysctl -n vm.loadavg | awk '{print $2}'").Output(); err == nil {
			fmt.Sscanf(string(out), "%f", &loadAvg)
		}
	case "windows":
		// Use CPU load percentage as a proxy for load average
		loadAvg = getCPUPercent() / 100.0
	}
	return SystemLoad{
		Uptime:   int64(time.Since(startTime).Seconds()),
		LoadAvg:  loadAvg,
		CPUCores: runtime.NumCPU(),
		MemTotal: getTotalMemory(),
		MemUsed:  int(m.Alloc / 1024 / 1024),
	}
}

var lastCPUPercent float64

func getCPUPercent() float64 {
	switch runtime.GOOS {
	case "windows":
		out, err := exec.Command("wmic", "cpu", "get", "loadpercentage").Output()
		if err == nil {
			for _, line := range strings.Split(string(out), "\n") {
				var pct int
				if _, err := fmt.Sscanf(strings.TrimSpace(line), "%d", &pct); err == nil && pct > 0 {
					lastCPUPercent = float64(pct)
					return lastCPUPercent
				}
			}
		}
	case "linux":
		out, err := exec.Command("sh", "-c", "top -bn1 | grep 'Cpu(s)' | awk '{print $2 + $4}'").Output()
		if err == nil {
			var pct float64
			if _, err := fmt.Sscanf(strings.TrimSpace(string(out)), "%f", &pct); err == nil {
				lastCPUPercent = pct
				return lastCPUPercent
			}
		}
	case "darwin":
		out, err := exec.Command("sh", "-c", "ps -A -o %cpu | awk '{s+=$1} END {print s}'").Output()
		if err == nil {
			var pct float64
			if _, err := fmt.Sscanf(strings.TrimSpace(string(out)), "%f", &pct); err == nil {
				cores := runtime.NumCPU()
				if cores > 0 {
					pct = pct / float64(cores)
				}
				lastCPUPercent = pct
				return lastCPUPercent
			}
		}
	}
	return lastCPUPercent
}

func getTotalMemory() int {
	switch runtime.GOOS {
	case "linux":
		out, err := exec.Command("sh", "-c", "grep MemTotal /proc/meminfo | awk '{print $2}'").Output()
		if err == nil {
			var kb int
			_, _ = fmt.Sscanf(string(out), "%d", &kb)
			return kb / 1024
		}
	case "darwin":
		out, err := exec.Command("sh", "-c", "sysctl hw.memsize | awk '{print $2}'").Output()
		if err == nil {
			var bytes int64
			if _, err := fmt.Sscanf(string(out), "%d", &bytes); err == nil {
				return int(bytes / 1024 / 1024)
			}
		}
	case "windows":
		out, err := exec.Command("wmic", "computersystem", "get", "TotalPhysicalMemory").Output()
		if err == nil {
			for _, line := range strings.Split(string(out), "\n") {
				var bytes int64
				if _, err := fmt.Sscanf(strings.TrimSpace(line), "%d", &bytes); err == nil && bytes > 0 {
					return int(bytes / 1024 / 1024)
				}
			}
		}
	}
	return 0
}
