package main

import (
	"context"
	"encoding/base64"
	"fmt"
	"net"
	"sync"
	"time"
)

type Tunnel struct {
	ID       string
	readCh   chan []byte
	errCh    chan error
	done     chan struct{}
	closeOnce sync.Once
}

var (
	tunnels   = make(map[string]*Tunnel)
	tunnelsMu sync.RWMutex
)

func dialTunnel(host string, port int) (net.Conn, error) {
	id := fmt.Sprintf("tun_%s_%d_%d", host, port, time.Now().UnixNano())
	tun := &Tunnel{
		ID:     id,
		readCh: make(chan []byte, 256),
		errCh:  make(chan error, 1),
		done:   make(chan struct{}),
	}

	tunnelsMu.Lock()
	tunnels[id] = tun
	tunnelsMu.Unlock()

	wsSend(WSMessage{Type: "tunnel_connect", AgentID: GetAgentID(), TunnelID: id, Host: host, Port: port})

	select {
	case <-tun.done:
		return tun, nil
	case err := <-tun.errCh:
		tunnelsMu.Lock()
		delete(tunnels, id)
		tunnelsMu.Unlock()
		return nil, err
	case <-time.After(15 * time.Second):
		tunnelsMu.Lock()
		delete(tunnels, id)
		tunnelsMu.Unlock()
		return nil, fmt.Errorf("tunnel to %s:%d timed out", host, port)
	}
}

func (t *Tunnel) Read(b []byte) (int, error) {
	data, ok := <-t.readCh
	if !ok {
		return 0, fmt.Errorf("tunnel closed")
	}
	n := copy(b, data)
	return n, nil
}

func (t *Tunnel) Write(b []byte) (int, error) {
	wsSend(WSMessage{Type: "tunnel_data", AgentID: GetAgentID(), TunnelID: t.ID, Payload: base64.StdEncoding.EncodeToString(b)})
	return len(b), nil
}

func (t *Tunnel) Close() error {
	t.closeOnce.Do(func() {
		wsSend(WSMessage{Type: "tunnel_close", AgentID: GetAgentID(), TunnelID: t.ID})
		tunnelsMu.Lock()
		delete(tunnels, t.ID)
		tunnelsMu.Unlock()
		close(t.done)
		close(t.readCh)
	})
	return nil
}

func (t *Tunnel) LocalAddr() net.Addr  { return &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0} }
func (t *Tunnel) RemoteAddr() net.Addr { return &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0} }
func (t *Tunnel) SetDeadline(_ time.Time) error      { return nil }
func (t *Tunnel) SetReadDeadline(_ time.Time) error   { return nil }
func (t *Tunnel) SetWriteDeadline(_ time.Time) error  { return nil }

func handleTunnelOpen(msg WSMessage) {
	tunnelsMu.RLock()
	tun, ok := tunnels[msg.TunnelID]
	tunnelsMu.RUnlock()
	if ok {
		close(tun.done)
	}
}

func handleTunnelData(msg WSMessage) {
	tunnelsMu.RLock()
	tun, ok := tunnels[msg.TunnelID]
	tunnelsMu.RUnlock()
	if ok {
		data, err := base64.StdEncoding.DecodeString(msg.Payload)
		if err == nil {
			tun.readCh <- data
		}
	}
}

func handleTunnelClose(msg WSMessage) {
	tunnelsMu.RLock()
	tun, ok := tunnels[msg.TunnelID]
	tunnelsMu.RUnlock()
	if ok {
		tun.Close()
	}
}

func handleTunnelError(msg WSMessage) {
	tunnelsMu.RLock()
	tun, ok := tunnels[msg.TunnelID]
	tunnelsMu.RUnlock()
	if ok {
		errMsg := msg.Error
		if errMsg == "" {
			errMsg = "tunnel error"
		}
		tun.errCh <- fmt.Errorf(errMsg)
	}
}

func dialWithProxy(ctx context.Context, network, addr string) (net.Conn, error) {
	d := &net.Dialer{Timeout: 10 * time.Second}
	return d.DialContext(ctx, network, addr)
}
