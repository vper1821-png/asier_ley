//go:build windows

package sysinfo

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"securelab-agent/internal/security"
)

func execPS(script string) string {
	out, err := exec.Command("powershell", "-NoProfile", "-NonInteractive", "-Command", script).Output()
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(out))
}

// Processes devuelve los procesos con mayor uso de memoria.
func GetProcesses() []Process {
	ps := `$ErrorActionPreference='SilentlyContinue'
Get-Process | Sort-Object WS -Descending | Select-Object -First 25 @{n='pid';e={$_.Id}},@{n='name';e={$_.ProcessName}},@{n='memMB';e={[int][math]::Round($_.WS/1MB,0)}},@{n='cpu';e={[math]::Round($_.CPU,1)}} | ConvertTo-Json -Compress`
	out := execPS(ps)
	if out == "" {
		return []Process{}
	}
	var list []Process
	if json.Unmarshal([]byte(out), &list) != nil {
		var one Process
		if json.Unmarshal([]byte(out), &one) == nil {
			list = []Process{one}
		}
	}
	return list
}

// Health devuelve un snapshot de salud del equipo.
func GetHealth() Health {
	ps := `$ErrorActionPreference='SilentlyContinue'
$os=Get-CimInstance Win32_OperatingSystem
$cpu=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average
$d=Get-CimInstance Win32_LogicalDisk -Filter "DeviceID='C:'"
$top=Get-Process | Sort-Object WS -Descending | Select-Object -First 8 @{n='pid';e={$_.Id}},@{n='name';e={$_.ProcessName}},@{n='memMB';e={[int][math]::Round($_.WS/1MB,0)}},@{n='cpu';e={[math]::Round($_.CPU,1)}}
[PSCustomObject]@{
 cpu=[int][math]::Round($cpu,0)
 memory=[int][math]::Round((($os.TotalVisibleMemorySize-$os.FreePhysicalMemory)/$os.TotalVisibleMemorySize)*100,0)
 diskFree=[long]$d.FreeSpace
 diskTotal=[long]$d.Size
 processes=(Get-Process).Count
 uptime=[long](((Get-Date)-$os.LastBootUpTime).TotalSeconds)
 top=@($top)
} | ConvertTo-Json -Compress -Depth 4`
	out := execPS(ps)
	h := Health{Hostname: hostname(), Platform: platform(), TopProcesses: []Process{}}
	if out == "" {
		return h
	}
	var raw struct {
		CPU       int       `json:"cpu"`
		Memory    int       `json:"memory"`
		DiskFree  int64     `json:"diskFree"`
		DiskTotal int64     `json:"diskTotal"`
		Processes int       `json:"processes"`
		Uptime    int64     `json:"uptime"`
		Top       []Process `json:"top"`
	}
	if json.Unmarshal([]byte(out), &raw) == nil {
		h.CPU = raw.CPU
		h.Memory = raw.Memory
		h.DiskFree = raw.DiskFree
		h.DiskTotal = raw.DiskTotal
		h.Processes = raw.Processes
		h.Uptime = raw.Uptime
		h.TopProcesses = raw.Top
	}
	return h
}

// Defender devuelve el estado de Windows Defender y firewall.
func GetDefender() Defender {
	ps := `$ErrorActionPreference='SilentlyContinue'
$s=Get-MpComputerStatus
$f=Get-NetFirewallProfile
[PSCustomObject]@{
 antivirusEnabled=[bool]$s.AntivirusEnabled
 realTimeProtection=[bool]$s.RealTimeProtectionEnabled
 signatureVersion=[string]$s.AntivirusSignatureVersion
 signatureUpdated=[string]$s.AntivirusSignatureLastUpdated
 firewallEnabled=([bool]($f | Where-Object {$_.Enabled -eq $true}).Count -gt 0)
 firewallProfiles=(($f | ForEach-Object { if($_.Enabled){$_.Name} else {"$($_.Name) (OFF)"}}) -join ', ')
} | ConvertTo-Json -Compress`
	out := execPS(ps)
	d := Defender{AntivirusEnabled: false, RealTimeProtection: false}
	if out == "" {
		return d
	}
	json.Unmarshal([]byte(out), &d)
	return d
}

// Screenshot captura la pantalla principal y la devuelve como data URL JPEG.
// Solo funciona si el agente corre en una sesión de usuario interactiva (no SYSTEM service).
func CaptureScreenshot() (string, error) {
	if !security.CanAccessDesktop() {
		return "", fmt.Errorf("screenshot no disponible: el agente corre como servicio SYSTEM sin acceso al escritorio interactivo")
	}
	tmp := filepath.Join(os.TempDir(), "slshot-"+fmt.Sprintf("%d", time.Now().UnixNano())+".jpg")
	ps := `$ErrorActionPreference='SilentlyContinue'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$b=[System.Windows.Forms.SystemInformation]::VirtualScreen
$bmp=New-Object System.Drawing.Bitmap $b.Width,$b.Height
$g=[System.Drawing.Graphics]::FromImage($bmp)
$g.CopyFromScreen($b.X,$b.Y,0,0,$bmp.Size)
$g.Dispose()
$scale=1.0
if($b.Width -gt 1280){$scale=1280.0/$b.Width}
$w=[int]($b.Width*$scale)
$h=[int]($b.Height*$scale)
$small=New-Object System.Drawing.Bitmap $w,$h
$sg=[System.Drawing.Graphics]::FromImage($small)
$sg.InterpolationMode=[System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$sg.DrawImage($bmp,0,0,$w,$h)
$sg.Dispose()
$bmp.Dispose()
$small.Save('` + tmp + `',[System.Drawing.Imaging.ImageFormat]::Jpeg)
Write-Output 'OK'`
	execPS(ps)
	data, err := os.ReadFile(tmp)
	os.Remove(tmp)
	if err != nil {
		return "", err
	}
	return "data:image/jpeg;base64," + base64.StdEncoding.EncodeToString(data), nil
}
