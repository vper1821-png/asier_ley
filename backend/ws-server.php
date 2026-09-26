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
        $templateHint = $data['template_id'] ?? null;

        if (empty($token) || empty($agentId)) {
            $conn->send(json_encode(['type' => 'error', 'payload' => ['message' => 'Token y agentId requeridos']]));
            $conn->close();
            return;
        }

        $decoded = Auth::verifyToken($token);
        if (!$decoded) {
            $conn->send(json_encode(['type' => 'error', 'payload' => ['message' => 'Token inválido o expirado']]));
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
                $packId = null;
                $templateIds = [];

                if ($templateHint) {
                    $pack = $this->db->findOne('compliance_packs', [
                        '_id' => $templateHint, 'userId' => $userId, 'active' => true,
                    ]);
                    if ($pack) {
                        $packId = (string)$pack['_id'];
                        $tpls = $this->db->find('compliance_templates', ['packId' => $packId, 'active' => true]);
                        $templateIds = array_map(fn($t) => (string)$t['_id'], $tpls);
                    }
                }

                $this->db->insertOne('agents', [
                    'userId'              => $userId,
                    'agentId'             => $agentId,
                    'status'              => 'online',
                    'lastSeen'            => date('c'),
                    'createdAt'           => date('c'),
                    'hostname'            => $data['hostname'] ?? $agentId,
                    'platform'            => $data['platform'] ?? '',
                    'packId'              => $packId,
                    'templateIds'         => $templateIds,
                    'packAssignedAt'      => $packId ? date('c') : null,
                    'packAssignedBy'      => null,
                    'packAssignedByEmail' => $packId ? 'install-hint' : null,
                ]);

                echo "✅ Agente nuevo registrado: {$agentId}" . ($packId ? " con pack {$packId}" : " (sin pack)") . "\n";
            } else {
                $this->db->updateOne('agents', ['agentId' => $agentId, 'userId' => $userId], [
                    'status' => 'online', 'lastSeen' => date('c'),
                ]);
                if ($templateHint && ($existing['packId'] ?? null) !== $templateHint) {
                    echo "ℹ️  Agente {$agentId} envió hint '{$templateHint}' pero BD tiene '{$existing['packId']}'. Ignorando hint.\n";
                }
            }
        }

        echo "✅ Agente registrado: {$agentId} (usuario: {$userId})\n";
        $conn->send(json_encode([
            'type' => 'registered',
            'payload' => ['agentId' => $agentId, 'message' => 'Agente registrado correctamente']
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

    // ─── HANDLER: INVENTORY_ITEM ────────────────────────────────────

    /**
     * Este handler delega a processFileDetection() — el mismo método que
     * usa handleFileDetected. Un solo flujo, un solo lugar para arreglar bugs.
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
            'rowCount'     => (int)($data['rowCount'] ?? $data['scanCount'] ?? 0),
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
            'agentId'     => $agentId,
            'userId'      => $from->userId ?? '',
            'timestamp'   => $data['timestamp'] ?? date('c'),
            'path'        => $data['path'] ?? '',
            'eventType'   => $data['eventType'] ?? 'unknown',
            'process'     => $data['process'] ?? '',
            'pid'         => (int)($data['pid'] ?? 0),
            'user'        => $data['user'] ?? '',
            'hostname'    => $data['hostname'] ?? '',
            'extension'   => $data['extension'] ?? '',
            'size'        => (int)($data['size'] ?? 0),
            'hash'        => $data['hash'] ?? '',
            'rowCount'    => (int)($data['rowCount'] ?? 0),
            'destination' => $data['destination'] ?? '',
            'createdAt'   => date('c'),
        ];
        $this->db->insertOne('file_events', $doc);
    }

    private function handleDBQuery(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;

        $doc = [
            'agentId'   => $agentId,
            'userId'    => $from->userId ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'engine'    => $data['engine'] ?? '',
            'database'  => $data['database'] ?? '',
            'user'      => $data['user'] ?? '',
            'host'      => $data['host'] ?? '',
            'query'     => $data['query'] ?? '',
            'operation' => $data['operation'] ?? 'query',
            'riskScore' => (float)($data['riskScore'] ?? 0),
            'createdAt' => date('c'),
        ];
        $this->db->insertOne('database_logs', $doc);

        if ($doc['riskScore'] >= 0.5) {
            $severity = $doc['riskScore'] >= 0.8 ? 'critical' : 'high';
            $this->db->insertOne('alerts', [
                'agentId'     => $agentId,
                'userId'      => $from->userId ?? '',
                'title'       => 'Consulta riesgosa en ' . $doc['database'],
                'message'     => $doc['query'],
                'severity'    => $severity,
                'source'      => 'db_query',
                'category'    => 'database_access',
                'lawArticle'  => 'Art. 25 Ley 21.719',
                'eventType'   => $doc['operation'] ?? 'query',
                'read'        => false,
                'resolved'    => false,
                'createdAt'   => date('c'),
            ]);
        }
    }

    private function handleHostEvent(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? $data['agentId'] ?? '';
        if (!$agentId || !$this->db) return;

        $doc = [
            'agentId'   => $agentId,
            'userId'    => $from->userId ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'type'      => $data['type'] ?? 'host_event',
            'severity'  => $data['severity'] ?? 'info',
            'title'     => $data['title'] ?? 'Evento del sistema',
            'detail'    => $data['detail'] ?? '',
            'source'    => $data['source'] ?? 'agent',
            'createdAt' => date('c'),
        ];

        $this->db->insertOne('alerts', [
            'userId'      => $from->userId ?? '',
            'agentId'     => $agentId,
            'title'       => $doc['title'],
            'message'     => $doc['detail'],
            'severity'    => $doc['severity'],
            'source'      => $doc['source'],
            'category'    => 'security_monitoring',
            'lawArticle'  => 'Art. 25 Ley 21.719',
            'eventType'   => $doc['type'],
            'read'        => false,
            'resolved'    => false,
            'createdAt'   => $doc['createdAt'],
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
            'userId'      => $from->userId ?? '',
            'agentId'     => $agentId,
            'hostname'    => $data['hostname'] ?? $agentId,
            'cpu'         => (float)($data['cpu'] ?? 0),
            'ram'         => (float)($data['memory'] ?? 0),
            'disk'        => max(0, min(100, $diskPct)),
            'diskFree'    => $diskFree,
            'diskTotal'   => $diskTotal,
            'diskUsed'    => max(0, $diskTotal - $diskFree),
            'processes'   => (int)($data['processes'] ?? 0),
            'connections' => (int)($data['connections'] ?? 0),
            'platform'    => $data['platform'] ?? '',
            'arch'        => $data['arch'] ?? '',
            'os'          => $data['os'] ?? '',
            'user'        => $data['user'] ?? '',
            'uptime'      => (int)($data['uptime'] ?? 0),
            'status'      => 'online',
            'lastSeen'    => date('c'),
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
            'userId'      => $from->userId ?? '',
            'agentId'     => $agentId,
            'title'       => $data['title'] ?? 'Evento del agente',
            'message'     => $data['description'] ?? '',
            'severity'    => $data['severity'] ?? 'medium',
            'source'      => $data['source'] ?? 'agent',
            'category'    => 'security_monitoring',
            'lawArticle'  => 'Art. 25 Ley 21.719',
            'eventType'   => 'generic',
            'read'        => false,
            'resolved'    => false,
            'createdAt'   => date('c'),
        ]);
    }

    // ─── DATA RESPONSE ──────────────────────────────────────────────

    private function handleDataResponse(ConnectionInterface $conn, $data) {
        $agentId = $conn->agentId ?? $data['agentId'] ?? '';
        $type = $data['type'] ?? '';
        if (!$agentId || !$type || !$this->db) return;

        $this->db->insertOne('agent_data', [
            'agentId'   => $agentId,
            'type'      => $type,
            'data'      => $data['data'] ?? null,
            'ts'        => (int)($data['ts'] ?? time()),
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
                'command'   => $cmd['command'],
                'params'    => $cmd['params'] ?? [],
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
            $engine   = $dbConn['engine'] ?? $dbConn['type'] ?? '';
            $host     = $dbConn['host'] ?? '';
            $port     = (int)($dbConn['port'] ?? 0);
            $database = $dbConn['database'] ?? '';
            $username = $dbConn['username'] ?? $dbConn['user'] ?? '';
            $password = $dbConn['password'] ?? '';
            $ssl      = (bool)($dbConn['ssl'] ?? false);

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
                'lockdown'        => $lockdown,
                'pendingCommands' => $pending,
                'connections'     => $connections,
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
                    'executed'   => true,
                    'executedAt' => date('c'),
                    'result'     => $result,
                    'status'     => $status,
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
                        'command'   => $cmd['command'],
                        'params'    => $cmd['params'] ?? [],
                        'commandId' => $cmd['_id'],
                    ]
                ]));
            } catch (\Throwable $e) {
                echo "⚠️ No se pudo enviar comando a {$agentId}: " . $e->getMessage() . "\n";
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PROCESAMIENTO DE DETECCIÓN DE ARCHIVO
    // ═══════════════════════════════════════════════════════════════
    //
    // Este método es el ÚNICO lugar que crea/actualiza el inventario.
    // Tanto handleFileDetected como handleInventoryItem lo llaman.
    //
    private function processFileDetection($userId, $agentId, $fileData) {
        if (!$this->db) throw new \Exception('Base de datos no disponible');
        $db = $this->db;

        $path = $fileData['path'] ?? '';
        $hash = $fileData['hash'] ?? '';
        if (!$path || !$hash) throw new \Exception('path y hash requeridos');

        $hostname   = $fileData['hostname'] ?? 'unknown';
        $extension  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $sensitive  = !empty($fileData['sensitive']);
        $personalData = $fileData['personalData'] ?? [];
        $rowCount   = (int)($fileData['rowCount'] ?? 0);

        // 1. Guardar archivo
        $existing = $db->findOne('compliance_files', [
            'agentId' => $agentId, 'path' => $path, 'sourceType' => 'agent'
        ]);

        $fileDoc = [
            'userId' => $userId, 'sourceType' => 'agent', 'agentId' => $agentId,
            'hostname' => $hostname, 'path' => $path,
            'originalName' => basename($path), 'ext' => $extension,
            'size' => (int)($fileData['size'] ?? 0), 'hash' => $hash,
            'status' => 'analyzed',
            'user' => $fileData['user'] ?? null,
            'analysisResult' => [
                'rowCount' => $rowCount, 'headers' => array_keys($personalData),
                'patterns' => $personalData, 'sensitive' => $sensitive,
                'analyzedAt' => date('c'), 'analyzedBy' => 'agent',
            ],
            'updatedAt' => date('c'),
        ];

        if ($existing) {
            $db->updateOne('compliance_files', ['_id' => $existing['_id']], $fileDoc);
            $fileId = $existing['_id'];
        } else {
            $fileDoc['createdAt'] = date('c');
            $fileId = $db->insertOne('compliance_files', $fileDoc)['_id'];
        }

        // 2. Categorías
        $categories = $this->extractCategories($personalData);

        // 3. Resolver plantilla (motor 3 capas)
        $resolved = $this->resolveTemplateForFile($userId, $agentId, [
            'path' => $path, 'hostname' => $hostname, 'extension' => $extension,
            'categories' => $categories, 'sensitive' => $sensitive,
        ]);

        $templateId = $resolved['templateId'];

        // 4. Buscar actividad existente (agregación)
        $activity = null;
        if ($templateId) {
            $activity = $db->findOne('compliance_inventory', [
                'userId' => $userId, 'agentId' => $agentId, 'templateApplied' => $templateId,
            ]);
        }

        if ($activity) {
            // AGREGAR
            $sources = $activity['sources'] ?? [];
            if (empty($sources) && !empty($activity['path'])) {
                $sources = [[
                    'fileId' => $activity['sourceId'] ?? '',
                    'path' => $activity['path'],
                    'records' => (int)($activity['records'] ?? 0),
                    'detectedAt' => $activity['createdAt'] ?? date('c'),
                ]];
            }

            $already = false;
            foreach ($sources as $s) {
                if (($s['fileId'] ?? '') === (string)$fileId) { $already = true; break; }
            }
            if (!$already) {
                $sources[] = [
                    'fileId' => (string)$fileId, 'path' => $path,
                    'records' => $rowCount, 'detectedAt' => date('c'), 'hash' => $hash,
                ];
            }

            $existingCats = is_array($activity['dataCategories'] ?? null)
                ? $activity['dataCategories']
                : array_filter(array_map('trim', explode(',', (string)($activity['dataCategories'] ?? ''))));
            $mergedCats = array_values(array_unique(array_filter(array_merge($existingCats, $categories))));

            $exts = $activity['fileExtensions'] ?? [];
            if (!in_array($extension, $exts)) $exts[] = $extension;

            $db->updateOne('compliance_inventory', ['_id' => $activity['_id']], [
                'sources' => $sources,
                'fileCount' => count($sources),
                'recordCount' => (int)($activity['recordCount'] ?? $activity['records'] ?? 0) + $rowCount,
                'dataCategories' => implode(', ', $mergedCats),
                'fileExtensions' => $exts,
                'sensitive' => !empty($activity['sensitive']) || $sensitive,
                'lastSeenAt' => date('c'), 'updatedAt' => date('c'),
            ]);

            $db->updateOne('compliance_files', ['_id' => $fileId], [
                'analysisResult.inventoryId' => (string)$activity['_id'],
            ]);
        } else {
            // CREAR nueva
            $defaults = $resolved['defaults'] ?? [];

            $inventoryDoc = array_merge([
                'userId' => $userId, 'agentId' => $agentId, 'hostname' => $hostname,
                'path' => $path, 'extension' => $extension,
                'name' => $resolved['templateName'] ?: ('📄 ' . basename($path)),
                'sourceType' => 'agent', 'sourceId' => (string)$fileId,
                'sources' => [[
                    'fileId' => (string)$fileId, 'path' => $path,
                    'records' => $rowCount, 'detectedAt' => date('c'), 'hash' => $hash,
                ]],
                'fileCount' => 1, 'recordCount' => $rowCount, 'records' => $rowCount,
                'fileExtensions' => [$extension],
                'dataCategories' => implode(', ', $categories),
                'sensitive' => $sensitive, 'active' => true,
                'storage' => $hostname, 'user' => $fileData['user'] ?? null,
                'firstSeenAt' => date('c'), 'lastSeenAt' => date('c'),
                'templateApplied' => $templateId, 'templateName' => $resolved['templateName'],
                'templateMode' => $resolved['mode'], 'templateAppliedAt' => date('c'),
                'needsReview' => ($resolved['mode'] !== null),
                'createdAt' => date('c'), 'updatedAt' => date('c'),
            ], $defaults);

            $inv = $db->insertOne('compliance_inventory', $inventoryDoc);
            $db->updateOne('compliance_files', ['_id' => $fileId], [
                'analysisResult.inventoryId' => (string)$inv['_id'],
            ]);
        }

        // 5. Auditoría
        $db->insertOne('file_audit_logs', [
            'userId' => $userId, 'agentId' => $agentId, 'hostname' => $hostname,
            'path' => $path, 'user' => $fileData['user'] ?? null,
            'detectedAt' => date('c'), 'categories' => $categories,
            'sensitive' => $sensitive, 'rowCount' => $rowCount,
            'fileType' => $fileData['fileType'] ?? $extension,
            'hash' => $hash, 'status' => 'processed',
            'templateApplied' => $templateId, 'templateMode' => $resolved['mode'],
        ]);

        $db->insertOne('audit_logs', [
            'userId' => $userId, 'action' => 'file_detected_by_agent',
            'details' => [
                'agentId' => $agentId, 'path' => $path,
                'template' => $resolved['templateName'], 'mode' => $resolved['mode'],
                'sensitive' => $sensitive, 'categories' => $categories,
            ],
            'createdAt' => date('c'),
        ]);

        return ['fileId' => $fileId];
    }


        // ═══════════════════════════════════════════════════════════════
    // MOTOR DE RESOLUCIÓN DE PLANTILLA (3 capas + learning)
    // ═══════════════════════════════════════════════════════════════

    private function resolveTemplateForFile($userId, $agentId, $ctx) {
        if (!$this->db) return ['defaults' => [], 'mode' => null, 'templateId' => null];
        $db = $this->db;

        // CAPA 0: Learning cache
        $parts = preg_split('#[\\\\/]+#', $ctx['path'] ?? '');
        $topPath = strtolower($parts[1] ?? '');
        $subPath = strtolower($parts[2] ?? '');
        $catSig = implode('|', array_map('strtolower', $ctx['categories'] ?? []));
        $signature = $topPath . '/' . $subPath . '::' . $catSig;

        $learned = $db->findOne('compliance_cluster_learning', [
            'userId' => $userId, 'agentId' => $agentId, 'signature' => $signature,
        ]);
        if ($learned && !empty($learned['templateId'])) {
            $tpl = $db->findOne('compliance_templates', ['_id' => $learned['templateId'], 'active' => true]);
            if ($tpl) {
                $db->updateOne('compliance_cluster_learning', ['_id' => $learned['_id']], [
                    'hitCount' => ((int)($learned['hitCount'] ?? 0)) + 1,
                    'lastSeenAt' => date('c'),
                ]);
                return [
                    'defaults' => $tpl['defaults'] ?? [], 'templateId' => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '', 'mode' => 'learning',
                ];
            }
        }

        // CAPA 1: Plantillas del agente
        $agent = $db->findOne('agents', ['agentId' => $agentId, 'userId' => $userId]);
        $agentTplIds = $agent['templateIds'] ?? [];

        $templates = [];
        foreach ($agentTplIds as $tid) {
            $t = $db->findOne('compliance_templates', ['_id' => $tid, 'active' => true]);
            if ($t) $templates[] = $t;
        }
        usort($templates, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        foreach ($templates as $tpl) {
            if (!empty($tpl['isFallback'])) continue;
            if ($this->matchTemplateRulesWs($tpl, $ctx)) {
                return [
                    'defaults' => $tpl['defaults'] ?? [], 'templateId' => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '', 'mode' => 'agent',
                ];
            }
        }
        foreach ($templates as $tpl) {
            if (!empty($tpl['isFallback'])) {
                return [
                    'defaults' => $tpl['defaults'] ?? [], 'templateId' => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '', 'mode' => 'agent_fallback',
                ];
            }
        }

        // CAPA 2: Plantillas globales
        $globalTpls = $db->find('compliance_templates', [
            'userId' => $userId, 'active' => true, 'isGlobal' => true,
        ]);
        usort($globalTpls, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
        foreach ($globalTpls as $tpl) {
            if ($this->matchTemplateRulesWs($tpl, $ctx)) {
                return [
                    'defaults' => $tpl['defaults'] ?? [], 'templateId' => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '', 'mode' => 'global',
                ];
            }
        }

        // CAPA 3: Inferencia
        return [
            'defaults' => $this->inferDefaults($ctx), 'templateId' => null,
            'templateName' => 'auto-inferencia', 'mode' => 'inference',
        ];
    }

    private function matchTemplateRulesWs($tpl, $ctx) {
        $rules = $tpl['matchRules'] ?? [];
        if (empty($rules)) return false;
        $logic = $tpl['matchLogic'] ?? 'OR';
        $results = [];
        foreach ($rules as $r) {
            $type = $r['type'] ?? ''; $value = $r['value'] ?? ''; $ok = false;
            if ($type === 'path')          $ok = fnmatch($value, $ctx['path'] ?? '', FNM_CASEFOLD);
            elseif ($type === 'hostname')  $ok = fnmatch($value, $ctx['hostname'] ?? '', FNM_CASEFOLD);
            elseif ($type === 'extension') {
                $exts = array_map('trim', explode(',', strtolower($value)));
                $ok = in_array(strtolower($ctx['extension'] ?? ''), $exts, true);
            } elseif ($type === 'category') {
                $want = array_map('trim', explode(',', strtolower($value)));
                $have = array_map('strtolower', $ctx['categories'] ?? []);
                $ok = !empty(array_intersect($want, $have));
            }
            $results[] = $ok;
        }
        return $logic === 'AND' ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    private function extractCategories($personalData) {
        $cats = [];
        foreach ($personalData as $col => $types) {
            if (is_array($types)) $cats = array_merge($cats, $types);
            elseif (is_string($types)) $cats[] = $types;
        }
        return array_values(array_unique(array_filter($cats)));
    }

    private function inferDefaults($ctx) {
        $path = strtolower($ctx['path'] ?? '');
        $cats = array_map('strtolower', $ctx['categories'] ?? []);
        $sens = !empty($ctx['sensitive']);

        $purpose = null;
        if (preg_match('/(cliente|crm|venta|factur|invoice|pedido)/', $path))         $purpose = 'gestion_clientes';
        elseif (preg_match('/(nomina|emplead|rrhh|payroll|personal)/', $path))       $purpose = 'gestion_personal';
        elseif (preg_match('/(marketing|campaign|newsletter|promo)/', $path))        $purpose = 'marketing';
        elseif (preg_match('/(paciente|salud|clinic|medic|ficha)/', $path))          $purpose = 'gestion_pacientes';
        elseif (preg_match('/(contab|balance|libro|financ|tesor)/', $path))          $purpose = 'gestion_financiera';
        elseif (preg_match('/(proveedor|compra|orden_compra)/', $path))              $purpose = 'gestion_proveedores';

        $subjects = [];
        if (array_intersect($cats, ['identificacion','contacto','financieros'])) $subjects[] = 'clientes';
        if (array_intersect($cats, ['laborales','financieros']))                 $subjects[] = 'empleados';
        if (in_array('salud', $cats, true))                                      $subjects[] = 'pacientes';
        if (in_array('ninos', $cats, true))                                      $subjects[] = 'ninos';
        if (empty($subjects)) $subjects = ['publico_general'];

        $risk = 'low';
        if ($sens) $risk = 'high';
        if ($sens && in_array('ninos', $cats, true)) $risk = 'critical';
        if (array_intersect($cats, ['biometricos','geneticos','salud'])) $risk = 'critical';

        $retention = 1825;
        if (in_array('financieros', $cats, true)) $retention = 2190;
        if (in_array('salud', $cats, true))       $retention = 3650;
        if (in_array('ninos', $cats, true))       $retention = 3650;

        return [
            'purpose' => $purpose, 'subjectCategories' => $subjects, 'risk' => $risk,
            'retentionDays' => $retention, 'legalBasis' => 'Pendiente de definir',
            'recipients' => ['no_se_comunica'], 'treatmentFrequency' => 'ocasional',
            'accessControl' => 'interno_solo',
            'notes' => 'Auto-generado por inferencia. Requiere revisión del DPO.',
        ];
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

        $db  = $this->db;
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

        // Extension real del evento (o derivada del path como fallback)
        $extension = $data['extension'] ?? strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $db->insertOne('file_audit_logs', [
            'userId'     => $userId,
            'agentId'    => $agentId,
            'hostname'   => $data['hostname'] ?? 'unknown',
            'path'       => $path,
            'user'       => $data['user'] ?? null,
            'detectedAt' => $now,
            'categories' => array_keys($data['personalData'] ?? []),
            'sensitive'  => !empty($data['sensitive']),
            'fileType'   => $extension,
            'hash'       => $hash,
            'status'     => 'deleted',
            'eventType'  => 'deleted',
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

// ─── PUSH DIRECTO: Polling MongoDB cada 1s para comandos pendientes ───
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
                        'command'   => $cmd['command'],
                        'params'    => $cmd['params'] ?? [],
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