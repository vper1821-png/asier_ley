<?php
require_once __DIR__ . '/../config.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';
$tab = $_GET['tab'] ?? 'overview';

// ── Actions (antes de HTML) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $col = $_POST['collection'] ?? '';
    if (isset($_POST['create_item']) && $col) {
        $payload = ['token' => $token];
        foreach (($_POST['fields'] ?? []) as $k => $v) $payload[$k] = $v;
        $res = api_post_form('/api/compliance/' . urlencode($col), $payload);
        if (!empty($res['success'])) $msg = 'Registro creado.'; else $err = $res['error'] ?? 'Error al crear.';
    } elseif (isset($_POST['delete_item']) && $col) {
        $res = api_delete('/api/compliance/' . urlencode($col) . '/' . urlencode($_POST['item_id']), ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Registro eliminado.'; else $err = $res['error'] ?? 'Error al eliminar.';
    } elseif (isset($_POST['item_action']) && $col) {
        $res = api_post_form('/api/compliance/' . urlencode($col) . '/' . urlencode($_POST['item_id']) . '/' . urlencode($_POST['item_action']), ['token' => $token, 'response' => $_POST['response'] ?? '']);
        if (!empty($res['success'])) $msg = 'Acción aplicada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['save_config'])) {
        api_post_form('/api/invisia/compliance/config', [
            'token' => $token,
            'companyName' => $_POST['companyName'] ?? '',
            'dpdName' => $_POST['dpdName'] ?? '',
            'dpdEmail' => $_POST['dpdEmail'] ?? '',
            'dpdPhone' => $_POST['dpdPhone'] ?? '',
            'privacyPolicyUrl' => $_POST['privacyPolicyUrl'] ?? '',
            'cookiesPolicyUrl' => $_POST['cookiesPolicyUrl'] ?? '',
            'dataRetentionPolicy' => $_POST['dataRetentionPolicy'] ?? '',
            'apdpRegistered' => !empty($_POST['apdpRegistered']) ? '1' : '',
            'complianceLevel' => $_POST['complianceLevel'] ?? 'basic',
        ]);
        $msg = 'Configuración guardada.';
    } elseif (isset($_POST['update_config'])) {
        api_post_form('/api/invisia/compliance/config', [
            'token' => $token,
            'privacyPolicyUrl' => $_POST['privacyPolicyUrl'] ?? '',
            'cookiesPolicyUrl' => $_POST['cookiesPolicyUrl'] ?? '',
            'dataRetentionPolicy' => $_POST['dataRetentionPolicy'] ?? '',
        ]);
        $msg = 'Políticas de privacidad actualizadas.';
    } elseif (isset($_POST['assign_training'])) {
        $res = api_post_form('/api/compliance/invites/' . urlencode($_POST['invite_id'] ?? '') . '/assign-training', [
            'token' => $token,
            'trainingId' => $_POST['training_id'] ?? '',
        ]);
        if (!empty($res['success'])) $msg = 'Firma asignada a la capacitación.';
        else $err = $res['error'] ?? 'Error al asignar la firma.';
    } elseif (isset($_POST['unassign_invite'])) {
        $res = api_post_form('/api/compliance/invites/' . urlencode($_POST['invite_id'] ?? '') . '/unassign', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Firma removida de la capacitación.';
        else $err = $res['error'] ?? 'Error al quitar la firma.';
    }
}

// ── Data ──
$overviewRes = api_get('/api/compliance/overview', ['token' => $token]);
$ov = $overviewRes['overview'] ?? [];
$config = api_get('/api/invisia/compliance/config', ['token' => $token]);
if (!is_array($config)) $config = [];

$fetchList = function ($col) use ($token) {
    $r = api_get('/api/compliance/' . urlencode($col), ['token' => $token]);
    return (is_array($r) && empty($r['error'])) ? $r : [];
};
$consents = $fetchList('consents');
$inventory = $fetchList('inventory');
$breaches = $fetchList('breaches');
$trainings = $fetchList('trainings');
$pseudoRules = $fetchList('pseudonymization');
$arcoRequests = $fetchList('arco-requests');
$allInvites = $fetchList('invites');
$signedInvites = array_values(array_filter($allInvites, fn($i) => !empty($i['signed'])));

$items = [];
if (!in_array($tab, ['overview', 'violations'])) {
    $items = $fetchList($tab);
}

// ── Checklist (mismo criterio que React) ──
$CHECKLIST = [
    ['id' => 'dpd', 'label' => 'DPD Designado', 'desc' => 'Aplicable cuando la naturaleza o escala del tratamiento exige esta función', 'icon' => 'users', 'done' => !empty($config['dpdEmail'])],
    ['id' => 'apdp', 'label' => 'Modelo certificado', 'desc' => 'Registro o evidencia de un modelo de prevención certificado, cuando corresponda', 'icon' => 'shield', 'done' => ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) && !empty($config['apdpRegistrationNumber'])],
    ['id' => 'inventory', 'label' => 'Inventario de Datos', 'desc' => 'Registro documentado para sustentar información y transparencia del tratamiento', 'icon' => 'database', 'done' => count(array_filter($inventory, fn($i) => !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories']))) > 0],
    ['id' => 'privacy', 'label' => 'Política de Privacidad', 'desc' => 'Política actualizada y accesible para los titulares', 'icon' => 'fileText', 'done' => !empty($config['privacyPolicyUrl'])],
    ['id' => 'consents', 'label' => 'Consentimientos', 'desc' => 'Consentimientos activos y trazables cuando sean la base de licitud', 'icon' => 'check', 'done' => count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0],
    ['id' => 'breach_protocol', 'label' => 'Protocolo de Brechas', 'desc' => 'Procedimiento documentado de gestión y notificación de incidentes', 'icon' => 'alert', 'done' => !empty($config['breachProtocolUrl']) || count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0],
    ['id' => 'arco', 'label' => 'Canal de derechos', 'desc' => 'Canal operativo para acceso, rectificación, supresión, oposición y portabilidad', 'icon' => 'users', 'done' => count($arcoRequests) > 0],
    ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'desc' => 'Medida de seguridad aplicada según la naturaleza y riesgo del tratamiento', 'icon' => 'search', 'done' => count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0],
    ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'desc' => 'Plan documentado o evidencia de incidentes gestionados', 'icon' => 'alert', 'done' => !empty($config['incidentResponsePlan']) || count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0],
    ['id' => 'training', 'label' => 'Capacitación', 'desc' => 'Formación completada y respaldada con evidencia', 'icon' => 'info', 'done' => count(array_filter($trainings, fn($t) => !empty($t['completed']))) > 0],
];
$checklistDone = count(array_filter($CHECKLIST, fn($c) => $c['done']));
$checklistTotal = count($CHECKLIST);
$checklistPct = (int)round($checklistDone / $checklistTotal * 100);
$pctColor = $checklistPct >= 70 ? 'text-emerald-400' : ($checklistPct >= 40 ? 'text-yellow-400' : 'text-red-400');
$pctBar = $checklistPct >= 70 ? 'bg-emerald-500' : ($checklistPct >= 40 ? 'bg-yellow-500' : 'bg-red-500');

$activeConsents = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
$activeBreaches = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') !== 'resolved'));
$criticalBreaches = count(array_filter($breaches, fn($b) => ($b['severity'] ?? '') === 'critical'));
$completedTrainings = count(array_filter($trainings, fn($t) => !empty($t['completed'])));
$sensitiveItems = count(array_filter($inventory, fn($i) => !empty($i['sensitive'])));

// ── Violaciones (idéntico a React) ──
$violations = [
    ['title' => 'Incumplir solicitudes ARCO', 'severity' => 'leve', 'art' => 'Arts. 8-13', 'fine' => 'Hasta 5.000 UTM', 'desc' => 'No responder, obstruir o retardar injustificadamente las solicitudes de acceso, rectificación, supresión, oposición o portabilidad dentro del plazo legal de 10 días hábiles.'],
    ['title' => 'Falta de consentimiento', 'severity' => 'leve', 'art' => 'Art. 12', 'fine' => 'Hasta 5.000 UTM', 'desc' => 'Tratar datos personales sin contar con el consentimiento explícito e informado del titular, o no acreditar debidamente su obtención.'],
    ['title' => 'No llevar inventario de datos', 'severity' => 'leve', 'art' => 'Art. 15', 'fine' => 'Hasta 5.000 UTM', 'desc' => 'No mantener un registro actualizado de las bases de datos con datos personales, incluyendo categorías, finalidades, base legal y medidas de seguridad.'],
    ['title' => 'Tratar datos sensibles sin autorización', 'severity' => 'grave', 'art' => 'Art. 16', 'fine' => 'Hasta 10.000 UTM', 'desc' => 'Procesar datos sensibles (salud, biometría, religión, orientación sexual, etc.) sin el consentimiento explícito del titular o sin cumplir las condiciones legales.'],
    ['title' => 'Transferencia internacional no autorizada', 'severity' => 'grave', 'art' => 'Art. 21', 'fine' => 'Hasta 10.000 UTM', 'desc' => 'Transferir datos personales a países que no otorguen un nivel adecuado de protección sin las garantías suficientes o sin autorización del titular.'],
    ['title' => 'No implementar medidas de seguridad', 'severity' => 'grave', 'art' => 'Art. 25', 'fine' => 'Hasta 10.000 UTM', 'desc' => 'No adoptar las medidas técnicas, organizativas y de seguridad necesarias para proteger los datos personales contra accesos no autorizados o destrucción.'],
    ['title' => 'No reportar brechas de seguridad', 'severity' => 'gravisima', 'art' => 'Art. 26', 'fine' => 'Hasta 20.000 UTM', 'desc' => 'No notificar a la APDP las violaciones de seguridad que afecten datos personales dentro del plazo establecido, especialmente cuando involucren datos sensibles o de niños.'],
    ['title' => 'No designar DPD', 'severity' => 'gravisima', 'art' => 'Art. 28', 'fine' => 'Hasta 20.000 UTM', 'desc' => 'No contar con un Delegado de Protección de Datos cuando sea obligatorio por el volumen o naturaleza de los datos tratados, o no publicar sus datos de contacto.'],
    ['title' => 'No registrarse en APDP', 'severity' => 'gravisima', 'art' => 'Art. 31', 'fine' => 'Hasta 20.000 UTM', 'desc' => 'No inscribirse en el Registro de la Agencia de Protección de Datos Personales ni mantener actualizada la información del tratamiento de datos.'],
    ['title' => 'Violación de datos de niños', 'severity' => 'gravisima', 'art' => 'Art. 17', 'fine' => 'Hasta 20.000 UTM', 'desc' => 'Tratar datos personales de niños, niñas o adolescentes sin el consentimiento del titular de la patria potestad o sin implementar las salvaguardas especiales requeridas.'],
    ['title' => 'Reincidencia en infracciones graves', 'severity' => 'gravisima', 'art' => 'Art. 35', 'fine' => 'Hasta 20.000 UTM (triplicable)', 'desc' => 'Cometer una infracción grave dentro del período de 2 años desde la sanción anterior. Las multas pueden triplicarse, alcanzando hasta 60.000 UTM.'],
];

// SVG icons idénticos a React
function cIcon($name, $cls = 'w-4 h-4') {
    $paths = [
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'database' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
        'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>',
        'fileText' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        'xmark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        'plus' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>',
        'search' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'info' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'pen' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>',
    ];
    return '<svg class="' . $cls . '" fill="none" viewBox="0 0 24 24" stroke="currentColor">' . ($paths[$name] ?? '') . '</svg>';
}

$tabs = [
    ['id' => 'overview', 'label' => 'Resumen', 'icon' => 'shield'],
    ['id' => 'inventory', 'label' => 'Inventario', 'icon' => 'database'],
    ['id' => 'consents', 'label' => 'Consentimientos', 'icon' => 'check'],
    ['id' => 'privacy', 'label' => 'Política Privacidad', 'icon' => 'fileText'],
    ['id' => 'breaches', 'label' => 'Brechas', 'icon' => 'alert'],
    ['id' => 'violations', 'label' => 'Violaciones', 'icon' => 'alert'],
    ['id' => 'dpia', 'label' => 'Eval. Impacto', 'icon' => 'shield'],
    ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'icon' => 'search'],
    ['id' => 'trainings', 'label' => 'Capacitaciones', 'icon' => 'info'],
    ['id' => 'invites', 'label' => 'Firmas', 'icon' => 'pen'],
    ['id' => 'processors', 'label' => 'Encargados', 'icon' => 'users'],
    ['id' => 'transfers', 'label' => 'Transferencias', 'icon' => 'globe'],
    ['id' => 'files', 'label' => 'Archivos', 'icon' => 'fileText'],
    ['id' => 'file-audit', 'label' => 'Auditoría Archivos', 'icon' => 'fileText'],
];
$activeLabel = 'Compliance';
foreach ($tabs as $t) { if ($t['id'] === $tab) $activeLabel = $t['label']; }

$pageTitle = 'Compliance';
$currentPage = 'compliance';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.compliance-workspace .compliance-header-inner { max-width: 1500px; margin: 0 auto; }
.compliance-workspace .compliance-context { display: flex; align-items: center; gap: 10px; color: var(--text-subtle); font-size: 10px; }
.compliance-workspace .compliance-context span { display: inline-flex; align-items: center; gap: 6px; }
.compliance-workspace .compliance-context span + span::before { content: ''; width: 3px; height: 3px; margin-right: 4px; border-radius: 50%; background: var(--text-subtle); }
.compliance-workspace .compliance-score-chip { display: flex; align-items: center; gap: 10px; min-height: 42px; padding: 7px 12px; border: 1px solid var(--border-color); border-radius: 10px; background: var(--bg-panel); }
.compliance-workspace .compliance-score-value { color: var(--text-heading); font-size: 16px; font-weight: 750; line-height: 1; }
.compliance-workspace .compliance-score-label { color: var(--text-subtle); font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.compliance-workspace .compliance-nav-wrap { border-top: 1px solid var(--border-subtle); }
.compliance-workspace .compliance-nav { max-width: 1500px; margin: 0 auto; padding: 8px 16px; }
.compliance-workspace .compliance-panel,
.compliance-workspace .rounded-xl.border.border-border-theme { box-shadow: 0 12px 34px color-mix(in srgb, var(--shadow-color) 38%, transparent); }
.compliance-workspace .rounded-xl:has(> form:not(.inline)) { border-color: var(--border-color) !important; background: color-mix(in srgb, var(--bg-panel) 92%, transparent) !important; }
.compliance-workspace .rounded-xl:has(> form:not(.inline)) > .flex:first-child { padding-bottom: 13px; border-bottom: 1px solid var(--border-subtle); }
.compliance-workspace form:not(.inline) { row-gap: 16px; }
.compliance-workspace form:not(.inline) > div { min-width: 0; }
.compliance-workspace form:not(.inline) button[type="submit"] { min-height: 40px; border-radius: 9px; padding-inline: 16px; font-size: 11px; font-weight: 700; box-shadow: none; }
.compliance-workspace form:not(.inline) button[type="button"] { min-height: 40px; border-radius: 9px; }
.compliance-workspace form:not(.inline) [class*="col-span"]:last-child.flex { margin-top: 2px; padding-top: 14px; border-top: 1px solid var(--border-subtle); }
.compliance-workspace select[multiple] { min-height: 132px !important; padding-block: 8px; }
.compliance-workspace select[multiple] option { padding: 7px 9px; border-radius: 5px; }
.compliance-workspace textarea { line-height: 1.55; }
.compliance-workspace .compliance-section-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; padding-bottom: 14px; border-bottom: 1px solid var(--border-subtle); }
.compliance-workspace .compliance-section-title { color: var(--text-heading); font-size: 17px; font-weight: 700; letter-spacing: -.02em; }
.compliance-workspace .compliance-section-desc { max-width: 760px; color: var(--text-muted); font-size: 11px; line-height: 1.55; margin-top: 4px; }
.compliance-workspace .compliance-stat { position: relative; min-height: 104px; padding: 15px; border: 1px solid var(--border-color); border-radius: 12px; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); overflow: hidden; }
.compliance-workspace .compliance-stat::after { content: ''; position: absolute; top: 0; left: 0; width: 3px; height: 100%; background: var(--accent); opacity: .7; }
.compliance-workspace .compliance-list-row { border: 1px solid var(--border-color); border-radius: 11px; background: color-mix(in srgb, var(--bg-panel) 85%, transparent); transition: border-color .18s ease, background-color .18s ease; }
.compliance-workspace .compliance-list-row:hover { border-color: var(--accent-border); background: color-mix(in srgb, var(--bg-elevated) 85%, transparent); }
.compliance-workspace .compliance-empty { padding: 44px 20px; border: 1px dashed var(--border-color); border-radius: 12px; background: color-mix(in srgb, var(--bg-panel) 55%, transparent); text-align: center; }
.compliance-workspace .compliance-empty strong { display: block; color: var(--text-heading); font-size: 13px; font-weight: 650; }
.compliance-workspace .compliance-empty span { display: block; color: var(--text-subtle); font-size: 10px; margin-top: 5px; }
.compliance-workspace .compliance-action { display: inline-flex; min-height: 34px; align-items: center; justify-content: center; padding: 0 11px; border: 1px solid var(--accent-border); border-radius: 8px; background: var(--accent-subtle); color: var(--accent); font-size: 10px; font-weight: 650; transition: background-color .15s ease; }
.compliance-workspace .compliance-action:hover { background: color-mix(in srgb, var(--accent) 18%, transparent); }

/* Professional form classes */
.compliance-workspace .compliance-form-row { display: grid; grid-template-columns: 1fr; gap: 1rem; }
@media (min-width: 768px) { .compliance-workspace .compliance-form-row { grid-template-columns: repeat(2, 1fr); } }
.compliance-workspace .compliance-form-row.grid-cols-3 { grid-template-columns: 1fr; }
@media (min-width: 768px) { .compliance-workspace .compliance-form-row.grid-cols-3 { grid-template-columns: repeat(3, 1fr); } }
.compliance-workspace .compliance-form-cell { display: flex; flex-direction: column; }
.compliance-workspace .compliance-form-label { display: block; color: var(--text-body); font-size: 11px; font-weight: 600; margin-bottom: 0.45rem; }
.compliance-workspace .compliance-form-label .required { color: #ef4444; margin-left: 2px; }
.compliance-workspace .compliance-input { width: 100%; min-height: 42px; padding: 0.625rem 0.875rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: color-mix(in srgb, var(--bg-input) 92%, transparent); color: var(--text-heading); font-size: 13px; transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease; outline: none; }
.compliance-workspace .compliance-input:hover { border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color)); }
.compliance-workspace .compliance-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-subtle); }
.compliance-workspace .compliance-select { width: 100%; min-height: 42px; padding: 0.625rem 0.875rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: color-mix(in srgb, var(--bg-input) 92%, transparent); color: var(--text-heading); font-size: 13px; transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease; outline: none; cursor: pointer; }
.compliance-workspace .compliance-select:hover { border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color)); }
.compliance-workspace .compliance-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-subtle); }
.compliance-workspace .compliance-textarea { width: 100%; min-height: 96px; padding: 0.625rem 0.875rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: color-mix(in srgb, var(--bg-input) 92%, transparent); color: var(--text-heading); font-size: 13px; line-height: 1.55; transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease; outline: none; resize: vertical; }
.compliance-workspace .compliance-textarea:hover { border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color)); }
.compliance-workspace .compliance-textarea:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-subtle); }
.compliance-workspace .compliance-btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; min-height: 40px; padding: 0 1rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all .18s ease; box-shadow: none; }
.compliance-workspace .compliance-btn-primary:hover { background: linear-gradient(135deg, #34d399 0%, #2dd4bf 100%); transform: translateY(-1px); }
.compliance-workspace .compliance-btn-secondary { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; min-height: 40px; padding: 0 1rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: color-mix(in srgb, var(--bg-panel) 92%, transparent); color: var(--text-heading); font-size: 11px; font-weight: 700; cursor: pointer; transition: all .18s ease; }
.compliance-workspace .compliance-btn-secondary:hover { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, transparent); }
.compliance-workspace .compliance-form-actions { display: flex; justify-content: flex-end; align-items: center; gap: 0.75rem; padding-top: 1rem; margin-top: 0.5rem; border-top: 1px solid var(--border-subtle); }
.compliance-workspace .compliance-fieldset { border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 1rem; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); }

/* DPIA Wizard Styles (preserved for other sections) */
.compliance-workspace .dpia-wizard-progress { padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-subtle); }
.compliance-workspace .dpia-wizard-progress-bar { background: color-mix(in srgb, var(--bg-elevated) 50%, transparent); }
.compliance-workspace .dpia-wizard-progress-fill { background: linear-gradient(90deg, var(--accent) 0%, #2dd4bf 100%); }
.compliance-workspace .dpia-wizard-step-indicator { transition: all 0.3s ease; }
.compliance-workspace .dpia-step-number { transition: all 0.3s ease; }
.compliance-workspace .dpia-step-indicator.active .dpia-step-number { background: var(--accent); color: white; border-color: var(--accent); }
.compliance-workspace .dpia-step-indicator.active .dpia-step-label { color: var(--text-heading); }
.compliance-workspace .dpia-step-indicator.completed .dpia-step-number { background: #10b981; color: white; border-color: #10b981; }
.compliance-workspace .dpia-step-indicator.completed .dpia-step-label { color: var(--text-heading); }
.compliance-workspace .dpia-wizard-step { animation: fadeIn 0.3s ease; }
.compliance-workspace .dpia-wizard-step.hidden { display: none; }
.compliance-workspace .compliance-fieldset-legend { display: block; color: var(--text-heading); font-size: 12px; font-weight: 600; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-subtle); }
.compliance-workspace .compliance-checkbox-group { display: flex; align-items: flex-start; gap: 0.5rem; }
.compliance-workspace .compliance-checkbox-group input[type="checkbox"] { width: 16px; height: 16px; margin-top: 2px; accent-color: var(--accent); cursor: pointer; }
.compliance-workspace .compliance-checkbox-group label { color: var(--text-body); font-size: 11px; line-height: 1.5; cursor: pointer; }
.compliance-workspace .compliance-checkbox-group label strong { color: var(--text-heading); }

/* Breach Wizard Styles (specific for breaches form) */
.compliance-workspace .wizard-container { max-width: 900px; margin: 0 auto; }
.compliance-workspace .wizard-progress { margin-bottom: 2rem; }
.compliance-workspace .wizard-progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.compliance-workspace .wizard-step-counter { font-size: 12px; font-weight: 600; color: var(--text-heading); letter-spacing: 0.02em; }
.compliance-workspace .wizard-step-titles { display: flex; justify-content: space-between; margin-top: 0.5rem; padding: 0 0.5rem; }
.compliance-workspace .wizard-step-title { font-size: 10px; font-weight: 500; color: var(--text-subtle); text-align: center; max-width: 80px; }
.compliance-workspace .wizard-step-title.active { color: var(--accent); font-weight: 600; }
.compliance-workspace .wizard-step-title.completed { color: var(--text-heading); }
.compliance-workspace .wizard-progress-bar { width: 100%; height: 6px; background: var(--bg-elevated); border-radius: 3px; overflow: hidden; }
.compliance-workspace .wizard-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981 0%, #14b8a6 100%); border-radius: 3px; transition: width 0.4s ease; }
.compliance-workspace .wizard-step-content { display: none; animation: wizardFadeIn 0.3s ease; }
.compliance-workspace .wizard-step-content.active { display: block; }
.compliance-workspace .wizard-navigation { display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1rem; border-top: 1px solid var(--border-subtle); }
.compliance-workspace .wizard-nav-btn { min-height: 40px; padding: 0 1.25rem; border-radius: 0.7rem; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
.compliance-workspace .wizard-nav-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.compliance-workspace .wizard-nav-btn-prev { border: 1px solid var(--border-color); background: color-mix(in srgb, var(--bg-panel) 92%, transparent); color: var(--text-heading); }
.compliance-workspace .wizard-nav-btn-prev:hover:not(:disabled) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, transparent); }
.compliance-workspace .wizard-nav-btn-next { background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%); color: white; border: none; }
.compliance-workspace .wizard-nav-btn-next:hover:not(:disabled) { background: linear-gradient(135deg, #34d399 0%, #2dd4bf 100%); transform: translateY(-1px); }
.compliance-workspace .wizard-nav-btn-submit { background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: white; border: none; }
.compliance-workspace .wizard-nav-btn-submit:hover:not(:disabled) { background: linear-gradient(135deg, #60a5fa 0%, #818cf8 100%); transform: translateY(-1px); }
.compliance-workspace .wizard-step-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 1rem; font-size: 11px; color: #f87171; display: none; }
.compliance-workspace .wizard-step-error.show { display: block; }
.compliance-workspace .compliance-hint { display: block; color: var(--text-subtle); font-size: 9px; margin-top: 0.35rem; line-height: 1.4; }
@keyframes wizardFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

/* Wizard-specific classes */
.compliance-workspace .wizard-progress { margin-bottom: 1.5rem; }
.compliance-workspace .wizard-progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.compliance-workspace .wizard-progress-title { font-size: 12px; font-weight: 600; color: var(--text-heading); }
.compliance-workspace .wizard-progress-step { font-size: 11px; color: var(--text-subtle); font-weight: 600; }
.compliance-workspace .wizard-progress-steps { font-size: 11px; color: var(--accent); font-weight: 700; }
.compliance-workspace .wizard-steps-indicator { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; padding: 1rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); }
.compliance-workspace .wizard-step-dot { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; flex: 1; position: relative; }
.compliance-workspace .wizard-step-dot::after { content: ''; position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; background: var(--border-subtle); z-index: 0; }
.compliance-workspace .wizard-step-dot:last-child::after { display: none; }
.compliance-workspace .wizard-step-dot.active .wizard-step-number { background: var(--accent); color: white; border-color: var(--accent); transform: scale(1.1); }
.compliance-workspace .wizard-step-dot.completed .wizard-step-number { background: var(--accent); color: white; border-color: var(--accent); }
.compliance-workspace .wizard-step-number { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--border-subtle); background: var(--bg-elevated); color: var(--text-subtle); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 1; transition: all 0.3s ease; }
.compliance-workspace .wizard-step-label { font-size: 9px; font-weight: 600; color: var(--text-subtle); text-align: center; }
.compliance-workspace .wizard-step-dot.active .wizard-step-label { color: var(--accent); }
.compliance-workspace .wizard-step-dot.completed .wizard-step-label { color: var(--accent); }
.compliance-workspace .wizard-step-title { font-size: 14px; font-weight: 700; color: var(--text-heading); margin-bottom: 1rem; }
.compliance-workspace .wizard-navigation { display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1rem; border-top: 1px solid var(--border-subtle); }
.compliance-workspace .wizard-btn-prev { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: transparent; color: var(--text-heading); font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .wizard-btn-prev:hover:not(:disabled) { background: var(--bg-elevated); border-color: var(--accent-border); }
.compliance-workspace .wizard-btn-prev:disabled { opacity: 0.5; cursor: not-allowed; }
.compliance-workspace .wizard-btn-next { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .wizard-btn-next:hover { background: linear-gradient(135deg, #34d399 0%, #2dd4bf 100%); transform: translateY(-1px); }
.compliance-workspace .wizard-btn-submit { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .wizard-btn-submit:hover { background: linear-gradient(135deg, #60a5fa 0%, #818cf8 100%); transform: translateY(-1px); }
.compliance-workspace .wizard-fieldset { padding: 1.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: color-mix(in srgb, var(--bg-panel) 92%, transparent); }
.compliance-workspace .wizard-fieldset-title { font-size: 12px; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-subtle); }

/* Training wizard steps */
.compliance-workspace .wizard-step { display: none; animation: wizardFadeIn 0.3s ease; }
.compliance-workspace .wizard-step.hidden { display: none !important; }
.compliance-workspace .wizard-step.active { display: block; }

/* Wizard step content - unified for all wizards */
.compliance-workspace .wizard-step-content { display: none !important; animation: fadeIn 0.3s ease; }
.compliance-workspace .wizard-step-content.hidden { display: none !important; }
.compliance-workspace .wizard-step-content.active { display: block !important; }

/* Wizard submit button visibility - use class instead of inline style */
.compliance-workspace .wizard-submit-btn { display: none !important; }
.compliance-workspace .wizard-submit-btn.visible { display: inline-flex !important; }

/* In-page error messages */
.compliance-workspace .wizard-error-message {
    display: none;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 0.75rem;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    align-items: flex-start;
    gap: 0.75rem;
    animation: slideDown 0.3s ease;
}
.compliance-workspace .wizard-error-message.show { display: flex; }
.compliance-workspace .wizard-error-message svg { flex-shrink: 0; margin-top: 2px; }
.compliance-workspace .wizard-error-message-text { color: #f87171; font-size: 11px; line-height: 1.5; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* Better title alignment */
.compliance-workspace .wizard-step-title {
    text-align: left;
    margin-bottom: 1rem;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--border-subtle);
}

/* Transfer Wizard Specific Styles */
.compliance-workspace .transfer-wizard-container { max-width: 900px; margin: 0 auto; }

/* Inventory Wizard Specific Styles (RAT - Registro de Actividades de Tratamiento) */
.compliance-workspace .inventory-wizard-container { max-width: 950px; margin: 0 auto; }
.compliance-workspace .inventory-wizard-progress { margin-bottom: 1.5rem; padding: 1rem; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); border: 1px solid var(--border-color); border-radius: 0.75rem; }
.compliance-workspace .inventory-wizard-progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.compliance-workspace .inventory-wizard-progress-title { font-size: 12px; font-weight: 600; color: var(--text-heading); }
.compliance-workspace .inventory-wizard-progress-steps { font-size: 11px; color: var(--accent); font-weight: 700; }
.compliance-workspace .inventory-wizard-progress-bar { width: 100%; height: 6px; background: var(--bg-elevated); border-radius: 3px; overflow: hidden; margin-bottom: 1rem; }
.compliance-workspace .inventory-wizard-progress-fill { height: 100%; background: linear-gradient(90deg, #3b82f6 0%, #6366f1 100%); border-radius: 3px; transition: width 0.4s ease; }
.compliance-workspace .inventory-wizard-steps-indicator { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.compliance-workspace .inventory-wizard-step-dot { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; flex: 1; position: relative; }
.compliance-workspace .inventory-wizard-step-dot::after { content: ''; position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; background: var(--border-subtle); z-index: 0; }
.compliance-workspace .inventory-wizard-step-dot:last-child::after { display: none; }
.compliance-workspace .inventory-wizard-step-dot.active .inventory-wizard-step-number { background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: white; border-color: #3b82f6; transform: scale(1.15); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
.compliance-workspace .inventory-wizard-step-dot.completed .inventory-wizard-step-number { background: #10b981; color: white; border-color: #10b981; }
.compliance-workspace .inventory-wizard-step-number { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--border-subtle); background: var(--bg-elevated); color: var(--text-subtle); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 1; transition: all 0.3s ease; }
.compliance-workspace .inventory-wizard-step-label { font-size: 9px; font-weight: 600; color: var(--text-subtle); text-align: center; }
.compliance-workspace .inventory-wizard-step-dot.active .inventory-wizard-step-label { color: #3b82f6; }
.compliance-workspace .inventory-wizard-step-dot.completed .inventory-wizard-step-label { color: #10b981; }
.compliance-workspace .inventory-wizard-step { display: none; animation: inventoryWizardFadeIn 0.3s ease; }
.compliance-workspace .inventory-wizard-step.active { display: block; }
.compliance-workspace .inventory-wizard-step-title { font-size: 15px; font-weight: 700; color: var(--text-heading); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
.compliance-workspace .inventory-wizard-step-title::before { content: ''; width: 4px; height: 18px; background: linear-gradient(180deg, #3b82f6 0%, #6366f1 100%); border-radius: 2px; }
.compliance-workspace .inventory-wizard-fieldset { padding: 1.5rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: color-mix(in srgb, var(--bg-panel) 92%, transparent); }
.compliance-workspace .inventory-wizard-fieldset-title { font-size: 12px; font-weight: 600; color: var(--text-heading); margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-subtle); }
.compliance-workspace .inventory-wizard-navigation { display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1rem; border-top: 1px solid var(--border-subtle); gap: 1rem; }
.compliance-workspace .inventory-wizard-btn-prev { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 40px; padding: 0 1.25rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: color-mix(in srgb, var(--bg-panel) 92%, transparent); color: var(--text-heading); font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .inventory-wizard-btn-prev:hover:not(:disabled) { background: var(--bg-elevated); border-color: var(--accent-border); }
.compliance-workspace .inventory-wizard-btn-prev:disabled { opacity: 0.5; cursor: not-allowed; }
.compliance-workspace .inventory-wizard-btn-next { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 40px; padding: 0 1.25rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .inventory-wizard-btn-next:hover { background: linear-gradient(135deg, #60a5fa 0%, #818cf8 100%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
.compliance-workspace .inventory-wizard-btn-submit { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 40px; padding: 0 1.25rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .inventory-wizard-btn-submit:hover { background: linear-gradient(135deg, #34d399 0%, #2dd4bf 100%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
.compliance-workspace .inventory-wizard-btn-cancel { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 40px; padding: 0 1.25rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: transparent; color: var(--text-subtle); font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .inventory-wizard-btn-cancel:hover { border-color: #ef4444; color: #ef4444; background: rgba(239, 68, 68, 0.05); }
.compliance-workspace .inventory-wizard-error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 11px; color: #f87171; display: none; align-items: center; gap: 0.5rem; }
.compliance-workspace .inventory-wizard-error.show { display: flex; }
.compliance-workspace .inventory-wizard-field { transition: border-color 0.18s ease, box-shadow 0.18s ease; }
.compliance-workspace .inventory-wizard-field:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.compliance-workspace .inventory-wizard-field.error { border-color: #ef4444; }
.compliance-workspace .inventory-wizard-field.error:focus { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15); }
@keyframes inventoryWizardFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 767px) {
    .compliance-workspace .inventory-wizard-navigation { flex-direction: column-reverse; }
    .compliance-workspace .inventory-wizard-btn-prev, .compliance-workspace .inventory-wizard-btn-next, .compliance-workspace .inventory-wizard-btn-submit, .compliance-workspace .inventory-wizard-btn-cancel { width: 100%; justify-content: center; }
    .compliance-workspace .inventory-wizard-step-label { font-size: 8px; }
    .compliance-workspace .inventory-wizard-step-number { width: 24px; height: 24px; font-size: 10px; }
}
.compliance-workspace .transfer-wizard-progress { margin-bottom: 1.5rem; }
.compliance-workspace .transfer-wizard-progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.compliance-workspace .transfer-wizard-progress-title { font-size: 12px; font-weight: 600; color: var(--text-heading); }
.compliance-workspace .transfer-wizard-progress-step { font-size: 11px; color: var(--accent); font-weight: 700; }
.compliance-workspace .transfer-wizard-progress-bar { width: 100%; height: 6px; background: var(--bg-elevated); border-radius: 3px; overflow: hidden; }
.compliance-workspace .transfer-wizard-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981 0%, #14b8a6 100%); border-radius: 3px; transition: width 0.4s ease; }
.compliance-workspace .transfer-wizard-steps-indicator { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; padding: 1rem; border: 1px solid var(--border-color); border-radius: 0.75rem; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); }
.compliance-workspace .transfer-wizard-step-dot { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; flex: 1; position: relative; }
.compliance-workspace .transfer-wizard-step-dot::after { content: ''; position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; background: var(--border-subtle); z-index: 0; }
.compliance-workspace .transfer-wizard-step-dot:last-child::after { display: none; }
.compliance-workspace .transfer-wizard-step-dot.active .transfer-wizard-step-number { background: var(--accent); color: white; border-color: var(--accent); transform: scale(1.1); }
.compliance-workspace .transfer-wizard-step-dot.completed .transfer-wizard-step-number { background: var(--accent); color: white; border-color: var(--accent); }
.compliance-workspace .transfer-wizard-step-number { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--border-subtle); background: var(--bg-elevated); color: var(--text-subtle); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; z-index: 1; transition: all 0.3s ease; }
.compliance-workspace .transfer-wizard-step-label { font-size: 9px; font-weight: 600; color: var(--text-subtle); text-align: center; }
.compliance-workspace .transfer-wizard-step-dot.active .transfer-wizard-step-label { color: var(--accent); }
.compliance-workspace .transfer-wizard-step-dot.completed .transfer-wizard-step-label { color: var(--accent); }
.compliance-workspace .transfer-wizard-step { display: none; animation: transferWizardFadeIn 0.3s ease; }
.compliance-workspace .transfer-wizard-step.active { display: block; }
.compliance-workspace .transfer-wizard-step-title { font-size: 14px; font-weight: 700; color: var(--text-heading); margin-bottom: 1rem; }
.compliance-workspace .transfer-wizard-navigation { display: flex; justify-content: space-between; align-items: center; padding-top: 1.5rem; margin-top: 1rem; border-top: 1px solid var(--border-subtle); }
.compliance-workspace .transfer-wizard-btn-prev { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: 1px solid var(--border-color); border-radius: 0.7rem; background: transparent; color: var(--text-heading); font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .transfer-wizard-btn-prev:hover:not(:disabled) { background: var(--bg-elevated); border-color: var(--accent-border); }
.compliance-workspace .transfer-wizard-btn-prev:disabled { opacity: 0.5; cursor: not-allowed; }
.compliance-workspace .transfer-wizard-btn-next { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .transfer-wizard-btn-next:hover { background: linear-gradient(135deg, #34d399 0%, #2dd4bf 100%); transform: translateY(-1px); }
.compliance-workspace .transfer-wizard-btn-submit { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 38px; padding: 0 1rem; border: none; border-radius: 0.7rem; background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%); color: white; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.18s ease; }
.compliance-workspace .transfer-wizard-btn-submit:hover { background: linear-gradient(135deg, #60a5fa 0%, #818cf8 100%); transform: translateY(-1px); }
@keyframes transferWizardFadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@media (max-width: 767px) {
    .compliance-workspace .compliance-header-inner { align-items: flex-start; }
    .compliance-workspace .compliance-score-chip { width: 100%; justify-content: space-between; }
    .compliance-workspace .compliance-nav { padding-inline: 10px; }
    .compliance-workspace .compliance-section-header { align-items: flex-start; flex-direction: column; }
    .compliance-workspace .rounded-xl:has(> form:not(.inline)) { padding: 14px !important; }
    .compliance-workspace form:not(.inline) [class*="col-span"]:last-child.flex { align-items: stretch; flex-direction: column-reverse; }
    .compliance-workspace form:not(.inline) [class*="col-span"]:last-child.flex button { width: 100%; }
    .compliance-workspace .wizard-navigation { flex-direction: column-reverse; }
    .compliance-workspace .wizard-btn-prev, .compliance-workspace .wizard-btn-next, .compliance-workspace .wizard-btn-submit { width: 100%; }
    .compliance-workspace .transfer-wizard-navigation { flex-direction: column-reverse; }
    .compliance-workspace .transfer-wizard-btn-prev, .compliance-workspace .transfer-wizard-btn-next, .compliance-workspace .transfer-wizard-btn-submit { width: 100%; }
}
</style>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="professional-workspace compliance-workspace flex-1 min-w-0 overflow-hidden flex flex-col">
        <!-- Header (igual a React) -->
        <header class="workspace-header flex-shrink-0">
            <div class="compliance-header-inner px-4 md:px-8 py-4 md:py-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="compliance-context mb-2">
                        <span>Gobierno de datos</span>
                        <span>Ley 21.719</span>
                        <span><?= h($activeLabel) ?></span>
                    </div>
                    <h1 class="workspace-title"><?= h($activeLabel) ?></h1>
                    <p class="workspace-subtitle mt-1">Gestión documental, evidencia y controles para el programa de privacidad de la organización.</p>
                </div>
                <div class="compliance-score-chip flex-shrink-0">
                    <div><p class="compliance-score-label">Evidencia completada</p><p class="text-[9px] text-text-subtle mt-1"><?= $checklistDone ?> de <?= $checklistTotal ?> controles</p></div>
                    <span class="compliance-score-value <?= $pctColor ?>"><?= $checklistPct ?>%</span>
                </div>
            </div>
            <div class="compliance-nav-wrap">
                <div class="compliance-nav">
                    <nav class="workspace-tabs" aria-label="Secciones de cumplimiento">
                        <?php foreach ($tabs as $t): $isActive = $tab === $t['id']; ?>
                        <a href="/compliance?tab=<?= $t['id'] ?>" class="workspace-tab <?= $isActive ? 'is-active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <?= h($t['label']) ?>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto scrollbar-custom tour-detail-1">
            <div class="workspace-content p-3 sm:p-5 md:p-8 w-full max-w-[1500px] mx-auto space-y-4 md:space-y-6">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <?php if ($tab === 'overview'): ?>
            <div class="rounded-xl border border-amber-500/20 bg-amber-500/[0.06] px-4 py-3 flex items-start gap-3">
                <span class="text-amber-400 mt-0.5"><?= cIcon('info') ?></span>
                <p class="text-[10px] md:text-[11px] text-text-muted leading-relaxed"><strong class="text-amber-300">Evaluación orientativa:</strong> el porcentaje se calcula con la evidencia registrada en la plataforma. No constituye certificación ni asesoría legal, y la aplicabilidad de cada obligación depende de la naturaleza, escala y riesgo de los tratamientos de la organización.</p>
            </div>
            <!-- ═══ Stat cards ═══ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-5">
                <?php
                $statCards = [
                    ['icon' => 'shield', 'label' => 'Nivel Cumplimiento', 'value' => $checklistPct . '%', 'color' => $pctColor, 'sub' => $checklistDone . '/' . $checklistTotal . ' requisitos cumplidos', 'help' => 'Porcentaje de cumplimiento de los requisitos de la Ley 21.719 según la checklist.'],
                    ['icon' => 'users', 'label' => 'Consentimientos Activos', 'value' => $activeConsents, 'color' => 'text-cyan-400', 'sub' => 'Total: ' . count($consents), 'help' => 'Número de consentimientos vigentes. Un consentimiento puede ser revocado o expirado.'],
                    ['icon' => 'database', 'label' => 'Datos Registrados', 'value' => count($inventory), 'color' => 'text-indigo-400', 'sub' => $sensitiveItems . ' sensibles', 'help' => 'Total de bases de datos/activos de tratamiento registrados y cuántos contienen datos sensibles.'],
                    ['icon' => 'alert', 'label' => 'Incidentes Activos', 'value' => $activeBreaches, 'color' => $activeBreaches ? 'text-red-400' : 'text-emerald-400', 'sub' => count($breaches) . ' total · ' . $criticalBreaches . ' críticos', 'help' => 'Brechas o incidentes de seguridad que aún no han sido cerrados/resueltos.'],
                    ['icon' => 'info', 'label' => 'Capacitaciones', 'value' => $completedTrainings, 'color' => 'text-amber-400', 'sub' => count($trainings) . ' registradas', 'help' => 'Capacitaciones del personal firmadas/completadas según el programa de formación.'],
                ];
                foreach ($statCards as $c): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-5 hover:border-border-theme/60 transition-colors duration-200">
                    <div class="flex items-center gap-2 md:gap-2.5 mb-2 md:mb-3">
                        <span class="text-text-muted"><?= cIcon($c['icon']) ?></span>
                        <span class="text-[9px] md:text-[10px] text-text-subtle font-medium uppercase tracking-widest truncate"><?= h($c['label']) ?><?= !empty($c['help']) ? ' ' . infoIcon($c['help']) : '' ?></span>
                    </div>
                    <p class="text-[22px] md:text-[26px] font-bold leading-none tracking-tight <?= $c['color'] ?>"><?= h($c['value']) ?></p>
                    <p class="text-[9px] md:text-[10px] text-text-subtle mt-1.5 md:mt-2 truncate"><?= h($c['sub']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ═══ Checklist ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-6">
                <div class="flex items-start justify-between gap-6 mb-5">
                    <div>
                        <h3 class="text-[15px] font-semibold text-text-heading">Checklist de Cumplimiento Ley 21.719</h3>
                        <p class="text-[12px] text-text-muted mt-1">Controles y evidencias aplicables según el contexto de tratamiento de la organización</p>
                    </div>
                    <span class="text-[32px] font-bold leading-none flex-shrink-0 <?= $pctColor ?>"><?= $checklistPct ?>%</span>
                </div>
                <div class="w-full bg-bg-elevated/50 rounded-full h-2.5 mb-6">
                    <div class="h-full rounded-full transition-all duration-700 <?= $pctBar ?>" style="width: <?= $checklistPct ?>%"></div>
                </div>
                <div class="compliance-form-row">
                    <?php foreach ($CHECKLIST as $item): ?>
                    <div class="flex items-start gap-3 p-4 rounded-lg transition-colors <?= $item['done'] ? 'bg-emerald-500/[0.04]' : 'bg-bg-base/40 hover:bg-bg-elevated/40' ?>">
                        <span class="mt-0.5 <?= $item['done'] ? 'text-emerald-400' : 'text-text-subtle' ?>"><?= cIcon($item['icon']) ?></span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[12px] font-medium <?= $item['done'] ? 'text-emerald-300' : 'text-text-muted' ?>"><?= h($item['label']) ?> <?= infoIcon($item['desc']) ?></span>
                                <?php if ($item['done']): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border bg-emerald-500/10 text-emerald-400 border-emerald-500/20"><?= cIcon('check', 'w-3 h-3') ?> Cumple</span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border bg-red-500/10 text-red-400 border-red-500/20"><?= cIcon('xmark', 'w-3 h-3') ?> Pendiente</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[11px] text-text-subtle mt-1"><?= h($item['desc']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ═══ Estado DPD / APDP / Capacitación / Nivel ═══ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 md:gap-5">
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= !empty($config['dpdEmail']) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <?= cIcon(!empty($config['dpdEmail']) ? 'users' : 'alert') ?>
                        </div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Delegado de Protección</h4>
                    </div>
                    <?php if (!empty($config['dpdEmail'])): ?>
                    <div class="space-y-2">
                        <p class="text-[13px] text-white font-medium"><?= h($config['dpdName'] ?? 'No especificado') ?></p>
                        <p class="text-[11px] text-text-muted font-mono"><?= h($config['dpdEmail']) ?></p>
                        <?php if (!empty($config['dpdPhone'])): ?><p class="text-[11px] text-text-muted"><?= h($config['dpdPhone']) ?></p><?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2 h-2 rounded-full bg-red-400/60 animate-pulse"></div>
                        <p class="text-[12px] text-text-subtle">No asignado - Requerido por ley</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <?= cIcon(($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) ? 'shield' : 'alert') ?>
                        </div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Registro APDP</h4>
                    </div>
                    <?php if ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true): ?>
                    <p class="text-[13px] text-emerald-400 font-medium flex items-center gap-1.5"><?= cIcon('check') ?> Registrado</p>
                    <?php else: ?>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2 h-2 rounded-full bg-red-400/60 animate-pulse"></div>
                        <p class="text-[12px] text-text-subtle">No registrado - Obligatorio</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-primary-500/10 text-accent flex items-center justify-center"><?= cIcon('fileText') ?></div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Política Pública (Art. 14 ter)</h4>
                    </div>
                    <p class="text-[11px] text-text-muted mb-3">Genera la política de privacidad pública obligatoria según Art. 14 ter Ley 21.719.</p>
                    <a href="<?= API_BASE_URL_BROWSER ?>/api/compliance/public-policy?token=<?= h($token) ?>" target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-primary-600 to-indigo-600 hover:from-primary-500 hover:to-indigo-500 text-white transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        Ver / Descargar Política
                    </a>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= count($trainings) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <?= cIcon(count($trainings) ? 'check' : 'alert') ?>
                        </div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Capacitación</h4>
                    </div>
                    <?php if (count($trainings)): ?>
                    <div class="space-y-2">
                        <p class="text-[13px] text-emerald-400 font-medium flex items-center gap-1.5"><?= cIcon('check') ?> <?= $completedTrainings ?> completadas</p>
                        <p class="text-[11px] text-text-muted"><?= count($trainings) ?> capacitaciones registradas</p>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2.5">
                        <div class="w-2 h-2 rounded-full bg-red-400/60 animate-pulse"></div>
                        <p class="text-[12px] text-text-subtle">Sin capacitaciones registradas</p>
                    </div>
                    <?php endif; ?>
                    <a href="/compliance?tab=trainings" class="mt-3 inline-block text-[10px] text-accent hover:text-primary-300 font-medium">Ver todas →</a>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-primary-500/10 text-accent flex items-center justify-center"><?= cIcon('info') ?></div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Nivel de Cumplimiento</h4>
                    </div>
                    <?php $lvl = $config['complianceLevel'] ?? 'básico'; ?>
                    <p class="text-[22px] font-bold text-white capitalize mb-2 tracking-tight"><?= h($lvl) ?></p>
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-2.5 h-2.5 rounded-full <?= $lvl === 'certified' ? 'bg-emerald-400' : ($lvl === 'advanced' ? 'bg-blue-400' : ($lvl === 'intermediate' ? 'bg-yellow-400' : 'bg-gray-500')) ?>"></div>
                        <span class="text-[11px] font-medium <?= $lvl === 'certified' ? 'text-emerald-400' : ($lvl === 'advanced' ? 'text-blue-400' : ($lvl === 'intermediate' ? 'text-yellow-400' : 'text-text-muted')) ?>"><?= h($lvl) ?></span>
                    </div>
                </div>
            </div>

            <!-- ═══ Timeline Ley 21.719 ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-6">
                <h3 class="text-[15px] font-semibold text-white mb-4">Ley 21.719 — Línea de Tiempo de Implementación</h3>
                <div class="relative">
                    <div class="absolute left-[15px] top-2 bottom-2 w-0.5 bg-bg-elevated/60"></div>
                    <div class="space-y-4">
                        <?php foreach ([
                            ['date' => '13 Dic 2024', 'title' => 'Publicación de la Ley', 'desc' => 'Se publica la Ley 21.719 en el Diario Oficial, iniciando el período de 24 meses para su implementación.', 'done' => true, 'urgent' => false],
                            ['date' => '2025', 'title' => 'Implementación de la APDP', 'desc' => 'La Agencia de Protección de Datos Personales debe comenzar a operar. Se definen sus facultades, estructura y presupuesto.', 'done' => false, 'urgent' => false],
                            ['date' => '13 Dic 2026', 'title' => 'Fin del Período de Transición', 'desc' => 'Todas las empresas deben estar en cumplimiento. La APDP comienza a fiscalizar y aplicar multas de hasta 20.000 UTM (~$1.400M).', 'done' => false, 'urgent' => true],
                            ['date' => '2027+', 'title' => 'Régimen Sancionatorio Pleno', 'desc' => 'Comienza la aplicación efectiva de multas y sanciones. Las empresas no adecuadas enfrentan multas gravísimas que pueden triplicarse por reincidencia.', 'done' => false, 'urgent' => false],
                        ] as $i => $m): ?>
                        <div class="relative flex items-start gap-4">
                            <div class="w-7 h-7 rounded-full border-2 flex items-center justify-center flex-shrink-0 z-10 text-[9px] font-bold <?= $m['done'] ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : ($m['urgent'] ? 'border-red-500 bg-red-500/20 text-red-400' : 'border-gray-600 bg-gray-600/20 text-text-muted') ?>">
                                <?= $m['done'] ? '✓' : ($m['urgent'] ? '!' : $i + 1) ?>
                            </div>
                            <div class="flex-1 min-w-0 pt-0.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] font-mono <?= $m['done'] ? 'text-emerald-400' : ($m['urgent'] ? 'text-red-400' : 'text-text-muted') ?>"><?= h($m['date']) ?></span>
                                    <span class="text-[12px] font-semibold text-text-heading"><?= h($m['title']) ?></span>
                                </div>
                                <p class="text-[11px] text-text-muted mt-0.5"><?= h($m['desc']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ═══ Registro APDP ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-6">
                <?php renderSectionHeader('Registro APDP', 'Registro de la organización en la Agencia de Protección de Datos Personales según Art. 31 de la Ley 21.719'); ?>
                
                <form method="POST">
                    <input type="hidden" name="save_config" value="1">
                    
                    <div class="compliance-form-row">
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Nombre de la empresa <span class="required">*</span></label>
                            <input type="text" name="companyName" required class="compliance-input" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Razón social de la empresa">
                        </div>
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Nivel de cumplimiento</label>
                            <select name="complianceLevel" class="compliance-select">
                                <option value="basic" <?= ($config['complianceLevel'] ?? '') === 'basic' ? 'selected' : '' ?>>Básico</option>
                                <option value="intermediate" <?= ($config['complianceLevel'] ?? '') === 'intermediate' ? 'selected' : '' ?>>Intermedio</option>
                                <option value="advanced" <?= ($config['complianceLevel'] ?? '') === 'advanced' ? 'selected' : '' ?>>Avanzado</option>
                                <option value="certified" <?= ($config['complianceLevel'] ?? '') === 'certified' ? 'selected' : '' ?>>Certificado</option>
                            </select>
                        </div>
                    </div>

                    <div class="compliance-form-row mt-4">
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Nombre del DPD <span class="required">*</span></label>
                            <input type="text" name="dpdName" required class="compliance-input" value="<?= h($config['dpdName'] ?? '') ?>" placeholder="Nombre completo del Delegado de Protección de Datos">
                        </div>
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Email del DPD <span class="required">*</span></label>
                            <input type="email" name="dpdEmail" required class="compliance-input" value="<?= h($config['dpdEmail'] ?? '') ?>" placeholder="dpd@empresa.cl">
                        </div>
                    </div>

                    <div class="compliance-form-row mt-4">
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Teléfono del DPD</label>
                            <input type="tel" name="dpdPhone" class="compliance-input" value="<?= h($config['dpdPhone'] ?? '') ?>" placeholder="+56 9 1234 5678">
                        </div>
                        <div class="compliance-form-cell">
                            <label class="compliance-form-label">Número de registro APDP</label>
                            <input type="text" name="apdpRegistrationNumber" class="compliance-input" value="<?= h($config['apdpRegistrationNumber'] ?? '') ?>" placeholder="Ej: APDP-2024-XXXXX">
                        </div>
                    </div>

                    <div class="compliance-form-row mt-4">
                        <div class="compliance-form-cell">
                            <div class="compliance-checkbox-group">
                                <input type="checkbox" name="apdpRegistered" id="apdpRegistered" value="1" <?= ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) ? 'checked' : '' ?>>
                                <label for="apdpRegistered">
                                    <strong>Registrado en APDP</strong> (Art. 31)<br>
                                    Confirmo que la organización está inscrita en el Registro de la Agencia de Protección de Datos Personales
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="compliance-form-actions">
                        <button type="submit" class="compliance-btn-primary">
                            Guardar configuración
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'violations'): ?>
            <!-- ═══ Violaciones y Sanciones ═══ -->
            <div class="mb-2">
                <h3 class="text-[15px] font-semibold text-text-heading">Violaciones y Sanciones — Ley 21.719</h3>
                <p class="text-[12px] text-text-muted mt-1">Infracciones clasificadas según su gravedad y las multas asociadas en UTM</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-5">
                <?php foreach ([
                    ['sev' => 'leve', 'label' => 'Leves', 'fine' => 'Hasta 5.000 UTM', 'color' => 'yellow'],
                    ['sev' => 'grave', 'label' => 'Graves', 'fine' => 'Hasta 10.000 UTM', 'color' => 'orange'],
                    ['sev' => 'gravisima', 'label' => 'Gravísimas', 'fine' => 'Hasta 20.000 UTM', 'color' => 'red'],
                ] as $g): $cnt = count(array_filter($violations, fn($v) => $v['severity'] === $g['sev'])); ?>
                <div class="rounded-xl border border-<?= $g['color'] ?>-500/20 bg-<?= $g['color'] ?>-500/[0.04] p-5">
                    <p class="text-[10px] text-<?= $g['color'] ?>-400 font-semibold uppercase tracking-wider mb-2">Infracciones <?= h($g['label']) ?></p>
                    <p class="text-[26px] font-bold text-<?= $g['color'] ?>-400 leading-none"><?= $cnt ?></p>
                    <p class="text-[10px] text-text-subtle mt-2"><?= h($g['fine']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="space-y-3">
                <?php foreach ($violations as $v):
                    $sevColor = $v['severity'] === 'gravisima' ? 'red' : ($v['severity'] === 'grave' ? 'orange' : 'yellow');
                    $sevLabel = $v['severity'] === 'gravisima' ? 'Gravísima' : ucfirst($v['severity']);
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-5 hover:border-<?= $sevColor ?>-500/30 transition-all">
                    <div class="flex items-start justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-2.5">
                            <span class="text-<?= $sevColor ?>-400"><?= cIcon('alert') ?></span>
                            <h4 class="text-[13px] font-semibold text-text-heading inline-flex items-center gap-1.5"><?= h($v['title']) ?> <?= infoIcon($v['desc']) ?></h4>
                            <span class="px-2 py-0.5 text-[9px] font-bold rounded uppercase bg-<?= $sevColor ?>-500/15 text-<?= $sevColor ?>-400 border border-<?= $sevColor ?>-500/20"><?= h($sevLabel) ?></span>
                        </div>
                        <div class="flex items-center gap-2 text-[10px]">
                            <span class="font-mono text-text-subtle"><?= h($v['art']) ?></span>
                            <span class="font-semibold text-<?= $sevColor ?>-400"><?= h($v['fine']) ?></span>
                        </div>
                    </div>
                    <p class="text-[11px] text-text-muted mt-2 leading-relaxed"><?= h($v['desc']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <?php elseif ($tab === 'consents'): ?>
            <?php
            $cActive = count(array_filter($items, fn($it) => !empty($it['active']) && empty($it['revokedAt'])));
            $cRevoked = count($items) - $cActive;
            ?>
            <?php renderSectionHeader('Consentimientos', 'Gestión de consentimientos de titulares de datos — Art. 12 de la Ley 21.719', 'consents'); ?>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('check')); ?>
                <?php renderComplianceStat('Activos', $cActive, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Revocados', $cRevoked, 'text-red-400', cIcon('xmark')); ?>
            </div>

            <!-- Formulario wizard de consentimiento (Art. 12 Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Registrar consentimiento (Art. 12 - Libre, expreso, informado, específico)
                    </p>
                    <?php renderImportBtn('consents'); ?>
                </div>

                <form method="POST" id="consent-wizard-form" class="wizard-container">
                    <input type="hidden" name="collection" value="consents">

                    <!-- Error Message -->
                    <div class="wizard-error-message" id="wizard-error-message">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="wizard-error-message-text" id="wizard-error-text">Por favor, complete todos los campos requeridos antes de continuar.</span>
                    </div>

                    <!-- Wizard Progress -->
                    <div class="wizard-progress">
                        <div class="wizard-progress-header">
                            <span class="wizard-progress-title">Progreso del formulario</span>
                            <span class="wizard-progress-steps" id="wizard-step-text">Paso 1 de 6</span>
                        </div>
                        <div class="wizard-progress-bar">
                            <div class="wizard-progress-fill" id="wizard-progress-fill" style="width: 16.67%"></div>
                        </div>
                    </div>

                    <!-- Wizard Steps Indicator -->
                    <div class="wizard-steps-indicator">
                        <div class="wizard-step-dot active" data-step="1">
                            <span class="wizard-step-number">1</span>
                            <span class="wizard-step-label">Identificación</span>
                        </div>
                        <div class="wizard-step-dot" data-step="2">
                            <span class="wizard-step-number">2</span>
                            <span class="wizard-step-label">Tratamiento</span>
                        </div>
                        <div class="wizard-step-dot" data-step="3">
                            <span class="wizard-step-number">3</span>
                            <span class="wizard-step-label">Consentimientos</span>
                        </div>
                        <div class="wizard-step-dot" data-step="4">
                            <span class="wizard-step-number">4</span>
                            <span class="wizard-step-label">Derechos</span>
                        </div>
                        <div class="wizard-step-dot" data-step="5">
                            <span class="wizard-step-number">5</span>
                            <span class="wizard-step-label">Responsable</span>
                        </div>
                        <div class="wizard-step-dot" data-step="6">
                            <span class="wizard-step-number">6</span>
                            <span class="wizard-step-label">Evidencia</span>
                        </div>
                    </div>

                    <!-- Paso 1: Identificación del Titular -->
                    <div class="wizard-step-content active" data-step="1">
                        <h3 class="wizard-step-title">Paso 1: Identificación del Titular (Art. 12.1)</h3>
                        <div class="wizard-fieldset">
                            <div class="compliance-form-row grid-cols-3">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Nombre completo<span class="required">*</span></label>
                                    <input type="text" name="fields[name]" id="step1-name" required class="compliance-input" placeholder="Juan Pérez González">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">RUT<span class="required">*</span></label>
                                    <input type="text" id="rut-consent" name="fields[rut]" required class="compliance-input" placeholder="12.345.678-9" pattern="[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]{1}">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Email<span class="required">*</span></label>
                                    <input type="email" name="fields[email]" id="step1-email" required class="compliance-input" placeholder="juan.perez@ejemplo.cl">
                                </div>
                            </div>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Teléfono</label>
                                    <input type="tel" name="fields[phone]" class="compliance-input" placeholder="+56 9 1234 5678">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Dirección</label>
                                    <input type="text" name="fields[address]" class="compliance-input" placeholder="Calle 123, Santiago">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 2: Información del Tratamiento -->
                    <div class="wizard-step-content" data-step="2">
                        <h3 class="wizard-step-title">Paso 2: Información del Tratamiento (Art. 12.2)</h3>
                        <div class="wizard-fieldset">
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Finalidad específica<span class="required">*</span></label>
                                    <select name="fields[purpose]" id="step2-purpose" required class="compliance-select">
                                        <option value="">Seleccionar finalidad</option>
                                        <optgroup label="Clientes/Comercial">
                                            <option value="gestion_clientes">Gestión de clientes y facturación</option>
                                            <option value="marketing">Marketing y comunicaciones comerciales</option>
                                            <option value="soporte">Soporte técnico y atención al cliente</option>
                                        </optgroup>
                                        <optgroup label="Empleados/RRHH">
                                            <option value="gestion_personal">Gestión de personal y nómina</option>
                                            <option value="seguridad_social">Seguridad social y prevención de riesgos</option>
                                        </optgroup>
                                        <optgroup label="Otras">
                                            <option value="cumplimiento_legal">Cumplimiento obligaciones legales</option>
                                            <option value="investigacion">Investigación y desarrollo</option>
                                            <option value="otro">Otra (especificar en observaciones)</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Base legal (Ley 21.719)<span class="required">*</span></label>
                                    <select name="fields[legalBasis]" id="step2-legalBasis" required class="compliance-select">
                                        <option value="">Seleccionar base legal</option>
                                        <option value="consentimiento">Art. 12 - Consentimiento del titular</option>
                                        <option value="ejecucion_contrato">Art. 13.1.a - Ejecución de contrato</option>
                                        <option value="obligacion_legal">Art. 13.1.b - Obligación legal</option>
                                        <option value="interes_vital">Art. 13.1.c - Interés vital</option>
                                        <option value="interes_publico">Art. 13.1.d - Interés público</option>
                                        <option value="interes_legitimo">Art. 13.1.e - Interés legítimo</option>
                                    </select>
                                </div>
                            </div>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de datos<span class="required">*</span></label>
                                    <select name="fields[dataCategories]" id="step2-dataCategories" multiple class="compliance-select" style="min-height: 132px;">
                                        <option value="identificacion">Identificación (nombre, RUT, dirección)</option>
                                        <option value="contacto">Contacto (email, teléfono)</option>
                                        <option value="financieros">Financieros (cuentas, tarjetas, ingresos)</option>
                                        <option value="laborales">Laborales (cargo, sueldo, antigüedad)</option>
                                        <option value="salud">Salud (historial, diagnósticos, recetas)</option>
                                        <option value="biometricos">Biométricos (huella, facial, iris)</option>
                                        <option value="geneticos">Genéticos</option>
                                        <option value="ninos">Datos de niños/niñas/adolescentes</option>
                                        <option value="navegacion">Navegación (IP, cookies, device ID)</option>
                                        <option value="ubicacion">Ubicación geográfica</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">Ctrl+Click para seleccionar múltiples</p>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Incluye datos sensibles? (Art. 16)</label>
                                    <select name="fields[sensitive]" id="step2-sensitive" class="compliance-select" onchange="toggleSensitiveFieldset()">
                                        <option value="no">No</option>
                                        <option value="si">Sí - Requiere consentimiento explícito reforzado</option>
                                    </select>
                                </div>
                            </div>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Incluye datos de niños/niñas/adolescentes? (Art. 17)</label>
                                    <select name="fields[childrenData]" id="step2-childrenData" class="compliance-select" onchange="toggleChildrenFieldset()">
                                        <option value="no">No</option>
                                        <option value="si">Sí - Requiere consentimiento representante legal</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 3: Consentimientos Especiales -->
                    <div class="wizard-step-content" data-step="3">
                        <h3 class="wizard-step-title">Paso 3: Consentimientos Especiales</h3>
                        
                        <!-- Consentimiento explícito reforzado para datos sensibles (Art. 16) -->
                        <div class="wizard-fieldset" id="sensitive-consent-fieldset" style="display: none; border-color: rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.03);">
                            <p class="wizard-fieldset-title" style="color: #f87171;">Consentimiento Explícito Reforzado - Datos Sensibles (Art. 16)</p>
                            <div class="bg-red-500/[0.05] border border-red-500/20 rounded-lg p-3 mb-4 text-[10px] text-text-body">
                                <p class="font-semibold text-red-300 mb-1">Art. 16: Tratamiento de datos sensibles requiere consentimiento EXPLÍCITO, LIBRE, INFORMADO, ESPECÍFICO e INEQUÍVOCO.</p>
                                <p>Categorías sensibles: origen racial/étnico, opiniones políticas, convicciones religiosas/filosóficas, afiliación sindical, datos genéticos, biométricos, salud, vida sexual.</p>
                            </div>
                            <div class="space-y-3">
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[sensitiveExplicit]" id="sensitiveExplicit" value="1">
                                    <label for="sensitiveExplicit">El titular ha dado consentimiento <strong>explícito y por escrito</strong> para cada categoría de dato sensible</label>
                                </div>
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[sensitiveInformed]" id="sensitiveInformed" value="1">
                                    <label for="sensitiveInformed">El titular ha sido informado de la <strong>naturaleza de los datos sensibles</strong>, los <strong>riesgos específicos</strong> y el <strong>derecho a revocar en cualquier momento</strong></label>
                                </div>
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[sensitiveSeparate]" id="sensitiveSeparate" value="1">
                                    <label for="sensitiveSeparate">El consentimiento sensible se obtuvo <strong>de forma separada</strong> de otros consentimientos (no bundled)</label>
                                </div>
                            </div>
                        </div>

                        <!-- Consentimiento parental para datos de niños (Art. 17) -->
                        <div class="wizard-fieldset" id="children-consent-fieldset" style="display: none; border-color: rgba(236, 72, 153, 0.3); background: rgba(236, 72, 153, 0.03);">
                            <p class="wizard-fieldset-title" style="color: #f472b6;">Consentimiento de Representante Legal - Datos de Niños (Art. 17 Ley 21.719)</p>
                            <div class="bg-pink-500/[0.05] border border-pink-500/20 rounded-lg p-3 mb-4 text-[10px] text-text-body">
                                <p class="font-semibold text-pink-300 mb-1">Art. 17: Tratamiento de datos de niños/niñas/adolescentes requiere consentimiento del TITULAR DE LA PATRIA POTESTAD o representante legal.</p>
                                <p>Debe respetarse el interés superior del niño y su autonomía progresiva.</p>
                            </div>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Nombre del representante legal *</label>
                                    <input type="text" name="fields[parentName]" id="step3-parentName" class="compliance-input" placeholder="Nombre completo del padre/madre/tutor">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">RUT del representante legal *</label>
                                    <input type="text" id="rut-parent" name="fields[parentRut]" id="step3-parentRut" class="compliance-input" placeholder="12.345.678-9">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Email del representante legal *</label>
                                    <input type="email" name="fields[parentEmail]" id="step3-parentEmail" class="compliance-input" placeholder="padre@ejemplo.cl">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Relación con el niño *</label>
                                    <select name="fields[parentRelation]" id="step3-parentRelation" class="compliance-select">
                                        <option value="">Seleccionar</option>
                                        <option value="padre">Padre</option>
                                        <option value="madre">Madre</option>
                                        <option value="tutor">Tutor legal</option>
                                        <option value="otro">Otro representante legal</option>
                                    </select>
                                </div>
                            </div>
                            <div class="space-y-3 mt-4">
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[parentExplicit]" id="parentExplicit" value="1">
                                    <label for="parentExplicit">El representante legal ha dado consentimiento <strong>explícito e informado</strong> para el tratamiento de datos del niño</label>
                                </div>
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[parentBestInterest]" id="parentBestInterest" value="1">
                                    <label for="parentBestInterest">Se ha considerado el <strong>interés superior del niño</strong> y su <strong>autonomía progresiva</strong> según edad y madurez</label>
                                </div>
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[parentInformed]" id="parentInformed" value="1">
                                    <label for="parentInformed">El representante ha sido informado de los <strong>derechos ARCO del niño</strong> y del <strong>derecho a revocar en cualquier momento</strong></label>
                                </div>
                            </div>
                        </div>

                        <div id="no-special-consents-message" class="text-center py-8 text-text-subtle text-[11px]">
                            <p>No se han seleccionado datos sensibles ni de niños en el paso anterior.</p>
                            <p class="mt-1">Este paso se aplica automáticamente cuando se requieren consentimientos especiales.</p>
                        </div>
                    </div>

                    <!-- Paso 4: Derechos y Vigencia -->
                    <div class="wizard-step-content" data-step="4">
                        <h3 class="wizard-step-title">Paso 4: Derechos del Titular y Vigencia</h3>
                        <div class="wizard-fieldset">
                            <div class="compliance-form-row grid-cols-3">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha inicio <span class="required">*</span></label>
                                    <input type="date" name="fields[startDate]" id="step4-startDate" required class="compliance-input" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha fin / Vigencia</label>
                                    <input type="date" name="fields[endDate]" class="compliance-input" placeholder="Opcional - indefinido si vacío">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Método de obtención <span class="required">*</span></label>
                                    <select name="fields[method]" id="step4-method" required class="compliance-select">
                                        <option value="formulario_web">Formulario web</option>
                                        <option value="formulario_papel">Formulario papel</option>
                                        <option value="contrato">En contrato</option>
                                        <option value="verbal_grabado">Verbal grabado</option>
                                        <option value="opt_in">Opt-in (casilla de verificación)</option>
                                        <option value="otro">Otro</option>
                                    </select>
                                </div>
                            </div>
                            <div class="space-y-3 mt-4">
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[arcoInformed]" id="arcoInformed" value="1">
                                    <label for="arcoInformed">Titular informado de derechos ARCO + Portabilidad (Art. 8-13)</label>
                                </div>
                                <div class="compliance-checkbox-group">
                                    <input type="checkbox" name="fields[revocationInformed]" id="revocationInformed" value="1">
                                    <label for="revocationInformed">Titular informado de derecho a revocar consentimiento (Art. 12.3)</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 5: Responsable y DPD -->
                    <div class="wizard-step-content" data-step="5">
                        <h3 class="wizard-step-title">Paso 5: Responsable del Tratamiento y DPD (Art. 28)</h3>
                        <div class="wizard-fieldset">
                            <div class="compliance-form-row grid-cols-3">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Responsable (Empresa)<span class="required">*</span></label>
                                    <input type="text" name="fields[controllerName]" id="step5-controllerName" required class="compliance-input" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Nombre de la empresa responsable">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">DPD (Delegado Protección Datos)</label>
                                    <input type="text" name="fields[dpdName]" class="compliance-input" value="<?= h($config['dpdName'] ?? '') ?>" placeholder="Nombre del DPD">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Contacto DPD</label>
                                    <input type="email" name="fields[dpdEmail]" class="compliance-input" value="<?= h($config['dpdEmail'] ?? '') ?>" placeholder="dpd@empresa.cl">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 6: Observaciones y Evidencia -->
                    <div class="wizard-step-content" data-step="6">
                        <h3 class="wizard-step-title">Paso 6: Observaciones y Evidencia</h3>
                        <div class="wizard-fieldset">
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Observaciones / Contexto</label>
                                    <textarea name="fields[notes]" rows="4" class="compliance-textarea" placeholder="Contexto adicional, canal de obtención, observaciones legales..."></textarea>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">URL de evidencia (formulario, contrato, grabación)</label>
                                    <input type="url" name="fields[evidenceUrl]" class="compliance-input" placeholder="https://empresa.cl/consentimiento-juan-perez">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="fields[active]" value="1">
                    <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">

                    <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button type="button" class="wizard-btn-prev" id="wizard-prev-btn" onclick="prevStep()" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Anterior
                        </button>
                        <button type="button" class="wizard-btn-next" id="wizard-next-btn" onclick="nextStep()">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" name="create_item" value="1" class="compliance-btn-primary wizard-submit-btn" id="wizard-submit-btn">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar consentimiento (Art. 12 - Libre, expreso, informado, específico)
                        </button>
                    </div>
                </form>
            </div>

            <?php if (empty($items)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin consentimientos todavía. Crea uno o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($items as $it):
                    $active = !empty($it['active']) && $it['active'] !== 'false' && empty($it['revokedAt']);
                    $initial = strtoupper(substr($it['name'] ?? '?', 0, 1));
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-[12px] font-bold <?= $active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-red-500/15 text-red-400' ?>"><?= h($initial) ?></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($it['name'] ?? 'Titular') ?></p>
                            <span class="text-[10px] text-text-subtle font-mono"><?= h($it['email'] ?? '') ?></span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $active ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20' ?>"><?= $active ? 'Activo' : 'Revocado' ?></span>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5">Finalidad: <?= h($it['purpose'] ?? '-') ?> · <?= h(substr($it['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if ($active) renderActionBtn('consents', $it['_id'] ?? '', 'revoke', 'Revocar'); ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="consents">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar este consentimiento?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ═══ INVENTARIO (VERSIÓN MEJORADA) ═══ -->
            <?php elseif ($tab === 'inventory'): ?>
            <?php
            // ─── Obtener datos del inventario ───
            $inventoryItems = $fetchList('inventory');
            if (!is_array($inventoryItems)) $inventoryItems = [];

            // ─── Estadísticas ───
            $totalItems = count($inventoryItems);
            $dbItems = count(array_filter($inventoryItems, fn($i) => ($i['sourceType'] ?? '') === 'database'));
            $fileItems = count(array_filter($inventoryItems, fn($i) => ($i['sourceType'] ?? '') === 'file'));
            $sensitiveItemsCount = count(array_filter($inventoryItems, fn($i) => !empty($i['sensitive'])));
            $riskCounts = [
                'critical' => count(array_filter($inventoryItems, fn($i) => ($i['risk'] ?? '') === 'critical')),
                'high' => count(array_filter($inventoryItems, fn($i) => ($i['risk'] ?? '') === 'high')),
                'medium' => count(array_filter($inventoryItems, fn($i) => ($i['risk'] ?? '') === 'medium')),
                'low' => count(array_filter($inventoryItems, fn($i) => ($i['risk'] ?? '') === 'low' || empty($i['risk']))),
            ];
            $completeItems = count(array_filter($inventoryItems, function($i) {
                return !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories']);
            }));

            // ─── Filtros y ordenamiento ───
            $search = $_GET['search'] ?? '';
            $filterRisk = $_GET['risk'] ?? '';
            $filterSensitive = $_GET['sensitive'] ?? '';
            $filterSource = $_GET['source'] ?? '';
            $sortBy = $_GET['sort'] ?? 'createdAt';
            $sortDir = $_GET['dir'] ?? 'desc';

            // Aplicar filtros
            $filtered = $inventoryItems;
            if ($search) {
                $searchLower = strtolower($search);
                $filtered = array_filter($filtered, function($i) use ($searchLower) {
                    return str_contains(strtolower($i['name'] ?? ''), $searchLower) ||
                           str_contains(strtolower($i['dataCategories'] ?? ''), $searchLower) ||
                           str_contains(strtolower($i['legalBasis'] ?? ''), $searchLower);
                });
            }
            if ($filterRisk) {
                $filtered = array_filter($filtered, fn($i) => ($i['risk'] ?? 'low') === $filterRisk);
            }
            if ($filterSensitive !== '') {
                $filtered = array_filter($filtered, fn($i) => !empty($i['sensitive']) === ($filterSensitive === '1'));
            }
            if ($filterSource) {
                $filtered = array_filter($filtered, fn($i) => ($i['sourceType'] ?? 'database') === $filterSource);
            }

            // Ordenar
            usort($filtered, function($a, $b) use ($sortBy, $sortDir) {
                $valA = $a[$sortBy] ?? '';
                $valB = $b[$sortBy] ?? '';
                $cmp = strcmp($valA, $valB);
                return $sortDir === 'desc' ? -$cmp : $cmp;
            });

            $totalFiltered = count($filtered);
            ?>

            <?php renderSectionHeader('Inventario de Datos Personales (RAT)',
                'Registro de todas las actividades de tratamiento de datos personales. ' .
                'Este inventario es obligatorio según el Art. 14 de la Ley 21.719.',
                'inventory'
            ); ?>

            <?php if ($msg): ?>
                <div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px] mb-4"><?= h($msg) ?></div>
            <?php endif; ?>
            <?php if ($err): ?>
                <div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px] mb-4"><?= h($err) ?></div>
            <?php endif; ?>

            <!-- ═══ BARRA DE ESTADÍSTICAS ═══ -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                <?php
                $invStats = [
                    ['label' => 'Total', 'value' => $totalItems, 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.1)', 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
                    ['label' => 'Bases de Datos', 'value' => $dbItems, 'color' => '#38bdf8', 'bg' => 'rgba(56,189,248,0.1)', 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
                    ['label' => 'Archivos', 'value' => $fileItems, 'color' => '#fbbf24', 'bg' => 'rgba(251,191,36,0.1)', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['label' => 'Datos Sensibles', 'value' => $sensitiveItemsCount, 'color' => '#f87171', 'bg' => 'rgba(248,113,113,0.1)', 'icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z'],
                    ['label' => 'Completos', 'value' => $completeItems . ' / ' . $totalItems, 'color' => '#34d399', 'bg' => 'rgba(52,211,153,0.1)', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['label' => 'Riesgo Alto', 'value' => $riskCounts['high'] + $riskCounts['critical'], 'color' => '#fbbf24', 'bg' => 'rgba(251,191,36,0.1)', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z'],
                ];
                foreach ($invStats as $s): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3.5 flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="<?= $s['icon'] ?>"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[9px] text-text-subtle uppercase tracking-wider truncate"><?= h($s['label']) ?></p>
                        <p class="text-[18px] font-bold leading-none tracking-tight" style="color:<?= $s['color'] ?>"><?= h($s['value']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ═══ FILTROS Y BÚSQUEDA ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 mb-5">
                <div class="flex flex-col md:flex-row gap-3">
                    <!-- Buscador -->
                    <div class="flex-1 relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" id="inventory-search" value="<?= h($search) ?>"
                               placeholder="Buscar por nombre, categoría o base legal..."
                               class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg pl-9 pr-3 py-2 focus:outline-none focus:border-accent transition-all"
                               onchange="updateFilters()">
                    </div>

                    <!-- Filtro: Riesgo -->
                    <select id="filter-risk" class="bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="updateFilters()">
                        <option value="">Todos los riesgos</option>
                        <option value="low" <?= $filterRisk === 'low' ? 'selected' : '' ?>>Bajo</option>
                        <option value="medium" <?= $filterRisk === 'medium' ? 'selected' : '' ?>>Medio</option>
                        <option value="high" <?= $filterRisk === 'high' ? 'selected' : '' ?>>Alto</option>
                        <option value="critical" <?= $filterRisk === 'critical' ? 'selected' : '' ?>>Crítico</option>
                    </select>

                    <!-- Filtro: Sensibles -->
                    <select id="filter-sensitive" class="bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="updateFilters()">
                        <option value="">Todos los datos</option>
                        <option value="1" <?= $filterSensitive === '1' ? 'selected' : '' ?>>Sensibles</option>
                        <option value="0" <?= $filterSensitive === '0' ? 'selected' : '' ?>>No sensibles</option>
                    </select>

                    <!-- Filtro: Origen -->
                    <select id="filter-source" class="bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="updateFilters()">
                        <option value="">Todos los orígenes</option>
                        <option value="database" <?= $filterSource === 'database' ? 'selected' : '' ?>>Base de datos</option>
                        <option value="file" <?= $filterSource === 'file' ? 'selected' : '' ?>>Archivo</option>
                    </select>

                    <!-- Botón limpiar -->
                    <button onclick="clearFilters()" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-bg-elevated/80 border border-border-theme text-text-muted hover:text-text-body transition-all">
                        Limpiar filtros
                    </button>

                    <!-- Botón crear nuevo (versión compacta) -->
                    <button onclick="document.getElementById('inventory-create-form').classList.toggle('hidden')"
                            class="px-3 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Nuevo
                    </button>
                    <?php renderImportBtn('inventory'); ?>
                </div>

                <!-- Resultados de filtro -->
                <div class="mt-2 text-[10px] text-text-subtle">
                    Mostrando <?= $totalFiltered ?> de <?= $totalItems ?> registros
                    <?php if ($search): ?> · Buscando: "<?= h($search) ?>"<?php endif; ?>
                    <?php if ($filterRisk): ?> · Riesgo: <?= h($filterRisk) ?><?php endif; ?>
                    <?php if ($filterSensitive === '1'): ?> · Solo sensibles<?php endif; ?>
                    <?php if ($filterSource): ?> · Origen: <?= h($filterSource) ?><?php endif; ?>
                </div>
            </div>

            <!-- ═══ FORMULARIO DE CREACIÓN (colapsable) - RAT Completo Art. 14 Ley 21.719 ═══ -->
            <div id="inventory-create-form" class="hidden rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <?php renderSectionHeader('Nueva Actividad de Tratamiento', 'Registro de Actividades de Tratamiento (RAT) según Art. 14 de la Ley 21.719'); ?>

                <div class="bg-indigo-500/[0.05] border border-indigo-500/20 rounded-lg p-3.5 mb-5 text-[10px] text-text-muted leading-relaxed flex gap-2.5">
                    <svg class="w-4 h-4 text-indigo-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p><span class="text-indigo-300 font-semibold">¿Qué es una actividad de tratamiento?</span></p>
                        <p>Toda operación que realices con datos personales: recopilar, almacenar, usar, modificar, compartir o eliminar.
                           Cada actividad debe registrarse con su finalidad, base legal, categorías de datos, destinatarios y medidas de seguridad.</p>
                        <p class="mt-1"><span class="text-indigo-300 font-semibold">Art. 14 Ley 21.719:</span> El responsable debe mantener un registro documentado (RAT) de todas las actividades de tratamiento.</p>
                    </div>
                </div>

                <form method="POST" id="inventory-wizard-form">
                    <input type="hidden" name="collection" value="inventory">

                    <!-- Inventory Wizard Container -->
                    <div class="inventory-wizard-container">
                        <!-- Error Message -->
                        <div class="inventory-wizard-error" id="inventory-wizard-error">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span id="inventory-wizard-error-text">Por favor complete los campos requeridos antes de continuar.</span>
                        </div>

                        <!-- Progress Section -->
                        <div class="inventory-wizard-progress">
                            <div class="inventory-wizard-progress-header">
                                <div class="inventory-wizard-progress-title">Progreso del formulario</div>
                                <div class="inventory-wizard-progress-steps">Paso <span id="inventory-wizard-current-step">1</span> de 6</div>
                            </div>
                            <div class="inventory-wizard-progress-bar">
                                <div class="inventory-wizard-progress-fill" id="inventory-wizard-progress-fill" style="width: 16.66%"></div>
                            </div>
                            <div class="inventory-wizard-steps-indicator">
                                <div class="inventory-wizard-step-dot active" data-step="1">
                                    <div class="inventory-wizard-step-number">1</div>
                                    <div class="inventory-wizard-step-label">Identificación</div>
                                </div>
                                <div class="inventory-wizard-step-dot" data-step="2">
                                    <div class="inventory-wizard-step-number">2</div>
                                    <div class="inventory-wizard-step-label">Finalidad</div>
                                </div>
                                <div class="inventory-wizard-step-dot" data-step="3">
                                    <div class="inventory-wizard-step-number">3</div>
                                    <div class="inventory-wizard-step-label">Datos</div>
                                </div>
                                <div class="inventory-wizard-step-dot" data-step="4">
                                    <div class="inventory-wizard-step-number">4</div>
                                    <div class="inventory-wizard-step-label">Titulares</div>
                                </div>
                                <div class="inventory-wizard-step-dot" data-step="5">
                                    <div class="inventory-wizard-step-number">5</div>
                                    <div class="inventory-wizard-step-label">Acceso</div>
                                </div>
                                <div class="inventory-wizard-step-dot" data-step="6">
                                    <div class="inventory-wizard-step-number">6</div>
                                    <div class="inventory-wizard-step-label">Obs.</div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1: Identificación de la Actividad -->
                        <div class="inventory-wizard-step active" data-step="1">
                            <div class="inventory-wizard-step-title">Paso 1: Identificación de la Actividad</div>
                            <div class="inventory-wizard-fieldset">
                                <div class="inventory-wizard-fieldset-title">Información básica de la actividad (Art. 14.1.a)</div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Nombre de la actividad <span class="required">*</span></label>
                                        <input type="text" name="name" id="inventory-wizard-name" required class="compliance-input inventory-wizard-field" placeholder="Ej: Gestión de clientes y facturación">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Código / Referencia</label>
                                        <input type="text" name="code" class="compliance-input" placeholder="Ej: RAT-001">
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Responsable del tratamiento <span class="required">*</span></label>
                                        <input type="text" name="controllerName" id="inventory-wizard-controller" required class="compliance-input inventory-wizard-field" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Nombre empresa/organización">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Encargado del tratamiento (si aplica)</label>
                                        <input type="text" name="processorName" class="compliance-input" placeholder="Proveedor cloud, SaaS, etc.">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Finalidad y Base Legal -->
                        <div class="inventory-wizard-step" data-step="2">
                            <div class="inventory-wizard-step-title">Paso 2: Finalidad y Base Legal</div>
                            <div class="inventory-wizard-fieldset">
                                <div class="inventory-wizard-fieldset-title">Finalidad específica y base de licitud (Art. 14.1.b / Art. 12-13)</div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Finalidad específica <span class="required">*</span></label>
                                        <select name="purpose" id="inventory-wizard-purpose" required class="compliance-select inventory-wizard-field">
                                            <option value="">Seleccionar finalidad</option>
                                            <optgroup label="Clientes/Comercial">
                                                <option value="gestion_clientes">Gestión de clientes y facturación</option>
                                                <option value="marketing">Marketing y comunicaciones comerciales</option>
                                                <option value="soporte">Soporte técnico y atención al cliente</option>
                                                <option value="cobranza">Cobranza y recuperación de cartera</option>
                                            </optgroup>
                                            <optgroup label="Empleados/RRHH">
                                                <option value="gestion_personal">Gestión de personal y nómina</option>
                                                <option value="seguridad_social">Seguridad social y prevención de riesgos</option>
                                                <option value="capacitacion">Capacitación y desarrollo</option>
                                            </optgroup>
                                            <optgroup label="Legales/Regulatorio">
                                                <option value="cumplimiento_legal">Cumplimiento obligaciones legales/regulatorias</option>
                                                <option value="auditoria">Auditoría y control interno</option>
                                            </optgroup>
                                            <optgroup label="Otras">
                                                <option value="investigacion">Investigación y desarrollo</option>
                                                <option value="seguridad">Seguridad física/lógica de instalaciones</option>
                                                <option value="videovigilancia">Videovigilancia</option>
                                                <option value="otro">Otra (especificar en observaciones)</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Base de licitud <span class="required">*</span></label>
                                        <select name="legalBasis" id="inventory-wizard-legalBasis" required class="compliance-select inventory-wizard-field">
                                            <option value="">Seleccionar base legal</option>
                                            <option value="consentimiento">Art. 12 - Consentimiento del titular</option>
                                            <option value="ejecucion_contrato">Art. 13.1.a - Ejecución de contrato</option>
                                            <option value="obligacion_legal">Art. 13.1.b - Obligación legal</option>
                                            <option value="interes_vital">Art. 13.1.c - Interés vital</option>
                                            <option value="interes_publico">Art. 13.1.d - Interés público</option>
                                            <option value="interes_legitimo">Art. 13.1.e - Interés legítimo</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Descripción del interés legítimo (si aplica Art. 13.1.e)</label>
                                        <textarea name="legitimateInterest" rows="2" class="compliance-textarea" placeholder="Describe el interés legítimo y la ponderación realizada..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Categorías de Datos -->
                        <div class="inventory-wizard-step" data-step="3">
                            <div class="inventory-wizard-step-title">Paso 3: Categorías de Datos</div>
                            <div class="inventory-wizard-fieldset">
                                <div class="inventory-wizard-fieldset-title">Tipos de datos personales tratados (Art. 14.1.c / Art. 15-16)</div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de datos personales <span class="required">*</span></label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-identificacion" value="identificacion" class="inventory-wizard-field-checkbox">
                                            <label for="cat-identificacion"><strong>Identificación:</strong> nombre, RUT, dirección, nacionalidad</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-contacto" value="contacto" class="inventory-wizard-field-checkbox">
                                            <label for="cat-contacto"><strong>Contacto:</strong> email, teléfono, redes sociales</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-financieros" value="financieros" class="inventory-wizard-field-checkbox">
                                            <label for="cat-financieros"><strong>Financieros:</strong> cuentas bancarias, tarjetas, ingresos</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-laborales" value="laborales" class="inventory-wizard-field-checkbox">
                                            <label for="cat-laborales"><strong>Laborales:</strong> cargo, sueldo, antigüedad, evaluaciones</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-salud" value="salud" class="inventory-wizard-field-checkbox">
                                            <label for="cat-salud"><strong>Salud:</strong> historial clínico, diagnósticos, recetas</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-biometricos" value="biometricos" class="inventory-wizard-field-checkbox">
                                            <label for="cat-biometricos"><strong>Biométricos:</strong> huella, reconocimiento facial, iris</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-geneticos" value="geneticos" class="inventory-wizard-field-checkbox">
                                            <label for="cat-geneticos"><strong>Genéticos</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-ninos" value="ninos" class="inventory-wizard-field-checkbox">
                                            <label for="cat-ninos"><strong>Datos de niños</strong> (Art. 17)</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-navegacion" value="navegacion" class="inventory-wizard-field-checkbox">
                                            <label for="cat-navegacion"><strong>Navegación:</strong> IP, cookies, device ID</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-ubicacion" value="ubicacion" class="inventory-wizard-field-checkbox">
                                            <label for="cat-ubicacion"><strong>Ubicación geográfica</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-comportamiento" value="comportamiento" class="inventory-wizard-field-checkbox">
                                            <label for="cat-comportamiento"><strong>Perfilado y comportamiento</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="dataCategories[]" id="cat-antecedentes" value="antecedentes" class="inventory-wizard-field-checkbox">
                                            <label for="cat-antecedentes"><strong>Antecedentes penales/judiciales</strong></label>
                                        </div>
                                    </div>
                                </div>
                                <div class="compliance-form-row grid-cols-3 mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Incluye datos sensibles? (Art. 16)</label>
                                        <select name="fields[sensitive]" class="compliance-select">
                                            <option value="0">No</option>
                                            <option value="1">Sí - Requiere consentimiento explícito reforzado</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Incluye datos de niños? (Art. 17)</label>
                                        <select name="fields[childrenData]" class="compliance-select">
                                            <option value="0">No</option>
                                            <option value="1">Sí - Requiere consentimiento del representante legal</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Categorías de Titulares -->
                        <div class="inventory-wizard-step" data-step="4">
                            <div class="inventory-wizard-step-title">Paso 4: Categorías de Titulares</div>
                            <div class="inventory-wizard-fieldset">
                                <div class="inventory-wizard-fieldset-title">Tipos de titulares de los datos (Art. 14.1.c)</div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de titulares <span class="required">*</span></label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-clientes" value="clientes" class="inventory-wizard-field-checkbox">
                                            <label for="sub-clientes"><strong>Clientes / Usuarios</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-empleados" value="empleados" class="inventory-wizard-field-checkbox">
                                            <label for="sub-empleados"><strong>Empleados / Colaboradores</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-proveedores" value="proveedores" class="inventory-wizard-field-checkbox">
                                            <label for="sub-proveedores"><strong>Proveedores / Contratistas</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-postulantes" value="postulantes" class="inventory-wizard-field-checkbox">
                                            <label for="sub-postulantes"><strong>Postulantes a empleo</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-ninos" value="ninos" class="inventory-wizard-field-checkbox">
                                            <label for="sub-ninos"><strong>Niños / Niñas / Adolescentes</strong> (Art. 17)</label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-pacientes" value="pacientes" class="inventory-wizard-field-checkbox">
                                            <label for="sub-pacientes"><strong>Pacientes / Usuarios de salud</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-visitantes" value="visitantes" class="inventory-wizard-field-checkbox">
                                            <label for="sub-visitantes"><strong>Visitantes / Invitados</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-ex_empleados" value="ex_empleados" class="inventory-wizard-field-checkbox">
                                            <label for="sub-ex_empleados"><strong>Ex-empleados</strong></label>
                                        </div>
                                        <div class="compliance-checkbox-group">
                                            <input type="checkbox" name="fields[subjectCategories][]" id="sub-publico" value="publico_general" class="inventory-wizard-field-checkbox">
                                            <label for="sub-publico"><strong>Público general</strong></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 5: Frecuencia y Acceso -->
                        <div class="inventory-wizard-step" data-step="5">
                            <div class="inventory-wizard-step-title">Paso 5: Frecuencia y Acceso</div>
                            <div class="inventory-wizard-fieldset">
                                <div class="inventory-wizard-fieldset-title">Frecuencia, acceso y medidas de seguridad (Art. 14.1.e / Art. 25)</div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Frecuencia de tratamiento</label>
                                        <select name="fields[treatmentFrequency]" class="compliance-select">
                                            <option value="continua">Continua (24/7)</option>
                                            <option value="diaria">Diaria</option>
                                            <option value="semanal">Semanal</option>
                                            <option value="mensual">Mensual</option>
                                            <option value="ocasional">Ocasional</option>
                                            <option value="unica">Única</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Quién accede a los datos?</label>
                                        <select name="fields[accessControl]" class="compliance-select">
                                            <option value="interno_solo">Solo personal interno</option>
                                            <option value="interno_externo">Personal interno y proveedores</option>
                                            <option value="publico">Acceso público</option>
                                            <option value="terceros">Terceros autorizados</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Medidas de seguridad implementadas</label>
                                        <select name="fields[technicalMeasures][]" multiple class="compliance-select" size="5">
                                            <option value="cifrado_reposo">Cifrado en reposo (AES-256)</option>
                                            <option value="cifrado_transito">Cifrado en tránsito (TLS 1.3)</option>
                                            <option value="pseudonimizacion">Seudonimización (Art. 30)</option>
                                            <option value="acceso_controlado">Control de acceso basado en roles (RBAC)</option>
                                            <option value="mfa">Autenticación multifactor (MFA)</option>
                                            <option value="auditoria_accesos">Auditoría de accesos (logs)</option>
                                            <option value="backup_cifrado">Backups cifrados y probados</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Plazo de retención (días) <span class="required">*</span></label>
                                        <input type="number" name="fields[retentionDays]" id="inventory-wizard-retention" required class="compliance-input inventory-wizard-field" min="1" max="3650" placeholder="Ej: 365 (1 año)">
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Nivel de riesgo <span class="required">*</span></label>
                                        <select name="fields[risk]" id="inventory-wizard-risk" required class="compliance-select inventory-wizard-field">
                                            <option value="">Seleccionar nivel</option>
                                            <option value="low">Bajo - Datos básicos, pocos titulares</option>
                                            <option value="medium">Medio - Datos personales comunes</option>
                                            <option value="high">Alto - Datos sensibles / muchos titulares</option>
                                            <option value="critical">Crítico - Salud, biometría, niños, vigilancia masiva</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 6: Observaciones -->
                        <div class="wizard-step" data-step="6">
                            <div class="wizard-step-title">Paso 6: Observaciones</div>
                            <div class="wizard-fieldset">
                                <div class="wizard-fieldset-title">Información adicional y evidencia</div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Observaciones adicionales</label>
                                        <textarea name="fields[notes]" rows="4" class="compliance-textarea" placeholder="Contexto adicional, dependencias, sistemas involucrados, observaciones legales..."></textarea>
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">URL de evidencia (políticas, contratos, EIPD)</label>
                                        <input type="url" name="fields[evidenceUrl]" class="compliance-input" placeholder="https://intranet.empresa.cl/rat-001">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Wizard Navigation -->
                        <div class="wizard-navigation">
                            <button type="button" id="wizard-prev-btn" class="wizard-btn-prev" disabled>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                Anterior
                            </button>
                            <button type="button" id="wizard-next-btn" class="wizard-btn-next">
                                Siguiente
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                            <button type="submit" id="wizard-submit-btn" name="create_item" value="1" class="wizard-btn-submit" style="display: none;">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Registrar actividad en RAT (Art. 14)
                            </button>
                            <button type="button" onclick="document.getElementById('inventory-create-form').classList.add('hidden')" class="wizard-btn-prev">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ═══ TABLA DE INVENTARIO ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-border-theme/20 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg border border-white/[0.04] bg-white/[0.01] flex items-center justify-center text-cyan-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        </div>
                        <p class="text-[12px] font-semibold text-white">Registro de Actividades de Tratamiento (RAT)</p>
                        <span class="text-[10px] text-text-subtle"><?= $totalFiltered ?> / <?= $totalItems ?> actividades</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="/compliance-export?type=ropa" class="text-[10px] text-primary-400 hover:text-primary-300 font-medium transition-colors flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Exportar RAT (CSV)
                        </a>
                    </div>
                </div>

                <?php if (empty($filtered)): ?>
                    <div class="px-5 py-12 text-center">
                        <?php if ($search || $filterRisk || $filterSensitive || $filterSource): ?>
                            <p class="text-[12px] text-text-subtle">No hay registros que coincidan con los filtros aplicados.</p>
                            <button onclick="clearFilters()" class="mt-2 text-[11px] text-primary-400 hover:text-primary-300">Limpiar filtros</button>
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-xl bg-bg-elevated border border-border-theme flex items-center justify-center mx-auto mb-4">
                                <svg class="w-6 h-6 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                            </div>
                            <h3 class="text-white font-semibold mb-2">Sin actividades de tratamiento</h3>
                            <p class="text-text-muted text-[12px]">Haz clic en <span class="text-primary-400">"Nuevo"</span> para registrar tu primera actividad.</p>
                            <p class="text-text-subtle text-[11px] mt-2 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-cyan-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                <span><span class="text-cyan-400">Consejo:</span> Sube un archivo o conecta una base de datos para generar actividades automáticamente.</span>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="border-b border-border-theme bg-bg-base/60 text-text-muted uppercase text-[10px] tracking-wider">
                                    <th class="text-left py-2.5 px-3">
                                        <a href="?tab=inventory&sort=name&dir=<?= $sortBy === 'name' && $sortDir === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterRisk ? '&risk='.urlencode($filterRisk) : '' ?>"
                                           class="hover:text-text-heading transition-colors flex items-center gap-1">
                                            Actividad
                                            <?php if ($sortBy === 'name'): ?>
                                                <span class="text-[8px]"><?= $sortDir === 'asc' ? '↑' : '↓' ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="text-left py-2.5 px-3">
                                        <a href="?tab=inventory&sort=dataCategories&dir=<?= $sortBy === 'dataCategories' && $sortDir === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterRisk ? '&risk='.urlencode($filterRisk) : '' ?>"
                                           class="hover:text-text-heading transition-colors flex items-center gap-1">
                                            Categorías
                                            <?php if ($sortBy === 'dataCategories'): ?>
                                                <span class="text-[8px]"><?= $sortDir === 'asc' ? '↑' : '↓' ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="text-left py-2.5 px-3">
                                        <a href="?tab=inventory&sort=legalBasis&dir=<?= $sortBy === 'legalBasis' && $sortDir === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterRisk ? '&risk='.urlencode($filterRisk) : '' ?>"
                                           class="hover:text-text-heading transition-colors flex items-center gap-1">
                                            Base legal
                                            <?php if ($sortBy === 'legalBasis'): ?>
                                                <span class="text-[8px]"><?= $sortDir === 'asc' ? '↑' : '↓' ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="text-left py-2.5 px-3">
                                        <a href="?tab=inventory&sort=risk&dir=<?= $sortBy === 'risk' && $sortDir === 'asc' ? 'desc' : 'asc' ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $filterRisk ? '&risk='.urlencode($filterRisk) : '' ?>"
                                           class="hover:text-text-heading transition-colors flex items-center gap-1">
                                            Riesgo
                                            <?php if ($sortBy === 'risk'): ?>
                                                <span class="text-[8px]"><?= $sortDir === 'asc' ? '↑' : '↓' ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="text-left py-2.5 px-3">Sensibles</th>
                                    <th class="text-left py-2.5 px-3">Origen</th>
                                    <th class="text-left py-2.5 px-3">Retención</th>
                                    <th class="text-center py-2.5 px-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-theme/30">
                                <?php foreach ($filtered as $it):
                                    $risk = $it['risk'] ?? 'low';
                                    $riskColors = [
                                        'critical' => ['text' => 'text-red-400', 'bg' => 'bg-red-500/15', 'border' => 'border-red-500/25', 'dot' => 'bg-red-400', 'label' => 'Crítico'],
                                        'high' => ['text' => 'text-yellow-400', 'bg' => 'bg-yellow-500/15', 'border' => 'border-yellow-500/25', 'dot' => 'bg-yellow-400', 'label' => 'Alto'],
                                        'medium' => ['text' => 'text-blue-400', 'bg' => 'bg-blue-500/15', 'border' => 'border-blue-500/25', 'dot' => 'bg-blue-400', 'label' => 'Medio'],
                                        'low' => ['text' => 'text-text-muted', 'bg' => 'bg-bg-elevated/50', 'border' => 'border-border-theme', 'dot' => 'bg-text-subtle', 'label' => 'Bajo'],
                                    ];
                                    $rc = $riskColors[$risk] ?? $riskColors['low'];
                                    $dc = $it['dataCategories'] ?? '';
                                    if (is_array($dc)) $dc = implode(', ', $dc);
                                    $sourceType = $it['sourceType'] ?? 'database';
                                    $sourceLabel = $sourceType === 'file' ? 'Archivo' : 'Base de datos';
                                    $sourceIcon = $sourceType === 'file'
                                        ? '<svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>'
                                        : '<svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>';
                                    $sourceId = $it['sourceId'] ?? null;
                                    $isComplete = !empty($it['name']) && !empty($it['legalBasis']) && !empty($it['dataCategories']);
                                ?>
                                <tr class="border-t border-border-theme/30 hover:bg-bg-base/40 transition-colors">
                                    <td class="py-2.5 px-3">
                                        <span class="text-[12px] font-medium text-text-heading"><?= h($it['name'] ?? 'Sin nombre') ?></span>
                                        <?php if (!$isComplete): ?>
                                            <span class="ml-1 text-[8px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">Incompleto</span>
                                        <?php endif; ?>
                                        <?php if (!empty($it['purpose'])): ?>
                                            <span class="block text-[9px] text-text-subtle mt-0.5"><?= h($it['purpose']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-body max-w-[120px] truncate" title="<?= h($dc) ?>">
                                        <?= h($dc ?: '-') ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]"><?= h($it['legalBasis'] ?? '-') ?></td>
                                    <td class="py-2.5 px-3">
                                        <span class="inline-flex items-center gap-1.5 text-[10px] px-2 py-0.5 rounded-full border <?= $rc['bg'] ?> <?= $rc['text'] ?> <?= $rc['border'] ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $rc['dot'] ?>"></span>
                                            <?= $rc['label'] ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <?php if (!empty($it['sensitive'])): ?>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 inline-flex items-center gap-1 w-fit">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                                                Sensible
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-text-subtle">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[10px] text-text-muted inline-flex items-center gap-1">
                                            <?= $sourceIcon ?>
                                            <?= $sourceLabel ?>
                                            <?php if ($sourceType === 'file' && $sourceId): ?>
                                                <a href="/compliance?tab=files" class="text-cyan-400 hover:text-cyan-300 text-[9px] inline-flex items-center" title="Ver archivo origen">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            <?php elseif ($sourceType === 'database' && $sourceId): ?>
                                                <a href="/databases" class="text-cyan-400 hover:text-cyan-300 text-[9px] inline-flex items-center" title="Ver base de datos origen">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]">
                                        <?= !empty($it['retentionDays']) ? h($it['retentionDays'] . ' días') : '-' ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <!-- Ver Detalle -->
                                            <button onclick="openInventoryDetailModal('<?= h($it['_id'] ?? '') ?>')"
                                                    class="p-2 rounded-lg text-text-muted hover:text-indigo-400 hover:bg-indigo-500/10 transition-all"
                                                    title="Ver detalle completo">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>

                                            <!-- Editar (más grande) -->
                                            <button onclick="openInventoryEditModal('<?= h($it['_id'] ?? '') ?>')"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-cyan-500/10 border border-cyan-500/25 text-cyan-400 hover:bg-cyan-500/20 transition-all"
                                                    title="Editar actividad">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                Editar
                                            </button>

                                            <!-- Eliminar -->
                                            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar esta actividad de tratamiento? Esta acción no se puede deshacer.')">
                                                <input type="hidden" name="collection" value="inventory">
                                                <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                                                <button type="submit" name="delete_item" value="1"
                                                        class="p-1.5 rounded-lg text-text-muted hover:text-red-400 hover:bg-red-500/10 transition-all"
                                                        title="Eliminar">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Footer de la tabla -->
                    <div class="px-5 py-2.5 border-t border-border-theme/20 flex items-center justify-between text-[10px] text-text-subtle">
                        <span><?= $totalFiltered ?> actividades mostradas</span>
                        <span>Última actualización: <?= date('H:i:s') ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================================================ -->
            <!-- ═══ MODAL: DETALLE COMPLETO (con explicaciones) ═══ -->
            <!-- ============================================================ -->
            <div id="inventory-detail-modal" class="hidden fixed inset-0 bg-black/75 flex items-center justify-center z-50 p-4">
                <div class="bg-bg-panel border border-border-theme rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0">
                        <div>
                            <h3 class="text-[15px] font-semibold text-white" id="detail-title">Detalle de actividad</h3>
                            <p class="text-[10px] text-text-subtle" id="detail-subtitle">Información completa del registro de tratamiento</p>
                        </div>
                        <button onclick="document.getElementById('inventory-detail-modal').classList.add('hidden')"
                                class="text-text-muted hover:text-text-heading transition-colors p-1 rounded-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <!-- Cuerpo -->
                    <div class="flex-1 overflow-y-auto p-5 scrollbar-custom space-y-4" id="detail-body">
                        <!-- Los datos se cargan dinámicamente con JavaScript -->
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end gap-2 px-5 py-4 border-t border-border-theme flex-shrink-0">
                        <button onclick="document.getElementById('inventory-detail-modal').classList.add('hidden')"
                                class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cerrar</button>
                        <button onclick="closeDetailAndEdit()" id="detail-edit-btn"
                                class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all">Editar actividad</button>
                    </div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ═══ MODAL: EDICIÓN COMPLETA (con guía) ═══ -->
            <!-- ============================================================ -->
            <div id="inventory-edit-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                <div class="bg-bg-panel border border-border-theme rounded-2xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme flex-shrink-0">
                        <div>
                            <h3 class="text-[15px] font-semibold text-white">Editar actividad de tratamiento</h3>
                            <p class="text-[11px] text-text-subtle mt-0.5">Actualiza los datos de esta actividad según lo requerido por la Ley 21.719</p>
                        </div>
                        <button onclick="document.getElementById('inventory-edit-modal').classList.add('hidden')"
                                class="text-text-muted hover:text-text-heading transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <!-- Cuerpo con formulario -->
                    <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
                        <!-- Leyenda de ayuda -->
                        <div class="bg-indigo-500/[0.05] border border-indigo-500/20 rounded-lg p-3.5 mb-5 text-[11px] text-text-muted leading-relaxed flex gap-2.5">
                            <svg class="w-4 h-4 text-indigo-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <p><span class="text-indigo-300 font-semibold">¿Por qué es importante este registro?</span></p>
                                <p>El Art. 14 de la Ley 21.719 exige mantener un <strong class="text-white">Registro de Actividades de Tratamiento (RAT)</strong> actualizado.
                                   Este registro es lo primero que revisará la APDP en una fiscalización.</p>
                            </div>
                        </div>

                        <form id="inventory-edit-form" method="POST" class="space-y-4">
                            <input type="hidden" name="update_inventory_item" value="1">
                            <input type="hidden" name="item_id" id="edit-item-id">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Nombre -->
                                <div>
                                    <label class="compliance-form-label">Nombre de la actividad <span class="text-red-400">*</span></label>
                                    <input type="text" name="name" id="edit-name" required class="compliance-input w-full" placeholder="Ej: Gestión de clientes">
                                    <p class="text-[9px] text-text-subtle mt-1">Identifica claramente qué tratamiento realizas. Debe ser específico.</p>
                                </div>

                                <!-- Propósito -->
                                <div>
                                    <label class="compliance-form-label">Finalidad / Propósito <span class="text-[9px] text-text-subtle font-normal">(Art. 3 letra b)</span></label>
                                    <input type="text" name="purpose" id="edit-purpose" class="compliance-input w-full" placeholder="Ej: Enviar facturación y promociones">
                                    <p class="text-[9px] text-text-subtle mt-1">La ley exige finalidades determinadas y explícitas.</p>
                                </div>

                                <!-- Categorías -->
                                <div>
                                    <label class="compliance-form-label">Categorías de datos</label>
                                    <input type="text" name="dataCategories" id="edit-categories" class="compliance-input w-full" placeholder="Ej: nombres, RUT, emails, teléfonos">
                                    <p class="text-[9px] text-text-subtle mt-1">Enumera los tipos de datos personales que tratas.</p>
                                </div>

                                <!-- Base legal -->
                                <div>
                                    <label class="compliance-form-label">Base de licitud <span class="text-red-400">*</span></label>
                                    <select name="legalBasis" id="edit-legalBasis" required class="compliance-input w-full">
                                        <option value="">Seleccionar...</option>
                                        <option value="Consentimiento">Consentimiento del titular (Art. 12)</option>
                                        <option value="Ejecución de contrato">Ejecución de contrato (Art. 13)</option>
                                        <option value="Obligación legal">Obligación legal (Art. 13)</option>
                                        <option value="Interés legítimo">Interés legítimo (Art. 13)</option>
                                        <option value="Interés público">Interés público (Art. 13)</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">Sin una base legal válida, el tratamiento es ilegal.</p>
                                </div>

                                <!-- Riesgo -->
                                <div>
                                    <label class="compliance-form-label">Nivel de riesgo</label>
                                    <select name="risk" id="edit-risk" class="compliance-input w-full">
                                        <option value="low">Bajo - Datos básicos (nombres, teléfonos)</option>
                                        <option value="medium">Medio - Datos personales comunes (RUT, dirección)</option>
                                        <option value="high">Alto - Datos sensibles o muchos registros</option>
                                        <option value="critical">Crítico - Datos muy sensibles (salud, biometría)</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">A mayor riesgo, mayores medidas de seguridad.</p>
                                </div>

                                <!-- Sensibles -->
                                <div>
                                    <label class="compliance-form-label">Datos sensibles</label>
                                    <select name="sensitive" id="edit-sensitive" class="compliance-input w-full">
                                        <option value="0">No contiene datos sensibles</option>
                                        <option value="1">Sí - Salud, biometría, religión, origen racial, etc.</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">Según Art. 16: salud, origen racial, creencias religiosas, vida sexual, etc.</p>
                                </div>

                                <!-- Retención -->
                                <div>
                                    <label class="compliance-form-label">Días de retención</label>
                                    <input type="number" name="retentionDays" id="edit-retention" class="compliance-input w-full" placeholder="Ej: 365" min="0">
                                    <p class="text-[9px] text-text-subtle mt-1">No conservar más tiempo del necesario (Art. 14).</p>
                                </div>

                                <!-- Almacenamiento -->
                                <div>
                                    <label class="compliance-form-label">Almacenamiento</label>
                                    <input type="text" name="storage" id="edit-storage" class="compliance-input w-full" placeholder="Ej: AWS, servidor local, Google Drive">
                                    <p class="text-[9px] text-text-subtle mt-1">¿Dónde se guardan físicamente estos datos?</p>
                                </div>
                            </div>

                            <!-- Mensaje de estado -->
                            <div id="edit-msg" class="hidden p-3 rounded-lg text-[11px]"></div>

                            <!-- Botones -->
                            <div class="flex justify-end gap-2 pt-3 border-t border-border-theme">
                                <button type="button" onclick="document.getElementById('inventory-edit-modal').classList.add('hidden')"
                                        class="px-4 py-2 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                                <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2 text-[12px] font-semibold rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all hover:from-blue-500 hover:to-indigo-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Guardar cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            // ─── Datos de inventario (para uso en JS) ───
            const inventoryData = <?= json_encode($inventoryItems, JSON_UNESCAPED_UNICODE) ?>;

            // ─── Filtros ───
            function updateFilters() {
                const search = document.getElementById('inventory-search').value;
                const risk = document.getElementById('filter-risk').value;
                const sensitive = document.getElementById('filter-sensitive').value;
                const source = document.getElementById('filter-source').value;

                let url = '?tab=inventory';
                if (search) url += '&search=' + encodeURIComponent(search);
                if (risk) url += '&risk=' + encodeURIComponent(risk);
                if (sensitive !== '') url += '&sensitive=' + encodeURIComponent(sensitive);
                if (source) url += '&source=' + encodeURIComponent(source);

                window.location.href = url;
            }

            function clearFilters() {
                window.location.href = '?tab=inventory';
            }

            // ─── Enter para buscar ───
            document.getElementById('inventory-search')?.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') updateFilters();
            });

            // ─── Modal: Detalle ───
            let detailItemId = null;

            function openInventoryDetailModal(itemId) {
                detailItemId = itemId;
                const item = inventoryData.find(i => i._id === itemId);
                if (!item) return;

                document.getElementById('detail-title').textContent = item.name || 'Actividad sin nombre';
                document.getElementById('detail-subtitle').textContent = 'ID: ' + itemId.substring(0, 8) + '...';

                const body = document.getElementById('detail-body');

                // Determinar estado de completitud
                const isComplete = !!(item.name && item.legalBasis && item.dataCategories);
                const missingFields = [];
                if (!item.name) missingFields.push('Nombre');
                if (!item.legalBasis) missingFields.push('Base legal');
                if (!item.dataCategories) missingFields.push('Categorías de datos');

                // Mapeo de riesgo
                const riskLabels = {
                    'critical': 'Crítico',
                    'high': 'Alto',
                    'medium': 'Medio',
                    'low': 'Bajo'
                };

                // Mapeo de origen
                const sourceLabels = {
                    'database': 'Base de datos',
                    'file': 'Archivo'
                };

                body.innerHTML = `
                    <!-- Estado de cumplimiento -->
                    <div class="rounded-lg p-3 ${isComplete ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-amber-500/10 border border-amber-500/20'}">
                        <div class="flex items-center gap-2">
                            <span class="${isComplete ? 'text-emerald-400' : 'text-amber-400'}">
                                ${isComplete
                                    ? '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>'
                                    : '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>'}
                            </span>
                            <span class="text-[12px] font-semibold ${isComplete ? 'text-emerald-400' : 'text-amber-400'}">
                                ${isComplete ? 'Registro completo' : 'Registro incompleto'}
                            </span>
                        </div>
                        ${!isComplete ? `<p class="text-[10px] text-text-muted mt-1">Faltan campos obligatorios: ${missingFields.join(', ')}</p>` : ''}
                        <p class="text-[9px] text-text-subtle mt-1">${isComplete ? 'Cumple con los requisitos mínimos del Art. 14' : 'Completa los campos faltantes para estar al día con la ley'}</p>
                    </div>

                    <!-- Información principal -->
                    <div class="compliance-form-row">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Nombre</p>
                            <p class="text-[13px] font-medium text-white">${escHtml(item.name || 'Sin nombre')}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Finalidad</p>
                            <p class="text-[13px] text-white">${escHtml(item.purpose || 'No especificada')}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Categorías de datos</p>
                            <p class="text-[13px] text-white">${escHtml(item.dataCategories || 'No especificadas')}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Base legal</p>
                            <p class="text-[13px] font-medium text-cyan-400">${escHtml(item.legalBasis || 'No definida')}</p>
                            ${item.legalBasis ? `<p class="text-[8px] text-text-subtle mt-0.5">Art. ${item.legalBasis === 'Consentimiento' ? '12' : '13'} Ley 21.719</p>` : ''}
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Nivel de riesgo</p>
                            <p class="text-[13px] font-medium">${riskLabels[item.risk] || 'Bajo'}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Datos sensibles</p>
                            <p class="text-[13px] font-medium ${item.sensitive ? 'text-red-400' : 'text-text-muted'}">
                                ${item.sensitive ? 'Sí - Requiere protección especial' : 'No'}
                            </p>
                            ${item.sensitive ? `<p class="text-[8px] text-text-subtle mt-0.5">Art. 16 - Requiere consentimiento explícito</p>` : ''}
                        </div>
                    </div>

                    <!-- Información adicional -->
                    <div class="compliance-form-row">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Origen</p>
                            <p class="text-[13px] text-white flex items-center gap-2">
                                ${sourceLabels[item.sourceType] || 'Base de datos'}
                                ${item.sourceId ? `<a href="${item.sourceType === 'file' ? '/compliance?tab=files' : '/databases'}" class="text-[10px] text-cyan-400 hover:text-cyan-300 inline-flex items-center gap-1"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg> Ver origen</a>` : ''}
                            </p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Retención</p>
                            <p class="text-[13px] text-white">${item.retentionDays ? item.retentionDays + ' días' : 'No definida'}</p>
                            ${!item.retentionDays ? `<p class="text-[8px] text-text-subtle mt-0.5">Recomendado: define un plazo de retención (Art. 14)</p>` : ''}
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Almacenamiento</p>
                            <p class="text-[13px] text-white">${escHtml(item.storage || 'No especificado')}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Fecha de creación</p>
                            <p class="text-[13px] text-white">${item.createdAt ? new Date(item.createdAt).toLocaleString('es-CL') : 'No disponible'}</p>
                        </div>
                    </div>

                    <!-- Consejos de cumplimiento -->
                    <div class="bg-emerald-500/[0.03] border border-emerald-500/20 rounded-lg p-3">
                        <p class="text-[10px] font-semibold text-emerald-400 flex items-center gap-2">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>Consejos de cumplimiento para esta actividad</span>
                        </p>
                        <ul class="text-[10px] text-text-muted space-y-1 mt-1.5 list-disc list-inside">
                            ${!item.legalBasis ? '<li><span class="text-amber-400">Falta base legal:</span> Define si el tratamiento se basa en consentimiento, contrato, obligación legal, interés legítimo o interés público.</li>' : ''}
                            ${!item.dataCategories ? '<li><span class="text-amber-400">Faltan categorías:</span> Especifica qué tipos de datos personales tratas (nombres, RUT, emails, etc.).</li>' : ''}
                            ${!item.retentionDays ? '<li>Define un plazo de retención para estos datos. La ley exige que no se conserven más tiempo del necesario.</li>' : ''}
                            ${item.sensitive ? '<li><span class="text-red-400">Dato sensible detectado:</span> Asegúrate de tener consentimiento explícito por escrito y medidas de seguridad reforzadas.</li>' : ''}
                            ${item.risk === 'high' || item.risk === 'critical' ? '<li><span class="text-yellow-400">Riesgo alto/crítico:</span> Considera realizar una Evaluación de Impacto (DPIA) según Art. 14 quater.</li>' : ''}
                            ${isComplete ? '<li>Este registro cumple con los requisitos mínimos del Art. 14 de la Ley 21.719.</li>' : ''}
                        </ul>
                    </div>
                `;

                // Configurar botón de edición
                document.getElementById('detail-edit-btn').onclick = function() {
                    document.getElementById('inventory-detail-modal').classList.add('hidden');
                    openInventoryEditModal(itemId);
                };

                document.getElementById('inventory-detail-modal').classList.remove('hidden');
            }

            function closeDetailAndEdit() {
                document.getElementById('inventory-detail-modal').classList.add('hidden');
                if (detailItemId) openInventoryEditModal(detailItemId);
            }

            // ─── Modal: Edición ───
            function openInventoryEditModal(itemId) {
                const item = inventoryData.find(i => i._id === itemId);
                if (!item) return;

                document.getElementById('edit-item-id').value = itemId;
                document.getElementById('edit-name').value = item.name || '';
                document.getElementById('edit-purpose').value = item.purpose || '';
                document.getElementById('edit-categories').value = typeof item.dataCategories === 'string' ? item.dataCategories : (item.dataCategories || '');
                document.getElementById('edit-legalBasis').value = item.legalBasis || '';
                document.getElementById('edit-risk').value = item.risk || 'low';
                document.getElementById('edit-sensitive').value = item.sensitive ? '1' : '0';
                document.getElementById('edit-retention').value = item.retentionDays || '';
                document.getElementById('edit-storage').value = item.storage || '';

                // Ocultar mensaje anterior
                const msg = document.getElementById('edit-msg');
                msg.classList.add('hidden');

                document.getElementById('inventory-edit-modal').classList.remove('hidden');
            }

            // ─── Envío del formulario de edición (AJAX) ───
            document.getElementById('inventory-edit-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const msg = document.getElementById('edit-msg');

                const payload = {
                    token: '<?= h($token) ?>',
                    name: formData.get('name'),
                    purpose: formData.get('purpose'),
                    dataCategories: formData.get('dataCategories'),
                    legalBasis: formData.get('legalBasis'),
                    risk: formData.get('risk'),
                    sensitive: formData.get('sensitive') === '1',
                    retentionDays: parseInt(formData.get('retentionDays')) || null,
                    storage: formData.get('storage'),
                };

                msg.classList.remove('hidden');
                msg.textContent = 'Guardando cambios...';
                msg.className = 'p-3 rounded-lg text-[11px] bg-blue-500/10 border border-blue-500/20 text-blue-400';

                try {
                    const res = await fetch('/api/compliance/inventory/' + formData.get('item_id'), {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        msg.textContent = 'Cambios guardados correctamente. Recargando...';
                        msg.className = 'p-3 rounded-lg text-[11px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400';
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        msg.textContent = (data.error || 'Error al guardar los cambios');
                        msg.className = 'p-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/20 text-red-400';
                    }
                } catch (e) {
                    msg.textContent = 'Error de conexión: ' + e.message;
                    msg.className = 'p-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/20 text-red-400';
                }
            });

            // ─── Utilidad: escape HTML ───
            function escHtml(str) {
                if (!str) return '';
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            // ─── Inventory Wizard JavaScript (Independent Implementation) ───
            (function() {
                const form = document.getElementById('inventory-wizard-form');
                if (!form) return;

                let currentStep = 1;
                const totalSteps = 6;

                // Step validation rules
                const stepValidation = {
                    1: function() {
                        const name = document.getElementById('inventory-wizard-name').value.trim();
                        const controller = document.getElementById('inventory-wizard-controller').value.trim();
                        if (!name) return 'Por favor ingrese el nombre de la actividad.';
                        if (!controller) return 'Por favor ingrese el responsable del tratamiento.';
                        return null;
                    },
                    2: function() {
                        const purpose = document.getElementById('inventory-wizard-purpose').value;
                        const legalBasis = document.getElementById('inventory-wizard-legalBasis').value;
                        if (!purpose) return 'Por favor seleccione la finalidad específica.';
                        if (!legalBasis) return 'Por favor seleccione la base de licitud.';
                        return null;
                    },
                    3: function() {
                        const checkboxes = form.querySelectorAll('.inventory-wizard-field-checkbox:checked');
                        if (checkboxes.length === 0) return 'Por favor seleccione al menos una categoría de datos.';
                        return null;
                    },
                    4: function() {
                        const checkboxes = form.querySelectorAll('.inventory-wizard-step[data-step="4"] .inventory-wizard-field-checkbox:checked');
                        if (checkboxes.length === 0) return 'Por favor seleccione al menos una categoría de titulares.';
                        return null;
                    },
                    5: function() {
                        const retention = document.getElementById('inventory-wizard-retention').value;
                        const risk = document.getElementById('inventory-wizard-risk').value;
                        if (!retention) return 'Por favor ingrese el plazo de retención.';
                        if (!risk) return 'Por favor seleccione el nivel de riesgo.';
                        return null;
                    },
                    6: function() {
                        return null; // No required fields in step 6
                    }
                };

                function showError(message) {
                    const errorEl = document.getElementById('inventory-wizard-error');
                    const errorText = document.getElementById('inventory-wizard-error-text');
                    if (errorEl && errorText) {
                        errorText.textContent = message;
                        errorEl.classList.add('show');
                    }
                }

                function hideError() {
                    const errorEl = document.getElementById('inventory-wizard-error');
                    if (errorEl) {
                        errorEl.classList.remove('show');
                    }
                }

                function updateWizard() {
                    // Update step visibility
                    document.querySelectorAll('.inventory-wizard-step').forEach(el => {
                        el.classList.remove('active');
                        if (parseInt(el.dataset.step) === currentStep) {
                            el.classList.add('active');
                        }
                    });

                    // Update step indicators
                    document.querySelectorAll('.inventory-wizard-step-dot').forEach(el => {
                        const step = parseInt(el.dataset.step);
                        el.classList.remove('active', 'completed');
                        if (step === currentStep) {
                            el.classList.add('active');
                        } else if (step < currentStep) {
                            el.classList.add('completed');
                        }
                    });

                    // Update progress bar
                    const progress = (currentStep / totalSteps) * 100;
                    document.getElementById('inventory-wizard-progress-fill').style.width = progress + '%';
                    document.getElementById('inventory-wizard-current-step').textContent = currentStep;

                    // Update navigation buttons
                    const prevBtn = document.getElementById('inventory-wizard-prev-btn');
                    const nextBtn = document.getElementById('inventory-wizard-next-btn');
                    const submitBtn = document.getElementById('inventory-wizard-submit-btn');

                    prevBtn.disabled = currentStep === 1;
                    if (currentStep === totalSteps) {
                        nextBtn.style.display = 'none';
                        if (submitBtn) submitBtn.classList.add('visible');
                    } else {
                        nextBtn.style.display = 'inline-flex';
                        if (submitBtn) submitBtn.classList.remove('visible');
                    }

                    hideError();
                }

                function validateCurrentStep() {
                    const validator = stepValidation[currentStep];
                    if (validator) {
                        const error = validator();
                        if (error) {
                            showError(error);
                            return false;
                        }
                    }
                    return true;
                }

                // Navigation functions
                window.InventoryWizard = {
                    nextStep: function() {
                        if (currentStep < totalSteps && validateCurrentStep()) {
                            currentStep++;
                            updateWizard();
                            window.scrollTo({ top: form.offsetTop - 100, behavior: 'smooth' });
                        }
                    },
                    prevStep: function() {
                        if (currentStep > 1) {
                            currentStep--;
                            updateWizard();
                            window.scrollTo({ top: form.offsetTop - 100, behavior: 'smooth' });
                        }
                    }
                };

                // Event listeners
                document.getElementById('inventory-wizard-prev-btn').addEventListener('click', window.InventoryWizard.prevStep);
                document.getElementById('inventory-wizard-next-btn').addEventListener('click', window.InventoryWizard.nextStep);

                // Initialize wizard
                updateWizard();
            })();
            </script>

            <?php elseif ($tab === 'privacy'): ?>
            <div class="mb-4">
                <h3 class="text-[14px] md:text-[15px] font-semibold text-text-heading">Política de Privacidad</h3>
                <p class="text-[11px] md:text-[12px] text-text-muted mt-1">Configuración de políticas de privacidad</p>
            </div>
            
            <!-- Formulario de configuración de DPD con diseño profesional -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <h4 class="text-[13px] font-semibold text-text-heading mb-4">Configuración del Delegado de Protección de Datos (DPD)</h4>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="save_config" value="1">
                    
                    <div class="compliance-form-row">
                        <div>
                            <label class="compliance-form-label">Nombre de la empresa *</label>
                            <input type="text" name="companyName" required class="compliance-input w-full" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Nombre de la empresa responsable">
                        </div>
                        <div>
                            <label class="compliance-form-label">Nombre del DPD *</label>
                            <input type="text" name="dpdName" required class="compliance-input w-full" value="<?= h($config['dpdName'] ?? '') ?>" placeholder="Nombre completo del Delegado">
                        </div>
                        <div>
                            <label class="compliance-form-label">Email del DPD *</label>
                            <input type="email" name="dpdEmail" required class="compliance-input w-full" value="<?= h($config['dpdEmail'] ?? '') ?>" placeholder="dpd@empresa.cl">
                        </div>
                        <div>
                            <label class="compliance-form-label">Teléfono del DPD</label>
                            <input type="tel" name="dpdPhone" class="compliance-input w-full" value="<?= h($config['dpdPhone'] ?? '') ?>" placeholder="+56 9 1234 5678">
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="apdpRegistered" id="apdpRegistered" value="1" <?= ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                        <label for="apdpRegistered" class="text-[11px] text-text-body">Registrado en APDP (Agencia de Protección de Datos Personales)</label>
                    </div>
                    
                    <div>
                        <label class="compliance-form-label">Nivel de Cumplimiento</label>
                        <select name="complianceLevel" class="compliance-input w-full">
                            <option value="basic" <?= ($config['complianceLevel'] ?? '') === 'basic' ? 'selected' : '' ?>>Básico</option>
                            <option value="intermediate" <?= ($config['complianceLevel'] ?? '') === 'intermediate' ? 'selected' : '' ?>>Intermedio</option>
                            <option value="advanced" <?= ($config['complianceLevel'] ?? '') === 'advanced' ? 'selected' : '' ?>>Avanzado</option>
                            <option value="certified" <?= ($config['complianceLevel'] ?? '') === 'certified' ? 'selected' : '' ?>>Certificado</option>
                        </select>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn-primary">
                            Guardar Configuración DPD
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-xl border border-blue-500/20 bg-blue-500/[0.02] p-5 mb-5">
                <h4 class="text-[13px] font-semibold text-text-heading mb-4">Configuración de Políticas</h4>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="update_config" value="1">
                    
                    <div>
                        <label class="compliance-form-label">URL Política de Privacidad</label>
                        <input type="url" name="privacyPolicyUrl" class="compliance-input w-full" value="<?= h($config['privacyPolicyUrl'] ?? '') ?>" placeholder="https://empresa.cl/privacidad">
                    </div>
                    
                    <div>
                        <label class="compliance-form-label">URL Política de Cookies</label>
                        <input type="url" name="cookiesPolicyUrl" class="compliance-input w-full" value="<?= h($config['cookiesPolicyUrl'] ?? '') ?>" placeholder="https://empresa.cl/cookies">
                    </div>
                    
                    <div>
                        <label class="compliance-form-label">Política de Retención de Datos</label>
                        <textarea name="dataRetentionPolicy" rows="4" class="compliance-input w-full" placeholder="Describa los plazos de retención..."><?= h($config['dataRetentionPolicy'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-3 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn-primary">
                            Guardar Políticas
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'breaches'): ?>
            <?php
            $bOpen = count(array_filter($items, fn($it) => ($it['status'] ?? '') !== 'resolved'));
            $bResolved = count($items) - $bOpen;
            $bCritical = count(array_filter($items, fn($it) => ($it['severity'] ?? '') === 'critical' && ($it['status'] ?? '') !== 'resolved'));
            $sevBadge = ['critical' => 'bg-red-500/15 text-red-400 border-red-500/30', 'high' => 'bg-orange-500/15 text-orange-400 border-orange-500/30', 'medium' => 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30', 'low' => 'bg-green-500/15 text-green-400 border-green-500/30'];
            $sevLabel = ['critical' => 'Crítica', 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja'];
            ?>
            <?php renderSectionHeader('Brechas', 'Registro de incidentes de seguridad y violaciones de datos — Art. 26 de la Ley 21.719', 'breaches'); ?>
            <div class="px-4 py-3 rounded-lg bg-red-500/[0.06] border border-red-500/20 text-[11px] text-text-body">
                <b class="text-red-300">Ley 21.719 Art. 26:</b> Notificación a APDP sin dilación indebida (máx. 72h tras conocimiento) y a titulares si hay riesgo alto para sus derechos. Multas hasta 20.000 UTM.
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('alert')); ?>
                <?php renderComplianceStat('Abiertas', $bOpen, $bOpen ? 'text-amber-400' : 'text-emerald-400', cIcon('alert')); ?>
                <?php renderComplianceStat('Críticas activas', $bCritical, $bCritical ? 'text-red-400' : 'text-text-subtle', cIcon('alert')); ?>
                <?php renderComplianceStat('Resueltas', $bResolved, 'text-emerald-400', cIcon('check')); ?>
            </div>

            <!-- Formulario wizard de brecha (Art. 26 Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 md:p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-[15px] font-semibold text-text-heading">Nuevo Registro de Brecha</h3>
                    <?php renderImportBtn('breaches'); ?>
                </div>

                <div class="wizard-container">
                    <form method="POST" id="breachWizardForm">
                        <input type="hidden" name="collection" value="breaches">
                        
                        <!-- Wizard Progress -->
                        <div class="wizard-progress">
                            <div class="wizard-progress-header">
                                <span class="wizard-step-counter">Paso <span id="currentStepNum">1</span> de 5</span>
                            </div>
                            <div class="wizard-progress-bar">
                                <div class="wizard-progress-fill" id="progressFill" style="width: 20%"></div>
                            </div>
                            <div class="wizard-step-titles">
                                <span class="wizard-step-title active" data-step="1">Identificación</span>
                                <span class="wizard-step-title" data-step="2">Datos Afectados</span>
                                <span class="wizard-step-title" data-step="3">Evaluación Riesgo</span>
                                <span class="wizard-step-title" data-step="4">Notificaciones</span>
                                <span class="wizard-step-title" data-step="5">Resolución</span>
                            </div>
                        </div>

                        <!-- Step Error Message -->
                        <div class="wizard-step-error" id="stepError">
                            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span id="stepErrorText">Por favor complete los campos requeridos antes de continuar.</span>
                        </div>

                        <!-- PASO 1: Identificación del Incidente -->
                        <div class="wizard-step-content active" data-step="1">
                            <div class="compliance-fieldset">
                                <h3 class="compliance-fieldset-legend">Paso 1: Identificación del Incidente</h3>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Título / Referencia <span class="required">*</span></label>
                                        <input type="text" name="fields[title]" required class="compliance-input w-full" placeholder="Ej: Fuga de base de datos clientes - Acceso no autorizado">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha/Hora detección <span class="required">*</span></label>
                                        <input type="datetime-local" name="fields[detectedAt]" required class="compliance-input w-full" value="<?= date('Y-m-d\TH:i') ?>">
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha/Hora ocurrencia</label>
                                        <input type="datetime-local" name="fields[occurredAt]" class="compliance-input w-full">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Tipo de brecha <span class="required">*</span></label>
                                        <select name="fields[breachType]" required class="compliance-select w-full">
                                            <option value="">Seleccionar tipo</option>
                                            <optgroup label="Confidencialidad">
                                                <option value="confidencialidad_acceso">Acceso no autorizado a datos</option>
                                                <option value="confidencialidad_divulgacion">Divulgación no autorizada</option>
                                                <option value="confidencialidad_fuga">Fuga de información (exfiltración)</option>
                                                <option value="confidencialidad_copia">Copia no autorizada</option>
                                            </optgroup>
                                            <optgroup label="Integridad">
                                                <option value="integridad_modificacion">Modificación no autorizada</option>
                                                <option value="integridad_corrupcion">Corrupción de datos</option>
                                                <option value="integridad_inyeccion">Inyección de datos falsos</option>
                                            </optgroup>
                                            <optgroup label="Disponibilidad">
                                                <option value="disponibilidad_ransomware">Ransomware / Cifrado malicioso</option>
                                                <option value="disponibilidad_borrado">Borrado accidental o malicioso</option>
                                                <option value="disponibilidad_denegacion">Denegación de servicio</option>
                                                <option value="disponibilidad_fallo">Fallo de sistema sin backup</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Severidad <span class="required">*</span></label>
                                        <select name="fields[severity]" required class="compliance-select w-full">
                                            <option value="low">Baja - Sin datos personales afectados</option>
                                            <option value="medium">Media - Datos personales básicos afectados</option>
                                            <option value="high">Alta - Datos sensibles afectados</option>
                                            <option value="critical">Crítica - Datos sensibles + niños / escala masiva</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Origen de la brecha</label>
                                        <select name="fields[source]" class="compliance-select w-full">
                                            <option value="">Desconocido</option>
                                            <option value="externo_ataque">Ataque externo (hacking, phishing, malware)</option>
                                            <option value="interno_malicioso">Interno malicioso (insider threat)</option>
                                            <option value="interno_error">Error humano interno</option>
                                            <option value="falla_sistema">Falla de sistema/software</option>
                                            <option value="terceros">Proveedor/tercero (encargado tratamiento)</option>
                                            <option value="fisico">Pérdida/robo dispositivo físico</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 2: Datos Afectados -->
                        <div class="wizard-step-content" data-step="2">
                            <div class="compliance-fieldset">
                                <h3 class="compliance-fieldset-legend">Paso 2: Datos Afectados (Art. 26.2)</h3>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Categorías de datos afectados <span class="required">*</span></label>
                                        <select name="fields[affectedCategories][]" multiple class="compliance-select w-full" size="5">
                                            <option value="identificacion">Identificación (nombre, RUT, dirección)</option>
                                            <option value="contacto">Contacto (email, teléfono)</option>
                                            <option value="financieros">Financieros (cuentas, tarjetas)</option>
                                            <option value="salud">Salud (historial, diagnósticos)</option>
                                            <option value="biometricos">Biométricos</option>
                                            <option value="geneticos">Genéticos</option>
                                            <option value="ninos">Datos de niños (Art. 17)</option>
                                            <option value="credenciales">Credenciales de acceso (passwords, tokens)</option>
                                            <option value="ubicacion">Ubicación geográfica</option>
                                            <option value="navegacion">Datos de navegación</option>
                                        </select>
                                        <span class="compliance-hint">Ctrl+Click para múltiples selecciones</span>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Nº aproximado de titulares afectados</label>
                                        <input type="number" name="fields[affectedCount]" class="compliance-input w-full" min="0" placeholder="Ej: 15000">
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Incluye datos sensibles?</label>
                                        <select name="fields[sensitiveInvolved]" class="compliance-select w-full">
                                            <option value="no">No</option>
                                            <option value="si">Sí - Notificación obligatoria a titulares</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Categorías de titulares afectados</label>
                                        <select name="fields[affectedSubjectCategories][]" multiple class="compliance-select w-full" size="4">
                                            <option value="clientes">Clientes</option>
                                            <option value="empleados">Empleados</option>
                                            <option value="proveedores">Proveedores</option>
                                            <option value="ninos">Niños/Adolescentes</option>
                                            <option value="pacientes">Pacientes</option>
                                            <option value="publico">Público general</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Sistemas/BD afectados</label>
                                        <input type="text" name="fields[affectedSystems]" class="compliance-input w-full" placeholder="Ej: CRM clientes, BD nóminas, servidor archivos">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 3: Evaluación de Riesgo -->
                        <div class="wizard-step-content" data-step="3">
                            <div class="compliance-fieldset">
                                <h3 class="compliance-fieldset-legend">Paso 3: Evaluación de Riesgo (Art. 26.3)</h3>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Consecuencias probables <span class="required">*</span></label>
                                        <textarea name="fields[likelyConsequences]" required rows="4" class="compliance-textarea" placeholder="Describe las consecuencias probables para los titulares: robo de identidad, fraude financiero, daño reputacional, discriminación, etc."></textarea>
                                        <span class="compliance-hint">Art. 26.3: descripción de las consecuencias probables.</span>
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Medidas adoptadas / propuestas <span class="required">*</span></label>
                                        <textarea name="fields[measuresTaken]" required rows="4" class="compliance-textarea" placeholder="Describe medidas técnicas y organizativas adoptadas: contención, investigación, notificación, corrección, prevención futura..."></textarea>
                                        <span class="compliance-hint">Art. 26.3: medidas adoptadas o propuestas para mitigar efectos.</span>
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Nivel de riesgo para titulares</label>
                                        <select name="fields[riskLevel]" class="compliance-select w-full">
                                            <option value="bajo">Bajo - Impacto mínimo</option>
                                            <option value="moderado">Moderado - Posible daño limitado</option>
                                            <option value="alto">Alto - Probable daño significativo</option>
                                            <option value="muy_alto">Muy alto - Daño severo/irreversible</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 4: Notificaciones -->
                        <div class="wizard-step-content" data-step="4">
                            <div class="compliance-fieldset">
                                <h3 class="compliance-fieldset-legend">Paso 4: Notificaciones (Art. 26.1, 26.4)</h3>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Notificación a APDP realizada?</label>
                                        <select name="fields[notifiedAPDP]" class="compliance-select w-full">
                                            <option value="no">No - Pendiente</option>
                                            <option value="si">Sí - Notificada</option>
                                            <option value="en_proceso">En proceso</option>
                                            <option value="no_procede">No procede (riesgo bajo)</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha notificación APDP</label>
                                        <input type="datetime-local" name="fields[apdpNotifiedAt]" class="compliance-input w-full">
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">¿Notificación a titulares?</label>
                                        <select name="fields[notifiedSubjects]" class="compliance-select w-full">
                                            <option value="no">No - Pendiente / No procede</option>
                                            <option value="si">Sí - Notificados individualmente</option>
                                            <option value="publica">Sí - Comunicación pública (web/medios)</option>
                                            <option value="en_proceso">En proceso</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha notificación titulares</label>
                                        <input type="datetime-local" name="fields[subjectsNotifiedAt]" class="compliance-input w-full">
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Canal de notificación</label>
                                        <select name="fields[notificationChannel]" class="compliance-select w-full">
                                            <option value="email">Email directo</option>
                                            <option value="carta">Carta certificada</option>
                                            <option value="web">Publicación en web/app</option>
                                            <option value="medios">Medios de comunicación</option>
                                            <option value="mixto">Múltiples canales</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PASO 5: Resolución -->
                        <div class="wizard-step-content" data-step="5">
                            <div class="compliance-fieldset">
                                <h3 class="compliance-fieldset-legend">Paso 5: Resolución y Evidencia</h3>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Estado</label>
                                        <select name="fields[status]" class="compliance-select w-full">
                                            <option value="open">Abierta - En investigación</option>
                                            <option value="contained">Contenida - Sin más fuga</option>
                                            <option value="investigating">Investigando causa raíz</option>
                                            <option value="resolved">Resuelta - Cerrada</option>
                                            <option value="closed_no_action">Cerrada - Sin acción (falso positivo)</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha resolución</label>
                                        <input type="datetime-local" name="fields[resolvedAt]" class="compliance-input w-full">
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Responsable gestión</label>
                                        <input type="text" name="fields[incidentManager]" class="compliance-input w-full" placeholder="DPD / CISO / Equipo seguridad">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">URL de evidencia</label>
                                        <input type="url" name="fields[evidenceUrl]" class="compliance-input w-full" placeholder="https://intranet.empresa.cl/brecha-2024-001">
                                        <span class="compliance-hint">Logs, informes forenses, notificaciones</span>
                                    </div>
                                </div>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Lecciones aprendidas</label>
                                        <textarea name="fields[lessonsLearned]" rows="3" class="compliance-textarea" placeholder="Qué se aprendió y qué se mejorará para evitar recurrencia..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Wizard Navigation -->
                        <div class="wizard-navigation">
                            <button type="button" class="wizard-nav-btn wizard-nav-btn-prev" id="prevBtn" disabled>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                Anterior
                            </button>
                            <button type="button" class="wizard-nav-btn wizard-nav-btn-next" id="nextBtn">
                                Siguiente
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                            <button type="submit" class="wizard-nav-btn wizard-nav-btn-submit" id="submitBtn" style="display: none;" name="create_item" value="1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Reportar Brecha
                            </button>
                            <button type="button" class="wizard-nav-btn wizard-nav-btn-prev" onclick="window.location.href='/compliance?tab=breaches'">
                                Cancelar
                            </button>
                        </div>

                        <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                        <input type="hidden" name="fields[reportedBy]" value="<?= h($user['email'] ?? '') ?>">
                    </form>
                    
                    <div class="text-[10px] text-text-subtle mt-4 text-center">
                        <strong>Obligatorio:</strong> Notificar a APDP dentro de las 72 horas. Si hay datos sensibles o de niños, también notificar a los titulares.
                    </div>
                </div>
            </div>

            <script>
            (function() {
                const totalSteps = 5;
                let currentStep = 1;
                
                const form = document.getElementById('breachWizardForm');
                const prevBtn = document.getElementById('prevBtn');
                const nextBtn = document.getElementById('nextBtn');
                const submitBtn = document.getElementById('submitBtn');
                const progressFill = document.getElementById('progressFill');
                const currentStepNum = document.getElementById('currentStepNum');
                const stepError = document.getElementById('stepError');
                const stepTitles = document.querySelectorAll('.wizard-step-title');
                
                function updateWizard() {
                    // Update step content visibility
                    document.querySelectorAll('.wizard-step-content').forEach(el => {
                        el.classList.remove('active');
                        if (parseInt(el.dataset.step) === currentStep) {
                            el.classList.add('active');
                        }
                    });
                    
                    // Update progress bar
                    const progress = (currentStep / totalSteps) * 100;
                    progressFill.style.width = progress + '%';
                    currentStepNum.textContent = currentStep;
                    
                    // Update step titles
                    stepTitles.forEach(title => {
                        const step = parseInt(title.dataset.step);
                        title.classList.remove('active', 'completed');
                        if (step === currentStep) {
                            title.classList.add('active');
                        } else if (step < currentStep) {
                            title.classList.add('completed');
                        }
                    });
                    
                    // Update navigation buttons
                    prevBtn.disabled = currentStep === 1;
                    
                    if (currentStep === totalSteps) {
                        nextBtn.style.display = 'none';
                        submitBtn.style.display = 'inline-flex';
                    } else {
                        nextBtn.style.display = 'inline-flex';
                        submitBtn.style.display = 'none';
                    }
                    
                    // Hide error message
                    stepError.classList.remove('show');
                }
                
                function validateStep(step) {
                    const stepContent = document.querySelector(`.wizard-step-content[data-step="${step}"]`);
                    const requiredFields = stepContent.querySelectorAll('[required]');
                    let isValid = true;
                    
                    requiredFields.forEach(field => {
                        if (field.type === 'select-multiple') {
                            if (field.selectedOptions.length === 0) {
                                isValid = false;
                            }
                        } else if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = 'rgba(239, 68, 68, 0.5)';
                        } else {
                            field.style.borderColor = '';
                        }
                    });
                    
                    return isValid;
                }
                
                function clearValidationStyles(step) {
                    const stepContent = document.querySelector(`.wizard-step-content[data-step="${step}"]`);
                    stepContent.querySelectorAll('[required]').forEach(field => {
                        field.style.borderColor = '';
                    });
                }
                
                nextBtn.addEventListener('click', function() {
                    if (validateStep(currentStep)) {
                        clearValidationStyles(currentStep);
                        currentStep++;
                        updateWizard();
                    } else {
                        stepError.classList.add('show');
                    }
                });
                
                prevBtn.addEventListener('click', function() {
                    if (currentStep > 1) {
                        currentStep--;
                        updateWizard();
                    }
                });
                
                // Initialize
                updateWizard();
            })();
            </script>
            <?php if (empty($items)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin brechas registradas. Reporta una o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($items as $it):
                    $resolved = ($it['status'] ?? '') === 'resolved';
                    $sev = $it['severity'] ?? 'medium';
                    $sb = $sevBadge[$sev] ?? $sevBadge['medium'];
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $resolved ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($it['title'] ?? 'Brecha') ?></p>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $sb ?>"><?= h($sevLabel[$sev] ?? $sev) ?></span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $resolved ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20' ?>"><?= $resolved ? 'Resuelta' : 'Abierta' ?></span>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5"><?= h($it['description'] ?? '') ?> · <?= h(substr($it['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (!$resolved) renderActionBtn('breaches', $it['_id'] ?? '', 'resolve', 'Resolver'); ?>
                        <?php if (empty($it['notifiedAPDP'])): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="breaches">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <input type="hidden" name="item_action" value="notify_apdp">
                            <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/20 transition-all" title="Notificar a APDP (Art. 26.1)">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                APDP
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (empty($it['notifiedSubjects']) && !empty($it['fields']['sensitiveInvolved']) && $it['fields']['sensitiveInvolved'] === 'si'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="breaches">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <input type="hidden" name="item_action" value="notify_subjects">
                            <button type="submit" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/20 transition-all" title="Notificar a titulares (Art. 26.4)">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Titulares
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="breaches">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar esta brecha?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'dpia'): ?>
            <?php
            $dApproved = count(array_filter($items, fn($it) => ($it['status'] ?? '') === 'approved'));
            $dPending = count($items) - $dApproved;
            $dHighRisk = count(array_filter($items, fn($it) => in_array($it['riskLevel'] ?? '', ['high', 'critical']) && ($it['status'] ?? '') !== 'approved'));
            $riskBadge = ['high' => 'bg-orange-500/15 text-orange-400 border-orange-500/30', 'medium' => 'bg-yellow-500/15 text-yellow-400 border-yellow-500/30', 'low' => 'bg-green-500/15 text-green-400 border-green-500/30', 'critical' => 'bg-red-500/15 text-red-400 border-red-500/30'];
            $riskLabel = ['high' => 'Alto', 'medium' => 'Medio', 'low' => 'Bajo', 'critical' => 'Crítico'];
            ?>
            <?php renderSectionHeader('Evaluación de Impacto — DPIA', 'Evaluación de riesgos para tratamientos de alto riesgo — Art. 14 quater / Art. 16'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total DPIA', count($items), 'text-white', cIcon('shield')); ?>
                <?php renderComplianceStat('Aprobadas', $dApproved, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $dPending, $dPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
                <?php renderComplianceStat('Alto riesgo', $dHighRisk, $dHighRisk ? 'text-red-400' : 'text-text-subtle', cIcon('alert')); ?>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white">Nueva evaluación de impacto (DPIA)</p>
                    <?php renderImportBtn('dpia'); ?>
                </div>
                
                <!-- DPIA Wizard Form -->
                <form method="POST" id="dpia-wizard-form">
                    <input type="hidden" name="collection" value="dpia">
                    
                    <!-- Wizard Progress Indicator -->
                    <div class="dpia-wizard-progress mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <span class="dpia-wizard-step-text text-[11px] font-semibold text-text-subtle">Paso 1 de 2</span>
                            <span class="dpia-wizard-percentage text-[11px] font-semibold text-accent">50%</span>
                        </div>
                        <div class="dpia-wizard-progress-bar bg-bg-elevated/50 rounded-full h-2 overflow-hidden">
                            <div class="dpia-wizard-progress-fill h-full rounded-full transition-all duration-500" style="width: 50%"></div>
                        </div>
                        <div class="flex items-center justify-between mt-3">
                            <div class="dpia-wizard-step-indicator dpia-step-1 flex items-center gap-2">
                                <div class="dpia-step-number w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold bg-accent text-white">1</div>
                                <span class="dpia-step-label text-[11px] font-medium text-text-heading">Información del Proyecto</span>
                            </div>
                            <div class="dpia-wizard-step-indicator dpia-step-2 flex items-center gap-2">
                                <div class="dpia-step-number w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold bg-bg-elevated text-text-subtle border border-border-color">2</div>
                                <span class="dpia-step-label text-[11px] font-medium text-text-subtle">Evaluación de Riesgo</span>
                            </div>
                        </div>
                    </div>

                    <!-- Step 1: Project Information -->
                    <div class="dpia-wizard-step dpia-step-1-content" data-step="1">
                        <div class="compliance-form-row">
                            <div class="compliance-form-cell">
                                <label for="dpia-name" class="compliance-form-label">Nombre del proyecto <span class="required">*</span></label>
                                <input type="text" id="dpia-name" name="fields[name]" required placeholder="Ej: Sistema de gestión de clientes" class="compliance-input">
                            </div>
                            <div class="compliance-form-cell">
                                <label for="dpia-description" class="compliance-form-label">Descripción del tratamiento</label>
                                <input type="text" id="dpia-description" name="fields[description]" placeholder="Descripción breve del tratamiento de datos" class="compliance-input">
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Risk Assessment -->
                    <div class="dpia-wizard-step dpia-step-2-content hidden" data-step="2">
                        <div class="compliance-form-row">
                            <div class="compliance-form-cell">
                                <label for="dpia-riskLevel" class="compliance-form-label">Nivel de riesgo <span class="required">*</span></label>
                                <select id="dpia-riskLevel" name="fields[riskLevel]" required class="compliance-select">
                                    <option value="">Seleccionar nivel de riesgo</option>
                                    <option value="low">Riesgo bajo</option>
                                    <option value="medium">Riesgo medio</option>
                                    <option value="high">Riesgo alto</option>
                                </select>
                            </div>
                            <div class="compliance-form-cell">
                                <label for="dpia-treatmentDescription" class="compliance-form-label">Descripción del tratamiento</label>
                                <textarea id="dpia-treatmentDescription" name="fields[treatmentDescription]" rows="4" placeholder="Describe detalladamente el tratamiento de datos y medidas de seguridad" class="compliance-input min-h-[100px] resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Wizard Navigation -->
                    <div class="compliance-form-actions">
                        <button type="button" id="dpia-prev-btn" class="compliance-btn-secondary hidden">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Anterior
                        </button>
                        <button type="button" id="dpia-next-btn" class="compliance-btn-primary">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" id="dpia-submit-btn" name="create_item" value="1" class="compliance-btn-primary hidden">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Crear DPIA
                        </button>
                    </div>
                </form>
            </div>
            <?php if (empty($items)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin evaluaciones de impacto todavía. Crea una o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($items as $it):
                    $approved = ($it['status'] ?? '') === 'approved';
                    $rl = $it['riskLevel'] ?? 'medium';
                    $rb = $riskBadge[$rl] ?? $riskBadge['medium'];
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $approved ? 'bg-emerald-500/10 text-emerald-400' : 'bg-blue-500/10 text-blue-400' ?>">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($it['name'] ?? 'DPIA') ?></p>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $rb ?>">Riesgo <?= h($riskLabel[$rl] ?? $rl) ?></span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $approved ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20' ?>"><?= $approved ? 'Aprobada' : 'Pendiente' ?></span>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5"><?= h($it['description'] ?? '') ?> · <?= h(substr($it['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (!$approved) renderActionBtn('dpia', $it['_id'] ?? '', 'approve', 'Aprobar'); ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="dpia">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar esta evaluación?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'processors'): ?>
            <?php
            $procItems = $fetchList('processors');
            if (!is_array($procItems)) $procItems = [];
            $procTotal = count($procItems);
            $procWithContract = count(array_filter($procItems, fn($p) => !empty($p['hasContract'])));
            $procIntl = count(array_filter($procItems, fn($p) => !empty($p['internationalTransfer'])));
            ?>
            <?php renderSectionHeader('Encargados del Tratamiento / Procesadores', 'Registro de terceros que tratan datos por cuenta del responsable — Art. 15 bis Ley 21.719'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total encargados', $procTotal, 'text-white', cIcon('users')); ?>
                <?php renderComplianceStat('Con contrato DPA', $procWithContract, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Transferencias intl.', $procIntl, $procIntl ? 'text-amber-400' : 'text-emerald-400', cIcon('globe')); ?>
            </div>

            <!-- Formulario de Encargado (Art. 15 bis Ley 21.719) - Wizard Step-by-Step -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Nuevo encargado de tratamiento (Art. 15 bis - Libre, expreso, informado, específico)
                    </p>
                    <?php renderImportBtn('processors'); ?>
                </div>

                <form method="POST" id="processorWizardForm" class="wizard-container">
                    <input type="hidden" name="collection" value="processors">

                    <!-- Error Message -->
                    <div class="wizard-error-message" id="wizard-error-message">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="wizard-error-message-text" id="wizard-error-text">Por favor, complete todos los campos requeridos antes de continuar.</span>
                    </div>

                    <!-- Wizard Progress -->
                    <div class="wizard-progress">
                        <div class="wizard-progress-header">
                            <span class="wizard-progress-title">Progreso del formulario</span>
                            <span class="wizard-progress-steps" id="wizard-step-text">Paso 1 de 5</span>
                        </div>
                        <div class="wizard-progress-bar">
                            <div class="wizard-progress-fill" id="wizard-progress-fill" style="width: 20%"></div>
                        </div>
                    </div>

                    <!-- Wizard Steps Indicator -->
                    <div class="wizard-steps-indicator">
                        <div class="wizard-step-dot active" data-step="1">
                            <span class="wizard-step-number">1</span>
                            <span class="wizard-step-label">Información</span>
                        </div>
                        <div class="wizard-step-dot" data-step="2">
                            <span class="wizard-step-number">2</span>
                            <span class="wizard-step-label">Tratamiento</span>
                        </div>
                        <div class="wizard-step-dot" data-step="3">
                            <span class="wizard-step-number">3</span>
                            <span class="wizard-step-label">Contrato</span>
                        </div>
                        <div class="wizard-step-dot" data-step="4">
                            <span class="wizard-step-number">4</span>
                            <span class="wizard-step-label">Seguridad</span>
                        </div>
                        <div class="wizard-step-dot" data-step="5">
                            <span class="wizard-step-number">5</span>
                            <span class="wizard-step-label">Relación</span>
                        </div>
                    </div>

                    <!-- Paso 1: Información del Encargado -->
                    <div class="wizard-step-content active" data-step="1">
                        <h3 class="wizard-step-title">Paso 1: Información del Encargado</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Datos básicos del encargado de tratamiento</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Nombre del encargado <span class="required">*</span></label>
                                    <input type="text" name="fields[name]" required class="compliance-input" placeholder="Ej: AWS, Google Cloud, Proveedor SaaS">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Tipo de servicio</label>
                                    <select name="fields[serviceType]" class="compliance-select">
                                        <option value="">Seleccionar</option>
                                        <option value="cloud">Cloud / Hosting</option>
                                        <option value="saas">SaaS / Software</option>
                                        <option value="contabilidad">Contabilidad / Fiscal</option>
                                        <option value="marketing">Marketing / Email</option>
                                        <option value="seguridad">Seguridad / Monitoreo</option>
                                        <option value="soporte">Soporte técnico</option>
                                        <option value="recursos_humanos">RRHH / Nómina</option>
                                        <option value="legal">Asesoría legal</option>
                                        <option value="otro">Otro</option>
                                    </select>
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">País de establecimiento</label>
                                    <input type="text" name="fields[country]" class="compliance-input" placeholder="Ej: Chile, EE.UE., UE">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Transferencia internacional? (Art. 21/27)</label>
                                    <select name="fields[internationalTransfer]" class="compliance-select">
                                        <option value="no">No</option>
                                        <option value="si_adecuado">Sí - País con nivel adecuado (Decisión APDP)</option>
                                        <option value="si_clausulas">Sí - Cláusulas contractuales tipo</option>
                                        <option value="si_bcr">Sí - Normas corporativas vinculantes (BCR)</option>
                                        <option value="si_excepcion">Sí - Excepción Art. 27 (consentimiento, contrato, etc.)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 2: Tratamiento de Datos -->
                    <div class="wizard-step-content" data-step="2">
                        <h3 class="wizard-step-title">Paso 2: Tratamiento de Datos</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Detalles sobre el tratamiento de datos</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Finalidad del tratamiento</label>
                                    <textarea name="fields[purpose]" rows="3" class="compliance-textarea" placeholder="Describe qué datos trata el encargado y para qué finalidad..."></textarea>
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de datos tratados</label>
                                    <input type="text" name="fields[dataCategories]" class="compliance-input" placeholder="Ej: Datos de contacto, datos financieros">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de titulares</label>
                                    <input type="text" name="fields[subjectCategories]" class="compliance-input" placeholder="Ej: Clientes, empleados, proveedores">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 3: Contrato y Legal -->
                    <div class="wizard-step-content" data-step="3">
                        <h3 class="wizard-step-title">Paso 3: Contrato y Legal</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Contrato DPA y evidencia legal</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Contrato DPA firmado? (Art. 15 bis)</label>
                                    <select name="fields[hasContract]" class="compliance-select">
                                        <option value="no">No</option>
                                        <option value="si">Sí - Incluye cláusulas Art. 15 bis</option>
                                        <option value="en_proceso">En proceso</option>
                                    </select>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha contrato DPA</label>
                                    <input type="date" name="fields[contractDate]" class="compliance-input">
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">URL del contrato / evidencia</label>
                                    <input type="url" name="fields[contractUrl]" class="compliance-input" placeholder="https://intranet.empresa.cl/dpa-aws.pdf">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 4: Seguridad -->
                    <div class="wizard-step-content" data-step="4">
                        <h3 class="wizard-step-title">Paso 4: Seguridad</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Medidas de seguridad y auditoría</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Medidas de seguridad del encargado</label>
                                    <textarea name="fields[securityMeasures]" rows="3" class="compliance-textarea" placeholder="Certificaciones (ISO 27001, SOC 2), cifrado, controles de acceso..."></textarea>
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Derecho de auditoría / inspección</label>
                                    <select name="fields[auditRights]" class="compliance-select">
                                        <option value="si">Sí - Incluido en contrato</option>
                                        <option value="no">No</option>
                                        <option value="parcial">Parcial / Solo certificación</option>
                                    </select>
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Sub-encargados autorizados</label>
                                    <textarea name="fields[subProcessors]" rows="3" class="compliance-textarea" placeholder="Lista de sub-encargados autorizados por escrito..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 5: Relación -->
                    <div class="wizard-step-content" data-step="5">
                        <h3 class="wizard-step-title">Paso 5: Relación</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Detalles de la relación comercial</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha fin de relación</label>
                                    <input type="date" name="fields[endDate]" class="compliance-input">
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Observaciones</label>
                                    <textarea name="fields[observations]" rows="3" class="compliance-textarea" placeholder="Notas adicionales sobre el encargado..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 p-3 rounded-lg bg-amber-500/10 border border-amber-500/20">
                            <p class="text-[10px] text-amber-300">
                                <strong>Nota:</strong> Obligatorio contrato con cláusulas Art. 15 bis. Si transferencia intl. → cláusulas tipo / BCR / decisión adecuación.
                            </p>
                        </div>
                    </div>

                    <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button type="button" class="wizard-btn-prev" id="wizard-prev-btn" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Anterior
                        </button>
                        <button type="button" class="wizard-btn-next" id="wizard-next-btn">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" class="wizard-btn-submit" id="wizard-submit-btn" name="create_item" value="1" style="display: none;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar encargado (Art. 15 bis - Libre, expreso, informado, específico)
                        </button>
                    </div>
                </form>
            </div>

            <script>
            (function() {
                const form = document.getElementById('processorWizardForm');
                if (!form) return;

                const totalSteps = 5;
                let currentStep = 1;

                const btnPrev = document.getElementById('wizard-prev-btn');
                const btnNext = document.getElementById('wizard-next-btn');
                const btnSubmit = document.getElementById('wizard-submit-btn');
                const progressText = document.getElementById('wizard-step-text');
                const progressFill = document.getElementById('wizard-progress-fill');
                const stepDots = form.querySelectorAll('.wizard-step-dot');
                const stepContents = form.querySelectorAll('.wizard-step-content');

                function updateWizard() {
                    // Update progress text and bar
                    progressText.textContent = `Paso ${currentStep} de ${totalSteps}`;
                    const progress = (currentStep / totalSteps) * 100;
                    progressFill.style.width = `${progress}%`;

                    // Update step dots
                    stepDots.forEach((dot, index) => {
                        const stepNum = index + 1;
                        dot.classList.remove('active', 'completed');
                        if (stepNum === currentStep) {
                            dot.classList.add('active');
                        } else if (stepNum < currentStep) {
                            dot.classList.add('completed');
                        }
                    });

                    // Update step content visibility
                    stepContents.forEach((content, index) => {
                        const stepNum = index + 1;
                        content.classList.remove('active', 'hidden');
                        if (stepNum === currentStep) {
                            content.classList.add('active');
                        } else {
                            content.classList.add('hidden');
                        }
                    });

                    // Update buttons
                    btnPrev.disabled = currentStep === 1;
                    if (currentStep === totalSteps) {
                        btnNext.style.display = 'none';
                        btnSubmit.style.display = 'inline-flex';
                    } else {
                        btnNext.style.display = 'inline-flex';
                        btnSubmit.style.display = 'none';
                    }
                }

                function validateCurrentStep() {
                    const currentContent = form.querySelector(`.wizard-step-content[data-step="${currentStep}"]`);
                    const requiredFields = currentContent.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.style.borderColor = '#ef4444';
                            isValid = false;
                        } else {
                            field.style.borderColor = '';
                        }
                    });

                    return isValid;
                }

                function goToStep(step) {
                    if (step < 1 || step > totalSteps) return;
                    currentStep = step;
                    updateWizard();
                }

                btnNext.addEventListener('click', function() {
                    if (!validateCurrentStep()) {
                        alert('Por favor, complete los campos obligatorios antes de continuar.');
                        return;
                    }
                    goToStep(currentStep + 1);
                });

                btnPrev.addEventListener('click', function() {
                    goToStep(currentStep - 1);
                });

                // Remove validation styling on input
                form.querySelectorAll('input, select, textarea').forEach(field => {
                    field.addEventListener('input', function() {
                        this.style.borderColor = '';
                    });
                });

                // Initialize
                updateWizard();
            })();
            </script>
            <?php if (empty($procItems)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin encargados registrados. Añade uno o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($procItems as $p): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 bg-cyan-500/10 text-cyan-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($p['name'] ?? 'Encargado') ?></p>
                            <?php if (!empty($p['internationalTransfer']) && $p['internationalTransfer'] !== 'no'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-semibold rounded-md border bg-amber-500/10 text-amber-400 border-amber-500/20">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c0 1.657-1.343 3-3 3"/></svg>
                                Intl.
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($p['hasContract']) && $p['hasContract'] === 'si'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-semibold rounded-md border bg-emerald-500/10 text-emerald-400 border-emerald-500/20">DPA ✓</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5"><?= h($p['serviceType'] ?? '') ?> · <?= h($p['purpose'] ?? '') ?> · <?= h(substr($p['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="processors">
                            <input type="hidden" name="item_id" value="<?= h($p['_id'] ?? '') ?>">
                            <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar este encargado?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'transfers'): ?>
            <?php
            $transItems = $fetchList('transfers');
            if (!is_array($transItems)) $transItems = [];
            $transTotal = count($transItems);
            $transAdequate = count(array_filter($transItems, fn($t) => in_array($t['mechanism'] ?? '', ['adequacy', 'adequate'])));
            $transSCCs = count(array_filter($transItems, fn($t) => in_array($t['mechanism'] ?? '', ['scc', 'clauses'])));
            $transBCR = count(array_filter($transItems, fn($t) => in_array($t['mechanism'] ?? '', ['bcr', 'binding_corporate_rules'])));
            ?>
            <?php renderSectionHeader('Transferencias Internacionales de Datos', 'Registro de transferencias a terceros países — Art. 21 y 27 Ley 21.719'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total transferencias', $transTotal, 'text-white', cIcon('globe')); ?>
                <?php renderComplianceStat('Decisión adecuación', $transAdequate, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Cláusulas tipo (SCC)', $transSCCs, 'text-blue-400', cIcon('fileText')); ?>
                <?php renderComplianceStat('BCR / Normas vinculantes', $transBCR, 'text-indigo-400', cIcon('shield')); ?>
            </div>

            <!-- Formulario de Transferencia Internacional (Art. 21/27 Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white">
                        Nueva transferencia internacional
                    </p>
                    <?php renderImportBtn('transfers'); ?>
                </div>
                <form method="POST" id="transferWizardForm">
                    <input type="hidden" name="collection" value="transfers">

                    <div class="transfer-wizard-container">
                        <!-- Progress Indicator -->
                        <div class="transfer-wizard-progress">
                            <div class="transfer-wizard-progress-header">
                                <span class="transfer-wizard-progress-title">Progreso del formulario</span>
                                <span class="transfer-wizard-progress-step" id="transferWizardStepText">Paso 1 de 4</span>
                            </div>
                            <div class="transfer-wizard-progress-bar">
                                <div class="transfer-wizard-progress-fill" id="transferWizardProgressFill" style="width: 25%"></div>
                            </div>
                            <div class="transfer-wizard-steps-indicator">
                                <div class="transfer-wizard-step-dot active" data-step="1">
                                    <span class="transfer-wizard-step-number">1</span>
                                    <span class="transfer-wizard-step-label">Destino</span>
                                </div>
                                <div class="transfer-wizard-step-dot" data-step="2">
                                    <span class="transfer-wizard-step-number">2</span>
                                    <span class="transfer-wizard-step-label">Mecanismo</span>
                                </div>
                                <div class="transfer-wizard-step-dot" data-step="3">
                                    <span class="transfer-wizard-step-number">3</span>
                                    <span class="transfer-wizard-step-label">Datos</span>
                                </div>
                                <div class="transfer-wizard-step-dot" data-step="4">
                                    <span class="transfer-wizard-step-number">4</span>
                                    <span class="transfer-wizard-step-label">Evidencia</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 1: Destino -->
                        <div class="transfer-wizard-step active" data-step="1">
                            <h3 class="transfer-wizard-step-title">Paso 1: Destino</h3>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">País destino <span class="required">*</span></label>
                                    <input type="text" name="fields[destinationCountry]" required class="compliance-input" placeholder="Ej: Estados Unidos, Colombia, España">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Destinatario / Encargado</label>
                                    <input type="text" name="fields[recipient]" class="compliance-input" placeholder="Ej: AWS (EE.UE.), Proveedor SaaS (Colombia)">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha inicio transferencia</label>
                                    <input type="date" name="fields[startDate]" class="compliance-input">
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Mecanismo de Transferencia -->
                        <div class="transfer-wizard-step" data-step="2">
                            <h3 class="transfer-wizard-step-title">Paso 2: Mecanismo de Transferencia</h3>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Mecanismo de transferencia <span class="required">*</span> (Art. 21/27)</label>
                                    <select name="fields[mechanism]" required class="compliance-select">
                                        <option value="">Seleccionar mecanismo</option>
                                        <optgroup label="Decisión de adecuación (Art. 21.1)">
                                            <option value="adequacy">Decisión de adecuación APDP</option>
                                        </optgroup>
                                        <optgroup label="Garantías adecuadas (Art. 21.2)">
                                            <option value="scc">Cláusulas contractuales tipo (SCC)</option>
                                            <option value="bcr">Normas corporativas vinculantes (BCR)</option>
                                            <option value="codes">Códigos de conducta + compromiso</option>
                                            <option value="certification">Mecanismos de certificación</option>
                                        </optgroup>
                                        <optgroup label="Excepciones (Art. 27)">
                                            <option value="consent">Consentimiento explícito informado</option>
                                            <option value="contract">Ejecución de contrato</option>
                                            <option value="public_interest">Interés público importante</option>
                                            <option value="legal_claim">Reclamaciones legales</option>
                                            <option value="vital_interest">Interés vital del titular</option>
                                        </optgroup>
                                    </select>
                                    <span class="compliance-hint">Art. 21: transferencia solo si país adecuado, garantías o excepción.</span>
                                </div>
                                <div class="compliance-form-cell" style="grid-column: 1 / -1;">
                                    <label class="compliance-form-label">Descripción de la garantía / cláusulas</label>
                                    <textarea name="fields[guaranteeDescription]" rows="3" class="compliance-textarea" placeholder="Detalla las cláusulas contractuales, BCR, código de conducta o mecanismo de certificación utilizado..."></textarea>
                                    <span class="compliance-hint">Art. 21.2: documento que acredite garantías adecuadas.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Datos Transferidos -->
                        <div class="transfer-wizard-step" data-step="3">
                            <h3 class="transfer-wizard-step-title">Paso 3: Datos Transferidos</h3>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de datos transferidos</label>
                                    <input type="text" name="fields[dataCategories]" class="compliance-input" placeholder="Ej: Datos de contacto, datos financieros, datos de empleados">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Categorías de titulares</label>
                                    <input type="text" name="fields[subjectCategories]" class="compliance-input" placeholder="Ej: Clientes, empleados, proveedores">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Incluye datos sensibles? (Art. 16)</label>
                                    <select name="fields[sensitiveData]" class="compliance-select">
                                        <option value="no">No</option>
                                        <option value="si">Sí - Requiere garantías reforzadas</option>
                                    </select>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Incluye datos de niños? (Art. 17)</label>
                                    <select name="fields[childrenData]" class="compliance-select">
                                        <option value="no">No</option>
                                        <option value="si">Sí - Protección reforzada</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Evidencia -->
                        <div class="transfer-wizard-step" data-step="4">
                            <h3 class="transfer-wizard-step-title">Paso 4: Evidencia</h3>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">URL evidencia (SCC firmadas, BCR, decisión APDP)</label>
                                    <input type="url" name="fields[evidenceUrl]" class="compliance-input" placeholder="https://intranet.empresa.cl/scc-aws.pdf">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Responsable de la transferencia</label>
                                    <input type="text" name="fields[transferManager]" class="compliance-input" placeholder="DPD / CISO / Legal">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha revisión / vencimiento</label>
                                    <input type="date" name="fields[reviewDate]" class="compliance-input">
                                </div>
                            </div>
                        </div>

                        <!-- Navigation -->
                        <div class="transfer-wizard-navigation">
                            <button type="button" class="transfer-wizard-btn-prev" id="transferWizardPrevBtn" disabled>
                                ← Anterior
                            </button>
                            <button type="button" class="transfer-wizard-btn-next" id="transferWizardNextBtn">
                                Siguiente →
                            </button>
                            <button type="submit" class="transfer-wizard-btn-submit" id="transferWizardSubmitBtn" name="create_item" value="1" style="display: none;">
                                Registrar transferencia (Art. 21/27)
                            </button>
                        </div>
                    </div>
                    <span class="text-[10px] text-text-muted">Si no hay decisión de adecuación → SCC o BCR obligatorias. Excepciones Art. 27 limitadas.</span>
                </form>
            </div>

            <script>
            (function() {
                // Transfer Wizard - Independent JavaScript
                const totalSteps = 4;
                let currentStep = 1;

                const stepText = document.getElementById('transferWizardStepText');
                const progressFill = document.getElementById('transferWizardProgressFill');
                const stepDots = document.querySelectorAll('.transfer-wizard-step-dot');
                const steps = document.querySelectorAll('.transfer-wizard-step');
                const prevBtn = document.getElementById('transferWizardPrevBtn');
                const nextBtn = document.getElementById('transferWizardNextBtn');
                const submitBtn = document.getElementById('transferWizardSubmitBtn');

                function updateTransferWizard() {
                    // Update step text
                    stepText.textContent = `Paso ${currentStep} de ${totalSteps}`;

                    // Update progress bar
                    const progress = (currentStep / totalSteps) * 100;
                    progressFill.style.width = `${progress}%`;

                    // Update step dots
                    stepDots.forEach((dot, index) => {
                        dot.classList.remove('active', 'completed');
                        if (index + 1 < currentStep) {
                            dot.classList.add('completed');
                        } else if (index + 1 === currentStep) {
                            dot.classList.add('active');
                        }
                    });

                    // Show current step, hide others
                    steps.forEach((step, index) => {
                        step.classList.remove('active');
                        if (index + 1 === currentStep) {
                            step.classList.add('active');
                        }
                    });

                    // Update buttons
                    prevBtn.disabled = currentStep === 1;

                    if (currentStep === totalSteps) {
                        nextBtn.style.display = 'none';
                        submitBtn.style.display = 'inline-flex';
                    } else {
                        nextBtn.style.display = 'inline-flex';
                        submitBtn.style.display = 'none';
                    }
                }

                function validateTransferStep() {
                    const currentStepEl = document.querySelector(`.transfer-wizard-step[data-step="${currentStep}"]`);
                    const requiredFields = currentStepEl.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                        } else {
                            field.style.borderColor = '';
                        }
                    });

                    return isValid;
                }

                prevBtn.addEventListener('click', () => {
                    if (currentStep > 1) {
                        currentStep--;
                        updateTransferWizard();
                    }
                });

                nextBtn.addEventListener('click', () => {
                    if (validateTransferStep() && currentStep < totalSteps) {
                        currentStep++;
                        updateTransferWizard();
                    }
                });

                // Clear validation on input
                document.querySelectorAll('#transferWizardForm input, #transferWizardForm select, #transferWizardForm textarea').forEach(field => {
                    field.addEventListener('input', () => {
                        field.style.borderColor = '';
                    });
                });
            })();
            </script>
            <?php if (empty($transItems)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin transferencias registradas. Añade una o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($transItems as $t): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 bg-amber-500/10 text-amber-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c0 1.657-1.343 3-3 3"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($t['destinationCountry'] ?? 'Transferencia') ?></p>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-semibold rounded-md border bg-blue-500/10 text-blue-400 border-blue-500/20"><?= h($t['mechanism'] ?? '—') ?></span>
                            <?php if (!empty($t['sensitiveData']) && $t['sensitiveData'] === 'si'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-semibold rounded-md border bg-red-500/10 text-red-400 border-red-500/20">Sensibles</span>
                            <?php endif; ?>
                            <?php if (!empty($t['childrenData']) && $t['childrenData'] === 'si'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-semibold rounded-md border bg-pink-500/10 text-pink-400 border-pink-500/20">Niños</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5"><?= h($t['recipient'] ?? '') ?> · <?= h($t['dataCategories'] ?? '') ?> · <?= h(substr($t['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="transfers">
                            <input type="hidden" name="item_id" value="<?= h($t['_id'] ?? '') ?>">
                            <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar esta transferencia?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'pseudonymization'): ?>
            <?php
            $pExecuted = count(array_filter($items, fn($it) => ($it['status'] ?? '') === 'executed'));
            $pPending = count($items) - $pExecuted;
            $pRules = count($items);
            ?>
            <?php renderSectionHeader('Seudonimización', 'Reemplazo de identificadores directos por seudónimos — Art. 30 de la Ley 21.719', 'pseudonymization'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total reglas', $pRules, 'text-white', cIcon('search')); ?>
                <?php renderComplianceStat('Ejecutadas', $pExecuted, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $pPending, $pPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
                <?php renderComplianceStat('Avance', $pRules ? round($pExecuted / $pRules * 100) . '%' : '—', 'text-indigo-400', cIcon('shield')); ?>
            </div>

            <!-- Formulario Wizard de Seudonimización (Art. 30 Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Nueva regla de seudonimización (Art. 30 - Libre, expreso, informado, específico)
                    </p>
                    <?php renderImportBtn('pseudonymization'); ?>
                </div>

                <form method="POST" id="pseudoWizardForm" class="wizard-container">
                    <input type="hidden" name="collection" value="pseudonymization">
                    <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                    <input type="hidden" name="fields[createdBy]" value="<?= h($user['email'] ?? '') ?>">

                    <!-- Error Message -->
                    <div class="wizard-error-message" id="pseudoWizard-error-message">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="wizard-error-message-text" id="pseudoWizard-error-text">Por favor, complete todos los campos requeridos antes de continuar.</span>
                    </div>

                    <!-- Wizard Progress -->
                    <div class="wizard-progress">
                        <div class="wizard-progress-header">
                            <span class="wizard-progress-title">Progreso del formulario</span>
                            <span class="wizard-progress-steps" id="wizard-step-text">Paso 1 de 5</span>
                        </div>
                        <div class="wizard-progress-bar">
                            <div class="wizard-progress-fill" id="wizard-progress-fill" style="width: 20%"></div>
                        </div>
                    </div>

                    <!-- Wizard Steps Indicator -->
                    <div class="wizard-steps-indicator">
                        <div class="wizard-step-dot active" data-step="1">
                            <span class="wizard-step-number">1</span>
                            <span class="wizard-step-label">Identificación</span>
                        </div>
                        <div class="wizard-step-dot" data-step="2">
                            <span class="wizard-step-number">2</span>
                            <span class="wizard-step-label">Configuración</span>
                        </div>
                        <div class="wizard-step-dot" data-step="3">
                            <span class="wizard-step-number">3</span>
                            <span class="wizard-step-label">Aplicación</span>
                        </div>
                        <div class="wizard-step-dot" data-step="4">
                            <span class="wizard-step-number">4</span>
                            <span class="wizard-step-label">Verificación</span>
                        </div>
                        <div class="wizard-step-dot" data-step="5">
                            <span class="wizard-step-number">5</span>
                            <span class="wizard-step-label">Evidencia</span>
                        </div>
                    </div>

                        <!-- Paso 1: Identificación de la Regla -->
                    <div class="wizard-step-content active" data-step="1">
                        <h3 class="wizard-step-title">Paso 1: Identificación de la Regla (Art. 30)</h3>
                        <div class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Identificación de la Regla (Art. 30)</p>
                                <div class="compliance-form-row">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Nombre de la regla <span class="required">*</span></label>
                                        <input type="text" name="fields[name]" required class="compliance-input" placeholder="Ej: Seudonimización RUT clientes">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Código / Referencia</label>
                                        <input type="text" name="fields[code]" class="compliance-input" placeholder="Ej: PSEUDO-001">
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Técnica de seudonimización <span class="required">*</span></label>
                                        <select name="fields[technique]" required class="compliance-select">
                                            <option value="">Seleccionar técnica</option>
                                            <option value="tokenizacion">Tokenización (token aleatorio reversible)</option>
                                            <option value="hashing">Hashing unidireccional (SHA-256, SHA-3)</option>
                                            <option value="cifrado_reversible">Cifrado reversible (AES-256 con key management)</option>
                                            <option value="masking">Enmascaramiento / Masking (parcial)</option>
                                            <option value="format_preserving">Cifrado conservador de formato (FPE)</option>
                                            <option value="differential_privacy">Privacidad diferencial (ruido estadístico)</option>
                                            <option value="otro">Otra (especificar en observaciones)</option>
                                        </select>
                                        <span class="compliance-hint">Art. 30: reemplazo de identificadores directos por seudónimos.</span>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Alcance <span class="required">*</span></label>
                                        <select name="fields[scope]" required class="compliance-select">
                                            <option value="">Seleccionar alcance</option>
                                            <option value="todos_identificadores">Todos los identificadores directos (RUT, email, nombre)</option>
                                            <option value="solo_rut">Solo RUT / DNI / RUN</option>
                                            <option value="solo_email">Solo emails</option>
                                            <option value="solo_nombres">Solo nombres y apellidos</option>
                                            <option value="datos_sensibles">Solo datos sensibles (salud, biometría)</option>
                                            <option value="personalizado">Personalizado (detallar en observaciones)</option>
                                        </select>
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <!-- Paso 2: Configuración Técnica -->
                    <div class="wizard-step-content" data-step="2">
                        <h3 class="wizard-step-title">Paso 2: Configuración Técnica</h3>
                        <div class="wizard-error-message" id="wizard-error-message2"></div>
                        <fieldset class="compliance-fieldset">
                            <legend class="compliance-fieldset-legend">Configuración Técnica y Gestión de Claves</legend>
                                <div class="compliance-form-row grid-cols-3">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Gestión de claves <span class="required">*</span></label>
                                        <select name="fields[keyManagement]" required class="compliance-select">
                                            <option value="">Seleccionar</option>
                                            <option value="kms_dedicado">KMS dedicado (AWS KMS, Azure Key Vault, GCP KMS)</option>
                                            <option value="hsm">HSM (Hardware Security Module)</option>
                                            <option value="vault">HashiCorp Vault / CyberArk / Thycotic</option>
                                            <option value="cloud_kms">Cloud KMS nativo (AWS/Azure/GCP)</option>
                                            <option value="manual_seguro">Manual con procedimiento documentado y custodia dual</option>
                                        </select>
                                        <span class="compliance-hint">Art. 30: la re-identificación debe requerir información adicional bajo custodia.</span>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Base de datos / Tabla origen</label>
                                        <input type="text" name="fields[sourceTable]" class="compliance-input" placeholder="Ej: public.clientes, dbo.empleados">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Columna(s) seudonimizadas</label>
                                        <input type="text" name="fields[columns]" class="compliance-input" placeholder="Ej: rut, email, nombre_completo">
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Algoritmo / Parámetros</label>
                                        <textarea name="fields[algorithmDetails]" rows="2" class="compliance-textarea" placeholder="Ej: AES-256-GCM, key rotation 90d, IV aleatorio por registro..."></textarea>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Tabla/Columna de mapeo (si reversible)</label>
                                        <input type="text" name="fields[mappingTable]" class="compliance-input" placeholder="Ej: pseudo_mapping (pseudonym, original_hash)">
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <!-- Step 3: Aplicación -->
                        <div class="pseudo-step-content" data-step="3" style="display: none;">
                            <div style="font-size: 10px; color: var(--text-subtle); font-weight: 600; margin-bottom: 0.25rem;">PASO 3 DE 5</div>
                            <h3 style="color: var(--text-heading); font-size: 14px; font-weight: 700; margin-bottom: 0.5rem;">Aplicación</h3>
                            <p style="color: var(--text-muted); font-size: 11px; line-height: 1.5; margin-bottom: 1.25rem;">Defina la frecuencia, entorno y estado de ejecución de la regla de seudonimización.</p>
                            
                            <fieldset class="compliance-fieldset">
                                <legend class="compliance-fieldset-legend">Configuración de Ejecución</legend>
                                <div class="compliance-form-row grid-cols-3">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Frecuencia de ejecución</label>
                                        <select name="fields[frequency]" class="compliance-select">
                                            <option value="bajo_demanda">Bajo demanda</option>
                                            <option value="diaria">Diaria (batch nocturno)</option>
                                            <option value="semanal">Semanal</option>
                                            <option value="mensual">Mensual</option>
                                            <option value="evento">Por evento (nuevo registro, migración)</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Entorno de aplicación</label>
                                        <select name="fields[environment]" class="compliance-select">
                                            <option value="produccion">Producción</option>
                                            <option value="preproduccion">Pre-producción / Staging</option>
                                            <option value="desarrollo">Desarrollo</option>
                                            <option value="analitica">Entorno analítico / Data Warehouse</option>
                                            <option value="todos">Todos los entornos</option>
                                        </select>
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Estado</label>
                                        <select name="fields[status]" class="compliance-select">
                                            <option value="draft">Borrador</option>
                                            <option value="testing">En pruebas</option>
                                            <option value="executed">Ejecutada / En producción</option>
                                            <option value="deprecated">Deprecada</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="compliance-form-row mt-4">
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Fecha última ejecución</label>
                                        <input type="datetime-local" name="fields[lastExecutedAt]" class="compliance-input">
                                    </div>
                                    <div class="compliance-form-cell">
                                        <label class="compliance-form-label">Próxima ejecución programada</label>
                                        <input type="datetime-local" name="fields[nextExecutionAt]" class="compliance-input">
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <!-- Paso 4: Verificación -->
                    <div class="wizard-step-content" data-step="4">
                        <h3 class="wizard-step-title">Paso 4: Verificación</h3>
                        <div class="wizard-error-message" id="wizard-error-message4"></div>
                        <fieldset class="compliance-fieldset">
                            <legend class="compliance-fieldset-legend">Verificación de Irreversibilidad</legend>
                            <div class="compliance-form-cell">
                                <label class="compliance-form-label">Verificación de irreversibilidad (para hashing)</label>
                                <select name="fields[irreversibilityVerified]" class="compliance-select">
                                    <option value="no">No verificada</option>
                                    <option value="si_teorica">Sí - Verificación teórica (análisis entropía)</option>
                                    <option value="si_practica">Sí - Verificación práctica (ataques de diccionario, rainbow tables)</option>
                                    <option value="certificado">Certificado por tercero</option>
                                </select>
                                <span class="compliance-hint">Art. 30: asegurar que el seudónimo no pueda ser revertido sin la información adicional bajo custodia.</span>
                            </div>
                        </fieldset>
                    </div>

                        <!-- Paso 5: Evidencia -->
                    <div class="wizard-step-content" data-step="5">
                        <h3 class="wizard-step-title">Paso 5: Evidencia</h3>
                        <div class="wizard-error-message" id="wizard-error-message5"></div>
                        <fieldset class="compliance-fieldset">
                            <legend class="compliance-fieldset-legend">Evidencia y Observaciones</legend>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Observaciones / Contexto</label>
                                    <textarea name="fields[notes]" rows="3" class="compliance-textarea" placeholder="Contexto: finalidad, tratamiento al que aplica, limitaciones, excepciones..."></textarea>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">URL de evidencia (scripts, configs, logs de ejecución)</label>
                                    <input type="url" name="fields[evidenceUrl]" class="compliance-input" placeholder="https://gitlab.empresa.cl/pseudo-rules/rut-clientes">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                        <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button type="button" class="wizard-btn-prev" id="wizard-prev-btn" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Anterior
                        </button>
                        <button type="button" class="wizard-btn-next" id="wizard-next-btn">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" name="create_item" value="1" class="wizard-btn-submit" id="wizard-submit-btn" style="display: none;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar regla de seudonimización (Art. 30 - Libre, expreso, informado, específico)
                        </button>
                    </div>

                    <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                    <input type="hidden" name="fields[createdBy]" value="<?= h($user['email'] ?? '') ?>">
                </form>
            </div>

            <script>
            (function() {
                const totalSteps = 5;
                let currentStep = 1;

                const prevBtn = document.getElementById('wizard-prev-btn');
                const nextBtn = document.getElementById('wizard-next-btn');
                const submitBtn = document.getElementById('wizard-submit-btn');
                const progressFill = document.getElementById('wizard-progress-fill');
                const stepCounter = document.getElementById('wizard-step-text');
                const stepDots = document.querySelectorAll('.wizard-step-dot');
                const stepContents = document.querySelectorAll('.wizard-step-content');

                function updateWizard() {
                    const progress = (currentStep / totalSteps) * 100;
                    progressFill.style.width = progress + '%';
                    stepCounter.textContent = 'Paso ' + currentStep + ' de ' + totalSteps;

                    stepDots.forEach(dot => {
                        const step = parseInt(dot.dataset.step);
                        dot.classList.remove('active', 'completed');
                        if (step < currentStep) {
                            dot.classList.add('completed');
                        } else if (step === currentStep) {
                            dot.classList.add('active');
                        }
                    });

                    stepContents.forEach(content => {
                        const step = parseInt(content.dataset.step);
                        content.classList.remove('active', 'hidden');
                        if (step === currentStep) {
                            content.classList.add('active');
                        } else {
                            content.classList.add('hidden');
                        }
                    });

                    prevBtn.disabled = currentStep === 1;
                    
                    if (currentStep === totalSteps) {
                        nextBtn.style.display = 'none';
                        submitBtn.style.display = 'inline-flex';
                    } else {
                        nextBtn.style.display = 'inline-flex';
                        submitBtn.style.display = 'none';
                    }
                }

                function validateStep(step) {
                    const stepContent = document.querySelector('.wizard-step-content[data-step="' + step + '"]');
                    const errorDiv = document.getElementById('wizard-error-message' + step);
                    const requiredFields = stepContent.querySelectorAll('[required]');
                    let isValid = true;
                    let errors = [];

                    requiredFields.forEach(field => {
                        field.style.borderColor = '';
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                            const label = field.closest('.compliance-form-cell').querySelector('.compliance-form-label');
                            errors.push(label ? label.textContent.replace(' *', '') : 'Campo requerido');
                        }
                    });

                    if (errorDiv) {
                        if (!isValid) {
                            errorDiv.textContent = 'Por favor complete los campos requeridos: ' + errors.join(', ');
                            errorDiv.classList.add('show');
                        } else {
                            errorDiv.classList.remove('show');
                        }
                    }

                    return isValid;
                }

                prevBtn.addEventListener('click', function() {
                    if (currentStep > 1) {
                        currentStep--;
                        updateWizard();
                    }
                });

                nextBtn.addEventListener('click', function() {
                    if (validateStep(currentStep) && currentStep < totalSteps) {
                        currentStep++;
                        updateWizard();
                    }
                });

                document.querySelectorAll('#pseudoWizardForm input, #pseudoWizardForm select, #pseudoWizardForm textarea').forEach(field => {
                    field.addEventListener('input', function() {
                        this.style.borderColor = '';
                        const stepContent = this.closest('.wizard-step-content');
                        const step = stepContent ? stepContent.dataset.step : null;
                        if (step) {
                            const errorDiv = document.getElementById('wizard-error-message' + step);
                            if (errorDiv) {
                                errorDiv.classList.remove('show');
                            }
                        }
                    });
                });

                updateWizard();
            })();
            </script>

            <?php elseif ($tab === 'trainings'): ?>
            <?php
            $tDone = count(array_filter($items, fn($it) => !empty($it['completed'])));
            $tPending = count($items) - $tDone;
            ?>
            <?php renderSectionHeader('Capacitaciones Ley 21.719', 'Registro de capacitación en protección de datos personales — Art. 28 letra c) (DPD debe capacitar al personal)', 'trainings'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('info')); ?>
                <?php renderComplianceStat('Completadas', $tDone, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $tPending, $tPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
                <?php renderComplianceStat('Avance', $items ? round($tDone / count($items) * 100) . '%' : '—', 'text-indigo-400', cIcon('shield')); ?>
            </div>

            <!-- Formulario wizard de capacitación (Art. 28.c Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Nueva capacitación (Art. 28 - Libre, expreso, informado, específico)
                    </p>
                    <?php renderImportBtn('trainings'); ?>
                </div>

                <form method="POST" id="trainingWizardForm" class="wizard-container">
                    <input type="hidden" name="collection" value="trainings">

                    <!-- Error Message -->
                    <div class="wizard-error-message" id="training-error-message">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="wizard-error-message-text" id="training-error-text">Por favor, complete todos los campos requeridos antes de continuar.</span>
                    </div>

                    <!-- Wizard Progress -->
                    <div class="wizard-progress">
                        <div class="wizard-progress-header">
                            <span class="wizard-progress-title">Progreso del formulario</span>
                            <span class="wizard-progress-steps" id="wizard-step-text">Paso 1 de 6</span>
                        </div>
                        <div class="wizard-progress-bar">
                            <div class="wizard-progress-fill" id="wizard-progress-fill" style="width: 16.66%"></div>
                        </div>
                        <div class="wizard-steps-indicator">
                            <div class="wizard-step-dot active" data-step="1">
                                <span class="wizard-step-number">1</span>
                                <span class="wizard-step-label">Info. Básica</span>
                            </div>
                            <div class="wizard-step-dot" data-step="2">
                                <span class="wizard-step-number">2</span>
                                <span class="wizard-step-label">Participantes</span>
                            </div>
                            <div class="wizard-step-dot" data-step="3">
                                <span class="wizard-step-number">3</span>
                                <span class="wizard-step-label">Contenido</span>
                            </div>
                            <div class="wizard-step-dot" data-step="4">
                                <span class="wizard-step-number">4</span>
                                <span class="wizard-step-label">Evaluación</span>
                            </div>
                            <div class="wizard-step-dot" data-step="5">
                                <span class="wizard-step-number">5</span>
                                <span class="wizard-step-label">Evidencia</span>
                            </div>
                            <div class="wizard-step-dot" data-step="6">
                                <span class="wizard-step-number">6</span>
                                <span class="wizard-step-label">Costo</span>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 1: Información Básica -->
                    <div class="wizard-step active" data-step="1">
                        <h3 class="wizard-step-title">Paso 1: Información Básica</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Información de la Capacitación</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Título / Tema <span class="required">*</span></label>
                                    <select name="fields[title]" required class="compliance-select">
                                        <option value="">Seleccionar tema</option>
                                        <optgroup label="Fundamentos">
                                            <option value="intro_ley_21719">Introducción a la Ley 21.719</option>
                                            <option value="principios">Principios de protección de datos (Art. 3)</option>
                                            <option value="derechos_arco">Derechos ARCO + Portabilidad (Art. 8-13)</option>
                                            <option value="consentimiento">Consentimiento informado (Art. 12)</option>
                                        </optgroup>
                                        <optgroup label="Tratamiento específico">
                                            <option value="datos_sensibles">Tratamiento de datos sensibles (Art. 16)</option>
                                            <option value="datos_ninos">Datos de niños/niñas/adolescentes (Art. 17)</option>
                                            <option value="transferencias">Transferencias internacionales (Art. 21)</option>
                                            <option value="seudonimizacion">Seudonimización y anonimización (Art. 30)</option>
                                        </optgroup>
                                        <optgroup label="Seguridad y Procedimientos">
                                            <option value="medidas_seguridad">Medidas de seguridad Art. 25</option>
                                            <option value="protocolo_brechas">Protocolo de brechas Art. 26</option>
                                            <option value="procedimiento_arco">Procedimiento atención solicitudes ARCO</option>
                                            <option value="eipd">Evaluación de Impacto (EIPD) Art. 29</option>
                                        </optgroup>
                                        <optgroup label="Roles específicos">
                                            <option value="dpd_role">Rol y obligaciones del DPD (Art. 28)</option>
                                            <option value="encargado_tratamiento">Obligaciones de encargados (Art. 22)</option>
                                            <option value="seguridad_informacion">Seguridad de la información y phishing</option>
                                        </optgroup>
                                        <option value="otro">Otro (especificar en observaciones)</option>
                                    </select>
                                    <span class="compliance-hint">Art. 28.c: DPD debe capacitar al personal que trata datos.</span>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Modalidad <span class="required">*</span></label>
                                    <select name="fields[modality]" required class="compliance-select">
                                        <option value="presencial">Presencial</option>
                                        <option value="virtual_sync">Virtual sincrónica (Zoom/Teams en vivo)</option>
                                        <option value="virtual_async">Virtual asincrónica (LMS/Moodle)</option>
                                        <option value="hibrida">Híbrida</option>
                                        <option value="e_learning">E-learning autogestionado</option>
                                    </select>
                                </div>
                            </div>
                            <div class="compliance-form-row grid-cols-3 mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha <span class="required">*</span></label>
                                    <input type="date" name="fields[date]" required class="compliance-input" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Duración (horas) <span class="required">*</span></label>
                                    <input type="number" name="fields[durationHours]" required class="compliance-input" min="0.5" max="40" step="0.5" value="2" placeholder="Ej: 2, 4, 8">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Instructor / Entidad</label>
                                    <input type="text" name="fields[instructor]" class="compliance-input" placeholder="Nombre del capacitador o entidad externa">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Paso 2: Participantes -->
                    <div class="wizard-step" data-step="2">
                        <h3 class="wizard-step-title">Paso 2: Participantes</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Participantes y Alcance</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Público objetivo <span class="required">*</span></label>
                                    <select name="fields[targetAudience]" required class="compliance-select">
                                        <option value="">Seleccionar</option>
                                        <option value="todo_personal">Todo el personal</option>
                                        <option value="tratamiento_datos">Personal que trata datos personales</option>
                                        <option value="nuevo_ingreso">Nuevos ingresos (onboarding)</option>
                                        <option value="directivos">Directivos y mandos medios</option>
                                        <option value="ti_seguridad">TI / Seguridad / DPD</option>
                                        <option value="atencion_publico">Atención al público / ARCO</option>
                                        <option value="proveedores">Proveedores / Encargados tratamiento</option>
                                        <option value="especifico">Equipo específico (detallar en observaciones)</option>
                                    </select>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Nº participantes esperados</label>
                                    <input type="number" name="fields[expectedAttendees]" class="compliance-input" min="1" placeholder="Ej: 50">
                                </div>
                            </div>
                            <div class="compliance-form-row mt-4">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Participantes reales (lista RUT/Nombres)</label>
                                    <textarea name="fields[attendeesList]" rows="3" class="compliance-textarea" placeholder="RUT, Nombre completo (uno por línea)&#10;12.345.678-9, Juan Pérez González&#10;98.765.432-1, María González López"></textarea>
                                    <span class="compliance-hint">Para trazabilidad y evidencia ante fiscalización.</span>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Departamentos/Áreas</label>
                                    <input type="text" name="fields[departments]" class="compliance-input" placeholder="RRHH, Comercial, TI, Finanzas, Operaciones">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Paso 3: Contenido -->
                    <div class="wizard-step" data-step="3">
                        <h3 class="wizard-step-title">Paso 3: Contenido</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Contenido y Materiales</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Temario / Contenido <span class="required">*</span></label>
                                    <textarea name="fields[content]" required rows="3" class="compliance-textarea" placeholder="Detalle de temas cubiertos, referencias legales (Art. 3, 12, 16, 25, 26, 28, 30), casos prácticos..."></textarea>
                                    <span class="compliance-hint">Debe incluir referencias a artículos específicos de la Ley 21.719.</span>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Materiales entregados</label>
                                    <select name="fields[materials][]" multiple class="compliance-select" size="5">
                                        <option value="presentacion">Presentación (PDF/PPT)</option>
                                        <option value="guia">Guía / Manual de procedimientos</option>
                                        <option value="casos_practicos">Casos prácticos / Ejercicios</option>
                                        <option value="test">Test de evaluación</option>
                                        <option value="video">Grabación de la sesión</option>
                                        <option value="certificado">Certificado de participación</option>
                                    </select>
                                    <span class="compliance-hint">Ctrl+Click. Evidencia para fiscalización.</span>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Paso 4: Evaluación -->
                    <div class="wizard-step" data-step="4">
                        <h3 class="wizard-step-title">Paso 4: Evaluación</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Evaluación y Resultados</p>
                            <div class="compliance-form-row grid-cols-3">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Incluye evaluación? <span class="required">*</span></label>
                                    <select name="fields[hasEvaluation]" required class="compliance-select">
                                        <option value="si_test">Sí - Test escrito</option>
                                        <option value="si_casos">Sí - Casos prácticos</option>
                                        <option value="si_ambos">Sí - Test + Casos prácticos</option>
                                        <option value="no">No (solo asistencia)</option>
                                    </select>
                                    <span class="compliance-hint">Recomendado: evaluación para demostrar efectividad.</span>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Nota mínima aprobación (%)</label>
                                    <input type="number" name="fields[passingScore]" class="compliance-input" min="50" max="100" value="70" placeholder="70">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">% Aprobados</label>
                                    <input type="number" name="fields[approvalRate]" class="compliance-input" min="0" max="100" placeholder="Ej: 85">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Paso 5: Evidencia y Certificación -->
                    <div class="wizard-step" data-step="5">
                        <h3 class="wizard-step-title">Paso 5: Evidencia y Certificación</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Evidencias y Certificados</p>
                            <div class="compliance-form-row grid-cols-3">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">URL de evidencias</label>
                                    <input type="url" name="fields[evidenceUrl]" class="compliance-input" placeholder="https://drive.empresa.cl/capacitaciones/2024-01">
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">¿Entrega certificado?</label>
                                    <select name="fields[certificateIssued]" class="compliance-select">
                                        <option value="si">Sí - Certificado individual</option>
                                        <option value="si_grupal">Sí - Certificado grupal</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Fecha próxima recertificación</label>
                                    <input type="date" name="fields[recertificationDate]" class="compliance-input" placeholder="Recomendado: 12 meses">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Paso 6: Costo y Observaciones -->
                    <div class="wizard-step" data-step="6">
                        <h3 class="wizard-step-title">Paso 6: Costo y Observaciones</h3>
                        <fieldset class="wizard-fieldset">
                            <p class="wizard-fieldset-title">Información Adicional</p>
                            <div class="compliance-form-row">
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Observaciones</label>
                                    <textarea name="fields[notes]" rows="3" class="compliance-textarea" placeholder="Observaciones, incidencias, mejoras para próxima edición..."></textarea>
                                </div>
                                <div class="compliance-form-cell">
                                    <label class="compliance-form-label">Costo (CLP)</label>
                                    <input type="number" name="fields[costCLP]" class="compliance-input" min="0" placeholder="Ej: 500000">
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button type="button" class="wizard-btn-prev" id="wizard-prev-btn" disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Anterior
                        </button>
                        <button type="button" class="wizard-btn-next" id="wizard-next-btn">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" class="wizard-btn-submit" id="wizard-submit-btn" name="create_item" value="1" style="display: none;">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar capacitación (Art. 28 - Libre, expreso, informado, específico)
                        </button>
                    </div>

                    <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                    <input type="hidden" name="fields[createdBy]" value="<?= h($user['email'] ?? '') ?>">
                </form>
            </div>

            <script>
            (function() {
                const wizardForm = document.getElementById('trainingWizardForm');
                if (!wizardForm) return;

                let currentStep = 1;
                const totalSteps = 6;

                const stepCounter = document.getElementById('wizard-step-text');
                const progressFill = document.getElementById('wizard-progress-fill');
                const stepDots = document.querySelectorAll('.wizard-step-dot');
                const stepContents = document.querySelectorAll('.wizard-step');
                const prevBtn = document.getElementById('wizard-prev-btn');
                const nextBtn = document.getElementById('wizard-next-btn');
                const submitBtn = document.getElementById('wizard-submit-btn');

                function updateWizard() {
                    stepCounter.textContent = `Paso ${currentStep} de ${totalSteps}`;
                    const progress = (currentStep / totalSteps) * 100;
                    progressFill.style.width = `${progress}%`;

                    stepDots.forEach((dot, index) => {
                        const stepNum = index + 1;
                        dot.classList.remove('active', 'completed');
                        if (stepNum === currentStep) {
                            dot.classList.add('active');
                        } else if (stepNum < currentStep) {
                            dot.classList.add('completed');
                        }
                    });

                    stepContents.forEach((content, index) => {
                        const stepNum = index + 1;
                        content.classList.remove('active', 'hidden');
                        if (stepNum === currentStep) {
                            content.classList.add('active');
                        } else {
                            content.classList.add('hidden');
                        }
                    });

                    prevBtn.disabled = currentStep === 1;
                    if (currentStep === totalSteps) {
                        nextBtn.style.display = 'none';
                        submitBtn.style.display = 'inline-flex';
                    } else {
                        nextBtn.style.display = 'inline-flex';
                        submitBtn.style.display = 'none';
                    }
                }

                function validateStep(step) {
                    const stepContent = document.querySelector(`.wizard-step[data-step="${step}"]`);
                    const requiredFields = stepContent.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            isValid = false;
                            field.style.borderColor = '#ef4444';
                        } else {
                            field.style.borderColor = '';
                        }
                    });

                    return isValid;
                }

                prevBtn.addEventListener('click', () => {
                    if (currentStep > 1) {
                        currentStep--;
                        updateWizard();
                    }
                });

                nextBtn.addEventListener('click', () => {
                    if (validateStep(currentStep)) {
                        if (currentStep < totalSteps) {
                            currentStep++;
                            updateWizard();
                        }
                    } else {
                        alert('Por favor, complete todos los campos requeridos antes de continuar.');
                    }
                });

                stepDots.forEach((dot, index) => {
                    dot.addEventListener('click', () => {
                        const targetStep = index + 1;
                        if (targetStep <= currentStep || targetStep === currentStep + 1) {
                            if (validateStep(currentStep) || targetStep < currentStep) {
                                currentStep = targetStep;
                                updateWizard();
                            }
                        }
                    });
                });

                updateWizard();
            })();
            </script>
            <?php if (empty($items)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin capacitaciones registradas. Crea una o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($items as $it):
                    $done = !empty($it['completed']) || !empty($it['signatureAssignedAt']) || !empty($it['inviteId']);
                    $initial = strtoupper(substr($it['title'] ?? '?', 0, 1));
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 text-[12px] font-bold <?= $done ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400' ?>"><?= h($initial) ?></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($it['title'] ?? 'Capacitación') ?></p>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $done ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20' ?>"><?= $done ? 'Completada' : 'Pendiente' ?></span>
                            <?php if (!empty($it['signatureAssignedAt']) || !empty($it['inviteId'])): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border bg-indigo-500/10 text-indigo-400 border-indigo-500/25">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Firma asignada
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-0.5"><?= h($it['attendee'] ?? '') ?> · <?= h($it['date'] ?? substr($it['createdAt'] ?? '', 0, 10)) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if (!$done): ?>
                        <button onclick="createTrainingInvite('<?= h($it['_id'] ?? '') ?>')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-gradient-to-r from-primary-600 to-cyan-600 hover:from-primary-500 hover:to-cyan-500 text-white border border-primary-500/30 transition-all flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Crear firma
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($it['inviteId'])): ?>
                        <?php 
                        $invite = null;
                        foreach ($allInvites as $inv) {
                            if (($inv['_id'] ?? '') === $it['inviteId']) {
                                $invite = $inv;
                                break;
                            }
                        }
                        $inviteToken = $invite['token'] ?? '';
                        $signUrl = $inviteToken ? 'http://localhost:8090/firmar/' . $inviteToken : '#';
                        ?>
                        <?php if ($inviteToken): ?>
                        <a href="<?= h($signUrl) ?>" target="_blank" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 border border-emerald-500/30 transition-all flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Abrir
                        </a>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="invite_id" value="<?= h(is_string($it['inviteId'] ?? '') ? $it['inviteId'] : '') ?>">
                            <button type="submit" name="unassign_invite" value="1" onclick="return confirm('¿Quitar la firma de esta capacitación?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Quitar</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="collection" value="trainings">
                            <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                            <input type="hidden" name="delete_item" value="1">
                            <button type="submit" onclick="return confirm('¿Eliminar esta capacitación?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'invites'): ?>
            <?php
            $invSigned = count(array_filter($items, fn($it) => !empty($it['signed'])));
            $invPending = count($items) - $invSigned;
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
            ?>
            <?php renderSectionHeader('Firmas', 'Invitaciones de firma electrónica de documentos de cumplimiento'); ?>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('pen')); ?>
                <?php renderComplianceStat('Firmadas', $invSigned, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $invPending, $invPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white">Nueva invitación de firma</p>
                    <?php renderImportBtn('invites'); ?>
                </div>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="hidden" name="collection" value="invites">
                    <input type="text" name="fields[title]" required placeholder="Título del documento" class="compliance-input">
                    <input type="text" name="fields[description]" placeholder="Descripción" class="compliance-input">
                    <button type="submit" name="create_item" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all">Crear invitación</button>
                </form>
            </div>
            <?php if (empty($items)): ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center">
                <p class="text-[11px] text-text-subtle">Sin invitaciones de firma todavía. Crea una o usa «Importar masivo».</p>
            </div>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($items as $it):
                    $signed = !empty($it['signed']);
                    $signUrl = 'http://localhost:8090/firmar/' . ($it['token'] ?? '');
                    $urlId = 'invurl-' . substr((string)($it['_id'] ?? ''), 0, 8);
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4">
                    <div class="flex flex-col md:flex-row md:items-center gap-3">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $signed ? 'bg-emerald-500/10 text-emerald-400' : 'bg-blue-500/10 text-blue-400' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-[12px] font-medium text-text-heading truncate"><?= h($it['title'] ?? 'Documento') ?></p>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border <?= $signed ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20' ?>"><?= $signed ? 'Firmado' : 'Pendiente' ?></span>
                            </div>
                            <?php if ($signed): ?>
                            <p class="text-[10px] text-emerald-400 mt-0.5">Firmado por <?= h($it['signerName'] ?? '-') ?> el <?= h(substr($it['signedAt'] ?? '', 0, 16)) ?></p>
                            <?php if (!empty($it['signature']) && str_starts_with((string)$it['signature'], 'data:image/')): ?>
                            <div class="mt-2 rounded-lg border border-emerald-500/20 bg-white p-2 w-40">
                                <p class="text-[8px] text-slate-400 uppercase tracking-widest mb-1">Firma manuscrita</p>
                                <img src="<?= h($it['signature']) ?>" alt="Firma" class="w-full h-16 object-contain">
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($it['assignedTrainingName'])): ?>
                            <p class="text-[10px] text-indigo-400 mt-2 inline-flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                Firma asignada a: <b class="text-indigo-300"><?= h($it['assignedTrainingName']) ?></b>
                            </p>
                            <?php endif; ?>
                            <button type="button" onclick="openAssignFirma('<?= h($it['_id'] ?? '') ?>')" class="mt-2 px-3 py-1.5 rounded-lg text-[10px] font-medium bg-indigo-500/10 border border-indigo-500/25 text-indigo-400 hover:bg-indigo-500/20 transition-all inline-flex items-center gap-1.5">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Asignar firma
                            </button>
                            <?php else: ?>
                            <p class="text-[10px] text-text-subtle mt-0.5"><?= h($it['description'] ?? '') ?> · Creada: <?= h(substr($it['createdAt'] ?? '', 0, 10)) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <?php if ($signed) renderActionBtn('invites', $it['_id'] ?? '', 'unsign', 'Anular firma'); ?>
                            <?php if ($signed && !empty($it['assignedTrainingId'])): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="invite_id" value="<?= h($it['_id'] ?? '') ?>">
                                <button type="submit" name="unassign_invite" value="1" onclick="return confirm('¿Quitar la asignación de esta firma?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-amber-900/10 border border-amber-800/20 text-amber-400 hover:bg-amber-900/20 transition-all">Quitar asignación</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="collection" value="invites">
                                <input type="hidden" name="item_id" value="<?= h($it['_id'] ?? '') ?>">
                                <button type="submit" name="delete_item" value="1" onclick="return confirm('¿Eliminar esta invitación?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-col sm:flex-row gap-2">
                        <input type="text" readonly value="<?= h($signUrl) ?>" id="<?= h($urlId) ?>" class="flex-1 bg-bg-base border border-border-theme text-[11px] font-mono text-text-muted rounded-lg px-3 py-2 focus:outline-none focus:border-accent">
                        <button type="button" onclick="copyInviteUrl('<?= h($urlId) ?>', this)" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.06] text-text-muted hover:text-text-body transition-all inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m9 0l-3-3m0 0l-3 3"/></svg>
                            <span>Copiar enlace</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'files'): ?>
            <!-- ═══ SECCIÓN ARCHIVOS (CORREGIDA) ═══ -->
            <?php
            $filesRes = api_get('/api/compliance/files', ['token' => $token]);
            $files = is_array($filesRes) && empty($filesRes['error']) ? $filesRes : [];
            $fileMsg = '';
            $fileErr = '';

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($_POST['analyze_file'])) {
                    $res = api_post_form('/api/compliance/files/analyze', [
                        'token' => $token,
                        'fileId' => $_POST['file_id'] ?? '',
                    ]);
                    if (!empty($res['success'])) $fileMsg = 'Archivo analizado correctamente.';
                    else $fileErr = $res['error'] ?? 'Error al analizar.';
                }
                if (isset($_POST['delete_file'])) {
                    $res = api_post_form('/api/compliance/files/delete', [
                        'token' => $token,
                        'fileId' => $_POST['file_id'] ?? '',
                    ]);
                    if (!empty($res['success'])) $fileMsg = 'Archivo eliminado.';
                    else $fileErr = $res['error'] ?? 'Error al eliminar.';
                }
            }
            ?>

            <?php renderSectionHeader('Archivos con Datos Personales', 'Sube y analiza archivos XLS, XLSX, CSV o TXT para incluirlos en el inventario de datos (RAT).'); ?>

            <?php if ($fileMsg): ?>
                <div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px] mb-4"><?= h($fileMsg) ?></div>
            <?php endif; ?>
            <?php if ($fileErr): ?>
                <div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px] mb-4"><?= h($fileErr) ?></div>
            <?php endif; ?>

            <!-- Formulario de subida -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <p class="text-[12px] font-semibold text-white mb-4">Subir nuevo archivo</p>
                <form id="upload-form" enctype="multipart/form-data" class="flex flex-col md:flex-row gap-3">
                    <input type="file" name="file" id="file-input" required
                           accept=".xlsx,.xls,.csv,.txt"
                           class="flex-1 bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-medium file:bg-primary-500/10 file:text-primary-400 hover:file:bg-primary-500/20">
                    <button type="submit" id="upload-btn"
                            class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all flex items-center gap-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Subir archivo
                    </button>
                </form>
                <div id="upload-progress" class="hidden mt-3">
                    <div class="w-full bg-bg-elevated rounded-full h-1.5">
                        <div id="upload-bar" class="bg-primary-500 h-1.5 rounded-full transition-all" style="width:0%"></div>
                    </div>
                    <p id="upload-status" class="text-[10px] text-text-subtle mt-1">Subiendo...</p>
                </div>
            </div>

            <!-- Lista de archivos -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-border-theme/20 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg border border-white/[0.04] bg-white/[0.01] flex items-center justify-center text-cyan-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <p class="text-[12px] font-semibold text-white">Archivos subidos</p>
                        <span class="text-[10px] text-text-subtle"><?= count($files) ?> archivos</span>
                    </div>
                    <button onclick="location.reload()" class="text-[10px] text-text-muted hover:text-text-body transition-colors">Refrescar</button>
                </div>

                <?php if (empty($files)): ?>
                    <p class="px-5 py-8 text-[11px] text-text-subtle text-center">No hay archivos subidos. Sube un archivo para comenzar.</p>
                <?php else: ?>
                    <div class="divide-y divide-border-theme/20">
                        <?php foreach ($files as $f):
                            $status = $f['status'] ?? 'pending';
                            $statusLabels = [
                                'pending'   => ['label' => 'Pendiente', 'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20'],
                                'analyzing' => ['label' => 'Analizando...', 'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20'],
                                'analyzed'  => ['label' => 'Analizado', 'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'],
                                'failed'    => ['label' => 'Error', 'class' => 'bg-red-500/10 text-red-400 border-red-500/20'],
                            ];
                            $st = $statusLabels[$status] ?? $statusLabels['pending'];
                            $result = $f['analysisResult'] ?? null;
                            $sourceType = $f['sourceType'] ?? 'user';
                        ?>
                        <div class="px-5 py-3 flex flex-col md:flex-row md:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[12px] font-medium text-text-heading truncate"><?= h($f['originalName'] ?? 'Archivo') ?></span>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $st['class'] ?>"><?= h($st['label']) ?></span>
                                    <span class="text-[10px] text-text-subtle"><?= h(number_format($f['size'] ?? 0)) ?> bytes</span>

                                    <!-- ═══ NUEVO: Mostrar origen y usuario ═══ -->
                                    <?php if ($sourceType === 'agent'): ?>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 flex items-center gap-1">
                                            🤖 Agente: <?= h($f['hostname'] ?? $f['agentId'] ?? 'N/A') ?>
                                        </span>
                                        <?php if (!empty($f['user'])): ?>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-400 border border-purple-500/20 flex items-center gap-1">
                                                👤 Usuario PC: <?= h($f['user']) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-500/10 text-text-muted border border-border-theme flex items-center gap-1">
                                            👤 Usuario
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[10px] text-text-subtle mt-0.5">
                                    Subido: <?= h(substr($f['createdAt'] ?? '', 0, 16)) ?>
                                    <?php if ($result && isset($result['rowCount'])): ?>
                                        · <?= h($result['rowCount']) ?> registros · <?= h(count($result['headers'] ?? [])) ?> columnas
                                    <?php endif; ?>
                                </p>
                                <?php if ($result && isset($result['patterns']) && !empty($result['patterns'])): ?>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        <?php foreach ($result['patterns'] as $col => $types): ?>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-primary-500/10 text-primary-400 border border-primary-500/20">
                                                <?= h($col) ?>: <?= h(implode(', ', $types)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <?php if ($status === 'pending'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="file_id" value="<?= h($f['_id'] ?? '') ?>">
                                        <button type="submit" name="analyze_file" value="1"
                                                class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 hover:bg-cyan-500/20 transition-all">
                                            Analizar
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($status === 'analyzed' && $result): ?>
                                    <button onclick="openMapModal('<?= h($f['_id'] ?? '') ?>', <?= htmlspecialchars(json_encode($result['headers'] ?? [])) ?>)"
                                            class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-500/20 transition-all">
                                        Mapear
                                    </button>
                                <?php endif; ?>

                                <?php if ($status === 'analyzed' && isset($result['inventoryId'])): ?>
                                    <a href="/compliance?tab=inventory" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all">
                                        Ver en inventario
                                    </a>
                                <?php endif; ?>

                                <form method="POST" class="inline">
                                    <input type="hidden" name="file_id" value="<?= h($f['_id'] ?? '') ?>">
                                    <button type="submit" name="delete_file" value="1" onclick="return confirm('¿Eliminar este archivo?')"
                                            class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Modal de Mapeo -->
            <div id="map-modal" class="hidden fixed inset-0 bg-black/65 flex items-center justify-center z-50 p-4">
                <div class="bg-bg-panel border border-border-theme rounded-xl shadow-2xl w-full max-w-xl max-h-[80vh] flex flex-col">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0">
                        <h3 class="text-[13px] font-semibold text-white">Mapeo de columnas</h3>
                        <button onclick="document.getElementById('map-modal').classList.add('hidden')" class="text-text-muted hover:text-text-heading transition-colors p-1 rounded-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-5 scrollbar-custom">
                        <p class="text-[11px] text-text-muted mb-4">Asigna cada columna a una categoría de dato personal. Esto actualizará el inventario.</p>
                        <form id="map-form" class="space-y-3">
                            <input type="hidden" name="fileId" id="map-file-id">
                            <div id="map-fields" class="space-y-2"></div>
                            <div id="map-result" class="hidden mt-3 p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px]"></div>
                            <div id="map-error" class="hidden mt-3 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-[11px]"></div>
                        </form>
                    </div>
                    <div class="flex justify-end gap-2 px-5 py-4 border-t border-border-theme flex-shrink-0">
                        <button onclick="document.getElementById('map-modal').classList.add('hidden')"
                                class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                        <button onclick="submitMapping()"
                                class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all">Guardar mapeo</button>
                    </div>
                </div>
            </div>

            <script>
            // ─── Subida de archivos con progreso ───
            document.getElementById('upload-form')?.addEventListener('submit', async function(e) {
                e.preventDefault();
                const fileInput = document.getElementById('file-input');
                const file = fileInput.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('file', file);
                formData.append('token', '<?= h($token) ?>');

                const progress = document.getElementById('upload-progress');
                const bar = document.getElementById('upload-bar');
                const status = document.getElementById('upload-status');

                progress.classList.remove('hidden');
                bar.style.width = '0%';
                status.textContent = 'Subiendo...';

                try {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '/api/compliance/files/upload');

                    xhr.upload.onprogress = (e) => {
                        if (e.lengthComputable) {
                            const pct = Math.round((e.loaded / e.total) * 100);
                            bar.style.width = pct + '%';
                        }
                    };

                    xhr.onload = function() {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                bar.style.width = '100%';
                                status.textContent = '✔ Subido correctamente. Analizando...';
                                // Analizar automáticamente después de subir
                                fetch('/api/compliance/files/analyze', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ fileId: res.fileId, token: '<?= h($token) ?>' })
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if (data.success) {
                                        status.textContent = '✔ Archivo analizado. Recargando...';
                                        setTimeout(() => location.reload(), 1000);
                                    } else {
                                        status.textContent = '⚠ ' + (data.error || 'Error en análisis');
                                    }
                                })
                                .catch(() => {
                                    status.textContent = '⚠ Error al analizar. Recarga para intentar.';
                                });
                            } else {
                                status.textContent = '✗ ' + (res.error || 'Error al subir');
                            }
                        } catch (e) {
                            status.textContent = '✗ Error al procesar respuesta';
                        }
                    };

                    xhr.onerror = function() {
                        status.textContent = '✗ Error de conexión';
                    };

                    xhr.send(formData);
                } catch (e) {
                    status.textContent = '✗ Error: ' + e.message;
                }
            });

            // ─── Mapeo manual ───
            const CATEGORIES = ['nombre', 'rut', 'email', 'telefono', 'direccion', 'fecha_nacimiento', 'genero', 'profesion', 'empresa', 'cargo', 'otro'];

            function openMapModal(fileId, headers) {
                document.getElementById('map-file-id').value = fileId;
                const container = document.getElementById('map-fields');
                container.innerHTML = '';
                headers.forEach((h, i) => {
                    const div = document.createElement('div');
                    div.className = 'flex items-center gap-3';
                    div.innerHTML = `
                        <label class="text-[11px] text-text-body w-32 truncate flex-shrink-0" title="${h}">${h}</label>
                        <select name="mapping[${i}]" class="flex-1 bg-bg-base border border-border-theme text-[11px] text-white rounded-lg px-2 py-1.5 focus:outline-none focus:border-accent transition-all">
                            <option value="">Seleccionar...</option>
                            ${CATEGORIES.map(c => `<option value="${c}">${c}</option>`).join('')}
                        </select>
                    `;
                    container.appendChild(div);
                });
                document.getElementById('map-modal').classList.remove('hidden');
                document.getElementById('map-result').classList.add('hidden');
                document.getElementById('map-error').classList.add('hidden');
            }

            async function submitMapping() {
                const fileId = document.getElementById('map-file-id').value;
                const selects = document.querySelectorAll('#map-fields select');
                const mapping = {};
                selects.forEach(sel => {
                    if (sel.value) {
                        const index = parseInt(sel.name.replace('mapping[', '').replace(']', ''));
                        mapping[index] = sel.value;
                    }
                });

                if (Object.keys(mapping).length === 0) {
                    document.getElementById('map-error').textContent = 'Selecciona al menos una columna para mapear.';
                    document.getElementById('map-error').classList.remove('hidden');
                    return;
                }

                try {
                    const res = await fetch('/api/compliance/files/map', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ fileId, mapping, token: '<?= h($token) ?>' })
                    });
                    const data = await res.json();
                    if (data.success) {
                        document.getElementById('map-result').textContent = '✔ Mapeo guardado correctamente. El inventario ha sido actualizado.';
                        document.getElementById('map-result').classList.remove('hidden');
                        document.getElementById('map-error').classList.add('hidden');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        document.getElementById('map-error').textContent = data.error || 'Error al guardar mapeo.';
                        document.getElementById('map-error').classList.remove('hidden');
                    }
                } catch (e) {
                    document.getElementById('map-error').textContent = 'Error de conexión: ' + e.message;
                    document.getElementById('map-error').classList.remove('hidden');
                }
            }
            </script>

            <?php elseif ($tab === 'file-audit'): ?>
            <!-- ═══ SECCIÓN AUDITORÍA DE ARCHIVOS ═══ -->
            <?php
            // ─── Obtener logs de auditoría de archivos ───
            $auditLogs = [];
            try {
                $auditRes = api_get('/api/compliance/files/audit-logs', ['token' => $token, 'limit' => 200]);
                if (is_array($auditRes) && !isset($auditRes['error'])) {
                    $auditLogs = $auditRes['logs'] ?? [];
                }
            } catch (Exception $e) {
                // Silently fail
            }
            $auditCount = count($auditLogs);
            ?>

            <?php renderSectionHeader('Auditoría de Archivos', 'Registro detallado de todos los archivos detectados por el agente, incluyendo usuario, categorías y estado.'); ?>

            <?php if ($auditCount === 0): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-12 text-center">
                    <div class="w-12 h-12 rounded-xl bg-bg-elevated border border-border-theme flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h3 class="text-white font-semibold mb-2">Sin logs de auditoría</h3>
                    <p class="text-text-muted text-[12px]">Cuando el agente detecte archivos con datos personales, aparecerán aquí con todos los detalles.</p>
                </div>
            <?php else: ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="border-b border-border-theme bg-bg-base/60 text-text-muted uppercase text-[10px] tracking-wider">
                                    <th class="text-left py-2.5 px-3">Fecha</th>
                                    <th class="text-left py-2.5 px-3">Agente / Host</th>
                                    <th class="text-left py-2.5 px-3">Usuario PC</th>
                                    <th class="text-left py-2.5 px-3">Archivo</th>
                                    <th class="text-left py-2.5 px-3">Categorías</th>
                                    <th class="text-left py-2.5 px-3">Sensible</th>
                                    <th class="text-left py-2.5 px-3">Registros</th>
                                    <th class="text-left py-2.5 px-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-theme/30">
                                <?php foreach ($auditLogs as $log): ?>
                                <tr class="border-t border-border-theme/30 hover:bg-bg-base/40 transition-colors">
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]">
                                        <?= h(substr($log['detectedAt'] ?? $log['createdAt'] ?? '', 0, 16)) ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[11px] text-text-body font-mono truncate block max-w-[120px]" title="<?= h($log['agentId'] ?? '') ?>">
                                            <?= h($log['hostname'] ?? substr($log['agentId'] ?? '', 0, 12)) ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-body text-[11px]">
                                        <?= h($log['user'] ?? '-') ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-heading text-[11px] truncate max-w-[200px]" title="<?= h($log['path'] ?? '') ?>">
                                        <?= h(basename($log['path'] ?? 'Desconocido')) ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <?php
                                        $cats = $log['categories'] ?? [];
                                        if (is_string($cats)) $cats = explode(',', $cats);
                                        if (!empty($cats) && is_array($cats)) {
                                            $display = array_slice($cats, 0, 3);
                                            echo implode(', ', array_map(fn($c) => '<span class="text-[10px] px-1.5 py-0.5 rounded bg-primary-500/10 text-primary-400 border border-primary-500/20">' . h($c) . '</span>', $display));
                                            if (count($cats) > 3) echo ' <span class="text-[10px] text-text-subtle">+'.(count($cats)-3).' más</span>';
                                        } else {
                                            echo '<span class="text-[10px] text-text-subtle">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <?php if (!empty($log['sensitive'])): ?>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 flex items-center gap-1 w-fit">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span> Sí
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-text-subtle">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]">
                                        <?= h($log['rowCount'] ?? 0) ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            <?= h($log['status'] ?? 'procesado') ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Footer -->
                    <div class="px-5 py-2.5 border-t border-border-theme/20 flex items-center justify-between text-[10px] text-text-subtle">
                        <span><?= $auditCount ?> registros de auditoría</span>
                        <span>Última actualización: <?= date('H:i:s') ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- JavaScript para el Wizard de Consentimientos -->
    <script>
    // Consentimiento Wizard Logic
    (function() {
        const wizardForm = document.getElementById('consent-wizard-form');
        if (!wizardForm) return;

        let currentStep = 1;
        const totalSteps = 6;

        const stepText = document.getElementById('wizard-step-text');
        const progressFill = document.getElementById('wizard-progress-fill');
        const prevBtn = document.getElementById('wizard-prev-btn');
        const nextBtn = document.getElementById('wizard-next-btn');
        const submitBtn = document.getElementById('wizard-submit-btn');

        function updateWizard() {
            // Update progress text and bar
            stepText.textContent = 'Paso ' + currentStep + ' de ' + totalSteps;
            progressFill.style.width = ((currentStep - 1) / (totalSteps - 1)) * 100 + '%';

            // Update step indicators
            for (let i = 1; i <= totalSteps; i++) {
                const dot = document.querySelector('.wizard-step-dot[data-step="' + i + '"]');
                const stepNumber = dot.querySelector('.wizard-step-number');
                const stepLabel = dot.querySelector('.wizard-step-label');

                dot.classList.remove('active', 'completed');
                stepNumber.textContent = i;

                if (i < currentStep) {
                    dot.classList.add('completed');
                    stepNumber.innerHTML = '✓';
                } else if (i === currentStep) {
                    dot.classList.add('active');
                }
            }

            // Show/hide step content
            document.querySelectorAll('.wizard-step-content').forEach(function(content) {
                content.classList.remove('active');
            });
            document.querySelector('.wizard-step-content[data-step="' + currentStep + '"]').classList.add('active');

            // Update navigation buttons
            prevBtn.disabled = currentStep === 1;

            if (currentStep === totalSteps) {
                nextBtn.style.display = 'none';
                if (submitBtn) submitBtn.classList.add('visible');
            } else {
                nextBtn.style.display = 'inline-flex';
                if (submitBtn) submitBtn.classList.remove('visible');
            }
        }

        function validateStep(step) {
            const stepContent = document.querySelector('.wizard-step-content[data-step="' + step + '"]');
            const requiredFields = stepContent.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#ef4444';
                    field.addEventListener('input', function() {
                        this.style.borderColor = '';
                    }, { once: true });
                }
            });

            // Special validation for step 2 - data categories
            if (step === 2) {
                const dataCategories = document.getElementById('step2-dataCategories');
                if (dataCategories && dataCategories.selectedOptions.length === 0) {
                    isValid = false;
                    dataCategories.style.borderColor = '#ef4444';
                    dataCategories.addEventListener('change', function() {
                        this.style.borderColor = '';
                    }, { once: true });
                }
            }

            // Special validation for step 3 - if sensitive or children data is selected
            if (step === 3) {
                const sensitive = document.getElementById('step2-sensitive');
                const childrenData = document.getElementById('step2-childrenData');
                const sensitiveFieldset = document.getElementById('sensitive-consent-fieldset');
                const childrenFieldset = document.getElementById('children-consent-fieldset');

                if (sensitive && sensitive.value === 'si' && sensitiveFieldset.style.display !== 'none') {
                    const sensitiveCheckboxes = sensitiveFieldset.querySelectorAll('input[type="checkbox"]');
                    let allChecked = true;
                    sensitiveCheckboxes.forEach(function(cb) {
                        if (!cb.checked) allChecked = false;
                    });
                    if (!allChecked) isValid = false;
                }

                if (childrenData && childrenData.value === 'si' && childrenFieldset.style.display !== 'none') {
                    const parentName = document.getElementById('step3-parentName');
                    const parentRut = document.getElementById('step3-parentRut');
                    const parentEmail = document.getElementById('step3-parentEmail');
                    const parentRelation = document.getElementById('step3-parentRelation');

                    if (!parentName.value.trim() || !parentRut.value.trim() || 
                        !parentEmail.value.trim() || !parentRelation.value) {
                        isValid = false;
                    }
                }
            }

            return isValid;
        }

        window.nextStep = function() {
            if (!validateStep(currentStep)) {
                const errorMsg = document.getElementById('wizard-error-message');
                const errorText = document.getElementById('wizard-error-text');
                if (errorMsg && errorText) {
                    errorText.textContent = 'Por favor, complete todos los campos requeridos antes de continuar.';
                    errorMsg.classList.add('show');
                    setTimeout(() => errorMsg.classList.remove('show'), 4000);
                }
                return;
            }
            if (currentStep < totalSteps) {
                currentStep++;
                updateWizard();
                window.scrollTo({ top: wizardForm.offsetTop - 100, behavior: 'smooth' });
            }
        };

        window.prevStep = function() {
            if (currentStep > 1) {
                currentStep--;
                updateWizard();
                window.scrollTo({ top: wizardForm.offsetTop - 100, behavior: 'smooth' });
            }
        };

        window.toggleSensitiveFieldset = function() {
            const sensitive = document.getElementById('step2-sensitive');
            const fieldset = document.getElementById('sensitive-consent-fieldset');
            const noSpecialMessage = document.getElementById('no-special-consents-message');

            if (sensitive.value === 'si') {
                fieldset.style.display = 'block';
                noSpecialMessage.style.display = 'none';
            } else {
                fieldset.style.display = 'none';
                // Check if children fieldset is also hidden
                const childrenData = document.getElementById('step2-childrenData');
                if (childrenData.value === 'no') {
                    noSpecialMessage.style.display = 'block';
                }
            }
        };

        window.toggleChildrenFieldset = function() {
            const childrenData = document.getElementById('step2-childrenData');
            const fieldset = document.getElementById('children-consent-fieldset');
            const noSpecialMessage = document.getElementById('no-special-consents-message');

            if (childrenData.value === 'si') {
                fieldset.style.display = 'block';
                noSpecialMessage.style.display = 'none';
            } else {
                fieldset.style.display = 'none';
                // Check if sensitive fieldset is also hidden
                const sensitive = document.getElementById('step2-sensitive');
                if (sensitive.value === 'no') {
                    noSpecialMessage.style.display = 'block';
                }
            }
        };

        // Initialize wizard
        updateWizard();
    })();
    </script>
</div>

<?php
function renderSectionHeader($title, $desc, $pdfResource = null) {
    echo '<div class="compliance-section-header">';
    echo '<div><p class="workspace-kicker">Área de gestión</p><h2 class="compliance-section-title mt-1">' . h($title) . '</h2>';
    echo '<p class="compliance-section-desc">' . h($desc) . '</p></div>';
    if ($pdfResource) {
        echo '<div class="flex items-center gap-2">';
        echo '<button onclick="generateCompliancePDF(\'' . h($pdfResource) . '\')" class="inline-flex items-center gap-1.5 min-h-8 px-3 rounded-lg text-[10px] font-semibold bg-gradient-to-r from-blue-500 to-indigo-500 hover:from-blue-600 hover:to-indigo-600 text-white border border-blue-500/20 transition-all shadow-sm">';
        echo '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
        echo 'Descargar PDF';
        echo '</button>';
        echo '<span class="hidden md:inline-flex px-2.5 py-1.5 rounded-lg border border-border-theme bg-bg-panel text-[9px] font-semibold uppercase tracking-wider text-text-subtle">Evidencia y control</span>';
        echo '</div>';
    } else {
        echo '<span class="hidden md:inline-flex px-2.5 py-1.5 rounded-lg border border-border-theme bg-bg-panel text-[9px] font-semibold uppercase tracking-wider text-text-subtle">Evidencia y control</span>';
    }
    echo '</div>';
}

function renderComplianceList($items, $collection, $renderMain, $renderStatus = null) {
    if (empty($items)) {
        echo '<div class="compliance-empty"><strong>Sin registros</strong><span>Los registros creados en esta sección aparecerán aquí con su estado y acciones disponibles.</span></div>';
        return;
    }
    echo '<div class="space-y-2.5">';
    foreach ($items as $it) {
        echo '<div class="compliance-list-row p-4 flex flex-col md:flex-row md:items-center gap-3">';
        echo '<div class="flex-1 min-w-0">';
        $renderMain($it);
        echo '</div><div class="flex items-center gap-2 flex-shrink-0">';
        if ($renderStatus) $renderStatus($it);
        echo '<form method="POST" class="inline">';
        echo '<input type="hidden" name="collection" value="' . h($collection) . '">';
        echo '<input type="hidden" name="item_id" value="' . h($it['_id'] ?? '') . '">';
        echo '<button type="submit" name="delete_item" value="1" onclick="return confirm(\'¿Eliminar este registro?\')" class="inline-flex min-h-8 items-center px-2.5 rounded-lg text-[10px] font-semibold bg-transparent hover:bg-red-500/10 text-red-400 border border-red-500/20 transition-all">Eliminar</button>';
        echo '</form></div></div>';
    }
    echo '</div>';
}

function renderActionBtn($collection, $id, $action, $label) {
    echo '<form method="POST" class="inline">';
    echo '<input type="hidden" name="collection" value="' . h($collection) . '">';
    echo '<input type="hidden" name="item_id" value="' . h($id) . '">';
    echo '<button type="submit" name="item_action" value="' . h($action) . '" class="compliance-action">' . h($label) . '</button>';
    echo '</form>';
}

function renderImportBtn($collection) {
    echo '<button type="button" onclick="openBulkImport(\'' . h($collection) . '\')" title="Importación masiva"
        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.06] text-text-muted hover:text-text-body transition-all">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M16 8l-4-4m0 0L8 8m4-4v12"/></svg>
        Importar masivo
    </button>';
}

function renderComplianceStat($label, $value, $color = 'text-white', $icon = '') {
    echo '<div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3.5">';
    if ($icon) echo '<span class="text-text-subtle block mb-1.5">' . $icon . '</span>';
    echo '<p class="text-[9px] text-text-subtle uppercase tracking-wider mb-1">' . h($label) . '</p>';
    echo '<p class="text-[20px] font-bold leading-none tracking-tight ' . $color . '">' . h($value) . '</p>';
    echo '</div>';
}
?>

<!-- ═══ MODAL IMPORTACIÓN MASIVA ═══ -->
<div id="bulk-import-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-[60] p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
            <div>
                <h3 class="text-[14px] font-semibold text-white">Importación masiva</h3>
                <p class="text-[11px] text-text-muted mt-0.5" id="bulk-import-subtitle">Pega tus filas en formato CSV</p>
            </div>
            <button onclick="closeBulkImport()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <p class="text-[11px] text-text-muted mb-2 leading-relaxed">
                    La <b class="text-text-body">primera línea</b> debe contener los nombres de los campos y cada línea siguiente es un registro.
                    Acepta CSV o valores separados por tabulaciones. <b class="text-text-body">Ejemplo:</b>
                </p>
                <pre id="bulk-import-example" class="text-[10px] font-mono text-text-subtle bg-bg-base border border-border-theme rounded-lg p-3 overflow-x-auto whitespace-pre-wrap mb-2"></pre>
                <textarea id="bulk-import-data" rows="8" class="w-full input-premium font-mono text-[11px]" placeholder="campo1,campo2,campo3&#10;valor1,valor2,valor3"></textarea>
            </div>
            <button onclick="runBulkImport()" class="w-full px-4 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all inline-flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 17l3 3 3-3m-3 3V9"/></svg>
                Importar registros
            </button>
            <div id="bulk-import-result" class="hidden px-4 py-3 rounded-lg text-[11px]"></div>
        </div>
    </div>
</div>

<!-- ═══ MODAL ASIGNAR (firma ↔ capacitación) ═══ -->
<div id="assign-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm flex items-center justify-center z-[65] p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-md h-[80vh] flex flex-col shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-border-theme flex items-center justify-between flex-shrink-0">
            <div>
                <h3 id="assign-title" class="text-[14px] font-semibold text-white">Asignar</h3>
                <p id="assign-subtitle" class="text-[11px] text-text-muted mt-0.5">Selecciona un elemento</p>
            </div>
            <button onclick="closeAssignModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="px-4 pt-3 flex-shrink-0">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input id="assign-search" type="text" placeholder="Buscar..." class="w-full input-premium pl-9" oninput="filterAssignList()">
            </div>
        </div>
        <div class="flex flex-1 overflow-hidden pt-2">
            <div id="assign-list" class="flex-1 overflow-y-auto scrollbar-custom px-3 pb-4 space-y-0.5"></div>
            <div id="assign-alphabet" class="flex-shrink-0 overflow-y-auto py-2 px-1 pr-2 scrollbar-custom select-none"></div>
        </div>
    </div>
</div>

<script>
const IMPORT_EXAMPLES = {
    consents: 'name,email,purpose\nJuan Pérez,juan@empresa.cl,Envío de facturación\nMaría López,maria@empresa.cl,Atención al cliente',
    breaches: 'title,description,severity\nFuga de credenciales,Correo interno expuesto,high\nAcceso no autorizado,Intento de acceso al sistema,medium',
    dpia: 'name,description,riskLevel\nApp móvil clientes,Trazabilidad de geolocalización,high\nPortal de empleados,Acceso a datos de RRHH,medium',
    trainings: 'title,attendee,date\nLey 21.719 Básico,Juan Pérez,2026-08-01\nProtección de datos avanzado,María López,2026-08-15',
    inventory: 'name,purpose,dataCategories,legalBasis,risk,sensitive\nGestión de clientes,Facturación,nombres;emails;RUT,Consentimiento,low,1\nNómina de empleados,Riesgo laboral,salud;remuneraciones,Consentimiento,high,1',
    invites: 'title,description\nPolítica de Privacidad,Aceptación de la nueva política\nContrato de servicios,Aprobación del contrato'
};
let bulkCollection = '';

function openBulkImport(col) {
    bulkCollection = col;
    const labels = { consents:'Consentimientos', breaches:'Brechas', dpia:'Eval. Impacto', trainings:'Capacitaciones', inventory:'Inventario', invites:'Firmas' };
    document.getElementById('bulk-import-subtitle').textContent = 'Importación masiva a: ' + (labels[col] || col);
    document.getElementById('bulk-import-example').textContent = IMPORT_EXAMPLES[col] || '';
    document.getElementById('bulk-import-data').value = '';
    const r = document.getElementById('bulk-import-result');
    r.classList.add('hidden');
    const m = document.getElementById('bulk-import-modal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeBulkImport() {
    const m = document.getElementById('bulk-import-modal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function parseDelimited(text) {
    const sep = text.indexOf('\t') !== -1 && text.indexOf(',') === -1 ? '\t' : ',';
    const rows = []; let row = [], cur = '', inQ = false;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (inQ) {
            if (ch === '"') { if (text[i+1] === '"') { cur += '"'; i++; } else inQ = false; }
            else cur += ch;
        } else if (ch === '"') inQ = true;
        else if (ch === sep) { row.push(cur); cur = ''; }
        else if (ch === '\n') { row.push(cur); rows.push(row); row = []; cur = ''; }
        else if (ch !== '\r') cur += ch;
    }
    if (cur !== '' || row.length) { row.push(cur); rows.push(row); }
    return rows.filter(r => r.some(c => c.trim() !== ''));
}
function runBulkImport() {
    const text = document.getElementById('bulk-import-data').value.trim();
    const res = document.getElementById('bulk-import-result');
    res.classList.remove('hidden');
    if (!text) {
        res.className = 'px-4 py-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/30 text-red-400';
        res.textContent = 'Pega los datos primero.';
        return;
    }
    const rows = parseDelimited(text);
    if (rows.length < 2) {
        res.className = 'px-4 py-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/30 text-red-400';
        res.textContent = 'Se necesita al menos una línea de campos y una de datos.';
        return;
    }
    const headers = rows[0].map(h => h.trim());
    const items = rows.slice(1).map(r => {
        const o = {};
        headers.forEach((h, i) => { if (h) o[h] = (r[i] || '').trim(); });
        return o;
    });
    res.className = 'px-4 py-3 rounded-lg text-[11px] bg-blue-500/10 border border-blue-500/30 text-blue-400';
    res.textContent = 'Importando ' + items.length + ' registros...';
    fetch('/api-proxy.php?path=/api/compliance/' + encodeURIComponent(bulkCollection) + '/bulk', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items: items })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            res.className = 'px-4 py-3 rounded-lg text-[11px] bg-emerald-500/10 border border-emerald-500/30 text-emerald-400';
            res.textContent = (d.created || items.length) + ' registros importados correctamente.';
            setTimeout(() => location.reload(), 1200);
        } else {
            res.className = 'px-4 py-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/30 text-red-400';
            res.textContent = 'Error: ' + (d.error || 'no se pudo importar');
        }
    }).catch(() => {
        res.className = 'px-4 py-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/30 text-red-400';
        res.textContent = 'Error de conexión al importar.';
    });
}
document.addEventListener('click', function (e) {
    if (e.target.id === 'bulk-import-modal') closeBulkImport();
});
function copyInviteUrl(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;
    el.select();
    el.setSelectionRange(0, 99999);
    try { document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard) navigator.clipboard.writeText(el.value).catch(() => {});
    const s = btn.querySelector('span');
    if (s) { const old = s.textContent; s.textContent = 'Copiado'; setTimeout(() => { s.textContent = old; }, 1500); }
}

// Mostrar/ocultar fieldsets de consentimiento sensible y niños
document.addEventListener('DOMContentLoaded', function() {
    const sensitiveSelect = document.querySelector('select[name="fields[sensitive]"]');
    const childrenSelect = document.querySelector('select[name="fields[childrenData]"]');
    const sensitiveFieldset = document.getElementById('sensitive-consent-fieldset');
    const childrenFieldset = document.getElementById('children-consent-fieldset');

    function toggleSensitive() {
        if (sensitiveSelect && sensitiveFieldset) {
            sensitiveFieldset.style.display = sensitiveSelect.value === 'si' ? 'block' : 'none';
        }
    }
    function toggleChildren() {
        if (childrenSelect && childrenFieldset) {
            childrenFieldset.style.display = childrenSelect.value === 'si' ? 'block' : 'none';
        }
    }

    if (sensitiveSelect) sensitiveSelect.addEventListener('change', toggleSensitive);
    if (childrenSelect) childrenSelect.addEventListener('change', toggleChildren);

    // Inicializar
    toggleSensitive();
    toggleChildren();

    // Auto-formateo de RUT
    function formatRUT(value) {
        // Eliminar todos los caracteres no numéricos excepto K/k
        let rut = value.replace(/[^0-9kK]/g, '');

        if (rut.length === 0) return '';

        // Separar el dígito verificador
        let dv = rut.slice(-1);
        let cuerpo = rut.slice(0, -1);

        // Formatear el cuerpo con puntos
        if (cuerpo.length > 0) {
            cuerpo = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        // Unir cuerpo y dígito verificador
        return cuerpo + (cuerpo.length > 0 ? '-' : '') + dv;
    }

    // Aplicar auto-formateo a todos los campos de RUT
    const rutInputs = document.querySelectorAll('input[type="text"][pattern*="RUT"], input[id*="rut"], input[name*="rut"]');
    rutInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            const cursorPos = this.selectionStart;
            const oldLength = this.value.length;

            this.value = formatRUT(this.value);

            // Ajustar la posición del cursor
            const newLength = this.value.length;
            const cursorOffset = newLength - oldLength;
            this.setSelectionRange(cursorPos + cursorOffset, cursorPos + cursorOffset);
        });

        // Formatear al perder el foco
        input.addEventListener('blur', function() {
            this.value = formatRUT(this.value);
        });
    });

    // Validar select multiple de categorías de datos
    const consentForm = document.querySelector('form input[name="collection"][value="consents"]')?.closest('form');
    if (consentForm) {
        consentForm.addEventListener('submit', function(e) {
            const dataCategoriesSelect = this.querySelector('select[name="fields[dataCategories]"]');
            if (dataCategoriesSelect) {
                const selectedOptions = Array.from(dataCategoriesSelect.selectedOptions).filter(opt => opt.selected);
                if (selectedOptions.length === 0) {
                    e.preventDefault();
                    alert('Por favor, selecciona al menos una categoría de datos (usa Ctrl+Click para seleccionar múltiples)');
                    dataCategoriesSelect.focus();
                    return;
                }
            }

            // Validar checkboxes de datos sensibles si el fieldset está visible
            const sensitiveSelect = this.querySelector('select[name="fields[sensitive]"]');
            const sensitiveFieldset = document.getElementById('sensitive-consent-fieldset');
            if (sensitiveSelect && sensitiveFieldset && sensitiveSelect.value === 'si') {
                const sensitiveExplicit = document.getElementById('sensitiveExplicit');
                const sensitiveInformed = document.getElementById('sensitiveInformed');
                const sensitiveSeparate = document.getElementById('sensitiveSeparate');

                if (!sensitiveExplicit.checked || !sensitiveInformed.checked || !sensitiveSeparate.checked) {
                    e.preventDefault();
                    alert('Para datos sensibles, debes marcar todas las confirmaciones del consentimiento (Art. 16)');
                    return;
                }
            }

            // Validar checkboxes de datos de niños si el fieldset está visible
            const childrenSelect = this.querySelector('select[name="fields[childrenData]"]');
            const childrenFieldset = document.getElementById('children-consent-fieldset');
            if (childrenSelect && childrenFieldset && childrenSelect.value === 'si') {
                const parentExplicit = document.getElementById('parentExplicit');
                const parentBestInterest = document.getElementById('parentBestInterest');
                const parentInformed = document.getElementById('parentInformed');

                if (!parentExplicit.checked || !parentBestInterest.checked || !parentInformed.checked) {
                    e.preventDefault();
                    alert('Para datos de niños/niñas/adolescentes, debes marcar todas las confirmaciones del consentimiento del representante legal (Art. 17)');
                    return;
                }
            }
        });
    }

    // Validar select multiple del formulario de inventario (RAT)
    const inventoryForm = document.querySelector('form input[name="collection"][value="inventory"]')?.closest('form');
    if (inventoryForm) {
        inventoryForm.addEventListener('submit', function(e) {
            const dataCategoriesSelect = this.querySelector('select[name="dataCategories[]"]');
            const subjectCategoriesSelect = this.querySelector('select[name="subjectCategories[]"]');

            if (dataCategoriesSelect) {
                const selectedOptions = Array.from(dataCategoriesSelect.selectedOptions).filter(opt => opt.selected);
                if (selectedOptions.length === 0) {
                    e.preventDefault();
                    alert('Por favor, selecciona al menos una categoría de datos (usa Ctrl+Click para seleccionar múltiples)');
                    dataCategoriesSelect.focus();
                    return;
                }
            }

            if (subjectCategoriesSelect) {
                const selectedOptions = Array.from(subjectCategoriesSelect.selectedOptions).filter(opt => opt.selected);
                if (selectedOptions.length === 0) {
                    e.preventDefault();
                    alert('Por favor, selecciona al menos una categoría de titulares (usa Ctrl+Click para seleccionar múltiples)');
                    subjectCategoriesSelect.focus();
                    return;
                }
            }
        });
    }

    // Validar select multiple del formulario de brechas
    const breachForm = document.querySelector('form input[name="collection"][value="breaches"]')?.closest('form');
    if (breachForm) {
        breachForm.addEventListener('submit', function(e) {
            const affectedCategoriesSelect = this.querySelector('select[name="fields[affectedCategories][]"]');
            if (affectedCategoriesSelect) {
                const selectedOptions = Array.from(affectedCategoriesSelect.selectedOptions).filter(opt => opt.selected);
                if (selectedOptions.length === 0) {
                    e.preventDefault();
                    alert('Por favor, selecciona al menos una categoría de datos afectados (usa Ctrl+Click para seleccionar múltiples)');
                    affectedCategoriesSelect.focus();
                }
            }
        });
    }
});

async function createTrainingInvite(trainingId) {
    if (!confirm('¿Crear invitación de firma para esta capacitación? Esto generará un enlace para firmar manualmente.')) return;
    
    try {
        const res = await fetch('/api-proxy.php?path=/api/invisia/auto-sign-training', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ trainingId: trainingId })
        });
        
        const data = await res.json();
        
        if (data.success) {
            alert('Invitación de firma creada. Usa el botón "Abrir" para firmar manualmente.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'No se pudo crear la invitación'));
        }
    } catch (e) {
        alert('Error al crear la invitación: ' + e.message);
    }
}

async function deleteTraining(trainingId) {
    if (!confirm('¿Eliminar esta capacitación? Esta acción no se puede deshacer.')) return;
    
    try {
        const res = await fetch('/api-proxy.php?path=/api/compliance/trainings/' + encodeURIComponent(trainingId), {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' }
        });
        
        const data = await res.json();
        
        if (data.success) {
            alert('Capacitación eliminada exitosamente');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'No se pudo eliminar la capacitación'));
        }
    } catch (e) {
        alert('Error al eliminar: ' + e.message);
    }
}

// ═══ PDF Generation Functions ═══
async function generateCompliancePDF(resource) {
    const token = '<?= $token ?>';
    
    // Encontrar el botón que disparó el evento
    let btn = event?.target?.closest('button');
    
    // Si no se encontró el botón mediante el evento, buscarlo manualmente
    if (!btn) {
        const buttons = document.querySelectorAll('button[onclick*="generateCompliancePDF"]');
        buttons.forEach(button => {
            if (button.getAttribute('onclick')?.includes(resource)) {
                btn = button;
            }
        });
    }
    
    if (!btn) {
        alert('Error: No se pudo encontrar el botón de descarga');
        return;
    }
    
    const originalText = btn.innerHTML;
    
    try {
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Generando...';
        
        const response = await fetch(`/api/compliance/${resource}/pdf?token=${encodeURIComponent(token)}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (data.pdfUrl && data.pdfUrl !== null && data.pdfUrl !== '') {
                // Open PDF in new tab
                window.open(data.pdfUrl, '_blank');
                alert('PDF generado exitosamente');
            } else if (data.html && data.html.trim().length > 100) {
                // Download HTML as file for printing
                const blob = new Blob([data.html], { type: 'text/html' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'certificado-compliance-' + resource + '-' + new Date().toISOString().slice(0,10) + '.html';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                alert('Documento HTML descargado. Ábrelo en tu navegador para imprimirlo como PDF.');
            } else {
                alert('Error: No se pudo generar el documento. No hay contenido disponible.');
            }
        } else {
            alert('Error al generar PDF: ' + (data.error || 'Error desconocido'));
        }
    } catch (error) {
        console.error('Error generating PDF:', error);
        console.error('Error details:', error.message, error.stack);
        alert('Error al generar PDF: ' + error.message);
        
        // Si el error es por no encontrar el botón, intentar continuar sin modificarlo
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}
</script>

<script>
// ═══ Asignación firma ↔ capacitación ═══
const TRAININGS_ALL = <?= json_encode($trainings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const SIGNED_INVITES_ALL = <?= json_encode($signedInvites, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let assignCtx = { type: 'firma', inviteId: '', trainingId: '' };
let assignBase = [];

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

function openAssignFirma(inviteId) {
    assignCtx = { type: 'firma', inviteId };
    document.getElementById('assign-title').textContent = 'Asignar firma';
    document.getElementById('assign-subtitle').textContent = 'Selecciona la capacitación para asignar esta firma';
    document.getElementById('assign-search').value = '';
    assignBase = (TRAININGS_ALL || []).slice().sort(function (a, b) { return String(a.title || '').localeCompare(String(b.title || ''), 'es'); });
    openAssignModal();
}
function openAssignCapacitacion(trainingId) {
    assignCtx = { type: 'capacitacion', trainingId };
    document.getElementById('assign-title').textContent = 'Asignar capacitación';
    document.getElementById('assign-subtitle').textContent = 'Selecciona la firma firmada para asignar a esta capacitación';
    document.getElementById('assign-search').value = '';
    assignBase = (SIGNED_INVITES_ALL || []).slice().sort(function (a, b) { return String(a.title || '').localeCompare(String(b.title || ''), 'es'); });
    openAssignModal();
}
function openAssignModal() {
    renderAssignList();
    renderAssignAlphabet();
    const m = document.getElementById('assign-modal');
    m.classList.remove('hidden');
}
function closeAssignModal() {
    document.getElementById('assign-modal').classList.add('hidden');
}
function filterAssignList() {
    const q = document.getElementById('assign-search').value.trim().toLowerCase();
    if (!q) { renderAssignList(); renderAssignAlphabet(); return; }
    const list = assignBase.filter(function (it) {
        return (it.title || '').toLowerCase().indexOf(q) !== -1 ||
               (it.attendee || '').toLowerCase().indexOf(q) !== -1 ||
               (it.signerName || '').toLowerCase().indexOf(q) !== -1;
    });
    renderAssignList(list);
    renderAssignAlphabet(list);
}
function firstLetter(s) {
    const c = String(s || '#').trim().charAt(0).toUpperCase();
    return /^[A-ZÑ]$/.test(c) ? c : '#';
}
function renderAssignList(customItems) {
    const items = customItems || assignBase;
    const list = document.getElementById('assign-list');
    if (!items.length) { list.innerHTML = '<p class="text-[11px] text-text-subtle text-center py-8">Sin resultados</p>'; return; }
    const groups = {};
    items.forEach(function (it) {
        const L = firstLetter(it.title);
        (groups[L] = groups[L] || []).push(it);
    });
    const letters = Object.keys(groups).sort(function (a, b) { return a.localeCompare(b, 'es'); });
    list.innerHTML = letters.map(function (L) {
        return '<div id="assign-letter-' + L + '" class="assign-letter-group">' +
            '<div class="sticky top-0 z-10 bg-bg-panel/95 backdrop-blur px-3 py-1 text-[10px] font-bold text-indigo-400 uppercase tracking-widest">' + L + '</div>' +
            groups[L].map(assignItemRow).join('') +
            '</div>';
    }).join('');
}
function assignItemRow(it) {
    const title = it.title || 'Sin título';
    const sub = assignCtx.type === 'firma' ? (it.attendee || '') : ('Firmado por ' + (it.signerName || '-'));
    return '<button type="button" onclick="doAssign(\'' + it._id + '\')" class="w-full text-left px-3 py-2 rounded-lg hover:bg-bg-elevated transition-all flex items-center gap-2.5">' +
        '<span class="w-7 h-7 rounded-full flex items-center justify-center text-[11px] font-bold bg-indigo-500/15 text-indigo-400 flex-shrink-0">' + esc(firstLetter(title)) + '</span>' +
        '<span class="min-w-0">' +
            '<span class="block text-[12px] font-medium text-text-heading truncate">' + esc(title) + '</span>' +
            '<span class="block text-[10px] text-text-muted truncate">' + esc(sub) + '</span>' +
        '</span>' +
    '</button>';
}
function renderAssignAlphabet(customItems) {
    const items = customItems || assignBase;
    const idx = document.getElementById('assign-alphabet');
    const present = {};
    items.forEach(function (it) { present[firstLetter(it.title)] = true; });
    idx.innerHTML = 'ABCDEFGHIJKLMNÑOPQRSTUVWXYZ'.split('').map(function (L) {
        const active = present[L];
        return '<button type="button" onclick="jumpToLetter(\'' + L + '\')" class="block w-5 h-4 text-[9px] font-semibold text-center transition-colors ' + (active ? 'text-indigo-400 hover:text-indigo-300' : 'text-text-subtle/40 hover:text-text-subtle') + '">' + L + '</button>';
    }).join('');
}
function jumpToLetter(L) {
    const groups = document.querySelectorAll('#assign-list .assign-letter-group');
    const wanted = 'assign-letter-' + L;
    for (const g of groups) {
        if (g.id === wanted) { g.scrollIntoView({ block: 'start', behavior: 'smooth' }); return; }
    }
    const letters = Array.from(groups).map(g => g.id.replace('assign-letter-', '')).filter(x => x > L).sort();
    if (letters.length) document.getElementById('assign-letter-' + letters[0]).scrollIntoView({ block: 'start', behavior: 'smooth' });
}
function doAssign(targetId) {
    const inviteId = assignCtx.type === 'firma' ? assignCtx.inviteId : targetId;
    const trainingId = assignCtx.type === 'capacitacion' ? assignCtx.trainingId : targetId;
    if (!inviteId || !trainingId) return;
    fetch('/api-proxy.php?path=/api/compliance/invites/' + encodeURIComponent(inviteId) + '/assign-training', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ trainingId: trainingId })
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.success) {
            closeAssignModal();
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'no se pudo asignar'));
        }
    }).catch(function () { alert('Error de conexión al asignar'); });
}
document.getElementById('assign-modal').addEventListener('click', function (e) {
    if (e.target.id === 'assign-modal') closeAssignModal();
});

// ═══ DPIA WIZARD ═══
(function() {
    const wizardForm = document.getElementById('dpia-wizard-form');
    if (!wizardForm) return;

    let currentStep = 1;
    const totalSteps = 2;

    const stepText = document.querySelector('.dpia-wizard-step-text');
    const stepPercentage = document.querySelector('.dpia-wizard-percentage');
    const progressFill = document.querySelector('.dpia-wizard-progress-fill');
    const prevBtn = document.getElementById('dpia-prev-btn');
    const nextBtn = document.getElementById('dpia-next-btn');
    const submitBtn = document.getElementById('dpia-submit-btn');

    function updateWizard() {
        // Update progress text
        stepText.textContent = 'Paso ' + currentStep + ' de ' + totalSteps;
        stepPercentage.textContent = Math.round((currentStep / totalSteps) * 100) + '%';
        progressFill.style.width = (currentStep / totalSteps) * 100 + '%';

        // Update step indicators
        for (let i = 1; i <= totalSteps; i++) {
            const indicator = document.querySelector('.dpia-step-' + i);
            const stepNumber = indicator.querySelector('.dpia-step-number');
            const stepLabel = indicator.querySelector('.dpia-step-label');

            indicator.classList.remove('active', 'completed');
            stepNumber.classList.remove('bg-accent', 'text-white', 'bg-emerald-500', 'text-white');
            stepNumber.classList.add('bg-bg-elevated', 'text-text-subtle');
            stepLabel.classList.remove('text-text-heading');
            stepLabel.classList.add('text-text-subtle');

            if (i < currentStep) {
                indicator.classList.add('completed');
                stepNumber.classList.remove('bg-bg-elevated', 'text-text-subtle');
                stepNumber.classList.add('bg-emerald-500', 'text-white');
                stepLabel.classList.remove('text-text-subtle');
                stepLabel.classList.add('text-text-heading');
                stepNumber.innerHTML = '✓';
            } else if (i === currentStep) {
                indicator.classList.add('active');
                stepNumber.classList.remove('bg-bg-elevated', 'text-text-subtle');
                stepNumber.classList.add('bg-accent', 'text-white');
                stepLabel.classList.remove('text-text-subtle');
                stepLabel.classList.add('text-text-heading');
                stepNumber.textContent = i;
            } else {
                stepNumber.textContent = i;
            }
        }

        // Show/hide steps
        document.querySelectorAll('.dpia-wizard-step').forEach(function(step) {
            if (parseInt(step.dataset.step) === currentStep) {
                step.classList.remove('hidden');
            } else {
                step.classList.add('hidden');
            }
        });

        // Update buttons
        prevBtn.classList.toggle('hidden', currentStep === 1);
        nextBtn.classList.toggle('hidden', currentStep === totalSteps);
        submitBtn.classList.toggle('hidden', currentStep !== totalSteps);
    }

    function validateStep(step) {
        const stepContent = document.querySelector('.dpia-step-' + step + '-content');
        const requiredFields = stepContent.querySelectorAll('[required]');
        let valid = true;

        requiredFields.forEach(function(field) {
            if (!field.value.trim()) {
                valid = false;
                field.style.borderColor = '#ef4444';
                field.addEventListener('input', function() {
                    field.style.borderColor = '';
                }, { once: true });
            }
        });

        return valid;
    }

    nextBtn.addEventListener('click', function() {
        if (!validateStep(currentStep)) {
            alert('Por favor completa los campos requeridos antes de continuar.');
            return;
        }
        if (currentStep < totalSteps) {
            currentStep++;
            updateWizard();
        }
    });

    prevBtn.addEventListener('click', function() {
        if (currentStep > 1) {
            currentStep--;
            updateWizard();
        }
    });

    // Initialize wizard
    updateWizard();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>