#!/usr/bin/env php
<?php
// backend/cron/check_agent_status.php
// Ejecutar cada minuto: * * * * * php /path/to/backend/cron/check_agent_status.php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Database.php';

$db = Database::getInstance();
$timeout = 120; // 2 minutos en segundos
$cutoff = time() - $timeout;

$agents = $db->find('agents', []);

$updated = 0;
foreach ($agents as $agent) {
    $lastSeen = strtotime($agent['lastSeen'] ?? '1970-01-01');
    if ($lastSeen < $cutoff) {
        $db->updateOne('agents', ['_id' => $agent['_id']], ['status' => 'offline']);
        $updated++;
    }
}

echo date('Y-m-d H:i:s') . " - Updated $updated agents to offline\n";