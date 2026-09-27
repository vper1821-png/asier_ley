<?php
// backend/cumplimiento/Alcance.php
// Traduce el scope multi-tenant a las preguntas específicas que hace
// el módulo de cumplimiento.

namespace Cumplimiento;

use Tenant;

class Alcance
{
    /**
     * Devuelve null si el usuario es admin global (ve todo).
     * Devuelve array de userIds (strings) en caso contrario.
     * Firma compatible con la función getCompanyUserIds() original.
     */
    public static function idsDeEmpresa($usuario, $bd)
    {
        $scope = Tenant::scope($usuario, $bd);
        return $scope['isGlobalAdmin'] ? null : $scope['userIds'];
    }

    /** Filtro listo para pasar a $bd->find(). */
    public static function filtro($usuario, $bd): array
    {
        return Tenant::scope($usuario, $bd)['filter'];
    }

    /** Filtro por campo companyId (robusto string+ObjectId). */
    public static function filtroEmpresa($usuario, $bd, string $campo = 'companyId'): array
    {
        return Tenant::companyFilter($usuario, $bd, $campo);
    }

    /** ¿Es DPO o DPD? */
    public static function esDpo($usuario, $bd): bool
    {
        if (Tenant::isGlobalAdmin($usuario)) return true;
        $registro = $bd->findOne('users', ['_id' => $usuario['_id']]) ?? [];
        $rol = strtolower($registro['role'] ?? ($usuario['role'] ?? ''));
        return in_array($rol, ['dpo', 'dpd'], true);
    }

    /** ¿Es admin global? */
    public static function esAdminGlobal($usuario): bool
    {
        return Tenant::isGlobalAdmin($usuario);
    }

    /** ¿Es superadmin? */
    public static function esSuperAdmin($usuario): bool
    {
        return Tenant::isSuperAdmin($usuario);
    }
}

// ─── Aliases de compatibilidad (funciones globales antiguas) ───
if (!function_exists('getCompanyUserIds')) {
    function getCompanyUserIds($usuario, $bd) {
        return \Cumplimiento\Alcance::idsDeEmpresa($usuario, $bd);
    }
}
if (!function_exists('isDpoOrDpd')) {
    function isDpoOrDpd($usuario, $bd) {
        return \Cumplimiento\Alcance::esDpo($usuario, $bd);
    }
}