<?php
require '/var/www/html/config.php';
require '/var/www/html/Database.php';
$db = Database::getInstance();
$u = $db->findOne('users', ['email' => 'test@test.com']);
var_export($u);