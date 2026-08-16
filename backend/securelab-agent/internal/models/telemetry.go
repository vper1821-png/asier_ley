package models

type TelemetryData struct {
	Timestamp   int64 `json:"timestamp"`
	CPU         int   `json:"cpu"`
	Memory      int   `json:"memory"`
	DiskFree    int64 `json:"diskFree"`
	DiskTotal   int64 `json:"diskTotal"`
	Processes   int   `json:"processes"`
	Connections int   `json:"connections"`
}
