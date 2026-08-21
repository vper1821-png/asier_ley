package ws

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/models"
	"securelab-agent/internal/queue"
	"securelab-agent/internal/security"
	"securelab-agent/internal/sysinfo"

	"github.com/gorilla/websocket"
)

type Client struct {
	mu           sync.Mutex
	conn         *websocket.Conn
	url          string
	token        string
	agentID      string
	log          *logger.Logger
	sendChan     chan interface{}      // Normal priority (telemetry, events)
	priorityChan chan interface{}      // High priority (commands, responses)
	done         chan struct{}
	queue        *queue.Queue
	ctx          context.Context
	cancel       context.CancelFunc
	wg           sync.WaitGroup
}

func NewClient(url, token string, log *logger.Logger, q *queue.Queue) *Client {
	ctx, cancel := context.WithCancel(context.Background())
	return &Client{
		url:          url,
		token:        token,
		log:          log,
		sendChan:     make(chan interface{}, 5000),     // Increased buffer
		priorityChan: make(chan interface{}, 1000),     // High priority buffer
		done:         make(chan struct{}),
		queue:        q,
		ctx:          ctx,
		cancel:       cancel,
	}
}

func (c *Client) SetAgentID(id string) {
	c.agentID = id
	c.log.Info("WS: agentID establecido a %s", id)
}

func (c *Client) Connect() {
	for {
		select {
		case <-c.ctx.Done():
			return
		default:
		}

		// Validar que tenemos token y agentID
		if c.token == "" {
			c.log.Error("WS: token vacio, no se puede conectar")
			time.Sleep(1 * time.Second) // Reduced from 5s
			continue
		}
		if c.agentID == "" {
			c.log.Error("WS: agentID vacio, no se puede conectar")
			time.Sleep(1 * time.Second) // Reduced from 5s
			continue
		}

		c.log.Info("WS: conectando a %s", c.url)
		conn, _, err := websocket.DefaultDialer.Dial(c.url, nil)
		if err != nil {
			c.log.Error("WS: error de conexion: %v. Reintentando en 1s...", err)
			time.Sleep(1 * time.Second) // Reduced from 5s
			continue
		}

		c.mu.Lock()
		c.conn = conn
		c.mu.Unlock()

		c.log.Info("WS: conexion establecida, enviando registro...")

		// Registrar agente
		if err := c.sendRegister(); err != nil {
			c.log.Error("WS: error en registro: %v", err)
			c.closeConn()
			time.Sleep(1 * time.Second) // Reduced from 5s
			continue
		}

		c.log.Info("WS: registro enviado, esperando respuesta...")

		c.wg.Add(2)
		go c.readLoop()
		go c.writeLoop()

		// Esperar hasta que se cierre la conexion
		<-c.done
		c.wg.Wait()

		c.log.Warn("WS: conexion perdida. Reintentando en 1s...")
		time.Sleep(1 * time.Second) // Reduced from 5s
	}
}

func (c *Client) sendRegister() error {
	c.log.Info("WS: preparando mensaje register (token: %s..., agentID: %s)", c.token[:20], c.agentID)

	msg := map[string]interface{}{
		"type": "register",
		"payload": map[string]string{
			"token":   c.token,
			"agentId": c.agentID,
		},
	}

	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn == nil {
		return fmt.Errorf("no connection")
	}

	c.log.Info("WS: enviando mensaje: %+v", msg)
	err := c.conn.WriteJSON(msg)
	if err != nil {
		c.log.Error("WS: error al escribir registro: %v", err)
		return err
	}
	c.log.Info("WS: mensaje register enviado correctamente")
	return nil
}

func (c *Client) readLoop() {
	defer c.wg.Done()
	for {
		_, msg, err := c.conn.ReadMessage()
		if err != nil {
			c.log.Error("WS: error de lectura: %v", err)
			c.closeConn()
			return
		}
		c.log.Debug("WS: mensaje recibido: %s", string(msg))
		c.handleMessage(msg)
	}
}

func (c *Client) writeLoop() {
	defer c.wg.Done()
	// Flush queued events immediately on connect, then every 200ms for responsiveness
	flushTicker := time.NewTicker(200 * time.Millisecond)
	defer flushTicker.Stop()

	for {
		select {
		// High priority: command responses, interactive commands - SEND IMMEDIATELY
		case msg := <-c.priorityChan:
			c.mu.Lock()
			if c.conn == nil {
				c.mu.Unlock()
				if data, err := json.Marshal(msg); err == nil {
					c.queue.Enqueue("priority", string(data))
				}
				continue
			}
			err := c.conn.WriteJSON(msg)
			c.mu.Unlock()
			if err != nil {
				c.log.Error("WS: error al escribir (priority): %v", err)
				c.closeConn()
				if data, err := json.Marshal(msg); err == nil {
					c.queue.Enqueue("priority", string(data))
				}
				return
			}

		// Normal priority: telemetry, file events, etc.
		case msg := <-c.sendChan:
			c.mu.Lock()
			if c.conn == nil {
				c.mu.Unlock()
				if data, err := json.Marshal(msg); err == nil {
					c.queue.Enqueue("direct", string(data))
				}
				continue
			}
			err := c.conn.WriteJSON(msg)
			c.mu.Unlock()
			if err != nil {
				c.log.Error("WS: error al escribir: %v", err)
				c.closeConn()
				if data, err := json.Marshal(msg); err == nil {
					c.queue.Enqueue("direct", string(data))
				}
				return
			}

		// Periodic flush of queued events
		case <-flushTicker.C:
			if c.queue == nil {
				continue
			}
			// Flush all queued events (priority first via queue ordering)
			pending, err := c.queue.Dequeue(200)
			if err != nil {
				c.log.Error("WS: error al obtener eventos pendientes: %v", err)
				continue
			}
			for _, ev := range pending {
				var payload interface{}
				if err := json.Unmarshal([]byte(ev.Payload), &payload); err != nil {
					c.log.Error("WS: error deserializando evento pendiente: %v", err)
					continue
				}
				msg := map[string]interface{}{
					"type":    ev.EventType,
					"payload": payload,
				}
				c.mu.Lock()
				if c.conn == nil {
					c.mu.Unlock()
					continue
				}
				err := c.conn.WriteJSON(msg)
				c.mu.Unlock()
				if err != nil {
					c.log.Error("WS: error enviando evento pendiente: %v", err)
					continue
				}
				c.queue.MarkAsSent(ev.ID)
			}

		case <-c.done:
			return
		case <-c.ctx.Done():
			return
		}
	}
}

func (c *Client) closeConn() {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.conn != nil {
		c.conn.Close()
		c.conn = nil
	}
	select {
	case <-c.done:
	default:
		close(c.done)
	}
}

func (c *Client) Close() {
	c.cancel()
	c.closeConn()
	c.wg.Wait()
	c.log.Info("WS: cerrado")
}

// ─── Envío de eventos ────────────────────────────────────────────

// sendPriority usa el canal de alta prioridad para respuestas de comandos y acciones interactivas
func (c *Client) sendPriority(typ string, payload interface{}) {
	msg := map[string]interface{}{
		"type":    typ,
		"payload": payload,
	}
	select {
	case c.priorityChan <- msg:
	default:
		if c.queue != nil {
			if err := c.queue.Enqueue("priority_"+typ, payload); err != nil {
				c.log.Error("WS: error guardando evento prioritario en cola: %v", err)
			}
		}
	}
}

func (c *Client) send(typ string, payload interface{}) {
	msg := map[string]interface{}{
		"type":    typ,
		"payload": payload,
	}
	select {
	case c.sendChan <- msg:
	default:
		if c.queue != nil {
			if err := c.queue.Enqueue(typ, payload); err != nil {
				c.log.Error("WS: error guardando evento en cola: %v", err)
			}
		}
	}
}

func (c *Client) SendFileEvent(ev audit.FileEvent) {
	payload := map[string]interface{}{
		"agentId":     c.agentID,
		"timestamp":   ev.Timestamp,
		"path":        ev.Path,
		"eventType":   ev.EventType,
		"process":     ev.ProcessName,
		"pid":         ev.PID,
		"user":        ev.User,
		"size":        ev.Size,
		"hash":        ev.Hash,
		"destination": ev.Destination,
	}
	c.send("file_event", payload)
}

func (c *Client) SendFileDetection(ev audit.FileEvent) {
	payload := map[string]interface{}{
		"agentId":      c.agentID,
		"timestamp":    ev.Timestamp,
		"path":         ev.Path,
		"hash":         ev.Hash,
		"eventType":    ev.EventType,
		"process":      ev.ProcessName,
		"pid":          ev.PID,
		"user":         ev.User,
		"size":         ev.Size,
		"personalData": ev.PersonalData,
		"sensitive":    ev.Sensitive,
		"fileType":     getFileType(ev.Path),
		"hostname":     getHostname(),
		"rowCount":     0,
	}
	c.send("file_detected", payload)
}

func (c *Client) SendDBQuery(entry audit.DBQueryEntry) {
	payload := map[string]interface{}{
		"agentId":   c.agentID,
		"timestamp": entry.Timestamp,
		"engine":    entry.Engine,
		"database":  entry.Database,
		"user":      entry.User,
		"host":      entry.Host,
		"query":     entry.Query,
		"operation": entry.Operation,
		"riskScore": entry.RiskScore,
	}
	c.send("db_query", payload)
}

func (c *Client) SendHostEvent(ev audit.HostEvent) {
	payload := map[string]interface{}{
		"agentId":   c.agentID,
		"timestamp": ev.Timestamp,
		"type":      ev.Type,
		"severity":  ev.Severity,
		"title":     ev.Title,
		"detail":    ev.Detail,
		"source":    ev.Source,
	}
	c.send("host_event", payload)
}

func (c *Client) SendWindowsEvent(ev audit.WindowsEvent) {
	payload := map[string]interface{}{
		"agentId":   c.agentID,
		"timestamp": ev.Timestamp,
		"channel":   ev.Channel,
		"provider":  ev.Provider,
		"eventId":   ev.EventID,
		"level":     ev.Level,
		"message":   ev.Message,
	}
	c.send("windows_event", payload)
}

func (c *Client) SendTelemetry(data models.TelemetryData) {
	payload := map[string]interface{}{
		"agentId":     c.agentID,
		"timestamp":   data.Timestamp,
		"cpu":         data.CPU,
		"memory":      data.Memory,
		"diskFree":    data.DiskFree,
		"diskTotal":   data.DiskTotal,
		"processes":   data.Processes,
		"connections": data.Connections,
		"hostname":    data.Hostname,
		"platform":    data.Platform,
		"arch":        data.Arch,
		"os":          data.OS,
		"user":        data.User,
		"uptime":      data.Uptime,
	}
	c.send("telemetry", payload)
}

func (c *Client) SendEvent(title, description, source, severity string) {
	payload := map[string]interface{}{
		"agentId":     c.agentID,
		"timestamp":   time.Now().UTC(),
		"title":       title,
		"description": description,
		"source":      source,
		"severity":    severity,
	}
	c.send("event", payload)
}

// ─── Manejo de mensajes entrantes ──────────────────────────────

func (c *Client) handleMessage(data []byte) {
	var msg map[string]interface{}
	if err := json.Unmarshal(data, &msg); err != nil {
		c.log.Error("WS: mensaje invalido: %v", err)
		return
	}
	typ, _ := msg["type"].(string)
	switch typ {
	case "command":
		c.handleCommand(msg)
	case "sync_response":
		c.handleSyncResponse(msg)
	case "ping":
		c.sendPriority("pong", map[string]interface{}{"ts": time.Now().Unix()}) // High priority for pong
	case "welcome":
		c.log.Info("WS: servidor envio bienvenida: %v", msg["payload"])
	case "registered":
		c.log.Info("WS: agente registrado correctamente: %v", msg["payload"])
	case "file_response":
		c.log.Info("WS: respuesta de archivo: %v", msg["payload"])
	case "error":
		c.log.Error("WS: servidor reporto error: %v", msg["payload"])
	default:
		c.log.Debug("WS: mensaje desconocido: %s", typ)
	}
}

// SendSync pide al servidor comandos pendientes y el estado de bloqueo.
func (c *Client) SendSync() {
	c.sendPriority("sync", map[string]interface{}{}) // High priority for sync
}

// StartSyncLoop envia sync periodicamente mientras la conexion este activa.
func (c *Client) StartSyncLoop(interval time.Duration) {
	ticker := time.NewTicker(interval)
	go func() {
		defer ticker.Stop()
		for {
			select {
			case <-ticker.C:
				c.SendSync()
			case <-c.ctx.Done():
				return
			}
		}
	}()
}

func (c *Client) handleSyncResponse(msg map[string]interface{}) {
	payload, _ := msg["payload"].(map[string]interface{})

	// Sincronizar estado de bloqueo
	if ld, ok := payload["lockdown"].(map[string]interface{}); ok {
		enabled, _ := ld["enabled"].(bool)
		message, _ := ld["message"].(string)
		if enabled && !security.IsLockdownActive() {
			c.log.Warn("WS: servidor solicita BLOQUEO TOTAL del equipo")
			security.Lockdown(message)
			c.log.Warn("WS: equipo bloqueado por el servidor")
		}
		if !enabled && security.IsLockdownActive() {
			if security.TimedLockPending() {
				c.log.Info("WS: bloqueo temporizado vigente, se ignora desbloqueo del servidor")
			} else {
				c.log.Info("WS: servidor solicita DESBLOQUEO del equipo")
				security.Unlock()
				c.log.Info("WS: equipo desbloqueado por el servidor")
			}
		}
	}

	// Ejecutar comandos pendientes ASYNCHRONOUSLY to not block readLoop
	if cmds, ok := payload["pendingCommands"].([]interface{}); ok {
		for _, raw := range cmds {
			cm, _ := raw.(map[string]interface{})
			command, _ := cm["command"].(string)
			commandId, _ := cm["commandId"].(string)
			params, _ := cm["params"].(map[string]interface{})
			if command == "" {
				continue
			}
			// Execute in goroutine to not block
			go c.executeCommandAsync(command, params, commandId)
		}
	}
}

func (c *Client) handleCommand(msg map[string]interface{}) {
	payload, ok := msg["payload"].(map[string]interface{})
	if !ok {
		c.log.Error("WS: comando sin payload")
		return
	}
	command, _ := payload["command"].(string)
	params, _ := payload["params"].(map[string]interface{})
	commandId, _ := payload["commandId"].(string)

	// Execute in goroutine to not block readLoop
	go c.executeCommandAsync(command, params, commandId)
}

// executeCommandAsync ejecuta el comando en goroutine y envia respuesta por canal prioritario
func (c *Client) executeCommandAsync(command string, params map[string]interface{}, commandId string) {
	result, err := c.ExecuteCommand(command, params, commandId)

	c.sendPriority("command_response", map[string]interface{}{
		"commandId": commandId,
		"status":    "success",
		"result":    result,
		"error":     err != nil,
	})
	if err != nil {
		c.log.Error("WS: error ejecutando comando %s: %v", command, err)
	} else {
		c.log.Info("WS: comando ejecutado: %s", command)
	}
}

// ExecuteCommand ejecuta un comando de gestion remota y devuelve el resultado.
func (c *Client) ExecuteCommand(command string, params map[string]interface{}, commandId string) (string, error) {
	var result string
	var err error

	switch command {
	case "request_data":
		dtype, _ := params["type"].(string)
		var data interface{}
		switch dtype {
		case "processes":
			data = sysinfo.GetProcesses()
			result = "Procesos enviados"
		case "health":
			data = sysinfo.GetHealth()
			result = "Snapshot de salud enviado"
		case "defender":
			data = sysinfo.GetDefender()
			result = "Estado de seguridad enviado"
		case "screenshot":
			shot, serr := sysinfo.CaptureScreenshot()
			if serr != nil {
				err = serr
			} else {
				data = map[string]interface{}{"image": shot}
				result = "Captura enviada"
			}
		default:
			err = fmt.Errorf("tipo de datos no soportado: %s", dtype)
		}
		if err == nil {
			c.sendPriority("data_response", map[string]interface{}{ // High priority for data response
				"agentId": c.agentID,
				"type":    dtype,
				"data":    data,
				"ts":      time.Now().Unix(),
			})
		}
	case "kill_process":
		pidStr, _ := params["pid"].(string)
		if pidStr == "" {
			err = fmt.Errorf("pid requerido")
		} else {
			cmd := exec.Command("taskkill", "/F", "/PID", pidStr)
			if o, cerr := cmd.CombinedOutput(); cerr != nil {
				err = fmt.Errorf("no se pudo terminar el proceso: %s", string(o))
			} else {
				result = "Proceso terminado (PID " + pidStr + ")"
			}
		}
	case "power_off":
		exec.Command("shutdown", "/s", "/t", "5", "/c", "SecureLab: el DPO solicito apagar este equipo").Run() // Reduced from 15s
		result = "Apagando el equipo en 5 segundos..."
	case "power_restart":
		exec.Command("shutdown", "/r", "/t", "5", "/c", "SecureLab: el DPO solicito reiniciar este equipo").Run() // Reduced from 15s
		result = "Reiniciando el equipo en 5 segundos..."
	case "power_suspend":
		exec.Command("rundll32.exe", "powrprof.dll,SetSuspendState", "0,1,0").Run()
		result = "Suspender el equipo"
	case "lock_timed":
		msg, _ := params["message"].(string)
		minutes, _ := params["minutes"].(float64)
		security.LockdownTimed(msg, int(minutes))
		result = "Equipo bloqueado temporalmente"
	case "lock_timed_silent":
		msg, _ := params["message"].(string)
		minutes, _ := params["minutes"].(float64)
		security.LockdownTimedSilent(msg, int(minutes))
		result = "Equipo bloqueado temporalmente (sin sonido)"
	case "lockdown":
		msg, _ := params["message"].(string)
		security.Lockdown(msg)
		result = "Equipo BLOQUEADO por seguridad (persistente)"
	case "lockdown_silent":
		msg, _ := params["message"].(string)
		security.LockdownSilent(msg)
		result = "Equipo BLOQUEADO sin sonido (persistente)"
	case "unlock":
		security.Unlock()
		result = "Equipo desbloqueado"
	case "alarm":
		security.PlayAlarm()
		result = "Alarma de intruso activada a maximo volumen"
	case "alarm_stop":
		security.StopAlarm()
		result = "Alarma detenida"
	case "speak":
		text, _ := params["text"].(string)
		security.Speak(text)
		result = "Mensaje reproducido"
	case "block_user":
		username, _ := params["username"].(string)
		err = security.BlockUser(username)
		if err == nil {
			result = "Usuario bloqueado"
		}
	case "unblock_user":
		username, _ := params["username"].(string)
		err = security.UnblockUser(username)
		if err == nil {
			result = "Usuario desbloqueado"
		}
	case "block_ip":
		ip, _ := params["ip"].(string)
		err = security.BlockIP(ip)
		if err == nil {
			result = "IP bloqueada"
		}
	case "unblock_ip":
		ip, _ := params["ip"].(string)
		err = security.UnblockIP(ip)
		if err == nil {
			result = "IP desbloqueada"
		}
	case "apply_firewall_rule":
		action, _ := params["action"].(string)
		protocol, _ := params["protocol"].(string)
		port, _ := params["port"].(string)
		direction, _ := params["direction"].(string)
		err = security.ApplyFirewallRule(action, protocol, port, direction)
		if err == nil {
			result = "Regla de firewall aplicada"
		}
	case "restart":
		result = "Reiniciando..."
		go func() {
			time.Sleep(500 * time.Millisecond) // Faster restart
			c.log.Info("WS: reinicio solicitado")
		}()
	case "shell_exec":
		shellCmd, _ := params["command"].(string)
		if shellCmd == "" {
			err = fmt.Errorf("command requerido")
		} else {
			c.log.Info("WS: ejecutando shell: %s", shellCmd)
			// Reduced timeout from 30s to 15s for faster failure detection
			ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
			defer cancel()
			var cmd *exec.Cmd
			if runtime.GOOS == "windows" {
				cmd = exec.CommandContext(ctx, "powershell", "-NoProfile", "-NonInteractive", "-Command", shellCmd)
			} else {
				cmd = exec.CommandContext(ctx, "/bin/sh", "-c", shellCmd)
			}
			out, cmdErr := cmd.CombinedOutput()
			output := string(out)
			if len(output) > 8000 { // Increased from 4000
				output = output[:8000] + "\n... (truncado)"
			}
			if cmdErr != nil {
				result = output + "\n[ERROR] " + cmdErr.Error()
				err = cmdErr
			} else {
				result = output
			}
		}
	case "uninstall":
		result = "Desinstalando..."
		c.log.Info("WS: desinstalacion solicitada")
	default:
		err = fmt.Errorf("comando desconocido: %s", command)
	}

	return result, err
}

// ─── Helpers ──────────────────────────────────────────────────

func getFileType(path string) string {
	ext := filepath.Ext(path)
	switch ext {
	case ".xlsx", ".xls":
		return "excel"
	case ".csv":
		return "csv"
	case ".txt":
		return "txt"
	default:
		return "unknown"
	}
}

func getHostname() string {
	name, _ := os.Hostname()
	return name
}