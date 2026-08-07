package main

import (
	"bufio"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// ──────────────────────────────────────────────
// Windows Event Log Monitor
// Reads Security/Application/System event logs
// to detect database-related activity:
// - Logon/logoff events (4624, 4625, 4634)
// - Privilege escalation (4672, 4673, 4674)
// - Account creation (4720, 4722)
// - Policy changes (4719, 4817)
// - Service installed (7045)
// - Suspicious process creation (4688)
// ──────────────────────────────────────────────

type WinEventMonitor struct {
	mu            sync.RWMutex
	lastCheck     time.Time
	lastEventIDs  map[string]int64 // channel -> last record ID seen
	seenEvents    map[int64]time.Time
}

type WinEvent struct {
	Timestamp  time.Time
	Channel    string
	Provider   string
	EventID    int
	Level      string
	Message    string
	RecordID   int64
}

var globalWinEventMonitor *WinEventMonitor

func GetWinEventMonitor() *WinEventMonitor {
	if globalWinEventMonitor == nil {
		globalWinEventMonitor = &WinEventMonitor{
			lastEventIDs: make(map[string]int64),
			seenEvents:   make(map[int64]time.Time),
		}
	}
	return globalWinEventMonitor
}

func (wm *WinEventMonitor) Start() {
	go wm.loop()
	logMsg("Windows Event Monitor: started — monitoring Security/Application/System logs")
}

func (wm *WinEventMonitor) loop() {
	time.Sleep(10 * time.Second) // initial delay
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for range ticker.C {
		wm.pollSecurityLog()
		wm.pollApplicationLog()
		wm.pollSystemLog()
		wm.cleanupSeen()
	}
}

func (wm *WinEventMonitor) pollSecurityLog() {
	wm.pollChannel("Security", []int{
		4624, // Logon success
		4625, // Logon failure
		4634, // Logoff
		4647, // User initiated logoff
		4672, // Special privileges assigned (admin logon)
		4673, // Privileged service use
		4674, // Privileged object access
		4688, // New process created (with command line)
		4697, // Service installed
		4702, // Scheduled task updated
		4720, // User account created
		4722, // User account enabled
		4724, // Password reset attempt
		4726, // User account deleted
		4728, // Member added to security-enabled global group
		4732, // Member added to local group
		4756, // Member added to universal group
		4768, // Kerberos TGT requested
		4769, // Kerberos service ticket requested
		4771, // Kerberos pre-authentication failed
		4776, // NTLM authentication
		4719, // Audit policy changed
		4817, // Audit policy changed
		5140, // Network share accessed
		5145, // Network share object access
	})
}

func (wm *WinEventMonitor) pollApplicationLog() {
	// DB-related application events
	wm.pollChannel("Application", []int{})
}

func (wm *WinEventMonitor) pollSystemLog() {
	// Service start/stop events related to databases
	wm.pollChannel("System", []int{
		7036, // Service started/stopped
		7040, // Service start type changed
		7045, // Service installed
	})
}

func (wm *WinEventMonitor) pollChannel(channel string, eventIDs []int) {
	wm.mu.RLock()
	lastID := wm.lastEventIDs[channel]
	wm.mu.RUnlock()

	// Build wevtutil query
	query := "*"
	if len(eventIDs) > 0 {
		// wevtutil uses XPath-like filters
		var idStrs []string
		for _, id := range eventIDs {
			idStrs = append(idStrs, strconv.Itoa(id))
		}
		query = "*[System[(" + buildORConditions("EventID", idStrs) + ")]]"
	}

	// Use PowerShell Get-WinEvent for reliability
	psCmd := "Get-WinEvent -LogName '" + channel + "' -MaxEvents 50"
	if query != "*" {
		psCmd += " -FilterXPath '" + query + "'"
	}
	psCmd += " | Select-Object TimeCreated,Id,ProviderName,LevelDisplayName,Message | ConvertTo-Json"

	cmd := exec.Command("powershell", "-NoProfile", "-Command", psCmd)
	output, err := cmd.Output()
	if err != nil {
		return
	}

	events := parsePSJsonEvents(string(output))
	for _, ev := range events {
		// Dedup
		wm.mu.RLock()
		seen := wm.seenEvents[ev.RecordID]
		wm.mu.RUnlock()
		if !seen.IsZero() {
			continue
		}

		wm.mu.Lock()
		wm.seenEvents[ev.RecordID] = time.Now()
		if ev.RecordID > lastID {
			wm.lastEventIDs[channel] = ev.RecordID
		}
		wm.mu.Unlock()

		// Classify severity based on event type
		sev := wm.classifyEvent(ev)

		// Log notable events
		if sev != "info" {
			logMsg("Windows Event [%s] [%s] ID:%d: %.200s", strings.ToUpper(sev), channel, ev.EventID, ev.Message)

			enqueueHostEvent(HostEvent{
				Type:      "windows_event",
				Severity:  sev,
				Title:     wm.getEventTitle(ev),
				Detail:    ev.Message,
				Timestamp: ev.Timestamp,
				Source:    "windows_event_log",
			})
		}

		// Persist to SQLite
		store := GetAuditStore()
		store.StoreWindowsEvent(ev.Timestamp, channel, ev.Provider, ev.Message, ev.EventID, sev)
	}
}

func (wm *WinEventMonitor) classifyEvent(ev WinEvent) string {
	switch ev.EventID {
	case 4625: // Logon failure
		return "medium"
	case 4720, 4722, 4724, 4726: // Account changes
		return "high"
	case 4728, 4732, 4756: // Group membership changes
		return "high"
	case 4672: // Special privileges
		return "medium"
	case 4673, 4674: // Privilege use
		return "medium"
	case 4688: // New process
		return "low"
	case 4697, 7045: // Service installed
		return "critical"
	case 4719, 4817: // Audit policy changed
		return "critical"
	case 7040: // Service start type changed
		return "high"
	case 5140, 5145: // Network share access
		return "medium"
	case 4771: // Kerberos pre-auth failure
		return "high"
	case 4776: // NTLM auth
		return "low"
	case 4624: // Logon success
		return "info"
	case 4634, 4647: // Logoff
		return "info"
	case 4768, 4769: // Kerberos
		return "info"
	case 7036: // Service start/stop
		return "info"
	default:
		return "low"
	}
}

func (wm *WinEventMonitor) getEventTitle(ev WinEvent) string {
	titles := map[int]string{
		4624: "User logon exito",
		4625: "User logon FALLIDO",
		4634: "User logoff",
		4647: "User initiated logoff",
		4672: "Special privileges assigned",
		4673: "Privileged service use",
		4674: "Privileged object access",
		4688: "New process created",
		4697: "Service installed",
		4702: "Scheduled task updated",
		4720: "User account CREATED",
		4722: "User account ENABLED",
		4724: "Password reset attempt",
		4726: "User account DELETED",
		4728: "User added to security group",
		4732: "User added to local group",
		4756: "User added to universal group",
		4771: "Kerberos pre-auth FAILED",
		4776: "NTLM authentication",
		4719: "Audit policy CHANGED",
		4817: "Audit policy CHANGED",
		5140: "Network share accessed",
		5145: "Network share object access",
		7036: "Service started/stopped",
		7040: "Service start type CHANGED",
		7045: "Service INSTALLED",
	}
	if t, ok := titles[ev.EventID]; ok {
		return t
	}
	return "Windows Event " + strconv.Itoa(ev.EventID)
}

func buildORConditions(field string, values []string) string {
	var parts []string
	for _, v := range values {
		parts = append(parts, field+"='"+v+"'")
	}
	return strings.Join(parts, " or ")
}

func parsePSJsonEvents(output string) []WinEvent {
	var events []WinEvent
	if strings.TrimSpace(output) == "" {
		return events
	}

	scanner := bufio.NewScanner(strings.NewReader(output))
	scanner.Buffer(make([]byte, 1024*1024), 1024*1024)

	// PowerShell returns a JSON array or a single JSON object
	// We parse each line as it may be individual objects
	inObj := false
	objStart := -1
	lineNum := 0
	lines := strings.Split(output, "\n")

	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "{" {
			inObj = true
			objStart = i
		}
		if trimmed == "}" && inObj {
			objBlock := strings.Join(lines[objStart:i+1], "\n")
			ev := parseSingleEvent(objBlock)
			if ev != nil {
				events = append(events, *ev)
			}
			inObj = false
		}
		// Also handle single-line event or array
		_ = lineNum
	}

	// Handle single event (not in array)
	if len(events) == 0 && strings.TrimSpace(output) != "" {
		// Try parsing entire output as single event
		ev := parseSingleEvent(output)
		if ev != nil {
			events = append(events, *ev)
		}
	}

	return events
}

func parseSingleEvent(jsonStr string) *WinEvent {
	ev := &WinEvent{}

	// Simple regex parsing for PowerShell JSON output
	tsRe := regexp.MustCompile(`"TimeCreated"\s*:\s*"([^"]+)"`)
	idRe := regexp.MustCompile(`"Id"\s*:\s*(\d+)`)
	provRe := regexp.MustCompile(`"ProviderName"\s*:\s*"([^"]*)"`)
	levelRe := regexp.MustCompile(`"LevelDisplayName"\s*:\s*"([^"]*)"`)
	msgRe := regexp.MustCompile(`"Message"\s*:\s*"((?:[^"\\]|\\.)*)"`)

	if m := tsRe.FindStringSubmatch(jsonStr); len(m) > 1 {
		t, err := time.Parse("2006-01-02T15:04:05", m[1][:19])
		if err == nil {
			ev.Timestamp = t
		}
	}

	if m := idRe.FindStringSubmatch(jsonStr); len(m) > 1 {
		ev.EventID, _ = strconv.Atoi(m[1])
	}

	if m := provRe.FindStringSubmatch(jsonStr); len(m) > 1 {
		ev.Provider = m[1]
	}

	if m := levelRe.FindStringSubmatch(jsonStr); len(m) > 1 {
		ev.Level = m[1]
	}

	if m := msgRe.FindStringSubmatch(jsonStr); len(m) > 1 {
		ev.Message = strings.ReplaceAll(m[1], `\n`, "\n")
		ev.Message = strings.ReplaceAll(ev.Message, `\r`, "")
		ev.Message = strings.ReplaceAll(ev.Message, `\"`, "\"")
	}

	if ev.EventID == 0 && ev.Message == "" {
		return nil
	}

	return ev
}

func (wm *WinEventMonitor) cleanupSeen() {
	wm.mu.Lock()
	defer wm.mu.Unlock()

	cutoff := time.Now().Add(-10 * time.Minute)
	for id, t := range wm.seenEvents {
		if t.Before(cutoff) {
			delete(wm.seenEvents, id)
		}
	}
}
