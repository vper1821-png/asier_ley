<?php
// Ticket routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (!empty($body['status'])) $filter['status'] = $body['status'];
    $tickets = $db->find('tickets', $filter);
    json_response($tickets);
}

function all() {
    Auth::requireAuth(); // Support users would be role-based; keep auth for now
    $db = Database::getInstance();
    $tickets = $db->find('tickets', []);
    json_response($tickets);
}

function create() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $subject = $body['subject'] ?? ($body['title'] ?? '');
    $description = $body['description'] ?? ($body['message'] ?? '');
    $priority = $body['priority'] ?? 'medium';

    if (!$subject || !$description) json_error('asunto y descripción requeridos');

    $ticket = $db->insertOne('tickets', [
        'userId' => $user['_id'],
        'subject' => $subject,
        'description' => $description,
        'priority' => $priority,
        'status' => 'open',
        'messages' => [[
            'role' => 'user',
            'content' => $description,
            'createdAt' => date('c'),
        ]],
    ]);
    json_response(['success' => true, 'ticket' => $ticket]);
}

function detail() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    if (!$id) json_error('id requerido');
    $db = Database::getInstance();
    $ticket = $db->findOne('tickets', ['_id' => $id]);
    if (!$ticket) json_error('ticket no encontrado', 404);
    if ($ticket['userId'] !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);
    json_response($ticket);
}

function reply() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    $body = get_body();
    $db = Database::getInstance();
    if (!$id) json_error('id requerido');

    $content = $body['content'] ?? ($body['message'] ?? '');
    if (!$content) json_error('mensaje requerido');

    $ticket = $db->findOne('tickets', ['_id' => $id]);
    if (!$ticket) json_error('ticket no encontrado', 404);
    if ($ticket['userId'] !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $messages = $ticket['messages'] ?? [];
    $messages[] = [
        'role' => $body['role'] ?? 'user',
        'content' => $content,
        'authorName' => $body['agentName'] ?? ($user['email'] ?? 'usuario'),
        'createdAt' => date('c'),
    ];
    $db->updateOne('tickets', ['_id' => $id], ['messages' => $messages, 'updatedAt' => date('c')]);
    json_response(['success' => true, 'ticket' => $db->findOne('tickets', ['_id' => $id])]);
}

function status() {
    $user = Auth::requireAuth();
    $body = get_body();
    $id = $_GET['id'] ?? ($body['id'] ?? ($body['ticketId'] ?? ''));
    $db = Database::getInstance();
    if (!$id) json_error('id requerido');

    $status = $body['status'] ?? '';
    if (!$status) json_error('status requerido');

    $ticket = $db->findOne('tickets', ['_id' => $id]);
    if (!$ticket) json_error('ticket no encontrado', 404);
    if ($ticket['userId'] !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $db->updateOne('tickets', ['_id' => $id], ['status' => $status, 'updatedAt' => date('c')]);
    json_response(['success' => true]);
}

function respond() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $id = $body['ticketId'] ?? '';
    $message = $body['message'] ?? '';
    $agentName = $body['agentName'] ?? 'Soporte';
    if (!$id || !$message) json_error('ticketId y mensaje requeridos');

    $ticket = $db->findOne('tickets', ['_id' => $id]);
    if (!$ticket) json_error('ticket no encontrado', 404);

    $messages = $ticket['messages'] ?? [];
    $messages[] = [
        'role' => 'support',
        'content' => $message,
        'authorName' => $agentName,
        'createdAt' => date('c'),
    ];
    $db->updateOne('tickets', ['_id' => $id], ['messages' => $messages, 'status' => 'in_progress', 'updatedAt' => date('c')]);
    json_response(['success' => true]);
}

function close() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $id = $body['ticketId'] ?? '';
    if (!$id) json_error('ticketId requerido');

    $ticket = $db->findOne('tickets', ['_id' => $id]);
    if (!$ticket) json_error('ticket no encontrado', 404);
    if ($ticket['userId'] !== $user['_id'] && !isAdmin($user)) json_error('acceso denegado', 403);

    $db->updateOne('tickets', ['_id' => $id], ['status' => 'closed', 'updatedAt' => date('c')]);
    json_response(['success' => true]);
}

function isAdmin($user) {
    return !empty($user['isAdmin']) || ($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'superadmin';
}
