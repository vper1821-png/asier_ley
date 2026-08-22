<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

// Listar empresas
$companies = $db->find('users', ['isActive' => true]);
echo "Empresas activas:\n";
foreach ($companies as $c) {
    $name = isset($c['companyName']) ? $c['companyName'] : 'Sin nombre';
    echo "- {$c['_id']}: $name ({$c['email']})\n";
}

// Actualizar la solicitud ARCO-1AA464A1 con el primer companyId
if (!empty($companies)) {
    $firstCompanyId = $companies[0]['_id'];
    $result = $db->updateOne('arco_requests', ['requestId' => 'ARCO-1AA464A1'], ['companyId' => $firstCompanyId]);
    echo "\nActualizada solicitud ARCO-1AA464A1 con companyId: $firstCompanyId\n";
} else {
    echo "\nNo hay empresas activas para asignar\n";
}