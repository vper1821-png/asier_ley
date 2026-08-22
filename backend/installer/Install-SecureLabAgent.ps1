# Instalador silencioso de SecureLab Agent
param()

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
$admin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $admin) {
    $arg = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSCommandPath`""
    Start-Process -FilePath powershell -ArgumentList $arg -Verb runas -Wait
    exit
}

$ErrorActionPreference = 'SilentlyContinue'
$dir = "C:\Program Files\SecureLab Agent"
$src = Split-Path -Parent $MyInvocation.MyCommand.Definition
$exe = Join-Path $src 'securelab-agent.exe'
$cfg = Join-Path $src 'config.json'

# Detener y eliminar servicio previo
$svc = Get-Service -Name 'SecureLabAgent' -ErrorAction SilentlyContinue
if ($svc) {
    Stop-Service -Name 'SecureLabAgent' -Force 2>$null | Out-Null
}
sc.exe delete 'SecureLabAgent' 2>$null | Out-Null

# Eliminar tarea programada previa (logon de usuario)
$taskName = "SecureLabAgent-User"
schtasks /Delete /TN $taskName /F 2>$null | Out-Null

# Limpiar e instalar
Remove-Item -Path $dir -Recurse -Force 2>$null | Out-Null
New-Item -ItemType Directory -Path $dir -Force | Out-Null
Copy-Item -Path $exe -Destination (Join-Path $dir 'securelab-agent.exe') -Force | Out-Null
Copy-Item -Path $cfg -Destination (Join-Path $dir 'config.json') -Force | Out-Null

# Instalar servicio SYSTEM (para persistencia y tareas de fondo)
& (Join-Path $dir 'securelab-agent.exe') 'install' 2>&1 | Out-Null

# Asegurar persistencia: inicio automático + reinicio ante fallos
sc.exe config 'SecureLabAgent' start= auto 2>&1 | Out-Null
sc.exe failure 'SecureLabAgent' reset= 86400 actions= 'restart/0/restart/0/restart/0' 2>&1 | Out-Null

# Iniciar servicio
Start-Service -Name 'SecureLabAgent' -ErrorAction SilentlyContinue

# Crear tarea programada en logon de usuario (para screenshot, alarma, overlay, shell interactivo)
# Se ejecuta con los máximos privilegios en la sesión del usuario que inicia sesión
$taskAction = New-ScheduledTaskAction -Execute "$dir\securelab-agent.exe"
$taskTrigger = New-ScheduledTaskTrigger -AtLogOn
$taskPrincipal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
$taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 24)
Register-ScheduledTask -TaskName $taskName -Action $taskAction -Trigger $taskTrigger -Principal $taskPrincipal -Settings $taskSettings -Force -ErrorAction SilentlyContinue | Out-Null

# También crear tarea para el usuario actual si hay sesión activa
$currentUser = $env:USERNAME
if ($currentUser -and $currentUser -ne "SYSTEM") {
    $taskPrincipalUser = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$currentUser" -LogonType Interactive -RunLevel Highest
    Register-ScheduledTask -TaskName "$taskName-$currentUser" -Action $taskAction -Trigger $taskTrigger -Principal $taskPrincipalUser -Settings $taskSettings -Force -ErrorAction SilentlyContinue | Out-Null
}
