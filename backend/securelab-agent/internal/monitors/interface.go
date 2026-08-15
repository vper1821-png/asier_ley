package monitors

import (
	"context"
	"time"

	"securelab-agent/internal/audit"
)

type DBConnection struct {
	Engine   string `json:"engine"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Database string `json:"database"`
	Username string `json:"username"`
	Password string `json:"password"`
	SSL      bool   `json:"ssl"`
}

type DBMonitor interface {
	Start(ctx context.Context) error
	Stop() error
	Name() string
	SetConnection(conn DBConnection)
	GetSummary() *ActivitySummary
	GetActiveQueries() []QueryInfo
	GetSlowQueries() []SlowQuery
	GetLogEntries(limit int) []audit.DBQueryEntry
}

type QueryInfo struct {
	ID       string    `json:"id"`
	User     string    `json:"user"`
	Host     string    `json:"host"`
	Database string    `json:"database"`
	Query    string    `json:"query"`
	State    string    `json:"state"`
	Time     time.Time `json:"time"`
	Duration float64   `json:"duration_sec"`
}

type SlowQuery struct {
	Query     string    `json:"query"`
	Duration  float64   `json:"duration_sec"`
	User      string    `json:"user"`
	Host      string    `json:"host"`
	Database  string    `json:"database"`
	Timestamp time.Time `json:"timestamp"`
}

type ActivitySummary struct {
	TotalQueries   int64       `json:"total_queries"`
	ActiveUsers    int         `json:"active_users"`
	TablesTracked  int         `json:"tables_tracked"`
	AnomaliesFound int         `json:"anomalies_found"`
	TopUsers       []UserStat  `json:"top_users"`
	TopTables      []TableStat `json:"top_tables"`
}

type UserStat struct {
	User      string `json:"user"`
	Queries   int64  `json:"queries"`
	Anomalies int    `json:"anomalies"`
}

type TableStat struct {
	Name      string `json:"name"`
	Accesses  int64  `json:"accesses"`
	Sensitive bool   `json:"sensitive"`
}
