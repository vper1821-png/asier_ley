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

    // ── Obtener todos los userIds de la empresa ──
    $userRecord = $db->findOne('users', ['_id' => $uid]);
    if (!$userRecord) {
        json_error('Usuario no encontrado');
    }
    $companyId = $userRecord['companyId'] ?? $uid;
    $users = $db->find('users', ['companyId' => $companyId]);
    $userIds = array_map('strval', array_column($users, '_id'));
    if (empty($userIds)) {
        $userIds = [(string)$uid];
    }

    // ── Filtrar por empresa (o superadmin) ──
    $isSuperAdmin = !empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin';
    $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];

    $agents = $db->find('agents', $filter);
    $databases = $db->find('databases', $filter);
    // ❌ ELIMINAR: $alerts = $db->find('alerts', $filter);
    $breaches = $db->find('compliance_breaches', $filter);
    $scans = $db->find('scans', $filter);
    $reports = $db->find('reports', $filter);
    $userMonitor = $db->find('user_monitor', $filter);

    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));

    // ✅ USAR count para alertas activas (sin límite de paginación)
    $activeFilter = array_merge($filter, [
        'resolved' => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]);
    $activeAlerts = $db->count('alerts', $activeFilter);

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

    // ── Checklist de cumplimiento (compartido por empresa) ──
    $config = $db->findOne('compliance_config', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $inventory = $db->find('compliance_inventory', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $consents = $db->find('compliance_consents', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $breaches = $db->find('compliance_breaches', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $trainings = $db->find('compliance_trainings', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $pseudoRules = $db->find('compliance_pseudonymization', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);

    if (!$config) $config = [];

    $dpdComplete = !empty($config['dpdEmail']) && !empty($config['dpdName']) && !empty($config['dpdPhone']);
    $apdpComplete = ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) && !empty($config['apdpRegistrationNumber']);
    $inventoryComplete = count($inventory) > 0 && count(array_filter($inventory, fn($i) => 
        !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories'])
    )) > 0;
    $privacyPolicyComplete = !empty($config['privacyPolicyUrl']);
    $consentsComplete = count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0;
    $resolvedBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved'));
    $breachProtocolComplete = !empty($config['breachProtocolUrl']) || $resolvedBreaches > 0;
    $arcoRequests = $db->find('compliance_arco-requests', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);
    $arcoComplete = count($arcoRequests) > 0;
    $pseudonymizationComplete = count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0;
    $incidentResponseComplete = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0 || !empty($config['incidentResponsePlan']);
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
            'activeAlerts' => $activeAlerts, // ✅ valor corregido
            'completedScans' => $completedScans,
            'totalScans' => count($scans),
            'generatedReports' => $generatedReports,
        ],
        'dbCompliance' => $dbCompliance,
        'complianceItems' => $complianceItems,
    ]);
}