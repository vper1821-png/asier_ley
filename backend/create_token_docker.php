<?php
require '/var/www/html/config.php';
require '/var/www/html/Database.php';
require '/var/www/html/Auth.php';

$db = Database::getInstance();
$admin = $db->findOne('users', ['email' => 'admin@invisia.local']);
if (!$admin) {
    echo "Admin user not found\n";
    exit(1);
}

$token = Auth::createToken($admin['_id']);
echo "Token: $token\n";
echo "User ID: " . $admin['_id'] . "\n";