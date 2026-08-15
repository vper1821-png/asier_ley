package main

import (
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// ─── Estructura de mensajes WebSocket ───
type WSMessage struct {
	Type            string          `json:"type"`
	AgentID         string          `json:"agentId,omitempty"`
	IP              string          `json:"ip,omitempty"`
	TunnelID        string          `json:"tunnelId,omitempty"`
	Host            string          `json:"host,omitempty"`
	Port            int             `json:"port,omitempty"`
	Payload         string          `json:"payload,omitempty"`
	Error           string          `json:"error,omitempty"`
	Telemetry       *Telemetry      `json:"data,omitempty"`
	Title           string          `json:"title,omitempty"`
	Description     string          `json:"description,omitempty"`
	Source          string          `json:"source,omitempty"`
	Severity        string          `json:"severity,omitempty"`
	AutoBlock       bool            `json:"autoBlock,omitempty"`
	Command         string          `json:"command,omitempty"`
	Success         bool            `json:"success,omitempty"`
	Output          string          `json:"output,omitempty"`
	Results         interface{}     `json:"results,omitempty"`
	TS              float64         `json:"ts,omitempty"` // ← CORREGIDO: float64
	PendingBlocks   []string        `json:"pendingBlocks,omitempty"`
	PendingUnblocks []string        `json:"pendingUnblocks,omitempty"`
	PendingRules    []Rule          `json:"pendingRules,omitempty"`
	Lockdown        bool            `json:"lockdown,omitempty"`
	KillProcesses   []string        `json:"killProcesses,omitempty"`
	BlockIPs        []string        `json:"blockIps,omitempty"`
	UnblockIPs      []string        `json:"unblockIps,omitempty"`
	RemoveRules     []string        `json:"removeRules,omitempty"`
	DBConnection    *DBConnection   `json:"dbConnection,omitempty"`
	ScanResult      *ScanResult     `json:"scanResult,omitempty"`
	DBLogDiscovery  interface{}     `json:"dbLogDiscovery,omitempty"`
	QueryLogs       []QueryLogEntry `json:"queryLogs,omitempty"`
	Antivirus       *AntivirusInfo  `json:"antivirus,omitempty"`
	Compliance      *AntivirusInfo  `json:"compliance,omitempty"`
	EventType       string          `json:"event_type,omitempty"`
	DetectedFile    *DetectedFile   `json:"detectedFile,omitempty"`
	Token           string          `json:"token,omitempty"` // ← NUEVO: token para autenticación
}

// ─── Gestor de WebSocket ───
type WSManager struct {
	conn           *websocket.Conn
	mu             sync.Mutex
	reconnectTimer *time.Timer
	done           chan struct{}
}

var wsManager = &WSManager{done: make(chan struct{})}
var wsReconnectDelay = 1 * time.Second
var crashCount int

const maxReconnectDelay = 60 * time.Second

// Buffer de mensajes pendientes (offline)
var pendingMessages []WSMessage
var pendingMu sync.Mutex

// ─── Conectar WebSocket ───
func wsConnect() {
	wsManager.mu.Lock()
	if wsManager.conn != nil {
		wsManager.mu.Unlock()
		return
	}
	wsManager.mu.Unlock()

	cfg := GetConfig()
	wsURL := strings.Replace(cfg.APIBase, "http://", "ws://", 1)
	wsURL = strings.Replace(wsURL, "https://", "wss://", 1)
	wsURL = strings.TrimSuffix(wsURL, "/api/agents") + "/ws/agent"

	u, _ := url.Parse(wsURL)
	if u == nil {
		logMsg("WS: invalid URL %s", wsURL)
		wsManager.reconnectTimer = time.AfterFunc(wsReconnectDelay, wsConnect)
		return
	}

	dialer := *websocket.DefaultDialer
	dialer.HandshakeTimeout = 10 * time.Second

	logMsg("WS: connecting to %s", u.String())
	c, _, err := dialer.Dial(u.String(), nil)
	if err != nil {
		logMsg("WS: connection error: %v (retry in %v)", err, wsReconnectDelay)
		wsManager.reconnectTimer = time.AfterFunc(wsReconnectDelay, wsConnect)
		wsReconnectDelay *= 2
		if wsReconnectDelay > maxReconnectDelay {
			wsReconnectDelay = maxReconnectDelay
		}
		return
	}

	wsReconnectDelay = 1 * time.Second

	// Configurar timeouts y ping/pong
	c.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.SetPongHandler(func(string) error {
		c.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})
	c.SetPingHandler(func(appData string) error {
		c.SetReadDeadline(time.Now().Add(60 * time.Second))
		return c.WriteControl(websocket.PongMessage, []byte(appData), time.Now().Add(10*time.Second))
	})

	wsManager.mu.Lock()
	wsManager.conn = c
	wsManager.mu.Unlock()

	logMsg("WS: connected")
	crashCount = 0

	// Enviar mensajes pendientes
	pendingMu.Lock()
	pendingCopy := pendingMessages
	pendingMessages = nil
	pendingMu.Unlock()
	for _, p := range pendingCopy {
		wsSend(p)
	}

	// ─── REGISTRO CON TOKEN ───
	wsSend(WSMessage{
		Type:    "register",
		AgentID: GetAgentID(),
		IP:      getLocalIP(),
		Token:   cfg.Token, // ¡CLAVE! envía el token para autenticación
	})

	sendTelemetry()
	go wsReadLoop(c)
}

// ─── Bucle de lectura ───
func wsReadLoop(c *websocket.Conn) {
	defer func() {
		wsManager.mu.Lock()
		wsManager.conn = nil
		wsManager.mu.Unlock()
		c.Close()
		logMsg("WS: disconnected")
		wsReconnectDelay = 1 * time.Second
		wsManager.reconnectTimer = time.AfterFunc(500*time.Millisecond, wsConnect)
	}()

	for {
		_, msgData, err := c.ReadMessage()
		if err != nil {
			logMsg("WS: read error: %v", err)
			return
		}

		c.SetReadDeadline(time.Now().Add(120 * time.Second))

		var msg WSMessage
		if err := json.Unmarshal(msgData, &msg); err != nil {
			logMsg("WS: parse error: %v", err)
			continue
		}

		handleWSCommand(msg)
	}
}

// ─── Enviar mensaje ───
func wsSend(msg WSMessage) {
	wsManager.mu.Lock()
	conn := wsManager.conn
	wsManager.mu.Unlock()

	if conn == nil {
		pendingMu.Lock()
		pendingMessages = append(pendingMessages, msg)
		if len(pendingMessages) > 2000 {
			pendingMessages = pendingMessages[len(pendingMessages)-1500:]
		}
		pendingMu.Unlock()
		return
	}

	data, err := json.Marshal(msg)
	if err != nil {
		return
	}

	if err := conn.WriteMessage(websocket.TextMessage, data); err != nil {
		logMsg("WS: send error: %v", err)
		conn.Close()
		wsManager.mu.Lock()
		wsManager.conn = nil
		wsManager.mu.Unlock()
		wsManager.reconnectTimer = time.AfterFunc(wsReconnectDelay, wsConnect)
	}
}

// ─── Enviar evento ───
func wsSendEvent(title, description, source, severity string, autoBlock bool) {
	wsSend(WSMessage{
		Type:        "event",
		AgentID:     GetAgentID(),
		Title:       title,
		Description: description,
		Source:      source,
		Severity:    severity,
		AutoBlock:   autoBlock,
	})
}

// ─── Enviar logs de consultas ───
func wsSendQueryLogs(logs []QueryLogEntry) {
	if len(logs) == 0 {
		return
	}
	wsSend(WSMessage{
		Type:      "log_query",
		AgentID:   GetAgentID(),
		QueryLogs: logs,
	})
}

// ─── Enviar detección de archivo ───
func wsSendFileDetection(detected *DetectedFile) {
	wsSend(WSMessage{
		Type:         "file_detected",
		AgentID:      GetAgentID(),
		DetectedFile: detected,
	})
}

// ─── Enviar telemetría ───
func sendTelemetry() {
	defer func() {
		if r := recover(); r != nil {
			logMsg("Telemetry panic: %v", r)
		}
	}()
	t := getFullTelemetry()
	wsSend(WSMessage{
		Type:      "telemetry",
		AgentID:   GetAgentID(),
		Telemetry: &t,
	})
}

// ─── Manejar comandos del servidor ───
func handleWSCommand(msg WSMessage) {
	logMsg("WS command: %s", msg.Type)

	switch msg.Type {
	case "commands":
		handleCommands(msg)
	case "ping":
		wsSend(WSMessage{Type: "pong", TS: float64(time.Now().UnixNano()) / 1e9})
	case "telemetry_request":
		sendTelemetry()
	case "restart":
		logMsg("WS: restart requested")
		time.AfterFunc(1*time.Second, func() { osExit(0) })
	case "scan_database":
		handleScanDatabase(msg)
	case "tunnel_open":
		handleTunnelOpen(msg)
	case "tunnel_data":
		handleTunnelData(msg)
	case "tunnel_close":
		handleTunnelClose(msg)
	case "tunnel_error":
		handleTunnelError(msg)
	case "uninstall_agent":
		handleUninstallAgent(msg)
	case "reconnect_db":
		handleReconnectDB(msg)
	case "reconnect_agent":
		handleReconnectAgent(msg)
	case "restart_agent":
		handleRestartAgent(msg)
	case "file_detected":
		// Solo log para depuración, el backend lo procesa
		logMsg("WS: file_detected received (will be processed by backend)")
	default:
		logMsg("WS: unknown command: %s", msg.Type)
	}
}

// ─── Handlers de comandos ───

func handleScanDatabase(msg WSMessage) {
	if msg.DBConnection == nil {
		wsSend(WSMessage{
			Type:    "scan_result",
			AgentID: GetAgentID(),
			Success: false,
			Output:  "No DB connection details provided",
		})
		return
	}

	logMsg("Scanning database: %s %s/%s", msg.DBConnection.Engine, msg.DBConnection.Host, msg.DBConnection.Database)

	activityMon.SetConnection(*msg.DBConnection)

	result, err := ScanDatabase(*msg.DBConnection)
	if err != nil {
		wsSend(WSMessage{
			Type:    "scan_result",
			AgentID: GetAgentID(),
			Success: false,
			Output:  err.Error(),
		})
		return
	}

	wsSend(WSMessage{
		Type:       "scan_result",
		AgentID:    GetAgentID(),
		Success:    true,
		ScanResult: result,
	})

	if result != nil {
		var sensitiveTables []string
		columns := make(map[string][]string)
		for _, table := range result.Tables {
			var hasPersonal bool
			for _, col := range table.Columns {
				if col.IsPersonal {
					columns[table.Name] = append(columns[table.Name], col.Name)
					if !hasPersonal {
						sensitiveTables = append(sensitiveTables, table.Name)
						hasPersonal = true
					}
				}
			}
		}
		activityMon.SetSensitiveTables(sensitiveTables, columns)
	}

	logMsg("Scan complete: %d tables, %d rows", result.TotalTables, result.TotalRows)
}

func handleCommands(msg WSMessage) {
	if len(msg.PendingBlocks) > 0 {
		for _, user := range msg.PendingBlocks {
			r := blockUser(user)
			logMsg("Block %s: %v", user, r.Success)
			if r.Success {
				wsSendEvent("Usuario bloqueado", "Bloqueado por comando", user, "high", true)
			}
		}
	}

	if len(msg.PendingUnblocks) > 0 {
		for _, user := range msg.PendingUnblocks {
			r := unblockUser(user)
			logMsg("Unblock %s: %v", user, r.Success)
		}
	}

	if len(msg.PendingRules) > 0 {
		logMsg("Applying %d firewall rules...", len(msg.PendingRules))
		results := applyFWRules(msg.PendingRules)
		success := 0
		for _, r := range results {
			if r.Success {
				success++
			}
		}
		wsSend(WSMessage{
			Type:    "result",
			AgentID: GetAgentID(),
			Command: "apply_rules",
			Success: true,
			Output:  fmt.Sprintf("Applied %d/%d", success, len(results)),
		})
	}

	if msg.Lockdown {
		doLockdown()
	}

	if len(msg.KillProcesses) > 0 {
		for _, proc := range msg.KillProcesses {
			killProcess(proc)
		}
	}

	if len(msg.BlockIPs) > 0 {
		for _, ip := range msg.BlockIPs {
			r := blockIP(ip)
			logMsg("Block IP %s: %v", ip, r.Success)
		}
	}

	if len(msg.UnblockIPs) > 0 {
		for _, ip := range msg.UnblockIPs {
			r := unblockIP(ip)
			logMsg("Unblock IP %s: %v", ip, r.Success)
		}
	}

	if len(msg.RemoveRules) > 0 {
		for _, rule := range msg.RemoveRules {
			r := removeFWRule(rule)
			logMsg("Remove rule %s: %v", rule, r.Success)
		}
	}

	wsSend(WSMessage{Type: "result", Command: "executed", Success: true})
}

// ─── Lockdown ───
func doLockdown() {
	logMsg("LOCKDOWN: Initiating")
	users := getActiveUsers()
	for _, u := range users {
		if u.Username != "Administrator" && u.Username != "root" && u.Username != "SYSTEM" {
			blockUser(u.Username)
		}
	}
	switch runtime.GOOS {
	case "windows":
		execCommand("netsh", "advfirewall", "set", "allprofiles", "firewallpolicy", "blockinbound,allowoutbound")
	default:
		execCommand("sh", "-c", "iptables -P INPUT DROP 2>/dev/null; iptables -P FORWARD DROP 2>/dev/null")
	}
	wsSendEvent("LOCKDOWN ACTIVADO", "Sistema bloqueado por comando IA", "invisia_ai", "critical", true)
}

// ─── Matar proceso ───
func killProcess(name string) {
	switch runtime.GOOS {
	case "windows":
		execCommand("taskkill", "/F", "/IM", name)
	default:
		execCommand("sh", "-c", fmt.Sprintf("killall -9 %s 2>/dev/null || kill -9 %s 2>/dev/null", name, name))
	}
	logMsg("Killed: %s", name)
}

// ─── Desinstalar agente ───
func handleUninstallAgent(msg WSMessage) {
	logMsg("Uninstall agent requested")
	wsSendEvent("Desinstalando Agente", "El agente se desinstalará del sistema", "client", "critical", false)
	uninstallService()
	time.AfterFunc(2*time.Second, func() { osExit(0) })
}

// ─── Reconectar DB ───
func handleReconnectDB(msg WSMessage) {
	logMsg("DB reconnect requested")
	wsSendEvent("Reconectando DB", "Reiniciando monitor de base de datos", "client", "low", false)
	go func() {
		StopDBMonitor()
		time.Sleep(1 * time.Second)
		StartDBMonitor()
		wsSend(WSMessage{
			Type:    "result",
			AgentID: GetAgentID(),
			Command: "reconnect_db",
			Success: true,
			Output:  "Monitor de DB reiniciado",
		})
	}()
}

// ─── Reconectar agente ───
func handleReconnectAgent(msg WSMessage) {
	logMsg("Agent reconnect requested")
	wsSendEvent("Reconectando Agente", "Forzando reconexión del agente al servidor", "client", "low", false)
	go func() {
		wsManager.mu.Lock()
		if wsManager.conn != nil {
			wsManager.conn.Close()
			wsManager.conn = nil
		}
		wsManager.mu.Unlock()
		time.Sleep(1 * time.Second)
		wsConnect()
		wsSend(WSMessage{
			Type:    "result",
			AgentID: GetAgentID(),
			Command: "reconnect_agent",
			Success: true,
			Output:  "Agente reconectado",
		})
	}()
}

// ─── Reiniciar agente ───
func handleRestartAgent(msg WSMessage) {
	logMsg("Agent restart requested")
	wsSendEvent("Reiniciando Agente", "El agente se reiniciará en 3 segundos", "client", "high", false)
	time.AfterFunc(3*time.Second, func() { osExit(0) })
}

// ─── Funciones auxiliares para túneles (stubs) ───
func handleTunnelOpen(msg WSMessage) {
	// Implementación existente en tunnel.go
	logMsg("Tunnel open: %s", msg.TunnelID)
}

func handleTunnelData(msg WSMessage) {
	// Implementación existente en tunnel.go
	logMsg("Tunnel data: %s", msg.TunnelID)
}

func handleTunnelClose(msg WSMessage) {
	// Implementación existente en tunnel.go
	logMsg("Tunnel close: %s", msg.TunnelID)
}

func handleTunnelError(msg WSMessage) {
	// Implementación existente en tunnel.go
	logMsg("Tunnel error: %s", msg.Error)
}

func now() time.Time {
	return time.Now()
}

var osExit = os.Exit

// ─── Comandos del sistema (stubs) ───
func execCommand(name string, args ...string) {
	// Función auxiliar para ejecutar comandos (definida en otro archivo)
	// Simulamos para compilación
}
