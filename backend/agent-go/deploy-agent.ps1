#Requires -RunAsAdministrator
$serviceName = "SecureLabAgent"
$servicePath = "C:\Program Files\SecureLab\Agent"
$newBinary = "C:\Users\asier\Videos\ley\agent-go\invisia-agent.exe"
$backupBinary = "$servicePath\securelab-agent.exe.bak"

Write-Output "Deteniendo servicio $serviceName..."
Stop-Service $serviceName -Force
Start-Sleep -Seconds 2

Write-Output "Respaldando binario anterior..."
if (Test-Path "$servicePath\securelab-agent.exe") {
    Copy-Item "$servicePath\securelab-agent.exe" $backupBinary -Force
}

Write-Output "Copiando nuevo binario..."
Copy-Item $newBinary "$servicePath\securelab-agent.exe" -Force

Write-Output "Iniciando servicio $serviceName..."
Start-Service $serviceName
Start-Sleep -Seconds 2

$svc = Get-Service $serviceName
if ($svc.Status -eq "Running") {
    Write-Output "Servicio iniciado correctamente."
    Write-Output "Nuevo binario: $(Get-Item "$servicePath\securelab-agent.exe" | Select-Object Length, LastWriteTime)"
} else {
    Write-Output "ERROR: El servicio no inició. Status: $($svc.Status)"
}
