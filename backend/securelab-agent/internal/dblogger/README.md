# DBLogger - MongoDB Query Logger

## Descripción

El módulo DBLogger captura todas las consultas de MongoDB ejecutadas por el agente SecureLab, incluyendo las consultas del usuario root. Este módulo es esencial para auditoría, debugging y cumplimiento normativo.

## Características

- **Captura completa**: Registra todas las operaciones MongoDB (find, insert, update, delete)
- **Incluye root**: Captura consultas del usuario root/administrador
- **Buffer eficiente**: Usa un buffer configurable para minimizar el impacto en rendimiento
- **Envío asíncrono**: Envía logs al servidor en segundo plano
- **Detalles completos**: Registra query, update, duración, éxito/error, etc.

## Uso

### Configuración Básica

```go
import (
    "github.com/yourproject/securelab-agent/internal/dblogger"
    "go.mongodb.org/mongo-driver/mongo"
)

// Crear instancia del DBLogger
dbLoggerConfig := dblogger.Config{
    Enabled:     true,
    BufferSize:  1000,           // Número de logs antes de enviar
    APIEndpoint: "https://api.example.com/db-logs",
    APIToken:    "your-api-token",
}

dbLogger := dblogger.NewDBLogger(dbLoggerConfig)
```

### Integración con Cliente MongoDB

```go
// Configurar el cliente MongoDB con monitoreo
client, err := mongo.Connect(ctx, options.Client().ApplyURI(uri))
if err != nil {
    log.Fatal(err)
}

// Iniciar el monitoreo
err = dbLogger.MonitorClient(client)
if err != nil {
    log.Fatal(err)
}
```

### Enviar Logs Manualmente

```go
// Forzar el envío de logs pendientes
err := dbLogger.Flush()
if err != nil {
    log.Printf("Error sending logs: %v", err)
}
```

### Obtener Logs Locales

```go
// Obtener copia de los logs en buffer
logs := dbLogger.GetLogs()
for _, logEntry := range logs {
    fmt.Printf("Operation: %s, Collection: %s, Duration: %dms\n",
        logEntry.Operation, logEntry.Collection, logEntry.Duration)
}
```

## Estructura del Log

```go
type DBLog struct {
    Timestamp   time.Time              `json:"timestamp"`    // Cuando se ejecutó
    Operation   string                 `json:"operation"`    // find, insert, update, delete
    Collection  string                 `json:"collection"`   // Nombre de la colección
    Database    string                 `json:"database"`     // Nombre de la base de datos
    Query       map[string]interface{} `json:"query"`        // Filtros de la consulta
    Update      map[string]interface{} `json:"update"`       // Datos de actualización
    Document    map[string]interface{} `json:"document"`     // Documento insertado
    Duration    int64                  `json:"duration_ms"`  // Duración en milisegundos
    Success     bool                   `json:"success"`      // Si la consulta fue exitosa
    Error       string                 `json:"error"`        // Mensaje de error si falló
    User        string                 `json:"user"`         // Usuario que ejecutó
    ConnectionID string                `json:"connection_id"`// ID de conexión
}
```

## Configuración del Buffer

El buffer se usa para acumular logs antes de enviarlos al servidor, minimizando el impacto en rendimiento:

```go
config := dblogger.Config{
    BufferSize: 1000,  // Envía logs cada 1000 operaciones
}
```

Valores recomendados:
- **Desarrollo**: 100 (envía más frecuentemente)
- **Producción**: 1000-5000 (balance entre rendimiento y latencia)
- **Alto volumen**: 10000+ (para sistemas con muchas consultas)

## Habilitar/Deshabilitar en Tiempo de Ejecución

```go
// Habilitar logging
dbLogger.Enable()

// Deshabilitar logging
dbLogger.Disable()

// Verificar estado
if dbLogger.IsEnabled() {
    log.Println("DBLogger está activo")
}
```

## Performance

El DBLogger está diseñado para tener un impacto mínimo en el rendimiento:

- **Buffer asíncrono**: Los logs se acumulan en memoria y se envían en background
- **Goroutines separadas**: Cada tipo de evento tiene su propia goroutine
- **Mutex eficiente**: Solo bloquea cuando accede al buffer
- **Serialización diferida**: Convierte BSON a JSON solo al enviar

## Seguridad

- **No captura datos sensibles**: Passwords, tokens y otros datos sensibles no se loguean
- **API Token**: Usa token de autenticación para enviar logs
- **HTTPS**: Siempre usa HTTPS para enviar logs
- **Encriptación**: Los logs se envían encriptados

## Ejemplo Completo

```go
package main

import (
    "context"
    "log"
    "time"

    "github.com/yourproject/securelab-agent/internal/dblogger"
    "go.mongodb.org/mongo-driver/mongo"
    "go.mongodb.org/mongo-driver/mongo/options"
)

func main() {
    // Configurar DBLogger
    dbLogger := dblogger.NewDBLogger(dblogger.Config{
        Enabled:     true,
        BufferSize:  1000,
        APIEndpoint: "https://api.example.com/db-logs",
        APIToken:    "your-api-token",
    })

    // Conectar a MongoDB
    ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
    defer cancel()

    client, err := mongo.Connect(ctx, options.Client().ApplyURI("mongodb://localhost:27017"))
    if err != nil {
        log.Fatal(err)
    }
    defer client.Disconnect(ctx)

    // Iniciar monitoreo
    err = dbLogger.MonitorClient(client)
    if err != nil {
        log.Fatal(err)
    }

    // Usar el cliente normalmente...
    // Todas las consultas se capturarán automáticamente

    // Asegurar que los logs se envíen antes de salir
    dbLogger.Flush()
}
```

## Troubleshooting

### Logs no se están enviando

1. Verifica que `Enabled` sea `true`
2. Verifica que `APIEndpoint` esté configurado correctamente
3. Revisa los logs del sistema para errores de red
4. Verifica que el token API sea válido

### Alto uso de memoria

1. Reduce el `BufferSize`
2. Llama a `Flush()` más frecuentemente
3. Considera deshabilitar el logging en producción si no es necesario

### Consultas no se capturan

1. Verifica que `MonitorClient()` se llamó después de conectar
2. Asegúrate de que el cliente MongoDB es el mismo que se está usando
3. Revisa los logs del sistema para errores del DBLogger

## Licencia

Parte del proyecto SecureLab - Sistema de Gestión de Cumplimiento de Datos Personales (Ley 21.719)