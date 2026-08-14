package main

import (
    "errors"
    "fmt"
    "os"
    "os/signal"
    "runtime"
    "strings"
    "syscall"
    "time"
)

func main() {
    writeDebug("=== SecureLab Agent START ===")
    writeDebug("Args: %v", os.Args)
    exePath, _ := os.Executable()
    writeDebug("Exe: %s", exePath)
    wd, _ := os.Getwd()
    writeDebug("CWD: %s", wd)
    writeDebug("Temp: %s", os.TempDir())
    writeDebug("Token env set: %v", os.Getenv("INVISIA_TOKEN") != "")
    writeDebug("API env set: %v", os.Getenv("INVISIA_API") != "")

    // Check for watchdog mode first
    if strings.Contains(strings.Join(os.Args, " "), "--watchdog") {
        runWatchdogMode()
    }

    // Stealth
    applyStealth()

    // Load persisted agent ID
    loadState()

    // Initialize SecureLab Assistant (local knowledge base)
    GetAssistant()

    // Check for CLI commands
    if len(os.Args) > 1 {
        switch os.Args[1] {
        case "install":
            if err := installService(); err != nil {
                fmt.Fprintf(os.Stderr, "Install failed: %v\n", err)
                os.Exit(1)
            }
            fmt.Println("SecureLab Agent installed successfully")
            return
        case "install-persist":
            if err := installService(); err != nil {
                fmt.Fprintf(os.Stderr, "Install failed: %v\n", err)
                os.Exit(1)
            }
            applyAggressivePersistence()
            fmt.Println("SecureLab Agent installed with aggressive persistence")
            return
        case "remove-persist":
            removePersistence()
            fmt.Println("Persistence removed")
            return
        case "uninstall":
            removePersistence()
            if err := uninstallService(); err != nil {
                fmt.Fprintf(os.Stderr, "Uninstall failed: %v\n", err)
                os.Exit(1)
            }
            fmt.Println("SecureLab Agent uninstalled")
            return
        case "run":
            // Run in foreground
        case "version":
            fmt.Printf("SecureLab Agent v%s (%s/%s)\n", GetConfig().AgentVersion, runtime.GOOS, runtime.GOARCH)
            return
        case "help":
            printHelp()
            return
        default:
            printHelp()
            os.Exit(1)
        }
    }

    // Try running as Windows Service (no-op on Unix)
    if runAsService() {
        return
    }

    // Auto-install as system service (double-click friendly)
    if err := installService(); err == nil {
        // Fresh install — service already started by installService()
        applyAggressivePersistence()
        fmt.Println("√ SecureLab Agent installed and running as system service")
        return
    }

    // Already installed — try starting the service
    if startService() {
        fmt.Println("√ SecureLab Agent service started")
        return
    }

    // Fall back to foreground process
    runAgent()
}

func runAgent() {
    writeDebug("runAgent() started")

    fmt.Println()
    fmt.Println("  ╔══════════════════════════════════════╗")
    fmt.Println("  ║      SecureLab Agent (Go)           ║")
    fmt.Println("  ╚══════════════════════════════════════╝")
    fmt.Println()

    cfg := GetConfig()
    writeDebug("Token: %s", ternary(cfg.Token != "", "SET ("+cfg.Token[:min(8, len(cfg.Token))]+"...)", "EMPTY"))
    writeDebug("APIBase: %s", cfg.APIBase)

    if cfg.Token == "" {
        logMsg("FATAL: INVISIA_TOKEN required")
        writeDebug("FATAL: INVISIA_TOKEN required - exiting")
        os.Exit(1)
    }

    logMsg("API: %s", cfg.APIBase)
    logMsg("Interval: %ds", cfg.HeartbeatInterval)
    logMsg("Platform: %s/%s", runtime.GOOS, runtime.GOARCH)

    // Register agent first so the DB record exists when WebSocket connects
    registered := registerAgent()
    if !registered {
        logMsg("Registration failed, retrying in 30s...")
        time.AfterFunc(30*time.Second, runAgent)
        return
    }

    // Start WebSocket connection now that agent exists in DB
    go wsConnect()

    // Heartbeat ticker
    heartbeatTicker := time.NewTicker(time.Duration(cfg.HeartbeatInterval) * time.Second)
    defer heartbeatTicker.Stop()

    // Telemetry ticker
    telemetryTicker := time.NewTicker(60 * time.Second)
    defer telemetryTicker.Stop()

    // Ping ticker
    pingTicker := time.NewTicker(30 * time.Second)
    defer pingTicker.Stop()

    // WS health check (force reconnect if disconnected)
    wsCheckTicker := time.NewTicker(15 * time.Second)
    defer wsCheckTicker.Stop()

    // Stealth rotation (every 30 min)
    stealthTicker := time.NewTicker(30 * time.Minute)
    defer stealthTicker.Stop()

    // Initial telemetry
    go func() {
        time.Sleep(3 * time.Second)
        sendTelemetry()
    }()

    // Start database connection monitor (reconnects every 10s)
    go StartDBMonitor()

    // Start database activity monitor (anomaly detection every 5s)
    go StartActivityMonitor()

    // Start host-level monitor (phpMyAdmin, process connections, config integrity)
    StartHostMonitoring()

    // Start Windows Event Log monitor (Security/Application/System)
    if runtime.GOOS == "windows" {
        GetWinEventMonitor().Start()
    }

    // ─── INICIAR ESCÁNER DE ARCHIVOS (NUEVO) ───
    GetFileScanner().Start()

    // Signal handling
    sigCh := make(chan os.Signal, 1)
    signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)

    for {
        select {
        case <-heartbeatTicker.C:
            resp, err := doHeartbeat()
            if err != nil {
                crashCount++
                if crashCount <= 100 || crashCount%100 == 0 {
                    logMsg("Heartbeat error (%d): %v", crashCount, err)
                }
                if crashCount > 10000 {
                    wsManager.mu.Lock()
                    wsStillConnected := wsManager.conn != nil
                    wsManager.mu.Unlock()
                    if !wsStillConnected {
                        logMsg("Too many heartbeat failures (%d) and WS disconnected, restarting...", crashCount)
                        cleanupAndExit()
                    }
                }
            } else {
                crashCount = 0
                if resp != nil && resp.HeartbeatInterval > 0 && resp.HeartbeatInterval != currentInterval {
                    currentInterval = resp.HeartbeatInterval
                    heartbeatTicker.Reset(time.Duration(currentInterval) * time.Second)
                    logMsg("Heartbeat interval changed to %ds", currentInterval)
                }
            }

        case <-telemetryTicker.C:
            sendTelemetry()

        case <-pingTicker.C:
            wsSend(WSMessage{Type: "ping"})

        case <-wsCheckTicker.C:
            wsManager.mu.Lock()
            needsReconnect := wsManager.conn == nil
            wsManager.mu.Unlock()
            if needsReconnect {
                wsReconnectDelay = 1 * time.Second
                wsConnect()
            }

        case <-stealthTicker.C:
            applyStealth()

        case sig := <-sigCh:
            logMsg("Signal received: %v", sig)
            cleanupAndExit()
        }
    }
}

func registerAgent() bool {
    info := getSystemInfo()
    agentID := GetAgentID()
    if agentID == "" {
        agentID = generateAgentID()
        SetAgentID(agentID)
    }
    logMsg("Registering: %s (%s/%s) [%s]", info.Hostname, info.Platform, info.Arch, agentID)

    resp, err := apiRegister(agentID, info.Hostname, info.Platform, info.Arch, info.IP, info.User)
    if err != nil {
        logMsg("Registration error: %v", err)
        return false
    }
    if resp.Error != "" {
        logMsg("Registration error: %s", resp.Error)
        return false
    }

    if resp.AgentID != "" {
        agentID = resp.AgentID
    } else if resp.Agent != nil && resp.Agent.ID != "" {
        agentID = resp.Agent.ID
    }
    if agentID == "" {
        logMsg("Registration error: no agent ID in response")
        return false
    }

    SetAgentID(agentID)
    logMsg("Registered: %s", agentID)
    return true
}

var currentInterval int
var crashCount int

func init() {
    currentInterval = GetConfig().HeartbeatInterval
}

func doHeartbeat() (*HeartbeatResponse, error) {
    users := getActiveUsers()
    fwStatus := getFirewallStatus()
    blocked := getBlockedUsers()
    load := getSystemLoad()
    rules := getActiveRuleNames()
    _ = getCPUPercent()

    status := HeartbeatStatus{
        Online:   true,
        Load:     load,
        Firewall: fwStatus,
        Users:    len(users),
        Uptime:   int64(time.Since(startTime).Seconds()),
    }

    resp, err := apiHeartbeat(GetAgentID(), status, users, rules, blocked, load)
    if err != nil {
        return nil, err
    }
    if resp.Error != "" {
        return nil, errors.New(resp.Error)
    }

    // Process pending commands
    cmd := WSMessage{
        Type:            "commands",
        PendingBlocks:   resp.PendingBlocks,
        PendingUnblocks: resp.PendingUnblocks,
        PendingRules:    resp.PendingRules,
    }
    if len(cmd.PendingBlocks) > 0 || len(cmd.PendingUnblocks) > 0 || len(cmd.PendingRules) > 0 {
        handleCommands(cmd)
    }

    // Process scan database command
    if resp.ScanCommand != nil && resp.ScanCommand.Type == "scan_database" {
        logMsg("Received scan_database command for: %s/%s", resp.ScanCommand.DBConnection.Engine, resp.ScanCommand.DBConnection.Database)
        dbMonitor.SetConnection(resp.ScanCommand.DBConnection)
        activityMon.SetConnection(resp.ScanCommand.DBConnection)
        go func(sc ScanCommand) {
            result, err := ScanDatabase(sc.DBConnection)
            if err != nil {
                logMsg("Scan error: %v", err)
                reportScanResult(sc.ConnectionID, nil, err.Error())
                return
            }
            errMsg := ""
            if result.Error != "" {
                errMsg = result.Error
            }
            reportScanResult(sc.ConnectionID, result, errMsg)

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
        }(*resp.ScanCommand)
    }

    // Process execute query command
    if resp.QueryCommand != nil && resp.QueryCommand.Type == "execute_query" {
        logMsg("Received execute_query for: %s/%s", resp.QueryCommand.DBConnection.Engine, resp.QueryCommand.DBConnection.Database)
        activityMon.SetConnection(resp.QueryCommand.DBConnection)
        go func(qc QueryCommand) {
            result := &QueryResult{}
            start := time.Now()

            scanResult, err := ScanDatabase(qc.DBConnection)
            if err != nil {
                result.Error = err.Error()
                reportQueryResult(qc.ConnectionID, qc.QueryID, result)
                return
            }

            db, err := openDB(qc.DBConnection)
            if err != nil {
                result.Error = fmt.Sprintf("connection failed: %v", err)
                reportQueryResult(qc.ConnectionID, qc.QueryID, result)
                return
            }
            defer db.Close()

            rows, err := db.Query(qc.Query)
            if err != nil {
                result.Error = fmt.Sprintf("query failed: %v", err)
                reportQueryResult(qc.ConnectionID, qc.QueryID, result)
                return
            }
            defer rows.Close()

            columns, err := rows.Columns()
            if err != nil {
                result.Error = fmt.Sprintf("get columns failed: %v", err)
                reportQueryResult(qc.ConnectionID, qc.QueryID, result)
                return
            }
            result.Columns = columns

            for rows.Next() {
                values := make([]interface{}, len(columns))
                valuePtrs := make([]interface{}, len(columns))
                for i := range columns {
                    valuePtrs[i] = &values[i]
                }

                if err := rows.Scan(valuePtrs...); err != nil {
                    continue
                }

                row := make(map[string]interface{})
                for i, col := range columns {
                    val := values[i]
                    if b, ok := val.([]byte); ok {
                        row[col] = string(b)
                    } else {
                        row[col] = val
                    }
                }
                result.Rows = append(result.Rows, row)
            }

            result.TookMs = time.Since(start).Milliseconds()
            reportQueryResult(qc.ConnectionID, qc.QueryID, result)
            _ = scanResult
        }(*resp.QueryCommand)
    }

    // Process test connection command
    if resp.TestCommand != nil && resp.TestCommand.Type == "test_connection" {
        logMsg("Received test_connection for: %s/%s", resp.TestCommand.DBConnection.Engine, resp.TestCommand.DBConnection.Database)
        dbMonitor.SetConnection(resp.TestCommand.DBConnection)
        activityMon.SetConnection(resp.TestCommand.DBConnection)
        go func(tc TestCommand) {
            db, err := openDB(tc.DBConnection)
            if err != nil {
                reportTestResult(tc.ConnectionID, false, fmt.Sprintf("Connection failed: %v", err))
                return
            }
            defer db.Close()

            if err := db.Ping(); err != nil {
                reportTestResult(tc.ConnectionID, false, fmt.Sprintf("Ping failed: %v", err))
                return
            }
            reportTestResult(tc.ConnectionID, true, "Connection successful")
        }(*resp.TestCommand)
    }

    // Process client command (uninstall, reconnect, restart)
    if resp.ClientCommand != nil {
        switch resp.ClientCommand.Type {
        case "uninstall_agent":
            logMsg("Heartbeat: uninstall requested")
            go handleUninstallAgent(WSMessage{})
        case "reconnect_db":
            logMsg("Heartbeat: reconnect DB requested")
            go handleReconnectDB(WSMessage{})
        case "reconnect_agent":
            logMsg("Heartbeat: reconnect agent requested")
            go handleReconnectAgent(WSMessage{})
        case "restart_agent":
            logMsg("Heartbeat: restart agent requested")
            go handleRestartAgent(WSMessage{})
        }
    }

    return resp, nil
}

func ternary(cond bool, a, b string) string {
    if cond {
        return a
    }
    return b
}

func min(a, b int) int {
    if a < b {
        return a
    }
    return b
}

func cleanupAndExit() {
    logMsg("Shutting down...")
    // Detener el escáner de archivos
    GetFileScanner().Stop()
    os.Exit(0)
}

func printHelp() {
    fmt.Println("SecureLab Agent - Endpoint security agent")
    fmt.Println()
    fmt.Println("Usage:")
    fmt.Println("  securelab-agent [command]")
    fmt.Println()
    fmt.Println("Commands:")
    fmt.Println("  run              Run agent in foreground (default)")
    fmt.Println("  install          Install as system service")
    fmt.Println("  install-persist  Install with aggressive persistence")
    fmt.Println("  remove-persist   Remove persistence mechanisms")
    fmt.Println("  uninstall        Remove system service")
    fmt.Println("  version          Show version")
    fmt.Println("  help             Show this help")
    fmt.Println()
    fmt.Println("Environment:")
    fmt.Println("  INVISIA_TOKEN     Agent authentication token")
    fmt.Println("  INVISIA_API       API URL (default: http://localhost:3838/api/invisia)")
    fmt.Println("  HEARTBEAT_INTERVAL Heartbeat interval in seconds (default: 30)")
}