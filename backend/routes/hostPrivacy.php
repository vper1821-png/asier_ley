<?php
// Host Privacy Control Panel - Ley 21.719 Chile

function summary() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? '';
    if (!$agentId) json_error('agentId requerido');

    $db = Database::getInstance();
    $host = $db->findOne('host_monitor', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$host) json_error('host no encontrado', 404);

    // Datos relacionados con el host
    $arcoRequests = $db->find('arco_requests', [
        'companyId' => $user['_id'],
        'agentId' => $agentId,
    ], ['limit' => 10, 'sort' => ['createdAt' => -1]]);

    $sensitiveEvents = $db->find('file_events', [
        'agentId' => $agentId,
        'userId' => $user['_id'],
        'sensitive' => true,
    ], ['limit' => 20, 'sort' => ['timestamp' => -1]]);

    $complianceConfig = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];

    json_response([
        'host' => $host,
        'arcoRequests' => $arcoRequests,
        'sensitiveEventsCount' => count($sensitiveEvents),
        'sensitiveEvents' => array_slice($sensitiveEvents, 0, 5),
        'complianceConfig' => $complianceConfig,
    ]);
}

function arcoCreate() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? '';
    $tipo = $body['tipo'] ?? 'acceso';
    $descripcion = $body['descripcion'] ?? '';

    if (!$agentId) json_error('agentId requerido');

    $db = Database::getInstance();
    $host = $db->findOne('host_monitor', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$host) json_error('host no encontrado', 404);

    $validTypes = ['acceso', 'rectificacion', 'cancelacion', 'oposicion', 'portabilidad', 'supresion', 'bloqueo', 'oposicion_automatizada'];
    if (!in_array($tipo, $validTypes)) json_error('tipo de solicitud inválido');

    $requestId = 'ARCO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    $solicitante = [
        'nombre' => $user['companyName'] ?? $user['name'] ?? $user['email'] ?? 'Titular',
        'email' => $user['email'] ?? '',
        'rut' => $user['rut'] ?? '',
    ];

    $request = $db->insertOne('arco_requests', [
        'requestId' => $requestId,
        'solicitante' => $solicitante,
        'tipo' => $tipo,
        'descripcion' => $descripcion,
        'companyId' => $user['_id'],
        'agentId' => $agentId,
        'hostname' => $host['hostname'] ?? $agentId,
        'status' => 'pending',
        'source' => 'host-privacy-panel',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ]);

    json_response([
        'success' => true,
        'requestId' => $requestId,
        'request' => $request,
    ]);
}

function breachReport() {
    $user = Auth::requireAuth();
    $body = get_body();
    $agentId = $body['agentId'] ?? '';
    $descripcion = $body['descripcion'] ?? '';
    $afectados = $body['afectados'] ?? 0;

    if (!$agentId) json_error('agentId requerido');

    $db = Database::getInstance();
    $host = $db->findOne('host_monitor', ['agentId' => $agentId, 'userId' => $user['_id']]);
    if (!$host) json_error('host no encontrado', 404);

    $breachId = 'BREACH-' . strtoupper(uniqid());

    $db->insertOne('compliance_breaches', [
        'breachId' => $breachId,
        'userId' => $user['_id'],
        'agentId' => $agentId,
        'hostname' => $host['hostname'] ?? $agentId,
        'descripcion' => $descripcion,
        'afectados' => (int)$afectados,
        'status' => 'detected',
        'reportedAt' => date('c'),
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'breachId' => $breachId]);
}
