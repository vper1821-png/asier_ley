//go:build !windows

package unix

import (
	"fmt"
	"os"
	"os/exec"
)

func InstallService() error {
	exe, _ := os.Executable()
	// Systemd
	unit := fmt.Sprintf(`[Unit]
Description=SecureLab Agent
[Service]
ExecStart=%s
Restart=always
[Install]
WantedBy=multi-user.target`, exe)
	if err := os.WriteFile("/etc/systemd/system/securelab-agent.service", []byte(unit), 0644); err != nil {
		return err
	}
	exec.Command("systemctl", "daemon-reload").Run()
	exec.Command("systemctl", "enable", "securelab-agent").Run()
	return exec.Command("systemctl", "start", "securelab-agent").Run()
}
