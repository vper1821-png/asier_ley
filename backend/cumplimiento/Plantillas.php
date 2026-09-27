<?php
namespace Cumplimiento;

class Plantillas
{
    /** POST /api/compliance/packs/{id}/apply-to-agents */
    public static function aplicarPack()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $cuerpo = \get_body();

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        preg_match('#/packs/([^/]+)/apply-to-agents#', $uri, $m);
        $packId = $m[1] ?? '';
        if (!$packId) \json_error('packId requerido');

        $agenteIds  = $cuerpo['agentIds'] ?? [];
        $reaplicar  = !empty($cuerpo['reapplyExisting']);
        if (!is_array($agenteIds) || empty($agenteIds)) \json_error('agentIds requerido (array)');

        $pack = $bd->findOne('compliance_packs', ['_id' => $packId, 'userId' => $usuario['_id']]);
        if (!$pack || (array_key_exists('active', $pack) && $pack['active'] === false)) {
            \json_error('pack no encontrado', 404);
        }

        $plantillasTodas = $bd->find('compliance_templates', ['packId' => $packId]);
        $plantillas = array_values(array_filter($plantillasTodas, function ($t) {
            return !array_key_exists('active', $t) || $t['active'] !== false;
        }));
        $plantillaIds = array_map(fn($t) => (string)$t['_id'], $plantillas);
        if (empty($plantillaIds)) \json_error('el pack no tiene plantillas activas', 400);

        // Diagnóstico de plantillas incompletas
        $plantillasIncompletas = [];
        foreach ($plantillas as $t) {
            $d = $t['defaults'] ?? [];
            $faltantes = [];
            if (empty($d['purpose']))    $faltantes[] = 'purpose';
            if (empty($d['legalBasis'])) $faltantes[] = 'legalBasis';
            if ($faltantes) {
                $plantillasIncompletas[] = [
                    'templateId'   => (string)$t['_id'],
                    'templateName' => $t['name'] ?? '',
                    'missing'      => $faltantes,
                ];
            }
        }

        $ahora = date('c');
        $emailAsignador = $usuario['email'] ?? '';
        $asignados = 0; $reaplicados = 0; $saltados = 0;
        $detalles = [];

        foreach ($agenteIds as $agenteId) {
            $agente = $bd->findOne('agents', ['agentId' => $agenteId, 'userId' => $usuario['_id']]);
            if (!$agente) {
                $detalles[] = ['agentId' => $agenteId, 'status' => 'not_found'];
                continue;
            }

            $bd->updateOne('agents', ['_id' => $agente['_id']], [
                'packId'              => $packId,
                'templateIds'         => $plantillaIds,
                'packAssignedAt'      => $ahora,
                'packAssignedBy'      => (string)$usuario['_id'],
                'packAssignedByEmail' => $emailAsignador,
            ]);
            $asignados++;

            if ($reaplicar) {
                $items = $bd->find('compliance_inventory', [
                    'userId'  => $usuario['_id'],
                    'agentId' => $agenteId,
                ]);
                $reap = 0; $skip = 0;

                foreach ($items as $it) {
                    if (isset($it['needsReview']) && $it['needsReview'] === false) continue;
                    $resuelto = self::resolverPlantillaParaItem($usuario['_id'], $agenteId, $it, $plantillas);
                    if (!$resuelto) { $skip++; continue; }

                    $cambios = Inventario::aplicarDefaults($it, $resuelto['defaults']);
                    if (!empty($cambios)) {
                        $cambios['templateApplied']   = $resuelto['templateId'];
                        $cambios['templateName']      = $resuelto['templateName'];
                        $cambios['templateMode']      = 'manual_bulk';
                        $cambios['templateAppliedAt'] = $ahora;
                        $cambios['needsReview']       = !empty($resuelto['defaults']['purpose'])
                                                     && !empty($resuelto['defaults']['legalBasis']) ? false : true;
                        $cambios['updatedAt'] = $ahora;
                        $bd->updateOne('compliance_inventory', ['_id' => $it['_id']], $cambios);
                        $reap++;
                    } else {
                        $skip++;
                    }
                }
                $reaplicados  += $reap;
                $saltados     += $skip;
                $detalles[] = ['agentId' => $agenteId, 'status' => 'ok', 'reapplied' => $reap, 'skipped' => $skip];
            } else {
                $detalles[] = ['agentId' => $agenteId, 'status' => 'ok', 'reapplied' => 0];
            }
        }

        $bd->updateOne('compliance_packs', ['_id' => $packId], ['updatedAt' => $ahora]);

        \audit_log('pack_applied_to_agents', [
            'packId'      => $packId,
            'packName'    => $pack['name'] ?? '',
            'agents'      => $asignados,
            'reapplied'   => $reaplicados,
            'skipped'     => $saltados,
            'templateIds' => count($plantillaIds),
        ], $usuario['_id']);

        \json_response([
            'success'                => true,
            'assigned'               => $asignados,
            'reapplied'              => $reaplicados,
            'skipped'                => $saltados,
            'templates'              => count($plantillaIds),
            'templatesMissingFields' => $plantillasIncompletas,
            'details'                => $detalles,
        ]);
    }

    /** POST /api/compliance/packs/preview-apply */
    public static function previsualizarPack()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $cuerpo = \get_body();

        $packId    = $cuerpo['packId']    ?? '';
        $agenteIds = $cuerpo['agentIds']  ?? [];
        if (!$packId || empty($agenteIds)) \json_error('packId y agentIds requeridos');

        $pack = $bd->findOne('compliance_packs', ['_id' => $packId, 'userId' => $usuario['_id']]);
        if (!$pack || (array_key_exists('active', $pack) && $pack['active'] === false)) {
            \json_error('pack no encontrado', 404);
        }

        $plantillasTodas = $bd->find('compliance_templates', ['packId' => $packId]);
        $plantillas = array_values(array_filter($plantillasTodas, function ($t) {
            return !array_key_exists('active', $t) || $t['active'] !== false;
        }));

        $preview = []; $cambiaran = 0; $saltados = 0; $porPlantilla = [];

        foreach ($agenteIds as $agenteId) {
            $items = $bd->find('compliance_inventory', [
                'userId'  => $usuario['_id'],
                'agentId' => $agenteId,
            ]);
            foreach ($items as $it) {
                if (isset($it['needsReview']) && $it['needsReview'] === false) { $saltados++; continue; }
                $resuelto = self::resolverPlantillaParaItem($usuario['_id'], $agenteId, $it, $plantillas);
                if (!$resuelto) continue;

                $cambios = Inventario::aplicarDefaults($it, $resuelto['defaults']);
                if (!empty($cambios)) {
                    $cambiaran++;
                    $tplId = $resuelto['templateId'];
                    if (!isset($porPlantilla[$tplId])) {
                        $porPlantilla[$tplId] = [
                            'templateId'   => $tplId,
                            'templateName' => $resuelto['templateName'],
                            'items'        => 0,
                        ];
                    }
                    $porPlantilla[$tplId]['items']++;
                    if (count($preview) < 20) {
                        $preview[] = [
                            'inventoryId' => (string)$it['_id'],
                            'name'        => $it['name'] ?? '',
                            'template'    => $resuelto['templateName'],
                            'changes'     => array_keys($cambios),
                        ];
                    }
                }
            }
        }

        \json_response([
            'success'   => true,
            'willChange'=> $cambiaran,
            'skipped'   => $saltados,
            'preview'   => $preview,
            'byTemplate'=> array_values($porPlantilla),
        ]);
    }

    /** POST /api/compliance/agents/{id}/unassign-pack */
    public static function desasignarPack()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();

        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        preg_match('#/agents/([^/]+)/unassign-pack#', $uri, $m);
        $agenteId = $m[1] ?? '';
        if (!$agenteId) \json_error('agentId requerido');

        $agente = $bd->findOne('agents', ['agentId' => $agenteId, 'userId' => $usuario['_id']]);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd->updateOne('agents', ['_id' => $agente['_id']], [
            'packId'              => null,
            'templateIds'         => [],
            'packAssignedAt'      => null,
            'packAssignedBy'      => null,
            'packAssignedByEmail' => null,
        ]);

        \audit_log('pack_unassigned', ['agentId' => $agenteId], $usuario['_id']);
        \json_response(['success' => true]);
    }

    /** POST /api/compliance/inventory/cluster */
    public static function agruparInventario()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $cuerpo = \get_body();

        $agenteIds = $cuerpo['agentIds'] ?? [];
        $dryRun    = !empty($cuerpo['dryRun']);

        $filtro = ['userId' => $usuario['_id']];
        if (!empty($agenteIds)) $filtro['agentId'] = ['$in' => $agenteIds];

        $items = $bd->find('compliance_inventory', $filtro);
        $grupos = [];

        foreach ($items as $it) {
            $topPath = '';
            $primerSource = $it['sources'][0] ?? null;
            $path = $primerSource['path'] ?? ($it['path'] ?? '');
            if ($path !== '') {
                $partes = preg_split('#[\\\\/]+#', $path);
                $topPath = $partes[1] ?? '';
            }
            $sig = md5(($it['agentId'] ?? '') . '|' . strtolower($topPath) . '|' . ($it['templateApplied'] ?? ''));
            if (!isset($grupos[$sig])) {
                $grupos[$sig] = [
                    'signature'    => $sig,
                    'agentId'      => $it['agentId'] ?? '',
                    'topPath'      => $topPath,
                    'templateId'   => $it['templateApplied'] ?? null,
                    'templateName' => $it['templateName'] ?? '',
                    'items'        => [],
                ];
            }
            $grupos[$sig]['items'][] = $it;
        }

        $propuestas = [];
        foreach ($grupos as $sig => $g) {
            if (count($g['items']) < 2) continue;
            $canonical = $g['items'][0];
            $totalSources = 0; $totalRecords = 0; $todasExts = []; $todasCats = [];

            foreach ($g['items'] as $it) {
                $sources = $it['sources'] ?? [];
                if (empty($sources) && !empty($it['path'])) {
                    $sources = [[
                        'fileId'     => $it['sourceId'] ?? '',
                        'path'       => $it['path'],
                        'records'    => (int)($it['records'] ?? 0),
                        'detectedAt' => $it['createdAt'] ?? date('c'),
                    ]];
                }
                $totalSources += count($sources);
                $totalRecords += (int)($it['recordCount'] ?? $it['records'] ?? 0);
                $ext = strtolower((string)($it['extension'] ?? ''));
                if ($ext !== '' && !in_array($ext, $todasExts)) $todasExts[] = $ext;

                $cats = $it['dataCategories'] ?? '';
                if (is_array($cats)) $todasCats = array_merge($todasCats, $cats);
                elseif (is_string($cats) && $cats !== '') {
                    $todasCats = array_merge($todasCats, array_map('trim', explode(',', $cats)));
                }
            }

            $propuestas[] = [
                'signature'      => $sig,
                'agentId'        => $g['agentId'],
                'topPath'        => $g['topPath'],
                'templateId'     => $g['templateId'],
                'templateName'   => $g['templateName'],
                'itemsToMerge'   => count($g['items']),
                'itemIds'        => array_map(fn($i) => (string)$i['_id'], $g['items']),
                'canonicalId'    => (string)$canonical['_id'],
                'mergedSources'  => $totalSources,
                'mergedRecords'  => $totalRecords,
                'mergedCats'     => array_values(array_unique(array_filter($todasCats))),
                'mergedExts'     => $todasExts,
            ];
        }

        if ($dryRun) {
            \json_response([
                'success'    => true,
                'dryRun'     => true,
                'proposals'  => $propuestas,
                'groups'     => count($propuestas),
                'totalItems' => count($items),
            ]);
        }

        $fusionados = 0; $eliminados = 0;
        foreach ($propuestas as $p) {
            $canonical = $bd->findOne('compliance_inventory', ['_id' => $p['canonicalId']]);
            if (!$canonical) continue;

            $allSources = $canonical['sources'] ?? [];
            foreach ($p['itemIds'] as $id) {
                if ($id === $p['canonicalId']) continue;
                $otro = $bd->findOne('compliance_inventory', ['_id' => $id]);
                if (!$otro) continue;
                foreach (($otro['sources'] ?? []) as $s) $allSources[] = $s;
                if (empty($otro['sources']) && !empty($otro['path'])) {
                    $allSources[] = [
                        'fileId'     => $otro['sourceId'] ?? '',
                        'path'       => $otro['path'],
                        'records'    => (int)($otro['records'] ?? 0),
                        'detectedAt' => $otro['createdAt'] ?? date('c'),
                    ];
                }
            }

            $bd->updateOne('compliance_inventory', ['_id' => $p['canonicalId']], [
                'sources'        => $allSources,
                'fileCount'      => count($allSources),
                'recordCount'    => $p['mergedRecords'],
                'dataCategories' => implode(', ', $p['mergedCats']),
                'fileExtensions' => $p['mergedExts'],
                'lastSeenAt'     => date('c'),
                'updatedAt'      => date('c'),
            ]);

            foreach ($p['itemIds'] as $id) {
                if ($id === $p['canonicalId']) continue;
                $bd->deleteOne('compliance_inventory', ['_id' => $id]);
                $eliminados++;
            }
            $fusionados++;
        }

        \audit_log('cluster_inventory_applied', [
            'groups'  => count($propuestas),
            'merged'  => $fusionados,
            'deleted' => $eliminados,
        ], $usuario['_id']);

        \json_response([
            'success'  => true,
            'merged'   => $fusionados,
            'deleted'  => $eliminados,
            'groups'   => count($propuestas),
        ]);
    }

    /** Resuelve una plantilla para un item concreto del inventario. */
    public static function resolverPlantillaParaItem($userId, $agentId, $item, array $plantillas)
    {
        $path = $item['path'] ?? '';
        $hostname = $item['hostname'] ?? '';
        $extension = strtolower((string)($item['extension'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $categorias = [];
        if (!empty($item['dataCategories'])) {
            $categorias = is_array($item['dataCategories'])
                ? $item['dataCategories']
                : array_map('trim', explode(',', $item['dataCategories']));
        }

        $ctx = [
            'path' => $path, 'hostname' => $hostname,
            'extension' => $extension, 'categories' => $categorias,
            'sensitive' => !empty($item['sensitive']),
        ];

        usort($plantillas, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        foreach ($plantillas as $tpl) {
            if (!empty($tpl['isFallback'])) continue;
            if (self::coincideReglas($tpl, $ctx)) {
                return [
                    'templateId'   => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '',
                    'defaults'     => $tpl['defaults'] ?? [],
                ];
            }
        }
        foreach ($plantillas as $tpl) {
            if (!empty($tpl['isFallback'])) {
                return [
                    'templateId'   => (string)$tpl['_id'],
                    'templateName' => $tpl['name'] ?? '',
                    'defaults'     => $tpl['defaults'] ?? [],
                ];
            }
        }
        return null;
    }

    /** Evalúa las reglas de una plantilla contra un contexto. */
    public static function coincideReglas($tpl, array $ctx): bool
    {
        $reglas = $tpl['matchRules'] ?? [];
        if (empty($reglas)) return false;
        $logica = strtoupper($tpl['matchLogic'] ?? 'OR');

        $ctxPath     = \stripAccentsForMatch(\normalizePathForMatch($ctx['path'] ?? ''));
        $ctxHostname = \stripAccentsForMatch(strtolower((string)($ctx['hostname'] ?? '')));
        $ctxExt      = strtolower(ltrim((string)($ctx['extension'] ?? ''), '.'));
        $ctxCats     = array_map(fn($c) => strtolower(\stripAccentsForMatch($c)), $ctx['categories'] ?? []);

        $resultados = [];
        foreach ($reglas as $r) {
            $tipo  = $r['type']  ?? '';
            $valor = (string)($r['value'] ?? '');
            $ok = false;

            if ($tipo === 'path') {
                $pat = \stripAccentsForMatch(\normalizePathForMatch($valor));
                $ok = fnmatch($pat, $ctxPath, FNM_CASEFOLD)
                   || fnmatch($pat, '/' . ltrim($ctxPath, '/'), FNM_CASEFOLD);
            } elseif ($tipo === 'hostname') {
                $ok = fnmatch(\stripAccentsForMatch(strtolower($valor)), $ctxHostname, FNM_CASEFOLD);
            } elseif ($tipo === 'extension') {
                $exts = array_map(fn($e) => ltrim(strtolower(trim($e)), '.'), explode(',', $valor));
                $ok = in_array($ctxExt, $exts, true);
            } elseif ($tipo === 'category') {
                $quiere = array_map(fn($c) => strtolower(\stripAccentsForMatch(trim($c))), explode(',', $valor));
                $ok = !empty(array_intersect($quiere, $ctxCats));
            }
            $resultados[] = $ok;
        }

        return $logica === 'AND'
            ? !in_array(false, $resultados, true)
            :  in_array(true,  $resultados, true);
    }
}