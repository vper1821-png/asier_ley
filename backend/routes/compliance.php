<?php
// Compliance routes

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

    $allowedCollections = ['consents', 'inventory', 'breaches', 'templates', 'trainings', 'dpia', 'dpa', 'pseudonymization', 'invites'];
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
    $collections = ['compliance_consents','compliance_inventory','compliance_breaches','compliance_templates','compliance_trainings','compliance_dpia','compliance_dpa','compliance_pseudonymization'];
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
