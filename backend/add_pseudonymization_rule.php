<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== CREANDO REGLA DE SEUDONIMIZACIÓN DE EJEMPLO ===\n\n";

$rule = [
    'userId' => $userId,
    'name' => 'Seudonimización RUT Clientes',
    'code' => 'PSEUDO-001',
    'technique' => 'tokenizacion',
    'dataCategories' => 'identificacion, contactos',
    'columns' => 'rut, email, nombre_completo',
    'algorithm' => 'AES-256-GCM',
    'keyRotation' => '90 días',
    'reversibility' => 'reversible_con_autorizacion',
    'status' => 'executed',
    'description' => 'Seudonimización de datos identificadores de clientes en base de datos de producción para análisis de datos sin acceso a información personal.',
    'evidenceUrl' => 'https://venmax.cl/docs/pseudo-rut-clientes.pdf',
    'createdAt' => date('c'),
    'updatedAt' => date('c')
];

$result = $db->insertOne('compliance_pseudonymization', $rule);

if ($result) {
    echo "✓ Regla de seudonimización creada correctamente\n";
    echo "  Nombre: {$rule['name']}\n";
    echo "  Técnica: {$rule['technique']}\n";
    echo "  Estado: {$rule['status']}\n";
    echo "\nAhora aparecerá como completado en el checklist de Compliance.\n";
} else {
    echo "✗ Error al crear la regla\n";
}