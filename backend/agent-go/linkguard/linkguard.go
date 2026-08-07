package main

import (
	"crypto/tls"
	"encoding/json"
	"fmt"
	"log"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/gorilla/websocket"
)

var (
	apiBase       = getEnv("API_BASE", "http://localhost:3838")
	agentToken    = getEnv("AGENT_TOKEN", "")
	agentId       = getEnv("AGENT_ID", "")
	agentPath     = getEnv("AGENT_PATH", "")
	reconnectWait = 1 * time.Second
	pingInterval  = 5 * time.Second

	conn       *websocket.Conn
	connMu     sync.Mutex
	agentCmd   *exec.Cmd
	agentAlive = false
	stopCh     = make(chan struct{})
)

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func wsURL() string {
	u, _ := url.Parse(apiBase)
	scheme := "ws"
	if u.Scheme == "https" {
		scheme = "wss"
	}
	return fmt.Sprintf("%s://%s/ws/agent", scheme, u.Host)
}

func connect() (*websocket.Conn, error) {
	dialer := &websocket.Dialer{
		HandshakeTimeout: 5 * time.Second,
		TLSClientConfig:  &tls.Config{InsecureSkipVerify: true},
	}
	c, _, err := dialer.Dial(wsURL(), nil)
	if err != nil {
		return nil, err
	}

	register := map[string]interface{}{
		"type":    "register",
		"token":   agentToken,
		"agentId": agentId,
	}
	data, _ := json.Marshal(register)
	if err := c.WriteMessage(websocket.TextMessage, data); err != nil {
		c.Close()
		return nil, fmt.Errorf("register: %w", err)
	}

	_, resp, err := c.ReadMessage()
	if err != nil {
		c.Close()
		return nil, fmt.Errorf("register response: %w", err)
	}
	var msg map[string]interface{}
	json.Unmarshal(resp, &msg)
	if msg["type"] != "registered" {
		c.Close()
		return nil, fmt.Errorf("unexpected register response: %s", string(resp))
	}

	log.Printf("[linkguard] Connected and registered as %s", agentId)
	return c, nil
}

func writeJSON(c *websocket.Conn, v interface{}) error {
	return c.WriteMessage(websocket.TextMessage, mustJSON(v))
}

func mustJSON(v interface{}) []byte {
	data, _ := json.Marshal(v)
	return data
}

func runAgentLoop() {
	if agentPath == "" || agentCmd != nil {
		return
	}

	for {
		select {
		case <-stopCh:
			return
		default:
		}

		cmd := exec.Command(agentPath)
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: false}

		log.Printf("[linkguard] Starting agent: %s", agentPath)
		if err := cmd.Start(); err != nil {
			log.Printf("[linkguard] Failed to start agent: %v", err)
			time.Sleep(5 * time.Second)
			continue
		}

		agentCmd = cmd
		agentAlive = true
		log.Printf("[linkguard] Agent started (PID %d)", cmd.Process.Pid)

		if err := cmd.Wait(); err != nil {
			log.Printf("[linkguard] Agent exited: %v", err)
		} else {
			log.Printf("[linkguard] Agent exited cleanly")
		}

		agentAlive = false
		agentCmd = nil

		select {
		case <-stopCh:
			return
		default:
			time.Sleep(2 * time.Second)
		}
	}
}

func mainLoop() {
	ticker := time.NewTicker(pingInterval)
	defer ticker.Stop()

	for {
		select {
		case <-stopCh:
			return
		default:
		}

		connMu.Lock()
		if conn == nil {
			connMu.Unlock()
			log.Printf("[linkguard] Disconnected. Reconnecting in %v...", reconnectWait)

			c, err := connect()
			if err != nil {
				log.Printf("[linkguard] Connection failed: %v", err)
				time.Sleep(reconnectWait)
				continue
			}

			connMu.Lock()
			conn = c
			connMu.Unlock()

			writeJSON(conn, map[string]string{"type": "heartbeat"})
			continue
		}
		connMu.Unlock()

		select {
		case <-ticker.C:
			connMu.Lock()
			if conn != nil {
				if err := writeJSON(conn, map[string]string{"type": "heartbeat"}); err != nil {
					log.Printf("[linkguard] Heartbeat error: %v", err)
					conn.Close()
					conn = nil
				}
			}
			connMu.Unlock()
		default:
		}

		connMu.Lock()
		c := conn
		connMu.Unlock()

		if c == nil {
			continue
		}

		_, message, err := c.ReadMessage()
		if err != nil {
			log.Printf("[linkguard] Read error: %v", err)
			connMu.Lock()
			if conn != nil {
				conn.Close()
				conn = nil
			}
			connMu.Unlock()
			continue
		}

		var msg map[string]interface{}
		if err := json.Unmarshal(message, &msg); err != nil {
			continue
		}

		msgType, _ := msg["type"].(string)
		switch msgType {
		case "ping":
			writeJSON(c, map[string]string{"type": "pong"})
		case "restart_agent":
			log.Printf("[linkguard] Restarting agent per server command")
			restartAgent()
		case "shutdown":
			log.Printf("[linkguard] Shutdown command received")
			stopCh <- struct{}{}
			return
		}
	}
}

func restartAgent() {
	if agentCmd != nil && agentCmd.Process != nil {
		agentCmd.Process.Signal(syscall.SIGTERM)
		time.Sleep(3 * time.Second)
		if agentCmd.Process != nil {
			agentCmd.Process.Kill()
		}
	}
	agentCmd = nil
	agentAlive = false
	go runAgentLoop()
}

func main() {
	log.SetFlags(log.Ldate | log.Ltime | log.Lshortfile)
	log.Printf("[linkguard] Starting LinkGuard for agent %s", agentId)
	log.Printf("[linkguard] API Base: %s, WS URL: %s", apiBase, wsURL())

	if agentPath != "" {
		go runAgentLoop()
	} else {
		log.Printf("[linkguard] No AGENT_PATH set, running in standalone mode")
	}

	go mainLoop()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM, os.Interrupt)
	<-sigCh

	log.Printf("[linkguard] Shutting down...")
	close(stopCh)

	connMu.Lock()
	if conn != nil {
		conn.Close()
		conn = nil
	}
	connMu.Unlock()

	if agentCmd != nil && agentCmd.Process != nil {
		agentCmd.Process.Kill()
	}

	log.Printf("[linkguard] Stopped.")
}

func init() {
	log.SetFlags(log.Ldate | log.Ltime | log.Lshortfile)
	wsReconnectBaseStr := os.Getenv("RECONNECT_INTERVAL")
	if wsReconnectBaseStr != "" {
		if d, err := time.ParseDuration(wsReconnectBaseStr); err == nil {
			reconnectWait = d
		}
	}
	pingStr := os.Getenv("PING_INTERVAL")
	if pingStr != "" {
		if d, err := time.ParseDuration(pingStr); err == nil {
			pingInterval = d
		}
	}

	// Normalize agent path
	if ap := os.Getenv("AGENT_PATH"); ap != "" {
		if strings.Contains(ap, " ") && !strings.HasPrefix(ap, "\"") {
			ap = `"` + ap + `"`
		}
		agentPath = ap
	}
}
