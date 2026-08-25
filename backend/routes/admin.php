<?php
// Admin routes

function users() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    $users = $db->find('users', []);
    foreach ($users as &$u) unset($u['password']);
    json_response($users);
}

function userDetail() {
    Auth::requireAdmin();
    $userId = $_GET['userId'] ?? '';
    if (!$userId) json_error('userId requerido');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['_id' => $userId]);
    if (!$user) json_error('usuario no encontrado');
    unset($user['password']);
    json_response($user);
}

function alerts() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    $alerts = $db->find('alerts', []);
    json_response($alerts);
}

function publicAlerts() {
    $db = Database::getInstance();
    $alerts = $db->find('alerts', ['showOnLanding' => true]);
    json_response($alerts);
}

function info() {
    $user = Auth::requireAuth();
    unset($user['password']);
    json_response($user);
}

function listAll() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    $users = $db->find('users', []);
    foreach ($users as &$u) unset($u['password']);
    json_response($users);
}

function update() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $targetUserId = $body['userId'] ?? $user['_id'];
    if ($targetUserId !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $target = $db->findOne('users', ['_id' => $targetUserId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $allowed = ['companyName', 'domain', 'name', 'paymentStatus', 'planType'];
    $updates = [];
    foreach ($allowed as $field) {
        if (isset($body[$field])) $updates[$field] = $body[$field];
    }
    if (isset($body['isActive']) && isAdmin($user)) $updates['isActive'] = filter_var($body['isActive'], FILTER_VALIDATE_BOOLEAN);
    if (isset($body['role']) && isAdmin($user)) $updates['role'] = $body['role'];
    if (isset($body['suspensionReason'])) $updates['suspensionReason'] = $body['suspensionReason'];
    if (isset($body['aiRetention'])) $updates['aiRetention'] = $body['aiRetention'];

    if (!empty($updates)) {
        $db->updateOne('users', ['_id' => $targetUserId], $updates);
        audit_log('admin_user_updated', ['targetUserId' => $targetUserId, 'targetEmail' => $target['email'] ?? '', 'changes' => array_keys($updates), 'details' => $updates], null);
    }
    json_response(['success' => true]);
}

function plans() {
    Auth::requireAuth();
    $db = Database::getInstance();
    $plans = $db->find('plans', []);
    if (empty($plans)) {
        $plans = [
            ['name' => 'Free', 'price' => 0, 'agents' => 3, 'databases' => 1, 'support' => 'email'],
            ['name' => 'Pro', 'price' => 49, 'agents' => 20, 'databases' => 10, 'support' => 'priority'],
            ['name' => 'Enterprise', 'price' => 199, 'agents' => -1, 'databases' => -1, 'support' => '24/7'],
        ];
    }
    json_response($plans);
}

function savePlans() {
    Auth::requireAdmin();
    $body = get_body();
    $plans = json_decode($body['plans'] ?? '[]', true);
    $db = Database::getInstance();
    foreach ($plans as $plan) {
        $existing = $db->findOne('plans', ['name' => $plan['name'] ?? '']);
        if ($existing) {
            $db->updateOne('plans', ['_id' => $existing['_id']], $plan);
        } else {
            $db->insertOne('plans', $plan);
        }
    }
    json_response(['success' => true]);
}

function createUser() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();

    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $companyName = $body['companyName'] ?? '';
    $domain = $body['domain'] ?? '';
    $planType = $body['planType'] ?? 'free';
    $role = $body['role'] ?? 'user';
    $isActive = filter_var($body['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN);

    if (!$email || !$password) json_error('email y contraseña requeridos');
    if (strlen($password) < 8) json_error('la contraseña debe tener al menos 8 caracteres');
    if ($db->findOne('users', ['email' => $email])) json_error('email ya registrado');

    $user = $db->insertOne('users', [
        'email' => $email,
        'password' => Auth::hashPassword($password),
        'companyName' => $companyName ?: explode('@', $email)[0],
        'domain' => $domain,
        'isActive' => $isActive,
        'isAdmin' => ($role === 'admin' || $role === 'superadmin'),
        'role' => $role,
        'planType' => $planType,
        'paymentStatus' => 'active',
        'onboardingComplete' => false,
    ]);
    unset($user['password']);
    audit_log('admin_user_created', ['targetEmail' => $email, 'role' => $role], null);
    json_response(['success' => true, 'user' => $user]);
}

function resetPassword() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $userId = $body['userId'] ?? '';
    $newPassword = $body['newPassword'] ?? '';
    if (!$userId || !$newPassword) json_error('userId y nueva contraseña requeridos');
    if (strlen($newPassword) < 8) json_error('la contraseña debe tener al menos 8 caracteres');

    $target = $db->findOne('users', ['_id' => $userId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $db->updateOne('users', ['_id' => $userId], ['password' => Auth::hashPassword($newPassword)]);
    audit_log('admin_password_reset', ['targetEmail' => $target['email'] ?? ''], null);
    json_response(['success' => true]);
}

function updateUser() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $userId = $body['userId'] ?? '';
    if (!$userId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $userId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $allowed = ['companyName', 'domain', 'planType', 'paymentStatus', 'isActive', 'role', 'suspensionReason', 'aiRetention'];
    $updates = [];
    foreach ($allowed as $field) {
        if (isset($body[$field])) $updates[$field] = $body[$field];
    }
    if (isset($updates['role'])) $updates['isAdmin'] = ($updates['role'] === 'admin' || $updates['role'] === 'superadmin');
    if (!empty($updates)) {
        $db->updateOne('users', ['_id' => $userId], $updates);
        audit_log('admin_user_updated', ['targetEmail' => $target['email'] ?? '', 'changes' => array_keys($updates), 'details' => $updates], null);
    }
    json_response(['success' => true]);
}

function deleteUserFull() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $userId = $body['userId'] ?? '';
    if (!$userId) json_error('userId requerido');
    if ($userId === ADMIN_EMAIL) json_error('no se puede eliminar el admin principal');

    $target = $db->findOne('users', ['_id' => $userId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $db->deleteOne('users', ['_id' => $userId]);
    $db->deleteOne('onboarding', ['userId' => $userId]);
    audit_log('admin_user_deleted', ['targetEmail' => $target['email'] ?? ''], null);
    json_response(['success' => true]);
}

function userDetails() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $userId = $body['userId'] ?? '';
    if (!$userId) json_error('userId requerido');

    $user = $db->findOne('users', ['_id' => $userId]);
    if (!$user) json_error('usuario no encontrado', 404);
    unset($user['password']);

    json_response([
        'user' => $user,
        'alerts' => $db->find('alerts', ['userId' => $userId]),
        'agents' => $db->find('agents', ['userId' => $userId]),
        'databases' => $db->find('databases', ['userId' => $userId]),
        'payments' => $db->find('payments', ['userId' => $userId]),
    ]);
}

function adminAlerts() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    $alerts = $db->find('admin_alerts', []);
    json_response($alerts);
}

function reset2FA() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $userId = $body['userId'] ?? '';
    if (!$userId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $userId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $db->updateOne('users', ['_id' => $userId], ['twoFactorSecret' => null, 'twoFactorEnabled' => false]);
    audit_log('admin_2fa_reset', ['targetEmail' => $target['email'] ?? ''], null);
    json_response(['success' => true]);
}

function dashboardStatus() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    json_response([
        'totalUsers' => $db->count('users'),
        'totalAgents' => $db->count('agents'),
        'totalDatabases' => $db->count('databases'),
        'totalAlerts' => $db->count('alerts'),
        'maintenanceMode' => false,
    ]);
}

function auditLogs() {
    Auth::requireAdmin();
    $body = get_body();
    $limit = min(2000, max(10, (int)($body['limit'] ?? 300)));
    $db = Database::getInstance();

    $filter = [];
    if (!empty($body['userId'])) $filter['userId'] = $body['userId'];
    if (!empty($body['agentId'])) $filter['agentId'] = $body['agentId'];
    if (!empty($body['action'])) $filter['action'] = $body['action'];

    $from = trim($body['from'] ?? '');
    $to = trim($body['to'] ?? '');
    if ($from !== '' || $to !== '') {
        $range = [];
        if ($from !== '') $range['$gte'] = (strlen($from) === 10) ? $from . 'T00:00:00' : $from;
        if ($to !== '') $range['$lte'] = (strlen($to) === 10) ? $to . 'T23:59:59' : $to;
        $filter['createdAt'] = $range;
    }

    $q = trim($body['q'] ?? '');
    if ($q !== '') {
        $rx = ['$regex' => preg_quote($q, '/'), '$options' => 'i'];
        $filter['$or'] = [
            ['action' => $rx],
            ['userEmail' => $rx],
            ['companyName' => $rx],
            ['agentId' => $rx],
        ];
    }

    $logs = $db->find('audit_logs', $filter, ['sort' => ['createdAt' => -1], 'limit' => $limit]);
    json_response(['logs' => $logs, 'total' => count($logs)]);
}

function adminReports() {
    Auth::requireAdmin();
    $db = Database::getInstance();
    $reports = $db->find('reports', []);
    json_response($reports);
}

function deleteReport() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $reportId = $body['reportId'] ?? '';
    if (!$reportId) json_error('reportId requerido');
    $db->deleteOne('reports', ['_id' => $reportId]);
    json_response(['success' => true]);
}

function maintenanceStatus() {
    Auth::requireAuth();
    $db = Database::getInstance();
    $status = $db->findOne('maintenance', ['scope' => 'global']);
    json_response([
        'maintenanceMode' => $status['enabled'] ?? false,
        'maintenanceMessage' => $status['message'] ?? '',
    ]);
}

function toggleMaintenance() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $message = $body['message'] ?? '';

    $existing = $db->findOne('maintenance', ['scope' => 'global']);
    if ($existing) {
        $db->updateOne('maintenance', ['_id' => $existing['_id']], ['enabled' => $enabled, 'message' => $message]);
    } else {
        $db->insertOne('maintenance', ['scope' => 'global', 'enabled' => $enabled, 'message' => $message]);
    }
    audit_log('maintenance_toggled', ['enabled' => $enabled, 'message' => $message], null);
    json_response(['success' => true, 'maintenanceMode' => $enabled]);
}

function activityLogs() {
    Auth::requireAuth();
    $body = get_body();
    $limit = (int)($body['limit'] ?? 200);
    $db = Database::getInstance();
    $filter = [];
    if (!empty($body['userId'])) $filter['userId'] = $body['userId'];
    $logs = $db->find('activity_logs', $filter, ['limit' => $limit]);
    json_response($logs);
}

function saveAdminAlert() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();

    $data = [
        'title' => $body['title'] ?? 'Alerta',
        'message' => $body['message'] ?? '',
        'severity' => $body['severity'] ?? 'info',
        'showOnLanding' => filter_var($body['showOnLanding'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'enabled' => filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'updatedAt' => date('c'),
    ];

    $id = $body['id'] ?? $body['alertId'] ?? '';
    if ($id) {
        $existing = $db->findOne('admin_alerts', ['_id' => $id]);
        if (!$existing) json_error('alerta no encontrada', 404);
        $db->updateOne('admin_alerts', ['_id' => $id], $data);
    } else {
        $data['createdAt'] = date('c');
        $db->insertOne('admin_alerts', $data);
    }

    json_response(['success' => true]);
}

function toggleAdminAlert() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $id = $body['alertId'] ?? $body['id'] ?? '';
    $enabled = filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
    if (!$id) json_error('alertId requerido');

    $existing = $db->findOne('admin_alerts', ['_id' => $id]);
    if (!$existing) json_error('alerta no encontrada', 404);
    $db->updateOne('admin_alerts', ['_id' => $id], ['enabled' => $enabled, 'updatedAt' => date('c')]);
    json_response(['success' => true]);
}

function deleteAdminAlert() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();
    $id = $body['alertId'] ?? $body['id'] ?? '';
    if (!$id) json_error('alertId requerido');

    $existing = $db->findOne('admin_alerts', ['_id' => $id]);
    if (!$existing) json_error('alerta no encontrada', 404);
    $db->deleteOne('admin_alerts', ['_id' => $id]);
    json_response(['success' => true]);
}

function purgeAi() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $targetUserId = $body['userId'] ?? $user['_id'];
    $target = $db->findOne('users', ['_id' => $targetUserId]);
    if (!$target) json_error('usuario no encontrado', 404);
    if ($targetUserId !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $db->deleteOne('ai_data', ['userId' => $targetUserId]);
    $db->updateOne('users', ['_id' => $targetUserId], ['aiDataPurgedAt' => date('c')]);
    json_response(['success' => true]);
}

function deleteUserById() {
    $user = Auth::requireAuth();
    $targetUserId = $_GET['userId'] ?? '';
    if (!$targetUserId) json_error('userId requerido');
    if ($targetUserId === ($user['_id'] ?? '') && !isAdmin($user)) json_error('no puedes eliminarte a ti mismo', 403);
    if (!isAdmin($user) && $targetUserId !== $user['_id']) json_error('acceso denegado', 403);

    $db = Database::getInstance();
    $db->deleteOne('users', ['_id' => $targetUserId]);
    $db->deleteOne('onboarding', ['userId' => $targetUserId]);
    json_response(['success' => true]);
}

// ── Agent Deploy ──
function agentDeployCreate() {
    Auth::requireAdmin();
    $body = get_body();
    $db = Database::getInstance();

    $platform = $body['platform'] ?? 'windows';
    $userAgent = $body['userAgent'] ?? '';

    // Get current admin user
    $user = Auth::requireAdmin();

    // Create deploy record
    $deploy = [
        'platform' => $platform,
        'userAgent' => $userAgent,
        'createdBy' => $user['_id'] ?? $user['email'] ?? 'admin',
        'createdAt' => date('c'),
        'status' => 'pending_download',
        'downloadCount' => 0,
    ];
    $deployId = $db->insertOne('agent_deploys', $deploy);

    audit_log('agent_deploy_created', ['deployId' => $deployId, 'platform' => $platform], null);

    json_response(['success' => true, 'deployId' => $deployId]);
}

function agentDownload() {
    // This can be called without auth (for the actual download)
    // But we verify the deploy token
    $platform = $_GET['platform'] ?? 'windows';
    $deployId = $_GET['deploy'] ?? '';

    if (!$deployId) {
        http_response_code(400);
        echo 'deploy parameter required';
        exit;
    }

    $db = Database::getInstance();
    $deploy = $db->findOne('agent_deploys', ['_id' => $deployId]);
    
    if (!$deploy) {
        http_response_code(404);
        echo 'Deploy not found';
        exit;
    }

    // Increment download count
    $db->updateOne('agent_deploys', ['_id' => $deployId], ['$inc' => ['downloadCount' => 1], 'status' => 'downloaded']);

    // Map platform to file
    $basePath = __DIR__ . '/../installer';
    $fileMap = [
        'windows'  => 'SecureLabAgent-Installer.exe',
        'win-x64'  => 'SecureLabAgent-Installer.exe',
        'linux'    => 'securelab-agent.deb',
        'darwin'   => 'securelab-agent.pkg',
        'linux-x64' => 'securelab-agent.deb',
        'mac-x64'  => 'securelab-agent.pkg',
    ];

    $fileName = $fileMap[$platform] ?? 'SecureLabAgent-Installer.exe';
    $filePath = $basePath . '/' . $fileName;

    if (!file_exists($filePath)) {
        // Fallback candidate paths
        $altPaths = [
            __DIR__ . '/../SecureLabAgent-Installer.exe',
            __DIR__ . '/../installer/Output/SecureLabAgent-Setup.exe',
        ];
        foreach ($altPaths as $alt) {
            if (file_exists($alt)) {
                $filePath = $alt;
                $fileName = basename($alt);
                break;
            }
        }
    }

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'Installer not found for platform: ' . $platform;
        exit;
    }

    // Set headers for download
    $contentType = 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Pragma: public');
    
    readfile($filePath);
    exit;
}

function isAdmin($user) {
    return !empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin';
}

function isSuperAdmin($user) {
    return ($user['role'] ?? '') === 'superadmin' || !empty($user['isAdmin']) && ($user['role'] ?? '') === 'superadmin';
}

// ── Data Reset Functions ──
function dataResetBackup() {
    $user = Auth::requireAuth();
    if (!isSuperAdmin($user)) json_error('Solo superadministradores pueden crear backups', 403);

    $db = Database::getInstance();
    $backupDir = __DIR__ . '/../data/backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/backup_' . $timestamp . '.json';

    // Collect all data
    $backup = [
        'timestamp' => $timestamp,
        'createdBy' => $user['email'] ?? $user['_id'],
        'collections' => []
    ];

    $collections = ['users', 'agents', 'databases', 'alerts', 'admin_alerts', 'tickets', 'audit_logs', 'activity_logs', 'reports', 'compliance_data', 'arco_requests', 'payments', 'hardening_data', 'maintenance'];
    foreach ($collections as $collection) {
        $backup['collections'][$collection] = $db->find($collection, []);
    }

    file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT));

    audit_log('data_reset_backup', ['backupFile' => basename($backupFile), 'collections' => array_keys($backup['collections'])], null);

    json_response(['success' => true, 'backupFile' => basename($backupFile), 'path' => $backupFile]);
}

function dataResetExecute() {
    $user = Auth::requireAuth();
    if (!isSuperAdmin($user)) json_error('Solo superadministradores pueden ejecutar reset', 403);

    $body = get_body();
    $confirm = $body['confirm'] ?? '';
    $preserveAdmins = filter_var($body['preserve_admins'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

    if ($confirm !== 'RESET') json_error('Confirmación inválida');

    $db = Database::getInstance();

    // Get admin users to preserve if requested
    $adminUsers = [];
    if ($preserveAdmins) {
        $adminUsers = $db->find('users', [
            '$or' => [
                ['role' => 'superadmin'],
                ['role' => 'admin'],
                ['isAdmin' => true]
            ]
        ]);
    }

    // Collections to delete (preserve system config)
    $collectionsToDelete = [
        'agents', 'databases', 'alerts', 'admin_alerts', 'tickets', 
        'audit_logs', 'activity_logs', 'reports', 'compliance_data', 
        'arco_requests', 'payments', 'hardening_data', 'onboarding',
        'ai_data', 'agent_deploys', 'notifications'
    ];

    $deletedCounts = [];

    // Delete all data from collections
    foreach ($collectionsToDelete as $collection) {
        $count = $db->count($collection);
        if ($count > 0) {
            $db->deleteMany($collection, []);
            $deletedCounts[$collection] = $count;
        }
    }

    // Delete non-admin users
    if ($preserveAdmins) {
        $allUsers = $db->find('users', []);
        $deletedUsers = 0;
        foreach ($allUsers as $u) {
            $isAdmin = ($u['role'] ?? '') === 'superadmin' || ($u['role'] ?? '') === 'admin' || !empty($u['isAdmin']);
            if (!$isAdmin) {
                $db->deleteOne('users', ['_id' => $u['_id']]);
                $deletedUsers++;
            }
        }
        $deletedCounts['users'] = $deletedUsers;
    } else {
        $userCount = $db->count('users');
        if ($userCount > 0) {
            $db->deleteMany('users', []);
            $deletedCounts['users'] = $userCount;
        }
    }

    // Reset counters/statistics if they exist
    $statsCollections = ['statistics', 'counters', 'metrics'];
    foreach ($statsCollections as $collection) {
        $count = $db->count($collection);
        if ($count > 0) {
            $db->deleteMany($collection, []);
            $deletedCounts[$collection] = $count;
        }
    }

    // Clear cache/temp data
    $cacheDir = __DIR__ . '/../data/cache';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        $deletedCounts['cache_files'] = count($files);
    }

    // Log the reset operation
    audit_log('data_reset_executed', [
        'preserveAdmins' => $preserveAdmins,
        'deletedCounts' => $deletedCounts,
        'totalCollections' => count($deletedCounts)
    ], null);

    json_response([
        'success' => true,
        'message' => 'Reset completado exitosamente',
        'deletedCounts' => $deletedCounts,
        'preservedAdmins' => count($adminUsers)
    ]);
}
