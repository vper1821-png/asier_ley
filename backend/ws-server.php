<?php
// backend/ws-server.php
// Servidor WebSocket para comunicación con agentes SecureLab
// Versión: 2.0 - con logging detallado y soporte para {type, payload}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class AgentWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $db;
    protected $agentSessions;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->agentSessions = [];
        try {
            $this->db = Database::getInstance();
            echo "✅ Base de datos inicializada correctamente\n";
        } catch (\Exception $e) {
            echo "❌ Error al inicializar la base de datos: " . $e->getMessage() . "\n";
        }
        echo "🔌 WebSocket Server iniciado en puerto 3839\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        try {
            $this->clients->attach($conn);
            echo "✅ Nueva conexión: {$conn->resourceId} desde " . $conn->remoteAddress . "\n";
            // Mensaje de bienvenida
            $conn->send(json_encode([
                'type' => 'welcome',
                'payload' => [
                    'message' => 'Conectado al servidor WebSocket de SecureLab',
                    'serverTime' => date('c')
                ]
            ]));
        } catch (\Exception $e) {
            echo "🔥 Error en onOpen: " . $e->getMessage() . "\n";
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            // 📥 Log del mensaje RAW (útil para depurar)
            $rawLength = strlen($msg);
            echo "📥 Mensaje RAW ({$rawLength} bytes): " . $msg . "\n";

            // Decodificar JSON
            $data = json_decode($msg, true);
            if (!$data) {
                echo "⚠️ Mensaje no es JSON válido\n";
                $from->send(json_encode([
                    'type' => 'error',
                    'payload' => ['message' => 'JSON inválido']
                ]));
                return;
            }

            // 📦 Log del mensaje decodificado (pretty print)
            echo "📦 Mensaje decodificado:\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n";

            // Extraer type y payload
            $type = $data['type'] ?? '';
            
            // Si existe 'payload', úsalo; si no, usa todo el objeto (compatibilidad)
            if (isset($data['payload']) && is_array($data['payload'])) {
                $payload = $data['payload'];
            } else {
                $payload = $data;
                unset($payload['type']); // Quitamos 'type' para no confundir
            }

            echo "📨 Tipo: {$type}\n";
            echo "📦 Payload: " . json_encode($payload) . "\n";

            // ─── Router de mensajes ──────────────────────────────────
            switch ($type) {
                case 'register':
                    $this->handleRegister($from, $payload);
                    break;
                case 'file_detected':
                    $this->handleFileDetected($from, $payload);
                    break;
                case 'file_event':
                    $this->handleFileEvent($from, $payload);
                    break;
                case 'db_query':
                    $this->handleDBQuery($from, $payload);
                    break;
                case 'host_event':
                    $this->handleHostEvent($from, $payload);
                    break;
                case 'telemetry':
                    $this->handleTelemetry($from, $payload);
                    break;
                case 'event':
                    $this->handleGenericEvent($from, $payload);
                    break;
                case 'command_response':
                    $this->handleCommandResponse($from, $payload);
                    break;
                case 'ping':
                    $from->send(json_encode([
                        'type' => 'pong',
                        'payload' => ['ts' => microtime(true)]
                    ]));
                    echo "🏓 Ping recibido, enviando pong\n";
                    break;
                default:
                    echo "⚠️ Tipo de mensaje desconocido: {$type}\n";
                    $from->send(json_encode([
                        'type' => 'error',
                        'payload' => ['message' => "Tipo de mensaje no soportado: {$type}"]
                    ]));
            }
        } catch (\Exception $e) {
            echo "🔥 Error en onMessage: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Error interno: ' . $e->getMessage()]
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        // Buscar el agentId asociado a esta conexión
        $agentId = null;
        foreach ($this->agentSessions as $id => $c) {
            if ($c === $conn) {
                $agentId = $id;
                break;
            }
        }
        if ($agentId) {
            unset($this->agentSessions[$agentId]);
            if ($this->db) {
                $this->db->updateOne('agents', ['agentId' => $agentId], ['status' => 'offline']);
            }
            echo "🔌 Agente desconectado: {$agentId}\n";
        }
        $this->clients->detach($conn);
        echo "❌ Conexión cerrada: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "🔥 Error en la conexión: " . $e->getMessage() . "\n";
        $conn->close();
    }

    // ─── HANDLER: REGISTER ──────────────────────────────────────────

    private function handleRegister(ConnectionInterface $conn, $data) {
        echo "🔍 handleRegister llamado con datos: " . json_encode($data) . "\n";

        // Extraer token y agentId (soporta tanto 'token' como 'accessToken' para compatibilidad)
        $token = $data['token'] ?? $data['accessToken'] ?? '';
        $agentId = $data['agentId'] ?? '';

        echo "🔎 Token: '{$token}' (longitud: " . strlen($token) . ")\n";
        echo "🔎 AgentId: '{$agentId}'\n";

        // Validar campos requeridos
        if (empty($token)) {
            echo "❌ Token vacío\n";
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Token requerido']
            ]));
            $conn->close();
            return;
        }

        if (empty($agentId)) {
            echo "❌ AgentId vacío\n";
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'AgentId requerido']
            ]));
            $conn->close();
            return;
        }

        // Verificar token JWT
        $decoded = Auth::verifyToken($token);
        if (!$decoded) {
            echo "❌ Token inválido o expirado\n";
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Token inválido o expirado']
            ]));
            $conn->close();
            return;
        }

        $userId = $decoded['userId'] ?? '';
        echo "✅ Token válido para usuario: {$userId}\n";

        // Asignar el agente a esta conexión
        $conn->userId = $userId;
        $conn->agentId = $agentId;
        $this->agentSessions[$agentId] = $conn;

        // Actualizar estado en la base de datos
        if ($this->db) {
            $this->db->updateOne('agents', ['agentId' => $agentId], [
                'status' => 'online',
                'lastSeen' => date('c'),
                'userId' => $userId
            ]);
            echo "📝 Agente actualizado en BD: {$agentId}\n";
        }

        echo "✅ Agente registrado: {$agentId} (usuario: {$userId})\n";
        $conn->send(json_encode([
            'type' => 'registered',
            'payload' => [
                'agentId' => $agentId,
                'message' => 'Agente registrado correctamente'
            ]
        ]));

        // Enviar comandos pendientes
        $this->sendPendingCommands($agentId);
    }

    // ─── HANDLER: FILE_DETECTED ─────────────────────────────────────

    private function handleFileDetected(ConnectionInterface $from, $data) {
        // Soporta formato directo o con wrapper 'detectedFile'
        $fileData = $data['detectedFile'] ?? $data;
        
        echo "📄 file_detected recibido: " . json_encode($fileData) . "\n";

        $agentId = $from->agentId ?? $fileData['agentId'] ?? '';
        $userId = $from->userId ?? '';

        if (!$agentId || !$userId) {
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Agente no registrado']
            ]));
            echo "❌ file_detected: agente no registrado\n";
            return;
        }

        if (empty($fileData['path']) || empty($fileData['hash'])) {
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Faltan datos del archivo (path, hash)']
            ]));
            echo "❌ file_detected: datos incompletos\n";
            return;
        }

        try {
            $result = $this->processFileDetection($userId, $agentId, $fileData);
            $from->send(json_encode([
                'type' => 'file_response',
                'payload' => [
                    'success' => true,
                    'fileId' => $result['fileId'] ?? null,
                    'message' => 'Archivo procesado correctamente'
                ]
            ]));
            echo "✅ Archivo procesado: {$fileData['path']}\n";
        } catch (\Exception $e) {
            $from->send(json_encode([
                'type' => 'file_response',
                'payload' => [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ]
            ]));
            echo "❌ Error procesando archivo: " . $e->getMessage() . "\n";
        }
    }

    // ─── HANDLERS: FILE_EVENT, DB_QUERY, HOST_EVENT, TELEMETRY, EVENT ──

    private function handleFileEvent(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) {
            echo "⚠️ file_event ignorado (agente no registrado o BD no disponible)\n";
            return;
        }
        $doc = [
            'agentId' => $agentId,
            'userId' => $from->userId ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'path' => $data['path'] ?? '',
            'eventType' => $data['eventType'] ?? 'unknown',
            'process' => $data['process'] ?? '',
            'pid' => (int)($data['pid'] ?? 0),
            'user' => $data['user'] ?? '',
            'size' => (int)($data['size'] ?? 0),
            'hash' => $data['hash'] ?? '',
            'destination' => $data['destination'] ?? '',
            'createdAt' => date('c'),
        ];
        $this->db->insertOne('file_events', $doc);
        echo "📄 File event: {$data['path']} ({$data['eventType']})\n";
    }

    private function handleDBQuery(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) {
            echo "⚠️ db_query ignorado\n";
            return;
        }
        $doc = [
            'agentId' => $agentId,
            'userId' => $from->userId ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'engine' => $data['engine'] ?? '',
            'database' => $data['database'] ?? '',
            'user' => $data['user'] ?? '',
            'host' => $data['host'] ?? '',
            'query' => $data['query'] ?? '',
            'operation' => $data['operation'] ?? 'query',
            'riskScore' => (float)($data['riskScore'] ?? 0),
            'createdAt' => date('c'),
        ];
        $this->db->insertOne('database_logs', $doc);
        echo "📊 DB query: {$data['database']} - {$data['user']}\n";
    }

    private function handleHostEvent(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;
        $doc = [
            'agentId' => $agentId,
            'userId' => $from->userId ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'type' => $data['type'] ?? 'host_event',
            'severity' => $data['severity'] ?? 'info',
            'title' => $data['title'] ?? 'Evento del sistema',
            'detail' => $data['detail'] ?? '',
            'source' => $data['source'] ?? 'agent',
            'createdAt' => date('c'),
        ];
        $this->db->insertOne('alerts', [
            'userId' => $from->userId ?? '',
            'agentId' => $agentId,
            'title' => $doc['title'],
            'message' => $doc['detail'],
            'severity' => $doc['severity'],
            'source' => $doc['source'],
            'eventType' => $doc['type'],
            'read' => false,
            'resolved' => false,
            'createdAt' => $doc['createdAt'],
        ]);
        $this->db->insertOne('host_events', $doc);
        echo "🖥️ Host event: {$doc['title']} ({$doc['severity']})\n";
    }

    private function handleTelemetry(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) {
            echo "⚠️ telemetry ignorado\n";
            return;
        }
        $doc = [
            'userId' => $from->userId ?? '',
            'agentId' => $agentId,
            'cpu' => (float)($data['cpu'] ?? 0),
            'ram' => (float)($data['memory'] ?? 0),
            'disk' => (float)($data['diskFree'] ?? 0),
            'uptime' => (int)($data['timestamp'] ?? 0),
            'status' => 'online',
            'lastSeen' => date('c'),
        ];
        $existing = $this->db->findOne('host_monitor', ['agentId' => $agentId]);
        if ($existing) {
            $this->db->updateOne('host_monitor', ['_id' => $existing['_id']], $doc);
        } else {
            $doc['createdAt'] = date('c');
            $this->db->insertOne('host_monitor', $doc);
        }
        echo "📊 Telemetría recibida de {$agentId}\n";
    }

    private function handleGenericEvent(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;
        $this->db->insertOne('alerts', [
            'userId' => $from->userId ?? '',
            'agentId' => $agentId,
            'title' => $data['title'] ?? 'Evento del agente',
            'message' => $data['description'] ?? '',
            'severity' => $data['severity'] ?? 'medium',
            'source' => $data['source'] ?? 'agent',
            'eventType' => 'generic',
            'read' => false,
            'resolved' => false,
            'createdAt' => date('c'),
        ]);
        echo "📢 Evento genérico: {$data['title']}\n";
    }

    private function handleCommandResponse(ConnectionInterface $from, $data) {
        $commandId = $data['commandId'] ?? '';
        $status = $data['status'] ?? 'error';
        $result = $data['result'] ?? '';
        if ($commandId && $this->db) {
            $this->db->updateOne('agent_commands', ['_id' => $commandId], [
                'executed' => true,
                'executedAt' => date('c'),
                'result' => $result,
                'status' => $status,
            ]);
            echo "📨 Command response: {$commandId} - {$status}\n";
        }
    }

    // ─── COMANDOS PENDIENTES ──────────────────────────────────────

    private function sendPendingCommands($agentId) {
        if (!$this->db) return;
        $commands = $this->db->find('agent_commands', [
            'agentId' => $agentId,
            'executed' => ['$in' => [false, null]],
        ]);
        foreach ($commands as $cmd) {
            $conn = $this->agentSessions[$agentId] ?? null;
            if (!$conn) {
                echo "⚠️ No se puede enviar comando, agente desconectado: {$agentId}\n";
                break;
            }
            $conn->send(json_encode([
                'type' => 'command',
                'payload' => [
                    'command' => $cmd['command'],
                    'params' => $cmd['params'] ?? [],
                    'commandId' => $cmd['_id'],
                ]
            ]));
            echo "📨 Comando enviado a {$agentId}: " . json_encode($cmd['command']) . "\n";
        }
    }

    // ─── PROCESAMIENTO DE DETECCIÓN DE ARCHIVO ──────────────────

    private function processFileDetection($userId, $agentId, $fileData) {
        if (!$this->db) {
            throw new \Exception('Base de datos no disponible');
        }
        $db = $this->db;

        // Validar campos requeridos
        $required = ['path', 'hash', 'fileType'];
        foreach ($required as $field) {
            if (empty($fileData[$field])) {
                throw new \Exception("Campo '$field' requerido");
            }
        }

        // 1. Guardar en compliance_files
        $existing = $db->findOne('compliance_files', [
            'agentId' => $agentId,
            'path' => $fileData['path'],
            'sourceType' => 'agent'
        ]);

        $doc = [
            'userId'        => $userId,
            'sourceType'    => 'agent',
            'agentId'       => $agentId,
            'hostname'      => $fileData['hostname'] ?? 'unknown',
            'path'          => $fileData['path'],
            'originalName'  => basename($fileData['path']),
            'ext'           => strtolower(pathinfo($fileData['path'], PATHINFO_EXTENSION)),
            'size'          => (int)($fileData['size'] ?? 0),
            'hash'          => $fileData['hash'],
            'mimeType'      => $fileData['mimeType'] ?? 'application/octet-stream',
            'status'        => 'analyzed',
            'user'          => $fileData['user'] ?? null,
            'analysisResult' => [
                'rowCount'    => (int)($fileData['rowCount'] ?? 0),
                'headers'     => array_keys($fileData['personalData'] ?? []),
                'patterns'    => $fileData['personalData'] ?? [],
                'sensitive'   => !empty($fileData['sensitive']),
                'analyzedAt'  => date('c'),
                'analyzedBy'  => 'agent',
                'user'        => $fileData['user'] ?? null,
            ],
            'createdAt'     => date('c'),
            'updatedAt'     => date('c'),
        ];

        if ($existing) {
            $db->updateOne('compliance_files', ['_id' => $existing['_id']], $doc);
            $fileId = $existing['_id'];
            $inventoryId = $existing['analysisResult']['inventoryId'] ?? null;
        } else {
            $inserted = $db->insertOne('compliance_files', $doc);
            $fileId = $inserted['_id'];
            $inventoryId = null;
        }

        // 2. Inventario (RAT)
        $categories = [];
        foreach ($fileData['personalData'] ?? [] as $col => $types) {
            $categories = array_merge($categories, $types);
        }
        $categories = array_unique($categories);

        $inventoryData = [
            'userId'         => $userId,
            'sourceType'     => 'file',
            'sourceId'       => $fileId,
            'name'           => '📄 Archivo: ' . basename($fileData['path']),
            'dataCategories' => implode(', ', $categories),
            'records'        => (int)($fileData['rowCount'] ?? 0),
            'sensitive'      => !empty($fileData['sensitive']),
            'legalBasis'     => 'Pendiente de definir',
            'active'         => true,
            'storage'        => $fileData['hostname'] ?? 'Agente',
            'user'           => $fileData['user'] ?? null,
            'updatedAt'      => date('c'),
        ];

        if ($inventoryId) {
            $db->updateOne('compliance_inventory', ['_id' => $inventoryId], $inventoryData);
        } else {
            $inventoryData['createdAt'] = date('c');
            $inv = $db->insertOne('compliance_inventory', $inventoryData);
            $db->updateOne('compliance_files', ['_id' => $fileId], [
                'analysisResult.inventoryId' => $inv['_id']
            ]);
        }

        // 3. Auditoría de archivos
        $db->insertOne('file_audit_logs', [
            'userId' => $userId,
            'agentId' => $agentId,
            'hostname' => $fileData['hostname'] ?? 'unknown',
            'path' => $fileData['path'],
            'user' => $fileData['user'] ?? null,
            'detectedAt' => date('c'),
            'categories' => array_keys($fileData['personalData'] ?? []),
            'sensitive' => !empty($fileData['sensitive']),
            'rowCount' => (int)($fileData['rowCount'] ?? 0),
            'fileType' => $fileData['fileType'] ?? 'unknown',
            'hash' => $fileData['hash'],
            'status' => 'processed',
        ]);

        // 4. Auditoría general
        $db->insertOne('audit_logs', [
            'userId' => $userId,
            'action' => 'file_detected_by_agent',
            'details' => [
                'agentId' => $agentId,
                'path' => $fileData['path'],
                'user' => $fileData['user'] ?? null,
                'sensitive' => !empty($fileData['sensitive']),
                'categories' => array_keys($fileData['personalData'] ?? []),
            ],
            'createdAt' => date('c'),
        ]);

        return ['fileId' => $fileId];
    }
}

// ─── INICIAR SERVIDOR ──────────────────────────────────────────

echo "🚀 Iniciando servidor WebSocket en el puerto 3839...\n";
echo "Presiona Ctrl+C para detener.\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AgentWebSocket()
        )
    ),
    3839
);

$server->run();