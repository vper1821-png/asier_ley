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
        $secret = self::getRotatedSecret();
        $signature = self::base64url_encode(
            hash_hmac('sha256', "$header.$payloadEncoded", $secret, true)
        );
        return "$header.$payloadEncoded.$signature";
    }

    public static function getRotatedSecret() {
        $base = JWT_SECRET ?? 'default_secret_change_me';
        $rotation = intdiv(time(), 86400 * 30); // rotate every 30 days
        return hash_hmac('sha256', $base . $rotation, 'rotation_salt', true);
    }

    public static function getRotatedSecretForVerification($rotationOffset = 0) {
        $base = JWT_SECRET ?? 'default_secret_change_me';
        $rotation = intdiv(time(), 86400 * 30) + $rotationOffset;
        return hash_hmac('sha256', $base . $rotation, 'rotation_salt', true);
    }

    public static function verifyToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        // Check current and previous rotation period (for tokens near rotation boundary)
        $valid = false;
        for ($offset = 0; $offset >= -1; $offset--) {
            $secret = self::getRotatedSecretForVerification($offset);
            $expectedSig = self::base64url_encode(
                hash_hmac('sha256', "$header.$payload", $secret, true)
            );
            if (hash_equals($expectedSig, $signature)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) return null;

        $data = json_decode(self::base64url_decode($payload), true);
        if (!$data) return null;
        
        // No expiración para tokens de agentes
        if (isset($data['purpose']) && $data['purpose'] === 'agent_installation') {
            // Tokens de instalación no expiran
        } elseif (isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }

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

        $tokenVersion = $decoded['tokenVersion'] ?? 1;
        $currentVersion = $user['tokenVersion'] ?? 1;
        if ($tokenVersion !== $currentVersion) {
            json_error('sesión cerrada. Vuelve a iniciar sesión.', 401);
        }

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
