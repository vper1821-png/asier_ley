package models

type TelemetryData struct {
	Timestamp   int64  `json:"timestamp"`
	CPU         int    `json:"cpu"`
	Memory      int    `json:"memory"`
	DiskFree    int64  `json:"diskFree"`
	DiskTotal   int64  `json:"diskTotal"`
	Processes   int    `json:"processes"`
	Connections int    `json:"connections"`
	Hostname    string `json:"hostname"`
	Platform    string `json:"platform"`
	Arch        string `json:"arch"`
	OS          string `json:"os"`
	User        string `json:"user"`
	Uptime      int64  `json:"uptime"`
}
