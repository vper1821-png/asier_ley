<?php
// Report routes

// ═══════════════════════════════════════════════════════════════════
// Límites ampliados para generación de PDFs complejos
// ═══════════════════════════════════════════════════════════════════
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');
@set_time_limit(300);

// ═══════════════════════════════════════════════════════════════════
// HELPERS BSON → PHP (universales, seguros)
// ═══════════════════════════════════════════════════════════════════

/**
 * Convierte cualquier valor (BSONDocument, BSONArray, array, JSON string, null)
 * a un array PHP nativo. Nunca lanza error.
 */
function bsonToArray($v) {
    if ($v === null) return [];
    if (is_array($v)) return $v;
    if ($v instanceof \MongoDB\Model\BSONDocument) return $v->getArrayCopy();
    if ($v instanceof \MongoDB\Model\BSONArray) return $v->getArrayCopy();
    if ($v instanceof \ArrayObject) return $v->getArrayCopy();
    if ($v instanceof \Traversable) return iterator_to_array($v);
    if (is_object($v)) {
        $decoded = json_decode(json_encode($v), true);
        return is_array($decoded) ? $decoded : [];
    }
    if (is_string($v)) {
        $decoded = json_decode($v, true);
        if (is_array($decoded)) return $decoded;
        if ($v !== '') return [$v];
        return [];
    }
    return (array)$v;
}

/**
 * Convierte cualquier valor a un array de strings limpios.
 */
function toStrArr($v) {
    if ($v === null || $v === '') return [];
    if (is_string($v)) {
        $decoded = json_decode($v, true);
        if (is_array($decoded)) {
            $v = $decoded;
        } else {
            return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
        }
    }
    $arr = bsonToArray($v);
    $out = [];
    foreach ($arr as $item) {
        if (is_scalar($item) && !is_bool($item)) {
            $s = trim((string)$item);
            if ($s !== '') $out[] = $s;
        } elseif (is_bool($item)) {
            $out[] = $item ? 'true' : 'false';
        }
    }
    return array_values($out);
}

/**
 * Convierte un valor a string seguro (nunca "Array").
 */
function bsonToString($v) {
    if ($v === null) return '';
    if (is_scalar($v)) return (string)$v;
    if (is_array($v) || is_object($v)) {
        $arr = bsonToArray($v);
        if (empty($arr)) return '';
        $allScalar = true;
        foreach ($arr as $x) { if (!is_scalar($x)) { $allScalar = false; break; } }
        if ($allScalar) return implode(', ', array_map('strval', $arr));
        return json_encode($arr, JSON_UNESCAPED_UNICODE);
    }
    return '';
}

// ═══════════════════════════════════════════════════════════════════
// CRUD básico
// ═══════════════════════════════════════════════════════════════════

function listAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $isSuperAdmin = !empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin';
    $filter = $isSuperAdmin ? [] : [];

    if (!$isSuperAdmin) {
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
        $filter = ['userId' => ['$in' => $userIds]];
    }

    $reports = $db->find('reports', $filter);
    json_response($reports);
}

function generate() {
    $user = Auth::requireAuth();
    $body = get_body();
    $db = Database::getInstance();

    $title = $body['title'] ?? 'Reporte personalizado';
    $type = $body['type'] ?? 'custom';

    $agents = $db->count('agents', ['userId' => $user['_id']]);
    $databases = $db->count('databases', ['userId' => $user['_id']]);
    $alerts = $db->count('alerts', ['userId' => $user['_id']]);
    $resolved = $db->count('alerts', ['userId' => $user['_id'], 'resolved' => true]);
    $open = $alerts - $resolved;

    $summary = [
        'agents' => $agents,
        'databases' => $databases,
        'alerts' => $alerts,
        'openAlerts' => $open,
        'resolvedAlerts' => $resolved,
    ];

    $report = $db->insertOne('reports', [
        'userId' => $user['_id'],
        'title' => $title,
        'type' => $type,
        'summary' => $summary,
        'generatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'report' => $report]);
}

function training() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    $report = $db->insertOne('reports', [
        'userId' => $user['_id'],
        'title' => 'Reporte de capacitación',
        'type' => 'training',
        'summary' => [
            'completedTrainings' => (int)$db->count('compliance_trainings', ['completed' => true]),
            'pendingTrainings' => (int)$db->count('compliance_trainings', ['completed' => ['$in' => [false, null]]]),
            'date' => date('c'),
        ],
        'generatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'report' => $report]);
}

// ═══════════════════════════════════════════════════════════════════
// HELPERS PDF
// ═══════════════════════════════════════════════════════════════════

function h_($v): string {
    return htmlspecialchars(bsonToString($v), ENT_QUOTES, 'UTF-8');
}

function pdf_page_band(string $title): string {
    return '<div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

function pdf_section_title(int $num, string $title): string {
    return '<table class="sec-title"><tr>'
        . '<td class="sec-bar"></td>'
        . '<td class="sec-num">' . str_pad((string)$num, 2, '0', STR_PAD_LEFT) . '</td>'
        . '<td class="sec-text">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr></table><div class="sec-rule"></div>';
}

function pdf_fields(array $fields): string {
    $out = '<table class="fields">';
    foreach ($fields as $pair) {
        $label = $pair[0] ?? '';
        $value = $pair[1] ?? '';
        $out .= '<tr><td class="f-label">' . h_($label) . ':</td><td class="f-value">' . h_($value) . '</td></tr>';
    }
    return $out . '</table>';
}

function pdf_data_table(array $headers, array $rows): string {
    $out = '<table class="data"><thead><tr>';
    foreach ($headers as $hcol) {
        $out .= '<th>' . h_($hcol) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        $out .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
        foreach ($row as $cell) {
            $cellStr = bsonToString($cell);
            $out .= '<td>' . h_($cellStr === '' ? '-' : $cellStr) . '</td>';
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function url_accessible($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'No configurada', 'http' => '—'];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 'Sin curl (no verificable)', 'http' => '—'];
    }
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SecureLab-Report/1.0');
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 0) return ['status' => 'No accesible', 'http' => $err ?: 'timeout'];
        $ok = $code >= 200 && $code < 400;
        return ['status' => $ok ? 'Accesible' : 'No accesible', 'http' => (string)$code];
    } catch (\Throwable $e) {
        return ['status' => 'Error: ' . $e->getMessage(), 'http' => '—'];
    }
}

// ═══════════════════════════════════════════════════════════════════
// DESCARGA DE REPORTE PRINCIPAL (compliance)
// ═══════════════════════════════════════════════════════════════════

function download() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    $db = Database::getInstance();

    // Servir PDFs ya generados
    if (preg_match('/\.pdf$/i', $id)) {
        $safe = basename($id);
        $path = __DIR__ . '/../reports/' . $safe;
        if (is_file($path)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $safe . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        json_error('archivo no encontrado', 404);
    }

    $report = null;
    if ($id === '' || $id === 'all') {
        $filename = 'reporte_compliance_' . date('Ymd-His') . '.pdf';
        $reportTitle = 'Informe de Cumplimiento Ley 21.719';
    } else {
        $report = $db->findOne('reports', ['_id' => $id, 'userId' => $user['_id']]);
        if (!$report) json_error('reporte no encontrado', 404);
        $filename = 'reporte_' . ($report['_id'] ?? $id) . '.pdf';
        $reportTitle = $report['title'] ?? 'Informe de Cumplimiento Ley 21.719';
    }

    if (!empty($report['type']) && $report['type'] === 'security') {
        downloadSecurityReport($user, $report);
        exit;
    }
    if (!empty($report['type']) && $report['type'] === 'training') {
        downloadTrainingReport($user, $report);
        exit;
    }

    // Resolver IDs de empresa
    $isSuperAdmin = !empty($user['isAdmin']) || ($user['role'] ?? '') === 'superadmin';
    if ($isSuperAdmin) {
        $userIds = null;
        $uid = $user['_id'];
    } else {
        $record = $db->findOne('users', ['_id' => $user['_id']]);
        $companyId = $record['companyId'] ?? $user['_id'];
        $uid = $companyId;
        $subs = $db->find('users', ['companyId' => $companyId]);
        $userIds = array_values(array_unique(array_map('strval', array_merge(
            [$companyId, $user['_id']],
            array_column($subs, '_id')
        ))));
    }
    $filter = $userIds === null ? [] : ['userId' => ['$in' => $userIds]];
    $filterCompany = $userIds === null ? [] : ['companyId' => ['$in' => $userIds]];

    // Recolectar data
    $config = $db->findOne('compliance_config', $filter) ?? [];

    $agents           = $db->find('agents', $filter);
    $databases        = $db->find('databases', $filter);
    $alerts           = $db->find('alerts', $filter);
    $consents         = $db->find('compliance_consents', $filter);
    $inventory        = $db->find('compliance_inventory', $filter);
    $breaches         = $db->find('compliance_breaches', $filter);
    $dpias            = $db->find('compliance_dpia', $filter);
    $dpas             = $db->find('compliance_dpa', $filter);
    $trainings        = $db->find('compliance_trainings', $filter);
    $pseudoRules      = $db->find('compliance_pseudonymization', $filter);
    $processors       = $db->find('compliance_processors', $filter);
    $transfers        = $db->find('compliance_transfers', $filter);
    $invites          = $db->find('compliance_invites', $filter);
    $breachProtocol   = $db->findOne('compliance_breach_protocol', $filter) ?? [];
    $incidentResponse = $db->findOne('compliance_incident_response', $filter) ?? [];
    $arcoRequests     = $db->find('arco_requests', $filterCompany);
    $auditLogs        = $db->find('audit_logs', $filter, ['limit' => 50]);
    $fileEvents       = $db->find('file_events', $filter, ['limit' => 100]);
    $dbLogs           = $db->find('database_logs', $filter, ['limit' => 100]);
    $hostEvents       = $db->find('host_events', $filter, ['limit' => 100]);
    $fileAudits       = $db->find('file_audit_logs', $filter, ['limit' => 100]);

    // Estadísticas
    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y') . ' a las ' . date('H:i');

    $onlineAgents       = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));
    $openBreaches       = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved'));
    $resolvedBreaches   = count($breaches) - $openBreaches;
    $activeConsents     = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $sensitiveItems     = array_values(array_filter($inventory, fn($i) => !empty($i['sensitive'])));
    $childrenItems      = array_values(array_filter($inventory, fn($i) => !empty($i['childrenData'])));
    $highRiskItems      = array_values(array_filter($inventory, fn($i) => in_array($i['risk'] ?? '', ['high', 'critical'])));
    $trainedCount       = count(array_filter($trainings, fn($t) => !empty($t['signatureData']) || !empty($t['signerName'])));
    $signedInvitesCount = count(array_filter($invites, fn($i) => !empty($i['signed'])));
    $approvedDpias      = count(array_filter($dpias, fn($d) => ($d['status'] ?? '') === 'approved'));
    $activeDpas         = count(array_filter($dpas, fn($d) => ($d['status'] ?? '') === 'active'));
    $executedPseudo     = count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed'])));
    $resolvedArco       = count(array_filter($arcoRequests, fn($r) => in_array($r['status'] ?? '', ['resolved','completed'], true)));

    // Flags
    $hasDpd              = !empty($config['dpdEmail']) && !empty($config['dpdName']);
    $hasApdp             = !empty($config['apdpRegistered']) && !empty($config['apdpRegistrationNumber']);
    $hasPrivacyPolicy    = !empty($config['privacyPolicyUrl']);
    $hasCookiesPolicy    = !empty($config['cookiesPolicyUrl']);
    $hasRetentionPolicy  = !empty($config['dataRetentionPolicy']);
    $hasInventory        = count($inventory) > 0;
    $hasConsents         = count($consents) > 0;
    $allConsentsActive   = $hasConsents && $activeConsents === count($consents);
    $hasDpias            = count($dpias) > 0;
    $hasDpas             = count($dpas) > 0;
    $hasBreaches         = count($breaches) > 0;
    $hasTrainings        = count($trainings) > 0;
    $allTrained          = $hasTrainings && $trainedCount === count($trainings);
    $hasPseudo           = count($pseudoRules) > 0;
    $hasProcessors       = count($processors) > 0;
    $hasTransfers        = count($transfers) > 0;
    $hasBreachProtocol   = !empty($breachProtocol['protocolName']);
    $hasIncidentResponse = !empty($incidentResponse['planName']);
    $hasArco             = count($arcoRequests) > 0 || !empty($config['arcoChannelUrl']);
    $arcoResponses       = $resolvedArco;

    // Checklist
    $checks = [
        ['category' => 'Identificación del Responsable (Art. 14, 28, 31)', 'items' => [
            ['label' => 'Razón social y RUT identificados', 'pass' => !empty($config['companyName']) && !empty($config['companyRut']), 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => !empty($config['companyRut']) ? ('RUT: ' . $config['companyRut']) : 'Falta RUT de la empresa'],
            ['label' => 'Delegado de Protección de Datos (DPD) designado', 'pass' => $hasDpd, 'article' => 'Art. 28', 'severity' => 'grave', 'detail' => $hasDpd ? ($config['dpdName'] . ' (' . $config['dpdEmail'] . ')') : 'No se ha designado DPD'],
            ['label' => 'Inscripción en Registro Nacional APDP', 'pass' => $hasApdp, 'article' => 'Art. 31', 'severity' => 'grave', 'detail' => $hasApdp ? ('Registro: ' . $config['apdpRegistrationNumber']) : 'No se ha registrado ante la APDP'],
        ]],
        ['category' => 'Transparencia y Políticas (Art. 14 ter)', 'items' => [
            ['label' => 'Política de privacidad publicada', 'pass' => $hasPrivacyPolicy, 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => $hasPrivacyPolicy ? $config['privacyPolicyUrl'] : 'Sin política publicada'],
            ['label' => 'Política de cookies publicada', 'pass' => $hasCookiesPolicy, 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => $hasCookiesPolicy ? $config['cookiesPolicyUrl'] : 'Sin política de cookies'],
            ['label' => 'Política de retención de datos definida', 'pass' => $hasRetentionPolicy, 'article' => 'Art. 14', 'severity' => 'leve', 'detail' => $hasRetentionPolicy ? 'Definida' : 'No definida'],
        ]],
        ['category' => 'Base de Licitud y Consentimiento (Art. 12-13)', 'items' => [
            ['label' => 'Consentimientos registrados y trazables', 'pass' => $allConsentsActive, 'article' => 'Art. 12', 'severity' => 'grave', 'detail' => $hasConsents ? ($activeConsents . ' activos de ' . count($consents)) : 'Sin consentimientos'],
            ['label' => 'Datos sensibles con base legal específica', 'pass' => count($sensitiveItems) === 0 || $activeConsents > 0, 'article' => 'Art. 16', 'severity' => 'gravísima', 'detail' => count($sensitiveItems) . ' items sensibles'],
            ['label' => 'Datos de niños con consentimiento parental', 'pass' => count($childrenItems) === 0 || $activeConsents > 0, 'article' => 'Art. 17', 'severity' => 'gravísima', 'detail' => count($childrenItems) . ' items con datos de menores'],
        ]],
        ['category' => 'Registro de Actividades de Tratamiento - RAT (Art. 14)', 'items' => [
            ['label' => 'Inventario registrado', 'pass' => $hasInventory, 'article' => 'Art. 14', 'severity' => 'grave', 'detail' => count($inventory) . ' actividades'],
            ['label' => 'Todas con finalidad definida', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['purpose']))) === 0, 'article' => 'Art. 3.b', 'severity' => 'grave', 'detail' => 'Verificar campo finalidad'],
            ['label' => 'Todas con base legal definida', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['legalBasis']))) === 0, 'article' => 'Art. 14.1.b', 'severity' => 'grave', 'detail' => 'Verificar campo base legal'],
            ['label' => 'Todas con categorías de datos', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['dataCategories']))) === 0, 'article' => 'Art. 14.1.c', 'severity' => 'grave', 'detail' => 'Verificar categorías'],
            ['label' => 'Todas con plazo de retención', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['retentionDays']))) === 0, 'article' => 'Art. 14.1.e', 'severity' => 'grave', 'detail' => 'Verificar retención'],
        ]],
        ['category' => 'Medidas de Seguridad (Art. 25)', 'items' => [
            ['label' => 'Seudonimización / cifrado implementado', 'pass' => $hasPseudo, 'article' => 'Art. 30', 'severity' => 'grave', 'detail' => $executedPseudo . '/' . count($pseudoRules) . ' reglas ejecutadas'],
            ['label' => 'Monitoreo activo de endpoints', 'pass' => $onlineAgents > 0, 'article' => 'Art. 25', 'severity' => 'grave', 'detail' => $onlineAgents . '/' . count($agents) . ' agentes en línea'],
            ['label' => 'Registro de eventos de seguridad', 'pass' => (count($hostEvents) + count($fileEvents) + count($dbLogs)) > 0, 'article' => 'Art. 25', 'severity' => 'leve', 'detail' => 'Host: ' . count($hostEvents) . ' · Archivos: ' . count($fileEvents) . ' · BD: ' . count($dbLogs)],
        ]],
        ['category' => 'Brechas de Seguridad (Art. 26)', 'items' => [
            ['label' => 'Protocolo de notificación de brechas documentado', 'pass' => $hasBreachProtocol, 'article' => 'Art. 26', 'severity' => 'gravísima', 'detail' => $hasBreachProtocol ? $breachProtocol['protocolName'] : 'Sin protocolo documentado'],
            ['label' => 'Brechas registradas y gestionadas', 'pass' => !$hasBreaches || $resolvedBreaches > 0, 'article' => 'Art. 26', 'severity' => 'gravísima', 'detail' => $openBreaches . ' abiertas / ' . $resolvedBreaches . ' resueltas'],
            ['label' => 'Notificación a APDP sin dilaciones', 'pass' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved' && empty($b['notifiedAPDP']))) === 0, 'article' => 'Art. 26', 'severity' => 'gravísima', 'detail' => 'Revisar notificaciones pendientes'],
        ]],
        ['category' => 'Evaluación de Impacto - DPIA (Art. 14 quater)', 'items' => [
            ['label' => 'DPIA realizadas para alto riesgo', 'pass' => count($highRiskItems) === 0 || $hasDpias, 'article' => 'Art. 14 quater', 'severity' => 'grave', 'detail' => $hasDpias ? ($approvedDpias . ' aprobadas de ' . count($dpias)) : 'Sin DPIA'],
            ['label' => 'DPIA aprobadas para datos sensibles', 'pass' => count($sensitiveItems) === 0 || count(array_filter($dpias, fn($d) => ($d['status'] ?? '') === 'approved')) > 0, 'article' => 'Art. 14 quater', 'severity' => 'grave', 'detail' => 'Verificar cobertura'],
        ]],
        ['category' => 'Encargados del Tratamiento (Art. 15 bis)', 'items' => [
            ['label' => 'Registro de encargados', 'pass' => $hasProcessors, 'article' => 'Art. 15 bis', 'severity' => 'grave', 'detail' => count($processors) . ' encargados'],
            ['label' => 'Contratos DPA firmados', 'pass' => count(array_filter($processors, fn($p) => ($p['hasContract'] ?? '') === 'si')) > 0 || !$hasProcessors, 'article' => 'Art. 15 bis', 'severity' => 'grave', 'detail' => 'Verificar contratos'],
        ]],
        ['category' => 'Transferencias Internacionales (Art. 21, 27)', 'items' => [
            ['label' => 'Transferencias registradas con garantías', 'pass' => !$hasTransfers || count(array_filter($transfers, fn($t) => !empty($t['mechanism']))) > 0, 'article' => 'Art. 21', 'severity' => 'gravísima', 'detail' => count($transfers) . ' transferencias registradas'],
        ]],
        ['category' => 'Derechos ARCO (Art. 8-13)', 'items' => [
            ['label' => 'Canal operativo para derechos ARCO', 'pass' => $hasArco, 'article' => 'Art. 8-13', 'severity' => 'leve', 'detail' => $hasArco ? 'Canal activo' : 'Sin canal configurado'],
            ['label' => 'Solicitudes ARCO respondidas en plazo', 'pass' => count($arcoRequests) === 0 || $resolvedArco > 0, 'article' => 'Art. 11', 'severity' => 'leve', 'detail' => $resolvedArco . '/' . count($arcoRequests) . ' respondidas'],
        ]],
        ['category' => 'Capacitación (Art. 28 c)', 'items' => [
            ['label' => 'Programa de capacitación implementado', 'pass' => $hasTrainings, 'article' => 'Art. 28 c', 'severity' => 'leve', 'detail' => count($trainings) . ' capacitaciones'],
            ['label' => 'Personal capacitado con firma digital', 'pass' => $allTrained, 'article' => 'Art. 28 c', 'severity' => 'leve', 'detail' => $trainedCount . '/' . count($trainings) . ' firmas'],
        ]],
        ['category' => 'Plan de Respuesta a Incidentes (Art. 25)', 'items' => [
            ['label' => 'Plan documentado', 'pass' => $hasIncidentResponse, 'article' => 'Art. 25', 'severity' => 'grave', 'detail' => $hasIncidentResponse ? $incidentResponse['planName'] : 'Sin plan documentado'],
        ]],
    ];

    $totalChecks = 0; $passedChecks = 0;
    foreach ($checks as $cat) foreach ($cat['items'] as $it) { $totalChecks++; if ($it['pass']) $passedChecks++; }
    $failedBySev = ['gravísima' => 0, 'grave' => 0, 'leve' => 0];
    foreach ($checks as $cat) foreach ($cat['items'] as $it) if (!$it['pass']) $failedBySev[$it['severity']]++;
    $passRate = $totalChecks > 0 ? (int)round($passedChecks / $totalChecks * 100) : 0;

    // Etiquetas legibles
    $labelMap = [
        'identificacion' => 'Identificación', 'contacto' => 'Contacto', 'financieros' => 'Financieros',
        'laborales' => 'Laborales', 'salud' => 'Salud', 'biometricos' => 'Biométricos',
        'geneticos' => 'Genéticos', 'ninos' => 'Niños/Adolescentes', 'navegacion' => 'Navegación',
        'ubicacion' => 'Ubicación geográfica', 'comportamiento' => 'Perfilado', 'antecedentes' => 'Antecedentes penales',
        'clientes' => 'Clientes', 'empleados' => 'Empleados', 'proveedores' => 'Proveedores',
        'postulantes' => 'Postulantes', 'pacientes' => 'Pacientes', 'visitantes' => 'Visitantes',
        'ex_empleados' => 'Ex-empleados', 'publico_general' => 'Público general',
        'consentimiento' => 'Consentimiento (Art. 12)', 'ejecucion_contrato' => 'Contrato (Art. 13.1.a)',
        'obligacion_legal' => 'Obligación legal (Art. 13.1.b)', 'interes_vital' => 'Interés vital (Art. 13.1.c)',
        'interes_publico' => 'Interés público (Art. 13.1.d)', 'interes_legitimo' => 'Interés legítimo (Art. 13.1.e)',
        'low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico',
        'continua' => 'Continua (24/7)', 'diaria' => 'Diaria', 'semanal' => 'Semanal',
        'mensual' => 'Mensual', 'ocasional' => 'Ocasional', 'unica' => 'Única',
        'interno_solo' => 'Solo personal interno', 'interno_externo' => 'Interno y proveedores',
        'publico' => 'Acceso público', 'terceros' => 'Terceros autorizados',
        'cifrado_reposo' => 'Cifrado AES-256', 'cifrado_transito' => 'TLS',
        'pseudonimizacion' => 'Seudonimización', 'acceso_controlado' => 'RBAC',
        'mfa' => 'MFA', 'auditoria_accesos' => 'Logs de auditoría', 'backup_cifrado' => 'Backups cifrados',
        'acceso' => 'Acceso', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación',
        'oposicion' => 'Oposición', 'portabilidad' => 'Portabilidad', 'supresion' => 'Supresión', 'bloqueo' => 'Bloqueo',
        'pending' => 'Pendiente', 'in_progress' => 'En proceso', 'completed' => 'Completada',
        'resolved' => 'Resuelta', 'rejected' => 'Rechazada',
        'approved' => 'Aprobada', 'active' => 'Activo', 'si' => 'Sí', 'no' => 'No',
        'adequacy' => 'Decisión de adecuación', 'scc' => 'Cláusulas contractuales tipo',
        'bcr' => 'Normas corporativas vinculantes', 'codes' => 'Códigos de conducta',
        'certification' => 'Certificación', 'consent' => 'Consentimiento explícito',
        'contract' => 'Ejecución de contrato', 'public_interest' => 'Interés público',
        'legal_claim' => 'Reclamaciones legales', 'vital_interest' => 'Interés vital',
        'tokenizacion' => 'Tokenización', 'hashing' => 'Hashing', 'cifrado_reversible' => 'Cifrado reversible',
        'masking' => 'Enmascaramiento', 'format_preserving' => 'Cifrado FPE',
        'differential_privacy' => 'Privacidad diferencial',
        'todos_identificadores' => 'Todos los identificadores', 'solo_rut' => 'Solo RUT',
        'solo_email' => 'Solo emails', 'solo_nombres' => 'Solo nombres',
    ];
    $L = fn($v) => $labelMap[strtolower(bsonToString($v))] ?? ucfirst(bsonToString($v));

    // URL validation
    $privacyOk = url_accessible($config['privacyPolicyUrl'] ?? '');
    $cookiesOk = url_accessible($config['cookiesPolicyUrl'] ?? '');

    // ═══ CSS ═══
    $css = "
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; margin: 0; padding: 0; color: #1a1a1a; font-size: 9px; line-height: 1.5; }
        .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
        .cover { page-break-after: always; padding: 0; }
        .cover-topline { height: 2px; background: #000000; width: 100%; }
        .cover-body { padding: 0 45px; text-align: center; }
        .cover-label { color: #777777; font-size: 9px; margin-top: 118px; }
        .cover-law { color: #777777; font-size: 8px; margin-top: 6px; }
        .cover-sep { border-top: 0.5px solid #000000; margin: 26px 60px 0 60px; }
        .cover-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 28px; text-transform: uppercase; }
        .cover-title { color: #1a1a1a; font-size: 20px; font-weight: bold; margin-top: 14px; }
        .cover-sub { color: #555555; font-size: 10px; margin-top: 24px; }
        .cover-sep2 { border-top: 0.5px solid #000000; margin: 24px 60px 0 60px; }
        .cover-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 28px 60px 0 60px; padding: 8px 10px; text-align: center; }
        .cover-box .lbl { color: #555555; font-size: 8px; }
        .cover-box .val { color: #1a1a1a; font-size: 10px; font-weight: bold; margin-top: 3px; }
        .cover-box2 { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 14px 60px 0 60px; padding: 8px 10px; text-align: center; color: #1a1a1a; font-size: 8px; font-weight: bold; }
        .page { page-break-before: always; }
        .page-band { background: #000000; padding: 9px 45px 10px 45px; }
        .band-sub { color: #ffffff; font-size: 8px; }
        .band-title { color: #ffffff; font-size: 10px; font-weight: bold; margin-top: 2px; }
        .content { padding: 20px 45px 40px 45px; }
        .toc-item { padding: 4px 0; border-bottom: 0.3px dotted #cccccc; }
        .toc-num { color: #555555; font-size: 9px; width: 40px; display: inline-block; }
        .toc-text { color: #1a1a1a; font-size: 10px; }
        .sec-title { border-collapse: collapse; margin-top: 14px; width: 100%; }
        .sec-bar { width: 4px; background: #000000; padding: 0; }
        .sec-num { width: 26px; color: #000000; font-size: 9px; font-weight: bold; padding: 2px 0 2px 10px; vertical-align: top; }
        .sec-text { color: #1a1a1a; font-size: 14px; font-weight: bold; padding: 0 0 0 4px; }
        .sec-rule { border-bottom: 0.5px solid #bbbbbb; margin: 6px 0 10px 0; }
        .fields { border-collapse: collapse; margin-top: 4px; width: 100%; }
        .f-label { color: #555555; font-size: 8px; width: 145px; padding: 4px 0; vertical-align: top; }
        .f-value { color: #1a1a1a; font-size: 9px; padding: 4px 0; }
        .warn { color: #4a4a4a; font-size: 9px; font-weight: bold; margin-top: 6px; }
        .note { color: #1a1a1a; font-size: 8px; margin-top: 4px; line-height: 1.5; }
        .body-text { color: #1a1a1a; font-size: 9px; line-height: 1.6; margin-top: 6px; }
        .kpi-box { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .kpi-box td { padding: 6px 8px; border: 0.3px solid #e0e0e0; vertical-align: top; }
        .kpi-label { color: #555555; font-size: 8px; }
        .kpi-value { color: #1a1a1a; font-size: 14px; font-weight: bold; }
        .cat-header { background: #f0f0f0; border: 0.3px solid #bbbbbb; color: #1a1a1a; font-size: 10px; font-weight: bold; padding: 6px 10px; margin-top: 14px; }
        .chk { margin: 6px 0 0 4px; border-bottom: 0.3px solid #e0e0e0; padding-bottom: 5px; }
        .chk-row { width: 100%; border-collapse: collapse; }
        .chk-mark { width: 16px; font-size: 10px; font-weight: bold; vertical-align: top; }
        .chk-pass { color: #166534; }
        .chk-fail { color: #991b1b; }
        .chk-label { color: #1a1a1a; font-size: 9px; }
        .chk-art { color: #555555; font-size: 7px; text-align: right; width: 80px; vertical-align: top; }
        .chk-detail { color: #555555; font-size: 7.5px; padding-left: 16px; margin-top: 2px; }
        .chk-sev { color: #991b1b; font-size: 7px; font-weight: bold; padding-left: 16px; margin-top: 2px; }
        .data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .data th { background: #1a1a1a; color: #cccccc; font-size: 7.5px; font-weight: bold; text-align: left; padding: 6px 8px; }
        .data td { color: #1a1a1a; font-size: 7.5px; padding: 5px 8px; border-bottom: 0.3px solid #e0e0e0; vertical-align: top; }
        .data tr.alt td { background: #f1f5f9; }
        .art-note { color: #555555; font-size: 8px; line-height: 1.5; margin-top: 4px; margin-bottom: 6px; padding: 6px 8px; background: #fafafa; border-left: 3px solid #bbbbbb; }
        .badge { display: inline-block; padding: 1px 6px; font-size: 7px; font-weight: bold; border: 0.3px solid #000; }
        .badge-success { color: #166534; background: #f0fdf4; }
        .badge-warning { color: #4a4a4a; background: #fefce8; }
        .badge-danger { color: #991b1b; background: #fef2f2; }
        .ficha-header { background: #f5f5f5; padding: 8px 12px; border: 0.5px solid #bbbbbb; margin-bottom: 10px; }
        .ficha-title { color: #1a1a1a; font-size: 11px; font-weight: bold; }
        .ficha-sub { color: #555555; font-size: 8px; margin-top: 2px; }
        .rec { margin-top: 10px; }
        .rec-prio { color: #991b1b; font-size: 8px; font-weight: bold; }
        .rec-prio.media { color: #555555; }
        .rec-text { color: #1a1a1a; font-size: 9px; line-height: 1.5; }
        .rec-art { color: #555555; font-size: 7px; }
        .close-rule { border-top: 0.5px solid #bbbbbb; margin-top: 24px; }
        .close-center { color: #555555; font-size: 8px; text-align: center; margin-top: 12px; }
        .signature-box { width: 45%; border-top: 1px solid #000; padding-top: 6px; font-size: 8px; text-align: center; vertical-align: bottom; height: 80px; }
        .score-box { text-align: center; padding: 12px; margin: 12px 0; border: 1px solid #000; }
        .score-value { font-size: 36px; font-weight: bold; line-height: 1; }
        .score-label { font-size: 10px; color: #555555; margin-top: 4px; }
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>" . h_($reportTitle) . "</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">Ley 21.719 · Informe Oficial de Cumplimiento · ' . h_($companyName) . '</div>';

    // PORTADA
    $html .= '<div class="cover"><div class="cover-topline"></div><div class="cover-body">';
    $html .= '<div class="cover-label">REPÚBLICA DE CHILE</div>';
    $html .= '<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-company">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-title">' . h_($reportTitle) . '</div>';
    $html .= '<div class="cover-sub">Documento oficial de cumplimiento normativo</div>';
    $html .= '<div class="cover-sep2"></div>';
    $html .= '<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . h_($dateStr) . '</div></div>';
    $html .= '<div class="cover-box2">CLASIFICACIÓN: CONFIDENCIAL · DOCUMENTO AUDITABLE</div>';
    $html .= '</div></div>';

    // ÍNDICE
    $html .= '<div class="page"></div>' . pdf_page_band('Índice del Documento') . '<div class="content">';
    $html .= pdf_section_title(1, 'Contenido');
    $toc = [
        ['2', 'Identificación del Responsable del Tratamiento'],
        ['3', 'Resumen Ejecutivo de Cumplimiento'],
        ['4', 'Checklist Detallado por Obligación Legal'],
        ['5', 'Registro de Actividades de Tratamiento (RAT)'],
        ['6', 'Base de Licitud y Consentimiento'],
        ['7', 'Evaluación de Impacto (DPIA)'],
        ['8', 'Encargados del Tratamiento'],
        ['9', 'Transferencias Internacionales'],
        ['10', 'Medidas de Seguridad y Seudonimización'],
        ['11', 'Gestión de Brechas de Seguridad'],
        ['12', 'Plan de Respuesta a Incidentes'],
        ['13', 'Derechos ARCO'],
        ['14', 'Programa de Capacitación'],
        ['15', 'Evidencia Técnica de Monitoreo'],
        ['16', 'Validaciones Externas'],
        ['17', 'Recomendaciones y Plan de Acción'],
        ['18', 'Marco Legal Aplicable'],
        ['19', 'Declaración y Firma'],
    ];
    foreach ($toc as $entry) {
        $html .= '<div class="toc-item"><span class="toc-num">' . h_($entry[0]) . '.</span><span class="toc-text">' . h_($entry[1]) . '</span></div>';
    }
    $html .= '</div>';

    // 2. IDENTIFICACIÓN
    $html .= '<div class="page"></div>' . pdf_page_band('Identificación del Responsable del Tratamiento') . '<div class="content">';
    $html .= pdf_section_title(2, 'Datos de la Organización');
    $html .= '<table class="kpi-box"><tr>';
    $html .= '<td width="50%"><div class="kpi-label">Razón Social</div><div style="font-size:10px;font-weight:bold;margin-top:4px">' . h_($config['companyName'] ?? '—') . '</div></td>';
    $html .= '<td width="50%"><div class="kpi-label">RUT Empresa</div><div style="font-size:10px;font-weight:bold;margin-top:4px">' . h_($config['companyRut'] ?? 'No especificado') . '</div></td>';
    $html .= '</tr><tr>';
    $html .= '<td><div class="kpi-label">Email de contacto</div><div style="font-size:9px;margin-top:4px">' . h_($user['email'] ?? '—') . '</div></td>';
    $html .= '<td><div class="kpi-label">Nivel de Cumplimiento declarado</div><div style="font-size:10px;font-weight:bold;margin-top:4px">' . h_(ucfirst($config['complianceLevel'] ?? 'No evaluado')) . '</div></td>';
    $html .= '</tr></table>';

    $html .= pdf_section_title(3, 'Delegado de Protección de Datos (DPD)');
    if ($hasDpd) {
        $html .= pdf_fields([
            ['Nombre completo', $config['dpdName']],
            ['Email', $config['dpdEmail']],
            ['Teléfono', $config['dpdPhone'] ?? '—'],
            ['RUT', $config['dpdRut'] ?? '—'],
            ['Cargo', $config['dpdTitle'] ?? 'Delegado de Protección de Datos'],
        ]);
        $html .= '<div class="art-note">Designación conforme al Art. 28 de la Ley 21.719. El DPD actúa como punto de contacto con la APDP y los titulares.</div>';
    } else {
        $html .= '<div class="warn">⚠ NO SE HA DESIGNADO UN DELEGADO DE PROTECCIÓN DE DATOS</div>';
        $html .= '<div class="note">Art. 28 Ley 21.719: La designación del DPD es obligatoria para responsables que realizan tratamiento a gran escala de datos sensibles.</div>';
    }

    $html .= pdf_section_title(4, 'Registro ante la APDP');
    if ($hasApdp) {
        $html .= pdf_fields([
            ['Estado', 'Registrado'],
            ['Número de registro', $config['apdpRegistrationNumber']],
            ['Fecha de registro', $config['apdpRegistrationDate'] ?? '—'],
        ]);
    } else {
        $html .= '<div class="warn">⚠ NO SE HA REGISTRADO ANTE LA APDP</div>';
        $html .= '<div class="note">Art. 31 Ley 21.719: Todo responsable debe inscribirse en el Registro Nacional.</div>';
    }
    $html .= '</div>';

    // 3. RESUMEN EJECUTIVO
    $html .= '<div class="page"></div>' . pdf_page_band('Resumen Ejecutivo de Cumplimiento') . '<div class="content">';
    $html .= pdf_section_title(5, 'Score Global de Cumplimiento');
    $scoreColor = $passRate >= 90 ? '#166534' : ($passRate >= 70 ? '#4a4a4a' : ($passRate >= 50 ? '#92400e' : '#991b1b'));
    $html .= '<div class="score-box" style="border-color:' . $scoreColor . '">';
    $html .= '<div class="score-value" style="color:' . $scoreColor . '">' . $passRate . '%</div>';
    $html .= '<div class="score-label">' . $passedChecks . ' de ' . $totalChecks . ' requisitos cumplidos</div>';
    $html .= '</div>';

    $html .= pdf_section_title(6, 'Indicadores Clave por Categoría');
    $html .= '<table class="kpi-box"><tr>';
    $html .= '<td width="33.33%"><div class="kpi-label">Infracciones Gravísimas</div><div class="kpi-value" style="color:#991b1b">' . $failedBySev['gravísima'] . '</div><div class="kpi-label">Hasta 20.000 UTM</div></td>';
    $html .= '<td width="33.33%"><div class="kpi-label">Infracciones Graves</div><div class="kpi-value" style="color:#92400e">' . $failedBySev['grave'] . '</div><div class="kpi-label">Hasta 10.000 UTM</div></td>';
    $html .= '<td width="33.33%"><div class="kpi-label">Infracciones Leves</div><div class="kpi-value" style="color:#4a4a4a">' . $failedBySev['leve'] . '</div><div class="kpi-label">Hasta 5.000 UTM</div></td>';
    $html .= '</tr></table>';

    $html .= pdf_section_title(7, 'Datos Gestionados en la Plataforma');
    $html .= '<table class="data"><thead><tr><th>Recurso</th><th>Cantidad</th><th>Observación</th></tr></thead><tbody>';
    $kpisData = [
        ['Actividades de Tratamiento (RAT)', count($inventory), count($sensitiveItems) . ' con datos sensibles'],
        ['Consentimientos registrados', count($consents), $activeConsents . ' activos'],
        ['Evaluaciones de Impacto (DPIA)', count($dpias), $approvedDpias . ' aprobadas'],
        ['Encargados del Tratamiento', count($processors), 'Registrados con contratos'],
        ['Transferencias Internacionales', count($transfers), 'Con mecanismo de garantía'],
        ['Brechas de Seguridad', count($breaches), $openBreaches . ' abiertas'],
        ['Solicitudes ARCO', count($arcoRequests), $resolvedArco . ' resueltas'],
        ['Capacitaciones', count($trainings), $trainedCount . ' con firma'],
        ['Reglas de Seudonimización', count($pseudoRules), $executedPseudo . ' ejecutadas'],
        ['Agentes de monitoreo', count($agents), $onlineAgents . ' en línea'],
        ['Bases de datos monitoreadas', count($databases), 'Activas'],
        ['Firmas electrónicas', count($invites), $signedInvitesCount . ' firmadas'],
    ];
    $i = 0;
    foreach ($kpisData as $row) {
        $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($row[0]) . '</td><td style="font-weight:bold">' . $row[1] . '</td><td>' . h_($row[2]) . '</td></tr>';
        $i++;
    }
    $html .= '</tbody></table>';

    if ($passRate >= 90) {
        $verdict = 'Nivel de cumplimiento EXCELENTE. La organización cumple con la gran mayoría de los requisitos de la Ley 21.719 y se encuentra apta para certificación.';
    } elseif ($passRate >= 70) {
        $verdict = "Nivel de cumplimiento ACEPTABLE con " . $passedChecks . "/" . $totalChecks . " requisitos cumplidos. Existen " . ($totalChecks - $passedChecks) . " requisitos pendientes.";
    } elseif ($passRate >= 50) {
        $verdict = "Nivel de cumplimiento DEFICIENTE. Solo " . $passedChecks . " de " . $totalChecks . " requisitos cumplidos. Se requiere plan de acción inmediato.";
    } else {
        $verdict = "Nivel de cumplimiento CRÍTICO. Solo " . $passedChecks . " de " . $totalChecks . " requisitos cumplidos. Alto riesgo de sanciones.";
    }
    $html .= '<div class="body-text" style="margin-top:14px;padding:10px;background:#f5f5f5;border-left:3px solid ' . $scoreColor . '">' . h_($verdict) . '</div>';
    $html .= '</div>';

    // 4. CHECKLIST
    $html .= '<div class="page"></div>' . pdf_page_band('Checklist Detallado por Obligación Legal') . '<div class="content">';
    $html .= pdf_section_title(8, 'Evaluación Artículo por Artículo');
    $sevLabels = ['gravísima' => 'GRAVÍSIMA', 'grave' => 'GRAVE', 'leve' => 'LEVE'];
    $sevMax = ['gravísima' => 'hasta 20.000 UTM', 'grave' => 'hasta 10.000 UTM', 'leve' => 'hasta 5.000 UTM'];
    foreach ($checks as $cat) {
        $html .= '<div class="cat-header">' . h_($cat['category']) . '</div>';
        foreach ($cat['items'] as $it) {
            $mark = $it['pass'] ? '✓' : '✗';
            $markClass = $it['pass'] ? 'chk-pass' : 'chk-fail';
            $html .= '<div class="chk"><table class="chk-row"><tr>';
            $html .= '<td class="chk-mark ' . $markClass . '">' . $mark . '</td>';
            $html .= '<td class="chk-label">' . h_($it['label']) . '</td>';
            $html .= '<td class="chk-art">' . h_($it['article']) . '</td>';
            $html .= '</tr></table>';
            $html .= '<div class="chk-detail">' . h_($it['detail']) . '</div>';
            if (!$it['pass']) {
                $html .= '<div class="chk-sev">Infracción ' . $sevLabels[$it['severity']] . ' — Multa ' . $sevMax[$it['severity']] . '</div>';
            }
            $html .= '</div>';
        }
    }
    $html .= '</div>';

    // 5. RAT
    if ($hasInventory) {
        $html .= '<div class="page"></div>' . pdf_page_band('Registro de Actividades de Tratamiento (RAT)') . '<div class="content">';
        $html .= pdf_section_title(9, 'Inventario de Datos Personales (' . count($inventory) . ' actividades)');
        $html .= '<div class="art-note">Art. 14 Ley 21.719: El responsable debe mantener un registro documentado de las actividades de tratamiento.</div>';

        $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">Resumen consolidado</h3>';
        $html .= '<table class="data"><thead><tr><th>#</th><th>Nombre</th><th>Finalidad</th><th>Base legal</th><th>Riesgo</th><th>Sens.</th></tr></thead><tbody>';
        foreach ($inventory as $idx => $it) {
            $purposeText = bsonToString($it['purpose'] ?? '—');
            $html .= '<tr' . ($idx % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . ($idx + 1) . '</td>';
            $html .= '<td>' . h_($it['name'] ?? '—') . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($purposeText, 0, 50, '…')) . '</td>';
            $html .= '<td>' . h_($L($it['legalBasis'] ?? '—')) . '</td>';
            $html .= '<td>' . h_($L($it['risk'] ?? 'low')) . '</td>';
            $html .= '<td>' . (!empty($it['sensitive']) ? 'Sí' : 'No') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Fichas individuales (limitadas)
        $html .= '<div style="page-break-before:always"></div>';
        $html .= pdf_section_title(10, 'Fichas Individuales Detalladas');
        $maxFichas = 15;
        $fichasMostradas = 0;
        foreach ($inventory as $idx => $it) {
            if ($fichasMostradas >= $maxFichas) {
                $html .= '<p style="font-size:9px;color:#555;margin-top:14px;font-style:italic">Se muestran las primeras ' . $maxFichas . ' fichas. Las ' . (count($inventory) - $maxFichas) . ' restantes se detallan en el listado consolidado.</p>';
                break;
            }
            $fichasMostradas++;
            if ($idx > 0) $html .= '<div style="page-break-before:always"></div>';

            $dataCats = toStrArr($it['dataCategories'] ?? null);
            $subCats  = toStrArr($it['subjectCategories'] ?? null);
            $techM    = toStrArr($it['technicalMeasures'] ?? null);
            $riskKey  = strtolower(bsonToString($it['risk'] ?? 'low'));

            $html .= '<div class="ficha-header"><div class="ficha-title">Ficha #' . ($idx + 1) . ': ' . h_($it['name'] ?? 'Sin nombre') . '</div>';
            $html .= '<div class="ficha-sub">Identificador: ' . h_((string)($it['_id'] ?? '—')) . ' · Registrado: ' . h_(substr(bsonToString($it['createdAt'] ?? ''), 0, 10)) . '</div></div>';

            // 1. Identificación
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">1. Identificación (Art. 14.1.a)</h3>';
            $html .= pdf_fields([
                ['Nombre de la actividad', $it['name'] ?? '—'],
                ['Código interno', $it['code'] ?? '—'],
                ['Responsable del tratamiento', $it['controllerName'] ?? $companyName],
                ['Encargado del tratamiento', $it['processorName'] ?? 'No aplica'],
            ]);

            // 2. Finalidad
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">2. Finalidad y Base Legal (Art. 14.1.b)</h3>';
            $html .= pdf_fields([
                ['Finalidad', $it['purpose'] ?? '—'],
                ['Base de licitud', $L($it['legalBasis'] ?? '—')],
                ['Justificación interés legítimo', $it['legitimateInterest'] ?? 'No aplica'],
            ]);

            // 3. Categorías
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">3. Categorías de Datos (Art. 14.1.c)</h3>';
            $html .= '<table class="fields"><tr><td class="f-label">Categorías tratadas:</td><td class="f-value">';
            $html .= empty($dataCats) ? '—' : h_(implode(', ', array_map($L, $dataCats)));
            $html .= '</td></tr>';
            $html .= '<tr><td class="f-label">Datos sensibles:</td><td class="f-value">' . (!empty($it['sensitive']) ? '<span class="badge badge-danger">SÍ — Art. 16</span>' : 'No') . '</td></tr>';
            $html .= '<tr><td class="f-label">Datos de menores:</td><td class="f-value">' . (!empty($it['childrenData']) ? '<span class="badge badge-danger">SÍ — Art. 17</span>' : 'No') . '</td></tr>';
            $html .= '</table>';

            // 4. Titulares
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">4. Categorías de Titulares</h3>';
            $html .= '<table class="fields"><tr><td class="f-label">Titulares:</td><td class="f-value">';
            $html .= empty($subCats) ? '—' : h_(implode(', ', array_map($L, $subCats)));
            $html .= '</td></tr></table>';

            // 5. Frecuencia
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">5. Frecuencia y Acceso</h3>';
            $html .= pdf_fields([
                ['Frecuencia', $L($it['treatmentFrequency'] ?? '—')],
                ['Control de acceso', $L($it['accessControl'] ?? '—')],
                ['Almacenamiento', $it['storage'] ?? '—'],
            ]);

            // 6. Medidas
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">6. Medidas de Seguridad (Art. 25)</h3>';
            $html .= '<table class="fields"><tr><td class="f-label">Medidas implementadas:</td><td class="f-value">';
            $html .= empty($techM) ? '—' : h_(implode(', ', array_map($L, $techM)));
            $html .= '</td></tr></table>';

            // 7. Retención y riesgo
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">7. Retención y Riesgo</h3>';
            $html .= pdf_fields([
                ['Plazo de retención', !empty($it['retentionDays']) ? ((int)$it['retentionDays'] . ' días') : 'No especificado'],
                ['Nivel de riesgo', $L($it['risk'] ?? 'low')],
            ]);

            // 8. Observaciones
            $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:10px">8. Observaciones y Evidencia</h3>';
            $html .= pdf_fields([
                ['Observaciones', $it['notes'] ?? '—'],
                ['URL de evidencia', $it['evidenceUrl'] ?? '—'],
            ]);
        }
        $html .= '</div>';
    }

    // 6. CONSENTIMIENTOS
    if ($hasConsents) {
        $html .= '<div class="page"></div>' . pdf_page_band('Base de Licitud y Consentimiento') . '<div class="content">';
        $html .= pdf_section_title(11, 'Consentimientos Registrados (' . count($consents) . ')');
        $html .= '<div class="art-note">Art. 12 Ley 21.719: El consentimiento debe ser libre, informado, específico, previo e inequívoco.</div>';
        $html .= '<table class="data"><thead><tr><th>Titular</th><th>RUT</th><th>Finalidad</th><th>Base legal</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($consents, 0, 30) as $c) {
            $isRevoked = !empty($c['revokedAt']);
            $purposeText = bsonToString($c['purpose'] ?? $c['treatmentPurpose'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($c['name'] ?? '—') . '</td>';
            $html .= '<td>' . h_($c['rut'] ?? '—') . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($purposeText, 0, 40, '…')) . '</td>';
            $html .= '<td>' . h_($L($c['legalBasis'] ?? '—')) . '</td>';
            $html .= '<td>' . h_(substr(bsonToString($c['createdAt'] ?? ''), 0, 10)) . '</td>';
            $html .= '<td>' . ($isRevoked ? '<span class="badge badge-danger">Revocado</span>' : '<span class="badge badge-success">Activo</span>') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    // 7. DPIA
    if ($hasDpias) {
        $html .= '<div class="page"></div>' . pdf_page_band('Evaluación de Impacto (DPIA)') . '<div class="content">';
        $html .= pdf_section_title(12, 'DPIA Registradas (' . count($dpias) . ')');
        $html .= '<div class="art-note">Art. 14 quater Ley 21.719: La Evaluación de Impacto es obligatoria para tratamientos de alto riesgo.</div>';
        foreach ($dpias as $idx => $d) {
            if ($idx > 0) $html .= '<div style="margin-top:16px;border-top:0.5px solid #bbbbbb;padding-top:12px"></div>';
            $status = strtolower(bsonToString($d['status'] ?? 'pending'));
            $stLabel = ['approved' => 'APROBADA', 'rejected' => 'RECHAZADA', 'pending' => 'PENDIENTE'][$status] ?? 'PENDIENTE';
            $stClass = ['approved' => 'badge-success', 'rejected' => 'badge-danger', 'pending' => 'badge-warning'][$status] ?? 'badge-warning';
            $html .= '<div class="ficha-header"><div class="ficha-title">DPIA #' . ($idx + 1) . ': ' . h_($d['name'] ?? 'Sin nombre') . '</div>';
            $html .= '<div class="ficha-sub">Estado: <span class="badge ' . $stClass . '">' . $stLabel . '</span> · Riesgo: ' . h_($L($d['riskLevel'] ?? 'medium')) . '</div></div>';
            if (!empty($d['purpose'])) $html .= '<p><strong>Finalidad:</strong> ' . h_($d['purpose']) . '</p>';
            if (!empty($d['description'])) {
                $descText = bsonToString($d['description']);
                $html .= '<p><strong>Descripción:</strong> ' . h_(mb_strimwidth($descText, 0, 300, '…')) . '</p>';
            }
            if (!empty($d['approvedByName'])) $html .= '<p><strong>Aprobada por:</strong> ' . h_($d['approvedByName']) . ' (' . h_($d['approvedByRole'] ?? 'dpo') . ')</p>';
            if (!empty($d['approvedAt'])) $html .= '<p><strong>Fecha aprobación:</strong> ' . h_(substr(bsonToString($d['approvedAt']), 0, 10)) . '</p>';
        }
        $html .= '</div>';
    }

    // 8. ENCARGADOS
    if ($hasProcessors) {
        $html .= '<div class="page"></div>' . pdf_page_band('Encargados del Tratamiento') . '<div class="content">';
        $html .= pdf_section_title(13, 'Encargados Registrados (' . count($processors) . ')');
        $html .= '<div class="art-note">Art. 15 bis Ley 21.719: Todo responsable debe suscribir contratos con sus encargados.</div>';
        $html .= '<table class="data"><thead><tr><th>Nombre</th><th>Servicio</th><th>País</th><th>Contrato DPA</th><th>Transferencia intl.</th></tr></thead><tbody>';
        $i = 0;
        foreach ($processors as $p) {
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($p['name'] ?? '—') . '</td>';
            $html .= '<td>' . h_($p['serviceType'] ?? '—') . '</td>';
            $html .= '<td>' . h_($p['country'] ?? '—') . '</td>';
            $html .= '<td>' . (($p['hasContract'] ?? '') === 'si' ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-danger">No</span>') . '</td>';
            $html .= '<td>' . (($p['internationalTransfer'] ?? 'no') !== 'no' ? 'Sí: ' . h_($L($p['internationalTransfer'] ?? '')) : 'No') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    // 9. TRANSFERENCIAS
    if ($hasTransfers) {
        $html .= '<div class="page"></div>' . pdf_page_band('Transferencias Internacionales') . '<div class="content">';
        $html .= pdf_section_title(14, 'Transferencias Registradas (' . count($transfers) . ')');
        $html .= '<div class="art-note">Art. 21 Ley 21.719: Las transferencias internacionales solo se permiten con garantías adecuadas o excepciones válidas.</div>';
        $html .= '<table class="data"><thead><tr><th>País destino</th><th>Destinatario</th><th>Mecanismo</th><th>Datos sensibles</th><th>Menores</th></tr></thead><tbody>';
        $i = 0;
        foreach ($transfers as $t) {
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($t['destinationCountry'] ?? '—') . '</td>';
            $html .= '<td>' . h_($t['recipient'] ?? '—') . '</td>';
            $html .= '<td>' . h_($L($t['mechanism'] ?? '—')) . '</td>';
            $html .= '<td>' . (($t['sensitiveData'] ?? 'no') === 'si' ? '<span class="badge badge-danger">Sí</span>' : 'No') . '</td>';
            $html .= '<td>' . (($t['childrenData'] ?? 'no') === 'si' ? '<span class="badge badge-danger">Sí</span>' : 'No') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    // 10. MEDIDAS DE SEGURIDAD
    $html .= '<div class="page"></div>' . pdf_page_band('Medidas de Seguridad y Seudonimización') . '<div class="content">';
    $html .= pdf_section_title(15, 'Medidas Técnicas y Organizativas (Art. 25)');
    $html .= '<table class="kpi-box"><tr>';
    $html .= '<td width="33.33%"><div class="kpi-label">Agentes en línea</div><div class="kpi-value">' . $onlineAgents . '</div><div class="kpi-label">de ' . count($agents) . ' desplegados</div></td>';
    $html .= '<td width="33.33%"><div class="kpi-label">Bases de datos monitoreadas</div><div class="kpi-value">' . count($databases) . '</div><div class="kpi-label">Activas</div></td>';
    $html .= '<td width="33.33%"><div class="kpi-label">Reglas de seudonimización</div><div class="kpi-value">' . $executedPseudo . '</div><div class="kpi-label">de ' . count($pseudoRules) . ' ejecutadas</div></td>';
    $html .= '</tr></table>';

    if ($hasPseudo) {
        $html .= pdf_section_title(16, 'Reglas de Seudonimización (Art. 30)');
        $html .= '<table class="data"><thead><tr><th>Nombre</th><th>Técnica</th><th>Alcance</th><th>Estado</th></tr></thead><tbody>';
        $i = 0;
        foreach ($pseudoRules as $r) {
            $scopeText = bsonToString($r['scope'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($r['name'] ?? '—') . '</td>';
            $html .= '<td>' . h_($L($r['technique'] ?? '—')) . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($scopeText, 0, 40, '…')) . '</td>';
            $html .= '<td>' . ((($r['status'] ?? '') === 'executed') ? '<span class="badge badge-success">Ejecutada</span>' : '<span class="badge badge-warning">Pendiente</span>') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    // 11. BRECHAS
    $html .= '<div class="page"></div>' . pdf_page_band('Gestión de Brechas de Seguridad') . '<div class="content">';
    $html .= pdf_section_title(17, 'Registro de Brechas (Art. 26)');
    if ($hasBreachProtocol) {
        $html .= '<div class="art-note">Protocolo documentado: <strong>' . h_($breachProtocol['protocolName'] ?? '—') . '</strong> (v' . h_($breachProtocol['protocolVersion'] ?? '—') . ')';
        if (!empty($breachProtocol['protocolOwner'])) $html .= ' · Responsable: ' . h_($breachProtocol['protocolOwner']);
        $html .= '</div>';
    } else {
        $html .= '<div class="warn">⚠ No se ha documentado un protocolo de gestión de brechas</div>';
    }

    if ($hasBreaches) {
        $html .= '<table class="data" style="margin-top:10px"><thead><tr><th>Título</th><th>Fecha</th><th>Severidad</th><th>Estado</th><th>Notificada APDP</th></tr></thead><tbody>';
        $i = 0;
        foreach ($breaches as $b) {
            $titleText = bsonToString($b['title'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_(mb_strimwidth($titleText, 0, 50, '…')) . '</td>';
            $html .= '<td>' . h_(substr(bsonToString($b['createdAt'] ?? ''), 0, 10)) . '</td>';
            $html .= '<td>' . h_($L($b['severity'] ?? '—')) . '</td>';
            $html .= '<td>' . h_($L($b['status'] ?? '—')) . '</td>';
            $html .= '<td>' . (!empty($b['notifiedAPDP']) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-danger">No</span>') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p style="margin-top:10px">Sin brechas registradas. Registro limpio.</p>';
    }
    $html .= '</div>';

    // 12. PLAN DE RESPUESTA
    $html .= '<div class="page"></div>' . pdf_page_band('Plan de Respuesta a Incidentes') . '<div class="content">';
    $html .= pdf_section_title(18, 'Plan Documentado (Art. 25)');
    if ($hasIncidentResponse) {
        $html .= '<div class="art-note">Plan: <strong>' . h_($incidentResponse['planName'] ?? '—') . '</strong> (v' . h_($incidentResponse['planVersion'] ?? '—') . ')';
        $html .= ' · Responsable: ' . h_($incidentResponse['planOwner'] ?? '—') . '</div>';
        $scopeText = bsonToString($incidentResponse['scope'] ?? '—');
        $rolesText = bsonToString($incidentResponse['csirtRoles'] ?? '—');
        $channelsText = bsonToString($incidentResponse['detectionChannels'] ?? '—');
        $html .= pdf_fields([
            ['Fecha de aprobación', $incidentResponse['approvalDate'] ?? '—'],
            ['Próxima revisión', $incidentResponse['nextReviewDate'] ?? '—'],
            ['Alcance', mb_strimwidth($scopeText, 0, 200, '…')],
            ['Roles CSIRT', mb_strimwidth($rolesText, 0, 200, '…')],
            ['Canales de detección', mb_strimwidth($channelsText, 0, 200, '…')],
        ]);
    } else {
        $html .= '<div class="warn">⚠ No se ha documentado un plan de respuesta a incidentes</div>';
        $html .= '<div class="note">Art. 25 Ley 21.719: El responsable debe implementar un plan de respuesta que permita detectar, contener y mitigar incidentes.</div>';
    }
    $html .= '</div>';

    // 13. ARCO
    $html .= '<div class="page"></div>' . pdf_page_band('Derechos ARCO') . '<div class="content">';
    $html .= pdf_section_title(19, 'Canal y Solicitudes (Art. 8-13)');
    $html .= '<table class="kpi-box"><tr>';
    $html .= '<td width="50%"><div class="kpi-label">Total solicitudes</div><div class="kpi-value">' . count($arcoRequests) . '</div></td>';
    $html .= '<td width="50%"><div class="kpi-label">Resueltas</div><div class="kpi-value" style="color:#166534">' . $resolvedArco . '</div></td>';
    $html .= '</tr></table>';

    if (count($arcoRequests) > 0) {
        $html .= '<table class="data" style="margin-top:10px"><thead><tr><th>Titular</th><th>Tipo</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($arcoRequests, 0, 30) as $r) {
            $sol = bsonToArray($r['solicitante'] ?? null);
            if (!is_array($sol)) $sol = [];
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($sol['nombre'] ?? '—') . '</td>';
            $html .= '<td>' . h_($L($r['tipo'] ?? $r['type'] ?? '—')) . '</td>';
            $html .= '<td>' . h_($L($r['status'] ?? '—')) . '</td>';
            $html .= '<td>' . h_(substr(bsonToString($r['createdAt'] ?? ''), 0, 10)) . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p style="margin-top:10px">Sin solicitudes ARCO registradas.</p>';
    }
    $html .= '</div>';

    // 14. CAPACITACIÓN
    if ($hasTrainings) {
        $html .= '<div class="page"></div>' . pdf_page_band('Programa de Capacitación') . '<div class="content">';
        $html .= pdf_section_title(20, 'Capacitaciones (' . count($trainings) . ')');
        $html .= '<div class="art-note">Art. 28 c) Ley 21.719: El responsable debe implementar programas de capacitación periódica.</div>';
        $html .= '<table class="data"><thead><tr><th>Colaborador</th><th>Título</th><th>Estado</th><th>Firma</th><th>Fecha</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($trainings, 0, 30) as $t) {
            $titleText = bsonToString($t['title'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_($t['employeeName'] ?? $t['attendee'] ?? '—') . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($titleText, 0, 50, '…')) . '</td>';
            $html .= '<td>' . (!empty($t['completed']) ? 'Completada' : 'Pendiente') . '</td>';
            $html .= '<td>' . ((!empty($t['signatureData']) || !empty($t['signerName'])) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-warning">No</span>') . '</td>';
            $html .= '<td>' . h_(substr(bsonToString($t['date'] ?? $t['createdAt'] ?? ''), 0, 10)) . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
        $html .= '</div>';
    }

    // 15. EVIDENCIA TÉCNICA
    $html .= '<div class="page"></div>' . pdf_page_band('Evidencia Técnica de Monitoreo') . '<div class="content">';
    $html .= pdf_section_title(21, 'Registros de Seguridad Continuos');
    $html .= '<table class="kpi-box"><tr>';
    $html .= '<td width="25%"><div class="kpi-label">Eventos de host</div><div class="kpi-value">' . count($hostEvents) . '</div></td>';
    $html .= '<td width="25%"><div class="kpi-label">Eventos de archivo</div><div class="kpi-value">' . count($fileEvents) . '</div></td>';
    $html .= '<td width="25%"><div class="kpi-label">Logs de BD</div><div class="kpi-value">' . count($dbLogs) . '</div></td>';
    $html .= '<td width="25%"><div class="kpi-label">Auditoría archivos</div><div class="kpi-value">' . count($fileAudits) . '</div></td>';
    $html .= '</tr></table>';

    if (count($hostEvents) > 0) {
        $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:12px">Últimos eventos de host</h3>';
        $html .= '<table class="data"><thead><tr><th>Fecha</th><th>Evento</th><th>Severidad</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($hostEvents, 0, 10) as $e) {
            $eventText = bsonToString($e['title'] ?? $e['event'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_(substr(bsonToString($e['timestamp'] ?? $e['createdAt'] ?? ''), 0, 16)) . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($eventText, 0, 60, '…')) . '</td>';
            $html .= '<td>' . h_($e['severity'] ?? '—') . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    }

    if (count($dbLogs) > 0) {
        $html .= '<h3 style="font-size:10px;font-weight:bold;margin-top:12px">Últimos logs de base de datos</h3>';
        $html .= '<table class="data"><thead><tr><th>Fecha</th><th>Base de datos</th><th>Operación</th></tr></thead><tbody>';
        $i = 0;
        foreach (array_slice($dbLogs, 0, 10) as $l) {
            $opText = bsonToString($l['operation'] ?? $l['query'] ?? '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
            $html .= '<td>' . h_(substr(bsonToString($l['timestamp'] ?? $l['createdAt'] ?? ''), 0, 16)) . '</td>';
            $html .= '<td>' . h_($l['database'] ?? '—') . '</td>';
            $html .= '<td>' . h_(mb_strimwidth($opText, 0, 60, '…')) . '</td>';
            $html .= '</tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    // 16. VALIDACIONES EXTERNAS
    $html .= '<div class="page"></div>' . pdf_page_band('Validaciones Externas') . '<div class="content">';
    $html .= pdf_section_title(22, 'Verificación de Recursos Públicos');
    $html .= '<div class="art-note">Esta sección verifica la accesibilidad de los recursos públicos declarados.</div>';
    $html .= '<table class="data"><thead><tr><th>Recurso</th><th>Estado</th><th>HTTP</th><th>Observación</th></tr></thead><tbody>';
    $html .= '<tr><td>Política de privacidad</td><td>' . h_($privacyOk['status']) . '</td><td>' . h_($privacyOk['http']) . '</td><td>' . ($hasPrivacyPolicy ? 'URL configurada' : 'No configurada') . '</td></tr>';
    $html .= '<tr class="alt"><td>Política de cookies</td><td>' . h_($cookiesOk['status']) . '</td><td>' . h_($cookiesOk['http']) . '</td><td>' . ($hasCookiesPolicy ? 'URL configurada' : 'No configurada') . '</td></tr>';
    $html .= '<tr><td>Registro APDP</td><td>' . ($hasApdp ? 'Declarado' : 'Sin registro declarado') . '</td><td>—</td><td>Requiere verificación manual</td></tr>';
    $html .= '</tbody></table>';
    $html .= '</div>';

    // 17. RECOMENDACIONES
    $recs = [];
    if (!$hasDpd)             $recs[] = ['ALTA', 'Designar un Delegado de Protección de Datos (DPD) según Art. 28.', 'Art. 28'];
    if (!$hasApdp)            $recs[] = ['ALTA', 'Inscribirse en el Registro Nacional de la APDP.', 'Art. 31'];
    if (!$hasPrivacyPolicy)   $recs[] = ['ALTA', 'Publicar política de privacidad accesible.', 'Art. 14 ter'];
    if (!$hasConsents)        $recs[] = ['ALTA', 'Implementar sistema de gestión de consentimientos.', 'Art. 12'];
    if (!$hasInventory)       $recs[] = ['ALTA', 'Crear el Registro de Actividades de Tratamiento (RAT).', 'Art. 14'];
    if (!$hasBreachProtocol)  $recs[] = ['ALTA', 'Documentar protocolo de gestión de brechas.', 'Art. 26'];
    if (!$hasIncidentResponse)$recs[] = ['ALTA', 'Documentar plan de respuesta a incidentes.', 'Art. 25'];
    if (!$hasPseudo)          $recs[] = ['MEDIA', 'Implementar medidas de seudonimización o cifrado.', 'Art. 30'];
    if (!$hasDpias && count($highRiskItems) > 0) $recs[] = ['ALTA', 'Realizar DPIA para tratamientos de alto riesgo.', 'Art. 14 quater'];
    if (!$hasProcessors && count($dbLogs) > 0)   $recs[] = ['ALTA', 'Registrar encargados y firmar contratos DPA.', 'Art. 15 bis'];
    if (!$hasTrainings)       $recs[] = ['MEDIA', 'Implementar programa de capacitación periódica.', 'Art. 28 c'];
    if (!$hasCookiesPolicy)   $recs[] = ['MEDIA', 'Publicar política de cookies.', 'Art. 14 ter'];
    if (!$hasRetentionPolicy) $recs[] = ['MEDIA', 'Definir política de retención de datos.', 'Art. 14'];
    if (!$hasArco)            $recs[] = ['MEDIA', 'Habilitar canal operativo para derechos ARCO.', 'Art. 8-13'];
    if (empty($recs))         $recs[] = ['BAJA', 'Mantener controles actuales y auditar periódicamente.', 'Buenas prácticas'];

    $html .= '<div class="page"></div>' . pdf_page_band('Recomendaciones y Plan de Acción') . '<div class="content">';
    $html .= pdf_section_title(23, 'Acciones Correctivas Priorizadas');
    foreach ($recs as $rec) {
        $prio = $rec[0] ?? '';
        $text = $rec[1] ?? '';
        $art = $rec[2] ?? '';
        $html .= '<div class="rec"><table class="chk-row"><tr>';
        $html .= '<td style="width:55px;vertical-align:top"><span class="rec-prio' . ($prio === 'MEDIA' ? ' media' : '') . '">[' . h_($prio) . ']</span></td>';
        $html .= '<td><div class="rec-text">' . h_($text) . '</div><div class="rec-art">' . h_($art) . '</div></td>';
        $html .= '</tr></table></div>';
    }
    $html .= '</div>';

    // 18. MARCO LEGAL
    $lawLines = [
        'La Ley 21.719, publicada el 13 de diciembre de 2024, regula la protección y el tratamiento de los datos personales en Chile, creando la Agencia de Protección de Datos Personales (APDP). Vigente desde el 1 de diciembre de 2026.',
        'Principios rectores (Art. 3): Licitud y lealtad, finalidad, proporcionalidad, calidad, responsabilidad, seguridad, transparencia e información, y confidencialidad.',
        'Derechos del titular (Art. 4-13): Acceso, Rectificación, Supresión, Oposición, Portabilidad y Bloqueo temporal. Plazo de respuesta: 10 días hábiles.',
        'Consentimiento (Art. 12): Libre, informado, específico, previo e inequívoco. Otras bases: obligación legal, ejecución de contrato, interés legítimo (Art. 13).',
        'Datos sensibles (Art. 16): Tratamiento con consentimiento explícito reforzado.',
        'Datos de menores (Art. 17): Consentimiento del representante legal y salvaguardas especiales.',
        'Medidas de seguridad (Art. 25): Cifrado, seudonimización, confidencialidad, integridad, disponibilidad.',
        'Brechas (Art. 26): Notificación a APDP sin dilaciones indebidas.',
        'DPD (Art. 28): Obligatorio para tratamiento a gran escala de datos sensibles.',
        'DPIA (Art. 14 quater): Obligatoria para tratamientos de alto riesgo.',
        'Encargados (Art. 15 bis): Contratos que incluyan cláusulas de confidencialidad y seguridad.',
        'Transferencias internacionales (Art. 21, 27): Solo con garantías adecuadas o excepciones válidas.',
        'Sanciones (Arts. 32-36): Leves hasta 5.000 UTM, graves hasta 10.000 UTM, gravísimas hasta 20.000 UTM.',
    ];
    $html .= '<div class="page"></div>' . pdf_page_band('Marco Legal Aplicable') . '<div class="content">';
    $html .= pdf_section_title(24, 'Ley 21.719 - Protección de Datos Personales');
    foreach ($lawLines as $line) {
        $html .= '<div class="body-text">' . h_($line) . '</div>';
    }
    $html .= '</div>';

    // 19. DECLARACIÓN Y FIRMA
    $html .= '<div class="page"></div>' . pdf_page_band('Declaración y Firma') . '<div class="content">';
    $html .= pdf_section_title(25, 'Declaración de Veracidad');
    $html .= '<div class="body-text">El presente informe constituye una declaración formal de cumplimiento de la Ley 21.719 de Protección de Datos Personales de la República de Chile, elaborado a partir de los registros y evidencias mantenidos en la plataforma de cumplimiento.</div>';
    $html .= '<div class="body-text">El responsable del tratamiento declara que la información contenida en este informe refleja el estado de cumplimiento a la fecha de emisión.</div>';

    $html .= pdf_section_title(26, 'Trazabilidad del Documento');
    $html .= pdf_fields([
        ['ID del informe', ($report['_id'] ?? ('all-' . date('Ymd-His')))],
        ['Generado por', ($user['email'] ?? '—')],
        ['Rol del generador', ($user['role'] ?? 'user')],
        ['Fecha y hora', date('c')],
        ['Score final', ($passRate . '%')],
        ['Plataforma', 'SecureLab · Ley 21.719'],
    ]);

    $html .= '<div style="margin-top:60px">';
    $html .= '<table style="width:100%;border-collapse:collapse">';
    $html .= '<tr>';
    $html .= '<td class="signature-box" style="width:45%">';
    $html .= '<strong>' . h_($config['dpdName'] ?? 'Delegado de Protección de Datos') . '</strong><br>';
    $html .= 'Delegado de Protección de Datos (DPD)<br>';
    $html .= h_($config['dpdEmail'] ?? '') . '<br>';
    $html .= '<span style="font-size:7px;color:#777;margin-top:20px;display:block">Firma y timbre</span>';
    $html .= '</td>';
    $html .= '<td style="width:10%"></td>';
    $html .= '<td class="signature-box" style="width:45%">';
    $html .= '<strong>' . h_($config['companyName'] ?? 'Representante Legal') . '</strong><br>';
    $html .= 'Representante Legal<br>';
    $html .= 'RUT: ' . h_($config['companyRut'] ?? '—') . '<br>';
    $html .= '<span style="font-size:7px;color:#777;margin-top:20px;display:block">Firma y timbre</span>';
    $html .= '</td>';
    $html .= '</tr>';
    $html .= '</table>';
    $html .= '</div>';

    $html .= '<div class="close-rule"></div>';
    $html .= '<div class="close-center">Documento generado electrónicamente · Ley 21.719 · Protección de Datos Personales · Chile</div>';
    $html .= '<div class="close-muted" style="color:#777;font-size:7.5px;text-align:center;margin-top:4px">Fecha de emisión: ' . h_($dateStr) . ' · Confidencial · Documento auditable</div>';
    $html .= '</div>';

    $html .= '</body></html>';

    // ═══ RENDER PDF ═══
    try {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $pdfContent = $dompdf->output();
        if (empty($pdfContent)) {
            error_log('[Reports PDF] Dompdf returned empty output');
            json_error('PDF vacío — revisa logs del servidor', 500);
        }

        if (ob_get_level() > 0) { while (ob_get_level() > 0) ob_end_clean(); }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    } catch (\Throwable $e) {
        error_log('[Reports PDF] Fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        error_log('[Reports PDF] Trace: ' . $e->getTraceAsString());
        if (ob_get_level() > 0) { while (ob_get_level() > 0) ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Error generando PDF: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════
// REPORTE DE SEGURIDAD
// ═══════════════════════════════════════════════════════════════════

function downloadSecurityReport($user, $report) {
    $db = Database::getInstance();
    $uid = $user['_id'];
    $config = $db->findOne('compliance_config', ['userId' => $uid]) ?? [];
    $companyName = bsonToString($config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa')));

    $fileEvents = $db->find('file_events', ['userId' => $uid]);
    $dbLogs = $db->find('database_logs', ['userId' => $uid]);
    $hostEvents = $db->find('host_events', ['userId' => $uid]);
    $fileAudits = $db->find('file_audit_logs', ['userId' => $uid]);
    $agents = $db->find('agents', ['userId' => $uid]);

    $highSeverity = fn($ev) => in_array(strtolower(bsonToString($ev['severity'] ?? $ev['level'] ?? '')), ['critical', 'high', 'alta', 'critico']);
    $criticalCount = count(array_filter(array_merge($fileEvents, $dbLogs, $hostEvents, $fileAudits), $highSeverity));
    $totalEvents = count($fileEvents) + count($dbLogs) + count($hostEvents) + count($fileAudits);
    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));

    $recent = array_slice(array_merge($fileEvents, $dbLogs, $hostEvents, $fileAudits), 0, 15);
    usort($recent, fn($a, $b) => strcmp(
        bsonToString($b['timestamp'] ?? $b['createdAt'] ?? ''),
        bsonToString($a['timestamp'] ?? $a['createdAt'] ?? '')
    ));

    $riskScore = $totalEvents > 0 ? max(0, min(100, (int)round($criticalCount / $totalEvents * 100))) : 0;
    $level = $riskScore >= 70 ? 'CRÍTICO' : ($riskScore >= 40 ? 'ALTO' : ($riskScore >= 15 ? 'MEDIO' : 'BAJO'));

    $recommendations = [];
    if ($criticalCount > 0) $recommendations[] = ['ALTA', 'Revisar inmediatamente los eventos críticos detectados.'];
    if (count($fileEvents) > 0) $recommendations[] = ['ALTA', 'Verificar integridad de archivos sensibles.'];
    if (count($dbLogs) > 0) $recommendations[] = ['MEDIA', 'Auditar consultas anómalas a bases de datos.'];
    if (count($hostEvents) > 0) $recommendations[] = ['MEDIA', 'Revisar actividad de procesos y conexiones.'];
    if (count($fileAudits) > 0) $recommendations[] = ['MEDIA', 'Reforzar monitoreo de auditoría de archivos.'];
    if (empty($recommendations)) $recommendations[] = ['BAJA', 'Mantener monitoreo continuo.'];

    $css = "
        @page{margin:0}
        body{font-family:'DejaVu Sans',Arial,sans-serif;margin:0;padding:0;color:#1a1a1a;font-size:9px}
        .footer-fixed{position:fixed;bottom:0;left:0;right:0;height:22px;background:#f5f5f5;border-top:0.5px solid #cccccc;color:#999999;font-size:7px;padding:6px 45px 0 45px}
        .cover{page-break-after:always;padding:0}
        .cover-topline{height:2px;background:#000000;width:100%}
        .cover-body{padding:0 45px;text-align:center}
        .cover-label{color:#777777;font-size:9px;margin-top:118px}
        .cover-law{color:#777777;font-size:8px;margin-top:6px}
        .cover-sep{border-top:0.5px solid #000000;margin:26px 60px 0 60px}
        .cover-company{color:#1a1a1a;font-size:14px;font-weight:bold;margin-top:28px;text-transform:uppercase}
        .cover-title{color:#1a1a1a;font-size:20px;font-weight:bold;margin-top:14px}
        .cover-sub{color:#555555;font-size:10px;margin-top:24px}
        .cover-sep2{border-top:0.5px solid #000000;margin:24px 60px 0 60px}
        .cover-box{background:#f5f5f5;border:0.5px solid #bbbbbb;margin:28px 60px 0 60px;padding:8px 10px;text-align:center}
        .cover-box .lbl{color:#555555;font-size:8px}
        .cover-box .val{color:#1a1a1a;font-size:10px;font-weight:bold;margin-top:3px}
        .page{page-break-before:always}
        .page-band{background:#000000;padding:9px 45px 10px 45px}
        .band-sub{color:#ffffff;font-size:8px}
        .band-title{color:#ffffff;font-size:10px;font-weight:bold;margin-top:2px}
        .content{padding:20px 45px 40px 45px}
        .sec-title{border-collapse:collapse;margin-top:14px;width:100%}
        .sec-bar{width:4px;background:#000000;padding:0}
        .sec-num{width:26px;color:#000000;font-size:9px;font-weight:bold;padding:2px 0 2px 10px;vertical-align:top}
        .sec-text{color:#1a1a1a;font-size:14px;font-weight:bold;padding:0 0 0 4px}
        .sec-rule{border-bottom:0.5px solid #bbbbbb;margin:6px 0 10px 0}
        .fields{border-collapse:collapse;margin-top:4px}
        .f-label{color:#555555;font-size:8px;width:125px;padding:4px 0}
        .f-value{color:#1a1a1a;font-size:9px;font-weight:bold;padding:4px 0}
        .data{width:100%;border-collapse:collapse;margin-top:8px}
        .data th{background:#1a1a1a;color:#777777;font-size:7.5px;font-weight:bold;text-align:left;padding:7px 8px}
        .data td{color:#1a1a1a;font-size:7.5px;padding:5px 8px}
        .data tr.alt td{background:#f1f5f9}
        .kpi{color:#1a1a1a;font-size:9px;line-height:1.9}
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Reporte de Seguridad</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">Ley 21.719 · Reporte de Seguridad</div>';

    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y');

    $html .= '<div class="cover"><div class="cover-topline"></div><div class="cover-body">';
    $html .= '<div class="cover-label">REPÚBLICA DE CHILE</div>';
    $html .= '<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-company">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-title">Reporte de Seguridad</div>';
    $html .= '<div class="cover-sub">Análisis de eventos e integridad de la información</div>';
    $html .= '<div class="cover-sep2"></div>';
    $html .= '<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . h_($dateStr) . '</div></div>';
    $html .= '</div></div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Resumen Ejecutivo de Seguridad</div></div><div class="content">';
    $html .= pdf_section_title(1, 'Indicadores de Riesgo');
    $html .= '<div class="kpi">';
    $html .= 'Nivel de riesgo: <strong>' . h_($level) . ' (' . $riskScore . '%)</strong><br>';
    $html .= 'Eventos totales: ' . $totalEvents . '<br>';
    $html .= 'Eventos críticos/altos: ' . $criticalCount . '<br>';
    $html .= 'Eventos de archivos: ' . count($fileEvents) . '<br>';
    $html .= 'Eventos de bases de datos: ' . count($dbLogs) . '<br>';
    $html .= 'Eventos de host: ' . count($hostEvents) . '<br>';
    $html .= 'Auditorías de archivos: ' . count($fileAudits) . '<br>';
    $html .= 'Agentes en línea: ' . $onlineAgents . ' / ' . count($agents) . '<br>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Eventos Recientes de Seguridad</div></div><div class="content">';
    $html .= pdf_section_title(2, 'Últimos eventos detectados');
    if (empty($recent)) {
        $html .= '<p style="font-size:10px">No se registraron eventos recientes.</p>';
    } else {
        $html .= '<table class="data"><thead><tr><th>Fuente</th><th>Severidad</th><th>Descripción</th><th>Fecha</th></tr></thead><tbody>';
        foreach ($recent as $i => $ev) {
            $source = bsonToString($ev['source'] ?? ($ev['collection'] ?? 'Sistema'));
            $sev = bsonToString($ev['severity'] ?? $ev['level'] ?? 'info');
            $desc = bsonToString($ev['message'] ?? $ev['description'] ?? $ev['action'] ?? json_encode($ev, JSON_UNESCAPED_UNICODE));
            $ts = substr(bsonToString($ev['timestamp'] ?? $ev['createdAt'] ?? ''), 0, 16);
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($source) . '</td><td>' . h_($sev) . '</td><td>' . h_(mb_strimwidth($desc, 0, 120, '…')) . '</td><td>' . h_($ts) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Recomendaciones</div></div><div class="content">';
    $html .= pdf_section_title(3, 'Acciones recomendadas');
    $html .= '<table class="data"><thead><tr><th>Prioridad</th><th>Recomendación</th></tr></thead><tbody>';
    foreach ($recommendations as $i => $rec) {
        $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($rec[0]) . '</td><td>' . h_($rec[1]) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p style="font-size:8px;color:#555;margin-top:20px">Art. 14 quinquies Ley 21.719: el responsable debe implementar medidas técnicas y organizativas adecuadas al riesgo.</p>';
    $html .= '</div>';

    $html .= '</body></html>';

    try {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        if (ob_get_level() > 0) { while (ob_get_level() > 0) ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="reporte_seguridad_' . ($report['_id'] ?? date('Ymd')) . '.pdf"');
        echo $dompdf->output();
        exit;
    } catch (\Throwable $e) {
        error_log('[Reports PDF Security] Fatal: ' . $e->getMessage());
        json_error('Error generando PDF de seguridad: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════════
// REPORTE DE CAPACITACIÓN
// ═══════════════════════════════════════════════════════════════════

function downloadTrainingReport($user, $report) {
    $db = Database::getInstance();
    $uid = $user['_id'];
    $config = $db->findOne('compliance_config', ['userId' => $uid]) ?? [];
    $companyName = bsonToString($config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa')));
    $trainings = $db->find('compliance_trainings', ['userId' => $uid]);

    $topicLabels = ['proteccion_datos' => 'Protección de Datos Personales', 'ciberseguridad' => 'Ciberseguridad', 'brechas' => 'Protocolo de Brechas', 'arco' => 'Derechos ARCO', 'consentimientos' => 'Gestión de Consentimientos', 'general' => 'General'];
    $total = count($trainings);
    $signed = count(array_filter($trainings, fn($t) => !empty($t['signatureData'])));
    $completed = count(array_filter($trainings, fn($t) => !empty($t['completed']) || !empty($t['signatureData'])));
    $pending = $total - $completed;

    $byTopic = [];
    foreach ($trainings as $t) {
        $topic = bsonToString($t['topic'] ?? 'general');
        if (!isset($byTopic[$topic])) $byTopic[$topic] = ['total' => 0, 'signed' => 0];
        $byTopic[$topic]['total']++;
        if (!empty($t['signatureData'])) $byTopic[$topic]['signed']++;
    }

    $css = "
        @page{margin:0}
        body{font-family:'DejaVu Sans',Arial,sans-serif;margin:0;padding:0;color:#1a1a1a;font-size:9px}
        .footer-fixed{position:fixed;bottom:0;left:0;right:0;height:22px;background:#f5f5f5;border-top:0.5px solid #cccccc;color:#999999;font-size:7px;padding:6px 45px 0 45px}
        .cover{page-break-after:always;padding:0}
        .cover-topline{height:2px;background:#000000;width:100%}
        .cover-body{padding:0 45px;text-align:center}
        .cover-label{color:#777777;font-size:9px;margin-top:118px}
        .cover-law{color:#777777;font-size:8px;margin-top:6px}
        .cover-sep{border-top:0.5px solid #000000;margin:26px 60px 0 60px}
        .cover-company{color:#1a1a1a;font-size:14px;font-weight:bold;margin-top:28px;text-transform:uppercase}
        .cover-title{color:#1a1a1a;font-size:20px;font-weight:bold;margin-top:14px}
        .cover-sub{color:#555555;font-size:10px;margin-top:24px}
        .cover-sep2{border-top:0.5px solid #000000;margin:24px 60px 0 60px}
        .cover-box{background:#f5f5f5;border:0.5px solid #bbbbbb;margin:28px 60px 0 60px;padding:8px 10px;text-align:center}
        .cover-box .lbl{color:#555555;font-size:8px}
        .cover-box .val{color:#1a1a1a;font-size:10px;font-weight:bold;margin-top:3px}
        .page{page-break-before:always}
        .page-band{background:#000000;padding:9px 45px 10px 45px}
        .band-sub{color:#ffffff;font-size:8px}
        .band-title{color:#ffffff;font-size:10px;font-weight:bold;margin-top:2px}
        .content{padding:20px 45px 40px 45px}
        .sec-title{border-collapse:collapse;margin-top:14px;width:100%}
        .sec-bar{width:4px;background:#000000;padding:0}
        .sec-num{width:26px;color:#000000;font-size:9px;font-weight:bold;padding:2px 0 2px 10px;vertical-align:top}
        .sec-text{color:#1a1a1a;font-size:14px;font-weight:bold;padding:0 0 0 4px}
        .sec-rule{border-bottom:0.5px solid #bbbbbb;margin:6px 0 10px 0}
        .fields{border-collapse:collapse;margin-top:4px}
        .f-label{color:#555555;font-size:8px;width:125px;padding:4px 0}
        .f-value{color:#1a1a1a;font-size:9px;font-weight:bold;padding:4px 0}
        .data{width:100%;border-collapse:collapse;margin-top:8px}
        .data th{background:#1a1a1a;color:#777777;font-size:7.5px;font-weight:bold;text-align:left;padding:7px 8px}
        .data td{color:#1a1a1a;font-size:7.5px;padding:5px 8px}
        .data tr.alt td{background:#f1f5f9}
        .kpi{color:#1a1a1a;font-size:9px;line-height:1.9}
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Reporte de Capacitación</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">Ley 21.719 · Reporte de Capacitación</div>';

    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y');

    $html .= '<div class="cover"><div class="cover-topline"></div><div class="cover-body">';
    $html .= '<div class="cover-label">REPÚBLICA DE CHILE</div>';
    $html .= '<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-company">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-title">Reporte de Capacitación</div>';
    $html .= '<div class="cover-sub">Programa de formación en protección de datos personales</div>';
    $html .= '<div class="cover-sep2"></div>';
    $html .= '<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . h_($dateStr) . '</div></div>';
    $html .= '</div></div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Resumen Ejecutivo</div></div><div class="content">';
    $html .= pdf_section_title(1, 'Indicadores de Capacitación');
    $html .= '<div class="kpi">';
    $html .= 'Total capacitaciones registradas: ' . $total . '<br>';
    $html .= 'Completadas/firmadas: ' . $completed . '<br>';
    $html .= 'Pendientes: ' . $pending . '<br>';
    $html .= 'Con firma digital: ' . $signed . '<br>';
    $html .= '</div>';
    $html .= '<p style="font-size:9px;line-height:1.5;margin-top:12px">Art. 28 letra c) Ley 21.719: el responsable debe implementar programas de capacitación periódica.</p>';
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Cobertura por Tema</div></div><div class="content">';
    $html .= pdf_section_title(2, 'Capacitaciones por área');
    if (empty($byTopic)) {
        $html .= '<p style="font-size:10px">No se registraron capacitaciones.</p>';
    } else {
        $html .= '<table class="data"><thead><tr><th>Tema</th><th>Total</th><th>Firmados</th><th>%</th></tr></thead><tbody>';
        $i = 0;
        foreach ($byTopic as $topic => $data) {
            $label = $topicLabels[$topic] ?? ucfirst($topic);
            $pct = $data['total'] > 0 ? round($data['signed'] / $data['total'] * 100) . '%' : '0%';
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($label) . '</td><td>' . $data['total'] . '</td><td>' . $data['signed'] . '</td><td>' . $pct . '</td></tr>';
            $i++;
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Registro de Participantes</div></div><div class="content">';
    $html .= pdf_section_title(3, 'Colaboradores capacitados');
    if (empty($trainings)) {
        $html .= '<p style="font-size:10px">No se encontraron registros de colaboradores.</p>';
    } else {
        $html .= '<table class="data"><thead><tr><th>Colaborador</th><th>Tema</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        foreach (array_slice($trainings, 0, 30) as $i => $t) {
            $name = bsonToString($t['employeeName'] ?? '—');
            $topic = $topicLabels[$t['topic'] ?? ''] ?? ucfirst(bsonToString($t['topic'] ?? '—'));
            $status = !empty($t['signatureData']) ? 'Firmado' : (!empty($t['completed']) ? 'Completado' : 'Pendiente');
            $date = bsonToString($t['date'] ?? '');
            if ($date === '') $date = substr(bsonToString($t['createdAt'] ?? ''), 0, 10);
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($name) . '</td><td>' . h_($topic) . '</td><td>' . h_($status) . '</td><td>' . h_(substr($date, 0, 10)) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    $html .= '</body></html>';

    try {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        if (ob_get_level() > 0) { while (ob_get_level() > 0) ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="reporte_capacitacion_' . ($report['_id'] ?? date('Ymd')) . '.pdf"');
        echo $dompdf->output();
        exit;
    } catch (\Throwable $e) {
        error_log('[Reports PDF Training] Fatal: ' . $e->getMessage());
        json_error('Error generando PDF de capacitación: ' . $e->getMessage(), 500);
    }
}