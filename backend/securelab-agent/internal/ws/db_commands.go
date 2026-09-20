package ws

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
	"time"

	"securelab-agent/internal/logger"

	_ "github.com/denisenkom/go-mssqldb"
	_ "github.com/go-sql-driver/mysql"
	_ "github.com/lib/pq"
	_ "modernc.org/sqlite"
)

// dbOpTimeout es el timeout para operaciones de BD (test, scan).
// Evita que una DB lenta cuelgue el agente.
const dbOpTimeout = 30 * time.Second

// DBTestResult represents the result of testing a database connection
type DBTestResult struct {
	Success bool   `json:"success"`
	Latency int    `json:"latency"`
	Error   string `json:"error,omitempty"`
	Status  string `json:"status"`
}

// DBScanResult represents the result of scanning a database
type DBScanResult struct {
	Success   bool          `json:"success"`
	Tables    int           `json:"tables"`
	Records   int           `json:"records"`
	TableList []DBTableInfo `json:"tableList,omitempty"`
	Error     string        `json:"error,omitempty"`
}

// DBTableInfo represents a table/collection with row count
type DBTableInfo struct {
	Name string `json:"name"`
	Rows int    `json:"rows"`
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

	ctx, cancel := context.WithTimeout(context.Background(), dbOpTimeout)
	defer cancel()

	db, err := c.openDB(dbType, host, port, database, user, password, ssl)
	if err != nil {
		if log != nil {
			log.Warn("DB test (%s %s:%d): open falló: %v", dbType, host, port, err)
		}
		return DBTestResult{Success: false, Error: err.Error(), Status: "error"}, nil
	}
	defer db.Close()

	if err := db.PingContext(ctx); err != nil {
		if log != nil {
			log.Warn("DB test (%s %s:%d): ping falló: %v", dbType, host, port, err)
		}
		return DBTestResult{Success: false, Error: err.Error(), Status: "error"}, nil
	}

	latency := int(time.Since(start).Milliseconds())
	if log != nil {
		log.Info("DB test OK (%s %s:%d): %dms", dbType, host, port, latency)
	}
	return DBTestResult{
		Success: true,
		Latency: latency,
		Status:  "connected",
	}, nil
}

func (c *Client) openDB(dbType, host string, port int, database, user, password string, ssl bool) (*sql.DB, error) {
	var dsn, driverName string
	switch dbType {
	case "mysql", "mariadb":
		driverName = "mysql"
		tlsOpt := "false"
		if ssl {
			// Nota: "true" en go-sql-driver/mysql es equivalente a "skip-verify"
			// (fuerza TLS sin validar el certificado del servidor).
			// Si necesitas validación estricta, hay que registrar un tls.Config custom.
			tlsOpt = "skip-verify"
		}
		dsn = fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?timeout=5s&parseTime=true&tls=%s&multiStatements=true",
			user, password, host, port, database, tlsOpt)
	case "postgres", "postgresql":
		driverName = "postgres"
		sslMode := "disable"
		if ssl {
			sslMode = "require"
		}
		dsn = fmt.Sprintf("host=%s port=%d user=%s password=%s dbname=%s sslmode=%s connect_timeout=5",
			host, port, user, password, database, sslMode)
	case "mssql":
		driverName = "mssql"
		// encrypt=disable y trustservercertificate=true para compatibilidad con SQL Server antiguos
		dsn = fmt.Sprintf("sqlserver://%s:%s@%s:%d?database=%s&connection+timeout=5&encrypt=disable&trustservercertificate=true",
			user, password, host, port, database)
	case "sqlite":
		db, err := sql.Open("sqlite", database)
		if err != nil {
			return nil, err
		}
		db.SetMaxOpenConns(1)
		db.SetMaxIdleConns(0)
		db.SetConnMaxLifetime(10 * time.Second)
		db.SetConnMaxIdleTime(2 * time.Second)
		return db, nil
	default:
		return nil, fmt.Errorf("tipo de base de datos no soportado: %s", dbType)
	}

	db, err := sql.Open(driverName, dsn)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1)
	db.SetMaxIdleConns(0)
	db.SetConnMaxLifetime(10 * time.Second)
	db.SetConnMaxIdleTime(2 * time.Second)
	return db, nil
}

func (c *Client) scanDB(dbType, host string, port int, database, user, password string, ssl bool, log *logger.Logger) (DBScanResult, error) {
	if log != nil {
		log.Info("DB scan iniciado: %s %s:%d/%s", dbType, host, port, database)
	}

	ctx, cancel := context.WithTimeout(context.Background(), dbOpTimeout*2)
	defer cancel()

	db, err := c.openDB(dbType, host, port, database, user, password, ssl)
	if err != nil {
		if log != nil {
			log.Warn("DB scan (%s %s:%d): open falló: %v", dbType, host, port, err)
		}
		return DBScanResult{Success: false, Error: err.Error()}, nil
	}
	defer db.Close()

	if err := db.PingContext(ctx); err != nil {
		if log != nil {
			log.Warn("DB scan (%s %s:%d): ping falló: %v", dbType, host, port, err)
		}
		return DBScanResult{Success: false, Error: err.Error()}, nil
	}

	var result DBScanResult
	result.Success = true

	switch dbType {
	case "mysql", "mariadb":
		c.scanMySQL(ctx, db, &result, log)

	case "postgres", "postgresql":
		c.scanPostgres(ctx, db, &result, log)

	case "mssql":
		c.scanMSSQL(ctx, db, &result, log)

	case "sqlite":
		c.scanSQLite(ctx, db, &result, log)

	default:
		return DBScanResult{Success: false, Error: "tipo de base de datos no soportado para scan: " + dbType}, nil
	}

	result.Tables = len(result.TableList)

	if log != nil {
		log.Info("DB scan OK (%s %s:%d/%s): %d tablas, %d registros",
			dbType, host, port, database, len(result.TableList), result.Records)
	}
	return result, nil
}

// ─── Scanners por motor (extraídos para evitar defers anidados) ───

func (c *Client) scanMySQL(ctx context.Context, db *sql.DB, result *DBScanResult, log *logger.Logger) {
	// Intento 1: information_schema (rápido)
	infoRows, err := db.QueryContext(ctx,
		"SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
	if err == nil {
		func() {
			defer infoRows.Close()
			for infoRows.Next() {
				var tableName string
				var rowCnt int64
				if err := infoRows.Scan(&tableName, &rowCnt); err != nil {
					if log != nil {
						log.Debug("MySQL scan: error leyendo fila: %v", err)
					}
					continue
				}
				cnt := int(rowCnt)
				result.TableList = append(result.TableList, DBTableInfo{Name: tableName, Rows: cnt})
				result.Records += cnt
			}
			if err := infoRows.Err(); err != nil && log != nil {
				log.Warn("MySQL scan: error al iterar information_schema: %v", err)
			}
		}()
		return
	}

	// Fallback: SHOW TABLES + COUNT(*) tabla por tabla
	if log != nil {
		log.Debug("MySQL: information_schema falló (%v), usando SHOW TABLES", err)
	}
	rows, err := db.QueryContext(ctx, "SHOW TABLES")
	if err != nil {
		result.Success = false
		result.Error = err.Error()
		return
	}
	defer rows.Close()

	for rows.Next() {
		var tableName string
		if err := rows.Scan(&tableName); err != nil {
			continue
		}
		var cnt int
		// Escapar backticks por si el nombre de tabla contiene uno
		safeName := "`" + strings.ReplaceAll(tableName, "`", "``") + "`"
		if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM "+safeName).Scan(&cnt); err != nil {
			cnt = 0
		}
		result.TableList = append(result.TableList, DBTableInfo{Name: tableName, Rows: cnt})
		result.Records += cnt
	}
	if err := rows.Err(); err != nil && log != nil {
		log.Warn("MySQL scan: error al iterar SHOW TABLES: %v", err)
	}
}

func (c *Client) scanPostgres(ctx context.Context, db *sql.DB, result *DBScanResult, log *logger.Logger) {
	rows, err := db.QueryContext(ctx,
		"SELECT schemaname, relname, n_live_tup FROM pg_stat_user_tables")
	if err != nil {
		result.Success = false
		result.Error = err.Error()
		return
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
	if err := rows.Err(); err != nil && log != nil {
		log.Warn("PostgreSQL scan: error al iterar pg_stat_user_tables: %v", err)
	}
}

func (c *Client) scanMSSQL(ctx context.Context, db *sql.DB, result *DBScanResult, log *logger.Logger) {
	// JOIN directo (mucho más rápido que subquery correlacionada en bases con muchas tablas)
	rows, err := db.QueryContext(ctx, `
		SELECT s.name AS schema_name, t.name AS table_name,
		       SUM(p.rows) AS row_count
		FROM sys.tables t
		INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
		INNER JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0,1)
		GROUP BY s.name, t.name
		ORDER BY s.name, t.name
	`)
	if err != nil {
		result.Success = false
		result.Error = err.Error()
		return
	}
	defer rows.Close()

	for rows.Next() {
		var schemaName, tableName string
		var cnt sql.NullInt64
		if err := rows.Scan(&schemaName, &tableName, &cnt); err != nil {
			continue
		}
		rowCount := 0
		if cnt.Valid {
			rowCount = int(cnt.Int64)
		}
		name := schemaName + "." + tableName
		result.TableList = append(result.TableList, DBTableInfo{Name: name, Rows: rowCount})
		result.Records += rowCount
	}
	if err := rows.Err(); err != nil && log != nil {
		log.Warn("MSSQL scan: error al iterar tablas: %v", err)
	}
}

func (c *Client) scanSQLite(ctx context.Context, db *sql.DB, result *DBScanResult, log *logger.Logger) {
	rows, err := db.QueryContext(ctx, "SELECT name FROM sqlite_master WHERE type='table'")
	if err != nil {
		result.Success = false
		result.Error = err.Error()
		return
	}
	defer rows.Close()

	for rows.Next() {
		var tableName string
		if err := rows.Scan(&tableName); err != nil {
			continue
		}
		result.TableList = append(result.TableList, DBTableInfo{Name: tableName, Rows: 0})
	}
	if err := rows.Err(); err != nil && log != nil {
		log.Warn("SQLite scan: error al iterar sqlite_master: %v", err)
	}
}
