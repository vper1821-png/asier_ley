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
            $reflection = new \ReflectionClass($this->db);
            $property = $reflection->getProperty('useMongo');
            $property->setAccessible(true);
            $useMongo = $property->getValue($this->db);
            echo "🔍 Database usando: " . ($useMongo ? "MongoDB" : "Archivos JSON") . "\n";
        } catch (\Throwable $e) {
            echo "❌ Error al inicializar la base de datos: " . $e->getMessage() . "\n";
        }
        echo "🔌 WebSocket Server iniciado en puerto 3839\n";
    }

    public function getDb() { return $this->db; }
    public function getAgentSessions() { return $this->agentSessions; }

    public function onOpen(ConnectionInterface $conn) {
        try {
            $this->clients->attach($conn);
            echo "✅ Nueva conexión: {$conn->resourceId} desde " . $conn->remoteAddress . "\n";
            $conn->send(json_encode([
                'type' => 'welcome',
                'payload' => [
                    'message' => 'Conectado al servidor WebSocket de SecureLab',
                    'serverTime' => date('c')
                ]
            ]));
        } catch (\Throwable $e) {
            echo "🔥 Error en onOpen: " . $e->getMessage() . "\n";
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        try {
            $rawLength = strlen($msg);
            echo "📥 Mensaje RAW ({$rawLength} bytes)\n";

            $data = json_decode($msg, true);
            if (!$data) {
                echo "⚠️ Mensaje no es JSON válido\n";
                $from->send(json_encode([
                    'type' => 'error',
                    'payload' => ['message' => 'JSON inválido']
                ]));
                return;
            }

            $type = $data['type'] ?? '';

            if (isset($data['payload']) && is_array($data['payload'])) {
                $payload = $data['payload'];
            } else {
                $payload = $data;
                unset($payload['type']);
            }

            switch ($type) {
                case 'register':
                    $this->handleRegister($from, $payload);
                    break;
                case 'file_detected':
                    $this->handleFileDetected($from, $payload);
                    break;
                case 'inventory_item':
                    $this->handleInventoryItem($from, $payload);
                    break;
                case 'file_event':
                    $this->handleFileEvent($from, $payload);
                    break;
                case 'file_deleted':
                    $this->handleFileDeleted($from, $payload);
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
                    break;
                case 'sync':
                    $this->handleSync($from);
                    break;
                case 'data_response':
                    $this->handleDataResponse($from, $payload);
                    break;
                default:
                    echo "⚠️ Tipo de mensaje desconocido: {$type}\n";
                    $from->send(json_encode([
                        'type' => 'error',
                        'payload' => ['message' => "Tipo de mensaje no soportado: {$type}"]
                    ]));
            }
        } catch (\Throwable $e) {
            echo "🔥 Error en onMessage: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Error interno: ' . $e->getMessage()]
            ]));
        }
    }

    public function onClose(ConnectionInterface $conn) {
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
                $userId = $conn->userId ?? '';
                $this->db->updateOne('agents', ['agentId' => $agentId, 'userId' => $userId], ['status' => 'offline']);
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
        $token = $data['token'] ?? $data['accessToken'] ?? '';
        $agentId = $data['agentId'] ?? '';

        if (empty($token)) {
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Token requerido']
            ]));
            $conn->close();
            return;
        }

        if (empty($agentId)) {
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'AgentId requerido']
            ]));
            $conn->close();
            return;
        }

        $decoded = Auth::verifyToken($token);
        if (!$decoded) {
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Token inválido o expirado']
            ]));
            $conn->close();
            return;
        }

        $userId = $decoded['userId'] ?? '';

        $conn->userId = $userId;
        $conn->agentId = $agentId;
        $this->agentSessions[$agentId] = $conn;

        if ($this->db) {
            $existing = $this->db->findOne('agents', ['agentId' => $agentId, 'userId' => $userId]);
            if (!$existing) {
                $this->db->insertOne('agents', [
                    'userId' => $userId,
                    'agentId' => $agentId,
                    'status' => 'online',
                    'lastSeen' => date('c'),
                    'createdAt' => date('c'),
                ]);
            } else {
                $this->db->updateOne('agents', ['agentId' => $agentId, 'userId' => $userId], [
                    'status' => 'online',
                    'lastSeen' => date('c'),
                    'userId' => $userId
                ]);
            }
        }

        echo "✅ Agente registrado: {$agentId} (usuario: {$userId})\n";
        $conn->send(json_encode([
            'type' => 'registered',
            'payload' => [
                'agentId' => $agentId,
                'message' => 'Agente registrado correctamente'
            ]
        ]));

        $this->sendPendingCommands($agentId);
    }

    // ─── HANDLER: FILE_DETECTED ─────────────────────────────────────

    private function handleFileDetected(ConnectionInterface $from, $data) {
        $fileData = $data['detectedFile'] ?? $data;

        $agentId = $from->agentId ?? $fileData['agentId'] ?? '';
        $userId = $from->userId ?? '';

        if (!$agentId || !$userId) {
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Agente no registrado']
            ]));
            return;
        }

        if (empty($fileData['path']) || empty($fileData['hash'])) {
            $from->send(json_encode([
                'type' => 'error',
                'payload' => ['message' => 'Faltan datos del archivo (path, hash)']
            ]));
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
            echo "✅ file_detected OK: {$fileData['path']}\n";
        } catch (\Throwable $e) {
            $from->send(json_encode([
                'type' => 'file_response',
                'payload' => [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ]
            ]));
            echo "❌ Error procesando file_detected: " . $e->getMessage() . "\n";
        }
    }

    // ─── HANDLER: INVENTORY_ITEM (FIX CRÍTICO) ──────────────────────

    /**
     * Antes: este handler tenía una implementación propia que SOBRESCRIBÍA
     * analysisResult (perdiendo inventoryId) y no creaba el item de inventario.
     * Resultado: solo ~13% de los archivos aparecían en el RAT.
     *
     * Ahora: delega a processFileDetection(), el mismo método que usa
     * handleFileDetected. Un solo flujo, un solo lugar para arreglar bugs.
     */
    private function handleInventoryItem(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        $userId = $from->userId ?? '';

        if (!$agentId || !$userId || !$this->db) {
            echo "⚠️ inventory_item ignorado (sin agente, usuario o BD)\n";
            return;
        }

        $path = $data['path'] ?? '';
        $hash = $data['hash'] ?? '';
        if (!$path || !$hash) {
            echo "⚠️ inventory_item DESCARTADO: falta " . (!$path ? 'path' : 'hash') . " (agent={$agentId})\n";
            return;
        }

        // Adaptar el formato del inventory_item al de file_detected
        $fileData = [
            'path'         => $path,
            'hash'         => $hash,
            'fileType'     => $data['extension'] ?? $data['fileType'] ?? 'unknown',
            'hostname'     => $data['hostname'] ?? 'unknown',
            'user'         => $data['user'] ?? null,
            'size'         => (int)($data['size'] ?? 0),
            'rowCount'     => (int)($data['scanCount'] ?? $data['rowCount'] ?? 0),
            'sensitive'    => !empty($data['sensitive']),
            'personalData' => $data['personalData'] ?? [],
            'categories'   => $data['categories'] ?? [],
        ];

        try {
            $result = $this->processFileDetection($userId, $agentId, $fileData);
            echo "🗂️  inventory_item OK: {$path} (agent={$agentId}, sensitive="
                . ($fileData['sensitive'] ? 'true' : 'false')
                . ", fileId=" . ($result['fileId'] ?? '?') . ")\n";
        } catch (\Throwable $e) {
            echo "❌ inventory_item ERROR: {$path} - " . $e->getMessage() . "\n";
        }
    }

    // ─── HANDLERS: FILE_EVENT, DB_QUERY, HOST_EVENT, TELEMETRY, EVENT ──

    private function handleFileEvent(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;
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
    }

    private function handleDBQuery(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;
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
        if ($doc['riskScore'] >= 0.5) {
            $severity = $doc['riskScore'] >= 0.8 ? 'critical' : 'high';
            $this->db->insertOne('alerts', [
                'agentId' => $agentId,
                'userId' => $from->userId ?? '',
                'title' => 'Consulta riesgosa en ' . $doc['database'],
                'message' => $doc['query'],
                'severity' => $severity,
                'source' => 'db_query',
                'category' => 'database_access',
                'lawArticle' => 'Art. 25 Ley 21.719',
                'eventType' => $doc['operation'] ?? 'query',
                'read' => false,
                'resolved' => false,
                'createdAt' => date('c'),
            ]);
        }
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
            'category' => 'security_monitoring',
            'lawArticle' => 'Art. 25 Ley 21.719',
            'eventType' => $doc['type'],
            'read' => false,
            'resolved' => false,
            'createdAt' => $doc['createdAt'],
        ]);
        $this->db->insertOne('host_events', $doc);
    }

    private function handleTelemetry(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) {
            echo "⚠️ telemetry ignorado (sin agentId o db)\n";
            return;
        }
        $diskFree = (float)($data['diskFree'] ?? 0);
        $diskTotal = (float)($data['diskTotal'] ?? 0);
        $diskPct = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0;
        $doc = [
            'userId' => $from->userId ?? '',
            'agentId' => $agentId,
            'hostname' => $data['hostname'] ?? $agentId,
            'cpu' => (float)($data['cpu'] ?? 0),
            'ram' => (float)($data['memory'] ?? 0),
            'disk' => max(0, min(100, $diskPct)),
            'diskFree' => $diskFree,
            'diskTotal' => $diskTotal,
            'diskUsed' => max(0, $diskTotal - $diskFree),
            'processes' => (int)($data['processes'] ?? 0),
            'connections' => (int)($data['connections'] ?? 0),
            'platform' => $data['platform'] ?? '',
            'arch' => $data['arch'] ?? '',
            'os' => $data['os'] ?? '',
            'user' => $data['user'] ?? '',
            'uptime' => (int)($data['uptime'] ?? 0),
            'status' => 'online',
            'lastSeen' => date('c'),
        ];
        $userId = $from->userId ?? '';
        $agent = $this->db->findOne('agents', ['agentId' => $agentId, 'userId' => $userId]);
        if (!$agent) {
            return;
        }
        $existing = $this->db->findOne('host_monitor', ['agentId' => $agentId, 'userId' => $userId]);
        if ($existing) {
            $this->db->updateOne('host_monitor', ['_id' => $existing['_id']], $doc);
        } else {
            $doc['createdAt'] = date('c');
            $this->db->insertOne('host_monitor', $doc);
        }
        if (isset($agent['lockdown'])) {
            $this->db->updateOne('host_monitor', ['agentId' => $agentId, 'userId' => $userId], ['lockdown' => $agent['lockdown']]);
        }
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
            'category' => 'security_monitoring',
            'lawArticle' => 'Art. 25 Ley 21.719',
            'eventType' => 'generic',
            'read' => false,
            'resolved' => false,
            'createdAt' => date('c'),
        ]);
    }

    // ─── DATA RESPONSE (FIX: no borrar el más nuevo) ────────────────

    private function handleDataResponse(ConnectionInterface $conn, $data) {
        $agentId = $conn->agentId ?? $data['agentId'] ?? '';
        $type = $data['type'] ?? '';
        if (!$agentId || !$type || !$this->db) return;

        $this->db->insertOne('agent_data', [
            'agentId' => $agentId,
            'type' => $type,
            'data' => $data['data'] ?? null,
            'ts' => (int)($data['ts'] ?? time()),
            'createdAt' => date('c'),
        ]);

        // Limpiar viejos: ordenar por ts desc, luego pop del final (los viejos)
        $all = $this->db->find('agent_data', ['agentId' => $agentId, 'type' => $type]);
        usort($all, function ($a, $b) {
            $ta = (int)($a['ts'] ?? 0);
            $tb = (int)($b['ts'] ?? 0);
            if ($ta === $tb) {
                return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '');
            }
            return $tb - $ta;
        });
        while (count($all) > 20) {
            $oldest = array_pop($all);
            if (isset($oldest['_id'])) {
                $this->db->deleteOne('agent_data', ['_id' => $oldest['_id']]);
            }
        }
    }

    // ─── SYNC ──────────────────────────────────────────────────────

    private function handleSync(ConnectionInterface $from) {
        $agentId = $from->agentId ?? '';
        if (!$agentId) return;
        if ($this->db) {
            $this->db->updateOne('agents', ['agentId' => $agentId], ['lastSeen' => date('c')]);
        }
        $agent = $this->db->findOne('agents', ['agentId' => $agentId]);
        $lockdown = $agent['lockdown'] ?? ['enabled' => false];
        $commands = $this->db->find('agent_commands', [
            'agentId' => $agentId,
            'executed' => ['$in' => [false, null]],
        ]);
        $pending = [];
        foreach ($commands as $cmd) {
            $pending[] = [
                'command' => $cmd['command'],
                'params' => $cmd['params'] ?? [],
                'commandId' => $cmd['_id'],
            ];
        }

        $dbConns = $this->db->find('agent_db_connections', [
            'agentId' => $agentId,
            'enabled' => true,
        ]);

        $dashboardConns = $this->db->find('databases', [
            'userId' => $from->userId,
            'status' => 'connected',
        ]);

        $connections = [];
        $seen = [];
        foreach (array_merge($dbConns, $dashboardConns) as $dbConn) {
            $engine = $dbConn['engine'] ?? $dbConn['type'] ?? '';
            $host = $dbConn['host'] ?? '';
            $port = (int)($dbConn['port'] ?? 0);
            $database = $dbConn['database'] ?? '';
            $username = $dbConn['username'] ?? $dbConn['user'] ?? '';
            $password = $dbConn['password'] ?? '';
            $ssl = (bool)($dbConn['ssl'] ?? false);

            if (in_array($engine, ['mariadb', 'mysql'])) {
                $engine = 'mysql';
            } elseif (in_array($engine, ['postgresql', 'postgres'])) {
                $engine = 'postgres';
            }

            $key = "$engine|$host|$port|$database|$username";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $connections[] = [
                'engine'   => $engine,
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'ssl'      => $ssl,
            ];
        }

        $from->send(json_encode([
            'type' => 'sync_response',
            'payload' => [
                'lockdown' => $lockdown,
                'pendingCommands' => $pending,
                'connections' => $connections,
            ]
        ]));
    }

    private function handleCommandResponse(ConnectionInterface $from, $data) {
        $commandId = $data['commandId'] ?? '';
        $status = $data['status'] ?? 'error';
        $result = $data['result'] ?? '';
        if ($commandId && $this->db) {
            try {
                $this->db->updateOne('agent_commands', ['_id' => $commandId, 'userId' => $from->userId ?? ''], [
                    'executed' => true,
                    'executedAt' => date('c'),
                    'result' => $result,
                    'status' => $status,
                ]);
            } catch (\Throwable $e) {
                echo "❌ Error guardando respuesta: " . $e->getMessage() . "\n";
            }
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
            if (!$conn) break;
            try {
                $conn->send(json_encode([
                    'type' => 'command',
                    'payload' => [
                        'command' => $cmd['command'],
                        'params' => $cmd['params'] ?? [],
                        'commandId' => $cmd['_id'],
                    ]
                ]));
            } catch (\Throwable $e) {
                echo "⚠️ No se pudo enviar comando a {$agentId}: " . $e->getMessage() . "\n";
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PROCESAMIENTO DE DETECCIÓN DE ARCHIVO (FIX CRÍTICO)
    // ═══════════════════════════════════════════════════════════════
    //
    // Este método es el ÚNICO lugar que crea/actualiza el inventario.
    // Tanto handleFileDetected como handleInventoryItem lo llaman.
    //
    // FIX 1: Preserva inventoryId al actualizar (evita duplicados)
    // FIX 2: Añade agentId, hostname, path al inventario (agrupación)
    //
    private function processFileDetection($userId, $agentId, $fileData) {
        if (!$this->db) {
            throw new \Exception('Base de datos no disponible');
        }
        $db = $this->db;

        $required = ['path', 'hash'];
        foreach ($required as $field) {
            if (empty($fileData[$field])) {
                throw new \Exception("Campo '$field' requerido");
            }
        }

        // 1. Buscar si ya existe
        $existing = $db->findOne('compliance_files', [
            'agentId' => $agentId,
            'path' => $fileData['path'],
            'sourceType' => 'agent'
        ]);

        // ── FIX: preservar inventoryId ──
        $existingInventoryId = $existing['analysisResult']['inventoryId'] ?? null;

        $hostname = $fileData['hostname'] ?? 'unknown';
        $sensitive = !empty($fileData['sensitive']);
        $personalData = $fileData['personalData'] ?? [];

        // 2. Construir documento del archivo
        $analysisResult = [
            'rowCount'    => (int)($fileData['rowCount'] ?? 0),
            'headers'     => array_keys($personalData),
            'patterns'    => $personalData,
            'sensitive'   => $sensitive,
            'analyzedAt'  => date('c'),
            'analyzedBy'  => 'agent',
            'user'        => $fileData['user'] ?? null,
        ];
        if ($existingInventoryId) {
            $analysisResult['inventoryId'] = $existingInventoryId;
        }

        $doc = [
            'userId'        => $userId,
            'sourceType'    => 'agent',
            'agentId'       => $agentId,
            'hostname'      => $hostname,
            'path'          => $fileData['path'],
            'originalName'  => basename($fileData['path']),
            'ext'           => strtolower(pathinfo($fileData['path'], PATHINFO_EXTENSION)),
            'size'          => (int)($fileData['size'] ?? 0),
            'hash'          => $fileData['hash'],
            'mimeType'      => $fileData['mimeType'] ?? 'application/octet-stream',
            'status'        => 'analyzed',
            'user'          => $fileData['user'] ?? null,
            'analysisResult' => $analysisResult,
            'createdAt'     => $existing['createdAt'] ?? date('c'),
            'updatedAt'     => date('c'),
        ];

        if ($existing) {
            $db->updateOne('compliance_files', ['_id' => $existing['_id']], $doc);
            $fileId = $existing['_id'];
            $inventoryId = $existingInventoryId;
        } else {
            $inserted = $db->insertOne('compliance_files', $doc);
            $fileId = $inserted['_id'];
            $inventoryId = null;
        }

        // 3. Categorías únicas (de personalData + categories directas)
        $categories = [];
        foreach ($personalData as $col => $types) {
            if (is_array($types)) {
                $categories = array_merge($categories, $types);
            } elseif (is_string($types)) {
                $categories[] = $types;
            }
        }
        if (!empty($fileData['categories']) && is_array($fileData['categories'])) {
            $categories = array_merge($categories, $fileData['categories']);
        }
        $categories = array_values(array_unique(array_filter($categories)));

        // 4. Inventario (RAT) — INCLUYE agentId y hostname para agrupar
        $inventoryData = [
            'userId'         => $userId,
            'sourceType'     => 'file',
            'sourceId'       => $fileId,
            'agentId'        => $agentId,
            'hostname'       => $hostname,
            'path'           => $fileData['path'],
            'name'           => '📄 ' . basename($fileData['path']),
            'dataCategories' => implode(', ', $categories),
            'records'        => (int)($fileData['rowCount'] ?? 0),
            'sensitive'      => $sensitive,
            'legalBasis'     => 'Pendiente de definir',
            'active'         => true,
            'storage'        => $hostname,
            'user'           => $fileData['user'] ?? null,
            'updatedAt'      => date('c'),
        ];

        if ($inventoryId) {
            // Actualizar el existente
            $db->updateOne('compliance_inventory', ['_id' => $inventoryId], $inventoryData);
        } else {
            // Crear nuevo
            $inventoryData['createdAt'] = date('c');
            $inv = $db->insertOne('compliance_inventory', $inventoryData);
            // Enlazar el inventario al file
            $db->updateOne('compliance_files', ['_id' => $fileId], [
                'analysisResult.inventoryId' => $inv['_id']
            ]);
        }

        // 5. Auditoría de archivos
        $db->insertOne('file_audit_logs', [
            'userId' => $userId,
            'agentId' => $agentId,
            'hostname' => $hostname,
            'path' => $fileData['path'],
            'user' => $fileData['user'] ?? null,
            'detectedAt' => date('c'),
            'categories' => $categories,
            'sensitive' => $sensitive,
            'rowCount' => (int)($fileData['rowCount'] ?? 0),
            'fileType' => $fileData['fileType'] ?? 'unknown',
            'hash' => $fileData['hash'],
            'status' => 'processed',
        ]);

        // 6. Auditoría general
        $db->insertOne('audit_logs', [
            'userId' => $userId,
            'action' => 'file_detected_by_agent',
            'details' => [
                'agentId' => $agentId,
                'path' => $fileData['path'],
                'user' => $fileData['user'] ?? null,
                'sensitive' => $sensitive,
                'categories' => $categories,
            ],
            'createdAt' => date('c'),
        ]);

        return ['fileId' => $fileId];
    }

    // ─── HANDLER: FILE_DELETED ─────────────────────────────────────

    private function handleFileDeleted(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        $userId  = $from->userId ?? '';
        $path    = $data['path'] ?? '';
        $hash    = $data['hash'] ?? '';

        if (!$agentId || !$userId || !$path) {
            echo "⚠️ file_deleted ignorado: datos incompletos\n";
            return;
        }

        if (!$this->db) {
            echo "⚠️ file_deleted: sin BD disponible\n";
            return;
        }

        $db = $this->db;
        $now = date('c');

        $existing = $db->findOne('compliance_files', [
            'agentId' => $agentId,
            'path'    => $path,
            'userId'  => $userId,
        ]);

        if (!$existing) {
            echo "ℹ️  file_deleted: archivo no encontrado en BD: {$path}\n";
            return;
        }

        $db->updateOne('compliance_files', ['_id' => $existing['_id']], [
            'status'      => 'deleted',
            'deletedAt'   => $now,
            'deletedHash' => $hash,
            'updatedAt'   => $now,
        ]);

        $inventoryId = $existing['analysisResult']['inventoryId'] ?? null;
        if ($inventoryId) {
            $db->updateOne('compliance_inventory', ['_id' => $inventoryId], [
                'active'    => false,
                'deletedAt' => $now,
                'updatedAt' => $now,
            ]);
        }

        $db->insertOne('file_audit_logs', [
            'userId'       => $userId,
            'agentId'      => $agentId,
            'hostname'     => $data['hostname'] ?? 'unknown',
            'path'         => $path,
            'user'         => $data['user'] ?? null,
            'detectedAt'   => $now,
            'categories'   => array_keys($data['personalData'] ?? []),
            'sensitive'    => !empty($data['sensitive']),
            'fileType'     => 'unknown',
            'hash'         => $hash,
            'status'       => 'deleted',
            'eventType'    => 'deleted',
        ]);

        $db->insertOne('audit_logs', [
            'userId'  => $userId,
            'action'  => 'file_deleted_by_agent',
            'details' => [
                'agentId' => $agentId,
                'path'    => $path,
                'hash'    => $hash,
                'user'    => $data['user'] ?? null,
            ],
            'createdAt' => $now,
        ]);

        echo "🗑️  Archivo marcado como eliminado: {$path}\n";
    }
}

// ─── INICIAR SERVIDOR ──────────────────────────────────────────

echo "🚀 Iniciando servidor WebSocket en el puerto 3839...\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            $agentWs = new AgentWebSocket()
        )
    ),
    3839
);

$agentWsRef = $agentWs;
$server->loop->addPeriodicTimer(1.0, function () use ($agentWsRef) {
    $sessions = $agentWsRef->getAgentSessions();
    if (empty($sessions)) return;

    $db = $agentWsRef->getDb();
    if (!$db) return;
    foreach ($sessions as $agentId => $conn) {
        $cmds = $db->find('agent_commands', [
            'agentId' => $agentId,
            'executed' => ['$in' => [false, null]],
        ]);
        if (empty($cmds)) continue;

        $count = 0;
        foreach ($cmds as $cmd) {
            $count++;
            try {
                $conn->send(json_encode([
                    'type' => 'command',
                    'payload' => [
                        'command' => $cmd['command'],
                        'params' => $cmd['params'] ?? [],
                        'commandId' => $cmd['_id'],
                    ]
                ]));
            } catch (\Throwable $e) {
                echo "⚠️ No se pudo enviar PUSH a {$agentId}: " . $e->getMessage() . "\n";
            }
        }
        if ($count > 0) {
            echo "⚡ PUSH: {$count} comandos a {$agentId}\n";
        }
    }
});

$server->run();