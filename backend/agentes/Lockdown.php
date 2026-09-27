<?php
// backend/agentes/Lockdown.php

namespace Agentes;

class Lockdown
{
    /** POST /api/agents/lockdown */
    public static function aplicar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $cuerpo['agentId']
                ?? ($cuerpo['id'] ?? ($_GET['agentId'] ?? ($_GET['id'] ?? ($_POST['agentId'] ?? ($_POST['id'] ?? '')))));
        $accion = $cuerpo['action'] ?? ($_GET['action'] ?? '');

        if (!$agentId || !in_array($accion, ['lock', 'unlock'], true)) {
            \json_error('agentId y action (lock|unlock) requeridos');
        }

        $bd = \Database::getInstance();
        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $estado = $accion === 'lock'
            ? [
                'enabled' => true,
                'message' => trim($cuerpo['message'] ?? ($cuerpo['reason'] ?? '')),
                'reason'  => trim($cuerpo['reason'] ?? ''),
                'setBy'   => $usuario['email'] ?? $usuario['_id'],
                'setAt'   => date('c'),
            ]
            : [
                'enabled' => false,
                'message' => '',
                'reason'  => '',
                'setBy'   => $usuario['email'] ?? $usuario['_id'],
                'setAt'   => date('c'),
            ];

        $bd->updateOne('agents', ['agentId' => $agentId], ['lockdown' => $estado]);
        $bd->updateOne('host_monitor', ['agentId' => $agentId], ['lockdown' => $estado]);

        $bd->insertOne('agent_commands', [
            'userId'    => $agente['userId'] ?? $usuario['_id'],
            'agentId'   => $agentId,
            'command'   => $accion === 'lock' ? 'lockdown' : 'unlock',
            'params'    => ['message' => $estado['message']],
            'createdAt' => date('c'),
            'executed'  => false,
        ]);

        \audit_log('lockdown_' . ($accion === 'lock' ? 'on' : 'off'), [
            'agentId'  => $agentId,
            'hostname' => $agente['hostname'] ?? '',
            'message'  => $estado['message'],
        ], $agente['userId'] ?? null, $agentId);

        \json_response(['success' => true, 'lockdown' => $estado]);
    }
}