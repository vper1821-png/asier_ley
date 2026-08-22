<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

echo "=== VERIFICANDO ESTADO DE USUARIOS ===\n\n";

$users = $db->find('users', []);

echo "Total de usuarios: " . count($users) . "\n\n";

$activeUsers = 0;
$suspendedUsers = 0;

foreach ($users as $u) {
    $isActive = !empty($u['isActive']);
    $email = $u['email'] ?? 'sin email';
    $role = $u['role'] ?? 'user';
    $planType = $u['planType'] ?? 'no definido';
    $created = $u['createdAt'] ?? 'no fecha';

    if ($isActive) {
        $activeUsers++;
        echo "✓ ACTIVO: $email | Role: $role | Plan: $planType | Creado: $created\n";
    } else {
        $suspendedUsers++;
        echo "✗ SUSPENDIDO: $email | Role: $role | Plan: $planType | Creado: $created\n";
    }
}

echo "\nResumen:\n";
echo "Activos: $activeUsers\n";
echo "Suspendidos: $suspendedUsers\n";