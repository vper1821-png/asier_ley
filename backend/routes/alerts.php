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
    $alerts = $db->find('alerts', $filter);
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

function resolve() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $alertId = $body['alertId'] ?? '';
    if (!$alertId) json_error('alertId requerido');
    $alert = $db->findOne('alerts', ['_id' => $alertId, 'userId' => $user['_id']]);
    if (!$alert) json_error('alerta no encontrada', 404);
    $db->updateOne('alerts', ['_id' => $alertId], [
        'resolved' => true,
        'resolvedAt' => date('c'),
        'resolution' => $body['resolution'] ?? ($body['notes'] ?? ''),
    ]);
    json_response(['success' => true]);
}

function dismiss() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $alertId = $body['alertId'] ?? '';
    if (!$alertId) json_error('alertId requerido');
    $alert = $db->findOne('alerts', ['_id' => $alertId, 'userId' => $user['_id']]);
    if (!$alert) json_error('alerta no encontrada', 404);
    $db->updateOne('alerts', ['_id' => $alertId], ['dismissed' => true, 'dismissedAt' => date('c')]);
    json_response(['success' => true]);
}

function resolveBulk() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $ids = json_decode($body['alertIds'] ?? '[]', true);
    if (empty($ids) || !is_array($ids)) json_error('alertIds requerido');
    foreach ($ids as $id) {
        $alert = $db->findOne('alerts', ['_id' => $id, 'userId' => $user['_id']]);
        if ($alert) {
            $db->updateOne('alerts', ['_id' => $id], ['resolved' => true, 'resolvedAt' => date('c')]);
        }
    }
    json_response(['success' => true, 'resolved' => count($ids)]);
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
