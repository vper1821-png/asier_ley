<?php
// Módulo de Certificación Ley 21.719

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../CertificateGenerator.php';

// ═══════════════════════════════════════════════════════════
// DEFINICIÓN DE LOS 24 DOCUMENTOS
// ═══════════════════════════════════════════════════════════
function certDocsDefinitions() {
    return [
        // I. GOBERNANZA (20%)
        ['code' => 'DOC-01', 'chapter' => 'I', 'name' => 'Designación del DPD', 'weight' => 4, 'auto' => true,  'source' => 'compliance_config'],
        ['code' => 'DOC-02', 'chapter' => 'I', 'name' => 'Registro APDP', 'weight' => 4, 'auto' => true,  'source' => 'compliance_config'],
        ['code' => 'DOC-03', 'chapter' => 'I', 'name' => 'Política de Privacidad', 'weight' => 4, 'auto' => true,  'source' => 'compliance_config'],
        ['code' => 'DOC-04', 'chapter' => 'I', 'name' => 'Política de Cookies', 'weight' => 4, 'auto' => true,  'source' => 'compliance_config'],
        ['code' => 'DOC-05', 'chapter' => 'I', 'name' => 'Política de Retención', 'weight' => 4, 'auto' => true,  'source' => 'compliance_config'],

        // II. REGISTROS (20%)
        ['code' => 'DOC-06', 'chapter' => 'II', 'name' => 'RAT', 'weight' => 6, 'auto' => true,  'source' => 'compliance_inventory'],
        ['code' => 'DOC-07', 'chapter' => 'II', 'name' => 'Base de Licitud por Actividad', 'weight' => 4, 'auto' => true,  'source' => 'compliance_inventory'],
        ['code' => 'DOC-08', 'chapter' => 'II', 'name' => 'Registro de Consentimientos', 'weight' => 5, 'auto' => true,  'source' => 'compliance_consents'],
        ['code' => 'DOC-09', 'chapter' => 'II', 'name' => 'Categorías Especiales', 'weight' => 5, 'auto' => true,  'source' => 'compliance_inventory'],

        // III. ANÁLISIS (20%)
        ['code' => 'DOC-10', 'chapter' => 'III', 'name' => 'Matriz de Riesgos', 'weight' => 5, 'auto' => true,  'source' => 'compliance_inventory'],
        ['code' => 'DOC-11', 'chapter' => 'III', 'name' => 'Evaluaciones de Impacto (DPIA)', 'weight' => 5, 'auto' => true,  'source' => 'compliance_dpia'],
        ['code' => 'DOC-12', 'chapter' => 'III', 'name' => 'Manual de Seguridad', 'weight' => 5, 'auto' => false, 'source' => null],
        ['code' => 'DOC-13', 'chapter' => 'III', 'name' => 'Seudonimización', 'weight' => 5, 'auto' => true,  'source' => 'compliance_pseudonymization'],

        // IV. TERCEROS (10%)
        ['code' => 'DOC-14', 'chapter' => 'IV', 'name' => 'Registro de Encargados', 'weight' => 4, 'auto' => true,  'source' => 'compliance_processors'],
        ['code' => 'DOC-15', 'chapter' => 'IV', 'name' => 'Transferencias Internacionales', 'weight' => 3, 'auto' => true,  'source' => 'compliance_transfers'],
        ['code' => 'DOC-16', 'chapter' => 'IV', 'name' => 'Cláusulas Contractuales Tipo', 'weight' => 3, 'auto' => false, 'source' => null],

        // V. INCIDENTES (10%)
        ['code' => 'DOC-17', 'chapter' => 'V', 'name' => 'Protocolo de Brechas', 'weight' => 4, 'auto' => true,  'source' => 'compliance_breach_protocol'],
        ['code' => 'DOC-18', 'chapter' => 'V', 'name' => 'Plan de Respuesta a Incidentes', 'weight' => 3, 'auto' => true,  'source' => 'compliance_incident_response'],
        ['code' => 'DOC-19', 'chapter' => 'V', 'name' => 'Registro Histórico de Brechas', 'weight' => 3, 'auto' => true,  'source' => 'compliance_breaches'],

        // VI. ARCO (10%)
        ['code' => 'DOC-20', 'chapter' => 'VI', 'name' => 'Canal de Derechos ARCO', 'weight' => 3, 'auto' => true,  'source' => 'compliance_config'],
        ['code' => 'DOC-21', 'chapter' => 'VI', 'name' => 'Procedimiento de Gestión ARCO', 'weight' => 3, 'auto' => false, 'source' => null],
        ['code' => 'DOC-22', 'chapter' => 'VI', 'name' => 'Registro Histórico ARCO', 'weight' => 4, 'auto' => true,  'source' => 'arco_requests'],

        // VII. FORMACIÓN (5%)
        ['code' => 'DOC-23', 'chapter' => 'VII', 'name' => 'Plan Anual de Capacitación', 'weight' => 5, 'auto' => true,  'source' => 'compliance_trainings'],

        // VIII. CIERRE (5%)
        ['code' => 'DOC-24', 'chapter' => 'VIII', 'name' => 'Declaración de Conformidad', 'weight' => 5, 'auto' => true,  'source' => 'declaration'],
    ];
}

function certChapters() {
    return [
        'I'    => 'I. Gobernanza',
        'II'   => 'II. Registros de Tratamiento',
        'III'  => 'III. Análisis y Seguridad',
        'IV'   => 'IV. Terceros',
        'V'    => 'V. Incidentes',
        'VI'   => 'VI. Derechos ARCO',
        'VII'  => 'VII. Formación',
        'VIII' => 'VIII. Cierre',
    ];
}

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════
function certResolveScope($user, $db) {
    $isSuper = !empty($user['isAdmin']) || in_array($user['role'] ?? '', ['admin','superadmin'], true);
    if ($isSuper) return ['isSuper' => true, 'companyId' => null, 'userIds' => []];

    $rec = $db->findOne('users', ['_id' => $user['_id']]);
    $companyId = (string)($rec['companyId'] ?? $user['_id']);
    $subs = $db->find('users', ['companyId' => $companyId]);
    $ids = array_values(array_unique(array_merge(
        [$companyId, (string)$user['_id']],
        array_map(fn($s) => (string)($s['_id'] ?? ''), $subs)
    )));
    $ids = array_filter($ids);
    return ['isSuper' => false, 'companyId' => $companyId, 'userIds' => $ids];
}

function certGetDocumentState($db, $companyId, $code) {
    $doc = $db->findOne('certification_documents', [
        'companyId' => $companyId,
        'docCode'   => $code,
    ]);
    return $doc ?: null;
}

function certComputeDocStatus($def, $scope, $db) {
    $source = $def['source'];
    $result = ['exists' => false, 'complete' => false, 'sourceData' => []];

    if ($source === 'compliance_config') {
        $cfg = $db->findOne('compliance_config', $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]]) ?: [];
        $result['sourceData'] = $cfg;
        $checks = [
            'DOC-01' => !empty($cfg['dpdName']) && !empty($cfg['dpdEmail']),
            'DOC-02' => !empty($cfg['apdpRegistered']) && !empty($cfg['apdpRegistrationNumber']),
            'DOC-03' => !empty($cfg['privacyPolicyUrl']) || !empty($cfg['privacyPolicyContent']),
            'DOC-04' => !empty($cfg['cookiesPolicyUrl']),
            'DOC-05' => !empty($cfg['dataRetentionPolicy']),
            'DOC-20' => !empty($cfg['privacyPolicyUrl']) || !empty($cfg['companyName']),
        ];
        $result['exists']   = !empty($cfg);
        $result['complete'] = $checks[$def['code']] ?? false;
    } elseif ($source === 'compliance_inventory') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $inv = $db->find('compliance_inventory', $filter);
        $result['sourceData'] = $inv;
        $result['exists'] = count($inv) > 0;
        if ($def['code'] === 'DOC-06') {
            $result['complete'] = count(array_filter($inv, fn($i) => !empty($i['name']) && !empty($i['legalBasis']))) > 0;
        } elseif ($def['code'] === 'DOC-07') {
            $result['complete'] = count(array_filter($inv, fn($i) => !empty($i['legalBasis']))) > 0;
        } elseif ($def['code'] === 'DOC-09') {
            $result['complete'] = count(array_filter($inv, fn($i) => !empty($i['sensitive']) || !empty($i['childrenData']))) > 0;
        } elseif ($def['code'] === 'DOC-10') {
            $result['complete'] = count(array_filter($inv, fn($i) => !empty($i['risk']))) > 0;
        }
    } elseif ($source === 'compliance_consents') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_consents', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count(array_filter($items, fn($c) => empty($c['revokedAt']))) > 0;
    } elseif ($source === 'compliance_dpia') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_dpia', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count(array_filter($items, fn($d) => in_array($d['status'] ?? '', ['approved'], true))) > 0;
    } elseif ($source === 'compliance_pseudonymization') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_pseudonymization', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count(array_filter($items, fn($r) => !empty($r['executed']) || ($r['status'] ?? '') === 'executed')) > 0;
    } elseif ($source === 'compliance_processors') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_processors', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count(array_filter($items, fn($p) => ($p['hasContract'] ?? '') === 'si')) > 0;
    } elseif ($source === 'compliance_transfers') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_transfers', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count($items) > 0;
    } elseif ($source === 'compliance_breach_protocol') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $item = $db->findOne('compliance_breach_protocol', $filter) ?? [];
        $result['sourceData'] = $item;
        $result['exists'] = !empty($item);
        $result['complete'] = !empty($item['protocolName']) && !empty($item['scope']);
    } elseif ($source === 'compliance_incident_response') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $item = $db->findOne('compliance_incident_response', $filter) ?? [];
        $result['sourceData'] = $item;
        $result['exists'] = !empty($item);
        $result['complete'] = !empty($item['planName']) && !empty($item['scope']);
    } elseif ($source === 'compliance_breaches') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_breaches', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = true;
        $result['complete'] = true;
    } elseif ($source === 'arco_requests') {
        $filter = $scope['isSuper'] ? [] : ['companyId' => ['$in' => $scope['userIds']]];
        $items = $db->find('arco_requests', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = true;
        $result['complete'] = true;
    } elseif ($source === 'compliance_trainings') {
        $filter = $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]];
        $items = $db->find('compliance_trainings', $filter);
        $result['sourceData'] = $items;
        $result['exists'] = count($items) > 0;
        $result['complete'] = count(array_filter($items, fn($t) => !empty($t['completed']))) > 0;
    } elseif ($source === 'declaration') {
        $result['exists'] = true;
        $result['complete'] = true;
    }

    return $result;
}

function certGenerateCertId() {
    $year = date('Y');
    $rand = strtoupper(bin2hex(random_bytes(4)));
    return "CERT-21.719-{$year}-{$rand}";
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Dashboard
// ═══════════════════════════════════════════════════════════
function certDashboard() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    $defs = certDocsDefinitions();
    $chapters = certChapters();

    // Sanity: pesos deben sumar 100
    $weightSum = array_sum(array_column($defs, 'weight'));
    if ($weightSum !== 100) {
        error_log('[Certification] ADVERTENCIA: pesos suman ' . $weightSum . ', deberían ser 100');
    }

    $score = 0;
    $totalWeight = 0;
    $documents = [];
    $byChapter = [];

    foreach ($defs as $def) {
        $totalWeight += $def['weight'];
        $state = certGetDocumentState($db, $scope['companyId'] ?? '', $def['code']);
        $auto  = certComputeDocStatus($def, $scope, $db);

        $status = $state['status'] ?? null;
        if (!$status) {
            if (!$def['auto']) {
                $status = 'pending_manual';
            } else {
                $status = $auto['complete'] ? 'ready' : ($auto['exists'] ? 'draft' : 'missing');
            }
        }

        $stateValue = [
            'signed'         => 1.00,
            'approved'       => 0.85,
            'ready'          => 0.75,
            'draft'          => 0.40,
            'pending_manual' => 0.00,
            'expired'        => 0.00,
            'rejected'       => 0.00,
            'missing'        => 0.00,
        ][$status] ?? 0;

        $score += $def['weight'] * $stateValue;

        $documents[] = [
            'code'       => $def['code'],
            'name'       => $def['name'],
            'chapter'    => $def['chapter'],
            'chapterName'=> $chapters[$def['chapter']],
            'weight'     => $def['weight'],
            'auto'       => $def['auto'],
            'status'     => $status,
            'canGenerate'=> (bool)$def['auto'],
            'hasAutoData'=> $def['auto'] ? ($auto['exists'] ?? false) : false,
            'version'    => $state['version'] ?? 0,
            'approvedAt' => $state['approvedAt'] ?? null,
            'approvedByName' => $state['approvedByName'] ?? null,
            'signedAt'   => $state['signedAt'] ?? null,
            'signedByName' => $state['signedByName'] ?? null,
            'pdfUrl'     => $state['pdfUrl'] ?? null,
            'currentHash'=> $state['currentHash'] ?? null,
        ];

        if (!isset($byChapter[$def['chapter']])) {
            $byChapter[$def['chapter']] = [
                'code' => $def['chapter'],
                'name' => $chapters[$def['chapter']],
                'total' => 0,
                'done' => 0,
                'weight' => 0,
                'score' => 0,
            ];
        }
        $byChapter[$def['chapter']]['total']++;
        if ($stateValue >= 0.75) $byChapter[$def['chapter']]['done']++;
        $byChapter[$def['chapter']]['weight'] += $def['weight'];
        $byChapter[$def['chapter']]['score'] += $def['weight'] * $stateValue;
    }

    $finalScore = $totalWeight > 0 ? round($score / $totalWeight * 100) : 0;

    $blockers = [];
    $cfg = $db->findOne('compliance_config', $scope['isSuper'] ? [] : ['userId' => ['$in' => $scope['userIds']]]) ?? [];
    if (empty($cfg['dpdName']) || empty($cfg['dpdEmail'])) $blockers[] = 'DPD no designado';
    if (empty($cfg['apdpRegistered'])) $blockers[] = 'Registro APDP pendiente';

    $lastCert = $db->findOne('certifications', $scope['isSuper'] ? ['status' => 'issued'] : [
        'companyId' => $scope['companyId'],
        'status'    => 'issued',
    ]);

    json_response([
        'success'    => true,
        'score'      => $finalScore,
        'canIssue'   => $finalScore >= 90 && empty($blockers) && !$scope['isSuper'],
        'blockers'   => $blockers,
        'isSuperAdmin' => $scope['isSuper'],
        'documents'  => $documents,
        'byChapter'  => array_values($byChapter),
        'lastCert'   => $lastCert,
        'thresholds' => [
            'apt'        => 90,
            'conditional'=> 70,
            'notApt'     => 0,
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Generar PDF de un documento
// ═══════════════════════════════════════════════════════════
function certGenerateDocument() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    if ($scope['isSuper']) {
        json_error('Selecciona una empresa específica para generar documentos de certificación', 400);
    }

    $code = $body['code'] ?? '';
    if (!$code) json_error('code requerido');

    $defs = certDocsDefinitions();
    $def = null;
    foreach ($defs as $d) { if ($d['code'] === $code) { $def = $d; break; } }
    if (!$def) json_error('documento no válido', 404);

    if (empty($def['auto'])) {
        json_error('Este documento requiere carga manual. Contacta al administrador para subir evidencia.', 400);
    }

    $auto = certComputeDocStatus($def, $scope, $db);
    $cfg = $db->findOne('compliance_config', ['userId' => ['$in' => $scope['userIds']]]) ?? [];

    $companyName = $cfg['companyName'] ?? ($user['companyName'] ?? $user['email'] ?? 'Empresa');
    $dpdName = $cfg['dpdName'] ?? '—';
    $dpdEmail = $cfg['dpdEmail'] ?? '—';

    $generator = new CertificateGenerator($db, $user, $scope);
    $html = $generator->generateDocument($def, $auto['sourceData'], [
        'companyName' => $companyName,
        'dpdName' => $dpdName,
        'dpdEmail' => $dpdEmail,
    ]);

    // Nombre único por versión para no chocar con PDFs anteriores
    $existing = certGetDocumentState($db, $scope['companyId'], $code);
    $newVersion = ($existing['version'] ?? 0) + 1;
    $filename = 'cert-' . $code . '-v' . $newVersion . '-' . date('Ymd-His') . '.pdf';
    $result = $generator->renderPDF($html, $filename);

    $now = date('c');

    $doc = [
        'companyId'    => $scope['companyId'],
        'docCode'      => $code,
        'docName'      => $def['name'],
        'chapter'      => $def['chapter'],
        'weight'       => $def['weight'],
        'status'       => $existing['status'] ?? 'draft',
        'version'      => $newVersion,
        'currentHash'  => $result['hash'],
        'pdfUrl'       => $result['url'],
        'generatedBy'  => (string)$user['_id'],
        'generatedAt'  => $now,
        'updatedAt'    => $now,
    ];

    if ($existing) {
        // ✅ FIX: añadir entrada al historial en cada regeneración
        $history = $existing['history'] ?? [];
        $history[] = [
            'version' => $newVersion,
            'hash'    => $result['hash'],
            'at'      => $now,
            'by'      => (string)$user['_id'],
            'action'  => 'regenerated',
        ];
        $doc['history'] = $history;
        $db->updateOne('certification_documents', ['_id' => $existing['_id']], $doc);
    } else {
        $doc['createdAt'] = $now;
        $doc['history'] = [[
            'version' => $newVersion,
            'hash'    => $result['hash'],
            'at'      => $now,
            'by'      => (string)$user['_id'],
            'action'  => 'created',
        ]];
        $db->insertOne('certification_documents', $doc);
    }

    json_response([
        'success' => true,
        'pdfUrl'  => $result['url'],
        'hash'    => $result['hash'],
        'version' => $newVersion,
    ]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Aprobar / Firmar / Rechazar documento
// ═══════════════════════════════════════════════════════════
function certUpdateDocumentStatus() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    if ($scope['isSuper']) {
        json_error('Selecciona una empresa específica para gestionar documentos', 400);
    }

    $code = $body['code'] ?? '';
    $action = $body['action'] ?? '';
    if (!$code || !$action) json_error('code y action requeridos');

    if (!in_array($action, ['approve', 'sign', 'reject', 'reset'], true)) {
        json_error('acción no válida');
    }

    $existing = certGetDocumentState($db, $scope['companyId'], $code);
    if (!$existing) json_error('primero genera el documento', 400);

    $now = date('c');

    // Refrescar nombre del usuario desde BD (más fiable que el JWT)
    $userRec = $db->findOne('users', ['_id' => $user['_id']]) ?? [];
    $userName = $userRec['name'] ?? ($user['name'] ?? ($userRec['email'] ?? ($user['email'] ?? 'Usuario')));

    $updates = ['updatedAt' => $now];

    if ($action === 'approve') {
        $updates['status']     = 'approved';
        $updates['approvedBy'] = (string)$user['_id'];
        $updates['approvedByName'] = $userName;
        $updates['approvedAt'] = $now;
    } elseif ($action === 'sign') {
        $updates['status']     = 'signed';
        $updates['signedBy']   = (string)$user['_id'];
        $updates['signedByName'] = $userName;
        $updates['signedAt']   = $now;
        $updates['signatureData'] = $body['signatureData'] ?? null;
    } elseif ($action === 'reject') {
        $updates['status']         = 'rejected';
        $updates['rejectedAt']     = $now;
        $updates['rejectedBy']     = (string)$user['_id'];
        $updates['rejectionReason'] = $body['reason'] ?? '';
    } else { // reset
        $updates['status'] = 'draft';
    }

    $history = $existing['history'] ?? [];
    $history[] = [
        'version' => $existing['version'] ?? 1,
        'status'  => $updates['status'],
        'action'  => $action,
        'by'      => $userName,
        'at'      => $now,
    ];
    $updates['history'] = $history;

    $db->updateOne('certification_documents', ['_id' => $existing['_id']], $updates);

    json_response(['success' => true, 'status' => $updates['status']]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Emitir certificación
// ═══════════════════════════════════════════════════════════
function certIssue() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    if ($scope['isSuper']) json_error('debes estar en una empresa específica para emitir', 400);

    $defs = certDocsDefinitions();
    $totalWeight = 0;
    $score = 0;
    $signedDocs = [];

    foreach ($defs as $def) {
        $totalWeight += $def['weight'];
        $state = certGetDocumentState($db, $scope['companyId'], $def['code']);
        $status = $state['status'] ?? 'missing';
        $stateValue = [
            'signed'   => 1.00,
            'approved' => 0.85,
            'ready'    => 0.75,
            'draft'    => 0.40,
        ][$status] ?? 0;

        $score += $def['weight'] * $stateValue;

        if ($status === 'signed' || $status === 'approved') {
            $signedDocs[] = [
                'code'    => $def['code'],
                'name'    => $def['name'],
                'status'  => $status,
                'version' => $state['version'] ?? 0,
                'hash'    => $state['currentHash'] ?? null,
            ];
        }
    }

    $finalScore = $totalWeight > 0 ? round($score / $totalWeight * 100) : 0;

    if ($finalScore < 90) {
        json_error("Score insuficiente ({$finalScore}%). Se requiere al menos 90% para emitir.", 400);
    }

    $cfg = $db->findOne('compliance_config', ['userId' => ['$in' => $scope['userIds']]]) ?? [];
    if (empty($cfg['dpdName']) || empty($cfg['dpdEmail'])) {
        json_error('DPD debe estar designado', 400);
    }

    $certId = certGenerateCertId();
    $now = date('c');
    $expiresAt = date('c', strtotime('+2 years'));

    $masterHash = hash('sha256', $certId . '|' . $now . '|' . json_encode($signedDocs));

    $cert = [
        'certId'       => $certId,
        'companyId'    => $scope['companyId'],
        'status'       => 'issued',
        'score'        => $finalScore,
        'issuedAt'     => $now,
        'issuedBy'     => (string)$user['_id'],
        'issuedByName' => $user['name'] ?? $user['email'] ?? 'Responsable',
        'expiresAt'    => $expiresAt,
        'companySnapshot' => [
            'name'     => $cfg['companyName'] ?? 'Empresa',
            'rut'      => $cfg['companyRut'] ?? '—',
            'dpdName'  => $cfg['dpdName'] ?? '—',
            'dpdEmail' => $cfg['dpdEmail'] ?? '—',
            'dpdPhone' => $cfg['dpdPhone'] ?? '—',
        ],
        'documents'    => $signedDocs,
        'masterHash'   => $masterHash,
        'verifyUrl'    => '/verify/' . $certId,
        'createdAt'    => $now,
        'updatedAt'    => $now,
    ];

    $inserted = $db->insertOne('certifications', $cert);

    json_response([
        'success'  => true,
        'certId'   => $certId,
        'score'    => $finalScore,
        'verifyUrl'=> '/verify/' . $certId,
        'hash'     => $masterHash,
        'id'       => (string)$inserted['_id'],
    ]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Descargar certificado (PDF maestro)
// ═══════════════════════════════════════════════════════════
function certDownloadMaster() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    $certId = $_GET['certId'] ?? '';
    if (!$certId) json_error('certId requerido');

    $cert = $db->findOne('certifications', ['certId' => $certId]);
    if (!$cert) json_error('certificación no encontrada', 404);

    if (!$scope['isSuper'] && (string)$cert['companyId'] !== $scope['companyId']) {
        json_error('acceso denegado', 403);
    }

    $generator = new CertificateGenerator($db, $user, $scope);
    $html = $generator->generateMasterCertificate($cert);

    $filename = 'certificado-' . $certId . '.pdf';
    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Descargar certificado (ZIP con todo)
// ═══════════════════════════════════════════════════════════
function certDownloadZip() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    $certId = $_GET['certId'] ?? '';
    if (!$certId) json_error('certId requerido');

    $cert = $db->findOne('certifications', ['certId' => $certId]);
    if (!$cert) json_error('certificación no encontrada', 404);

    if (!$scope['isSuper'] && (string)$cert['companyId'] !== $scope['companyId']) {
        json_error('acceso denegado', 403);
    }

    if (!class_exists('ZipArchive')) json_error('ZipArchive no disponible en el servidor', 500);

    // ✅ FIX: guardar en el mismo directorio que los PDFs individuales
    $reportsDir = __DIR__ . '/../reports';
    if (!is_dir($reportsDir)) mkdir($reportsDir, 0755, true);

    $zipPath = $reportsDir . '/' . $certId . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_error('no se pudo crear el ZIP', 500);
    }

    // 1) Certificado maestro
    $generator = new CertificateGenerator($db, $user, $scope);
    $masterHtml = $generator->generateMasterCertificate($cert);

    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($masterHtml, 'UTF-8');
    $dompdf->render();
    $zip->addFromString('00-certificado-maestro.pdf', $dompdf->output());

    // 2) Índice maestro (CSV)
    $csv = "Código,Documento,Estado,Versión,Hash\n";
    foreach (($cert['documents'] ?? []) as $d) {
        $csv .= sprintf("%s,%s,%s,%s,%s\n",
            $d['code'] ?? '',
            str_replace(',', ';', $d['name'] ?? ''),
            $d['status'] ?? '',
            $d['version'] ?? 0,
            $d['hash'] ?? ''
        );
    }
    $zip->addFromString('01-indice.csv', $csv);

    // 3) PDFs individuales (mismo directorio /reports)
    foreach (($cert['documents'] ?? []) as $d) {
        $docState = certGetDocumentState($db, $scope['companyId'], $d['code']);
        if (!$docState || empty($docState['pdfUrl'])) continue;

        // El pdfUrl ahora es /api/reports/download/{filename}
        $filename = basename($docState['pdfUrl']);
        $pdfFile = $reportsDir . '/' . $filename;

        if (is_file($pdfFile)) {
            $zip->addFile($pdfFile, '02-documentos/' . $d['code'] . '-' . preg_replace('/[^a-z0-9]+/i', '-', $d['name']) . '.pdf');
        }
    }

    // 4) Metadatos
    $meta = json_encode([
        'certId'        => $cert['certId'],
        'company'       => $cert['companySnapshot'] ?? [],
        'score'         => $cert['score'] ?? 0,
        'issuedAt'      => $cert['issuedAt'] ?? null,
        'expiresAt'     => $cert['expiresAt'] ?? null,
        'masterHash'    => $cert['masterHash'] ?? null,
        'verifyUrl'     => $cert['verifyUrl'] ?? null,
        'documentCount' => count($cert['documents'] ?? []),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $zip->addFromString('03-metadatos.json', $meta);

    // 5) README
    $readme = "CERTIFICADO DE CUMPLIMIENTO LEY 21.719\n";
    $readme .= "=====================================\n\n";
    $readme .= "Empresa: " . ($cert['companySnapshot']['name'] ?? '—') . "\n";
    $readme .= "RUT: " . ($cert['companySnapshot']['rut'] ?? '—') . "\n";
    $readme .= "Certificado: " . $cert['certId'] . "\n";
    $readme .= "Score: " . ($cert['score'] ?? 0) . "%\n";
    $readme .= "Emitido: " . ($cert['issuedAt'] ?? '—') . "\n";
    $readme .= "Vence: " . ($cert['expiresAt'] ?? '—') . "\n";
    $readme .= "Hash maestro: " . ($cert['masterHash'] ?? '—') . "\n\n";
    $readme .= "Verificación pública: " . ($cert['verifyUrl'] ?? '—') . "\n\n";
    $readme .= "Contenido:\n";
    $readme .= "  00-certificado-maestro.pdf   Documento oficial firmado\n";
    $readme .= "  01-indice.csv                Listado de documentos incluidos\n";
    $readme .= "  02-documentos/               PDFs individuales\n";
    $readme .= "  03-metadatos.json            Metadatos completos\n";
    $zip->addFromString('README.txt', $readme);

    $zip->close();

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $certId . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit;
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Verificación pública
// ═══════════════════════════════════════════════════════════
function certVerify() {
    $certId = $_GET['certId'] ?? '';
    if (!$certId) json_error('certId requerido');

    $db = Database::getInstance();
    $cert = $db->findOne('certifications', ['certId' => $certId]);
    if (!$cert) json_error('certificación no encontrada', 404);

    json_response([
        'success'    => true,
        'certId'     => $cert['certId'],
        'status'     => $cert['status'] ?? 'unknown',
        'company'    => [
            'name' => $cert['companySnapshot']['name'] ?? '—',
            'rut'  => $cert['companySnapshot']['rut'] ?? '—',
        ],
        'dpd'        => [
            'name'  => $cert['companySnapshot']['dpdName'] ?? '—',
            'email' => $cert['companySnapshot']['dpdEmail'] ?? '—',
        ],
        'score'      => $cert['score'] ?? 0,
        'issuedAt'   => $cert['issuedAt'] ?? null,
        'expiresAt'  => $cert['expiresAt'] ?? null,
        'masterHash' => $cert['masterHash'] ?? null,
        'verifyUrl'  => $cert['verifyUrl'] ?? null,
        'isExpired'  => !empty($cert['expiresAt']) && strtotime($cert['expiresAt']) < time(),
        // ✅ FIX: exponer info de revocación
        'revokedAt'     => $cert['revokedAt'] ?? null,
        'revokedReason' => $cert['revokedReason'] ?? null,
    ]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Listar certificaciones
// ═══════════════════════════════════════════════════════════
function certList() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $scope = certResolveScope($user, $db);

    $filter = $scope['isSuper'] ? [] : ['companyId' => $scope['companyId']];
    $items = $db->find('certifications', $filter);

    usort($items, fn($a, $b) => strcmp($b['issuedAt'] ?? '', $a['issuedAt'] ?? ''));

    json_response(['success' => true, 'items' => $items]);
}

// ═══════════════════════════════════════════════════════════
// ENDPOINT: Revocar certificación
// ═══════════════════════════════════════════════════════════
function certRevoke() {
    $user = Auth::requireAuth();
    if (empty($user['isAdmin']) && !in_array($user['role'] ?? '', ['admin','superadmin'], true)) {
        json_error('solo administradores pueden revocar', 403);
    }

    $body = get_body();
    $certId = $body['certId'] ?? '';
    $reason = trim($body['reason'] ?? '');
    if (!$certId || !$reason) json_error('certId y reason requeridos');

    $db = Database::getInstance();
    $cert = $db->findOne('certifications', ['certId' => $certId]);
    if (!$cert) json_error('certificación no encontrada', 404);

    $db->updateOne('certifications', ['certId' => $certId], [
        'status' => 'revoked',
        'revokedAt' => date('c'),
        'revokedBy' => (string)$user['_id'],
        'revokedReason' => $reason,
        'updatedAt' => date('c'),
    ]);

    json_response(['success' => true]);
}