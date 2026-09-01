//go:build !windows

package sysinfo

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"fmt"
	"math"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

func GetProcesses() []Process {
	if runtime.GOOS != "linux" {
		return []Process{}
	}
	out, err := exec.Command("ps", "-eo", "pid,comm,pcpu,rss,args", "--sort=-%cpu").Output()
	if err != nil {
		return []Process{}
	}
	var procs []Process
	scanner := bufio.NewScanner(bytes.NewReader(out))
	first := true
	for scanner.Scan() && len(procs) < 20 {
		line := scanner.Text()
		if first {
			first = false
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 4 {
			continue
		}
		pid, _ := strconv.Atoi(fields[0])
		name := fields[1]
		cpu, _ := strconv.ParseFloat(fields[2], 64)
		rss, _ := strconv.Atoi(fields[3])
		procs = append(procs, Process{
			PID:   pid,
			Name:  name,
			MemMB: rss / 1024,
			CPU:   cpu,
		})
	}
	return procs
}

func GetHealth() Health {
	if runtime.GOOS != "linux" {
		return Health{Hostname: hostname(), Platform: platform(), TopProcesses: []Process{}}
	}

	h := Health{
		Hostname: hostname(),
		Platform: platform(),
	}

	// CPU usage: suma de %CPU de todos los procesos
	if out, err := exec.Command("ps", "-A", "-o", "%cpu").Output(); err == nil {
		total := 0.0
		for _, line := range strings.Split(string(out), "\n") {
			v := strings.TrimSpace(line)
			if v == "" || v == "%CPU" {
				continue
			}
			if f, e := strconv.ParseFloat(v, 64); e == nil {
				total += f
			}
		}
		h.CPU = int(math.Min(total, 100))
	}

	// Memoria usada (%)
	if out, err := exec.Command("free", "-m").Output(); err == nil {
		for _, line := range strings.Split(string(out), "\n") {
			if strings.HasPrefix(line, "Mem:") {
				fields := strings.Fields(line)
				if len(fields) >= 3 {
					total, _ := strconv.Atoi(fields[1])
					used, _ := strconv.Atoi(fields[2])
					if total > 0 {
						h.Memory = int(float64(used) / float64(total) * 100)
					}
				}
				break
			}
		}
	}

	// Disco
	if out, err := exec.Command("df", "-P", "/").Output(); err == nil {
		lines := strings.Split(string(out), "\n")
		if len(lines) >= 2 {
			fields := strings.Fields(lines[1])
			if len(fields) >= 4 {
				total, _ := strconv.ParseInt(fields[1], 10, 64)
				available, _ := strconv.ParseInt(fields[3], 10, 64)
				h.DiskTotal = total * 1024
				h.DiskFree = available * 1024
			}
		}
	}

	// Número de procesos
	if out, err := exec.Command("ps", "-e", "h").Output(); err == nil {
		h.Processes = len(strings.Split(strings.TrimSpace(string(out)), "\n"))
	}

	// Conexiones activas
	if out, err := exec.Command("ss", "-t", "-a", "state", "established").Output(); err == nil {
		h.Connections = len(strings.Split(strings.TrimSpace(string(out)), "\n"))
	}

	// Uptime
	if data, err := os.ReadFile("/proc/uptime"); err == nil {
		parts := strings.Fields(string(data))
		if len(parts) > 0 {
			if f, e := strconv.ParseFloat(parts[0], 64); e == nil {
				h.Uptime = int64(f)
			}
		}
	}

	h.TopProcesses = GetProcesses()
	return h
}

func GetDefender() Defender {
	if runtime.GOOS != "linux" {
		return Defender{}
	}
	d := Defender{}

	// Firewall: ufw o firewalld activos
	if out, err := exec.Command("systemctl", "is-active", "ufw").Output(); err == nil && strings.TrimSpace(string(out)) == "active" {
		d.FirewallEnabled = true
		d.FirewallProfiles = "ufw"
	} else if out, err := exec.Command("systemctl", "is-active", "firewalld").Output(); err == nil && strings.TrimSpace(string(out)) == "active" {
		d.FirewallEnabled = true
		d.FirewallProfiles = "firewalld"
	} else if out, err := exec.Command("iptables", "-L").Output(); err == nil && len(out) > 100 {
		d.FirewallEnabled = true
		d.FirewallProfiles = "iptables"
	}

	// Antivirus/sandbox: AppArmor o SELinux
	if out, err := exec.Command("systemctl", "is-active", "apparmor").Output(); err == nil && strings.TrimSpace(string(out)) == "active" {
		d.AntivirusEnabled = true
		d.RealTimeProtection = true
		d.SignatureVersion = "apparmor"
	} else if out, err := exec.Command("getenforce").Output(); err == nil && strings.TrimSpace(string(out)) == "Enforcing" {
		d.AntivirusEnabled = true
		d.RealTimeProtection = true
		d.SignatureVersion = "selinux"
	}

	d.SignatureUpdated = time.Now().Format(time.RFC3339)
	return d
}

func CaptureScreenshot() (string, error) {
	if runtime.GOOS != "linux" {
		return "", fmt.Errorf("captura no soportada en %s", runtime.GOOS)
	}

	tmpFile := filepath.Join(os.TempDir(), fmt.Sprintf("sl-screenshot-%d.png", now()))
	defer os.Remove(tmpFile)

	candidates := [][]string{
		{"gnome-screenshot", "-f", tmpFile},
		{"scrot", tmpFile},
		{"import", "-window", "root", tmpFile},
		{"grim", tmpFile},
	}

	for _, cmd := range candidates {
		if _, err := exec.LookPath(cmd[0]); err == nil {
			c := exec.Command(cmd[0], cmd[1:]...)
			if err := c.Run(); err == nil {
				data, err := os.ReadFile(tmpFile)
				if err == nil && len(data) > 0 {
					return base64.StdEncoding.EncodeToString(data), nil
				}
			}
		}
	}

	return "", fmt.Errorf("no se encontró herramienta de captura de pantalla")
}
