<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

// Buscar la solicitud ARCO-1AA464A1
$request = $db->findOne('arco_requests', ['requestId' => 'ARCO-1AA464A1']);

if ($request) {
    echo "Solicitud encontrada:\n";
    echo json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "Solicitud ARCO-1AA464A1 no encontrada\n";
}

// Listar todas las solicitudes ARCO
$all = $db->find('arco_requests', []);
echo "\n\nTodas las solicitudes ARCO:\n";
echo count($all) . " solicitudes encontradas\n";
foreach ($all as $r) {
    echo "- {$r['requestId']}: companyId = {$r['companyId']}, status = {$r['status']}\n";
}