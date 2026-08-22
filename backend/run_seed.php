<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Auth.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\seed.php';

seedAdminUser();

$db = Database::getInstance();
$users = $db->find('users', []);
echo 'Users count after seed: ' . count($users) . PHP_EOL;
foreach ($users as $u) {
    echo $u['email'] . ' - ' . $u['_id'] . PHP_EOL;
}