package ws

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"os"
	"sync"
	"time"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/models"
	"securelab-agent/internal/queue"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/security"
	"securelab-agent/internal/sysinfo"

	"github.com/gorilla/websocket"
)

type Client struct {
	mu                sync.Mutex
	conn              *websocket.Conn
	url               string
	token             string
	agentID           string
	log               *logger.Logger
	sendChan          chan interface{}
	priorityChan      chan interface{}
	done              chan struct{}
	queue             *queue.Queue
	ctx               context.Context
	cancel            context.CancelFunc
	wg                sync.WaitGroup
	dbConnectionsChan chan []map[string]interface{}
}

func NewClient(url, token string, log *logger.Logger, q *queue.Queue) *Client {
	ctx, cancel := context.WithCancel(context.Background())
	return &Client{
		url:                url,
		token:              token,
		log:                log,
		sendChan:           make(chan interface{}, 5000),
		priorityChan:       make(chan interface{}, 1000),
		done:               make(chan struct{}),
		queue:              q,
		ctx:                ctx,
		cancel:             cancel,
		dbConnectionsChan:  make(chan []map[string]interface{}, 10),
	}
}

func (c *Client) SetAgentID(id string) {
	c.agentID = id
	c.log.Info("WS: agentID establecido a %s", id)
}

func (c *Client) SetDBConnectionsChan(ch chan []map[string]interface{}) {
	c.dbConnectionsChan = ch
}

func (c *Client) GetDBConnectionsChan() chan []map[string]interface{} {
	return c.dbConnectionsChan
}

func (c *Client) Connect() {
	for {
		select {
		case <-c.ctx.Done():
			return
		default:
		}

		c.done = make(chan struct{})
		c.wg = sync.WaitGroup{}

		if c.token == "" {
			c.log.Error("WS: token vacio, no se puede conectar")
			time.Sleep(1 * time.Second)
			continue
		}
		if c.agentID == "" {
			c.log.Error("WS: agentID vacio, no se puede conectar")
			time.Sleep(1 * time.Second)
			continue
		}

		c.log.Info("WS: conectando a %s", c.url)
		dialer := websocket.Dialer{
			TLSClientConfig:  &tls.Config{InsecureSkipVerify: true},
			HandshakeTimeout: 10 * time.Second,
		}
		conn, _, err := dialer.Dial(c.url, nil)
		if err != nil {
			c.log.Error("WS: error de conexion: %v. Reintentando en 1s...", err)
			time.Sleep(1 * time.Second)
			continue
		}

		c.mu.Lock()
		c.conn = conn
		c.mu.Unlock()

		c.log.Info("WS: conexion establecida, enviando registro...")

		if err := c.sendRegister(); err != nil {
			c.log.Error("WS: error en registro: %v", err)
			c.closeConn()
			time.Sleep(1 * time.Second)
			continue
		}

		c.log.Info("WS: registro enviado, esperando respuesta...")

		c.wg.Add(2)
		go c.readLoop()
		go c.writeLoop()

		<-c.done
		c.wg.Wait()

		c.log.Warn("WS: conexion perdida. Reintentando en 1s...")
		time.Sleep(1 * time.Second)
	}
}

func (c *Client) sendRegister() error {
	tokPreview := c.token
	if len(tokPreview) > 20 {
		tokPreview = tokPreview[:20] + "..."
	}
	c.log.Info("WS: preparando mensaje register (token: %s, agentID: %s)", tokPreview, c.agentID)

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
	flushTicker := time.NewTicker(200 * time.Millisecond)
	defer flushTicker.Stop()

	for {
		select {
		case <-flushTicker.C:
			c.flushQueue()
		case <-c.ctx.Done():
			return
		case msg := <-c.sendChan:
			c.mu.Lock()
			if c.conn != nil {
				c.conn.WriteJSON(msg)
			}
			c.mu.Unlock()
		case msg := <-c.priorityChan:
			c.mu.Lock()
			if c.conn != nil {
				c.conn.WriteJSON(msg)
			}
			c.mu.Unlock()
		}
	}
}

func (c *Client) flushQueue() {
	c.mu.Lock()
	defer c.mu.Unlock()

	if c.conn == nil {
		return
	}

	for {
		select {
		case msg := <-c.sendChan:
			c.conn.WriteJSON(msg)
		case msg := <-c.priorityChan:
			c.conn.WriteJSON(msg)
		default:
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
}

func (c *Client) Close() {
	close(c.done)
	c.closeConn()
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
		"agentId":      c.agentID,
		"timestamp":    ev.Timestamp,
		"path":         ev.Path,
		"eventType":    ev.EventType,
		"process":      ev.ProcessName,
		"pid":          ev.PID,
		"user":         ev.User,
		"size":         ev.Size,
		"hash":         ev.Hash,
		"destination":  ev.Destination,
		"sensitive":    ev.Sensitive,
		"personalData": ev.PersonalData,
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

func (c *Client) SendDataResponse(dataType string, data interface{}) {
	payload := map[string]interface{}{
		"agentId": c.agentID,
		"type":    dataType,
		"data":    data,
		"ts":      time.Now().Unix(),
	}
	c.send("data_response", payload)
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

func (c *Client) SendInitialInventory(item scanner.InitialInventoryItem) {
	payload := map[string]interface{}{
		"agentId":       c.agentID,
		"userId":        item.UserID,
		"companyId":     item.CompanyID,
		"hostname":      item.Hostname,
		"path":          item.Path,
		"relativePath":  item.RelativePath,
		"size":          item.Size,
		"extension":     item.Extension,
		"categories":    item.Categories,
		"sensitive":     item.Sensitive,
		"personalData":  item.PersonalData,
		"hash":          item.Hash,
		"firstSeen":     item.FirstSeen.Format(time.RFC3339),
		"lastScanned":   item.LastScanned.Format(time.RFC3339),
		"lastModified":  item.LastModified.Format(time.RFC3339),
		"scanCount":     item.ScanCount,
		"status":        item.Status,
	}
	c.send("inventory_item", payload)
}

func (c *Client) SendSync() {
	c.sendPriority("sync", map[string]interface{}{})
}

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

func (c *Client) sendPriority(typ string, payload interface{}) {
	msg := map[string]interface{}{
		"type":    typ,
		"payload": payload,
	}
	select {
	case c.priorityChan <- msg:
	default:
		if c.queue != nil {
			if err := c.queue.Enqueue(typ, payload); err != nil {
				c.log.Error("WS: error guardando en cola prioritaria: %v", err)
			}
		}
	}
}

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
		c.sendPriority("pong", map[string]interface{}{"ts": time.Now().Unix()})
	case "welcome":
		c.log.Info("WS: servidor envio bienvenida: %v", msg["payload"])
	case "registered":
		c.log.Info("WS: agente registrado correctamente: %v", msg["payload"])
	case "file_response":
		c.log.Info("WS: respuesta de archivo: %v", msg["payload"])
	case "error":
		c.log.Error("WS: servidor reporto error: %v", msg["payload"])
	case "db_connections":
		c.handleDBConnections(msg)
	default:
		c.log.Debug("WS: mensaje desconocido: %s", typ)
	}
}

func (c *Client) handleSyncResponse(msg map[string]interface{}) {
	payload, _ := msg["payload"].(map[string]interface{})

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

	if cmds, ok := payload["pendingCommands"].([]interface{}); ok {
		for _, raw := range cmds {
			cm, _ := raw.(map[string]interface{})
			command, _ := cm["command"].(string)
			commandId, _ := cm["commandId"].(string)
			params, _ := cm["params"].(map[string]interface{})
			if command == "" {
				continue
			}
			go c.executeCommandAsync(command, params, commandId)
		}
	}

	// Recibir conexiones de BD incluidas en sync_response
	if conns, ok := payload["connections"].([]interface{}); ok {
		dbMsg := map[string]interface{}{
			"type":    "db_connections",
			"payload": map[string]interface{}{"connections": conns},
		}
		c.handleDBConnections(dbMsg)
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

	go c.executeCommandAsync(command, params, commandId)
}

func (c *Client) executeCommandAsync(command string, params map[string]interface{}, commandId string) {
	result, err := c.executeCommand(command, params, commandId)

	c.sendPriority("command_response", map[string]interface{}{
		"commandId": commandId,
		"status":    "success",
		"result":    result,
		"error":     err != nil,
	})
	if err != nil {
		c.log.Error("WS: error ejecutando comando %s: %v", command, err)
	}
}

func (c *Client) executeCommand(command string, params map[string]interface{}, commandId string) (interface{}, error) {
	switch command {
	case "test_db", "scan_db":
		return c.executeDBCommand(command, params, c.log)
	case "speak":
		text, _ := params["text"].(string)
		security.Speak(text)
		return "ok", nil
	case "power_restart":
		security.PowerRestart()
		return "ok", nil
	case "power_off":
		security.PowerOff()
		return "ok", nil
	case "power_suspend":
		security.PowerSuspend()
		return "ok", nil
	case "lockdown":
		message, _ := params["message"].(string)
		security.Lockdown(message)
		return "ok", nil
	case "unlock":
		security.Unlock()
		return "ok", nil
	case "lock_timed":
		message, _ := params["message"].(string)
		minutes, _ := params["minutes"].(int)
		security.LockdownTimed(message, minutes)
		return "ok", nil
	case "kill_process":
		pid, _ := params["pid"].(int)
		security.KillProcess(pid)
		return "ok", nil
	case "request_data":
		dataType, _ := params["type"].(string)
		if dataType == "" {
			dataType = "processes"
		}
		var data interface{}
		switch dataType {
		case "processes":
			data = sysinfo.GetProcesses()
		case "health":
			data = sysinfo.GetHealth()
		case "defender":
			data = sysinfo.GetDefender()
		case "screenshot":
			img, err := sysinfo.CaptureScreenshot()
			if err != nil {
				return nil, err
			}
			data = map[string]interface{}{"image": img}
		default:
			return nil, fmt.Errorf("tipo de dato no soportado: %s", dataType)
		}
		c.SendDataResponse(dataType, data)
		return "ok", nil
	default:
		return nil, fmt.Errorf("comando no soportado: %s", command)
	}
}

func (c *Client) handleDBConnections(msg map[string]interface{}) {
	payload, ok := msg["payload"].(map[string]interface{})
	if !ok {
		c.log.Error("WS: db_connections sin payload valido")
		return
	}

	connectionsRaw, ok := payload["connections"].([]interface{})
	if !ok {
		c.log.Error("WS: db_connections sin lista de conexiones")
		return
	}

	c.log.Info("WS: recibido %d conexiones de BD del backend", len(connectionsRaw))

	var connections []map[string]interface{}
	for _, raw := range connectionsRaw {
		conn, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		connections = append(connections, conn)
	}

	if len(connections) > 0 {
		c.log.Info("WS: aplicando %d conexiones de BD válidas al monitor de actividad", len(connections))
		select {
		case c.dbConnectionsChan <- connections:
		default:
			c.log.Warn("WS: canal de conexiones de BD lleno, descartando")
		}
	}
}

func getString(m map[string]interface{}, key string) string {
	if v, ok := m[key].(string); ok {
		return v
	}
	return ""
}

func getInt(m map[string]interface{}, key string) int {
	if v, ok := m[key].(float64); ok {
		return int(v)
	}
	if v, ok := m[key].(int); ok {
		return v
	}
	return 0
}

func getBool(m map[string]interface{}, key string) bool {
	if v, ok := m[key].(bool); ok {
		return v
	}
	return false
}

func getFileType(path string) string {
	ext := ""
	if i := len(path) - 1; i >= 0 {
		for ; i >= 0; i-- {
			if path[i] == '.' {
				ext = path[i+1:]
				break
			}
		}
	}
	return ext
}

func getHostname() string {
	h, _ := os.Hostname()
	return h
}
