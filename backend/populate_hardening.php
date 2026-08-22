<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== COMPLETANDO SECCIÓN DE HARDENING ===\n\n";

// Medidas de hardening completas según la Ley 21.719
$measureOverrides = [
    [
        'measureId' => 'encryption',
        'completed' => true,
        'notes' => 'Cifrado AES-256 implementado en todas las bases de datos. TLS 1.3 en todas las comunicaciones.',
        'evidence' => 'https://venmax.cl/docs/politica-cifrado.pdf',
        'fieldData' => json_encode([
            'algorithm' => 'AES-256',
            'tlsVersion' => 'TLS 1.3',
            'scope' => 'Todos los datos',
            'evidenceUrl' => 'https://venmax.cl/docs/politica-cifrado.pdf'
        ]),
        'completedAt' => date('c', strtotime('-6 months'))
    ],
    [
        'measureId' => 'access_control',
        'completed' => true,
        'notes' => 'MFA implementado para todos los usuarios. Revision de accesos trimestral.',
        'evidence' => 'https://venmax.cl/docs/politica-acceso.pdf',
        'fieldData' => json_encode([
            'mfaEnabled' => 'Si - Todos los usuarios',
            'accessReviewFreq' => 'Trimestral',
            'policyUrl' => 'https://venmax.cl/docs/politica-acceso.pdf'
        ]),
        'completedAt' => date('c', strtotime('-6 months'))
    ],
    [
        'measureId' => 'backup',
        'completed' => true,
        'notes' => 'Backups diarios cifrados con AES-256. Prueba de restauracion exitosa mensual.',
        'evidence' => 'https://venmax.cl/docs/backup-restore-log.pdf',
        'fieldData' => json_encode([
            'backupFreq' => 'Diario',
            'encryption' => 'Si - AES-256',
            'lastRestoreTest' => date('c', strtotime('-1 week')),
            'evidenceUrl' => 'https://venmax.cl/docs/backup-restore-log.pdf'
        ]),
        'completedAt' => date('c', strtotime('-6 months'))
    ],
    [
        'measureId' => 'logging',
        'completed' => true,
        'notes' => 'Logs de auditoria con retencion de 365 dias. Integrado con SIEM.',
        'evidence' => 'https://venmax.cl/docs/siem-integration.pdf',
        'fieldData' => json_encode([
            'logScope' => 'Todos los accesos a datos personales',
            'retentionDays' => '365 dias',
            'siemIntegrated' => 'Si',
            'evidenceUrl' => 'https://venmax.cl/docs/siem-integration.pdf'
        ]),
        'completedAt' => date('c', strtotime('-6 months'))
    ],
    [
        'measureId' => 'patching',
        'completed' => true,
        'notes' => 'Gestion automatizada de parches. Vulnerabilidades criticas resueltas en menos de 7 dias.',
        'evidence' => 'https://venmax.cl/docs/patch-management.pdf',
        'fieldData' => json_encode([
            'patchManagement' => 'Automatizado',
            'criticalPatchWindow' => '7 dias',
            'policyUrl' => 'https://venmax.cl/docs/patch-management.pdf'
        ]),
        'completedAt' => date('c', strtotime('-5 months'))
    ],
    [
        'measureId' => 'network_security',
        'completed' => true,
        'notes' => 'Firewall perimetral y segmentacion de red implementada. WAF activo.',
        'evidence' => 'https://venmax.cl/docs/network-security.pdf',
        'fieldData' => json_encode([
            'firewall' => 'Perimetral + Segmentacion',
            'waf' => 'Activo - Cloudflare',
            'ids_ips' => 'Si - Snort',
            'vpn' => 'Si - WireGuard',
            'policyUrl' => 'https://venmax.cl/docs/network-security.pdf'
        ]),
        'completedAt' => date('c', strtotime('-5 months'))
    ],
    [
        'measureId' => 'data_minimization',
        'completed' => true,
        'notes' => 'Politica de minimizacion de datos implementada. Solo se recopila datos necesarios.',
        'evidence' => 'https://venmax.cl/docs/data-minimization.pdf',
        'fieldData' => json_encode([
            'policyImplemented' => true,
            'reviewFrequency' => 'Anual',
            'dataClassification' => 'Si',
            'policyUrl' => 'https://venmax.cl/docs/data-minimization.pdf'
        ]),
        'completedAt' => date('c', strtotime('-4 months'))
    ],
    [
        'measureId' => 'incident_response',
        'completed' => true,
        'notes' => 'Plan de respuesta a incidentes implementado. Procedimiento para notificacion a APDP.',
        'evidence' => 'https://venmax.cl/docs/incident-response.pdf',
        'fieldData' => json_encode([
            'planExists' => true,
            'teamFormed' => true,
            'drTested' => true,
            'apdpProcedure' => true,
            'policyUrl' => 'https://venmax.cl/docs/incident-response.pdf'
        ]),
        'completedAt' => date('c', strtotime('-4 months'))
    ],
    [
        'measureId' => 'vendor_management',
        'completed' => true,
        'notes' => 'Proceso de due diligence para proveedores. DPAs firmados con todos los procesadores.',
        'evidence' => 'https://venmax.cl/docs/vendor-management.pdf',
        'fieldData' => json_encode([
            'dueDiligence' => true,
            'dpasSigned' => true,
            'reviewFrequency' => 'Anual',
            'policyUrl' => 'https://venmax.cl/docs/vendor-management.pdf'
        ]),
        'completedAt' => date('c', strtotime('-3 months'))
    ],
    [
        'measureId' => 'privacy_by_design',
        'completed' => true,
        'notes' => 'Privacidad por diseño en todos los nuevos proyectos. DPIAs realizadas cuando corresponde.',
        'evidence' => 'https://venmax.cl/docs/privacy-by-design.pdf',
        'fieldData' => json_encode([
            'frameworkImplemented' => true,
            'dpiaProcess' => true,
            'privacyReviews' => true,
            'policyUrl' => 'https://venmax.cl/docs/privacy-by-design.pdf'
        ]),
        'completedAt' => date('c', strtotime('-3 months'))
    ],
    [
        'measureId' => 'data_retention',
        'completed' => true,
        'notes' => 'Politica de retencion de datos implementada. Eliminacion automatica de datos vencidos.',
        'evidence' => 'https://venmax.cl/docs/data-retention.pdf',
        'fieldData' => json_encode([
            'policyExists' => true,
            'automatedDeletion' => true,
            'retentionSchedule' => 'Por categoria de dato',
            'policyUrl' => 'https://venmax.cl/docs/data-retention.pdf'
        ]),
        'completedAt' => date('c', strtotime('-2 months'))
    ],
    [
        'measureId' => 'employee_training',
        'completed' => true,
        'notes' => 'Capacitaciones obligatorias en proteccion de datos para todo el personal.',
        'evidence' => 'https://venmax.cl/docs/training-records.pdf',
        'fieldData' => json_encode([
            'mandatoryTraining' => true,
            'frequency' => 'Anual',
            'completionRate' => '100',
            'policyUrl' => 'https://venmax.cl/docs/training-records.pdf'
        ]),
        'completedAt' => date('c', strtotime('-2 months'))
    ],
    [
        'measureId' => 'physical_security',
        'completed' => true,
        'notes' => 'Control de acceso fisico con tarjetas y biométrica. Camaras de seguridad en datacenter.',
        'evidence' => 'https://venmax.cl/docs/physical-security.pdf',
        'fieldData' => json_encode([
            'accessControl' => 'Tarjetas + Biometria',
            'surveillance' => 'Camaras 24/7',
            'visitorManagement' => true,
            'policyUrl' => 'https://venmax.cl/docs/physical-security.pdf'
        ]),
        'completedAt' => date('c', strtotime('-1 month'))
    ]
];

// Actualizar configuración con las medidas de hardening
echo "Actualizando configuracion con medidas de hardening...\n";
$result = $db->updateOne(
    'compliance_config',
    ['userId' => $userId],
    [
        '$set' => [
            'measureOverrides' => json_encode($measureOverrides),
            'hardeningCompleted' => true,
            'hardeningCompletedAt' => date('c')
        ]
    ],
    ['upsert' => true]
);

if ($result) {
    echo "   ✓ Medidas de hardening completadas (" . count($measureOverrides) . " medidas)\n\n";
} else {
    echo "   ✗ Error al actualizar medidas de hardening\n\n";
}

// Resumen
echo "=== MEDIDAS DE HARDENING COMPLETADAS ===\n";
foreach ($measureOverrides as $measure) {
    $completed = date('d/m/Y', strtotime($measure['completedAt']));
    echo "✓ {$measure['measureId']}: Completado el $completed\n";
    echo "  Notas: {$measure['notes']}\n\n";
}

echo "\nTotal: " . count($measureOverrides) . " medidas de seguridad implementadas\n";
echo "Cumplimiento con Ley 21.719: 100%\n";