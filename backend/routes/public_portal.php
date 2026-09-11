<?php
require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/NotificationService.php';

// Configuración del portal
const PORTAL_CODE_TTL = 600;
const PORTAL_MAX_ATTEMPTS = 5;
const PORTAL_RESEND_COOLDOWN = 60;
const PORTAL_ARCO_SLA_DAYS = 10;

// ═══════════════════════════════════════════════════════════
// Solicitar código por email
// ═══════════════════════════════════════════════════════════
function portalRequestCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }

    $db = Database::getInstance();
    $now = date('c');

    $cooldownCutoff = date('c', time() - PORTAL_RESEND_COOLDOWN);
    $recent = $db->find('portal_sessions', [
        'email' => $email,
        'createdAt' => ['$gte' => $cooldownCutoff],
    ]);
    if (!empty($recent)) {
        json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
    }

    $exists = $db->findOne('compliance_consents', ['email' => $email])
           || $db->findOne('arco_requests', ['solicitante.email' => $email]);

    if ($exists) {
        $pending = $db->find('portal_sessions', [
            'email' => $email,
            'used' => false,
            'expiresAt' => ['$gte' => $now],
        ]);
        foreach ($pending as $p) {
            $db->updateOne('portal_sessions', ['_id' => $p['_id']], ['used' => true]);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db->insertOne('portal_sessions', [
            'email' => $email,
            'codeHash' => hash('sha256', $code),
            'expiresAt' => date('c', time() + PORTAL_CODE_TTL),
            'used' => false,
            'attempts' => 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'createdAt' => $now,
        ]);

        $companyId = '';
        $consent = $db->findOne('compliance_consents', ['email' => $email]);
        if ($consent && !empty($consent['userId'])) {
            $companyId = $consent['userId'];
        } else {
            $arco = $db->findOne('arco_requests', ['solicitante.email' => $email]);
            if ($arco) {
                $companyId = $arco['companyId'] ?? ($arco['userId'] ?? '');
            }
        }

        NotificationService::queue($db, [
            'companyId' => $companyId,
            'channel' => 'email',
            'recipient' => $email,
            'recipientType' => 'titular',
            'templateCode' => 'portal_magic_code',
            'variables' => ['code' => $code],
        ]);

        if (defined('PORTAL_DEV_SHOW_CODE') && PORTAL_DEV_SHOW_CODE) {
            json_response([
                'success' => true,
                'message' => 'Si el email existe recibirás un código',
                'dev_code' => $code,
            ]);
        }
    }

    json_response(['success' => true, 'message' => 'Si el email existe recibirás un código']);
}

// ═══════════════════════════════════════════════════════════
// Verificar código
// ═══════════════════════════════════════════════════════════
function portalVerifyCode() {
    $body = get_body();
    $email = strtolower(trim($body['email'] ?? ''));
    $code = trim((string)($body['code'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('email inválido');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        json_error('código inválido o expirado', 401);
    }

    $db = Database::getInstance();
    $now = date('c');

    $sessions = $db->find('portal_sessions', [
        'email' => $email,
        'used' => false,
        'expiresAt' => ['$gte' => $now],
    ]);

    if (empty($sessions)) {
        json_error('código inválido o expirado', 401);
    }

    usort($sessions, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
    $session = $sessions[0];

    $attempts = (int)($session['attempts'] ?? 0);
    if ($attempts >= PORTAL_MAX_ATTEMPTS) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], ['used' => true]);
        json_error('demasiados intentos. Solicita un nuevo código.', 429);
    }

    if (!hash_equals((string)$session['codeHash'], hash('sha256', $code))) {
        $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
            'attempts' => $attempts + 1,
            'lastAttemptAt' => $now,
        ]);
        json_error('código inválido o expirado', 401);
    }

    $token = Auth::createPortalToken($email);

    $db->updateOne('portal_sessions', ['_id' => $session['_id']], [
        'used' => true,
        'usedAt' => $now,
    ]);

    foreach ($sessions as $s) {
        if ($s['_id'] !== $session['_id']) {
            $db->updateOne('portal_sessions', ['_id' => $s['_id']], ['used' => true]);
        }
    }

    json_response(['success' => true, 'token' => $token]);
}

// ═══════════════════════════════════════════════════════════
// MY DATA — Todo lo que la empresa declara sobre ti
// ═══════════════════════════════════════════════════════════
function portalMyData() {
    $email = Auth::requirePortalSession();
    $db = Database::getInstance();

    // ── 1. Consentimientos ──
    $consents = $db->find('compliance_consents', ['email' => $email]);

    // ── 2. Solicitudes ARCO ──
    $arcoRequests = $db->find('arco_requests', ['solicitante.email' => $email]);

    // ── 3. Resolver companyId ──
    $companyId = null;
    foreach ($consents as $c) {
        if (!empty($c['userId'])) { $companyId = $c['userId']; break; }
    }
    if (!$companyId) {
        foreach ($arcoRequests as $r) {
            if (!empty($r['companyId'])) { $companyId = $r['companyId']; break; }
        }
    }

    // ── 4. Inventario (RAT) + encargados + transferencias + config ──
    $inventory  = [];
    $processors = [];
    $transfers  = [];
    $config     = [];

    if ($companyId) {
        $inventory  = $db->find('compliance_inventory', ['userId' => $companyId]);
        $processors = $db->find('compliance_processors', ['userId' => $companyId]);
        $transfers  = $db->find('compliance_transfers', ['userId' => $companyId]);
        $config     = $db->findOne('compliance_config', ['userId' => $companyId]) ?? [];
    }

    // ── 5. Datos del titular ──
    $subject = ['email' => $email, 'name' => null, 'rut' => null];
    foreach ($consents as $c) {
        if (empty($subject['name']) && !empty($c['name'])) $subject['name'] = $c['name'];
        if (empty($subject['rut'])  && !empty($c['rut']))  $subject['rut']  = $c['rut'];
    }

    // ── 6. Categorías únicas de datos ──
    $dataCategories = [];
    foreach ($inventory as $inv) {
        $cats = $inv['dataCategories'] ?? [];
        if (is_string($cats)) $cats = array_map('trim', explode(',', $cats));
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                $cat = trim((string)$cat);
                if ($cat !== '' && !in_array($cat, $dataCategories, true)) {
                    $dataCategories[] = $cat;
                }
            }
        }
    }

    // ── 7. Mapa de tratamiento (una entrada por actividad del RAT) ──
    $treatmentMap = [];
    $retentionMap = []; // { categoria: días }
    foreach ($inventory as $inv) {
        $cats = $inv['dataCategories'] ?? [];
        if (is_string($cats)) $cats = array_map('trim', explode(',', $cats));
        if (!is_array($cats)) $cats = [];

        $retentionDays = isset($inv['retentionDays']) && $inv['retentionDays'] !== null
            ? (int)$inv['retentionDays'] : null;

        // Retención más larga por categoría (el límite superior)
        if ($retentionDays) {
            foreach ($cats as $cat) {
                $cat = trim((string)$cat);
                if ($cat === '') continue;
                if (!isset($retentionMap[$cat]) || $retentionMap[$cat] < $retentionDays) {
                    $retentionMap[$cat] = $retentionDays;
                }
            }
        }

        $treatmentMap[] = [
            'name'           => (string)($inv['name'] ?? 'Sin nombre'),
            'purpose'        => (string)($inv['purpose'] ?? ''),
            'legalBasis'     => (string)($inv['legalBasis'] ?? ''),
            'dataCategories' => $cats,
            'retentionDays'  => $retentionDays,
            'sensitive'      => !empty($inv['sensitive']),
            'childrenData'   => !empty($inv['childrenData']),
            'recipients'     => is_array($inv['recipients'] ?? null) ? $inv['recipients'] : [],
            'risk'           => (string)($inv['risk'] ?? 'low'),
        ];
    }

    // ── 8. Encargados ──
    $processorList = [];
    foreach ($processors as $p) {
        $processorList[] = [
            'name'        => (string)($p['name'] ?? ''),
            'serviceType' => (string)($p['serviceType'] ?? ''),
            'country'     => (string)($p['country'] ?? ''),
            'hasContract' => ($p['hasContract'] ?? '') === 'si',
        ];
    }

    // ── 9. Transferencias internacionales ──
    $transferList = [];
    foreach ($transfers as $t) {
        $transferList[] = [
            'country'   => (string)($t['destinationCountry'] ?? ''),
            'recipient' => (string)($t['recipient'] ?? ''),
            'mechanism' => (string)($t['mechanism'] ?? ''),
        ];
    }

    // ── 10. Consentimientos simplificados ──
    $consentList = [];
    foreach ($consents as $c) {
        $consentList[] = [
            'id'         => (string)($c['_id'] ?? ''),
            'purpose'    => (string)($c['purpose'] ?? 'Sin especificar'),
            'legalBasis' => (string)($c['legalBasis'] ?? ''),
            'grantedAt'  => $c['createdAt'] ?? null,
            'revokedAt'  => $c['revokedAt'] ?? null,
            'active'     => empty($c['revokedAt']),
            'revocable'  => empty($c['revokedAt']),
        ];
    }

    // ── 11. Solicitudes ARCO con SLA ──
    $arcoList = [];
    foreach ($arcoRequests as $r) {
        $status = (string)($r['status'] ?? 'pending');
        $isClosed = in_array($status, ['completed', 'resolved', 'finished', 'rejected'], true);
        $bDays = _portalBusinessDays($r['createdAt'] ?? null);
        $arcoList[] = [
            'requestId'     => (string)($r['requestId'] ?? ''),
            'type'          => (string)($r['tipo'] ?? $r['type'] ?? 'acceso'),
            'description'   => (string)($r['descripcion'] ?? ''),
            'status'        => $status,
            'response'      => (string)($r['response'] ?? ''),
            'createdAt'     => $r['createdAt'] ?? null,
            'updatedAt'     => $r['updatedAt'] ?? null,
            'resolvedAt'    => $r['resolvedAt'] ?? $r['finishedAt'] ?? null,
            'businessDays'  => $bDays,
            'daysRemaining' => max(0, PORTAL_ARCO_SLA_DAYS - $bDays),
            'overdue'       => !$isClosed && $bDays > PORTAL_ARCO_SLA_DAYS,
            'closed'        => $isClosed,
        ];
    }

    // ── 12. Resumen ──
    $activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $openRequests = count(array_filter($arcoRequests, fn($r) => !in_array(
        $r['status'] ?? '', ['completed','resolved','finished','rejected'], true
    )));

    json_response([
        'email' => $email,
        'subject' => $subject,

        'company' => [
            'name'    => (string)($config['companyName'] ?? ''),
            'rut'     => (string)($config['companyRut'] ?? ''),
            'dpd'     => [
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

        // Mapa de tratamiento
        'dataCategories' => $dataCategories,
        'treatmentMap'   => $treatmentMap,
        'retention'      => $retentionMap,

        // Terceros
        'processors' => $processorList,
        'transfers'  => $transferList,

        // Derechos
        'consents'     => $consentList,
        'arcoRequests' => $arcoList,

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
// Revocar consentimiento
// ═══════════════════════════════════════════════════════════
function portalRevokeConsent() {
    $email = Auth::requirePortalSession();
    $body = get_body();
    $consentId = $body['consentId'] ?? '';

    if (!$consentId) json_error('consentId requerido');

    $db = Database::getInstance();
    $consent = $db->findOne('compliance_consents', ['_id' => $consentId, 'email' => $email]);

    if (!$consent) json_error('consentimiento no encontrado', 404);
    if (!empty($consent['revokedAt'])) json_error('ya estaba revocado');

    $db->updateOne('compliance_consents', ['_id' => $consentId], [
        'active' => false,
        'revokedAt' => date('c'),
        'revokedVia' => 'portal_titular',
        'revokedFromIp' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    NotificationService::queue($db, [
        'companyId' => $consent['userId'] ?? '',
        'channel' => 'email',
        'recipient' => $email,
        'recipientType' => 'titular',
        'templateCode' => 'consent_revoked',
        'variables' => [
            'nombre' => $consent['name'] ?? '',
            'purpose' => $consent['purpose'] ?? '',
            'fecha' => date('d/m/Y'),
        ],
    ]);

    json_response(['success' => true]);
}

// ═══════════════════════════════════════════════════════════
// Descargar todos mis datos (Art. 13 - Portabilidad)
// ═══════════════════════════════════════════════════════════
function portalDownloadAll() {
    $email = Auth::requirePortalSession();
    $format = strtolower($_GET['format'] ?? 'json');
    $db = Database::getInstance();

    $consents = $db->find('compliance_consents', ['email' => $email]);
    $arcoRequests = $db->find('arco_requests', ['solicitante.email' => $email]);

    // Resolver companyId
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

/**
 * Días hábiles transcurridos desde $dateStr (misma lógica que el dashboard
 * para que los números coincidan exactamente).
 */
function _portalBusinessDays($dateStr) {
    if (!$dateStr) return 0;
    $ts = strtotime($dateStr);
    if (!$ts) return 0;
    $days = 0;
    $cur = strtotime(date('Y-m-d', $ts));
    $today = strtotime(date('Y-m-d'));
    while ($cur < $today) {
        $cur = strtotime('+1 day', $cur);
        if ((int)date('N', $cur) < 6) $days++;
    }
    return $days;
}

/**
 * Aplana un array anidado para exportar a CSV.
 */
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

/**
 * Convierte un array a XML (para portabilidad Art. 13).
 */
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