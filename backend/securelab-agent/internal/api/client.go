package api

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"os"
	"runtime"
	"strings"
	"time"

	"securelab-agent/internal/logger"
)

type Client struct {
	baseURL  string
	token    string
	client   *http.Client
	log      *logger.Logger
	sendChan chan interface{}
	agentID  string
}

func NewClient(baseURL, token string, log *logger.Logger) *Client {
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	return &Client{
		baseURL: baseURL,
		token:   token,
		client: &http.Client{
			Transport: tr,
			Timeout:   30 * time.Second,
		},
		log:      log,
		sendChan: make(chan interface{}, 100),
	}
}

// SetAgentID establece el ID del agente (para usarlo en los mensajes)
func (c *Client) SetAgentID(id string) {
	c.agentID = id
}

// ---- System Info ----

type SystemInfo struct {
	Hostname string
	Platform string
	Arch     string
	IP       string
	User     string
}

func GetSystemInfo() SystemInfo {
	host, _ := os.Hostname()
	return SystemInfo{
		Hostname: host,
		Platform: runtime.GOOS,
		Arch:     runtime.GOARCH,
		IP:       getLocalIP(),
		User:     getCurrentUser(),
	}
}

func getLocalIP() string {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return "127.0.0.1"
	}
	for _, addr := range addrs {
		if ipnet, ok := addr.(*net.IPNet); ok && !ipnet.IP.IsLoopback() && ipnet.IP.To4() != nil {
			return ipnet.IP.String()
		}
	}
	return "127.0.0.1"
}

func getCurrentUser() string {
	u, _ := os.UserHomeDir()
	if u != "" {
		parts := strings.Split(u, string(os.PathSeparator))
		return parts[len(parts)-1]
	}
	return "unknown"
}

// ---- API Endpoints ----

type RegisterResponse struct {
	AgentID string `json:"agentId"`
	Error   string `json:"error,omitempty"`
}

type HeartbeatResponse struct {
	Error             string   `json:"error,omitempty"`
	PendingRules      []Rule   `json:"pendingRules"`
	PendingBlocks     []string `json:"pendingBlocks"`
	PendingUnblocks   []string `json:"pendingUnblocks"`
	HeartbeatInterval int      `json:"heartbeatInterval,omitempty"`
}

type Rule struct {
	Name     string `json:"name"`
	ID       string `json:"_id"`
	Type     string `json:"type"`
	Protocol string `json:"protocol"`
	Port     string `json:"port"`
	Action   string `json:"action"`
	SourceIP string `json:"sourceIp"`
	DestIP   string `json:"destinationIp"`
}

type HeartbeatStatus struct {
	Online      bool        `json:"online"`
	Load        interface{} `json:"load"`
	Firewall    interface{} `json:"firewall"`
	Users       int         `json:"users"`
	Uptime      int64       `json:"uptime"`
	CPUUsage    float64     `json:"cpuUsage"`
	MemoryUsage int         `json:"memoryUsage"`
}

type UserInfo struct {
	Username string `json:"username"`
	Session  string `json:"session"`
	Since    string `json:"since"`
}

type SystemLoad struct {
	LoadAvg  float64 `json:"loadAvg"`
	MemUsed  int     `json:"memUsed"`
	MemTotal int     `json:"memTotal"`
	CPUCores int     `json:"cpuCores"`
}

type Event struct {
	Title       string `json:"title"`
	Description string `json:"description"`
	Source      string `json:"source"`
	Severity    string `json:"severity"`
	AutoBlock   bool   `json:"autoBlock"`
}

// Register envía el registro incluyendo agentId si está disponible
func (c *Client) Register(hostname, platform, arch, ip, user, agentID string) (*RegisterResponse, error) {
	payload := map[string]string{
		"token":    c.token,
		"hostname": hostname,
		"platform": platform,
		"arch":     arch,
		"ip":       ip,
		"user":     user,
		"version":  "2.0.0",
	}
	if agentID != "" {
		payload["agentId"] = agentID
	}
	body, _ := json.Marshal(payload)
	resp, err := c.client.Post(c.baseURL+"/register", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	var result RegisterResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, err
	}
	if result.Error != "" {
		return nil, errors.New(result.Error)
	}
	return &result, nil
}

func (c *Client) Heartbeat(agentID string, status HeartbeatStatus, users []UserInfo, rules []string, blocked []string, load SystemLoad) (*HeartbeatResponse, error) {
	metrics := map[string]interface{}{
		"uptime": status.Uptime,
		"users":  status.Users,
		"load":   load.LoadAvg,
		"memory": status.MemoryUsage,
		"cpu":    status.CPUUsage,
	}
	payload := map[string]interface{}{
		"token":               c.token,
		"metrics":             metrics,
		"status":              status,
		"activeUsers":         users,
		"activeFirewallRules": rules,
		"blockedUsers":        blocked,
	}
	body, _ := json.Marshal(payload)
	resp, err := c.client.Post(c.baseURL+"/"+agentID+"/heartbeat", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(resp.Body)
	var result HeartbeatResponse
	if err := json.Unmarshal(data, &result); err != nil {
		return nil, err
	}
	if result.Error != "" {
		return nil, errors.New(result.Error)
	}
	return &result, nil
}

func (c *Client) ReportEvent(agentID string, evt Event) error {
	payload := map[string]interface{}{
		"token":       c.token,
		"title":       evt.Title,
		"description": evt.Description,
		"source":      evt.Source,
		"severity":    evt.Severity,
		"autoBlock":   evt.AutoBlock,
	}
	body, _ := json.Marshal(payload)
	resp, err := c.client.Post(c.baseURL+"/"+agentID+"/event", "application/json", bytes.NewReader(body))
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}
