<?php
// Alert routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (!empty($body['status'])) $filter['status'] = $body['status'];
    if (!empty($body['severity'])) $filter['severity'] = $body['severity'];
    if (isset($body['resolved'])) $filter['resolved'] = filter_var($body['resolved'], FILTER_VALIDATE_BOOLEAN);
    if (!empty($body['source'])) $filter['source'] = $body['source'];
    if (!empty($body['category'])) $filter['category'] = $body['category'];
    $alerts = $db->find('alerts', $filter);

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

    // 4. Eventos de host/sistema (Art. 25 - Medidas de seguridad)
    $hostEvents = $db->find('host_events', ['userId' => $user['_id']]);
    foreach ($hostEvents as $he) {
        $sev = $he['severity'] ?? 'info';
        $sevMap = ['critical'=>'critical','high'=>'high','medium'=>'medium','low'=>'low','info'=>'low'];
        $alerts[] = [
            '_id' => (string)($he['_id'] ?? ''),
            'userId' => $user['_id'],
            'agentId' => $he['agentId'] ?? '',
            'title' => 'Evento de sistema: ' . ($he['title'] ?? 'Evento'),
            'message' => ($he['detail'] ?? '') . ' · Agente: ' . ($he['agentId'] ?? '') . ' · Tipo: ' . ($he['type'] ?? 'host_event'),
            'source' => 'host_event',
            'category' => 'security_monitoring',
            'severity' => $sevMap[$sev] ?? 'low',
            'eventType' => 'host_' . ($he['type'] ?? 'unknown'),
            'resolved' => false,
            'dismissed' => false,
            'read' => !empty($he['readAt']),
            'createdAt' => $he['createdAt'] ?? $he['timestamp'] ?? date('c'),
            'lawArticle' => 'Art. 25 Ley 21.719',
            'details' => $he,
        ];
    }

    // 5. Eventos de archivos (Art. 25 - Integridad)
    $fileEvents = $db->find('file_events', ['userId' => $user['_id']]);
    foreach ($fileEvents as $e) {
        $alerts[] = [
            '_id' => (string)($e['_id'] ?? ''),
            'userId' => $user['_id'],
            'agentId' => $e['agentId'] ?? '',
            'title' => 'Archivo modificado: ' . ($e['path'] ?? 'desconocido'),
            'message' => 'Ruta: ' . ($e['path'] ?? '') . ' · Evento: ' . ($e['eventType'] ?? 'unknown') . ($e['process'] ? ' · Proceso: ' . $e['process'] : ''),
            'source' => 'file_event',
            'category' => 'file_integrity',
            'severity' => in_array($e['eventType'] ?? '', ['delete','encrypt','exfiltrate']) ? 'high' : 'medium',
            'eventType' => 'file_' . ($e['eventType'] ?? 'unknown'),
            'resolved' => false,
            'dismissed' => false,
            'read' => !empty($e['readAt']),
            'createdAt' => $e['createdAt'] ?? $e['timestamp'] ?? date('c'),
            'lawArticle' => 'Art. 25 Ley 21.719',
            'details' => $e,
        ];
    }

    // 6. Logs de base de datos (Art. 25 - Acceso a datos)
    $dbLogs = $db->find('database_logs', ['userId' => $user['_id']]);
    foreach ($dbLogs as $l) {
        $risk = (float)($l['riskScore'] ?? 0);
        $alerts[] = [
            '_id' => (string)($l['_id'] ?? ''),
            'userId' => $user['_id'],
            'agentId' => $l['agentId'] ?? '',
            'title' => 'Log de BBDD: ' . ($l['database'] ?? 'desconocida'),
            'message' => 'Consulta: ' . substr(($l['query'] ?? ''), 0, 120) . ' · Motor: ' . ($l['engine'] ?? '') . ' · Usuario: ' . ($l['user'] ?? ''),
            'source' => 'database_log',
            'category' => 'database_access',
            'severity' => $risk > 7 ? 'critical' : ($risk > 4 ? 'high' : ($risk > 2 ? 'medium' : 'low')),
            'eventType' => 'db_' . ($l['operation'] ?? 'query'),
            'resolved' => false,
            'dismissed' => false,
            'read' => !empty($l['readAt']),
            'createdAt' => $l['createdAt'] ?? $l['timestamp'] ?? date('c'),
            'lawArticle' => 'Art. 25 Ley 21.719',
            'riskScore' => $risk,
            'details' => $l,
        ];
    }

    // 7. Auditoría de archivos (Art. 25)
    $fileAudits = $db->find('file_audit_logs', ['userId' => $user['_id']]);
    foreach ($fileAudits as $fa) {
        $alerts[] = [
            '_id' => (string)($fa['_id'] ?? ''),
            'userId' => $user['_id'],
            'title' => 'Auditoría archivo: ' . ($fa['fileName'] ?? 'desconocido'),
            'message' => 'Acción: ' . ($fa['action'] ?? '') . ' · Usuario: ' . ($fa['user'] ?? '') . ' · Hash: ' . substr($fa['hash'] ?? '', 0, 16),
            'source' => 'file_audit',
            'category' => 'file_integrity',
            'severity' => in_array($fa['action'] ?? '', ['delete','encrypt','permission_change']) ? 'high' : 'medium',
            'eventType' => 'audit_' . ($fa['action'] ?? 'unknown'),
            'resolved' => false,
            'dismissed' => false,
            'read' => !empty($fa['readAt']),
            'createdAt' => $fa['createdAt'] ?? date('c'),
            'lawArticle' => 'Art. 25 Ley 21.719',
            'details' => $fa,
        ];
    }

    // 8. Auditoría general
    $auditLogs = $db->find('audit_logs', ['userId' => $user['_id']]);
    foreach ($auditLogs as $al) {
        $alerts[] = [
            '_id' => (string)($al['_id'] ?? ''),
            'userId' => $user['_id'],
            'title' => 'Auditoría: ' . ($al['action'] ?? 'acción'),
            'message' => 'Recurso: ' . ($al['resource'] ?? '') . ' · Usuario: ' . ($al['user'] ?? '') . ' · IP: ' . ($al['ip'] ?? ''),
            'source' => 'audit_log',
            'category' => 'audit_trail',
            'severity' => in_array($al['action'] ?? '', ['delete','export','permission_change','login_failed']) ? 'high' : 'medium',
            'eventType' => 'audit_' . ($al['action'] ?? 'unknown'),
            'resolved' => false,
            'dismissed' => false,
            'read' => !empty($al['readAt']),
            'createdAt' => $al['createdAt'] ?? date('c'),
            'lawArticle' => 'Art. 25 Ley 21.719',
            'details' => $al,
        ];
    }

    // Sincronizar estado leído/resuelto/descartado desde el documento origen
    foreach ($alerts as &$alert) {
        if (empty($alert['details'])) continue;
        $d = $alert['details'];
        $alert['resolved'] = !empty($d['resolved']) || in_array($d['status'] ?? '', ['resolved','completed','closed_resolved']);
        $alert['dismissed'] = !empty($d['dismissed']) || in_array($d['status'] ?? '', ['closed_no_action','rejected']);
        $alert['read'] = !empty($d['readAt']) || !empty($d['read']);
    }

    // Ordenar por fecha descendente
    usort($alerts, function($a, $b) {
        return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '');
    });
    json_response($alerts);
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
