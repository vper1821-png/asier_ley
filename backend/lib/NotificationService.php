<?php
/**
 * NotificationService - envío síncrono de notificaciones por email.
 * Usa la función global sendEmail() de config.php (SMTP).
 * Mantiene la firma queue($db, $payload) que ya usan las rutas.
 */
class NotificationService {

    // ═══════════════════════════════════════════════════════════
    // Punto de entrada
    // ═══════════════════════════════════════════════════════════
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

    // ═══════════════════════════════════════════════════════════
    // Asuntos de correo
    // ═══════════════════════════════════════════════════════════
    private static function subjectFor(string $template): string {
        switch ($template) {
            case 'portal_magic_code':           return 'Tu código de acceso al Portal de Privacidad';
            case 'consent_revoked':             return 'Confirmación de revocación de consentimiento';
            case 'consent_revocation_review':   return 'Revocación de consentimiento pendiente de revisión';
            case 'revocation_reviewed':         return 'Revisión de revocación completada';
            case 'arco_new_request':            return 'Nueva solicitud ARCO recibida';
            case 'arco_identity_required':      return 'Acción requerida: valida la identidad del titular';
            case 'arco_identity_uploaded':      return 'Documentos de identidad recibidos';
            case 'identity_rejected':           return 'Identidad no validada';
            case 'concrete_data_delivered':     return 'Tus datos personales están listos';
            default:                            return 'Notificación';
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Render de plantillas
    // ═══════════════════════════════════════════════════════════
    private static function renderTemplate(string $template, array $vars): string {
        $html = self::templateBody($template);
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
            return htmlspecialchars((string)($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8');
        }, $html);
    }

    // ═══════════════════════════════════════════════════════════
    // Cuerpos de correo
    // ═══════════════════════════════════════════════════════════
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
    <p style="margin:0;font-size:12px;color:#64748b;">El cese del tratamiento es inmediato. La revocación es definitiva conforme a la Ley 21.719.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'consent_revocation_review':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Revocación pendiente de revisión</h2>
    <p>Un titular ha revocado su consentimiento. El cese del tratamiento es inmediato, pero debes documentar los efectos sobre otras bases legales.</p>
    <ul style="margin:8px 0 16px;padding-left:18px;">
      <li><b>Titular:</b> {{titular}} ({{email}})</li>
      <li><b>Finalidad:</b> {{purpose}}</li>
      <li><b>Fecha:</b> {{fecha}}</li>
    </ul>
    <p style="font-size:12px;color:#64748b;">Revisa en el panel del DPO si existen otras bases legales aplicables (contrato, obligación legal, interés legítimo). Plazo sugerido: 48 horas.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'revocation_reviewed':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Revisión de revocación completada</h2>
    <p>Tu revocación del tratamiento <b>{{purpose}}</b> ha sido revisada por el Delegado de Protección de Datos.</p>
    <p><b>Efecto:</b> {{effect}}</p>
    <p style="margin:0 0 8px;">{{notes}}</p>
    <p style="font-size:12px;color:#64748b;">Puedes consultar el estado actualizado en tu portal de privacidad.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'arco_new_request':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Nueva solicitud ARCO recibida</h2>
    <p>Se ha recibido una nueva solicitud de derechos desde el portal del titular.</p>
    <ul style="margin:8px 0 16px;padding-left:18px;">
      <li><b>ID:</b> {{requestId}}</li>
      <li><b>Tipo:</b> {{tipo}}</li>
      <li><b>Titular:</b> {{titular}} ({{email}})</li>
    </ul>
    <p style="font-size:12px;color:#64748b;">Plazo legal de respuesta: 10 días hábiles desde la recepción (Art. 11 Ley 21.719).</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'arco_identity_required':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Solicitud de datos concretos</h2>
    <p>Se ha recibido una solicitud de acceso a datos concretos. El titular deberá subir cédula + selfie para que valides su identidad.</p>
    <ul style="margin:8px 0 16px;padding-left:18px;">
      <li><b>ID:</b> {{requestId}}</li>
      <li><b>Titular:</b> {{titular}} ({{email}})</li>
    </ul>
    <p style="font-size:12px;color:#64748b;">Una vez que el titular suba sus documentos, recibirás una notificación adicional para validar la identidad y preparar el paquete de datos.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'arco_identity_uploaded':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Documentos de identidad recibidos</h2>
    <p>El titular ha subido sus documentos de identidad. Ingresa al panel para validarlos.</p>
    <ul style="margin:8px 0 16px;padding-left:18px;">
      <li><b>ID:</b> {{requestId}}</li>
      <li><b>Titular:</b> {{titular}}</li>
    </ul>
    <p style="font-size:12px;color:#64748b;">Al validar la identidad podrás preparar el paquete de datos y entregarlo por enlace temporal.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'identity_rejected':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Identidad no validada</h2>
    <p>No pudimos validar tu identidad para la solicitud <b>{{requestId}}</b>.</p>
    <p><b>Motivo:</b> {{reason}}</p>
    <p style="font-size:12px;color:#64748b;">Puedes ingresar nuevamente a tu portal de privacidad para subir documentos válidos. El plazo legal sigue corriendo.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            case 'concrete_data_delivered':
                return <<<HTML
<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f6f7f9;padding:24px;color:#111;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb;">
    <h2 style="margin:0 0 12px;color:#0f172a;">Tus datos personales están listos</h2>
    <p>El Delegado de Protección de Datos ha preparado el paquete con tus datos personales.</p>
    <p style="margin:16px 0;"><b>Solicitud:</b> {{requestId}}</p>
    <p style="margin:16px 0;text-align:center;">
      <a href="{{deliveryUrl}}" style="display:inline-block;padding:12px 24px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">Descargar mis datos</a>
    </p>
    <p style="font-size:12px;color:#64748b;">El enlace expira en {{expiresHours}} horas. Una vez expirado, deberás solicitar una nueva entrega al DPO.</p>
    <p style="font-size:12px;color:#64748b;">Por seguridad, no compartas este enlace con terceros.</p>
    <p style="margin:20px 0 0;font-size:11px;color:#94a3b8;">{$site}</p>
  </div>
</body></html>
HTML;

            default:
                return '<p>{{message}}</p>';
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Utilidades
    // ═══════════════════════════════════════════════════════════
    private static function previewVars(array $vars): string {
        $safe = $vars;
        if (isset($safe['code'])) {
            $safe['code'] = substr((string)$safe['code'], 0, 2) . '****';
        }
        if (isset($safe['deliveryUrl'])) {
            $safe['deliveryUrl'] = '[enlace temporal]';
        }
        return json_encode($safe, JSON_UNESCAPED_UNICODE);
    }

    private static function log(string $msg): void {
        @file_put_contents(sys_get_temp_dir() . '/notifications.log', date('c') . ' ' . $msg . PHP_EOL, FILE_APPEND);
    }
}