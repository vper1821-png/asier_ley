<?php
// Account routes

function update() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $updates = [];
    if (isset($body['name'])) $updates['companyName'] = $body['name'];
    if (isset($body['companyName'])) $updates['companyName'] = $body['companyName'];

    if (!empty($updates)) {
        $db->updateOne('users', ['_id' => $user['_id']], $updates);
    }

    json_response(['success' => true]);
}

function changePassword() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $currentPassword = $body['currentPassword'] ?? '';
    $newPassword = $body['newPassword'] ?? '';

    if (!$newPassword) json_error('nueva contraseña requerida');
    if (strlen($newPassword) < 8) json_error('la nueva contraseña debe tener al menos 8 caracteres');

    $fullUser = $db->findOne('users', ['_id' => $user['_id']]);
    if ($currentPassword && !Auth::verifyPassword($currentPassword, $fullUser['password'])) {
        json_error('contraseña actual incorrecta');
    }

    $newVersion = ($fullUser['tokenVersion'] ?? 1) + 1;
    $db->updateOne('users', ['_id' => $user['_id']], [
        'password' => Auth::hashPassword($newPassword),
        'tokenVersion' => $newVersion,
    ]);

    $newToken = Auth::createToken($user['_id'], ['tokenVersion' => $newVersion]);

    json_response(['success' => true, 'token' => $newToken]);
}

function changeEmail() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $newEmail = strtolower(trim($body['newEmail'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$newEmail || !$password) json_error('nuevo email y contraseña requeridos');
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) json_error('email inválido');

    $fullUser = $db->findOne('users', ['_id' => $user['_id']]);
    if (!Auth::verifyPassword($password, $fullUser['password'])) {
        json_error('contraseña actual incorrecta');
    }

    $existing = $db->findOne('users', ['email' => $newEmail]);
    if ($existing && $existing['_id'] !== $user['_id']) {
        json_error('email ya registrado');
    }

    $newVersion = ($fullUser['tokenVersion'] ?? 1) + 1;
    $db->updateOne('users', ['_id' => $user['_id']], [
        'email' => $newEmail,
        'tokenVersion' => $newVersion,
    ]);

    $newToken = Auth::createToken($user['_id'], ['tokenVersion' => $newVersion]);

    json_response(['success' => true, 'email' => $newEmail, 'token' => $newToken]);
}

function logoutAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $fullUser = $db->findOne('users', ['_id' => $user['_id']]);
    $newVersion = ($fullUser['tokenVersion'] ?? 1) + 1;
    $db->updateOne('users', ['_id' => $user['_id']], ['tokenVersion' => $newVersion]);
    $newToken = Auth::createToken($user['_id'], ['tokenVersion' => $newVersion]);
    json_response(['success' => true, 'token' => $newToken]);
}
