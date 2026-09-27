<?php
// backend/agentes/AlcanceAgente.php

namespace Agentes;

use Tenant;

class AlcanceAgente
{
    public static function esSuperAdmin($u): bool
    {
        return Tenant::isSuperAdmin($u);
    }

    public static function esAdminGlobal($u): bool
    {
        return Tenant::isGlobalAdmin($u);
    }

    /**
     * Replica exacta del findAgentFor() original:
     * - Acepta agentId o _id
     * - Aplica filtro de userId salvo para superadmin
     * - Devuelve null si no existe o no es del usuario
     */
    public static function buscar($usuario, $agentId)
    {
        $bd = \Database::getInstance();
        $filtro = ['$or' => [
            ['agentId' => $agentId],
            ['_id'     => $agentId],
        ]];
        if (!self::esSuperAdmin($usuario)) {
            $filtro['userId'] = $usuario['_id'];
        }
        $agente = $bd->findOne('agents', $filtro);

        if (!$agente) return null;
        if (($agente['userId'] ?? '') !== $usuario['_id'] && !self::esSuperAdmin($usuario)) {
            return null;
        }
        return $agente;
    }

    /**
     * Réplica de la cadena de lectura del agentId usada en casi todos los
     * endpoints del archivo original:
     *   $_GET['id'] ?? $_GET['agentId'] ?? $body['agentId'] ?? $body['id']
     *   ?? $_POST['id'] ?? $_POST['agentId'] ?? ''
     */
    public static function leerAgentId(array $cuerpo): string
    {
        return $_GET['id']
            ?? $_GET['agentId']
            ?? $cuerpo['agentId']
            ?? $cuerpo['id']
            ?? $_POST['id']
            ?? $_POST['agentId']
            ?? '';
    }

    /** Devuelve el filtro userId de la empresa, o [] para admin global. */
    public static function filtroEmpresa($usuario, $bd): array
    {
        return Tenant::scope($usuario, $bd)['filter'];
    }

    /** Genera un agentId legible cuando el agente no envía uno. */
    public static function generarAgentId(): string
    {
        return 'AGT-' . strtoupper(bin2hex(random_bytes(10)));
    }
}