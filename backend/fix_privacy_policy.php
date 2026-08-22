<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== ACTUALIZANDO POLÍTICA DE PRIVACIDAD ===\n\n";

// Actualizar la configuración para marcar la política como publicada
$updateResult = $db->updateOne(
    'compliance_config',
    ['userId' => $userId],
    [
        'privacyPolicyUrl' => '/api/compliance/public-policy',
        'privacyPolicyPublished' => true,
        'privacyPolicyLastUpdated' => date('c')
    ]
);

if ($updateResult) {
    echo "✓ Política de privacidad marcada como publicada\n";
    echo "  URL: /api/compliance/public-policy\n";
    echo "  Fecha: " . date('c') . "\n";
    echo "\nAhora aparecerá como completado en el checklist de Compliance.\n";
} else {
    echo "✗ Error al actualizar la configuración\n";
}