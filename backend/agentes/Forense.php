<?php
// backend/agentes/Forense.php

namespace Agentes;

class Forense
{
    /** GET /api/agents/{id}/forensics?type=X */
    public static function eventos()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd = \Database::getInstance();
        $type  = $_GET['type']  ?? ($cuerpo['type']  ?? 'files');
        $limit = (int)($_GET['limit'] ?? ($cuerpo['limit'] ?? 50));

        $coleccion = in_array($type, ['files', 'db', 'host'], true)
            ? ($type === 'files' ? 'file_events' : ($type === 'db' ? 'database_logs' : 'host_events'))
            : 'file_events';

        $eventos = $bd->find($coleccion, ['agentId' => $agentId, 'userId' => $usuario['_id']]);
        usort($eventos, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        \json_response([
            'success' => true,
            'type'    => $type,
            'events'  => array_slice($eventos, 0, $limit),
        ]);
    }

    /** GET /api/agents/{id}/logs */
    public static function logs()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        $lines = (int)($_GET['lines'] ?? ($cuerpo['lines'] ?? 100));
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $rutas = [
            '/var/log/securelab-agent/agent.log',
            '/opt/securelab-agent/logs/agent.log',
            'C:\Program Files\SecureLab Agent\logs\agent.log',
            'C:\Program Files (x86)\SecureLab\SecureLab Agent\logs\agent.log',
            'C:\ProgramData\SecureLab Agent\logs\agent.log',
        ];

        $contenido = '';
        foreach ($rutas as $ruta) {
            if (file_exists($ruta)) {
                $c = file_get_contents($ruta);
                if ($c) { $contenido = $c; break; }
            }
        }

        if (!$contenido) {
            \json_response([
                'success' => true,
                'logs'    => 'No log file found',
                'agentId' => $agentId,
            ]);
            return;
        }

        $lineas = array_slice(explode("\n", $contenido), -$lines);
        \json_response([
            'success'    => true,
            'logs'       => implode("\n", $lineas),
            'agentId'    => $agentId,
            'totalLines' => count($lineas),
        ]);
    }
}