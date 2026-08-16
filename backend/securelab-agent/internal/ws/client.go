package ws

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/models" // NUEVO
	"securelab-agent/internal/queue"
	"securelab-agent/internal/security"

	"github.com/gorilla/websocket"
)

// Client maneja la conexión WebSocket con el backend
type Client struct {
	mu       sync.Mutex
	conn     *websocket.Conn
	url      string
	token    string
	agentID  string
	log      *logger.Logger
	sendChan chan interface{}
	done     chan struct{}
	queue    *queue.Queue
	ctx      context.Context
	cancel   context.CancelFunc
	wg       sync.WaitGroup
}

// NewClient crea una nueva instancia del cliente WebSocket
func NewClient(url, token string, log *logger.Logger, q *queue.Queue) *Client {
	ctx, cancel := context.WithCancel(context.Background())
	return &Client{
		url:      url,
		token:    token,
		log:      log,
		sendChan: make(chan interface{}, 1000),
		done:     make(chan struct{}),
		queue:    q,
		ctx:      ctx,
		cancel:   cancel,
	}
}

// SetAgentID establece el ID del agente
func (c *Client) SetAgentID(id string) {
	c.agentID = id
}

// Connect establece la conexión WebSocket con reconexión automática
func (c *Client) Connect() {
	for {
		select {
		case <-c.ctx.Done():
			return
		default:
		}

		c.log.Info("WS: conectando a %s", c.url)
		conn, _, err := websocket.DefaultDialer.Dial(c.url, nil)
		if err != nil {
			c.log.Error("WS: error de conexión: %v. Reintentando en 5s...", err)
			time.Sleep(5 * time.Second)
			continue
		}

		c.mu.Lock()
		c.conn = conn
		c.mu.Unlock()

		// Registrar agente
		if err := c.sendRegister(); err != nil {
			c.log.Error("WS: error en registro: %v", err)
			c.closeConn()
			time.Sleep(5 * time.Second)
			continue
		}

		c.wg.Add(2)
		go c.readLoop()
		go c.writeLoop()

		// Esperar hasta que se cierre la conexión
		<-c.done
		c.wg.Wait()

		c.log.Warn("WS: conexión perdida. Reintentando en 5s...")
		time.Sleep(5 * time.Second)
	}
}

func (c *Client) sendRegister() error {
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
	return c.conn.WriteJSON(msg)
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
		c.handleMessage(msg)
	}
}

func (c *Client) writeLoop() {
	defer c.wg.Done()
	ticker := time.NewTicker(1 * time.Second)
	defer ticker.Stop()

	for {
		select {
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
		case <-ticker.C:
			// Enviar eventos pendientes desde la cola
			if c.queue == nil {
				continue
			}
			pending, err := c.queue.Dequeue(50)
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

// Close cierra la conexión WebSocket
func (c *Client) Close() {
	c.cancel()
	c.closeConn()
	c.wg.Wait()
	c.log.Info("WS: cerrado")
}

// ─── Envío de eventos ──────────────────────────────────────────────

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

// SendFileEvent envía evento de archivo
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

// SendFileDetection envía detección de PII en archivo
func (c *Client) SendFileDetection(ev audit.FileEvent) {
	payload := map[string]interface{}{
		"agentId":      c.agentID,
		"timestamp":    ev.Timestamp,
		"path":         ev.Path,
		"eventType":    ev.EventType,
		"process":      ev.ProcessName,
		"pid":          ev.PID,
		"user":         ev.User,
		"personalData": ev.PersonalData,
		"sensitive":    ev.Sensitive,
		"fileType":     getFileType(ev.Path),
		"hostname":     getHostname(),
		"rowCount":     0,
	}
	c.send("file_detected", payload)
}

// SendDBQuery envía consulta de base de datos
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

// SendHostEvent envía evento del sistema/hardening
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

// SendWindowsEvent envía evento de Windows
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

// SendTelemetry envía telemetría del sistema
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
	}
	c.send("telemetry", payload)
}

// SendEvent envía evento genérico
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

// ─── Manejo de mensajes entrantes ──────────────────────────────────

func (c *Client) handleMessage(data []byte) {
	var msg map[string]interface{}
	if err := json.Unmarshal(data, &msg); err != nil {
		c.log.Error("WS: mensaje inválido: %v", err)
		return
	}
	typ, _ := msg["type"].(string)
	switch typ {
	case "command":
		c.handleCommand(msg)
	case "ping":
		c.send("pong", map[string]interface{}{"ts": time.Now().Unix()})
	default:
		c.log.Debug("WS: mensaje desconocido: %s", typ)
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

	var result string
	var err error

	switch command {
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
	case "lockdown":
		security.Lockdown()
		result = "Modo lockdown activado"
	case "restart":
		result = "Reiniciando..."
		go func() {
			time.Sleep(1 * time.Second)
			c.log.Info("WS: reinicio solicitado")
			// Aquí implementar reinicio real (syscall.Exec)
		}()
	case "uninstall":
		result = "Desinstalando..."
		c.log.Info("WS: desinstalación solicitada")
	default:
		err = fmt.Errorf("comando desconocido: %s", command)
	}

	resp := map[string]interface{}{
		"type": "command_response",
		"payload": map[string]interface{}{
			"commandId": commandId,
			"status":    "success",
			"result":    result,
			"error":     err != nil,
		},
	}
	c.send("command_response", resp)
	if err != nil {
		c.log.Error("WS: error ejecutando comando %s: %v", command, err)
	}
}

// ─── Helpers ──────────────────────────────────────────────────────

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
