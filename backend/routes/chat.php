<?php
// Chat routes (AI chat)

function send() {
    $user = Auth::requireAuth();
    $body = get_body();
    $message = $body['message'] ?? '';

    if (!$message) json_error('mensaje requerido');

    // Try Ollama AI
    $response = callOllama($message);

    $db = Database::getInstance();
    $db->insertOne('chat_messages', [
        'userId' => $user['_id'],
        'message' => $message,
        'response' => $response,
        'role' => 'user',
    ]);

    json_response([
        'reply' => $response,
        'role' => 'assistant',
    ]);
}

function callOllama($prompt) {
    $url = OLLAMA_HOST . '/api/generate';
    $data = json_encode([
        'model' => AI_MODEL,
        'prompt' => "Eres un asistente de ciberseguridad y cumplimiento normativo. Responde en español de forma concisa y útil.\n\nUsuario: $prompt\n\nAsistente:",
        'stream' => false,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    if ($result) {
        $json = json_decode($result, true);
        return $json['response'] ?? 'Lo siento, no pude procesar tu consulta.';
    }

    return 'El servicio de IA no está disponible en este momento. Por favor, intenta de nuevo más tarde.';
}
