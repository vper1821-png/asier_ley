<?php
// Notification routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (isset($body['unreadOnly'])) $filter['read'] = ['$in' => [false, null]];
    $notifications = $db->find('notifications', $filter);
    json_response($notifications);
}

function unreadCount() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $all = $db->find('notifications', ['userId' => $user['_id']]);
    $unread = count(array_filter($all, fn($n) => empty($n['read'])));
    json_response(['count' => $unread]);
}

function markRead() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    if (!$id) json_error('id requerido');
    $db = Database::getInstance();
    $note = $db->findOne('notifications', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$note) json_error('notificación no encontrada', 404);
    $db->updateOne('notifications', ['_id' => $id], ['read' => true, 'readAt' => date('c')]);
    json_response(['success' => true]);
}

function markAllRead() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $all = $db->find('notifications', ['userId' => $user['_id']]);
    foreach ($all as $note) {
        $db->updateOne('notifications', ['_id' => $note['_id']], ['read' => true, 'readAt' => date('c')]);
    }
    json_response(['success' => true]);
}

function create() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $type = $body['type'] ?? 'info';
    $title = $body['title'] ?? 'Notificación';
    $message = $body['message'] ?? '';
    if (!$message) json_error('mensaje requerido');
    $note = $db->insertOne('notifications', [
        'userId' => $user['_id'],
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'read' => false,
    ]);
    json_response(['success' => true, 'notification' => $note]);
}

function deleteOne() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    if (!$id) json_error('id requerido');
    $db = Database::getInstance();
    $note = $db->findOne('notifications', ['_id' => $id, 'userId' => $user['_id']]);
    if (!$note) json_error('notificación no encontrada', 404);
    $db->deleteOne('notifications', ['_id' => $id]);
    json_response(['success' => true]);
}

function clearAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $all = $db->find('notifications', ['userId' => $user['_id']]);
    foreach ($all as $note) {
        $db->deleteOne('notifications', ['_id' => $note['_id']]);
    }
    json_response(['success' => true]);
}
