//go:build windows

package windows

import (
	"fmt"
	"os"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/eventlog"
	"golang.org/x/sys/windows/svc/mgr"
)

// AgentService implementa la interfaz svc.Handler
type AgentService struct {
	runFunc func() // función de arranque del agente
}

func (s *AgentService) Execute(args []string, r <-chan svc.ChangeRequest, changes chan<- svc.Status) (bool, uint32) {
	changes <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	// Iniciar el agente en una goroutine
	if s.runFunc != nil {
		go s.runFunc()
	}
	for {
		c := <-r
		switch c.Cmd {
		case svc.Stop, svc.Shutdown:
			changes <- svc.Status{State: svc.StopPending}
			return false, 0
		case svc.Interrogate:
			changes <- c.CurrentStatus
		}
	}
}

// RunService inicia el servicio con la función de arranque proporcionada.
// Esta función debe ser llamada desde main cuando se ejecute como servicio.
func RunService(run func()) error {
	inService, err := svc.IsWindowsService()
	if err != nil {
		return fmt.Errorf("service check: %w", err)
	}
	if !inService {
		return nil // no es un servicio, ejecutar en foreground
	}
	return svc.Run("SecureLabAgent", &AgentService{runFunc: run})
}

// InstallService instala el servicio.
func InstallService() error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	exe, _ := os.Executable()
	s, err := m.CreateService("SecureLabAgent", exe, mgr.Config{
		DisplayName: "SecureLab Agent",
		Description: "SecureLab Security Agent",
		StartType:   mgr.StartAutomatic,
	})
	if err != nil {
		return err
	}
	defer s.Close()
	s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 5 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 10 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
	}, 86400)
	eventlog.InstallAsEventCreate("SecureLabAgent", eventlog.Error|eventlog.Warning|eventlog.Info)
	return s.Start()
}
