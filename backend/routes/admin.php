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

    if (!empty($updates)) $db->updateOne('users', ['_id' => $targetUserId], $updates);
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
    if (!empty($updates)) $db->updateOne('users', ['_id' => $userId], $updates);
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
    $limit = (int)($body['limit'] ?? 200);
    $db = Database::getInstance();
    $logs = $db->find('audit_logs', [], ['limit' => $limit]);
    json_response($logs);
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

function isAdmin($user) {
    return !empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin';
}
