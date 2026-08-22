<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
$db = Database::getInstance();
$users = $db->find('users', []);
foreach ($users as $u) {
    echo $u['email'] . ' - ' . $u['_id'] . PHP_EOL;
}