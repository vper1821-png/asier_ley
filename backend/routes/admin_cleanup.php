<?php
// backend/routes/admin_cleanup.php
// Suite de herramientas de limpieza y diagnóstico de datos por empresa.
// - Superadmin: puede limpiar CUALQUIER empresa
// - Admin/company_admin: solo SU empresa
// Todas las operaciones quedan en `audit_logs` para trazabilidad.

// ─────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────

function _cleanup_resolveScope($user, $db, $targetCompanyId = null) {
    $isSuperAdmin = !empty($user['isAdmin']) && ($user['role'] ?? '') === 'superadmin';
    $isGlobalAdmin = !empty($user['isAdmin']) || in_array($user['role'] ?? '', ['admin', 'superadmin'], true);

    if ($isGlobalAdmin) {
        return [
            'ok' => true,
            'isSuperAdmin' => $isSuperAdmin,
            'companyId' => $targetCompanyId,
            'userIds' => $targetCompanyId ? _cleanup_getCompanyUserIds($db, $targetCompanyId) : null,
        ];
    }

    $record = $db->findOne('users', ['_id' => $user['_id']]);
    if (!$record) return ['ok' => false, 'error' => 'Usuario no encontrado'];

    $companyId = (string)($record['companyId'] ?? $user['_id']);

    if ($targetCompanyId && $targetCompanyId !== $companyId) {
        return ['ok' => false, 'error' => 'Solo puedes limpiar tu propia empresa'];
    }

    return [
        'ok' => true,
        'isSuperAdmin' => false,
        'companyId' => $companyId,
        'userIds' => _cleanup_getCompanyUserIds($db, $companyId),
    ];
}

function _cleanup_getCompanyUserIds($db, $companyId) {
    $users = $db->find('users', ['companyId' => $companyId]);
    $ids = array_map('strval', array_column($users, '_id'));
    if (empty($ids)) $ids = [(string)$companyId];
    return $ids;
}

function _cleanup_userFilter($scope) {
    if ($scope['userIds'] === null) return [];
    return ['userId' => ['$in' => $scope['userIds']]];
}

function _cleanup_requireAccess() {
    $user = Auth::requireAuth();
    $role = strtolower($user['role'] ?? '');
    $isAdmin = !empty($user['isAdmin']) || in_array($role, ['admin','superadmin','company_admin'], true);
    if (!$isAdmin) json_error('solo administradores pueden ejecutar limpiezas', 403);
    return $user;
}

// ─────────────────────────────────────────────────────────────────────
// 1. DIAGNÓSTICO
// ─────────────────────────────────────────────────────────────────────
function cleanupDiagnose() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? $_GET['companyId'] ?? null;

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $filter = _cleanup_userFilter($scope);

    $files = $db->find('compliance_files', $filter);
    $filesByAgentPath = [];
    foreach ($files as $f) {
        if (($f['sourceType'] ?? '') !== 'agent') continue;
        $key = ($f['agentId'] ?? '?') . '|' . ($f['path'] ?? '?');
        $filesByAgentPath[$key] = ($filesByAgentPath[$key] ?? 0) + 1;
    }
    $dupFiles = 0;
    $groupsFiles = 0;
    foreach ($filesByAgentPath as $count) {
        if ($count > 1) { $groupsFiles++; $dupFiles += ($count - 1); }
    }

    $inventory = $db->find('compliance_inventory', $filter);
    $invBySourceId = [];
    foreach ($inventory as $i) {
        $sid = $i['sourceId'] ?? null;
        if (!$sid) continue;
        $key = (string)$sid;
        $invBySourceId[$key] = ($invBySourceId[$key] ?? 0) + 1;
    }
    $dupInv = 0;
    $groupsInv = 0;
    foreach ($invBySourceId as $count) {
        if ($count > 1) { $groupsInv++; $dupInv += ($count - 1); }
    }

    $fileIds = [];
    foreach ($files as $f) $fileIds[(string)$f['_id']] = true;

    $orphanInv = 0;
    $invNoiseCats = 0;
    $invMissingAgent = 0;
    foreach ($inventory as $i) {
        $sid = (string)($i['sourceId'] ?? '');
        if ($sid && !isset($fileIds[$sid])) $orphanInv++;

        $cats = (string)($i['dataCategories'] ?? '');
        if (preg_match('/line_\d+/', $cats)) $invNoiseCats++;

        if (empty($i['agentId'])) $invMissingAgent++;
    }

    $invSourceIds = [];
    foreach ($inventory as $i) {
        $sid = (string)($i['sourceId'] ?? '');
        if ($sid) $invSourceIds[$sid] = true;
    }
    $filesWithPiiNoInv = 0;
    foreach ($files as $f) {
        $hasPii = !empty($f['analysisResult']['sensitive']) ||
                  !empty($f['analysisResult']['categories']) ||
                  ($f['status'] ?? '') === 'analyzed';
        if (!$hasPii) continue;
        if (!isset($invSourceIds[(string)$f['_id']])) $filesWithPiiNoInv++;
    }

    $auditLogs = $db->count('file_audit_logs', $filter);
    $generalLogs = $db->count('audit_logs', $filter);

    json_response([
        'success' => true,
        'scope' => [
            'companyId' => $scope['companyId'],
            'isSuperAdmin' => $scope['isSuperAdmin'],
            'companies_count' => $scope['companyId'] ? 1 : 'all',
        ],
        'files' => [
            'total' => count($files),
            'duplicate_groups' => $groupsFiles,
            'duplicates_extra' => $dupFiles,
            'with_pii_without_inventory' => $filesWithPiiNoInv,
        ],
        'inventory' => [
            'total' => count($inventory),
            'duplicate_groups' => $groupsInv,
            'duplicates_extra' => $dupInv,
            'orphans' => $orphanInv,
            'with_noisy_categories' => $invNoiseCats,
            'missing_agentId' => $invMissingAgent,
        ],
        'logs' => [
            'file_audit_logs' => $auditLogs,
            'audit_logs' => $generalLogs,
        ],
        'health' => [
            'score' => _cleanup_healthScore($dupFiles, $dupInv, $orphanInv, $invNoiseCats, $filesWithPiiNoInv),
            'issues' => _cleanup_listIssues($dupFiles, $dupInv, $orphanInv, $invNoiseCats, $filesWithPiiNoInv),
        ],
    ]);
}

function _cleanup_healthScore($dupFiles, $dupInv, $orphanInv, $noiseCats, $filesNoInv) {
    $penalties = 0;
    $penalties += min(30, $dupFiles * 1.5);
    $penalties += min(20, $dupInv * 1.5);
    $penalties += min(20, $orphanInv * 2);
    $penalties += min(15, $noiseCats * 0.5);
    $penalties += min(15, $filesNoInv * 2);
    return max(0, round(100 - $penalties));
}

function _cleanup_listIssues($dupFiles, $dupInv, $orphanInv, $noiseCats, $filesNoInv) {
    $issues = [];
    if ($dupFiles > 0) $issues[] = "$dupFiles archivos duplicados (mismo agentId+path)";
    if ($dupInv > 0) $issues[] = "$dupInv items de inventario duplicados";
    if ($orphanInv > 0) $issues[] = "$orphanInv items de inventario huérfanos (sin archivo)";
    if ($noiseCats > 0) $issues[] = "$noiseCats items con categorías ruidosas (line_N)";
    if ($filesNoInv > 0) $issues[] = "$filesNoInv archivos con PII sin item de inventario";
    return $issues;
}

// ─────────────────────────────────────────────────────────────────────
// 2. PREVIEW (dry run)
// ─────────────────────────────────────────────────────────────────────
function cleanupPreview() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? $_GET['companyId'] ?? null;
    $operation = $body['operation'] ?? $_GET['operation'] ?? 'all';

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $filter = _cleanup_userFilter($scope);
    $result = [];

    if (in_array($operation, ['all', 'dup_files'], true)) {
        $files = $db->find('compliance_files', $filter);
        $groups = [];
        foreach ($files as $f) {
            if (($f['sourceType'] ?? '') !== 'agent') continue;
            $key = ($f['agentId'] ?? '?') . '|' . ($f['path'] ?? '?');
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $f;
        }

        $toDelete = [];
        $toKeep = [];
        foreach ($groups as $key => $items) {
            if (count($items) <= 1) continue;
            usort($items, fn($a, $b) =>
                strcmp($b['updatedAt'] ?? $b['createdAt'] ?? '', $a['updatedAt'] ?? $a['createdAt'] ?? '')
            );
            $keep = $items[0];
            $toKeep[] = ['_id' => (string)$keep['_id'], 'path' => $keep['path'], 'updatedAt' => $keep['updatedAt'] ?? $keep['createdAt']];
            foreach (array_slice($items, 1) as $dup) {
                $toDelete[] = [
                    '_id' => (string)$dup['_id'],
                    'path' => $dup['path'] ?? '',
                    'hash' => substr($dup['hash'] ?? '', 0, 12),
                    'createdAt' => $dup['createdAt'] ?? '',
                    'agentId' => $dup['agentId'] ?? '?',
                ];
            }
        }
        $result['dup_files'] = [
            'will_keep' => count($toKeep),
            'will_delete' => count($toDelete),
            'preview' => array_slice($toDelete, 0, 20),
        ];
    }

    if (in_array($operation, ['all', 'dup_inventory'], true)) {
        $inventory = $db->find('compliance_inventory', $filter);
        $groups = [];
        foreach ($inventory as $i) {
            $sid = (string)($i['sourceId'] ?? '');
            if (!$sid) continue;
            if (!isset($groups[$sid])) $groups[$sid] = [];
            $groups[$sid][] = $i;
        }

        $toDelete = [];
        foreach ($groups as $sid => $items) {
            if (count($items) <= 1) continue;
            usort($items, fn($a, $b) =>
                strcmp($b['updatedAt'] ?? $b['createdAt'] ?? '', $a['updatedAt'] ?? $a['createdAt'] ?? '')
            );
            foreach (array_slice($items, 1) as $dup) {
                $toDelete[] = [
                    '_id' => (string)$dup['_id'],
                    'name' => $dup['name'] ?? '',
                    'sourceId' => $sid,
                ];
            }
        }
        $result['dup_inventory'] = [
            'will_delete' => count($toDelete),
            'preview' => array_slice($toDelete, 0, 20),
        ];
    }

    if (in_array($operation, ['all', 'orphan_inventory'], true)) {
        $files = $db->find('compliance_files', $filter);
        $fileIds = [];
        foreach ($files as $f) $fileIds[(string)$f['_id']] = true;

        $inventory = $db->find('compliance_inventory', $filter);
        $toDelete = [];
        foreach ($inventory as $i) {
            $sid = (string)($i['sourceId'] ?? '');
            if (!$sid) continue;
            if (isset($fileIds[$sid])) continue;
            $toDelete[] = [
                '_id' => (string)$i['_id'],
                'name' => $i['name'] ?? '',
                'sourceId' => $sid,
                'active' => !empty($i['active']),
            ];
        }
        $result['orphan_inventory'] = [
            'will_delete' => count($toDelete),
            'preview' => array_slice($toDelete, 0, 20),
        ];
    }

    json_response([
        'success' => true,
        'dry_run' => true,
        'operation' => $operation,
        'scope' => $scope['companyId'],
        'result' => $result,
        'note' => 'PREVIEW. Nada se ha borrado. Usa /cleanup/apply con el mismo operation para ejecutar.',
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// 3. APPLY
// ─────────────────────────────────────────────────────────────────────
function cleanupApply() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? null;
    $operation = $body['operation'] ?? 'all';
    $confirm = $body['confirm'] ?? false;

    if (!$confirm && $confirm !== 'yes' && $confirm !== 'YES') {
        json_error('Se requiere confirm=true para ejecutar. Usa /cleanup/preview primero.', 400);
    }

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $filter = _cleanup_userFilter($scope);
    $stats = [
        'dup_files_deleted' => 0,
        'dup_files_updated' => 0,
        'dup_inventory_deleted' => 0,
        'orphan_inventory_deleted' => 0,
        'categories_cleaned' => 0,
        'errors' => [],
    ];

    if (in_array($operation, ['all', 'dup_files'], true)) {
        $files = $db->find('compliance_files', $filter);
        $groups = [];
        foreach ($files as $f) {
            if (($f['sourceType'] ?? '') !== 'agent') continue;
            $key = ($f['agentId'] ?? '?') . '|' . ($f['path'] ?? '?');
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $f;
        }

        foreach ($groups as $key => $items) {
            if (count($items) <= 1) continue;
            usort($items, fn($a, $b) =>
                strcmp($b['updatedAt'] ?? $b['createdAt'] ?? '', $a['updatedAt'] ?? $a['createdAt'] ?? '')
            );
            $keep = $items[0];
            $keptInvId = $keep['analysisResult']['inventoryId'] ?? null;

            foreach (array_slice($items, 1) as $dup) {
                try {
                    $dupInvId = $dup['analysisResult']['inventoryId'] ?? null;
                    if ($dupInvId && !$keptInvId) {
                        $db->updateOne('compliance_files', ['_id' => $keep['_id']], [
                            'analysisResult.inventoryId' => $dupInvId,
                        ]);
                        $keptInvId = $dupInvId;
                        $stats['dup_files_updated']++;
                    }

                    $db->deleteOne('compliance_files', ['_id' => $dup['_id']]);
                    $stats['dup_files_deleted']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "dup_file {$dup['_id']}: " . $e->getMessage();
                }
            }
        }
    }

    if (in_array($operation, ['all', 'dup_inventory'], true)) {
        $inventory = $db->find('compliance_inventory', $filter);
        $groups = [];
        foreach ($inventory as $i) {
            $sid = (string)($i['sourceId'] ?? '');
            if (!$sid) continue;
            if (!isset($groups[$sid])) $groups[$sid] = [];
            $groups[$sid][] = $i;
        }

        foreach ($groups as $sid => $items) {
            if (count($items) <= 1) continue;
            usort($items, fn($a, $b) =>
                strcmp($b['updatedAt'] ?? $b['createdAt'] ?? '', $a['updatedAt'] ?? $a['createdAt'] ?? '')
            );
            foreach (array_slice($items, 1) as $dup) {
                try {
                    $db->deleteOne('compliance_inventory', ['_id' => $dup['_id']]);
                    $stats['dup_inventory_deleted']++;
                } catch (\Throwable $e) {
                    $stats['errors'][] = "dup_inv {$dup['_id']}: " . $e->getMessage();
                }
            }
        }
    }

    if (in_array($operation, ['all', 'orphan_inventory'], true)) {
        $files = $db->find('compliance_files', $filter);
        $fileIds = [];
        foreach ($files as $f) $fileIds[(string)$f['_id']] = true;

        $inventory = $db->find('compliance_inventory', $filter);
        foreach ($inventory as $i) {
            $sid = (string)($i['sourceId'] ?? '');
            if (!$sid || isset($fileIds[$sid])) continue;
            try {
                $db->deleteOne('compliance_inventory', ['_id' => $i['_id']]);
                $stats['orphan_inventory_deleted']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = "orphan_inv {$i['_id']}: " . $e->getMessage();
            }
        }
    }

    if (in_array($operation, ['all', 'categories'], true)) {
        $inventory = $db->find('compliance_inventory', $filter);
        foreach ($inventory as $i) {
            $cats = (string)($i['dataCategories'] ?? '');
            if (!preg_match('/line_\d+/', $cats)) continue;

            $parts = array_map('trim', explode(',', $cats));
            $clean = [];
            foreach ($parts as $p) {
                if ($p === '') continue;
                if (preg_match('/^line_\d+$/', $p)) continue;
                $clean[$p] = true;
            }
            $cleanList = array_keys($clean);
            try {
                $db->updateOne('compliance_inventory', ['_id' => $i['_id']], [
                    'dataCategories' => implode(', ', $cleanList),
                    'updatedAt' => date('c'),
                ]);
                $stats['categories_cleaned']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = "cat {$i['_id']}: " . $e->getMessage();
            }
        }
    }

    audit_log('cleanup_applied', [
        'operation' => $operation,
        'companyId' => $scope['companyId'],
        'isSuperAdmin' => $scope['isSuperAdmin'],
        'stats' => $stats,
    ], $user['_id']);

    json_response([
        'success' => true,
        'operation' => $operation,
        'scope' => $scope['companyId'],
        'stats' => $stats,
        'message' => 'Limpieza completada. Ejecuta /cleanup/diagnose para verificar.',
    ]);
}

// ─────────────────────────────────────────────────────────────────────
// 4. REPARAR links huérfanos
// ─────────────────────────────────────────────────────────────────────
function cleanupRepairLinks() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? null;
    $confirm = $body['confirm'] ?? false;

    if (!$confirm && $confirm !== 'yes' && $confirm !== 'YES') {
        json_error('Se requiere confirm=true', 400);
    }

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $filter = _cleanup_userFilter($scope);
    $files = $db->find('compliance_files', $filter);
    $inventory = $db->find('compliance_inventory', $filter);

    $stats = ['relinked' => 0, 'agentId_fixed' => 0, 'hostname_fixed' => 0, 'errors' => []];

    $invBySource = [];
    foreach ($inventory as $i) {
        $sid = (string)($i['sourceId'] ?? '');
        if (!$sid) continue;
        if (!isset($invBySource[$sid])) {
            $invBySource[$sid] = $i;
        } else {
            $a = $invBySource[$sid];
            $newer = ($i['updatedAt'] ?? $i['createdAt'] ?? '') > ($a['updatedAt'] ?? $a['createdAt'] ?? '');
            if ($newer) $invBySource[$sid] = $i;
        }
    }

    foreach ($files as $f) {
        $fid = (string)$f['_id'];
        $currentInvId = (string)($f['analysisResult']['inventoryId'] ?? '');

        if (!$currentInvId && isset($invBySource[$fid])) {
            try {
                $db->updateOne('compliance_files', ['_id' => $f['_id']], [
                    'analysisResult.inventoryId' => $invBySource[$fid]['_id'],
                ]);
                $stats['relinked']++;

                $invUpdates = ['updatedAt' => date('c')];
                if (empty($invBySource[$fid]['agentId']) && !empty($f['agentId'])) {
                    $invUpdates['agentId'] = $f['agentId'];
                    $stats['agentId_fixed']++;
                }
                if (empty($invBySource[$fid]['hostname']) && !empty($f['hostname'])) {
                    $invUpdates['hostname'] = $f['hostname'];
                    $stats['hostname_fixed']++;
                }
                if (!empty($invUpdates) && count($invUpdates) > 1) {
                    $db->updateOne('compliance_inventory', ['_id' => $invBySource[$fid]['_id']], $invUpdates);
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "relink {$fid}: " . $e->getMessage();
            }
        }

        if ($currentInvId && isset($invBySource[$fid])) {
            $inv = $invBySource[$fid];
            $invUpdates = [];
            if (empty($inv['agentId']) && !empty($f['agentId'])) {
                $invUpdates['agentId'] = $f['agentId'];
                $stats['agentId_fixed']++;
            }
            if (empty($inv['hostname']) && !empty($f['hostname'])) {
                $invUpdates['hostname'] = $f['hostname'];
                $stats['hostname_fixed']++;
            }
            if (!empty($invUpdates)) {
                try {
                    $db->updateOne('compliance_inventory', ['_id' => $inv['_id']], $invUpdates);
                } catch (\Throwable $e) {
                    $stats['errors'][] = "fix_meta {$inv['_id']}: " . $e->getMessage();
                }
            }
        }
    }

    audit_log('cleanup_repair_links', [
        'companyId' => $scope['companyId'],
        'stats' => $stats,
    ], $user['_id']);

    json_response(['success' => true, 'stats' => $stats]);
}

// ─────────────────────────────────────────────────────────────────────
// 5. LISTAR EMPRESAS
// ─────────────────────────────────────────────────────────────────────
function cleanupListCompanies() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();

    $isAdmin = !empty($user['isAdmin']) || in_array($user['role'] ?? '', ['admin','superadmin'], true);
    if (!$isAdmin) {
        $record = $db->findOne('users', ['_id' => $user['_id']]);
        $companyId = (string)($record['companyId'] ?? $user['_id']);
        json_response([
            'success' => true,
            'companies' => [[
                'companyId' => $companyId,
                'companyName' => $record['companyName'] ?? 'Mi empresa',
                'usersCount' => 1,
                'filesCount' => 0,
                'inventoryCount' => 0,
            ]],
        ]);
        return;
    }

    $users = $db->find('users', []);
    $byCompany = [];
    foreach ($users as $u) {
        $cid = (string)($u['companyId'] ?? $u['_id']);
        if (!isset($byCompany[$cid])) {
            $byCompany[$cid] = [
                'companyId' => $cid,
                'companyName' => $u['companyName'] ?? 'Sin nombre',
                'usersCount' => 0,
                'isRoot' => ($cid === (string)$u['_id']),
            ];
        }
        $byCompany[$cid]['usersCount']++;

        if ($byCompany[$cid]['isRoot'] && !empty($u['companyName'])) {
            $byCompany[$cid]['companyName'] = $u['companyName'];
        }
    }

    foreach ($byCompany as &$c) {
        $userIds = _cleanup_getCompanyUserIds($db, $c['companyId']);
        $filter = ['userId' => ['$in' => $userIds]];
        $c['filesCount'] = $db->count('compliance_files', $filter);
        $c['inventoryCount'] = $db->count('compliance_inventory', $filter);
    }
    unset($c);

    $list = array_values($byCompany);
    usort($list, fn($a, $b) => strcasecmp($a['companyName'], $b['companyName']));

    json_response(['success' => true, 'companies' => $list]);
}

// ─────────────────────────────────────────────────────────────────────
// 6. AUDIT LOG de limpiezas
// ─────────────────────────────────────────────────────────────────────
function cleanupAuditLog() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? $_GET['companyId'] ?? null;
    $limit = (int)($body['limit'] ?? $_GET['limit'] ?? 50);

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $filter = [];
    if ($scope['userIds'] !== null) {
        $filter['userId'] = ['$in' => $scope['userIds']];
    }
    $filter['action'] = ['$in' => ['cleanup_applied', 'cleanup_repair_links', 'cleanup_purge_logs']];

    $logs = $db->find('audit_logs', $filter);
    usort($logs, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    $logs = array_slice($logs, 0, $limit);

    json_response(['success' => true, 'logs' => $logs]);
}

// ─────────────────────────────────────────────────────────────────────
// 7. PURGAR LOGS VIEJOS
// ─────────────────────────────────────────────────────────────────────
function cleanupPurgeLogs() {
    $user = _cleanup_requireAccess();
    $db = Database::getInstance();
    $body = get_body();
    $targetCompanyId = $body['companyId'] ?? null;
    $days = (int)($body['days'] ?? 180);
    $confirm = $body['confirm'] ?? false;
    $onlyNonSensitive = filter_var($body['onlyNonSensitive'] ?? true, FILTER_VALIDATE_BOOLEAN);

    if (!$confirm && $confirm !== 'yes') json_error('Se requiere confirm=true', 400);
    if ($days < 30) json_error('days debe ser >= 30 por seguridad', 400);

    $scope = _cleanup_resolveScope($user, $db, $targetCompanyId);
    if (!$scope['ok']) json_error($scope['error'], 403);

    $cutoff = date('c', time() - ($days * 86400));
    $filter = _cleanup_userFilter($scope);
    $filter['detectedAt'] = ['$lt' => $cutoff];
    if ($onlyNonSensitive) $filter['sensitive'] = false;

    $logs = $db->find('file_audit_logs', $filter);
    $deleted = 0;
    foreach ($logs as $l) {
        try {
            $db->deleteOne('file_audit_logs', ['_id' => $l['_id']]);
            $deleted++;
        } catch (\Throwable $e) { /* ignorar */ }
    }

    audit_log('cleanup_purge_logs', [
        'companyId' => $scope['companyId'],
        'days' => $days,
        'onlyNonSensitive' => $onlyNonSensitive,
        'deleted' => $deleted,
    ], $user['_id']);

    json_response(['success' => true, 'deleted' => $deleted]);
}