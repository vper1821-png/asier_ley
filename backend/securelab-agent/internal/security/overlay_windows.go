//go:build windows

package security

import (
	_ "embed"
	"encoding/base64"
	"encoding/binary"
	"math"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"syscall"
)

//go:embed assets/logo-nuevo.png
var logoPNG []byte

const overlayScript = `param(
  [string]$MsgFile,
  [string]$Logo,
  [string]$Alarm,
  [switch]$Silent
)
$ErrorActionPreference = 'SilentlyContinue'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.Speech
Add-Type -AssemblyName System.Media

# Only set max volume and use TTS if not silent
if (-not $Silent) {
  Add-Type -TypeDefinition @"
  using System.Runtime.InteropServices;
  public class SecVol {
    [DllImport(""winmm.dll"")]
    public static extern int waveOutSetVolume(IntPtr hwo, uint dwVolume);
  }
"@
  [SecVol]::waveOutSetVolume([IntPtr]::Zero, 0xFFFFFFFF) | Out-Null
}

$message = 'ESTE EQUIPO ESTA BLOQUEADO POR SEGURIDAD'
if (Test-Path $MsgFile) {
  $raw = (Get-Content $MsgFile -Raw).Trim()
  if ($raw) { $message = $raw }
}

$form = New-Object System.Windows.Forms.Form
$form.Text = 'SecureLab - Equipo Bloqueado'
$form.WindowState = [System.Windows.Forms.FormWindowState]::Maximized
$form.FormBorderStyle = [System.Windows.Forms.FormBorderStyle]::None
$form.TopMost = $true
$form.BackColor = [System.Drawing.Color]::FromArgb(8, 8, 14)
$form.ShowInTaskbar = $false
$form.ControlBox = $false
$form.KeyPreview = $true

$table = New-Object System.Windows.Forms.TableLayoutPanel
$table.Dock = [System.Windows.Forms.DockStyle]::Fill
$table.ColumnCount = 1
$table.RowCount = 3
$table.RowStyles.Add((New-Object System.Windows.Forms.RowStyle('Percent', 18))) | Out-Null
$table.RowStyles.Add((New-Object System.Windows.Forms.RowStyle('Percent', 64))) | Out-Null
$table.RowStyles.Add((New-Object System.Windows.Forms.RowStyle('Percent', 18))) | Out-Null

$lbl = New-Object System.Windows.Forms.Label
$lbl.Text = $message
$lbl.ForeColor = [System.Drawing.Color]::White
$lbl.Font = New-Object System.Drawing.Font('Segoe UI', 20, [System.Drawing.FontStyle]::Bold)
$lbl.TextAlign = [System.Drawing.ContentAlignment]::MiddleCenter
$lbl.Dock = [System.Windows.Forms.DockStyle]::Fill
$lbl.AutoSize = $false
$table.Controls.Add($lbl, 0, 0)

$pic = New-Object System.Windows.Forms.PictureBox
$pic.Dock = [System.Windows.Forms.DockStyle]::Fill
$pic.SizeMode = [System.Windows.Forms.PictureBoxSizeMode]::Zoom
if (Test-Path $Logo) { $pic.Image = [System.Drawing.Image]::FromFile($Logo) }
$table.Controls.Add($pic, 0, 1)

$sub = New-Object System.Windows.Forms.Label
$sub.Text = 'SecureLab - Cumplimiento Ley 21.719  |  El equipo sera desbloqueado por el DPO cuando corresponda'
$sub.ForeColor = [System.Drawing.Color]::FromArgb(148, 163, 184)
$sub.Font = New-Object System.Drawing.Font('Segoe UI', 12)
$sub.TextAlign = [System.Drawing.ContentAlignment]::MiddleCenter
$sub.Dock = [System.Windows.Forms.DockStyle]::Fill
$sub.AutoSize = $false
$table.Controls.Add($sub, 0, 2)

$form.Controls.Add($table)

$form.Add_FormClosing({ param($s, $e) $e.Cancel = $true })



if (-not $Silent) {
  try {
    $synth = New-Object System.Speech.Synthesis.SpeechSynthesizer
    $synth.Volume = 100
    $synth.Rate = 0
    $synth.Speak($message)
  } catch {}
}

$form.Add_Shown({ $form.Activate() })
[System.Windows.Forms.Application]::Run($form)
`

func agentDir() string {
	exe, _ := os.Executable()
	return filepath.Dir(exe)
}

func overlayPath() string { return filepath.Join(agentDir(), "overlay.ps1") }
func logoPath() string    { return filepath.Join(agentDir(), "logo-nuevo.png") }
func alarmPath() string   { return filepath.Join(agentDir(), "alarm.wav") }
func msgPath() string     { return filepath.Join(agentDir(), ".lockdown-msg.txt") }
func overlayPidFile() string {
	return filepath.Join(agentDir(), ".securelab-overlay-pid")
}
func alarmPidFile() string {
	return filepath.Join(agentDir(), ".securelab-alarm-pid")
}

func hiddenProc() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
}

func ensureAssets() {
	os.WriteFile(overlayPath(), []byte(overlayScript), 0644)
	if _, err := os.Stat(logoPath()); err != nil {
		os.WriteFile(logoPath(), logoPNG, 0644)
	}
	if _, err := os.Stat(alarmPath()); err != nil {
		writeAlarmWav(alarmPath())
	}
}

func applyLockdown(message string, silent bool) {
	ensureAssets()
	os.WriteFile(msgPath(), []byte(message), 0644)
	stopOverlayProcess()
	args := []string{
		"-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass",
		"-File", overlayPath(), "-MsgFile", msgPath(), "-Logo", logoPath(), "-Alarm", alarmPath(),
	}
	if silent {
		args = append(args, "-Silent")
	}
	cmd := exec.Command("powershell", args...)
	cmd.SysProcAttr = hiddenProc()
	if err := cmd.Start(); err == nil {
		os.WriteFile(overlayPidFile(), []byte(strconv.Itoa(cmd.Process.Pid)), 0600)
	}
}

func removeLockdown() {
	stopOverlayProcess()
	os.Remove(msgPath())
}

func stopOverlayProcess() {
	if pid, err := readPidFile(overlayPidFile()); err == nil && pid > 0 {
		exec.Command("taskkill", "/F", "/T", "/PID", strconv.Itoa(pid)).Run()
	}
	os.Remove(overlayPidFile())
}

// PlayAlarm reproduce la alarma una sola vez a máximo volumen.
func PlayAlarm() {
	ensureAssets()
	StopAlarm()
	ps := "Add-Type -AssemblyName System.Media; $p=New-Object System.Media.SoundPlayer('" + alarmPath() + "'); $p.Play()"
	cmd := exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command", ps)
	cmd.SysProcAttr = hiddenProc()
	if err := cmd.Start(); err == nil {
		os.WriteFile(alarmPidFile(), []byte(strconv.Itoa(cmd.Process.Pid)), 0600)
	}
	setMaxVolume()
}

// StopAlarm detiene la alarma.
func StopAlarm() {
	if pid, err := readPidFile(alarmPidFile()); err == nil && pid > 0 {
		exec.Command("taskkill", "/F", "/T", "/PID", strconv.Itoa(pid)).Run()
	}
	os.Remove(alarmPidFile())
}

// Speak reproduce un texto configurado por el DPO a máximo volumen.
func Speak(text string) {
	if text == "" {
		return
	}
	setMaxVolume()
	encoded := base64.StdEncoding.EncodeToString([]byte(text))
	ps := "Add-Type -AssemblyName System.Speech; $s=New-Object System.Speech.Synthesis.SpeechSynthesizer; $s.Volume=100; $s.Rate=0; $s.Speak([System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('" + encoded + "')))"
	cmd := exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command", ps)
	cmd.SysProcAttr = hiddenProc()
	cmd.Start()
}

func setMaxVolume() {
	ps := "Add-Type -TypeDefinition 'using System.Runtime.InteropServices; public class SecVol2 { [DllImport(\"winmm.dll\")] public static extern int waveOutSetVolume(IntPtr h, uint v); }'; [SecVol2]::waveOutSetVolume([IntPtr]::Zero, 0xFFFFFFFF)"
	cmd := exec.Command("powershell", "-NoProfile", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-Command", ps)
	cmd.SysProcAttr = hiddenProc()
	cmd.Run()
}

func readPidFile(path string) (int, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}
	return strconv.Atoi(string(data))
}

func writeAlarmWav(path string) {
	sr := 8000
	n := sr * 2
	buf := make([]byte, 44+n)
	copy(buf, "RIFF")
	binary.LittleEndian.PutUint32(buf[4:], uint32(36+n))
	copy(buf[8:], "WAVE")
	copy(buf[12:], "fmt ")
	binary.LittleEndian.PutUint32(buf[16:], 16)
	binary.LittleEndian.PutUint16(buf[20:], 1)
	binary.LittleEndian.PutUint16(buf[22:], 1)
	binary.LittleEndian.PutUint32(buf[24:], uint32(sr))
	binary.LittleEndian.PutUint32(buf[28:], uint32(sr))
	binary.LittleEndian.PutUint16(buf[32:], 1)
	binary.LittleEndian.PutUint16(buf[34:], 8)
	copy(buf[36:], "data")
	binary.LittleEndian.PutUint32(buf[40:], uint32(n))
	for i := 0; i < n; i++ {
		t := float64(i) / float64(sr)
		m := math.Mod(t, 0.6)
		var v float64
		if m < 0.3 {
			f := 880.0
			if int(t/0.6)%2 == 1 {
				f = 620.0
			}
			v = math.Sin(2 * math.Pi * f * t)
		}
		buf[44+i] = byte(128 + int(110*v))
	}
	os.WriteFile(path, buf, 0644)
}
