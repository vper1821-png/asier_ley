<?php
/**
 * NotificationService - envío síncrono de notificaciones por email.
 * Usa la función global sendEmail() de config.php (SMTP).
 * Mantiene la firma queue($db, $payload) que ya usan las rutas.
 */
class NotificationService {

    public static function queue($db, array $payload): bool {
        try {
            $channel = $payload['channel'] ?? 'email';
            if ($channel !== 'email') {
                self::log('canal no soportado: ' . $channel);
                return false;
            }

            $to        = trim((string)($payload['recipient'] ?? ''));
            $template  = (string)($payload['templateCode'] ?? '');
            $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];

            if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                self::log('recipient inválido: ' . $to);
                return false;
            }

            $subject = self::subjectFor($template);
            $html    = self::renderTemplate($template, $variables);
            $text    = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));

            if (!defined('SMTP_HOST') || !SMTP_HOST || !SMTP_USER || !SMTP_PASS) {
                self::log("SMTP sin configurar. [$template] -> $to :: " . self::previewVars($variables));
                return false;
            }

            $ok = sendEmail($to, $subject, $html, $text);
            self::log(($ok ? 'OK ' : 'FAIL ') . "[$template] -> $to :: " . self::previewVars($variables));
            return (bool)$ok;

        } catch (Throwable $e) {
            self::log('exception: ' . $e->getMessage());
            return false;
        }
    }

    // ── Plantillas ────────────────────────────────────────────────────────

    private static function subjectFor(string $template): string {
        switch ($template) {
            case 'portal_magic_code': return 'Tu código de acceso al Portal de Privacidad';
            case 'consent_revoked':   return 'Confirmación de revocación de consentimiento';
            default:                  return 'Notificación';
        }
    }

    private static function renderTemplate(string $template, array $vars): string {
        $html = self::templateBody($template);
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return htmlspecialchars((string)($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8');
        }, $html);
    }

    private static function templateBody(string $template): string {
        $site = htmlspecialchars(defined('API_BASE_URL') ? API_BASE_URL : '', ENT_QUOTES, 'UTF-8');

        switch ($template) {
            case 'portal_magic_code':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Acceso a tu Portal de Privacidad</h2>
    <p style="margin:0 0 16px;">Usa este código para iniciar sesión. Es válido por 10 minutos.</p>
    <div style="font-size:34px;letter-spacing:8px;font-weight:700;text-align:center;background:#f1f5f9;padding:16px;border-radius:10px;color:#0f172a;">{{code}}</div>
    <p style="margin:16px 0 0;font-size:12px;color:#64748b;">Si no solicitaste este código, ignora este mensaje.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'consent_revoked':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Consentimiento revocado</h2>
    <p style="margin:0 0 8px;">Hola {{nombre}},</p>
    <p style="margin:0 0 8px;">Confirmamos la revocación del siguiente consentimiento:</p>
    <ul style="margin:8px 0 16px;padding-left:18px;">
      <li><b>Finalidad:</b> {{purpose}}</li>
      <li><b>Fecha:</b> {{fecha}}</li>
    </ul>
    <p style="margin:0;font-size:12px;color:#64748b;">La revocación es definitiva conforme a la Ley 21.719.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            default:
                return '<p>{{message}}</p>';
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────

    private static function previewVars(array $vars): string {
        $safe = $vars;
        if (isset($safe['code'])) {
            $safe['code'] = substr((string)$safe['code'], 0, 2) . '****';
        }
        return json_encode($safe, JSON_UNESCAPED_UNICODE);
    }

    private static function log(string $msg): void {
        @file_put_contents(sys_get_temp_dir() . '/notifications.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    }
}