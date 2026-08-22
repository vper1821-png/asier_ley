<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

$reportId = 'bc5c99312c9885b56b3a1c7c';
$userId = '9b5201371fb106d07e107d24';

// Buscar el reporte específico
$report = $db->findOne('reports', ['_id' => $reportId]);
if ($report) {
    echo "Reporte encontrado:\n";
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo "\n\nuserId del reporte: " . (isset($report['userId']) ? $report['userId'] : 'no definido');
    echo "\nuserId del token: $userId";
} else {
    echo "Reporte $reportId no encontrado\n";
}

// Listar todos los reportes del usuario
$reports = $db->find('reports', ['userId' => $userId]);
echo "\n\nReportes del usuario $userId:\n";
echo count($reports) . " reportes encontrados\n";
foreach ($reports as $r) {
    echo "- {$r['_id']}: {$r['title']}\n";
}