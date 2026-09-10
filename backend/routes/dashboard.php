<?php
/**
 * Dashboard routes — Company-scoped, Ley 21.719
 * ARCO con query defensiva: múltiples colecciones + filtros en cascada.
 */

// =========================================================
// Helpers
// =========================================================
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

    $userRecord = $db->findOne('users', ['_id' => $user['_id']]) ?: $user;
    $companyId  = (string)($userRecord['companyId'] ?? $user['_id']);

    $companyUsers = $db->find('users', ['companyId' => $companyId], ['limit' => 500]);
    if (empty($companyUsers)) {
        $companyUsers = $db->find('users', ['_id' => $companyId], ['limit' => 500]);
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

function _dash_agent_filter($scope, $db) {
    if ($scope['isSuperAdmin']) return [];

    $agents = $db->find('agents', $scope['filter'], ['limit' => 5000]);
    $agentIds = array_values(array_unique(array_map(fn($a) => (string)$a['_id'], $agents)));
    if (empty($agentIds)) return $scope['filter'];

    return [
        '$or' => [
            ['userId'  => ['$in' => $scope['userIds']]],
            ['agentId' => ['$in' => $agentIds]],
        ],
    ];
}

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

/**
 * Días hábiles entre una fecha ISO y hoy.
 * Misma lógica que arco.php para que los números coincidan exactamente.
 */
function _dash_business_days_elapsed($dateStr) {
    $ts = strtotime($dateStr ?: 'now');
    if (!$ts) return 0;
    $days = 0;
    $cur  = strtotime(date('Y-m-d', $ts));
    $today = strtotime(date('Y-m-d'));
    while ($cur < $today) {
        $cur = strtotime('+1 day', $cur);
        if ((int)date('N', $cur) < 6) $days++;
    }
    return $days;
}

/**
 * Encuentra la colección ARCO real y devuelve los documentos
 * filtrados por empresa. Prueba varias combinaciones para no
 * depender del nombre exacto de la colección ni del campo scope.
 */
function _dash_find_arco_requests($scope, $db) {
    $collections = [
        'compliance_arco-requests',
        'compliance_arco_requests',
        'arco_requests',
        'arco-requests',
        'arco',
    ];

    foreach ($collections as $col) {
        // 1) Filtro por userId (lo que usaba el dashboard)
        if (!$scope['isSuperAdmin']) {
            $reqs = $db->find($col, ['userId' => ['$in' => $scope['userIds']]], ['limit' => 3000]);
            if (!empty($reqs)) return ['collection' => $col, 'items' => $reqs, 'via' => 'userId'];
        }

        // 2) Filtro por companyId (probable para solicitudes públicas)
        if (!$scope['isSuperAdmin'] && !empty($scope['companyId'])) {
            $reqs = $db->find($col, ['companyId' => $scope['companyId']], ['limit' => 3000]);
            if (!empty($reqs)) return ['collection' => $col, 'items' => $reqs, 'via' => 'companyId'];
        }

        // 3) Filtro combinado $or
        if (!$scope['isSuperAdmin']) {
            $orFilter = [
                '$or' => [
                    ['userId'    => ['$in' => $scope['userIds']]],
                    ['companyId' => $scope['companyId'] ?? ''],
                ],
            ];
            $reqs = $db->find($col, $orFilter, ['limit' => 3000]);
            if (!empty($reqs)) return ['collection' => $col, 'items' => $reqs, 'via' => '$or'];
        }

        // 4) Superadmin: todo
        if ($scope['isSuperAdmin']) {
            $reqs = $db->find($col, [], ['limit' => 3000]);
            if (!empty($reqs)) return ['collection' => $col, 'items' => $reqs, 'via' => 'all'];
        }

        // 5) Último recurso: leer todo y filtrar en memoria
        $all = $db->find($col, [], ['limit' => 5000]);
        if (!empty($all)) {
            $filtered = [];
            foreach ($all as $r) {
                $rUid = (string)($r['userId']    ?? '');
                $rCid = (string)($r['companyId'] ?? '');
                $rOwner = (string)($r['ownerId'] ?? '');
                if ($scope['isSuperAdmin']
                    || in_array($rUid, $scope['userIds'], true)
                    || ($scope['companyId'] && ($rCid === $scope['companyId'] || $rOwner === $scope['companyId']))) {
                    $filtered[] = $r;
                }
            }
            if (!empty($filtered)) return ['collection' => $col, 'items' => $filtered, 'via' => 'memory'];
        }
    }

    return ['collection' => null, 'items' => [], 'via' => 'none'];
}

// =========================================================
// status (ligero)
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
        'alerts'             => $db->find('alerts', $scope['filter'], ['limit' => 10]),
        'agentCount'         => $db->count('agents', $scope['filter']),
        'databaseCount'      => $db->count('databases', $scope['filter']),
        'activeAlerts'       => $db->count('alerts', $activeFilter),
        'companyName'        => $scope['companyName'],
        'userCount'          => $scope['isSuperAdmin'] ? null : count($scope['userIds']),
    ]);
}

// =========================================================
// stats (KPIs principales)
// =========================================================
function stats() {
    $user = Auth::requireAuth();
    $db   = Database::getInstance();
    $scope = _dash_scope($user, $db);
    $cacheKey = 'stats:' . ($scope['companyId'] ?? 'superadmin');

    $cached = _dash_cache_get($cacheKey, 45);
    if ($cached) { json_response($cached); }

    $filter = $scope['filter'];

    $totalAgents    = $db->count('agents', $filter);
    $totalDatabases = $db->count('databases', $filter);
    $totalScans     = $db->count('scans', $filter);

    $agents = $db->find('agents', $filter, ['limit' => 5000]);
    $onlineAgents = 0;
    foreach ($agents as $a) { if (($a['status'] ?? '') === 'online') $onlineAgents++; }

    $activeAlerts = $db->count('alerts', array_merge($filter, [
        'resolved'  => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]));

    $openBreaches  = $db->count('compliance_breaches', array_merge($filter, ['status' => ['$ne' => 'resolved']]));
    $totalBreaches = $db->count('compliance_breaches', $filter);
    $completedScans = $db->count('scans', array_merge($filter, ['status' => 'completed']));

    $monthStart = date('Y-m-01') . 'T00:00:00';
    $generatedReports = $db->count('reports', array_merge($filter, ['createdAt' => ['$gte' => $monthStart]]));

    $databases = $db->find('databases', $filter, ['limit' => 2000]);
    $totalTables = 0; $totalRecords = 0; $compliantDBs = 0;
    $dbCompliance = [];
    foreach ($databases as $d) {
        $tables  = (int)($d['tableCount']  ?? $d['tables']  ?? 0);
        $records = (int)($d['recordCount'] ?? $d['records'] ?? 0);
        $totalTables  += $tables;
        $totalRecords += $records;

        $isConnected = ($d['status'] ?? '') === 'connected';
        $dbBreaches = (int)($d['openBreaches'] ?? 0);
        $compliant  = $isConnected && $dbBreaches === 0 && ($d['compliant'] ?? true) !== false;
        if ($compliant) $compliantDBs++;

        $dbCompliance[] = [
            'id' => $d['_id'], 'name' => $d['name'] ?? $d['database'] ?? 'db',
            'engine' => $d['engine'] ?? $d['type'] ?? '', 'tables' => $tables,
            'records' => $records, 'compliant' => $compliant,
            'status' => $d['status'] ?? 'configured', 'breaches' => $dbBreaches,
        ];
    }
    $totalDatabases = count($dbCompliance) ?: $totalDatabases;
    $nonCompliantDBs = max(0, $totalDatabases - $compliantDBs);

    // Checklist ponderado
    $config      = $db->findOne('compliance_config', $filter) ?: [];
    $inventory   = $db->find('compliance_inventory', $filter, ['limit' => 2000]);
    $consents    = $db->find('compliance_consents', $filter, ['limit' => 5000]);
    $breaches    = $db->find('compliance_breaches', $filter, ['limit' => 2000]);
    $trainings   = $db->find('compliance_trainings', $filter, ['limit' => 2000]);
    $pseudoRules = $db->find('compliance_pseudonymization', $filter, ['limit' => 500]);

    // ✅ ARCO: usar la query defensiva
    $arcoResult = _dash_find_arco_requests($scope, $db);
    $arcoReqs = $arcoResult['items'];

    $checklistDef = [
        ['id'=>'dpd','weight'=>15,'label'=>'DPD Designado','desc'=>'Función obligatoria cuando aplica','icon'=>'users','done'=>!empty($config['dpdEmail']) && !empty($config['dpdName']) && !empty($config['dpdPhone'])],
        ['id'=>'apdp','weight'=>10,'label'=>'Modelo certificado (APDP)','desc'=>'Registro o evidencia del modelo de prevención','icon'=>'shield','done'=>($config['apdpRegistered'] ?? false) && !empty($config['apdpRegistrationNumber'])],
        ['id'=>'inventory','weight'=>15,'label'=>'Inventario de Datos (RAT)','desc'=>'Registro documentado del tratamiento','icon'=>'database','done'=>count(array_filter($inventory, fn($i)=>!empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories'])))>0],
        ['id'=>'privacy','weight'=>10,'label'=>'Política de Privacidad','desc'=>'Política actualizada y accesible','icon'=>'fileText','done'=>!empty($config['privacyPolicyUrl'])],
        ['id'=>'consents','weight'=>10,'label'=>'Consentimientos','desc'=>'Activos y trazables','icon'=>'check','done'=>count(array_filter($consents, fn($c)=>empty($c['revokedAt'])))>0],
        ['id'=>'breach_protocol','weight'=>10,'label'=>'Protocolo de Brechas','desc'=>'Procedimiento documentado','icon'=>'alert','done'=>!empty($config['breachProtocolUrl']) || count(array_filter($breaches, fn($b)=>($b['status'] ?? '')==='resolved'))>0],
        ['id'=>'arco','weight'=>10,'label'=>'Canal ARCO','desc'=>'Canal operativo para titulares','icon'=>'users','done'=>count($arcoReqs)>0],
        ['id'=>'pseudonymization','weight'=>5,'label'=>'Seudonimización','desc'=>'Medida de seguridad aplicada','icon'=>'search','done'=>count(array_filter($pseudoRules, fn($r)=>($r['status'] ?? '')==='executed' || !empty($r['executed'])))>0],
        ['id'=>'incident_response','weight'=>10,'label'=>'Plan de Respuesta a Incidentes','desc'=>'Plan documentado o incidentes gestionados','icon'=>'alert','done'=>count(array_filter($breaches, fn($b)=>($b['status'] ?? '')==='resolved'))>0 || !empty($config['incidentResponsePlan'])],
        ['id'=>'training','weight'=>5,'label'=>'Capacitación','desc'=>'Formación completada con evidencia','icon'=>'info','done'=>count(array_filter($trainings, fn($t)=>!empty($t['completed'])))>0],
    ];

    $complianceScore = 0; $itemsDone = 0;
    foreach ($checklistDef as $item) {
        if ($item['done']) { $complianceScore += $item['weight']; $itemsDone++; }
    }

    $agentScore    = $totalAgents    > 0 ? ($onlineAgents    / $totalAgents)    : 0;
    $dbScore       = $totalDatabases > 0 ? ($compliantDBs    / $totalDatabases) : 0;
    $breachScore   = $totalBreaches  > 0 ? (($totalBreaches - $openBreaches) / $totalBreaches) : 1;
    $hardeningScore = (int)round(($agentScore * 0.30 + $dbScore * 0.40 + $breachScore * 0.30) * 100);
    $agentDBScore   = (int)round(($agentScore * 0.5 + $dbScore * 0.5) * 100);
    $globalScore    = (int)round($agentDBScore * 0.30 + $complianceScore * 0.50 + $hardeningScore * 0.20);

    $userMonitor = $db->find('user_monitor', $filter, ['limit' => 3000]);
    $vulnerableUsersCount = count(array_filter($userMonitor, fn($u) =>
        !empty($u['vulnerable']) || ($u['riskLevel'] ?? '') === 'high'
    ));

    $response = [
        'companyName' => $scope['companyName'],
        'userCount'   => $scope['isSuperAdmin'] ? null : count($scope['userIds']),
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
            'arcoTotal'            => count($arcoReqs), // ✅ para el KPI
        ],
        'scores' => [
            'global' => $globalScore, 'agentDb' => $agentDBScore,
            'compliance' => $complianceScore, 'hardening' => $hardeningScore,
            'hardeningDone'  => (int)round($hardeningScore / 100 * 6),
            'hardeningTotal' => 6,
        ],
        'checklist' => ['done' => $itemsDone, 'total' => count($checklistDef)],
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
// ARCO summary — query defensiva con SLA 10 días hábiles
// =========================================================
function arcoSummary() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $result = _dash_find_arco_requests($scope, $db);
    $reqs   = $result['items'];
    $SLA_DAYS = 10; // Ley 21.719: 10 días hábiles

    $total = count($reqs);
    $byStatus = ['pending'=>0, 'in_progress'=>0, 'completed'=>0, 'finished'=>0, 'rejected'=>0];
    $overdue = 0; $totalDays = 0; $countedDays = 0;
    $byType = [];
    $recent = [];

    foreach ($reqs as $r) {
        $status = (string)($r['status'] ?? 'pending');
        if (!isset($byStatus[$status])) $byStatus[$status] = 0;
        $byStatus[$status]++;

        $type = (string)($r['type'] ?? $r['tipo'] ?? 'acceso');
        $byType[$type] = ($byType[$type] ?? 0) + 1;

        $created = $r['createdAt'] ?? $r['created_at'] ?? $r['fecha'] ?? null;
        $isClosed = in_array($status, ['completed', 'resolved', 'finished', 'rejected'], true);
        $bDays = _dash_business_days_elapsed($created);
        $remaining = $SLA_DAYS - $bDays;

        if (!$isClosed && $bDays > $SLA_DAYS) $overdue++;

        if ($isClosed && $created) {
            $totalDays += $bDays;
            $countedDays++;
        }

        $name  = $r['solicitante']['nombre'] ?? $r['name'] ?? $r['requesterName'] ?? 'Titular';
        $email = $r['solicitante']['email']  ?? $r['email'] ?? $r['requesterEmail'] ?? '';
        $rut   = $r['solicitante']['rut']    ?? $r['rut'] ?? '';
        $rid   = $r['requestId'] ?? $r['_id'] ?? '';

        $recent[] = [
            'id'            => $r['_id'] ?? $rid,
            'requestId'     => (string)$rid,
            'type'          => $type,
            'subject'       => $name,
            'email'         => $email,
            'rut'           => $rut,
            'status'        => $status,
            'createdAt'     => $created,
            'businessDays'  => $bDays,
            'daysRemaining' => $remaining,
            'overdue'       => (!$isClosed && $bDays > $SLA_DAYS),
        ];
    }

    // Ordenar: pendientes/vencidas primero por días restantes
    usort($recent, function($a, $b) {
        $aClosed = in_array($a['status'], ['completed','finished','resolved','rejected'], true);
        $bClosed = in_array($b['status'], ['completed','finished','resolved','rejected'], true);
        if ($aClosed !== $bClosed) return $aClosed ? 1 : -1;
        return ($a['daysRemaining'] ?? 999) <=> ($b['daysRemaining'] ?? 999);
    });

    json_response([
        'total'           => $total,
        'pending'         => $byStatus['pending']     ?? 0,
        'in_progress'     => $byStatus['in_progress'] ?? 0,
        'completed'       => $byStatus['completed']   ?? 0,
        'finished'        => $byStatus['finished']    ?? 0,
        'rejected'        => $byStatus['rejected']    ?? 0,
        'overdue'         => $overdue,
        'slaDays'         => $SLA_DAYS,
        'avgBusinessDays' => $countedDays ? round($totalDays / $countedDays, 1) : 0,
        'byType'          => array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($byType), array_values($byType)),
        'recent'          => array_slice($recent, 0, 20),
        // Debug opcional: útil para ti mientras ajustas la colección real
        '_debug' => [
            'collection' => $result['collection'],
            'via'        => $result['via'],
        ],
    ]);
}

// =========================================================
// breach-timers (72h)
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
        $isOverdue = ($status !== 'resolved' && $hoursSince !== null && $hoursSince > 72 && !$notifiedAt);
        if ($isOverdue) $overdue72++;

        $items[] = [
            'id' => $b['_id'], 'title' => $b['title'] ?? $b['type'] ?? 'Brecha',
            'severity' => $b['severity'] ?? 'media', 'status' => $status,
            'detectedAt' => $detected, 'notifiedAgencyAt' => $notifiedAt,
            'hoursSince' => $hoursSince, 'within72' => $within72,
            'overdue72' => $isOverdue, 'affectedRecords' => (int)($b['affectedRecords'] ?? 0),
        ];
    }
    usort($items, fn($a, $b) => ($b['overdue72'] <=> $a['overdue72']) ?: (($b['hoursSince'] ?? 0) <=> ($a['hoursSince'] ?? 0)));

    json_response([
        'total' => $total, 'open' => $open, 'notified' => $notified,
        'overdue72' => $overdue72, 'slaHours' => 72,
        'items' => array_slice($items, 0, 30),
    ]);
}

// =========================================================
// files-summary (FIX: agentId + userId)
// =========================================================
function filesSummary() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $fileFilter = _dash_agent_filter($scope, $db);
    $files = $db->find('compliance_files', $fileFilter, ['limit' => 5000]);
    if (empty($files)) $files = $db->find('compliance_files_index', $fileFilter, ['limit' => 5000]);
    if (empty($files)) $files = $db->find('monitored_files', $fileFilter, ['limit' => 5000]);

    $total = count($files); $withPii = 0; $bytes = 0;
    $piiTypes = []; $byKind = []; $byAgent = []; $recent = [];

    foreach ($files as $f) {
        $size = (int)($f['size'] ?? $f['fileSize'] ?? 0);
        $bytes += $size;
        $has = !empty($f['hasSensitiveData']) || !empty($f['piiDetected']) || !empty($f['containsPII'])
            || !empty($f['sensitive']) || !empty($f['piiTypes']) || !empty($f['sensitiveColumns']);
        if ($has) $withPii++;

        $kind = $f['kind'] ?? $f['mime'] ?? $f['extension'] ?? 'otro';
        $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;

        $types = [];
        if (!empty($f['piiTypes']) && is_array($f['piiTypes'])) $types = $f['piiTypes'];
        elseif (!empty($f['sensitiveTypes']) && is_array($f['sensitiveTypes'])) $types = $f['sensitiveTypes'];
        elseif (!empty($f['sensitiveColumns']) && is_array($f['sensitiveColumns'])) $types = $f['sensitiveColumns'];
        foreach ($types as $t) {
            $key = is_array($t) ? ($t['type'] ?? json_encode($t)) : (string)$t;
            $piiTypes[$key] = ($piiTypes[$key] ?? 0) + 1;
        }

        $agentId = (string)($f['agentId'] ?? 'desconocido');
        $byAgent[$agentId] = ($byAgent[$agentId] ?? 0) + 1;

        if ($has && count($recent) < 15) {
            $recent[] = [
                'id' => $f['_id'], 'name' => $f['fileName'] ?? $f['name'] ?? 'archivo',
                'path' => $f['path'] ?? $f['filePath'] ?? '', 'piiTypes' => $types,
                'agentId' => $agentId, 'createdAt' => $f['createdAt'] ?? $f['detectedAt'] ?? null,
                'size' => $size,
            ];
        }
    }
    arsort($piiTypes); arsort($byKind); arsort($byAgent);

    $invFilter = _dash_agent_filter($scope, $db);
    $inv = $db->find('sensitive_inventory', $invFilter, ['limit' => 5000]);
    if (empty($inv)) $inv = $db->find('compliance_sensitive_inventory', $invFilter, ['limit' => 5000]);
    $invTypes = [];
    foreach ($inv as $i) {
        $types = $i['types'] ?? $i['piiTypes'] ?? [];
        if (is_array($types)) foreach ($types as $t) {
            $key = is_array($t) ? ($t['type'] ?? json_encode($t)) : (string)$t;
            $invTypes[$key] = ($invTypes[$key] ?? 0) + 1;
        }
    }
    arsort($invTypes);

    json_response([
        'total' => $total, 'withPii' => $withPii, 'totalBytes' => $bytes,
        'piiTypes' => array_slice(array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($piiTypes), array_values($piiTypes)), 0, 10),
        'byKind'   => array_map(fn($k,$v)=>['kind'=>$k,'count'=>$v], array_keys($byKind), array_values($byKind)),
        'byAgent'  => array_slice(array_map(fn($k,$v)=>['agentId'=>$k,'count'=>$v], array_keys($byAgent), array_values($byAgent)), 0, 10),
        'inventoryPii' => array_slice(array_map(fn($k,$v)=>['type'=>$k,'count'=>$v], array_keys($invTypes), array_values($invTypes)), 0, 10),
        'recent'   => $recent,
    ]);
}

// =========================================================
// recent-activity (audit)
// =========================================================
function recentActivity() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $collections = ['activity_logs', 'audit_logs', 'activity', 'logs', 'audit'];
    $logs = [];
    foreach ($collections as $col) {
        $logs = $db->find($col, $scope['filter'], ['limit' => 200]);
        if (!empty($logs)) break;
    }
    if (empty($logs) && !$scope['isSuperAdmin'] && $scope['companyId']) {
        foreach ($collections as $col) {
            $logs = $db->find($col, ['companyId' => $scope['companyId']], ['limit' => 200]);
            if (!empty($logs)) break;
        }
    }

    $items = [];
    foreach ($logs as $l) {
        $items[] = [
            'id' => $l['_id'], 'action' => $l['action'] ?? $l['event'] ?? $l['type'] ?? 'evento',
            'user' => $l['userEmail'] ?? $l['email'] ?? $l['userId'] ?? '',
            'target' => $l['target'] ?? $l['resource'] ?? $l['details'] ?? '',
            'severity' => $l['severity'] ?? $l['level'] ?? 'info',
            'createdAt' => $l['createdAt'] ?? $l['timestamp'] ?? $l['ts'] ?? null,
        ];
        if (count($items) >= 200) break;
    }
    usort($items, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

    json_response(['items' => array_slice($items, 0, 40)]);
}

// =========================================================
// DEBUG — endpoint para ti: te dice la colección real y campos
// =========================================================
function arcoDebug() {
    $user  = Auth::requireAuth();
    $db    = Database::getInstance();
    $scope = _dash_scope($user, $db);

    $collections = ['compliance_arco-requests','compliance_arco_requests','arco_requests','arco-requests','arco'];
    $report = [];
    foreach ($collections as $col) {
        $count = $db->count($col, []);
        if ($count > 0) {
            $sample = $db->find($col, [], ['limit' => 1]);
            $report[$col] = [
                'total'   => $count,
                'sample'  => $sample[0] ?? null,
                'fields'  => $sample[0] ? array_keys($sample[0]) : [],
            ];
        } else {
            $report[$col] = ['total' => 0];
        }
    }

    json_response([
        'scope' => [
            'isSuperAdmin' => $scope['isSuperAdmin'],
            'companyId'    => $scope['companyId'],
            'userIds'      => $scope['userIds'],
        ],
        'collections' => $report,
    ]);
}