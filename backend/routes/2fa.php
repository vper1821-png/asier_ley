<?php
// 2FA / TOTP routes

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode($data) {
    $map = BASE32_ALPHABET;
    $out = '';
    $bits = 0;
    $value = 0;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $value = ($value << 8) | ord($data[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $map[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) {
        $out .= $map[($value << (5 - $bits)) & 31];
    }
    return $out;
}

function base32_decode($data) {
    $map = array_flip(str_split(BASE32_ALPHABET));
    $out = '';
    $bits = 0;
    $value = 0;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $char = strtoupper($data[$i]);
        if (!isset($map[$char])) continue;
        $value = ($value << 5) | $map[$char];
        $bits += 5;
        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }
    return $out;
}

function generateSecret() {
    return base32_encode(random_bytes(20));
}

function totp($secret, $timeStep = null) {
    $key = base32_decode($secret);
    $timeStep = intdiv($timeStep ?? time(), 30);
    $msg = pack('N', 0) . pack('N', $timeStep);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $bin = ((ord($hash[$offset]) & 0x7f) << 24) |
           ((ord($hash[$offset + 1]) & 0xff) << 16) |
           ((ord($hash[$offset + 2]) & 0xff) << 8) |
           (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
}

function verifyCode($secret, $code) {
    $now = time();
    foreach ([-1, 0, 1] as $step) {
        if (hash_equals(totp($secret, $now + ($step * 30)), (string)$code)) {
            return true;
        }
    }
    return false;
}

function setup() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $secret = generateSecret();
    $db->updateOne('users', ['_id' => $user['_id']], ['twoFactorTempSecret' => $secret]);

    $email = $user['email'] ?? '';
    $issuer = urlencode('SecureLab');
    $label = urlencode('SecureLab:' . $email);
    $qrUrl = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";

    json_response([
        'success' => true,
        'secret' => $secret,
        'qrCodeUrl' => $qrUrl,
    ]);
}

function verify() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $code = $body['code'] ?? '';
    if (!$code) json_error('código requerido');

    $fullUser = $db->findOne('users', ['_id' => $user['_id']]);
    $secret = $fullUser['twoFactorTempSecret'] ?? $fullUser['twoFactorSecret'] ?? '';
    if (!$secret) json_error('2FA no iniciado', 400);

    if (!verifyCode($secret, $code)) json_error('código incorrecto');

    $db->updateOne('users', ['_id' => $user['_id']], [
        'twoFactorSecret' => $secret,
        'twoFactorEnabled' => true,
    ]);
    json_response(['success' => true, 'enabled' => true]);
}

function disable2FA() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $password = $body['password'] ?? '';
    if (!$password) json_error('contraseña requerida');

    $fullUser = $db->findOne('users', ['_id' => $user['_id']]);
    if (!Auth::verifyPassword($password, $fullUser['password'])) {
        json_error('contraseña incorrecta');
    }

    $db->updateOne('users', ['_id' => $user['_id']], [
        'twoFactorEnabled' => false,
        'twoFactorSecret' => null,
        'twoFactorTempSecret' => null,
    ]);
    json_response(['success' => true]);
}

function completeLogin() {
    $body = get_body();
    $tempToken = $body['tempToken'] ?? '';
    $code = $body['code'] ?? '';

    if (!$tempToken || !$code) json_error('tempToken y código requeridos');

    $decoded = Auth::verifyToken($tempToken);
    if (!$decoded || ($decoded['purpose'] ?? '') !== '2fa') json_error('tempToken inválido', 401);

    $db = Database::getInstance();
    $user = $db->findOne('users', ['_id' => $decoded['userId']]);
    if (!$user) json_error('usuario no encontrado', 404);
    if (empty($user['twoFactorEnabled'])) json_error('2FA no habilitado');
    if (!verifyCode($user['twoFactorSecret'] ?? '', $code)) json_error('código incorrecto');

    $token = Auth::createToken($user['_id']);
    unset($user['password']);
    json_response(['success' => true, 'token' => $token, 'user' => $user]);
}
