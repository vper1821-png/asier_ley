<?php
// SecureLab - DB Logs API
// Recibe logs de consultas de MongoDB desde el agente

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Auth.php';

header('Content-Type: application/json');

// Verificar autenticación
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Missing token']);
    exit;
}

// Validar token
try {
    $payload = JWT::decode($token, JWT_SECRET, ['HS256']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Invalid token']);
    exit;
}

$db = Database::getInstance();

// POST /api/db-logs - Recibir logs del agente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['logs']) || !is_array($input['logs'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input - logs array required']);
        exit;
    }

    $logs = $input['logs'];
    $savedCount = 0;

    foreach ($logs as $logEntry) {
        // Validar campos requeridos
        if (!isset($logEntry['timestamp']) || !isset($logEntry['operation']) || !isset($logEntry['collection'])) {
            continue;
        }

        // Preparar documento para guardar
        $document = [
            'timestamp' => $logEntry['timestamp'],
            'operation' => $logEntry['operation'],
            'collection' => $logEntry['collection'],
            'database' => $logEntry['database'] ?? 'invisia',
            'query' => $logEntry['query'] ?? [],
            'update' => $logEntry['update'] ?? [],
            'document' => $logEntry['document'] ?? [],
            'duration_ms' => $logEntry['duration'] ?? 0,
            'success' => $logEntry['success'] ?? true,
            'error' => $logEntry['error'] ?? null,
            'user' => $logEntry['user'] ?? 'agent',
            'connection_id' => $logEntry['connection_id'] ?? null,
            'agent_id' => $logEntry['agent_id'] ?? null,
            'received_at' => date('c'),
        ];

        // Guardar en base de datos
        $db->insertOne('db_logs', $document);
        $savedCount++;
    }

    echo json_encode([
        'success' => true,
        'saved' => $savedCount,
        'received' => count($logs)
    ]);
    exit;
}

// GET /api/db-logs - Listar logs (con filtros)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $filter = [];
    $options = ['limit' => 100];

    // Filtro por operación
    if (isset($_GET['operation'])) {
        $filter['operation'] = $_GET['operation'];
    }

    // Filtro por colección
    if (isset($_GET['collection'])) {
        $filter['collection'] = $_GET['collection'];
    }

    // Filtro por usuario
    if (isset($_GET['user'])) {
        $filter['user'] = $_GET['user'];
    }

    // Filtro por éxito/error
    if (isset($_GET['success'])) {
        $filter['success'] = $_GET['success'] === 'true';
    }

    // Rango de fechas
    if (isset($_GET['date_from'])) {
        $filter['timestamp'] = ['$gte' => $_GET['date_from']];
    }
    if (isset($_GET['date_to'])) {
        if (!isset($filter['timestamp'])) {
            $filter['timestamp'] = [];
        }
        $filter['timestamp']['$lte'] = $_GET['date_to'] . 'T23:59:59';
    }

    // Limit
    if (isset($_GET['limit'])) {
        $options['limit'] = (int)$_GET['limit'];
    }

    $logs = $db->find('db_logs', $filter, $options);

    // Estadísticas
    $totalLogs = $db->count('db_logs', $filter);
    $errorLogs = $db->count('db_logs', array_merge($filter, ['success' => false]));
    $avgDuration = 0;

    if (count($logs) > 0) {
        $totalDuration = array_sum(array_column($logs, 'duration_ms'));
        $avgDuration = round($totalDuration / count($logs), 2);
    }

    echo json_encode([
        'logs' => $logs,
        'total' => $totalLogs,
        'errors' => $errorLogs,
        'avg_duration_ms' => $avgDuration
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// DELETE /api/db-logs - Eliminar logs antiguos
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // Solo permitir eliminar logs anteriores a una fecha
    $daysToKeep = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $cutoffDate = date('Y-m-d', strtotime("-$daysToKeep days"));

    $result = $db->deleteMany('db_logs', [
        'timestamp' => ['$lt' => $cutoffDate]
    ]);

    echo json_encode([
        'success' => true,
        'deleted' => $result->getDeletedCount()
    ]);
    exit;
}

// Método no permitido
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);