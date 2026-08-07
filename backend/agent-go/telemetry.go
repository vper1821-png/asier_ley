package main

import (
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

type Telemetry struct {
	Processes    []ProcInfo    `json:"processes"`
	NetworkConns []NetConn     `json:"networkConns"`
	LoggedUsers  []LoggedUser  `json:"loggedUsers"`
	DiskUsage    []DiskInfo    `json:"diskUsage"`
	CPUUsage     CPUInfo       `json:"cpuUsage"`
	MemUsage     MemInfo       `json:"memUsage"`
	Services     []SvcInfo     `json:"services"`
	SystemInfo   SysInfo       `json:"systemInfo"`
	Uptime       int64         `json:"uptime"`
	Hostname     string        `json:"hostname"`
	Platform     string        `json:"platform"`
}

type ProcInfo struct {
	Name string `json:"name"`
	PID  string `json:"pid"`
	CPU  string `json:"cpu,omitempty"`
	Mem  string `json:"mem,omitempty"`
}

type NetConn struct {
	LocalAddr  string `json:"localAddr"`
	LocalPort  string `json:"localPort"`
	RemoteAddr string `json:"remoteAddr"`
	RemotePort string `json:"remotePort"`
	State      string `json:"state"`
	PID        string `json:"pid"`
}

type LoggedUser struct {
	Username string `json:"username"`
	Session  string `json:"session"`
	Since    string `json:"since"`
}

type DiskInfo struct {
	Drive string  `json:"drive"`
	Free  float64 `json:"free"`
	Total float64 `json:"total"`
}

type CPUInfo struct {
	Cores int    `json:"cores"`
	Model string `json:"model"`
	Speed int    `json:"speed"`
}

type MemInfo struct {
	Total float64 `json:"total"`
	Free  float64 `json:"free"`
	Used  float64 `json:"used"`
}

type SvcInfo struct {
	Name  string `json:"name"`
	State string `json:"state"`
}

type SysInfo struct {
	Hostname string `json:"hostname"`
	Platform string `json:"platform"`
	Arch     string `json:"arch"`
	Release  string `json:"release"`
	User     string `json:"user"`
}

func getFullTelemetry() Telemetry {
	return Telemetry{
		Processes:    getProcessList(),
		NetworkConns: getNetworkConns(),
		LoggedUsers:  getLoggedUsers(),
		DiskUsage:    getDiskUsage(),
		CPUUsage:     getCPUInfo(),
		MemUsage:     getMemInfo(),
		Services:     getServices(),
		SystemInfo:   getSysInfo(),
		Uptime:       int64(time.Since(startTime).Seconds()),
		Hostname:     hostname(),
		Platform:     runtime.GOOS,
	}
}

func hostname() string {
	h, _ := os.Hostname()
	return h
}

func getProcessList() []ProcInfo {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("tasklist", "/FO", "CSV", "/NH")
	default:
		cmd = exec.Command("sh", "-c", "ps aux --sort=-%cpu 2>/dev/null | head -50 || ps -ef 2>/dev/null | head -50")
	}
	out, err := cmd.Output()
	if err != nil {
		return nil
	}
	var procs []ProcInfo
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if runtime.GOOS == "windows" {
			line = strings.ReplaceAll(line, "\"", "")
			parts := strings.Split(line, ",")
			if len(parts) >= 2 {
				procs = append(procs, ProcInfo{Name: safe(parts, 0), PID: safe(parts, 1), Mem: safe(parts, 3)})
			}
		} else {
			parts := strings.Fields(line)
			if len(parts) >= 11 {
				procs = append(procs, ProcInfo{Name: parts[len(parts)-1], PID: parts[1], CPU: parts[2]})
			}
		}
		if len(procs) >= 100 {
			break
		}
	}
	return procs
}

func getNetworkConns() []NetConn {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("netstat", "-ano")
	case "linux":
		cmd = exec.Command("sh", "-c", "ss -tunap 2>/dev/null | head -50 || netstat -tunap 2>/dev/null | head -50")
	case "darwin":
		cmd = exec.Command("sh", "-c", "lsof -i -P -n 2>/dev/null | head -50")
	}
	out, err := cmd.Output()
	if err != nil {
		return nil
	}
	var conns []NetConn
	for _, line := range strings.Split(string(out), "\n") {
		if runtime.GOOS == "windows" {
			if !strings.Contains(line, "ESTABLISHED") && !strings.Contains(line, "LISTENING") {
				continue
			}
			parts := strings.Fields(line)
			if len(parts) >= 5 {
				local := strings.Split(parts[1], ":")
				remote := strings.Split(parts[2], ":")
				conns = append(conns, NetConn{
					LocalAddr: safeStr(local, 0), LocalPort: safeStr(local, 1),
					RemoteAddr: safeStr(remote, 0), RemotePort: safeStr(remote, 1),
					State: parts[3], PID: parts[4],
				})
			}
		} else {
			if !strings.Contains(line, "ESTAB") && !strings.Contains(line, "LISTEN") {
				continue
			}
			parts := strings.Fields(line)
			if len(parts) >= 5 {
				local := strings.Split(parts[4], ":")
				remote := strings.Split(parts[5], ":")
				conns = append(conns, NetConn{
					LocalAddr: safeStr(local, 0), LocalPort: safeStr(local, 1),
					RemoteAddr: safeStr(remote, 0), RemotePort: safeStr(remote, 1),
					State: parts[1], PID: parts[6],
				})
			}
		}
		if len(conns) >= 50 {
			break
		}
	}
	return conns
}

func getLoggedUsers() []LoggedUser {
	out, err := exec.Command("who").Output()
	if err != nil {
		return nil
	}
	var users []LoggedUser
	for _, line := range strings.Split(string(out), "\n") {
		parts := strings.Fields(line)
		if len(parts) >= 2 {
			users = append(users, LoggedUser{Username: parts[0], Session: parts[1], Since: strings.Join(parts[2:], " ")})
		}
	}
	return users
}

func getDiskUsage() []DiskInfo {
	switch runtime.GOOS {
	case "windows":
		out, err := exec.Command("wmic", "logicaldisk", "get", "size,freespace,caption").Output()
		if err != nil {
			return nil
		}
		var disks []DiskInfo
		for _, line := range strings.Split(string(out), "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "Caption") {
				continue
			}
			parts := strings.Fields(line)
			if len(parts) >= 3 {
				var free, total float64
				_, _ = fmt.Sscanf(parts[1], "%f", &free)
				_, _ = fmt.Sscanf(parts[2], "%f", &total)
				disks = append(disks, DiskInfo{Drive: parts[0], Free: free / 1073741824, Total: total / 1073741824})
			}
		}
		return disks
	default:
		out, err := exec.Command("df", "-h", "/").Output()
		if err != nil {
			return nil
		}
		lines := strings.Split(string(out), "\n")
		if len(lines) < 2 {
			return nil
		}
		parts := strings.Fields(lines[1])
		if len(parts) >= 5 {
			return []DiskInfo{{Drive: parts[0], Free: parseSize(parts[3]), Total: parseSize(parts[1])}}
		}
	}
	return nil
}

func parseSize(s string) float64 {
	var val float64
	var unit string
	_, _ = fmt.Sscanf(s, "%f%s", &val, &unit)
	switch unit {
	case "G", "GB":
		return val
	case "T", "TB":
		return val * 1024
	case "M", "MB":
		return val / 1024
	}
	return val
}

func getCPUInfo() CPUInfo {
	info := CPUInfo{Cores: runtime.NumCPU()}
	if runtime.GOOS == "linux" {
		out, err := exec.Command("sh", "-c", "grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2").Output()
		if err == nil {
			info.Model = strings.TrimSpace(string(out))
		}
	}
	return info
}

func getMemInfo() MemInfo {
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	total := float64(getTotalMemory())
	used := float64(m.Alloc) / 1073741824
	return MemInfo{
		Total: total,
		Used:  used,
		Free:  total - used,
	}
}

func getServices() []SvcInfo {
	switch runtime.GOOS {
	case "windows":
		out, err := exec.Command("sc", "query", "state=", "all").Output()
		if err != nil {
			return nil
		}
		var svcs []SvcInfo
		var current string
		for _, line := range strings.Split(string(out), "\n") {
			line = strings.TrimSpace(line)
			if strings.HasPrefix(line, "SERVICE_NAME") {
				current = strings.TrimSpace(strings.SplitN(line, ":", 2)[1])
			}
			if strings.Contains(line, "STATE") && current != "" {
				state := "stopped"
				if strings.Contains(line, "RUNNING") {
					state = "running"
				}
				svcs = append(svcs, SvcInfo{Name: current, State: state})
				current = ""
				if len(svcs) >= 30 {
					break
				}
			}
		}
		return svcs
	case "linux":
		out, err := exec.Command("sh", "-c", "systemctl list-units --type=service --state=running 2>/dev/null | head -30").Output()
		if err != nil {
			return nil
		}
		var svcs []SvcInfo
		for _, line := range strings.Split(string(out), "\n") {
			if strings.Contains(line, ".service") {
				parts := strings.Fields(line)
				if len(parts) >= 2 {
					svcs = append(svcs, SvcInfo{Name: parts[0], State: parts[1]})
				}
			}
		}
		return svcs
	case "darwin":
		out, err := exec.Command("launchctl", "list").Output()
		if err != nil {
			return nil
		}
		var svcs []SvcInfo
		for _, line := range strings.Split(string(out), "\n") {
			parts := strings.Fields(line)
			if len(parts) >= 3 {
				svcs = append(svcs, SvcInfo{Name: strings.Join(parts[2:], " "), State: parts[1]})
			}
			if len(svcs) >= 30 {
				break
			}
		}
		return svcs
	}
	return nil
}

func getSysInfo() SysInfo {
	h, _ := os.Hostname()
	return SysInfo{
		Hostname: h,
		Platform: runtime.GOOS,
		Arch:     runtime.GOARCH,
		Release:  fmt.Sprintf("%s/%s", runtime.GOOS, runtime.GOARCH),
		User:     getCurrentUser(),
	}
}

func safe(parts []string, i int) string {
	if i < len(parts) {
		return parts[i]
	}
	return ""
}

func safeStr(parts []string, i int) string {
	if i < len(parts) {
		return parts[i]
	}
	return ""
}
