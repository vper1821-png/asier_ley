<?php
// backend/arco/GenerarRespuesta.php
// POST /api/arco/requests/generate-response

namespace Arco;

class GenerarRespuesta
{
    public static function ejecutar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo  = \get_body();
        $bd      = \Database::getInstance();

        $requestId = $cuerpo['requestId'] ?? '';
        if (!$requestId) \json_error('requestId requerido');

        $req = $bd->findOne('arco_requests', ['requestId' => $requestId]);
        if (!$req) \json_error('solicitud no encontrada', 404);

        if (!AlcanceArco::puedeAcceder($usuario, $bd, $req)) {
            \json_error('acceso denegado', 403);
        }

        $texto = 'Respuesta generada automáticamente conforme a la Ley 21.719 '
               . 'y a los derechos ARCO del solicitante.';

        $bd->updateOne('arco_requests', ['requestId' => $requestId], [
            'response'  => $texto,
            'status'    => 'resolved',
            'updatedAt' => date('c'),
        ]);

        \json_response(['success' => true, 'response' => $texto]);
    }
}