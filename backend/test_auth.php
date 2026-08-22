<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Auth.php';

$_GET['token'] = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiJlNGE5NzU5YTYyYjM3MzkzN2VkNTAzMTgiLCJpYXQiOjE3ODczMzk1MDYsImV4cCI6MTc4Nzk0NDMwNn0.JM5b7pvM0HVFQVqymE3RF-_RpRiaWyubTJgWnkuOwgI";

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