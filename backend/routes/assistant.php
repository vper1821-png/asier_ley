<?php
// Assistant routes

function query() {
    $user = Auth::requireAuth();
    $body = get_body();
    $query = $body['question'] ?? $body['query'] ?? $body['message'] ?? '';

    if (!$query) json_error('consulta requerida');

    // Try Ollama AI
    $url = OLLAMA_HOST . '/api/generate';
    $data = json_encode([
        'model' => AI_MODEL,
        'prompt' => "Eres SecureLab Assistant, un experto en ciberseguridad, cumplimiento normativo y protección de datos en Chile. " .
            "El marco legal aplicable es EXCLUSIVAMENTE la Ley 21.719 de Protección de Datos Personales de Chile " .
            "(que crea la Agencia de Protección de Datos Personales, APDP) y, en su caso, la Ley 19.628. " .
            "NUNCA cites la legislación de España (LOPDGDD, LOPD, AEPD) ni el RGPD/GDPR de la Unión Europea como norma aplicable; " .
            "los derechos de los titulares se rigen por los artículos 8 a 13 de la Ley 21.719. " .
            "Si el usuario pregunta por otra jurisdicción, aclara que en Chile aplica la Ley 21.719. " .
            "Responde en español.\n\nUsuario: $query\n\nAsistente:",
        'stream' => false,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    if ($result) {
        $json = json_decode($result, true);
        $response = $json['response'] ?? 'No pude procesar tu consulta.';
    } else {
        $response = 'El asistente IA no está disponible. Intenta más tarde.';
    }

    json_response([
        'answer' => $response,
        'reply' => $response,
        'role' => 'assistant',
    ]);
}

function ask() {
    query();
}

function feedback() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $db->insertOne('assistant_feedback', [
        'userId' => $user['_id'],
        'rating' => $body['rating'] ?? null,
        'message' => $body['message'] ?? '',
        'question' => $body['question'] ?? '',
        'response' => $body['response'] ?? '',
        'createdAt' => date('c'),
    ]);

    json_response(['success' => true]);
}
