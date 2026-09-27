<?php
// backend/agentes/Listado.php

namespace Agentes;

class Listado
{
    /** POST /api/agents o /api/agents/list */
    public static function listar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        if (AlcanceAgente::esAdminGlobal($usuario)) {
            $agentes = $bd->find('agents', []);
            $ownerMap = [];
            foreach ($bd->find('users', []) as $u) {
                $ownerMap[$u['_id']] = [
                    'email'       => $u['email']       ?? '',
                    'companyName' => $u['companyName'] ?? '',
                    'isActive'    => $u['isActive']    ?? true,
                    'role'        => $u['role']        ?? 'user',
                ];
            }
            foreach ($agentes as &$a) {
                $o = $ownerMap[$a['userId'] ?? ''] ?? null;
                $a['companyEmail']  = $o['email']       ?? '';
                $a['companyName']   = $o['companyName'] ?? '';
                $a['companyActive'] = $o['isActive']    ?? true;
            }
            unset($a);
            \json_response($agentes);
        }

        $registro = $bd->findOne('users', ['_id' => $usuario['_id']]);
        if (!$registro) \json_error('Usuario no encontrado');

        $companyId = $registro['companyId'] ?? $usuario['_id'];
        $usuarios = $bd->find('users', ['companyId' => $companyId]);
        $userIds = array_map('strval', array_column($usuarios, '_id'));
        if (empty($userIds)) $userIds = [(string)$usuario['_id']];

        $agentes = $bd->find('agents', ['userId' => ['$in' => $userIds]]);
        \json_response($agentes);
    }

    /** POST /api/agents/combined — agentes + host_monitor */
    public static function combinado()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        if (AlcanceAgente::esAdminGlobal($usuario)) {
            $filtro = [];
        } else {
            $registro = $bd->findOne('users', ['_id' => $usuario['_id']]);
            if (!$registro) \json_error('Usuario no encontrado');
            $companyId = $registro['companyId'] ?? $usuario['_id'];
            $usuarios = $bd->find('users', ['companyId' => $companyId]);
            $userIds = array_map('strval', array_column($usuarios, '_id'));
            if (empty($userIds)) $userIds = [(string)$usuario['_id']];
            $filtro = ['userId' => ['$in' => $userIds]];
        }

        $agentes = $bd->find('agents', $filtro);
        $hosts = $bd->find('host_monitor', $filtro);
        $hostsByAgent = [];
        foreach ($hosts as $h) {
            if (!empty($h['agentId'])) $hostsByAgent[$h['agentId']] = $h;
        }

        $combinado = [];
        foreach ($agentes as $a) {
            $aid = $a['agentId'] ?? $a['_id'] ?? '';
            $combinado[] = [
                'agent' => $a,
                'host'  => $hostsByAgent[$aid] ?? [],
            ];
        }
        \json_response($combinado);
    }

    /** GET /api/agents/{id} */
    public static function detalle()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = AlcanceAgente::leerAgentId($cuerpo);
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        \json_response(['success' => true, 'agent' => $agente]);
    }

    /** GET /api/agents/{id}/data?type=X */
    public static function datos()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $_GET['id'] ?? ($cuerpo['agentId'] ?? '');
        $type = $_GET['type'] ?? ($cuerpo['type'] ?? '');
        if (!$agentId || !$type) \json_error('agentId y type requeridos');

        $bd = \Database::getInstance();
        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $recs = $bd->find('agent_data', ['agentId' => $agentId, 'type' => $type]);

        if (empty($recs)) {
            $bd->insertOne('agent_commands', [
                'userId'    => $agente['userId'] ?? $usuario['_id'],
                'agentId'   => $agentId,
                'command'   => 'request_data',
                'params'    => ['type' => $type],
                'createdAt' => date('c'),
                'executed'  => false,
            ]);
            \json_response(['success' => true, 'data' => null, 'ts' => 0, 'requested' => true]);
        }

        usort($recs, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        $ultimo = $recs[0];
        $ts = $ultimo['ts'] ?? 0;
        $ahora = time();

        if ($ahora - $ts > 60) {
            $bd->insertOne('agent_commands', [
                'userId'    => $agente['userId'] ?? $usuario['_id'],
                'agentId'   => $agentId,
                'command'   => 'request_data',
                'params'    => ['type' => $type],
                'createdAt' => date('c'),
                'executed'  => false,
            ]);
        }

        \json_response([
            'success' => true,
            'data'    => $ultimo['data'] ?? null,
            'ts'      => $ts,
            'fresh'   => ($ahora - $ts < 60),
        ]);
    }
}