//go:build !windows

package unix

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"syscall"
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

func RemoveService() error {
	exec.Command("systemctl", "stop", "securelab-agent").Run()
	exec.Command("systemctl", "disable", "securelab-agent").Run()
	os.Remove("/etc/systemd/system/securelab-agent.service")
	exec.Command("systemctl", "daemon-reload").Run()
	return nil
}

func RunService(runFunc func(context.Context)) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Handle signals
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-sigChan
		cancel()
	}()

	runFunc(ctx)
}
