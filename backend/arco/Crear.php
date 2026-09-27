<?php
// backend/arco/Crear.php
// POST /api/arco/requests — Creación pública de solicitudes ARCO.

namespace Arco;

class Crear
{
    public static function ejecutar()
    {
        $cuerpo = \get_body();
        $solicitante   = $cuerpo['solicitante'] ?? [];
        $tipo          = $cuerpo['tipo']        ?? 'acceso';
        $descripcion   = $cuerpo['descripcion'] ?? '';
        $companyId     = $cuerpo['companyId']   ?? null;
        $captchaToken  = $cuerpo['captchaToken'] ?? '';

        if (empty($solicitante['nombre']) || empty($solicitante['rut']) || empty($solicitante['email'])) {
            \json_error('datos del solicitante requeridos');
        }

        // Verificación captcha Turnstile
        if (!\verify_turnstile($captchaToken)) {
            \json_error('verificación captcha fallida. Por favor, intenta nuevamente.');
        }

        $bd = \Database::getInstance();
        $requestId = 'ARCO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $ahora = date('c');

        $solicitud = $bd->insertOne('arco_requests', [
            'requestId'   => $requestId,
            'solicitante' => $solicitante,
            'tipo'        => $tipo,
            'descripcion' => $descripcion,
            'companyId'   => $companyId,
            'status'      => 'pending',
            'source'      => 'public_form',
            'createdAt'   => $ahora,
            'updatedAt'   => $ahora,
        ]);

        // Notificar al responsable de la empresa
        if ($companyId) {
            $bd->insertOne('notifications', [
                'userId'    => $companyId,
                'type'      => 'arco',
                'title'     => 'Nueva solicitud ARCO recibida',
                'message'   => 'Solicitud ' . $requestId . ' de ' . $tipo
                             . ' recibida de ' . ($solicitante['nombre'] ?? 'un titular'),
                'read'      => false,
                'createdAt' => $ahora,
                'requestId' => $requestId,
            ]);
        }

        \json_response([
            'success'   => true,
            'requestId' => $requestId,
            'request'   => $solicitud,
        ]);
    }
}