package ws

import (
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"

	"github.com/gorilla/websocket"
)

// Client maneja la conexión WebSocket con el backend
type Client struct {
	mu       sync.Mutex
	conn     *websocket.Conn
	url      string
	token    string
	log      *logger.Logger
	store    *audit.Store
	sendChan chan interface{}
	done     chan struct{}
	agentID  string
}

// NewClient crea una nueva instancia del cliente WebSocket
func NewClient(url, token string, log *logger.Logger, store *audit.Store) *Client {
	return &Client{
		url:      url,
		token:    token,
		log:      log,
		store:    store,
		sendChan: make(chan interface{}, 1000),
		done:     make(chan struct{}),
	}
}

// SetAgentID establece el ID del agente
func (c *Client) SetAgentID(id string) {
	c.agentID = id
}

// Connect establece la conexión WebSocket (implementación básica)
func (c *Client) Connect() {
	c.log.Info("WS: connecting to %s", c.url)
	// Aquí iría la lógica real de conexión
	c.log.Info("WS: connected")
}

// SendFileEvent envía un evento de archivo al backend
func (c *Client) SendFileEvent(ev audit.FileEvent) {
	c.sendChan <- map[string]interface{}{
		"type":         "file_event",
		"agentId":      c.agentID,
		"token":        c.token,
		"timestamp":    ev.Timestamp,
		"path":         ev.Path,
		"event_type":   ev.EventType,
		"process_name": ev.ProcessName,
		"pid":          ev.PID,
		"user":         ev.User,
		"size":         ev.Size,
		"hash":         ev.Hash,
		"destination":  ev.Destination,
	}
}

// SendFileDetection envía una detección de PII al backend
func (c *Client) SendFileDetection(ev audit.FileEvent) {
	c.sendChan <- map[string]interface{}{
		"type":          "file_detected",
		"agentId":       c.agentID,
		"token":         c.token,
		"path":          ev.Path,
		"event_type":    ev.EventType,
		"process_name":  ev.ProcessName,
		"pid":           ev.PID,
		"user":          ev.User,
		"personal_data": ev.PersonalData,
		"sensitive":     ev.Sensitive,
	}
}

// SendEvent envía un evento genérico al backend
func (c *Client) SendEvent(title, description, source, severity string) {
	c.sendChan <- map[string]interface{}{
		"type":        "event",
		"title":       title,
		"description": description,
		"source":      source,
		"severity":    severity,
		"agentId":     c.agentID,
		"token":       c.token,
	}
}

// SendTelemetry envía datos de telemetría al backend
func (c *Client) SendTelemetry(data interface{}) {
	c.sendChan <- map[string]interface{}{
		"type":    "telemetry",
		"data":    data,
		"agentId": c.agentID,
		"token":   c.token,
	}
}

// Close cierra la conexión WebSocket
func (c *Client) Close() {
	close(c.done)
	c.mu.Lock()
	if c.conn != nil {
		c.conn.Close()
		c.conn = nil
	}
	c.mu.Unlock()
	c.log.Info("WS: closed")
}
