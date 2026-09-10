<?php
// ARCO routes

// ─── Helper: convertir BSONDocument/BSONArray/Object a array PHP plano ───
function arcoToArray($value) {
    if ($value === null) return null;

    // BSONDocument y BSONArray implementan Traversable, getArrayCopy(),
    // jsonSerialize() o bsonSerialize() según la versión.
    if (is_object($value)) {
        if (method_exists($value, 'getArrayCopy')) {
            $value = $value->getArrayCopy();
        } elseif (method_exists($value, 'jsonSerialize')) {
            $value = $value->jsonSerialize();
        } elseif (method_exists($value, 'bsonSerialize')) {
            $value = (array)$value->bsonSerialize();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        } else {
            $value = get_object_vars($value);
        }
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = arcoToArray($v);
        }
        return $out;
    }

    return $value;
}

// ─── Helper: construir condiciones $or para un id (string + ObjectId) ───
function arcoIdConditions($field, $id) {
    $conditions = [[$field => (string)$id]];
    if (preg_match('/^[0-9a-fA-F]{24}$/', (string)$id) && class_exists('MongoDB\BSON\ObjectId')) {
        try {
            $conditions[] = [$field => new MongoDB\BSON\ObjectId((string)$id)];
        } catch (\Throwable $e) {
            // Ignorar si no se puede construir ObjectId
        }
    }
    return $conditions;
}

// ─── Helper: resolver IDs de empresa del usuario (tolerante a tipos) ───
function arcoCompanyIds($user, $db) {
    // Admin / superadmin → sin filtro (null = todas las empresas)
    if (!empty($user['isAdmin'])
        || ($user['role'] ?? '') === 'admin'
        || ($user['role'] ?? '') === 'superadmin') {
        return null;
    }

    $userRecord = $db->findOne('users', ['_id' => $user['_id']]);
    $companyId = $userRecord['companyId'] ?? ($user['_id'] ?? null);
    if (!$companyId) $companyId = $user['_id'] ?? null;

    $ids = [];
    if ($companyId) {
        $companyIdStr = (string)$companyId;
        $ids[] = $companyIdStr;

        // Buscar sub-usuarios por AMBAS formas (string y ObjectId)
        $orConditions = arcoIdConditions('companyId', $companyIdStr);
        if (!empty($orConditions)) {
            $subUsers = $db->find('users', ['$or' => $orConditions]);
            foreach ($subUsers as $su) {
                if (!empty($su['_id']))       $ids[] = (string)$su['_id'];
                if (!empty($su['companyId'])) $ids[] = (string)$su['companyId'];
            }
        }
    }
    if (!empty($user['_id'])) $ids[] = (string)$user['_id'];

    return array_values(array_unique(array_filter($ids)));
}

// ─── Helper: verificar acceso a una solicitud ARCO ───
function arcoCanAccess($user, $db, $req) {
    if (!empty($user['isAdmin'])
        || ($user['role'] ?? '') === 'admin'
        || ($user['role'] ?? '') === 'superadmin') {
        return true;
    }
    $companyIds = arcoCompanyIds($user, $db);
    if ($companyIds === null) return true;

    $reqCompany = (string)($req['companyId'] ?? '');
    return in_array($reqCompany, $companyIds, true);
}

// ─── Crear solicitud ARCO ───
function create() {
    $body = get_body();
    $solicitante = $body['solicitante'] ?? [];
    $tipo = $body['tipo'] ?? 'acceso';
    $descripcion = $body['descripcion'] ?? '';
    $companyId = $body['companyId'] ?? null;
    $captchaToken = $body['captchaToken'] ?? '';

    if (empty($solicitante['nombre']) || empty($solicitante['rut']) || empty($solicitante['email'])) {
        json_error('datos del solicitante requeridos');
    }

    // Verify Turnstile captcha
    if (!verify_turnstile($captchaToken)) {
        json_error('verificación captcha fallida. Por favor, intenta nuevamente.');
    }

    $db = Database::getInstance();
    $requestId = 'ARCO-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $now = date('c');

    $request = $db->insertOne('arco_requests', [
        'requestId' => $requestId,
        'solicitante' => $solicitante,
        'tipo' => $tipo,
        'descripcion' => $descripcion,
        'companyId' => $companyId,
        'status' => 'pending',
        'createdAt' => $now,
        'updatedAt' => $now,
    ]);

    // Notificar al responsable de la empresa
    if ($companyId) {
        $db->insertOne('notifications', [
            'userId' => $companyId,
            'type' => 'arco',
            'title' => 'Nueva solicitud ARCO recibida',
            'message' => 'Solicitud ' . $requestId . ' de ' . $tipo . ' recibida de ' . ($solicitante['nombre'] ?? 'un titular'),
            'read' => false,
            'createdAt' => $now,
            'requestId' => $requestId,
        ]);
    }

    json_response([
        'success' => true,
        'requestId' => $requestId,
        'request' => $request,
    ]);
}

// ─── Tracking público por requestId ───
function track() {
    $body = get_body();
    $trackingId = $body['trackingId'] ?? '';

    if (!$trackingId) json_error('ID de seguimiento requerido');

    $db = Database::getInstance();
    $request = $db->findOne('arco_requests', ['requestId' => $trackingId]);

    if (!$request) json_error('solicitud no encontrada');

    json_response($request);
}

// ─── Listar solicitudes (todos los usuarios de la empresa) ───
function listRequests() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $companyIds = arcoCompanyIds($user, $db);

    if ($companyIds === null) {
        // Admin / superadmin → todas las solicitudes
        $items = $db->find('arco_requests', []);
    } else {
        // Buscar por AMBAS formas (string + ObjectId) para evitar mismatch de tipo
        $orList = [];
        foreach ($companyIds as $cid) {
            foreach (arcoIdConditions('companyId', $cid) as $cond) {
                $orList[] = $cond;
            }
        }
        if (empty($orList)) {
            $items = [];
        } else {
            $items = $db->find('arco_requests', ['$or' => $orList]);
        }
    }

    // Convertir BSONDocuments a arrays PHP planos para el frontend
    $items = array_map('arcoToArray', $items);

    json_response($items);
}

// ─── Actualizar solicitud (historial append-only con snapshot completo) ───
function updateRequest() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $requestId = $body['requestId'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);
    if (!arcoCanAccess($user, $db, $req)) json_error('acceso denegado', 403);

    $now = date('c');
    $respondedBy = $user['name'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Responsable'));

    // Normalizar entradas
    $estado    = trim((string)($body['estado'] ?? $body['status'] ?? ''));
    $hasResp   = array_key_exists('response', $body) || array_key_exists('respuesta', $body);
    $respuesta = trim((string)($body['response'] ?? $body['respuesta'] ?? ''));

    $prevStatus   = $req['status'] ?? 'pending';
    $prevResponse = (string)($req['response'] ?? '');

    // Detectar cambios reales (no borrar respuesta si llega vacía)
    $statusChanged   = ($estado !== '' && $estado !== $prevStatus);
    $responseChanged = ($hasResp && $respuesta !== '' && $respuesta !== $prevResponse);

    if (!$statusChanged && !$responseChanged) {
        json_response([
            'success' => true,
            'unchanged' => true,
            'history' => arcoToArray($req['statusHistory'] ?? []),
        ]);
    }

    // Construir updates
    $updates = ['updatedAt' => $now];

    if ($responseChanged) {
        $updates['response']    = $respuesta;
        $updates['respondedBy'] = $respondedBy;
        $updates['respondedAt'] = $now;
    }

    if ($statusChanged) {
        $updates['status'] = $estado;

        if ($estado === 'in_progress' && empty($req['startedAt'])) {
            $updates['startedAt'] = $now;
        }
        if (in_array($estado, ['completed', 'resolved'], true)) {
            $updates['resolvedAt'] = $now;
        }
        if ($estado === 'finished') {
            $updates['finishedAt'] = $now;
        }
        if ($estado === 'rejected') {
            $updates['rejectedAt'] = $now;
        }
    }

    // Historial append-only con snapshot completo
    $history = arcoToArray($req['statusHistory'] ?? []);
    if (!is_array($history)) $history = [];

    $newStatus   = $statusChanged ? $estado : $prevStatus;
    $newResponse = $responseChanged ? $respuesta : $prevResponse;

    $notePreview = mb_strlen($newResponse) > 140
        ? mb_substr($newResponse, 0, 140) . '…'
        : $newResponse;

    $kind = $statusChanged && $responseChanged ? 'status+response'
          : ($statusChanged ? 'status' : 'response');

    $entry = [
        'at'         => $now,
        'by'         => $respondedBy,
        'status'     => $newStatus,
        'prevStatus' => $prevStatus,
        'kind'       => $kind,
        'response'   => $newResponse,
        'note'       => $notePreview,
    ];

    // Evitar entradas duplicadas consecutivas idénticas
    $last = !empty($history) ? end($history) : null;
    $isDuplicate = $last
        && ($last['status'] ?? '') === $entry['status']
        && (string)($last['response'] ?? '') === $entry['response'];

    if (!$isDuplicate) {
        $history[] = $entry;
        if (count($history) > 200) $history = array_slice($history, -200);
        $updates['statusHistory'] = $history;
    }

    $db->updateOne('arco_requests', ['requestId' => $requestId], $updates);

    json_response([
        'success' => true,
        'status'  => $newStatus,
        'history' => $history,
    ]);
}

// ─── Generar respuesta automática ───
function generateResponse() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $requestId = $body['requestId'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    if (!arcoCanAccess($user, $db, $req)) {
        json_error('acceso denegado', 403);
    }

    $text = 'Respuesta generada automáticamente conforme a la Ley 21.719 y a los derechos ARCO del solicitante.';
    $db->updateOne('arco_requests', ['requestId' => $requestId], [
        'response' => $text,
        'status' => 'resolved',
        'updatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'response' => $text]);
}

// ─── Descargar respuesta en PDF ───
function downloadResponse() {
    $user = Auth::requireAuth();
    $requestId = $_GET['requestId'] ?? $_GET['id'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $db = Database::getInstance();
    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    // Convertir a array plano inmediatamente (evita problemas con BSONDocument)
    $req = arcoToArray($req);

    // Verificación de acceso
    if (!arcoCanAccess($user, $db, $req)) {
        if (empty($req['companyId'])) {
            json_error('solicitud pública - solo administradores pueden acceder', 403);
        }
        json_error('acceso denegado', 403);
    }

    $config = arcoToArray($db->findOne('compliance_config', ['userId' => $user['_id']]) ?? []);
    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));
    $dpdName  = $config['dpdName']  ?? '—';
    $dpdEmail = $config['dpdEmail'] ?? '—';

    $type = $req['tipo'] ?? $req['type'] ?? 'acceso';
    $typeLabels = [
        'acceso'        => 'Acceso',
        'rectificacion' => 'Rectificación',
        'cancelacion'   => 'Cancelación',
        'oposicion'     => 'Oposición',
        'portabilidad'  => 'Portabilidad',
        'supresion'     => 'Supresión',
        'bloqueo'       => 'Bloqueo',
    ];
    $typeLabel = $typeLabels[$type] ?? ucfirst($type);

    // ─── Solicitante: ya es array plano tras arcoToArray ───
    $solicitante = $req['solicitante'] ?? [];
    if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
    if (!is_array($solicitante)) $solicitante = [];

    $name  = $solicitante['nombre'] ?? ($req['name']  ?? 'Titular');
    $rut   = $solicitante['rut']    ?? ($req['rut']   ?? '—');
    $email = $solicitante['email']  ?? ($req['email'] ?? '—');

    $requestDate  = substr(($req['createdAt'] ?? date('c')), 0, 10);
    $responseRaw  = $req['respondedAt'] ?? $req['resolvedAt'] ?? $req['finishedAt'] ?? $req['updatedAt'] ?? date('c');
    $responseDate = date('d/m/Y', strtotime($responseRaw));
    $generatedAt  = date('d/m/Y H:i');

    // ─── Etiquetas de estado (con finished) ───
    $statusLabels = [
        'pending'     => 'Pendiente',
        'in_progress' => 'En proceso',
        'completed'   => 'Completada',
        'resolved'    => 'Completada',
        'finished'    => 'Terminada',
        'rejected'    => 'Rechazada',
    ];
    $statusLabel = $statusLabels[$req['status'] ?? 'pending'] ?? ucfirst($req['status'] ?? 'pendiente');

    // ─── Responsable: prioriza DPD, luego respondedBy, luego user ───
    $respondedBy = $req['respondedBy'] ?? null;
    if (!$respondedBy || $respondedBy === 'kp') {
        if ($dpdName !== '—') {
            $respondedBy = $dpdName;
        } elseif (!empty($user['name'])) {
            $respondedBy = $user['name'];
        } elseif (!empty($user['email'])) {
            $respondedBy = $user['email'];
        } else {
            $respondedBy = 'Responsable';
        }
    }

    // ─── Historial: ya es array plano tras arcoToArray ───
    $statusHistory = $req['statusHistory'] ?? [];
    if (!is_array($statusHistory)) $statusHistory = [];

    $h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

    // ─── Párrafos legales por tipo ───
    $paragraphs = [
        'acceso' => [
            'De conformidad con el artículo 8 de la Ley 21.719, usted tiene derecho a acceder a los datos personales que el responsable del tratamiento mantiene bajo su nombre.',
            'A continuación se detalla la información disponible asociada a su identidad en nuestros registros. Si existieran datos adicionales que no sean posibles de incluir en este documento, serán entregados en un plazo adicional no mayor a 30 días corridos, conforme al artículo 8 de la Ley 21.719.',
            'El acceso se otorga sin costo alguno y la presente respuesta no exime al titular de ejercer otros derechos reconocidos por la ley (rectificación, cancelación, oposición o portabilidad).',
        ],
        'rectificacion' => [
            'De conformidad con el artículo 9 de la Ley 21.719, el titular tiene derecho a rectificar los datos personales que resulten inexactos, erróneos, engañosos o desactualizados.',
            'Una vez recibida la solicitud y verificada la identidad del titular, el responsable del tratamiento procederá a corregir los datos indicados dentro del plazo legal de 30 días corridos. En caso de no ser procedente la rectificación, se informarán los motivos debidamente fundamentados.',
            'La rectificación será comunicada a terceros que hubieren recibido los datos, cuando ello sea posible y no resulte desproporcionado.',
        ],
        'cancelacion' => [
            'De conformidad con el artículo 10 de la Ley 21.719, el titular tiene derecho a solicitar la cancelación o supresión de sus datos personales cuando la finalidad del tratamiento no existiere o hubiere dejado de ser necesaria.',
            'El responsable del tratamiento evaluará la solicitud dentro del plazo de 30 días corridos. Si concurren causales de cancelación, los datos serán bloqueados y posteriormente eliminados, salvo en los casos lícitos de conservación previstos en la ley (por ejemplo, obligaciones legales o contractuales).',
            'La cancelación no procederá respecto de datos cuya conservación sea necesaria para el cumplimiento de una obligación legal o la ejecución de un contrato.',
        ],
        'oposicion' => [
            'De conformidad con el artículo 11 de la Ley 21.719, el titular tiene derecho a oponerse al tratamiento de sus datos personales en determinadas circunstancias, salvo que concurran causas legítimas que prevalezcan sobre los derechos del titular.',
            'El responsable del tratamiento analizará la solicitud dentro del plazo legal de 30 días corridos. Si la oposición resulta procedente, se suspenderá el tratamiento afectado y se informará a terceros a quienes se hubieren transferido los datos, cuando sea posible.',
            'La oposición no procederá cuando el tratamiento sea necesario para el cumplimiento de una obligación legal, la ejecución de un contrato o el interés legítimo debidamente ponderado.',
        ],
        'portabilidad' => [
            'De conformidad con el artículo 13 de la Ley 21.719, el titular tiene derecho a obtener una copia de sus datos personales en un formato estructurado, de uso común y lectura mecánica, para poder transmitirlos a otro responsable del tratamiento.',
            'El responsable del tratamiento entregará la información en el formato solicitado o en un formato interoperable de uso común, dentro del plazo legal de 30 días corridos. Los datos se transmitirán de manera segura y se acompañarán de la información necesaria para su comprensión.',
            'El derecho de portabilidad se limita a los datos personales proporcionados por el titular y no se extiende a datos inferidos o derivados del tratamiento.',
        ],
        'supresion' => [
            'De conformidad con el artículo 7 de la Ley 21.719, el titular tiene derecho a obtener la supresión o eliminación de sus datos personales cuando los datos ya no sean necesarios para los fines para los que fueron recogidos, cuando el titular retire su consentimiento y no exista otra base legal, cuando se oponga al tratamiento y no prevalezcan motivos legítimos, o cuando los datos hayan sido tratados ilícitamente.',
            'El responsable del tratamiento procederá a la supresión de los datos en un plazo máximo de 30 días corridos desde la recepción de la solicitud, salvo que exista una obligación legal de conservación que impida la eliminación. En tal caso, los datos se bloquearán y solo se conservarán para la atención de posibles responsabilidades derivadas del tratamiento.',
            'La supresión será comunicada a terceros a quienes se hubieren transferido los datos, cuando ello sea posible y no resulte desproporcionado.',
        ],
        'bloqueo' => [
            'De conformidad con el artículo 8 ter de la Ley 21.719, el titular tiene derecho a solicitar el bloqueo temporal de sus datos personales mientras se resuelva una solicitud de rectificación, supresión u oposición.',
            'El bloqueo implica la identificación y reserva de los datos, impidiendo su tratamiento para cualquier finalidad distinta a la de atender la solicitud del titular. Los datos bloqueados no podrán ser utilizados, comunicados ni cedidos mientras dure el bloqueo.',
            'El responsable del tratamiento deberá informar al titular de la procedencia del bloqueo y de su levantamiento una vez resuelta la solicitud principal.',
        ],
    ];
    $bodyText = $paragraphs[$type] ?? $paragraphs['acceso'];

    // ─── Hash de verificación (integridad del documento) ───
    $verifyHash = strtoupper(substr(hash('sha256', $requestId . '|' . $responseRaw . '|' . ($req['status'] ?? '')), 0, 16));

    // ─── Tipo de cambio en historial ───
    $kindLabels = [
        'status'          => 'Cambio de estado',
        'response'        => 'Respuesta agregada',
        'status+response' => 'Estado + respuesta',
    ];

    // ═══════════════════════════════════════════════════════════
    //  CONSTRUCCIÓN DEL HTML
    // ═══════════════════════════════════════════════════════════
    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Respuesta ARCO - {$h($typeLabel)}</title>";
    $html .= "<style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.6; color: #1a1a1a; margin: 0; }
        .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
        .footer-page { float: right; color: #666; }
        .topline { height: 2px; background: #000000; }
        .band { background: #000000; padding: 9px 45px 10px 45px; }
        .band .sub { color: #ffffff; font-size: 8px; }
        .band .ttl { color: #ffffff; font-size: 10px; font-weight: bold; margin-top: 2px; }
        .head-wrap { padding: 30px 60px 0 60px; text-align: center; }
        .head-label { color: #777777; font-size: 9px; }
        .head-law { color: #777777; font-size: 8px; margin-top: 4px; }
        .head-sep { border-top: 0.5px solid #000000; margin: 18px 40px 0 40px; }
        .head-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 16px; text-transform: uppercase; }
        .head-title { color: #1a1a1a; font-size: 15px; font-weight: bold; margin-top: 8px; }
        .head-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 16px 40px 0 40px; padding: 7px 10px; color: #1a1a1a; font-size: 8px; font-weight: bold; }
        .content { padding: 20px 60px 50px 60px; }
        .meta { margin-bottom: 18px; background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px 14px; }
        .meta div { margin-bottom: 4px; font-size: 9px; }
        .label { font-weight: bold; color: #555555; font-size: 8px; text-transform: uppercase; }
        .subject { font-size: 12px; font-weight: bold; margin: 20px 0 12px; border-left: 4px solid #000000; padding: 2px 0 2px 10px; }
        .body p { margin-bottom: 10px; text-align: justify; font-size: 10px; }
        .data-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .data-table th { background: #1a1a1a; color: #cccccc; font-size: 8px; font-weight: bold; text-align: left; padding: 6px 8px; }
        .data-table td { border-bottom: 0.3px solid #e0e0e0; padding: 6px 8px; font-size: 9px; vertical-align: top; }
        .signature { margin-top: 45px; }
        .signature p { margin: 4px 0; font-size: 10px; }
        .entry { border-left: 3px solid #000000; padding: 6px 0 6px 10px; margin: 10px 0 14px; page-break-inside: avoid; }
        .entry-head { font-size: 9px; color: #555555; margin: 0 0 6px; }
        .entry-head strong { color: #1a1a1a; }
        .entry-body p { font-size: 10px; text-align: justify; margin: 0 0 6px; }
        .badge { display: inline-block; padding: 1px 6px; border: 0.5px solid #888; border-radius: 8px; font-size: 7px; color: #333; background: #fafafa; }
        .status-pending { background: #fef3c7; border-color: #d97706; color: #92400e; }
        .status-progress { background: #dbeafe; border-color: #2563eb; color: #1e40af; }
        .status-completed { background: #d1fae5; border-color: #059669; color: #065f46; }
        .status-finished { background: #ccfbf1; border-color: #0d9488; color: #115e59; }
        .status-rejected { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
        .empty-note { font-size: 10px; color: #555555; font-style: italic; }
        .verify { margin-top: 30px; padding: 8px 12px; background: #f8f8f8; border: 0.5px dashed #999; font-size: 8px; color: #555; font-family: 'DejaVu Sans Mono', monospace; }
    </style></head><body>";

    // Footer con numeración
    $html .= "<div class='footer-fixed'>Ley 21.719 - Respuesta a Derecho ARCO · {$h($companyName)} <span class='footer-page'>Generado el {$h($generatedAt)}</span></div>";

    // Header
    $html .= "<div class='topline'></div>";
    $html .= "<div class='head-wrap'>";
    $html .= "<div class='head-label'>REPÚBLICA DE CHILE</div>";
    $html .= "<div class='head-law'>Ley 21.719 - Protección de Datos Personales</div>";
    $html .= "<div class='head-sep'></div>";
    $html .= "<div class='head-company'>{$h($companyName)}</div>";
    $html .= "<div class='head-title'>Respuesta a Solicitud de {$h($typeLabel)}</div>";
    $html .= "<div class='head-box'>CLASIFICACIÓN: CONFIDENCIAL · DPD: {$h($dpdName)} ({$h($dpdEmail)})</div>";
    $html .= "</div>";

    $html .= "<div class='content'>";

    // Meta de la solicitud
    $html .= "<div class='meta'>";
    $html .= "<div><span class='label'>Número de solicitud:</span> {$h($requestId)}</div>";
    $html .= "<div><span class='label'>Tipo de derecho:</span> {$h($typeLabel)}</div>";
    $html .= "<div><span class='label'>Fecha de recepción:</span> {$h($requestDate)}</div>";
    $html .= "<div><span class='label'>Fecha de respuesta:</span> {$h($responseDate)}</div>";
    $html .= "<div><span class='label'>Estado:</span> {$h($statusLabel)}</div>";
    $html .= "<div><span class='label'>Gestionada por:</span> {$h($respondedBy)}</div>";
    $html .= "</div>";

    // Subject + datos del titular
    $html .= "<div class='subject'>Respuesta a solicitud de {$h($typeLabel)} - Ley 21.719</div>";

    $html .= "<div class='meta'>";
    $html .= "<div><span class='label'>Titular:</span> {$h($name)}</div>";
    $html .= "<div><span class='label'>RUT:</span> {$h($rut)}</div>";
    $html .= "<div><span class='label'>Email:</span> {$h($email)}</div>";
    $html .= "</div>";

    // Fundamentos legales
    $html .= "<div class='body'>";
    foreach ($bodyText as $p) {
        $html .= "<p>{$h($p)}</p>";
    }
    $html .= "</div>";

    // ─── Respuesta entregada al titular (última versión) ───
    $responseText = trim((string)($req['response'] ?? ''));
    $html .= "<div class='subject'>Respuesta del responsable</div>";
    if ($responseText !== '') {
        $lines = preg_split('/\r?\n/', $responseText);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $html .= "<p style='text-align:justify;font-size:10px;margin-bottom:8px'>{$h($line)}</p>";
            }
        }
        $html .= "<p style='font-size:8px;color:#555555;margin-top:6px'>Registrada por {$h($respondedBy)} el {$h($responseDate)}</p>";
    } else {
        $html .= "<p class='empty-note'>Aún no se ha registrado una respuesta específica para esta solicitud.</p>";
    }

    // ─── Historial de gestión: tabla resumen + cuerpo completo ───
    if (!empty($statusHistory)) {
        $html .= "<div class='subject' style='font-size:11px;margin-top:20px'>Historial de gestión</div>";

        $html .= "<table class='data-table'>";
        $html .= "<tr><th style='width:100px'>Fecha</th><th style='width:80px'>Estado</th>"
               . "<th style='width:120px'>Responsable</th><th>Tipo de cambio</th></tr>";

        foreach ($statusHistory as $ev) {
            $evDate   = !empty($ev['at']) ? date('d/m/Y H:i', strtotime($ev['at'])) : '—';
            $evStatus = $statusLabels[$ev['status'] ?? ''] ?? ucfirst($ev['status'] ?? '—');
            $evBy     = $ev['by'] ?? '—';
            $evKind   = $kindLabels[$ev['kind'] ?? ''] ?? '—';

            $statusCls = 'badge';
            switch ($ev['status'] ?? '') {
                case 'pending':     $statusCls .= ' status-pending';   break;
                case 'in_progress': $statusCls .= ' status-progress';  break;
                case 'completed':
                case 'resolved':    $statusCls .= ' status-completed'; break;
                case 'finished':    $statusCls .= ' status-finished';  break;
                case 'rejected':    $statusCls .= ' status-rejected';  break;
            }

            $html .= "<tr><td>{$h($evDate)}</td>"
                   . "<td><span class='{$statusCls}'>{$h($evStatus)}</span></td>"
                   . "<td>{$h($evBy)}</td>"
                   . "<td>{$h($evKind)}</td></tr>";
        }
        $html .= "</table>";

        // Cuerpo completo de cada respuesta registrada
        $html .= "<div style='margin-top:14px'>";
        $entryNum = 0;
        foreach ($statusHistory as $ev) {
            $fullResponse = trim((string)($ev['response'] ?? ''));
            if ($fullResponse === '') continue;

            $entryNum++;
            $evDate   = !empty($ev['at']) ? date('d/m/Y H:i', strtotime($ev['at'])) : '—';
            $evStatus = $statusLabels[$ev['status'] ?? ''] ?? ucfirst($ev['status'] ?? '—');
            $evBy     = $ev['by'] ?? '—';

            $html .= "<div class='entry'>";
            $html .= "<p class='entry-head'><strong>Entrada #{$entryNum}</strong> · {$h($evDate)} · "
                   . "Estado: <strong>{$h($evStatus)}</strong> · Responsable: {$h($evBy)}</p>";
            $html .= "<div class='entry-body'>";
            foreach (preg_split('/\r?\n/', $fullResponse) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $html .= "<p>{$h($line)}</p>";
                }
            }
            $html .= "</div></div>";
        }

        if ($entryNum === 0) {
            $html .= "<p class='empty-note'>Sin respuestas registradas aún.</p>";
        }
        $html .= "</div>";
    }

    // Firma
    $html .= "<div class='signature'><p>Atentamente,</p>";
    $html .= "<p><strong>{$h($dpdName)}</strong><br>Delegado de Protección de Datos</p></div>";

    // Hash de verificación
    $html .= "<div class='verify'>";
    $html .= "<strong>Verificación de integridad:</strong> {$h($verifyHash)}<br>";
    $html .= "Documento emitido conforme al procedimiento de derechos ARCO establecido por la Ley 21.719. ";
    $html .= "Cualquier alteración posterior invalida esta verificación.";
    $html .= "</div>";

    $html .= "</div></body></html>";

    // ─── Render PDF ───
    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    // Limpiar cualquier output accidental (BOM, warnings, espacios)
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="respuesta_arco_' . $requestId . '.pdf"');
    echo $dompdf->output();
    exit;
}

// ─── Exportar portabilidad ───
function exportPortabilidad() {
    $user = Auth::requireAuth();
    $requestId = $_GET['requestId'] ?? $_GET['id'] ?? '';
    if (!$requestId) json_error('requestId requerido');

    $db = Database::getInstance();
    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    // Convertir a array plano
    $req = arcoToArray($req);

    if (!arcoCanAccess($user, $db, $req)) {
        json_error('acceso denegado', 403);
    }

    $format = strtolower($_GET['format'] ?? 'json');

    $uid = $req['companyId'] ?? $user['_id'];

    $solicitante = $req['solicitante'] ?? [];
    if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
    if (!is_array($solicitante)) $solicitante = [];

    $email = ($solicitante['email'] ?? $req['email'] ?? '');
    $rut   = ($solicitante['rut']   ?? $req['rut']   ?? '');
    $name  = ($solicitante['nombre'] ?? $req['name'] ?? '');

    $data = [
        'solicitante' => [
            'nombre' => $name,
            'rut'    => $rut,
            'email'  => $email,
        ],
        'consentimientos' => $db->find('compliance_consents', [
            'userId' => $uid,
            '$or' => [
                ['email' => $email],
                ['rut' => $rut],
            ],
        ]),
        'arco_requests' => $db->find('arco_requests', [
            'companyId' => $uid,
            '$or' => [
                ['solicitante.email' => $email],
                ['solicitante.rut'   => $rut],
            ],
        ]),
        'inventario' => $db->find('compliance_inventory', [
            'userId' => $uid,
        ]),
        'brechas' => $db->find('compliance_breaches', [
            'userId' => $uid,
            '$or' => [
                ['affectedEmail' => $email],
                ['affectedRut'   => $rut],
            ],
        ]),
        'capacitaciones' => $db->find('compliance_trainings', [
            'userId' => $uid,
            'employeeEmail' => $email,
        ]),
    ];

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="portabilidad_' . $requestId . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Colección', 'Campo', 'Valor']);
        foreach ($data as $collection => $items) {
            foreach ($items as $item) {
                foreach ($item as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    fputcsv($out, [$collection, $key, $value]);
                }
            }
        }
        fclose($out);
        exit;
    }

    // JSON por defecto
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="portabilidad_' . $requestId . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Descargar comprobante de recepción (público) ───
function downloadReceipt() {
    $requestId = $_GET['requestId'] ?? '';
    $email = $_GET['email'] ?? '';

    if (!$requestId) json_error('requestId requerido');

    $db = Database::getInstance();
    $req = $db->findOne('arco_requests', ['requestId' => $requestId]);
    if (!$req) json_error('solicitud no encontrada', 404);

    // Convertir a array plano
    $req = arcoToArray($req);

    // Verificación mínima para evitar acceso a ciegas por ID
    $solicitante = $req['solicitante'] ?? [];
    if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
    if (!is_array($solicitante)) $solicitante = [];

    $solicitanteEmail = $solicitante['email'] ?? ($req['email'] ?? '');
    if ($email && strtolower($email) !== strtolower($solicitanteEmail)) {
        json_error('verificación de email fallida', 403);
    }

    $company = arcoToArray($db->findOne('users', ['_id' => ($req['companyId'] ?? '')]) ?? []);
    $companyName = $company['companyName'] ?? ($company['name'] ?? 'Empresa');
    $dpdName  = $company['dpdName']  ?? '—';
    $dpdEmail = $company['dpdEmail'] ?? '—';

    $type = $req['tipo'] ?? $req['type'] ?? 'acceso';
    $typeLabels = [
        'acceso'        => 'Acceso',
        'rectificacion' => 'Rectificación',
        'cancelacion'   => 'Cancelación',
        'oposicion'     => 'Oposición',
        'portabilidad'  => 'Portabilidad',
        'supresion'     => 'Supresión',
        'bloqueo'       => 'Bloqueo',
    ];
    $typeLabel = $typeLabels[$type] ?? ucfirst($type);

    $name       = $solicitante['nombre'] ?? ($req['name']  ?? 'Titular');
    $rut        = $solicitante['rut']    ?? ($req['rut']   ?? '—');
    $emailView  = $solicitante['email']  ?? ($req['email'] ?? '—');
    $requestDate = substr(($req['createdAt'] ?? date('c')), 0, 10);
    $receiptDate = date('d/m/Y');

    $h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Comprobante ARCO - {$h($typeLabel)}</title>";
    $html .= "<style>
        @page{margin:0}
        body{font-family:'DejaVu Sans',Arial,sans-serif;font-size:10px;line-height:1.6;color:#1a1a1a;margin:0}
        .footer-fixed{position:fixed;bottom:0;left:0;right:0;height:22px;background:#f5f5f5;border-top:0.5px solid #cccccc;color:#999999;font-size:7px;padding:6px 45px 0 45px}
        .topline{height:2px;background:#000000}
        .head-wrap{padding:30px 60px 0 60px;text-align:center}
        .head-label{color:#777777;font-size:9px}
        .head-law{color:#777777;font-size:8px;margin-top:4px}
        .head-sep{border-top:0.5px solid #000000;margin:18px 40px 0 40px}
        .head-company{color:#1a1a1a;font-size:14px;font-weight:bold;margin-top:16px;text-transform:uppercase}
        .head-title{color:#1a1a1a;font-size:15px;font-weight:bold;margin-top:8px}
        .head-box{background:#f5f5f5;border:0.5px solid #bbbbbb;margin:16px 40px 0 40px;padding:7px 10px;color:#1a1a1a;font-size:8px;font-weight:bold}
        .content{padding:20px 60px 50px 60px}
        .meta{margin-bottom:16px;background:#f5f5f5;border:0.5px solid #bbbbbb;padding:12px 14px}
        .meta div{margin-bottom:4px;font-size:9px}
        .label{font-weight:bold;color:#555555;font-size:8px;text-transform:uppercase}
        .subject{font-size:12px;font-weight:bold;margin:18px 0 12px;border-left:4px solid #000000;padding:2px 0 2px 10px}
        .body p{margin-bottom:10px;text-align:justify;font-size:10px}
        .data-table{width:100%;border-collapse:collapse;margin:12px 0}
        .data-table td{border-bottom:0.3px solid #e0e0e0;padding:6px 8px;font-size:9px}
        .stamp{display:inline-block;margin-top:26px;padding:8px 15px;border:1.5px dashed #166534;color:#166534;font-weight:bold;font-size:10px}
    </style></head><body>";
    $html .= "<div class='footer-fixed'>Ley 21.719 - Comprobante de Recepción ARCO · {$h($companyName)}</div>";
    $html .= "<div class='topline'></div>";
    $html .= "<div class='head-wrap'>";
    $html .= "<div class='head-label'>REPÚBLICA DE CHILE</div>";
    $html .= "<div class='head-law'>Ley 21.719 - Protección de Datos Personales</div>";
    $html .= "<div class='head-sep'></div>";
    $html .= "<div class='head-company'>{$h($companyName)}</div>";
    $html .= "<div class='head-title'>Comprobante de Recepción - {$h($typeLabel)}</div>";
    $html .= "<div class='head-box'>CLASIFICACIÓN: CONFIDENCIAL · DPD: {$h($dpdName)} ({$h($dpdEmail)})</div>";
    $html .= "</div>";
    $html .= "<div class='content'>";

    $html .= "<div class='meta'>";
    $html .= "<div><span class='label'>Número de solicitud:</span> {$h($requestId)}</div>";
    $html .= "<div><span class='label'>Derecho ejercido:</span> {$h($typeLabel)}</div>";
    $html .= "<div><span class='label'>Fecha de recepción:</span> {$h($requestDate)}</div>";
    $html .= "<div><span class='label'>Fecha de comprobante:</span> {$h($receiptDate)}</div>";
    $html .= "</div>";

    $html .= "<div class='meta'>";
    $html .= "<div><span class='label'>Titular:</span> {$h($name)}</div>";
    $html .= "<div><span class='label'>RUT:</span> {$h($rut)}</div>";
    $html .= "<div><span class='label'>Email:</span> {$h($emailView)}</div>";
    $html .= "</div>";

    $html .= "<table class='data-table'>";
    $html .= "<tr><td class='label'>Descripción de la solicitud</td><td>" . $h($req['descripcion'] ?? 'Sin descripción adicional.') . "</td></tr>";
    $html .= "</table>";

    $html .= "<div class='body'>";
    $html .= "<p>De conformidad con la Ley 21.719, la presente solicitud ha sido registrada por el responsable del tratamiento. El plazo máximo de respuesta es de 30 días corridos, pudiendo extenderse por un plazo adicional de hasta 30 días cuando concurren causas justificadas y se notifica oportunamente al titular.</p>";
    $html .= "<p>El titular podrá hacer seguimiento de esta solicitud mediante el número de referencia <strong>{$h($requestId)}</strong> y el email declarado en el formulario.</p>";
    $html .= "</div>";

    $html .= "<div class='stamp'>SOLICITUD RECIBIDA</div>";

    $html .= "</div></body></html>";

    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    // Limpiar output previo (BOM, warnings)
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="comprobante_arco_' . $requestId . '.pdf"');
    echo $dompdf->output();
    exit;
}