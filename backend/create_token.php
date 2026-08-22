<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Auth.php';

$db = Database::getInstance();
$admin = $db->findOne('users', ['email' => 'admin@invisia.local']);
if (!$admin) {
    echo "Admin user not found\n";
    exit(1);
}

$token = Auth::createToken($admin['_id']);
echo "Token: $token\n";