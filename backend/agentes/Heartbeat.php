<?php
// backend/agentes/Heartbeat.php

namespace Agentes;

class Heartbeat
{
    /** POST /api/agents/{id}/heartbeat */
    public static function ejecutar()
    {
        $agentId = $_GET['agentId'] ?? '';
        $cuerpo = \get_body();
        $token = $cuerpo['token'] ?? '';

        $decoded = \Auth::verifyToken($token);
        if (!$decoded) \json_error('token inválido', 401);

        $bd = \Database::getInstance();
        $agente = $bd->findOne('agents', [
            'agentId' => $agentId,
            'userId'  => $decoded['userId'],
        ]);
        if (!$agente) \json_error('agente no encontrado', 404);

        $metricas = $cuerpo['metrics'] ?? [];
        $status   = $cuerpo['status']  ?? [];

        // ── host_monitor ──
        $hostData = [
            'userId'   => $decoded['userId'],
            'agentId'  => $agentId,
            'hostname' => $agente['hostname'] ?? $agentId,
            'cpu'      => $metricas['cpu']    ?? 0,
            'ram'      => $metricas['memory'] ?? 0,
            'disk'     => $metricas['disk']   ?? 0,
            'load'     => $metricas['load']   ?? 0,
            'uptime'   => $metricas['uptime'] ?? 0,
            'users'    => $metricas['users']  ?? 0,
            'status'   => 'online',
            'lastSeen' => date('c'),
        ];
        $existente = $bd->findOne('host_monitor', [
            'agentId' => $agentId,
            'userId'  => $decoded['userId'],
        ]);
        if ($existente) {
            $bd->updateOne('host_monitor', ['agentId' => $agentId, 'userId' => $decoded['userId']], $hostData);
        } else {
            $bd->insertOne('host_monitor', $hostData);
        }

        // ── agentes ──
        $actualizaciones = [
            'status'       => 'online',
            'lastSeen'     => date('c'),
            'metrics'      => $metricas,
            'systemStatus' => $status,
        ];

        // scanState: acepta múltiples alias y normaliza tipos
        $scanRaw = $cuerpo['scanState']
                ?? ($cuerpo['scan_state'] ?? null);
        if (is_array($scanRaw) && !empty($scanRaw)) {
            $actualizaciones['scanState']   = Escaneo::normalizar($scanRaw);
            $actualizaciones['scanStateAt'] = date('c');
        }

        $bd->updateOne('agents', ['agentId' => $agentId], $actualizaciones);

        // ── eventos del agente → alerts ──
        foreach (($cuerpo['events'] ?? []) as $evento) {
            $bd->insertOne('alerts', [
                'userId'    => $decoded['userId'],
                'agentId'   => $agentId,
                'title'     => $evento['title']       ?? 'Alerta de agente',
                'message'   => $evento['description'] ?? '',
                'source'    => $evento['source']      ?? 'agent',
                'severity'  => $evento['severity']    ?? 'low',
                'autoBlock' => $evento['autoBlock']   ?? false,
            ]);
        }

        // ── comandos pendientes (con dedup por sentAt) ──
        $pendientes = [];
        try {
            $staleThreshold = date('c', time() - 30);
            $cmds = $bd->find('agent_commands', [
                'agentId'  => $agentId,
                'executed' => ['$in' => [false, null]],
                '$or' => [
                    ['sentAt' => null],
                    ['sentAt' => ['$exists' => false]],
                    ['sentAt' => ['$lt' => $staleThreshold]],
                ],
            ]);

            foreach ($cmds as $cmd) {
                $pendientes[] = [
                    'command'   => $cmd['command'] ?? '',
                    'params'    => $cmd['params']  ?? [],
                    'commandId' => (string)($cmd['_id'] ?? ''),
                ];

                $bd->updateOne('agent_commands', ['_id' => $cmd['_id']], [
                    'sentAt'             => date('c'),
                    'sentViaHeartbeat'   => true,
                    'sentViaHeartbeatAt' => date('c'),
                    'sentCount'          => ((int)($cmd['sentCount'] ?? 0)) + 1,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[heartbeat] error leyendo agent_commands: ' . $e->getMessage());
        }

        \json_response([
            'error'             => '',
            'pendingRules'      => [],
            'pendingBlocks'     => [],
            'pendingUnblocks'   => [],
            'syncBlocked'       => [],
            'pendingCommands'   => $pendientes,
            'heartbeatInterval' => 5,
        ]);
    }
}