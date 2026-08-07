<?php
// Payment routes

function myInfo() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $payments = $db->find('payments', ['userId' => $user['_id']]);
    json_response([
        'pendingPayments' => array_values(array_filter($payments, fn($p) => ($p['status'] ?? '') === 'pending')),
        'paymentHistory' => $payments,
        'paymentStatus' => $user['paymentStatus'] ?? 'active',
    ]);
}

function users() {
    $user = Auth::requireAuth();
    if (!isAdmin($user)) json_error('acceso denegado', 403);
    $db = Database::getInstance();
    $users = $db->find('users', []);
    foreach ($users as &$u) unset($u['password']);
    json_response($users);
}

function pending() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $filter = isAdmin($user) ? ['status' => 'pending'] : ['userId' => $user['_id'], 'status' => 'pending'];
    $payments = $db->find('payments', $filter);
    json_response($payments);
}

function record() {
    $user = Auth::requireAuth();
    if (!isAdmin($user)) json_error('acceso denegado', 403);
    $body = get_body();
    $db = Database::getInstance();
    $targetUserId = $body['userId'] ?? '';
    if (!$targetUserId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $targetUserId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $payment = $db->insertOne('payments', [
        'userId' => $targetUserId,
        'month' => $body['month'] ?? date('m'),
        'year' => $body['year'] ?? date('Y'),
        'amount' => $body['amount'] ?? 0,
        'concept' => $body['concept'] ?? 'Pago',
        'status' => $body['status'] ?? 'pending',
        'recordedBy' => $user['_id'],
        'recordedAt' => date('c'),
    ]);
    json_response(['success' => true, 'payment' => $payment]);
}

function submit() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    if (empty($body['month']) || empty($body['year']) || empty($body['amount']) || empty($body['concept'])) {
        json_error('mes, año, monto y concepto requeridos');
    }

    $payment = $db->insertOne('payments', [
        'userId' => $user['_id'],
        'month' => $body['month'],
        'year' => $body['year'],
        'amount' => $body['amount'],
        'concept' => $body['concept'],
        'status' => 'pending',
        'submittedAt' => date('c'),
    ]);
    json_response(['success' => true, 'payment' => $payment]);
}

function verify() {
    $user = Auth::requireAuth();
    if (!isAdmin($user)) json_error('acceso denegado', 403);
    $body = get_body();
    $db = Database::getInstance();
    $paymentId = $body['paymentId'] ?? '';
    $status = $body['status'] ?? '';
    if (!$paymentId || !$status) json_error('paymentId y status requeridos');

    $payment = $db->findOne('payments', ['_id' => $paymentId]);
    if (!$payment) json_error('pago no encontrado', 404);

    $db->updateOne('payments', ['_id' => $paymentId], [
        'status' => $status,
        'verifiedBy' => $user['_id'],
        'notes' => $body['notes'] ?? '',
        'verifiedAt' => date('c'),
    ]);
    json_response(['success' => true]);
}

function history() {
    $user = Auth::requireAuth();
    $targetUserId = $_GET['userId'] ?? '';
    if (!$targetUserId) json_error('userId requerido');
    if ($targetUserId !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $db = Database::getInstance();
    $payments = $db->find('payments', ['userId' => $targetUserId]);
    json_response($payments);
}

function userUpdate() {
    $user = Auth::requireAuth();
    if (!isAdmin($user)) json_error('acceso denegado', 403);
    $body = get_body();
    $db = Database::getInstance();
    $targetUserId = $body['userId'] ?? '';
    if (!$targetUserId) json_error('userId requerido');

    $target = $db->findOne('users', ['_id' => $targetUserId]);
    if (!$target) json_error('usuario no encontrado', 404);

    $updates = [];
    if (isset($body['paymentStatus'])) $updates['paymentStatus'] = $body['paymentStatus'];
    if (isset($body['customPrice'])) $updates['customPrice'] = $body['customPrice'];
    if (isset($body['bankName'])) $updates['bankName'] = $body['bankName'];
    if (isset($body['accountType'])) $updates['accountType'] = $body['accountType'];
    if (isset($body['accountNumber'])) $updates['accountNumber'] = $body['accountNumber'];
    if (isset($body['rut'])) $updates['rut'] = $body['rut'];
    if (isset($body['email'])) $updates['paymentEmail'] = $body['email'];

    if (!empty($updates)) $db->updateOne('users', ['_id' => $targetUserId], $updates);
    json_response(['success' => true]);
}

function isAdmin($user) {
    return !empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin';
}
