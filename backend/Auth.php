<?php
// SecureLab2v - JWT Authentication

class Auth {
    // JWT implementation (no external dependencies)
    public static function createToken($userId, $payload = []) {
        $header = self::base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['userId'] = $userId;
        $payload['iat'] = time();
        $payload['exp'] = $payload['exp'] ?? (time() + (7 * 24 * 3600)); // 7 days default
        $payloadEncoded = self::base64url_encode(json_encode($payload));
        $signature = self::base64url_encode(
            hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
        );
        return "$header.$payloadEncoded.$signature";
    }

    public static function verifyToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;
        $expectedSig = self::base64url_encode(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSig, $signature)) return null;

        $data = json_decode(self::base64url_decode($payload), true);
        if (!$data) return null;
        if (isset($data['exp']) && $data['exp'] < time()) return null;

        return $data;
    }

    public static function requireAuth() {
        $token = get_token();
        if (!$token) json_error('token requerido', 401);

        $decoded = self::verifyToken($token);
        if (!$decoded) json_error('token inválido', 401);

        $db = Database::getInstance();
        $user = $db->findOne('users', ['_id' => $decoded['userId']]);
        if (!$user) json_error('usuario no encontrado', 401);
        if (empty($user['isActive'])) json_error('cuenta no activa', 403);

        // Remove password from user object
        unset($user['password']);
        return $user;
    }

    public static function requireAdmin() {
        $user = self::requireAuth();
        if (empty($user['isAdmin']) && ($user['role'] ?? 'user') !== 'admin' && ($user['role'] ?? 'user') !== 'superadmin') {
            json_error('acceso denegado', 403);
        }
        return $user;
    }

    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
