<?php
// Passkey routes (WebAuthn) - placeholders for development

function beginLogin() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    if (!$email) json_error('email requerido');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['email' => $email]);
    if (!$user) json_error('usuario no encontrado', 404);

    $challenge = base64url_encode(random_bytes(32));
    $db->updateOne('users', ['_id' => $user['_id']], ['passkeyChallenge' => $challenge]);

    json_response([
        'options' => [
            'challenge' => $challenge,
            'timeout' => 60000,
            'rpId' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'allowCredentials' => [],
            'userVerification' => 'preferred',
        ],
    ]);
}

function finishLogin() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ($body['credential']['email'] ?? '')));
    if (!$email) json_error('email requerido');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['email' => $email]);
    if (!$user) json_error('usuario no encontrado', 404);

    $token = Auth::createToken($user['_id']);
    unset($user['password']);
    json_response(['success' => true, 'token' => $token, 'user' => $user]);
}

function beginRegistration() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    if (!$email) json_error('email requerido');

    $challenge = base64url_encode(random_bytes(32));
    json_response([
        'options' => [
            'challenge' => $challenge,
            'timeout' => 60000,
            'rp' => ['name' => 'SecureLab', 'id' => $_SERVER['HTTP_HOST'] ?? 'localhost'],
            'user' => ['name' => $email, 'id' => base64url_encode(random_bytes(16))],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]],
        ],
    ]);
}

function finishRegistration() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    if (!$email) json_error('email requerido');

    $db = Database::getInstance();
    $user = $db->findOne('users', ['email' => $email]);
    if (!$user) json_error('usuario no encontrado', 404);

    $credentialId = $body['credential']['id'] ?? '';
    if ($credentialId) {
        $passkeys = $user['passkeys'] ?? [];
        $passkeys[] = ['id' => $credentialId, 'createdAt' => date('c')];
        $db->updateOne('users', ['_id' => $user['_id']], ['passkeys' => $passkeys]);
    }

    json_response(['success' => true]);
}

function register() {
    $user = Auth::requireAuth();
    json_response(['success' => true, 'message' => 'Use /api/passkey/beginRegistration']);
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
