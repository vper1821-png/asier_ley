<?php
// Database routes

function listAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $databases = $db->find('databases', ['userId' => $user['_id']]);
    json_response($databases);
}

function getDbId() {
    return $_GET['id'] ?? '';
}

function connect() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $required = ['name', 'type', 'host', 'port', 'user', 'database', 'agentId'];
    foreach ($required as $field) {
        if (empty($body[$field])) json_error("$field requerido");
    }

    $agentId = $body['agentId'];
    $agent = $db->findOne('agents', ['$or' => [['agentId' => $agentId], ['_id' => $agentId]], 'userId' => $user['_id']]);
    if (!$agent) json_error('agente no encontrado', 404);

    $allowedTypes = ['mysql', 'mariadb', 'postgres', 'postgresql', 'mssql', 'sqlite', 'mongodb'];
    if (!in_array($body['type'], $allowedTypes)) json_error('tipo de base de datos no soportado');

    if ($body['type'] === 'mongodb') {
        if (!class_exists('MongoDB\Client')) {
            json_error('MongoDB driver no instalado. Ejecuta: composer require mongodb/mongodb');
        }
    }

    $storedAgentId = $agent['agentId'] ?? $agent['_id'] ?? $agentId;
    $record = $db->insertOne('databases', [
        'userId' => $user['_id'],
        'agentId' => $storedAgentId,
        'agentName' => $agent['hostname'] ?? '',
        'name' => $body['name'],
        'type' => $body['type'],
        'host' => $body['host'],
        'port' => $body['port'],
        'user' => $body['user'],
        'password' => $body['password'] ?? '',
        'database' => $body['database'],
        'ssl' => filter_var($body['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'status' => 'configured',
        'lastTest' => null,
        'tables' => 0,
    ]);

    unset($record['password']);
    json_response(['success' => true, 'database' => $record]);
}

function localConnect() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    if (empty($body['name']) || empty($body['type']) || empty($body['database'])) {
        json_error('nombre, tipo y base de datos requeridos');
    }

    $type = $body['type'];
    $allowedTypes = ['mysql', 'mariadb', 'postgres', 'postgresql', 'mssql', 'sqlite', 'mongodb'];
    if (!in_array($type, $allowedTypes)) json_error('tipo no soportado');

    $defaultPorts = ['mysql' => 3306, 'mariadb' => 3306, 'postgres' => 5432, 'postgresql' => 5432, 'mssql' => 1433, 'sqlite' => 0, 'mongodb' => 27017];
    $record = $db->insertOne('databases', [
        'userId' => $user['_id'],
        'name' => $body['name'],
        'type' => $type,
        'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'port' => $body['port'] ?? ($defaultPorts[$type] ?? 0),
        'user' => $body['user'] ?? '',
        'password' => $body['password'] ?? '',
        'database' => $body['database'],
        'ssl' => false,
        'isLocal' => true,
        'status' => 'configured',
        'lastTest' => null,
        'tables' => 0,
    ]);

    unset($record['password']);
    json_response(['success' => true, 'database' => $record]);
}

function update() {
    $user = Auth::requireAuth();
    $body = get_body();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $existing = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$existing) json_error('base de datos no encontrada', 404);

    $allowed = ['name', 'type', 'host', 'port', 'user', 'password', 'database', 'ssl'];
    $updates = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) $updates[$field] = $body[$field];
    }
    if (!empty($updates)) $db->updateOne('databases', ['_id' => $id], $updates);
    json_response(['success' => true]);
}

function delete() {
    $user = Auth::requireAuth();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $existing = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$existing) json_error('base de datos no encontrada', 404);

    $db->deleteOne('databases', ['_id' => $id]);
    json_response(['success' => true]);
}

function getOnlineAgentForUser($userId) {
    $db = Database::getInstance();
    $recent = date('c', strtotime('-30 minutes'));
    $agents = $db->find('agents', [
        'userId' => $userId,
        'status' => 'online',
        'lastSeen' => ['$gte' => $recent]
    ], ['sort' => ['lastSeen' => -1], 'limit' => 1]);
    return $agents[0] ?? null;
}

function sendAgentCommand($userId, $agentId, $command, $params) {
    $db = Database::getInstance();
    $cmdId = $db->insertOne('agent_commands', [
        'userId' => $userId,
        'agentId' => $agentId,
        'command' => $command,
        'params' => $params,
        'executed' => false,
        'createdAt' => date('c'),
    ]);
    if (isset($cmdId['_id'])) $cmdId = $cmdId['_id'];
    return (string)$cmdId;
}

function waitForAgentCommand($commandId, $timeoutSeconds = 30) {
    $db = Database::getInstance();
    $start = microtime(true);
    while ((microtime(true) - $start) < $timeoutSeconds) {
        $cmd = $db->findOne('agent_commands', ['_id' => $commandId]);
        if (!empty($cmd['executed'])) {
            return $cmd;
        }
        usleep(500000);
    }
    return null;
}

function executeDBCommandViaAgent($userId, $command, $record, $timeout = 25) {
    $db = Database::getInstance();
    $agentId = $record['agentId'] ?? '';
    if ($agentId === '') {
        json_error('esta conexión no tiene un agente asignado');
    }

    $agent = $db->findOne('agents', [
        '$or' => [['agentId' => $agentId], ['_id' => $agentId]],
        'userId' => $userId
    ]);

    $recent = date('c', strtotime('-30 minutes'));
    $isOnline = fn($a) => $a
        && ($a['status'] ?? '') === 'online'
        && !empty($a['lastSeen'])
        && $a['lastSeen'] >= $recent;

    if (!$isOnline($agent) && !empty($agent['hostname'])) {
        $sameHost = array_values(array_filter(
            $db->find('agents', ['userId' => $userId, 'hostname' => $agent['hostname']]),
            $isOnline
        ));
        if ($sameHost) {
            usort($sameHost, fn($a, $b) => strcmp($b['lastSeen'], $a['lastSeen']));
            $agent = $sameHost[0];
        }
    }

    if (!$isOnline($agent)) {
        $online = array_values(array_filter(
            $db->find('agents', ['userId' => $userId]),
            $isOnline
        ));
        if (count($online) === 1) {
            $agent = $online[0];
        }
    }

    if (!$agent) {
        json_error('el agente asignado a esta conexión ya no existe');
    }
    if (!$isOnline($agent)) {
        json_error('el agente "' . ($agent['hostname'] ?? $agentId) . '" no está online. Inicia el agente y vuelve a intentarlo.');
    }

    $resolvedAgentId = $agent['agentId'] ?? '';
    if ($resolvedAgentId !== '' && $resolvedAgentId !== $agentId) {
        $db->updateOne('databases', ['_id' => $record['_id']], [
            'agentId' => $resolvedAgentId,
            'agentName' => $agent['hostname'] ?? '',
        ]);
    }

    $params = [
        'type' => $record['type'],
        'host' => $record['host'],
        'port' => (int)($record['port'] ?? 0),
        'database' => $record['database'],
        'user' => $record['user'],
        'password' => $record['password'] ?? '',
        'ssl' => filter_var($record['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ];

    $commandId = sendAgentCommand($userId, $agent['agentId'] ?? $agentId, $command, $params);
    $result = waitForAgentCommand($commandId, $timeout);
    if (!$result) {
        json_error('el agente no respondió a tiempo. Comprueba que está conectado y vuelve a intentarlo.');
    }

    $res = toArrayRec($result['result'] ?? []);
    if (is_string($res)) {
        $res = json_decode($res, true) ?: [];
    }
    if (is_array($res)) {
        return $res;
    }

    json_error('respuesta del agente inválida');
    return null;
}

function listTablesDirect($conn, $record) {
    $tables = [];
    $type = $record['type'] ?? '';
    if (in_array($type, ['mysql', 'mariadb'])) {
        $stmt = $conn->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = '" . addslashes($record['database']) . "'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = ['name' => $row['table_name'], 'rows' => (int)$row['table_rows']];
        }
    } elseif (in_array($type, ['postgres', 'postgresql'])) {
        $stmt = $conn->query("SELECT relname AS table_name, n_live_tup AS row_count FROM pg_stat_user_tables");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = ['name' => $row['table_name'], 'rows' => (int)($row['row_count'] ?? 0)];
        }
    } elseif ($type === 'sqlite') {
        $stmt = $conn->query("SELECT name FROM sqlite_master WHERE type='table'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tables[] = ['name' => $row['name'], 'rows' => 0];
        }
    } elseif ($type === 'mongodb') {
        $database = $conn->selectDatabase($record['database']);
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            $tables[] = ['name' => $name, 'rows' => $database->selectCollection($name)->countDocuments()];
        }
    }
    return $tables;
}

function toArrayRec($data) {
    if (is_object($data) && ($data instanceof MongoDB\Model\BSONDocument || $data instanceof MongoDB\Model\BSONArray)) {
        $data = $data->getArrayCopy();
    }
    if (is_array($data) || (is_object($data) && $data instanceof Traversable)) {
        $arr = [];
        foreach ($data as $k => $v) {
            $arr[$k] = toArrayRec($v);
        }
        return $arr;
    }
    return $data;
}

function getDsn($record) {
    $type = $record['type'] ?? '';
    $host = $record['host'] ?? '';
    $port = $record['port'] ?? 0;
    $database = $record['database'] ?? '';
    $user = $record['user'] ?? '';
    $password = $record['password'] ?? '';
    $ssl = filter_var($record['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);

    try {
        if (in_array($type, ['mysql', 'mariadb'])) {
            $dsn = "mysql:host=$host;port=$port;dbname=$database" . ($ssl ? ';charset=utf8mb4' : '');
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($ssl) $pdo->exec("SET NAMES utf8mb4");
            return $pdo;
        }
        if (in_array($type, ['postgres', 'postgresql'])) {
            $dsn = "pgsql:host=$host;port=$port;dbname=$database";
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($ssl) $pdo->exec("SET sslmode=require");
            return $pdo;
        }
        if ($type === 'mssql') {
            $dsn = "sqlsrv:Server=$host,$port;Database=$database;Encrypt=no;TrustServerCertificate=yes";
            $pdo = new PDO($dsn, $user, $password, [PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            return $pdo;
        }
        if ($type === 'sqlite') {
            if (!file_exists($database)) json_error('archivo sqlite no encontrado');
            return new PDO("sqlite:$database", '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        if ($type === 'mongodb') {
            if (!class_exists('MongoDB\Client')) {
                json_error('MongoDB driver no instalado. Ejecuta: composer require mongodb/mongodb');
            }
            
            $uri = "mongodb://";
            if ($user && $password) {
                $uri .= urlencode($user) . ':' . urlencode($password) . '@';
            }
            $uri .= $host . ':' . $port . '/' . $database;
            
            $client = new MongoDB\Client($uri, [
                'serverSelectionTimeoutMS' => 5000,
                'connectTimeoutMS' => 5000,
                'socketTimeoutMS' => 5000,
            ]);
            
            $client->selectDatabase($database)->command(['ping' => 1]);
            return $client;
        }
    } catch (PDOException $e) {
        json_error('conexión fallida: ' . $e->getMessage());
    } catch (Exception $e) {
        json_error('conexión fallida: ' . $e->getMessage());
    }
    json_error('tipo no soportado');
}

function testConnection() {
    $user = Auth::requireAuth();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $record = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$record) json_error('base de datos no encontrada', 404);

    $res = executeDBCommandViaAgent($user['_id'], 'test_db', $record, 25);
    if (empty($res['success'])) {
        $msg = $res['error'] ?? $res['Error'] ?? 'conexión fallida';
        json_error('conexión fallida: ' . $msg);
    }
    $latency = (int)($res['latency'] ?? $res['Latency'] ?? 0);
    $status = $res['status'] ?? $res['Status'] ?? 'connected';

    $db->updateOne('databases', ['_id' => $id], [
        'lastTest' => date('c'),
        'status' => $status,
        'latency' => $latency
    ]);
    json_response(['success' => true, 'latency' => $latency, 'status' => $status]);
}

function scan() {
    $user = Auth::requireAuth();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $record = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$record) json_error('base de datos no encontrada', 404);

    $res = executeDBCommandViaAgent($user['_id'], 'scan_db', $record, 25);
    if (empty($res['success'])) {
        $msg = $res['error'] ?? $res['Error'] ?? 'escaneo fallido';
        json_error('escaneo fallido: ' . $msg);
    }

    $tables = $res['tableList'] ?? $res['TableList'] ?? [];
    $totalRows = (int)($res['records'] ?? $res['Records'] ?? 0);
    $tablesCount = (int)($res['tables'] ?? $res['Tables'] ?? count($tables));

    $db->updateOne('databases', ['_id' => $id], [
        'tables' => $tablesCount,
        'totalRows' => $totalRows,
        'records' => $totalRows,
        'recordCount' => $totalRows,
        'status' => 'connected',
        'lastScan' => date('c')
    ]);

    $inventoryData = [
        'userId' => $user['_id'],
        'databaseId' => $id,
        'name' => 'Tratamiento: ' . ($record['name'] ?? $record['database'] ?? 'db'),
        'dataCategories' => implode(', ', array_column($tables, 'name')) ?: 'Datos personales',
        'legalBasis' => 'Interés legítimo / Consentimiento',
        'records' => $totalRows,
        'active' => true,
        'updatedAt' => date('c'),
    ];
    $existingInv = $db->findOne('compliance_inventory', ['userId' => $user['_id'], 'databaseId' => $id]);
    if ($existingInv) {
        $db->updateOne('compliance_inventory', ['_id' => $existingInv['_id']], $inventoryData);
    } else {
        $inventoryData['createdAt'] = date('c');
        $db->insertOne('compliance_inventory', $inventoryData);
    }

    json_response(['success' => true, 'tables' => $tables, 'totalRows' => $totalRows]);
}

function query() {
    $user = Auth::requireAuth();
    $body = get_body();
    $id = getDbId();
    $query = trim($body['query'] ?? '');
    if (!$id) json_error('id requerido');
    if (!$query) json_error('query requerido');

    $db = Database::getInstance();
    $record = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$record) json_error('base de datos no encontrada', 404);

    $type = $record['type'] ?? '';

    if ($type === 'mongodb') {
        if (strpos(strtolower($query), 'find') === false) {
            json_error('MongoDB solo soporta consultas find()');
        }
        
        try {
            $conn = getDsn($record);
            $database = $conn->selectDatabase($record['database']);
            
            $parts = explode(',', $query);
            $collectionName = trim(str_replace(['find(', "'", '"'], '', $parts[0]));
            $filter = isset($parts[1]) ? json_decode(trim($parts[1]), true) : [];
            
            $collection = $database->selectCollection($collectionName);
            $cursor = $collection->find($filter, ['limit' => 100]);
            $rows = iterator_to_array($cursor);
            
            foreach ($rows as &$row) {
                if (isset($row['_id']) && $row['_id'] instanceof MongoDB\BSON\ObjectId) {
                    $row['_id'] = (string)$row['_id'];
                }
            }
            
            json_response(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
        } catch (Exception $e) {
            json_error('query fallida: ' . $e->getMessage());
        }
    }

    $firstWord = strtoupper(strtok($query, " \t\n\r"));
    if (!in_array($firstWord, ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'])) {
        json_error('solo se permiten consultas de lectura (SELECT, SHOW, DESCRIBE, EXPLAIN)');
    }

    $conn = getDsn($record);
    try {
        $stmt = $conn->query($query);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_response(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
    } catch (PDOException $e) {
        json_error('query fallida: ' . $e->getMessage());
    }
}

function generateReport() {
    $user = Auth::requireAuth();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $record = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$record) json_error('base de datos no encontrada', 404);

    $conn = getDsn($record);
    $tables = [];
    $type = $record['type'] ?? '';
    
    try {
        if (in_array($type, ['mysql', 'mariadb'])) {
            $stmt = $conn->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = '" . addslashes($record['database']) . "'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = ['name' => $row['table_name'], 'rows' => (int)$row['table_rows']];
            }
        } elseif (in_array($type, ['postgres', 'postgresql'])) {
            $stmt = $conn->query("SELECT relname AS table_name, n_live_tup AS row_count FROM pg_stat_user_tables");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = ['name' => $row['table_name'], 'rows' => (int)($row['row_count'] ?? 0)];
            }
        } elseif ($type === 'sqlite') {
            $stmt = $conn->query("SELECT name FROM sqlite_master WHERE type='table'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = ['name' => $row['name'], 'rows' => 0];
            }
        } elseif ($type === 'mongodb') {
            $database = $conn->selectDatabase($record['database']);
            $collections = $database->listCollections();
            foreach ($collections as $collection) {
                $name = $collection->getName();
                $count = $database->selectCollection($name)->countDocuments();
                $tables[] = ['name' => $name, 'rows' => $count];
            }
        }
    } catch (PDOException $e) {
        json_error('reporte fallido: ' . $e->getMessage());
    } catch (Exception $e) {
        json_error('reporte fallido: ' . $e->getMessage());
    }

    json_response(['success' => true, 'name' => $record['name'], 'type' => $type, 'tables' => $tables, 'generatedAt' => date('c')]);
}

function syncAgent() {
    $user = Auth::requireAuth();
    $id = getDbId();
    if (!$id) json_error('id requerido');

    $db = Database::getInstance();
    $record = $db->findOne('databases', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$record) json_error('base de datos no encontrada', 404);

    $db->updateOne('databases', ['_id' => $id], ['agentSyncedAt' => date('c'), 'agentStatus' => 'synced']);
    json_response(['success' => true, 'message' => 'sincronización con agente registrada']);
}

// ============================================================
// MEJORAS EN LOGS: uso de agregación con filtros por empresa
// ============================================================

/**
 * Construye el pipeline de agregación para consultar logs,
 * aplicando filtros y compartiendo logs por empresa (companyId).
 *
 * @param string $userId   ID del usuario autenticado (string)
 * @param array  $params   Filtros: operation, database, engine, risk, search, limit, offset
 * @param bool   $countOnly Si true, solo devuelve pipeline de conteo
 * @return array           Pipeline de agregación de MongoDB
 */
function buildLogsPipeline($userId, $params, $countOnly = false) {
    $db = Database::getInstance();

    // 1. Obtener companyId del usuario
    $user = $db->findOne('users', ['_id' => $userId]);
    if (!$user) {
        json_error('Usuario no encontrado');
    }
    $companyId = $user['companyId'] ?? $userId;

    // 2. Obtener todos los userIds de la empresa (convertidos a string)
    $users = $db->find('users', ['companyId' => $companyId]);
    $userIds = array_map('strval', array_column($users, '_id'));
    if (empty($userIds)) {
        $userIds = [(string)$userId];
    }

    // 3. Filtro base: userId en la lista de la empresa
    $match = ['userId' => ['$in' => $userIds]];

    // 4. Filtros adicionales
    if (!empty($params['operation'])) {
        $match['operation'] = strtoupper(trim($params['operation']));
    }
    if (!empty($params['database'])) {
        $match['database'] = $params['database'];
    }
    if (!empty($params['engine'])) {
        $match['engine'] = $params['engine'];
    }
    if (!empty($params['risk'])) {
        if ($params['risk'] === 'risk') {
            $match['riskScore'] = ['$gt' => 0];
        } elseif ($params['risk'] === 'safe') {
            $match['riskScore'] = ['$lte' => 0];
        }
    }

    // 5. Búsqueda libre (case-insensitive) sobre varios campos
    if (!empty($params['search']) && strlen(trim($params['search'])) >= 2) {
        $search = trim($params['search']);
        $regex = ['$regex' => $search, '$options' => 'i'];
        $match['$or'] = [
            ['query' => $regex],
            ['database' => $regex],
            ['dbUser' => $regex],
            ['host' => $regex],
            ['engine' => $regex],
        ];
    }

    $pipeline = [['$match' => $match]];

    // 6. Ordenar por fecha descendente
    $pipeline[] = ['$sort' => ['createdAt' => -1]];

    if ($countOnly) {
        $pipeline[] = ['$count' => 'total'];
        return $pipeline;
    }

    // 7. Paginación
    $limit = isset($params['limit']) ? (int)$params['limit'] : 100;
    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;
    $pipeline[] = ['$skip' => $offset];
    $pipeline[] = ['$limit' => $limit];

    return $pipeline;
}

/**
 * Lista logs con filtros y paginación usando agregación.
 */
function logList() {
    $user = Auth::requireAuth();
    $params = get_body();
    $db = Database::getInstance();

    $limit = isset($params['limit']) ? (int)$params['limit'] : 100;
    $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

    // Pipeline para obtener los logs paginados
    $pipeline = buildLogsPipeline((string)$user['_id'], $params);
    $logs = $db->aggregate('database_logs', $pipeline);

    // Pipeline para contar el total (sin paginación)
    $countPipeline = buildLogsPipeline((string)$user['_id'], $params, true);
    $countResult = $db->aggregate('database_logs', $countPipeline);
    $total = isset($countResult[0]['total']) ? (int)$countResult[0]['total'] : 0;

    json_response([
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

/**
 * Estadísticas de logs usando agregación con los mismos filtros.
 */
function logStats() {
    $user = Auth::requireAuth();
    $params = get_body();
    $db = Database::getInstance();

    // Construir pipeline base sin paginación
    $pipeline = buildLogsPipeline((string)$user['_id'], $params);
    // Añadir grupo para estadísticas
    $pipeline[] = [
        '$group' => [
            '_id' => null,
            'total' => ['$sum' => 1],
            'selects' => ['$sum' => ['$cond' => [['$in' => ['$operation', ['SELECT','SHOW','DESCRIBE']]], 1, 0]]],
            'writes' => ['$sum' => ['$cond' => [['$in' => ['$operation', ['INSERT','UPDATE','DELETE','REPLACE']]], 1, 0]]],
            'ddl' => ['$sum' => ['$cond' => [['$in' => ['$operation', ['CREATE','ALTER','DROP','TRUNCATE']]], 1, 0]]],
            'suspicious' => ['$sum' => ['$cond' => [['$gt' => ['$riskScore', 0]], 1, 0]]],
            'severityList' => ['$push' => '$severity'],
        ]
    ];

    $result = $db->aggregate('database_logs', $pipeline);
    $stats = isset($result[0]) ? $result[0] : [];

    // Normalizar valores numéricos
    $stats['total'] = (int)($stats['total'] ?? 0);
    $stats['selects'] = (int)($stats['selects'] ?? 0);
    $stats['writes'] = (int)($stats['writes'] ?? 0);
    $stats['ddl'] = (int)($stats['ddl'] ?? 0);
    $stats['suspicious'] = (int)($stats['suspicious'] ?? 0);

    // Calcular distribución por severidad
    $severityCount = [];
    if (!empty($stats['severityList'])) {
        foreach ($stats['severityList'] as $sev) {
            if ($sev) {
                $severityCount[$sev] = ($severityCount[$sev] ?? 0) + 1;
            }
        }
    }
    $stats['bySeverity'] = [];
    foreach ($severityCount as $k => $v) {
        $stats['bySeverity'][] = ['_id' => $k, 'count' => $v];
    }
    unset($stats['severityList']); // eliminar campo temporal

    json_response($stats);
}

/**
 * Exporta logs a CSV aplicando los mismos filtros.
 */
function logExportCsv() {
    $user = Auth::requireAuth();
    $params = get_body() + $_GET;
    $db = Database::getInstance();

    // Obtener todos los logs filtrados (sin paginación)
    $pipeline = buildLogsPipeline((string)$user['_id'], $params);
    $logs = $db->aggregate('database_logs', $pipeline);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="db-logs-' . date('Y-m-d-His') . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Operación', 'Base de datos', 'Motor', 'Usuario DB', 'Host', 'Riesgo', 'Consulta']);

    foreach ($logs as $l) {
        $operation = strtoupper($l['operation'] ?? strtok(trim($l['query'] ?? ''), " \t\r\n") ?: 'QUERY');
        fputcsv($out, [
            $l['createdAt'] ?? $l['timestamp'] ?? '',
            $operation,
            $l['database'] ?? '',
            $l['engine'] ?? 'database',
            $l['dbUser'] ?? $l['user'] ?? '',
            $l['host'] ?? '',
            (float)($l['riskScore'] ?? 0),
            $l['query'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/**
 * Elimina logs que coinciden con los filtros (userId, databaseId, query).
 * Se convierte userId a string para consistencia.
 */
function deleteByQuery() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => (string)$user['_id']];
    if (!empty($body['databaseId'])) {
        $filter['databaseId'] = $body['databaseId'];
    }
    if (!empty($body['query'])) {
        $filter['query'] = $body['query'];
    }
    $all = $db->find('database_logs', $filter);
    $deleted = 0;
    foreach ($all as $log) {
        $db->deleteOne('database_logs', ['_id' => $log['_id']]);
        $deleted++;
    }
    json_response(['success' => true, 'deleted' => $deleted]);
}

// ---------- Funciones sin cambios (skip, revoke, etc.) ----------

function skipQuery() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $dbId = $body['databaseId'] ?? '';
    $query = $body['query'] ?? '';
    if (!$dbId || !$query) json_error('databaseId y query requeridos');

    $db->insertOne('database_skipped_queries', [
        'userId' => $user['_id'],
        'databaseId' => $dbId,
        'query' => $query,
        'reason' => $body['reason'] ?? '',
        'createdAt' => date('c'),
    ]);
    json_response(['success' => true]);
}

function skippedQueries() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $dbId = $_GET['databaseId'] ?? ($_POST['databaseId'] ?? '');
    $filter = ['userId' => $user['_id']];
    if ($dbId) $filter['databaseId'] = $dbId;
    json_response($db->find('database_skipped_queries', $filter));
}

function revokeSkip() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $skipId = $body['skipId'] ?? '';
    $query = $body['query'] ?? '';

    if ($skipId) {
        $db->deleteOne('database_skipped_queries', ['_id' => $skipId, 'userId' => $user['_id']]);
    } elseif ($query) {
        $db->deleteOne('database_skipped_queries', ['query' => $query, 'userId' => $user['_id']]);
    } else {
        json_error('skipId o query requerido');
    }

    json_response(['success' => true]);
}

function clientAction($action) {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $dbId = $_GET['dbId'] ?? $_GET['id'] ?? '';
    if (!$dbId) json_error('dbId requerido');

    $cmd = match ($action) {
        'uninstall', 'uninstall-agent' => ['action' => 'uninstall'],
        'reconnect-db' => ['action' => 'reconnect-db', 'databaseId' => $body['databaseId'] ?? $dbId],
        'reconnect-agent' => ['action' => 'reconnect-agent'],
        'restart-agent', 'restart' => ['action' => 'restart'],
        default => json_error('acción no soportada'),
    };

    $database = $db->findOne('databases', ['_id' => $dbId, 'userId' => $user['_id']]);
    $agentId = $database['agentId'] ?? $dbId;
    $agent = $db->findOne('agents', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$agent && !($database && ($database['agentId'] ?? ''))) {
        $agentId = $dbId;
    }

    $db->insertOne('agent_commands', [
        'userId' => $user['_id'],
        'agentId' => $agentId,
        'command' => $cmd,
        'createdAt' => date('c'),
        'executed' => false,
    ]);
    json_response(['success' => true]);
}