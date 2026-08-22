<?php
// Test de endpoints simulando el flujo del frontend
require_once __DIR__ . '/config.php';

$email = 'asiersinmas2004@gmail.com';
$password = 'Racilo14@@';

echo "=== TEST DE ENDPOINTS (SIMULANDO FRONTEND) ===\n\n";

// Función para hacer peticiones como el frontend
function apiRequest($method, $endpoint, $data = null, $token = null) {
    // Usar el nombre del contenedor backend para conexiones internas
    $baseUrl = 'http://invisia-backend-php:3838';
    $ch = curl_init($baseUrl . $endpoint);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($data !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response
    ];
}

// 1. LOGIN
echo "1. LOGIN\n";
echo "   URL: http://invisia-backend-php:3838/api/login\n";
$loginResult = apiRequest('POST', '/api/login', [
    'email' => $email,
    'password' => $password,
    'captchaToken' => 'development-bypass'
]);

if ($loginResult['http_code'] === 200 && !empty($loginResult['body']['token'])) {
    echo "   ✓ Login exitoso\n";
    $token = $loginResult['body']['token'];
    $user = $loginResult['body']['user'];
    echo "   Token: " . substr($token, 0, 20) . "...\n";
    echo "   User ID: {$user['_id']}\n";
    echo "   Email: {$user['email']}\n";
    echo "   Role: " . ($user['role'] ?? 'N/A') . "\n";
    echo "   IsActive: " . ($user['isActive'] ? 'Yes' : 'No') . "\n";
} else {
    echo "   ✗ Login fallido\n";
    echo "   HTTP Code: {$loginResult['http_code']}\n";
    echo "   Response: " . json_encode($loginResult['body']) . "\n";
    exit(1);
}

echo "\n";

// 2. GET /api/user
echo "2. POST /api/user (user info)\n";
$result = apiRequest('POST', '/api/user', ['token' => $token], null);
if ($result['http_code'] === 200) {
    echo "   ✓ Obtener usuario exitoso\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
    echo "   Error: " . json_encode($result['body']) . "\n";
}

echo "\n";

// 3. GET /api/compliance/config
echo "3. GET /api/compliance/config\n";
$result = apiRequest('GET', '/api/compliance/config', null, $token);
if ($result['http_code'] === 200) {
    echo "   ✓ Configuración de compliance obtenida\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 4. POST /api/agents
echo "4. POST /api/agents\n";
$result = apiRequest('POST', '/api/agents', ['token' => $token], null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Agentes obtenidos ($count agentes)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 5. POST /api/databases
echo "5. POST /api/databases\n";
$result = apiRequest('POST', '/api/databases', ['token' => $token], null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Bases de datos obtenidas ($count bases)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 6. POST /api/alerts
echo "6. POST /api/alerts\n";
$result = apiRequest('POST', '/api/alerts', ['token' => $token], null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Alertas obtenidas ($count alertas)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 7. POST /api/reports/list
echo "7. POST /api/reports/list\n";
$result = apiRequest('POST', '/api/reports/list', ['token' => $token], null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Reportes obtenidos ($count reportes)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 8. POST /api/arco/requests/list
echo "8. POST /api/arco/requests/list\n";
$result = apiRequest('POST', '/api/arco/requests/list', ['token' => $token], null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Solicitudes ARCO obtenidas ($count solicitudes)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 9. GET /api/compliant-companies/search
echo "9. GET /api/compliant-companies/search?q=Ven\n";
$result = apiRequest('GET', '/api/compliant-companies/search?q=Ven', null, null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Búsqueda de empresas ($count resultados)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 10. GET /api/compliance/overview
echo "10. GET /api/compliance/overview\n";
$result = apiRequest('GET', '/api/compliance/overview?token=' . $token, null, null);
if ($result['http_code'] === 200) {
    echo "   ✓ Compliance overview obtenido\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 11. GET /api/compliance/inventory
echo "11. GET /api/compliance/inventory?token=$token\n";
$result = apiRequest('GET', '/api/compliance/inventory?token=' . $token, null, null);
if ($result['http_code'] === 200) {
    $count = is_array($result['body']) ? count($result['body']) : 0;
    echo "   ✓ Inventory obtenido ($count items)\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n";

// 12. POST /api/notifications/unread-count
echo "11. POST /api/notifications/unread-count\n";
$result = apiRequest('POST', '/api/notifications/unread-count', ['token' => $token], null);
if ($result['http_code'] === 200) {
    echo "   ✓ Contador de notificaciones obtenido\n";
    echo "   Count: " . ($result['body']['count'] ?? 'N/A') . "\n";
} else {
    echo "   ✗ Falló (HTTP {$result['http_code']})\n";
}

echo "\n=== TEST COMPLETADO ===\n";