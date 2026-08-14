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
            'apdpRegistered' => !empty($_POST['apdpRegistered']) ? '1' : '',
            'complianceLevel' => $_POST['complianceLevel'] ?? 'basic',
        ]);
        $msg = 'Configuración guardada.';
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

$items = [];
if (!in_array($tab, ['overview', 'violations'])) {
    $items = $fetchList($tab);
}

// ── Checklist (mismo criterio que React) ──
$CHECKLIST = [
    ['id' => 'dpd', 'label' => 'DPD Designado', 'desc' => 'Delegado de Protección de Datos (Art. 28)', 'icon' => 'users', 'done' => !empty($config['dpdEmail'])],
    ['id' => 'apdp', 'label' => 'Registro APDP', 'desc' => 'Registro ante Agencia de Protección de Datos (Art. 31)', 'icon' => 'shield', 'done' => !empty($config['apdpRegistered'])],
    ['id' => 'inventory', 'label' => 'Inventario de Datos', 'desc' => 'Inventario de datos personales (Art. 15)', 'icon' => 'database', 'done' => count($inventory) > 0],
    ['id' => 'privacy', 'label' => 'Política de Privacidad', 'desc' => 'Política actualizada y accesible (Art. 14)', 'icon' => 'fileText', 'done' => !empty($config['privacyPolicyUrl'])],
    ['id' => 'consents', 'label' => 'Consentimientos', 'desc' => 'Mecanismo de consentimiento explícito (Art. 12)', 'icon' => 'check', 'done' => count($consents) > 0],
    ['id' => 'breach_protocol', 'label' => 'Protocolo de Brechas', 'desc' => 'Procedimiento de notificación (Art. 26)', 'icon' => 'alert', 'done' => count($breaches) > 0],
    ['id' => 'arco', 'label' => 'Portal ARCO', 'desc' => 'Derechos Acceso, Rectificación, Cancelación, Oposición + Portabilidad', 'icon' => 'users', 'done' => true],
    ['id' => 'pseudonymization', 'label' => 'Seudonimización', 'desc' => 'Reemplazo de identificadores directos por seudónimos (Art. 30)', 'icon' => 'search', 'done' => count($pseudoRules) > 0],
    ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'desc' => 'Procedimiento documentado para brechas de seguridad (Art. 26)', 'icon' => 'alert', 'done' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0],
    ['id' => 'training', 'label' => 'Capacitación', 'desc' => 'Programa de formación en protección de datos', 'icon' => 'info', 'done' => count($trainings) > 0),
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
    ['id' => 'breaches', 'label' => 'Brechas', 'icon' => 'alert'],
    ['id' => 'violations', 'label' => 'Violaciones', 'icon' => 'alert'],
    ['id' => 'dpia', 'label' => 'Eval. Impacto', 'icon' => 'shield'],
    ['id' => 'trainings', 'label' => 'Capacitaciones', 'icon' => 'info'],
    ['id' => 'invites', 'label' => 'Firmas', 'icon' => 'pen'],
    ['id' => 'files', 'label' => 'Archivos', 'icon' => 'fileText'],
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
                    ['icon' => 'shield', 'label' => 'Nivel Cumplimiento', 'value' => $checklistPct . '%', 'color' => $pctColor, 'sub' => $checklistDone . '/' . $checklistTotal . ' requisitos cumplidos'],
                    ['icon' => 'users', 'label' => 'Consentimientos Activos', 'value' => $activeConsents, 'color' => 'text-cyan-400', 'sub' => 'Total: ' . count($consents)],
                    ['icon' => 'database', 'label' => 'Datos Registrados', 'value' => count($inventory), 'color' => 'text-indigo-400', 'sub' => $sensitiveItems . ' sensibles'],
                    ['icon' => 'alert', 'label' => 'Incidentes Activos', 'value' => $activeBreaches, 'color' => $activeBreaches ? 'text-red-400' : 'text-emerald-400', 'sub' => count($breaches) . ' total · ' . $criticalBreaches . ' críticos'],
                    ['icon' => 'info', 'label' => 'Capacitaciones', 'value' => $completedTrainings, 'color' => 'text-amber-400', 'sub' => count($trainings) . ' registradas'],
                ];
                foreach ($statCards as $c): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 md:p-5 hover:border-border-theme/60 transition-colors duration-200">
                    <div class="flex items-center gap-2 md:gap-2.5 mb-2 md:mb-3">
                        <span class="text-text-muted"><?= cIcon($c['icon']) ?></span>
                        <span class="text-[9px] md:text-[10px] text-text-subtle font-medium uppercase tracking-widest truncate"><?= h($c['label']) ?></span>
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
                                <span class="text-[12px] font-medium <?= $item['done'] ? 'text-emerald-300' : 'text-text-muted' ?>"><?= h($item['label']) ?></span>
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
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center <?= !empty($config['apdpRegistered']) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <?= cIcon(!empty($config['apdpRegistered']) ? 'shield' : 'alert') ?>
                        </div>
                        <h4 class="text-[11px] font-bold text-text-muted uppercase tracking-wider">Registro APDP</h4>
                    </div>
                    <?php if (!empty($config['apdpRegistered'])): ?>
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
                            <h4 class="text-[13px] font-semibold text-text-heading"><?= h($v['title']) ?></h4>
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
            <?php renderSectionHeader('Consentimientos', 'Gestión de consentimientos de titulares de datos'); ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Nuevo consentimiento</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="collection" value="consents">
                    <input type="text" name="fields[name]" required placeholder="Nombre del titular" class="input-premium">
                    <input type="email" name="fields[email]" required placeholder="Email" class="input-premium">
                    <input type="text" name="fields[purpose]" required placeholder="Finalidad del tratamiento" class="input-premium">
                    <div class="flex gap-2">
                        <input type="hidden" name="fields[active]" value="1">
                        <button type="submit" name="create_item" value="1" class="flex-1 px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Registrar</button>
                    </div>
                </form>
            </div>
            <?php renderComplianceList($items, 'consents', function ($it) {
                echo '<p class="text-[12px] font-medium text-text-heading truncate">' . h($it['name'] ?? 'Titular') . ' <span class="text-[10px] text-text-subtle">' . h($it['email'] ?? '') . '</span></p>';
                echo '<p class="text-[10px] text-text-subtle mt-0.5">Finalidad: ' . h($it['purpose'] ?? '-') . ' · ' . h(substr($it['createdAt'] ?? '', 0, 10)) . '</p>';
            }, function ($it) {
                $active = !empty($it['active']) && $it['active'] !== 'false' && empty($it['revokedAt']);
                echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border ' . ($active ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20') . '">' . ($active ? 'Activo' : 'Revocado') . '</span>';
                if ($active) renderActionBtn('consents', $it['_id'] ?? '', 'revoke', 'Revocar');
            }); ?>

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
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">Total</p>
                    <p class="text-[18px] font-bold text-white"><?= $totalItems ?></p>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">Bases de Datos</p>
                    <p class="text-[18px] font-bold text-cyan-400"><?= $dbItems ?></p>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">Archivos</p>
                    <p class="text-[18px] font-bold text-amber-400"><?= $fileItems ?></p>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">Datos Sensibles</p>
                    <p class="text-[18px] font-bold text-red-400"><?= $sensitiveItemsCount ?></p>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">Completos</p>
                    <p class="text-[18px] font-bold text-emerald-400"><?= $completeItems ?> / <?= $totalItems ?></p>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-3">
                    <p class="text-[9px] text-text-subtle uppercase tracking-wider">⚠️ Riesgo Alto</p>
                    <p class="text-[18px] font-bold text-yellow-400"><?= $riskCounts['high'] + $riskCounts['critical'] ?></p>
                </div>
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
                        <option value="low" <?= $filterRisk === 'low' ? 'selected' : '' ?>>🟢 Bajo</option>
                        <option value="medium" <?= $filterRisk === 'medium' ? 'selected' : '' ?>>🟡 Medio</option>
                        <option value="high" <?= $filterRisk === 'high' ? 'selected' : '' ?>>🟠 Alto</option>
                        <option value="critical" <?= $filterRisk === 'critical' ? 'selected' : '' ?>>🔴 Crítico</option>
                    </select>
                    
                    <!-- Filtro: Sensibles -->
                    <select id="filter-sensitive" class="bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="updateFilters()">
                        <option value="">Todos los datos</option>
                        <option value="1" <?= $filterSensitive === '1' ? 'selected' : '' ?>>🔒 Sensibles</option>
                        <option value="0" <?= $filterSensitive === '0' ? 'selected' : '' ?>>📄 No sensibles</option>
                    </select>
                    
                    <!-- Filtro: Origen -->
                    <select id="filter-source" class="bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="updateFilters()">
                        <option value="">Todos los orígenes</option>
                        <option value="database" <?= $filterSource === 'database' ? 'selected' : '' ?>>🗄️ Base de datos</option>
                        <option value="file" <?= $filterSource === 'file' ? 'selected' : '' ?>>📄 Archivo</option>
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

            <!-- ═══ FORMULARIO DE CREACIÓN (colapsable) ═══ -->
            <div id="inventory-create-form" class="hidden rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[12px] font-semibold text-white">📝 Nueva actividad de tratamiento</p>
                    <button onclick="document.getElementById('inventory-create-form').classList.add('hidden')" class="text-text-muted hover:text-text-heading">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                
                <div class="bg-cyan-500/[0.04] border border-cyan-500/20 rounded-lg p-3 mb-4 text-[10px] text-text-muted leading-relaxed">
                    <p><span class="text-cyan-400 font-semibold">📖 ¿Qué es una actividad de tratamiento?</span></p>
                    <p>Toda operación que realices con datos personales: recopilar, almacenar, usar, modificar, compartir o eliminar. 
                       Cada actividad debe registrarse con su finalidad, base legal y medidas de seguridad.</p>
                    <p class="mt-1"><span class="text-cyan-400">⚖️ Art. 14 Ley 21.719:</span> El responsable debe mantener un registro documentado de todas las actividades de tratamiento.</p>
                </div>
                
                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input type="hidden" name="create_inventory_item" value="1">
                    
                    <div>
                        <label class="label-premium">Nombre de la actividad *</label>
                        <input type="text" name="name" required class="input-premium w-full" placeholder="Ej: Gestión de clientes">
                        <p class="text-[8px] text-text-subtle mt-0.5">Identifica claramente qué tratamiento realizas.</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Finalidad / Propósito</label>
                        <input type="text" name="purpose" class="input-premium w-full" placeholder="Ej: Enviar facturación y promociones">
                        <p class="text-[8px] text-text-subtle mt-0.5">¿Para qué usas estos datos? (Art. 3 letra b)</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Categorías de datos</label>
                        <input type="text" name="dataCategories" class="input-premium w-full" placeholder="Ej: nombres, RUT, emails, teléfonos">
                        <p class="text-[8px] text-text-subtle mt-0.5">¿Qué tipo de datos personales tratas?</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Base de licitud *</label>
                        <select name="legalBasis" required class="input-premium w-full">
                            <option value="">Seleccionar...</option>
                            <option value="Consentimiento">✅ Consentimiento del titular (Art. 12)</option>
                            <option value="Ejecución de contrato">📄 Ejecución de contrato (Art. 13)</option>
                            <option value="Obligación legal">⚖️ Obligación legal (Art. 13)</option>
                            <option value="Interés legítimo">🎯 Interés legítimo (Art. 13)</option>
                            <option value="Interés público">🏛️ Interés público (Art. 13)</option>
                        </select>
                        <p class="text-[8px] text-text-subtle mt-0.5">Base legal que justifica el tratamiento. Sin esta, el tratamiento es ilegal.</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Nivel de riesgo</label>
                        <select name="risk" class="input-premium w-full">
                            <option value="low">🟢 Bajo - Datos básicos</option>
                            <option value="medium">🟡 Medio - Datos personales comunes</option>
                            <option value="high">🟠 Alto - Datos sensibles o muchos registros</option>
                            <option value="critical">🔴 Crítico - Datos muy sensibles (salud, biometría)</option>
                        </select>
                        <p class="text-[8px] text-text-subtle mt-0.5">Evalúa el impacto si estos datos se ven comprometidos.</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Datos sensibles</label>
                        <select name="sensitive" class="input-premium w-full">
                            <option value="0">❌ No</option>
                            <option value="1">🔒 Sí - Salud, biometría, religión, etc.</option>
                        </select>
                        <p class="text-[8px] text-text-subtle mt-0.5">Según Art. 16: datos de salud, origen racial, creencias, etc.</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Días de retención</label>
                        <input type="number" name="retentionDays" class="input-premium w-full" placeholder="Ej: 365">
                        <p class="text-[8px] text-text-subtle mt-0.5">¿Cuánto tiempo conservas estos datos? (Art. 14)</p>
                    </div>
                    
                    <div>
                        <label class="label-premium">Almacenamiento</label>
                        <input type="text" name="storage" class="input-premium w-full" placeholder="Ej: AWS, servidor local, Google Drive">
                        <p class="text-[8px] text-text-subtle mt-0.5">¿Dónde se guardan estos datos?</p>
                    </div>
                    
                    <div class="md:col-span-2 flex justify-end gap-2 mt-1">
                        <button type="button" onclick="document.getElementById('inventory-create-form').classList.add('hidden')" 
                                class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                        <button type="submit" class="px-4 py-1.5 text-[11px] font-medium rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all">Registrar actividad</button>
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
                            <p class="text-text-subtle text-[11px] mt-2">💡 <span class="text-cyan-400">Consejo:</span> Sube un archivo o conecta una base de datos para generar actividades automáticamente.</p>
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
                                        'critical' => ['text' => 'text-red-400', 'bg' => 'bg-red-500/15', 'border' => 'border-red-500/25', 'label' => '🔴 Crítico'],
                                        'high' => ['text' => 'text-yellow-400', 'bg' => 'bg-yellow-500/15', 'border' => 'border-yellow-500/25', 'label' => '🟠 Alto'],
                                        'medium' => ['text' => 'text-blue-400', 'bg' => 'bg-blue-500/15', 'border' => 'border-blue-500/25', 'label' => '🟡 Medio'],
                                        'low' => ['text' => 'text-text-muted', 'bg' => 'bg-bg-elevated/50', 'border' => 'border-border-theme', 'label' => '🟢 Bajo'],
                                    ];
                                    $rc = $riskColors[$risk] ?? $riskColors['low'];
                                    $dc = $it['dataCategories'] ?? '';
                                    if (is_array($dc)) $dc = implode(', ', $dc);
                                    $sourceType = $it['sourceType'] ?? 'database';
                                    $sourceLabel = $sourceType === 'file' ? '📄 Archivo' : '🗄️ Base de datos';
                                    $sourceId = $it['sourceId'] ?? null;
                                    $isComplete = !empty($it['name']) && !empty($it['legalBasis']) && !empty($it['dataCategories']);
                                ?>
                                <tr class="border-t border-border-theme/30 hover:bg-bg-base/40 transition-colors">
                                    <td class="py-2.5 px-3">
                                        <span class="text-[12px] font-medium text-text-heading"><?= h($it['name'] ?? 'Sin nombre') ?></span>
                                        <?php if (!$isComplete): ?>
                                            <span class="ml-1 text-[8px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">⚠️ Incompleto</span>
                                        <?php endif; ?>
                                        <?php if (!empty($it['purpose'])): ?>
                                            <span class="block text-[9px] text-text-subtle mt-0.5">🎯 <?= h($it['purpose']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-body max-w-[120px] truncate" title="<?= h($dc) ?>">
                                        <?= h($dc ?: '-') ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]"><?= h($it['legalBasis'] ?? '-') ?></td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $rc['bg'] ?> <?= $rc['text'] ?> <?= $rc['border'] ?>">
                                            <?= $rc['label'] ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <?php if (!empty($it['sensitive'])): ?>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 flex items-center gap-1 w-fit">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                                                Sensible
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[10px] text-text-subtle">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[10px] text-text-muted flex items-center gap-1">
                                            <?= $sourceLabel ?>
                                            <?php if ($sourceType === 'file' && $sourceId): ?>
                                                <a href="/compliance?tab=files" class="text-cyan-400 hover:text-cyan-300 text-[9px]">🔗</a>
                                            <?php elseif ($sourceType === 'database' && $sourceId): ?>
                                                <a href="/databases" class="text-cyan-400 hover:text-cyan-300 text-[9px]">🔗</a>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-muted text-[11px]">
                                        <?= !empty($it['retentionDays']) ? h($it['retentionDays'] . ' días') : '-' ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <!-- Ver Detalle -->
                                            <button onclick="openInventoryDetailModal('<?= h($it['_id'] ?? '') ?>')" 
                                                    class="p-1.5 rounded-lg text-text-muted hover:text-text-heading hover:bg-bg-elevated transition-all"
                                                    title="Ver detalle completo">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                            
                                            <!-- Editar -->
                                            <button onclick="openInventoryEditModal('<?= h($it['_id'] ?? '') ?>')" 
                                                    class="p-1.5 rounded-lg text-cyan-400 hover:text-cyan-300 hover:bg-cyan-500/10 transition-all"
                                                    title="Editar actividad">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
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
            <div id="inventory-edit-modal" class="hidden fixed inset-0 bg-black/75 flex items-center justify-center z-50 p-4">
                <div class="bg-bg-panel border border-border-theme rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
                    <!-- Header -->
                    <div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0">
                        <div>
                            <h3 class="text-[15px] font-semibold text-white">✏️ Editar actividad de tratamiento</h3>
                            <p class="text-[10px] text-text-subtle">Actualiza los datos de esta actividad según lo requerido por la Ley 21.719</p>
                        </div>
                        <button onclick="document.getElementById('inventory-edit-modal').classList.add('hidden')" 
                                class="text-text-muted hover:text-text-heading transition-colors p-1 rounded-lg">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    
                    <!-- Cuerpo con formulario -->
                    <div class="flex-1 overflow-y-auto p-5 scrollbar-custom">
                        <!-- Leyenda de ayuda (siempre visible) -->
                        <div class="bg-cyan-500/[0.04] border border-cyan-500/20 rounded-lg p-3 mb-4 text-[10px] text-text-muted leading-relaxed">
                            <p><span class="text-cyan-400 font-semibold">⚖️ ¿Por qué es importante este registro?</span></p>
                            <p>El Art. 14 de la Ley 21.719 exige que mantengas un <strong class="text-white">Registro de Actividades de Tratamiento (RAT)</strong> actualizado. 
                               Este registro es lo primero que revisará la APDP en una fiscalización.</p>
                            <p class="mt-1">Cada campo tiene un propósito legal. Completa toda la información posible para estar mejor protegido.</p>
                        </div>
                        
                        <form id="inventory-edit-form" method="POST" class="space-y-3">
                            <input type="hidden" name="update_inventory_item" value="1">
                            <input type="hidden" name="item_id" id="edit-item-id">
                            
                            <!-- Nombre -->
                            <div>
                                <label class="label-premium flex items-center gap-1">
                                    Nombre de la actividad <span class="text-red-400">*</span>
                                    <span class="text-[9px] text-text-subtle font-normal ml-1">(requerido)</span>
                                </label>
                                <input type="text" name="name" id="edit-name" required class="input-premium w-full" placeholder="Ej: Gestión de clientes">
                                <p class="text-[8px] text-text-subtle mt-0.5">📌 Identifica claramente qué tratamiento realizas. Debe ser específico.</p>
                            </div>
                            
                            <!-- Propósito -->
                            <div>
                                <label class="label-premium flex items-center gap-1">
                                    Finalidad / Propósito
                                    <span class="text-[9px] text-text-subtle font-normal">(Art. 3 letra b)</span>
                                </label>
                                <input type="text" name="purpose" id="edit-purpose" class="input-premium w-full" placeholder="Ej: Enviar facturación y promociones">
                                <p class="text-[8px] text-text-subtle mt-0.5">🎯 Define claramente para qué usas estos datos. La ley exige finalidades determinadas y explícitas.</p>
                            </div>
                            
                            <!-- Categorías -->
                            <div>
                                <label class="label-premium flex items-center gap-1">
                                    Categorías de datos
                                    <span class="text-[9px] text-text-subtle font-normal">(recomendado)</span>
                                </label>
                                <input type="text" name="dataCategories" id="edit-categories" class="input-premium w-full" placeholder="Ej: nombres, RUT, emails, teléfonos">
                                <p class="text-[8px] text-text-subtle mt-0.5">📋 Enumera los tipos de datos personales que tratas. Esto ayuda a clasificar el riesgo.</p>
                            </div>
                            
                            <!-- Base legal -->
                            <div>
                                <label class="label-premium flex items-center gap-1">
                                    Base de licitud <span class="text-red-400">*</span>
                                    <span class="text-[9px] text-text-subtle font-normal">(requerido)</span>
                                </label>
                                <select name="legalBasis" id="edit-legalBasis" required class="input-premium w-full">
                                    <option value="">Seleccionar...</option>
                                    <option value="Consentimiento">✅ Consentimiento del titular (Art. 12)</option>
                                    <option value="Ejecución de contrato">📄 Ejecución de contrato (Art. 13)</option>
                                    <option value="Obligación legal">⚖️ Obligación legal (Art. 13)</option>
                                    <option value="Interés legítimo">🎯 Interés legítimo (Art. 13)</option>
                                    <option value="Interés público">🏛️ Interés público (Art. 13)</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">⚖️ Sin una base legal válida, el tratamiento es ilegal. Elige la que corresponda a tu caso.</p>
                            </div>
                            
                            <!-- Riesgo -->
                            <div>
                                <label class="label-premium">Nivel de riesgo</label>
                                <select name="risk" id="edit-risk" class="input-premium w-full">
                                    <option value="low">🟢 Bajo - Datos básicos (nombres, teléfonos)</option>
                                    <option value="medium">🟡 Medio - Datos personales comunes (RUT, dirección)</option>
                                    <option value="high">🟠 Alto - Datos sensibles o muchos registros</option>
                                    <option value="critical">🔴 Crítico - Datos muy sensibles (salud, biometría)</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">📊 Evalúa el impacto si estos datos se ven comprometidos. A mayor riesgo, mayores medidas de seguridad.</p>
                            </div>
                            
                            <!-- Sensibles -->
                            <div>
                                <label class="label-premium">Datos sensibles</label>
                                <select name="sensitive" id="edit-sensitive" class="input-premium w-full">
                                    <option value="0">❌ No contiene datos sensibles</option>
                                    <option value="1">🔒 Sí - Salud, biometría, religión, origen racial, etc.</option>
                                </select>
                                <p class="text-[8px] text-text-subtle mt-0.5">🔐 Según Art. 16: datos de salud, origen racial, creencias religiosas, vida sexual, etc.</p>
                            </div>
                            
                            <!-- Retención -->
                            <div>
                                <label class="label-premium">Días de retención</label>
                                <input type="number" name="retentionDays" id="edit-retention" class="input-premium w-full" placeholder="Ej: 365" min="0">
                                <p class="text-[8px] text-text-subtle mt-0.5">📅 ¿Cuánto tiempo conservas estos datos? La ley exige que no se conserven más tiempo del necesario (Art. 14).</p>
                            </div>
                            
                            <!-- Almacenamiento -->
                            <div>
                                <label class="label-premium">Almacenamiento</label>
                                <input type="text" name="storage" id="edit-storage" class="input-premium w-full" placeholder="Ej: AWS, servidor local, Google Drive">
                                <p class="text-[8px] text-text-subtle mt-0.5">💾 ¿Dónde se guardan físicamente estos datos? Ayuda a identificar riesgos de seguridad.</p>
                            </div>
                            
                            <!-- Mensaje de estado -->
                            <div id="edit-msg" class="hidden p-3 rounded-lg text-[11px]"></div>
                            
                            <!-- Botones -->
                            <div class="flex justify-end gap-2 pt-2 border-t border-border-theme">
                                <button type="button" onclick="document.getElementById('inventory-edit-modal').classList.add('hidden')" 
                                        class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                                <button type="submit" class="px-4 py-1.5 text-[11px] font-medium rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white transition-all hover:from-blue-500 hover:to-indigo-500">
                                    💾 Guardar cambios
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
                    'critical': '🔴 Crítico',
                    'high': '🟠 Alto',
                    'medium': '🟡 Medio',
                    'low': '🟢 Bajo'
                };
                
                // Mapeo de origen
                const sourceLabels = {
                    'database': '🗄️ Base de datos',
                    'file': '📄 Archivo'
                };
                
                body.innerHTML = `
                    <!-- Estado de cumplimiento -->
                    <div class="rounded-lg p-3 ${isComplete ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-amber-500/10 border border-amber-500/20'}">
                        <div class="flex items-center gap-2">
                            <span>${isComplete ? '✅' : '⚠️'}</span>
                            <span class="text-[12px] font-semibold ${isComplete ? 'text-emerald-400' : 'text-amber-400'}">
                                ${isComplete ? 'Registro completo' : 'Registro incompleto'}
                            </span>
                        </div>
                        ${!isComplete ? `<p class="text-[10px] text-text-muted mt-1">Faltan campos obligatorios: ${missingFields.join(', ')}</p>` : ''}
                        <p class="text-[9px] text-text-subtle mt-1">${isComplete ? '✅ Cumple con los requisitos mínimos del Art. 14' : '⚠️ Completa los campos faltantes para estar al día con la ley'}</p>
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
                            ${item.legalBasis ? `<p class="text-[8px] text-text-subtle mt-0.5">⚖️ Art. ${item.legalBasis === 'Consentimiento' ? '12' : '13'} Ley 21.719</p>` : ''}
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Nivel de riesgo</p>
                            <p class="text-[13px] font-medium">${riskLabels[item.risk] || '🟢 Bajo'}</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Datos sensibles</p>
                            <p class="text-[13px] font-medium ${item.sensitive ? 'text-red-400' : 'text-text-muted'}">
                                ${item.sensitive ? '🔒 Sí - Requiere protección especial' : '📄 No'}
                            </p>
                            ${item.sensitive ? `<p class="text-[8px] text-text-subtle mt-0.5">🔐 Art. 16 - Requiere consentimiento explícito</p>` : ''}
                        </div>
                    </div>
                    
                    <!-- Información adicional -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Origen</p>
                            <p class="text-[13px] text-white flex items-center gap-2">
                                ${sourceLabels[item.sourceType] || '🗄️ Base de datos'}
                                ${item.sourceId ? `<a href="${item.sourceType === 'file' ? '/compliance?tab=files' : '/databases'}" class="text-[10px] text-cyan-400 hover:text-cyan-300">🔗 Ver origen</a>` : ''}
                            </p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-3">
                            <p class="text-[9px] text-text-subtle uppercase tracking-wider">Retención</p>
                            <p class="text-[13px] text-white">${item.retentionDays ? item.retentionDays + ' días' : 'No definida'}</p>
                            ${!item.retentionDays ? `<p class="text-[8px] text-text-subtle mt-0.5">⚠️ Recomendado: define un plazo de retención (Art. 14)</p>` : ''}
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
                            📋 <span>Consejos de cumplimiento para esta actividad</span>
                        </p>
                        <ul class="text-[10px] text-text-muted space-y-1 mt-1.5 list-disc list-inside">
                            ${!item.legalBasis ? '<li>⚠️ <span class="text-amber-400">Falta base legal:</span> Define si el tratamiento se basa en consentimiento, contrato, obligación legal, interés legítimo o interés público.</li>' : ''}
                            ${!item.dataCategories ? '<li>📋 <span class="text-amber-400">Faltan categorías:</span> Especifica qué tipos de datos personales tratas (nombres, RUT, emails, etc.).</li>' : ''}
                            ${!item.retentionDays ? '<li>📅 Define un plazo de retención para estos datos. La ley exige que no se conserven más tiempo del necesario.</li>' : ''}
                            ${item.sensitive ? '<li>🔐 <span class="text-red-400">Dato sensible detectado:</span> Asegúrate de tener consentimiento explícito por escrito y medidas de seguridad reforzadas.</li>' : ''}
                            ${item.risk === 'high' || item.risk === 'critical' ? '<li>🛡️ <span class="text-yellow-400">Riesgo alto/crítico:</span> Considera realizar una Evaluación de Impacto (DPIA) según Art. 14 quater.</li>' : ''}
                            ${isComplete ? '<li>✅ Este registro cumple con los requisitos mínimos del Art. 14 de la Ley 21.719.</li>' : ''}
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
                msg.textContent = '⏳ Guardando cambios...';
                msg.className = 'p-3 rounded-lg text-[11px] bg-blue-500/10 border border-blue-500/20 text-blue-400';
                
                try {
                    const res = await fetch('/api/compliance/inventory/' + formData.get('item_id'), {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        msg.textContent = '✅ ¡Cambios guardados correctamente! Recargando...';
                        msg.className = 'p-3 rounded-lg text-[11px] bg-emerald-500/10 border border-emerald-500/20 text-emerald-400';
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        msg.textContent = '❌ ' + (data.error || 'Error al guardar los cambios');
                        msg.className = 'p-3 rounded-lg text-[11px] bg-red-500/10 border border-red-500/20 text-red-400';
                    }
                } catch (e) {
                    msg.textContent = '❌ Error de conexión: ' + e.message;
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

            <?php elseif ($tab === 'breaches'): ?>
            <?php renderSectionHeader('Brechas', 'Registro de incidentes de seguridad y violaciones de datos'); ?>
            <div class="px-4 py-3 rounded-lg bg-red-500/[0.06] border border-red-500/20">
                <p class="text-[11px] text-text-body"><span class="font-semibold text-red-300">Ley 21.719:</span> Las brechas de seguridad deben notificarse a la APDP sin dilación indebida y, cuando proceda, a los titulares afectados.</p>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Reportar brecha</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="collection" value="breaches">
                    <input type="text" name="fields[title]" required placeholder="Título de la brecha" class="input-premium">
                    <input type="text" name="fields[description]" placeholder="Descripción" class="input-premium">
                    <select name="fields[severity]" class="input-premium">
                        <option value="low">Baja</option><option value="medium">Media</option>
                        <option value="high">Alta</option><option value="critical">Crítica</option>
                    </select>
                    <div class="flex gap-2">
                        <input type="hidden" name="fields[status]" value="open">
                        <button type="submit" name="create_item" value="1" class="flex-1 px-3 py-2 rounded-lg text-[11px] font-medium bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 transition-all">Reportar</button>
                    </div>
                </form>
            </div>
            <?php renderComplianceList($items, 'breaches', function ($it) {
                echo '<p class="text-[12px] font-medium text-text-heading truncate">' . h($it['title'] ?? 'Brecha') . '</p>';
                echo '<p class="text-[10px] text-text-subtle mt-0.5">' . h($it['description'] ?? '') . ' · Severidad: ' . h($it['severity'] ?? '-') . ' · ' . h(substr($it['createdAt'] ?? '', 0, 10)) . '</p>';
            }, function ($it) {
                $resolved = ($it['status'] ?? '') === 'resolved';
                echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border ' . ($resolved ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20') . '">' . ($resolved ? 'Resuelta' : 'Abierta') . '</span>';
                if (!$resolved) renderActionBtn('breaches', $it['_id'] ?? '', 'resolve', 'Resolver');
            }); ?>

            <?php elseif ($tab === 'dpia'): ?>
            <?php renderSectionHeader('Evaluación de Impacto — DPIA', 'Evaluación de riesgos para tratamientos de alto riesgo (Art. 14 quater / Art. 16)'); ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Nueva evaluación de impacto (DPIA)</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="collection" value="dpia">
                    <input type="text" name="fields[name]" required placeholder="Nombre del proyecto" class="input-premium">
                    <input type="text" name="fields[description]" placeholder="Descripción del tratamiento" class="input-premium">
                    <select name="fields[riskLevel]" class="input-premium">
                        <option value="low">Riesgo bajo</option><option value="medium">Riesgo medio</option><option value="high">Riesgo alto</option>
                    </select>
                    <button type="submit" name="create_item" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Crear DPIA</button>
                </form>
            </div>
            <?php renderComplianceList($items, 'dpia', function ($it) {
                echo '<p class="text-[12px] font-medium text-text-heading truncate">' . h($it['name'] ?? 'DPIA') . '</p>';
                echo '<p class="text-[10px] text-text-subtle mt-0.5">' . h($it['description'] ?? '') . ' · Riesgo: ' . h($it['riskLevel'] ?? '-') . '</p>';
            }, function ($it) {
                $approved = ($it['status'] ?? '') === 'approved';
                echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border ' . ($approved ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20') . '">' . ($approved ? 'Aprobada' : 'Pendiente') . '</span>';
                if (!$approved) renderActionBtn('dpia', $it['_id'] ?? '', 'approve', 'Aprobar');
            }); ?>

            <?php elseif ($tab === 'trainings'): ?>
            <?php renderSectionHeader('Capacitaciones Ley 21.719', 'Registro de empleados capacitados en protección de datos personales — Art. 28 letra c)'); ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Nueva capacitación</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input type="hidden" name="collection" value="trainings">
                    <input type="text" name="fields[title]" required placeholder="Título de la capacitación" class="input-premium">
                    <input type="text" name="fields[attendee]" placeholder="Participante / equipo" class="input-premium">
                    <input type="date" name="fields[date]" class="input-premium">
                    <button type="submit" name="create_item" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Registrar</button>
                </form>
            </div>
            <?php renderComplianceList($items, 'trainings', function ($it) {
                echo '<p class="text-[12px] font-medium text-text-heading truncate">' . h($it['title'] ?? 'Capacitación') . '</p>';
                echo '<p class="text-[10px] text-text-subtle mt-0.5">' . h($it['attendee'] ?? '') . ' · ' . h($it['date'] ?? substr($it['createdAt'] ?? '', 0, 10)) . '</p>';
            }, function ($it) {
                $done = !empty($it['completed']);
                echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border ' . ($done ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20') . '">' . ($done ? 'Completada' : 'Pendiente') . '</span>';
                if (!$done) renderActionBtn('trainings', $it['_id'] ?? '', 'complete', 'Completar');
            }); ?>

            <?php elseif ($tab === 'invites'): ?>
            <?php renderSectionHeader('Firmas', 'Invitaciones de firma electrónica de documentos de cumplimiento'); ?>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Nueva invitación de firma</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <input type="hidden" name="collection" value="invites">
                    <input type="text" name="fields[title]" required placeholder="Título del documento" class="input-premium">
                    <input type="text" name="fields[description]" placeholder="Descripción" class="input-premium">
                    <button type="submit" name="create_item" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Crear invitación</button>
                </form>
            </div>
            <?php renderComplianceList($items, 'invites', function ($it) {
                $host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
                echo '<p class="text-[12px] font-medium text-text-heading truncate">' . h($it['title'] ?? 'Documento') . '</p>';
                echo '<p class="text-[10px] text-text-subtle mt-0.5 break-all">Enlace: http://' . h($host) . ':8090/sign-invite?token=' . h($it['token'] ?? '') . '</p>';
                if (!empty($it['signed'])) {
                    echo '<p class="text-[10px] text-emerald-400 mt-0.5">Firmado por ' . h($it['signerName'] ?? '-') . ' el ' . h(substr($it['signedAt'] ?? '', 0, 16)) . '</p>';
                }
            }, function ($it) {
                $signed = !empty($it['signed']);
                echo '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border ' . ($signed ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-yellow-500/10 text-yellow-400 border-yellow-500/20') . '">' . ($signed ? 'Firmado' : 'Pendiente') . '</span>';
                if ($signed) renderActionBtn('invites', $it['_id'] ?? '', 'unsign', 'Anular firma');
            }); ?>

            <?php elseif ($tab === 'files'): ?>
            <!-- ═══ SECCIÓN ARCHIVOS (NUEVA) ═══ -->
            <?php
            // ─── Obtener lista de archivos ───
            $filesRes = api_get('/api/compliance/files', ['token' => $token]);
            $files = is_array($filesRes) && empty($filesRes['error']) ? $filesRes : [];
            $fileMsg = '';
            $fileErr = '';

            // ─── Procesar acciones POST ───
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
                        ?>
                        <div class="px-5 py-3 flex flex-col md:flex-row md:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[12px] font-medium text-text-heading truncate"><?= h($f['originalName'] ?? 'Archivo') ?></span>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $st['class'] ?>"><?= h($st['label']) ?></span>
                                    <span class="text-[10px] text-text-subtle"><?= h(number_format($f['size'] ?? 0)) ?> bytes</span>
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

            <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php
function renderSectionHeader($title, $desc) {
    echo '<div class="mb-1"><h3 class="text-[14px] md:text-[15px] font-semibold text-text-heading">' . h($title) . '</h3>';
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

require_once __DIR__ . '/../includes/footer.php';
?>