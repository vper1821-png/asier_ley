<?php
// Auth routes

function login() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$email || !$password) json_error('email y contraseña requeridos');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['email' => $email]);

    if (!$user || !Auth::verifyPassword($password, $user['password'])) {
        audit_log('login_failed', ['email' => $email], $user['_id'] ?? null);
        json_error('credenciales inválidas');
    }

    unset($user['password']);

    if (!empty($user['twoFactorEnabled'])) {
        audit_log('login_2fa_required', ['email' => $email], $user['_id']);
        $tempToken = Auth::createToken($user['_id'], [
            'purpose' => '2fa',
            'exp' => time() + 600,
        ]);
        json_response([
            'requireTwoFactor' => true,
            'tempToken' => $tempToken,
        ]);
    }

    audit_log('login_success', ['email' => $email], $user['_id']);
    $token = Auth::createToken($user['_id']);
    json_response([
        'token' => $token,
        'user' => $user,
    ]);
}

function register() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';
    $name = $body['name'] ?? '';

    if (!$email || !$password) json_error('email y contraseña requeridos');
    if (strlen($password) < 8) json_error('la contraseña debe tener al menos 8 caracteres');

    $db = Database::getInstance();
    $existing = $db->findOne('users', ['email' => $email]);
    if ($existing) json_error('el email ya está registrado');

    $user = $db->insertOne('users', [
        'email' => $email,
        'password' => Auth::hashPassword($password),
        'companyName' => $name ?: explode('@', $email)[0],
        'isActive' => true,
        'isAdmin' => false,
        'role' => 'user',
        'planType' => 'free',
        'paymentStatus' => 'active',
        'onboardingComplete' => false,
    ]);

    $token = Auth::createToken($user['_id']);
    unset($user['password']);
    audit_log('user_registered', ['email' => $email, 'companyName' => $user['companyName'] ?? ''], $user['_id']);

    json_response([
        'token' => $token,
        'user' => $user,
    ]);
}

function verify() {
    $user = Auth::requireAuth();
    json_response(['valid' => true, 'user' => $user]);
}

function forgotPassword() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));

    if (!$email) json_error('email requerido');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['email' => $email]);

    // Always return generic success to avoid email enumeration
    $successMessage = 'Si el email existe, recibirás instrucciones para restablecer tu contraseña';

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = new MongoDB\BSON\UTCDateTime((time() + 3600) * 1000);
        $db->updateOne('users', ['_id' => $user['_id']], [
            'resetToken' => $token,
            'resetExpires' => $expires,
        ]);
    }

    json_response(['success' => true, 'message' => $successMessage]);
}

function resetPassword() {
    $body = get_body();
    $token = trim($body['token'] ?? '');
    $password = $body['password'] ?? '';

    if (!$token || !$password) json_error('token y contraseña requeridos');
    if (strlen($password) < 8) json_error('la contraseña debe tener al menos 8 caracteres');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['resetToken' => $token]);
    if (!$user) json_error('token inválido', 404);

    $expires = $user['resetExpires'] ?? null;
    if ($expires instanceof MongoDB\BSON\UTCDateTime && $expires->toDateTime()->getTimestamp() < time()) {
        json_error('token expirado', 403);
    }

    $db->updateOne('users', ['_id' => $user['_id']], [
        'password' => Auth::hashPassword($password),
        'resetToken' => null,
        'resetExpires' => null,
    ]);

    json_response(['success' => true, 'message' => 'contraseña restablecida']);
}
