<?php
// backend/agentes/Comandos.php

namespace Agentes;

class Comandos
{
    /** POST /api/agents/{id}/command */
    public static function enviar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        $command = $cuerpo['command'] ?? '';
        $params  = $cuerpo['params']  ?? '';

        if (!$agentId) \json_error('agentId requerido');
        if (!$command) \json_error('command requerido');

        if (is_string($command)) $command = json_decode($command, true) ?? $command;
        if (is_array($command)) {
            $params = $command['params'] ?? $command;
            unset($params['command']);
            $command = $command['command'] ?? '';
        }
        if (!$command) \json_error('command requerido');
        if (is_string($params)) $params = json_decode($params, true) ?? [];

        $bd = \Database::getInstance();
        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $cmd = $bd->insertOne('agent_commands', [
            'userId'    => $agente['userId'] ?? $usuario['_id'],
            'agentId'   => $agentId,
            'command'   => $command,
            'params'    => is_array($params) ? $params : [],
            'createdAt' => date('c'),
            'executed'  => false,
        ]);

        \audit_log('agent_command', [
            'agentId'  => $agentId,
            'hostname' => $agente['hostname'] ?? '',
            'command'  => $command,
            'params'   => $params,
        ], $agente['userId'] ?? null, $agentId);

        \json_response(['success' => true, 'commandId' => $cmd['_id']]);
    }

    /** POST /api/agents/{id}/request-data (equivalente a listCommands en request_data) */
    public static function solicitarDatos()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        $type = $_GET['type'] ?? ($cuerpo['type'] ?? 'processes');

        if (!$agentId) \json_error('agentId requerido');

        $bd = \Database::getInstance();
        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $cmd = $bd->insertOne('agent_commands', [
            'userId'    => $agente['userId'] ?? $usuario['_id'],
            'agentId'   => $agentId,
            'command'   => 'request_data',
            'params'    => ['type' => $type],
            'createdAt' => date('c'),
            'executed'  => false,
        ]);

        \json_response(['success' => true, 'commandId' => $cmd['_id']]);
    }

    /** POST /api/agents/{id}/commands — historial */
    public static function listar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        if (!$agentId) \json_error('agentId requerido');

        $bd = \Database::getInstance();
        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $cmds = $bd->find('agent_commands', ['agentId' => $agentId]);
        usort($cmds, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        \json_response(array_slice($cmds, 0, 50));
    }

    /** POST /api/agents/{id}/message — eventos enviados por el agente */
    public static function mensaje()
    {
        $usuario = \Auth::requireAuth();
        $agentId = $_GET['agentId'] ?? ($_GET['id'] ?? '');
        if (!$agentId) \json_error('agentId requerido');

        $cuerpo = \get_body();
        $rawMsg = $cuerpo['message'] ?? [];
        $msg = is_array($rawMsg) ? $rawMsg : (json_decode($rawMsg, true) ?: []);
        $type = $msg['type'] ?? '';
        $bd = \Database::getInstance();

        switch ($type) {
            case 'event':
            case 'host_event':
            case 'antivirus':
            case 'compliance':
            case 'db_activity':
            case 'ai_analyzer':
            case 'ai_analyzer_deep':
                $bd->insertOne('alerts', [
                    'userId'        => $usuario['_id'],
                    'agentId'       => $agentId,
                    'title'         => $msg['title']       ?? 'Evento del agente',
                    'message'       => $msg['description'] ?? ($msg['payload'] ?? ''),
                    'severity'      => $msg['severity']    ?? 'medium',
                    'source'        => $msg['source']      ?? $type,
                    'type'          => $type,
                    'eventType'     => $msg['eventType']   ?? $type,
                    'read'          => false,
                    'showOnLanding' => false,
                    'createdAt'     => date('c'),
                ]);
                break;

            case 'db_log_discovery':
                if (!empty($msg['dbLogDiscovery'])) {
                    $bd->insertOne('database_logs', [
                        'userId'    => $usuario['_id'],
                        'agentId'   => $agentId,
                        'logType'   => 'discovery',
                        'severity'  => 'info',
                        'operation' => 'scan',
                        'data'      => $msg['dbLogDiscovery'],
                        'createdAt' => date('c'),
                    ]);
                }
                break;

            case 'log_query':
                $logs = $msg['queryLogs'] ?? [];
                if (is_array($logs)) {
                    foreach ($logs as $log) {
                        $bd->insertOne('database_logs', [
                            'userId'    => $usuario['_id'],
                            'agentId'   => $agentId,
                            'logType'   => 'query',
                            'severity'  => $log['severity']  ?? 'info',
                            'operation' => $log['operation'] ?? 'query',
                            'query'     => $log['query']     ?? '',
                            'dbUser'    => $log['user']      ?? '',
                            'database'  => $log['database']  ?? '',
                            'timestamp' => $log['timestamp'] ?? date('c'),
                            'createdAt' => date('c'),
                        ]);
                    }
                }
                break;
        }

        \json_response(['success' => true]);
    }
}