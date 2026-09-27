<?php
// backend/agentes/Eliminar.php

namespace Agentes;

class Eliminar
{
    /** POST /api/agents/{id}/delete */
    public static function borrar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $id = AlcanceAgente::leerAgentId($cuerpo);
        if (!$id) \json_error('agentId requerido');

        $bd = \Database::getInstance();
        $agente = $bd->findOne('agents', ['$or' => [
            ['agentId' => $id],
            ['_id'     => $id],
        ]]);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd->deleteOne('agents', ['_id' => $agente['_id']]);
        $bd->deleteOne('host_monitor', ['agentId' => $agente['agentId'] ?? $id]);

        \audit_log('agent_deleted', [
            'agentId'  => $agente['agentId'] ?? $id,
            'hostname' => $agente['hostname'] ?? '',
        ], $agente['userId'] ?? null, $agente['agentId'] ?? $id);

        \json_response(['success' => true]);
    }

    /** POST /api/agents/{id} — actualiza name/pinned/group */
    public static function actualizar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd = \Database::getInstance();
        $updates = [];
        if (array_key_exists('name', $cuerpo))   $updates['name']   = trim($cuerpo['name']);
        if (array_key_exists('pinned', $cuerpo)) $updates['pinned'] = filter_var($cuerpo['pinned'], FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('group', $cuerpo))  $updates['group']  = trim($cuerpo['group']);

        if ($updates) {
            $bd->updateOne('agents', ['_id' => $agente['_id']], $updates);
        }
        \json_response(['success' => true]);
    }
}