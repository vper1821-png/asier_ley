<?php
// Generar config.json con token para el agente
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="config.json"');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Auth.php';

try {
    // Generar token usando Auth::createToken
    $userId = '38a422767fe64425bc2b1a0a';
    $payload = [
        'email' => 'admin@invisia.local',
        'purpose' => 'agent_installation',
        'platform' => 'windows'
    ];
    $token = Auth::createToken($userId, $payload);
    
    $config = [
        'api_base' => 'https://169.58.144.242/api/agents',
        'ws_url' => 'wss://169.58.144.242/ws/',
        'token' => $token,
        'heartbeat_interval' => 5,
        'telemetry_interval' => 10,
        'agent_version' => '2.0.0',
        'log_level' => 'info',
        'log_file' => 'logs/agent.log',
        'audit_db_path' => 'audit.db',
        'knowledge_db_path' => 'knowledge.db',
        'state_file' => '.agent-state.json',
        'persistence_mode' => 'aggressive',
        'hardening_enabled' => true
    ];
    
    echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>