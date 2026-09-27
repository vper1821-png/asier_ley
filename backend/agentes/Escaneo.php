<?php
// backend/agentes/Escaneo.php

namespace Agentes;

class Escaneo
{
    /** GET|POST /api/agents/{id}/scan-state */
    public static function consultar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        $cuerpo = \get_body();
        $agentId = $_GET['id'] ?? ($cuerpo['agentId'] ?? '');
        if (!$agentId) \json_error('agentId requerido');

        $agente = $bd->findOne('agents', ['agentId' => $agentId]);
        if (!$agente) \json_error('agente no encontrado', 404);

        $state = self::normalizar($agente['scanState'] ?? []);

        \json_response([
            'success'   => true,
            'agentId'   => $agentId,
            'scanState' => $state,
            'updatedAt' => $agente['scanStateAt'] ?? null,
        ]);
    }

    /** POST /api/agents/{id}/force-rescan */
    public static function forzar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        $cuerpo = \get_body();
        $agentId = $_GET['id'] ?? ($cuerpo['agentId'] ?? '');
        if (!$agentId) \json_error('agentId requerido');

        $agente = $bd->findOne('agents', ['agentId' => $agentId]);
        if (!$agente) \json_error('agente no encontrado', 404);

        $existente = $bd->findOne('agent_commands', [
            'agentId' => $agentId,
            'command' => 'force-rescan',
            'status'  => 'pending',
        ]);
        if ($existente) {
            \json_response([
                'success' => true,
                'message' => 'Ya hay un re-escaneo pendiente. Se ejecutará en el próximo heartbeat.',
                'queued'  => false,
            ]);
            return;
        }

        $bd->insertOne('agent_commands', [
            'agentId'   => $agentId,
            'command'   => 'force-rescan',
            'payload'   => [],
            'status'    => 'pending',
            'createdAt' => date('c'),
            'createdBy' => (string)$usuario['_id'],
        ]);

        \audit_log('agent_force_rescan', ['agentId' => $agentId], $usuario['_id']);

        \json_response([
            'success' => true,
            'queued'  => true,
            'message' => 'Comando de re-escaneo encolado. Se ejecutará en el próximo heartbeat.',
        ]);
    }

    /** POST /api/agents/{id}/scan-state/report */
    public static function reportar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        $cuerpo = \get_body();
        $agentId = $_GET['id'] ?? ($cuerpo['agentId'] ?? '');
        if (!$agentId) \json_error('agentId requerido');

        $raw = $cuerpo['scanState'] ?? null;
        if (!$raw) \json_error('scanState requerido');

        $bd->updateOne('agents', ['agentId' => $agentId], [
            'scanState'   => self::normalizar($raw),
            'scanStateAt' => date('c'),
        ]);

        \json_response(['success' => true]);
    }

    /**
     * Normaliza cualquier variante de scanState a la forma canónica.
     * FIX crítico: MongoDB devuelve subdocumentos como BSONDocument, no
     * como array — hay que convertirlos o los campos se pierden.
     */
    public static function normalizar($raw): array
    {
        // Convertir BSONDocument → array
        if ($raw instanceof \MongoDB\Model\BSONDocument) {
            $raw = $raw->getArrayCopy();
        } elseif (is_object($raw) && method_exists($raw, 'getArrayCopy')) {
            $raw = $raw->getArrayCopy();
        } elseif (is_object($raw)) {
            $raw = get_object_vars($raw);
        }
        if (!is_array($raw)) $raw = [];

        return [
            'completed'        => self::aBool($raw['completed'] ?? false),
            'started_at'       => $raw['started_at']       ?? null,
            'completed_at'     => $raw['completed_at']     ?? null,
            'total_files'      => (int)($raw['total_files']      ?? 0),
            'sensitive_files'  => (int)($raw['sensitive_files']  ?? 0),
            'duration_seconds' => (int)($raw['duration_seconds'] ?? 0),
        ];
    }

    private static function aBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) !== 0;
        if (is_string($v)) {
            return in_array(strtolower($v), ['1','true','yes','si','sí','done','completed'], true);
        }
        return false;
    }
}