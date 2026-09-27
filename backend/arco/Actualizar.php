<?php
// backend/arco/Actualizar.php
// POST /api/arco/requests/update — Actualización con historial append-only.

namespace Arco;

class Actualizar
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

        $ahora = date('c');
        $respondidoPor = $usuario['name']
                       ?? ($usuario['companyName']
                       ?? ($usuario['email'] ?? 'Responsable'));

        // Normalizar entradas
        $estado    = trim((string)($cuerpo['estado'] ?? ($cuerpo['status'] ?? '')));
        $hasResp   = array_key_exists('response', $cuerpo) || array_key_exists('respuesta', $cuerpo);
        $respuesta = trim((string)($cuerpo['response'] ?? ($cuerpo['respuesta'] ?? '')));

        $estadoPrevio     = $req['status']   ?? 'pending';
        $respuestaPrevia  = (string)($req['response'] ?? '');

        // Detectar cambios reales (no borrar respuesta si llega vacía)
        $estadoCambio    = ($estado !== '' && $estado !== $estadoPrevio);
        $respuestaCambio = ($hasResp && $respuesta !== '' && $respuesta !== $respuestaPrevia);

        if (!$estadoCambio && !$respuestaCambio) {
            \json_response([
                'success'   => true,
                'unchanged' => true,
                'history'   => \BsonHelpers::toArray($req['statusHistory'] ?? []),
            ]);
        }

        // Construir updates
        $actualizaciones = ['updatedAt' => $ahora];

        if ($respuestaCambio) {
            $actualizaciones['response']    = $respuesta;
            $actualizaciones['respondedBy'] = $respondidoPor;
            $actualizaciones['respondedAt'] = $ahora;
        }

        if ($estadoCambio) {
            $actualizaciones['status'] = $estado;

            if ($estado === 'in_progress' && empty($req['startedAt'])) {
                $actualizaciones['startedAt'] = $ahora;
            }
            if (in_array($estado, ['completed', 'resolved'], true)) {
                $actualizaciones['resolvedAt'] = $ahora;
            }
            if ($estado === 'finished') {
                $actualizaciones['finishedAt'] = $ahora;
            }
            if ($estado === 'rejected') {
                $actualizaciones['rejectedAt'] = $ahora;
            }
        }

        // Historial append-only con snapshot completo
        $historial = \BsonHelpers::toArray($req['statusHistory'] ?? []);
        if (!is_array($historial)) $historial = [];

        $nuevoEstado    = $estadoCambio    ? $estado    : $estadoPrevio;
        $nuevaRespuesta = $respuestaCambio ? $respuesta : $respuestaPrevia;

        $resumenNota = mb_strlen($nuevaRespuesta) > 140
            ? mb_substr($nuevaRespuesta, 0, 140) . '…'
            : $nuevaRespuesta;

        $tipoCambio = $estadoCambio && $respuestaCambio ? 'status+response'
                    : ($estadoCambio ? 'status' : 'response');

        $entrada = [
            'at'         => $ahora,
            'by'         => $respondidoPor,
            'status'     => $nuevoEstado,
            'prevStatus' => $estadoPrevio,
            'kind'       => $tipoCambio,
            'response'   => $nuevaRespuesta,
            'note'       => $resumenNota,
        ];

        // Evitar entradas duplicadas consecutivas idénticas
        $ultima = !empty($historial) ? end($historial) : null;
        $esDuplicada = $ultima
            && ($ultima['status'] ?? '') === $entrada['status']
            && (string)($ultima['response'] ?? '') === $entrada['response'];

        if (!$esDuplicada) {
            $historial[] = $entrada;
            if (count($historial) > 200) $historial = array_slice($historial, -200);
            $actualizaciones['statusHistory'] = $historial;
        }

        $bd->updateOne('arco_requests', ['requestId' => $requestId], $actualizaciones);

        \json_response([
            'success' => true,
            'status'  => $nuevoEstado,
            'history' => $historial,
        ]);
    }
}