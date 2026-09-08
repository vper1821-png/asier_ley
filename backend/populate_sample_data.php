<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$userId = '9b5201371fb106d07e107d24';
$db = Database::getInstance();

echo "=== POBLANDO DATOS DE EJEMPLO PARA CUMPLIR LA LEY ===\n\n";

// 1. AGENTES
echo "1. Creando agentes...\n";
$agents = [
    [
        'userId' => $userId,
        'name' => 'Agente Windows - Servidor Principal',
        'hostname' => 'srv-01.empresa.local',
        'os' => 'Windows Server 2022',
        'ip' => '192.168.1.10',
        'status' => 'online',
        'lastSeen' => date('c'),
        'agentVersion' => '1.5.2',
        'installDate' => date('c', strtotime('-30 days'))
    ],
    [
        'userId' => $userId,
        'name' => 'Agente Linux - Servidor Web',
        'hostname' => 'web-01.empresa.local',
        'os' => 'Ubuntu 22.04 LTS',
        'ip' => '192.168.1.20',
        'status' => 'online',
        'lastSeen' => date('c'),
        'agentVersion' => '1.5.2',
        'installDate' => date('c', strtotime('-15 days'))
    ],
    [
        'userId' => $userId,
        'name' => 'Agente macOS - Estación Diseño',
        'hostname' => 'design-mac.local',
        'os' => 'macOS Sonoma 14.2',
        'ip' => '192.168.1.50',
        'status' => 'offline',
        'lastSeen' => date('c', strtotime('-2 hours')),
        'agentVersion' => '1.5.0',
        'installDate' => date('c', strtotime('-45 days'))
    ]
];

foreach ($agents as $agent) {
    $db->insertOne('agents', $agent);
}
echo "   ✓ " . count($agents) . " agentes creados\n\n";

// 2. BASES DE DATOS
echo "2. Creando bases de datos...\n";
$databases = [
    [
        'userId' => $userId,
        'name' => 'BD Clientes Producción',
        'type' => 'PostgreSQL',
        'host' => 'db-prod.empresa.local',
        'port' => 5432,
        'database' => 'clientes_prod',
        'status' => 'connected',
        'lastConnected' => date('c'),
        'tablesCount' => 24,
        'recordsCount' => 15420,
        'containsPII' => true,
        'encryption' => 'AES-256'
    ],
    [
        'userId' => $userId,
        'name' => 'BD Ventas',
        'type' => 'MySQL',
        'host' => 'db-ventas.empresa.local',
        'port' => 3306,
        'database' => 'ventas_2024',
        'status' => 'connected',
        'lastConnected' => date('c'),
        'tablesCount' => 18,
        'recordsCount' => 8950,
        'containsPII' => true,
        'encryption' => 'TLS'
    ],
    [
        'userId' => $userId,
        'name' => 'BD Logs',
        'type' => 'MongoDB',
        'host' => 'db-logs.empresa.local',
        'port' => 27017,
        'database' => 'application_logs',
        'status' => 'connected',
        'lastConnected' => date('c'),
        'tablesCount' => 5,
        'recordsCount' => 250000,
        'containsPII' => false,
        'encryption' => 'None'
    ]
];

foreach ($databases as $db_data) {
    $db->insertOne('databases', $db_data);
}
echo "   ✓ " . count($databases) . " bases de datos creadas\n\n";

// 3. INVENTARIO (Compliance Inventory)
echo "3. Creando inventario de datos...\n";
$inventory = [
    [
        'userId' => $userId,
        'name' => 'Datos Personales Clientes',
        'category' => 'personales',
        'description' => 'Nombre, RUT, dirección, teléfono de clientes',
        'source' => 'BD Clientes Producción',
        'purpose' => 'Gestión de relación con clientes',
        'legalBasis' => 'consentimiento',
        'retentionPeriod' => '5 años',
        'sensitive' => false,
        'encryption' => 'AES-256',
        'accessControls' => 'RBAC',
        'thirdPartySharing' => false,
        'crossBorderTransfer' => false
    ],
    [
        'userId' => $userId,
        'name' => 'Historial de Compras',
        'category' => 'comercial',
        'description' => 'Historial transacciones, montos, productos',
        'source' => 'BD Ventas',
        'purpose' => 'Análisis comercial y facturación',
        'legalBasis' => 'contrato',
        'retentionPeriod' => '7 años',
        'sensitive' => false,
        'encryption' => 'TLS',
        'accessControls' => 'RBAC',
        'thirdPartySharing' => false,
        'crossBorderTransfer' => false
    ],
    [
        'userId' => $userId,
        'name' => 'Datos de Salud Empleados',
        'category' => 'sensible',
        'description' => 'Historial médico, exámenes, licencias',
        'source' => 'RRHH',
        'purpose' => 'Gestión de salud ocupacional',
        'legalBasis' => 'obligación_legal',
        'retentionPeriod' => '10 años',
        'sensitive' => true,
        'encryption' => 'AES-256',
        'accessControls' => 'MFA + RBAC',
        'thirdPartySharing' => false,
        'crossBorderTransfer' => false
    ],
    [
        'userId' => $userId,
        'name' => 'Datos Biométricos',
        'category' => 'sensible',
        'description' => 'Huella digital para control de acceso',
        'source' => 'Sistema de Control de Acceso',
        'purpose' => 'Control de acceso físico',
        'legalBasis' => 'consentimiento',
        'retentionPeriod' => '3 años',
        'sensitive' => true,
        'encryption' => 'AES-256',
        'accessControls' => 'MFA + RBAC',
        'thirdPartySharing' => false,
        'crossBorderTransfer' => false
    ]
];

foreach ($inventory as $item) {
    $db->insertOne('compliance_inventory', $item);
}
echo "   ✓ " . count($inventory) . " items de inventario creados\n\n";

// 4. CONFIGURACIÓN DE COMPLIANCE
echo "4. Actualizando configuración de compliance...\n";
$config = [
    'userId' => $userId,
    'companyName' => 'Venmax',
    'rut' => '76.123.456-K',
    'address' => 'Av. Providencia 1234, Oficina 505, Santiago',
    'dpdName' => 'Juan Pérez',
    'dpdEmail' => 'dpd@venmax.cl',
    'dpdPhone' => '+56 2 2345 6789',
    'privacyPolicyPublished' => true,
    'privacyPolicyUrl' => '/politica-privacidad',
    'privacyPolicyLastUpdated' => date('c'),
    'cookieConsentEnabled' => true,
    'dataBreachProcedure' => true,
    'dataRetentionPolicy' => true,
    'dpiaRequired' => false,
    'lastComplianceCheck' => date('c'),
    'complianceScore' => 85
];

$db->updateOne('compliance_config', ['userId' => $userId], ['$set' => $config], ['upsert' => true]);
echo "   ✓ Configuración actualizada\n\n";

// 5. DPAS (Acuerdos de Procesamiento)
echo "5. Creando acuerdos de procesamiento (DPAs)...\n";
$dpas = [
    [
        'userId' => $userId,
        'name' => 'Acuerdo con Proveedor de Nube AWS',
        'counterparty' => 'Amazon Web Services',
        'type' => 'cloud',
        'startDate' => date('c', strtotime('-1 year')),
        'endDate' => date('c', strtotime('+1 year')),
        'dataCategories' => ['personales', 'comercial'],
        'transferLocation' => 'EEUU',
        'safeguards' => 'GDPR Art. 46 Clauses',
        'status' => 'active',
        'reviewDate' => date('c')
    ],
    [
        'userId' => $userId,
        'name' => 'Acuerdo con Procesador de Pagos',
        'counterparty' => 'Transbank S.A.',
        'type' => 'payment',
        'startDate' => date('c', strtotime('-6 months')),
        'endDate' => null,
        'dataCategories' => ['financiera'],
        'transferLocation' => 'Chile',
        'safeguards' => 'PCI DSS Compliance',
        'status' => 'active',
        'reviewDate' => date('c')
    ]
];

foreach ($dpas as $dpa) {
    $db->insertOne('compliance_dpa', $dpa);
}
echo "   ✓ " . count($dpas) . " acuerdos creados\n\n";

// 6. DPIAS (Evaluaciones de Impacto)
echo "6. Creando evaluaciones de impacto (DPIAs)...\n";
$dpias = [
    [
        'userId' => $userId,
        'name' => 'DPIA - Implementación de Reconocimiento Facial',
        'description' => 'Evaluación de impacto para sistema de control de acceso biométrico',
        'riskLevel' => 'alto',
        'status' => 'completed',
        'completionDate' => date('c', strtotime('-2 months')),
        'measures' => [
            'Cifrado de datos biométricos',
            'Consentimiento explícito',
            'Política de retención',
            'Mínimización de datos'
        ],
        'findings' => 'Riesgos mitigados con medidas de seguridad adecuadas'
    ],
    [
        'userId' => $userId,
        'name' => 'DPIA - Almacenamiento en Nube',
        'description' => 'Evaluación de impacto para migración de datos a AWS',
        'riskLevel' => 'medio',
        'status' => 'in_progress',
        'completionDate' => null,
        'measures' => [
            'Encriptación en reposo y tránsito',
            'Control de acceso granular',
            'Auditoría de accesos'
        ],
        'findings' => 'En proceso de implementación'
    ]
];

foreach ($dpias as $dpia) {
    $db->insertOne('compliance_dpia', $dpia);
}
echo "   ✓ " . count($dpias) . " DPIAs creadas\n\n";

// 7. CAPACITACIONES
echo "7. Creando registros de capacitaciones...\n";
$trainings = [
    [
        'userId' => $userId,
        'title' => 'Capacitación Ley 21.719 - Nivel Básico',
        'date' => date('c', strtotime('-3 months')),
        'attendees' => 25,
        'completionRate' => 100,
        'topics' => ['Derechos ARCO', 'Obligaciones del titular', 'Sanciones'],
        'status' => 'completed'
    ],
    [
        'userId' => $userId,
        'title' => 'Capacitación en Ciberseguridad',
        'date' => date('c', strtotime('-1 month')),
        'attendees' => 18,
        'completionRate' => 95,
        'topics' => ['Phishing', 'Contraseñas seguras', 'Manejo de datos'],
        'status' => 'completed'
    ],
    [
        'userId' => $userId,
        'title' => 'Capacitación DPIA - Nivel Avanzado',
        'date' => date('c', strtotime('+1 week')),
        'attendees' => 0,
        'completionRate' => 0,
        'topics' => ['Evaluación de impacto', 'Análisis de riesgos', 'Medidas de mitigación'],
        'status' => 'scheduled'
    ]
];

foreach ($trainings as $training) {
    $db->insertOne('compliance_trainings', $training);
}
echo "   ✓ " . count($trainings) . " capacitaciones creadas\n\n";

// 8. BRECHAS (Registros de brechas)
echo "8. Creando registros de brechas...\n";
$breaches = [
    [
        'userId' => $userId,
        'title' => 'Acceso no autorizado detectado',
        'severity' => 'medio',
        'description' => 'Intento de acceso desde IP externa bloqueado por firewall',
        'discoveredDate' => date('c', strtotime('-1 week')),
        'reportedDate' => date('c'),
        'affectedRecords' => 0,
        'status' => 'resolved',
        'rootCause' => 'Ataque desde IP desconocida',
        'remediation' => 'IP bloqueada, reglas de firewall actualizadas',
        'apdpNotified' => false,
        'dataSubjectsNotified' => false
    ],
    [
        'userId' => $userId,
        'title' => 'Fuga de credenciales',
        'severity' => 'alto',
        'description' => 'Credenciales de empleado comprometidas mediante phishing',
        'discoveredDate' => date('c', strtotime('-2 weeks')),
        'reportedDate' => date('c', strtotime('-2 weeks')),
        'affectedRecords' => 150,
        'status' => 'resolved',
        'rootCause' => 'Phishing exitoso',
        'remediation' => 'Credenciales reseteadas, capacitación adicional',
        'apdpNotified' => true,
        'dataSubjectsNotified' => true,
        'notificationDate' => date('c', strtotime('-2 weeks'))
    ]
];

foreach ($breaches as $breach) {
    $db->insertOne('compliance_breaches', $breach);
}
echo "   ✓ " . count($breaches) . " brechas registradas\n\n";

echo "=== DATOS DE EJEMPLO CREADOS EXITOSAMENTE ===\n";
echo "Resumen:\n";
echo "- Agentes: " . count($agents) . "\n";
echo "- Bases de datos: " . count($databases) . "\n";
echo "- Inventario: " . count($inventory) . " items\n";
echo "- Configuración: Actualizada\n";
echo "- DPAs: " . count($dpas) . "\n";
echo "- DPIAs: " . count($dpias) . "\n";
echo "- Capacitaciones: " . count($trainings) . "\n";
echo "- Brechas: " . count($breaches) . "\n";