<?php
require_once __DIR__ . '/config.php';

$email = 'asiersinmas2004@gmail.com';
$password = 'Racilo14@@';

echo "=== TEST DE ENDPOINTS DE LA API ===\n\n";

// 1. LOGIN
echo "1. Probando LOGIN...\n";
$loginData = [
    'email' => $email,
    'password' => $password,
    'captchaToken' => 'development-bypass'
];

$ch = curl_init(API_BASE_URL . '/api/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($loginData),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$loginResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$loginResult = json_decode($loginResponse, true);
if ($httpCode === 200 && !empty($loginResult['token'])) {
    echo "   ✓ Login exitoso\n";
    $token = $loginResult['token'];
    $user = $loginResult['user'];
    echo "   User ID: {$user['_id']}\n";
    echo "   Email: {$user['email']}\n";
    echo "   Role: " . ($user['role'] ?? 'N/A') . "\n";
} else {
    echo "   ✗ Login fallido (HTTP $httpCode)\n";
    echo "   Response: $loginResponse\n";
    exit(1);
}

echo "\n";

// Function to test endpoint
function testEndpoint($name, $method, $path, $body = null, $token) {
    echo "2. Probando $name...\n";
    $ch = curl_init(API_BASE_URL . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "   ✓ $name (HTTP $httpCode)\n";
        if (is_array($result) && isset($result['error'])) {
            echo "   ⚠ Response contiene error: {$result['error']}\n";
        }
        return ['success' => true, 'http_code' => $httpCode, 'data' => $result];
    } else {
        echo "   ✗ $name falló (HTTP $httpCode)\n";
        if (isset($result['error'])) {
            echo "   Error: {$result['error']}\n";
        } else {
            echo "   Response: $response\n";
        }
        return ['success' => false, 'http_code' => $httpCode, 'data' => $result];
    }
}

// Test endpoints
$endpoints = [
    ['GET /api/user', 'GET', '/api/user'],
    ['POST /api/compliance/generate', 'POST', '/api/compliance/generate', ['type' => 'compliance']],
    ['GET /api/compliance/config', 'GET', '/api/compliance/config'],
    ['GET /api/reports/list', 'GET', '/api/reports/list'],
    ['GET /api/agents', 'GET', '/api/agents'],
    ['GET /api/databases', 'GET', '/api/databases'],
    ['GET /api/alerts', 'GET', '/api/alerts'],
    ['GET /api/arco/requests', 'GET', '/api/arco/requests'],
    ['GET /api/compliant-companies/search?q=test', 'GET', '/api/compliant-companies/search?q=test'],
];

$results = [];
foreach ($endpoints as $ep) {
    $result = testEndpoint($ep[0], $ep[1], $ep[2], $ep[3] ?? null, $token);
    $results[$ep[0]] = ['success' => $result !== null, 'http_code' => $httpCode ?? 0];
    echo "\n";
}

// Summary
echo "=== RESUMEN ===\n";
$success = 0;
$failed = 0;
foreach ($results as $name => $r) {
    if ($r['success']) {
        echo "✓ $name\n";
        $success++;
    } else {
        echo "✗ $name\n";
        $failed++;
    }
}
echo "\nTotal: $success exitosos, $failed fallidos\n";