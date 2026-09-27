<?php
// backend/agentes/Sensible.php

namespace Agentes;

class Sensible
{
    /** POST /api/agents/sensitive-inventory */
    public static function inventario()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $_GET['agentId'] ?? ($cuerpo['agentId'] ?? '');
        $status  = $_GET['status']  ?? ($cuerpo['status']  ?? '');
        $limit   = (int)($_GET['limit'] ?? ($cuerpo['limit'] ?? 100));

        $companyId = $usuario['companyId'] ?? $usuario['_id'];

        $mongo = new \MongoDB\Client(MONGODB_URI);
        $coll = $mongo->selectDatabase('invisia')->selectCollection('sensitive_inventory');

        $filtro = ['company_id' => $companyId];
        if ($agentId) $filtro['agent_id'] = $agentId;
        if ($status)  $filtro['status']   = $status;

        $cursor = $coll->find($filtro, ['sort' => ['last_scanned' => -1], 'limit' => $limit]);

        $items = [];
        foreach ($cursor as $doc) {
            $doc = (array)$doc;
            if (isset($doc['_id'])) $doc['_id'] = (string)$doc['_id'];
            $items[] = $doc;
        }

        \json_response(['success' => true, 'items' => $items, 'total' => count($items)]);
    }
}