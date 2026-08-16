package config

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"sync"
)

var (
	config     *Config
	configOnce sync.Once
	agentID    string
	agentMu    sync.RWMutex
)

type Config struct {
	APIBase           string   `json:"api_base"`
	Token             string   `json:"token"`
	HeartbeatInterval int      `json:"heartbeat_interval"`
	AgentVersion      string   `json:"agent_version"`
	LogFile           string   `json:"log_file"`
	LogLevel          string   `json:"log_level"`
	StateFile         string   `json:"state_file"`
	MaxLogSize        int64    `json:"max_log_size"`
	AuditDBPath       string   `json:"audit_db_path"`
	KnowledgeDBPath   string   `json:"knowledge_db_path"`
	WSURL             string   `json:"ws_url"`
	TelemetryInterval int      `json:"telemetry_interval"`
	FileWatchDirs     []string `json:"file_watch_dirs"`
	PersistenceMode   string   `json:"persistence_mode"`
	HardeningEnabled  bool     `json:"hardening_enabled"`
	Platform          string   `json:"platform"`
	PasswordPolicy    struct {
		MinLength      int  `json:"min_length"`
		RequireUpper   bool `json:"require_upper"`
		RequireLower   bool `json:"require_lower"`
		RequireDigit   bool `json:"require_digit"`
		RequireSpecial bool `json:"require_special"`
	} `json:"password_policy"`
}

type AgentState struct {
	AgentID string `json:"agentId"`
}

func defaultConfig() *Config {
	exe, _ := os.Executable()
	dir := filepath.Dir(exe)
	return &Config{
		APIBase:           "https://leysecurelab.sytes.net/api/agents",
		HeartbeatInterval: 30,
		AgentVersion:      "2.0.0",
		LogLevel:          "info",
		MaxLogSize:        5 * 1024 * 1024,
		TelemetryInterval: 60,
		PersistenceMode:   "aggressive",
		HardeningEnabled:  true,
		Platform:          runtime.GOOS,
		AuditDBPath:       filepath.Join(dir, "audit.db"),
		KnowledgeDBPath:   filepath.Join(dir, "knowledge.db"),
		LogFile:           filepath.Join(dir, "agent.log"),
		StateFile:         filepath.Join(dir, ".agent-state.json"),
		FileWatchDirs: []string{
			os.ExpandEnv("$HOME/Documents"),
			os.ExpandEnv("$HOME/Desktop"),
			os.ExpandEnv("$HOME/Downloads"),
		},
	}
}

func Load() *Config {
	configOnce.Do(func() {
		cfg := defaultConfig()
		loadConfigFile(cfg)
		overrideFromEnv(cfg)
		config = cfg
	})
	return config
}

func loadConfigFile(cfg *Config) {
	exe, _ := os.Executable()
	dir := filepath.Dir(exe)
	configPath := filepath.Join(dir, "config.json")
	data, err := os.ReadFile(configPath)
	if err != nil {
		return
	}
	var fileCfg Config
	if err := json.Unmarshal(data, &fileCfg); err != nil {
		return
	}
	if fileCfg.Token != "" {
		cfg.Token = fileCfg.Token
	}
	if fileCfg.APIBase != "" {
		cfg.APIBase = fileCfg.APIBase
	}
	if fileCfg.HeartbeatInterval > 0 {
		cfg.HeartbeatInterval = fileCfg.HeartbeatInterval
	}
	if fileCfg.LogFile != "" {
		cfg.LogFile = fileCfg.LogFile
	}
	if fileCfg.LogLevel != "" {
		cfg.LogLevel = fileCfg.LogLevel
	}
	if fileCfg.AuditDBPath != "" {
		cfg.AuditDBPath = fileCfg.AuditDBPath
	}
	if fileCfg.KnowledgeDBPath != "" {
		cfg.KnowledgeDBPath = fileCfg.KnowledgeDBPath
	}
	if fileCfg.TelemetryInterval > 0 {
		cfg.TelemetryInterval = fileCfg.TelemetryInterval
	}
	if len(fileCfg.FileWatchDirs) > 0 {
		cfg.FileWatchDirs = fileCfg.FileWatchDirs
	}
	if fileCfg.PersistenceMode != "" {
		cfg.PersistenceMode = fileCfg.PersistenceMode
	}
	if !fileCfg.HardeningEnabled {
		cfg.HardeningEnabled = false
	}
	if fileCfg.WSURL != "" {
		cfg.WSURL = fileCfg.WSURL
	}
	if fileCfg.Platform != "" {
		cfg.Platform = fileCfg.Platform
	}
	if fileCfg.AgentVersion != "" {
		cfg.AgentVersion = fileCfg.AgentVersion
	}
	if fileCfg.MaxLogSize > 0 {
		cfg.MaxLogSize = fileCfg.MaxLogSize
	}
	if fileCfg.PasswordPolicy.MinLength > 0 {
		cfg.PasswordPolicy = fileCfg.PasswordPolicy
	}
}

func overrideFromEnv(cfg *Config) {
	if v := os.Getenv("INVISIA_TOKEN"); v != "" {
		cfg.Token = v
	}
	if v := os.Getenv("INVISIA_API"); v != "" {
		cfg.APIBase = v
	}
	if v := os.Getenv("WS_URL"); v != "" {
		cfg.WSURL = v
	}
	if v := os.Getenv("HEARTBEAT_INTERVAL"); v != "" {
		if i, err := strconv.Atoi(v); err == nil && i > 0 {
			cfg.HeartbeatInterval = i
		}
	}
	if v := os.Getenv("LOG_LEVEL"); v != "" {
		cfg.LogLevel = v
	}
	if v := os.Getenv("PERSISTENCE_MODE"); v != "" {
		cfg.PersistenceMode = v
	}
}

func generateAgentID() string {
	b := make([]byte, 8)
	rand.Read(b)
	host, _ := os.Hostname()
	return fmt.Sprintf("%s-%s", host, hex.EncodeToString(b))
}

func GetAgentID() string {
	agentMu.RLock()
	defer agentMu.RUnlock()
	if agentID == "" {
		loadState()
	}
	return agentID
}

func SetAgentID(id string) {
	agentMu.Lock()
	defer agentMu.Unlock()
	agentID = id
	saveStateLocked()
}

func loadState() {
	cfg := Load()
	if cfg == nil {
		return
	}
	data, err := os.ReadFile(cfg.StateFile)
	if err != nil {
		return
	}
	var state AgentState
	if err := json.Unmarshal(data, &state); err == nil && state.AgentID != "" {
		agentID = state.AgentID
	}
}

func saveStateLocked() {
	cfg := Load()
	if cfg == nil {
		return
	}
	state := AgentState{AgentID: agentID}
	data, _ := json.Marshal(state)
	os.WriteFile(cfg.StateFile, data, 0600)
}

func envOrDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func envIntOrDefault(key string, def int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}
