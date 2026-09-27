<?php
namespace Cumplimiento;

class Inventario
{
    public static function manejar($usuario, $bd, $userIds, $esSuper, string $metodo, string $id, string $accion, array $cuerpo)
    {
        // POST /api/compliance/inventory/{id}/apply-template
        if ($id && $accion === 'apply-template' && $metodo === 'POST') {
            return self::aplicarPlantilla($usuario, $bd, $userIds, $esSuper, $id, $cuerpo);
        }

        // POST /api/compliance/inventory/fix-needsreview
        if ($id === 'fix-needsreview' && $metodo === 'POST') {
            return self::arreglarNeedsReview($usuario, $bd, $userIds, $esSuper);
        }

        // POST /api/compliance/inventory/diagnose
        if ($id === 'diagnose' && $metodo === 'POST') {
            return self::diagnosticar($usuario, $bd, $userIds, $esSuper, $cuerpo);
        }

        // No es un comando especial: devolver control al enrutador genérico
        return false;
    }

    private static function aplicarPlantilla($usuario, $bd, $userIds, $esSuper, string $itemId, array $cuerpo)
    {
        $filtro = ['_id' => $itemId];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $item = $bd->findOne('compliance_inventory', $filtro);
        if (!$item) \json_error('item no encontrado', 404);

        $plantillaId = $cuerpo['templateId'] ?? '';
        if (!$plantillaId) \json_error('templateId requerido');

        $plantilla = $bd->findOne('compliance_templates', [
            '_id' => $plantillaId,
            'userId' => $usuario['_id'],
        ]);
        if (!$plantilla) \json_error('plantilla no encontrada', 404);
        if (array_key_exists('active', $plantilla) && $plantilla['active'] === false) {
            \json_error('plantilla inactiva', 400);
        }

        $defaults = $plantilla['defaults'] ?? [];
        $forzar   = !empty($cuerpo['force']);

        $actualizaciones = $forzar ? [] : self::aplicarDefaults($item, $defaults);
        if ($forzar) {
            foreach ($defaults as $k => $v) {
                if ($v === '' || $v === null) continue;
                if (is_array($v) && empty($v)) continue;
                $actualizaciones[$k] = $v;
            }
        }

        $actualizaciones['templateApplied']   = (string)$plantilla['_id'];
        $actualizaciones['templateName']      = $plantilla['name'] ?? '';
        $actualizaciones['templateMode']      = 'manual_single';
        $actualizaciones['templateAppliedAt'] = date('c');
        $actualizaciones['needsReview']       = !empty($defaults['purpose']) && !empty($defaults['legalBasis']) ? false : true;
        $actualizaciones['updatedAt']         = date('c');
        $actualizaciones['templateDebug']     = [
            'resolvedMode'         => 'manual_single',
            'resolvedTemplateId'   => (string)$plantilla['_id'],
            'resolvedTemplateName' => $plantilla['name'] ?? '',
            'force'                => $forzar,
            'at'                   => date('c'),
        ];

        $bd->updateOne('compliance_inventory', ['_id' => $itemId], $actualizaciones);

        \audit_log('inventory_template_applied', [
            'itemId'     => $itemId,
            'templateId' => $plantillaId,
            'force'      => $forzar,
        ], $usuario['_id']);

        \json_response([
            'success'  => true,
            'applied'  => $actualizaciones,
            'template' => ['id' => (string)$plantilla['_id'], 'name' => $plantilla['name'] ?? ''],
        ]);
    }

    private static function arreglarNeedsReview($usuario, $bd, $userIds, $esSuper)
    {
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];
        $items = $bd->find('compliance_inventory', $filtro, ['limit' => 5000]);

        $arreglados = 0;
        $detalles = [];

        foreach ($items as $it) {
            $tienePurpose    = !empty($it['purpose']);
            $tieneLegalBasis = !empty($it['legalBasis']) && $it['legalBasis'] !== 'Pendiente de definir';
            $tieneCategorias = !empty($it['dataCategories']);
            $tieneDestinos   = !empty($it['recipients']);

            $deberiaEstarCompleto = $tienePurpose && $tieneLegalBasis && $tieneCategorias && $tieneDestinos;
            $necesitaRevisionActualmente = !empty($it['needsReview']);

            if ($deberiaEstarCompleto && $necesitaRevisionActualmente) {
                $bd->updateOne('compliance_inventory', ['_id' => $it['_id']], [
                    'needsReview' => false,
                    'reviewedAt'  => date('c'),
                    'reviewedBy'  => 'auto-fix-migration',
                    'updatedAt'   => date('c'),
                ]);
                $arreglados++;
                if (count($detalles) < 20) {
                    $detalles[] = ['id' => (string)$it['_id'], 'name' => $it['name'] ?? ''];
                }
            }
        }

        \audit_log('inventory_needsreview_fixed', ['count' => $arreglados, 'scanned' => count($items)], $usuario['_id']);
        \json_response([
            'success' => true,
            'scanned' => count($items),
            'fixed'   => $arreglados,
            'details' => $detalles,
        ]);
    }

    private static function diagnosticar($usuario, $bd, $userIds, $esSuper, array $cuerpo)
    {
        $itemId = $cuerpo['itemId'] ?? '';
        if (!$itemId) \json_error('itemId requerido');

        $filtro = ['_id' => $itemId];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $item = $bd->findOne('compliance_inventory', $filtro);
        if (!$item) \json_error('item no encontrado', 404);

        $agentId = $item['agentId'] ?? '';
        $agente = $bd->findOne('agents', ['agentId' => $agentId, 'userId' => $usuario['_id']]);
        $agenteTplIds = $agente['templateIds'] ?? [];

        $ctx = [
            'path'       => $item['path'] ?? '',
            'hostname'   => $item['hostname'] ?? '',
            'extension'  => $item['extension'] ?? '',
            'categories' => is_array($item['dataCategories'] ?? null)
                ? $item['dataCategories']
                : array_filter(array_map('trim', explode(',', (string)($item['dataCategories'] ?? '')))),
            'sensitive'  => !empty($item['sensitive']),
        ];

        $plantillas = [];
        foreach ($agenteTplIds as $tid) {
            $t = $bd->findOne('compliance_templates', ['_id' => $tid]);
            if ($t) $plantillas[] = $t;
        }
        usort($plantillas, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        $evaluacion = [];
        foreach ($plantillas as $tpl) {
            $reglas = $tpl['matchRules'] ?? [];
            $resultadosReglas = [];
            foreach ($reglas as $r) {
                $tipo  = $r['type']  ?? '';
                $valor = (string)($r['value'] ?? '');
                $coincide = false;

                $ctxPath     = \stripAccentsForMatch(\normalizePathForMatch($ctx['path']));
                $ctxHostname = \stripAccentsForMatch(strtolower($ctx['hostname']));
                $ctxExt      = strtolower(ltrim($ctx['extension'], '.'));
                $ctxCats     = array_map(fn($c) => strtolower(\stripAccentsForMatch($c)), $ctx['categories']);

                if ($tipo === 'path') {
                    $pat = \stripAccentsForMatch(\normalizePathForMatch($valor));
                    $coincide = fnmatch($pat, $ctxPath, FNM_CASEFOLD)
                             || fnmatch($pat, '/' . ltrim($ctxPath, '/'), FNM_CASEFOLD);
                } elseif ($tipo === 'hostname') {
                    $coincide = fnmatch(\stripAccentsForMatch(strtolower($valor)), $ctxHostname, FNM_CASEFOLD);
                } elseif ($tipo === 'extension') {
                    $exts = array_map(fn($e) => ltrim(strtolower(trim($e)), '.'), explode(',', $valor));
                    $coincide = in_array($ctxExt, $exts, true);
                } elseif ($tipo === 'category') {
                    $quiere = array_map(fn($c) => strtolower(\stripAccentsForMatch(trim($c))), explode(',', $valor));
                    $coincide = !empty(array_intersect($quiere, $ctxCats));
                }

                $resultadosReglas[] = ['type' => $tipo, 'value' => $valor, 'matched' => $coincide];
            }

            $logica = strtoupper($tpl['matchLogic'] ?? 'OR');
            $coincideTodo = $logica === 'AND'
                ? !in_array(false, array_column($resultadosReglas, 'matched'), true)
                : in_array(true,  array_column($resultadosReglas, 'matched'), true);

            $evaluacion[] = [
                'templateId'    => (string)$tpl['_id'],
                'templateName'  => $tpl['name'] ?? '',
                'priority'      => $tpl['priority'] ?? 0,
                'isFallback'    => !empty($tpl['isFallback']),
                'active'        => !array_key_exists('active', $tpl) || $tpl['active'] !== false,
                'matchLogic'    => $logica,
                'rules'         => $resultadosReglas,
                'matchedAll'    => $coincideTodo,
                'hasPurpose'    => !empty($tpl['defaults']['purpose']),
                'hasLegalBasis' => !empty($tpl['defaults']['legalBasis']),
            ];
        }

        \json_response([
            'success'           => true,
            'item'              => [
                'id'                  => $itemId,
                'name'                => $item['name'] ?? '',
                'path'                => $item['path'] ?? '',
                'currentTemplateId'   => $item['templateApplied'] ?? null,
                'currentTemplateName' => $item['templateName'] ?? null,
                'needsReview'         => !empty($item['needsReview']),
                'templateDebug'       => $item['templateDebug'] ?? null,
            ],
            'agentTemplateIds'  => $agenteTplIds,
            'context'           => $ctx,
            'evaluation'        => $evaluacion,
        ]);
    }

    /** Aplica defaults sin sobrescribir valores existentes. */
    public static function aplicarDefaults(array $item, array $defaults): array
    {
        $actualizaciones = [];
        foreach ($defaults as $k => $v) {
            if ($v === '' || $v === null) continue;
            if (is_array($v) && empty($v)) continue;

            $actual = $item[$k] ?? null;
            $esVacio = $actual === null
                    || $actual === ''
                    || $actual === []
                    || $actual === 'Pendiente de definir';

            if ($k === 'purpose'    && is_string($actual) && trim($actual) === '') $esVacio = true;
            if ($k === 'legalBasis' && is_string($actual) && trim($actual) === '') $esVacio = true;

            if ($esVacio) $actualizaciones[$k] = $v;
        }
        return $actualizaciones;
    }
}