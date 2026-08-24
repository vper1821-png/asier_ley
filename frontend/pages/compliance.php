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
$allInvites = $fetchList('invites');
$signedInvites = array_values(array_filter($allInvites, fn($i) => !empty($i['signed'])));

$items = [];
if (!in_array($tab, ['overview', 'violations'])) {
    $items = $fetchList($tab);
}

// ── Checklist (mismo criterio que React) ──
$CHECKLIST = [
    ['id' => 'dpd', 'label' => 'DPD Designado', 'desc' => 'Delegado de Protección de Datos (Art. 28)', 'icon' => 'users', 'done' => !empty($config['dpdEmail'])],
    ['id' => 'apdp', 'label' => 'Registro APDP', 'desc' => 'Registro ante Agencia de Protección de Datos (Art. 31)', 'icon' => 'shield', 'done' => ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true)],
    ['id' => 'inventory', 'label' => 'Inventario de Datos', 'desc' => 'Inventario de datos personales (Art. 15)', 'icon' => 'database', 'done' => count($inventory) > 0],
    ['id' => 'privacy', 'label' => 'Política de Privacidad', 'desc' => 'Política actualizada y accesible (Art. 14)', 'icon' => 'fileText', 'done' => !empty($config['privacyPolicyUrl'])],
    ['id' => 'consents', 'label' => 'Consentimientos', 'desc' => 'Mecanismo de consentimiento explícito (Art. 12)', 'icon' => 'check', 'done' => count($consents) > 0],
    ['id' => 'breach_protocol', 'label' => 'Protocolo de Brechas', 'desc' => 'Procedimiento de notificación (Art. 26)', 'icon' => 'alert', 'done' => count($breaches) > 0],
    ['id' => 'arco', 'label' => 'Portal ARCO', 'desc' => 'Derechos Acceso, Rectificación, Cancelación, Oposición + Portabilidad', 'icon' => 'users', 'done' => true],
    ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'desc' => 'Reemplazo de identificadores directos por seudónimos (Art. 30)', 'icon' => 'search', 'done' => count($pseudoRules) > 0],
    ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'desc' => 'Procedimiento documentado para brechas de seguridad (Art. 26)', 'icon' => 'alert', 'done' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0],
    ['id' => 'training', 'label' => 'Capacitación', 'desc' => 'Programa de formación en protección de datos', 'icon' => 'info', 'done' => count($trainings) > 0],
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

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header (igual a React) -->
        <header class="flex-shrink-0 bg-bg-base border-b border-white/[0.04]">
            <div class="w-full px-4 md:px-8 pt-4 pb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center flex-shrink-0">
                        <?= cIcon('shield', 'w-5 h-5') ?>
                    </div>
                    <div>
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider font-medium">Cumplimiento normativo · Ley 21.719</p>
                        <h1 class="text-[18px] md:text-[20px] font-bold text-text-heading tracking-tight"><?= h($activeLabel) ?></h1>
                    </div>
                </div>
            </div>
            <div class="w-full px-4 md:px-8 pb-0">
                <nav class="flex gap-1 -mb-px overflow-x-auto">
                    <?php foreach ($tabs as $t): $isActive = $tab === $t['id']; ?>
                    <a href="/compliance?tab=<?= $t['id'] ?>"
                        class="flex items-center gap-1.5 px-3 py-2.5 text-[11px] font-medium border-b-2 transition-colors whitespace-nowrap <?= $isActive ? 'border-accent text-text-heading' : 'border-transparent text-text-muted hover:text-text-body hover:border-white/[0.1]' ?>">
                        <span class="<?= $isActive ? 'text-accent' : 'text-text-subtle' ?>"><?= cIcon($t['icon']) ?></span>
                        <?= h($t['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto scrollbar-custom tour-detail-1">
            <div class="p-4 md:p-8 w-full space-y-4 md:space-y-6">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <?php if ($tab === 'overview'): ?>
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
                        <p class="text-[12px] text-text-muted mt-1">Requisitos legales obligatorios para la protección de datos personales</p>
                    </div>
                    <span class="text-[32px] font-bold leading-none flex-shrink-0 <?= $pctColor ?>"><?= $checklistPct ?>%</span>
                </div>
                <div class="w-full bg-bg-elevated/50 rounded-full h-2.5 mb-6">
                    <div class="h-full rounded-full transition-all duration-700 <?= $pctBar ?>" style="width: <?= $checklistPct ?>%"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
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
            <?php renderSectionHeader('Consentimientos', 'Gestión de consentimientos de titulares de datos — Art. 12 de la Ley 21.719'); ?>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('check')); ?>
                <?php renderComplianceStat('Activos', $cActive, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Revocados', $cRevoked, 'text-red-400', cIcon('xmark')); ?>
            </div>

            <!-- Formulario profesional de consentimiento (Art. 12 Ley 21.719) -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Registrar consentimiento (Art. 12 - Libre, expreso, informado, específico)
                    </p>
                    <?php renderImportBtn('consents'); ?>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="consents">

                    <!-- Identificación del titular -->
                    <fieldset class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-emerald-300 px-2">Identificación del Titular (Art. 12.1)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Nombre completo *</label>
                                <input type="text" name="fields[name]" required class="input-premium w-full" placeholder="Juan Pérez González">
                            </div>
                            <div>
                                <label class="label-premium">RUT *</label>
                                <input type="text" id="rut-consent" name="fields[rut]" required class="input-premium w-full" placeholder="12.345.678-9" pattern="[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]{1}">
                            </div>
                            <div>
                                <label class="label-premium">Email *</label>
                                <input type="email" name="fields[email]" required class="input-premium w-full" placeholder="juan.perez@ejemplo.cl">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Teléfono</label>
                                <input type="tel" name="fields[phone]" class="input-premium w-full" placeholder="+56 9 1234 5678">
                            </div>
                            <div>
                                <label class="label-premium">Dirección</label>
                                <input type="text" name="fields[address]" class="input-premium w-full" placeholder="Calle 123, Santiago">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Información del tratamiento (Art. 12.2) -->
                    <fieldset class="rounded-lg border border-blue-500/20 bg-blue-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-blue-300 px-2">Información del Tratamiento (Art. 12.2)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Finalidad específica *</label>
                                <select name="fields[purpose]" required class="input-premium w-full">
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
                            <div>
                                <label class="label-premium">Base legal (Ley 21.719) *</label>
                                <select name="fields[legalBasis]" required class="input-premium w-full">
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
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Categorías de datos *</label>
                                <select name="fields[dataCategories]" multiple class="input-premium w-full" size="4">
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
                                <p class="text-[10px] text-text-muted mt-1">Ctrl+Click para seleccionar múltiples</p>
                            </div>
                            <div>
                                <label class="label-premium">¿Incluye datos sensibles? (Art. 16)</label>
                                <select name="fields[sensitive]" class="input-premium w-full">
                                    <option value="no">No</option>
                                    <option value="si">Sí - Requiere consentimiento explícito reforzado</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">¿Incluye datos de niños/niñas/adolescentes? (Art. 17)</label>
                                <select name="fields[childrenData]" class="input-premium w-full">
                                    <option value="no">No</option>
                                    <option value="si">Sí - Requiere consentimiento representante legal</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Consentimiento explícito reforzado para datos sensibles (Art. 16) -->
                    <fieldset class="rounded-lg border border-red-500/20 bg-red-500/[0.02] p-4" id="sensitive-consent-fieldset" style="display: none;">
                        <legend class="text-[11px] font-medium text-red-300 px-2">Consentimiento Explícito Reforzado - Datos Sensibles (Art. 16 Ley 21.719)</legend>
                        <div class="bg-red-500/[0.05] border border-red-500/20 rounded-lg p-3 mb-3 text-[10px] text-text-body">
                            <p class="font-semibold text-red-300 mb-1">Art. 16: Tratamiento de datos sensibles requiere consentimiento EXPLÍCITO, LIBRE, INFORMADO, ESPECÍFICO e INEQUÍVOCO.</p>
                            <p>Categorías sensibles: origen racial/étnico, opiniones políticas, convicciones religiosas/filosóficas, afiliación sindical, datos genéticos, biométricos, salud, vida sexual.</p>
                        </div>
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[sensitiveExplicit]" id="sensitiveExplicit" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="sensitiveExplicit" class="text-[11px] text-text-body">El titular ha dado consentimiento <strong>explícito y por escrito</strong> para cada categoría de dato sensible</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[sensitiveInformed]" id="sensitiveInformed" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="sensitiveInformed" class="text-[11px] text-text-body">El titular ha sido informado de la <strong>naturaleza de los datos sensibles</strong>, los <strong>riesgos específicos</strong> y el <strong>derecho a revocar en cualquier momento</strong></label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[sensitiveSeparate]" id="sensitiveSeparate" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="sensitiveSeparate" class="text-[11px] text-text-body">El consentimiento sensible se obtuvo <strong>de forma separada</strong> de otros consentimientos (no bundled)</label>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Consentimiento parental para datos de niños (Art. 17) -->
                    <fieldset class="rounded-lg border border-pink-500/20 bg-pink-500/[0.02] p-4" id="children-consent-fieldset" style="display: none;">
                        <legend class="text-[11px] font-medium text-pink-300 px-2">Consentimiento de Representante Legal - Datos de Niños (Art. 17 Ley 21.719)</legend>
                        <div class="bg-pink-500/[0.05] border border-pink-500/20 rounded-lg p-3 mb-3 text-[10px] text-text-body">
                            <p class="font-semibold text-pink-300 mb-1">Art. 17: Tratamiento de datos de niños/niñas/adolescentes requiere consentimiento del TITULAR DE LA PATRIA POTESTAD o representante legal.</p>
                            <p>Debe respetarse el interés superior del niño y su autonomía progresiva.</p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Nombre del representante legal *</label>
                                <input type="text" name="fields[parentName]" class="input-premium w-full" placeholder="Nombre completo del padre/madre/tutor">
                            </div>
                            <div>
                                <label class="label-premium">RUT del representante legal *</label>
                                <input type="text" id="rut-parent" name="fields[parentRut]" class="input-premium w-full" placeholder="12.345.678-9">
                            </div>
                            <div>
                                <label class="label-premium">Email del representante legal *</label>
                                <input type="email" name="fields[parentEmail]" class="input-premium w-full" placeholder="padre@ejemplo.cl">
                            </div>
                            <div>
                                <label class="label-premium">Relación con el niño *</label>
                                <select name="fields[parentRelation]" class="input-premium w-full">
                                    <option value="">Seleccionar</option>
                                    <option value="padre">Padre</option>
                                    <option value="madre">Madre</option>
                                    <option value="tutor">Tutor legal</option>
                                    <option value="otro">Otro representante legal</option>
                                </select>
                            </div>
                        </div>
                        <div class="space-y-2 mt-3">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[parentExplicit]" id="parentExplicit" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="parentExplicit" class="text-[11px] text-text-body">El representante legal ha dado consentimiento <strong>explícito e informado</strong> para el tratamiento de datos del niño</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[parentBestInterest]" id="parentBestInterest" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="parentBestInterest" class="text-[11px] text-text-body">Se ha considerado el <strong>interés superior del niño</strong> y su <strong>autonomía progresiva</strong> según edad y madurez</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[parentInformed]" id="parentInformed" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="parentInformed" class="text-[11px] text-text-body">El representante ha sido informado de los <strong>derechos ARCO del niño</strong> y del <strong>derecho a revocar en cualquier momento</strong></label>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Derechos ARCO y revocación -->
                    <fieldset class="rounded-lg border border-amber-500/20 bg-amber-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-amber-300 px-2">Derechos del Titular y Vigencia</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Fecha inicio *</label>
                                <input type="date" name="fields[startDate]" required class="input-premium w-full" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="label-premium">Fecha fin / Vigencia</label>
                                <input type="date" name="fields[endDate]" class="input-premium w-full" placeholder="Opcional - indefinido si vacío">
                            </div>
                            <div>
                                <label class="label-premium">Método de obtención *</label>
                                <select name="fields[method]" required class="input-premium w-full">
                                    <option value="formulario_web">Formulario web</option>
                                    <option value="formulario_papel">Formulario papel</option>
                                    <option value="contrato">En contrato</option>
                                    <option value="verbal_grabado">Verbal grabado</option>
                                    <option value="opt_in">Opt-in (casilla de verificación)</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[arcoInformed]" id="arcoInformed" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="arcoInformed" class="text-[11px] text-text-body">Titular informado de derechos ARCO + Portabilidad (Art. 8-13)</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="fields[revocationInformed]" id="revocationInformed" value="1" class="w-4 h-4 rounded border-border-theme text-primary-600 focus:ring-primary-500">
                                <label for="revocationInformed" class="text-[11px] text-text-body">Titular informado de derecho a revocar consentimiento (Art. 12.3)</label>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Responsable y DPD -->
                    <fieldset class="rounded-lg border border-purple-500/20 bg-purple-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-purple-300 px-2">Responsable del Tratamiento y DPD (Art. 28)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Responsable (Empresa) *</label>
                                <input type="text" name="fields[controllerName]" required class="input-premium w-full" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Nombre de la empresa responsable">
                            </div>
                            <div>
                                <label class="label-premium">DPD (Delegado Protección Datos)</label>
                                <input type="text" name="fields[dpdName]" class="input-premium w-full" value="<?= h($config['dpdName'] ?? '') ?>" placeholder="Nombre del DPD">
                            </div>
                            <div>
                                <label class="label-premium">Contacto DPD</label>
                                <input type="email" name="fields[dpdEmail]" class="input-premium w-full" value="<?= h($config['dpdEmail'] ?? '') ?>" placeholder="dpd@empresa.cl">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Observaciones y evidencia -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="label-premium">Observaciones / Contexto</label>
                            <textarea name="fields[notes]" rows="3" class="input-premium w-full" placeholder="Contexto adicional, canal de obtención, observaciones legales..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">URL de evidencia (formulario, contrato, grabación)</label>
                            <input type="url" name="fields[evidenceUrl]" class="input-premium w-full" placeholder="https://empresa.cl/consentimiento-juan-perez">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <input type="hidden" name="fields[active]" value="1">
                        <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                        <button type="submit" name="create_item" value="1" class="px-4 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar consentimiento conforme Art. 12
                        </button>
                        <span class="text-[10px] text-text-muted">Este registro cumple requisitos Art. 12: libre, expreso, informado, específico e inequívoco</span>
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
                'Este inventario es obligatorio según el Art. 14 de la Ley 21.719.'
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
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
                        Nueva actividad de tratamiento (RAT - Art. 14 Ley 21.719)
                    </p>
                    <button onclick="document.getElementById('inventory-create-form').classList.add('hidden')" class="text-text-muted hover:text-text-heading">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="bg-indigo-500/[0.05] border border-indigo-500/20 rounded-lg p-3.5 mb-4 text-[10px] text-text-muted leading-relaxed flex gap-2.5">
                    <svg class="w-4 h-4 text-indigo-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p><span class="text-indigo-300 font-semibold">¿Qué es una actividad de tratamiento?</span></p>
                        <p>Toda operación que realices con datos personales: recopilar, almacenar, usar, modificar, compartir o eliminar.
                           Cada actividad debe registrarse con su finalidad, base legal, categorías de datos, destinatarios y medidas de seguridad.</p>
                        <p class="mt-1"><span class="text-indigo-300 font-semibold">Art. 14 Ley 21.719:</span> El responsable debe mantener un registro documentado (RAT) de todas las actividades de tratamiento.</p>
                    </div>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="create_inventory_item" value="1">

                    <!-- Identificación básica -->
                    <fieldset class="rounded-lg border border-cyan-500/20 bg-cyan-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-cyan-300 px-2">Identificación de la Actividad (Art. 14.1.a)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Nombre de la actividad *</label>
                                <input type="text" name="name" required class="input-premium w-full" placeholder="Ej: Gestión de clientes y facturación">
                                <p class="text-[8px] text-text-subtle mt-0.5">Nombre descriptivo único de la actividad de tratamiento.</p>
                            </div>
                            <div>
                                <label class="label-premium">Código / Referencia</label>
                                <input type="text" name="code" class="input-premium w-full" placeholder="Ej: RAT-001">
                                <p class="text-[8px] text-text-subtle mt-0.5">Código interno para trazabilidad.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Responsable del tratamiento *</label>
                                <input type="text" name="controllerName" required class="input-premium w-full" value="<?= h($config['companyName'] ?? '') ?>" placeholder="Nombre empresa/organización">
                            </div>
                            <div>
                                <label class="label-premium">Encargado del tratamiento (si aplica)</label>
                                <input type="text" name="processorName" class="input-premium w-full" placeholder="Proveedor cloud, SaaS, etc.">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Finalidad y base legal -->
                    <fieldset class="rounded-lg border border-blue-500/20 bg-blue-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-blue-300 px-2">Finalidad y Base de Licitud (Art. 14.1.b / Art. 12-13)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Finalidad específica *</label>
                                <select name="purpose" required class="input-premium w-full">
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
                                <p class="text-[8px] text-text-subtle mt-0.5">Finalidad específica, explícita y legítima (Art. 3 letra b).</p>
                            </div>
                            <div>
                                <label class="label-premium">Base de licitud *</label>
                                <select name="legalBasis" required class="input-premium w-full">
                                    <option value="">Seleccionar base legal</option>
                                    <option value="consentimiento">Art. 12 - Consentimiento del titular</option>
                                    <option value="ejecucion_contrato">Art. 13.1.a - Ejecución de contrato</option>
                                    <option value="obligacion_legal">Art. 13.1.b - Obligación legal</option>
                                    <option value="interes_vital">Art. 13.1.c - Interés vital</option>
                                    <option value="interes_publico">Art. 13.1.d - Interés público</option>
                                    <option value="interes_legitimo">Art. 13.1.e - Interés legítimo</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Sin base legal válida, el tratamiento es ilícito (Art. 11).</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">¿Interés legítimo? (si aplica Art. 13.1.e)</label>
                                <textarea name="legitimateInterest" rows="2" class="input-premium w-full" placeholder="Describe el interés legítimo y la ponderación realizada..."></textarea>
                                <p class="text-[8px] text-text-subtle mt-0.5">Obligatorio si base legal = Interés legítimo. Debe documentarse la ponderación.</p>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Categorías de datos y sujetos -->
                    <fieldset class="rounded-lg border border-amber-500/20 bg-amber-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-amber-300 px-2">Categorías de Datos y Titulares (Art. 14.1.c / Art. 15-16)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="md:col-span-2">
                                <label class="label-premium">Categorías de datos personales *</label>
                                <select name="dataCategories[]" multiple class="input-premium w-full" size="6">
                                    <option value="identificacion">Identificación: nombre, RUT, dirección, nacionalidad</option>
                                    <option value="contacto">Contacto: email, teléfono, redes sociales</option>
                                    <option value="financieros">Financieros: cuentas bancarias, tarjetas, ingresos, historial crediticio</option>
                                    <option value="laborales">Laborales: cargo, sueldo, antigüedad, evaluaciones</option>
                                    <option value="salud">Salud: historial clínico, diagnósticos, recetas, discapacidad</option>
                                    <option value="biometricos">Biométricos: huella, reconocimiento facial, iris, voz</option>
                                    <option value="geneticos">Genéticos</option>
                                    <option value="ninos">Datos de niños/niñas/adolescentes (Art. 17)</option>
                                    <option value="navegacion">Navegación: IP, cookies, device ID, geolocalización</option>
                                    <option value="ubicacion">Ubicación geográfica precisa</option>
                                    <option value="comportamiento">Perfilado y análisis de comportamiento</option>
                                    <option value="antecedentes">Antecedentes penales/judiciales</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Ctrl+Click para múltiples. Según Art. 15: categorías de datos tratados.</p>
                            </div>
                            <div>
                                <label class="label-premium">Categorías de titulares *</label>
                                <select name="subjectCategories[]" multiple class="input-premium w-full" size="6">
                                    <option value="clientes">Clientes / Usuarios</option>
                                    <option value="empleados">Empleados / Colaboradores</option>
                                    <option value="proveedores">Proveedores / Contratistas</option>
                                    <option value="postulantes">Postulantes a empleo</option>
                                    <option value="ninos">Niños / Niñas / Adolescentes (Art. 17)</option>
                                    <option value="pacientes">Pacientes / Usuarios de salud</option>
                                    <option value="visitantes">Visitantes / Invitados</option>
                                    <option value="ex_empleados">Ex-empleados</option>
                                    <option value="publico_general">Público general</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 15: categorías de interesados afectados.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">¿Incluye datos sensibles? (Art. 16)</label>
                                <select name="sensitive" class="input-premium w-full">
                                    <option value="0">No</option>
                                    <option value="1">Sí - Requiere consentimiento explícito reforzado</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">¿Incluye datos de niños? (Art. 17)</label>
                                <select name="childrenData" class="input-premium w-full">
                                    <option value="0">No</option>
                                    <option value="1">Sí - Requiere consentimiento del representante legal</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">¿Análisis de riesgo realizado? (Art. 25)</label>
                                <select name="riskAssessment" class="input-premium w-full">
                                    <option value="no">No</option>
                                    <option value="si_simple">Sí - Evaluación simplificada</option>
                                    <option value="si_completa">Sí - EIPD completa (Art. 29)</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Destinatarios y transferencias -->
                    <fieldset class="rounded-lg border border-purple-500/20 bg-purple-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-purple-300 px-2">Destinatarios y Transferencias (Art. 14.1.d / Art. 21-22)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Destinatarios / Cesionarios</label>
                                <select name="recipients[]" multiple class="input-premium w-full" size="4">
                                    <option value="ninguno">Ninguno (solo responsable)</option>
                                    <option value="encargados">Encargados de tratamiento (proveedores SaaS, cloud)</option>
                                    <option value="autoridades">Autoridades públicas / Reguladores</option>
                                    <option value="terceros">Terceros con consentimiento del titular</option>
                                    <option value="grupo_empresas">Empresas del mismo grupo</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 14.1.d: destinatarios o categorías de destinatarios.</p>
                            </div>
                            <div>
                                <label class="label-premium">Transferencias internacionales (Art. 21)</label>
                                <select name="internationalTransfers" class="input-premium w-full">
                                    <option value="no">No hay transferencias internacionales</option>
                                    <option value="pais_adecuado">País con nivel adecuado (decisión APDP)</option>
                                    <option value="clausulas_tipo">Cláusulas contractuales tipo</option>
                                    <option value="normas_corporativas">Normas corporativas vinculantes (BCR)</option>
                                    <option value="consentimiento_explicito">Consentimiento explícito del titular</option>
                                    <option value="otras_garantias">Otras garantías adecuadas</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 21: garantías para transferencias internacionales.</p>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="label-premium">Países de destino (si hay transferencias)</label>
                            <input type="text" name="transferCountries" class="input-premium w-full" placeholder="EE.UU., España, Brasil, etc.">
                        </div>
                    </fieldset>

                    <!-- Medidas de seguridad y retención -->
                    <fieldset class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-emerald-300 px-2">Medidas de Seguridad y Retención (Art. 14.1.e / Art. 25)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Medidas técnicas</label>
                                <select name="technicalMeasures[]" multiple class="input-premium w-full" size="5">
                                    <option value="cifrado_reposo">Cifrado en reposo (AES-256)</option>
                                    <option value="cifrado_transito">Cifrado en tránsito (TLS 1.3)</option>
                                    <option value="pseudonimizacion">Seudonimización (Art. 30)</option>
                                    <option value="anonimizacion">Anonimización</option>
                                    <option value="acceso_controlado">Control de acceso basado en roles (RBAC)</option>
                                    <option value="mfa">Autenticación multifactor (MFA)</option>
                                    <option value="auditoria_accesos">Auditoría de accesos (logs)</option>
                                    <option value="dlp">DLP (Prevención fuga de datos)</option>
                                    <option value="backup_cifrado">Backups cifrados y probados</option>
                                    <option value="segmentacion_red">Segmentación de red</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Ctrl+Click. Art. 25: medidas técnicas y organizativas.</p>
                            </div>
                            <div>
                                <label class="label-premium">Medidas organizativas</label>
                                <select name="organizationalMeasures[]" multiple class="input-premium w-full" size="5">
                                    <option value="politica_privacidad">Política de privacidad publicada</option>
                                    <option value="dpd_asignado">DPD designado (Art. 28)</option>
                                    <option value="capacitacion">Capacitación periódica al personal</option>
                                    <option value="procedimiento_arco">Procedimiento ARCO implementado</option>
                                    <option value="protocolo_brechas">Protocolo de brechas (Art. 26)</option>
                                    <option value="eipd">EIPD realizada (Art. 29)</option>
                                    <option value="contratos_encargados">Contratos con encargados (Art. 22)</option>
                                    <option value="inventario_actualizado">RAT actualizado (Art. 14)</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Plazo de retención (días) *</label>
                                <input type="number" name="retentionDays" required class="input-premium w-full" min="1" max="3650" placeholder="Ej: 365 (1 año)">
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 14: plazo de conservación. Máx. 10 años salvo obligación legal.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Criterio de retención</label>
                                <select name="retentionCriteria" class="input-premium w-full">
                                    <option value="cumplimiento_contractual">Cumplimiento contractual</option>
                                    <option value="obligacion_legal">Obligación legal/regulatoria</option>
                                    <option value="prescripcion_legal">Prescripción legal</option>
                                    <option value="interes_legitimo">Interés legítimo documentado</option>
                                    <option value="consentimiento">Mientras dure el consentimiento</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Eliminación segura al vencimiento</label>
                                <select name="secureDeletion" class="input-premium w-full">
                                    <option value="si">Sí - Procedimiento documentado</option>
                                    <option value="no">No implementado</option>
                                    <option value="parcial">Parcial - Solo algunos sistemas</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Evaluación de riesgo y EIPD -->
                    <fieldset class="rounded-lg border border-red-500/20 bg-red-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-red-300 px-2">Evaluación de Riesgo y EIPD (Art. 25, 29)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                            <div>
                                <label class="label-premium">Nivel de riesgo *</label>
                                <select name="risk" required class="input-premium w-full">
                                    <option value="low">Bajo - Datos básicos, pocos titulares</option>
                                    <option value="medium">Medio - Datos personales comunes</option>
                                    <option value="high">Alto - Datos sensibles / muchos titulares / perfilado</option>
                                    <option value="critical">Crítico - Salud, biometría, niños, vigilancia masiva</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">¿Requiere EIPD? (Art. 29)</label>
                                <select name="requiresEIPD" class="input-premium w-full">
                                    <option value="no">No (riesgo bajo/medio)</option>
                                    <option value="si">Sí - Evaluación de impacto obligatoria</option>
                                    <option value="en_proceso">En proceso</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Fecha EIPD (si aplica)</label>
                                <input type="date" name="eipdDate" class="input-premium w-full">
                            </div>
                            <div>
                                <label class="label-premium">Responsable EIPD</label>
                                <input type="text" name="eipdResponsible" class="input-premium w-full" placeholder="DPD / CISO / Asesor legal">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Observaciones y evidencia -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="label-premium">Observaciones / Contexto</label>
                            <textarea name="notes" rows="3" class="input-premium w-full" placeholder="Contexto adicional, dependencias, sistemas involucrados, observaciones legales..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">URL de evidencia (políticas, contratos, EIPD)</label>
                            <input type="url" name="evidenceUrl" class="input-premium w-full" placeholder="https://intranet.empresa.cl/rat-001">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <button type="button" onclick="document.getElementById('inventory-create-form').classList.add('hidden')"
                                class="px-4 py-2 rounded-lg text-[11px] font-medium bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                        <button type="submit" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Registrar actividad en RAT (Art. 14)
                        </button>
                        <span class="text-[10px] text-text-muted">Cumple Art. 14.1: responsable, finalidad, categorías, destinatarios, transferencias, seguridad, retención, EIPD</span>
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
                                    <label class="label-premium">Nombre de la actividad <span class="text-red-400">*</span></label>
                                    <input type="text" name="name" id="edit-name" required class="input-premium w-full" placeholder="Ej: Gestión de clientes">
                                    <p class="text-[9px] text-text-subtle mt-1">Identifica claramente qué tratamiento realizas. Debe ser específico.</p>
                                </div>

                                <!-- Propósito -->
                                <div>
                                    <label class="label-premium">Finalidad / Propósito <span class="text-[9px] text-text-subtle font-normal">(Art. 3 letra b)</span></label>
                                    <input type="text" name="purpose" id="edit-purpose" class="input-premium w-full" placeholder="Ej: Enviar facturación y promociones">
                                    <p class="text-[9px] text-text-subtle mt-1">La ley exige finalidades determinadas y explícitas.</p>
                                </div>

                                <!-- Categorías -->
                                <div>
                                    <label class="label-premium">Categorías de datos</label>
                                    <input type="text" name="dataCategories" id="edit-categories" class="input-premium w-full" placeholder="Ej: nombres, RUT, emails, teléfonos">
                                    <p class="text-[9px] text-text-subtle mt-1">Enumera los tipos de datos personales que tratas.</p>
                                </div>

                                <!-- Base legal -->
                                <div>
                                    <label class="label-premium">Base de licitud <span class="text-red-400">*</span></label>
                                    <select name="legalBasis" id="edit-legalBasis" required class="input-premium w-full">
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
                                    <label class="label-premium">Nivel de riesgo</label>
                                    <select name="risk" id="edit-risk" class="input-premium w-full">
                                        <option value="low">Bajo - Datos básicos (nombres, teléfonos)</option>
                                        <option value="medium">Medio - Datos personales comunes (RUT, dirección)</option>
                                        <option value="high">Alto - Datos sensibles o muchos registros</option>
                                        <option value="critical">Crítico - Datos muy sensibles (salud, biometría)</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">A mayor riesgo, mayores medidas de seguridad.</p>
                                </div>

                                <!-- Sensibles -->
                                <div>
                                    <label class="label-premium">Datos sensibles</label>
                                    <select name="sensitive" id="edit-sensitive" class="input-premium w-full">
                                        <option value="0">No contiene datos sensibles</option>
                                        <option value="1">Sí - Salud, biometría, religión, origen racial, etc.</option>
                                    </select>
                                    <p class="text-[9px] text-text-subtle mt-1">Según Art. 16: salud, origen racial, creencias religiosas, vida sexual, etc.</p>
                                </div>

                                <!-- Retención -->
                                <div>
                                    <label class="label-premium">Días de retención</label>
                                    <input type="number" name="retentionDays" id="edit-retention" class="input-premium w-full" placeholder="Ej: 365" min="0">
                                    <p class="text-[9px] text-text-subtle mt-1">No conservar más tiempo del necesario (Art. 14).</p>
                                </div>

                                <!-- Almacenamiento -->
                                <div>
                                    <label class="label-premium">Almacenamiento</label>
                                    <input type="text" name="storage" id="edit-storage" class="input-premium w-full" placeholder="Ej: AWS, servidor local, Google Drive">
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
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
            </script>

            <?php elseif ($tab === 'privacy'): ?>
            <div class="mb-4">
                <h3 class="text-[14px] md:text-[15px] font-semibold text-text-heading">Política de Privacidad</h3>
                <p class="text-[11px] md:text-[12px] text-text-muted mt-1">Configuración de políticas de privacidad</p>
            </div>
            
            <div class="rounded-xl border border-blue-500/20 bg-blue-500/[0.02] p-5 mb-5">
                <h4 class="text-[12px] font-bold text-white mb-4">Configuración de Políticas</h4>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="update_config" value="1">
                    
                    <div>
                        <label class="block text-[10px] font-medium text-text-body mb-1">URL Política de Privacidad</label>
                        <input type="url" name="privacyPolicyUrl" class="w-full px-3 py-2 rounded-lg bg-bg-surface border border-border-theme text-text-body text-[11px]" value="" placeholder="https://empresa.cl/privacidad">
                    </div>
                    
                    <div>
                        <label class="block text-[10px] font-medium text-text-body mb-1">URL Política de Cookies</label>
                        <input type="url" name="cookiesPolicyUrl" class="w-full px-3 py-2 rounded-lg bg-bg-surface border border-border-theme text-text-body text-[11px]" value="" placeholder="https://empresa.cl/cookies">
                    </div>
                    
                    <div>
                        <label class="block text-[10px] font-medium text-text-body mb-1">Política de Retención de Datos</label>
                        <textarea name="dataRetentionPolicy" rows="4" class="w-full px-3 py-2 rounded-lg bg-bg-surface border border-border-theme text-text-body text-[11px]" placeholder="Describa los plazos de retención..."></textarea>
                    </div>
                    
                    <button type="submit" class="px-4 py-2 rounded-lg text-[11px] font-semibold bg-blue-600 text-white">
                        Guardar Políticas
                    </button>
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
            <?php renderSectionHeader('Brechas', 'Registro de incidentes de seguridad y violaciones de datos — Art. 26 de la Ley 21.719'); ?>
            <div class="px-4 py-3 rounded-lg bg-red-500/[0.06] border border-red-500/20 text-[11px] text-text-body">
                <b class="text-red-300">Ley 21.719 Art. 26:</b> Notificación a APDP sin dilación indebida (máx. 72h tras conocimiento) y a titulares si hay riesgo alto para sus derechos. Multas hasta 20.000 UTM.
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('alert')); ?>
                <?php renderComplianceStat('Abiertas', $bOpen, $bOpen ? 'text-amber-400' : 'text-emerald-400', cIcon('alert')); ?>
                <?php renderComplianceStat('Críticas activas', $bCritical, $bCritical ? 'text-red-400' : 'text-text-subtle', cIcon('alert')); ?>
                <?php renderComplianceStat('Resueltas', $bResolved, 'text-emerald-400', cIcon('check')); ?>
            </div>

            <!-- Formulario profesional de brecha (Art. 26 Ley 21.719) -->
            <div class="rounded-xl border border-red-500/20 bg-red-500/[0.02] p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        Reportar brecha de seguridad (Art. 26)
                    </p>
                    <?php renderImportBtn('breaches'); ?>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="breaches">

                    <!-- Identificación básica -->
                    <fieldset class="rounded-lg border border-red-500/20 bg-red-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-red-300 px-2">Identificación del Incidente</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Título / Referencia *</label>
                                <input type="text" name="fields[title]" required class="input-premium w-full" placeholder="Ej: Fuga de base de datos clientes - Acceso no autorizado">
                            </div>
                            <div>
                                <label class="label-premium">Fecha/Hora detección *</label>
                                <input type="datetime-local" name="fields[detectedAt]" required class="input-premium w-full" value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                            <div>
                                <label class="label-premium">Fecha/Hora ocurrencia (estimada)</label>
                                <input type="datetime-local" name="fields[occurredAt]" class="input-premium w-full">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Tipo de brecha * (Art. 26)</label>
                                <select name="fields[breachType]" required class="input-premium w-full">
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
                                <p class="text-[8px] text-text-subtle mt-0.5">Clasificación CIA: Confidencialidad, Integridad, Disponibilidad.</p>
                            </div>
                            <div>
                                <label class="label-premium">Severidad *</label>
                                <select name="fields[severity]" required class="input-premium w-full">
                                    <option value="low">🟢 Baja - Sin datos personales afectados</option>
                                    <option value="medium">🟡 Media - Datos personales básicos afectados</option>
                                    <option value="high">🟠 Alta - Datos sensibles afectados</option>
                                    <option value="critical">🔴 Crítica - Datos sensibles + niños / escala masiva</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Origen de la brecha</label>
                                <select name="fields[source]" class="input-premium w-full">
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
                    </fieldset>

                    <!-- Datos y titulares afectados -->
                    <fieldset class="rounded-lg border border-amber-500/20 bg-amber-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-amber-300 px-2">Datos y Titulares Afectados (Art. 26.2)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Categorías de datos afectados *</label>
                                <select name="fields[affectedCategories][]" multiple class="input-premium w-full" size="5">
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
                                <p class="text-[8px] text-text-subtle mt-0.5">Ctrl+Click para múltiples. Art. 26.2: naturaleza de los datos afectados.</p>
                            </div>
                            <div>
                                <label class="label-premium">Nº aproximado de titulares afectados</label>
                                <input type="number" name="fields[affectedCount]" class="input-premium w-full" min="0" placeholder="Ej: 15000">
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 26.2: número aproximado de interesados.</p>
                            </div>
                            <div>
                                <label class="label-premium">¿Incluye datos sensibles? (Art. 16)</label>
                                <select name="fields[sensitiveInvolved]" class="input-premium w-full">
                                    <option value="no">No</option>
                                    <option value="si">Sí - Notificación obligatoria a titulares</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Categorías de titulares afectados</label>
                                <select name="fields[affectedSubjectCategories][]" multiple class="input-premium w-full" size="4">
                                    <option value="clientes">Clientes</option>
                                    <option value="empleados">Empleados</option>
                                    <option value="proveedores">Proveedores</option>
                                    <option value="ninos">Niños/Adolescentes</option>
                                    <option value="pacientes">Pacientes</option>
                                    <option value="publico">Público general</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Sistemas/BD afectados</label>
                                <input type="text" name="fields[affectedSystems]" class="input-premium w-full" placeholder="Ej: CRM clientes, BD nóminas, servidor archivos">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Evaluación de riesgo y consecuencias -->
                    <fieldset class="rounded-lg border border-orange-500/20 bg-orange-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-orange-300 px-2">Evaluación de Riesgo y Consecuencias (Art. 26.3)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Consecuencias probables *</label>
                                <textarea name="fields[likelyConsequences]" required rows="3" class="input-premium w-full" placeholder="Describe las consecuencias probables para los titulares: robo de identidad, fraude financiero, daño reputacional, discriminación, etc."></textarea>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 26.3: descripción de las consecuencias probables.</p>
                            </div>
                            <div>
                                <label class="label-premium">Medidas adoptadas / propuestas *</label>
                                <textarea name="fields[measuresTaken]" required rows="3" class="input-premium w-full" placeholder="Describe medidas técnicas y organizativas adoptadas: contención, investigación, notificación, corrección, prevención futura..."></textarea>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 26.3: medidas adoptadas o propuestas para mitigar efectos.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Nivel de riesgo para titulares</label>
                                <select name="fields[riskLevel]" class="input-premium w-full">
                                    <option value="bajo">Bajo - Impacto mínimo</option>
                                    <option value="moderado">Moderado - Posible daño limitado</option>
                                    <option value="alto">Alto - Probable daño significativo</option>
                                    <option value="muy_alto">Muy alto - Daño severo/irreversible</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">¿Notificación a APDP realizada? (Art. 26.1)</label>
                                <select name="fields[notifiedAPDP]" class="input-premium w-full">
                                    <option value="no">No - Pendiente</option>
                                    <option value="si">Sí - Notificada</option>
                                    <option value="en_proceso">En proceso</option>
                                    <option value="no_procede">No procede (riesgo bajo)</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Fecha notificación APDP</label>
                                <input type="datetime-local" name="fields[apdpNotifiedAt]" class="input-premium w-full">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">¿Notificación a titulares? (Art. 26.4)</label>
                                <select name="fields[notifiedSubjects]" class="input-premium w-full">
                                    <option value="no">No - Pendiente / No procede</option>
                                    <option value="si">Sí - Notificados individualmente</option>
                                    <option value="publica">Sí - Comunicación pública (web/medios)</option>
                                    <option value="en_proceso">En proceso</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Fecha notificación titulares</label>
                                <input type="datetime-local" name="fields[subjectsNotifiedAt]" class="input-premium w-full">
                            </div>
                            <div>
                                <label class="label-premium">Canal de notificación</label>
                                <select name="fields[notificationChannel]" class="input-premium w-full">
                                    <option value="email">Email directo</option>
                                    <option value="carta">Carta certificada</option>
                                    <option value="web">Publicación en web/app</option>
                                    <option value="medios">Medios de comunicación</option>
                                    <option value="mixto">Múltiples canales</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Resolución y evidencia -->
                    <fieldset class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-emerald-300 px-2">Resolución y Evidencia</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Estado</label>
                                <select name="fields[status]" class="input-premium w-full">
                                    <option value="open">Abierta - En investigación</option>
                                    <option value="contained">Contenida - Sin más fuga</option>
                                    <option value="investigating">Investigando causa raíz</option>
                                    <option value="resolved">Resuelta - Cerrada</option>
                                    <option value="closed_no_action">Cerrada - Sin acción (falso positivo)</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Fecha resolución</label>
                                <input type="datetime-local" name="fields[resolvedAt]" class="input-premium w-full">
                            </div>
                            <div>
                                <label class="label-premium">Responsable gestión</label>
                                <input type="text" name="fields[incidentManager]" class="input-premium w-full" placeholder="DPD / CISO / Equipo seguridad">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Lecciones aprendidas</label>
                                <textarea name="fields[lessonsLearned]" rows="2" class="input-premium w-full" placeholder="Qué se aprendió y qué se mejorará para evitar recurrencia..."></textarea>
                            </div>
                            <div>
                                <label class="label-premium">URL de evidencia (logs, informes forenses, notificaciones)</label>
                                <input type="url" name="fields[evidenceUrl]" class="input-premium w-full" placeholder="https://intranet.empresa.cl/brecha-2024-001">
                            </div>
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                        <input type="hidden" name="fields[reportedBy]" value="<?= h($user['email'] ?? '') ?>">
                        <button type="submit" name="create_item" value="1" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-red-600 to-orange-600 hover:from-red-500 hover:to-orange-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            Reportar brecha (Art. 26 Ley 21.719)
                        </button>
                        <span class="text-[10px] text-text-muted">Obligatorio notificar a APDP en 72h. Si datos sensibles/niños → notificar a titulares.</span>
                    </div>
                </form>
            </div>
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
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="collection" value="dpia">
                    <input type="text" name="fields[name]" required placeholder="Nombre del proyecto" class="input-premium">
                    <input type="text" name="fields[description]" placeholder="Descripción del tratamiento" class="input-premium">
                    <select name="fields[riskLevel]" class="input-premium">
                        <option value="low">🟢 Riesgo bajo</option><option value="medium">🟡 Riesgo medio</option><option value="high">🟠 Riesgo alto</option>
                    </select>
                    <button type="submit" name="create_item" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all">Crear DPIA</button>
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

            <!-- Formulario de Encargado (Art. 15 bis Ley 21.719) -->
            <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/[0.02] p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Nuevo encargado de tratamiento
                    </p>
                    <?php renderImportBtn('processors'); ?>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="processors">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="label-premium">Nombre del encargado *</label>
                            <input type="text" name="fields[name]" required class="input-premium w-full" placeholder="Ej: AWS, Google Cloud, Proveedor SaaS, Contabilidad externa">
                        </div>
                        <div>
                            <label class="label-premium">Tipo de servicio</label>
                            <select name="fields[serviceType]" class="input-premium w-full">
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
                        <div>
                            <label class="label-premium">País de establecimiento</label>
                            <input type="text" name="fields[country]" class="input-premium w-full" placeholder="Ej: Chile, EE.UE., UE">
                        </div>
                        <div>
                            <label class="label-premium">¿Transferencia internacional? (Art. 21/27)</label>
                            <select name="fields[internationalTransfer]" class="input-premium w-full">
                                <option value="no">No</option>
                                <option value="si_adecuado">Sí - País con nivel adecuado (Decisión APDP)</option>
                                <option value="si_clausulas">Sí - Cláusulas contractuales tipo</option>
                                <option value="si_bcr">Sí - Normas corporativas vinculantes (BCR)</option>
                                <option value="si_excepcion">Sí - Excepción Art. 27 (consentimiento, contrato, etc.)</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="label-premium">Finalidad del tratamiento</label>
                            <textarea name="fields[purpose]" rows="2" class="input-premium w-full" placeholder="Describe qué datos trata el encargado y para qué finalidad..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">Categorías de datos tratados</label>
                            <input type="text" name="fields[dataCategories]" class="input-premium w-full" placeholder="Ej: Datos de contacto, datos financieros, datos de empleados">
                        </div>
                        <div>
                            <label class="label-premium">Categorías de titulares</label>
                            <input type="text" name="fields[subjectCategories]" class="input-premium w-full" placeholder="Ej: Clientes, empleados, proveedores">
                        </div>
                        <div>
                            <label class="label-premium">¿Contrato DPA firmado? (Art. 15 bis)</label>
                            <select name="fields[hasContract]" class="input-premium w-full">
                                <option value="no">No</option>
                                <option value="si">Sí - Incluye cláusulas Art. 15 bis</option>
                                <option value="en_proceso">En proceso</option>
                            </select>
                        </div>
                        <div>
                            <label class="label-premium">Fecha contrato DPA</label>
                            <input type="date" name="fields[contractDate]" class="input-premium w-full">
                        </div>
                        <div>
                            <label class="label-premium">URL del contrato / evidencia</label>
                            <input type="url" name="fields[contractUrl]" class="input-premium w-full" placeholder="https://intranet.empresa.cl/dpa-aws.pdf">
                        </div>
                        <div>
                            <label class="label-premium">Sub-encargados autorizados</label>
                            <textarea name="fields[subProcessors]" rows="2" class="input-premium w-full" placeholder="Lista de sub-encargados autorizados por escrito..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">Medidas de seguridad del encargado</label>
                            <textarea name="fields[securityMeasures]" rows="2" class="input-premium w-full" placeholder="Certificaciones (ISO 27001, SOC 2), cifrado, controles de acceso..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">Derecho de auditoría / inspección</label>
                            <select name="fields[auditRights]" class="input-premium w-full">
                                <option value="si">Sí - Incluido en contrato</option>
                                <option value="no">No</option>
                                <option value="parcial">Parcial / Solo certificación</option>
                            </select>
                        </div>
                        <div>
                            <label class="label-premium">Fecha fin de relación</label>
                            <input type="date" name="fields[endDate]" class="input-premium w-full">
                        </div>
                    </div>
                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <button type="submit" name="create_item" value="1" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Registrar encargado (Art. 15 bis)
                        </button>
                        <span class="text-[10px] text-text-muted">Obligatorio contrato con cláusulas Art. 15 bis. Si transferencia intl. → cláusulas tipo / BCR / decisión adecuación.</span>
                    </div>
                </form>
            </div>
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
            <div class="rounded-xl border border-amber-500/20 bg-amber-500/[0.02] p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c0 1.657-1.343 3-3 3"/></svg>
                        Nueva transferencia internacional
                    </p>
                    <?php renderImportBtn('transfers'); ?>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="transfers">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="label-premium">País destino *</label>
                            <input type="text" name="fields[destinationCountry]" required class="input-premium w-full" placeholder="Ej: Estados Unidos, Colombia, España">
                        </div>
                        <div>
                            <label class="label-premium">Mecanismo de transferencia * (Art. 21/27)</label>
                            <select name="fields[mechanism]" required class="input-premium w-full">
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
                            <p class="text-[8px] text-text-subtle mt-0.5">Art. 21: transferencia solo si país adecuado, garantías o excepción.</p>
                        </div>
                        <div>
                            <label class="label-premium">Destinatario / Encargado</label>
                            <input type="text" name="fields[recipient]" class="input-premium w-full" placeholder="Ej: AWS (EE.UE.), Proveedor SaaS (Colombia)">
                        </div>
                        <div>
                            <label class="label-premium">Fecha inicio transferencia</label>
                            <input type="date" name="fields[startDate]" class="input-premium w-full">
                        </div>
                        <div class="md:col-span-2">
                            <label class="label-premium">Categorías de datos transferidos</label>
                            <input type="text" name="fields[dataCategories]" class="input-premium w-full" placeholder="Ej: Datos de contacto, datos financieros, datos de empleados">
                        </div>
                        <div>
                            <label class="label-premium">Categorías de titulares</label>
                            <input type="text" name="fields[subjectCategories]" class="input-premium w-full" placeholder="Ej: Clientes, empleados, proveedores">
                        </div>
                        <div>
                            <label class="label-premium">¿Incluye datos sensibles? (Art. 16)</label>
                            <select name="fields[sensitiveData]" class="input-premium w-full">
                                <option value="no">No</option>
                                <option value="si">Sí - Requiere garantías reforzadas</option>
                            </select>
                        </div>
                        <div>
                            <label class="label-premium">¿Incluye datos de niños? (Art. 17)</label>
                            <select name="fields[childrenData]" class="input-premium w-full">
                                <option value="no">No</option>
                                <option value="si">Sí - Protección reforzada</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="label-premium">Descripción de la garantía / cláusulas</label>
                            <textarea name="fields[guaranteeDescription]" rows="3" class="input-premium w-full" placeholder="Detalla las cláusulas contractuales, BCR, código de conducta o mecanismo de certificación utilizado..."></textarea>
                            <p class="text-[8px] text-text-subtle mt-0.5">Art. 21.2: documento que acredite garantías adecuadas.</p>
                        </div>
                        <div>
                            <label class="label-premium">URL evidencia (SCC firmadas, BCR, decisión APDP)</label>
                            <input type="url" name="fields[evidenceUrl]" class="input-premium w-full" placeholder="https://intranet.empresa.cl/scc-aws.pdf">
                        </div>
                        <div>
                            <label class="label-premium">Responsable de la transferencia</label>
                            <input type="text" name="fields[transferManager]" class="input-premium w-full" placeholder="DPD / CISO / Legal">
                        </div>
                        <div>
                            <label class="label-premium">Fecha revisión / vencimiento</label>
                            <input type="date" name="fields[reviewDate]" class="input-premium w-full">
                        </div>
                    </div>
                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <button type="submit" name="create_item" value="1" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c0 1.657-1.343 3-3 3"/></svg>
                            Registrar transferencia (Art. 21/27)
                        </button>
                        <span class="text-[10px] text-text-muted">Si no hay decisión de adecuación → SCC o BCR obligatorias. Excepciones Art. 27 limitadas.</span>
                    </div>
                </form>
            </div>
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
            <?php renderSectionHeader('Seudonimización', 'Reemplazo de identificadores directos por seudónimos — Art. 30 de la Ley 21.719'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total reglas', $pRules, 'text-white', cIcon('search')); ?>
                <?php renderComplianceStat('Ejecutadas', $pExecuted, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $pPending, $pPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
                <?php renderComplianceStat('Avance', $pRules ? round($pExecuted / $pRules * 100) . '%' : '—', 'text-indigo-400', cIcon('shield')); ?>
            </div>

            <!-- Formulario profesional de seudonimización (Art. 30 Ley 21.719) -->
            <div class="rounded-xl border border-purple-500/20 bg-purple-500/[0.02] p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Nueva regla de seudonimización (Art. 30)
                    </p>
                    <?php renderImportBtn('pseudonymization'); ?>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="pseudonymization">

                    <!-- Identificación básica -->
                    <fieldset class="rounded-lg border border-purple-500/20 bg-purple-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-purple-300 px-2">Identificación de la Regla (Art. 30)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Nombre de la regla *</label>
                                <input type="text" name="fields[name]" required class="input-premium w-full" placeholder="Ej: Seudonimización RUT clientes">
                            </div>
                            <div>
                                <label class="label-premium">Código / Referencia</label>
                                <input type="text" name="fields[code]" class="input-premium w-full" placeholder="Ej: PSEUDO-001">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Técnica de seudonimización *</label>
                                <select name="fields[technique]" required class="input-premium w-full">
                                    <option value="">Seleccionar técnica</option>
                                    <option value="tokenizacion">Tokenización (token aleatorio reversible)</option>
                                    <option value="hashing">Hashing unidireccional (SHA-256, SHA-3)</option>
                                    <option value="cifrado_reversible">Cifrado reversible (AES-256 con key management)</option>
                                    <option value="masking">Enmascaramiento / Masking (parcial)</option>
                                    <option value="format_preserving">Cifrado conservador de formato (FPE)</option>
                                    <option value="differential_privacy">Privacidad diferencial (ruido estadístico)</option>
                                    <option value="otro">Otra (especificar en observaciones)</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 30: reemplazo de identificadores directos por seudónimos.</p>
                            </div>
                            <div>
                                <label class="label-premium">Alcance *</label>
                                <select name="fields[scope]" required class="input-premium w-full">
                                    <option value="">Seleccionar alcance</option>
                                    <option value="todos_identificadores">Todos los identificadores directos (RUT, email, nombre)</option>
                                    <option value="solo_rut">Solo RUT / DNI / RUN</option>
                                    <option value="solo_email">Solo emails</                                    >
                                    <option value="solo_nombres">Solo nombres y apellidos</option>
                                    <option value="datos_sensibles">Solo datos sensibles (salud, biometría)</option>
                                    <option value="personalizado">Personalizado (detallar en observaciones)</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Configuración técnica -->
                    <fieldset class="rounded-lg border border-blue-500/20 bg-blue-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-blue-300 px-2">Configuración Técnica y Gestión de Claves</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Gestión de claves *</label>
                                <select name="fields[keyManagement]" required class="input-premium w-full">
                                    <option value="">Seleccionar</option>
                                    <option value="kms_dedicado">KMS dedicado (AWS KMS, Azure Key Vault, GCP KMS)</option>
                                    <option value="hsm">HSM (Hardware Security Module)</option>
                                    <option value="vault">HashiCorp Vault / CyberArk / Thycotic</option>
                                    <option value="cloud_kms">Cloud KMS nativo (AWS/Azure/GCP)</option>
                                    <option value="manual_seguro">Manual con procedimiento documentado y custodia dual</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 30: la re-identificación debe requerir información adicional bajo custodia.</p>
                            </div>
                            <div>
                                <label class="label-premium">Base de datos / Tabla origen</label>
                                <input type="text" name="fields[sourceTable]" class="input-premium w-full" placeholder="Ej: public.clientes, dbo.empleados">
                            </div>
                            <div>
                                <label class="label-premium">Columna(s) seudonimizadas</label>
                                <input type="text" name="fields[columns]" class="input-premium w-full" placeholder="Ej: rut, email, nombre_completo">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Algoritmo / Parámetros</label>
                                <textarea name="fields[algorithmDetails]" rows="2" class="input-premium w-full" placeholder="Ej: AES-256-GCM, key rotation 90d, IV aleatorio por registro..."></textarea>
                            </div>
                            <div>
                                <label class="label-premium">Tabla/Columna de mapeo (si reversible)</label>
                                <input type="text" name="fields[mappingTable]" class="input-premium w-full" placeholder="Ej: pseudo_mapping (pseudonym, original_hash)">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Aplicación y cumplimiento -->
                    <fieldset class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-emerald-300 px-2">Aplicación y Cumplimiento (Art. 30.2)</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">Frecuencia de ejecución</label>
                                <select name="fields[frequency]" class="input-premium w-full">
                                    <option value="bajo_demanda">Bajo demanda</option>
                                    <option value="diaria">Diaria (batch nocturno)</option>
                                    <option value="semanal">Semanal</option>
                                    <option value="mensual">Mensual</option>
                                    <option value="evento">Por evento (nuevo registro, migración)</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Entorno de aplicación</label>
                                <select name="fields[environment]" class="input-premium w-full">
                                    <option value="produccion">Producción</option>
                                    <option value="preproduccion">Pre-producción / Staging</option>
                                    <option value="desarrollo">Desarrollo</option>
                                    <option value="analitica">Entorno analítico / Data Warehouse</option>
                                    <option value="todos">Todos los entornos</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Estado</label>
                                <select name="fields[status]" class="input-premium w-full">
                                    <option value="draft">Borrador</option>
                                    <option value="testing">En pruebas</option>
                                    <option value="executed">Ejecutada / En producción</option>
                                    <option value="deprecated">Deprecada</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Fecha última ejecución</label>
                                <input type="datetime-local" name="fields[lastExecutedAt]" class="input-premium w-full">
                            </div>
                            <div>
                                <label class="label-premium">Próxima ejecución programada</label>
                                <input type="datetime-local" name="fields[nextExecutionAt]" class="input-premium w-full">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="label-premium">Verificación de irreversibilidad (para hashing)</label>
                            <select name="fields[irreversibilityVerified]" class="input-premium w-full">
                                <option value="no">No verificada</option>
                                <option value="si_teorica">Sí - Verificación teórica (análisis entropía)</option>
                                <option value="si_practica">Sí - Verificación práctica (ataques de diccionario, rainbow tables)</option>
                                <option value="certificado">Certificado por tercero</option>
                            </select>
                        </div>
                    </fieldset>

                    <!-- Evidencia y observaciones -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="label-premium">Observaciones / Contexto</label>
                            <textarea name="fields[notes]" rows="3" class="input-premium w-full" placeholder="Contexto: finalidad, tratamiento al que aplica, limitaciones, excepciones..."></textarea>
                        </div>
                        <div>
                            <label class="label-premium">URL de evidencia (scripts, configs, logs de ejecución)</label>
                            <input type="url" name="fields[evidenceUrl]" class="input-premium w-full" placeholder="https://gitlab.empresa.cl/pseudo-rules/rut-clientes">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                        <input type="hidden" name="fields[createdBy]" value="<?= h($user['email'] ?? '') ?>">
                        <button type="submit" name="create_item" value="1" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Registrar regla de seudonimización (Art. 30 Ley 21.719)
                        </button>
                        <span class="text-[10px] text-text-muted">La seudonimización permite tratar datos sin identificación directa. Claves de re-identificación bajo custodia separada (Art. 30.2).</span>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'trainings'): ?>
            <?php
            $tDone = count(array_filter($items, fn($it) => !empty($it['completed'])));
            $tPending = count($items) - $tDone;
            ?>
            <?php renderSectionHeader('Capacitaciones Ley 21.719', 'Registro de capacitación en protección de datos personales — Art. 28 letra c) (DPD debe capacitar al personal)'); ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php renderComplianceStat('Total', count($items), 'text-white', cIcon('info')); ?>
                <?php renderComplianceStat('Completadas', $tDone, 'text-emerald-400', cIcon('check')); ?>
                <?php renderComplianceStat('Pendientes', $tPending, $tPending ? 'text-amber-400' : 'text-emerald-400', cIcon('pen')); ?>
                <?php renderComplianceStat('Avance', $items ? round($tDone / count($items) * 100) . '%' : '—', 'text-indigo-400', cIcon('shield')); ?>
            </div>

            <!-- Formulario profesional de capacitación (Art. 28.c Ley 21.719) -->
            <div class="rounded-xl border border-amber-500/20 bg-amber-500/[0.02] p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        Nueva capacitación en protección de datos
                    </p>
                    <?php renderImportBtn('trainings'); ?>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="collection" value="trainings">

                    <!-- Información básica -->
                    <fieldset class="rounded-lg border border-amber-500/20 bg-amber-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-amber-300 px-2">Información de la Capacitación</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Título / Tema *</label>
                                <select name="fields[title]" required class="input-premium w-full">
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
                                <p class="text-[8px] text-text-subtle mt-0.5">Art. 28.c: DPD debe capacitar al personal que trata datos.</p>
                            </div>
                            <div>
                                <label class="label-premium">Modalidad *</label>
                                <select name="fields[modality]" required class="input-premium w-full">
                                    <option value="presencial">Presencial</option>
                                    <option value="virtual_sync">Virtual sincrónica (Zoom/Teams en vivo)</option>
                                    <option value="virtual_async">Virtual asincrónica (LMS/Moodle)</option>
                                    <option value="hibrida">Híbrida</option>
                                    <option value="e_learning">E-learning autogestionado</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Fecha *</label>
                                <input type="date" name="fields[date]" required class="input-premium w-full" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div>
                                <label class="label-premium">Duración (horas) *</label>
                                <input type="number" name="fields[durationHours]" required class="input-premium w-full" min="0.5" max="40" step="0.5" value="2" placeholder="Ej: 2, 4, 8">
                            </div>
                            <div>
                                <label class="label-premium">Instructor / Entidad</label>
                                <input type="text" name="fields[instructor]" class="input-premium w-full" placeholder="Nombre del capacitador o entidad externa">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Participantes y alcance -->
                    <fieldset class="rounded-lg border border-blue-500/20 bg-blue-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-blue-300 px-2">Participantes y Alcance</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Público objetivo *</label>
                                <select name="fields[targetAudience]" required class="input-premium w-full">
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
                            <div>
                                <label class="label-premium">Nº participantes esperados</label>
                                <input type="number" name="fields[expectedAttendees]" class="input-premium w-full" min="1" placeholder="Ej: 50">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Participantes reales (lista RUT/Nombres)</label>
                                <textarea name="fields[attendeesList]" rows="3" class="input-premium w-full" placeholder="RUT, Nombre completo (uno por línea)&#10;12.345.678-9, Juan Pérez González&#10;98.765.432-1, María González López"></textarea>
                                <p class="text-[8px] text-text-subtle mt-0.5">Para trazabilidad y evidencia ante fiscalización.</p>
                            </div>
                            <div>
                                <label class="label-premium">Departamentos/Áreas</label>
                                <input type="text" name="fields[departments]" class="input-premium w-full" placeholder="RRHH, Comercial, TI, Finanzas, Operaciones">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Contenido y evaluación -->
                    <fieldset class="rounded-lg border border-purple-500/20 bg-purple-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-purple-300 px-2">Contenido y Evaluación</legend>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="label-premium">Temario / Contenido *</label>
                                <textarea name="fields[content]" required rows="3" class="input-premium w-full" placeholder="Detalle de temas cubiertos, referencias legales (Art. 3, 12, 16, 25, 26, 28, 30), casos prácticos..."></textarea>
                                <p class="text-[8px] text-text-subtle mt-0.5">Debe incluir referencias a artículos específicos de la Ley 21.719.</p>
                            </div>
                            <div>
                                <label class="label-premium">Materiales entregados</label>
                                <select name="fields[materials][]" multiple class="input-premium w-full" size="5">
                                    <option value="presentacion">Presentación (PDF/PPT)</option>
                                    <option value="guia">Guía / Manual de procedimientos</option>
                                    <option value="casos_practicos">Casos prácticos / Ejercicios</option>
                                    <option value="test">Test de evaluación</option>
                                    <option value="video">Grabación de la sesión</option>
                                    <option value="certificado">Certificado de participación</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Ctrl+Click. Evidencia para fiscalización.</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                            <div>
                                <label class="label-premium">¿Incluye evaluación? *</label>
                                <select name="fields[hasEvaluation]" required class="input-premium w-full">
                                    <option value="si_test">Sí - Test escrito</option>
                                    <option value="si_casos">Sí - Casos prácticos</option>
                                    <option value="si_ambos">Sí - Test + Casos prácticos</option>
                                    <option value="no">No (solo asistencia)</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">Recomendado: evaluación para demostrar efectividad.</p>
                            </div>
                            <div>
                                <label class="label-premium">Nota mínima aprobación (%)</label>
                                <input type="number" name="fields[passingScore]" class="input-premium w-full" min="50" max="100" value="70" placeholder="70">
                            </div>
                            <div>
                                <label class="label-premium">% Aprobados</label>
                                <input type="number" name="fields[approvalRate]" class="input-premium w-full" min="0" max="100" placeholder="Ej: 85">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Evidencia y certificados -->
                    <fieldset class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.02] p-4">
                        <legend class="text-[11px] font-medium text-emerald-300 px-2">Evidencia y Certificación</legend>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="label-premium">URL de evidencias</label>
                                <input type="url" name="fields[evidenceUrl]" class="input-premium w-full" placeholder="https://drive.empresa.cl/capacitaciones/2024-01">
                            </div>
                            <div>
                                <label class="label-premium">¿Entrega certificado?</label>
                                <select name="fields[certificateIssued]" class="input-premium w-full">
                                    <option value="si">Sí - Certificado individual</option>
                                    <option value="si_grupal">Sí - Certificado grupal</option>
                                    <option value="no">No</option>
                                </select>
                            </div>
                            <div>
                                <label class="label-premium">Fecha próxima recertificación</label>
                                <input type="date" name="fields[recertificationDate]" class="input-premium w-full" placeholder="Recomendado: 12 meses">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="label-premium">Observaciones</label>
                                <textarea name="fields[notes]" rows="2" class="input-premium w-full" placeholder="Observaciones, incidencias, mejoras para próxima edición..."></textarea>
                            </div>
                            <div>
                                <label class="label-premium">Costo (CLP)</label>
                                <input type="number" name="fields[costCLP]" class="input-premium w-full" min="0" placeholder="Ej: 500000">
                            </div>
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3 pt-2 border-t border-border-theme">
                        <input type="hidden" name="fields[createdAt]" value="<?= date('c') ?>">
                        <input type="hidden" name="fields[createdBy]" value="<?= h($user['email'] ?? '') ?>">
                        <button type="submit" name="create_item" value="1" class="px-5 py-2.5 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477 4.5 1.253"/></svg>
                            Registrar capacitación (Art. 28.c Ley 21.719)
                        </button>
                        <span class="text-[10px] text-text-muted">El DPD debe capacitar al personal que trata datos. Registrar asistentes, evaluación y evidencia.</span>
                    </div>
                </form>
            </div>
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
                    <input type="text" name="fields[title]" required placeholder="Título del documento" class="input-premium">
                    <input type="text" name="fields[description]" placeholder="Descripción" class="input-premium">
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
</div>

<?php
function renderSectionHeader($title, $desc) {
    echo '<div class="mb-1"><h3 class="text-[14px] md:text-[15px] font-semibold text-text-heading inline-flex items-center gap-2">' . h($title) . ' ' . infoIcon($desc) . '</h3>';
    echo '<p class="text-[11px] md:text-[12px] text-text-muted mt-1">' . h($desc) . '</p></div>';
}

function renderComplianceList($items, $collection, $renderMain, $renderStatus = null) {
    if (empty($items)) {
        echo '<div class="rounded-xl border border-border-theme bg-bg-panel/60 p-10 text-center"><p class="text-[11px] text-text-subtle">Sin registros todavía.</p></div>';
        return;
    }
    echo '<div class="space-y-2">';
    foreach ($items as $it) {
        echo '<div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm hover:border-border-theme/60 transition-colors p-4 flex flex-col md:flex-row md:items-center gap-3">';
        echo '<div class="flex-1 min-w-0">';
        $renderMain($it);
        echo '</div><div class="flex items-center gap-2 flex-shrink-0">';
        if ($renderStatus) $renderStatus($it);
        echo '<form method="POST" class="inline">';
        echo '<input type="hidden" name="collection" value="' . h($collection) . '">';
        echo '<input type="hidden" name="item_id" value="' . h($it['_id'] ?? '') . '">';
        echo '<button type="submit" name="delete_item" value="1" onclick="return confirm(\'¿Eliminar este registro?\')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Eliminar</button>';
        echo '</form></div></div>';
    }
    echo '</div>';
}

function renderActionBtn($collection, $id, $action, $label) {
    echo '<form method="POST" class="inline">';
    echo '<input type="hidden" name="collection" value="' . h($collection) . '">';
    echo '<input type="hidden" name="item_id" value="' . h($id) . '">';
    echo '<button type="submit" name="item_action" value="' . h($action) . '" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-primary-500/10 border border-accent-border text-accent hover:bg-primary-500/20 transition-all">' . h($label) . '</button>';
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>