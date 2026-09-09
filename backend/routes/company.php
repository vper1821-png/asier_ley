<?php
// Company-scoped user management (sub-accounts)

function companyUsers() {
    $user = Auth::requireCompanyAdmin();
    $db = Database::getInstance();
    $companyId = Auth::companyId($user);
    $users = $db->find('users', ['companyId' => $companyId]);
    foreach ($users as &$u) unset($u['password']);
    json_response($users);
}

function companyCreateUser() {
    $user = Auth::requireCompanyAdmin();
    $db = Database::getInstance();
    $body = get_body();

    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $name = trim($body['name'] ?? '');
    $role = $body['role'] ?? 'user';

    if (!$email || !$password) json_error('email y contraseña requeridos');
    if (strlen($password) < 8) json_error('la contraseña debe tener al menos 8 caracteres');

    $allowed = ['user', 'developer', 'dpo', 'company_admin'];
    if (Auth::isAdmin($user)) {
        $allowed[] = 'admin';
        $allowed[] = 'superadmin';
    }
    if (!in_array($role, $allowed, true)) {
        json_error('rol no permitido para esta cuenta', 403);
    }

    if ($db->findOne('users', ['email' => $email])) json_error('email ya registrado');

    $companyId = Auth::isAdmin($user) && !empty($body['companyId'])
        ? $body['companyId']
        : Auth::companyId($user);
    $companyName = !empty($body['companyName'])
        ? $body['companyName']
        : ($user['companyName'] ?? explode('@', $email)[0]);

    $newUser = $db->insertOne('users', [
        'email' => $email,
        'password' => Auth::hashPassword($password),
        'name' => $name,
        'companyName' => $companyName,
        'companyId' => $companyId,
        'parentUserId' => $user['_id'],
        'isActive' => true,
        'isAdmin' => false,
        'role' => $role,
        'planType' => $user['planType'] ?? 'free',
        'paymentStatus' => 'active',
        'onboardingComplete' => false,
        'tokenVersion' => 1,
        'createdAt' => date('c'),
    ]);
    unset($newUser['password']);
    audit_log('company_user_created', [
        'targetEmail' => $email,
        'role' => $role,
        'companyId' => $companyId,
    ], $user['_id']);
    json_response(['success' => true, 'user' => $newUser]);
}

function companyUpdateUser() {
    $user = Auth::requireCompanyAdmin();
    $db = Database::getInstance();
    $body = get_body();
    $targetId = $body['userId'] ?? '';
    if (!$targetId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $targetId]);
    if (!$target) json_error('usuario no encontrado', 404);

    if (!Auth::canManageUser($user, $target)) json_error('acceso denegado', 403);
    if (!Auth::isAdmin($user) && (string)$target['_id'] === (string)Auth::companyId($user) && (string)$user['_id'] !== (string)$target['_id']) {
        json_error('no puedes modificar al titular de la empresa', 403);
    }

    $allowed = ['name', 'companyName', 'isActive'];
    $updates = [];
    foreach ($allowed as $field) {
        if (isset($body[$field])) $updates[$field] = $body[$field];
    }

    if (isset($body['role'])) {
        $allowedRoles = ['user', 'developer', 'dpo', 'company_admin'];
        if (Auth::isAdmin($user)) $allowedRoles[] = 'admin';
        if (Auth::isSuperAdmin($user)) $allowedRoles[] = 'superadmin';
        if (!in_array($body['role'], $allowedRoles, true)) json_error('rol no permitido', 403);
        $updates['role'] = $body['role'];
    }

    if (isset($updates['isActive'])) {
        $updates['isActive'] = filter_var($updates['isActive'], FILTER_VALIDATE_BOOLEAN);
    }

    if (!empty($updates)) {
        $db->updateOne('users', ['_id' => $targetId], $updates);
        audit_log('company_user_updated', [
            'targetUserId' => $targetId,
            'targetEmail' => $target['email'] ?? '',
            'changes' => array_keys($updates),
        ], $user['_id']);
    }
    json_response(['success' => true]);
}

function companyDeleteUser() {
    $user = Auth::requireCompanyAdmin();
    $db = Database::getInstance();
    $body = get_body();
    $targetId = $body['userId'] ?? '';
    if (!$targetId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $targetId]);
    if (!$target) json_error('usuario no encontrado', 404);

    if (!Auth::canManageUser($user, $target)) json_error('acceso denegado', 403);
    if (!Auth::isAdmin($user) && (string)$target['_id'] === (string)Auth::companyId($user)) {
        json_error('no puedes eliminar al titular de la empresa', 403);
    }
    if ((string)$target['_id'] === (string)$user['_id'] && !Auth::isAdmin($user)) {
        json_error('no puedes eliminarte a ti mismo', 403);
    }

    $db->deleteOne('users', ['_id' => $targetId]);
    $db->deleteOne('onboarding', ['userId' => $targetId]);
    audit_log('company_user_deleted', [
        'targetEmail' => $target['email'] ?? '',
        'targetUserId' => $targetId,
        'companyId' => Auth::companyId($user),
    ], $user['_id']);
    json_response(['success' => true]);
}

function companyResetPassword() {
    $user = Auth::requireCompanyAdmin();
    $db = Database::getInstance();
    $body = get_body();
    $targetId = $body['userId'] ?? '';
    $newPassword = $body['newPassword'] ?? '';
    if (!$targetId || !$newPassword) json_error('userId y nueva contraseña requeridos');
    if (strlen($newPassword) < 8) json_error('la contraseña debe tener al menos 8 caracteres');

    $target = $db->findOne('users', ['_id' => $targetId]);
    if (!$target) json_error('usuario no encontrado', 404);
    if (!Auth::canManageUser($user, $target)) json_error('acceso denegado', 403);

    $db->updateOne('users', ['_id' => $targetId], [
        'password' => Auth::hashPassword($newPassword),
        'tokenVersion' => ($target['tokenVersion'] ?? 1) + 1,
    ]);
    audit_log('company_password_reset', [
        'targetEmail' => $target['email'] ?? '',
        'targetUserId' => $targetId,
    ], $user['_id']);
    json_response(['success' => true]);
}

function companyRoles() {
    $user = Auth::requireAuth();
    $roles = [
        ['id' => 'user', 'label' => 'Usuario', 'description' => 'Acceso básico a la plataforma'],
        ['id' => 'developer', 'label' => 'Desarrollador', 'description' => 'Gestiona agentes, bases de datos y logs técnicos'],
        ['id' => 'dpo', 'label' => 'DPO / DPD', 'description' => 'Gestiona cumplimiento, ARCO, inventario y privacidad'],
        ['id' => 'company_admin', 'label' => 'Admin de empresa', 'description' => 'Gestiona usuarios y configuración de su empresa'],
    ];
    if (Auth::isAdmin($user)) {
        $roles[] = ['id' => 'admin', 'label' => 'Admin global', 'description' => 'Acceso total a todas las empresas'];
        $roles[] = ['id' => 'superadmin', 'label' => 'Superadmin', 'description' => 'Control total del sistema'];
    }
    json_response(['roles' => $roles]);
}
