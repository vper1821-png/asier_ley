<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== VERIFICANDO CONFIGURACIÓN DE POLÍTICA DE PRIVACIDAD ===\n\n";

$config = $db->findOne('compliance_config', ['userId' => $userId]);

if ($config) {
    echo "Configuración encontrada:\n";
    echo "privacyPolicyUrl: " . (isset($config['privacyPolicyUrl']) ? $config['privacyPolicyUrl'] : 'NO DEFINIDO') . "\n";
    echo "privacyPolicyPublished: " . (isset($config['privacyPolicyPublished']) ? ($config['privacyPolicyPublished'] ? 'Si' : 'No') : 'NO DEFINIDO') . "\n";
    echo "privacyPolicyLastUpdated: " . (isset($config['privacyPolicyLastUpdated']) ? $config['privacyPolicyLastUpdated'] : 'NO DEFINIDO') . "\n";
} else {
    echo "Configuración no encontrada\n";
}