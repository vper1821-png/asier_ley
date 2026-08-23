<?php
// SecureLab - Backend Configuration (copia este archivo a config.php y rellena tus valores)

// Variables sensibles: usa variables de entorno o un .env en producción
define('PORT', getenv('PORT') ?: '3838');
define('MONGODB_URI', getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017/securelab');
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'cambia-este-secreto-por-uno-fuerte-y-largo');
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: 'admin@example.com');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: '');
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');
define('OLLAMA_HOST', getenv('OLLAMA_HOST') ?: 'http://localhost:11434');
define('AI_MODEL', getenv('AI_MODEL') ?: 'mistral');
define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY') ?: '');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://tu-servidor.example.com');

// SMTP
// Variables sensibles: usa variables de entorno o un .env en producción
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: '');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@example.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'SecureLab');
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
