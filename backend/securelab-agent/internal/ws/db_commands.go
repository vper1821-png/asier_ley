package ws

import (
	"database/sql"
	"fmt"
	"time"

	"securelab-agent/internal/logger"

	_ "github.com/denisenkom/go-mssqldb"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
	_ "modernc.org/sqlite"
)

// DBTestResult represents the result of testing a database connection
type DBTestResult struct {
	Success   bool   `json:"success"`
	Latency   int    `json:"latency"`
	Error     string `json:"error,omitempty"`
	Status    string `json:"status"`
}

// DBScanResult represents the result of scanning a database
type DBScanResult struct {
	Success   bool            `json:"success"`
	Tables    int             `json:"tables"`
	Records   int             `json:"records"`
	TableList []DBTableInfo   `json:"tableList,omitempty"`
	Error     string          `json:"error,omitempty"`
}

// DBTableInfo represents a table/collection with row count
type DBTableInfo struct {
	Name  string `json:"name"`
	Rows  int    `json:"rows"`
}

// executeDBCommand handles database test, scan and query commands
func (c *Client) executeDBCommand(command string, params map[string]interface{}, log *logger.Logger) (interface{}, error) {
	dbType, _ := params["type"].(string)
	host, _ := params["host"].(string)
	portF, _ := params["port"].(float64)
	port := int(portF)
	if port == 0 {
		port = getDefaultPort(dbType)
	}
	database, _ := params["database"].(string)
	user, _ := params["user"].(string)
	password, _ := params["password"].(string)
	ssl, _ := params["ssl"].(bool)

	if dbType == "" || host == "" || database == "" || user == "" {
		return nil, fmt.Errorf("datos de conexion incompletos")
	}

	switch command {
	case "test_db":
		return c.testDBConnection(dbType, host, port, database, user, password, ssl, log)
	case "scan_db":
		return c.scanDB(dbType, host, port, database, user, password, ssl, log)
	default:
		return nil, fmt.Errorf("comando de base de datos no soportado: %s", command)
	}
}

func getDefaultPort(dbType string) int {
	switch dbType {
	case "mysql", "mariadb":
		return 3306
	case "postgres", "postgresql":
		return 5432
	case "mssql":
		return 1433
	case "mongodb":
		return 27017
	case "redis":
		return 6379
	case "sqlite":
		return 0
	default:
		return 0
	}
}

func (c *Client) testDBConnection(dbType, host string, port int, database, user, password string, ssl bool, log *logger.Logger) (DBTestResult, error) {
	start := time.Now()
	db, err := c.openDB(dbType, host, port, database, user, password, ssl)
	if err != nil {
		return DBTestResult{Success: false, Error: err.Error(), Status: "error"}, nil
	}
	defer db.Close()

	if err := db.Ping(); err != nil {
		return DBTestResult{Success: false, Error: err.Error(), Status: "error"}, nil
	}

	latency := int(time.Since(start).Milliseconds())
	return DBTestResult{
		Success: true,
		Latency: latency,
		Status:  "connected",
	}, nil
}

func (c *Client) openDB(dbType, host string, port int, database, user, password string, ssl bool) (*sql.DB, error) {
	var dsn string
	switch dbType {
	case "mysql", "mariadb":
		tlsOpt := "false"
		if ssl {
			tlsOpt = "true"
		}
		dsn = fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?timeout=5s&tls=%s&multiStatements=true",
			user, password, host, port, database, tlsOpt)
	case "postgres", "postgresql":
		sslMode := "disable"
		if ssl {
			sslMode = "require"
		}
		dsn = fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=%s connect_timeout=5",
			host, port, user, password, database, sslMode)
	case "mssql":
		// encrypt=disable y trustservercertificate=true para compatibilidad con SQL Server antiguos
		dsn = fmt.Sprintf("sqlserver://%s:%s@%s:%d?database=%s&connection+timeout=5&encrypt=disable&trustservercertificate=true",
			user, password, host, port, database)
	case "sqlite":
		return sql.Open("sqlite", database)
	default:
		return nil, fmt.Errorf("tipo de base de datos no soportado: %s", dbType)
	}

	db, err := sql.Open(dbType, dsn)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(2)
	db.SetConnMaxLifetime(10 * time.Second)
	return db, nil
}

func (c *Client) scanDB(dbType, host string, port int, database, user, password string, ssl bool, log *logger.Logger) (DBScanResult, error) {
	db, err := c.openDB(dbType, host, port, database, user, password, ssl)
	if err != nil {
		return DBScanResult{Success: false, Error: err.Error()}, nil
	}
	defer db.Close()

	var result DBScanResult
	result.Success = true

	switch dbType {
	case "mysql", "mariadb":
		rows, err := db.Query("SHOW TABLES")
		if err != nil {
			return DBScanResult{Success: false, Error: err.Error()}, nil
		}
		defer rows.Close()

		for rows.Next() {
			var tableName string
			if err := rows.Scan(&tableName); err != nil {
				continue
			}
			var cnt int
			if err := db.QueryRow("SELECT COUNT(*) FROM `" + tableName + "`").Scan(&cnt); err != nil {
				cnt = 0
			}
			result.TableList = append(result.TableList, DBTableInfo{Name: tableName, Rows: cnt})
			result.Records += cnt
		}

	case "postgres", "postgresql":
		rows, err := db.Query("SELECT schemaname, relname, n_live_tup FROM pg_stat_user_tables")
		if err != nil {
			return DBScanResult{Success: false, Error: err.Error()}, nil
		}
		defer rows.Close()

		for rows.Next() {
			var schema, tableName string
			var cnt int
			if err := rows.Scan(&schema, &tableName, &cnt); err != nil {
				continue
			}
			name := schema + "." + tableName
			result.TableList = append(result.TableList, DBTableInfo{Name: name, Rows: cnt})
			result.Records += cnt
		}

	case "mssql":
		rows, err := db.Query(`
			SELECT TABLE_SCHEMA, TABLE_NAME,
				(SELECT SUM(p.rows) FROM sys.partitions p
				 INNER JOIN sys.tables t ON p.object_id = t.object_id
				 INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
				 WHERE t.name = INFORMATION_SCHEMA.TABLES.TABLE_NAME
				   AND s.name = TABLE_SCHEMA
				   AND p.index_id IN (0,1)) AS row_count
			FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_TYPE = 'BASE TABLE'
		`)
		if err != nil {
			return DBScanResult{Success: false, Error: err.Error()}, nil
		}
		defer rows.Close()

		for rows.Next() {
			var schema, tableName string
			var cnt interface{}
			if err := rows.Scan(&schema, &tableName, &cnt); err != nil {
				continue
			}
			rows := 0
			if v, ok := cnt.(int64); ok {
				rows = int(v)
			}
			name := schema + "." + tableName
			result.TableList = append(result.TableList, DBTableInfo{Name: name, Rows: rows})
			result.Records += rows
		}

	case "sqlite":
		rows, err := db.Query("SELECT name FROM sqlite_master WHERE type='table'")
		if err != nil {
			return DBScanResult{Success: false, Error: err.Error()}, nil
		}
		defer rows.Close()

		for rows.Next() {
			var tableName string
			if err := rows.Scan(&tableName); err != nil {
				continue
			}
			result.TableList = append(result.TableList, DBTableInfo{Name: tableName, Rows: 0})
		}

	default:
		return DBScanResult{Success: false, Error: "tipo de base de datos no soportado para scan: " + dbType}, nil
	}

	result.Tables = len(result.TableList)
	return result, nil
}
