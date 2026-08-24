//go:build windows

package windows

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/eventlog"
	"golang.org/x/sys/windows/svc/mgr"
)

type AgentService struct {
	runFunc func(context.Context)
}

func (s *AgentService) Execute(args []string, r <-chan svc.ChangeRequest, changes chan<- svc.Status) (bool, uint32) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Report running immediately so SCM is happy
	changes <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}

	var wg sync.WaitGroup
	if s.runFunc != nil {
		wg.Add(1)
		go func() {
			defer wg.Done()
			s.runFunc(ctx)
		}()
	}

	for {
		c := <-r
		switch c.Cmd {
		case svc.Stop, svc.Shutdown:
			changes <- svc.Status{State: svc.StopPending}
			// Signal shutdown and wait for cleanup
			cancel()
			done := make(chan struct{})
			go func() {
				wg.Wait()
				close(done)
			}()
			select {
			case <-done:
			case <-time.After(5 * time.Second):
				// Force exit if cleanup takes too long
			}
			return false, 0
		case svc.Interrogate:
			changes <- c.CurrentStatus
		}
	}
}

func RunService(run func(context.Context)) error {
	inService, err := svc.IsWindowsService()
	if err != nil {
		return fmt.Errorf("service check: %w", err)
	}
	if !inService {
		// Not running as service, run with signal handling
		ctx, cancel := context.WithCancel(context.Background())
		sigChan := make(chan os.Signal, 1)
		signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)
		go func() {
			<-sigChan
			cancel()
		}()
		run(ctx)
		return nil
	}
	return svc.Run("SecureLabAgent", &AgentService{runFunc: run})
}

func InstallService() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect: %w", err)
	}
	defer m.Disconnect()
	exe, _ := os.Executable()

	// Try to remove existing service first to avoid conflicts on reinstall
	_ = removeServiceNoLock(m)

	s, err := m.CreateService("SecureLabAgent", exe, mgr.Config{
		DisplayName: "SecureLab Agent",
		Description: "SecureLab Security Agent - Endpoint protection and compliance monitoring.",
		StartType:   mgr.StartAutomatic,
	})
	if err != nil {
		return fmt.Errorf("create service: %w", err)
	}
	defer s.Close()
	s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 10 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}, 86400)
	eventlog.InstallAsEventCreate("SecureLabAgent", eventlog.Error|eventlog.Warning|eventlog.Info)

	// Try to start the service, but don't fail the install if it can't start immediately
	if err := s.Start(); err != nil {
		// Log warning but report success for install/upgrade purposes
		fmt.Fprintf(os.Stderr, "Advertencia: servicio creado pero no pudo iniciarse: %v\n", err)
	}
	return nil
}

func RemoveService() error {
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect: %w", err)
	}
	defer m.Disconnect()
	return removeServiceNoLock(m)
}

func removeServiceNoLock(m *mgr.Mgr) error {
	s, err := m.OpenService("SecureLabAgent")
	if err != nil {
		// Service doesn't exist, not an error
		return nil
	}
	defer s.Close()

	// Stop first if running (best-effort)
	status, err := s.Control(svc.Stop)
	_ = status
	_ = err
	time.Sleep(1 * time.Second)

	return s.Delete()
}
