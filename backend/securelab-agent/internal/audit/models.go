package audit

import "time"

type FileEvent struct {
	Timestamp    time.Time           `json:"timestamp"`
	Path         string              `json:"path"`
	EventType    string              `json:"event_type"`
	ProcessName  string              `json:"process_name"`
	PID          int                 `json:"pid"`
	User         string              `json:"user"`
	Size         int64               `json:"size,omitempty"`
	Hash         string              `json:"hash,omitempty"`
	Destination  string              `json:"destination,omitempty"`
	PersonalData map[string][]string `json:"personal_data,omitempty"`
	Sensitive    bool                `json:"sensitive,omitempty"`
}

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

type HostEvent struct {
	Timestamp time.Time `json:"timestamp"`
	Type      string    `json:"type"`
	Severity  string    `json:"severity"`
	Title     string    `json:"title"`
	Detail    string    `json:"detail"`
	Source    string    `json:"source"`
}

type WindowsEvent struct {
	Timestamp time.Time `json:"timestamp"`
	Channel   string    `json:"channel"`
	Provider  string    `json:"provider"`
	EventID   int       `json:"event_id"`
	Level     string    `json:"level"`
	Message   string    `json:"message"`
}
