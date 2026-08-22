<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== VERIFICANDO HARDENING ===\n\n";

$config = $db->findOne('compliance_config', ['userId' => $userId]);

if ($config) {
    echo "Configuración encontrada:\n";
    echo "Hardening completado: " . ($config['hardeningCompleted'] ? 'Si' : 'No') . "\n";
    echo "Fecha: " . ($config['hardeningCompletedAt'] ?? 'N/A') . "\n";

    if (!empty($config['measureOverrides'])) {
        $measures = json_decode($config['measureOverrides'], true);
        if (is_array($measures)) {
            echo "\nMedidas implementadas (" . count($measures) . "):\n";
            foreach ($measures as $m) {
                $status = $m['completed'] ? '✓' : '✗';
                echo "$status {$m['measureId']}: {$m['notes']}\n";
            }
        }
    }
} else {
    echo "Configuración no encontrada\n";
}