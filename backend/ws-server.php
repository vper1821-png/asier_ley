<?php
// backend/ws-server.php
// Servidor WebSocket para comunicación con agentes SecureLab

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

// Incluir funciones de compliance_files para reutilizar lógica
require_once __DIR__ . '/routes/compliance_files.php';

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
        $this->db = Database::getInstance();
        echo "🔌 WebSocket Server iniciado\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "✅ Nueva conexión: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) {
            echo "⚠️ Mensaje inválido\n";
            return;
        }

        $type = $data['type'] ?? '';
        echo "📨 Mensaje recibido: {$type}\n";

        switch ($type) {
            case 'register':
                $this->handleRegister($from, $data);
                break;
            case 'file_detected':
                $this->handleFileDetected($from, $data);
                break;
            case 'telemetry':
                $this->handleTelemetry($from, $data);
                break;
            case 'heartbeat':
                $this->handleHeartbeat($from, $data);
                break;
            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'ts' => microtime(true)]));
                break;
            default:
                echo "⚠️ Tipo de mensaje desconocido: {$type}\n";
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
            echo "🔌 Agente desconectado: {$agentId}\n";
        }
        $this->clients->detach($conn);
        echo "❌ Conexión cerrada: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "🔥 Error: {$e->getMessage()}\n";
        $conn->close();
    }

    private function handleRegister(ConnectionInterface $conn, $data) {
        $token = $data['token'] ?? '';
        $agentId = $data['agentId'] ?? '';

        if (!$token || !$agentId) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Token y agentId requeridos']));
            $conn->close();
            echo "❌ Registro fallido: faltan datos\n";
            return;
        }

        $decoded = Auth::verifyToken($token);
        if (!$decoded) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Token inválido']));
            $conn->close();
            echo "❌ Registro fallido: token inválido\n";
            return;
        }

        $conn->userId = $decoded['userId'];
        $conn->agentId = $agentId;
        $this->agentSessions[$agentId] = $conn;

        echo "✅ Agente registrado: {$agentId} (usuario: {$conn->userId})\n";
        $conn->send(json_encode([
            'type' => 'registered',
            'agentId' => $agentId,
            'message' => 'Agente registrado correctamente'
        ]));
    }

    private function handleFileDetected(ConnectionInterface $from, $data) {
        $fileData = $data['detectedFile'] ?? [];
        if (empty($fileData)) {
            $from->send(json_encode(['type' => 'error', 'message' => 'No se recibió archivo']));
            echo "❌ file_detected: datos vacíos\n";
            return;
        }

        $agentId = $from->agentId ?? '';
        $userId = $from->userId ?? '';

        if (!$agentId || !$userId) {
            $from->send(json_encode(['type' => 'error', 'message' => 'Agente no registrado']));
            echo "❌ file_detected: agente no registrado\n";
            return;
        }

        try {
            // Procesar detección de archivo con auditoría
            $result = $this->processFileDetection($userId, $agentId, $fileData);
            $from->send(json_encode([
                'type' => 'file_response',
                'success' => true,
                'fileId' => $result['fileId'] ?? null,
                'message' => 'Archivo procesado correctamente'
            ]));
            echo "📄 Archivo reportado por agente {$agentId}: " . basename($fileData['path'] ?? '') . "\n";
        } catch (Exception $e) {
            $from->send(json_encode([
                'type' => 'file_response',
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]));
            echo "❌ Error procesando archivo: " . $e->getMessage() . "\n";
        }
    }

    private function handleTelemetry(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? 'unknown';
        echo "📊 Telemetría recibida de {$agentId}\n";
    }

    private function handleHeartbeat(ConnectionInterface $from, $data) {
        $agentId = $from->agentId ?? 'unknown';
        if ($agentId && $agentId !== 'unknown') {
            $this->db->updateOne('agents', ['agentId' => $agentId], [
                'lastSeen' => date('c'),
                'status' => 'online'
            ]);
        }
        $pendingCommands = $this->getPendingCommands($from->agentId);
        if (!empty($pendingCommands)) {
            $from->send(json_encode([
                'type' => 'commands',
                'commands' => $pendingCommands
            ]));
        }
    }

    /**
     * Procesa la detección de archivo y guarda en:
     * - compliance_files
     * - compliance_inventory
     * - file_audit_logs (NUEVA)
     * - audit_logs
     */
    private function processFileDetection($userId, $agentId, $fileData) {
        $db = $this->db;

        // ─── LOG DE ENTRADA ───
        error_log("[WS] processFileDetection: userId=$userId, agentId=$agentId");
        error_log("[WS] fileData recibido: " . json_encode($fileData));

        $required = ['path', 'hash', 'fileType'];
        foreach ($required as $field) {
            if (empty($fileData[$field])) {
                throw new Exception("Campo '$field' requerido");
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
            'user'          => $fileData['user'] ?? null,  // ← AGREGADO
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

        error_log("[WS] compliance_files guardado: fileId=$fileId");

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

        error_log("[WS] inventory guardado");

        // 3. Auditoría de archivos (file_audit_logs)
        $logData = [
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
        ];

        error_log("[WS] Guardando en file_audit_logs: " . json_encode($logData));
        $db->insertOne('file_audit_logs', $logData);

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

    private function getPendingCommands($agentId) {
        // Comandos pendientes (se puede ampliar con base de datos)
        return [];
    }
}

echo "🚀 Iniciando servidor WebSocket en el puerto 3839...\n";
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new AgentWebSocket()
        )
    ),
    3839
);

$server->run();