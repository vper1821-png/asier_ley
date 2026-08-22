<?php
// Compliance routes
require_once __DIR__ . '/../Auth.php';

function score() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    // Calculate compliance score based on completed items
    $agents = $db->count('agents', ['userId' => $user['_id']]);
    $databases = $db->count('databases', ['userId' => $user['_id']]);
    $alerts = $db->count('alerts', ['userId' => $user['_id']]);
    $onboarding = $db->findOne('onboarding', ['userId' => $user['_id']]);

    $score = 0;
    $details = [];

    // Agent deployment (30%)
    $agentScore = $agents > 0 ? 100 : 0;
    $score += $agentScore * 0.3;
    $details['agents'] = ['label' => 'Agentes desplegados', 'score' => $agentScore];

    // Database monitoring (25%)
    $dbScore = $databases > 0 ? 100 : 0;
    $score += $dbScore * 0.25;
    $details['databases'] = ['label' => 'Bases de datos monitorizadas', 'score' => $dbScore];

    // Onboarding complete (25%)
    $onboardingScore = ($onboarding && !empty($onboarding['completed'])) ? 100 : 0;
    $score += $onboardingScore * 0.25;
    $details['onboarding'] = ['label' => 'Onboarding completado', 'score' => $onboardingScore];

    // Alerts configured (20%)
    $alertScore = $alerts > 0 ? 100 : 0;
    $score += $alertScore * 0.2;
    $details['alerts'] = ['label' => 'Alertas configuradas', 'score' => $alertScore];

    json_response([
        'score' => round($score),
        'details' => $details,
    ]);
}

function detailedChecklist() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    $inventory = $db->find('compliance_inventory', ['userId' => $user['_id']]);
    $consents = $db->find('compliance_consents', ['userId' => $user['_id']]);
    $trainings = $db->find('compliance_trainings', ['userId' => $user['_id']]);
    $dpia = $db->find('compliance_dpia', ['userId' => $user['_id']]);
    $arcoRequests = $db->find('compliance_arco_requests', ['userId' => $user['_id']]);
    $breaches = $db->find('compliance_breaches', ['userId' => $user['_id']]);
    $pseudoRules = $db->find('compliance_pseudonymization', ['userId' => $user['_id']]);
    $processors = $db->find('compliance_processors', ['userId' => $user['_id']]);
    $transfers = $db->find('compliance_transfers', ['userId' => $user['_id']]);

    // Checklist completo basado en la Ley 21.719
    $detailedChecklist = [
        // Identificación y Registro
        [
            'id' => 'dpd',
            'label' => 'DPD Designado',
            'done' => !empty($config['dpdEmail']),
            'link' => '/hardening?tab=dpd',
            'severity' => 'gravisima',
            'fine' => 'Hasta 20.000 UTM'
        ],
        [
            'id' => 'apdp',
            'label' => 'Registro APDP',
            'done' => !empty($config['apdpRegistered']),
            'link' => '/hardening?tab=dpd',
            'severity' => 'gravisima',
            'fine' => 'Hasta 20.000 UTM'
        ],
        // Política de Privacidad
        [
            'id' => 'privacy_policy',
            'label' => 'Política de Privacidad publicada',
            'done' => !empty($config['privacyPolicyUrl']),
            'link' => '/compliance?tab=privacy',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        [
            'id' => 'cookies_policy',
            'label' => 'Política de cookies publicada',
            'done' => !empty($config['cookiesPolicyUrl']),
            'link' => '/compliance?tab=privacy',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        [
            'id' => 'retention_policy',
            'label' => 'Política de retención de datos definida',
            'done' => !empty($config['dataRetentionPolicy']),
            'link' => '/compliance?tab=privacy',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        // Base de Licitud y Consentimiento
        [
            'id' => 'consents',
            'label' => 'Consentimientos registrados',
            'done' => count($consents) > 0,
            'link' => '/compliance?tab=consents',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        // Inventario de Tratamiento
        [
            'id' => 'inventory',
            'label' => 'Inventario de datos registrado',
            'done' => count($inventory) > 0,
            'link' => '/compliance?tab=inventory',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        [
            'id' => 'sensitive_legal_basis',
            'label' => 'Datos sensibles con base legal',
            'done' => count(array_filter($inventory, fn($i) => !empty($i['sensitive']) && !empty($i['legalBasis']))) === count(array_filter($inventory, fn($i) => !empty($i['sensitive']))),
            'link' => '/compliance?tab=inventory',
            'severity' => 'gravisima',
            'fine' => 'Hasta 20.000 UTM'
        ],
        // Protocolo de Brechas
        [
            'id' => 'breaches_protocol',
            'label' => 'Protocolo de notificación de brechas',
            'done' => count($breaches) > 0,
            'link' => '/compliance?tab=breaches',
            'severity' => 'grave',
            'fine' => 'Hasta 10.000 UTM'
        ],
        [
            'id' => 'breaches_resolved',
            'label' => 'Plan de respuesta a incidentes',
            'done' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0,
            'link' => '/compliance?tab=breaches',
            'severity' => 'grave',
            'fine' => 'Hasta 10.000 UTM'
        ],
        // Evaluación de Impacto (DPIA)
        [
            'id' => 'dpia_approved',
            'label' => 'DPIA aprobadas para datos sensibles',
            'done' => count(array_filter($dpia, fn($d) => ($d['status'] ?? '') === 'approved')) > 0,
            'link' => '/compliance?tab=dpia',
            'severity' => 'grave',
            'fine' => 'Hasta 10.000 UTM'
        ],
        // Seudonimización
        [
            'id' => 'pseudonymization',
            'label' => 'Reglas de seudonimización definidas',
            'done' => count($pseudoRules) > 0,
            'link' => '/compliance?tab=pseudonymization',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        // Capacitación
        [
            'id' => 'training_signed',
            'label' => 'Personal capacitado con firma',
            'done' => count(array_filter($trainings, fn($t) => !empty($t['signature']) || !empty($t['inviteId']))) > 0,
            'link' => '/compliance?tab=trainings',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
        // Encargados (DPA)
        [
            'id' => 'processors',
            'label' => 'Acuerdos con encargados (DPA)',
            'done' => count($processors) > 0,
            'link' => '/compliance?tab=processors',
            'severity' => 'grave',
            'fine' => 'Hasta 10.000 UTM'
        ],
        // Transferencias internacionales
        [
            'id' => 'transfers',
            'label' => 'Transferencias internacionales registradas',
            'done' => count($transfers) > 0,
            'link' => '/compliance?tab=transfers',
            'severity' => 'grave',
            'fine' => 'Hasta 10.000 UTM'
        ],
        // Derechos ARCO
        [
            'id' => 'arco_requests',
            'label' => 'Registro de solicitudes ARCO',
            'done' => count($arcoRequests) > 0,
            'link' => '/arco',
            'severity' => 'leve',
            'fine' => 'Hasta 5.000 UTM'
        ],
    ];

    json_response(['checklist' => $detailedChecklist]);
}

function autoSignTraining() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $body = get_body();
    
    $trainingId = $body['trainingId'] ?? '';
    if (!$trainingId) json_error('trainingId requerido');
    
    $training = $db->findOne('compliance_trainings', ['_id' => $trainingId, 'userId' => $user['_id']]);
    if (!$training) json_error('Capacitación no encontrada', 404);
    
    // Crear invitación de firma automáticamente (SIN firmar)
    $inviteToken = bin2hex(random_bytes(16));
    $invite = [
        'userId' => $user['_id'],
        'token' => $inviteToken,
        'title' => $training['title'] ?? 'Capacitación: ' . ($training['title'] ?? ''),
        'description' => 'Firma para capacitación: ' . ($training['title'] ?? ''),
        'companyName' => $user['companyName'] ?? ($user['email'] ?? ''),
        'signed' => false, // NO firmar automáticamente
    ];
    
    $inviteId = $db->insertOne('compliance_invites', $invite);
    
    // Asignar la invitación a la capacitación (SIN firmar)
    $db->updateOne('compliance_trainings', ['_id' => $trainingId], [
        'inviteId' => $inviteId,
        'inviteAssignedAt' => date('c'),
    ]);
    
    json_response(['success' => true, 'message' => 'Invitación de firma creada exitosamente', 'token' => $inviteToken]);
}

function updateConfig() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $body = get_body();
    
    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    
    $updates = [
        'privacyPolicyUrl' => $body['privacyPolicyUrl'] ?? '',
        'cookiesPolicyUrl' => $body['cookiesPolicyUrl'] ?? '',
        'dataRetentionPolicy' => $body['dataRetentionPolicy'] ?? '',
    ];
    
    if (empty($config)) {
        $updates['userId'] = $user['_id'];
        $updates['createdAt'] = date('c');
        $db->insertOne('compliance_config', $updates);
    } else {
        $updates['updatedAt'] = date('c');
        $db->updateOne('compliance_config', ['userId' => $user['_id']], $updates);
    }
    
    json_response(['success' => true, 'message' => 'Configuración actualizada']);
}

function getConfig() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    
    $config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    
    json_response($config);
}

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
    $db = Database::getInstance();

    // Public endpoints (no auth)
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

    // Special non-collection endpoints
    if ($resource === 'overview' || $resource === 'stats') {
        overview($user, $db);
    }
    if ($resource === 'config') {
        if ($method === 'GET') {
            $cfg = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
            json_response($cfg);
        }
        if ($method === 'POST') {
            $existing = $db->findOne('compliance_config', ['userId' => $user['_id']]);
            $data = ['userId' => $user['_id'], 'updatedAt' => date('c')] + $body;
            if ($existing) {
                $db->updateOne('compliance_config', ['_id' => $existing['_id']], $data);
            } else {
                $db->insertOne('compliance_config', $data);
            }
            json_response(['success' => true]);
        }
        json_error('método no soportado', 405);
    }
    if ($resource === 'ropa-export') {
        ropaExport($db);
    }
    if ($resource === 'labor-clause') {
        laborClause();
    }

    // PDF generation endpoints
    if ($action === 'pdf' && in_array($resource, ['dpia', 'dpa'])) {
        json_response(['success' => true, 'message' => 'PDF en construcción', 'url' => $uri]);
    }

    // ARCO requests
    if ($resource === 'arco-requests') {
        arcoCrud($user, $db, $method, $id, $action, $body);
    }

    $allowedCollections = ['consents', 'inventory', 'breaches', 'templates', 'trainings', 'dpia', 'dpa', 'pseudonymization', 'invites', 'processors', 'transfers', 'public_policy'];
    if (!in_array($resource, $allowedCollections)) {
        json_error('recurso no soportado', 404);
    }

    $collection = 'compliance_' . $resource;

    // Bulk import (cualquier colección)
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
    }

    // Asignar la firma de una invitación firmada a una capacitación existente
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
    }

    // Desasignar la firma de una invitación (quita la firma de la capacitación)
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
    }

    // Search list
    if ($method === 'GET' && !$id) {
        $filter = ['userId' => $user['_id']];
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
    }

    if ($method === 'GET' && $id) {
        $item = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$item) json_error('elemento no encontrado', 404);
        json_response($item);
    }

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
    }

    if ($method === 'PUT' && $id) {
        $existing = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$existing) json_error('elemento no encontrado', 404);
        $updates = $body;
        unset($updates['_id'], $updates['userId']);
        $updates['updatedAt'] = date('c');
        $db->updateOne($collection, ['_id' => $id], $updates);
        json_response(['success' => true]);
    }

    if ($method === 'DELETE' && $id) {
        $existing = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$existing) json_error('elemento no encontrado', 404);
        $db->deleteOne($collection, ['_id' => $id]);
        json_response(['success' => true]);
    }

    if ($method === 'DELETE' && !$id) {
        $all = $db->find($collection, ['userId' => $user['_id']]);
        foreach ($all as $it) $db->deleteOne($collection, ['_id' => $it['_id']]);
        json_response(['success' => true, 'deleted' => count($all)]);
    }

    if ($method === 'POST' && $id && $action) {
        $existing = $db->findOne($collection, ['_id' => $id, 'userId' => $user['_id']]);
        if (!$existing) json_error('elemento no encontrado', 404);

        $actionUpdates = ['updatedAt' => date('c')];
        $extra = $body['response'] ?? $body['notes'] ?? '';
        switch ($action) {
            case 'revoke': $actionUpdates = ['active' => false, 'revokedAt' => date('c')] + $actionUpdates; break;
            case 'resolve': $actionUpdates = ['status' => 'resolved', 'resolvedAt' => date('c'), 'resolution' => $extra] + $actionUpdates; break;
            case 'approve': $actionUpdates = ['status' => 'approved', 'approvedAt' => date('c')] + $actionUpdates; break;
            case 'complete': $actionUpdates = ['completed' => true, 'completedAt' => date('c')] + $actionUpdates; break;
            case 'unsign': $actionUpdates = ['signed' => false, 'unsignedAt' => date('c')] + $actionUpdates; break;
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
        if ($resource === 'invites' && $action === 'unsign') {
            $db->updateOne('compliance_trainings', ['inviteId' => $id], [
                'signature' => null, 'signatureType' => null, 'signerName' => null,
                'signedAt' => null, 'inviteId' => null, 'signatureAssignedAt' => null,
                'completed' => false, 'completedAt' => null,
            ]);
            $db->updateOne($collection, ['_id' => $id], [
                'assignedTrainingId' => null, 'assignedTrainingName' => null, 'assignedAt' => null,
            ]);
        }
        json_response(['success' => true]);
    }

    json_error('método no soportado', 405);
}

function overview($user, $db) {
    $data = [
        'consents' => $db->count('compliance_consents', ['userId' => $user['_id'], 'active' => true]),
        'inventory' => $db->count('compliance_inventory', ['userId' => $user['_id']]),
        'breaches' => $db->count('compliance_breaches', ['userId' => $user['_id']]),
        'templates' => $db->count('compliance_templates', ['userId' => $user['_id']]),
        'trainings' => $db->count('compliance_trainings', ['userId' => $user['_id']]),
        'dpia' => $db->count('compliance_dpia', ['userId' => $user['_id']]),
        'dpa' => $db->count('compliance_dpa', ['userId' => $user['_id']]),
        'pseudonymization' => $db->count('compliance_pseudonymization', ['userId' => $user['_id']]),
        'processors' => $db->count('compliance_processors', ['userId' => $user['_id']]),
        'transfers' => $db->count('compliance_transfers', ['userId' => $user['_id']]),
    ];
    json_response(['success' => true, 'overview' => $data]);
}

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

function arcoCrud($user, $db, $method, $id, $action, $body) {
    $collection = 'arco_requests';
    if ($method === 'GET' && !$id) {
        $items = $db->find($collection, ['companyId' => $user['_id']]);
        json_response($items);
    }
    if ($method === 'GET' && $id) {
        $item = $db->findOne($collection, ['_id' => $id, 'companyId' => $user['_id']]);
        if (!$item) json_error('solicitud no encontrada', 404);
        json_response($item);
    }
    if ($method === 'POST' && $id && in_array($action, ['respond', 'reject'])) {
        $req = $db->findOne($collection, ['_id' => $id, 'companyId' => $user['_id']]);
        if (!$req) json_error('solicitud no encontrada', 404);
        $status = $action === 'respond' ? 'resolved' : 'rejected';
        $response = $body['response'] ?? '';
        if ($action === 'generate-response') {
            $response = 'Respuesta generada automáticamente conforme a la Ley 21.719.';
            $status = 'resolved';
        }
        $db->updateOne($collection, ['_id' => $id], [
            'status' => $status,
            'response' => $response,
            'resolvedAt' => date('c'),
            'resolvedBy' => $user['_id'],
        ]);
        audit_log('arco_' . $action, ['arcoId' => $id, 'solicitante' => $req['solicitante'] ?? '', 'tipo' => $req['tipo'] ?? '', 'status' => $status], $user['_id']);
        json_response(['success' => true]);
    }
    if ($method === 'POST' && $action === 'generate-response') {
        $req = $db->findOne($collection, ['_id' => $id, 'companyId' => $user['_id']]);
        if (!$req) json_error('solicitud no encontrada', 404);
        $response = 'Respuesta generada automáticamente conforme a la Ley 21.719.';
        $db->updateOne($collection, ['_id' => $id], ['response' => $response, 'status' => 'in_review']);
        audit_log('arco_generate_response', ['arcoId' => $id, 'solicitante' => $req['solicitante'] ?? '', 'tipo' => $req['tipo'] ?? ''], $user['_id']);
        json_response(['success' => true, 'response' => $response]);
    }
    json_error('método no soportado', 405);
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

function searchCompaniesPublic($body, $db) {
    $query = strtolower(trim($body['q'] ?? ''));
    if (strlen($query) < 2) {
        json_response(['companies' => []]);
    }

    // Buscar en compliance_config (empresas registradas)
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

    // También buscar en users (empresas registradas)
    $users = $db->find('users', []);
    foreach ($users as $u) {
        $name = strtolower($u['companyName'] ?? '');
        if (str_contains($name, $query)) {
            // Evitar duplicados
            $exists = false;
            foreach ($results as $r) {
                if ($r['_id'] === ($u['_id'] ?? '')) {
                    $exists = true;
                    break;
                }
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
