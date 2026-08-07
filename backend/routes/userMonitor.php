<?php
// User Monitor routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $targetUserId = $body['userId'] ?? $user['_id'];
    if ($targetUserId !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);
    $monitors = $db->find('user_monitor', ['userId' => $targetUserId]);
    json_response($monitors);
}

function detail() {
    $user = Auth::requireAuth();
    $targetUserId = $_GET['userId'] ?? '';
    if (!$targetUserId) json_error('userId requerido');
    if ($targetUserId !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $db = Database::getInstance();
    $target = $db->findOne('users', ['_id' => $targetUserId]);
    if (!$target) json_error('usuario no encontrado', 404);
    unset($target['password']);

    $alerts = $db->find('alerts', ['userId' => $targetUserId]);
    $hosts = $db->find('host_monitor', ['userId' => $targetUserId]);
    $payments = $db->find('payments', ['userId' => $targetUserId]);

    json_response([
        'user' => $target,
        'alerts' => $alerts,
        'hosts' => $hosts,
        'payments' => $payments,
    ]);
}

function isAdmin($user) {
    return !empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin';
}
