<?php
namespace Cumplimiento;

class ArcoCrud
{
    public static function manejar($usuario, $bd, string $metodo, string $id, string $accion, array $cuerpo)
    {
        $userIds = Alcance::idsDeEmpresa($usuario, $bd);
        $esSuper = ($userIds === null);
        $filtroEmpresa = $esSuper ? [] : ['companyId' => ['$in' => $userIds]];

        if ($metodo === 'GET' && !$id) {
            $items = $bd->find('arco_requests', $filtroEmpresa);
            \json_response($items);
        }

        if ($metodo === 'GET' && $id) {
            $filtro = array_merge(['_id' => $id], $filtroEmpresa);
            $item = $bd->findOne('arco_requests', $filtro);
            if (!$item) \json_error('solicitud no encontrada', 404);
            \json_response($item);
        }

        if ($metodo === 'POST' && $id && in_array($accion, ['respond', 'reject'], true)) {
            $filtro = array_merge(['_id' => $id], $filtroEmpresa);
            $solicitud = $bd->findOne('arco_requests', $filtro);
            if (!$solicitud) \json_error('solicitud no encontrada', 404);

            $estado = $accion === 'respond' ? 'resolved' : 'rejected';
            $respuesta = $cuerpo['response'] ?? '';

            $bd->updateOne('arco_requests', ['_id' => $id], [
                'status'     => $estado,
                'response'   => $respuesta,
                'resolvedAt' => date('c'),
                'resolvedBy' => $usuario['_id'],
            ]);
            \json_response(['success' => true]);
        }

        if ($metodo === 'POST' && $accion === 'generate-response') {
            $filtro = array_merge(['_id' => $id], $filtroEmpresa);
            $solicitud = $bd->findOne('arco_requests', $filtro);
            if (!$solicitud) \json_error('solicitud no encontrada', 404);

            $respuesta = 'Respuesta generada automáticamente conforme a la Ley 21.719.';
            $bd->updateOne('arco_requests', ['_id' => $id], [
                'response' => $respuesta,
                'status'   => 'in_review',
            ]);
            \json_response(['success' => true, 'response' => $respuesta]);
        }

        \json_error('método no soportado para ARCO', 405);
    }
}