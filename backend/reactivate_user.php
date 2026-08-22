<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

echo "=== REACTIVANDO CUENTA SUPERADMIN ===\n\n";

$email = 'asiersinmas2004@gmail.com';
$user = $db->findOne('users', ['email' => $email]);

if ($user) {
    echo "Usuario encontrado: $email\n";
    echo "Estado actual: " . (!empty($user['isActive']) ? 'Activo' : 'Suspendido') . "\n";
    echo "Rol: " . ($user['role'] ?? 'user') . "\n";

    $result = $db->updateOne('users', ['email' => $email], ['isActive' => true]);

    if ($result) {
        echo "✓ Cuenta reactivada correctamente\n";
        echo "Ahora puedes acceder nuevamente.\n";
    } else {
        echo "✗ Error al reactivar cuenta\n";
    }
} else {
    echo "Usuario no encontrado\n";
}