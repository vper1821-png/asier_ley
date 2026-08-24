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
	config         *Config
	configOnce     sync.Once
	configFilePath string
	agentID        string
	agentMu        sync.RWMutex
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
	SyncInterval      int      `json:"sync_interval"`
	MaxPendingEvents  int      `json:"max_pending_events"`
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

type fileConfig struct {
	APIBase           *string  `json:"api_base"`
	APIBaseAlt        *string  `json:"apiBase"`
	Token             *string  `json:"token"`
	HeartbeatInterval *int     `json:"heartbeat_interval"`
	HeartbeatIntervalAlt *int  `json:"heartbeatInterval"`
	AgentVersion      *string  `json:"agent_version"`
	AgentVersionAlt   *string  `json:"agentVersion"`
	LogFile           *string  `json:"log_file"`
	LogFileAlt        *string  `json:"logFile"`
	LogLevel          *string  `json:"log_level"`
	LogLevelAlt       *string  `json:"logLevel"`
	StateFile         *string  `json:"state_file"`
	StateFileAlt      *string  `json:"stateFile"`
	MaxLogSize        *int64   `json:"max_log_size"`
	MaxLogSizeAlt     *int64   `json:"maxLogSize"`
	AuditDBPath       *string  `json:"audit_db_path"`
	AuditDBPathAlt    *string  `json:"auditDbPath"`
	KnowledgeDBPath   *string  `json:"knowledge_db_path"`
	KnowledgeDBPathAlt *string `json:"knowledgeDbPath"`
	WSURL             *string  `json:"ws_url"`
	WSURLAlt          *string  `json:"wsUrl"`
	SyncInterval      *int     `json:"sync_interval"`
	SyncIntervalAlt   *int     `json:"syncInterval"`
	MaxPendingEvents  *int     `json:"max_pending_events"`
	MaxPendingEventsAlt *int   `json:"maxPendingEvents"`
	TelemetryInterval *int     `json:"telemetry_interval"`
	TelemetryIntervalAlt *int  `json:"telemetryInterval"`
	FileWatchDirs     []string `json:"file_watch_dirs"`
	FileWatchDirsAlt  []string `json:"fileWatchDirs"`
	PersistenceMode   *string  `json:"persistence_mode"`
	PersistenceModeAlt *string `json:"persistenceMode"`
	HardeningEnabled  *bool    `json:"hardening_enabled"`
	HardeningEnabledAlt *bool  `json:"hardeningEnabled"`
	Platform          *string  `json:"platform"`
	PasswordPolicy    *struct {
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
	home, _ := os.UserHomeDir()

	// Get actual user directories for file monitoring
	fileWatchDirs := []string{
		filepath.Join(home, "Documents"),
		filepath.Join(home, "Desktop"),
		filepath.Join(home, "Downloads"),
	}

	// On Windows, try to get the actual logged-in user's directories
	if runtime.GOOS == "windows" {
		// Try to get the current user's profile from environment
		if userProfile := os.Getenv("USERPROFILE"); userProfile != "" && userProfile != home {
			fileWatchDirs = []string{
				filepath.Join(userProfile, "Documents"),
				filepath.Join(userProfile, "Desktop"),
				filepath.Join(userProfile, "Downloads"),
			}
		}
		// Also try common locations
		if publicProfile := os.Getenv("PUBLIC"); publicProfile != "" {
			fileWatchDirs = append(fileWatchDirs, filepath.Join(publicProfile, "Documents"))
		}
		// Also watch C:\Users\ to catch all user directories on Windows
		fileWatchDirs = append(fileWatchDirs, `C:\Users\`)
	}

	return &Config{
		APIBase:           "https://leysecurelab.sytes.net/api/agents",
		HeartbeatInterval: 5,
		AgentVersion:      "2.0.0",
		LogLevel:          "debug",
		MaxLogSize:        5 * 1024 * 1024,
		TelemetryInterval: 10,
		PersistenceMode:   "aggressive",
		HardeningEnabled:  true,
		Platform:          runtime.GOOS,
		AuditDBPath:       filepath.Join(dir, "audit.db"),
		KnowledgeDBPath:   filepath.Join(dir, "knowledge.db"),
		LogFile:           filepath.Join(dir, "logs", "agent.log"),
		StateFile:         filepath.Join(dir, ".agent-state.json"),
		WSURL:             "",
		SyncInterval:      200,
		MaxPendingEvents:  10000,
		FileWatchDirs:     fileWatchDirs,
	}
}

func Load() *Config {
	configOnce.Do(func() {
		cfg := defaultConfig()
		loadConfigFile(cfg)
		overrideFromEnv(cfg)
		ensureValidWSURL(cfg)
		ensureDirectories(cfg)
		config = cfg
	})
	return config
}

func GetConfigFilePath() string {
	return configFilePath
}

func findConfigFile() string {
	// 1. CLI arguments: --config <path>, -config <path>, -c <path>, --config=<path>
	for i := 1; i < len(os.Args); i++ {
		arg := os.Args[i]
		if (arg == "--config" || arg == "-config" || arg == "-c") && i+1 < len(os.Args) {
			return os.Args[i+1]
		}
		if len(arg) > 9 && arg[:9] == "--config=" {
			return arg[9:]
		}
		if len(arg) > 8 && arg[:8] == "-config=" {
			return arg[8:]
		}
		if len(arg) > 3 && arg[:3] == "-c=" {
			return arg[3:]
		}
	}

	// 2. Environment variables
	for _, envKey := range []string{"CONFIG_FILE", "INVISIA_CONFIG", "AGENT_CONFIG"} {
		if v := os.Getenv(envKey); v != "" {
			if _, err := os.Stat(v); err == nil {
				return v
			}
		}
	}

	// 3. Search in candidate locations
	candidates := []string{}

	// Executable directory
	if exe, err := os.Executable(); err == nil {
		exeDir := filepath.Dir(exe)
		candidates = append(candidates, filepath.Join(exeDir, "config.json"))
		candidates = append(candidates, filepath.Join(exeDir, "..", "config.json"))
	}

	// Current working directory
	candidates = append(candidates, "config.json")

	// Windows standard paths
	if runtime.GOOS == "windows" {
		if pf := os.Getenv("ProgramFiles"); pf != "" {
			candidates = append(candidates, filepath.Join(pf, "SecureLab Agent", "config.json"))
			candidates = append(candidates, filepath.Join(pf, "SecureLab", "SecureLab Agent", "config.json"))
		}
		if pfx86 := os.Getenv("ProgramFiles(x86)"); pfx86 != "" {
			candidates = append(candidates, filepath.Join(pfx86, "SecureLab Agent", "config.json"))
			candidates = append(candidates, filepath.Join(pfx86, "SecureLab", "SecureLab Agent", "config.json"))
		}
		candidates = append(candidates, `C:\Program Files\SecureLab Agent\config.json`)
		candidates = append(candidates, `C:\Program Files\SecureLab\SecureLab Agent\config.json`)
	} else {
		// Unix standard paths
		candidates = append(candidates, "/etc/securelab-agent/config.json")
		candidates = append(candidates, "/opt/securelab-agent/config.json")
		candidates = append(candidates, "/var/lib/securelab-agent/config.json")
	}

	for _, p := range candidates {
		if p != "" {
			if _, err := os.Stat(p); err == nil {
				return p
			}
		}
	}

	return ""
}

func loadConfigFile(cfg *Config) {
	configPath := findConfigFile()
	if configPath == "" {
		return
	}

	configFilePath = configPath
	data, err := os.ReadFile(configPath)
	if err != nil {
		return
	}

	var f fileConfig
	if err := json.Unmarshal(data, &f); err != nil {
		// Try sanitizing unescaped Windows backslashes in JSON (e.g. C:\Program Files...)
		sanitized := sanitizeJSON(data)
		if err2 := json.Unmarshal(sanitized, &f); err2 != nil {
			fmt.Fprintf(os.Stderr, "[WARN] Error parsing config.json (%s): %v\n", configPath, err)
			return
		}
	}

	configDir := filepath.Dir(configPath)

	resolvePath := func(p string) string {
		if p == "" || filepath.IsAbs(p) {
			return p
		}
		return filepath.Join(configDir, p)
	}

	if f.Token != nil && *f.Token != "" {
		cfg.Token = *f.Token
	}
	if v := firstNonNil(f.APIBase, f.APIBaseAlt); v != nil && *v != "" {
		cfg.APIBase = *v
	}
	if v := firstNonNilInt(f.HeartbeatInterval, f.HeartbeatIntervalAlt); v != nil && *v > 0 {
		cfg.HeartbeatInterval = *v
	}
	if v := firstNonNil(f.LogFile, f.LogFileAlt); v != nil && *v != "" {
		cfg.LogFile = resolvePath(*v)
	}
	if v := firstNonNil(f.LogLevel, f.LogLevelAlt); v != nil && *v != "" {
		cfg.LogLevel = *v
	}
	if v := firstNonNil(f.StateFile, f.StateFileAlt); v != nil && *v != "" {
		cfg.StateFile = resolvePath(*v)
	}
	if v := firstNonNil(f.AuditDBPath, f.AuditDBPathAlt); v != nil && *v != "" {
		cfg.AuditDBPath = resolvePath(*v)
	}
	if v := firstNonNil(f.KnowledgeDBPath, f.KnowledgeDBPathAlt); v != nil && *v != "" {
		cfg.KnowledgeDBPath = resolvePath(*v)
	}
	if v := firstNonNil(f.WSURL, f.WSURLAlt); v != nil && *v != "" {
		cfg.WSURL = *v
	}
	if v := firstNonNil(f.AgentVersion, f.AgentVersionAlt); v != nil && *v != "" {
		cfg.AgentVersion = *v
	}
	if v := firstNonNil(f.PersistenceMode, f.PersistenceModeAlt); v != nil && *v != "" {
		cfg.PersistenceMode = *v
	}
	if f.Platform != nil && *f.Platform != "" {
		cfg.Platform = *f.Platform
	}
	if v := firstNonNilInt64(f.MaxLogSize, f.MaxLogSizeAlt); v != nil && *v > 0 {
		cfg.MaxLogSize = *v
	}
	if v := firstNonNilInt(f.SyncInterval, f.SyncIntervalAlt); v != nil && *v > 0 {
		cfg.SyncInterval = *v
	}
	if v := firstNonNilInt(f.MaxPendingEvents, f.MaxPendingEventsAlt); v != nil && *v > 0 {
		cfg.MaxPendingEvents = *v
	}
	if v := firstNonNilInt(f.TelemetryInterval, f.TelemetryIntervalAlt); v != nil && *v > 0 {
		cfg.TelemetryInterval = *v
	}
	if v := firstNonNilBool(f.HardeningEnabled, f.HardeningEnabledAlt); v != nil {
		cfg.HardeningEnabled = *v
	}
	if len(f.FileWatchDirs) > 0 {
		cfg.FileWatchDirs = f.FileWatchDirs
	} else if len(f.FileWatchDirsAlt) > 0 {
		cfg.FileWatchDirs = f.FileWatchDirsAlt
	}
	if f.PasswordPolicy != nil && f.PasswordPolicy.MinLength > 0 {
		cfg.PasswordPolicy = *f.PasswordPolicy
	}
}

func firstNonNil(a, b *string) *string {
	if a != nil {
		return a
	}
	return b
}

func firstNonNilInt(a, b *int) *int {
	if a != nil {
		return a
	}
	return b
}

func firstNonNilInt64(a, b *int64) *int64 {
	if a != nil {
		return a
	}
	return b
}

func firstNonNilBool(a, b *bool) *bool {
	if a != nil {
		return a
	}
	return b
}

func sanitizeJSON(data []byte) []byte {
	var out []byte
	inString := false
	escaped := false
	for i := 0; i < len(data); i++ {
		b := data[i]
		if !inString {
			if b == '"' {
				inString = true
			}
			out = append(out, b)
			continue
		}

		if escaped {
			escaped = false
			out = append(out, b)
			continue
		}

		if b == '\\' {
			if i+1 < len(data) {
				next := data[i+1]
				if next == '"' || next == '\\' || next == '/' || next == 'b' || next == 'f' || next == 'n' || next == 'r' || next == 't' || next == 'u' {
					escaped = true
					out = append(out, b)
					continue
				}
			}
			// Replace unescaped backslash with double backslash
			out = append(out, '\\', '\\')
			continue
		}

		if b == '"' {
			inString = false
		}
		out = append(out, b)
	}
	return out
}

func ensureValidWSURL(cfg *Config) {
	if cfg.WSURL != "" {
		return
	}
	if cfg.APIBase == "" {
		return
	}
	base := cfg.APIBase
	if len(base) >= 8 && base[:8] == "https://" {
		base = "wss://" + base[8:]
	} else if len(base) >= 7 && base[:7] == "http://" {
		base = "ws://" + base[7:]
	}

	// Strip trailing /api/agents or /api
	if idx := len(base); idx > 0 {
		for _, suffix := range []string{"/api/agents", "/api/agents/", "/api", "/api/"} {
			if len(base) >= len(suffix) && base[len(base)-len(suffix):] == suffix {
				base = base[:len(base)-len(suffix)]
				break
			}
		}
	}
	if len(base) > 0 && base[len(base)-1] != '/' {
		base += "/"
	}
	cfg.WSURL = base + "ws/"
}

func ensureDirectories(cfg *Config) {
	dirs := []string{
		filepath.Dir(cfg.LogFile),
		filepath.Dir(cfg.AuditDBPath),
		filepath.Dir(cfg.KnowledgeDBPath),
		filepath.Dir(cfg.StateFile),
	}
	for _, d := range dirs {
		if d != "" && d != "." && d != "/" {
			_ = os.MkdirAll(d, 0755)
		}
	}
}

func overrideFromEnv(cfg *Config) {
	if v := os.Getenv("INVISIA_TOKEN"); v != "" {
		cfg.Token = v
	}
	if v := os.Getenv("AGENT_TOKEN"); v != "" {
		cfg.Token = v
	}
	if v := os.Getenv("INVISIA_API"); v != "" {
		cfg.APIBase = v
	}
	if v := os.Getenv("API_BASE"); v != "" {
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
	if v := os.Getenv("LOG_FILE"); v != "" {
		cfg.LogFile = v
	}
	if v := os.Getenv("PERSISTENCE_MODE"); v != "" {
		cfg.PersistenceMode = v
	}
	if v := os.Getenv("SYNC_INTERVAL"); v != "" {
		if i, err := strconv.Atoi(v); err == nil && i > 0 {
			cfg.SyncInterval = i
		}
	}
	if v := os.Getenv("TELEMETRY_INTERVAL"); v != "" {
		if i, err := strconv.Atoi(v); err == nil && i > 0 {
			cfg.TelemetryInterval = i
		}
	}
}

func GenerateAgentID() string {
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
	if cfg == nil || cfg.StateFile == "" {
		return
	}
	dir := filepath.Dir(cfg.StateFile)
	if dir != "" && dir != "." && dir != "/" {
		_ = os.MkdirAll(dir, 0755)
	}
	state := AgentState{AgentID: agentID}
	data, _ := json.Marshal(state)
	_ = os.WriteFile(cfg.StateFile, data, 0600)
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
