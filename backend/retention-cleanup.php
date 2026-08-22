<?php
// SecureLab - Retention Cleanup Job
// Runs periodically to delete expired data based on retention policies

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();
$now = date('c');

echo "[$now] Starting retention cleanup job...\n";

$deletedCounts = [
    'consents' => 0,
    'inventory' => 0,
    'breaches' => 0,
    'arco_requests' => 0,
    'file_audit_logs' => 0,
    'database_logs' => 0,
    'host_events' => 0,
    'file_events' => 0,
];

// ── 1. Consentimientos expirados (Art. 12) ──
// Eliminar consentimientos revocados hace más de 30 días o con fecha de fin pasada
$expiredConsents = $db->find('compliance_consents', [
    '$or' => [
        ['revokedAt' => ['$lt' => date('c', strtotime('-30 days'))]],
        ['endDate' => ['$lt' => date('c'), '$ne' => '']],
    ],
]);

foreach ($expiredConsents as $c) {
    $db->deleteOne('compliance_consents', ['_id' => $c['_id']]);
    $deletedCounts['consents']++;
}

// ── 2. Inventario con retención vencida (Art. 14) ──
// Items con retentionDays configurado y fecha de creación antigua
$inventoryItems = $db->find('compliance_inventory', ['retentionDays' => ['$gt' => 0]]);
foreach ($inventoryItems as $item) {
    $retentionDays = (int)($item['retentionDays'] ?? 0);
    $createdAt = $item['createdAt'] ?? '';
    if ($createdAt && strtotime($createdAt) < strtotime("-{$retentionDays} days")) {
        $db->deleteOne('compliance_inventory', ['_id' => $item['_id']]);
        $deletedCounts['inventory']++;
    }
}

// ── 3. Brechas resueltas antiguas (Art. 26) ──
// Brechas resueltas hace más de 1 año (mantener evidencia 1 año)
$oldBreaches = $db->find('compliance_breaches', [
    'status' => 'resolved',
    'resolvedAt' => ['$lt' => date('c', strtotime('-1 year'))],
]);
foreach ($oldBreaches as $b) {
    $db->deleteOne('compliance_breaches', ['_id' => $b['_id']]);
    $deletedCounts['breaches']++;
}

// ── 4. Solicitudes ARCO completadas antiguas ──
// Completadas hace más de 2 años
$oldArco = $db->find('arco_requests', [
    'status' => ['$in' => ['completed', 'delivered', 'rejected']],
    'resolvedAt' => ['$lt' => date('c', strtotime('-2 years'))],
]);
foreach ($oldArco as $r) {
    $db->deleteOne('arco_requests', ['_id' => $r['_id']]);
    $deletedCounts['arco_requests']++;
}

// ── 5. Auditoría de archivos antigua ──
// Logs de auditoría de archivos más de 2 años
$oldFileAudits = $db->find('file_audit_logs', [
    'createdAt' => ['$lt' => date('c', strtotime('-2 years'))],
]);
foreach ($oldFileAudits as $f) {
    $db->deleteOne('file_audit_logs', ['_id' => $f['_id']]);
    $deletedCounts['file_audit_logs']++;
}

// ── 6. Logs de base de datos antiguos ──
// Logs de BBDD más de 1 año
$oldDbLogs = $db->find('database_logs', [
    'createdAt' => ['$lt' => date('c', strtotime('-1 year'))],
]);
foreach ($oldDbLogs as $l) {
    $db->deleteOne('database_logs', ['_id' => $l['_id']]);
    $deletedCounts['database_logs']++;
}

// ── 7. Eventos de host antiguos ──
// Eventos de host más de 6 meses
$oldHostEvents = $db->find('host_events', [
    'createdAt' => ['$lt' => date('c', strtotime('-6 months'))],
]);
foreach ($oldHostEvents as $h) {
    $db->deleteOne('host_events', ['_id' => $h['_id']]);
    $deletedCounts['host_events']++;
}

// ── 8. Eventos de archivo antiguos ──
// Eventos de archivo más de 6 meses
$oldFileEvents = $db->find('file_events', [
    'createdAt' => ['$lt' => date('c', strtotime('-6 months'))],
]);
foreach ($oldFileEvents as $f) {
    $db->deleteOne('file_events', ['_id' => $f['_id']]);
    $deletedCounts['file_events']++;
}

// ── Resumen ──
echo "[$now] Retention cleanup completed:\n";
foreach ($deletedCounts as $collection => $count) {
    if ($count > 0) {
        echo "  - $collection: $count registros eliminados\n";
    }
}

$total = array_sum($deletedCounts);
if ($total === 0) {
    echo "  No se eliminaron registros (todo dentro de retención)\n";
} else {
    echo "  Total: $total registros eliminados\n";
}

exit(0);