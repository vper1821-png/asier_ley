<?php
require 'C:\Users\asier\Music\LA LEY V8\backend\config.php';
require 'C:\Users\asier\Music\LA LEY V8\backend\Database.php';
$db = Database::getInstance();

// Test with string ID
$user1 = $db->findOne('users', ['_id' => 'e4a9759a62b373937ed50318']);
echo "Query with string ID: " . ($user1 ? 'found' : 'NOT FOUND') . PHP_EOL;

// Test with ObjectId
$oid = new MongoDB\BSON\ObjectId('e4a9759a62b373937ed50318');
$user2 = $db->findOne('users', ['_id' => $oid]);
echo "Query with ObjectId: " . ($user2 ? 'found' : 'NOT FOUND') . PHP_EOL;

// Check what's actually in the collection
$users = $db->find('users', []);
foreach ($users as $u) {
    echo "User in DB: _id=" . $u['_id'] . " type=" . gettype($u['_id']) . PHP_EOL;
}