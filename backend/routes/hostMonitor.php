<?php
// Host Monitor routes

function listAll() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (!empty($body['hostId'])) $filter['agentId'] = $body['hostId'];
    if (!empty($body['status'])) $filter['status'] = $body['status'];
    $hosts = $db->find('host_monitor', $filter);
    json_response($hosts);
}

function stats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $hosts = $db->find('host_monitor', ['userId' => $user['_id']]);
    $online = count(array_filter($hosts, fn($h) => ($h['status'] ?? '') === 'online'));
    $offline = count(array_filter($hosts, fn($h) => ($h['status'] ?? '') !== 'online'));
    $alerts = $db->find('alerts', ['userId' => $user['_id']]);
    $critical = count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical' && empty($a['resolved'])));

    $avgCpu = 0;
    $avgRam = 0;
    if (!empty($hosts)) {
        $avgCpu = round(array_sum(array_column($hosts, 'cpu')) / count($hosts), 2);
        $avgRam = round(array_sum(array_column($hosts, 'ram')) / count($hosts), 2);
    }

    json_response([
        'online' => $online,
        'offline' => $offline,
        'criticalAlerts' => $critical,
        'avgCpu' => $avgCpu,
        'avgRam' => $avgRam,
        'totalHosts' => count($hosts),
    ]);
}

function events() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $filter = ['userId' => $user['_id']];
    if (!empty($body['hostId'])) $filter['agentId'] = $body['hostId'];
    $alerts = $db->find('alerts', $filter, ['sort' => ['createdAt' => -1], 'limit' => 500]);
    $result = [];
    foreach ($alerts as $a) {
        $result[] = [
            '_id' => $a['_id'] ?? uniqid(),
            'eventType' => $a['eventType'] ?? $a['type'] ?? 'unknown',
            'severity' => $a['severity'] ?? 'medium',
            'title' => $a['title'] ?? 'Evento',
            'detail' => $a['message'] ?? '',
            'source' => $a['source'] ?? 'agent',
            'timestamp' => $a['createdAt'] ?? date('c'),
            'agentId' => $a['agentId'] ?? '',
        ];
    }
    json_response($result);
}

function eventsStats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $alerts = $db->find('alerts', ['userId' => $user['_id']]);
    $byType = ['web_access' => 0, 'process_connection' => 0, 'config_change' => 0, 'windows_event' => 0];
    foreach ($alerts as $a) {
        $et = $a['eventType'] ?? $a['type'] ?? 'unknown';
        if (!isset($byType[$et])) $byType[$et] = 0;
        $byType[$et]++;
    }
    json_response([
        'total' => count($alerts),
        'byType' => $byType,
    ]);
}
