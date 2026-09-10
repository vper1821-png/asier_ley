<?php
// Compliance routes
// Todas las consultas de LISTADO (GET sin id, GET con filtros) usan userId IN (userIds de la empresa)
// Las operaciones de escritura (POST, PUT, DELETE específico) usan el userId del usuario autenticado
// para que cada usuario pueda crear/modificar sus propios elementos, pero al listarlos se compartan.

require_once __DIR__ . '/../Auth.php';

// ─── Función auxiliar para obtener userIds de la empresa ───
function getCompanyUserIds($user, $db) {
    $isSuperAdmin = !empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin';
    if ($isSuperAdmin) {
        return null;
    }
    $userRecord = $db->findOne('users', ['_id' => $user['_id']]);
    if (!$userRecord) {
        json_error('Usuario no encontrado');
    }
    $companyId = $userRecord['companyId'] ?? $user['_id'];
    $users = $db->find('users', ['companyId' => $companyId]);
    $userIds = array_map('strval', array_column($users, '_id'));
    if (empty($userIds)) {
        $userIds = [(string)$user['_id']];
    }
    return $userIds;
}

// ✅ NUEVO: Helper para verificar si el usuario es DPO/DPD
function isDpoOrDpd($user, $db) {
    // Superadmin siempre puede
    if (!empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin') {
        return true;
    }
    // Refrescar desde BD (por si el JWT está desactualizado)
    $record = $db->findOne('users', ['_id' => $user['_id']]) ?? [];
    $role = strtolower($record['role'] ?? ($user['role'] ?? ''));
    // Roles válidos para aprobar DPIA
    return in_array($role, ['dpo', 'dpd'], true);
}

// ─── Score ──────────────────────────────────────────────────────────
function score() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);

    $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];

    $agents = $db->count('agents', $filter);
    $databases = $db->count('databases', $filter);
    $alerts = $db->count('alerts', $filter);
    $onboarding = $db->findOne('onboarding', $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]]);

    $score = 0;
    $details = [];

    $agentScore = $agents > 0 ? 100 : 0;
    $score += $agentScore * 0.3;
    $details['agents'] = ['label' => 'Agentes desplegados', 'score' => $agentScore];

    $dbScore = $databases > 0 ? 100 : 0;
    $score += $dbScore * 0.25;
    $details['databases'] = ['label' => 'Bases de datos monitorizadas', 'score' => $dbScore];

    $onboardingScore = ($onboarding && !empty($onboarding['completed'])) ? 100 : 0;
    $score += $onboardingScore * 0.25;
    $details['onboarding'] = ['label' => 'Onboarding completado', 'score' => $onboardingScore];

    $alertScore = $alerts > 0 ? 100 : 0;
    $score += $alertScore * 0.2;
    $details['alerts'] = ['label' => 'Alertas configuradas', 'score' => $alertScore];

    json_response([
        'score' => round($score),
        'details' => $details,
    ]);
}

// ─── Checklist detallado ──────────────────────────────────────────
function detailedChecklist() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);

    $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];

    $config = $db->findOne('compliance_config', $filter) ?? [];
    $inventory = $db->find('compliance_inventory', $filter);
    $consents = $db->find('compliance_consents', $filter);
    $trainings = $db->find('compliance_trainings', $filter);
    $dpia = $db->find('compliance_dpia', $filter);
    $arcoRequests = $db->find('compliance_arco_requests', $filter);
    $breaches = $db->find('compliance_breaches', $filter);
    $pseudoRules = $db->find('compliance_pseudonymization', $filter);
    $processors = $db->find('compliance_processors', $filter);
    $transfers = $db->find('compliance_transfers', $filter);

    $checkDocs = $db->find('compliance_checklist', $filter);
    $documentedSections = [];
    foreach ($checkDocs as $doc) {
        $section = $doc['section'] ?? '';
        $data = (array)($doc['data'] ?? []);
        $hasData = false;
        if (is_array($data) && count($data) > 0) {
            foreach ($data as $v) { if (!empty($v) || (is_array($v) && count($v) > 0)) { $hasData = true; break; } }
        }
        if ($section !== '' && $hasData) $documentedSections[] = $section;
    }
    $documentedSections = array_unique($documentedSections);

    $detailedChecklist = [
        ['id' => 'dpd', 'label' => 'DPD Designado', 'done' => (!empty($config['dpdName']) && !empty($config['dpdEmail'])) || in_array('dpd', $documentedSections)],
        ['id' => 'apdp', 'label' => 'Modelo certificado', 'done' => !empty($config['apdpRegistered']) && !empty($config['apdpRegistrationNumber'])],
        ['id' => 'inventory', 'label' => 'Inventario de Datos', 'done' => (count($inventory) > 0 && count(array_filter($inventory, fn($i) => !empty($i['name']) && !empty($i['legalBasis']))) > 0) || in_array('inventory', $documentedSections)],
        ['id' => 'privacy', 'label' => 'Política de Privacidad', 'done' => (!empty($config['privacyPolicyUrl']) || !empty($config['privacyPolicyContent'])) || in_array('privacy', $documentedSections)],
        ['id' => 'consents', 'label' => 'Consentimientos', 'done' => (count($consents) > 0 && count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0) || in_array('consents', $documentedSections)],
        ['id' => 'breach_protocol', 'label' => 'Protocolo de Brechas', 'done' => !empty($config['breachProtocolUrl']) || !empty($config['breachProtocolContent'])],
        ['id' => 'arco', 'label' => 'Canal de derechos', 'done' => (count($arcoRequests) > 0 || !empty($config['arcoChannelUrl'])) || in_array('arco', $documentedSections)],
        ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'done' => (count($pseudoRules) > 0 && count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0) || in_array('pseudonymization', $documentedSections)],
        ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'done' => !empty($config['incidentResponsePlan']) || !empty($config['incidentResponsePlanUrl'])],
        ['id' => 'training', 'label' => 'Capacitación', 'done' => (count($trainings) > 0 && count(array_filter($trainings, fn($t) => !empty($t['completed']))) > 0) || in_array('training', $documentedSections)],
    ];

    json_response(['checklist' => $detailedChecklist]);
}

// ─── Auto-sign training ─────────────────────────────────────────────
function autoSignTraining() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $body = get_body();

    $trainingId = $body['trainingId'] ?? '';
    if (!$trainingId) json_error('trainingId requerido');

    $training = $db->findOne('compliance_trainings', ['_id' => $trainingId, 'userId' => $user['_id']]);
    if (!$training) json_error('Capacitación no encontrada', 404);

    $inviteToken = bin2hex(random_bytes(16));
    $invite = [
        'userId' => $user['_id'],
        'token' => $inviteToken,
        'title' => $training['title'] ?? 'Capacitación: ' . ($training['title'] ?? ''),
        'description' => 'Firma para capacitación: ' . ($training['title'] ?? ''),
        'companyName' => $user['companyName'] ?? ($user['email'] ?? ''),
        'signed' => false,
    ];

    $inviteId = $db->insertOne('compliance_invites', $invite);

    $db->updateOne('compliance_trainings', ['_id' => $trainingId], [
        'inviteId' => $inviteId,
        'inviteAssignedAt' => date('c'),
    ]);

    json_response(['success' => true, 'message' => 'Invitación de firma creada exitosamente', 'token' => $inviteToken]);
}

// ─── Configuración de compliance (compartida por empresa) ────────
function updateConfig() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $body = get_body();

    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);
    $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];

    $existing = $db->findOne('compliance_config', $filter);
    $config = $existing ?: ['userId' => $user['_id']];

    $policiesRaw = null;
    if (isset($body['policies'])) {
        $policiesRaw = is_string($body['policies']) ? json_decode($body['policies'], true) : $body['policies'];
        if (!is_array($policiesRaw)) $policiesRaw = [];
    }

    $privacyUrl = $config['privacyPolicyUrl'] ?? '';
    $cookiesUrl = $config['cookiesPolicyUrl'] ?? '';
    $retention = $config['dataRetentionPolicy'] ?? '';

    if ($policiesRaw !== null) {
        foreach ($policiesRaw as $p) {
            if (!is_array($p)) continue;
            $t = $p['type'] ?? '';
            if ($t === 'privacy' && empty($privacyUrl) && !empty($p['url'])) $privacyUrl = $p['url'];
            if ($t === 'cookies' && empty($cookiesUrl) && !empty($p['url'])) $cookiesUrl = $p['url'];
            if ($t === 'retention' && empty($retention) && !empty($p['content'])) $retention = $p['content'];
        }
    } else {
        $policiesRaw = $config['policies'] ?? [];
        if (!empty($body['privacyPolicyUrl'])) $privacyUrl = $body['privacyPolicyUrl'];
        if (!empty($body['cookiesPolicyUrl'])) $cookiesUrl = $body['cookiesPolicyUrl'];
        if (!empty($body['dataRetentionPolicy'])) $retention = $body['dataRetentionPolicy'];
    }

    $updates = [
        'privacyPolicyUrl' => $privacyUrl,
        'cookiesPolicyUrl' => $cookiesUrl,
        'dataRetentionPolicy' => $retention,
        'policies' => $policiesRaw,
        'dpdName' => $body['dpdName'] ?? $config['dpdName'] ?? '',
        'dpdRut' => $body['dpdRut'] ?? $config['dpdRut'] ?? '',
        'dpdEmail' => $body['dpdEmail'] ?? $config['dpdEmail'] ?? '',
        'dpdPhone' => $body['dpdPhone'] ?? $config['dpdPhone'] ?? '',
        'dpdTitle' => $body['dpdTitle'] ?? $config['dpdTitle'] ?? '',
        'companyName' => $body['companyName'] ?? $config['companyName'] ?? '',
        'companyRut' => $body['companyRut'] ?? $config['companyRut'] ?? '',
        'dpdAddress' => $body['dpdAddress'] ?? $config['dpdAddress'] ?? '',
        'dpdPublicUrl' => $body['dpdPublicUrl'] ?? $config['dpdPublicUrl'] ?? '',
        'apdpRegistered' => $body['apdpRegistered'] ?? $config['apdpRegistered'] ?? '',
        'apdpRegistrationNumber' => $body['apdpRegistrationNumber'] ?? $config['apdpRegistrationNumber'] ?? '',
        'apdpRegistrationDate' => $body['apdpRegistrationDate'] ?? $config['apdpRegistrationDate'] ?? '',
        'complianceLevel' => $body['complianceLevel'] ?? $config['complianceLevel'] ?? '',
        'preventionModelDate' => $body['preventionModelDate'] ?? $config['preventionModelDate'] ?? '',
        'measureOverrides' => $body['measureOverrides'] ?? $config['measureOverrides'] ?? '',
    ];

    if ($existing) {
        $updates['updatedAt'] = date('c');
        $db->updateOne('compliance_config', ['_id' => $existing['_id']], $updates);
    } else {
        $updates['userId'] = $user['_id'];
        $updates['createdAt'] = date('c');
        $db->insertOne('compliance_config', $updates);
    }

    json_response(['success' => true, 'message' => 'Configuración actualizada']);
}

function getConfig() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);
    $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];

    $config = $db->findOne('compliance_config', $filter) ?? [];
    json_response($config);
}

// ─── Invitaciones públicas ──────────────────────────────────────────
function verifyInvite() {
    $body = get_body();
    $inviteToken = $body['token'] ?? '';
    if (!$inviteToken) json_error('token requerido');

    $db = Database::getInstance();
    $invite = $db->findOne('compliance_invites', ['token' => $inviteToken]);
    if (!$invite) json_error('invitación no encontrada');
    if (!empty($invite['signed'])) json_error('documento ya firmado');

    json_response([
        'title' => $invite['title'] ?? 'Documento de Compliance',
        'description' => $invite['description'] ?? '',
        'companyName' => $invite['companyName'] ?? '',
    ]);
}

function sign() {
    $body = get_body();
    $inviteToken = $body['inviteToken'] ?? '';
    $signature = $body['signature'] ?? '';
    $name = $body['name'] ?? '';

    if (!$inviteToken || !$signature) json_error('datos requeridos');

    $db = Database::getInstance();
    $invite = $db->findOne('compliance_invites', ['token' => $inviteToken]);
    if (!$invite) json_error('invitación no encontrada');
    if (!empty($invite['signed'])) json_error('documento ya firmado');

    $db->updateOne('compliance_invites', ['token' => $inviteToken], [
        'signed' => true,
        'signature' => $signature,
        'signatureType' => str_starts_with($signature, 'data:image/') ? 'image' : 'text',
        'signerName' => $name,
        'signedAt' => date('c'),
    ]);

    json_response(['success' => true]);
}

// ─── Protocolo de brechas ──────────────────────────────────────────
function saveBreachProtocol($user, $db, $body) {
    $protocolData = [
        'userId' => $user['_id'],
        'protocolName' => $body['protocolName'] ?? '',
        'protocolVersion' => $body['protocolVersion'] ?? '',
        'approvalDate' => $body['approvalDate'] ?? '',
        'nextReviewDate' => $body['nextReviewDate'] ?? '',
        'protocolOwner' => $body['protocolOwner'] ?? '',
        'approvedBy' => $body['approvedBy'] ?? '',
        'scope' => $body['scope'] ?? '',
        'definitions' => $body['definitions'] ?? '',
        'detectionChannels' => $body['detectionChannels'] ?? '',
        'maxDetectionTime' => $body['maxDetectionTime'] ?? '',
        'severityLevels' => $body['severityLevels'] ?? [],
        'incidentTypes' => $body['incidentTypes'] ?? '',
        'internalReporting' => $body['internalReporting'] ?? '',
        'csirtTeam' => $body['csirtTeam'] ?? '',
        'containmentActions' => $body['containmentActions'] ?? '',
        'evidencePreservation' => $body['evidencePreservation'] ?? '',
        'maxContainmentTime' => $body['maxContainmentTime'] ?? '',
        'autoEscalation' => $body['autoEscalation'] ?? '',
        'assessmentMethodology' => $body['assessmentMethodology'] ?? '',
        'apdpCriteria' => $body['apdpCriteria'] ?? '',
        'subjectCriteria' => $body['subjectCriteria'] ?? '',
        'likelyConsequences' => $body['likelyConsequences'] ?? '',
        'apdpNotification' => $body['apdpNotification'] ?? '',
        'subjectNotification' => $body['subjectNotification'] ?? '',
        'thirdPartyNotification' => $body['thirdPartyNotification'] ?? '',
        'externalCommunication' => $body['externalCommunication'] ?? '',
        'communicationTemplates' => $body['communicationTemplates'] ?? '',
        'recoveryPlan' => $body['recoveryPlan'] ?? '',
        'closureCriteria' => $body['closureCriteria'] ?? '',
        'correctivePreventive' => $body['correctivePreventive'] ?? '',
        'rtoRpo' => $body['rtoRpo'] ?? '',
        'postmortemReport' => $body['postmortemReport'] ?? '',
        'lessonsLearned' => $body['lessonsLearned'] ?? '',
        'protocolUpdate' => $body['protocolUpdate'] ?? '',
        'drillsTesting' => $body['drillsTesting'] ?? '',
        'annexes' => $body['annexes'] ?? '',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ];

    $existing = $db->findOne('compliance_breach_protocol', ['userId' => $user['_id']]);
    if ($existing) {
        $protocolData['updatedAt'] = date('c');
        $db->updateOne('compliance_breach_protocol', ['_id' => $existing['_id']], $protocolData);
    } else {
        $db->insertOne('compliance_breach_protocol', $protocolData);
    }

    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    $config['breachProtocolContent'] = 'documented';
    $config['breachProtocolUpdatedAt'] = date('c');
    if (empty($config)) {
        $config['userId'] = $user['_id'];
        $db->insertOne('compliance_config', $config);
    } else {
        $db->updateOne('compliance_config', ['_id' => $config['_id']], $config);
    }

    audit_log('breach_protocol_saved', [
        'protocolName' => $protocolData['protocolName'],
        'protocolVersion' => $protocolData['protocolVersion'],
    ], $user['_id']);

    json_response(['success' => true, 'message' => 'Protocolo de brechas guardado correctamente']);
}

function getBreachProtocol($user, $db) {
    $protocol = $db->findOne('compliance_breach_protocol', ['userId' => $user['_id']]);
    if ($protocol) {
        unset($protocol['_id']);
        unset($protocol['userId']);
    }
    json_response($protocol ?? []);
}

// ─── Plan de Respuesta a Incidentes ────────────────────────────────
function saveIncidentResponse($user, $db, $body) {
    $planData = [
        'userId' => $user['_id'],
        'planName' => $body['planName'] ?? '',
        'planVersion' => $body['planVersion'] ?? '',
        'approvalDate' => $body['approvalDate'] ?? '',
        'nextReviewDate' => $body['nextReviewDate'] ?? '',
        'planOwner' => $body['planOwner'] ?? '',
        'scope' => $body['scope'] ?? '',
        'references' => $body['references'] ?? '',
        'csirtRoles' => $body['csirtRoles'] ?? '',
        'csirtPhone24' => $body['csirtPhone24'] ?? '',
        'csirtEmail24' => $body['csirtEmail24'] ?? '',
        'escalationToManagement' => $body['escalationToManagement'] ?? '',
        'detectionChannels' => $body['detectionChannels'] ?? '',
        'maxDetectionTime' => $body['maxDetectionTime'] ?? '',
        'severityClassification' => $body['severityClassification'] ?? [],
        'containmentActions' => $body['containmentActions'] ?? '',
        'evidencePreservation' => $body['evidencePreservation'] ?? '',
        'apdpNotification' => $body['apdpNotification'] ?? '',
        'subjectNotification' => $body['subjectNotification'] ?? '',
        'thirdPartyNotification' => $body['thirdPartyNotification'] ?? '',
        'externalCommunication' => $body['externalCommunication'] ?? '',
        'recoveryPlan' => $body['recoveryPlan'] ?? '',
        'closureCriteria' => $body['closureCriteria'] ?? '',
        'rtoRpo' => $body['rtoRpo'] ?? '',
        'correctivePreventive' => $body['correctivePreventive'] ?? '',
        'postmortemReport' => $body['postmortemReport'] ?? '',
        'lessonsLearned' => $body['lessonsLearned'] ?? '',
        'planUpdate' => $body['planUpdate'] ?? '',
        'drillsTesting' => $body['drillsTesting'] ?? '',
        'annexes' => $body['annexes'] ?? '',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
    ];

    $existing = $db->findOne('compliance_incident_response', ['userId' => $user['_id']]);
    if ($existing) {
        $planData['updatedAt'] = date('c');
        $db->updateOne('compliance_incident_response', ['_id' => $existing['_id']], $planData);
    } else {
        $db->insertOne('compliance_incident_response', $planData);
    }

    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    $config['incidentResponsePlan'] = 'documented';
    $config['incidentResponsePlanUpdatedAt'] = date('c');
    if (empty($config)) {
        $config['userId'] = $user['_id'];
        $db->insertOne('compliance_config', $config);
    } else {
        $db->updateOne('compliance_config', ['_id' => $config['_id']], $config);
    }

    audit_log('incident_response_saved', [
        'planName' => $planData['planName'],
        'planVersion' => $planData['planVersion'],
    ], $user['_id']);

    json_response(['success' => true, 'message' => 'Plan de respuesta a incidentes guardado correctamente']);
}

function getIncidentResponse($user, $db) {
    $plan = $db->findOne('compliance_incident_response', ['userId' => $user['_id']]);
    if ($plan) {
        unset($plan['_id']);
        unset($plan['userId']);
    }
    json_response($plan ?? []);
}

// ─── CRUD principal ─────────────────────────────────────────────────
function crud() {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $prefix = '/api/invisia/compliance/';
    if (strpos($uri, $prefix) !== 0) {
        $prefix = '/api/compliance/';
    }
    $path = trim(substr($uri, strlen($prefix)), '/');
    $segments = explode('/', $path);
    $method = $_SERVER['REQUEST_METHOD'];
    $body = get_body();

    if (empty($segments[0])) json_error('ruta inválida');

    $resource = $segments[0];
    $id = $segments[1] ?? '';
    $action = $segments[2] ?? '';

    if ($id === 'pdf' && empty($action)) {
        $action = 'pdf';
        $id = '';
    }

    $db = Database::getInstance();

    // ── Public endpoints ──
    if ($resource === 'public' && $segments[1] === 'invites') {
        $token = $segments[2] ?? '';
        if ($segments[3] === 'submit' && $method === 'POST') {
            publicInviteSubmit($token, $body, $db);
        }
        publicInviteGet($token, $db);
    }
    if ($resource === 'portability' && $id === 'export') {
        portabilityExport($body, $db);
    }
    if ($resource === 'transfer-validation') {
        transferValidation($body);
    }
    if ($resource === 'companies' && $id === 'search') {
        searchCompaniesPublic($body, $db);
    }

    $user = Auth::requireAuth();
    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);

    // ─── Manejo de PDFs para cualquier recurso soportado ────
    // ✅ NUEVO: 'dpia' agregado
    $pdfResources = ['consents', 'inventory', 'breaches', 'trainings', 'pseudonymization',
                      'arco-requests', 'arco', 'incident_response', 'breach_protocol',
                      'apdp', 'privacy', 'dpd', 'incident-response', 'breach-protocol',
                      'dpia'];

    $normalizedResource = str_replace('_', '-', $resource);

    if ($action === 'pdf' && in_array($normalizedResource, $pdfResources)) {
        generateCompliancePDF($normalizedResource);
        return;
    }

    // ── Endpoints especiales ──
    if ($resource === 'overview' || $resource === 'stats') {
        $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];
        $data = [
            'consents' => $db->count('compliance_consents', $filter),
            'inventory' => $db->count('compliance_inventory', $filter),
            'breaches' => $db->count('compliance_breaches', $filter),
            'templates' => $db->count('compliance_templates', $filter),
            'trainings' => $db->count('compliance_trainings', $filter),
            'dpia' => $db->count('compliance_dpia', $filter),
            'dpa' => $db->count('compliance_dpa', $filter),
            'pseudonymization' => $db->count('compliance_pseudonymization', $filter),
            'processors' => $db->count('compliance_processors', $filter),
            'transfers' => $db->count('compliance_transfers', $filter),
        ];
        json_response(['success' => true, 'overview' => $data]);
        return;
    }

    if ($resource === 'config') {
        if ($method === 'GET') {
            $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];
            $cfg = $db->findOne('compliance_config', $filter) ?? [];
            json_response($cfg);
        }
        if ($method === 'POST') {
            updateConfig();
            return;
        }
        json_error('método no soportado', 405);
    }

    if ($resource === 'ropa-export') {
        ropaExport($db);
        return;
    }
    if ($resource === 'labor-clause') {
        laborClause();
        return;
    }
    if ($resource === 'breach-protocol') {
        if ($method === 'POST') {
            saveBreachProtocol($user, $db, $body);
        } else if ($method === 'GET') {
            getBreachProtocol($user, $db);
        } else {
            json_error('método no soportado', 405);
        }
        return;
    }
    if ($resource === 'incident-response') {
        if ($method === 'POST') {
            saveIncidentResponse($user, $db, $body);
        } else if ($method === 'GET') {
            getIncidentResponse($user, $db);
        } else {
            json_error('método no soportado', 405);
        }
        return;
    }
    if ($resource === 'arco-requests') {
        arcoCrud($user, $db, $method, $id, $action, $body);
        return;
    }

    // ─── Checklist ──────────────────────────────────────────────────
    if ($resource === 'checklist') {
        $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];
        if (!$id) {
            $docs = $db->find('compliance_checklist', $filter);
            $sections = [];
            foreach ($docs as $d) {
                if (empty($d['section'])) continue;
                $data = (array)($d['data'] ?? []);
                $hasData = is_array($data) && count(array_filter($data, fn($v) => $v !== '' && $v !== null && $v !== [])) > 0;
                if ($hasData) $sections[] = $d['section'];
            }
            json_response(['success' => true, 'sections' => $sections]);
            return;
        }

        if ($method === 'GET') {
            $doc = $db->findOne('compliance_checklist', ['userId' => ['$in' => $userIds], 'section' => $id]);
            json_response((array)($doc['data'] ?? []));
            return;
        }

        if ($method === 'POST') {
            $data = $body;
            unset($data['token']);
            $existing = $db->findOne('compliance_checklist', ['userId' => $user['_id'], 'section' => $id]);
            $doc = [
                'userId' => $user['_id'],
                'section' => $id,
                'data' => $data,
                'updatedAt' => date('c'),
            ];
            if ($existing) {
                $db->updateOne('compliance_checklist', ['_id' => $existing['_id']], $doc);
            } else {
                $doc['createdAt'] = date('c');
                $db->insertOne('compliance_checklist', $doc);
            }
            json_response(['success' => true, 'message' => 'Documentación guardada']);
            return;
        }

        if ($method === 'DELETE') {
            $db->deleteOne('compliance_checklist', ['userId' => $user['_id'], 'section' => $id]);
            if ($id === 'breach_protocol') {
                $db->deleteOne('compliance_breach_protocol', ['userId' => $user['_id']]);
            }
            if ($id === 'incident_response') {
                $db->deleteOne('compliance_incident_response', ['userId' => $user['_id']]);
            }
            if ($id === 'dpd') {
                $db->updateOne('compliance_config', ['userId' => $user['_id']], ['dpdName' => null, 'dpdEmail' => null, 'dpdPhone' => null]);
            }
            if ($id === 'apdp') {
                $db->updateOne('compliance_config', ['userId' => $user['_id']], ['apdpRegistered' => null, 'apdpRegistrationNumber' => null]);
            }
            if ($id === 'privacy') {
                $db->updateOne('compliance_config', ['userId' => $user['_id']], ['privacyPolicyUrl' => null, 'cookiesPolicyUrl' => null, 'dataRetentionPolicy' => null]);
            }
            if ($id === 'arco') {
                $db->updateOne('compliance_config', ['userId' => $user['_id']], ['arcoChannelUrl' => null]);
            }
            json_response(['success' => true, 'message' => 'Control eliminado']);
            return;
        }
        json_error('método no soportado', 405);
    }

    // ─── Colecciones estándar ──────────────────────────────────────
    $allowedCollections = ['consents', 'inventory', 'breaches', 'templates', 'trainings', 'dpia', 'dpa', 'pseudonymization', 'invites', 'processors', 'transfers', 'public_policy'];
    if (!in_array($resource, $allowedCollections)) {
        json_error('recurso no soportado', 404);
    }

    $collection = 'compliance_' . $resource;

    // Bulk import
    if ($id === 'bulk' && $method === 'POST') {
        $items = $body['items'] ?? $body['invites'] ?? $body ?? [];
        if (!is_array($items) || empty($items)) json_error('items requerido');
        $created = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $doc = $item;
            unset($doc['token']);
            $doc['userId'] = $user['_id'];
            $doc['createdAt'] = date('c');
            if ($resource === 'invites') {
                $doc['token'] = bin2hex(random_bytes(16));
                $doc['signed'] = false;
                $doc['companyName'] = $doc['companyName'] ?? ($user['companyName'] ?? '');
            }
            $created[] = $db->insertOne($collection, $doc);
        }
        json_response(['success' => true, 'created' => count($created), 'items' => $created]);
        return;
    }

    // Asignación de firma a capacitación
    if ($resource === 'invites' && $id && $action === 'assign-training' && $method === 'POST') {
        $invite = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$invite) json_error('invitación no encontrada', 404);
        if (empty($invite['signed'])) json_error('la invitación aún no está firmada');
        $trainingId = $body['trainingId'] ?? '';
        if (!$trainingId) json_error('trainingId requerido');
        $training = $db->findOne('compliance_trainings', ['_id' => $trainingId, 'userId' => $user['_id']]);
        if (!$training) json_error('capacitación no encontrada', 404);

        $db->updateOne('compliance_trainings', ['_id' => $trainingId], [
            'signature' => $invite['signature'] ?? '',
            'signatureType' => $invite['signatureType'] ?? 'image',
            'signerName' => $invite['signerName'] ?? '',
            'signedAt' => $invite['signedAt'] ?? date('c'),
            'inviteId' => $id,
            'signatureAssignedAt' => date('c'),
            'completed' => true,
            'completedAt' => date('c'),
        ]);
        $db->updateOne($collection, ['_id' => $id], [
            'assignedTrainingId' => $trainingId,
            'assignedTrainingName' => $training['title'] ?? '',
            'assignedAt' => date('c'),
        ]);
        json_response(['success' => true]);
        return;
    }

    // Desasignar firma
    if ($resource === 'invites' && $id && $action === 'unassign' && $method === 'POST') {
        $invite = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$invite) json_error('invitación no encontrada', 404);
        $clearTraining = [
            'signature' => null,
            'signatureType' => null,
            'signerName' => null,
            'signedAt' => null,
            'inviteId' => null,
            'signatureAssignedAt' => null,
            'completed' => false,
            'completedAt' => null,
        ];
        $linked = $invite['assignedTrainingId'] ?? '';
        if ($linked) {
            $db->updateOne('compliance_trainings', ['_id' => $linked], $clearTraining);
        }
        $db->updateOne('compliance_trainings', ['inviteId' => $id], $clearTraining);
        $db->updateOne($collection, ['_id' => $id], [
            'assignedTrainingId' => null,
            'assignedTrainingName' => null,
            'assignedAt' => null,
        ]);
        json_response(['success' => true]);
        return;
    }

    // GET list (filtrado por empresa)
    if ($method === 'GET' && !$id) {
        $filter = $isSuperAdmin ? [] : ['userId' => ['$in' => $userIds]];
        if (!empty($_GET['active'])) $filter['active'] = filter_var($_GET['active'], FILTER_VALIDATE_BOOLEAN);
        $items = $db->find($collection, $filter);
        if (!empty($_GET['search'])) {
            $search = strtolower($_GET['search']);
            $items = array_filter($items, fn($it) =>
                str_contains(strtolower($it['name'] ?? ''), $search) ||
                str_contains(strtolower($it['email'] ?? ''), $search) ||
                str_contains(strtolower($it['title'] ?? ''), $search) ||
                str_contains(strtolower($it['description'] ?? ''), $search)
            );
            $items = array_values($items);
        }
        json_response($items);
        return;
    }

    // GET one
    if ($method === 'GET' && $id) {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['userId'] = ['$in' => $userIds];
        $item = $db->findOne($collection, $filter);
        if (!$item) json_error('elemento no encontrado', 404);
        json_response($item);
        return;
    }

    // POST (create)
    if ($method === 'POST' && !$id) {
        $item = $body;
        unset($item['token']);
        $item['userId'] = $user['_id'];
        $item['createdAt'] = date('c');
        if ($resource === 'invites') {
            $item['token'] = bin2hex(random_bytes(16));
            $item['signed'] = false;
        }
        $created = $db->insertOne($collection, $item);
        json_response(['success' => true, $resource => $created]);
        return;
    }

    // PUT (update)
    if ($method === 'PUT' && $id) {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['userId'] = ['$in' => $userIds];
        $existing = $db->findOne($collection, $filter);
        if (!$existing) json_error('elemento no encontrado', 404);
        $updates = $body;
        unset($updates['_id'], $updates['userId']);
        $updates['updatedAt'] = date('c');
        $db->updateOne($collection, ['_id' => $id], $updates);
        json_response(['success' => true]);
        return;
    }

    // DELETE one (solo del usuario autenticado)
    if ($method === 'DELETE' && $id) {
        $existing = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$existing) json_error('elemento no encontrado o no pertenece al usuario', 404);
        $db->deleteOne($collection, ['_id' => $id]);
        json_response(['success' => true]);
        return;
    }

    // DELETE all (solo del usuario autenticado)
    if ($method === 'DELETE' && !$id) {
        $all = $db->find($collection, ['userId' => $user['_id']]);
        foreach ($all as $it) $db->deleteOne($collection, ['_id' => $it['_id']]);
        json_response(['success' => true, 'deleted' => count($all)]);
        return;
    }

    // Acciones sobre un elemento (POST con action)
    if ($method === 'POST' && $id && $action) {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['userId'] = ['$in' => $userIds];
        $existing = $db->findOne($collection, $filter);
        if (!$existing) json_error('elemento no encontrado', 404);

        $actionUpdates = ['updatedAt' => date('c')];
        $extra = $body['response'] ?? $body['notes'] ?? '';
        switch ($action) {
            case 'revoke': $actionUpdates = ['active' => false, 'revokedAt' => date('c')] + $actionUpdates; break;
            case 'resolve': $actionUpdates = ['status' => 'resolved', 'resolvedAt' => date('c'), 'resolution' => $extra] + $actionUpdates; break;

            // ✅ NUEVO: aprobación de DPIA solo por DPO/DPD/superadmin
            case 'approve':
                if ($resource === 'dpia' && !isDpoOrDpd($user, $db)) {
                    json_error('Solo el DPO/DPD puede aprobar evaluaciones de impacto', 403);
                }
                $actionUpdates = [
                    'status'         => 'approved',
                    'approvedAt'     => date('c'),
                    'approvedBy'     => (string)$user['_id'],
                    'approvedByRole' => $user['role'] ?? 'dpo',
                    'approvedByName' => $user['name'] ?? ($user['email'] ?? ''),
                ] + $actionUpdates;
                break;

            // ✅ NUEVO: rechazo de DPIA solo por DPO/DPD/superadmin
            case 'reject':
                if ($resource === 'dpia' && !isDpoOrDpd($user, $db)) {
                    json_error('Solo el DPO/DPD puede rechazar evaluaciones de impacto', 403);
                }
                $actionUpdates = [
                    'status'          => 'rejected',
                    'rejectedAt'      => date('c'),
                    'rejectedBy'      => (string)$user['_id'],
                    'rejectedByRole'  => $user['role'] ?? 'dpo',
                    'rejectionReason' => $extra,
                ] + $actionUpdates;
                break;

            case 'complete': $actionUpdates = ['completed' => true, 'completedAt' => date('c')] + $actionUpdates; break;
            case 'unsign':
                $actionUpdates = ['signed' => false, 'unsignedAt' => date('c')] + $actionUpdates;
                if ($resource === 'invites') {
                    $db->updateOne('compliance_trainings', ['inviteId' => $id], [
                        'signature' => null, 'signatureType' => null, 'signerName' => null,
                        'signedAt' => null, 'inviteId' => null, 'signatureAssignedAt' => null,
                        'completed' => false, 'completedAt' => null,
                    ]);
                    $db->updateOne($collection, ['_id' => $id], [
                        'assignedTrainingId' => null, 'assignedTrainingName' => null, 'assignedAt' => null,
                    ]);
                }
                break;
            case 'execute': $actionUpdates = ['executed' => true, 'executedAt' => date('c')] + $actionUpdates; break;
            case 'revert': $actionUpdates = ['executed' => false, 'revertedAt' => date('c')] + $actionUpdates; break;
            case 'notify_apdp':
                $actionUpdates = [
                    'notifiedAPDP' => true,
                    'apdpNotifiedAt' => date('c'),
                    'apdpNotificationMethod' => $body['method'] ?? 'portal',
                    'apdpNotificationRef' => $body['ref'] ?? '',
                ] + $actionUpdates;
                break;
            case 'notify_subjects':
                $actionUpdates = [
                    'notifiedSubjects' => true,
                    'subjectsNotifiedAt' => date('c'),
                    'notificationChannel' => $body['channel'] ?? 'email',
                    'notificationRef' => $body['ref'] ?? '',
                ] + $actionUpdates;
                break;
            default: json_error('acción no soportada', 400);
        }
        $db->updateOne($collection, ['_id' => $id], $actionUpdates);
        json_response(['success' => true]);
        return;
    }

    json_error('método no soportado', 405);
}

// ─── ARCO CRUD ──────────────────────────────────────────────────────
function arcoCrud($user, $db, $method, $id, $action, $body) {
    $collection = 'arco_requests';
    $userIds = getCompanyUserIds($user, $db);
    $isSuperAdmin = ($userIds === null);

    if ($method === 'GET' && !$id) {
        $filter = $isSuperAdmin ? [] : ['companyId' => ['$in' => $userIds]];
        $items = $db->find($collection, $filter);
        json_response($items);
        return;
    }
    if ($method === 'GET' && $id) {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['companyId'] = ['$in' => $userIds];
        $item = $db->findOne($collection, $filter);
        if (!$item) json_error('solicitud no encontrada', 404);
        json_response($item);
        return;
    }
    if ($method === 'POST' && $id && in_array($action, ['respond', 'reject'])) {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['companyId'] = ['$in' => $userIds];
        $req = $db->findOne($collection, $filter);
        if (!$req) json_error('solicitud no encontrada', 404);
        $status = $action === 'respond' ? 'resolved' : 'rejected';
        $response = $body['response'] ?? '';
        $db->updateOne($collection, ['_id' => $id], [
            'status' => $status,
            'response' => $response,
            'resolvedAt' => date('c'),
            'resolvedBy' => $user['_id'],
        ]);
        json_response(['success' => true]);
        return;
    }
    if ($method === 'POST' && $action === 'generate-response') {
        $filter = ['_id' => $id];
        if (!$isSuperAdmin) $filter['companyId'] = ['$in' => $userIds];
        $req = $db->findOne($collection, $filter);
        if (!$req) json_error('solicitud no encontrada', 404);
        $response = 'Respuesta generada automáticamente conforme a la Ley 21.719.';
        $db->updateOne($collection, ['_id' => $id], ['response' => $response, 'status' => 'in_review']);
        json_response(['success' => true, 'response' => $response]);
        return;
    }
    json_error('método no soportado para ARCO', 405);
}

// ─── Funciones públicas y exportaciones ────────────────────────────
function publicInviteGet($token, $db) {
    if (!$token) json_error('token requerido');
    $invite = $db->findOne('compliance_invites', ['token' => $token]);
    if (!$invite) json_error('invitación no encontrada', 404);
    json_response([
        'title' => $invite['title'] ?? 'Documento de Compliance',
        'description' => $invite['description'] ?? '',
        'companyName' => $invite['companyName'] ?? '',
    ]);
}

function publicInviteSubmit($token, $body, $db) {
    if (!$token) json_error('token requerido');
    $invite = $db->findOne('compliance_invites', ['token' => $token]);
    if (!$invite) json_error('invitación no encontrada', 404);
    if (!empty($invite['signed'])) json_error('documento ya firmado');

    $db->updateOne('compliance_invites', ['token' => $token], [
        'signed' => true,
        'signature' => $body['signature'] ?? '',
        'signatureType' => str_starts_with($body['signature'] ?? '', 'data:image/') ? 'image' : 'text',
        'signerName' => $body['name'] ?? ($body['signerName'] ?? ''),
        'signerEmail' => $body['email'] ?? '',
        'signedAt' => date('c'),
    ]);
    json_response(['success' => true]);
}

function ropaExport($db) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ropa-export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Recurso', 'Registros']);
    $collections = ['compliance_consents','compliance_inventory','compliance_breaches','compliance_templates','compliance_trainings','compliance_dpia','compliance_dpa','compliance_pseudonymization','compliance_processors','compliance_transfers'];
    foreach ($collections as $c) {
        fputcsv($out, [$c, $db->count($c)]);
    }
    fclose($out);
    exit;
}

function laborClause() {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="labor-clause.pdf"');
    echo "PDF de cláusula laboral en construcción.";
    exit;
}

function portabilityExport($body, $db) {
    $email = strtolower(trim($body['titularEmail'] ?? ''));
    $format = $body['format'] ?? 'json';
    if (!$email) json_error('titularEmail requerido');
    $user = $db->findOne('users', ['email' => $email]);
    if (!$user) json_error('titular no encontrado', 404);
    unset($user['password']);
    $data = [
        'titular' => $user,
        'alerts' => $db->find('alerts', ['userId' => $user['_id']]),
        'payments' => $db->find('payments', ['userId' => $user['_id']]),
        'arcoRequests' => $db->find('arco_requests', ['companyId' => $user['_id']]),
    ];
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="portabilidad.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Campo', 'Valor']);
        fputcsv($out, ['titular', json_encode($data['titular'])]);
        fputcsv($out, ['alerts', json_encode($data['alerts'])]);
        fputcsv($out, ['payments', json_encode($data['payments'])]);
        fclose($out);
        exit;
    }
    json_response(['success' => true, 'data' => $data]);
}

function transferValidation($body) {
    $country = $body['country'] ?? '';
    $adequate = in_array(strtolower($country), ['andorra', 'argentina', 'canada', 'faeroe islands', 'guernsey', 'israel', 'isle of man', 'jersey', 'new zealand', 'republic of korea', 'switzerland', 'united kingdom', 'uruguay', 'usa']);
    json_response([
        'allowed' => $adequate,
        'adequacy' => $adequate,
        'safeguards' => $adequate ? 'decisión de adecuación' : 'garantías adicionales necesarias',
        'message' => $adequate ? 'Transferencia permitida' : 'Se requieren garantías suplementarias para transferir datos',
    ]);
}

function searchCompaniesPublic($body, $db) {
    $query = strtolower(trim($body['q'] ?? ''));
    if (strlen($query) < 2) {
        json_response(['companies' => []]);
    }
    $configs = $db->find('compliance_config', []);
    $results = [];
    foreach ($configs as $cfg) {
        $name = strtolower($cfg['companyName'] ?? '');
        if (str_contains($name, $query)) {
            $results[] = [
                '_id' => $cfg['userId'] ?? '',
                'name' => $cfg['companyName'] ?? '',
                'email' => $cfg['dpdEmail'] ?? '',
                'city' => $cfg['city'] ?? '',
            ];
        }
    }
    $users = $db->find('users', []);
    foreach ($users as $u) {
        $name = strtolower($u['companyName'] ?? '');
        if (str_contains($name, $query)) {
            $exists = false;
            foreach ($results as $r) {
                if ($r['_id'] === ($u['_id'] ?? '')) { $exists = true; break; }
            }
            if (!$exists) {
                $results[] = [
                    '_id' => $u['_id'] ?? '',
                    'name' => $u['companyName'] ?? '',
                    'email' => $u['email'] ?? '',
                    'city' => $u['city'] ?? '',
                ];
            }
        }
    }
    json_response(['companies' => array_slice($results, 0, 10)]);
}

// ─── Generación de PDF para cumplimiento ──────────────────────────
function generateCompliancePDF($resource) {
    try {
        $user = Auth::requireAuth();
        $db = Database::getInstance();

        require_once __DIR__ . '/../PDFGenerator.php';
        $pdfGenerator = new PDFGenerator($db, $user);

        $itemId = $_GET['id'] ?? null;

        switch ($resource) {
            case 'consents':
                $html = $pdfGenerator->generateConsentPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'consentimientos');
                break;
            case 'inventory':
                $html = $pdfGenerator->generateInventoryPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'inventario');
                break;
            case 'breaches':
                $html = $pdfGenerator->generateBreachesPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'brechas');
                break;
            case 'trainings':
                $html = $pdfGenerator->generateTrainingsPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'capacitaciones');
                break;
            case 'pseudonymization':
                $html = $pdfGenerator->generatePseudonymizationPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'seudonimizacion');
                break;
            case 'arco-requests':
                $html = $pdfGenerator->generateARCORequestsPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'solicitudes-arco');
                break;

            // ✅ NUEVO: case para generar PDF de DPIA
            case 'dpia':
                $html = $pdfGenerator->generateDPIAPDF($itemId);
                $result = $pdfGenerator->generatePDFFile($html, 'dpia');
                break;

            case 'arco':
                $arcoDoc = $db->findOne('compliance_checklist', ['userId' => $user['_id'], 'section' => 'arco']);
                $arcoData = (array)($arcoDoc['data'] ?? []);
                array_walk_recursive($arcoData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Canal de Derechos ARCO', $arcoData);
                $result = $pdfGenerator->generatePDFFile($html, 'arco');
                break;
            case 'incident-response':
            case 'incident_response':
                $irDoc = $db->findOne('compliance_incident_response', ['userId' => $user['_id']]) ?? $db->findOne('compliance_checklist', ['userId' => $user['_id'], 'section' => 'incident_response']);
                $irData = (array)(!empty($irDoc['data']) ? $irDoc['data'] : $irDoc);
                array_walk_recursive($irData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Plan de Respuesta a Incidentes', $irData);
                $result = $pdfGenerator->generatePDFFile($html, 'respuesta-incidentes');
                break;
            case 'breach-protocol':
            case 'breach_protocol':
                $bpDoc = $db->findOne('compliance_breach_protocol', ['userId' => $user['_id']]) ?? [];
                $bpCfg = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
                $bpData = array_merge((array)$bpDoc, array_filter([
                    'URL del protocolo' => $bpCfg['breachProtocolUrl'] ?? null,
                    'Contenido del protocolo' => $bpCfg['breachProtocolContent'] ?? null,
                ]));
                array_walk_recursive($bpData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Protocolo de Brechas', $bpData);
                $result = $pdfGenerator->generatePDFFile($html, 'protocolo-brechas');
                break;
            case 'apdp':
                $aCfg = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
                $aData = array_filter([
                    'Registrado ante la APDP' => isset($aCfg['apdpRegistered']) ? ($aCfg['apdpRegistered'] ? 'Sí' : 'No') : null,
                    'Número de registro' => $aCfg['apdpRegistrationNumber'] ?? null,
                    'Fecha de registro' => $aCfg['apdpRegistrationDate'] ?? null,
                    'Entidad certificadora' => $aCfg['apdpEntity'] ?? null,
                    'Observaciones' => $aCfg['apdpNotes'] ?? null,
                ]);
                array_walk_recursive($aData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Modelo de Prevención Certificado (APDP)', $aData);
                $result = $pdfGenerator->generatePDFFile($html, 'modelo-certificado');
                break;
            case 'privacy':
                $pCfg = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
                $pData = array_filter([
                    'URL de la política' => $pCfg['privacyPolicyUrl'] ?? null,
                    'Contenido de la política' => $pCfg['privacyPolicyContent'] ?? null,
                    'URL política de cookies' => $pCfg['cookiesPolicyUrl'] ?? null,
                    'Última actualización' => $pCfg['privacyPolicyUpdatedAt'] ?? ($pCfg['updatedAt'] ?? null),
                ]);
                array_walk_recursive($pData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Política de Privacidad', $pData);
                $result = $pdfGenerator->generatePDFFile($html, 'politica-privacidad');
                break;
            case 'dpd':
                $dCfg = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
                $dData = array_filter([
                    'Nombre del DPD' => $dCfg['dpdName'] ?? null,
                    'Email del DPD' => $dCfg['dpdEmail'] ?? null,
                    'Teléfono' => $dCfg['dpdPhone'] ?? null,
                    'Fecha de designación' => $dCfg['dpdAppointmentDate'] ?? null,
                    'Registro ante APDP' => $dCfg['dpdApdpRecord'] ?? null,
                ]);
                array_walk_recursive($dData, function(&$item) {
                    if (is_array($item) || is_object($item)) {
                        $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                    }
                });
                $html = $pdfGenerator->generateGenericChecklistPDF('Delegado de Protección de Datos (DPD)', $dData);
                $result = $pdfGenerator->generatePDFFile($html, 'dpd-designado');
                break;
            default:
                json_error('Recurso no soportado para generación de PDF', 400);
        }

        json_response([
            'success' => true,
            'pdfUrl' => $result['pdfUrl'] ?? null,
            'pdfBase64' => $result['pdfBase64'] ?? null,
            'html' => $result['html'] ?? null,
            'message' => $result['message'] ?? ''
        ]);
    } catch (\Throwable $e) {
        error_log('generateCompliancePDF error (' . $resource . '): ' . $e->getMessage());
        json_error('No se pudo generar el documento: ' . $e->getMessage(), 500);
    }
}

// ─── Generar política pública ──────────────────────────────────────
function generatePublicPolicy() {
    $token = $_GET['token'] ?? '';
    if (!$token) {
        header('HTTP/1.1 401 Unauthorized');
        echo 'Token requerido';
        exit;
    }

    $decoded = Auth::verifyToken($token);
    if (!$decoded) {
        header('HTTP/1.1 401 Unauthorized');
        echo 'Token inválido';
        exit;
    }

    $db = Database::getInstance();
    $user = $db->findOne('users', ['_id' => $decoded['userId']]);
    if (!$user) {
        header('HTTP/1.1 401 Unauthorized');
        echo 'Usuario no encontrado';
        exit;
    }

    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));
    $dpdName = $config['dpdName'] ?? '—';
    $dpdEmail = $config['dpdEmail'] ?? '—';
    $dpdPhone = $config['dpdPhone'] ?? '—';
    $privacyPolicyUrl = $config['privacyPolicyUrl'] ?? '';
    $cookiesPolicyUrl = $config['cookiesPolicyUrl'] ?? '';
    $dataRetentionPolicy = $config['dataRetentionPolicy'] ?? '';

    $inventory = $db->find('compliance_inventory', ['userId' => $user['_id']]);
    $consents = $db->find('compliance_consents', ['userId' => $user['_id']]);
    $breaches = $db->find('compliance_breaches', ['userId' => $user['_id']]);

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Política de Privacidad - {$companyName}</title>";
    $html .= "<style>
        body{font-family:'Inter',Arial,sans-serif;line-height:1.7;color:#1a1a1a;max-width:900px;margin:0 auto;padding:40px 20px;background:#fafafa}
        .header{border-bottom:2px solid #1a1a1a;padding-bottom:20px;margin-bottom:40px}
        .header h1{font-size:28px;font-weight:700;margin:0 0 10px}
        .header p{color:#555;margin:0}
        .meta{background:#f5f5f5;padding:15px 20px;border-radius:8px;margin-bottom:30px;font-size:14px}
        .meta strong{color:#1a1a1a}
        section{margin-bottom:40px}
        h2{font-size:22px;font-weight:600;color:#1a1a1a;border-left:4px solid #2563eb;padding-left:15px;margin-bottom:15px}
        h3{font-size:18px;font-weight:600;margin:20px 0 10px}
        ul{padding-left:20px}
        li{margin-bottom:8px}
        .dpd-card{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:20px;margin:20px 0}
        .dpd-card h3{margin-top:0;color:#1e40af}
        .footer{border-top:1px solid #ddd;padding-top:20px;margin-top:40px;color:#666;font-size:14px}
        @media print{body{background:#fff;padding:0}.footer{display:none}}
    </style></head><body>";

    $html .= "<div class='header'><h1>Política de Privacidad</h1><p>{$companyName} · Ley 21.719 · Protección de Datos Personales</p></div>";
    $html .= "<div class='meta'><strong>Versión:</strong> 1.0 | <strong>Fecha:</strong> " . date('d/m/Y') . " | <strong>Responsable:</strong> {$companyName}</div>";

    $html .= "<section><h2>1. Identidad del Responsable</h2>";
    $html .= "<p><strong>Nombre:</strong> {$companyName}</p>";
    $html .= "<p><strong>Contacto DPD:</strong> {$dpdName} — {$dpdEmail} — {$dpdPhone}</p>";
    $html .= "</section>";

    $html .= "<section><h2>2. Finalidades y Base Legal del Tratamiento</h2>";
    $html .= "<p>Tratamos sus datos personales para las siguientes finalidades, con la base legal correspondiente:</p>";
    $html .= "<ul>";
    foreach ($inventory as $inv) {
        $purpose = $inv['purpose'] ?? $inv['name'] ?? '';
        $basis = $inv['legalBasis'] ?? '';
        $categories = $inv['dataCategories'] ?? '';
        if (is_array($categories)) $categories = implode(', ', $categories);
        $html .= "<li><strong>{$purpose}</strong> — Base legal: {$basis} — Categorías: {$categories}</li>";
    }
    $html .= "</ul>";
    $html .= "</section>";

    $html .= "<section><h2>3. Categorías de Datos Tratados</h2>";
    $html .= "<p>Según el Art. 14.1.c de la Ley 21.719, las categorías principales son:</p>";
    $html .= "<ul>";
    $catMap = [];
    foreach ($inventory as $inv) {
        $cats = $inv['dataCategories'] ?? '';
        if (is_array($cats)) {
            foreach ($cats as $c) $catMap[$c] = true;
        } else {
            foreach (explode(';', $cats) as $c) $catMap[trim($c)] = true;
        }
    }
    foreach (array_keys($catMap) as $cat) {
        $html .= "<li>{$cat}</li>";
    }
    $html .= "</ul>";
    $html .= "</section>";

    $html .= "<section><h2>4. Derechos del Titular (Art. 4-13 Ley 21.719)</h2>";
    $html .= "<p>Usted puede ejercer los siguientes derechos gratuitamente:</p>";
    $html .= "<ul>";
    $html .= "<li><strong>Acceso (Art. 8):</strong> Obtener confirmación y copia de sus datos.</li>";
    $html .= "<li><strong>Rectificación (Art. 9):</strong> Corregir datos inexactos o incompletos.</li>";
    $html .= "<li><strong>Supresión (Art. 10):</strong> Solicitar eliminación cuando ya no sean necesarios.</li>";
    $html .= "<li><strong>Oposición (Art. 11):</strong> Oponerse al tratamiento en ciertos casos.</li>";
    $html .= "<li><strong>Portabilidad (Art. 13):</strong> Recibir sus datos en formato estructurado.</li>";
    $html .= "<li><strong>Bloqueo (Art. 8 ter):</strong> Suspender temporalmente el tratamiento.</li>";
    $html .= "</ul>";
    $html .= "<p>Para ejercer sus derechos, contacte al DPD en: {$dpdEmail}</p>";
    $html .= "</section>";

    $html .= "<section><h2>5. Consentimiento (Art. 12)</h2>";
    $html .= "<p>Cuando el tratamiento se base en consentimiento, este es libre, informado, específico, previo e inequívoco. Puede revocarlo en cualquier momento contactando al DPD.</p>";
    $html .= "<p>Total de consentimientos activos registrados: " . count(array_filter($consents, fn($c) => empty($c['revokedAt']))) . "</p>";
    $html .= "</section>";

    $html .= "<section><h2>6. Cesiones y Transferencias Internacionales (Art. 15, 21, 27)</h2>";
    $html .= "<p>No cedemos datos a terceros salvo obligación legal, ejecución de contrato o consentimiento. Las transferencias internacionales se realizan con garantías adecuadas (decisión de adecuación, cláusulas tipo, BCR).</p>";
    $html .= "</section>";

    $html .= "<section><h2>7. Medidas de Seguridad (Art. 14 quinquies, 25, 26)</h2>";
    $html .= "<p>Implementamos medidas técnicas y organizativas: cifrado, control de acceso, registro de accesos, evaluación de impacto (DPIA), plan de respuesta a incidentes.</p>";
    $html .= "<p>Incidentes de seguridad registrados: " . count($breaches) . " (resueltos: " . count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) . ")</p>";
    $html .= "</section>";

    $html .= "<section><h2>8. Retención de Datos (Art. 14)</h2>";
    $html .= "<p>Los datos se conservan solo el tiempo necesario para la finalidad del tratamiento o mientras exista obligación legal.</p>";
    $html .= "</section>";

    $html .= "<section><h2>9. Delegado de Protección de Datos (Art. 28)</h2>";
    $html .= "<div class='dpd-card'><h3>Contacto DPD</h3>";
    $html .= "<p><strong>Nombre:</strong> {$dpdName}</p>";
    $html .= "<p><strong>Email:</strong> {$dpdEmail}</p>";
    $html .= "<p><strong>Teléfono:</strong> {$dpdPhone}</p>";
    $html .= "</div>";
    $html .= "</section>";

    $html .= "<section><h2>10. Reclamaciones ante la APDP</h2>";
    $html .= "<p>Si considera que sus derechos no han sido respetados, puede presentar reclamación ante la Agencia de Protección de Datos Personales (APDP) en www.apdp.cl</p>";
    $html .= "</section>";

    $html .= "<div class='footer'>";
    $html .= "<p>Política de Privacidad generada automáticamente por SecureLab — Ley 21.719 — Protección de Datos Personales — Chile</p>";
    $html .= "<p>Fecha de última actualización: " . date('d/m/Y') . "</p>";
    $html .= "</div>";

    $html .= "</body></html>";

    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}