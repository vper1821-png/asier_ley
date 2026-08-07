<?php
// Onboarding routes

function save() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $existing = $db->findOne('onboarding', ['userId' => $user['_id']]);

    $data = [
        'userId' => $user['_id'],
        'companyName' => $body['companyName'] ?? ($existing['companyName'] ?? $user['companyName'] ?? ''),
        'domain' => $body['domain'] ?? ($existing['domain'] ?? ''),
        'planType' => $body['planType'] ?? ($existing['planType'] ?? 'free'),
        'completed' => true,
    ];

    if ($existing) {
        $db->updateOne('onboarding', ['userId' => $user['_id']], $data);
    } else {
        $db->insertOne('onboarding', $data);
    }

    // Update user
    $db->updateOne('users', ['_id' => $user['_id']], [
        'onboardingComplete' => true,
        'companyName' => $data['companyName'],
        'domain' => $data['domain'],
    ]);

    json_response(['success' => true]);
}

function get() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $data = $db->findOne('onboarding', ['userId' => $user['_id']]);
    json_response($data ?: ['completed' => false]);
}
