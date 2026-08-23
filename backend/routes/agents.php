<?php
// Agent routes

function isSuperAdminUser($u) {
    return ($u['role'] ?? '') === 'superadmin';
}

function findAgentFor($user, $agentId) {
    $db = Database::getInstance();
    // Buscar por agentId o _id (para compatibilidad)
    $agent = $db->findOne('agents', ['$or' => [
        ['agentId' => $agentId],
        ['_id' => $agentId]
    ]]);
    if (!$agent || (($agent['userId'] ?? '') !== $user['_id'] && !isSuperAdminUser($user))) {
        return null;
    }
    return $agent;
}

function register() {
    $body = get_body();
    $token = $body['token'] ?? '';
    $agentId = $body['agentId'] ?? '';
    $hostname = $body['hostname'] ?? '';
    $platform = $body['platform'] ?? '';
    $arch = $body['arch'] ?? '';
    $ip = $body['ip'] ?? '';
    $version = $body['version'] ?? '';
    $user = $body['user'] ?? '';

    $decoded = Auth::verifyToken($token);
    if (!$decoded) json_error('token inválido', 401);

    if (!$hostname) json_error('hostname requerido');

    $db = Database::getInstance();

    // Si el agente no envió un ID, generamos uno provisional. El backend tratará
    // de reutilizar el agente existente del mismo hostname para evitar duplicados.
    if (!$agentId) {
        $agentId = 'AGT-' . strtoupper(bin2hex(random_bytes(10)));
    }

    $userId = $decoded['userId'];
    $now = date('c');

    // Buscar si ya existe un agente para este mismo hostname en la misma cuenta.
    // Se considera plataforma para evitar colisiones entre SO distintos con mismo nombre.
    $allSame = $db->find('agents', [
        'userId' => $userId,
        'hostname' => $hostname,
        'platform' => $platform,
    ]);

    $existing = null;
    if (!empty($allSame)) {
        // Elegir el más reciente como el "oficial"
        usort($allSame, fn($a, $b) => strcmp($b['lastSeen'] ?? $b['createdAt'] ?? '', $a['lastSeen'] ?? $a['createdAt'] ?? ''));
        $existing = $allSame[0];
    }

    if ($existing) {
        $keptAgentId = $existing['agentId'];
        $db->updateOne('agents', ['_id' => $existing['_id']], [
            'agentId' => $keptAgentId,
            'hostname' => $hostname,
            'platform' => $platform,
            'arch' => $arch,
            'ip' => $ip,
            'version' => $version,
            'user' => $user,
            'status' => 'online',
            'lastSeen' => $now,
        ]);

        // Limpiar duplicados antiguos del mismo equipo para que el panel no se llene
        foreach ($allSame as $dup) {
            if (($dup['_id'] ?? '') !== ($existing['_id'] ?? '')) {
                $db->deleteOne('agents', ['_id' => $dup['_id']]);
                $db->deleteOne('host_monitor', ['agentId' => $dup['agentId']]);
            }
        }

        $agent = $db->findOne('agents', ['_id' => $existing['_id']]);
        $agentId = $keptAgentId;
    } else {
        $agent = $db->insertOne('agents', [
            'userId' => $userId,
            'agentId' => $agentId,
            'hostname' => $hostname,
            'platform' => $platform,
            'arch' => $arch,
            'ip' => $ip,
            'version' => $version,
            'user' => $user,
            'status' => 'online',
            'lastSeen' => $now,
        ]);
        audit_log('agent_registered', ['agentId' => $agentId, 'hostname' => $hostname, 'platform' => $platform, 'ip' => $ip], $userId, $agentId);
    }

    json_response([
        'agentId' => $agentId,
        'agent' => ['_id' => $agent['_id'] ?? ''],
    ]);
}

function heartbeat() {
    $agentId = $_GET['agentId'] ?? '';
    $body = get_body();
    $token = $body['token'] ?? '';

    $decoded = Auth::verifyToken($token);
    if (!$decoded) json_error('token inválido', 401);

    $db = Database::getInstance();
    $agent = $db->findOne('agents', ['agentId' => $agentId, 'userId' => $decoded['userId']]);
    if (!$agent) json_error('agente no encontrado', 404);

    $metrics = $body['metrics'] ?? [];
    $status = $body['status'] ?? [];

    // Update host monitor data
    $hostData = [
        'userId' => $decoded['userId'],
        'agentId' => $agentId,
        'hostname' => $agent['hostname'] ?? $agentId,
        'cpu' => $metrics['cpu'] ?? 0,
        'ram' => $metrics['memory'] ?? 0,
        'disk' => $metrics['disk'] ?? 0,
        'load' => $metrics['load'] ?? 0,
        'uptime' => $metrics['uptime'] ?? 0,
        'users' => $metrics['users'] ?? 0,
        'status' => 'online',
        'lastSeen' => date('c'),
    ];

    $existingHost = $db->findOne('host_monitor', ['agentId' => $agentId]);
    if ($existingHost) {
        $db->updateOne('host_monitor', ['agentId' => $agentId], $hostData);
    } else {
        $db->insertOne('host_monitor', $hostData);
    }

    // Update agent status
    $db->updateOne('agents', ['agentId' => $agentId], [
        'status' => 'online',
        'lastSeen' => date('c'),
        'metrics' => $metrics,
        'systemStatus' => $status,
    ]);

    // Process events from agent and create alerts
    $events = $body['events'] ?? [];
    foreach ($events as $event) {
        $db->insertOne('alerts', [
            'userId' => $decoded['userId'],
            'agentId' => $agentId,
            'title' => $event['title'] ?? 'Alerta de agente',
            'message' => $event['description'] ?? '',
            'source' => $event['source'] ?? 'agent',
            'severity' => $event['severity'] ?? 'low',
            'autoBlock' => $event['autoBlock'] ?? false,
        ]);
    }

    json_response([
        'error' => '',
        'pendingRules' => [],
        'pendingBlocks' => [],
        'pendingUnblocks' => [],
        'syncBlocked' => [],
        'pendingCommands' => [],
        'heartbeatInterval' => 5,
    ]);
}

function listAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    if (isSuperAdminUser($user)) {
        $agents = $db->find('agents', []);
        $ownerMap = [];
        foreach ($db->find('users', []) as $u) {
            $ownerMap[$u['_id']] = [
                'email' => $u['email'] ?? '',
                'companyName' => $u['companyName'] ?? '',
                'isActive' => $u['isActive'] ?? true,
                'role' => $u['role'] ?? 'user',
            ];
        }
        foreach ($agents as &$a) {
            $o = $ownerMap[$a['userId'] ?? ''] ?? null;
            $a['companyEmail'] = $o['email'] ?? '';
            $a['companyName'] = $o['companyName'] ?? '';
            $a['companyActive'] = $o['isActive'] ?? true;
        }
        unset($a);
        json_response($agents);
    }
    $agents = $db->find('agents', ['userId' => $user['_id']]);
    json_response($agents);
}

function combined() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $agents = $db->find('agents', ['userId' => $user['_id']]);
    $hosts = $db->find('host_monitor', ['userId' => $user['_id']]);
    $hostsByAgent = [];
    foreach ($hosts as $h) {
        if (!empty($h['agentId'])) $hostsByAgent[$h['agentId']] = $h;
    }
    $combined = [];
    foreach ($agents as $a) {
        $aid = $a['agentId'] ?? $a['_id'] ?? '';
        $combined[] = ['agent' => $a, 'host' => $hostsByAgent[$aid] ?? []];
    }
    json_response($combined);
}

function deleteAgent() {
    $user = Auth::requireAuth();
    $body = get_body();
    $id = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['id'] ?? $_POST['agentId'] ?? '';
    if (!$id) json_error('agentId requerido');

    $db = Database::getInstance();
    
    // Buscar por agentId o _id
    $agent = $db->findOne('agents', ['$or' => [
        ['agentId' => $id],
        ['_id' => $id]
    ]]);
    
    if (!$agent) json_error('agente no encontrado', 404);

    $db->deleteOne('agents', ['_id' => $agent['_id']]);
    $db->deleteOne('host_monitor', ['agentId' => $agent['agentId'] ?? $id]);
    audit_log('agent_deleted', ['agentId' => $agent['agentId'] ?? $id, 'hostname' => $agent['hostname'] ?? ''], $agent['userId'] ?? null, $agent['agentId'] ?? $id);
    json_response(['success' => true]);
}

function updateAgent() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['id'] ?? $_POST['agentId'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $db = Database::getInstance();
    $updates = [];
    if (array_key_exists('name', $body)) $updates['name'] = trim($body['name']);
    if (array_key_exists('pinned', $body)) $updates['pinned'] = filter_var($body['pinned'], FILTER_VALIDATE_BOOLEAN);
    if (array_key_exists('group', $body)) $updates['group'] = trim($body['group']);
    if ($updates) {
        $db->updateOne('agents', ['_id' => $agent['_id']], $updates);
    }
    json_response(['success' => true]);
}

function sendCommand() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['id'] ?? $_POST['agentId'] ?? '';
    $command = $body['command'] ?? '';
    $params = $body['params'] ?? '';

    if (!$agentId) json_error('agentId requerido');
    if (!$command) json_error('command requerido');
    if (is_string($command)) $command = json_decode($command, true) ?? $command;
    if (is_array($command)) {
        $params = $command['params'] ?? $command;
        unset($params['command']);
        $command = $command['command'] ?? '';
    }
    if (!$command) json_error('command requerido');
    if (is_string($params)) $params = json_decode($params, true) ?? [];

    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);

    $cmd = $db->insertOne('agent_commands', [
        'userId' => $agent['userId'] ?? $user['_id'],
        'agentId' => $agentId,
        'command' => $command,
        'params' => is_array($params) ? $params : [],
        'createdAt' => date('c'),
        'executed' => false,
    ]);
    audit_log('agent_command', ['agentId' => $agentId, 'hostname' => $agent['hostname'] ?? '', 'command' => $command, 'params' => $params], $agent['userId'] ?? null, $agentId);

    json_response(['success' => true, 'commandId' => $cmd['_id']]);
}

function requestData() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['agentId'] ?? $_POST['id'] ?? '';
    $type = $_GET['type'] ?? $body['type'] ?? 'processes';

    if (!$agentId) json_error('agentId requerido');
    
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);

    // Crear comando de solicitud de datos
    $cmd = $db->insertOne('agent_commands', [
        'userId' => $agent['userId'] ?? $user['_id'],
        'agentId' => $agentId,
        'command' => 'request_data',
        'params' => ['type' => $type],
        'createdAt' => date('c'),
        'executed' => false,
    ]);

    json_response(['success' => true, 'commandId' => $cmd['_id']]);
}

function downloadToken() {
    $user = Auth::requireAuth();
    $dlToken = Auth::createToken($user['_id'], ['email' => $user['email'] ?? '', 'purpose' => 'agent_download']);
    json_response(['token' => $dlToken]);
}

// Create a deploy record for the current user
function createDeploy() {
    $user = Auth::requireAuth();
    $body = get_body();
    $platform = $body['platform'] ?? 'win-x64';
    $userAgent = $body['userAgent'] ?? '';

    $db = Database::getInstance();
    $deploy = [
        'userId' => $user['_id'],
        'platform' => $platform,
        'userAgent' => $userAgent,
        'createdAt' => date('c'),
        'status' => 'pending_download',
        'downloadCount' => 0,
    ];
    $deployId = $db->insertOne('agent_deploys', $deploy);
    audit_log('agent_deploy_created', ['deployId' => (string)$deployId, 'platform' => $platform], $user['_id']);
    json_response(['success' => true, 'deployId' => (string)$deployId]);
}

function autoRegister() {
    $body = get_body();
    $hostname = $body['hostname'] ?? 'unknown';
    $platform = $body['platform'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $db = Database::getInstance();
    
    // Buscar si ya existe un agente con este hostname
    $existingAgent = $db->findOne('agents', ['hostname' => $hostname]);
    
    if ($existingAgent) {
        // Si ya existe, generar un nuevo token
        $token = Auth::createToken($existingAgent['userId'], [
            'agentId' => $existingAgent['agentId'] ?? $existingAgent['_id'],
            'hostname' => $hostname,
            'platform' => $platform
        ]);
        
        json_response([
            'success' => true,
            'token' => $token,
            'agentId' => $existingAgent['agentId'] ?? $existingAgent['_id'],
            'message' => 'Agente ya registrado, token actualizado'
        ]);
    }
    
    // Si no existe, crear un nuevo agente
    $agent = [
        'hostname' => $hostname,
        'platform' => $platform,
        'ip' => $ip,
        'status' => 'pending',
        'lastSeen' => date('c'),
        'createdAt' => date('c'),
        'agentId' => generateAgentId(),
        'version' => '2.0.0'
    ];
    
    $agentId = $db->insertOne('agents', $agent);
    
    // Generar token para el nuevo agente
    $token = Auth::createToken($agent['userId'] ?? null, [
        'agentId' => (string)$agentId,
        'hostname' => $hostname,
        'platform' => $platform
    ]);
    
    audit_log('agent_auto_registered', [
        'agentId' => (string)$agentId,
        'hostname' => $hostname,
        'platform' => $platform,
        'ip' => $ip
    ]);
    
    json_response([
        'success' => true,
        'token' => $token,
        'agentId' => (string)$agentId,
        'message' => 'Agente registrado exitosamente'
    ]);
}

function generateAgentId() {
    return 'agent_' . bin2hex(random_bytes(8));
}

function getAgentData() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $body['agentId'] ?? '';
    $type = $_GET['type'] ?? $body['type'] ?? '';
    if (!$agentId || !$type) json_error('agentId y type requeridos');
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $recs = $db->find('agent_data', ['agentId' => $agentId, 'type' => $type]);
    if (empty($recs)) json_response(['success' => true, 'data' => null, 'ts' => 0]);
    usort($recs, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    $latest = $recs[0];
    json_response(['success' => true, 'data' => $latest['data'] ?? null, 'ts' => $latest['ts'] ?? 0]);
}

function getAgent() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['agentId'] ?? $_POST['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    json_response(['success' => true, 'agent' => $agent]);
}

function getAgentLogs() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? '';
    $lines = (int)($_GET['lines'] ?? $body['lines'] ?? 100);
    if (!$agentId) json_error('agentId requerido');
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    
    // Try to read log file from agent's perspective
    $logPaths = [
        '/var/www/asier_ley-main/backend/installer/agent.log',
        '/var/www/asier_ley-main/backend/securelab-agent/agent.log',
        '/var/log/securelab-agent/agent.log',
        '/opt/securelab-agent/logs/agent.log',
        'C:\Program Files\SecureLab Agent\logs\agent.log',
        'C:\Program Files (x86)\SecureLab\SecureLab Agent\logs\agent.log',
    ];
    
    $logContent = '';
    foreach ($logPaths as $path) {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            if ($content) {
                $logContent = $content;
                break;
            }
        }
    }
    
    if (!$logContent) {
        json_response(['success' => true, 'logs' => 'No log file found', 'agentId' => $agentId]);
        return;
    }
    
    $logLines = explode("\n", $logContent);
    $logLines = array_slice($logLines, -$lines);
    json_response(['success' => true, 'logs' => implode("\n", $logLines), 'agentId' => $agentId, 'totalLines' => count($logLines)]);
}

function listCommands() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['agentId'] ?? $_POST['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $cmds = $db->find('agent_commands', ['agentId' => $agentId]);
    usort($cmds, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    json_response(array_slice($cmds, 0, 50));
}

function forensics() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id'] ?? $_POST['agentId'] ?? $_POST['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $db = Database::getInstance();
    $type = $_GET['type'] ?? $body['type'] ?? 'files';
    $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 50);
    $collection = in_array($type, ['files', 'db', 'host']) ? ($type === 'files' ? 'file_events' : ($type === 'db' ? 'database_logs' : 'host_events')) : 'file_events';
    $events = $db->find($collection, ['agentId' => $agentId, 'userId' => $user['_id']]);
    usort($events, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    json_response(['success' => true, 'type' => $type, 'events' => array_slice($events, 0, $limit)]);
}

function sensitiveInventory() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['agentId'] ?? $body['agentId'] ?? '';
    $status = $_GET['status'] ?? $body['status'] ?? '';
    $limit = (int)($_GET['limit'] ?? $body['limit'] ?? 100);

    $db = Database::getInstance();

    // Si es superadmin, puede ver todo; si no, solo de sus agentes
    $companyId = $user['companyId'] ?? $user['_id'];

    // Usar el método del store para buscar inventario
    // Como el store no expone directamente este método, hacemos query directa a MongoDB
    $mongo = new MongoDB\Client(MONGODB_URI);
    $coll = $mongo->selectDatabase('invisia')->selectCollection('sensitive_inventory');

    $filter = ['company_id' => $companyId];
    if ($agentId) {
        $filter['agent_id'] = $agentId;
    }
    if ($status) {
        $filter['status'] = $status;
    }

    $cursor = $coll->find($filter, [
        'sort' => ['last_scanned' => -1],
        'limit' => $limit
    ]);

    $items = [];
    foreach ($cursor as $doc) {
        $doc = (array)$doc;
        if (isset($doc['_id'])) $doc['_id'] = (string)$doc['_id'];
        $items[] = $doc;
    }

    json_response(['success' => true, 'items' => $items, 'total' => count($items)]);
}

function dbConnectionList() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $_GET['agentId'] ?? $_GET['id'] ?? $body['agentId'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    
    $db = Database::getInstance();
    $conns = $db->find('agent_db_connections', ['agentId' => $agentId]);
    json_response(['success' => true, 'connections' => $conns]);
}

function dbConnectionCreate() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? $_GET['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    
    $conn = [
        'agentId'  => $agentId,
        'engine'   => $body['engine'] ?? '',
        'host'     => $body['host'] ?? '',
        'port'     => (int)($body['port'] ?? 0),
        'database' => $body['database'] ?? '',
        'username' => $body['username'] ?? '',
        'password' => $body['password'] ?? '',
        'ssl'      => (bool)($body['ssl'] ?? false),
        'enabled'  => true,
        'createdAt' => date('c'),
    ];
    
    if (!$conn['engine'] || !$conn['host'] || !$conn['port'] || !$conn['database'] || !$conn['username']) {
        json_error('Todos los campos son requeridos: engine, host, port, database, username');
    }
    
    $validEngines = ['mssql', 'postgres', 'mysql', 'mongodb', 'redis', 'sqlite'];
    if (!in_array($conn['engine'], $validEngines)) {
        json_error('Engine no soportado. Validos: ' . implode(', ', $validEngines));
    }
    
    $db = Database::getInstance();
    $connId = $db->insertOne('agent_db_connections', $conn);
    
    // Enviar al agente via WebSocket
    $token = Auth::createToken($agent['userId'], [
        'agentId' => $agentId,
        'purpose' => 'agent_command'
    ]);
    
    json_response(['success' => true, 'connectionId' => $connId, 'connection' => $conn]);
}

function dbConnectionDelete() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? $_GET['id'] ?? '';
    $connId = $body['connectionId'] ?? '';
    if (!$agentId || !$connId) json_error('agentId y connectionId requeridos');
    
    $db = Database::getInstance();
    $db->deleteOne('agent_db_connections', ['_id' => $connId, 'agentId' => $agentId]);
    
    json_response(['success' => true]);
}

function dbConnectionTest() {
    $user = Auth::requireAuth();
    $body = get_body();
    $connId = $body['connectionId'] ?? '';
    $agentId = $body['agentId'] ?? $_GET['id'] ?? '';
    if (!$connId || !$agentId) json_error('connectionId y agentId requeridos');
    
    $db = Database::getInstance();
    $conn = $db->findOne('agent_db_connections', ['_id' => $connId]);
    if (!$conn) json_error('conexión no encontrada', 404);
    
    // Test connection by trying to connect
    $testResult = testDBConnection($conn);
    json_response(['success' => $testResult['success'], 'message' => $testResult['message']]);
}

function testDBConnection($conn) {
    try {
        switch ($conn['engine']) {
            case 'mssql':
                $dsn = sprintf("sqlserver://%s:%s@%s:%d?database=%s&connection+timeout=5",
                    $conn['username'], $conn['password'], $conn['host'], $conn['port'], $conn['database']);
                $pdo = new PDO($dsn);
                break;
            case 'postgres':
                $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s",
                    $conn['host'], $conn['port'], $conn['database']);
                $pdo = new PDO($dsn, $conn['username'], $conn['password']);
                break;
            case 'mysql':
                $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s",
                    $conn['host'], $conn['port'], $conn['database']);
                $pdo = new PDO($dsn, $conn['username'], $conn['password']);
                break;
            case 'mongodb':
                $mongo = new MongoDB\Client(sprintf("mongodb://%s:%s@%s:%d",
                    $conn['username'], $conn['password'], $conn['host'], $conn['port']));
                $mongo->selectDatabase($conn['database'])->command(['ping' => 1]);
                return ['success' => true, 'message' => 'Conexión exitosa'];
            default:
                return ['success' => false, 'message' => 'Engine no soportado para test'];
        }
        $pdo->query('SELECT 1');
        return ['success' => true, 'message' => 'Conexión exitosa'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function folderList() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $folders = $db->find('folders', ['userId' => $user['_id']]);
    usort($folders, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
    json_response($folders);
}

function folderCreate() {
    $user = Auth::requireAuth();
    $body = get_body();
    $name = trim($body['name'] ?? '');
    if (!$name) json_error('nombre requerido');
    $db = Database::getInstance();
    $existing = $db->findOne('folders', ['userId' => $user['_id'], 'name' => $name]);
    if ($existing) json_error('la carpeta ya existe');
    $db->insertOne('folders', ['userId' => $user['_id'], 'name' => $name, 'createdAt' => date('c')]);
    json_response(['success' => true]);
}

function folderDelete() {
    $user = Auth::requireAuth();
    $body = get_body();
    $name = trim($body['name'] ?? '');
    if (!$name) json_error('nombre requerido');
    $db = Database::getInstance();
    $db->deleteOne('folders', ['userId' => $user['_id'], 'name' => $name]);
    $agents = $db->find('agents', ['userId' => $user['_id'], 'group' => $name]);
    foreach ($agents as $a) {
        $db->updateOne('agents', ['_id' => $a['_id']], ['group' => '']);
    }
    json_response(['success' => true]);
}

function setLockdown() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? $body['id'] ?? $_GET['agentId'] ?? $_GET['id'] ?? $_POST['agentId'] ?? $_POST['id'] ?? '';
    $action = $body['action'] ?? $_GET['action'] ?? '';
    if (!$agentId || !in_array($action, ['lock', 'unlock'])) json_error('agentId y action (lock|unlock) requeridos');

    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);

    $lockdown = $action === 'lock'
        ? [
            'enabled' => true,
            'message' => trim($body['message'] ?? ($body['reason'] ?? '')),
            'reason' => trim($body['reason'] ?? ''),
            'setBy' => $user['email'] ?? $user['_id'],
            'setAt' => date('c'),
        ]
        : [
            'enabled' => false,
            'message' => '',
            'reason' => '',
            'setBy' => $user['email'] ?? $user['_id'],
            'setAt' => date('c'),
        ];

    $db->updateOne('agents', ['agentId' => $agentId], ['lockdown' => $lockdown]);
    $db->updateOne('host_monitor', ['agentId' => $agentId], ['lockdown' => $lockdown]);

    // Comando inmediato para el agente (se entrega vía WS sync)
    $db->insertOne('agent_commands', [
        'userId' => $agent['userId'] ?? $user['_id'],
        'agentId' => $agentId,
        'command' => $action === 'lock' ? 'lockdown' : 'unlock',
        'params' => ['message' => $lockdown['message']],
        'createdAt' => date('c'),
        'executed' => false,
    ]);
    audit_log('lockdown_' . ($action === 'lock' ? 'on' : 'off'), ['agentId' => $agentId, 'hostname' => $agent['hostname'] ?? '', 'message' => $lockdown['message']], $agent['userId'] ?? null, $agentId);

    json_response(['success' => true, 'lockdown' => $lockdown]);
}

function download() {
    @set_time_limit(180);
    $user = Auth::requireAuth();
    $token = get_token();

    $platform = $_GET['platform'] ?? 'win-x64';
    if (preg_match('#^win#i', $platform) || isset($_GET['installer'])) {
        $platform = 'win-x64';
    }

    $allowedPlatforms = ['win-x64', 'linux-x64', 'mac-x64', 'mac-arm64'];
    if (!in_array($platform, $allowedPlatforms)) {
        $platform = 'win-x64';
    }

    // Optional deploy ID to track downloads
    $deployId = $_GET['deploy'] ?? '';
    if ($deployId) {
        $db = Database::getInstance();
        $deploy = $db->findOne('agent_deploys', ['_id' => $deployId, 'userId' => $user['_id']]);
        if ($deploy) {
            $db->updateOne('agent_deploys', ['_id' => $deployId], ['$inc' => ['downloadCount' => 1], 'status' => 'downloaded']);
        }
    }

    // ── Windows: Instalador ejecutable compilado con NSIS ──
    if ($platform === 'win-x64') {
        $agentToken = $token ?: Auth::createToken($user['_id'], [
            'email' => $user['email'] ?? '',
            'purpose' => 'agent_installation',
            'platform' => 'windows'
        ]);

        $baseUrl = API_BASE_URL !== '' ? API_BASE_URL : 'https://169.58.144.242';
        $apiBase = rtrim($baseUrl, '/') . '/api/agents';
        $wsBase = preg_replace(['#^https://#', '#^http://#'], ['wss://', 'ws://'], rtrim($baseUrl, '/')) . '/ws/';

        // Check cache for this token
        $cacheFile = sys_get_temp_dir() . '/nsis-cache-' . md5($agentToken . $apiBase . $wsBase) . '.exe';
        if (file_exists($cacheFile) && filesize($cacheFile) > 500000 && (time() - filemtime($cacheFile) < 86400)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="SecureLabAgent-Installer.exe"');
            header('Content-Length: ' . filesize($cacheFile));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($cacheFile);
            exit;
        }

        // Buscar script NSIS y binario
        $nsiCandidates = [
            __DIR__ . '/../installer/SecureLabAgent.nsi',
            '/var/www/html/installer/SecureLabAgent.nsi',
            '/var/www/asier_ley-main/backend/installer/SecureLabAgent.nsi',
            dirname(__DIR__, 2) . '/backend/installer/SecureLabAgent.nsi',
        ];
        $nsiPath = null;
        foreach ($nsiCandidates as $candidate) {
            if (file_exists($candidate)) {
                $nsiPath = $candidate;
                break;
            }
        }

        $binCandidates = [
            __DIR__ . '/../installer/securelab-agent.exe',
            __DIR__ . '/../agent-bin/securelab-agent-win-x64.exe',
            __DIR__ . '/../securelab-agent/securelab-agent.exe',
            '/var/www/html/installer/securelab-agent.exe',
            '/var/www/html/agent-bin/securelab-agent-win-x64.exe',
            '/var/www/asier_ley-main/backend/installer/securelab-agent.exe',
            '/var/www/asier_ley-main/backend/agent-bin/securelab-agent-win-x64.exe',
        ];
        $agentExePath = null;
        foreach ($binCandidates as $candidate) {
            if (file_exists($candidate) && filesize($candidate) > 500000) {
                $agentExePath = $candidate;
                break;
            }
        }

        $finalInstallerPath = null;

        // Intentar compilación dinámica con makensis si está disponible
        $hasMakensis = false;
        exec('which makensis 2>/dev/null', $whichOut, $whichRet);
        if ($whichRet === 0 && !empty($whichOut[0])) {
            $hasMakensis = true;
        }

        if ($hasMakensis && $nsiPath && $agentExePath) {
            $tmpDir = sys_get_temp_dir() . '/nsis-build-' . uniqid();
            if (mkdir($tmpDir, 0755, true)) {
                $tmpOut = $tmpDir . '/SecureLabAgent-Installer.exe';
                $nsiDir = dirname($nsiPath);

                // Copiar archivos auxiliares si están en el directorio NSIS
                foreach (['LICENSE.txt', 'installer-logo.bmp', 'installer-small.bmp'] as $aux) {
                    if (file_exists($nsiDir . '/' . $aux)) {
                        @copy($nsiDir . '/' . $aux, $tmpDir . '/' . $aux);
                    }
                }
                @copy($agentExePath, $tmpDir . '/securelab-agent.exe');
                @copy($nsiPath, $tmpDir . '/SecureLabAgent.nsi');

                $cmd = sprintf(
                    'cd %s && makensis -DAGENT_TOKEN=%s -DAPI_BASE=%s -DWS_URL=%s -DOUTFILE=%s SecureLabAgent.nsi 2>&1',
                    escapeshellarg($tmpDir),
                    escapeshellarg($agentToken),
                    escapeshellarg($apiBase),
                    escapeshellarg($wsBase),
                    escapeshellarg($tmpOut)
                );

                exec($cmd, $makensisOutput, $makensisStatus);
                if ($makensisStatus === 0 && file_exists($tmpOut) && filesize($tmpOut) > 500000) {
                    @copy($tmpOut, $cacheFile);
                    $finalInstallerPath = $cacheFile;
                }
                @array_map('unlink', glob($tmpDir . '/*'));
                @rmdir($tmpDir);
            }
        }

        // Fallback a instalador pre-generado si la compilación dinámica no se usó
        if (!$finalInstallerPath || !file_exists($finalInstallerPath)) {
            $prebuiltCandidates = [
                __DIR__ . '/../installer/SecureLabAgent-Installer.exe',
                __DIR__ . '/../installer/Output/SecureLabAgent-Setup.exe',
                __DIR__ . '/../SecureLabAgent-Installer.exe',
                '/var/www/asier_ley-main/backend/installer/SecureLabAgent-Installer.exe',
                '/var/www/asier_ley-main/backend/SecureLabAgent-Installer.exe',
                '/var/www/html/installer/output/SecureLabAgent-Installer.exe',
                '/var/www/html/installer/SecureLabAgent-Installer.exe',
                '/var/www/html/SecureLabAgent-Installer.exe',
            ];
            foreach ($prebuiltCandidates as $candidate) {
                if (file_exists($candidate) && filesize($candidate) > 500000) {
                    $finalInstallerPath = $candidate;
                    break;
                }
            }
        }

        if (!$finalInstallerPath || !file_exists($finalInstallerPath)) {
            json_error('Instalador NSIS no disponible en el servidor', 503);
        }

        $fileSize = filesize($finalInstallerPath);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="SecureLabAgent-Installer.exe"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($finalInstallerPath);
        exit;
    }

    // ── Linux / macOS: binario directo ──
    $binaryMap = [
        'linux-x64'  => 'securelab-agent-linux-x64',
        'mac-x64'    => 'securelab-agent-mac-x64',
        'mac-arm64'  => 'securelab-agent-mac-arm64',
    ];
    $binaryName = $binaryMap[$platform] ?? 'securelab-agent-' . $platform;

    $candidateDirs = [
        __DIR__ . '/../agent-bin',
        '/var/www/html/agent-bin',
        __DIR__ . '/../securelab-agent',
    ];

    $binaryPath = null;
    foreach ($candidateDirs as $dir) {
        $p = $dir . '/' . $binaryName;
        if (file_exists($p) && filesize($p) > 500000) {
            $binaryPath = $p;
            break;
        }
    }

    if (!$binaryPath || !file_exists($binaryPath)) {
        json_error('Binario del agente no disponible', 503);
    }

    $fileSize = filesize($binaryPath);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $binaryName . '"');
    header('Content-Length: ' . $fileSize);
    readfile($binaryPath);
    exit;
}

function downloadBinary() {
    $platform = $_GET['platform'] ?? 'win-x64';
    if (preg_match('#^win#i', $platform)) {
        $platform = 'win-x64';
    }

    $binaryMap = [
        'win-x64'    => 'securelab-agent-win-x64.exe',
        'linux-x64'  => 'securelab-agent-linux-x64',
        'mac-x64'    => 'securelab-agent-mac-x64',
        'mac-arm64'  => 'securelab-agent-mac-arm64',
    ];
    $binaryName = $binaryMap[$platform] ?? 'securelab-agent-win-x64.exe';

    $candidatePaths = [
        __DIR__ . '/../agent-bin/' . $binaryName,
        __DIR__ . '/../installer/securelab-agent.exe',
        __DIR__ . '/../securelab-agent/' . $binaryName,
        __DIR__ . '/../securelab-agent/securelab-agent.exe',
        '/var/www/html/agent-bin/' . $binaryName,
        '/var/www/html/installer/securelab-agent.exe',
    ];

    $binaryPath = null;
    foreach ($candidatePaths as $p) {
        if (file_exists($p) && filesize($p) > 100000) {
            $binaryPath = $p;
            break;
        }
    }

    if (!$binaryPath) {
        json_error('Binario no encontrado', 404);
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $binaryName . '"');
    header('Content-Length: ' . filesize($binaryPath));
    readfile($binaryPath);
    exit;
}

function message() {
    $user = Auth::requireAuth();
    $agentId = $_GET['agentId'] ?? $_GET['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');

    $body = get_body();
    $rawMsg = $body['message'] ?? [];
    $msg = is_array($rawMsg) ? $rawMsg : (json_decode($rawMsg, true) ?: []);
    $type = $msg['type'] ?? '';
    $db = Database::getInstance();

    switch ($type) {
        case 'event':
        case 'host_event':
        case 'antivirus':
        case 'compliance':
        case 'db_activity':
        case 'ai_analyzer':
        case 'ai_analyzer_deep':
            $db->insertOne('alerts', [
                'userId' => $user['_id'],
                'agentId' => $agentId,
                'title' => $msg['title'] ?? 'Evento del agente',
                'message' => $msg['description'] ?? $msg['payload'] ?? '',
                'severity' => $msg['severity'] ?? 'medium',
                'source' => $msg['source'] ?? $type,
                'type' => $type,
                'eventType' => $msg['eventType'] ?? $type,
                'read' => false,
                'showOnLanding' => false,
                'createdAt' => date('c'),
            ]);
            break;

        case 'db_log_discovery':
            if (!empty($msg['dbLogDiscovery'])) {
                $db->insertOne('database_logs', [
                    'userId' => $user['_id'],
                    'agentId' => $agentId,
                    'logType' => 'discovery',
                    'severity' => 'info',
                    'operation' => 'scan',
                    'data' => $msg['dbLogDiscovery'],
                    'createdAt' => date('c'),
                ]);
            }
            break;

        case 'log_query':
            $logs = $msg['queryLogs'] ?? [];
            if (is_array($logs)) {
                foreach ($logs as $log) {
                    $db->insertOne('database_logs', [
                        'userId' => $user['_id'],
                        'agentId' => $agentId,
                        'logType' => 'query',
                        'severity' => $log['severity'] ?? 'info',
                        'operation' => $log['operation'] ?? 'query',
                        'query' => $log['query'] ?? '',
                        'dbUser' => $log['user'] ?? '',
                        'database' => $log['database'] ?? '',
                        'timestamp' => $log['timestamp'] ?? date('c'),
                        'createdAt' => date('c'),
                    ]);
                }
            }
            break;
    }

    json_response(['success' => true]);
}