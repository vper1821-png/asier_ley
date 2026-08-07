package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"strings"
	"time"
)

func calcMemPercent(used, total int) float64 {
	if total <= 0 {
		return 0
	}
	return math.Round(float64(used) / float64(total) * 100)
}

type RegisterResponse struct {
	AgentID string `json:"agentId"`
	Agent   *struct {
		ID string `json:"_id"`
	} `json:"agent"`
	Error string `json:"error,omitempty"`
}

type HeartbeatResponse struct {
	Error             string            `json:"error,omitempty"`
	PendingRules      []Rule            `json:"pendingRules"`
	PendingBlocks     []string          `json:"pendingBlocks"`
	PendingUnblocks   []string          `json:"pendingUnblocks"`
	SyncBlocked       []string          `json:"syncBlocked"`
	ScanCommand       *ScanCommand      `json:"scanCommand,omitempty"`
	QueryCommand      *QueryCommand     `json:"queryCommand,omitempty"`
	TestCommand       *TestCommand      `json:"testCommand,omitempty"`
	HeartbeatInterval int               `json:"heartbeatInterval,omitempty"`
	ClientCommand    *ClientCommand     `json:"clientCommand,omitempty"`
}

type ClientCommand struct {
	Type string `json:"type"`
}

type ScanCommand struct {
	Type           string        `json:"type"`
	DBConnection   DBConnection  `json:"dbConnection"`
	ConnectionID   string        `json:"connectionId"`
}

type QueryCommand struct {
	Type         string       `json:"type"`
	DBConnection DBConnection `json:"dbConnection"`
	ConnectionID string       `json:"connectionId"`
	QueryID      string       `json:"queryId"`
	Query        string       `json:"query"`
}

type TestCommand struct {
	Type         string       `json:"type"`
	DBConnection DBConnection `json:"dbConnection"`
	ConnectionID string       `json:"connectionId"`
}

type Rule struct {
	Name       string `json:"name"`
	ID         string `json:"_id"`
	Type       string `json:"type"`
	Protocol   string `json:"protocol"`
	Port       string `json:"port"`
	Action     string `json:"action"`
	SourceIP   string `json:"sourceIp"`
	DestIP     string `json:"destinationIp"`
}

type Event struct {
	Title       string `json:"title"`
	Description string `json:"description"`
	Source      string `json:"source"`
	Severity    string `json:"severity"`
	AutoBlock   bool   `json:"autoBlock"`
}

var httpClient = &http.Client{Timeout: 30 * time.Second}

func apiRegister(agentId, hostname, platform, arch, ip, user string) (*RegisterResponse, error) {
	cfg := GetConfig()
	body, _ := json.Marshal(map[string]string{
		"token":    cfg.Token,
		"agentId":  agentId,
		"hostname": hostname,
		"platform": platform,
		"arch":     arch,
		"ip":       ip,
		"version":  cfg.AgentVersion,
		"user":     user,
	})
	resp, err := httpClient.Post(cfg.APIBase+"/register", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("register request: %w", err)
	}
	defer resp.Body.Close()
	var result RegisterResponse
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil, fmt.Errorf("register decode: %w", err)
	}
	return &result, nil
}

func apiHeartbeat(agentID string, status HeartbeatStatus, users []UserInfo, rules []string, blocked []string, sysLoad SystemLoad) (*HeartbeatResponse, error) {
	cfg := GetConfig()
	// Build top-level metrics for direct consumption
	metrics := map[string]interface{}{
		"uptime":  status.Uptime,
		"users":   status.Users,
		"load":    sysLoad.LoadAvg,
		"memory":  calcMemPercent(sysLoad.MemUsed, sysLoad.MemTotal),
		"cpu":     getCPUPercent(),
	}
	payload := map[string]interface{}{
		"token":               cfg.Token,
		"metrics":             metrics,
		"status":              status,
		"activeUsers":         users,
		"activeFirewallRules": rules,
		"blockedUsers":        blocked,
	}
	body, _ := json.Marshal(payload)
	resp, err := httpClient.Post(cfg.APIBase+"/"+agentID+"/heartbeat", "application/json", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("heartbeat request: %w", err)
	}
	defer resp.Body.Close()
	data, _ := io.ReadAll(resp.Body)
	var result HeartbeatResponse
	if err := json.Unmarshal(data, &result); err != nil {
		return nil, fmt.Errorf("heartbeat decode: %w", err)
	}
	return &result, nil
}

func apiReportEvent(agentID string, evt Event) error {
	cfg := GetConfig()
	payload := map[string]interface{}{
		"token": cfg.Token,
		"title": evt.Title,
		"description": evt.Description,
		"source": evt.Source,
		"severity": evt.Severity,
		"autoBlock": evt.AutoBlock,
	}
	body, _ := json.Marshal(payload)
	resp, err := httpClient.Post(cfg.APIBase+"/"+agentID+"/event", "application/json", bytes.NewReader(body))
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}

type HeartbeatStatus struct {
	Online   bool        `json:"online"`
	Load     interface{} `json:"load"`
	Firewall interface{} `json:"firewall"`
	Users    int         `json:"users"`
	Uptime   int64       `json:"uptime"`
}

type QueryResult struct {
	Columns []string   `json:"columns"`
	Rows    []map[string]interface{} `json:"rows"`
	Error   string     `json:"error,omitempty"`
	TookMs  int64      `json:"tookMs"`
}

func reportScanResult(connectionID string, result *ScanResult, errMsg string) {
	cfg := GetConfig()
	totalTables := 0
	totalRows := int64(0)
	personalDataCols := 0
	var tables []TableInfo
	if result != nil {
		tables = result.Tables
		totalTables = result.TotalTables
		totalRows = result.TotalRows
		personalDataCols = result.PersonalDataColumns
	}
	sizeBytes := int64(0)
	if result != nil {
		sizeBytes = result.SizeBytes
	}
	body, _ := json.Marshal(map[string]interface{}{
		"token":               cfg.Token,
		"tables":              tables,
		"totalTables":         totalTables,
		"totalRows":           totalRows,
		"personalDataColumns": personalDataCols,
		"sizeBytes":           sizeBytes,
		"error":               errMsg,
	})
	url := fmt.Sprintf("%s/api/databases/%s/agent-scan-result", strings.TrimSuffix(cfg.APIBase, "/api/agents"), connectionID)
	resp, err := httpClient.Post(url, "application/json", bytes.NewReader(body))
	if err != nil {
		logMsg("Failed to report scan result: %v", err)
		return
	}
	defer resp.Body.Close()
	logMsg("Scan result reported for connection %s (status %d)", connectionID, resp.StatusCode)
}

func reportQueryResult(connectionID, queryID string, result *QueryResult) {
	cfg := GetConfig()
	body, _ := json.Marshal(map[string]interface{}{
		"token":    cfg.Token,
		"queryId":  queryID,
		"columns":  result.Columns,
		"rows":     result.Rows,
		"error":    result.Error,
		"tookMs":   result.TookMs,
	})
	url := fmt.Sprintf("%s/api/databases/%s/agent-query-result", strings.TrimSuffix(cfg.APIBase, "/api/agents"), connectionID)
	resp, err := httpClient.Post(url, "application/json", bytes.NewReader(body))
	if err != nil {
		logMsg("Failed to report query result: %v", err)
		return
	}
	defer resp.Body.Close()
	logMsg("Query result reported for connection %s (status %d)", connectionID, resp.StatusCode)
}

func reportTestResult(connectionID string, success bool, message string) {
	cfg := GetConfig()
	body, _ := json.Marshal(map[string]interface{}{
		"token":   cfg.Token,
		"success": success,
		"message": message,
	})
	url := fmt.Sprintf("%s/api/databases/%s/agent-test-result", strings.TrimSuffix(cfg.APIBase, "/api/agents"), connectionID)
	resp, err := httpClient.Post(url, "application/json", bytes.NewReader(body))
	if err != nil {
		logMsg("Failed to report test result: %v", err)
		return
	}
	defer resp.Body.Close()
	logMsg("Test result reported for connection %s (status %d)", connectionID, resp.StatusCode)
}
