//go:build !windows

package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
)

func runAsService() bool {
	return false
}

func installService() error {
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("executable path: %w", err)
	}
	exe, _ = filepath.EvalSymlinks(exe)

	switch runtime.GOOS {
	case "linux":
		return installSystemd(exe)
	case "darwin":
		return installLaunchd(exe)
	}
	return fmt.Errorf("unsupported platform")
}

func startService() bool {
	switch runtime.GOOS {
	case "linux":
		return exec.Command("systemctl", "start", "invisia-agent").Run() == nil
	case "darwin":
		home, _ := os.UserHomeDir()
		plistPath := filepath.Join(home, "Library", "LaunchAgents", "com.invisia.agent.plist")
		exec.Command("launchctl", "load", plistPath).Run()
		return exec.Command("launchctl", "start", "com.invisia.agent").Run() == nil
	}
	return false
}

func uninstallService() error {
	switch runtime.GOOS {
	case "linux":
		return uninstallSystemd()
	case "darwin":
		return uninstallLaunchd()
	}
	return fmt.Errorf("unsupported platform")
}

func installSystemd(exe string) error {
	unit := fmt.Sprintf(`[Unit]
Description=Invisia V2 Security Agent
After=network.target network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=%s
ExecStart=%s
Restart=always
RestartSec=10
StartLimitInterval=300
StartLimitBurst=5
Environment=INVISIA_TOKEN=%s
Environment=INVISIA_API=%s
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
`, filepath.Dir(exe), exe, GetConfig().Token, GetConfig().APIBase)

	if err := os.WriteFile("/etc/systemd/system/invisia-agent.service", []byte(unit), 0644); err != nil {
		return fmt.Errorf("write unit: %w", err)
	}

	exec.Command("systemctl", "daemon-reload").Run()
	exec.Command("systemctl", "enable", "invisia-agent").Run()
	exec.Command("systemctl", "start", "invisia-agent").Run()

	return nil
}

func uninstallSystemd() error {
	exec.Command("systemctl", "stop", "invisia-agent").Run()
	exec.Command("systemctl", "disable", "invisia-agent").Run()
	os.Remove("/etc/systemd/system/invisia-agent.service")
	exec.Command("systemctl", "daemon-reload").Run()
	return nil
}

func installLaunchd(exe string) error {
	dir := filepath.Dir(exe)
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.invisia.agent</string>
    <key>ProgramArguments</key>
    <array>
        <string>%s</string>
    </array>
    <key>WorkingDirectory</key>
    <string>%s</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>INVISIA_TOKEN</key>
        <string>%s</string>
        <key>INVISIA_API</key>
        <string>%s</string>
    </dict>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>%s/agent.log</string>
    <key>StandardErrorPath</key>
    <string>%s/agent.log</string>
</dict>
</plist>`, exe, dir, GetConfig().Token, GetConfig().APIBase, dir, dir)

	home, _ := os.UserHomeDir()
	plistPath := filepath.Join(home, "Library", "LaunchAgents", "com.invisia.agent.plist")
	if err := os.WriteFile(plistPath, []byte(plist), 0644); err != nil {
		return fmt.Errorf("write plist: %w", err)
	}

	exec.Command("launchctl", "load", plistPath).Run()
	exec.Command("launchctl", "start", "com.invisia.agent").Run()

	return nil
}

func uninstallLaunchd() error {
	home, _ := os.UserHomeDir()
	plistPath := filepath.Join(home, "Library", "LaunchAgents", "com.invisia.agent.plist")
	exec.Command("launchctl", "unload", plistPath).Run()
	os.Remove(plistPath)
	return nil
}
