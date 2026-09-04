<?php
// Alert routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $alerts = $db->find('alerts', ['userId' => $user['_id']]);

    // ── Ley 21.719: Fuentes de alertas de cumplimiento ──

    // 1. Brechas de seguridad (Art. 26)
    $breaches = $db->find('compliance_breaches', ['userId' => $user['_id']]);
    foreach ($breaches as $b) {
        $sev = $b['severity'] ?? 'medium';
        $alerts[] = [
            '_id' => (string)($b['_id'] ?? ''),
            'userId' => $user['_id'],
            'title' => 'Brecha de seguridad: ' . ($b['title'] ?? 'Sin título'),
            'message' => ($b['description'] ?? '') . ' · Tipo: ' . ($b['breachType'] ?? '') . ' · Titulares afectados: ' . ($b['affectedCount'] ?? 'N/A'),
            'source' => 'compliance_breach',
            'category' => 'breach_notification',
            'severity' => $sev,
            'eventType' => 'breach_' . ($b['breachType'] ?? 'unknown'),
            'resolved' => ($b['status'] ?? '') === 'resolved',
            'dismissed' => ($b['status'] ?? '') === 'closed_no_action',
            'read' => !empty($b['readAt']),
            'createdAt' => $b['createdAt'] ?? $b['detectedAt'] ?? date('c'),
            'lawArticle' => 'Art. 26 Ley 21.719',
            'requiresAPDPNotification' => !empty($b['notifiedAPDP']),
            'requiresSubjectNotification' => !empty($b['notifiedSubjects']),
            'details' => $b,
        ];
    }

    // 2. Solicitudes ARCO (Art. 8-13)
    $arcoRequests = $db->find('arco_requests', ['userId' => $user['_id']]);
    foreach ($arcoRequests as $ar) {
        $alerts[] = [
            '_id' => (string)($ar['_id'] ?? ''),
            'userId' => $user['_id'],
            'title' => 'Solicitud ARCO: ' . ($ar['type'] ?? 'acceso'),
            'message' => 'Titular: ' . ($ar['subjectName'] ?? $ar['subjectEmail'] ?? 'N/A') . ' · Estado: ' . ($ar['status'] ?? 'pending') . ' · Plazo: 10 días hábiles',
            'source' => 'arco_request',
            'category' => 'data_subject_rights',
            'severity' => ($ar['status'] ?? '') === 'overdue' ? 'critical' : (($ar['status'] ?? '') === 'pending' ? 'high' : 'low'),
            'eventType' => 'arco_' . ($ar['type'] ?? 'unknown'),
            'resolved' => in_array($ar['status'] ?? '', ['completed', 'delivered']),
            'dismissed' => ($ar['status'] ?? '') === 'rejected',
            'read' => !empty($ar['readAt']),
            'createdAt' => $ar['createdAt'] ?? $ar['requestedAt'] ?? date('c'),
            'lawArticle' => 'Art. ' . (['acceso'=>8,'rectificacion'=>9,'cancelacion'=>10,'oposicion'=>11,'portabilidad'=>12][$ar['type'] ?? ''] ?? '8-13') . ' Ley 21.719',
            'deadline' => $ar['deadline'] ?? null,
            'details' => $ar,
        ];
    }

    // 3. Consentimientos revocados/expirados (Art. 12)
    $consents = $db->find('compliance_consents', ['userId' => $user['_id']]);
    foreach ($consents as $c) {
        if (!empty($c['revokedAt']) || (!empty($c['endDate']) && strtotime($c['endDate']) < time())) {
            $alerts[] = [
                '_id' => (string)($c['_id'] ?? ''),
                'userId' => $user['_id'],
                'title' => 'Consentimiento ' . (!empty($c['revokedAt']) ? 'revocado' : 'expirado') . ': ' . ($c['name'] ?? 'Titular'),
                'message' => 'RUT: ' . ($c['rut'] ?? '') . ' · Finalidad: ' . ($c['purpose'] ?? '') . ' · ' . (!empty($c['revokedAt']) ? 'Revocado el ' . substr($c['revokedAt'], 0, 10) : 'Expirado el ' . substr($c['endDate'], 0, 10)),
                'source' => 'consent_change',
                'category' => 'consent_management',
                'severity' => 'medium',
                'eventType' => !empty($c['revokedAt']) ? 'consent_revoked' : 'consent_expired',
                'resolved' => false,
                'dismissed' => false,
                'read' => !empty($c['readAt']),
                'createdAt' => $c['revokedAt'] ?? $c['endDate'] ?? $c['createdAt'] ?? date('c'),
                'lawArticle' => 'Art. 12 Ley 21.719',
                'details' => $c,
            ];
        }
    }

    // Los eventos tecnicos (host, archivo, db) ya se materializan en la
    // coleccion `alerts` desde ws-server.php; no se re-leen sus colecciones
    // de origen para evitar duplicados y sobrecargar la respuesta.

    // Normalizar categoria y articulo para alertas provenientes del agente
    $categoryMap = [
        'host_event' => 'security_monitoring',
        'db_query'   => 'database_access',
        'agent'      => 'security_monitoring',
        'generic'    => 'security_monitoring',
    ];
    foreach ($alerts as &$alert) {
        if (!empty($alert['details'])) {
            $d = $alert['details'];
            $alert['resolved'] = !empty($d['resolved']) || in_array($d['status'] ?? '', ['resolved','completed','closed_resolved']);
            $alert['dismissed'] = !empty($d['dismissed']) || in_array($d['status'] ?? '', ['closed_no_action','rejected']);
            $alert['read'] = !empty($d['readAt']) || !empty($d['read']);
        }
        if (empty($alert['category']) || ($alert['category'] ?? '') === 'database') {
            $alert['category'] = $categoryMap[$alert['source'] ?? ''] ?? 'general';
        }
        if (empty($alert['lawArticle'])) {
            $alert['lawArticle'] = 'Art. 25 Ley 21.719';
        }
    }
    unset($alert);

    // Filtros comunes (pueden venir por GET o POST)
    $status = $body['status'] ?? $_GET['status'] ?? '';
    $severity = $body['severity'] ?? $_GET['severity'] ?? '';
    $category = $body['category'] ?? $_GET['category'] ?? '';
    $source = $body['source'] ?? $_GET['source'] ?? '';
    $article = $body['article'] ?? $_GET['article'] ?? '';
    $search = $body['search'] ?? $_GET['search'] ?? '';
    $dateFrom = $body['date_from'] ?? $_GET['date_from'] ?? '';
    $dateTo = $body['date_to'] ?? $_GET['date_to'] ?? '';
    $sortBy = $body['sort'] ?? $_GET['sort'] ?? 'createdAt';
    $sortDir = $body['dir'] ?? $_GET['dir'] ?? 'desc';

    if ($status === 'active') {
        $alerts = array_filter($alerts, fn($a) => empty($a['resolved']) && empty($a['dismissed']));
    } elseif ($status === 'resolved') {
        $alerts = array_filter($alerts, fn($a) => !empty($a['resolved']));
    } elseif ($status === 'dismissed') {
        $alerts = array_filter($alerts, fn($a) => !empty($a['dismissed']));
    }
    if ($severity) $alerts = array_filter($alerts, fn($a) => ($a['severity'] ?? '') === $severity);
    if ($category) $alerts = array_filter($alerts, fn($a) => ($a['category'] ?? '') === $category);
    if ($source) $alerts = array_filter($alerts, fn($a) => ($a['source'] ?? '') === $source);
    if ($article) $alerts = array_filter($alerts, fn($a) => ($a['lawArticle'] ?? '') === $article);
    if ($dateFrom) $alerts = array_filter($alerts, fn($a) => ($a['createdAt'] ?? '') >= $dateFrom);
    if ($dateTo) $alerts = array_filter($alerts, fn($a) => ($a['createdAt'] ?? '') <= $dateTo . 'T23:59:59');
    if ($search) {
        $sl = strtolower($search);
        $alerts = array_filter($alerts, function($a) use ($sl) {
            return str_contains(strtolower($a['title'] ?? ''), $sl) ||
                   str_contains(strtolower($a['message'] ?? ''), $sl) ||
                   str_contains(strtolower($a['agentId'] ?? ''), $sl) ||
                   str_contains(strtolower($a['lawArticle'] ?? ''), $sl) ||
                   str_contains(strtolower($a['eventType'] ?? ''), $sl);
        });
    }

    // Ordenar
    usort($alerts, function($a, $b) use ($sortBy, $sortDir) {
        $va = $a[$sortBy] ?? '';
        $vb = $b[$sortBy] ?? '';
        $cmp = strcmp($va, $vb);
        return $sortDir === 'desc' ? -$cmp : $cmp;
    });
    $alerts = array_values($alerts);
    $total = count($alerts);

    // Estadisticas sobre el conjunto filtrado (antes de paginar)
    $active = array_filter($alerts, fn($a) => empty($a['resolved']) && empty($a['dismissed']));
    $resolved = array_filter($alerts, fn($a) => !empty($a['resolved']));
    $dismissed = array_filter($alerts, fn($a) => !empty($a['dismissed']));
    $unread = array_filter($alerts, fn($a) => empty($a['read']));

    $trendData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayAlerts = array_filter($alerts, fn($a) => ($a['createdAt'] ?? '') >= $date . 'T00:00:00' && ($a['createdAt'] ?? '') <= $date . 'T23:59:59');
        $trendData[] = [
            'date' => date('d/m', strtotime("-$i days")),
            'count' => count($dayAlerts),
            'critical' => count(array_filter($dayAlerts, fn($a) => ($a['severity'] ?? '') === 'critical')),
        ];
    }
    $sevDistribution = [
        'critical' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical')),
        'high' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'high')),
        'medium' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'medium')),
        'low' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'low')),
    ];

    $stats = [
        'total' => $total,
        'active' => count($active),
        'resolved' => count($resolved),
        'dismissed' => count($dismissed),
        'unread' => count($unread),
        'critical' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical' && empty($a['resolved']) && empty($a['dismissed']))),
        'high' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'high' && empty($a['resolved']) && empty($a['dismissed']))),
        'trend' => $trendData,
        'severity' => $sevDistribution,
    ];

    // Paginacion
    $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 50);
    $offset = (int)($body['offset'] ?? $_GET['offset'] ?? 0);
    if ($limit <= 0) $limit = 50;
    if ($offset < 0) $offset = 0;
    $paged = array_slice($alerts, $offset, $limit);

    json_response(['alerts' => $paged, 'total' => $total, 'stats' => $stats, 'limit' => $limit, 'offset' => $offset]);
}

function stats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $all = $db->find('alerts', ['userId' => $user['_id']]);
    $critical = count(array_filter($all, fn($a) => ($a['severity'] ?? '') === 'critical'));
    $high = count(array_filter($all, fn($a) => ($a['severity'] ?? '') === 'high'));
    $unresolved = count(array_filter($all, fn($a) => empty($a['resolved'])));
    json_response([
        'total' => count($all),
        'critical' => $critical,
        'high' => $high,
        'unresolved' => $unresolved,
    ]);
}

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
