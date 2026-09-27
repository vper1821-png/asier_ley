<?php
// backend/lib/Tenant.php
//
// Scope multi-tenant unificado para SecureLab.
//
// ────────────────────────────────────────────────────────────────
// USO RÁPIDO
// ────────────────────────────────────────────────────────────────
//   require_once __DIR__ . '/../lib/Tenant.php';
//   $scope = Tenant::scope($user, $db);
//
//   // Filtrar listados por la empresa del usuario (superadmin ve todo):
//   $items = $db->find('compliance_files', $scope['filter']);
//
//   // Filtrar por companyId (ARCO, solicitudes públicas):
//   $filter = Tenant::companyFilter($user, $db); // [] para superadmin
//   $items = $db->find('arco_requests', $filter);
//
//   // ¿El usuario es admin global?
//   if (Tenant::isGlobalAdmin($user)) { ... }
//
// ────────────────────────────────────────────────────────────────
// CONTRATO DE RETORNO DE scope()
// ────────────────────────────────────────────────────────────────
//   [
//     'isSuperAdmin'   => bool,          // role === 'superadmin'
//     'isGlobalAdmin'  => bool,          // isAdmin || role in (admin, superadmin)
//     'isCompanyAdmin' => bool,          // company_admin | dpo | dpd | admin
//     'userIds'        => string[],      // [] si isGlobalAdmin
//     'companyId'      => ?string,       // null si isGlobalAdmin
//     'companyIds'     => string[],      // [] si isGlobalAdmin
//     'filter'         => array,         // ['userId'=>['$in'=>...]] o [] (superadmin)
//     'companyName'    => string,
//     'userRecord'     => array,         // el documento users completo
//   ]
//
// ────────────────────────────────────────────────────────────────
// GARANTÍAS
// ────────────────────────────────────────────────────────────────
//   - Un userId siempre se incluye en su propio scope (no se autoexcluye).
//   - companyId se busca como string Y como ObjectId (tolerante a migraciones).
//   - Los userIds "huérfanos" que aparecen en compliance_* por agentes
//     heredados se propagan al scope (opt-in, cacheado).
//   - Nunca tira excepción: si el usuario no tiene registro en BD,
//     usa el objeto $user recibido.
// ────────────────────────────────────────────────────────────────

if (!class_exists('Tenant')) {

class Tenant
{
    /** @var array<string,array> Cache por request, keyed por userId|opts */
    private static array $cache = [];

    /** @var array<string,int> Estadísticas de hits para debug */
    private static array $stats = ['hits' => 0, 'misses' => 0];

    // ═══════════════════════════════════════════════════════════
    // ROLES
    // ═══════════════════════════════════════════════════════════

    /**
     * Superadmin estricto: solo el rol 'superadmin'.
     * (No exigimos isAdmin=true para no romper cuentas heredadas.)
     */
    public static function isSuperAdmin($user): bool
    {
        return ($user['role'] ?? '') === 'superadmin';
    }

    /**
     * Admin global: cualquier usuario con isAdmin=true o rol admin/superadmin.
     * Esta es la definición que usan compliance, dashboard, agents, databases,
     * reports, alerts. La replicamos exactamente para no cambiar comportamiento.
     */
    public static function isGlobalAdmin($user): bool
    {
        return !empty($user['isAdmin'])
            || in_array(($user['role'] ?? ''), ['admin', 'superadmin'], true);
    }

    /**
     * ¿Puede gestionar usuarios dentro de su propia empresa?
     * DPO/DPD y company_admin también pueden.
     */
    public static function isCompanyAdmin($user): bool
    {
        $role = strtolower((string)($user['role'] ?? ''));
        return in_array($role, ['company_admin', 'dpo', 'dpd', 'admin', 'superadmin'], true)
            || self::isGlobalAdmin($user);
    }

    // ═══════════════════════════════════════════════════════════
    // SCOPE PRINCIPAL
    // ═══════════════════════════════════════════════════════════

    /**
     * @param array $user  El usuario autenticado (viene de Auth::requireAuth)
     * @param Database $db Instancia de Database
     * @param array $opts  ['expandByAgents' => bool] (default: true)
     */
    public static function scope($user, $db, array $opts = []): array
    {
        $userId = (string)($user['_id'] ?? '');
        if ($userId === '') {
            // Sin _id: no podemos scoping. Devolvemos algo seguro.
            return self::globalScope($user, false);
        }

        $expand = ($opts['expandByAgents'] ?? true) ? '1' : '0';
        $cacheKey = $userId . '|' . $expand;

        if (isset(self::$cache[$cacheKey])) {
            self::$stats['hits']++;
            return self::$cache[$cacheKey];
        }
        self::$stats['misses']++;

        // ─── Superadmin / admin global: sin filtro ─────────────
        if (self::isGlobalAdmin($user)) {
            return self::$cache[$cacheKey] = self::globalScope($user, self::isSuperAdmin($user));
        }

        // ─── Usuario normal: leer registro fresco ──────────────
        $userRecord = $db->findOne('users', ['_id' => $userId]) ?: $user;

        $companyId = (string)($userRecord['companyId'] ?? $userId);
        if ($companyId === '') $companyId = $userId;

        $userIds = self::collectCompanyUserIds(
            $db,
            $companyId,
            $userId,
            $opts['expandByAgents'] ?? true
        );

        $role = strtolower((string)($userRecord['role'] ?? ''));

        return self::$cache[$cacheKey] = [
            'isSuperAdmin'   => false,
            'isGlobalAdmin'  => false,
            'isCompanyAdmin' => in_array($role, ['company_admin', 'dpo', 'dpd', 'admin'], true),
            'userIds'        => $userIds,
            'companyId'      => $companyId,
            'companyIds'     => [$companyId],
            'filter'         => ['userId' => ['$in' => $userIds]],
            'companyName'    => (string)(
                $userRecord['companyName']
                ?? $userRecord['email']
                ?? 'Mi empresa'
            ),
            'userRecord'     => $userRecord,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS PÚBLICOS
    // ═══════════════════════════════════════════════════════════

    /**
     * Filtro listo para colecciones con campo userId.
     * Devuelve [] para superadmin (ve todo).
     */
    public static function userFilter($user, $db): array
    {
        return self::scope($user, $db)['filter'];
    }

    /**
     * Filtro por campo companyId. Robusto: incluye string + ObjectId.
     * Devuelve [] para superadmin.
     */
    public static function companyFilter($user, $db, string $field = 'companyId'): array
    {
        $scope = self::scope($user, $db);
        if ($scope['isGlobalAdmin']) return [];

        $orList = [];
        foreach ($scope['companyIds'] as $cid) {
            foreach (self::idConditions($field, $cid) as $cond) {
                $orList[] = $cond;
            }
        }
        if (empty($orList)) {
            return [$field => ['$in' => []]]; // nunca matchea, seguro
        }
        return count($orList) === 1 ? $orList[0] : ['$or' => $orList];
    }

    /**
     * Genera condiciones $or que matchean un ID como string Y como ObjectId.
     * Útil cuando la colección no siempre guarda el mismo tipo.
     */
    public static function idConditions(string $field, $id): array
    {
        $idStr = (string)$id;
        if ($idStr === '') return [];

        $conds = [[$field => $idStr]];

        if (preg_match('/^[0-9a-fA-F]{24}$/', $idStr)
            && class_exists('MongoDB\\BSON\\ObjectId')) {
            try {
                $conds[] = [$field => new MongoDB\BSON\ObjectId($idStr)];
            } catch (\Throwable $e) {
                // ignorar: si no se puede construir el ObjectId, nos quedamos con string
            }
        }
        return $conds;
    }

    /**
     * Combina un filtro de negocio con el scope de empresa.
     * Ej: Tenant::applyTo(['status'=>'open'], $user, $db)
     */
    public static function applyTo(array $filter, $user, $db): array
    {
        $scope = self::scope($user, $db);
        if ($scope['isGlobalAdmin']) return $filter;
        return array_merge($filter, $scope['filter']);
    }

    /**
     * Verifica si un documento pertenece a la empresa del usuario.
     * Útil para validar antes de updateOne/deleteOne.
     */
    public static function canAccess($doc, $user, $db): bool
    {
        $scope = self::scope($user, $db);
        if ($scope['isGlobalAdmin']) return true;

        $docUser    = (string)($doc['userId']    ?? '');
        $docCompany = (string)($doc['companyId'] ?? '');

        if ($docUser !== '' && in_array($docUser, $scope['userIds'], true)) return true;
        if ($docCompany !== '' && in_array($docCompany, $scope['companyIds'], true)) return true;

        return false;
    }

    /**
     * Limpia la cache (útil tras actualizar el propio usuario).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Estadísticas de cache (debug).
     */
    public static function stats(): array
    {
        return self::$stats + ['cached_users' => count(self::$cache)];
    }

    // ═══════════════════════════════════════════════════════════
    // INTERNO
    // ═══════════════════════════════════════════════════════════

    private static function globalScope($user, bool $isSuper): array
    {
        return [
            'isSuperAdmin'   => $isSuper,
            'isGlobalAdmin'  => true,
            'isCompanyAdmin' => true,
            'userIds'        => [],
            'companyId'      => null,
            'companyIds'     => [],
            'filter'         => [],
            'companyName'    => 'Todas las empresas',
            'userRecord'     => $user,
        ];
    }

    /**
     * Recolecta todos los userIds que pertenecen a la empresa.
     * Tolerante a:
     *   - companyId guardado como string
     *   - companyId guardado como ObjectId
     *   - usuarios sin companyId pero con _id === companyId
     *   - items huérfanos asociados vía agentes (opcional)
     */
    private static function collectCompanyUserIds(
        $db,
        string $companyId,
        string $fallbackUserId,
        bool $expandByAgents
    ): array {
        $userIds = [$fallbackUserId => true];

        // ── 1. companyId como string ───────────────────────────
        try {
            $byString = $db->find('users', ['companyId' => $companyId], ['limit' => 2000]);
            foreach ($byString as $u) {
                $uid = (string)($u['_id'] ?? '');
                if ($uid !== '') $userIds[$uid] = true;
            }
        } catch (\Throwable $e) { /* ignorar */ }

        // ── 2. companyId como ObjectId ─────────────────────────
        if (preg_match('/^[0-9a-fA-F]{24}$/', $companyId)
            && class_exists('MongoDB\\BSON\\ObjectId')) {
            try {
                $objId = new MongoDB\BSON\ObjectId($companyId);
                $byObj = $db->find('users', ['companyId' => $objId], ['limit' => 2000]);
                foreach ($byObj as $u) {
                    $uid = (string)($u['_id'] ?? '');
                    if ($uid !== '') $userIds[$uid] = true;
                }
            } catch (\Throwable $e) { /* ignorar */ }
        }

        // ── 3. Usuarios cuya _id es exactamente companyId ──────
        try {
            $root = $db->find('users', ['_id' => $companyId], ['limit' => 10]);
            foreach ($root as $u) {
                $uid = (string)($u['_id'] ?? '');
                if ($uid !== '') $userIds[$uid] = true;
            }
        } catch (\Throwable $e) { /* ignorar */ }

        // ── 4. (opt-in) Propagar por agentes ───────────────────
        // Cubre el caso de migraciones donde un agente fue registrado
        // con un userId distinto al de la empresa actual.
        if ($expandByAgents) {
            try {
                $userIdsArr = array_keys($userIds);
                $agents = $db->find('agents', ['userId' => ['$in' => $userIdsArr]], ['limit' => 500]);
                $agentIds = array_values(array_filter(array_map(
                    fn($a) => (string)($a['agentId'] ?? ''),
                    $agents
                )));
                if (!empty($agentIds)) {
                    foreach (['compliance_inventory', 'compliance_files', 'file_audit_logs'] as $col) {
                        $rows = $db->find(
                            $col,
                            ['agentId' => ['$in' => $agentIds]],
                            ['limit' => 2000]
                        );
                        foreach ($rows as $r) {
                            $uid = (string)($r['userId'] ?? '');
                            if ($uid !== '') $userIds[$uid] = true;
                        }
                    }
                }
            } catch (\Throwable $e) { /* ignorar */ }
        }

        return array_keys($userIds);
    }
}

} // if (!class_exists('Tenant'))