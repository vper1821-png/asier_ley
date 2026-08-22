<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Auth.php';

// Simulate GET request with token
$_GET['token'] = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiJlNGE5NzU5YTYyYjM3MzkzN2VkNTAzMTgiLCJpYXQiOjE3ODczMzk1MDYsImV4cCI6MTc4Nzk0NDMwNn0.JM5b7pvM0HVFQVqymE3RF-_RpRiaWyubTJgWnkuOwgI";

$token = get_token();
echo "get_token() returned: " . ($token ?: 'empty') . PHP_EOL;

if ($token) {
    $decoded = Auth::verifyToken($token);
    echo "verifyToken() returned: " . ($decoded ? 'valid' : 'invalid') . PHP_EOL;
    if ($decoded) {
        print_r($decoded);
    }
}