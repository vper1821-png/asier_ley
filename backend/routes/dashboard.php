<?php
// Dashboard routes

function status() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $alerts = $db->find('alerts', ['userId' => $user['_id']], ['limit' => 10]);
    $agents = $db->find('agents', ['userId' => $user['_id']]);
    $databases = $db->find('databases', ['userId' => $user['_id']]);

    json_response([
        'maintenanceMode' => false,
        'maintenanceMessage' => '',
        'alerts' => $alerts,
        'agentCount' => count($agents),
        'databaseCount' => count($databases),
    ]);
}

function stats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $uid = $user['_id'];

    $agents = $db->find('agents', ['userId' => $uid]);
    $databases = $db->find('databases', ['userId' => $uid]);
    $alerts = $db->find('alerts', ['userId' => $uid]);
    $breaches = $db->find('compliance_breaches', ['userId' => $uid]);
    $scans = $db->find('scans', ['userId' => $uid]);
    $reports = $db->find('reports', ['userId' => $uid]);
    $userMonitor = $db->find('user_monitor', ['userId' => $uid]);

    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));
    $activeAlerts = count(array_filter($alerts, fn($a) => empty($a['resolved']) && empty($a['dismissed'])));
    $openBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? 'open') !== 'resolved'));
    $completedScans = count(array_filter($scans, fn($s) => ($s['status'] ?? '') === 'completed'));
    $monthStart = date('Y-m-01');
    $generatedReports = count(array_filter($reports, fn($r) => ($r['createdAt'] ?? '') >= $monthStart));

    $totalTables = 0;
    $totalRecords = 0;
    $compliantDBs = 0;
    $dbCompliance = [];
    foreach ($databases as $d) {
        $tables = (int)($d['tableCount'] ?? $d['tables'] ?? 0);
        $records = (int)($d['recordCount'] ?? $d['records'] ?? 0);
        $totalTables += $tables;
        $totalRecords += $records;
        // Una base de datos solo cumple si está conectada y sin brechas abiertas
        $isConnected = ($d['status'] ?? '') === 'connected';
        $compliant = $isConnected && ($openBreaches === 0 || !empty($d['compliant']));
        if ($compliant) $compliantDBs++;
        $dbCompliance[] = [
            'id' => $d['_id'],
            'name' => $d['name'] ?? $d['database'] ?? 'db',
            'engine' => $d['engine'] ?? $d['type'] ?? '',
            'tables' => $tables,
            'records' => $records,
            'compliant' => $compliant,
            'status' => $d['status'] ?? 'configured',
            'breaches' => 0,
        ];
    }
    $nonCompliantDBs = count($databases) - $compliantDBs;
    
    // Calcular complianceScore basado en completitud real (no solo existencia)
    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]);
    $inventory = $db->find('compliance_inventory', ['userId' => $user['_id']]);
    $consents = $db->find('compliance_consents', ['userId' => $user['_id']]);
    $breaches = $db->find('compliance_breaches', ['userId' => $user['_id']]);
    $trainings = $db->find('compliance_trainings', ['userId' => $user['_id']]);
    $pseudoRules = $db->find('compliance_pseudonymization', ['userId' => $user['_id']]);
    
    // Si config no existe, inicializar array vacío
    if (!$config) $config = [];

    // DPD Designado: debe tener email, nombre y teléfono
    $dpdComplete = !empty($config['dpdEmail']) && !empty($config['dpdName']) && !empty($config['dpdPhone']);
    // Registro APDP: debe estar registrado con número de registro
    $apdpComplete = ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) && !empty($config['apdpRegistrationNumber']);
    // Inventario: debe tener items completos (nombre, legalBasis, dataCategories)
    $inventoryComplete = count($inventory) > 0 && count(array_filter($inventory, fn($i) => 
        !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories'])
    )) > 0;
    // Política de Privacidad: debe tener URL pública
    $privacyPolicyComplete = !empty($config['privacyPolicyUrl']);
    // Consentimientos: debe haber consentimientos activos (no revocados)
    $consentsComplete = count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0;
    // Protocolo de Brechas: debe haber protocolo documentado O breaches resueltos (no solo abiertos)
    $resolvedBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved'));
    $breachProtocolComplete = !empty($config['breachProtocolUrl']) || $resolvedBreaches > 0;
    // Portal ARCO: debe haber solicitudes ARCO reales
    $arcoRequests = $db->find('compliance_arco-requests', ['userId' => $user['_id']]);
    $arcoComplete = count($arcoRequests) > 0;
    // Seudonimización: debe haber reglas ejecutadas
    $pseudonymizationComplete = count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0;
    // Plan de Respuesta a Incidentes: debe haber breaches resueltos o protocolo
    $incidentResponseComplete = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0 || !empty($config['incidentResponsePlan']);
    // Capacitación: debe haber capacitaciones completadas
    $trainingComplete = count(array_filter($trainings, fn($t) => !empty($t['completed']))) > 0;

    $checklist = [
        $dpdComplete,
        $apdpComplete,
        $inventoryComplete,
        $privacyPolicyComplete,
        $consentsComplete,
        $breachProtocolComplete,
        $arcoComplete,
        $pseudonymizationComplete,
        $incidentResponseComplete,
        $trainingComplete,
    ];

    $complianceChecklistDone = count(array_filter($checklist, fn($c) => $c));
    $complianceScore = (int)round($complianceChecklistDone / count($checklist) * 100);

    $vulnerableUsersCount = count(array_filter($userMonitor, fn($u) => !empty($u['vulnerable']) || ($u['riskLevel'] ?? '') === 'high'));

    $complianceItems = [
        ['id' => 'dpd', 'label' => 'DPD Designado', 'done' => $dpdComplete, 'desc' => 'Aplicable cuando la naturaleza o escala del tratamiento exige esta función', 'icon' => 'users'],
        ['id' => 'apdp', 'label' => 'Modelo certificado', 'done' => $apdpComplete, 'desc' => 'Registro o evidencia de un modelo de prevención certificado, cuando corresponda', 'icon' => 'shield'],
        ['id' => 'inventory', 'label' => 'Inventario de Datos', 'done' => $inventoryComplete, 'desc' => 'Registro documentado para sustentar información y transparencia del tratamiento', 'icon' => 'database'],
        ['id' => 'privacy', 'label' => 'Política de Privacidad', 'done' => $privacyPolicyComplete, 'desc' => 'Política actualizada y accesible para los titulares', 'icon' => 'fileText'],
        ['id' => 'consents', 'label' => 'Consentimientos', 'done' => $consentsComplete, 'desc' => 'Consentimientos activos y trazables cuando sean la base de licitud', 'icon' => 'check'],
        ['id' => 'breach_protocol', 'label' => 'Protocolo de Brechas', 'done' => $breachProtocolComplete, 'desc' => 'Procedimiento documentado de gestión y notificación de incidentes', 'icon' => 'alert'],
        ['id' => 'arco', 'label' => 'Canal ARCO', 'done' => $arcoComplete, 'desc' => 'Canal operativo para acceso, rectificación, supresión, oposición y portabilidad', 'icon' => 'users'],
        ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'done' => $pseudonymizationComplete, 'desc' => 'Medida de seguridad aplicada según la naturaleza y riesgo del tratamiento', 'icon' => 'search'],
        ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'done' => $incidentResponseComplete, 'desc' => 'Plan documentado o evidencia de incidentes gestionados', 'icon' => 'alert'],
        ['id' => 'training', 'label' => 'Capacitación', 'done' => $trainingComplete, 'desc' => 'Formación completada y respaldada con evidencia', 'icon' => 'info'],
    ];

    json_response([
        'stats' => [
            'onlineAgents' => $onlineAgents,
            'totalAgents' => count($agents),
            'totalDatabases' => count($databases),
            'totalTables' => $totalTables,
            'totalRecords' => $totalRecords,
            'complianceScore' => $complianceScore,
            'compliantDBs' => $compliantDBs,
            'nonCompliantDBs' => $nonCompliantDBs,
            'openBreaches' => $openBreaches,
            'totalBreaches' => count($breaches),
            'vulnerableUsersCount' => $vulnerableUsersCount,
            'activeAlerts' => $activeAlerts,
            'completedScans' => $completedScans,
            'totalScans' => count($scans),
            'generatedReports' => $generatedReports,
        ],
        'dbCompliance' => $dbCompliance,
        'complianceItems' => $complianceItems,
    ]);
}
