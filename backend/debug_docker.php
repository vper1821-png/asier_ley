<?php
require '/var/www/html/config.php';
require '/var/www/html/Database.php';
require '/var/www/html/Auth.php';

$_GET['token'] = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VySWQiOiJlNGE5NzU5YTYyYjM3MzkzN2VkNTAzMTgiLCJpYXQiOjE3ODczMzk1MDYsImV4cCI6MTc4Nzk0NDMwNn0.JM5b7pvM0HVFQVqymE3RF-_RpRiaWyubTJgWnkuOwgI';

$token = get_token();
echo 'get_token: ' . ($token ?: 'empty') . PHP_EOL;

if ($token) {
    $decoded = Auth::verifyToken($token);
    echo 'verifyToken: ' . ($decoded ? 'valid' : 'invalid') . PHP_EOL;
    if ($decoded) {
        $db = Database::getInstance();
        $user = $db->findOne('users', ['_id' => $decoded['userId']]);
        echo 'User query: ' . ($user ? 'found' : 'NOT FOUND') . PHP_EOL;
        if ($user) {
            echo 'User email: ' . $user['email'] . PHP_EOL;
            echo 'User isActive: ' . ($user['isActive'] ?? 'not set') . PHP_EOL;
        }
    }
}