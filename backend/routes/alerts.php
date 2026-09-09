<?php
// Alert routes

function listAll() {
    error_log("[ALERTAS-DEBUG] listAll() ejecutada en routes/alerts.php");

    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    // 1. Obtener companyId del usuario autenticado
    $userRecord = $db->findOne('users', ['_id' => $user['_id']]);
    if (!$userRecord) {
        json_error('Usuario no encontrado');
    }
    $companyId = $userRecord['companyId'] ?? $user['_id'];

    // 2. Obtener todos los userIds de la empresa (convertidos a string)
    $users = $db->find('users', ['companyId' => $companyId]);
    $userIds = array_map('strval', array_column($users, '_id'));
    if (empty($userIds)) {
        $userIds = [(string)$user['_id']];
    }

    // 3. Construir filtro base
    $filter = ['userId' => ['$in' => $userIds]];

    // 4. Aplicar filtros adicionales (severity, category, source, lawArticle, status, date, search)
    if (!empty($body['severity'])) {
        $filter['severity'] = $body['severity'];
    }
    if (!empty($body['category'])) {
        $filter['category'] = $body['category'];
    }
    if (!empty($body['source'])) {
        $filter['source'] = $body['source'];
    }
    if (!empty($body['lawArticle'])) {
        $filter['lawArticle'] = $body['lawArticle'];
    }
    if (!empty($body['status'])) {
        if ($body['status'] === 'active') {
            $filter['resolved'] = ['$ne' => true];
            $filter['dismissed'] = ['$ne' => true];
        } elseif ($body['status'] === 'resolved') {
            $filter['resolved'] = true;
        } elseif ($body['status'] === 'dismissed') {
            $filter['dismissed'] = true;
        }
    }
    if (!empty($body['date_from'])) {
        $filter['createdAt'] = ['$gte' => $body['date_from']];
    }
    if (!empty($body['date_to'])) {
        $filter['createdAt']['$lte'] = $body['date_to'] . 'T23:59:59';
    }
    if (!empty($body['search']) && strlen(trim($body['search'])) >= 2) {
        $search = trim($body['search']);
        $regex = ['$regex' => $search, '$options' => 'i'];
        $filter['$or'] = [
            ['title' => $regex],
            ['message' => $regex],
            ['agentId' => $regex],
            ['lawArticle' => $regex],
            ['eventType' => $regex],
        ];
    }

    // 5. Orden y paginación
    $sortBy = $body['sort'] ?? 'createdAt';
    $sortDir = $body['dir'] ?? 'desc';
    $sort = [$sortBy => ($sortDir === 'asc' ? 1 : -1)];

    $limit = (int)($body['limit'] ?? 50);
    $offset = (int)($body['offset'] ?? 0);
    if ($limit <= 0) $limit = 50;
    if ($offset < 0) $offset = 0;

    // 6. Total (sin paginar)
    $total = $db->count('alerts', $filter);

    // 7. Datos paginados
    $alerts = $db->find('alerts', $filter, [
        'limit' => $limit,
        'skip'  => $offset,
        'sort'  => $sort,
    ]);

    // 8. Estadísticas sobre el conjunto filtrado (sin paginar)
    $stats = [];

    // Activas
    $activeFilter = array_merge($filter, [
        'resolved' => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]);
    $stats['active'] = $db->count('alerts', $activeFilter);

    // Resueltas
    $resolvedFilter = array_merge($filter, ['resolved' => true]);
    $stats['resolved'] = $db->count('alerts', $resolvedFilter);

    // Descartadas
    $dismissedFilter = array_merge($filter, ['dismissed' => true]);
    $stats['dismissed'] = $db->count('alerts', $dismissedFilter);

    // No leídas
    $unreadFilter = array_merge($filter, ['read' => ['$ne' => true]]);
    $stats['unread'] = $db->count('alerts', $unreadFilter);

    // Críticas activas
    $criticalActiveFilter = array_merge($activeFilter, ['severity' => 'critical']);
    $stats['critical'] = $db->count('alerts', $criticalActiveFilter);

    // Altas activas
    $highActiveFilter = array_merge($activeFilter, ['severity' => 'high']);
    $stats['high'] = $db->count('alerts', $highActiveFilter);

    // Tendencia últimos 7 días (agregación)
    $trendPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$createdAt']],
            'count' => ['$sum' => 1],
            'critical' => ['$sum' => ['$cond' => [['$eq' => ['$severity', 'critical']], 1, 0]]],
        ]],
        ['$sort' => ['_id' => 1]],
        ['$limit' => 7],
    ];
    $trendResult = $db->aggregate('alerts', $trendPipeline);
    $trendData = [];
    foreach ($trendResult as $row) {
        $trendData[] = [
            'date' => date('d/m', strtotime($row['_id'])),
            'count' => $row['count'],
            'critical' => $row['critical'],
        ];
    }
    // Rellenar días faltantes (si el usuario no tiene alertas en algún día)
    $dates = array_column($trendData, 'date');
    for ($i = 6; $i >= 0; $i--) {
        $d = date('d/m', strtotime("-$i days"));
        if (!in_array($d, $dates)) {
            $trendData[] = ['date' => $d, 'count' => 0, 'critical' => 0];
        }
    }
    // Ordenar por fecha ascendente
    usort($trendData, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
    $stats['trend'] = array_slice($trendData, -7); // asegurar solo 7

    // Distribución por severidad
    $sevPipeline = [
        ['$match' => $filter],
        ['$group' => [
            '_id' => '$severity',
            'count' => ['$sum' => 1],
        ]],
    ];
    $sevResult = $db->aggregate('alerts', $sevPipeline);
    $sevMap = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
    foreach ($sevResult as $row) {
        if (isset($sevMap[$row['_id']])) {
            $sevMap[$row['_id']] = $row['count'];
        }
    }
    $stats['severity'] = $sevMap;

    // Devolver respuesta
    json_response([
        'alerts' => $alerts,
        'total' => $total,
        'stats' => $stats,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

// ──────────────────────────────────────────────
// stats() – versión optimizada
// ──────────────────────────────────────────────
function stats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    // Obtener companyId y userIds de la empresa
    $userRecord = $db->findOne('users', ['_id' => $user['_id']]);
    if (!$userRecord) {
        json_error('Usuario no encontrado');
    }
    $companyId = $userRecord['companyId'] ?? $user['_id'];
    $users = $db->find('users', ['companyId' => $companyId]);
    $userIds = array_map('strval', array_column($users, '_id'));
    if (empty($userIds)) {
        $userIds = [(string)$user['_id']];
    }

    $filter = ['userId' => ['$in' => $userIds]];

    $total = $db->count('alerts', $filter);
    $critical = $db->count('alerts', array_merge($filter, ['severity' => 'critical']));
    $high = $db->count('alerts', array_merge($filter, ['severity' => 'high']));
    $unresolved = $db->count('alerts', array_merge($filter, [
        'resolved' => ['$ne' => true],
        'dismissed' => ['$ne' => true],
    ]));

    json_response([
        'total' => $total,
        'critical' => $critical,
        'high' => $high,
        'unresolved' => $unresolved,
    ]);
}

// ──────────────────────────────────────────────
// Funciones auxiliares y de acción (sin cambios)
// ──────────────────────────────────────────────

function findAlertSource($db, $alertId, $userId) {
    $collections = ['alerts','compliance_breaches','arco_requests','host_events','file_events','database_logs','file_audit_logs','audit_logs','compliance_consents'];
    foreach ($collections as $col) {
        $doc = $db->findOne($col, ['_id' => $alertId]);
        if ($doc) {
            $owner = $doc['userId'] ?? $doc['companyId'] ?? '';
            if ((string)$owner === (string)$userId) {
                return ['collection' => $col, 'doc' => $doc];
            }
        }
    }
    return null;
}

function resolve() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $alertId = $body['alertId'] ?? '';
    if (!$alertId) json_error('alertId requerido');
    $src = findAlertSource($db, $alertId, $user['_id']);
    if (!$src) json_error('alerta no encontrada', 404);
    $now = date('c');
    $resolution = $body['resolution'] ?? ($body['notes'] ?? '');
    if ($src['collection'] === 'compliance_breaches') {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['status' => 'resolved', 'resolvedAt' => $now, 'resolution' => $resolution]);
    } elseif ($src['collection'] === 'arco_requests') {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['status' => 'completed', 'resolvedAt' => $now, 'response' => $resolution]);
    } else {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['resolved' => true, 'resolvedAt' => $now, 'resolution' => $resolution]);
    }
    json_response(['success' => true]);
}

function dismiss() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $alertId = $body['alertId'] ?? '';
    if (!$alertId) json_error('alertId requerido');
    $src = findAlertSource($db, $alertId, $user['_id']);
    if (!$src) json_error('alerta no encontrada', 404);
    $now = date('c');
    if ($src['collection'] === 'compliance_breaches') {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['status' => 'closed_no_action', 'dismissedAt' => $now]);
    } elseif ($src['collection'] === 'arco_requests') {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['status' => 'rejected', 'dismissedAt' => $now]);
    } else {
        $db->updateOne($src['collection'], ['_id' => $alertId], ['dismissed' => true, 'dismissedAt' => $now]);
    }
    json_response(['success' => true]);
}

function resolveBulk() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $ids = json_decode($body['alertIds'] ?? '[]', true);
    if (empty($ids) || !is_array($ids)) json_error('alertIds requerido');
    $now = date('c');
    $resolved = 0;
    foreach ($ids as $id) {
        $src = findAlertSource($db, $id, $user['_id']);
        if ($src) {
            if ($src['collection'] === 'compliance_breaches') {
                $db->updateOne($src['collection'], ['_id' => $id], ['status' => 'resolved', 'resolvedAt' => $now]);
            } elseif ($src['collection'] === 'arco_requests') {
                $db->updateOne($src['collection'], ['_id' => $id], ['status' => 'completed', 'resolvedAt' => $now]);
            } else {
                $db->updateOne($src['collection'], ['_id' => $id], ['resolved' => true, 'resolvedAt' => $now]);
            }
            $resolved++;
        }
    }
    json_response(['success' => true, 'resolved' => $resolved]);
}

function deleteAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $all = $db->find('alerts', ['userId' => $user['_id']]);
    foreach ($all as $alert) {
        $db->deleteOne('alerts', ['_id' => $alert['_id']]);
    }
    json_response(['success' => true]);
}

function markRead() {
    $user = Auth::requireAuth();
    $body = get_body();
    $alertId = $body['alertId'] ?? '';
    if (!$alertId) json_error('alertId requerido');
    $db = Database::getInstance();
    $src = findAlertSource($db, $alertId, $user['_id']);
    if (!$src) json_error('alerta no encontrada', 404);
    $db->updateOne($src['collection'], ['_id' => $alertId], ['readAt' => date('c')]);
    json_response(['success' => true]);
}

function exportCsv() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (!empty($body['severity'])) $filter['severity'] = $body['severity'];
    if (!empty($body['category'])) $filter['category'] = $body['category'];
    if (!empty($body['source'])) $filter['source'] = $body['source'];
    if (!empty($body['lawArticle'])) $filter['lawArticle'] = $body['lawArticle'];
    if (isset($body['resolved'])) $filter['resolved'] = filter_var($body['resolved'], FILTER_VALIDATE_BOOLEAN);
    $alerts = $db->find('alerts', $filter);
    usort($alerts, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alertas-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Fecha', 'Título', 'Mensaje', 'Severidad', 'Fuente', 'Categoría', 'Artículo Legal', 'Agente', 'Estado', 'Leída', 'Requiere APDP', 'Requiere Titulares', 'Plazo']);
    foreach ($alerts as $a) {
        fputcsv($out, [
            $a['_id'] ?? '',
            $a['createdAt'] ?? '',
            $a['title'] ?? '',
            $a['message'] ?? '',
            $a['severity'] ?? '',
            $a['source'] ?? '',
            $a['category'] ?? '',
            $a['lawArticle'] ?? '',
            $a['agentId'] ?? '',
            $a['resolved'] ? 'Resuelta' : ($a['dismissed'] ? 'Descartada' : 'Activa'),
            $a['read'] ? 'Sí' : 'No',
            $a['requiresAPDPNotification'] ? 'Sí' : 'No',
            $a['requiresSubjectNotification'] ? 'Sí' : 'No',
            $a['deadline'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}