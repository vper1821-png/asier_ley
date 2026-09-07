<?php
// Compliant Companies routes

function search() {
    $search = $_GET['q'] ?? $_GET['search'] ?? ($_POST['q'] ?? ($_POST['search'] ?? ''));
    $search = trim($search);
    $db = Database::getInstance();

    $companies = $db->find('users', ['isActive' => true]);
    $results = [];

    foreach ($companies as $c) {
        if ($search && stripos($c['companyName'] ?? '', $search) === false && stripos($c['email'] ?? '', $search) === false) {
            continue;
        }
        $results[] = [
            '_id' => $c['_id'],
            'name' => $c['companyName'] ?? '',
            'companyName' => $c['companyName'] ?? '',
            'email' => $c['email'] ?? '',
            'domain' => $c['domain'] ?? '',
        ];
    }

    json_response($results);
}
