<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/NotificationService.php';

// ═══════════════════════════════════════════════════════════
// Configuración
// ═══════════════════════════════════════════════════════════
const PORTAL_CODE_TTL          = 600;   // 10 min
const PORTAL_MAX_ATTEMPTS      = 5;
const PORTAL_RESEND_COOLDOWN   = 60;
const PORTAL_CHECK_COOLDOWN    = 30;
const PORTAL_ARCO_SLA_DAYS     = 10;
const PORTAL_IDENTITY_MAX_MB   = 10;
const PORTAL_IDENTITY_DIR      = __DIR__ . '/../uploads/identity/';
const PORTAL_DELIVERY_TTL_HOURS = 48;   // enlace temporal de entrega

// ═══════════════════════════════════════════════════════════
// Helpers de clasificación por base legal
// ═══════════════════════════════════════════════════════════
function _portalLegalBasisLabel(string $base): string {
    return [
        'consentimiento'     => 'Consentimiento del titular',
        'contrato'           => 'Ejecución de contrato',
        'ejecucion_contrato' => 'Ejecución de contrato',
        'obligacion_legal'   => 'Obligación legal',
        'interes_vital'      => 'Interés vital',
        'interes_publico'    => 'Interés público',
        'interes_legitimo'   => 'Interés legítimo',
    ][$base] ?? ucfirst($base);
}

function _portalLegalBasisExplanation(string $base): string {
    return [
        'consentimiento'     => 'Puedes revocar este tratamiento en cualquier momento y sin expresión de causa.',
        'contrato'           => 'Este tratamiento es necesario para ejecutar el servicio contratado. '
                              . 'Puedes solicitar su supresión cuando termine el contrato, o ejercer oposición con motivos fundados.',
        'ejecucion_contrato' => 'Este tratamiento es necesario para ejecutar el servicio contratado. '
                              . 'Puedes solicitar su supresión cuando termine el contrato, o ejercer oposición con motivos fundados.',
        'obligacion_legal'   => 'Este tratamiento es obligatorio por ley. Los datos se conservarán por el plazo que exija la normativa aplicable.',
        'interes_vital'      => 'Este tratamiento se realiza para proteger tu vida o salud, o la de otra persona.',
        'interes_publico'    => 'Este tratamiento se basa en el interés público o en el ejercicio de funciones públicas.',
        'interes_legitimo'   => 'Este tratamiento se basa en un interés legítimo del responsable. Puedes oponerte con motivos fundados.',
    ][$base] ?? 'Base legal: ' . $base;
}

/**
 * Determina las acciones que el titular puede ejercer sobre un tratamiento
 * según su base legal, conforme a la Ley 21.719.
 *
 * - consentimiento    → revocar (inmediato)
 * - contrato          → solicitar supresión + oponerse (va al DPO)
 * - obligacion_legal  → solicitar supresión (el DPO explicará por qué no procede)
 * - interes_legitimo  → oponerse + solicitar supresión
 * - interes_vital     → solicitar supresión
 * - interes_publico   → solicitar supresión
 */
function _portalActionsFor(string $base, bool $active): array {
    if (!$active) return [];
    switch ($base) {
        case 'consentimiento':
            return ['revoke'];
        case 'contrato':
        case 'ejecucion_contrato':
            return ['oppose', 'request_deletion'];
        case 'obligacion_legal':
            return ['request_deletion'];
        case 'interes_legitimo':
            return ['oppose', 'request_deletion'];
        case 'interes_vital':
        case 'interes_publico':
            return ['request_deletion'];
        default:
            return ['request_deletion'];
    }
}

// ═══════════════════════════════════════════════════════════
// CAPA 1 — Consulta pública (multi-tenant)
// ═══════════════════════════════════════════════════════════
function portalCheckEmail() {
    $body  = get_body();
    $email = strtolower(trim($body['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }

    $db = Database::getInstance();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Cooldown por IP
    $cutoff = date('c', time() - PORTAL_CHECK_COOLDOWN);
    $recent = $db->find('portal_check_log', [
        'ip'        => $ip,
        'createdAt' => ['$gte' => $cutoff],
    ]);
    if (!empty($recent)) {
        json_error('demasiadas consultas. Espera ' . PORTAL_CHECK_COOLDOWN . ' segundos.', 429);
    }

    $db->insertOne('portal_check_log', [
        'email'     => $email,
        'ip'        => $ip,
        'userAgent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
        'createdAt' => date('c'),
    ]);

    // Empresas que tienen datos sobre este email
    $companyIds = [];

    $consents = $db->find('compliance_consents', ['email' => $email]);
    foreach ($consents as $c) {
        if (!empty($c['userId'])) $companyIds[(string)$c['userId']] = true;
    }

    $arcos = $db->find('arco_requests', ['solicitante.email' => $email]);
    foreach ($arcos as $a) {
        if (!empty($a['companyId'])) $companyIds[(string)$a['companyId']] = true;
    }

    $companyIds = array_keys($companyIds);

    if (empty($companyIds)) {
        json_response([
            'success'   => true,
            'exists'    => false,
            'companies' => [],
            'message'   => 'No tenemos datos personales asociados a este email. '
                         . 'Si crees que es un error, puedes presentar una solicitud ARCO '
                         . 'para que el Delegado de Protección de Datos lo verifique manualmente.',
            'arcoRequestUrl' => '/arco-solicitud',
        ]);
    }

    $companies = [];
    foreach ($companyIds as $cid) {
        $config = $db->findOne('compliance_config', ['userId' => $cid]) ?? [];
        $user   = $db->findOne('users', ['_id' => $cid]);

        $name = $config['companyName']
             ?? ($user['companyName'] ?? null)
             ?? ($user['email'] ?? null)
             ?? 'Empresa';

        $consentsCompany = array_filter($consents, fn($c) => (string)($c['userId'] ?? '') === $cid);
        $arcosCompany    = array_filter($arcos, fn($a) => (string)($a['companyId'] ?? '') === $cid);

        $companies[] = [
            'companyId'      => $cid,
            'name'           => $name,
            'dpd'            => [
                'name'  => $config['dpdName'] ?? '',
                'email' => $config['dpdEmail'] ?? '',
            ],
            'consentsCount'  => count($consentsCompany),
            'activeConsents' => count(array_filter($consentsCompany, fn($c) => empty($c['revokedAt']))),
            'arcoRequests'   => count($arcosCompany),
        ];
    }

    json_response([
        'success'   => true,
        'exists'    => true,
        'companies' => $companies,
        'message'   => 'Encontramos datos asociados a este email en ' . count($companies) . ' empresa(s).',
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Solicitar código
// ═══════════════════════════════════════════════════════════
function portalRequestCode() {
    $body      = get_body();
    $email     = strtolower(trim($body['email'] ?? ''));
    $companyId = trim((string)($body['companyId'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }

    $db  = Database::getInstance();
    $now = date('c');

    $cutoff = date('c', time() - PORTAL_RESEND_COOLDOWN);
    $recent = $db->find('portal_sessions', [
        'email'     => $email,
        'mode'      => 'access',
        'createdAt' => ['$gte' => $cutoff],
    ]);
    if (!empty($recent)) {
        json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
    }

    $exists = $db->findOne('compliance_consents', ['email' => $email])
           || $db->findOne('arco_requests', ['solicitante.email' => $email]);

    if (!$exists) {
        json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
    }

    // Invalidar códigos previos
    $pending = $db->find('portal_sessions', [
        'email'     => $email,
        'mode'      => 'access',
        'used'      => false,
        'expiresAt' => ['$gte' => $now],
    ]);
    foreach ($pending as $p) {
        $db->updateOne('portal_sessions', ['_id' => $p['_id']], ['used' => true]);
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->insertOne('portal_sessions', [
        'email'              => $email,
        'mode'               => 'access',
        'requestedCompanyId' => $companyId,
        'codeHash'           => hash('sha256', $code),
        'expiresAt'          => date('c', time() + PORTAL_CODE_TTL),
        'used'               => false,
        'attempts'           => 0,
        'ip'                 => $_SERVER['REMOTE_ADDR'] ?? '',
        'createdAt'          => $now,
    ]);

    $notifCompanyId = $companyId;
    if (!$notifCompanyId) {
        $c = $db->findOne('compliance_consents', ['email' => $email]);
        $notifCompanyId = $c['userId'] ?? '';
    }

    NotificationService::queue($db, [
        'companyId'     => $notifCompanyId,
        'channel'       => 'email',
        'recipient'     => $email,
        'recipientType' => 'titular',
        'templateCode'  => 'portal_magic_code',
        'variables'     => ['code' => $code],
    ]);

    if (defined('PORTAL_DEV_SHOW_CODE') && PORTAL_DEV_SHOW_CODE) {
        json_response([
            'success'  => true,
            'message'  => 'Si el email existe recibirás un código',
            'dev_code' => $code,
        ]);
    }

    json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Verificar código
// ═══════════════════════════════════════════════════════════
function portalVerifyCode() {
    $body  = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $code  = trim((string)($body['code'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        json_error('código inválido o expirado', 401);
    }

    $db  = Database::getInstance();
    $now = date('c');

    $sessions = $db->find('portal_sessions', [
        'email'     => $email,
        'mode'      => 'access',
        'used'      => false,
        'expiresAt' => ['$gte' => $now],
    ]);

    if (empty($sessions)) json_error('código inválido o expirado', 401);

    usort($sessions, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    $session = $sessions[0];

    $attempts = (int)($session['attempts'] ?? 0);
    if ($attempts >= PORTAL_MAX_ATTEMPTS) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], ['used' => true]);
        json_error('demasiados intentos. Solicita un nuevo código.', 429);
    }

    if (!hash_equals((string)$session['codeHash'], hash('sha256', $code))) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
            'attempts'      => $attempts + 1,
            'lastAttemptAt' => $now,
        ]);
        json_error('código inválido o expirado', 401);
    }

    $token = Auth::createPortalToken($email);

    $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
        'used'   => true,
        'usedAt' => $now,
    ]);

    foreach ($sessions as $s) {
        if ($s['_id'] !== $session['_id']) {
            $db->updateOne('portal_sessions', ['_id' => $s['_id']], ['used' => true]);
        }
    }

    audit_log('portal_session_created', ['email' => $email], null);

    json_response(['success' => true, 'token' => $token]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Dashboard del titular (multi-tenant)
// ═══════════════════════════════════════════════════════════
function portalMyData() {
    $email = Auth::requirePortalSession();
    $db    = Database::getInstance();

    $companyId = trim((string)($_GET['companyId'] ?? ''));

    $allConsents = $db->find('compliance_consents', ['email' => $email]);
    $allArcos    = $db->find('arco_requests', ['solicitante.email' => $email]);

    // Empresas disponibles
    $companyIds = [];
    foreach ($allConsents as $c) if (!empty($c['userId'])) $companyIds[(string)$c['userId']] = true;
    foreach ($allArcos    as $a) if (!empty($a['companyId'])) $companyIds[(string)$a['companyId']] = true;
    $companyIds = array_keys($companyIds);

    // Selector de empresa si hay varias y no se especificó
    if (!$companyId && count($companyIds) > 1) {
        $companies = [];
        foreach ($companyIds as $cid) {
            $config = $db->findOne('compliance_config', ['userId' => $cid]) ?? [];
            $user   = $db->findOne('users', ['_id' => $cid]);
            $companies[] = [
                'companyId' => $cid,
                'name'      => $config['companyName'] ?? $user['companyName'] ?? $user['email'] ?? 'Empresa',
                'dpd'       => [
                    'name'  => $config['dpdName'] ?? '',
                    'email' => $config['dpdEmail'] ?? '',
                ],
            ];
        }
        json_response([
            'mode'      => 'select_company',
            'email'     => $email,
            'companies' => $companies,
        ]);
    }

    if (!$companyId && count($companyIds) === 1) $companyId = $companyIds[0];
    if (!$companyId) json_error('no hay datos asociados a este email', 404);

    // Filtrar por empresa
    $consents     = array_values(array_filter($allConsents, fn($c) => (string)($c['userId'] ?? '') === $companyId));
    $arcoRequests = array_values(array_filter($allArcos,    fn($r) => (string)($r['companyId'] ?? '') === $companyId));

    $inventory  = $db->find('compliance_inventory', ['userId' => $companyId]);
    $processors = $db->find('compliance_processors', ['userId' => $companyId]);
    $transfers  = $db->find('compliance_transfers', ['userId' => $companyId]);
    $config     = $db->findOne('compliance_config', ['userId' => $companyId]) ?? [];
    $userRecord = $db->findOne('users', ['_id' => $companyId]);

    // Subject
    $subject = ['email' => $email, 'name' => null, 'rut' => null];
    foreach ($consents as $c) {
        if (empty($subject['name']) && !empty($c['name'])) $subject['name'] = $c['name'];
        if (empty($subject['rut'])  && !empty($c['rut']))  $subject['rut']  = $c['rut'];
    }

    // Categorías únicas
    $dataCategories = [];
    foreach ($inventory as $inv) {
        $cats = $inv['dataCategories'] ?? [];
        if (is_string($cats)) $cats = array_map('trim', explode(',', $cats));
        if (!is_array($cats)) continue;
        foreach ($cats as $cat) {
            $cat = trim((string)$cat);
            if ($cat !== '' && !in_array($cat, $dataCategories, true)) $dataCategories[] = $cat;
        }
    }

    // Treatment map
    $treatmentMap = [];
    $retentionMap = [];
    foreach ($inventory as $inv) {
        $cats = $inv['dataCategories'] ?? [];
        if (is_string($cats)) $cats = array_map('trim', explode(',', $cats));
        if (!is_array($cats)) $cats = [];

        $retentionDays = isset($inv['retentionDays']) && $inv['retentionDays'] !== null
            ? (int)$inv['retentionDays'] : null;

        if ($retentionDays) {
            foreach ($cats as $cat) {
                $cat = trim((string)$cat);
                if ($cat === '') continue;
                if (!isset($retentionMap[$cat]) || $retentionMap[$cat] < $retentionDays) {
                    $retentionMap[$cat] = $retentionDays;
                }
            }
        }

        $base = strtolower((string)($inv['legalBasis'] ?? ''));
        $treatmentMap[] = [
            'name'            => (string)($inv['name'] ?? 'Sin nombre'),
            'purpose'         => (string)($inv['purpose'] ?? ''),
            'legalBasis'      => $base,
            'legalBasisLabel' => _portalLegalBasisLabel($base),
            'legalBasisExplanation' => _portalLegalBasisExplanation($base),
            'dataCategories'  => $cats,
            'retentionDays'   => $retentionDays,
            'sensitive'       => !empty($inv['sensitive']),
            'childrenData'    => !empty($inv['childrenData']),
            'risk'            => (string)($inv['risk'] ?? 'low'),
        ];
    }

    // Encargados
    $processorList = [];
    foreach ($processors as $p) {
        $processorList[] = [
            'name'        => (string)($p['name'] ?? ''),
            'serviceType' => (string)($p['serviceType'] ?? ''),
            'country'     => (string)($p['country'] ?? ''),
            'hasContract' => ($p['hasContract'] ?? '') === 'si',
        ];
    }

    // Transferencias
    $transferList = [];
    foreach ($transfers as $t) {
        $transferList[] = [
            'country'   => (string)($t['destinationCountry'] ?? ''),
            'recipient' => (string)($t['recipient'] ?? ''),
            'mechanism' => (string)($t['mechanism'] ?? ''),
        ];
    }

    // Consentimientos con clasificación por base legal
    $consentList = [];
    foreach ($consents as $c) {
        $base = strtolower((string)($c['legalBasis'] ?? 'consentimiento'));
        $active = empty($c['revokedAt']);
        $consentList[] = [
            'id'               => (string)($c['_id'] ?? ''),
            'purpose'          => (string)($c['purpose'] ?? 'Sin especificar'),
            'legalBasis'       => $base,
            'legalBasisLabel'  => _portalLegalBasisLabel($base),
            'explanation'      => _portalLegalBasisExplanation($base),
            'grantedAt'        => $c['createdAt'] ?? null,
            'revokedAt'        => $c['revokedAt'] ?? null,
            'active'           => $active,
            'revocable'        => $active && $base === 'consentimiento',
            'availableActions' => _portalActionsFor($base, $active),
            'revocationStatus' => $c['revocationStatus'] ?? null,
            'revocationEffect' => $c['revocationEffect'] ?? null,
        ];
    }

    // ARCO con SLA
    $arcoList = [];
    foreach ($arcoRequests as $r) {
        $status = (string)($r['status'] ?? 'pending');
        $isClosed = in_array($status, ['completed','resolved','finished','rejected'], true);
        $bDays = _portalBusinessDays($r['createdAt'] ?? null);
        $arcoList[] = [
            'requestId'     => (string)($r['requestId'] ?? ''),
            'type'          => (string)($r['tipo'] ?? $r['type'] ?? 'acceso'),
            'description'   => (string)($r['descripcion'] ?? ''),
            'status'        => $status,
            'response'      => (string)($r['response'] ?? ''),
            'source'        => (string)($r['source'] ?? 'portal'),
            'createdAt'     => $r['createdAt'] ?? null,
            'updatedAt'     => $r['updatedAt'] ?? null,
            'resolvedAt'    => $r['resolvedAt'] ?? $r['finishedAt'] ?? null,
            'businessDays'  => $bDays,
            'daysRemaining' => max(0, PORTAL_ARCO_SLA_DAYS - $bDays),
            'overdue'       => !$isClosed && $bDays > PORTAL_ARCO_SLA_DAYS,
            'closed'        => $isClosed,
        ];
    }

    // Solicitud de datos concretos en curso
    $concreteRequest = null;
    foreach ($arcoRequests as $r) {
        if (($r['source'] ?? '') === 'concrete_data' &&
            !in_array($r['status'] ?? '', ['completed','resolved','finished','rejected'], true)) {
            $concreteRequest = [
                'requestId'      => $r['requestId'] ?? '',
                'status'         => $r['status'] ?? 'pending',
                'identityStatus' => $r['identityStatus'] ?? 'pending',
                'createdAt'      => $r['createdAt'] ?? null,
                'daysRemaining'  => max(0, PORTAL_ARCO_SLA_DAYS - _portalBusinessDays($r['createdAt'] ?? null)),
            ];
            break;
        }
    }

    $activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $openRequests   = count(array_filter($arcoRequests, fn($r) => !in_array(
        $r['status'] ?? '', ['completed','resolved','finished','rejected'], true
    )));

    json_response([
        'mode'      => 'company',
        'companyId' => $companyId,
        'email'     => $email,
        'subject'   => $subject,

        'company' => [
            'name' => (string)($config['companyName'] ?? $userRecord['companyName'] ?? ''),
            'rut'  => (string)($config['companyRut'] ?? ''),
            'dpd'  => [
                'name'  => (string)($config['dpdName'] ?? ''),
                'email' => (string)($config['dpdEmail'] ?? ''),
                'phone' => (string)($config['dpdPhone'] ?? ''),
            ],
            'policies' => [
                'privacyUrl'      => (string)($config['privacyPolicyUrl'] ?? ''),
                'cookiesUrl'      => (string)($config['cookiesPolicyUrl'] ?? ''),
                'retentionPolicy' => (string)($config['dataRetentionPolicy'] ?? ''),
            ],
        ],

        'dataCategories' => $dataCategories,
        'treatmentMap'   => $treatmentMap,
        'retention'      => $retentionMap,
        'processors'     => $processorList,
        'transfers'      => $transferList,
        'consents'       => $consentList,
        'arcoRequests'   => $arcoList,
        'concreteDataRequest' => $concreteRequest,

        'summary' => [
            'totalConsents'       => count($consents),
            'activeConsents'      => $activeConsents,
            'openRequests'        => $openRequests,
            'totalTreatments'     => count($inventory),
            'dataCategoriesCount' => count($dataCategories),
            'processorsCount'     => count($processorList),
            'transfersCount'      => count($transferList),
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Revocar consentimiento (solo cuando base = consentimiento)
// ═══════════════════════════════════════════════════════════
function portalRevokeConsent() {
    $email = Auth::requirePortalSession();
    $body  = get_body();
    $consentId = $body['consentId'] ?? '';

    if (!$consentId) json_error('consentId requerido');

    $db = Database::getInstance();
    $consent = $db->findOne('compliance_consents', ['_id' => $consentId, 'email' => $email]);

    if (!$consent) json_error('consentimiento no encontrado', 404);
    if (!empty($consent['revokedAt'])) json_error('ya estaba revocado');

    // ✅ Bloquear la revocación si la base legal NO es consentimiento
    $base = strtolower((string)($consent['legalBasis'] ?? 'consentimiento'));
    if ($base !== 'consentimiento') {
        json_error(
            'Este tratamiento no puede revocarse: su base legal es "' 
            . _portalLegalBasisLabel($base) 
            . '". Puedes solicitar su supresión o ejercer oposición.',
            400
        );
    }

    $now = date('c');

    // Revocación INMEDIATA
    $db->updateOne('compliance_consents', ['_id' => $consentId], [
        'active'           => false,
        'revokedAt'        => $now,
        'revokedVia'       => 'portal_titular',
        'revokedFromIp'    => $_SERVER['REMOTE_ADDR'] ?? '',
        'revocationStatus' => 'pending_review',
        'revocationHistory'=> array_merge(
            $consent['revocationHistory'] ?? [],
            [[
                'at'     => $now,
                'by'     => 'titular',
                'action' => 'revoked',
                'via'    => 'portal_titular',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            ]]
        ),
    ]);

    // Notificar al titular
    NotificationService::queue($db, [
        'companyId'     => $consent['userId'] ?? '',
        'channel'       => 'email',
        'recipient'     => $email,
        'recipientType' => 'titular',
        'templateCode'  => 'consent_revoked',
        'variables'     => [
            'nombre'  => $consent['name'] ?? '',
            'purpose' => $consent['purpose'] ?? '',
            'fecha'   => date('d/m/Y'),
        ],
    ]);

    // Notificar al DPO
    if (!empty($consent['userId'])) {
        $config = $db->findOne('compliance_config', ['userId' => $consent['userId']]) ?? [];
        if (!empty($config['dpdEmail'])) {
            NotificationService::queue($db, [
                'companyId'     => $consent['userId'],
                'channel'       => 'email',
                'recipient'     => $config['dpdEmail'],
                'recipientType' => 'dpo',
                'templateCode'  => 'consent_revocation_review',
                'variables'     => [
                    'consentId' => $consentId,
                    'titular'   => $consent['name'] ?? '',
                    'email'     => $email,
                    'purpose'   => $consent['purpose'] ?? '',
                    'fecha'     => date('d/m/Y H:i'),
                ],
            ]);
        }
    }

    audit_log('portal_consent_revoked', [
        'consentId' => $consentId,
        'email'     => $email,
        'purpose'   => $consent['purpose'] ?? '',
    ], null);

    json_response([
        'success' => true,
        'message' => 'Consentimiento revocado. El cese del tratamiento es inmediato. '
                   . 'El Delegado de Protección de Datos revisará si existen otras bases legales '
                   . 'aplicables en 48 horas.',
        'status'  => 'pending_review',
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Crear solicitud ARCO desde el portal
// (aplica a rectificación, supresión, oposición, portabilidad, bloqueo)
// ═══════════════════════════════════════════════════════════
function portalCreateARCO() {
    $email = Auth::requirePortalSession();
    $body  = get_body();

    $tipo        = trim((string)($body['tipo'] ?? ''));
    $descripcion = trim((string)($body['descripcion'] ?? ''));
    $consentId   = trim((string)($body['consentId'] ?? ''));

    $validTypes = ['acceso','rectificacion','supresion','oposicion','portabilidad','bloqueo'];
    if (!in_array($tipo, $validTypes, true)) json_error('tipo de solicitud inválido');
    if (strlen($descripcion) < 20) json_error('la descripción debe tener al menos 20 caracteres');

    $db = Database::getInstance();

    $consent = $db->findOne('compliance_consents', ['email' => $email]);
    $nombre  = $consent['name'] ?? $email;
    $rut     = $consent['rut'] ?? '';
    $companyId = $consent['userId'] ?? '';

    if (!$companyId) {
        $prevArco = $db->findOne('arco_requests', ['solicitante.email' => $email]);
        if ($prevArco) $companyId = $prevArco['companyId'] ?? '';
    }

    $requestId = 'ARCO-' . strtoupper(bin2hex(random_bytes(4)));
    $now       = date('c');

    $db->insertOne('arco_requests', [
        'requestId'        => $requestId,
        'solicitante'      => ['nombre' => $nombre, 'rut' => $rut, 'email' => $email],
        'tipo'             => $tipo,
        'descripcion'      => $descripcion,
        'companyId'        => $companyId,
        'relatedConsentId' => $consentId ?: null,
        'status'           => 'pending',
        'source'           => 'portal_titular',
        'slaDays'          => PORTAL_ARCO_SLA_DAYS,
        'createdAt'        => $now,
        'updatedAt'        => $now,
        'statusHistory'    => [[
            'at'     => $now,
            'by'     => 'titular',
            'status' => 'pending',
            'kind'   => 'created',
            'note'   => 'Solicitud creada desde el portal del titular',
        ]],
    ]);

    if ($companyId) {
        $config = $db->findOne('compliance_config', ['userId' => $companyId]) ?? [];
        if (!empty($config['dpdEmail'])) {
            NotificationService::queue($db, [
                'companyId'     => $companyId,
                'channel'       => 'email',
                'recipient'     => $config['dpdEmail'],
                'recipientType' => 'dpo',
                'templateCode'  => 'arco_new_request',
                'variables'     => [
                    'requestId' => $requestId,
                    'tipo'      => $tipo,
                    'titular'   => $nombre,
                    'email'     => $email,
                ],
            ]);
        }
    }

    $db->insertOne('notifications', [
        'userId'         => $companyId ?: $email,
        'recipientEmail' => $email,
        'type'           => 'arco',
        'title'          => 'Solicitud ' . $requestId . ' recibida',
        'message'        => 'Tu solicitud de ' . $tipo . ' fue registrada. Plazo: 10 días hábiles.',
        'read'           => false,
        'createdAt'      => $now,
        'requestId'      => $requestId,
    ]);

    audit_log('portal_arco_created', [
        'requestId' => $requestId,
        'tipo'      => $tipo,
        'email'     => $email,
    ], null);

    json_response([
        'success'   => true,
        'requestId' => $requestId,
        'plazoDias' => PORTAL_ARCO_SLA_DAYS,
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Solicitar datos concretos
// ═══════════════════════════════════════════════════════════
function portalRequestConcreteData() {
    $email = Auth::requirePortalSession();
    $body  = get_body();

    $motivo = trim((string)($body['motivo'] ?? ''));

    $db = Database::getInstance();

    $existing = $db->findOne('arco_requests', [
        'solicitante.email' => $email,
        'source'            => 'concrete_data',
        'status'            => ['$nin' => ['completed','resolved','finished','rejected']],
    ]);
    if ($existing) {
        json_error('Ya tienes una solicitud de datos concretos en curso: ' . ($existing['requestId'] ?? ''), 409);
    }

    $consent = $db->findOne('compliance_consents', ['email' => $email]);
    $nombre  = $consent['name'] ?? $email;
    $rut     = $consent['rut'] ?? '';
    $companyId = $consent['userId'] ?? '';

    $requestId = 'DATA-' . strtoupper(bin2hex(random_bytes(4)));
    $now       = date('c');

    $db->insertOne('arco_requests', [
        'requestId'      => $requestId,
        'solicitante'    => ['nombre' => $nombre, 'rut' => $rut, 'email' => $email],
        'tipo'           => 'acceso',
        'descripcion'    => $motivo ?: 'Solicitud de acceso a datos concretos desde el portal del titular',
        'companyId'      => $companyId,
        'status'         => 'pending_identity',
        'identityStatus' => 'pending',
        'source'         => 'concrete_data',
        'slaDays'        => PORTAL_ARCO_SLA_DAYS,
        'createdAt'      => $now,
        'updatedAt'      => $now,
        'statusHistory'  => [[
            'at'     => $now,
            'by'     => 'titular',
            'status' => 'pending_identity',
            'kind'   => 'created',
            'note'   => 'Solicitud de datos concretos. Pendiente de validación de identidad.',
        ]],
    ]);

    if ($companyId) {
        $config = $db->findOne('compliance_config', ['userId' => $companyId]) ?? [];
        if (!empty($config['dpdEmail'])) {
            NotificationService::queue($db, [
                'companyId'     => $companyId,
                'channel'       => 'email',
                'recipient'     => $config['dpdEmail'],
                'recipientType' => 'dpo',
                'templateCode'  => 'arco_identity_required',
                'variables'     => [
                    'requestId' => $requestId,
                    'titular'   => $nombre,
                    'email'     => $email,
                ],
            ]);
        }
    }

    audit_log('portal_concrete_data_requested', [
        'requestId' => $requestId,
        'email'     => $email,
    ], null);

    json_response([
        'success'   => true,
        'requestId' => $requestId,
        'plazoDias' => PORTAL_ARCO_SLA_DAYS,
        'nextStep'  => 'Sube tu cédula de identidad vigente (frente y reverso) y una selfie sosteniéndola para que el DPO valide tu identidad.',
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Subir identidad
// ═══════════════════════════════════════════════════════════
function portalUploadIdentity() {
    $email = Auth::requirePortalSession();

    $requestId = trim((string)($_POST['requestId'] ?? ''));
    if (!$requestId) json_error('requestId requerido');

    $db = Database::getInstance();

    $req = $db->findOne('arco_requests', [
        'requestId'         => $requestId,
        'solicitante.email' => $email,
        'source'            => 'concrete_data',
    ]);
    if (!$req) json_error('solicitud no encontrada', 404);
    if (($req['identityStatus'] ?? '') === 'verified') json_error('identidad ya verificada');

    if (!is_dir(PORTAL_IDENTITY_DIR)) {
        if (!@mkdir(PORTAL_IDENTITY_DIR, 0750, true)) {
            json_error('no se pudo preparar el directorio de subida', 500);
        }
    }

    $files = [];
    foreach (['cedula_frontal', 'cedula_reverso', 'selfie'] as $field) {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            json_error("Falta el archivo: $field");
        }
        $f = $_FILES[$field];

        if ($f['size'] > PORTAL_IDENTITY_MAX_MB * 1024 * 1024) {
            json_error("$field excede el tamaño máximo");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            json_error("$field debe ser JPG, PNG o WEBP");
        }

        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $safeName = $requestId . '_' . $field . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target   = PORTAL_IDENTITY_DIR . $safeName;

        if (!move_uploaded_file($f['tmp_name'], $target)) {
            json_error("no se pudo guardar $field", 500);
        }

        $files[$field] = [
            'path'       => 'uploads/identity/' . $safeName,
            'hash'       => hash_file('sha256', $target),
            'size'       => $f['size'],
            'mime'       => $mime,
            'uploadedAt' => date('c'),
        ];
    }

    $now = date('c');

    $db->updateOne('arco_requests', ['_id' => $req['_id']], [
        'identityDocuments'  => $files,
        'identityStatus'     => 'documents_uploaded',
        'identityUploadedAt' => $now,
        'status'             => 'pending_validation',
        'updatedAt'          => $now,
    ]);

    $history = $req['statusHistory'] ?? [];
    $history[] = [
        'at'     => $now,
        'by'     => 'titular',
        'status' => 'pending_validation',
        'kind'   => 'identity_uploaded',
        'note'   => 'Documentos de identidad subidos. Pendiente de validación del DPO.',
    ];
    $db->updateOne('arco_requests', ['_id' => $req['_id']], ['statusHistory' => $history]);

    if (!empty($req['companyId'])) {
        $config = $db->findOne('compliance_config', ['userId' => $req['companyId']]) ?? [];
        if (!empty($config['dpdEmail'])) {
            NotificationService::queue($db, [
                'companyId'     => $req['companyId'],
                'channel'       => 'email',
                'recipient'     => $config['dpdEmail'],
                'recipientType' => 'dpo',
                'templateCode'  => 'arco_identity_uploaded',
                'variables'     => [
                    'requestId' => $requestId,
                    'titular'   => $req['solicitante']['nombre'] ?? '',
                ],
            ]);
        }
    }

    audit_log('portal_identity_uploaded', [
        'requestId' => $requestId,
        'email'     => $email,
    ], null);

    json_response([
        'success' => true,
        'message' => 'Documentos recibidos. El DPO los validará en un plazo máximo de 3 días hábiles.',
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAPA 3 — Estado de solicitud de datos concretos
// ═══════════════════════════════════════════════════════════
function portalConcreteDataStatus() {
    $email     = Auth::requirePortalSession();
    $requestId = $_GET['requestId'] ?? '';

    if (!$requestId) json_error('requestId requerido');

    $db = Database::getInstance();
    $req = $db->findOne('arco_requests', [
        'requestId'         => $requestId,
        'solicitante.email' => $email,
        'source'            => 'concrete_data',
    ]);
    if (!$req) json_error('solicitud no encontrada', 404);

    $response = [
        'requestId'      => $req['requestId'],
        'status'         => $req['status'] ?? 'pending',
        'identityStatus' => $req['identityStatus'] ?? 'pending',
        'createdAt'      => $req['createdAt'] ?? null,
        'updatedAt'      => $req['updatedAt'] ?? null,
        'daysRemaining'  => max(0, PORTAL_ARCO_SLA_DAYS - _portalBusinessDays($req['createdAt'] ?? null)),
    ];

    if (in_array($req['status'] ?? '', ['completed','resolved','finished'], true)) {
        $response['response']          = $req['response'] ?? '';
        $response['deliveryUrl']       = $req['deliveryUrl'] ?? null;
        $response['deliveryExpiresAt'] = $req['deliveryExpiresAt'] ?? null;
        $response['resolvedAt']        = $req['resolvedAt'] ?? $req['finishedAt'] ?? null;
    }

    json_response($response);
}

// ═══════════════════════════════════════════════════════════
// Descargar todos mis datos (Art. 13 - Portabilidad)
// ═══════════════════════════════════════════════════════════
function portalDownloadAll() {
    $email  = Auth::requirePortalSession();
    $format = strtolower($_GET['format'] ?? 'json');
    $db     = Database::getInstance();

    $consents     = $db->find('compliance_consents', ['email' => $email]);
    $arcoRequests = $db->find('arco_requests', ['solicitante.email' => $email]);

    $companyId = null;
    foreach ($consents as $c) {
        if (!empty($c['userId'])) { $companyId = $c['userId']; break; }
    }

    $data = [
        'exportedAt'   => date('c'),
        'subject'      => ['email' => $email],
        'format'       => 'Ley 21.719 - Art. 13 (Portabilidad)',
        'consents'     => $consents,
        'arcoRequests' => $arcoRequests,
    ];

    if ($companyId) {
        $config = $db->findOne('compliance_config', ['userId' => $companyId]) ?? [];
        $data['company'] = [
            'name' => $config['companyName'] ?? '',
            'rut'  => $config['companyRut'] ?? '',
            'dpd'  => [
                'name'  => $config['dpdName'] ?? '',
                'email' => $config['dpdEmail'] ?? '',
            ],
        ];
        $data['treatmentMap'] = $db->find('compliance_inventory', ['userId' => $companyId]);
        $data['processors']   = $db->find('compliance_processors', ['userId' => $companyId]);
        $data['transfers']    = $db->find('compliance_transfers', ['userId' => $companyId]);
    }

    while (ob_get_level() > 0) ob_end_clean();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-datos-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sección', 'Campo', 'Valor']);
        _portalFlattenForCsv($data, '', function($path, $value) use ($out) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($out, [$path, '', $value]);
        });
        fclose($out);
        exit;
    }

    if ($format === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-datos-' . date('Ymd-His') . '.xml"');
        echo _portalArrayToXml($data, 'mis-datos');
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mis-datos-' . date('Ymd-His') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════
function _portalBusinessDays($dateStr) {
    if (!$dateStr) return 0;
    $ts = strtotime($dateStr);
    if (!$ts) return 0;
    $days = 0;
    $cur  = strtotime(date('Y-m-d', $ts));
    $today = strtotime(date('Y-m-d'));
    while ($cur < $today) {
        $cur = strtotime('+1 day', $cur);
        if ((int)date('N', $cur) < 6) $days++;
    }
    return $days;
}

function _portalFlattenForCsv($data, $prefix, $cb) {
    foreach ($data as $k => $v) {
        $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
        if (is_array($v) || is_object($v)) {
            _portalFlattenForCsv((array)$v, $path, $cb);
        } else {
            $cb($path, $v);
        }
    }
}

function _portalArrayToXml($data, $rootName) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $rootName . '/>');
    $add = function($node, $value) use (&$add) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $key = is_numeric($k) ? 'item' : preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$k);
                $child = $node->addChild($key);
                $add($child, $v);
            }
        } else {
            $node[0] = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
    };
    $add($xml, $data);
    return $xml->asXML();
}

// ═══════════════════════════════════════════════════════════
// Descarga del enlace temporal (entregado por el DPO)
// ═══════════════════════════════════════════════════════════
function portalDeliver() {
    $token = $_GET['token'] ?? '';
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Enlace inválido</h1>';
        exit;
    }

    $db = Database::getInstance();
    $tokenHash = hash('sha256', $token);

    $req = $db->findOne('arco_requests', [
        'deliveryTokenHash' => $tokenHash,
        'source'            => 'concrete_data',
    ]);

    if (!$req) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Enlace no encontrado o ya utilizado</h1>';
        exit;
    }

    $expiresAt = $req['deliveryExpiresAt'] ?? null;
    if ($expiresAt && strtotime($expiresAt) < time()) {
        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Enlace expirado</h1><p>Solicita una nueva entrega al DPO.</p>';
        exit;
    }

    $email    = $req['solicitante']['email'] ?? '';
    $consents = $db->find('compliance_consents', ['email' => $email]);
    $arcos    = $db->find('arco_requests', ['solicitante.email' => $email]);

    $data = [
        'exportedAt'   => date('c'),
        'requestId'    => $req['requestId'],
        'subject'      => $req['solicitante'],
        'consents'     => $consents,
        'arcoRequests' => $arcos,
        'response'     => $req['response'] ?? '',
    ];

    if (!empty($req['companyId'])) {
        $config = $db->findOne('compliance_config', ['userId' => $req['companyId']]) ?? [];
        $data['company'] = [
            'name' => $config['companyName'] ?? '',
            'rut'  => $config['companyRut'] ?? '',
            'dpd'  => [
                'name'  => $config['dpdName'] ?? '',
                'email' => $config['dpdEmail'] ?? '',
            ],
        ];
    }

    $db->updateOne('arco_requests', ['_id' => $req['_id']], [
        'deliveryAccessedAt' => date('c'),
        'deliveryAccessedIp' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    audit_log('concrete_data_downloaded', [
        'requestId' => $req['requestId'],
        'email'     => $email,
    ], null);

    $format = strtolower($_GET['format'] ?? 'json');
    while (ob_get_level() > 0) ob_end_clean();

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-datos-' . $req['requestId'] . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sección', 'Campo', 'Valor']);
        _portalFlattenForCsv($data, '', function($path, $value) use ($out) {
            if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            fputcsv($out, [$path, '', $value]);
        });
        fclose($out);
        exit;
    }

    if ($format === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="mis-datos-' . $req['requestId'] . '.xml"');
        echo _portalArrayToXml($data, 'mis-datos');
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mis-datos-' . $req['requestId'] . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}