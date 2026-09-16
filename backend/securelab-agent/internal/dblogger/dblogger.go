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
)

// DBLogger captura todas las consultas de MongoDB incluyendo las del usuario root
type DBLogger struct {
	client      *mongo.Client
	enabled     bool
	bufferSize  int
	logs        []DBLog
	mu          sync.Mutex
	apiEndpoint string
	apiToken    string
}

// DBLog representa un log de consulta de base de datos
type DBLog struct {
	Timestamp    time.Time              `json:"timestamp"`
	Operation    string                 `json:"operation"`
	Collection   string                 `json:"collection"`
	Database     string                 `json:"database"`
	Query        map[string]interface{} `json:"query,omitempty"`
	Update       map[string]interface{} `json:"update,omitempty"`
	Document     map[string]interface{} `json:"document,omitempty"`
	Duration     int64                  `json:"duration_ms"`
	Success      bool                   `json:"success"`
	Error        string                 `json:"error,omitempty"`
	User         string                 `json:"user,omitempty"`
	ConnectionID string                 `json:"connection_id,omitempty"`
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

// NewCommandMonitor devuelve el *event.CommandMonitor que debe pasarse a
// options.Client().SetMonitor(...) al construir el cliente Mongo.
// Esta es la forma correcta: NO se crea un cliente nuevo desde el DBLogger,
// se engancha al cliente que ya tiene el agente.
func (db *DBLogger) NewCommandMonitor() *event.CommandMonitor {
	if !db.enabled {
		return nil
	}

	cmdStarted := make(chan *event.CommandStartedEvent, 1000)
	cmdSucceeded := make(chan *event.CommandSucceededEvent, 1000)
	cmdFailed := make(chan *event.CommandFailedEvent, 1000)

	go db.processCommandStarted(cmdStarted)
	go db.processCommandSucceeded(cmdSucceeded)
	go db.processCommandFailed(cmdFailed)

	log.Println("[DBLogger] MongoDB command monitor ready")
	return &event.CommandMonitor{
		Started:   func(_ context.Context, evt *event.CommandStartedEvent) { cmdStarted <- evt },
		Succeeded: func(_ context.Context, evt *event.CommandSucceededEvent) { cmdSucceeded <- evt },
		Failed:    func(_ context.Context, evt *event.CommandFailedEvent) { cmdFailed <- evt },
	}
}

// MonitorClient deja el cliente registrado y (si no se usó NewCommandMonitor)
// avisa por log que hay que enganchar el monitor manualmente con
// options.Client().SetMonitor(db.NewCommandMonitor()).
func (db *DBLogger) MonitorClient(client *mongo.Client) error {
	if !db.enabled {
		return nil
	}
	db.client = client
	log.Println("[DBLogger] client registrado. Asegúrate de pasar NewCommandMonitor() a options.Client().SetMonitor(...)")
	return nil
}

// processCommandStarted procesa eventos de comando iniciado
func (db *DBLogger) processCommandStarted(ch <-chan *event.CommandStartedEvent) {
	for evt := range ch {
		logEntry := DBLog{
			Timestamp:    time.Now(),
			Operation:    evt.CommandName,
			Database:     evt.DatabaseName,
			ConnectionID: evt.ConnectionID,
		}

		// evt.Command es bson.Raw en versiones modernas → hay que deserializar.
		// También soportamos el caso antiguo donde ya es bson.M.
		var cmd bson.M
		switch raw := interface{}(evt.Command).(type) {
		case bson.M:
			cmd = raw
		case bson.Raw:
			if err := bson.Unmarshal(raw, &cmd); err != nil {
				// no se pudo deserializar, aún así registramos la operación básica
				db.addLog(logEntry)
				continue
			}
		case []byte:
			if err := bson.Unmarshal(raw, &cmd); err != nil {
				db.addLog(logEntry)
				continue
			}
		default:
			db.addLog(logEntry)
			continue
		}

		// Extraer colección
		for _, key := range []string{"insert", "find", "update", "delete", "aggregate", "count", "distinct", "findAndModify"} {
			if coll, ok := cmd[key].(string); ok && coll != "" {
				logEntry.Collection = coll
				break
			}
		}

		// Extraer filtro de find / delete / update
		if filter, ok := cmd["filter"].(bson.M); ok {
			logEntry.Query = bsonToMap(filter)
		} else if filter, ok := cmd["filter"].(bson.D); ok {
			logEntry.Query = bsonDToMap(filter)
		}

		// Extraer updates[0].u (update document)
		if updates, ok := cmd["updates"].(bson.A); ok && len(updates) > 0 {
			if u, ok := updates[0].(bson.M); ok {
				if doc, ok := u["u"].(bson.M); ok {
					logEntry.Update = bsonToMap(doc)
				} else if doc, ok := u["u"].(bson.D); ok {
					logEntry.Update = bsonDToMap(doc)
				}
			}
		}

		// Extraer documents (insert)
		if documents, ok := cmd["documents"].(bson.A); ok && len(documents) > 0 {
			switch d := documents[0].(type) {
			case bson.M:
				logEntry.Document = bsonToMap(d)
			case bson.D:
				logEntry.Document = bsonDToMap(d)
			}
		}

		db.addLog(logEntry)
	}
}

// processCommandSucceeded actualiza duración y marca éxito
func (db *DBLogger) processCommandSucceeded(ch <-chan *event.CommandSucceededEvent) {
	for evt := range ch {
		db.mu.Lock()
		for i := len(db.logs) - 1; i >= 0; i-- {
			if db.logs[i].ConnectionID == evt.ConnectionID && db.logs[i].Duration == 0 {
				db.logs[i].Duration = evt.Duration.Nanoseconds() / 1e6
				db.logs[i].Success = true
				break
			}
		}
		db.mu.Unlock()
	}
}

// processCommandFailed marca fallo. En versiones recientes Failure es string,
// en otras es error → usamos un type switch defensivo.
func (db *DBLogger) processCommandFailed(ch <-chan *event.CommandFailedEvent) {
	for evt := range ch {
		var failureMsg string
		switch f := interface{}(evt.Failure).(type) {
		case string:
			failureMsg = f
		case error:
			failureMsg = f.Error()
		default:
			failureMsg = fmt.Sprintf("%v", f)
		}

		db.mu.Lock()
		for i := len(db.logs) - 1; i >= 0; i-- {
			if db.logs[i].ConnectionID == evt.ConnectionID && db.logs[i].Duration == 0 {
				db.logs[i].Duration = evt.Duration.Nanoseconds() / 1e6
				db.logs[i].Success = false
				db.logs[i].Error = failureMsg
				break
			}
		}
		db.mu.Unlock()
	}
}

// addLog agrega un log al buffer
func (db *DBLogger) addLog(entry DBLog) {
	db.mu.Lock()
	defer db.mu.Unlock()
	db.logs = append(db.logs, entry)
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
		log.Printf("[DBLogger] No API endpoint configured, skipping log upload (%d entries)", len(logsCopy))
		return nil
	}
	log.Printf("[DBLogger] Would send %d logs to %s", len(logsCopy), db.apiEndpoint)
	return nil
}

func (db *DBLogger) Flush() error { return db.sendLogs() }

func (db *DBLogger) GetLogs() []DBLog {
	db.mu.Lock()
	defer db.mu.Unlock()
	out := make([]DBLog, len(db.logs))
	copy(out, db.logs)
	return out
}

// ---- Helpers de conversión BSON ----

func bsonToMap(m bson.M) map[string]interface{} {
	result := make(map[string]interface{}, len(m))
	for k, v := range m {
		result[k] = convertBSONValue(v)
	}
	return result
}

func bsonDToMap(d bson.D) map[string]interface{} {
	result := make(map[string]interface{}, len(d))
	for _, e := range d {
		result[e.Key] = convertBSONValue(e.Value)
	}
	return result
}

func convertBSONValue(v interface{}) interface{} {
	switch val := v.(type) {
	case bson.M:
		return bsonToMap(val)
	case bson.D:
		return bsonDToMap(val)
	case bson.A:
		out := make([]interface{}, len(val))
		for i, item := range val {
			out[i] = convertBSONValue(item)
		}
		return out
	case primitive.DateTime:
		return val.Time()
	case primitive.Timestamp:
		return fmt.Sprintf("Timestamp(%d,%d)", val.T, val.I)
	default:
		return v
	}
}

func (db *DBLogger) Enable()         { db.enabled = true; log.Println("[DBLogger] Enabled") }
func (db *DBLogger) Disable()        { db.enabled = false; log.Println("[DBLogger] Disabled") }
func (db *DBLogger) IsEnabled() bool { return db.enabled }
