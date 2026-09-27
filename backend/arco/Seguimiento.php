<?php
// backend/arco/Seguimiento.php
// POST /api/arco/track — Consulta pública por requestId.

namespace Arco;

class Seguimiento
{
    public static function ejecutar()
    {
        $cuerpo = \get_body();
        $trackingId = $cuerpo['trackingId'] ?? '';
        if (!$trackingId) \json_error('ID de seguimiento requerido');

        $bd = \Database::getInstance();
        $solicitud = $bd->findOne('arco_requests', ['requestId' => $trackingId]);
        if (!$solicitud) \json_error('solicitud no encontrada');

        \json_response($solicitud);
    }
}