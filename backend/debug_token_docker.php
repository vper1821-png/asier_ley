<?php
require '/var/www/html/config.php';
require '/var/www/html/Database.php';
require '/var/www/html/Auth.php';

// Simulate GET request with token in query string
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/agents/download/win-x64?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiIzOGE0MjI3NjdmZTY0NDI1YmMyYjFhMGEiLCJpYXQiOjE3ODczMzk3MDYsImV4cCI6MTc4Nzk0NDUwNn0.5fWR-gz2J93nc_0pHkBPJTeZ60dUY0wJlm6jByQdSsI&deploy=test123';
$_GET['token'] = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiIzOGE0MjI3NjdmZTY0NDI1YmMyYjFhMGEiLCJpYXQiOjE3ODczMzk3MDYsImV4cCI6MTc4Nzk0NDUwNn0.5fWR-gz2J93nc_0pHkBPJTeZ60dUY0wJlm6jByQdSsI';
$_GET['deploy'] = 'test123';

echo "Testing get_token()...\n";
$token = get_token();
echo "Token: " . ($token ?: 'empty') . "\n";

if ($token) {
    echo "Testing verifyToken()...\n";
    $decoded = Auth::verifyToken($token);
    echo "Decoded: " . ($decoded ? 'valid' : 'invalid') . "\n";
    if ($decoded) {
        print_r($decoded);
        echo "Testing requireAuth()...\n";
        try {
            $user = Auth::requireAuth();
            echo "User found: " . $user['email'] . "\n";
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}