#Requires -RunAsAdministrator
<#
.SYNOPSIS
  SecureLab Agent - Fully automated setup for local MySQL (XAMPP)
.DESCRIPTION
  Creates config.json, generates JWT token, starts agent, creates DB connection,
  and triggers monitoring — all automatically.
#>

$ErrorActionPreference = "Stop"
$AgentDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir = "$AgentDir\..\backend-node"
$ConfigFile = "$AgentDir\config.json"
$ApiBase = "http://localhost:3838/api/agents"

Write-Output "=== SecureLab Agent Setup ===`n"

# ─── Step 1: Detect MySQL (XAMPP) ───
Write-Output "[1/5] Detectando MySQL..."
$mysqlExe = "C:\xampp\mysql\bin\mysql.exe"
$mysqlUser = "root"
$mysqlPass = ""
$mysqlHost = "127.0.0.1"
$mysqlPort = 3306
$mysqlDb = "mysql"

if (Test-Path $mysqlExe) {
    Write-Output "  -> XAMPP MySQL encontrado en $mysqlExe"

    # Test connection
    try {
        $testConn = & $mysqlExe -u $mysqlUser --host $mysqlHost --port $mysqlPort -e "SELECT 1 AS ok" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Output "  -> Conexion MySQL OK (root sin password)"
        } else {
            Write-Output "  -> Error conectando como root sin password: $testConn"
            Write-Output "  -> Introduce la password de MySQL:"
            $mysqlPass = Read-Host -AsSecureString
            $mysqlPass = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($mysqlPass))
        }
    } catch {
        Write-Output "  -> Error: $_"
    }
} else {
    Write-Output "  -> XAMPP MySQL no encontrado en C:\xampp"
    Write-Output "  -> Introduce los datos de conexion MySQL manualmente:"
    $mysqlHost = Read-Host "Host (default: 127.0.0.1)"
    if ([string]::IsNullOrWhiteSpace($mysqlHost)) { $mysqlHost = "127.0.0.1" }
    $mysqlPort = Read-Host "Port (default: 3306)"
    if ([string]::IsNullOrWhiteSpace($mysqlPort)) { $mysqlPort = 3306 }
    $mysqlUser = Read-Host "User (default: root)"
    if ([string]::IsNullOrWhiteSpace($mysqlUser)) { $mysqlUser = "root" }
    $mysqlPass = Read-Host -AsSecureString
    $mysqlPass = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($mysqlPass))
}

# ─── Step 2: Generate JWT token ───
Write-Output "`n[2/5] Generando token JWT..."
$jwtSecret = "domain-scanner-jwt-secret-key-2024-production"

# Get user from MongoDB via backend
$userCheckUrl = "http://localhost:3838/api/auth/me"
$tokenCheck = $null
try {
    $tokenCheck = Invoke-RestMethod -Uri $userCheckUrl -Method Post -Body (@{ token = "" } | ConvertTo-Json) -ContentType "application/json" -ErrorAction SilentlyContinue
} catch {}

# Use Node.js to generate the token
$genTokenScript = @"
const jwt = require('jsonwebtoken');
const token = jwt.sign(
  { userId: '6a45a96a85e6d84c343e1f9a', email: 'asiersinmas2004@gmail.com' },
  '$jwtSecret',
  { expiresIn: '365d' }
);
console.log(token);
"@

try {
    $jwtToken = node -e $genTokenScript 2>$null
    if (-not $jwtToken) { throw "Node.js no genero token" }
    Write-Output "  -> Token JWT generado correctamente"
} catch {
    Write-Output "  -> Error generando token: $_"
    exit 1
}

# ─── Step 3: Create config.json ───
Write-Output "`n[3/5] Creando config.json..."
$config = @{
    token             = $jwtToken
    api_base          = $ApiBase
    heartbeat_interval = 5
    agent_version     = "2.0.0"
    max_log_size      = 5242880
} | ConvertTo-Json

Set-Content -LiteralPath $ConfigFile -Value $config -Encoding UTF8
Write-Output "  -> $ConfigFile creado"

# ─── Step 4: Register agent and create DB connection via API ───
Write-Output "`n[4/5] Registrando agente y creando conexion DB..."
$agentId = "$env:COMPUTERNAME-" + [System.IO.Path]::GetRandomFileName().Replace(".", "").Substring(0, 8)

$registerBody = @{
    token    = $jwtToken
    agentId  = $agentId
    hostname = $env:COMPUTERNAME
    platform = "windows"
    arch     = "amd64"
    ip       = "127.0.0.1"
    version  = "2.0.0"
} | ConvertTo-Json

try {
    $registerResult = Invoke-RestMethod -Uri "$ApiBase/register" -Method Post -Body $registerBody -ContentType "application/json"
    if ($registerResult.error) {
        Write-Output "  -> Error registro: $($registerResult.error)"
    } else {
        Write-Output "  -> Agente registrado: $($registerResult.agentId)"
    }
} catch {
    Write-Output "  -> Error llamando API: $_"
}

# Create DB connection
$dbConnUrl = "http://localhost:3838/api/databases/local-connect"
$dbBody = @{
    token    = $jwtToken
    name     = "XAMPP MySQL (Local)"
    engine   = "mysql"
    host     = $mysqlHost
    port     = $mysqlPort
    database = $mysqlDb
    username = $mysqlUser
    password = $mysqlPass
} | ConvertTo-Json

try {
    $dbResult = Invoke-RestMethod -Uri $dbConnUrl -Method Post -Body $dbBody -ContentType "application/json"
    if ($dbResult.error) {
        Write-Output "  -> Error creando conexion DB: $($dbResult.error)"
    } else {
        Write-Output "  -> Conexion DB creada: $($dbResult.connection._id)"
        
        # Sync agent to this connection
        $syncUrl = "http://localhost:3838/api/databases/$($dbResult.connection._id)/sync-agent"
        $syncBody = @{
            token   = $jwtToken
            agentId = $agentId
        } | ConvertTo-Json
        
        try {
            $syncResult = Invoke-RestMethod -Uri $syncUrl -Method Post -Body $syncBody -ContentType "application/json"
            if ($syncResult.error) {
                Write-Output "  -> Error sync: $($syncResult.error)"
            } else {
                Write-Output "  -> Agente sincronizado con la DB"
                
                # Trigger scan
                $scanUrl = "http://localhost:3838/api/databases/$($dbResult.connection._id)/scan"
                $scanBody = @{ token = $jwtToken } | ConvertTo-Json
                try {
                    $scanResult = Invoke-RestMethod -Uri $scanUrl -Method Post -Body $scanBody -ContentType "application/json"
                    if ($scanResult.error) {
                        Write-Output "  -> Error scan: $($scanResult.error)"
                    } else {
                        Write-Output "  -> Scan iniciado"
                    }
                } catch { Write-Output "  -> Error scan: $_" }
            }
        } catch { Write-Output "  -> Error sync: $_" }
    }
} catch {
    Write-Output "  -> Error creando conexion: $_"
}

# ─── Step 5: Start agent ───
Write-Output "`n[5/5] Iniciando agente..."

$agentExe = "$AgentDir\agent.exe"
if (-not (Test-Path $agentExe)) {
    $agentExe = "$AgentDir\SecureLabAgent.exe"
}
if (-not (Test-Path $agentExe)) {
    $agentExe = "$AgentDir\invisia-agent.exe"
}

if (Test-Path $agentExe) {
    Write-Output "  -> Iniciando: $agentExe"
    
    # Start agent as background job
    $agentJob = Start-Job -ScriptBlock {
        param($exe, $dir)
        Set-Location -LiteralPath $dir
        & $exe run
    } -ArgumentList $agentExe, $AgentDir
    
    Write-Output "  -> Agente iniciado (Job ID: $($agentJob.Id))"
    Write-Output "  -> Log: $AgentDir\agent.log"
} else {
    Write-Output "  -> ERROR: No se encuentra agent.exe, SecureLabAgent.exe, ni invisia-agent.exe"
    Write-Output "  -> Descarga el agente desde la UI web en: http://localhost:3838/agents"
    exit 1
}

Write-Output "`n=== Setup completo ==="
Write-Output "Config: $ConfigFile"
Write-Output "Log: $AgentDir\agent.log"
Write-Output "`nPara monitorear el log en tiempo real:"
Write-Output "  Get-Content -Path '$AgentDir\agent.log' -Wait"
