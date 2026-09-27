<?php
// backend/arco/Listar.php
// POST /api/arco/requests/list — Listado por empresa.

namespace Arco;

class Listar
{
    public static function ejecutar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        $filtro = AlcanceArco::filtroEmpresa($usuario, $bd);

        if (empty($filtro)) {
            // Admin global: sin filtro (ve todas las solicitudes)
            $items = $bd->find('arco_requests', []);
        } else {
            $items = $bd->find('arco_requests', $filtro);
        }

        // Convertir BSONDocuments a arrays PHP planos para el frontend
        $items = array_map(['\BsonHelpers', 'toArray'], $items);

        \json_response($items);
    }
}