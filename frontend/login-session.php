<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
$token = $body['token'] ?? '';
$user = $body['user'] ?? [];

if ($token && $user) {
    $_SESSION['token'] = $token;
    $_SESSION['user'] = $user;
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}