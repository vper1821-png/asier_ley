<?php
// ARCO routes

function create() {
    $body = get_body();
    $solicitante = $body['solicitante'] ?? [];
    $tipo = $body['tipo'] ?? 'acceso';
    $descripcion = $body['descripcion'] ?? '';
    $companyId = $body['companyId'] ?? null;

    if (empty($solicitante['nombre']) || empty($solicitante['rut']) || empty($solicitante['email'])) {
        json_error('datos del solicitante requeridos');
    }

    $db = Database::getInstance();
    $requestId = 'ARCO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    $request = $db->insertOne('arco_requests', [
        'requestId' => $requestId,
        'solicitante' => $solicitante,
        'tipo' => $tipo,
        'descripcion' => $descripcion,
        'companyId' => $companyId,
        'status' => 'pending',
    ]);

    json_response([
        'success' => true,
        'requestId' => $requestId,
        'request' => $request,
    ]);
}

function track() {
    $body = get_body();
    $trackingId = $body['trackingId'] ?? '';

    if (!$trackingId) json_error('ID de seguimiento requerido');

    $db = Database::getInstance();
    $request = $db->findOne('arco_requests', ['requestId' => $trackingId]);

    if (!$request) json_error('solicitud no encontrada');

    json_response($request);
}

function listRequests() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    if (!empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin') {
        $items = $db->find('arco_requests', []);
    } else {
        $items = $db->find('arco_requests', ['companyId' => $user['_id']]);
    }

    json_response($items);
}

function updateRequest() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $requestId = $body['requestId'] ?? '';
    $estado = $body['estado'] ?? $body['status'] ?? '';
    $respuesta = $body['respuesta'] ?? $body['response'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    if (empty($user['isAdmin']) && ($user['role'] ?? '') !== 'admin' && ($user['role'] ?? '') !== 'superadmin' && $req['companyId'] !== $user['_id']) {
        json_error('acceso denegado', 403);
    }

    $updates = [
        'updatedAt' => date('c'),
    ];
    if ($estado) $updates['status'] = $estado;
    if ($respuesta) $updates['response'] = $respuesta;

    $db->updateOne('arco_requests', ['requestId' => $requestId], $updates);
    json_response(['success' => true]);
}

function generateResponse() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $requestId = $body['requestId'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    if (empty($user['isAdmin']) && ($user['role'] ?? '') !== 'admin' && ($user['role'] ?? '') !== 'superadmin' && $req['companyId'] !== $user['_id']) {
        json_error('acceso denegado', 403);
    }

    $text = 'Respuesta generada automáticamente conforme a la Ley 21.719 y a los derechos ARCO del solicitante.';
    $db->updateOne('arco_requests', ['requestId' => $requestId], [
        'response' => $text,
        'status' => 'resolved',
        'updatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'response' => $text]);
}
