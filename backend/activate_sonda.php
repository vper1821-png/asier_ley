<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

echo "=== ACTIVANDO CUENTA SONDA ===\n\n";

$email = 'sonda@sonda.com';
$user = $db->findOne('users', ['email' => $email]);

if ($user) {
    echo "Usuario encontrado: $email\n";
    echo "Estado actual: " . (!empty($user['isActive']) ? 'Activo' : 'Suspendido') . "\n";
    echo "Rol: " . ($user['role'] ?? 'user') . "\n";

    $result = $db->updateOne('users', ['email' => $email], ['isActive' => true]);

    if ($result) {
        echo "✓ Cuenta activada correctamente\n";
        echo "Ahora puedes acceder.\n";
    } else {
        echo "✗ Error al activar cuenta\n";
    }
} else {
    echo "Usuario no encontrado\n";
}