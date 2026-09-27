<?php
// backend/agentes/Registro.php

namespace Agentes;

class Registro
{
    /** POST /api/agents/register — registro completo */
    public static function registrar()
    {
        $cuerpo = \get_body();
        $token = $cuerpo['token'] ?? '';
        $agentId = $cuerpo['agentId'] ?? '';
        $hostname = $cuerpo['hostname'] ?? '';
        $platform = $cuerpo['platform'] ?? '';
        $arch = $cuerpo['arch'] ?? '';
        $ip = $cuerpo['ip'] ?? '';
        $version = $cuerpo['version'] ?? '';
        $user = $cuerpo['user'] ?? '';

        $decoded = \Auth::verifyToken($token);
        if (!$decoded) \json_error('token inválido', 401);
        if (!$hostname) \json_error('hostname requerido');

        $bd = \Database::getInstance();

        if (!$agentId) {
            $agentId = AlcanceAgente::generarAgentId();
        }

        $userId = $decoded['userId'];
        $ahora = date('c');

        $mismoHost = $bd->find('agents', [
            'userId'   => $userId,
            'hostname' => $hostname,
            'platform' => $platform,
        ]);

        $existente = null;
        if (!empty($mismoHost)) {
            usort($mismoHost, fn($a, $b) =>
                strcmp($b['lastSeen'] ?? ($b['createdAt'] ?? ''), $a['lastSeen'] ?? ($a['createdAt'] ?? ''))
            );
            $existente = $mismoHost[0];
        }

        if ($existente) {
            $keptAgentId = $existente['agentId'];
            $bd->updateOne('agents', ['_id' => $existente['_id']], [
                'agentId'  => $keptAgentId,
                'hostname' => $hostname,
                'platform' => $platform,
                'arch'     => $arch,
                'ip'       => $ip,
                'version'  => $version,
                'user'     => $user,
                'status'   => 'online',
                'lastSeen' => $ahora,
            ]);

            foreach ($mismoHost as $dup) {
                if (($dup['_id'] ?? '') !== ($existente['_id'] ?? '')) {
                    $bd->deleteOne('agents', ['_id' => $dup['_id']]);
                    $bd->deleteOne('host_monitor', ['agentId' => $dup['agentId']]);
                }
            }

            $agente = $bd->findOne('agents', ['_id' => $existente['_id']]);
            $agentId = $keptAgentId;
        } else {
            $agente = $bd->insertOne('agents', [
                'userId'   => $userId,
                'agentId'  => $agentId,
                'hostname' => $hostname,
                'platform' => $platform,
                'arch'     => $arch,
                'ip'       => $ip,
                'version'  => $version,
                'user'     => $user,
                'status'   => 'online',
                'lastSeen' => $ahora,
            ]);
            \audit_log('agent_registered', [
                'agentId'  => $agentId,
                'hostname' => $hostname,
                'platform' => $platform,
                'ip'       => $ip,
            ], $userId, $agentId);
        }

        \json_response([
            'agentId' => $agentId,
            'agent'   => ['_id' => $agente['_id'] ?? ''],
        ]);
    }

    /** POST /api/agents/auto-register — registro simplificado sin token */
    public static function autoRegistrar()
    {
        $cuerpo = \get_body();
        $hostname = $cuerpo['hostname'] ?? 'unknown';
        $platform = $cuerpo['platform'] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $bd = \Database::getInstance();

        $existente = $bd->findOne('agents', ['hostname' => $hostname]);
        if ($existente) {
            $token = \Auth::createToken($existente['userId'], [
                'agentId'  => $existente['agentId'] ?? $existente['_id'],
                'hostname' => $hostname,
                'platform' => $platform,
            ]);
            \json_response([
                'success' => true,
                'token'   => $token,
                'agentId' => $existente['agentId'] ?? $existente['_id'],
                'message' => 'Agente ya registrado, token actualizado',
            ]);
        }

        $nuevo = [
            'hostname'  => $hostname,
            'platform'  => $platform,
            'ip'        => $ip,
            'status'    => 'pending',
            'lastSeen'  => date('c'),
            'createdAt' => date('c'),
            'agentId'   => AlcanceAgente::generarAgentId(),
            'version'   => '2.0.0',
        ];
        $insertado = $bd->insertOne('agents', $nuevo);

        $token = \Auth::createToken($nuevo['userId'] ?? null, [
            'agentId'  => (string)$insertado['_id'],
            'hostname' => $hostname,
            'platform' => $platform,
        ]);

        \audit_log('agent_auto_registered', [
            'agentId'  => (string)$insertado['_id'],
            'hostname' => $hostname,
            'platform' => $platform,
            'ip'       => $ip,
        ]);

        \json_response([
            'success' => true,
            'token'   => $token,
            'agentId' => (string)$insertado['_id'],
            'message' => 'Agente registrado exitosamente',
        ]);
    }

    /** POST /api/agents/deploy — registra un evento de descarga */
    public static function crearDeploy()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $platform  = $cuerpo['platform']  ?? 'win-x64';
        $userAgent = $cuerpo['userAgent'] ?? '';

        $bd = \Database::getInstance();
        $deploy = [
            'userId'        => $usuario['_id'],
            'platform'      => $platform,
            'userAgent'     => $userAgent,
            'createdAt'     => date('c'),
            'status'        => 'pending_download',
            'downloadCount' => 0,
        ];
        $deployId = $bd->insertOne('agent_deploys', $deploy);
        \audit_log('agent_deploy_created', [
            'deployId' => (string)$deployId,
            'platform' => $platform,
        ], $usuario['_id']);

        \json_response(['success' => true, 'deployId' => (string)$deployId]);
    }

    /** POST /api/agents/download-token */
    public static function tokenDescarga()
    {
        $usuario = \Auth::requireAuth();
        $token = \Auth::createToken($usuario['_id'], [
            'email'   => $usuario['email'] ?? '',
            'purpose' => 'agent_download',
        ]);
        \json_response(['token' => $token]);
    }
}