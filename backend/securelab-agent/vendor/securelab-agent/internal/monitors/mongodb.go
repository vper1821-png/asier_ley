package monitors

import (
	"context"
	"fmt"
	"sync"

	"securelab-agent/internal/audit"
	"securelab-agent/internal/logger"
	"securelab-agent/internal/scanner"
	"securelab-agent/internal/ws"

	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

type MongoDBMonitor struct {
	mu         sync.RWMutex
	client     *mongo.Client
	conn       DBConnection
	connected  bool
	store      *audit.Store
	wsClient   *ws.Client
	piiScanner *scanner.PIIScanner
	log        *logger.Logger
	wg         sync.WaitGroup
	ctx        context.Context
	cancel     context.CancelFunc
}

func NewMongoDBMonitor(store *audit.Store, wsClient *ws.Client, piiScanner *scanner.PIIScanner, log *logger.Logger) *MongoDBMonitor {
	return &MongoDBMonitor{
		store:      store,
		wsClient:   wsClient,
		piiScanner: piiScanner,
		log:        log,
	}
}

func (m *MongoDBMonitor) Name() string { return "mongodb" }

func (m *MongoDBMonitor) SetConnection(conn DBConnection) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.conn = conn
	m.connected = false
	if m.client != nil {
		m.client.Disconnect(context.Background())
		m.client = nil
	}
}

func (m *MongoDBMonitor) Start(ctx context.Context) error {
	m.mu.Lock()
	if m.connected {
		m.mu.Unlock()
		return nil
	}
	m.mu.Unlock()

	uri := fmt.Sprintf("mongodb://%s:%d", m.conn.Host, m.conn.Port)
	if m.conn.Username != "" && m.conn.Password != "" {
		uri = fmt.Sprintf("mongodb://%s:%s@%s:%d", m.conn.Username, m.conn.Password, m.conn.Host, m.conn.Port)
	}
	client, err := mongo.Connect(ctx, options.Client().ApplyURI(uri))
	if err != nil {
		return err
	}
	if err := client.Ping(ctx, nil); err != nil {
		client.Disconnect(ctx)
		return err
	}
	m.mu.Lock()
	m.client = client
	m.connected = true
	m.ctx, m.cancel = context.WithCancel(ctx)
	m.mu.Unlock()
	m.log.Info("MongoDB monitor conectado a %s:%d", m.conn.Host, m.conn.Port)

	m.wg.Add(1)
	go m.loop()
	return nil
}

func (m *MongoDBMonitor) loop() {
	defer m.wg.Done()
	// Implementar monitoreo de oplog o currentOp()
}

func (m *MongoDBMonitor) Stop() error {
	if m.cancel != nil {
		m.cancel()
	}
	m.wg.Wait()
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.client != nil {
		m.client.Disconnect(context.Background())
		m.client = nil
	}
	m.connected = false
	return nil
}

// Métodos requeridos por la interfaz
func (m *MongoDBMonitor) GetActiveQueries() []QueryInfo                { return []QueryInfo{} }
func (m *MongoDBMonitor) GetSlowQueries() []SlowQuery                  { return []SlowQuery{} }
func (m *MongoDBMonitor) GetLogEntries(limit int) []audit.DBQueryEntry { return []audit.DBQueryEntry{} }
func (m *MongoDBMonitor) GetSummary() *ActivitySummary                 { return &ActivitySummary{} }
