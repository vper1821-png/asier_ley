//go:build windows

package main

import (
	"fmt"
	"os"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/eventlog"
	"golang.org/x/sys/windows/svc/mgr"
)

type AgentService struct{}

func (s *AgentService) Execute(args []string, r <-chan svc.ChangeRequest, changes chan<- svc.Status) (bool, uint32) {
	writeDebug("AgentService.Execute() called, args=%v", args)
	const cmdsAccepted = svc.AcceptStop | svc.AcceptShutdown
	changes <- svc.Status{State: svc.Running, Accepts: cmdsAccepted}
	writeDebug("Service status set to Running")
	go runAgent()

	for {
		select {
		case c := <-r:
			switch c.Cmd {
			case svc.Interrogate:
				changes <- c.CurrentStatus
			case svc.Stop, svc.Shutdown:
				logMsg("Service stop requested")
				changes <- svc.Status{State: svc.StopPending}
				osExit(0)
				return false, 0
			}
		}
	}
}

func runAsService() bool {
	inService, err := svc.IsWindowsService()
	writeDebug("svc.IsWindowsService() -> %v, err=%v", inService, err)
	if err != nil {
		logMsg("Service check error: %v", err)
		return false
	}
	if !inService {
		writeDebug("Not running as Windows Service, continuing as app")
		return false
	}
	writeDebug("Running as Windows Service, calling svc.Run(SecureLabAgent)")
	logMsg("Running as Windows Service")
	err = svc.Run("SecureLabAgent", &AgentService{})
	if err != nil {
		logMsg("Service run error: %v", err)
	}
	return true
}

func installService() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect to service manager: %w", err)
	}
	defer m.Disconnect()

	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("get executable path: %w", err)
	}

	s, err := m.OpenService("SecureLabAgent")
	if err == nil {
		s.Close()
		return fmt.Errorf("service already exists")
	}

	cfg := GetConfig()
	envVars := []string{}
	if cfg.Token != "" {
		envVars = append(envVars, "INVISIA_TOKEN="+cfg.Token)
	}
	if cfg.APIBase != "" {
		envVars = append(envVars, "INVISIA_API="+cfg.APIBase)
	}

	s, err = m.CreateService("SecureLabAgent", exe, mgr.Config{
		DisplayName:      "SecureLab Agent",
		Description:      "SecureLab Security Agent - Endpoint protection, user monitoring, firewall management, and database compliance scanning.",
		StartType:        mgr.StartAutomatic,
		DelayedAutoStart: true,
	}, envVars...)
	if err != nil {
		return fmt.Errorf("create service: %w", err)
	}
	defer s.Close()

	if err := s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 10 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}, 86400); err != nil {
		logMsg("Service recovery config: %v", err)
	}

	if err := eventlog.InstallAsEventCreate("SecureLabAgent", eventlog.Error|eventlog.Warning|eventlog.Info); err != nil {
		logMsg("Event log install: %v", err)
	}

	if err := s.Start(); err != nil {
		return fmt.Errorf("start service: %w", err)
	}

	return nil
}

func startService() bool {
	m, err := mgr.Connect()
	if err != nil {
		return false
	}
	defer m.Disconnect()

	s, err := m.OpenService("SecureLabAgent")
	if err != nil {
		return false
	}
	defer s.Close()

	if err := s.Start(); err != nil {
		return false
	}
	logMsg("Service started")
	return true
}

func uninstallService() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect: %w", err)
	}
	defer m.Disconnect()

	s, err := m.OpenService("SecureLabAgent")
	if err != nil {
		return fmt.Errorf("service not found: %w", err)
	}
	defer s.Close()

	// Stop the service first
	s.Control(svc.Stop)

	if err := s.Delete(); err != nil {
		return fmt.Errorf("delete service: %w", err)
	}

	eventlog.Remove("SecureLabAgent")

	return nil
}
