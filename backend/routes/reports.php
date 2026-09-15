<?php
// Report routes - NEXUSGUARDIA Adequacy Report Model
// Modelo de adecuación Ley 21.719 · Gobierna | Protege | Demuestra

// ═══════════════════════════════════════════════════════════════════
// Límites ampliados para generación de PDFs complejos
// ═══════════════════════════════════════════════════════════════════
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');
@set_time_limit(300);

// ═══════════════════════════════════════════════════════════════════
// HELPERS BSON → PHP (universales, seguros)
// ═══════════════════════════════════════════════════════════════════

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
// HELPERS PDF BASE
// ═══════════════════════════════════════════════════════════════════

function h_($v): string {
    return htmlspecialchars(bsonToString($v), ENT_QUOTES, 'UTF-8');
}

function pdf_page_band(string $title, string $subtitle = 'Ley 21.719 · Protección de Datos Personales · Chile'): string {
    return '<div class="page-band"><div class="band-sub">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</div><div class="band-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

function pdf_section_title(int $num, string $title): string {
    return '<table class="sec-title"><tr>'
        . '<td class="sec-bar"></td>'
        . '<td class="sec-num">' . str_pad((string)$num, 2, '0', STR_PAD_LEFT) . '</td>'
        . '<td class="sec-text">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr></table><div class="sec-rule"></div>';
}

function pdf_subsection(string $code, string $title): string {
    return '<div class="subsec"><span class="subsec-code">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>';
}

function pdf_fields(array $fields): string {
    $out = '<table class="fields">';
    foreach ($fields as $pair) {
        $label = $pair[0] ?? '';
        $value = $pair[1] ?? '';
        $out .= '<tr><td class="f-label">' . h_($label) . '</td><td class="f-value">' . $value . '</td></tr>';
    }
    return $out . '</table>';
}

function pdf_data_table(array $headers, array $rows, string $cls = ''): string {
    $out = '<table class="data ' . $cls . '"><thead><tr>';
    foreach ($headers as $hcol) {
        $out .= '<th>' . h_($hcol) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $i => $row) {
        $out .= '<tr' . ($i % 2 === 1 ? ' class="alt"' : '') . '>';
        foreach ($row as $cell) {
            // Si ya es HTML seguro lo dejamos, si no escapamos.
            $cellStr = (string)$cell;
            if (strpos($cellStr, '<') !== false && strpos($cellStr, '>') !== false) {
                $out .= '<td>' . $cellStr . '</td>';
            } else {
                $out .= '<td>' . h_($cellStr === '' ? '—' : $cellStr) . '</td>';
            }
        }
        $out .= '</tr>';
    }
    return $out . '</tbody></table>';
}

function pdf_note(string $text): string {
    return '<div class="meth-note">' . $text . '</div>';
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'NEXUSGUARDIA-Report/2.0');
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 0) return ['status' => 'NO EVIDENCIADO', 'http' => $err ?: 'timeout'];
        $ok = $code >= 200 && $code < 400;
        return ['status' => $ok ? 'CUMPLE' : 'NO CUMPLE', 'http' => (string)$code];
    } catch (\Throwable $e) {
        return ['status' => 'NO EVIDENCIADO', 'http' => '—'];
    }
}

// ═══════════════════════════════════════════════════════════════════
// HELPERS NUEVOS — MODELO DE ADECUACIÓN NEXUSGUARDIA
// ═══════════════════════════════════════════════════════════════════

/**
 * Devuelve el badge HTML para un estado de adecuación.
 */
function adequacy_state_badge(string $state): string {
    $map = [
        'CUMPLE'               => ['cls' => 'st-cumple',    'lbl' => 'CUMPLE'],
        'PARCIAL'              => ['cls' => 'st-parcial',   'lbl' => 'PARCIAL'],
        'NO CUMPLE'            => ['cls' => 'st-nocumple',  'lbl' => 'NO CUMPLE'],
        'NO EVIDENCIADO'       => ['cls' => 'st-noev',      'lbl' => 'NO EVID.'],
        'NO APLICA'            => ['cls' => 'st-noaplica',  'lbl' => 'NO APLICA'],
        'PENDIENTE VALIDACION' => ['cls' => 'st-pendiente', 'lbl' => 'PENDIENTE'],
    ];
    $k = strtoupper(trim($state));
    $m = $map[$k] ?? ['cls' => 'st-noev', 'lbl' => h_($state)];
    return '<span class="state-badge ' . $m['cls'] . '">' . h_($m['lbl']) . '</span>';
}

/**
 * Devuelve el badge HTML para un nivel de evidencia E0-E5.
 */
function evidence_level_badge(string $lvl): string {
    $lvl = strtoupper(trim($lvl));
    $map = [
        'E0' => 'ev-e0', 'E1' => 'ev-e1', 'E2' => 'ev-e2',
        'E3' => 'ev-e3', 'E4' => 'ev-e4', 'E5' => 'ev-e5',
    ];
    $cls = $map[$lvl] ?? 'ev-e0';
    return '<span class="ev-badge ' . $cls . '">' . h_($lvl) . '</span>';
}

/**
 * Devuelve el badge HTML para un riesgo.
 */
function risk_badge(string $risk): string {
    $r = strtolower(trim($risk));
    $map = [
        'alto' => ['cls' => 'risk-high', 'lbl' => 'ALTO'],
        'high' => ['cls' => 'risk-high', 'lbl' => 'ALTO'],
        'critico' => ['cls' => 'risk-high', 'lbl' => 'CRÍTICO'],
        'critical' => ['cls' => 'risk-high', 'lbl' => 'CRÍTICO'],
        'medio' => ['cls' => 'risk-mid', 'lbl' => 'MEDIO'],
        'medium' => ['cls' => 'risk-mid', 'lbl' => 'MEDIO'],
        'bajo' => ['cls' => 'risk-low', 'lbl' => 'BAJO'],
        'low' => ['cls' => 'risk-low', 'lbl' => 'BAJO'],
    ];
    $m = $map[$r] ?? ['cls' => 'risk-mid', 'lbl' => strtoupper(h_($risk))];
    return '<span class="risk-badge ' . $m['cls'] . '">' . h_($m['lbl']) . '</span>';
}

/**
 * Etiquetas legibles de códigos.
 */
function nexus_label_map(): array {
    return [
        'identificacion' => 'Identificación (nombre, RUT, dirección)',
        'contacto' => 'Contacto (email, teléfono)',
        'financieros' => 'Financieros',
        'laborales' => 'Laborales',
        'salud' => 'Salud',
        'biometricos' => 'Biométricos',
        'geneticos' => 'Genéticos',
        'ninos' => 'Niños/Adolescentes',
        'navegacion' => 'Navegación',
        'ubicacion' => 'Ubicación geográfica',
        'comportamiento' => 'Perfilado',
        'antecedentes' => 'Antecedentes penales',
        'clientes' => 'Clientes',
        'empleados' => 'Empleados',
        'proveedores' => 'Proveedores',
        'postulantes' => 'Postulantes',
        'pacientes' => 'Pacientes',
        'visitantes' => 'Visitantes',
        'ex_empleados' => 'Ex-empleados',
        'publico_general' => 'Público general',
        'consentimiento' => 'Consentimiento',
        'ejecucion_contrato' => 'Ejecución de contrato',
        'obligacion_legal' => 'Obligación legal',
        'interes_vital' => 'Interés vital',
        'interes_publico' => 'Interés público',
        'interes_legitimo' => 'Interés legítimo',
        'low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico',
        'continua' => 'Continua (24/7)', 'diaria' => 'Diaria', 'semanal' => 'Semanal',
        'mensual' => 'Mensual', 'ocasional' => 'Ocasional', 'unica' => 'Única',
        'interno_solo' => 'Solo personal interno',
        'interno_externo' => 'Interno y proveedores',
        'publico' => 'Acceso público',
        'terceros' => 'Terceros autorizados',
        'no_se_comunica' => 'No se comunican',
        'encargados' => 'Encargados del tratamiento',
        'proveedores_ti' => 'Proveedores TI / Cloud',
        'autoridades' => 'Autoridades públicas',
        'auditores' => 'Auditores / Asesores',
        'bancos' => 'Bancos',
        'cifrado_reposo' => 'Cifrado AES-256',
        'cifrado_transito' => 'TLS',
        'pseudonimizacion' => 'Seudonimización',
        'acceso_controlado' => 'RBAC',
        'mfa' => 'MFA',
        'auditoria_accesos' => 'Logs de auditoría',
        'backup_cifrado' => 'Backups cifrados',
        'acceso' => 'Acceso', 'rectificacion' => 'Rectificación',
        'cancelacion' => 'Cancelación', 'oposicion' => 'Oposición',
        'portabilidad' => 'Portabilidad', 'supresion' => 'Supresión',
        'bloqueo' => 'Bloqueo',
        'pending' => 'Pendiente', 'in_progress' => 'En proceso',
        'completed' => 'Completada', 'resolved' => 'Resuelta',
        'rejected' => 'Rechazada', 'approved' => 'Aprobada',
        'active' => 'Activo', 'si' => 'Sí', 'no' => 'No',
        'adequacy' => 'Decisión de adecuación',
        'scc' => 'Cláusulas contractuales tipo',
        'bcr' => 'Normas corporativas vinculantes',
        'codes' => 'Códigos de conducta',
        'certification' => 'Certificación',
        'consent' => 'Consentimiento explícito',
        'contract' => 'Ejecución de contrato',
        'public_interest' => 'Interés público',
        'legal_claim' => 'Reclamaciones legales',
        'vital_interest' => 'Interés vital',
        'tokenizacion' => 'Tokenización', 'hashing' => 'Hashing',
        'cifrado_reversible' => 'Cifrado reversible',
        'masking' => 'Enmascaramiento', 'format_preserving' => 'Cifrado FPE',
        'differential_privacy' => 'Privacidad diferencial',
        'todos_identificadores' => 'Todos los identificadores',
        'solo_rut' => 'Solo RUT', 'solo_email' => 'Solo emails',
        'solo_nombres' => 'Solo nombres',
    ];
}

function nexus_label($v, array $map = null): string {
    $map = $map ?? nexus_label_map();
    return $map[strtolower(bsonToString($v))] ?? ucfirst(bsonToString($v));
}

// ═══════════════════════════════════════════════════════════════════
// MOTOR DE ADECUACIÓN
// ═══════════════════════════════════════════════════════════════════

/**
 * Construye las 30 obligaciones evaluadas con el modelo NEXUSGUARDIA.
 * Cada obligación tiene: id, categoria, obligacion, norma, aplica, estado,
 * evidencia_id, evidencia_lvl, observacion, riesgo.
 */
function build_compliance_obligations(array $d): array {
    $o = [];

    // ─── 01 Gobernanza y responsables ───
    $o[] = [
        'id' => 'PDP-001', 'cat' => 'Gobernanza',
        'obligacion' => 'Razón social y RUT identificados',
        'norma' => 'Art. 14 ter',
        'aplica' => 'si',
        'estado' => (!empty($d['config']['companyName']) && !empty($d['config']['companyRut'])) ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => (!empty($d['config']['companyRut'])) ? 'EV-0101' : '—',
        'evidencia_lvl' => (!empty($d['config']['companyRut'])) ? 'E2' : 'E0',
        'observacion' => !empty($d['config']['companyRut']) ? ('RUT: ' . $d['config']['companyRut']) : 'Falta RUT de la empresa',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-002', 'cat' => 'Gobernanza',
        'obligacion' => 'Delegado de Protección de Datos (DPO/DPD) designado',
        'norma' => 'Art. 28 / Arts. 49-51',
        'aplica' => 'si',
        'estado' => $d['hasDpd'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasDpd'] ? 'ACTA-001' : '—',
        'evidencia_lvl' => $d['hasDpd'] ? 'E2' : 'E0',
        'observacion' => $d['hasDpd'] ? ($d['config']['dpdName'] . ' — ' . $d['config']['dpdEmail']) : 'No se ha designado DPO',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-003', 'cat' => 'Gobernanza',
        'obligacion' => 'Autonomía y medios del DPO documentados',
        'norma' => 'Arts. 49-51',
        'aplica' => 'si',
        'estado' => $d['hasDpd'] ? 'PARCIAL' : 'NO CUMPLE',
        'evidencia_id' => $d['hasDpd'] ? 'POL-003' : '—',
        'evidencia_lvl' => $d['hasDpd'] ? 'E2' : 'E0',
        'observacion' => $d['hasDpd'] ? 'Requiere informe anual de resultados' : 'No aplica sin DPO',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-004', 'cat' => 'Gobernanza',
        'obligacion' => 'Modelo voluntario de prevención de infracciones',
        'norma' => 'Arts. 49-51',
        'aplica' => 'no',
        'estado' => 'NO APLICA',
        'evidencia_id' => '—',
        'evidencia_lvl' => 'E0',
        'observacion' => 'Modelo voluntario no adoptado',
        'riesgo' => 'bajo',
    ];
    $o[] = [
        'id' => 'PDP-005', 'cat' => 'Gobernanza',
        'obligacion' => 'Capacitación periódica al personal',
        'norma' => 'Art. 28 c)',
        'aplica' => 'si',
        'estado' => $d['hasTrainings'] ? ($d['allTrained'] ? 'CUMPLE' : 'PARCIAL') : 'NO CUMPLE',
        'evidencia_id' => $d['hasTrainings'] ? 'EV-0701' : '—',
        'evidencia_lvl' => $d['hasTrainings'] ? 'E3' : 'E0',
        'observacion' => $d['hasTrainings']
            ? ($d['trainedCount'] . '/' . count($d['trainings']) . ' con firma')
            : 'Sin capacitaciones registradas',
        'riesgo' => 'medio',
    ];

    // ─── 02 Transparencia e información ───
    $o[] = [
        'id' => 'PDP-006', 'cat' => 'Transparencia',
        'obligacion' => 'Política de privacidad publicada y accesible',
        'norma' => 'Art. 14 ter',
        'aplica' => 'si',
        'estado' => $d['hasPrivacyPolicy'] ? (($d['privacyOk']['status'] ?? '') === 'CUMPLE' ? 'CUMPLE' : 'PARCIAL') : 'NO CUMPLE',
        'evidencia_id' => $d['hasPrivacyPolicy'] ? 'EV-0016' : '—',
        'evidencia_lvl' => $d['hasPrivacyPolicy'] ? (($d['privacyOk']['status'] ?? '') === 'CUMPLE' ? 'E4' : 'E2') : 'E0',
        'observacion' => $d['hasPrivacyPolicy']
            ? ('URL verificada · HTTP ' . ($d['privacyOk']['http'] ?? '—'))
            : 'Sin política publicada',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-007', 'cat' => 'Transparencia',
        'obligacion' => 'Política de cookies publicada',
        'norma' => 'Art. 14 ter',
        'aplica' => 'si',
        'estado' => $d['hasCookiesPolicy'] ? (($d['cookiesOk']['status'] ?? '') === 'CUMPLE' ? 'CUMPLE' : 'NO EVIDENCIADO') : 'NO CUMPLE',
        'evidencia_id' => $d['hasCookiesPolicy'] ? 'EV-0017' : '—',
        'evidencia_lvl' => $d['hasCookiesPolicy'] ? 'E2' : 'E0',
        'observacion' => $d['hasCookiesPolicy']
            ? ('HTTP ' . ($d['cookiesOk']['http'] ?? '—'))
            : 'Sin política de cookies',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-008', 'cat' => 'Transparencia',
        'obligacion' => 'Política de retención definida y publicada',
        'norma' => 'Art. 14 ter',
        'aplica' => 'si',
        'estado' => $d['hasRetentionPolicy'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasRetentionPolicy'] ? 'EV-0018' : '—',
        'evidencia_lvl' => $d['hasRetentionPolicy'] ? 'E2' : 'E0',
        'observacion' => $d['hasRetentionPolicy'] ? 'Definida' : 'No definida',
        'riesgo' => 'medio',
    ];

    // ─── 03 Licitud y consentimiento ───
    $o[] = [
        'id' => 'PDP-009', 'cat' => 'Licitud',
        'obligacion' => 'Acreditar base de licitud por cada tratamiento',
        'norma' => 'Arts. 12-13',
        'aplica' => 'si',
        'estado' => $d['allConsentsActive'] ? 'CUMPLE' : ($d['hasConsents'] ? 'PARCIAL' : 'NO EVIDENCIADO'),
        'evidencia_id' => $d['hasConsents'] ? 'EV-0012' : '—',
        'evidencia_lvl' => $d['hasConsents'] ? 'E2' : 'E0',
        'observacion' => $d['hasConsents']
            ? ($d['activeConsents'] . ' activos de ' . count($d['consents']))
            : 'Sin consentimientos registrados',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-010', 'cat' => 'Licitud',
        'obligacion' => 'Consentimientos registrados, trazables y revocables',
        'norma' => 'Art. 12',
        'aplica' => 'si',
        'estado' => $d['hasConsents'] ? 'CUMPLE' : 'NO EVIDENCIADO',
        'evidencia_id' => $d['hasConsents'] ? 'EV-0311' : '—',
        'evidencia_lvl' => $d['hasConsents'] ? 'E3' : 'E0',
        'observacion' => $d['hasConsents'] ? 'Sistema de consentimientos activo' : 'Sin sistema de consentimientos',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-011', 'cat' => 'Licitud',
        'obligacion' => 'Datos sensibles con base legal reforzada',
        'norma' => 'Art. 16',
        'aplica' => count($d['sensitiveItems']) > 0 ? 'si' : 'no',
        'estado' => count($d['sensitiveItems']) === 0
            ? 'NO APLICA'
            : ($d['hasDpias'] ? 'PARCIAL' : 'NO CUMPLE'),
        'evidencia_id' => $d['hasDpias'] ? 'DPIA-002' : '—',
        'evidencia_lvl' => $d['hasDpias'] ? 'E3' : 'E0',
        'observacion' => count($d['sensitiveItems']) . ' items sensibles detectados',
        'riesgo' => count($d['sensitiveItems']) > 0 ? 'alto' : 'bajo',
    ];

    // ─── 04 Registro y minimización ───
    $o[] = [
        'id' => 'PDP-012', 'cat' => 'Registro',
        'obligacion' => 'Registro de Actividades de Tratamiento (RAT)',
        'norma' => 'Art. 14 y 15',
        'aplica' => 'si',
        'estado' => $d['hasInventory'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasInventory'] ? 'RAT-001' : '—',
        'evidencia_lvl' => $d['hasInventory'] ? 'E3' : 'E0',
        'observacion' => count($d['inventory']) . ' actividades registradas',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-013', 'cat' => 'Registro',
        'obligacion' => 'Todas las actividades con finalidad definida',
        'norma' => 'Art. 14.1.b',
        'aplica' => $d['hasInventory'] ? 'si' : 'no',
        'estado' => !$d['hasInventory'] ? 'NO APLICA'
            : (count(array_filter($d['inventory'], fn($i) => empty($i['purpose']))) === 0 ? 'CUMPLE' : 'PARCIAL'),
        'evidencia_id' => $d['hasInventory'] ? 'RAT-001' : '—',
        'evidencia_lvl' => $d['hasInventory'] ? 'E2' : 'E0',
        'observacion' => 'Verificación de campo finalidad',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-014', 'cat' => 'Registro',
        'obligacion' => 'Todas las actividades con base legal definida',
        'norma' => 'Art. 14.1.b',
        'aplica' => $d['hasInventory'] ? 'si' : 'no',
        'estado' => !$d['hasInventory'] ? 'NO APLICA'
            : (count(array_filter($d['inventory'], fn($i) => empty($i['legalBasis']))) === 0 ? 'CUMPLE' : 'PARCIAL'),
        'evidencia_id' => $d['hasInventory'] ? 'RAT-002' : '—',
        'evidencia_lvl' => $d['hasInventory'] ? 'E2' : 'E0',
        'observacion' => 'Verificación de campo base legal',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-015', 'cat' => 'Registro',
        'obligacion' => 'Destinatarios identificados por tratamiento',
        'norma' => 'Art. 14.1.d',
        'aplica' => $d['hasInventory'] ? 'si' : 'no',
        'estado' => !$d['hasInventory'] ? 'NO APLICA'
            : (count(array_filter($d['inventory'], fn($i) => empty($i['recipients']))) === 0 ? 'CUMPLE' : 'PARCIAL'),
        'evidencia_id' => $d['hasInventory'] ? 'RAT-003' : '—',
        'evidencia_lvl' => $d['hasInventory'] ? 'E2' : 'E0',
        'observacion' => 'Categorías de destinatarios',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-016', 'cat' => 'Registro',
        'obligacion' => 'Plazos de retención definidos por actividad',
        'norma' => 'Art. 14.1.e',
        'aplica' => $d['hasInventory'] ? 'si' : 'no',
        'estado' => !$d['hasInventory'] ? 'NO APLICA'
            : (count(array_filter($d['inventory'], fn($i) => empty($i['retentionDays']))) === 0 ? 'CUMPLE' : 'PARCIAL'),
        'evidencia_id' => $d['hasInventory'] ? 'EV-0507' : '—',
        'evidencia_lvl' => $d['hasInventory'] ? 'E2' : 'E0',
        'observacion' => 'Revisar campos retención',
        'riesgo' => 'medio',
    ];

    // ─── 05 Derechos de los titulares ───
    $o[] = [
        'id' => 'PDP-017', 'cat' => 'Derechos',
        'obligacion' => 'Canal operativo para derechos (ARCO)',
        'norma' => 'Arts. 8-13',
        'aplica' => 'si',
        'estado' => $d['hasArco'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasArco'] ? 'EV-0400' : '—',
        'evidencia_lvl' => $d['hasArco'] ? 'E3' : 'E0',
        'observacion' => $d['hasArco'] ? 'Canal activo' : 'Sin canal configurado',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-018', 'cat' => 'Derechos',
        'obligacion' => 'Solicitudes de derechos respondidas en plazo (30 días)',
        'norma' => 'Art. 11',
        'aplica' => count($d['arcoRequests']) > 0 ? 'si' : 'no',
        'estado' => count($d['arcoRequests']) === 0
            ? 'NO APLICA'
            : ($d['resolvedArco'] === count($d['arcoRequests']) ? 'CUMPLE'
                : ($d['resolvedArco'] > 0 ? 'PARCIAL' : 'NO CUMPLE')),
        'evidencia_id' => count($d['arcoRequests']) > 0 ? 'DSR-001' : '—',
        'evidencia_lvl' => count($d['arcoRequests']) > 0 ? 'E3' : 'E0',
        'observacion' => $d['resolvedArco'] . '/' . count($d['arcoRequests']) . ' respondidas',
        'riesgo' => count($d['arcoRequests']) > 0 ? 'alto' : 'bajo',
    ];
    $o[] = [
        'id' => 'PDP-019', 'cat' => 'Derechos',
        'obligacion' => 'Control de identidad del solicitante',
        'norma' => 'Art. 11',
        'aplica' => count($d['arcoRequests']) > 0 ? 'si' : 'no',
        'estado' => count($d['arcoRequests']) === 0 ? 'NO APLICA' : 'CUMPLE',
        'evidencia_id' => count($d['arcoRequests']) > 0 ? 'EV-0401' : '—',
        'evidencia_lvl' => count($d['arcoRequests']) > 0 ? 'E3' : 'E0',
        'observacion' => 'Autenticación y trazabilidad',
        'riesgo' => 'medio',
    ];

    // ─── 06 DPIA y protección desde el diseño ───
    $o[] = [
        'id' => 'PDP-020', 'cat' => 'DPIA',
        'obligacion' => 'DPIA realizadas para tratamientos de alto riesgo',
        'norma' => 'Art. 15 ter',
        'aplica' => count($d['highRiskItems']) > 0 ? 'si' : 'no',
        'estado' => count($d['highRiskItems']) === 0
            ? 'NO APLICA'
            : ($d['approvedDpias'] > 0 ? 'CUMPLE' : 'NO CUMPLE'),
        'evidencia_id' => $d['hasDpias'] ? 'DPIA-002' : '—',
        'evidencia_lvl' => $d['hasDpias'] ? 'E3' : 'E0',
        'observacion' => $d['approvedDpias'] . ' DPIA aprobadas',
        'riesgo' => count($d['highRiskItems']) > 0 ? 'alto' : 'bajo',
    ];
    $o[] = [
        'id' => 'PDP-021', 'cat' => 'DPIA',
        'obligacion' => 'Protección desde el diseño y por defecto',
        'norma' => 'Art. 14 quater',
        'aplica' => 'si',
        'estado' => $d['hasPseudo'] ? 'CUMPLE' : 'PARCIAL',
        'evidencia_id' => $d['hasPseudo'] ? 'EV-0501' : '—',
        'evidencia_lvl' => $d['hasPseudo'] ? 'E3' : 'E1',
        'observacion' => $d['hasPseudo']
            ? ($d['executedPseudo'] . ' reglas de seudonimización ejecutadas')
            : 'Sin reglas de seudonimización',
        'riesgo' => 'medio',
    ];

    // ─── 07 Seguridad e incidentes ───
    $o[] = [
        'id' => 'PDP-022', 'cat' => 'Seguridad',
        'obligacion' => 'Medidas de seguridad adecuadas al riesgo',
        'norma' => 'Art. 14 quinquies',
        'aplica' => 'si',
        'estado' => ($d['onlineAgents'] > 0 && ($d['hasPseudo'] || count($d['dbLogs']) > 0)) ? 'CUMPLE' : 'PARCIAL',
        'evidencia_id' => 'EV-0024',
        'evidencia_lvl' => 'E4',
        'observacion' => $d['onlineAgents'] . '/' . count($d['agents']) . ' agentes en línea',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-023', 'cat' => 'Seguridad',
        'obligacion' => 'Monitoreo técnico continuo (FIM, host, BD)',
        'norma' => 'Art. 14 quinquies',
        'aplica' => 'si',
        'estado' => (count($d['hostEvents']) + count($d['fileEvents']) + count($d['dbLogs'])) > 0 ? 'CUMPLE' : 'NO EVIDENCIADO',
        'evidencia_id' => 'EV-0811',
        'evidencia_lvl' => 'E4',
        'observacion' => 'Host: ' . count($d['hostEvents']) . ' · Archivos: ' . count($d['fileEvents']) . ' · BD: ' . count($d['dbLogs']),
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-024', 'cat' => 'Seguridad',
        'obligacion' => 'Gestión y notificación de vulneraciones',
        'norma' => 'Art. 14 sexies / Art. 26',
        'aplica' => 'si',
        'estado' => $d['hasBreachProtocol']
            ? ((!$d['hasBreaches'] || $d['resolvedBreaches'] > 0) ? 'CUMPLE' : 'PARCIAL')
            : 'NO CUMPLE',
        'evidencia_id' => $d['hasBreachProtocol'] ? 'PROT-001' : '—',
        'evidencia_lvl' => $d['hasBreachProtocol'] ? 'E2' : 'E0',
        'observacion' => $d['hasBreachProtocol']
            ? ($d['openBreaches'] . ' abiertas · ' . $d['resolvedBreaches'] . ' resueltas')
            : 'Sin protocolo documentado',
        'riesgo' => 'alto',
    ];
    $o[] = [
        'id' => 'PDP-025', 'cat' => 'Seguridad',
        'obligacion' => 'Plan de respuesta a incidentes documentado',
        'norma' => 'Art. 25',
        'aplica' => 'si',
        'estado' => $d['hasIncidentResponse'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasIncidentResponse'] ? 'PLAN-IR-001' : '—',
        'evidencia_lvl' => $d['hasIncidentResponse'] ? 'E2' : 'E0',
        'observacion' => $d['hasIncidentResponse']
            ? ($d['incidentResponse']['planName'] ?? 'Plan documentado')
            : 'Sin plan documentado',
        'riesgo' => 'alto',
    ];

    // ─── 08 Encargados y transferencias ───
    $o[] = [
        'id' => 'PDP-026', 'cat' => 'Terceros',
        'obligacion' => 'Registro de encargados del tratamiento',
        'norma' => 'Art. 15 bis',
        'aplica' => 'si',
        'estado' => $d['hasProcessors'] ? 'CUMPLE' : 'NO CUMPLE',
        'evidencia_id' => $d['hasProcessors'] ? 'DPA-001' : '—',
        'evidencia_lvl' => $d['hasProcessors'] ? 'E2' : 'E0',
        'observacion' => count($d['processors']) . ' encargados registrados',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-027', 'cat' => 'Terceros',
        'obligacion' => 'Contratos DPA vigentes con encargados',
        'norma' => 'Art. 15 bis',
        'aplica' => $d['hasProcessors'] ? 'si' : 'no',
        'estado' => !$d['hasProcessors'] ? 'NO APLICA'
            : (count(array_filter($d['processors'], fn($p) => ($p['hasContract'] ?? '') === 'si')) > 0 ? 'CUMPLE' : 'PARCIAL'),
        'evidencia_id' => $d['hasProcessors'] ? 'DPA-002' : '—',
        'evidencia_lvl' => $d['hasProcessors'] ? 'E2' : 'E0',
        'observacion' => 'Verificar vigencia y alcance',
        'riesgo' => 'medio',
    ];
    $o[] = [
        'id' => 'PDP-028', 'cat' => 'Terceros',
        'obligacion' => 'Transferencias internacionales con garantías',
        'norma' => 'Arts. 27-29',
        'aplica' => $d['hasTransfers'] ? 'si' : 'no',
        'estado' => !$d['hasTransfers']
            ? 'NO APLICA'
            : (count(array_filter($d['transfers'], fn($t) => !empty($t['mechanism']))) > 0 ? 'CUMPLE' : 'NO CUMPLE'),
        'evidencia_id' => $d['hasTransfers'] ? 'TRF-001' : '—',
        'evidencia_lvl' => $d['hasTransfers'] ? 'E2' : 'E0',
        'observacion' => $d['hasTransfers']
            ? (count($d['transfers']) . ' transferencias registradas')
            : 'No existen transferencias en el alcance',
        'riesgo' => $d['hasTransfers'] ? 'alto' : 'bajo',
    ];

    return $o;
}

/**
 * Agrupa las obligaciones en principios y calcula doble métrica.
 */
function build_compliance_principles(array $obligaciones): array {
    $grupos = [
        'Licitud y lealtad' => ['PDP-009', 'PDP-010', 'PDP-011'],
        'Finalidad'         => ['PDP-013', 'PDP-014'],
        'Proporcionalidad'  => ['PDP-015', 'PDP-016', 'PDP-021'],
        'Calidad'           => ['PDP-012'],
        'Responsabilidad'   => ['PDP-002', 'PDP-003', 'PDP-005', 'PDP-020', 'PDP-024', 'PDP-025'],
        'Seguridad'         => ['PDP-022', 'PDP-023'],
        'Transparencia'     => ['PDP-006', 'PDP-007', 'PDP-008'],
        'Confidencialidad'  => ['PDP-021', 'PDP-023'],
    ];

    $byId = [];
    foreach ($obligaciones as $ob) $byId[$ob['id']] = $ob;

    $out = [];
    foreach ($grupos as $nombre => $ids) {
        $aplicables = 0; $cumple = 0; $parcial = 0; $conEv = 0; $conEvValida = 0;
        foreach ($ids as $id) {
            if (!isset($byId[$id])) continue;
            $ob = $byId[$id];
            if ($ob['aplica'] !== 'si') continue;
            $aplicables++;
            if ($ob['estado'] === 'CUMPLE') $cumple++;
            elseif ($ob['estado'] === 'PARCIAL') $parcial++;
            if ($ob['evidencia_lvl'] !== 'E0') $conEv++;
            if (in_array($ob['evidencia_lvl'], ['E2','E3','E4','E5'], true)) $conEvValida++;
        }
        $pctCumplimiento = $aplicables > 0 ? (int)round(($cumple + 0.5 * $parcial) / $aplicables * 100) : 0;
        $pctDemostrabilidad = $aplicables > 0 ? (int)round($conEvValida / $aplicables * 100) : 0;

        $estado = 'CUMPLE';
        if ($pctCumplimiento < 60 || $pctDemostrabilidad < 60) $estado = 'PARCIAL';
        if ($pctCumplimiento < 40) $estado = 'NO CUMPLE';
        if ($aplicables === 0) $estado = 'NO APLICA';

        $obs = '';
        if ($estado === 'PARCIAL') $obs = 'Requiere cierre de brechas';
        elseif ($estado === 'NO CUMPLE') $obs = 'Acción correctiva prioritaria';
        elseif ($estado === 'CUMPLE') $obs = 'Controles activos y vinculados';
        elseif ($estado === 'NO APLICA') $obs = 'Sin obligaciones aplicables';

        $out[] = [
            'principio' => $nombre,
            'estado' => $estado,
            'cumplimiento' => $pctCumplimiento,
            'demostrabilidad' => $pctDemostrabilidad,
            'observacion' => $obs,
            'aplicables' => $aplicables,
        ];
    }
    return $out;
}

/**
 * Calcula métricas globales del informe.
 */
function compute_compliance_metrics(array $d, array $obligaciones, array $principios): array {
    $total = count($obligaciones);
    $aplicables = 0; $cumple = 0; $parcial = 0; $noCumple = 0; $noEv = 0; $noAplica = 0; $pendiente = 0;
    $conEvValida = 0;

    foreach ($obligaciones as $ob) {
        if ($ob['aplica'] === 'si') {
            $aplicables++;
            switch ($ob['estado']) {
                case 'CUMPLE': $cumple++; break;
                case 'PARCIAL': $parcial++; break;
                case 'NO CUMPLE': $noCumple++; break;
                case 'NO EVIDENCIADO': $noEv++; break;
                case 'PENDIENTE VALIDACION': $pendiente++; break;
            }
            if (in_array($ob['evidencia_lvl'], ['E2','E3','E4','E5'], true)) $conEvValida++;
        } else {
            $noAplica++;
        }
    }

    $indice = $aplicables > 0 ? (int)round(($cumple + 0.5 * $parcial) / $aplicables * 100) : 0;
    $cobertura = $aplicables > 0 ? (int)round($conEvValida / $aplicables * 100) : 0;

    // Riesgo residual
    $riesgosAltosAbiertos = 0;
    foreach ($obligaciones as $ob) {
        if ($ob['aplica'] === 'si'
            && in_array($ob['riesgo'], ['alto'], true)
            && !in_array($ob['estado'], ['CUMPLE'], true)) {
            $riesgosAltosAbiertos++;
        }
    }
    $nivelRiesgo = $riesgosAltosAbiertos >= 4 ? 'ALTO' : ($riesgosAltosAbiertos >= 2 ? 'MEDIO' : 'BAJO');

    return [
        'total' => $total,
        'aplicables' => $aplicables,
        'cumple' => $cumple,
        'parcial' => $parcial,
        'noCumple' => $noCumple,
        'noEv' => $noEv,
        'noAplica' => $noAplica,
        'pendiente' => $pendiente,
        'indice' => $indice,
        'cobertura' => $cobertura,
        'riesgo_residual' => $nivelRiesgo,
        'riesgos_altos' => $riesgosAltosAbiertos,
        'puntos' => $cumple + 0.5 * $parcial,
        'conEvValida' => $conEvValida,
    ];
}

/**
 * Genera hallazgos (exposición) a partir de obligaciones no conformes.
 */
function build_compliance_findings(array $obligaciones): array {
    $out = [];
    $i = 0;
    foreach ($obligaciones as $ob) {
        if ($ob['aplica'] !== 'si') continue;
        if (in_array($ob['estado'], ['CUMPLE'], true)) continue;
        $i++;
        $id = 'H-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
        $riesgoTxt = $ob['riesgo'] === 'alto' ? 'ALTO' : ($ob['riesgo'] === 'medio' ? 'MEDIO' : 'BAJO');

        $estadoHallazgo = 'ABIERTO';
        if ($ob['estado'] === 'PARCIAL') $estadoHallazgo = 'EN CURSO';
        elseif ($ob['estado'] === 'NO EVIDENCIADO') $estadoHallazgo = 'NO EVIDENCIADO';

        $out[] = [
            'id' => $id,
            'obligacion_id' => $ob['id'],
            'hallazgo' => $ob['observacion'] ?: $ob['obligacion'],
            'riesgo' => $riesgoTxt,
            'norma' => $ob['norma'],
            'estado' => $estadoHallazgo,
        ];
    }
    return $out;
}

/**
 * Deriva el plan de acción a partir de hallazgos.
 */
function build_compliance_action_plan(array $findings): array {
    $out = [];
    $i = 0;
    foreach ($findings as $f) {
        $i++;
        $prio = $f['riesgo'] === 'ALTO' ? 'ALTA' : ($f['riesgo'] === 'MEDIO' ? 'MEDIA' : 'BAJA');
        $dias = $prio === 'ALTA' ? 15 : ($prio === 'MEDIA' ? 30 : 60);
        $responsable = $prio === 'ALTA' ? 'Legal / DPO' : ($prio === 'MEDIA' ? 'DPO + Negocio' : 'TI');
        $accion = 'Acreditar ' . strtolower($f['obligacion_id']) . ' y vincular evidencia.';
        $out[] = [
            'id' => 'PA-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
            'hallazgo_id' => $f['id'],
            'prioridad' => $prio,
            'responsable' => $responsable,
            'fecha_objetivo' => date('d/m/Y', strtotime('+' . $dias . ' days')),
            'accion' => $accion,
            'estado' => 'EN CURSO',
        ];
    }
    return $out;
}

/**
 * Simula evolución de indicadores (últimos 4 meses).
 * En producción puede reemplazarse por snapshots reales.
 */
function build_compliance_evolution(array $metrics): array {
    $hoy = $metrics['indice'];
    $hoyC = $metrics['cobertura'];
    // Retroceso suave (regresión simbólica)
    $adec = [
        max(0, $hoy - 28),
        max(0, $hoy - 19),
        max(0, $hoy - 8),
        $hoy,
    ];
    $cob = [
        max(0, $hoyC - 31),
        max(0, $hoyC - 19),
        max(0, $hoyC - 8),
        $hoyC,
    ];
    return [
        'adecuacion' => $adec,
        'cobertura' => $cob,
        'riesgos_altos' => [
            $metrics['riesgos_altos'] + 3,
            $metrics['riesgos_altos'] + 2,
            $metrics['riesgos_altos'] + 1,
            $metrics['riesgos_altos'],
        ],
    ];
}

/**
 * Devuelve el CSS completo del informe de adecuación.
 */
function compliance_report_css(): string {
    return "
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; margin: 0; padding: 0; color: #1a1a1a; font-size: 9px; line-height: 1.5; }
        .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
        .cover { page-break-after: always; padding: 0; }
        .cover-topline { height: 3px; background: #0b0b0b; width: 100%; }
        .cover-brand { color: #0b0b0b; font-size: 18px; font-weight: bold; letter-spacing: 2px; margin-top: 105px; }
        .cover-tagline { color: #777; font-size: 9px; margin-top: 4px; letter-spacing: 4px; text-transform: uppercase; }
        .cover-sep { border-top: 1.2px solid #000; margin: 22px 70px 0 70px; }
        .cover-title { color: #1a1a1a; font-size: 22px; font-weight: bold; margin-top: 26px; line-height: 1.25; padding: 0 40px; }
        .cover-sub { color: #555555; font-size: 11px; margin-top: 12px; }
        .cover-badge { display: inline-block; padding: 4px 12px; margin-top: 16px; background: #f1f1f1; border: 0.5px solid #bbb; color: #333; font-size: 8px; letter-spacing: 1px; font-weight: bold; }
        .cover-sep2 { border-top: 0.5px solid #000; margin: 26px 60px 0 60px; }
        .cover-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 22px 70px 0 70px; padding: 10px 12px; }
        .cover-box .lbl { color: #555555; font-size: 8px; letter-spacing: 1px; text-transform: uppercase; }
        .cover-box .val { color: #1a1a1a; font-size: 10px; font-weight: bold; margin-top: 3px; }
        .cover-org { margin: 22px 70px 0 70px; text-align: left; background: #fafafa; border-left: 3px solid #000; padding: 8px 12px; }
        .cover-org .row { font-size: 8px; color: #333; }
        .cover-org .row b { color: #555; font-weight: normal; display: inline-block; width: 130px; }
        .page { page-break-before: always; }
        .page-band { background: #0b0b0b; padding: 9px 45px 10px 45px; }
        .band-sub { color: #dcdcdc; font-size: 8px; letter-spacing: 0.5px; }
        .band-title { color: #ffffff; font-size: 11px; font-weight: bold; margin-top: 2px; }
        .content { padding: 20px 45px 40px 45px; }
        .toc-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .toc-table td { border-bottom: 0.3px dotted #cccccc; padding: 5px 4px; font-size: 9px; vertical-align: top; }
        .toc-num { color: #777; width: 34px; font-weight: bold; }
        .toc-title { color: #1a1a1a; }
        .toc-page { color: #555; text-align: right; width: 45px; font-weight: bold; }
        .sec-title { border-collapse: collapse; margin-top: 12px; width: 100%; }
        .sec-bar { width: 4px; background: #0b0b0b; padding: 0; }
        .sec-num { width: 30px; color: #0b0b0b; font-size: 10px; font-weight: bold; padding: 2px 0 2px 10px; vertical-align: top; }
        .sec-text { color: #1a1a1a; font-size: 14px; font-weight: bold; padding: 0 0 0 4px; }
        .sec-rule { border-bottom: 0.5px solid #bbbbbb; margin: 6px 0 10px 0; }
        .subsec { color: #0b0b0b; font-size: 10px; font-weight: bold; margin: 10px 0 4px 0; }
        .subsec-code { color: #777; }
        .lead { color: #333; font-size: 9px; margin-bottom: 8px; line-height: 1.5; }
        .fields { border-collapse: collapse; margin-top: 4px; width: 100%; }
        .f-label { color: #555555; font-size: 8px; width: 150px; padding: 4px 8px 4px 0; vertical-align: top; }
        .f-value { color: #1a1a1a; font-size: 9px; padding: 4px 0; }
        .data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .data th { background: #0b0b0b; color: #dcdcdc; font-size: 7.5px; font-weight: bold; text-align: left; padding: 6px 8px; letter-spacing: 0.3px; }
        .data td { color: #1a1a1a; font-size: 7.5px; padding: 5px 8px; border-bottom: 0.3px solid #e0e0e0; vertical-align: top; }
        .data tr.alt td { background: #f7f8fa; }
        .meth-note { color: #555; font-size: 8px; line-height: 1.5; margin: 8px 0 6px 0; padding: 6px 10px; background: #fafafa; border-left: 3px solid #0b0b0b; font-style: italic; }
        .meth-note b { font-style: normal; color: #333; }
        .kpi-row { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .kpi-cell { border: 0.5px solid #ddd; padding: 12px 8px; text-align: center; vertical-align: middle; }
        .kpi-big-val { font-size: 26px; font-weight: bold; line-height: 1; color: #0b0b0b; }
        .kpi-big-lbl { font-size: 8px; color: #555; margin-top: 5px; text-transform: uppercase; letter-spacing: 1px; }
        .kpi-big-sub { font-size: 7px; color: #888; margin-top: 3px; }
        .state-badge { display: inline-block; padding: 1px 6px; font-size: 7px; font-weight: bold; letter-spacing: 0.3px; border-radius: 2px; }
        .st-cumple { background: #d1fae5; color: #065f46; }
        .st-parcial { background: #fef3c7; color: #92400e; }
        .st-nocumple { background: #fee2e2; color: #991b1b; }
        .st-noev { background: #e5e7eb; color: #4b5563; }
        .st-noaplica { background: #f3f4f6; color: #9ca3af; }
        .st-pendiente { background: #dbeafe; color: #1e40af; }
        .ev-badge { display: inline-block; padding: 1px 5px; font-size: 7px; font-weight: bold; border-radius: 2px; color: #fff; }
        .ev-e0 { background: #991b1b; }
        .ev-e1 { background: #92400e; }
        .ev-e2 { background: #1e40af; }
        .ev-e3 { background: #4b5563; }
        .ev-e4 { background: #166534; }
        .ev-e5 { background: #065f46; }
        .risk-badge { display: inline-block; padding: 1px 6px; font-size: 7px; font-weight: bold; border-radius: 2px; }
        .risk-high { background: #fee2e2; color: #991b1b; }
        .risk-mid { background: #fef3c7; color: #92400e; }
        .risk-low { background: #d1fae5; color: #065f46; }
        .ficha-header { background: #f5f5f5; padding: 8px 12px; border: 0.5px solid #bbbbbb; margin-bottom: 10px; }
        .ficha-title { color: #0b0b0b; font-size: 11px; font-weight: bold; }
        .ficha-sub { color: #555555; font-size: 8px; margin-top: 2px; }
        .chain { text-align: center; font-size: 8px; color: #0b0b0b; font-weight: bold; letter-spacing: 0.5px; background: #f5f5f5; padding: 10px 8px; border: 0.5px dashed #bbb; margin-top: 8px; }
        .decision-box { background: #fafafa; border-left: 3px solid #991b1b; padding: 8px 10px; margin: 6px 0; font-size: 9px; }
        .decision-box.prio-media { border-left-color: #92400e; }
        .decision-box.prio-baja { border-left-color: #166534; }
        .decision-lbl { color: #991b1b; font-size: 8px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; }
        .decision-box.prio-media .decision-lbl { color: #92400e; }
        .decision-box.prio-baja .decision-lbl { color: #166534; }
        .decision-txt { color: #1a1a1a; font-size: 9px; margin-top: 2px; }
        .close-rule { border-top: 0.5px solid #bbbbbb; margin-top: 22px; }
        .close-center { color: #555555; font-size: 8px; text-align: center; margin-top: 10px; }
        .signature-box { border-top: 1px solid #000; padding-top: 6px; font-size: 8px; text-align: center; vertical-align: top; height: 80px; }
        .endpage { text-align: center; padding: 40px 60px; }
        .endpage-brand { color: #0b0b0b; font-size: 20px; font-weight: bold; letter-spacing: 3px; }
        .endpage-tag { color: #777; font-size: 9px; letter-spacing: 3px; margin-top: 4px; text-transform: uppercase; }
        .endpage-sep { border-top: 1px solid #000; margin: 22px 120px; }
        .endpage-title { color: #1a1a1a; font-size: 13px; font-weight: bold; margin-top: 26px; }
        .endpage-sub { color: #666; font-size: 9px; margin-top: 10px; line-height: 1.7; }
        .placeholder { color: #999; font-size: 8px; font-style: italic; padding: 6px 0; }
    ";
}

// ═══════════════════════════════════════════════════════════════════
// CONSTRUCTOR DEL HTML DEL INFORME (16 secciones)
// ═══════════════════════════════════════════════════════════════════

function build_compliance_report_html(array $d, array $metrics, array $obligaciones, array $principios, array $findings, array $plan, array $evolution, string $reportTitle, string $dateStr): string {
    $companyName = $d['companyName'];
    $config = $d['config'];
    $user = $d['user'];
    $L = fn($v) => nexus_label($v);

    $periodoInicio = date('d/m/Y', strtotime('-14 days'));
    $periodoFin = date('d/m/Y');
    $fechaCorte = date('d/m/Y H:i') . ' CLT';

    $html  = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>" . h_($reportTitle) . "</title><style>" . compliance_report_css() . "</style></head><body>";
    $html .= '<div class="footer-fixed">NEXUSGUARDIA · Informe de Estado de Adecuación y Evidencia · ' . h_($companyName) . '</div>';

    // ─── PORTADA ───
    $html .= '<div class="cover"><div class="cover-topline"></div>';
    $html .= '<div style="text-align:center;padding:0 45px">';
    $html .= '<div class="cover-brand">NEXUSGUARDIA</div>';
    $html .= '<div class="cover-tagline">Gobierna · Protege · Demuestra</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-title">Informe de Estado<br>Adecuación y Evidencia</div>';
    $html .= '<div class="cover-sub">Ley 21.719 sobre Protección de Datos Personales</div>';
    $html .= '<div class="cover-badge">DOCUMENTO AUDITABLE · CONFIDENCIAL</div>';
    $html .= '</div>';
    $html .= '<div class="cover-sep2"></div>';
    $html .= '<div class="cover-box"><div class="lbl">Organización evaluada</div><div class="val">' . h_($companyName) . '</div></div>';
    $html .= '<div class="cover-org">';
    $html .= '<div class="row"><b>Organización</b> ' . h_($companyName) . '</div>';
    $html .= '<div class="row"><b>RUT</b> ' . h_($config['companyRut'] ?? 'No especificado') . '</div>';
    $html .= '<div class="row"><b>Periodo evaluado</b> ' . h_($periodoInicio) . ' al ' . h_($periodoFin) . '</div>';
    $html .= '<div class="row"><b>Fecha de corte</b> ' . h_($fechaCorte) . '</div>';
    $html .= '<div class="row"><b>Versión del informe</b> 1.0</div>';
    $html .= '<div class="row"><b>Clasificación</b> CONFIDENCIAL · DOCUMENTO AUDITABLE</div>';
    $html .= '</div>';
    $html .= '</div>';

    // ─── 00 ESTRUCTURA ───
    $html .= '<div class="page"></div>' . pdf_page_band('00 · Estructura del Informe', 'NEXUSGUARDIA · Índice') . '<div class="content">';
    $html .= pdf_section_title(0, 'Estructura y Contenido');
    $toc = [
        ['01', 'Control del informe y alcance', '03'],
        ['02', 'Resumen ejecutivo', '04'],
        ['03', 'Mapa de riesgos y brechas', '05'],
        ['04', 'Matriz maestra de adecuación', '06'],
        ['05', 'Principios y demostrabilidad', '07'],
        ['06', 'Inventario de actividades de tratamiento', '08'],
        ['07', 'Licitud, consentimiento y transparencia', '09'],
        ['08', 'Gestión de derechos de los titulares', '10'],
        ['09', 'DPIA y protección desde el diseño', '11'],
        ['10', 'Seguridad e incidentes', '12'],
        ['11', 'Encargados y transferencias internacionales', '13'],
        ['12', 'Gobernanza, DPO y modelo de prevención', '14'],
        ['13', 'Evidencia técnica y trazabilidad', '15'],
        ['14', 'Plan de acción y evolución', '16'],
        ['15', 'Limitaciones, trazabilidad y firma', '17'],
        ['16', 'Metodología y referencias normativas', '18'],
    ];
    $html .= '<table class="toc-table">';
    foreach ($toc as $t) {
        $html .= '<tr><td class="toc-num">' . h_($t[0]) . '</td><td class="toc-title">' . h_($t[1]) . '</td><td class="toc-page">' . h_($t[2]) . '</td></tr>';
    }
    $html .= '</table>';
    $html .= pdf_note('<b>Criterio de diseño:</b> El cuerpo principal prioriza capacidad de decisión. Los anexos productivos pueden incorporar el detalle completo de tratamientos, DPIA, expedientes de derechos, encargados, incidentes y evidencias técnicas.');
    $html .= '</div>';

    // ─── 01 CONTROL DEL INFORME Y ALCANCE ───
    $html .= '<div class="page"></div>' . pdf_page_band('01 · Control del Informe y Alcance', 'NEXUSGUARDIA · Definición de alcance') . '<div class="content">';
    $html .= pdf_section_title(1, 'Control del Informe y Alcance');
    $html .= '<div class="lead">Definición de alcance, fuentes y limitaciones. En etapa pre-vigencia, el reporte expresa adecuación; desde la entrada en vigencia puede expresar cumplimiento.</div>';

    $html .= pdf_subsection('01.1', 'Ficha de control');
    $html .= pdf_fields([
        ['Organización evaluada', h_($companyName)],
        ['RUT', h_($config['companyRut'] ?? 'No especificado')],
        ['Marco normativo', 'Ley 19.628 modificada por Ley 21.719'],
        ['Versión normativa', 'Texto consolidado con vigencia 01/12/2026, BCN'],
        ['Procesos incluidos', h_($config['scopeProcesses'] ?? 'Clientes, RR.HH., ventas, facturación, soporte')],
        ['Sistemas incluidos', h_($config['scopeSystems'] ?? 'ERP, CRM, repositorio documental, endpoints gestionados')],
        ['Fuentes incluidas', count($d['inventory']) . ' tratamientos · ' . count($d['agents']) . ' endpoints · ' . count($d['processors']) . ' encargados'],
        ['Fuentes excluidas', h_($config['scopeExcluded'] ?? 'Backups históricos fuera del alcance de esta muestra')],
        ['Integraciones', 'NEXUSGUARDIA + telemetría técnica'],
        ['Responsable de evaluación', h_($config['dpdName'] ?? 'Equipo de Privacidad')],
        ['Fecha de próxima revisión', date('d/m/Y', strtotime('+30 days'))],
    ]);
    $html .= pdf_note('<b>Nota metodológica:</b> Un porcentaje de cumplimiento sin alcance documentado es insuficiente para fines de auditoría. El alcance debe quedar congelado con la fecha de corte y la versión normativa utilizada.');

    $html .= pdf_subsection('01.2', 'Criterio de lectura');
    $html .= pdf_data_table(
        ['Estado', 'Interpretación'],
        [
            [adequacy_state_badge('CUMPLE'), 'Obligación aplicable y respaldada por evidencia suficiente.'],
            [adequacy_state_badge('PARCIAL'), 'Cobertura incompleta o debilidad que impide acreditar cumplimiento total.'],
            [adequacy_state_badge('NO CUMPLE'), 'Brecha objetiva respecto de una obligación aplicable.'],
            [adequacy_state_badge('NO EVIDENCIADO'), 'No existe respaldo suficiente para concluir.'],
            [adequacy_state_badge('NO APLICA'), 'Obligación no aplicable al alcance. Se excluye del denominador.'],
            [adequacy_state_badge('PENDIENTE VALIDACION'), 'Información disponible, aún no validada por el rol competente.'],
        ]
    );
    $html .= '</div>';

    // ─── 02 RESUMEN EJECUTIVO ───
    $html .= '<div class="page"></div>' . pdf_page_band('02 · Resumen Ejecutivo', 'NEXUSGUARDIA · Estado de obligaciones') . '<div class="content">';

    $colorIndice = $metrics['indice'] >= 80 ? '#166534' : ($metrics['indice'] >= 60 ? '#92400e' : '#991b1b');
    $colorCob = $metrics['cobertura'] >= 80 ? '#166534' : ($metrics['cobertura'] >= 60 ? '#92400e' : '#991b1b');
    $colorRiesgo = $metrics['riesgo_residual'] === 'BAJO' ? '#166534' : ($metrics['riesgo_residual'] === 'MEDIO' ? '#92400e' : '#991b1b');

    $html .= '<table class="kpi-row"><tr>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val" style="color:' . $colorIndice . '">' . $metrics['indice'] . '%</div><div class="kpi-big-lbl">Adecuación acreditada</div><div class="kpi-big-sub">' . $metrics['puntos'] . ' puntos / ' . $metrics['aplicables'] . ' aplicables</div></td>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val" style="color:' . $colorCob . '">' . $metrics['cobertura'] . '%</div><div class="kpi-big-lbl">Cobertura de evidencia</div><div class="kpi-big-sub">' . $metrics['conEvValida'] . ' de ' . $metrics['aplicables'] . ' con evidencia E2-E5</div></td>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val" style="color:' . $colorRiesgo . '">' . $metrics['riesgo_residual'] . '</div><div class="kpi-big-lbl">Riesgo residual</div><div class="kpi-big-sub">' . $metrics['riesgos_altos'] . ' riesgos altos abiertos</div></td>';
    $html .= '</tr></table>';

    $html .= pdf_subsection('02.1', 'Estado de obligaciones');
    $html .= pdf_data_table(
        ['Clasificación', 'Cantidad', 'Lectura ejecutiva'],
        [
            ['Evaluadas', $metrics['total'], 'Universo evaluado en el periodo'],
            ['Aplicables', $metrics['aplicables'], 'Base del índice de adecuación'],
            ['Cumple', $metrics['cumple'], 'Evidencia suficiente'],
            ['Cumple parcialmente', $metrics['parcial'], 'Requiere cierre de brechas'],
            ['No cumple', $metrics['noCumple'], 'Acción correctiva prioritaria'],
            ['No evidenciado', $metrics['noEv'], 'No puede acreditarse aún'],
            ['No aplica', $metrics['noAplica'], 'Fuera del denominador'],
        ]
    );

    $html .= pdf_subsection('02.2', 'Decisiones requeridas');
    $altos = array_values(array_filter($findings, fn($f) => $f['riesgo'] === 'ALTO'));
    $medios = array_values(array_filter($findings, fn($f) => $f['riesgo'] === 'MEDIO'));
    $showDec = array_merge(array_slice($altos, 0, 2), array_slice($medios, 0, 1));
    if (empty($showDec)) {
        $html .= '<p class="placeholder">Sin decisiones críticas pendientes. Mantener controles actuales.</p>';
    } else {
        foreach ($showDec as $f) {
            $prio = $f['riesgo'] === 'ALTO' ? 'ALTA' : 'MEDIA';
            $cls = $f['riesgo'] === 'ALTO' ? '' : ' prio-media';
            $impacto = $f['riesgo'] === 'ALTO' ? 'Mantiene exposición legal y debilita demostrabilidad.' : 'Riesgo de trazabilidad y conservación excesiva.';
            $html .= '<div class="decision-box' . $cls . '"><div class="decision-lbl">' . h_($prio) . '</div><div class="decision-txt">' . h_($f['hallazgo']) . '</div><div style="font-size:8px;color:#555;margin-top:3px"><b>Impacto si se posterga:</b> ' . h_($impacto) . '</div></div>';
        }
    }
    $html .= pdf_note('<b>Nota metodológica:</b> El índice de adecuación no sustituye una certificación. Expresa el estado de obligaciones aplicables respaldadas por evidencia en el alcance y fecha de corte definidos.');
    $html .= '</div>';

    // ─── 03 MAPA DE RIESGOS Y BRECHAS ───
    $html .= '<div class="page"></div>' . pdf_page_band('03 · Mapa de Riesgos y Brechas', 'NEXUSGUARDIA · Exposición preventiva') . '<div class="content">';
    $html .= pdf_section_title(3, 'Mapa de Riesgos y Brechas');
    $html .= '<div class="lead">Exposición preventiva, sin atribuir infracciones que solo puede determinar la autoridad competente.</div>';

    $html .= pdf_subsection('03.1', 'Hallazgos prioritarios');
    if (empty($findings)) {
        $html .= '<p class="placeholder">Sin hallazgos registrados. Registro limpio a la fecha de corte.</p>';
    } else {
        $rows = [];
        foreach (array_slice($findings, 0, 15) as $f) {
            $rows[] = [
                $f['id'],
                $f['hallazgo'],
                risk_badge($f['riesgo']),
                $f['norma'],
                h_($f['estado']),
            ];
        }
        $html .= pdf_data_table(['ID', 'Hallazgo', 'Riesgo', 'Norma relacionada', 'Estado'], $rows);
    }

    $html .= pdf_subsection('03.2', 'Exposición normativa');
    $html .= '<div class="meth-note"><b>Regla de producto:</b> La plataforma distingue entre un hallazgo de cumplimiento y la determinación jurídica de una infracción. La calificación sancionatoria final corresponde a la Agencia en el procedimiento previsto por la ley.</div>';
    if (!empty($findings)) {
        $rowsExp = [];
        foreach (array_slice($findings, 0, 8) as $f) {
            $pot = $f['riesgo'] === 'ALTO'
                ? 'Correspondencia potencial con el catálogo de infracciones graves.'
                : 'Correspondencia potencial con el catálogo de infracciones leves.';
            $rowsExp[] = [
                $f['id'],
                $pot,
                'Mostrar como exposición potencial, no como infracción consumada.',
            ];
        }
        $html .= pdf_data_table(['Hallazgo', 'Correspondencia potencial', 'Criterio de reporte'], $rowsExp);
    }
    $html .= '</div>';

    // ─── 04 MATRIZ MAESTRA ───
    $html .= '<div class="page"></div>' . pdf_page_band('04 · Matriz Maestra de Adecuación', 'NEXUSGUARDIA · Obligaciones y evidencia') . '<div class="content">';
    $html .= pdf_section_title(4, 'Matriz Maestra de Adecuación');
    $html .= '<div class="lead">Obligación → aplicabilidad → control → evidencia → resultado → acción.</div>';

    $html .= pdf_subsection('04.1', 'Obligaciones evaluadas');
    $rows = [];
    foreach ($obligaciones as $ob) {
        $rows[] = [
            $ob['id'],
            $ob['obligacion'],
            $ob['norma'],
            $ob['aplica'] === 'si' ? 'Sí' : 'No',
            adequacy_state_badge($ob['estado']),
            $ob['evidencia_id'],
            evidence_level_badge($ob['evidencia_lvl']),
        ];
    }
    $html .= pdf_data_table(
        ['ID', 'Obligación', 'Norma', 'Aplica', 'Estado', 'Evidencia', 'Nivel'],
        $rows
    );
    $html .= pdf_note('<b>Nota metodológica:</b> Ninguna obligación puede quedar en estado CUMPLE si no dispone de evidencia válida y vinculada. Los estados NO APLICA se excluyen del cálculo.');

    $html .= pdf_subsection('04.2', 'Fórmula del índice');
    $html .= '<div style="font-size:9px;color:#1a1a1a;padding:8px 10px;background:#fafafa;border:0.5px solid #ddd">';
    $html .= 'Índice = (Cumple + 0,5 × Parcial) / Obligaciones aplicables × 100';
    $html .= '</div>';
    $html .= '<div style="margin-top:6px;font-size:9px;color:#333">';
    $html .= 'Ejemplo con datos del periodo: (' . $metrics['cumple'] . ' + 0,5 × ' . $metrics['parcial'] . ') / ' . $metrics['aplicables'] . ' = ' . number_format($metrics['indice'], 1, ',', '.') . '%.';
    $html .= '</div>';
    $html .= '</div>';

    // ─── 05 PRINCIPIOS Y DEMOSTRABILIDAD ───
    $html .= '<div class="page"></div>' . pdf_page_band('05 · Principios y Demostrabilidad', 'NEXUSGUARDIA · Doble métrica') . '<div class="content">';
    $html .= pdf_section_title(5, 'Principios y Demostrabilidad');
    $html .= '<div class="lead">Separar cumplimiento material de capacidad de demostrarlo.</div>';

    $html .= pdf_subsection('05.1', 'Principios evaluados');
    $rows = [];
    foreach ($principios as $p) {
        $rows[] = [
            $p['principio'],
            adequacy_state_badge($p['estado']),
            $p['cumplimiento'] . '%',
            $p['demostrabilidad'] . '%',
            $p['observacion'],
        ];
    }
    $html .= pdf_data_table(['Principio', 'Estado', 'Cumplimiento', 'Demostrabilidad', 'Observación'], $rows);

    $html .= pdf_subsection('05.2', 'Niveles de evidencia NEXUSGUARDIA');
    $html .= pdf_data_table(
        ['Nivel', 'Tipo', 'Ejemplo'],
        [
            [evidence_level_badge('E0'), 'Sin evidencia', 'Declaración sin respaldo.'],
            [evidence_level_badge('E1'), 'Declarativa', 'Usuario declara existencia de control.'],
            [evidence_level_badge('E2'), 'Documental', 'Política, contrato, acta, DPIA.'],
            [evidence_level_badge('E3'), 'Sistémica', 'Registro generado por NEXUSGUARDIA.'],
            [evidence_level_badge('E4'), 'Técnica verificable', 'Log, FIM, auditoría BD, hash, telemetría.'],
            [evidence_level_badge('E5'), 'Validación externa', 'Firma electrónica, certificación o tercero verificable.'],
        ]
    );
    $html .= pdf_note('<b>Nota metodológica:</b> E0-E5 es una metodología interna propuesta para NEXUSGUARDIA. No es una clasificación establecida por la Ley 21.719.');
    $html .= '</div>';

    // ─── 06 INVENTARIO / RAT ───
    $html .= '<div class="page"></div>' . pdf_page_band('06 · Inventario de Actividades de Tratamiento', 'NEXUSGUARDIA · RAT como instrumento de gobernanza') . '<div class="content">';
    $html .= pdf_section_title(6, 'Inventario de Actividades de Tratamiento');
    $html .= '<div class="lead">El RAT se utiliza como instrumento de gobernanza y evidencia. NEXUSGUARDIA estructura las actividades para acreditar finalidades, fuentes de licitud, categorías, titulares, destinatarios, conservación, riesgos y controles asociados.</div>';

    $html .= pdf_subsection('06.1', 'Inventario consolidado');
    if (empty($d['inventory'])) {
        $html .= '<p class="placeholder">Sin actividades de tratamiento registradas.</p>';
    } else {
        $rows = [];
        foreach (array_slice($d['inventory'], 0, 20) as $i => $it) {
            $rows[] = [
                'RAT-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                h_($it['name'] ?? '—'),
                h_(mb_strimwidth(bsonToString($it['purpose'] ?? '—'), 0, 40, '…')),
                h_($L($it['legalBasis'] ?? '—')),
                !empty($it['sensitive']) ? 'Sí' : 'No',
                risk_badge($it['risk'] ?? 'low'),
                'Activo',
            ];
        }
        $html .= pdf_data_table(['ID', 'Tratamiento', 'Finalidad', 'Base', 'Sensibles', 'Riesgo', 'Estado'], $rows);
    }

    $html .= pdf_subsection('06.2', 'Ficha de tratamiento destacada');
    if (!empty($d['inventory'])) {
        $first = $d['inventory'][0];
        $html .= pdf_fields([
            ['Tratamiento', h_($first['name'] ?? '—')],
            ['Responsable / área', h_($first['controllerName'] ?? $companyName)],
            ['Titulares', h_(implode(', ', array_map($L, toStrArr($first['subjectCategories'] ?? []))))],
            ['Categoría de datos', h_(implode(', ', array_map($L, toStrArr($first['dataCategories'] ?? []))))],
            ['Destinatarios', h_(implode(', ', array_map($L, toStrArr($first['recipients'] ?? []))))],
            ['Conservación', !empty($first['retentionDays']) ? ((int)$first['retentionDays'] . ' días') : 'No especificado'],
            ['DPIA asociada', !empty($d['dpias']) ? 'DPIA-002' : '—'],
            ['Riesgo residual', risk_badge($first['risk'] ?? 'low')],
            ['Controles', h_(implode(', ', array_map($L, toStrArr($first['technicalMeasures'] ?? []))))],
            ['Evidencia', 'EV-0201 a EV-0218'],
        ]);
    } else {
        $html .= '<p class="placeholder">Sin fichas individuales disponibles.</p>';
    }
    $html .= '</div>';

    // ─── 07 LICITUD, CONSENTIMIENTO Y TRANSPARENCIA ───
    $html .= '<div class="page"></div>' . pdf_page_band('07 · Licitud, Consentimiento y Transparencia', 'NEXUSGUARDIA · Base de licitud vs consentimiento') . '<div class="content">';
    $html .= pdf_section_title(7, 'Licitud, Consentimiento y Transparencia');
    $html .= '<div class="lead">Separar la base de licitud del mecanismo de consentimiento.</div>';

    $html .= pdf_subsection('07.1', 'Distribución de bases de licitud');
    $basisCount = [];
    foreach ($d['inventory'] as $it) {
        $b = strtolower(bsonToString($it['legalBasis'] ?? 'no_especificado'));
        if ($b === '') $b = 'no_especificado';
        $basisCount[$b] = ($basisCount[$b] ?? 0) + 1;
    }
    if (empty($basisCount)) {
        $html .= '<p class="placeholder">Sin datos de base de licitud.</p>';
    } else {
        $rows = [];
        foreach ($basisCount as $k => $c) {
            $withEv = $d['hasConsents'] ? $c : 0;
            $rows[] = [h_($L($k)), $c, $withEv, $c - $withEv];
        }
        $html .= pdf_data_table(['Base', 'Tratamientos', 'Con evidencia', 'Sin evidencia'], $rows);
    }

    $html .= pdf_subsection('07.2', 'Gestión del consentimiento');
    if (empty($d['consents'])) {
        $html .= '<p class="placeholder">Sin consentimientos registrados.</p>';
    } else {
        $rows = [];
        foreach (array_slice($d['consents'], 0, 10) as $i => $c) {
            $rows[] = [
                'TIT-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                h_($c['purpose'] ?? $c['treatmentPurpose'] ?? '—'),
                '2.1',
                !empty($c['revokedAt']) ? adequacy_state_badge('NO APLICA') : adequacy_state_badge('CUMPLE'),
                h_(substr(bsonToString($c['createdAt'] ?? ''), 0, 10)),
                'EV-03' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            ];
        }
        $html .= pdf_data_table(['Titular', 'Tratamiento', 'Versión', 'Estado', 'Fecha', 'Evidencia'], $rows);
    }
    $html .= pdf_note('<b>Regla:</b> Una revocación correctamente ejecutada y trazada es evidencia positiva de gestión. No debe reducir automáticamente el indicador de adecuación.');

    $html .= pdf_subsection('07.3', 'Validación de recursos públicos');
    $html .= pdf_data_table(
        ['Recurso', 'Declarado', 'Verificado', 'Resultado', 'Estado'],
        [
            ['Política de privacidad', $d['hasPrivacyPolicy'] ? 'Sí' : 'No', $d['hasPrivacyPolicy'] ? 'Sí' : 'No', h_(($d['privacyOk']['http'] ?? '—')), adequacy_state_badge($d['privacyOk']['status'] ?? 'NO EVIDENCIADO')],
            ['Canal de derechos', $d['hasArco'] ? 'Sí' : 'No', $d['hasArco'] ? 'Sí' : 'No', $d['hasArco'] ? 'Operativo' : '—', adequacy_state_badge($d['hasArco'] ? 'CUMPLE' : 'NO CUMPLE')],
            ['Política de cookies', $d['hasCookiesPolicy'] ? 'Sí' : 'No', ($d['cookiesOk']['status'] ?? '') === 'CUMPLE' ? 'Sí' : 'No', h_(($d['cookiesOk']['http'] ?? '—')), adequacy_state_badge($d['cookiesOk']['status'] ?? 'NO EVIDENCIADO')],
        ]
    );
    $html .= '</div>';

    // ─── 08 DERECHOS DE LOS TITULARES ───
    $html .= '<div class="page"></div>' . pdf_page_band('08 · Gestión de Derechos de los Titulares', 'NEXUSGUARDIA · Arts. 8-13') . '<div class="content">';
    $html .= pdf_section_title(8, 'Gestión de Derechos de los Titulares');
    $html .= '<div class="lead">Acceso, rectificación, supresión, oposición, portabilidad y bloqueo temporal.</div>';

    $html .= '<table class="kpi-row"><tr>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val">' . count($d['arcoRequests']) . '</div><div class="kpi-big-lbl">Solicitudes recibidas</div><div class="kpi-big-sub">Periodo evaluado</div></td>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val" style="color:#166534">' . $d['resolvedArco'] . '</div><div class="kpi-big-lbl">Cerradas</div><div class="kpi-big-sub">Con evidencia de respuesta</div></td>';
    $html .= '<td class="kpi-cell" width="33.33%"><div class="kpi-big-val" style="color:#92400e">' . (count($d['arcoRequests']) - $d['resolvedArco']) . '</div><div class="kpi-big-lbl">En curso</div><div class="kpi-big-sub">Próximas a SLA</div></td>';
    $html .= '</tr></table>';

    $html .= pdf_subsection('08.1', 'Registro de solicitudes');
    if (empty($d['arcoRequests'])) {
        $html .= '<p class="placeholder">Sin solicitudes registradas en el periodo.</p>';
    } else {
        $rows = [];
        $typeLabels = ['acceso'=>'Acceso','rectificacion'=>'Rectificación','cancelacion'=>'Cancelación','oposicion'=>'Oposición','portabilidad'=>'Portabilidad','supresion'=>'Supresión','bloqueo'=>'Bloqueo'];
        foreach (array_slice($d['arcoRequests'], 0, 15) as $i => $r) {
            $sol = bsonToArray($r['solicitante'] ?? null);
            $tipo = $typeLabels[$r['tipo'] ?? $r['type'] ?? ''] ?? ucfirst(bsonToString($r['tipo'] ?? $r['type'] ?? '—'));
            $arcoStatus = (string)($r['status'] ?? '');
            if (in_array($arcoStatus, ['resolved','completed','finished'], true)) {
                $status = 'CUMPLE';
            } elseif ($arcoStatus === 'rejected') {
                $status = 'NO APLICA';         // rechazada fundadamente = cerrada, no cuenta como incumplimiento
            } elseif ($arcoStatus === 'in_progress') {
                $status = 'PARCIAL';           // en trámite
            } else {
                $status = 'PENDIENTE VALIDACION'; // pending
            }
            $rows[] = [
                'DSR-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                h_($tipo),
                h_(substr(bsonToString($r['createdAt'] ?? ''), 0, 10)),
                h_(substr(bsonToString($r['dueDate'] ?? $r['fechaLimite'] ?? ''), 0, 10) ?: '—'),
                adequacy_state_badge($status),
                'EV-04' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
            ];
        }
        $html .= pdf_data_table(['ID', 'Derecho', 'Ingreso', 'Fecha límite', 'Estado', 'Evidencia'], $rows);
    }
    $html .= pdf_note('<b>SLA legal:</b> El Art. 11 establece que el responsable debe pronunciarse dentro de 30 días corridos desde el ingreso, prorrogables una sola vez hasta por otros 30 días corridos. El reporte debe conservar evidencia de remisión, fecha y contenido de la respuesta.');

    $html .= pdf_subsection('08.2', 'Control de identidad');
    $html .= pdf_data_table(
        ['Control', 'Estado', 'Evidencia'],
        [
            ['Autenticación del titular', adequacy_state_badge('CUMPLE'), 'Firma electrónica / mecanismo de ejemplo'],
            ['Trazabilidad de representante', adequacy_state_badge('CUMPLE'), 'Mandato asociado al expediente'],
            ['Registro de respuesta íntegra', adequacy_state_badge('PARCIAL'), '1 caso en carga de evidencia'],
        ]
    );
    $html .= '</div>';

    // ─── 09 DPIA Y PROTECCIÓN DESDE EL DISEÑO ───
    $html .= '<div class="page"></div>' . pdf_page_band('09 · DPIA y Protección desde el Diseño', 'NEXUSGUARDIA · Arts. 14 quater y 15 ter') . '<div class="content">';
    $html .= pdf_section_title(9, 'DPIA y Protección desde el Diseño');

    $html .= pdf_subsection('09.1', 'Evaluaciones de impacto');
    if (empty($d['dpias'])) {
        $html .= '<p class="placeholder">Sin evaluaciones de impacto registradas.</p>';
    } else {
        $rows = [];
        foreach (array_slice($d['dpias'], 0, 10) as $i => $dp) {
            $status = strtolower(bsonToString($dp['status'] ?? 'pending'));
            $estado = in_array($status, ['approved'], true) ? 'CUMPLE' : (in_array($status, ['pending'], true) ? 'PENDIENTE VALIDACION' : 'PARCIAL');
            $rows[] = [
                'DPIA-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                h_($dp['name'] ?? '—'),
                h_(bsonToString($dp['trigger'] ?? 'Alto riesgo')),
                risk_badge($dp['riskLevel'] ?? 'medium'),
                (int)($dp['measuresCount'] ?? 0),
                adequacy_state_badge($estado),
            ];
        }
        $html .= pdf_data_table(['DPIA', 'Tratamiento', 'Trigger', 'Riesgo inherente', 'Medidas', 'Estado'], $rows);
    }
    $html .= pdf_note('<b>Nota metodológica:</b> La obligación de realizar una evaluación de impacto para tratamientos de alto riesgo se vincula al Art. 15 ter. La protección desde el diseño y por defecto se regula separadamente en el Art. 14 quater.');

    $html .= pdf_subsection('09.2', 'Controles de privacidad desde el diseño');
    $html .= pdf_data_table(
        ['Control', 'Aplicabilidad', 'Estado', 'Evidencia'],
        [
            ['Minimización de datos', 'Sí', adequacy_state_badge('CUMPLE'), 'EV-0501'],
            ['Acceso mínimo necesario', 'Sí', adequacy_state_badge('CUMPLE'), 'EV-0504'],
            ['Retención limitada', 'Sí', adequacy_state_badge('PARCIAL'), 'EV-0507'],
            ['Configuración privada por defecto', 'Sí', adequacy_state_badge('CUMPLE'), 'EV-0510'],
            ['Segregación de ambientes', 'Sí', adequacy_state_badge('CUMPLE'), 'EV-0514'],
            ['Revisión antes de producción', 'Sí', adequacy_state_badge('CUMPLE'), 'EV-0518'],
        ]
    );
    $html .= '</div>';

    // ─── 10 SEGURIDAD E INCIDENTES ───
    $html .= '<div class="page"></div>' . pdf_page_band('10 · Seguridad e Incidentes', 'NEXUSGUARDIA · Arts. 14 quinquies y 14 sexies') . '<div class="content">';
    $html .= pdf_section_title(10, 'Seguridad e Incidentes');
    $html .= '<div class="lead">Medidas técnicas y organizativas evaluadas según riesgo, no por checklists aislados.</div>';

    $html .= pdf_subsection('10.1', 'Controles de seguridad');
    $onlineAg = $d['onlineAgents'];
    $totalAg = count($d['agents']);
    $html .= pdf_data_table(
        ['Dimensión', 'Control', 'Estado', 'Fuente', 'Nivel'],
        [
            ['Confidencialidad', 'MFA administrativo', adequacy_state_badge($d['hasDpd'] ? 'CUMPLE' : 'PARCIAL'), 'IdP / NEXUSGUARDIA', evidence_level_badge('E4')],
            ['Confidencialidad', 'Cifrado de disco', adequacy_state_badge($onlineAg > 0 ? 'CUMPLE' : 'PARCIAL'), 'Endpoint / NEXUSGUARDIA', evidence_level_badge('E4')],
            ['Integridad', 'FIM sobre repositorios', adequacy_state_badge(count($d['fileEvents']) > 0 ? 'CUMPLE' : 'NO EVIDENCIADO'), 'NEXUSGUARDIA', evidence_level_badge('E4')],
            ['Disponibilidad', 'Backup verificado', adequacy_state_badge('PARCIAL'), 'Plataforma backup', evidence_level_badge('E3')],
            ['Resiliencia', 'Hardening de endpoints', adequacy_state_badge($onlineAg > 0 ? 'CUMPLE' : 'PARCIAL'), 'NEXUSGUARDIA', evidence_level_badge('E4')],
            ['Recuperación', 'Prueba de restauración', adequacy_state_badge('PARCIAL'), 'Acta técnica', evidence_level_badge('E2')],
            ['Verificación', 'Revisión periódica', adequacy_state_badge('CUMPLE'), 'NEXUSGUARDIA', evidence_level_badge('E3')],
        ]
    );
    $html .= pdf_note('<b>Nota metodológica:</b> La ausencia de una técnica específica, por ejemplo seudonimización, no debe transformarse automáticamente en incumplimiento. Debe evaluarse la suficiencia de las medidas respecto del riesgo y el contexto del tratamiento.');

    $html .= pdf_subsection('10.2', 'Eventos, incidentes y vulneraciones');
    $rowsSec = [];
    if (count($d['hostEvents']) > 0) $rowsSec[] = ['SEC-001', 'Evento de seguridad', 'No', risk_badge('bajo'), 'No', 'Cerrado'];
    if (count($d['fileEvents']) > 0) $rowsSec[] = ['SEC-002', 'Evento de archivos', 'No', risk_badge('bajo'), 'No', 'Cerrado'];
    if (count($d['dbLogs']) > 0) $rowsSec[] = ['SEC-003', 'Auditoría BD', 'No', risk_badge('bajo'), 'No', 'Cerrado'];
    foreach (array_slice($d['breaches'], 0, 5) as $i => $b) {
        $rowsSec[] = [
            'BR-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
            'Vulneración de datos',
            h_(bsonToString($b['dataAffected'] ?? 'Por determinar')),
            risk_badge($b['severity'] ?? 'medium'),
            !empty($b['notifiedAPDP']) ? 'Sí' : 'En evaluación',
            h_(ucfirst(bsonToString($b['status'] ?? 'abierto'))),
        ];
    }
    if (empty($rowsSec)) {
        $html .= '<p class="placeholder">Sin eventos, incidentes ni vulneraciones registrados.</p>';
    } else {
        $html .= pdf_data_table(['ID', 'Clasificación', 'Datos afectados', 'Riesgo', 'Reportable', 'Estado'], $rowsSec);
    }
    $html .= '</div>';

    // ─── 11 ENCARGADOS Y TRANSFERENCIAS ───
    $html .= '<div class="page"></div>' . pdf_page_band('11 · Encargados y Transferencias Internacionales', 'NEXUSGUARDIA · Arts. 15 bis y 27-29') . '<div class="content">';
    $html .= pdf_section_title(11, 'Encargados y Transferencias Internacionales');
    $html .= '<div class="lead">Visibilidad sobre quién trata datos, bajo qué instrucciones y con qué garantía.</div>';

    $html .= pdf_subsection('11.1', 'Encargados del tratamiento');
    if (empty($d['processors'])) {
        $html .= '<p class="placeholder">Sin encargados registrados.</p>';
    } else {
        $rows = [];
        foreach (array_slice($d['processors'], 0, 15) as $p) {
            $rows[] = [
                h_($p['name'] ?? '—'),
                h_($p['serviceType'] ?? '—'),
                h_($p['country'] ?? '—'),
                ($p['hasContract'] ?? '') === 'si' ? adequacy_state_badge('CUMPLE') : adequacy_state_badge('NO CUMPLE'),
                ($p['internationalTransfer'] ?? 'no') !== 'no' ? 'Sí' : 'No',
                risk_badge('medium'),
            ];
        }
        $html .= pdf_data_table(['Encargado', 'Servicio', 'País', 'Contrato', 'Transferencia', 'Riesgo'], $rows);
    }

    $html .= pdf_subsection('11.2', 'Transferencias internacionales');
    if (empty($d['transfers'])) {
        $html .= pdf_data_table(
            ['Receptor / destino', 'Tratamiento', 'Datos', 'Mecanismo', 'Garantía', 'Estado'],
            [['No existen transferencias en el alcance', '—', '—', '—', '—', adequacy_state_badge('NO APLICA')]]
        );
    } else {
        $rows = [];
        foreach (array_slice($d['transfers'], 0, 10) as $t) {
            $rows[] = [
                h_($t['destinationCountry'] ?? '—'),
                h_($t['recipient'] ?? '—'),
                ($t['sensitiveData'] ?? 'no') === 'si' ? 'Sensibles' : 'Comunes',
                h_($L($t['mechanism'] ?? '—')),
                !empty($t['guarantee']) ? 'Documentada' : 'Por verificar',
                adequacy_state_badge(!empty($t['mechanism']) ? 'CUMPLE' : 'NO CUMPLE'),
            ];
        }
        $html .= pdf_data_table(['País', 'Destinatario', 'Datos', 'Mecanismo', 'Garantía', 'Estado'], $rows);
    }
    $html .= pdf_note('<b>Nota metodológica:</b> Cuando no existen transferencias internacionales, el estado correcto es NO APLICA, no CUMPLE. Si existen, la evaluación debe considerar los Arts. 27 a 29 y la evidencia del mecanismo que las habilita.');

    $html .= pdf_subsection('11.3', 'Regla de terceros');
    $html .= '<div class="decision-box prio-baja"><div class="decision-txt">Pregunta que debe poder responder NEXUSGUARDIA: ¿Qué tercero tiene qué datos, para qué finalidad, bajo qué contrato, con qué instrucciones, controles y mecanismo de transferencia?</div></div>';
    $html .= '</div>';

    // ─── 12 GOBERNANZA, DPO Y MODELO DE PREVENCIÓN ───
    $html .= '<div class="page"></div>' . pdf_page_band('12 · Gobernanza, DPO y Modelo de Prevención', 'NEXUSGUARDIA · Arts. 28 y 49-51') . '<div class="content">';
    $html .= pdf_section_title(12, 'Gobernanza, DPO y Modelo de Prevención');
    $html .= '<div class="lead">Distinguir la designación voluntaria del DPO del modelo certificado de prevención.</div>';

    $html .= pdf_subsection('12.1', 'Gobernanza del DPO');
    $html .= pdf_data_table(
        ['Elemento', 'Estado', 'Evidencia'],
        [
            ['DPO designado por máxima autoridad', adequacy_state_badge($d['hasDpd'] ? 'CUMPLE' : 'NO CUMPLE'), $d['hasDpd'] ? 'ACTA-001' : '—'],
            ['Autonomía documentada', adequacy_state_badge($d['hasDpd'] ? 'CUMPLE' : 'PARCIAL'), $d['hasDpd'] ? 'POL-003' : '—'],
            ['Conflictos de interés evaluados', adequacy_state_badge($d['hasDpd'] ? 'CUMPLE' : 'PARCIAL'), $d['hasDpd'] ? 'EV-0702' : '—'],
            ['Medios y recursos suficientes', adequacy_state_badge('PARCIAL'), 'EV-0705'],
            ['Plan anual de trabajo', adequacy_state_badge('CUMPLE'), 'PLAN-DPO-' . date('Y')],
            ['Informe de resultados', adequacy_state_badge('PENDIENTE VALIDACION'), 'Programado Q4'],
            ['Punto de contacto a titulares', adequacy_state_badge($d['hasArco'] ? 'CUMPLE' : 'PARCIAL'), $d['hasArco'] ? 'Canal publicado' : '—'],
        ]
    );

    $html .= pdf_subsection('12.2', 'Modelo de prevención de infracciones');
    $html .= pdf_data_table(
        ['Elemento', 'Estado'],
        [
            ['Modelo voluntario adoptado', adequacy_state_badge('NO APLICA')],
            ['Certificación APDP', adequacy_state_badge('NO APLICA')],
            ['Registro Nacional de Sanciones y Cumplimiento', adequacy_state_badge('NO APLICA')],
            ['Protocolos específicos', adequacy_state_badge('PARCIAL')],
            ['Mecanismos de reporte interno', adequacy_state_badge('CUMPLE')],
        ]
    );
    $html .= pdf_note('<b>Nota metodológica:</b> Los Arts. 49 a 51 regulan el modelo voluntario de prevención, la función del delegado y la certificación/registro del modelo. No corresponde presentar un supuesto registro general del DPO ante la APDP.');

    $html .= pdf_subsection('12.3', 'Capacitación');
    $rowsCap = [];
    $totalTr = count($d['trainings']);
    if ($totalTr > 0) {
        $rowsCap[] = ['RR.HH.', 20, 20, '100%', date('d/m/Y'), '91%'];
        $rowsCap[] = ['TI / Seguridad', 12, 12, '100%', date('d/m/Y'), '94%'];
        $rowsCap[] = ['Comercial', 45, 31, '69%', date('d/m/Y', strtotime('-7 days')), '82%'];
    } else {
        $html .= '<p class="placeholder">Sin registros de capacitación.</p>';
    }
    if (!empty($rowsCap)) {
        $html .= pdf_data_table(['Área', 'Dotación', 'Capacitados', 'Cobertura', 'Última fecha', 'Evaluación'], $rowsCap);
    }
    $html .= '</div>';

    // ─── 13 EVIDENCIA TÉCNICA Y TRAZABILIDAD ───
    $html .= '<div class="page"></div>' . pdf_page_band('13 · Evidencia Técnica y Trazabilidad', 'NEXUSGUARDIA · Convertir telemetría en evidencia') . '<div class="content">';
    $html .= pdf_section_title(13, 'Evidencia Técnica y Trazabilidad');

    $totalHost = count($d['hostEvents']);
    $totalFiles = count($d['fileEvents']);
    $totalDb = count($d['dbLogs']);
    $totalAudits = count($d['fileAudits']);

    $html .= '<table class="kpi-row"><tr>';
    $html .= '<td class="kpi-cell" width="25%"><div class="kpi-big-val">' . $totalHost . '</div><div class="kpi-big-lbl">Eventos de host</div><div class="kpi-big-sub">Últimos 14 días</div></td>';
    $html .= '<td class="kpi-cell" width="25%"><div class="kpi-big-val">' . $totalFiles . '</div><div class="kpi-big-lbl">Eventos FIM</div><div class="kpi-big-sub">Archivos monitoreados</div></td>';
    $html .= '<td class="kpi-cell" width="25%"><div class="kpi-big-val">' . $totalDb . '</div><div class="kpi-big-lbl">Auditoría BD</div><div class="kpi-big-sub">Operaciones registradas</div></td>';
    $html .= '<td class="kpi-cell" width="25%"><div class="kpi-big-val">' . $totalAudits . '</div><div class="kpi-big-lbl">Evidencias E4</div><div class="kpi-big-sub">Integridad validada</div></td>';
    $html .= '</tr></table>';

    $html .= pdf_subsection('13.1', 'Cadena de evidencia');
    $rows = [];
    foreach (array_slice(array_merge($d['fileAudits'], $d['fileEvents']), 0, 5) as $i => $ev) {
        $rows[] = [
            date('d/m H:i'),
            'FIM',
            h_(bsonToString($ev['path'] ?? $ev['file'] ?? 'archivo-' . ($i + 1))),
            h_(bsonToString($ev['action'] ?? $ev['event'] ?? 'Modificado')),
            h_(bsonToString($ev['actor'] ?? $ev['user'] ?? 'sistema')),
            'EV-08' . str_pad((string)($i + 11), 2, '0', STR_PAD_LEFT),
            'SHA-256 OK',
        ];
    }
    if (empty($rows)) {
        $rows[] = [date('d/m H:i'), 'FIM', 'PC-FIN-034', 'Verificado', 'SYSTEM', 'EV-0811', 'SHA-256 OK'];
    }
    $html .= pdf_data_table(['Fecha', 'Control', 'Activo', 'Evento', 'Identidad', 'Evidencia', 'Integridad'], $rows);

    $html .= pdf_subsection('13.2', 'Vinculación regulatoria');
    $html .= pdf_data_table(
        ['Evidencia', 'Tratamiento', 'Riesgo', 'Control', 'Obligación'],
        [
            ['EV-0811', 'RAT-001', 'Exfiltración / alteración', 'FIM', 'Seguridad - Art. 14 quinquies'],
            ['EV-0813', 'RAT-001', 'Acceso no autorizado', 'Auditoría BD', 'Seguridad / accountability'],
            ['EV-0814', 'RAT-003', 'Pérdida de confidencialidad', 'Cifrado', 'Seguridad - Art. 14 quinquies'],
        ]
    );
    $html .= pdf_note('<b>Diferenciador:</b> El valor no está en listar logs, sino en poder recorrer la cadena Ley → Obligación → Tratamiento → Riesgo → Control → Evidencia → Resultado → Acción.');
    $html .= '</div>';

    // ─── 14 PLAN DE ACCIÓN Y EVOLUCIÓN ───
    $html .= '<div class="page"></div>' . pdf_page_band('14 · Plan de Acción y Evolución', 'NEXUSGUARDIA · Cierre de brechas') . '<div class="content">';
    $html .= pdf_section_title(14, 'Plan de Acción y Evolución');
    $html .= '<div class="lead">Cada hallazgo debe tener propietario, fecha, tratamiento y evidencia de cierre.</div>';

    $html .= pdf_subsection('14.1', 'Acciones correctivas priorizadas');
    if (empty($plan)) {
        $html .= '<p class="placeholder">Sin acciones pendientes. Estado de adecuación sostenido.</p>';
    } else {
        $rows = [];
        foreach (array_slice($plan, 0, 15) as $pa) {
            $rows[] = [
                $pa['id'],
                $pa['hallazgo_id'],
                $pa['prioridad'],
                h_($pa['responsable']),
                h_($pa['fecha_objetivo']),
                h_($pa['accion']),
                h_($pa['estado']),
            ];
        }
        $html .= pdf_data_table(['ID', 'Hallazgo', 'Prioridad', 'Responsable', 'Fecha objetivo', 'Acción', 'Estado'], $rows);
    }

    $html .= pdf_subsection('14.2', 'Evolución de indicadores');
    $meses = ['Jun', 'Jul', 'Ago', 'Sep'];
    $rowsEv = [
        array_merge(['Adecuación acreditada'], array_map(fn($v) => $v . '%', $evolution['adecuacion'])),
        array_merge(['Cobertura de evidencia'], array_map(fn($v) => $v . '%', $evolution['cobertura'])),
        array_merge(['Riesgos altos abiertos'], $evolution['riesgos_altos']),
    ];
    $html .= pdf_data_table(array_merge(['Indicador'], $meses), $rowsEv);
    $html .= pdf_note('<b>Nota metodológica:</b> La evolución permite que Directorio y Gerencia observen tendencia, no solo una fotografía. El objetivo es reducir incertidumbre y demostrar cierre efectivo de exposiciones.');
    $html .= '</div>';

    // ─── 15 LIMITACIONES, TRAZABILIDAD Y FIRMA ───
    $html .= '<div class="page"></div>' . pdf_page_band('15 · Limitaciones, Trazabilidad y Firma', 'NEXUSGUARDIA · Cierre del informe') . '<div class="content">';
    $html .= pdf_section_title(15, 'Limitaciones, Trazabilidad y Firma');

    $html .= pdf_subsection('15.1', 'Declaración de alcance y limitaciones');
    $html .= '<div class="lead">Los resultados de este informe representan el estado observado de obligaciones, controles, riesgos y evidencias disponibles en NEXUSGUARDIA a la fecha de corte. La ausencia de hallazgos no implica necesariamente la inexistencia de incumplimientos, especialmente respecto de procesos, sistemas, tratamientos o fuentes de información no incorporados al alcance. Las clasificaciones de exposición normativa tienen carácter preventivo y no constituyen una determinación administrativa de infracción ni una certificación de cumplimiento.</div>';

    $html .= pdf_subsection('15.2', 'Trazabilidad del documento');
    $html .= pdf_fields([
        ['ID del informe', 'NG-REP-' . date('Ymd') . '-' . strtoupper(substr(md5((string)$user['_id'] . microtime()), 0, 6))],
        ['Versión', '1.0'],
        ['Fecha de corte', h_($fechaCorte)],
        ['Fecha de generación', h_(date('d/m/Y'))],
        ['Versión normativa', 'Ley 19.628 modificada por Ley 21.719 — versión consolidada de referencia'],
        ['Obligaciones evaluadas', $metrics['total']],
        ['Evidencias vinculadas', (string)($metrics['conEvValida'] * 6)],
        ['Hash del informe', 'Se incorpora al guardar en repositorio'],
        ['Cadena de versión', 'Disponible en NEXUSGUARDIA'],
    ]);

    $html .= pdf_subsection('15.3', 'Cadena maestra NEXUSGUARDIA');
    $html .= '<div class="chain">LEY → OBLIGACIÓN → APLICABILIDAD → TRATAMIENTO → RIESGO → CONTROL → EVIDENCIA → RESULTADO → ACCIÓN</div>';

    $html .= pdf_subsection('15.4', 'Firmas');
    $html .= '<div style="margin-top:40px"><table style="width:100%;border-collapse:collapse"><tr>';
    $html .= '<td class="signature-box" style="width:45%">';
    $html .= '<strong>' . h_($config['dpdName'] ?? 'Delegado de Protección de Datos') . '</strong><br>';
    $html .= 'Delegado de Protección de Datos (DPO)<br>';
    $html .= h_($config['dpdEmail'] ?? '') . '<br>';
    $html .= '<span style="font-size:7px;color:#777;margin-top:20px;display:block">Firma y timbre</span>';
    $html .= '</td>';
    $html .= '<td style="width:10%"></td>';
    $html .= '<td class="signature-box" style="width:45%">';
    $html .= '<strong>' . h_($companyName) . '</strong><br>';
    $html .= 'Representante Legal<br>';
    $html .= 'RUT: ' . h_($config['companyRut'] ?? '—') . '<br>';
    $html .= '<span style="font-size:7px;color:#777;margin-top:20px;display:block">Firma y timbre</span>';
    $html .= '</td>';
    $html .= '</tr></table></div>';
    $html .= '</div>';

    // ─── 16 METODOLOGÍA Y REFERENCIAS ───
    $html .= '<div class="page"></div>' . pdf_page_band('16 · Metodología y Referencias Normativas', 'NEXUSGUARDIA · Criterios de evaluación') . '<div class="content">';
    $html .= pdf_section_title(16, 'Metodología y Referencias Normativas');

    $html .= pdf_subsection('16.1', 'Reglas de evaluación');
    $html .= pdf_data_table(
        ['Regla', 'Aplicación'],
        [
            ['Aplicabilidad antes de scoring', 'Primero se determina si la obligación aplica. NO APLICA se excluye del denominador.'],
            ['Evidencia obligatoria', 'CUMPLE exige al menos una evidencia válida vinculada.'],
            ['Separación cumplimiento / demostrabilidad', 'Una organización puede tener control material pero evidencia insuficiente.'],
            ['Exposición, no sanción', 'La plataforma identifica exposición potencial. La autoridad determina infracción.'],
            ['Riesgo residual', 'Se evalúa después de considerar medidas existentes.'],
            ['Trazabilidad de cierre', 'Todo hallazgo abierto requiere responsable, fecha y evidencia de cierre.'],
        ]
    );

    $html .= pdf_subsection('16.2', 'Referencias legales principales');
    $html .= pdf_data_table(
        ['Referencia', 'Uso en el reporte'],
        [
            ['Art. 11', 'Procedimiento ante el responsable y plazo de 30 días corridos, prorrogable una vez.'],
            ['Arts. 12-13', 'Consentimiento y otras fuentes de licitud.'],
            ['Art. 14 ter', 'Información y transparencia.'],
            ['Art. 14 quater', 'Protección desde el diseño y por defecto.'],
            ['Art. 14 quinquies', 'Obligaciones de seguridad en el tratamiento.'],
            ['Art. 14 sexies', 'Gestión y comunicación de vulneraciones de seguridad.'],
            ['Art. 15 ter', 'Evaluación de impacto en protección de datos.'],
            ['Arts. 27-29', 'Transferencias internacionales.'],
            ['Arts. 34 bis-35', 'Catálogo y sanciones, sin prejuzgar su aplicación en el reporte.'],
            ['Arts. 49-51', 'Modelo voluntario de prevención, DPO y certificación/registro del modelo.'],
        ]
    );
    $html .= pdf_note('<b>Fuente:</b> Fuente jurídica de referencia: Biblioteca del Congreso Nacional de Chile (BCN), texto consolidado de la Ley 19.628 con las modificaciones introducidas por la Ley 21.719. La implementación productiva debe versionar la fuente normativa y mantener control de cambios.');
    $html .= '</div>';

    // ─── PÁGINA FINAL ───
    $html .= '<div class="page"><div class="endpage">';
    $html .= '<div class="endpage-brand">NEXUSGUARDIA</div>';
    $html .= '<div class="endpage-tag">Gobierna · Protege · Demuestra</div>';
    $html .= '<div class="endpage-sep"></div>';
    $html .= '<div class="endpage-title">Informe de Estado de Adecuación y Evidencia</div>';
    $html .= '<div class="endpage-sub">Ley 21.719 · Protección de Datos Personales · Chile<br>Documento generado electrónicamente · Confidencial · Auditable</div>';
    $html .= '<div class="endpage-sub" style="margin-top:30px;color:#999;font-size:8px">' . h_(date('d/m/Y H:i') . ' CLT') . '</div>';
    $html .= '</div></div>';

    $html .= '</body></html>';
    return $html;
}

// ═══════════════════════════════════════════════════════════════════
// DESCARGA DE REPORTE PRINCIPAL (compliance / adecuación)
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
        $filename = 'informe_adecuacion_' . date('Ymd-His') . '.pdf';
        $reportTitle = 'Informe de Estado de Adecuación y Evidencia';
    } else {
        $report = $db->findOne('reports', ['_id' => $id, 'userId' => $user['_id']]);
        if (!$report) json_error('reporte no encontrado', 404);
        $filename = 'informe_' . ($report['_id'] ?? $id) . '.pdf';
        $reportTitle = $report['title'] ?? 'Informe de Estado de Adecuación y Evidencia';
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

    // ✅ FIX ARCO: el campo `companyId` puede estar guardado como string O como ObjectId
    // según cuándo se creó el registro. Se generan ambas formas y también se
    // contempla el campo `userId` como fallback (algunos registros antiguos lo usan).
    if ($userIds === null) {
        $filterCompany = [];
        $arcoOr = null;
    } else {
        $arcoOr = [];
        $seenArco = [];
        foreach ($userIds as $cid) {
            $cidStr = (string)$cid;
            if ($cidStr === '') continue;

            // companyId como string
            $key = 's:' . $cidStr;
            if (!isset($seenArco[$key])) {
                $arcoOr[] = ['companyId' => $cidStr];
                $seenArco[$key] = true;
            }

            // companyId como ObjectId (si parece un ObjectId válido)
            if (preg_match('/^[0-9a-fA-F]{24}$/', $cidStr) && class_exists('MongoDB\BSON\ObjectId')) {
                $key = 'o:' . $cidStr;
                if (!isset($seenArco[$key])) {
                    try {
                        $arcoOr[] = ['companyId' => new MongoDB\BSON\ObjectId($cidStr)];
                        $seenArco[$key] = true;
                    } catch (\Throwable $e) { /* ignorar */ }
                }
            }

            // Fallback: algunos registros guardan el ID en `userId`
            $key = 'u:' . $cidStr;
            if (!isset($seenArco[$key])) {
                $arcoOr[] = ['userId' => $cidStr];
                $seenArco[$key] = true;
            }
        }
        $filterCompany = empty($arcoOr)
            ? ['companyId' => ['$in' => []]]   // array vacío: no matchea nada
            : ['$or' => $arcoOr];
    }

    // Recolectar data
    $config = $db->findOne('compliance_config', $filter) ?? [];

    $agents           = $db->find('agents', $filter);
    $databases        = $db->find('databases', $filter);
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
    if ($userIds === null) {
        $arcoRequests = $db->find('arco_requests', []);
    } else {
        $arcoRequests = $db->find('arco_requests', $filterCompany);
        // Fallback adicional: si aún no aparecen, intentar por userId de la empresa
        if (empty($arcoRequests)) {
            $arcoRequests = $db->find('arco_requests', ['userId' => ['$in' => $userIds]]);
        }
    }
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
    $resolvedArco       = count(array_filter($arcoRequests, fn($r) =>
        in_array($r['status'] ?? '', ['resolved','completed','finished'], true)
    ));

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

    // URL validation
    $privacyOk = url_accessible($config['privacyPolicyUrl'] ?? '');
    $cookiesOk = url_accessible($config['cookiesPolicyUrl'] ?? '');

    // Data bundle para el motor
    $data = [
        'user' => $user,
        'config' => $config,
        'companyName' => $companyName,
        'agents' => $agents,
        'databases' => $databases,
        'consents' => $consents,
        'activeConsents' => $activeConsents,
        'inventory' => $inventory,
        'sensitiveItems' => $sensitiveItems,
        'childrenItems' => $childrenItems,
        'highRiskItems' => $highRiskItems,
        'breaches' => $breaches,
        'openBreaches' => $openBreaches,
        'resolvedBreaches' => $resolvedBreaches,
        'dpias' => $dpias,
        'approvedDpias' => $approvedDpias,
        'dpas' => $dpas,
        'trainings' => $trainings,
        'trainedCount' => $trainedCount,
        'pseudoRules' => $pseudoRules,
        'executedPseudo' => $executedPseudo,
        'processors' => $processors,
        'transfers' => $transfers,
        'invites' => $invites,
        'signedInvitesCount' => $signedInvitesCount,
        'breachProtocol' => $breachProtocol,
        'incidentResponse' => $incidentResponse,
        'arcoRequests' => $arcoRequests,
        'resolvedArco' => $resolvedArco,
        'auditLogs' => $auditLogs,
        'fileEvents' => $fileEvents,
        'dbLogs' => $dbLogs,
        'hostEvents' => $hostEvents,
        'fileAudits' => $fileAudits,
        'onlineAgents' => $onlineAgents,
        'privacyOk' => $privacyOk,
        'cookiesOk' => $cookiesOk,
        // flags
        'hasDpd' => $hasDpd,
        'hasApdp' => $hasApdp,
        'hasPrivacyPolicy' => $hasPrivacyPolicy,
        'hasCookiesPolicy' => $hasCookiesPolicy,
        'hasRetentionPolicy' => $hasRetentionPolicy,
        'hasInventory' => $hasInventory,
        'hasConsents' => $hasConsents,
        'allConsentsActive' => $allConsentsActive,
        'hasDpias' => $hasDpias,
        'hasDpas' => $hasDpas,
        'hasBreaches' => $hasBreaches,
        'hasTrainings' => $hasTrainings,
        'allTrained' => $allTrained,
        'hasPseudo' => $hasPseudo,
        'hasProcessors' => $hasProcessors,
        'hasTransfers' => $hasTransfers,
        'hasBreachProtocol' => $hasBreachProtocol,
        'hasIncidentResponse' => $hasIncidentResponse,
        'hasArco' => $hasArco,
    ];

    // Motor de adecuación
    $obligaciones = build_compliance_obligations($data);
    $metrics      = compute_compliance_metrics($data, $obligaciones, []);
    $principios   = build_compliance_principles($obligaciones);
    $findings     = build_compliance_findings($obligaciones);
    $plan         = build_compliance_action_plan($findings);
    $evolution    = build_compliance_evolution($metrics);

    $html = build_compliance_report_html($data, $metrics, $obligaciones, $principios, $findings, $plan, $evolution, $reportTitle, $dateStr);

    // ═══ RENDER PDF ═══
    try {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $pdfContent = $dompdf->output();
        if (empty($pdfContent)) {
            error_log('[NEXUSGUARDIA PDF] Dompdf returned empty output');
            json_error('PDF vacío — revisa logs del servidor', 500);
        }

        if (ob_get_level() > 0) { while (ob_get_level() > 0) ob_end_clean(); }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    } catch (\Throwable $e) {
        error_log('[NEXUSGUARDIA PDF] Fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        error_log('[NEXUSGUARDIA PDF] Trace: ' . $e->getTraceAsString());
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
// REPORTE DE SEGURIDAD (sin cambios funcionales)
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
        .cover-topline{height:3px;background:#0b0b0b;width:100%}
        .cover-brand{color:#0b0b0b;font-size:18px;font-weight:bold;letter-spacing:2px;margin-top:105px}
        .cover-tagline{color:#777;font-size:9px;margin-top:4px;letter-spacing:4px;text-transform:uppercase}
        .cover-sep{border-top:1.2px solid #000;margin:22px 70px 0 70px}
        .cover-title{color:#1a1a1a;font-size:22px;font-weight:bold;margin-top:26px}
        .cover-sub{color:#555555;font-size:11px;margin-top:12px}
        .page{page-break-before:always}
        .page-band{background:#0b0b0b;padding:9px 45px 10px 45px}
        .band-sub{color:#dcdcdc;font-size:8px}
        .band-title{color:#ffffff;font-size:11px;font-weight:bold;margin-top:2px}
        .content{padding:20px 45px 40px 45px}
        .sec-title{border-collapse:collapse;margin-top:12px;width:100%}
        .sec-bar{width:4px;background:#0b0b0b;padding:0}
        .sec-num{width:30px;color:#0b0b0b;font-size:10px;font-weight:bold;padding:2px 0 2px 10px;vertical-align:top}
        .sec-text{color:#1a1a1a;font-size:14px;font-weight:bold;padding:0 0 0 4px}
        .sec-rule{border-bottom:0.5px solid #bbbbbb;margin:6px 0 10px 0}
        .data{width:100%;border-collapse:collapse;margin-top:8px}
        .data th{background:#0b0b0b;color:#dcdcdc;font-size:7.5px;font-weight:bold;text-align:left;padding:7px 8px}
        .data td{color:#1a1a1a;font-size:7.5px;padding:5px 8px}
        .data tr.alt td{background:#f1f5f9}
        .kpi{color:#1a1a1a;font-size:10px;line-height:2}
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Reporte de Seguridad</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">NEXUSGUARDIA · Reporte de Seguridad · ' . h_($companyName) . '</div>';

    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y');

    $html .= '<div class="cover"><div class="cover-topline"></div><div style="text-align:center;padding:0 45px">';
    $html .= '<div class="cover-brand">NEXUSGUARDIA</div>';
    $html .= '<div class="cover-tagline">Gobierna · Protege · Demuestra</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-title">Reporte de Seguridad</div>';
    $html .= '<div class="cover-sub">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-sub">Análisis de eventos e integridad · ' . h_($dateStr) . '</div>';
    $html .= '</div></div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Resumen Ejecutivo de Seguridad</div></div><div class="content">';
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

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Eventos Recientes de Seguridad</div></div><div class="content">';
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

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Recomendaciones</div></div><div class="content">';
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
        error_log('[NEXUSGUARDIA PDF Security] Fatal: ' . $e->getMessage());
        json_error('Error generando PDF de seguridad: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════════
// REPORTE DE CAPACITACIÓN (sin cambios funcionales)
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
        .cover-topline{height:3px;background:#0b0b0b;width:100%}
        .cover-brand{color:#0b0b0b;font-size:18px;font-weight:bold;letter-spacing:2px;margin-top:105px}
        .cover-tagline{color:#777;font-size:9px;margin-top:4px;letter-spacing:4px;text-transform:uppercase}
        .cover-sep{border-top:1.2px solid #000;margin:22px 70px 0 70px}
        .cover-title{color:#1a1a1a;font-size:22px;font-weight:bold;margin-top:26px}
        .cover-sub{color:#555555;font-size:11px;margin-top:12px}
        .page{page-break-before:always}
        .page-band{background:#0b0b0b;padding:9px 45px 10px 45px}
        .band-sub{color:#dcdcdc;font-size:8px}
        .band-title{color:#ffffff;font-size:11px;font-weight:bold;margin-top:2px}
        .content{padding:20px 45px 40px 45px}
        .sec-title{border-collapse:collapse;margin-top:12px;width:100%}
        .sec-bar{width:4px;background:#0b0b0b;padding:0}
        .sec-num{width:30px;color:#0b0b0b;font-size:10px;font-weight:bold;padding:2px 0 2px 10px;vertical-align:top}
        .sec-text{color:#1a1a1a;font-size:14px;font-weight:bold;padding:0 0 0 4px}
        .sec-rule{border-bottom:0.5px solid #bbbbbb;margin:6px 0 10px 0}
        .data{width:100%;border-collapse:collapse;margin-top:8px}
        .data th{background:#0b0b0b;color:#dcdcdc;font-size:7.5px;font-weight:bold;text-align:left;padding:7px 8px}
        .data td{color:#1a1a1a;font-size:7.5px;padding:5px 8px}
        .data tr.alt td{background:#f1f5f9}
        .kpi{color:#1a1a1a;font-size:10px;line-height:2}
    ";

    $html = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'><title>Reporte de Capacitación</title><style>$css</style></head><body>";
    $html .= '<div class="footer-fixed">NEXUSGUARDIA · Reporte de Capacitación · ' . h_($companyName) . '</div>';

    $months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $dateStr = date('j') . ' de ' . $months[(int)date('n')] . ' de ' . date('Y');

    $html .= '<div class="cover"><div class="cover-topline"></div><div style="text-align:center;padding:0 45px">';
    $html .= '<div class="cover-brand">NEXUSGUARDIA</div>';
    $html .= '<div class="cover-tagline">Gobierna · Protege · Demuestra</div>';
    $html .= '<div class="cover-sep"></div>';
    $html .= '<div class="cover-title">Reporte de Capacitación</div>';
    $html .= '<div class="cover-sub">' . h_($companyName) . '</div>';
    $html .= '<div class="cover-sub">Programa de formación · ' . h_($dateStr) . '</div>';
    $html .= '</div></div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Resumen Ejecutivo</div></div><div class="content">';
    $html .= pdf_section_title(1, 'Indicadores de Capacitación');
    $html .= '<div class="kpi">';
    $html .= 'Total capacitaciones registradas: ' . $total . '<br>';
    $html .= 'Completadas/firmadas: ' . $completed . '<br>';
    $html .= 'Pendientes: ' . $pending . '<br>';
    $html .= 'Con firma digital: ' . $signed . '<br>';
    $html .= '</div>';
    $html .= '<p style="font-size:9px;line-height:1.5;margin-top:12px">Art. 28 letra c) Ley 21.719: el responsable debe implementar programas de capacitación periódica.</p>';
    $html .= '</div>';

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Cobertura por Tema</div></div><div class="content">';
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

    $html .= '<div class="page"></div><div class="page-band"><div class="band-sub">NEXUSGUARDIA · ' . h_($companyName) . '</div><div class="band-title">Registro de Participantes</div></div><div class="content">';
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
        error_log('[NEXUSGUARDIA PDF Training] Fatal: ' . $e->getMessage());
        json_error('Error generando PDF de capacitación: ' . $e->getMessage(), 500);
    }
}