<?php
// backend/arco/AlcanceArco.php
// Scope y verificación de acceso específicos del módulo ARCO.
// Delega en Tenant cuando puede; mantiene firmas compatibles con el
// arco.php original.

namespace Arco;

use Tenant;

class AlcanceArco
{
    /**
     * Devuelve array de companyIds del usuario, o null si es admin global.
     * Compatible con arcoCompanyIds() original.
     */
    public static function companyIds($usuario, $bd): ?array
    {
        $scope = Tenant::scope($usuario, $bd);
        return $scope['isGlobalAdmin'] ? null : $scope['companyIds'];
    }

    /** Filtro listo para $bd->find() sobre 'arco_requests'. */
    public static function filtroEmpresa($usuario, $bd): array
    {
        return Tenant::companyFilter($usuario, $bd, 'companyId');
    }

    /**
     * ¿Puede el usuario acceder a esta solicitud ARCO?
     * Equivalente a arcoCanAccess() original pero delegando en Tenant.
     */
    public static function puedeAcceder($usuario, $bd, $solicitud): bool
    {
        // Tenant::canAccess valida userId Y companyId con tolerancia string/ObjectId
        return Tenant::canAccess($solicitud, $usuario, $bd);
    }

    /** Condiciones $or para un id (string + ObjectId). Delegado a Tenant. */
    public static function idConditions(string $campo, $id): array
    {
        return Tenant::idConditions($campo, $id);
    }
}