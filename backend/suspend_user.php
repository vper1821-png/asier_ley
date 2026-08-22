<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

echo "=== SUSPENDIENDO USUARIO PARA QUE REQUIERA APROBACIÓN ===\n\n";

$email = 'asiersinmas2004@gmail.com';
$user = $db->findOne('users', ['email' => $email]);

if ($user) {
    echo "Usuario encontrado: $email\n";
    echo "Estado actual: " . (!empty($user['isActive']) ? 'Activo' : 'Suspendido') . "\n";

    $result = $db->updateOne('users', ['email' => $email], ['isActive' => false]);

    if ($result) {
        echo "✓ Usuario suspendido correctamente\n";
        echo "Ahora requerirá aprobación del admin para acceder.\n";
    } else {
        echo "✗ Error al suspender usuario\n";
    }
} else {
    echo "Usuario no encontrado\n";
}