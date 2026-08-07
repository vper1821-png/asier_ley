<?php
// Captcha routes (Turnstile)

function verify() {
    $body = get_body();
    $token = $body['token'] ?? '';

    if (!$token) json_error('token requerido');

    // In development, bypass captcha
    if ($token === 'development-bypass') {
        json_response(['success' => true]);
    }

    // Verify with Cloudflare Turnstile
    if (TURNSTILE_SECRET_KEY) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => TURNSTILE_SECRET_KEY,
                'response' => $token,
            ]),
            CURLOPT_TIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        if (!empty($data['success'])) {
            json_response(['success' => true]);
        }
    }

    json_error('captcha inválido');
}
