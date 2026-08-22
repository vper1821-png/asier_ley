<?php
// Report routes

function listAll() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $reports = $db->find('reports', ['userId' => $user['_id']]);
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

function h_(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function pdf_page_band(string $title): string {
    return '<div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">' . h_($title) . '</div></div>';
}

function pdf_section_title(int $num, string $title): string {
    return '<table class="sec-title"><tr>'
        . '<td class="sec-bar"></td>'
        . '<td class="sec-num">' . str_pad((string)$num, 2, '0', STR_PAD_LEFT) . '</td>'
        . '<td class="sec-text">' . h_($title) . '</td>'
        . '</tr></table><div class="sec-rule"></div>';
}

function pdf_fields(array $fields): string {
    $out = '<table class="fields">';
    foreach ($fields as [$label, $value]) {
        $out .= '<tr><td class="f-label">' . h_($label) . ':</td><td class="f-value">' . h_($value) . '</td></tr>';
    }
    return $out . '</table>';
}

function pdf_data_table(array $headers, array $rows): string {
    $out = '<table class="data"><thead><tr>';
    foreach ($headers as $hcol) $out .= '<th>' . h_($hcol) . '</th>';
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        $out .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
        foreach ($row as $cell) $out .= '<td>' . h_($cell === '' || $cell === null ? '-' : $cell) . '</td>';
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function url_accessible($url) {
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return ['status' => 'No es URL', 'http' => '—'];
    if (!function_exists('curl_init')) return ['status' => 'No se puede verificar (curl no disponible)', 'http' => '—'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SecureLab-Report/1.0');
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code >= 200 && $code < 400;
    return ['status' => $ok ? 'Accesible' : 'No accesible', 'http' => (string)$code];
}

function download() {
    $user = Auth::requireAuth();
    $id = $_GET['id'] ?? '';
    $db = Database::getInstance();

    if ($id === '' || $id === 'all') {
        $filename = 'reportes.pdf';
        $reportTitle = 'Reporte de Cumplimiento - ' . date('Y-m-d');
    } else {
        $report = $db->findOne('reports', ['_id' => $id, 'userId' => $user['_id']]);
        if (!$report) json_error('reporte no encontrado', 404);
        $filename = 'reporte_' . ($report['_id'] ?? $id) . '.pdf';
        $reportTitle = $report['title'] ?? ('Reporte de Cumplimiento - ' . date('Y-m-d'));
    }

    if (!empty($report['type']) && $report['type'] !== 'compliance') {
        if ($report['type'] === 'security') {
            downloadSecurityReport($user, $report);
            exit;
        } elseif ($report['type'] === 'training') {
            downloadTrainingReport($user, $report);
            exit;
        }
    }

    $uid = $user['_id'];
    $agents = $db->find('agents', ['userId' => $uid]);
    $databases = $db->find('databases', ['userId' => $uid]);
    $consents = $db->find('compliance_consents', ['userId' => $uid]);
    $inventory = $db->find('compliance_inventory', ['userId' => $uid]);
    $breaches = $db->find('compliance_breaches', ['userId' => $uid]);
    $config = $db->findOne('compliance_config', ['userId' => $uid]) ?? [];
    $dpias = $db->find('compliance_dpia', ['userId' => $uid]);
    $dpas = $db->find('compliance_dpa', ['userId' => $uid]);
    $trainings = $db->find('compliance_trainings', ['userId' => $uid]);
    $pseudoRules = $db->find('compliance_pseudonymization', ['userId' => $uid]);
    $auditLogs = $db->find('audit_logs', ['userId' => $uid], ['limit' => 50]);
    $fileEvents = $db->find('file_events', ['userId' => $uid], ['limit' => 100]);
    $dbLogs = $db->find('database_logs', ['userId' => $uid], ['limit' => 100]);
    $hostEvents = $db->find('host_events', ['userId' => $uid], ['limit' => 100]);
    $fileAudits = $db->find('file_audit_logs', ['userId' => $uid], ['limit' => 100]);

    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));
    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y') . ' a las ' . date('H:i');

    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));
    $openBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved'));
    $resolvedBreaches = count($breaches) - $openBreaches;
    $activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
    $sensitiveItems = array_values(array_filter($inventory, fn($i) => !empty($i['sensitive'])));
    $highRiskItems = array_values(array_filter($inventory, fn($i) => in_array($i['risk'] ?? '', ['high', 'critical'])));
    $trainedCount = count(array_filter($trainings, fn($t) => !empty($t['signatureData'])));

    $hasDpd = !empty($config['dpdEmail']);
    $hasApdp = !empty($config['apdpRegistered']);
    $hasPrivacyPolicy = !empty($config['privacyPolicyUrl']);
    $hasCookiesPolicy = !empty($config['cookiesPolicyUrl']);
    $hasRetentionPolicy = !empty($config['dataRetentionPolicy']);
    $hasInventory = count($inventory) > 0;
    $hasConsents = count($consents) > 0;
    $allConsentsActive = $hasConsents && $activeConsents === count($consents);
    $hasDpias = count($dpias) > 0;
    $approvedDpias = count(array_filter($dpias, fn($d) => ($d['status'] ?? '') === 'approved'));
    $hasDpas = count($dpas) > 0;
    $activeDpas = count(array_filter($dpas, fn($d) => ($d['status'] ?? '') === 'active'));
    $hasBreaches = count($breaches) > 0;
    $hasTrainings = count($trainings) > 0;
    $allTrained = $hasTrainings && $trainedCount === count($trainings);
    $consentForPurpose = fn($purpose) => count(array_filter($consents, fn($c) => empty($c['revokedAt']) && ($c['purpose'] ?? null) === $purpose)) > 0;
    $hasSensitiveHandled = count($sensitiveItems) === 0 || count(array_filter($sensitiveItems, fn($si) => !$consentForPurpose($si['purpose'] ?? null))) === 0;
    $hasHighRiskDpias = count($highRiskItems) === 0 || $hasDpias;
    $hasIntlTransferOk = count(array_filter($dpas, fn($d) => !empty($d['internationalTransfer']) && (empty($d['transferGuarantees']) || ($d['status'] ?? '') !== 'active'))) === 0;
    $hasPseudo = count($pseudoRules) > 0;
    $arcoResponses = count(array_filter($auditLogs, fn($a) => ($a['action'] ?? '') === 'arco_response'));
    $complianceLevel = $config['complianceLevel'] ?? '';

    $checks = [
        ['category' => 'Identificación y Registro', 'items' => [
            ['label' => 'Delegado de Protección de Datos (DPD) designado', 'pass' => $hasDpd, 'article' => 'Art. 28', 'severity' => 'grave', 'detail' => $hasDpd ? ('DPD: ' . ($config['dpdName'] ?? '') . ' (' . $config['dpdEmail'] . ')') : 'No se ha designado un Delegado de Protección de Datos'],
            ['label' => 'Inscripción en Registro de la APDP', 'pass' => $hasApdp, 'article' => 'Art. 31', 'severity' => 'grave', 'detail' => $hasApdp ? 'Registrado ante la APDP' : 'No se ha registrado ante la Agencia de Protección de Datos Personales'],
            ['label' => 'Razón social y RUT identificados', 'pass' => !empty($config['companyName']) && !empty($config['companyRut']), 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => !empty($config['companyRut']) ? ('RUT: ' . $config['companyRut']) : 'Falta identificación formal de la empresa'],
        ]],
        ['category' => 'Política de Privacidad', 'items' => [
            ['label' => 'Política de privacidad publicada', 'pass' => $hasPrivacyPolicy, 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => $hasPrivacyPolicy ? ('URL: ' . $config['privacyPolicyUrl']) : 'No se ha publicado política de privacidad'],
            ['label' => 'Política de cookies publicada', 'pass' => $hasCookiesPolicy, 'article' => 'Art. 14 ter', 'severity' => 'leve', 'detail' => $hasCookiesPolicy ? ('URL: ' . $config['cookiesPolicyUrl']) : 'No se ha publicado política de cookies'],
            ['label' => 'Política de retención de datos definida', 'pass' => $hasRetentionPolicy, 'article' => 'Art. 14', 'severity' => 'leve', 'detail' => $hasRetentionPolicy ? ('Retención: ' . $config['dataRetentionPolicy']) : 'No se ha definido política de retención'],
        ]],
        ['category' => 'Base de Licitud y Consentimiento', 'items' => [
            ['label' => 'Consentimientos registrados para datos tratados', 'pass' => $allConsentsActive, 'article' => 'Art. 12', 'severity' => 'grave', 'detail' => $hasConsents ? ($activeConsents . ' consentimiento(s) activo(s) de ' . count($consents) . ' total') : 'No existen consentimientos registrados'],
            ['label' => 'Todos los datos sensibles con base legal', 'pass' => $hasSensitiveHandled, 'article' => 'Art. 16', 'severity' => 'gravísima', 'detail' => $hasSensitiveHandled ? 'Todos los datos sensibles tienen base legal asociada' : (count(array_filter($sensitiveItems, fn($si) => !$consentForPurpose($si['purpose'] ?? null))) . ' dato(s) sensible(s) sin base legal')],
            ['label' => 'Datos de menores con consentimiento parental', 'pass' => count(array_filter($inventory, fn($i) => ($i['category'] ?? '') === 'children')) === 0 || $consentForPurpose('children_data'), 'article' => 'Art. 16 quáter', 'severity' => 'gravísima', 'detail' => 'Consentimiento de padres/tutores para menores de 14 años'],
        ]],
        ['category' => 'Inventario de Tratamiento (RAT)', 'items' => [
            ['label' => 'Inventario de datos personales registrado', 'pass' => $hasInventory, 'article' => 'Art. 14', 'severity' => 'grave', 'detail' => $hasInventory ? (count($inventory) . ' item(s) en inventario') : 'No existe registro de actividades de tratamiento'],
            ['label' => 'Categorías de datos documentadas', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['category']))) === 0, 'article' => 'Art. 14', 'severity' => 'leve', 'detail' => 'Cada item debe tener categoría asignada'],
            ['label' => 'Finalidades del tratamiento definidas', 'pass' => !$hasInventory || count(array_filter($inventory, fn($i) => empty($i['purpose']))) === 0, 'article' => 'Art. 3 literal b)', 'severity' => 'grave', 'detail' => 'Finalidad específica documentada para cada tratamiento'],
        ]],
        ['category' => 'Medidas de Seguridad', 'items' => [
            ['label' => 'Nivel de seguridad adecuado al riesgo', 'pass' => $complianceLevel !== '' && $complianceLevel !== 'basic', 'article' => 'Art. 14 quinquies', 'severity' => 'grave', 'detail' => $complianceLevel !== '' ? ('Nivel: ' . $complianceLevel) : 'No se ha evaluado el nivel de seguridad'],
            ['label' => 'Cifrado/seudonimización implementado', 'pass' => $hasPseudo, 'article' => 'Art. 14 quinquies', 'severity' => 'grave', 'detail' => $hasPseudo ? (count($pseudoRules) . ' regla(s) de seudonimización') : 'No se han configurado reglas de seudonimización'],
            ['label' => 'Monitoreo de seguridad activo', 'pass' => $onlineAgents > 0, 'article' => 'Art. 14 quinquies', 'severity' => 'grave', 'detail' => $onlineAgents > 0 ? ($onlineAgents . ' agente(s) en línea · ' . count($hostEvents) . ' eventos de host · ' . count($fileEvents) . ' eventos de archivo · ' . count($dbLogs) . ' logs de BBDD') : 'No hay agentes de monitoreo activos'],
        ]],
        ['category' => 'Brechas de Seguridad', 'items' => [
            ['label' => 'Protocolo de notificación de brechas', 'pass' => !$hasBreaches || $resolvedBreaches > 0, 'article' => 'Art. 14 sexies', 'severity' => 'gravísima', 'detail' => $hasBreaches ? ($resolvedBreaches . ' brecha(s) resuelta(s) de ' . count($breaches) . ' total') : 'Sin incidentes registrados'],
            ['label' => 'Notificación a APDP dentro de plazo', 'pass' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved' && empty($b['notifiedAPDP']))) === 0, 'article' => 'Art. 14 sexies', 'severity' => 'gravísima', 'detail' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved' && empty($b['notifiedAPDP']))) > 0 ? (count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved' && empty($b['notifiedAPDP']))) . ' brecha(s) abierta(s) sin notificar a APDP') : 'Todas las brechas notificadas'],
        ]],
        ['category' => 'Evaluación de Impacto (DPIA)', 'items' => [
            ['label' => 'DPIA realizadas para tratamientos de alto riesgo', 'pass' => $hasHighRiskDpias, 'article' => 'Art. 14 quater', 'severity' => 'grave', 'detail' => $hasDpias ? ($approvedDpias . ' DPIA aprobada(s) de ' . count($dpias) . ' total') : (count($highRiskItems) > 0 ? 'Existen items de alto riesgo sin DPIA' : 'No se requiere DPIA actualmente')],
            ['label' => 'DPIA aprobadas para datos sensibles', 'pass' => count($sensitiveItems) === 0 || count(array_filter($dpias, fn($d) => !empty($d['sensitiveData']) && ($d['status'] ?? '') === 'approved')) > 0, 'article' => 'Art. 14 quater', 'severity' => 'grave', 'detail' => 'Evaluación de impacto para tratamientos con datos sensibles'],
        ]],
        ['category' => 'Acuerdos con Encargados (DPA)', 'items' => [
            ['label' => 'Acuerdos con encargados vigentes', 'pass' => !$hasDpas || $activeDpas > 0, 'article' => 'Art. 29', 'severity' => 'grave', 'detail' => $hasDpas ? ($activeDpas . ' DPA activo(s) de ' . count($dpas) . ' total') : 'No hay acuerdos con encargados registrados'],
            ['label' => 'Sin transferencias internacionales sin garantías', 'pass' => $hasIntlTransferOk, 'article' => 'Art. 27', 'severity' => 'gravísima', 'detail' => $hasIntlTransferOk ? 'Transferencias internacionales con garantías adecuadas' : 'Existe transferencia internacional sin garantías documentadas'],
        ]],
        ['category' => 'Capacitación', 'items' => [
            ['label' => 'Programa de capacitación implementado', 'pass' => $hasTrainings, 'article' => 'Art. 28 letra c)', 'severity' => 'leve', 'detail' => $hasTrainings ? (count($trainings) . ' capacitación(es) registrada(s)') : 'No se ha implementado programa de capacitación'],
            ['label' => 'Personal capacitado con firma', 'pass' => $allTrained, 'article' => 'Art. 28 letra c)', 'severity' => 'leve', 'detail' => $hasTrainings ? ($trainedCount . '/' . count($trainings) . ' colaborador(es) con firma') : 'Sin registros de capacitación'],
        ]],
        ['category' => 'Derechos ARCO', 'items' => [
            ['label' => 'Mecanismo para ejercer derechos ARCO', 'pass' => $hasPrivacyPolicy, 'article' => 'Art. 4-9', 'severity' => 'leve', 'detail' => $hasPrivacyPolicy ? 'Política de privacidad publicada (debe incluir mecanismo ARCO)' : 'Sin mecanismo documentado para derechos ARCO'],
            ['label' => 'Registro de solicitudes ARCO', 'pass' => $arcoResponses > 0, 'article' => 'Art. 11', 'severity' => 'leve', 'detail' => $arcoResponses > 0 ? ($arcoResponses . ' respuesta(s) ARCO registrada(s)') : 'Sin solicitudes ARCO registradas'],
        ]],
    ];

    $totalChecks = 0; $passedChecks = 0;
    foreach ($checks as $cat) {
        foreach ($cat['items'] as $it) { $totalChecks++; if ($it['pass']) $passedChecks++; }
    }
    $failedBySev = ['gravísima' => 0, 'grave' => 0, 'leve' => 0];
    foreach ($checks as $cat) foreach ($cat['items'] as $it) if (!$it['pass']) $failedBySev[$it['severity']]++;
    $passRate = $totalChecks > 0 ? (int)round($passedChecks / $totalChecks * 100) : 0;

    // ====== BUILD HTML (diseño portado de backend-node PDFKit) ======
    $css = "
        @page{margin:0}
        body{font-family:'DejaVu Sans',Helvetica,Arial,sans-serif;margin:0;padding:0;color:#1a1a1a;font-size:9px}
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
        .cover-box2{background:#f5f5f5;border:0.5px solid #bbbbbb;margin:14px 60px 0 60px;padding:8px 10px;text-align:center;color:#1a1a1a;font-size:8px;font-weight:bold}
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
        .warn{color:#4a4a4a;font-size:9px;font-weight:bold;margin-top:6px}
        .note{color:#1a1a1a;font-size:8px;margin-top:4px;line-height:1.5}
        .body-text{color:#1a1a1a;font-size:9px;line-height:1.6;margin-top:6px}
        .kpi{color:#1a1a1a;font-size:9px;line-height:1.9}
        .cat-header{background:#f0f0f0;border:0.3px solid #bbbbbb;color:#1a1a1a;font-size:9px;font-weight:bold;padding:5px 8px;margin-top:12px}
        .chk{margin:8px 0 0 4px;border-bottom:0.3px solid #e0e0e0;padding-bottom:6px}
        .chk-row{width:100%;border-collapse:collapse}
        .chk-mark{width:16px;font-size:10px;font-weight:bold;vertical-align:top}
        .chk-pass{color:#166534}
        .chk-fail{color:#4a4a4a}
        .chk-label{color:#1a1a1a;font-size:8px}
        .chk-art{color:#555555;font-size:7px;text-align:right;width:60px;vertical-align:top}
        .chk-detail{color:#555555;font-size:7px;padding-left:16px;margin-top:2px}
        .chk-sev{color:#4a4a4a;font-size:7px;font-weight:bold;padding-left:16px;margin-top:2px}
        .data{width:100%;border-collapse:collapse;margin-top:8px}
        .data th{background:#1a1a1a;color:#777777;font-size:7.5px;font-weight:bold;text-align:left;padding:7px 8px}
        .data td{color:#1a1a1a;font-size:7.5px;padding:5px 8px}
        .data tr.alt td{background:#f1f5f9}
        .art-note{color:#555555;font-size:8px;line-height:1.5;margin-top:2px}
        .rec{margin-top:10px}
        .rec-prio{color:#4a4a4a;font-size:8px;font-weight:bold}
        .rec-prio.media{color:#555555}
        .rec-text{color:#1a1a1a;font-size:8.5px;line-height:1.5}
        .rec-art{color:#555555;font-size:7px}
        .close-rule{border-top:0.5px solid #bbbbbb;margin-top:24px}
        .close-center{color:#555555;font-size:8px;text-align:center;margin-top:12px}
        .close-muted{color:#777777;font-size:7.5px;text-align:center;margin-top:4px}
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>" . h_($reportTitle) . "</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">Ley 21.719 · Reporte de Cumplimiento</div>';

    // ====== PORTADA ======
    $html .= '<div class="cover"><div class="cover-topline"></div><div class="cover-body">';
    $html .= '<div class="cover-label">REPÚBLICA DE CHILE</div>';
    $html .= '<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-company">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-title">' . h_($reportTitle) . '</div>';
    $html .= '<div class="cover-sub">Reporte de Cumplimiento</div>';
    $html .= '<div class="cover-sep2"></div>';
    $html .= '<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . h_($dateStr) . '</div></div>';
    $html .= '<div class="cover-box2">CLASIFICACIÓN: CONFIDENCIAL</div>';
    $html .= '</div></div>';

    // ====== IDENTIFICACIÓN DEL RESPONSABLE ======
    $levelLabel = $complianceLevel !== '' ? ucfirst($complianceLevel) : 'No evaluado';
    $html .= pdf_page_band('Identificación del Responsable del Tratamiento') . '<div class="content">';
    $html .= pdf_section_title(1, 'Datos de la Organización');
    $html .= pdf_fields([
        ['Razón Social', $config['companyName'] ?? '—'],
        ['RUT', $config['companyRut'] ?? '—'],
        ['Giro / Actividad', $config['companyActivity'] ?? '—'],
        ['Domicilio', $config['companyAddress'] ?? '—'],
        ['Email de Contacto', $user['email'] ?? '—'],
        ['Nivel de Cumplimiento', $levelLabel],
    ]);
    $html .= pdf_section_title(2, 'Delegado de Protección de Datos (DPD)');
    if ($hasDpd) {
        $html .= pdf_fields([
            ['Nombre', $config['dpdName'] ?? '—'],
            ['Email', $config['dpdEmail'] ?? '—'],
            ['Teléfono', $config['dpdPhone'] ?? '—'],
        ]);
    } else {
        $html .= '<div class="warn">⚠ NO SE HA DESIGNADO UN DELEGADO DE PROTECCIÓN DE DATOS</div>';
        $html .= '<div class="note">Art. 28 Ley 21.719: La designación del DPD es obligatoria para responsables que realizan tratamiento a gran escala de datos sensibles. Su ausencia constituye infracción grave sancionable con multa de hasta 10.000 UTM.</div>';
    }
    $html .= pdf_section_title(3, 'Registro ante la APDP');
    if ($hasApdp) {
        $html .= '<div class="body-text">La organización se encuentra inscrita en el Registro Nacional de Sanciones y Cumplimiento de la Agencia de Protección de Datos Personales (Art. 31).</div>';
    } else {
        $html .= '<div class="warn">⚠ NO SE HA REGISTRADO ANTE LA APDP</div>';
        $html .= '<div class="note">Art. 31 Ley 21.719: Todo responsable del tratamiento debe inscribirse en el Registro Nacional. La omisión constituye infracción grave sancionable con multa de hasta 10.000 UTM.</div>';
    }
    $html .= '</div>';

    // ====== RESUMEN EJECUTIVO ======
    $html .= '<div class="page"></div>' . pdf_page_band('Resumen Ejecutivo de Cumplimiento') . '<div class="content">';
    $html .= pdf_section_title(4, 'Indicadores Clave');
    $kpis = [
        ['Score de Cumplimiento General', $passRate . '% (' . $passedChecks . '/' . $totalChecks . ' requisitos cumplidos)'],
        ['Infracciones Gravísimas Pendientes', (string)$failedBySev['gravísima']],
        ['Infracciones Graves Pendientes', (string)$failedBySev['grave']],
        ['Infracciones Leves Pendientes', (string)$failedBySev['leve']],
        ['Bases de Datos Monitoreadas', (string)count($databases)],
        ['Items de Datos Registrados', (string)count($inventory)],
        ['Consentimientos Activos', (string)$activeConsents],
        ['Brechas de Seguridad', $openBreaches . ' abierta(s) / ' . $resolvedBreaches . ' resuelta(s)'],
        ['Agentes de Monitoreo', $onlineAgents . '/' . count($agents)],
        ['Capacitaciones Firmadas', $trainedCount . '/' . count($trainings)],
    ];
    $html .= '<div class="kpi">';
    foreach ($kpis as [$k, $v]) $html .= h_($k) . ': ' . h_($v) . '<br>';
    $html .= '</div>';
    if ($passRate >= 90) {
        $levelText = 'Nivel de cumplimiento: EXCELENTE. La organización cumple con la mayoría de los requisitos establecidos en la Ley 21.719. Se recomienda mantener los controles actuales y realizar auditorías periódicas.';
    } elseif ($passRate >= 70) {
        $levelText = "Nivel de cumplimiento: ACEPTABLE. Se cumplen $passedChecks de $totalChecks requisitos. Se recomienda atender los " . ($totalChecks - $passedChecks) . ' requisitos pendientes para alcanzar un nivel óptimo de cumplimiento.';
    } elseif ($passRate >= 50) {
        $levelText = "Nivel de cumplimiento: DEFICIENTE. Solo se cumplen $passedChecks de $totalChecks requisitos. La organización se expone a sanciones significativas. Se requiere acción inmediata.";
    } else {
        $levelText = "Nivel de cumplimiento: CRÍTICO. Solo se cumplen $passedChecks de $totalChecks requisitos. La organización se encuentra en alto riesgo de sanciones de hasta 20.000 UTM. Se requiere plan de acción urgente.";
    }
    $html .= '<div class="body-text">' . h_($levelText) . '</div>';
    $html .= '</div>';

    // ====== CHECKLIST ======
    $html .= '<div class="page"></div>' . pdf_page_band('Checklist de Cumplimiento - Ley 21.719') . '<div class="content">';
    $html .= pdf_section_title(5, 'Evaluación Detallada por Obligación Legal');
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

    // ====== INVENTARIO ======
    if ($hasInventory) {
        $html .= '<div class="page"></div>' . pdf_page_band('Registro de Actividades de Tratamiento (RAT)') . '<div class="content">';
        $html .= pdf_section_title(6, 'Inventario de Datos Personales (' . count($inventory) . ' items)');
        $html .= '<div class="art-note">Art. 14 Ley 21.719: El responsable debe mantener un registro documentado de las actividades de tratamiento, incluyendo finalidades, categorías de datos, destinatarios y plazos de conservación.</div>';
        $html .= pdf_data_table(
            ['Tipo de Dato', 'Categoría', 'Sensibles', 'Propósito'],
            array_map(fn($i) => [$i['dataType'] ?? '-', $i['category'] ?? '-', !empty($i['sensitive']) ? 'SÍ' : 'No', $i['purpose'] ?? '-'], array_slice($inventory, 0, 25))
        );
        $html .= '</div>';
    }

    // ====== CONSENTIMIENTOS ======
    if ($hasConsents) {
        $html .= '<div class="page"></div>' . pdf_page_band('Gestión de Consentimientos') . '<div class="content">';
        $html .= pdf_section_title(7, 'Consentimientos Registrados (' . count($consents) . ')');
        $html .= '<div class="art-note">Art. 12 Ley 21.719: El consentimiento debe ser libre, informado, específico, previo e inequívoco. Corresponde al responsable probar que contó con el consentimiento del titular.</div>';
        $html .= pdf_data_table(
            ['Propósito', 'Usuario', 'Estado', 'Otorgado'],
            array_map(fn($c) => [$c['purpose'] ?? '-', $c['userEmail'] ?? ($c['grantedBy'] ?? '-'), empty($c['revokedAt']) ? 'Activo' : 'Revocado', !empty($c['grantedAt']) ? substr($c['grantedAt'], 0, 10) : '-'], array_slice($consents, 0, 25))
        );
        $html .= '</div>';
    }

    // ====== BRECHAS ======
    if ($hasBreaches) {
        $html .= '<div class="page"></div>' . pdf_page_band('Registro de Brechas de Seguridad') . '<div class="content">';
        $html .= pdf_section_title(8, 'Brechas Reportadas (' . count($breaches) . ')');
        $html .= '<div class="art-note">Art. 14 sexies: El responsable debe reportar a la APDP, sin dilaciones indebidas, las vulneraciones que generen riesgo para los derechos de los titulares. Cuando afecten datos sensibles, niños o datos económicos, debe también comunicar a los titulares.</div>';
        $html .= '<div class="art-note">Total: ' . count($breaches) . ' · Abiertas: ' . $openBreaches . ' · Resueltas: ' . $resolvedBreaches . '</div>';
        $html .= pdf_data_table(
            ['Tipo', 'Severidad', 'Estado', 'Detectado'],
            array_map(fn($b) => [$b['type'] ?? '-', $b['severity'] ?? '-', ($b['status'] ?? '') === 'resolved' ? 'Resuelta' : 'Abierta', !empty($b['detectedAt']) ? substr($b['detectedAt'], 0, 10) : '-'], array_slice($breaches, 0, 20))
        );
        $html .= '</div>';
    }

    // ====== CAPACITACIÓN ======
    if ($hasTrainings) {
        $topicLabels = ['proteccion_datos' => 'Protección de Datos Personales', 'ciberseguridad' => 'Ciberseguridad', 'brechas' => 'Protocolo de Brechas', 'arco' => 'Derechos ARCO', 'consentimientos' => 'Gestión de Consentimientos', 'general' => 'General'];
        $html .= '<div class="page"></div>' . pdf_page_band('Programa de Capacitación') . '<div class="content">';
        $html .= pdf_section_title(9, 'Capacitaciones Registradas (' . count($trainings) . ')');
        $html .= '<div class="art-note">Art. 28 letra c): El responsable debe implementar programas de capacitación periódica en protección de datos personales para todo el personal que participe en operaciones de tratamiento.</div>';
        $html .= pdf_data_table(
            ['Colaborador', 'Tema', 'Estado', 'Fecha'],
            array_map(fn($t) => [
                $t['employeeName'] ?? '-',
                $topicLabels[$t['topic'] ?? ''] ?? ($t['topic'] ?? '-'),
                !empty($t['signatureData']) ? 'Firmado' : (!empty($t['completed']) ? 'Completado' : 'Pendiente'),
                !empty($t['date']) ? substr($t['date'], 0, 10) : '-',
            ], array_slice($trainings, 0, 20))
        );
        $html .= '</div>';
    }

    // ====== EVIDENCIA DE AGENTES ======
    $html .= '<div class="page"></div>' . pdf_page_band('Evidencia de Agentes de Seguridad') . '<div class="content">';
    $html .= pdf_section_title(10, 'Indicadores de Seguridad');
    $html .= pdf_fields([
        ['Agentes en línea', (string)$onlineAgents],
        ['Eventos de host', (string)count($hostEvents)],
        ['Eventos de archivo', (string)count($fileEvents)],
        ['Logs de BBDD', (string)count($dbLogs)],
        ['Auditorías de archivos', (string)count($fileAudits)],
    ]);
    $html .= '<p class="body-text">Los datos siguientes provienen del monitoreo continuo de agentes instalados en los endpoints y bases de datos. Esta evidencia permite verificar el cumplimiento de las medidas de seguridad del Art. 14 quinquies de la Ley 21.719.</p>';

    if (count($fileEvents) > 0) {
        $html .= pdf_section_title(11, 'Eventos de Archivo Recientes (' . count($fileEvents) . ')');
        $html .= pdf_data_table(
            ['Fecha', 'Ruta', 'Evento'],
            array_map(fn($e) => [
                substr(($e['timestamp'] ?? $e['createdAt'] ?? ''), 0, 16),
                $e['path'] ?? '-',
                $e['eventType'] ?? '-',
            ], array_slice($fileEvents, 0, 10))
        );
    }

    if (count($dbLogs) > 0) {
        $html .= pdf_section_title(12, 'Logs de Base de Datos Recientes (' . count($dbLogs) . ')');
        $html .= pdf_data_table(
            ['Fecha', 'Base de Datos', 'Operación'],
            array_map(fn($l) => [
                substr(($l['timestamp'] ?? $l['createdAt'] ?? ''), 0, 16),
                $l['database'] ?? '-',
                $l['operation'] ?? ($l['query'] ?? '-'),
            ], array_slice($dbLogs, 0, 10))
        );
    }

    if (count($hostEvents) > 0) {
        $html .= pdf_section_title(13, 'Eventos de Sistema Recientes (' . count($hostEvents) . ')');
        $html .= pdf_data_table(
            ['Fecha', 'Título', 'Severidad'],
            array_map(fn($e) => [
                substr(($e['timestamp'] ?? $e['createdAt'] ?? ''), 0, 16),
                $e['title'] ?? ($e['event'] ?? '-'),
                $e['severity'] ?? '-',
            ], array_slice($hostEvents, 0, 10))
        );
    }

    if (count($fileAudits) > 0) {
        $html .= pdf_section_title(14, 'Auditoría de Archivos Reciente (' . count($fileAudits) . ')');
        $html .= pdf_data_table(
            ['Fecha', 'Archivo', 'Acción'],
            array_map(fn($a) => [
                substr(($a['createdAt'] ?? $a['timestamp'] ?? ''), 0, 16),
                $a['fileName'] ?? ($a['path'] ?? '-'),
                $a['action'] ?? '-',
            ], array_slice($fileAudits, 0, 10))
        );
    }
    $html .= '</div>';

    // ====== RECOMENDACIONES ======
    $sectionNum = 15;
    $recs = [];
    if (!$hasDpd) $recs[] = ['ALTA', 'Designar un Delegado de Protección de Datos (DPD) según Art. 28. Este será el responsable de supervisar el cumplimiento continuo de la ley.', 'Art. 28'];
    if (!$hasApdp) $recs[] = ['ALTA', 'Inscribirse en el Registro Nacional de Sanciones y Cumplimiento de la APDP antes del 1 de diciembre de 2026.', 'Art. 31'];
    if (!$hasPrivacyPolicy) $recs[] = ['ALTA', 'Publicar una política de privacidad clara y accesible que incluya: identidad del responsable, finalidades, base de licitud, derechos del titular y mecanismo para ejercerlos.', 'Art. 14 ter'];
    if (!$allConsentsActive) $recs[] = ['ALTA', 'Implementar un sistema de gestión de consentimientos que registre el consentimiento libre, informado, específico, previo e inequívoco de cada titular.', 'Art. 12'];
    if (!$hasInventory) $recs[] = ['ALTA', 'Crear un Registro de Actividades de Tratamiento (RAT) documentando cada actividad: qué datos, para qué, base legal, destinatarios y plazos.', 'Art. 14'];
    if (!$hasPseudo) $recs[] = ['MEDIA', 'Implementar medidas de seudonimización o cifrado para datos personales según el nivel de riesgo.', 'Art. 14 quinquies'];
    if (!$hasDpias && count($highRiskItems) > 0) $recs[] = ['ALTA', 'Realizar Evaluaciones de Impacto (DPIA) para tratamientos de alto riesgo, especialmente los que involucren datos sensibles.', 'Art. 14 quater'];
    if (!$hasTrainings) $recs[] = ['MEDIA', 'Implementar un programa de capacitación periódica en protección de datos para todo el personal que manipule datos personales.', 'Art. 28 c)'];
    if (!$hasCookiesPolicy) $recs[] = ['MEDIA', 'Publicar una política de cookies que informe claramente sobre el uso de tecnologías de rastreo.', 'Art. 14 ter'];
    if (!$hasRetentionPolicy) $recs[] = ['MEDIA', 'Definir y documentar una política de retención de datos que establezca plazos máximos de conservación para cada categoría.', 'Art. 14'];
    if (!$hasIntlTransferOk) $recs[] = ['ALTA', 'Regularizar las transferencias internacionales de datos con cláusulas contractuales o verificación de nivel adecuado del país receptor.', 'Art. 27'];
    if (empty($recs)) $recs[] = ['MEDIA', 'Mantener los controles actuales y realizar auditorías periódicas de cumplimiento al menos una vez al año.', 'Buenas prácticas'];

    $html .= '<div class="page"></div>' . pdf_page_band('Recomendaciones y Plan de Acción') . '<div class="content">';
    $html .= pdf_section_title($sectionNum++, 'Acciones Correctivas');
    foreach ($recs as [$prio, $text, $art]) {
        $html .= '<div class="rec"><table class="chk-row"><tr>';
        $html .= '<td style="width:42px;vertical-align:top"><span class="rec-prio' . ($prio === 'MEDIA' ? ' media' : '') . '">[' . $prio . ']</span></td>';
        $html .= '<td><div class="rec-text">' . h_($text) . '</div><div class="rec-art">' . h_($art) . '</div></td>';
        $html .= '</tr></table></div>';
    }
    $html .= '</div>';

    // ====== VALIDACIONES EXTERNAS ======
    $html .= '<div class="page"></div>' . pdf_page_band('Validaciones Externas') . '<div class="content">';
    $html .= pdf_section_title($sectionNum++, 'Verificación de Recursos Públicos');
    $html .= '<div class="art-note">Esta sección intenta verificar la accesibilidad pública de políticas documentadas. La validación real ante la APDP depende de mecanismos oficiales (no disponibles en API pública al cierre de esta versión).</div>';
    $privacyOk = url_accessible($config['privacyPolicyUrl'] ?? '');
    $cookiesOk = url_accessible($config['cookiesPolicyUrl'] ?? '');
    $retentionOk = url_accessible($config['dataRetentionPolicy'] ?? '');
    $apdpOk = $hasApdp ? ['status' => 'Registrado declarado', 'http' => '—'] : ['status' => 'Sin registro declarado', 'http' => '—'];
    $html .= pdf_data_table(
        ['Recurso', 'Estado', 'HTTP', 'Observación'],
        [
            ['Política de privacidad', $privacyOk['status'], $privacyOk['http'], $hasPrivacyPolicy ? 'URL configurada' : 'No configurada'],
            ['Política de cookies', $cookiesOk['status'], $cookiesOk['http'], $hasCookiesPolicy ? 'URL configurada' : 'No configurada'],
            ['Política de retención', $retentionOk['status'], $retentionOk['http'], $hasRetentionPolicy ? 'URL configurada' : 'No configurada'],
            ['Registro APDP', $apdpOk['status'], $apdpOk['http'], 'Requiere verificación manual o API de la APDP'],
        ]
    );
    $html .= '</div>';

    // ====== MARCO LEGAL ======
    $lawLines = [
        'La Ley 21.719, publicada el 13 de diciembre de 2024, regula la protección y el tratamiento de los datos personales en Chile, creando la Agencia de Protección de Datos Personales (APDP). Vigente desde el 1 de diciembre de 2026.',
        'Principios rectores (Art. 3): Licitud y lealtad, finalidad, proporcionalidad, calidad, responsabilidad, seguridad, transparencia e información, y confidencialidad.',
        'Derechos del titular (Art. 4-9): Acceso, Rectificación, Supresión, Oposición, Portabilidad y Bloqueo temporal. Plazo de respuesta: 30 días corridos.',
        'Consentimiento (Art. 12): Libre, informado, específico, previo e inequívoco. Otras bases: obligación legal, ejecución de contrato, interés legítimo.',
        'Medidas de seguridad (Art. 14 quinquies): Cifrado, seudonimización, confidencialidad, integridad, disponibilidad y resiliencia.',
        'Brechas (Art. 14 sexies): Notificación a APDP sin dilaciones indebidas. A titulares cuando afecten datos sensibles, niños o económicos.',
        'DPD (Art. 28): Obligatorio para tratamiento a gran escala de datos sensibles.',
        'Sanciones: Leves hasta 5.000 UTM, graves hasta 10.000 UTM, gravísimas hasta 20.000 UTM (Art. 34 bis-34 quáter).',
    ];
    $html .= '<div class="page"></div>' . pdf_page_band('Ley 21.719 - Protección de Datos Personales') . '<div class="content">';
    $html .= pdf_section_title($sectionNum++, 'Marco Legal - Ley 21.719');
    foreach ($lawLines as $line) $html .= '<div class="body-text">' . h_($line) . '</div>';
    $html .= '</div>';

    // ====== CIERRE ======
    $html .= '<div class="page"></div>' . pdf_page_band('Cierre del Reporte') . '<div class="content">';
    $html .= pdf_section_title($sectionNum++, 'Declaración');
    $html .= '<div class="body-text">El presente reporte ha sido generado electrónicamente por la plataforma de cumplimiento de la Ley 21.719 de Protección de Datos Personales. Refleja el estado de cumplimiento de ' . h_($companyName) . ' al momento de su emisión.</div>';
    $html .= '<div class="body-text">Este documento tiene carácter de declaración de cumplimiento y debe ser revisado por el Delegado de Protección de Datos (DPD) o encargado designado. No sustituye una auditoría externa independiente.</div>';
    $html .= '<div class="body-text">Fecha de emisión: ' . h_($dateStr) . '</div>';
    $html .= '<div class="body-text">Score de cumplimiento: ' . $passRate . '%</div>';
    $html .= '<div class="body-text">Requisitos evaluados: ' . $totalChecks . ' · Cumplidos: ' . $passedChecks . ' · Pendientes: ' . ($totalChecks - $passedChecks) . '</div>';
    $html .= pdf_section_title($sectionNum++, 'Trazabilidad del Documento');
    $html .= pdf_fields([
        ['ID del reporte', $report['_id'] ?? ('all-' . date('Ymd-His'))],
        ['Generado por', $user['email'] ?? '—'],
        ['Fecha/hora UTC', date('c')],
        ['Plataforma', 'SecureLab / Ley 21.719'],
    ]);
    $html .= '<div class="close-rule"></div>';
    $html .= '<div class="close-center">Documento generado electrónicamente · Ley 21.719 · Protección de Datos Personales · Chile</div>';
    $html .= '<div class="close-muted">Fecha de emisión: ' . h_($dateStr) . ' · Confidencial</div>';
    $html .= '</div>';

    $html .= '</body></html>';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html);
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $dompdf->output();
    exit;
}

function downloadSecurityReport($user, $report) {
    $db = Database::getInstance();
    $uid = $user['_id'];
    $config = $db->findOne('compliance_config', ['userId' => $uid]) ?? [];
    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));

    $fileEvents = $db->find('file_events', ['userId' => $uid]);
    $dbLogs = $db->find('database_logs', ['userId' => $uid]);
    $hostEvents = $db->find('host_events', ['userId' => $uid]);
    $fileAudits = $db->find('file_audit_logs', ['userId' => $uid]);
    $agents = $db->find('agents', ['userId' => $uid]);

    $highSeverity = fn($ev) => in_array(($ev['severity'] ?? $ev['level'] ?? ''), ['critical', 'high', 'alta', 'critico']);
    $criticalCount = count(array_filter(array_merge($fileEvents, $dbLogs, $hostEvents, $fileAudits), $highSeverity));
    $totalEvents = count($fileEvents) + count($dbLogs) + count($hostEvents) + count($fileAudits);
    $onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));

    $recent = array_slice(array_merge($fileEvents, $dbLogs, $hostEvents, $fileAudits), 0, 15);
    usort($recent, fn($a, $b) => strcmp(($b['timestamp'] ?? $b['createdAt'] ?? ''), ($a['timestamp'] ?? $a['createdAt'] ?? '')));

    $riskScore = $totalEvents > 0 ? max(0, min(100, (int)round($criticalCount / $totalEvents * 100))) : 0;
    $level = $riskScore >= 70 ? 'CRÍTICO' : ($riskScore >= 40 ? 'ALTO' : ($riskScore >= 15 ? 'MEDIO' : 'BAJO'));

    $recommendations = [];
    if ($criticalCount > 0) $recommendations[] = ['ALTA', 'Revisar inmediatamente los eventos críticos detectados en equipos, bases de datos y archivos.'];
    if (count($fileEvents) > 0) $recommendations[] = ['ALTA', 'Verificar integridad de archivos sensibles y control de accesos.'];
    if (count($dbLogs) > 0) $recommendations[] = ['MEDIA', 'Auditar consultas anómalas a bases de datos y reforzar permisos.'];
    if (count($hostEvents) > 0) $recommendations[] = ['MEDIA', 'Revisar actividad de procesos, usuarios y conexiones en endpoints.'];
    if (count($fileAudits) > 0) $recommendations[] = ['MEDIA', 'Reforzar monitoreo de auditoría de archivos y accesos no autorizados.'];
    if (empty($recommendations)) $recommendations[] = ['BAJA', 'No se registraron eventos de seguridad en el período. Mantener monitoreo continuo.'];

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
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">01</td><td class="sec-text">Indicadores de Riesgo</td></tr></table><div class="sec-rule"></div>';
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
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">02</td><td class="sec-text">Últimos eventos detectados</td></tr></table><div class="sec-rule"></div>';
    if (empty($recent)) {
        $html .= '<p style="font-size:10px">No se registraron eventos recientes.</p>';
    } else {
        $html .= '<table class="data"><thead><tr><th>Fuente</th><th>Severidad</th><th>Descripción</th><th>Fecha</th></tr></thead><tbody>';
        foreach ($recent as $i => $ev) {
            $source = $ev['source'] ?? ($ev['collection'] ?? 'Sistema');
            $sev = $ev['severity'] ?? $ev['level'] ?? 'info';
            $desc = $ev['message'] ?? $ev['description'] ?? $ev['action'] ?? json_encode($ev);
            $ts = $ev['timestamp'] ?? $ev['createdAt'] ?? '';
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($source) . '</td><td>' . h_($sev) . '</td><td>' . h_(substr($desc, 0, 120)) . '</td><td>' . h_(substr($ts, 0, 16)) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Recomendaciones</div></div><div class="content">';
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">03</td><td class="sec-text">Acciones recomendadas</td></tr></table><div class="sec-rule"></div>';
    $html .= '<table class="data"><thead><tr><th>Prioridad</th><th>Recomendación</th></tr></thead><tbody>';
    foreach ($recommendations as $i => $rec) {
        $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($rec[0]) . '</td><td>' . h_($rec[1]) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p style="font-size:8px;color:#555;margin-top:20px">Art. 14 quinquies Ley 21.719: el responsable debe implementar medidas técnicas y organizativas adecuadas al riesgo para garantizar la seguridad, confidencialidad, integridad y disponibilidad de los datos personales.</p>';
    $html .= '</div>';

    $html .= '</body></html>';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html);
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reporte_seguridad_' . ($report['_id'] ?? date('Ymd')) . '.pdf"');
    echo $dompdf->output();
    exit;
}

function downloadTrainingReport($user, $report) {
    $db = Database::getInstance();
    $uid = $user['_id'];
    $config = $db->findOne('compliance_config', ['userId' => $uid]) ?? [];
    $companyName = $config['companyName'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Empresa'));
    $trainings = $db->find('compliance_trainings', ['userId' => $uid]);

    $topicLabels = ['proteccion_datos' => 'Protección de Datos Personales', 'ciberseguridad' => 'Ciberseguridad', 'brechas' => 'Protocolo de Brechas', 'arco' => 'Derechos ARCO', 'consentimientos' => 'Gestión de Consentimientos', 'general' => 'General'];
    $total = count($trainings);
    $signed = count(array_filter($trainings, fn($t) => !empty($t['signatureData'])));
    $completed = count(array_filter($trainings, fn($t) => !empty($t['completed']) || !empty($t['signatureData'])));
    $pending = $total - $completed;

    $byTopic = [];
    foreach ($trainings as $t) {
        $topic = $t['topic'] ?? 'general';
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
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">01</td><td class="sec-text">Indicadores de Capacitación</td></tr></table><div class="sec-rule"></div>';
    $html .= '<div class="kpi">';
    $html .= 'Total capacitaciones registradas: ' . $total . '<br>';
    $html .= 'Completadas/firmadas: ' . $completed . '<br>';
    $html .= 'Pendientes: ' . $pending . '<br>';
    $html .= 'Con firma digital: ' . $signed . '<br>';
    $html .= '</div>';
    $html .= '<p style="font-size:9px;line-height:1.5;margin-top:12px">Art. 28 letra c) Ley 21.719: el responsable debe implementar programas de capacitación periódica en protección de datos personales para todo el personal que participe en operaciones de tratamiento.</p>';
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">Ley 21.719 · Protección de Datos Personales · Chile</div><div class="band-title">Cobertura por Tema</div></div><div class="content">';
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">02</td><td class="sec-text">Capacitaciones por área</td></tr></table><div class="sec-rule"></div>';
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
    $html .= '<table class="sec-title"><tr><td class="sec-bar"></td><td class="sec-num">03</td><td class="sec-text">Colaboradores capacitados</td></tr></table><div class="sec-rule"></div>';
    if (empty($trainings)) {
        $html .= '<p style="font-size:10px">No se encontraron registros de colaboradores.</p>';
    } else {
        $html .= '<table class="data"><thead><tr><th>Colaborador</th><th>Tema</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        foreach (array_slice($trainings, 0, 30) as $i => $t) {
            $name = $t['employeeName'] ?? '—';
            $topic = $topicLabels[$t['topic'] ?? ''] ?? ucfirst($t['topic'] ?? '—');
            $status = !empty($t['signatureData']) ? 'Firmado' : (!empty($t['completed']) ? 'Completado' : 'Pendiente');
            $date = !empty($t['date']) ? $t['date'] : (!empty($t['createdAt']) ? substr($t['createdAt'], 0, 10) : '—');
            $html .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '><td>' . h_($name) . '</td><td>' . h_($topic) . '</td><td>' . h_($status) . '</td><td>' . h_(substr($date, 0, 10)) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '</div>';

    $html .= '</body></html>';

    $dompdf = new Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html);
    $dompdf->render();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reporte_capacitacion_' . ($report['_id'] ?? date('Ymd')) . '.pdf"');
    echo $dompdf->output();
    exit;
}
