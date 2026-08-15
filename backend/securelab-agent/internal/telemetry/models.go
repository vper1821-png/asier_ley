package telemetry

type TelemetryData struct {
	Timestamp   int64 `json:"timestamp"`
	CPU         int   `json:"cpu"`
	Memory      int   `json:"memory"`
	DiskFree    int64 `json:"disk_free"`
	DiskTotal   int64 `json:"disk_total"`
	Processes   int   `json:"processes"`
	Connections int   `json:"connections"`
}
