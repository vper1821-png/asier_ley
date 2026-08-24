module securelab-agent

go 1.25.0

require (
	github.com/fsnotify/fsnotify v1.10.1
	github.com/gorilla/websocket v1.5.3
	github.com/xuri/excelize/v2 v2.11.0
	golang.org/x/sys v0.47.0
	modernc.org/sqlite v1.14.0
	securelab-agent/internal/monitors v0.0.0-00010101000000-000000000000
)

require (
	filippo.io/edwards25519 v1.1.0 // indirect
	github.com/denisenkom/go-mssqldb v0.12.3 // indirect
	github.com/go-sql-driver/mysql v1.8.1 // indirect
	github.com/golang-sql/civil v0.0.0-20190719163853-cb61b32ac6fe // indirect
	github.com/golang-sql/sqlexp v0.1.0 // indirect
	github.com/golang/snappy v0.0.4 // indirect
	github.com/google/uuid v1.3.0 // indirect
	github.com/kballard/go-shellquote v0.0.0-20180428030007-95032a82bc51 // indirect
	github.com/klauspost/compress v1.16.7 // indirect
	github.com/lib/pq v1.12.3 // indirect
	github.com/mattn/go-isatty v0.0.12 // indirect
	github.com/montanaflynn/stats v0.7.1 // indirect
	github.com/remyoudompheng/bigfft v0.0.0-20200410134404-eec4a21b6bb0 // indirect
	github.com/richardlehane/mscfb v1.0.7 // indirect
	github.com/richardlehane/msoleps v1.0.6 // indirect
	github.com/tiendc/go-deepcopy v1.7.2 // indirect
	github.com/xdg-go/pbkdf2 v1.0.0 // indirect
	github.com/xdg-go/scram v1.1.2 // indirect
	github.com/xdg-go/stringprep v1.0.4 // indirect
	github.com/xuri/efp v0.0.1 // indirect
	github.com/xuri/nfp v0.0.2-0.20250530014748-2ddeb826f9a9 // indirect
	github.com/youmark/pkcs8 v0.0.0-20240726163527-a2c0da244d78 // indirect
	go.mongodb.org/mongo-driver v1.17.9 // indirect
	golang.org/x/crypto v0.53.0 // indirect
	golang.org/x/mod v0.36.0 // indirect
	golang.org/x/net v0.56.0 // indirect
	golang.org/x/sync v0.21.0 // indirect
	golang.org/x/text v0.38.0 // indirect
	golang.org/x/tools v0.45.0 // indirect
	lukechampine.com/uint128 v1.1.1 // indirect
	modernc.org/cc/v3 v3.35.17 // indirect
	modernc.org/ccgo/v3 v3.12.65 // indirect
	modernc.org/libc v1.11.70 // indirect
	modernc.org/mathutil v1.4.1 // indirect
	modernc.org/memory v1.0.5 // indirect
	modernc.org/opt v0.1.1 // indirect
	modernc.org/strutil v1.1.1 // indirect
	modernc.org/token v1.0.0 // indirect
)

replace (
	securelab-agent/internal/assistant => ./internal/assistant
	securelab-agent/internal/audit => ./internal/audit
	securelab-agent/internal/config => ./internal/config
	securelab-agent/internal/filemonitor => ./internal/filemonitor
	securelab-agent/internal/hardening => ./internal/hardening
	securelab-agent/internal/logger => ./internal/logger
	securelab-agent/internal/monitors => ./internal/monitors
	securelab-agent/internal/queue => ./internal/queue
	securelab-agent/internal/scanner => ./internal/scanner
	securelab-agent/internal/security => ./internal/security
	securelab-agent/internal/sysinfo => ./internal/sysinfo
	securelab-agent/internal/telemetry => ./internal/telemetry
	securelab-agent/internal/utils => ./internal/utils
	securelab-agent/internal/ws => ./internal/ws
)
