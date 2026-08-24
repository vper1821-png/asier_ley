package monitors

import (
	"context"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/ws"
)

type RedisMonitor struct {
	mu        sync.RWMutex
	conn      DBConnection
	connected bool
	store     *audit.Store
	wsClient  *ws.Client
	log       *logger.Logger
	wg        sync.WaitGroup
	ctx       context.Context
	cancel    context.CancelFunc
}

func NewRedisMonitor(store *audit.Store, wsClient *ws.Client, log *logger.Logger) *RedisMonitor {
	return &RedisMonitor{
		store:    store,
		wsClient: wsClient,
		log:      log,
	}
}

func (m *RedisMonitor) Name() string { return "redis" }

func (m *RedisMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
}

func (m *RedisMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()
	m.log.Info("Redis monitor iniciado (conexión pendiente)")
	m.mu.Lock()
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	return nil
}

func (m *RedisMonitor) Stop() error {
	if m.cancel != nil {
		m.cancel()
	}
	m.wg.Wait()
	m.mu.Lock()
	defer m.mu.Unlock()
	m.connected = false
	return nil
}

// Métodos requeridos por la interfaz
func (m *RedisMonitor) GetActiveQueries() []QueryInfo                { return []QueryInfo{} }
func (m *RedisMonitor) GetSlowQueries() []SlowQuery                  { return []SlowQuery{} }
func (m *RedisMonitor) GetLogEntries(limit int) []audit.DBQueryEntry { return []audit.DBQueryEntry{} }
func (m *RedisMonitor) GetSummary() *ActivitySummary                 { return &ActivitySummary{} }
