package audit

import "time"

// FileEvent representa un evento de acceso o modificación a un archivo
type FileEvent struct {
	Timestamp    time.Time           `json:"timestamp"`
	Path         string              `json:"path"`
	EventType    string              `json:"event_type"` // create, modify, delete, move, copy, open, read, write
	ProcessName  string              `json:"process_name"`
	PID          int                 `json:"pid"`
	User         string              `json:"user"`
	Size         int64               `json:"size,omitempty"`
	Hash         string              `json:"hash,omitempty"`
	Destination  string              `json:"destination,omitempty"`   // para movimientos/copias
	PersonalData map[string][]string `json:"personal_data,omitempty"` // categorías de PII detectadas
	Sensitive    bool                `json:"sensitive,omitempty"`     // si contiene datos sensibles
}

// DBQueryEntry representa una consulta a base de datos
type DBQueryEntry struct {
	Timestamp time.Time `json:"timestamp"`
	Engine    string    `json:"engine"`
	Database  string    `json:"database"`
	User      string    `json:"user"`
	Host      string    `json:"host"`
	Query     string    `json:"query"`
	Operation string    `json:"operation"`
	RiskScore float64   `json:"risk_score"`
}

// HostEvent representa un evento del sistema o seguridad
type HostEvent struct {
	Timestamp time.Time `json:"timestamp"`
	Type      string    `json:"type"`     // hardening, web_access, process_connection, config_change, etc.
	Severity  string    `json:"severity"` // critical, high, medium, low, info
	Title     string    `json:"title"`
	Detail    string    `json:"detail"`
	Source    string    `json:"source"`
}

// WindowsEvent representa un evento del registro de eventos de Windows
type WindowsEvent struct {
	Timestamp time.Time `json:"timestamp"`
	Channel   string    `json:"channel"`
	Provider  string    `json:"provider"`
	EventID   int       `json:"event_id"`
	Level     string    `json:"level"`
	Message   string    `json:"message"`
}
