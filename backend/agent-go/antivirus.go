package main

import (
	"encoding/json"
	"fmt"
	"os/exec"
	"runtime"
	"strings"
)

type AntivirusInfo struct {
	Detected           bool        `json:"detected"`
	Products           []AVProduct `json:"products"`
	WindowsDefenderOn  bool        `json:"windowsDefenderOn"`
	RealTimeProtection bool        `json:"realTimeProtection"`
	FirewallEnabled    bool        `json:"firewallEnabled"`
	FirewallProfiles   []string    `json:"firewallProfiles"`
	BitLockerEnabled   bool        `json:"bitLockerEnabled"`
	WindowsUpdated     bool        `json:"windowsUpdated"`
	LastUpdateCheck    string      `json:"lastUpdateCheck"`
	DBProcesses        []DBProcess `json:"dbProcesses"`
}

type AVProduct struct {
	DisplayName string `json:"displayName"`
	State       string `json:"state"`
	Enabled     bool   `json:"enabled"`
}

type DBProcess struct {
	ProcessName string `json:"processName"`
	PID         int    `json:"pid"`
	RemoteAddr  string `json:"remoteAddr"`
	RemotePort  int    `json:"remotePort"`
	DBType      string `json:"dbType"`
}

var knownDBPorts = map[int]string{
	3306: "mysql",
	3307: "mariadb",
	5432: "postgresql",
	5433: "postgresql",
	1433: "mssql",
	1434: "mssql",
	1521: "oracle",
	1522: "oracle",
	2484: "oracle",
	27017: "mongodb",
	27018: "mongodb",
	27019: "mongodb",
	6379: "redis",
	6380: "redis",
	9200: "elasticsearch",
	9300: "elasticsearch",
	9042: "cassandra",
	7474: "neo4j",
	7687: "neo4j",
	8123: "clickhouse",
	9000: "clickhouse",
	8086: "influxdb",
	8083: "influxdb",
	5984: "couchdb",
	6984: "couchdb",
	443: "https",
	80: "http",
}

func detectWindowsSecurity() AntivirusInfo {
	info := detectAntivirus()

	if runtime.GOOS != "windows" {
		return info
	}

	// Firewall status
	fwOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
		`Get-NetFirewallProfile | Select-Object Name, Enabled | ConvertTo-Json -Compress`,
	).Output()
	if len(fwOut) > 0 {
		var profiles []map[string]interface{}
		if err := json.Unmarshal(fwOut, &profiles); err != nil {
			var single map[string]interface{}
			if err2 := json.Unmarshal(fwOut, &single); err2 == nil {
				profiles = []map[string]interface{}{single}
			}
		}
		for _, p := range profiles {
			name, _ := p["Name"].(string)
			enabled, _ := p["Enabled"].(bool)
			if enabled {
				info.FirewallProfiles = append(info.FirewallProfiles, name)
			}
			if enabled {
				info.FirewallEnabled = true
			}
		}
	}

	// BitLocker status
	blOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
		`Get-BitLockerVolume -MountPoint $env:SystemDrive 2>$null | Select-Object ProtectionStatus | ConvertTo-Json -Compress`,
	).Output()
	if len(blOut) > 0 {
		var blInfo map[string]interface{}
		if err := json.Unmarshal(blOut, &blInfo); err == nil {
			if status, ok := blInfo["ProtectionStatus"].(float64); ok {
				info.BitLockerEnabled = status == 1
			}
		}
	}

	// Windows Update status
	wuOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
		`Get-WUHistory 2>$null | Select-Object -First 1 Date | ConvertTo-Json -Compress`,
	).Output()
	if len(wuOut) > 0 {
		var wuInfo map[string]interface{}
		if err := json.Unmarshal(wuOut, &wuInfo); err == nil {
			if date, ok := wuInfo["Date"].(string); ok {
				info.LastUpdateCheck = date
			}
		}
	}

	// DB process monitoring via netstat
	dbPorts := make([]string, 0, len(knownDBPorts))
	for p := range knownDBPorts {
		dbPorts = append(dbPorts, fmt.Sprintf("%d", p))
	}

	nsOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
		fmt.Sprintf(`Get-NetTCPConnection -State Established 2>$null | Where-Object { $_.RemotePort -in @(%s) } | Select-Object OwningProcess, RemoteAddress, RemotePort | ConvertTo-Json -Compress`, strings.Join(dbPorts, ",")),
	).Output()
	if len(nsOut) > 2 { // not empty array
		var conns []map[string]interface{}
		if err := json.Unmarshal(nsOut, &conns); err != nil {
			var single map[string]interface{}
			if err2 := json.Unmarshal(nsOut, &single); err2 == nil {
				conns = []map[string]interface{}{single}
			}
		}
		for _, c := range conns {
			pid, _ := c["OwningProcess"].(float64)
			remoteAddr, _ := c["RemoteAddress"].(string)
			remotePort, _ := c["RemotePort"].(float64)

			// Get process name from PID
			procName := "unknown"
			if pid > 0 {
				pOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
					fmt.Sprintf(`Get-Process -Id %.0f 2>$null | Select-Object -ExpandProperty ProcessName`, pid),
				).Output()
				if len(pOut) > 0 {
					procName = strings.TrimSpace(string(pOut))
				}
			}

			dbType := knownDBPorts[int(remotePort)]
			info.DBProcesses = append(info.DBProcesses, DBProcess{
				ProcessName: procName,
				PID:         int(pid),
				RemoteAddr:  remoteAddr,
				RemotePort:  int(remotePort),
				DBType:      dbType,
			})
		}
	}

	return info
}

func sendComplianceStatus() {
	info := detectWindowsSecurity()
	data, _ := json.Marshal(info)
	logMsg("Compliance: %s", string(data))
	wsSend(WSMessage{
		Type:       "compliance_status",
		AgentID:    GetAgentID(),
		Compliance: &info,
	})
}

func detectAntivirus() AntivirusInfo {
	info := AntivirusInfo{
		Products: []AVProduct{},
	}

	if runtime.GOOS != "windows" {
		info.Detected = false
		return info
	}

	// Method 1: WMI via PowerShell (SecurityCenter2)
	psOut, psErr := exec.Command("powershell", "-NoProfile", "-Command",
		`Get-CimInstance -Namespace root/SecurityCenter2 -ClassName AntiVirusProduct 2>$null | Select-Object displayName, productState | ConvertTo-Json -Compress`,
	).Output()

	if psErr == nil && len(psOut) > 0 {
		output := strings.TrimSpace(string(psOut))
		if output != "" && output != "null" {
			// Try array parse first, then single object
			var products []map[string]interface{}
			if err := json.Unmarshal([]byte(output), &products); err != nil {
				var single map[string]interface{}
				if err2 := json.Unmarshal([]byte(output), &single); err2 == nil {
					products = []map[string]interface{}{single}
				}
			}
			for _, p := range products {
				name, _ := p["displayName"].(string)
				state, _ := p["productState"].(string)
				enabled := isAVEnabled(state)
				if name != "" {
					info.Products = append(info.Products, AVProduct{
						DisplayName: name,
						State:       state,
						Enabled:     enabled,
					})
					info.Detected = true
				}
			}
		}
	}

	// Method 2: Check Windows Defender status
	defOut, defErr := exec.Command("powershell", "-NoProfile", "-Command",
		`Get-MpComputerStatus 2>$null | Select-Object AntivirusEnabled, RealTimeProtectionEnabled | ConvertTo-Json -Compress`,
	).Output()
	if defErr == nil && len(defOut) > 0 {
		var defStatus map[string]interface{}
		if err := json.Unmarshal(defOut, &defStatus); err == nil {
			if av, ok := defStatus["AntivirusEnabled"].(bool); ok {
				info.WindowsDefenderOn = av
			}
			if rtp, ok := defStatus["RealTimeProtectionEnabled"].(bool); ok {
				info.RealTimeProtection = rtp
			}
		}
	}

	// Method 3: Check for known antivirus processes
	if !info.Detected {
		avProcesses := []string{
			"MsMpEng", "McTray", "ccSvcHst", "avguard", "AVGSvc",
			"bdagent", "kaspersky", "ekrn", "egui", "vsserv",
			"SophosUI", "f-secure", "pavfires", "TMCC", "TMBMSRV",
			"avp", "AviraSvc", "NortonSecurity", "norton",
		}
		procOut, _ := exec.Command("powershell", "-NoProfile", "-Command",
			`Get-Process 2>$null | Select-Object -ExpandProperty Name`,
		).Output()
		procList := strings.Split(string(procOut), "\n")
		for _, proc := range procList {
			proc = strings.TrimSpace(proc)
			if proc == "" {
				continue
			}
			for _, avp := range avProcesses {
				if strings.EqualFold(proc, avp) || strings.Contains(strings.ToLower(proc), strings.ToLower(avp)) {
					info.Products = append(info.Products, AVProduct{
						DisplayName: proc,
						State:       "process_detected",
						Enabled:     true,
					})
					info.Detected = true
					break
				}
			}
		}
	}

	return info
}

func isAVEnabled(stateHex string) bool {
	if len(stateHex) < 4 {
		return false
	}
	// Windows Security Center productState decoding:
	// Byte 0: WSC_SECURITY_PROVIDER_AV
	// Byte 2: State (0=off, 1=on)
	state := strings.TrimPrefix(stateHex, "0x")
	if len(state) >= 2 {
		lastTwo := state[len(state)-2:]
		return lastTwo == "00" || lastTwo == "10"
	}
	return false
}

func sendAntivirusStatus() {
	info := detectAntivirus()
	if runtime.GOOS != "windows" || info.Detected {
		logMsg("Antivirus: detected=%v products=%d defender=%v rtp=%v",
			info.Detected, len(info.Products), info.WindowsDefenderOn, info.RealTimeProtection)
	}
	wsSend(WSMessage{
		Type:       "antivirus_status",
		AgentID:    GetAgentID(),
		Antivirus:  &info,
	})
}
