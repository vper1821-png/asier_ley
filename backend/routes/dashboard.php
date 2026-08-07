<?php
// Dashboard routes

function status() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $alerts = $db->find('alerts', ['userId' => $user['_id']], ['limit' => 10]);
    $agents = $db->find('agents', ['userId' => $user['_id']]);
    $databases = $db->find('databases', ['userId' => $user['_id']]);

    json_response([
        'maintenanceMode' => false,
        'maintenanceMessage' => '',
        'alerts' => $alerts,
        'agentCount' => count($agents),
        'databaseCount' => count($databases),
    ]);
}

function stats() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $uid = $user['_id'];

    $agents = $db->find('agents', ['userId' => $uid]);
    $databases = $db->find('databases', ['userId' => $uid]);
    $alerts = $db->find('alerts', ['userId' => $uid]);
    $breaches = $db->find('compliance_breaches', ['userId' => $uid]);
    $scans = $db->find('scans', ['userId' => $uid]);
    $reports = $db->find('reports', ['userId' => $uid]);
    $userMonitor = $db->find('user_monitor', ['userId' => $uid]);

    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));
    $activeAlerts = count(array_filter($alerts, fn($a) => empty($a['resolved']) && empty($a['dismissed'])));
    $openBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? 'open') !== 'resolved'));
    $completedScans = count(array_filter($scans, fn($s) => ($s['status'] ?? '') === 'completed'));
    $monthStart = date('Y-m-01');
    $generatedReports = count(array_filter($reports, fn($r) => ($r['createdAt'] ?? '') >= $monthStart));

    $totalTables = 0;
    $totalRecords = 0;
    $compliantDBs = 0;
    $dbCompliance = [];
    foreach ($databases as $d) {
        $tables = (int)($d['tableCount'] ?? $d['tables'] ?? 0);
        $records = (int)($d['recordCount'] ?? $d['records'] ?? 0);
        $totalTables += $tables;
        $totalRecords += $records;
        $compliant = !empty($d['compliant']) || $openBreaches === 0;
        if ($compliant) $compliantDBs++;
        $dbCompliance[] = [
            'id' => $d['_id'],
            'name' => $d['name'] ?? $d['database'] ?? 'db',
            'engine' => $d['engine'] ?? $d['type'] ?? '',
            'tables' => $tables,
            'records' => $records,
            'compliant' => $compliant,
            'breaches' => 0,
        ];
    }
    $nonCompliantDBs = count($databases) - $compliantDBs;
    $complianceScore = count($databases) > 0 ? (int)round($compliantDBs / count($databases) * 100) : 0;

    $vulnerableUsersCount = count(array_filter($userMonitor, fn($u) => !empty($u['vulnerable']) || ($u['riskLevel'] ?? '') === 'high'));

    json_response([
        'stats' => [
            'onlineAgents' => $onlineAgents,
            'totalAgents' => count($agents),
            'totalDatabases' => count($databases),
            'totalTables' => $totalTables,
            'totalRecords' => $totalRecords,
            'complianceScore' => $complianceScore,
            'compliantDBs' => $compliantDBs,
            'nonCompliantDBs' => $nonCompliantDBs,
            'openBreaches' => $openBreaches,
            'totalBreaches' => count($breaches),
            'vulnerableUsersCount' => $vulnerableUsersCount,
            'activeAlerts' => $activeAlerts,
            'completedScans' => $completedScans,
            'totalScans' => count($scans),
            'generatedReports' => $generatedReports,
        ],
        'dbCompliance' => $dbCompliance,
    ]);
}
