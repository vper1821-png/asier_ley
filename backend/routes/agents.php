<?php
// Agent routes

function isSuperAdminUser($u) {
    return ($u['role'] ?? '') === 'superadmin';
}

function findAgentFor($user, $agentId) {
    $db = Database::getInstance();
    $agent = $db->findOne('agents', ['agentId' => $agentId]);
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

    if (!$agentId || !$hostname) json_error('agentId y hostname requeridos');

    $db = Database::getInstance();

    $existing = $db->findOne('agents', ['agentId' => $agentId]);
    if ($existing) {        $db->updateOne('agents', ['agentId' => $agentId], [
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
        audit_log('agent_registered', ['agentId' => $agentId, 'hostname' => $hostname, 'platform' => $platform, 'ip' => $ip], $decoded['userId'], $agentId);
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
    $agentId = $_GET['id'] ?? '';
    if (!$agentId) json_error('agentId requerido');

    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);

    $db = Database::getInstance();
    $db->deleteOne('agents', ['_id' => $agent['_id']]);
    $db->deleteOne('host_monitor', ['agentId' => $agentId]);
    audit_log('agent_deleted', ['agentId' => $agentId, 'hostname' => $agent['hostname'] ?? ''], $agent['userId'] ?? null, $agentId);
    json_response(['success' => true]);
}

function sendCommand() {
    $user = Auth::requireAuth();
    $agentId = $_GET['id'] ?? '';
    $body = get_body();
    $command = $body['command'] ?? '';
    $params = $body['params'] ?? [];

    if (!$agentId || !$command) json_error('agentId y command requeridos');
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

    // El WS server ahora pollea MongoDB directamente cada 1s para agentes conectados
    // No se necesita archivo trigger

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
    audit_log('agent_deploy_created', ['deployId' => $deployId, 'platform' => $platform], $user['_id']);
    json_response(['success' => true, 'deployId' => $deployId]);
}

function requestData() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? '';
    $type = $body['type'] ?? '';
    if (!$agentId || !in_array($type, ['processes', 'health', 'defender', 'screenshot'])) json_error('agentId y type requeridos');
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $db->insertOne('agent_commands', [
        'userId' => $agent['userId'] ?? $user['_id'],
        'agentId' => $agentId,
        'command' => 'request_data',
        'params' => ['type' => $type],
        'createdAt' => date('c'),
        'executed' => false,
    ]);
    json_response(['success' => true]);
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

function listCommands() {
    $user = Auth::requireAuth();
    $agentId = $_GET['id'] ?? ($_POST['agentId'] ?? '');
    if (!$agentId) json_error('agentId requerido');
    $db = Database::getInstance();
    $agent = findAgentFor($user, $agentId);
    if (!$agent) json_error('agente no encontrado', 404);
    $cmds = $db->find('agent_commands', ['agentId' => $agentId]);
    usort($cmds, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    json_response(array_slice($cmds, 0, 50));
}

function setLockdown() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? '';
    $action = $body['action'] ?? '';
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

    // Optional deploy ID to track downloads
    $deployId = $_GET['deploy'] ?? '';
    if ($deployId) {
        $db = Database::getInstance();
        $deploy = $db->findOne('agent_deploys', ['_id' => $deployId, 'userId' => $user['_id']]);
        if ($deploy) {
            $db->updateOne('agent_deploys', ['_id' => $deployId], ['$inc' => ['downloadCount' => 1], 'status' => 'downloaded']);
        }
    }

    // ── Windows: generar ZIP on-the-fly CON TOKEN del usuario ──
    if ($platform === 'win-x64') {
        // Generar siempre un ZIP personalizado con el token del usuario
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

        $basePath = 'C:\\Program Files\\SecureLab Agent';
        $baseUrl = API_BASE_URL !== '' ? API_BASE_URL : 'http://localhost:3838';
        $wsBase  = preg_replace('#^https?://#', 'ws://', rtrim($baseUrl, '/'));

        $config = [
            'api_base'           => rtrim($baseUrl, '/') . '/api/agents',
            'ws_url'             => $wsBase . '/ws/',
            'token'              => $token,
            'heartbeat_interval' => 5,
            'agent_version'      => '2.0.0',
            'audit_db_path'      => $basePath . DIRECTORY_SEPARATOR . 'audit.db',
            'knowledge_db_path'  => $basePath . DIRECTORY_SEPARATOR . 'knowledge.db',
            'log_file'           => $basePath . DIRECTORY_SEPARATOR . 'agent.log',
            'hardening_enabled'  => true,
            'persistence_mode'   => 'aggressive',
            'log_level'          => 'info',
        ];

        file_put_contents(
            $tmpDir . '/config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        // Copiar el .bat y .ps1 de auto-instalación
        $batSrc = __DIR__ . '/../installer/Install-SecureLabAgent.bat';
        $ps1Src = __DIR__ . '/../installer/Install-SecureLabAgent.ps1';
        $readmeSrc = __DIR__ . '/../installer/README.txt';
        if (!file_exists($batSrc) || !file_exists($ps1Src) || !file_exists($readmeSrc)) {
            json_error('Instalador incompleto: faltan los scripts de auto-instalación', 500);
        }
        copy($batSrc, $tmpDir . '/Install-SecureLabAgent.bat');
        copy($ps1Src, $tmpDir . '/Install-SecureLabAgent.ps1');

        // README con instrucciones
        $folderName = 'SecureLabAgent-Windows';
        $readme = "========================================\n"
                . "  SecureLab Agent - Instalador Windows\n"
                . "========================================\n\n"
                . "1. Haz DOBLE CLIC en el archivo:\n"
                . "   Install-SecureLabAgent.bat\n\n"
                . "2. Acepta el Control de Cuentas de Usuario (UAC) cuando aparezca.\n\n"
                . "3. El agente se instalará en:\n"
                . "   C:\\Program Files\\SecureLab Agent\n\n"
                . "4. El servicio SecureLabAgent se iniciará automáticamente.\n\n"
                . "No necesitas abrir los otros archivos manualmente.\n";
        file_put_contents($tmpDir . '/README.txt', $readme);

        // Fallback: ZIP con todos los archivos dentro de una carpeta
        $archiveName = 'SecureLabAgent-Windows-Installer.zip';
        $archivePath = sys_get_temp_dir() . '/' . $archiveName;
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE);
        $zip->addFile($tmpDir . '/' . $binaryName, $folderName . '/securelab-agent.exe');
        $zip->addFile($tmpDir . '/config.json', $folderName . '/config.json');
        $zip->addFile($tmpDir . '/Install-SecureLabAgent.bat', $folderName . '/Install-SecureLabAgent.bat');
        $zip->addFile($tmpDir . '/Install-SecureLabAgent.ps1', $folderName . '/Install-SecureLabAgent.ps1');
        $zip->addFile($tmpDir . '/README.txt', $folderName . '/README.txt');
        $zip->close();

        $size = filesize($archivePath);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $archiveName . '"');
        header('Content-Length: ' . $size);
        readfile($archivePath);

        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
        unlink($archivePath);
        exit;
    }

    // ── Linux / macOS: tar.gz (sin cambios) ──
    $binaryMap = [
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

    $basePath = '/opt/securelab-agent';
    $baseUrl = API_BASE_URL !== '' ? API_BASE_URL : 'http://localhost:3838';
    $wsBase  = preg_replace('#^https?://#', 'ws://', rtrim($baseUrl, '/'));

    $config = [
        'api_base'           => rtrim($baseUrl, '/') . '/api/agents',
        'ws_url'             => $wsBase . '/ws/',
        'token'              => $token,
        'heartbeat_interval' => 5,
        'agent_version'      => '2.0.0',
        'audit_db_path'      => $basePath . DIRECTORY_SEPARATOR . 'audit.db',
        'knowledge_db_path'  => $basePath . DIRECTORY_SEPARATOR . 'knowledge.db',
        'log_file'           => $basePath . DIRECTORY_SEPARATOR . 'agent.log',
        'hardening_enabled'  => true,
        'persistence_mode'   => 'aggressive',
        'log_level'          => 'info',
    ];

    file_put_contents(
        $tmpDir . '/config.json',
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $archiveName = 'SecureLab-Agent-' . $platform . '.tar.gz';
    $tarPath = sys_get_temp_dir() . '/agent-' . uniqid('', true) . '.tar';
    $phar = new PharData($tarPath);
    $phar->addFile($tmpDir . '/' . $binaryName, $binaryName);
    $phar->addFile($tmpDir . '/config.json', 'config.json');
    $phar->compress(Phar::GZ);
    unset($phar);
    Phar::unlinkArchive($tarPath);
    $archivePath = $tarPath . '.gz';

    $size = filesize($archivePath);
    header('Content-Type: application/gzip');
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