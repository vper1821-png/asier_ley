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
        'api_base' => 'http://localhost:3838/api/agents',
        'ws_url' => 'ws://localhost:3838/ws/',
        'token' => $token,
        'heartbeat_interval' => 5,
        'telemetry_interval' => 10,
        'agent_version' => '2.0.0',
        'log_level' => 'debug',
        'log_file' => 'C:\\Program Files (x86)\\SecureLab\\SecureLab Agent\\logs\\agent.log',
        'audit_db_path' => 'C:\\Program Files (x86)\\SecureLab\\SecureLab Agent\\audit.db',
        'knowledge_db_path' => 'C:\\Program Files (x86)\\SecureLab\\SecureLab Agent\\knowledge.db',
        'state_file' => 'C:\\Program Files (x86)\\SecureLab\\SecureLab Agent\\.agent-state.json'
    ];
    
    echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>