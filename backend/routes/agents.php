<?php
// Agent routes

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

    if (!$agentId || !$hostname) json_error('agentId y hostname requeridos');

    $db = Database::getInstance();

    $existing = $db->findOne('agents', ['agentId' => $agentId]);
    if ($existing) {
        $db->updateOne('agents', ['agentId' => $agentId], [
            'userId' => $decoded['userId'],
            'hostname' => $hostname,
            'platform' => $platform,
            'arch' => $arch,
            'ip' => $ip,
            'version' => $version,
            'user' => $user,
            'status' => 'online',
            'lastSeen' => date('c'),
        ]);
        $agent = $db->findOne('agents', ['agentId' => $agentId]);
    } else {
        $agent = $db->insertOne('agents', [
            'userId' => $decoded['userId'],
            'agentId' => $agentId,
            'hostname' => $hostname,
            'platform' => $platform,
            'arch' => $arch,
            'ip' => $ip,
            'version' => $version,
            'user' => $user,
            'status' => 'online',
            'lastSeen' => date('c'),
        ]);
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

    $pendingCommands = [];
    foreach ($db->find('agent_commands', ['agentId' => $agentId, 'executed' => ['$in' => [false, null]]]) as $cmd) {
        $pendingCommands[] = ['command' => $cmd['command'], 'commandId' => $cmd['_id']];
        $db->updateOne('agent_commands', ['_id' => $cmd['_id']], ['executed' => true, 'executedAt' => date('c')]);
    }

    json_response([
        'error' => '',
        'pendingRules' => [],
        'pendingBlocks' => [],
        'pendingUnblocks' => [],
        'syncBlocked' => [],
        'pendingCommands' => $pendingCommands,
        'heartbeatInterval' => 5,
    ]);
}

function listAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $agents = $db->find('agents', ['userId' => $user['_id']]);
    json_response($agents);
}

function deleteAgent() {
    $user = Auth::requireAuth();
    $agentId = $_GET['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');

    $db = Database::getInstance();
    $agent = $db->findOne('agents', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$agent) json_error('agente no encontrado', 404);

    $db->deleteOne('agents', ['_id' => $agent['_id']]);
    $db->deleteOne('host_monitor', ['agentId' => $agentId]);
    json_response(['success' => true]);
}

function sendCommand() {
    $user = Auth::requireAuth();
    $agentId = $_GET['id'] ?? '';
    $body = get_body();
    $command = $body['command'] ?? '';

    if (!$agentId || !$command) json_error('agentId y command requeridos');
    if (is_string($command)) $command = json_decode($command, true) ?? $command;

    $db = Database::getInstance();
    $agent = $db->findOne('agents', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$agent) json_error('agente no encontrado', 404);

    $cmd = $db->insertOne('agent_commands', [
        'userId' => $user['_id'],
        'agentId' => $agentId,
        'command' => $command,
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




function download() {
    $user = Auth::requireAuth();
    $token = get_token();

    $platform = $_GET['platform'] ?? 'linux-x64';
    if (preg_match('#^win#', $platform)) {
        $platform = 'win-x64';
    }
    $allowedPlatforms = ['win-x64', 'linux-x64', 'mac-x64', 'mac-arm64'];
    if (!in_array($platform, $allowedPlatforms)) {
        json_error('plataforma no válida');
    }

    $binaryMap = [
        'win-x64'    => 'securelab-agent-win-x64.exe',
        'linux-x64'  => 'securelab-agent-linux-x64',
        'mac-x64'    => 'securelab-agent-mac-x64',
        'mac-arm64'  => 'securelab-agent-mac-arm64',
    ];
    $binaryName = $binaryMap[$platform];
    $binDir = __DIR__ . '/../agent-bin';
    $binaryPath = $binDir . '/' . $binaryName;

    if (!file_exists($binaryPath) || filesize($binaryPath) < 1000000) {
        json_error('Agente aún no compilado, intenta de nuevo en unos segundos', 503);
    }

    $tmpDir = sys_get_temp_dir() . '/agent-dl-' . uniqid();
    mkdir($tmpDir, 0755, true);
    copy($binaryPath, $tmpDir . '/' . $binaryName);

    // ── Config.json con rutas de instalación ──
    $basePath = ($platform === 'win-x64')
        ? 'C:\\Program Files\\SecureLab Agent'
        : '/opt/securelab-agent';

    $config = [
        'api_base'           => API_BASE_URL . '/api/agents',
        'token'              => $token,
        'heartbeat_interval' => 5,
        'agent_version'      => '2.0.0',
        'audit_db_path'      => $basePath . DIRECTORY_SEPARATOR . 'audit.db',
        'knowledge_db_path'  => $basePath . DIRECTORY_SEPARATOR . 'knowledge.db',
        'log_file'           => $basePath . DIRECTORY_SEPARATOR . 'agent.log',
        'hardening_enabled'  => true,
        'persistence_mode'   => 'aggressive',
        'log_level'          => 'info',
        // file_watch_dirs se omiten para usar los valores por defecto
    ];

    file_put_contents(
        $tmpDir . '/config.json',
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // ── Empaquetado según plataforma ──
    if ($platform === 'win-x64') {
        // Intentar generar MSI con wixl
        $wxsPath = __DIR__ . '/../installer/product.wxs';
        if (file_exists($wxsPath)) {
            $archiveName = 'SecureLab-Agent-win-x64.msi';
            $archivePath = sys_get_temp_dir() . '/' . $archiveName;
            $cmd = sprintf(
                'wixl -D ExeSource="%s" -D ConfigSource="%s" -o %s --arch x64 %s 2>&1',
                escapeshellarg($tmpDir . '/' . $binaryName),
                escapeshellarg($tmpDir . '/config.json'),
                escapeshellarg($archivePath),
                escapeshellarg($wxsPath)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode === 0 && file_exists($archivePath)) {
                // MSI generado correctamente
                $size = filesize($archivePath);
                header('Content-Type: application/x-msi');
                header('Content-Disposition: attachment; filename="' . $archiveName . '"');
                header('Content-Length: ' . $size);
                readfile($archivePath);
                unlink($archivePath);
                exit;
            }
            error_log('[Agent] wixl failed (' . $exitCode . '): ' . implode(' | ', $output));
        }

        // Fallback: ZIP
        $archiveName = 'SecureLab-Agent-win-x64.zip';
        $archivePath = sys_get_temp_dir() . '/' . $archiveName;
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE);
        $zip->addFile($tmpDir . '/' . $binaryName, $binaryName);
        $zip->addFile($tmpDir . '/config.json', 'config.json');
        $zip->close();
    } else {
        $archiveName = 'SecureLab-Agent-' . $platform . '.tar.gz';
        $tarPath = sys_get_temp_dir() . '/agent-' . uniqid('', true) . '.tar';
        $phar = new PharData($tarPath);
        $phar->addFile($tmpDir . '/' . $binaryName, $binaryName);
        $phar->addFile($tmpDir . '/config.json', 'config.json');
        $phar->compress(Phar::GZ);
        unset($phar);
        Phar::unlinkArchive($tarPath);
        $archivePath = $tarPath . '.gz';
    }

    $size = filesize($archivePath);
    $contentType = ($platform === 'win-x64') ? 'application/zip' : 'application/gzip';
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $archiveName . '"');
    header('Content-Length: ' . $size);
    readfile($archivePath);

    array_map('unlink', glob($tmpDir . '/*'));
    rmdir($tmpDir);
    unlink($archivePath);
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
