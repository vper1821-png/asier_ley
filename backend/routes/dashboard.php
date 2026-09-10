<?php
/**
 * Dashboard routes — Company-scoped, Ley 21.719 focused
 * ─────────────────────────────────────────────────────
 * Todos los endpoints filtran por empresa (companyId). Si el usuario
 * pertenece a una empresa con N usuarios, se agregan los datos de TODOS
 * los usuarios de esa empresa. Superadmin ve todo.
 */

// =========================================================
// ── Helpers ──
// =========================================================

/**
 * Devuelve el scope del usuario: superadmin o empresa.
 * @return array{isSuperAdmin:bool, userIds:array, companyId:?string, filter:array, companyName:string}
 */
function _dash_scope($user, $db) {
    $isSuperAdmin = !empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin';

    if ($isSuperAdmin) {
        return [
            'isSuperAdmin' => true,
            'userIds'      => [],
            'companyId'    => null,
            'filter'       => [],
            'companyName'  => 'Todas las empresas',
        ];
    }

    $userRecord = $db->findOne('users', ['_id' => $user['_id']]);
    if (!$userRecord) {
        $userRecord = $user; // fallback
    }
    $companyId = (string)($userRecord['companyId'] ?? $user['_id']);

    // Todos los usuarios de la empresa
    $companyUsers = $db->find('users', ['companyId' => $companyId]);
    if (empty($companyUsers)) {
        // Compatibilidad: si no hay companyId, quizá el companyId es el _id del dueño
        $companyUsers = $db->find('users', ['_id' => $companyId]);
        if (empty($companyUsers)) $companyUsers = [$userRecord];
    }
    $userIds = array_values(array_unique(array_map('strval', array_column($companyUsers, '_id'))));
    if (empty($userIds)) $userIds = [(string)$user['_id']];

    return [
        'isSuperAdmin' => false,
        'userIds'      => $userIds,
        'companyId'    => $companyId,
        'filter'       => ['userId' => ['$in' => $userIds]],
        'companyName'  => $userRecord['companyName'] ?? $userRecord['email'] ?? 'Mi empresa',
    ];
}

/** Cache simple por archivo (evita recargar stats cada segundo). */
function _dash_cache_get($key, $ttl = 60) {
    $f = sys_get_temp_dir() . '/dash_cache_' . md5($key) . '.json';
    if (!file_exists($f)) return null;
    if (time() - filemtime($f) > $ttl) { @unlink($f); return null; }
    $raw = @file_get_contents($f);
    return $raw ? json_decode($raw, true) : null;
}
function _dash_cache_set($key, $value) {
    $f = sys_get_temp_dir() . '/dash_cache_' . md5($key) . '.json';
    @file_put_contents($f, json_encode($value), LOCK_EX);
}
function _dash_cache_bust($key) {
    $f = sys_get_temp_dir() . '/dash_cache_' . md5($key) . '.json';
    @unlink($f);
}

/** Días hábiles transcurridos desde una fecha ISO. */
function _dash_business_days_between($fromIso, $toTs = null) {
    if (!$fromIso) return 0;
    $toTs = $toTs ?? time();
    $fromTs = strtotime($fromIso);
    if (!$fromTs) return 0;
    $days = 0;
    $cursor = $fromTs;
    while ($cursor < $toTs) {
        $cursor += 86400;
        $dow = (int)date('N', $cursor); // 1=Lun … 7=Dom
        if ($dow <= 5) $days++;
    }
    return $days;
}

// =========================================================
// ── Endpoint: status (ligero, para polling) ──
// =========================================================
function status() {
    $user = Auth::requireAuth();
    $db   = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $activeFilter = array_merge($scope['filter'], [
        'resolved'  => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]);

    json_response([
        'maintenanceMode'    => false,
        'maintenanceMessage' => '',
        'alerts'             => $db->find('alerts', array_merge($scope['filter'], []), ['limit' => 10]),
        'agentCount'         => $db->count('agents', $scope['filter']),
        'databaseCount'      => $db->count('databases', $scope['filter']),
        'activeAlerts'       => $db->count('alerts', $activeFilter),
        'companyName'        => $scope['companyName'],
        'userCount'          => $scope['isSuperAdmin'] ? null : count($scope['userIds']),
    ]);
}

// =========================================================
// ── Endpoint: stats (KPI principal — cacheado 45s) ──
// =========================================================
function stats() {
    $user = Auth::requireAuth();
    $db   = Database::getInstance();
    $scope = _dash_scope($user, $db);
    $cacheKey = 'stats:' . ($scope['companyId'] ?? 'superadmin');

    $cached = _dash_cache_get($cacheKey, 45);
    if ($cached) { json_response($cached); }

    $filter = $scope['filter'];

    // ── Conteos eficientes (sin traer todos los docs) ──
    $totalAgents    = $db->count('agents', $filter);
    $totalDatabases = $db->count('databases', $filter);
    $totalScans     = $db->count('scans', $filter);

    // Agentes online: requiere traer solo el campo status (limit razonable)
    // Fallback a find con proyección manual:
    $agents = $db->find('agents', $filter, ['limit' => 5000]);
    $onlineAgents = 0;
    foreach ($agents as $a) { if (($a['status'] ?? '') === 'online') $onlineAgents++; }

    // Alertas activas
    $activeAlerts = $db->count('alerts', array_merge($filter, [
        'resolved'  => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]));

    // Brechas
    $openBreaches  = $db->count('compliance_breaches', array_merge($filter, ['status' => ['$ne' => 'resolved']]));
    $totalBreaches = $db->count('compliance_breaches', $filter);

    // Escaneos completados
    $completedScans = $db->count('scans', array_merge($filter, ['status' => 'completed']));

    // Reportes del mes
    $monthStart = date('Y-m-01') . 'T00:00:00';
    $generatedReports = $db->count('reports', array_merge($filter, [
        'createdAt' => ['$gte' => $monthStart]
    ]));

    // ── Bases de datos: métricas ──
    $databases = $db->find('databases', $filter, ['limit' => 2000]);
    $totalTables = 0; $totalRecords = 0; $compliantDBs = 0;
    $dbCompliance = [];

    foreach ($databases as $d) {
        $tables  = (int)($d['tableCount']  ?? $d['tables']  ?? 0);
        $records = (int)($d['recordCount'] ?? $d['records'] ?? 0);
        $totalTables  += $tables;
        $totalRecords += $records;

        $isConnected = ($d['status'] ?? '') === 'connected';
        // ✅ Fix bug: compliant requiere conexión Y flag explícito O cero brechas PARA ESA DB
        $dbBreaches = (int)($d['openBreaches'] ?? 0);
        $compliant  = $isConnected && $dbBreaches === 0 && ($d['compliant'] ?? true) !== false;
        if ($compliant) $compliantDBs++;

        $dbCompliance[] = [
            'id'        => $d['_id'],
            'name'      => $d['name'] ?? $d['database'] ?? 'db',
            'engine'    => $d['engine'] ?? $d['type'] ?? '',
            'tables'    => $tables,
            'records'   => $records,
            'compliant' => $compliant,
            'status'    => $d['status'] ?? 'configured',
            'breaches'  => $dbBreaches,
        ];
    }
    $totalDatabases = count($dbCompliance) ?: $totalDatabases;
    $nonCompliantDBs = max(0, $totalDatabases - $compliantDBs);

    // ── Checklist Ley 21.719 (ponderado) ──
    $config      = $db->findOne('compliance_config', $filter) ?: [];
    $inventory   = $db->find('compliance_inventory', $filter, ['limit' => 2000]);
    $consents    = $db->find('compliance_consents', $filter, ['limit' => 5000]);
    $breaches    = $db->find('compliance_breaches', $filter, ['limit' => 2000]);
    $trainings   = $db->find('compliance_trainings', $filter, ['limit' => 2000]);
    $pseudoRules = $db->find('compliance_pseudonymization', $filter, ['limit' => 500]);
    $arcoReqs    = $db->find('compliance_arco-requests', $filter, ['limit' => 2000]);

    // Pesos por criticidad legal (suman 100)
    $checklistDef = [
        ['id' => 'dpd',              'weight' => 15, 'label' => 'DPD Designado',                'desc' => 'Aplicable cuando la naturaleza o escala del tratamiento exige esta función', 'icon' => 'users',    'done' => !empty($config['dpdEmail']) && !empty($config['dpdName']) && !empty($config['dpdPhone'])],
        ['id' => 'apdp',             'weight' => 10, 'label' => 'Modelo certificado (APDP)',    'desc' => 'Registro o evidencia de un modelo de prevención certificado', 'icon' => 'shield',   'done' => ($config['apdpRegistered'] ?? false) && !empty($config['apdpRegistrationNumber'])],
        ['id' => 'inventory',        'weight' => 15, 'label' => 'Inventario de Datos (RAT)',    'desc' => 'Registro documentado del tratamiento', 'icon' => 'database', 'done' => count(array_filter($inventory, fn($i) => !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories']))) > 0],
        ['id' => 'privacy',          'weight' => 10, 'label' => 'Política de Privacidad',       'desc' => 'Política actualizada y accesible para los titulares', 'icon' => 'fileText', 'done' => !empty($config['privacyPolicyUrl'])],
        ['id' => 'consents',         'weight' => 10, 'label' => 'Consentimientos',              'desc' => 'Consentimientos activos y trazables', 'icon' => 'check',    'done' => count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0],
        ['id' => 'breach_protocol',  'weight' => 10, 'label' => 'Protocolo de Brechas',         'desc' => 'Procedimiento documentado de gestión y notificación', 'icon' => 'alert',    'done' => !empty($config['breachProtocolUrl']) || count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0],
        ['id' => 'arco',             'weight' => 10, 'label' => 'Canal ARCO',                   'desc' => 'Canal operativo para derechos de titulares', 'icon' => 'users',    'done' => count($arcoReqs) > 0],
        ['id' => 'pseudonymization', 'weight' => 5,  'label' => 'Seudonimización',              'desc' => 'Medida de seguridad aplicada según riesgo', 'icon' => 'search',   'done' => count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0],
        ['id' => 'incident_response','weight' => 10, 'label' => 'Plan de Respuesta a Incidentes','desc' => 'Plan documentado o evidencia de incidentes gestionados', 'icon' => 'alert',  'done' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0 || !empty($config['incidentResponsePlan'])],
        ['id' => 'training',         'weight' => 5,  'label' => 'Capacitación',                 'desc' => 'Formación completada y respaldada con evidencia', 'icon' => 'info',     'done' => count(array_filter($trainings, fn($t) => !empty($t['completed']))) > 0],
    ];

    $complianceScore = 0;
    $itemsDone = 0;
    foreach ($checklistDef as $item) {
        if ($item['done']) { $complianceScore += $item['weight']; $itemsDone++; }
    }

    // ── Scores derivados ──
    $agentScore    = $totalAgents    > 0 ? ($onlineAgents    / $totalAgents)    : 0;
    $dbScore       = $totalDatabases > 0 ? ($compliantDBs    / $totalDatabases) : 0;
    $breachScore   = $totalBreaches  > 0 ? (($totalBreaches - $openBreaches) / $totalBreaches) : 1;
    $hardeningScore = (int)round(($agentScore * 0.30 + $dbScore * 0.40 + $breachScore * 0.30) * 100);
    $agentDBScore   = (int)round((($agentScore * 0.5 + $dbScore * 0.5)) * 100);
    $globalScore    = (int)round($agentDBScore * 0.30 + $complianceScore * 0.50 + $hardeningScore * 0.20);

    // ── Usuarios vulnerables ──
    $userMonitor = $db->find('user_monitor', $filter, ['limit' => 3000]);
    $vulnerableUsersCount = count(array_filter($userMonitor, fn($u) =>
        !empty($u['vulnerable']) || ($u['riskLevel'] ?? '') === 'high'
    ));

    // ── Usuarios de la empresa ──
    $userCount = $scope['isSuperAdmin'] ? null : count($scope['userIds']);

    $response = [
        'companyName' => $scope['companyName'],
        'userCount'   => $userCount,
        'stats' => [
            'onlineAgents'         => $onlineAgents,
            'totalAgents'          => $totalAgents,
            'totalDatabases'       => $totalDatabases,
            'totalTables'          => $totalTables,
            'totalRecords'         => $totalRecords,
            'complianceScore'      => $complianceScore,
            'compliantDBs'         => $compliantDBs,
            'nonCompliantDBs'      => $nonCompliantDBs,
            'openBreaches'         => $openBreaches,
            'totalBreaches'        => $totalBreaches,
            'vulnerableUsersCount' => $vulnerableUsersCount,
            'activeAlerts'         => $activeAlerts,
            'completedScans'       => $completedScans,
            'totalScans'           => $totalScans,
            'generatedReports'     => $generatedReports,
        ],
        'scores' => [
            'global'     => $globalScore,
            'agentDb'    => $agentDBScore,
            'compliance' => $complianceScore,
            'hardening'  => $hardeningScore,
            'hardeningDone'  => (int)round($hardeningScore / 100 * 6), // proxy de 6 medidas
            'hardeningTotal' => 6,
        ],
        'checklist' => [
            'done'  => $itemsDone,
            'total' => count($checklistDef),
        ],
        'dbCompliance' => $dbCompliance,
        'complianceItems' => array_map(fn($i) => [
            'id' => $i['id'], 'label' => $i['label'], 'done' => $i['done'],
            'desc' => $i['desc'], 'icon' => $i['icon'], 'weight' => $i['weight'],
        ], $checklistDef),
    ];

    _dash_cache_set($cacheKey, $response);
    json_response($response);
}

// =========================================================
// ── Endpoint: arco-summary (lazy-load por tab) ──
// =========================================================
function arcoSummary() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $reqs = $db->find('compliance_arco-requests', $scope['filter'], ['limit' => 2000]);

    $open = 0; $closed = 0; $overdue = 0; $total = count($reqs);
    $totalDays = 0; $countedDays = 0;
    $recent = [];

    foreach ($reqs as $r) {
        $status = $r['status'] ?? 'pending';
        $isClosed = in_array($status, ['resolved', 'rejected', 'closed'], true);
        if ($isClosed) { $closed++; } else { $open++; }

        $created = $r['createdAt'] ?? null;
        $closedAt = $r['resolvedAt'] ?? $r['closedAt'] ?? null;
        $bDays = _dash_business_days_between($created, $closedAt ? strtotime($closedAt) : null);
        $remaining = 15 - $bDays;

        if (!$isClosed && $remaining < 0) $overdue++;

        if ($closedAt && $created) {
            $totalDays += $bDays; $countedDays++;
        }

        if (count($recent) < 10) {
            $recent[] = [
                'id'         => $r['_id'],
                'requestId'  => $r['requestId'] ?? $r['ticketId'] ?? '',
                'type'       => $r['type'] ?? 'acceso',
                'subject'    => $r['subjectName'] ?? $r['email'] ?? '',
                'status'     => $status,
                'createdAt'  => $created,
                'businessDays' => $bDays,
                'daysRemaining' => $remaining,
                'overdue'    => (!$isClosed && $remaining < 0),
            ];
        }
    }
    usort($recent, fn($a, $b) => ($a['daysRemaining'] ?? 999) <=> ($b['daysRemaining'] ?? 999));

    json_response([
        'total'            => $total,
        'open'             => $open,
        'closed'           => $closed,
        'overdue'          => $overdue,
        'avgBusinessDays'  => $countedDays ? round($totalDays / $countedDays, 1) : 0,
        'slaDays'          => 15,
        'recent'           => $recent,
    ]);
}

// =========================================================
// ── Endpoint: breach-timers (72h notificación Agencia) ──
// =========================================================
function breachTimers() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $breaches = $db->find('compliance_breaches', $scope['filter'], ['limit' => 2000]);

    $total = count($breaches);
    $open = 0; $notified = 0; $overdue72 = 0;
    $items = [];

    foreach ($breaches as $b) {
        $status = $b['status'] ?? 'open';
        if ($status !== 'resolved') $open++;

        $detected = $b['detectedAt'] ?? $b['createdAt'] ?? null;
        $notifiedAt = $b['notifiedAgencyAt'] ?? null;
        if ($notifiedAt) $notified++;

        $hoursSince = $detected ? round((time() - strtotime($detected)) / 3600, 1) : null;
        $within72 = $hoursSince !== null ? $hoursSince <= 72 : null;
        if ($status !== 'resolved' && $hoursSince !== null && $hoursSince > 72 && !$notifiedAt) {
            $overdue72++;
        }

        $items[] = [
            'id'          => $b['_id'],
            'title'       => $b['title'] ?? $b['type'] ?? 'Brecha',
            'severity'    => $b['severity'] ?? 'media',
            'status'      => $status,
            'detectedAt'  => $detected,
            'notifiedAgencyAt' => $notifiedAt,
            'hoursSince'  => $hoursSince,
            'within72'    => $within72,
            'overdue72'   => ($status !== 'resolved' && $hoursSince !== null && $hoursSince > 72 && !$notifiedAt),
            'affectedRecords' => (int)($b['affectedRecords'] ?? 0),
        ];
    }

    // Ordenar: primero las vencidas 72h, luego las más recientes
    usort($items, fn($a, $b) => ($b['overdue72'] <=> $a['overdue72']) ?: (($b['hoursSince'] ?? 0) <=> ($a['hoursSince'] ?? 0)));

    json_response([
        'total'         => $total,
        'open'          => $open,
        'notified'      => $notified,
        'overdue72'     => $overdue72,
        'slaHours'      => 72,
        'items'         => array_slice($items, 0, 30),
    ]);
}

// =========================================================
// ── Endpoint: files-summary (archivos + PII monitoreado) ──
// =========================================================
function filesSummary() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $files = $db->find('compliance_files', $scope['filter'], ['limit' => 5000]);

    $total = count($files);
    $withPii = 0; $bytes = 0;
    $piiTypes = []; $byKind = []; $byAgent = [];
    $recent = [];

    foreach ($files as $f) {
        $bytes += (int)($f['size'] ?? 0);
        $has = !empty($f['hasSensitiveData']) || !empty($f['piiDetected']);
        if ($has) $withPii++;

        $kind = $f['kind'] ?? $f['mime'] ?? 'otro';
        $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;

        if ($has && !empty($f['piiTypes']) && is_array($f['piiTypes'])) {
            foreach ($f['piiTypes'] as $t) $piiTypes[$t] = ($piiTypes[$t] ?? 0) + 1;
        }

        $agentId = (string)($f['agentId'] ?? 'desconocido');
        $byAgent[$agentId] = ($byAgent[$agentId] ?? 0) + 1;

        if ($has && count($recent) < 15) {
            $recent[] = [
                'id'       => $f['_id'],
                'name'     => $f['fileName'] ?? $f['name'] ?? 'archivo',
                'path'     => $f['path'] ?? '',
                'piiTypes' => $f['piiTypes'] ?? [],
                'agentId'  => $agentId,
                'createdAt'=> $f['createdAt'] ?? null,
                'size'     => (int)($f['size'] ?? 0),
            ];
        }
    }
    arsort($piiTypes);
    arsort($byKind);

    // Sensitive inventory (top tipos)
    $inv = $db->find('sensitive_inventory', $scope['filter'], ['limit' => 5000]);
    $invTypes = [];
    foreach ($inv as $i) {
        $types = $i['types'] ?? [];
        if (is_array($types)) {
            foreach ($types as $t) $invTypes[$t] = ($invTypes[$t] ?? 0) + 1;
        }
    }
    arsort($invTypes);

    json_response([
        'total'        => $total,
        'withPii'      => $withPii,
        'totalBytes'   => $bytes,
        'piiTypes'     => array_slice(array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($piiTypes), array_values($piiTypes)), 0, 10),
        'byKind'       => array_map(fn($k,$v)=>['kind'=>$k,'count'=>$v], array_keys($byKind), array_values($byKind)),
        'byAgent'      => array_slice(array_map(fn($k,$v)=>['agentId'=>$k,'count'=>$v], array_keys($byAgent), array_values($byAgent)), 0, 10),
        'inventoryPii' => array_slice(array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($invTypes), array_values($invTypes)), 0, 10),
        'recent'       => $recent,
    ]);
}

// =========================================================
// ── Endpoint: recent-activity (auditoría) ──
// =========================================================
function recentActivity() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    // Intentamos varias colecciones de auditoría
    $logs = $db->find('activity_logs', $scope['filter'], ['limit' => 200]);
    if (empty($logs)) $logs = $db->find('audit_logs', $scope['filter'], ['limit' => 200]);

    $items = [];
    foreach ($logs as $l) {
        $items[] = [
            'id'        => $l['_id'],
            'action'    => $l['action'] ?? $l['event'] ?? 'evento',
            'user'      => $l['userEmail'] ?? $l['userId'] ?? '',
            'target'    => $l['target'] ?? $l['resource'] ?? '',
            'severity'  => $l['severity'] ?? 'info',
            'createdAt' => $l['createdAt'] ?? null,
        ];
        if (count($items) >= 40) break;
    }
    usort($items, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

    json_response(['items' => array_slice($items, 0, 40)]);
}

// =========================================================
// ── Endpoint: documentation (reportes y documentos generados) ──
// =========================================================
function documentation() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $reports = $db->find('reports', $scope['filter'], ['limit' => 500]);

    $total = count($reports);
    $byType = [];
    $recent = [];

    foreach ($reports as $r) {
        $type = $r['type'] ?? $r['kind'] ?? 'reporte';
        $byType[$type] = ($byType[$type] ?? 0) + 1;

        if (count($recent) < 15) {
            $recent[] = [
                'id'        => $r['_id'],
                'name'      => $r['name'] ?? $r['title'] ?? 'reporte',
                'type'      => $type,
                'url'       => $r['url'] ?? $r['downloadUrl'] ?? null,
                'size'      => (int)($r['size'] ?? 0),
                'createdAt' => $r['createdAt'] ?? null,
            ];
        }
    }
    usort($recent, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

    json_response([
        'total'   => $total,
        'byType'  => array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($byType), array_values($byType)),
        'recent'  => $recent,
    ]);
}