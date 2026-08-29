package dblogger

import (
	"context"
	"fmt"
	"log"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/bson/primitive"
	"go.mongodb.org/mongo-driver/event"
	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

// DBLogger captura todas las consultas de MongoDB incluyendo las del usuario root
type DBLogger struct {
	client     *mongo.Client
	enabled    bool
	bufferSize int
	logs       []DBLog
	mu         sync.Mutex
	apiEndpoint string
	apiToken    string
}

// DBLog representa un log de consulta de base de datos
type DBLog struct {
	Timestamp   time.Time              `json:"timestamp"`
	Operation   string                 `json:"operation"`   // find, insert, update, delete, etc.
	Collection  string                 `json:"collection"`
	Database    string                 `json:"database"`
	Query       map[string]interface{} `json:"query,omitempty"`
	Update      map[string]interface{} `json:"update,omitempty"`
	Document    map[string]interface{} `json:"document,omitempty"`
	Duration    int64                  `json:"duration_ms"` // Duración en milisegundos
	Success     bool                   `json:"success"`
	Error       string                 `json:"error,omitempty"`
	User        string                 `json:"user,omitempty"` // Usuario que ejecutó la consulta
	ConnectionID string                `json:"connection_id,omitempty"`
}

// Config para configurar el DBLogger
type Config struct {
	Enabled     bool
	BufferSize  int
	APIEndpoint string
	APIToken    string
}

// NewDBLogger crea una nueva instancia de DBLogger
func NewDBLogger(config Config) *DBLogger {
	if config.BufferSize <= 0 {
		config.BufferSize = 1000
	}

	return &DBLogger{
		enabled:     config.Enabled,
		bufferSize:  config.BufferSize,
		logs:        make([]DBLog, 0, config.BufferSize),
		apiEndpoint: config.APIEndpoint,
		apiToken:    config.APIToken,
	}
}

// MonitorClient configura el monitor de eventos en el cliente MongoDB
func (db *DBLogger) MonitorClient(client *mongo.Client) error {
	if !db.enabled {
		return nil
	}

	db.client = client

	// Configurar command started listener
	cmdStarted := make(chan *event.CommandStartedEvent, 1000)
	cmdSucceeded := make(chan *event.CommandSucceededEvent, 1000)
	cmdFailed := make(chan *event.CommandFailedEvent, 1000)

	// Configurar el monitor de comandos
	opts := options.Client().
		SetMonitor(&event.CommandMonitor{
			Started:   func(ctx context.Context, evt *event.CommandStartedEvent) { cmdStarted <- evt },
			Succeeded: func(ctx context.Context, evt *event.CommandSucceededEvent) { cmdSucceeded <- evt },
			Failed:    func(ctx context.Context, evt *event.CommandFailedEvent) { cmdFailed <- evt },
		})

	// Iniciar goroutines para procesar eventos
	go db.processCommandStarted(cmdStarted)
	go db.processCommandSucceeded(cmdSucceeded)
	go db.processCommandFailed(cmdFailed)

	log.Println("[DBLogger] MongoDB monitoring enabled")
	return nil
}

// processCommandStarted procesa eventos de comando iniciado
func (db *DBLogger) processCommandStarted(ch <-chan *event.CommandStartedEvent) {
	for evt := range ch {
		logEntry := DBLog{
			Timestamp:   time.Now(),
			Operation:   evt.CommandName,
			Database:    evt.DatabaseName,
			ConnectionID: evt.ConnectionID,
		}

		// Extraer colección y query del comando
		if collection, ok := evt.Command["insert"].(string); ok {
			logEntry.Collection = collection
		}
		if collection, ok := evt.Command["find"].(string); ok {
			logEntry.Collection = collection
		}
		if collection, ok := evt.Command["update"].(string); ok {
			logEntry.Collection = collection
		}
		if collection, ok := evt.Command["delete"].(string); ok {
			logEntry.Collection = collection
		}

		// Extraer query si existe
		if query, ok := evt.Command["filter"].(bson.M); ok {
			logEntry.Query = bsonToMap(query)
		}

		// Extraer update si existe
		if update, ok := evt.Command["updates"].(bson.A); ok && len(update) > 0 {
			if u, ok := update[0].(bson.M); ok {
				if upd, ok := u["u"].(bson.M); ok {
					logEntry.Update = bsonToMap(upd)
				}
			}
		}

		db.addLog(logEntry)
	}
}

// processCommandSucceeded procesa eventos de comando exitoso
func (db *DBLogger) processCommandSucceeded(ch <-chan *event.CommandSucceededEvent) {
	for evt := range ch {
		db.mu.Lock()
		// Buscar el log correspondiente y actualizar duración
		for i := len(db.logs) - 1; i >= 0; i-- {
			if db.logs[i].ConnectionID == evt.ConnectionID && db.logs[i].Duration == 0 {
				db.logs[i].Duration = evt.Duration.Nanoseconds() / 1e6 // Convertir a milisegundos
				db.logs[i].Success = true
				break
			}
		}
		db.mu.Unlock()
	}
}

// processCommandFailed procesa eventos de comando fallido
func (db *DBLogger) processCommandFailed(ch <-chan *event.CommandFailedEvent) {
	for evt := range ch {
		db.mu.Lock()
		// Buscar el log correspondiente y marcar como fallido
		for i := len(db.logs) - 1; i >= 0; i-- {
			if db.logs[i].ConnectionID == evt.ConnectionID && db.logs[i].Duration == 0 {
				db.logs[i].Duration = evt.Duration.Nanoseconds() / 1e6
				db.logs[i].Success = false
				db.logs[i].Error = evt.Failure.Error()
				break
			}
		}
		db.mu.Unlock()
	}
}

// addLog agrega un log al buffer
func (db *DBLogger) addLog(log DBLog) {
	db.mu.Lock()
	defer db.mu.Unlock()

	db.logs = append(db.logs, log)

	// Si el buffer está lleno, enviar al servidor
	if len(db.logs) >= db.bufferSize {
		go db.sendLogs()
	}
}

// sendLogs envía los logs al servidor
func (db *DBLogger) sendLogs() error {
	db.mu.Lock()
	if len(db.logs) == 0 {
		db.mu.Unlock()
		return nil
	}

	logsCopy := make([]DBLog, len(db.logs))
	copy(logsCopy, db.logs)
	db.logs = make([]DBLog, 0, db.bufferSize)
	db.mu.Unlock()

	if db.apiEndpoint == "" {
		log.Printf("[DBLogger] No API endpoint configured, skipping log upload")
		return nil
	}

	// Aquí se implementaría el envío al servidor
	// Por ahora solo logueamos
	log.Printf("[DBLogger] Would send %d logs to %s", len(logsCopy), db.apiEndpoint)

	return nil
}

// Flush envía todos los logs pendientes
func (db *DBLogger) Flush() error {
	return db.sendLogs()
}

// GetLogs retorna una copia de los logs actuales
func (db *DBLogger) GetLogs() []DBLog {
	db.mu.Lock()
	defer db.mu.Unlock()

	logsCopy := make([]DBLog, len(db.logs))
	copy(logsCopy, db.logs)
	return logsCopy
}

// bsonToMap convierte bson.M a map[string]interface{}
func bsonToMap(m bson.M) map[string]interface{} {
	result := make(map[string]interface{})
	for k, v := range m {
		result[k] = convertBSONValue(v)
	}
	return result
}

// convertBSONValue convierte valores BSON a tipos JSON serializables
func convertBSONValue(v interface{}) interface{} {
	switch val := v.(type) {
	case bson.M:
		return bsonToMap(val)
	case bson.A:
		result := make([]interface{}, len(val))
		for i, item := range val {
			result[i] = convertBSONValue(item)
		}
		return result
	case primitive.DateTime:
		return val.Time()
	case primitive.Timestamp:
		return fmt.Sprintf("Timestamp(%d,%d)", val.T, val.I)
	default:
		return v
	}
}

// Enable habilita el logging
func (db *DBLogger) Enable() {
	db.enabled = true
	log.Println("[DBLogger] Enabled")
}

// Disable deshabilita el logging
func (db *DBLogger) Disable() {
	db.enabled = false
	log.Println("[DBLogger] Disabled")
}

// IsEnabled retorna si el logging está habilitado
func (db *DBLogger) IsEnabled() bool {
	return db.enabled
}