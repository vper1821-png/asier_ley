<?php
require '/var/www/html/config.php';
require '/var/www/html/Database.php';

$db = Database::getInstance();
$users = $db->find('users', []);
echo 'Users count: ' . count($users) . PHP_EOL;
foreach ($users as $u) {
    echo '  ' . $u['email'] . ' - ' . $u['_id'] . PHP_EOL;
}