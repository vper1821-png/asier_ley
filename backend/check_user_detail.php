<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
$db = Database::getInstance();
$user = $db->findOne('users', ['email' => 'admin@invisia.local']);
echo 'User found: ' . ($user ? 'yes' : 'no') . PHP_EOL;
if ($user) {
    echo '_id: ' . $user['_id'] . PHP_EOL;
    echo '_id type: ' . gettype($user['_id']) . PHP_EOL;
    echo 'isActive: ' . ($user['isActive'] ?? 'not set') . PHP_EOL;
    echo 'password set: ' . (isset($user['password']) ? 'yes' : 'no') . PHP_EOL;
}