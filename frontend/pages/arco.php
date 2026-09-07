<?php
$pageTitle = 'Derechos ARCO';
$currentPage = 'arco';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {
    $res = api_post_form('/api/arco/requests/update', [
        'token' => $token,
        'requestId' => $_POST['request_id'] ?? '',
        'status' => $_POST['status'] ?? '',
        'response' => $_POST['response'] ?? '',
    ]);
    if (!empty($res['success'])) $msg = 'Solicitud actualizada.';
    else $err = $res['error'] ?? 'Error al actualizar.';
}

$reqRes = api_post_form('/api/arco/requests/list', ['token' => $token]);
$requests = is_array($reqRes) && empty($reqRes['error']) ? ($reqRes['requests'] ?? $reqRes) : [];
if (!is_array($requests)) $requests = [];

$typeCfg = [
    'acceso' => 'Acceso', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación',
    'oposicion' => 'Oposición', 'portabilidad' => 'Portabilidad', 'supresion' => 'Supresión', 'bloqueo' => 'Bloqueo',
];
$typeIcons = [
    'acceso' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
    'rectificacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
    'cancelacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    'oposicion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>',
    'portabilidad' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
    'supresion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    'bloqueo' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
];
$typeAccent = [
    'acceso' => 'text-sky-400 bg-sky-500/10 border-sky-500/25',
    'rectificacion' => 'text-violet-400 bg-violet-500/10 border-violet-500/25',
    'cancelacion' => 'text-rose-400 bg-rose-500/10 border-rose-500/25',
    'oposicion' => 'text-orange-400 bg-orange-500/10 border-orange-500/25',
    'portabilidad' => 'text-teal-400 bg-teal-500/10 border-teal-500/25',
    'supresion' => 'text-rose-400 bg-rose-500/10 border-rose-500/25',
    'bloqueo' => 'text-slate-300 bg-slate-500/10 border-slate-500/25',
];
$statusCfg = [
    'pending' => ['label' => 'Pendiente', 'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20', 'dot' => 'bg-amber-400', 'bar' => 'bg-amber-400'],
    'in_progress' => ['label' => 'En proceso', 'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20', 'dot' => 'bg-blue-400', 'bar' => 'bg-blue-400'],
    'completed' => ['label' => 'Completada', 'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'dot' => 'bg-emerald-400', 'bar' => 'bg-emerald-400'],
    'rejected' => ['label' => 'Rechazada', 'class' => 'bg-red-500/10 text-red-400 border-red-500/20', 'dot' => 'bg-red-400', 'bar' => 'bg-red-400'],
];
$pending = count(array_filter($requests, fn($r) => ($r['status'] ?? 'pending') === 'pending'));
$inProgress = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'in_progress'));
$completed = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'completed'));
$rejected = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'rejected'));

$typeCounts = [];
foreach ($requests as $r) {
    $k = $r['type'] ?? $r['tipo'] ?? '';
    if ($k) $typeCounts[$k] = ($typeCounts[$k] ?? 0) + 1;
}
arsort($typeCounts);

function arcoName($r) {
    if (!empty($r['solicitante']['nombre'])) return $r['solicitante']['nombre'];
    return $r['name'] ?? $r['requesterName'] ?? 'Titular';
}
function arcoEmail($r) {
    if (!empty($r['solicitante']['email'])) return $r['solicitante']['email'];
    return $r['email'] ?? $r['requesterEmail'] ?? '';
}
function arcoRut($r) {
    if (!empty($r['solicitante']['rut'])) return $r['solicitante']['rut'];
    return $r['rut'] ?? '';
}
function arcoInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $ini = mb_strtoupper(mb_substr($parts[0] ?? 'T', 0, 1));
    if (count($parts) > 1) $ini .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    return $ini;
}
function arcoBusinessDays($dateStr) {
    $ts = strtotime($dateStr ?: 'now');
    if (!$ts) return 0;
    $days = 0;
    $cur = strtotime(date('Y-m-d', $ts));
    $today = strtotime(date('Y-m-d'));
    while ($cur < $today) {
        $cur = strtotime('+1 day', $cur);
        $dow = (int)date('N', $cur);
        if ($dow < 6) $days++;
    }
    return $days;
}
$publicUrl = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
$publicUrl = ($publicUrl ?: '') . '/arco-solicitud';
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">

        <!-- Top App Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-blue-600/30 to-indigo-500/20 border border-blue-500/30 flex items-center justify-center text-blue-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        Derechos ARCO
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-mono bg-blue-500/10 text-blue-400 border border-blue-500/20">
                            Ley 21.719
                        </span>
                        <?= infoIcon('Portal para gestionar las solicitudes de Acceso, Rectificación, Cancelación, Oposición y Portabilidad de datos personales.') ?>
                    </h1>
                    <p class="text-[11px] text-text-muted">Gestión de solicitudes de Acceso, Rectificación, Cancelación, Oposición y Portabilidad</p>
                </div>
            </div>
            <div class="flex items-center gap-2.5">
                <button onclick="generateCompliancePDF('arco-requests')" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-emerald-500/20 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Descargar PDF
                </button>
                <a href="/arco-solicitud" target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-blue-500/20 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Formulario Público
                </a>
                <button onclick="location.reload()" title="Actualizar" class="px-3 py-2 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 sm:p-6 min-h-0 scrollbar-custom">
            <div class="max-w-[1400px] mx-auto space-y-4">

            <!-- Hero / Legal banner -->
            <div class="relative overflow-hidden rounded-2xl border border-blue-500/20 bg-gradient-to-br from-blue-950/50 via-bg-panel to-indigo-950/30">
                <div class="absolute -top-20 -right-20 w-64 h-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-24 -left-16 w-56 h-56 rounded-full bg-indigo-500/10 blur-3xl pointer-events-none"></div>
                <div class="relative p-5 sm:p-6 flex flex-col lg:flex-row lg:items-center gap-6">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></span>
                            <span class="text-[10px] font-semibold uppercase tracking-widest text-blue-300">Canal de derechos de los titulares</span>
                        </div>
                        <h2 class="text-lg font-bold text-white tracking-tight leading-snug">
                            Toda persona puede ejercer sus derechos sobre sus datos personales
                        </h2>
                        <p class="text-[12px] text-text-muted mt-1.5 leading-relaxed max-w-2xl">
                            La <span class="text-blue-300 font-medium">Ley 21.719</span> garantiza a los titulares ejercer sus derechos de forma gratuita.
                            Como responsable, debes dar respuesta dentro de <span class="text-amber-300 font-semibold">10 días hábiles</span> desde la recepción de la solicitud.
                            Las solicitudes ingresan a través del formulario público.
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-3.5">
                            <?php
                            $chips = [
                                ['acceso', 'Acceso'], ['rectificacion', 'Rectificación'], ['cancelacion', 'Cancelación'],
                                ['oposicion', 'Oposición'], ['portabilidad', 'Portabilidad'], ['bloqueo', 'Bloqueo'],
                            ];
                            foreach ($chips as [$key, $label]): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10px] font-medium border <?= $typeAccent[$key] ?>">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $typeIcons[$key] ?></svg>
                                <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex-shrink-0 w-full lg:w-[300px] space-y-3">
                        <div class="rounded-xl border border-amber-500/25 bg-amber-500/[0.07] p-3.5 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-500/15 border border-amber-500/25 flex items-center justify-center text-amber-400 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-amber-300">10 días hábiles</p>
                                <p class="text-[10px] text-amber-200/70 leading-snug">Plazo legal máximo de respuesta al titular</p>
                            </div>
                        </div>
                        <div class="rounded-xl border border-white/[0.08] bg-white/[0.03] p-3.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-text-subtle mb-1.5">Enlace del formulario público</p>
                            <div class="flex items-center gap-2">
                                <code class="flex-1 min-w-0 truncate text-[11px] font-mono text-blue-300 bg-blue-950/30 border border-blue-500/20 rounded-lg px-2.5 py-1.5" id="arco-public-url"><?= h($publicUrl) ?></code>
                                <button onclick="copyArcoUrl(this)" title="Copiar enlace" class="flex-shrink-0 px-2.5 py-1.5 rounded-lg bg-blue-600/20 border border-blue-500/30 text-blue-300 hover:bg-blue-600/30 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Toast / Alerts -->
            <?php if ($msg): ?>
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs shadow-theme-sm">
                <div class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= h($msg) ?></span>
                </div>
                <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-emerald-400 hover:text-emerald-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <?php if ($err): ?>
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs shadow-theme-sm">
                <div class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <span><?= h($err) ?></span>
                </div>
                <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-red-400 hover:text-red-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3">
                <div class="group bg-bg-panel/70 border border-border-theme rounded-2xl p-4 backdrop-blur-md flex items-center justify-between hover:border-surface-600 hover:-translate-y-0.5 transition-all duration-200">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Total</p>
                        <p class="text-2xl font-bold text-white font-mono mt-1"><?= count($requests) ?></p>
                        <p class="text-[10px] text-text-subtle mt-0.5">Solicitudes recibidas</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted group-hover:text-white transition-colors">
                        <svg class="w-4.5 h-4.5 w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                </div>

                <div class="group bg-bg-panel/70 border border-amber-500/15 rounded-2xl p-4 backdrop-blur-md flex items-center justify-between hover:border-amber-500/30 hover:-translate-y-0.5 transition-all duration-200">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-amber-400/90 tracking-wider">Pendientes</p>
                        <p class="text-2xl font-bold text-amber-400 font-mono mt-1"><?= $pending ?></p>
                        <p class="text-[10px] text-amber-200/50 mt-0.5">Requieren atención</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                    </div>
                </div>

                <div class="group bg-bg-panel/70 border border-blue-500/15 rounded-2xl p-4 backdrop-blur-md flex items-center justify-between hover:border-blue-500/30 hover:-translate-y-0.5 transition-all duration-200">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-blue-400/90 tracking-wider">En Proceso</p>
                        <p class="text-2xl font-bold text-blue-400 font-mono mt-1"><?= $inProgress ?></p>
                        <p class="text-[10px] text-blue-200/50 mt-0.5">Siendo gestionadas</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>

                <div class="group bg-bg-panel/70 border border-emerald-500/15 rounded-2xl p-4 backdrop-blur-md flex items-center justify-between hover:border-emerald-500/30 hover:-translate-y-0.5 transition-all duration-200">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-emerald-400/90 tracking-wider">Completadas</p>
                        <p class="text-2xl font-bold text-emerald-400 font-mono mt-1"><?= $completed ?></p>
                        <p class="text-[10px] text-emerald-200/50 mt-0.5">Respondidas al titular</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>

                <div class="group bg-bg-panel/70 border border-red-500/15 rounded-2xl p-4 backdrop-blur-md flex items-center justify-between hover:border-red-500/30 hover:-translate-y-0.5 transition-all duration-200">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-red-400/90 tracking-wider">Rechazadas</p>
                        <p class="text-2xl font-bold text-red-400 font-mono mt-1"><?= $rejected ?></p>
                        <p class="text-[10px] text-red-200/50 mt-0.5">Con fundamento legal</p>
                    </div>
                    <div class="w-9 h-9 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-400">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                </div>
            </div>

            <?php if (!empty($typeCounts)): ?>
            <!-- Distribution by right type -->
            <div class="flex items-center gap-2 flex-wrap px-1">
                <span class="text-[10px] font-semibold uppercase tracking-wider text-text-subtle">Por derecho ejercido:</span>
                <?php foreach ($typeCounts as $tk => $tc):
                    $tLabel = $typeCfg[$tk] ?? ucfirst($tk);
                    $tAccent = $typeAccent[$tk] ?? 'text-text-muted bg-white/5 border-white/10';
                ?>
                <button type="button" onclick="setArcoTypeFilter('<?= h($tk) ?>')" data-type="<?= h($tk) ?>"
                        class="arco-type-chip inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[10px] font-medium border transition-all <?= $tAccent ?> opacity-80 hover:opacity-100">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $typeIcons[$tk] ?? $typeIcons['acceso'] ?></svg>
                    <?= h($tLabel) ?> <span class="font-mono opacity-70">(<?= $tc ?>)</span>
                </button>
                <?php endforeach; ?>
                <button type="button" onclick="setArcoTypeFilter('')" id="arco-type-clear" class="hidden text-[10px] text-text-subtle hover:text-white underline underline-offset-2 transition-colors">Quitar filtro</button>
            </div>
            <?php endif; ?>

            <!-- Main Requests List -->
            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm flex flex-col min-h-[320px]">

                <!-- Search & Filter Controls -->
                <div class="p-3.5 border-b border-border-theme space-y-2.5 bg-bg-surface/30">
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle pointer-events-none">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" id="arco-search" placeholder="Buscar por titular, email, RUT, tracking ID..."
                               oninput="filterArcoList()"
                               class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/20 transition-all">
                    </div>

                    <div class="flex items-center gap-1 overflow-x-auto scrollbar-none py-0.5" id="arco-status-filters" role="tablist">
                        <button type="button" onclick="setArcoStatusFilter('all')" data-status="all"
                                class="arco-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap">
                            Todos (<?= count($requests) ?>)
                        </button>
                        <button type="button" onclick="setArcoStatusFilter('pending')" data-status="pending"
                                class="arco-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Pendientes (<?= $pending ?>)
                        </button>
                        <button type="button" onclick="setArcoStatusFilter('in_progress')" data-status="in_progress"
                                class="arco-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            En Proceso (<?= $inProgress ?>)
                        </button>
                        <button type="button" onclick="setArcoStatusFilter('completed')" data-status="completed"
                                class="arco-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Completadas (<?= $completed ?>)
                        </button>
                        <button type="button" onclick="setArcoStatusFilter('rejected')" data-status="rejected"
                                class="arco-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Rechazadas (<?= $rejected ?>)
                        </button>
                    </div>
                </div>

                <!-- Requests List -->
                <div class="p-2.5 space-y-2" id="arco-inbox-items">
                    <?php if (empty($requests)): ?>
                    <div class="flex flex-col items-center justify-center py-20 px-4 text-center space-y-4">
                        <div class="relative">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600/20 to-indigo-500/10 border border-blue-500/25 flex items-center justify-center text-blue-400">
                                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-blue-500/20 border border-blue-500/40 flex items-center justify-center">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                            </span>
                        </div>
                        <div class="max-w-sm">
                            <p class="text-sm font-semibold text-text-heading">Sin solicitudes ARCO</p>
                            <p class="text-[11px] text-text-subtle mt-1 leading-relaxed">Cuando un titular ejerza sus derechos a través del formulario público, su solicitud aparecerá aquí para ser gestionada.</p>
                        </div>
                        <a href="/arco-solicitud" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-semibold hover:from-blue-500 hover:to-indigo-500 transition-all shadow-theme-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Ver Formulario Público
                        </a>
                    </div>
                    <?php else: ?>
                    <?php foreach ($requests as $r):
                        $statusKey = $r['status'] ?? 'pending';
                        $st = $statusCfg[$statusKey] ?? $statusCfg['pending'];
                        $rid = $r['requestId'] ?? $r['_id'] ?? '';
                        $shortId = substr($rid, -6);
                        $dateStr = substr($r['createdAt'] ?? '', 0, 10);
                        $typeKey = $r['type'] ?? $r['tipo'] ?? '';
                        $reqType = $typeCfg[$typeKey] ?? ucfirst($typeKey ?: 'Solicitud');
                        $tAccent = $typeAccent[$typeKey] ?? 'text-text-muted bg-white/5 border-white/10';
                        $name = arcoName($r);
                        $email = arcoEmail($r);
                        $rut = arcoRut($r);
                        $initials = arcoInitials($name);
                        $searchText = mb_strtolower($name . ' ' . $email . ' ' . $rut . ' ' . $shortId . ' ' . $reqType);
                        $isClosed = in_array($statusKey, ['completed', 'rejected'], true);
                        $bdays = $isClosed ? 0 : arcoBusinessDays($dateStr);
                        if ($isClosed) {
                            $deadlineBadge = null;
                        } elseif ($bdays > 10) {
                            $deadlineBadge = ['label' => 'Plazo excedido · ' . $bdays . ' días hábiles', 'cls' => 'bg-red-500/10 text-red-400 border-red-500/30', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z'];
                        } elseif ($bdays >= 7) {
                            $deadlineBadge = ['label' => $bdays . ' días hábiles · próxima a vencer', 'cls' => 'bg-amber-500/10 text-amber-400 border-amber-500/30', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'];
                        } else {
                            $deadlineBadge = ['label' => $bdays . ' días hábiles transcurridos', 'cls' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/25', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'];
                        }
                    ?>
                    <div data-arco-item
                         data-status="<?= h($statusKey) ?>"
                         data-type="<?= h($typeKey) ?>"
                         data-search="<?= h($searchText) ?>"
                         class="group relative rounded-xl border transition-all duration-200 border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600 hover:shadow-theme-sm overflow-hidden">
                        <span class="absolute left-0 top-0 bottom-0 w-[3px] <?= $st['bar'] ?> opacity-60"></span>

                        <!-- Card Header -->
                        <div class="p-3.5 pl-4">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-xl flex items-center justify-center border text-[11px] font-bold flex-shrink-0 <?= $tAccent ?>">
                                    <?= h($initials) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="text-[13px] font-semibold text-text-heading leading-snug"><?= h($name) ?></h3>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-medium border <?= $tAccent ?>">
                                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $typeIcons[$typeKey] ?? $typeIcons['acceso'] ?></svg>
                                            <?= h($reqType) ?>
                                        </span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $st['dot'] ?>"></span>
                                            <?= h($st['label']) ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3 flex-wrap mt-1">
                                        <?php if ($email): ?>
                                        <span class="text-[11px] text-text-muted flex items-center gap-1">
                                            <svg class="w-3 h-3 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                            <?= h($email) ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($rut): ?>
                                        <span class="text-[11px] text-text-muted font-mono flex items-center gap-1">
                                            <svg class="w-3 h-3 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0"/></svg>
                                            <?= h($rut) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($r['description']) || !empty($r['details']) || !empty($r['descripcion'])): ?>
                                    <p class="text-[11px] text-text-muted mt-1.5 line-clamp-2 leading-relaxed"><?= h($r['description'] ?? $r['details'] ?? $r['descripcion'] ?? '') ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                    <span class="text-[9px] font-mono text-blue-400 font-medium px-1.5 py-0.5 rounded bg-blue-950/40 border border-blue-500/20">
                                        #AR-<?= h(strtoupper($shortId)) ?>
                                    </span>
                                    <span class="text-[9px] font-mono text-text-subtle"><?= h($dateStr) ?></span>
                                    <?php if ($deadlineBadge): ?>
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-medium border <?= $deadlineBadge['cls'] ?>">
                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $deadlineBadge['icon'] ?>"/></svg>
                                        <?= h($deadlineBadge['label']) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="px-3.5 py-2.5 pl-4 border-t border-white/[0.04] bg-white/[0.01] flex items-center justify-between gap-2">
                            <span class="text-[10px] text-text-subtle font-mono truncate">ID: <?= h($rid) ?></span>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <a href="/api-proxy.php?path=/api/arco/requests/<?= h($rid) ?>/document" target="_blank"
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-white/[0.04] border border-white/[0.07] text-text-muted hover:text-white hover:bg-white/[0.08] transition-all inline-flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    PDF
                                </a>
                                <button onclick="toggleArcoResp('<?= h($rid) ?>')"
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-blue-600/15 border border-blue-500/25 text-blue-300 hover:bg-blue-600/25 transition-all">
                                    Responder
                                </button>
                            </div>
                        </div>

                        <!-- Expandable Response Panel -->
                        <div id="arco-resp-<?= h($rid) ?>" class="hidden border-t border-white/[0.06] bg-gradient-to-b from-blue-500/[0.05] via-indigo-500/[0.02] to-transparent">
                            <form method="POST" class="p-4 space-y-4">
                                <input type="hidden" name="request_id" value="<?= h($rid) ?>">

                                <!-- Panel header -->
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-7 h-7 rounded-lg bg-blue-500/15 border border-blue-500/25 flex items-center justify-center text-blue-400">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-[12px] font-semibold text-text-heading">Respuesta y gestión</p>
                                            <p class="text-[10px] text-text-subtle">Se adjunta al documento formal que recibe el titular</p>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center gap-1.5 text-[9px] px-2 py-1 rounded-lg bg-amber-500/10 border border-amber-500/25 text-amber-300 font-medium">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Plazo legal: 10 días hábiles
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-[220px_1fr] gap-4">
                                    <!-- Status selector -->
                                    <div class="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
                                        <label class="flex items-center gap-1.5 text-[9px] font-semibold text-text-subtle uppercase tracking-wider mb-2">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Estado de la solicitud
                                        </label>
                                        <select name="status" class="input-premium w-full text-[11px]">
                                            <?php foreach ($statusCfg as $val => $cfg): ?>
                                            <option value="<?= $val ?>" <?= $statusKey === $val ? 'selected' : '' ?>><?= h($cfg['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="text-[9px] text-text-subtle mt-2 leading-relaxed">Marca "En proceso" mientras gestionas y "Completada" al entregar la respuesta.</p>
                                    </div>

                                    <!-- Response textarea -->
                                    <div class="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
                                        <label class="flex items-center gap-1.5 text-[9px] font-semibold text-text-subtle uppercase tracking-wider mb-2">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                            Respuesta al titular
                                        </label>
                                        <textarea name="response" rows="4" placeholder="Ej.: Se adjunta copia de los datos personales registrados a nombre del titular, conforme al derecho de acceso (Art. 8)..." class="input-premium w-full resize-none text-[12px] leading-relaxed"><?= h($r['response'] ?? '') ?></textarea>
                                        <p class="text-[9px] text-text-subtle mt-1.5 leading-relaxed">Redacta una respuesta clara y con fundamento. Si rechazas la solicitud, indica la causal legal.</p>
                                    </div>
                                </div>

                                <!-- Actions footer -->
                                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-1 border-t border-white/[0.05]">
                                    <a href="/api-proxy.php?path=/api/arco/requests/<?= h($rid) ?>/document" target="_blank"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-[11px] font-medium bg-white/[0.04] border border-white/[0.08] text-text-muted hover:text-white hover:bg-white/[0.08] transition-all w-fit">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        Descargar documento formal (PDF)
                                    </a>
                                    <div class="flex items-center gap-2">
                                        <?php if ($typeKey === 'portabilidad'): ?>
                                        <a href="/api-proxy.php?path=/api/arco/requests/export-portabilidad&requestId=<?= h($rid) ?>&format=json"
                                            class="px-3 py-2 rounded-lg text-[11px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all flex items-center gap-1.5 whitespace-nowrap">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            JSON
                                        </a>
                                        <a href="/api-proxy.php?path=/api/arco/requests/export-portabilidad&requestId=<?= h($rid) ?>&format=csv"
                                            class="px-3 py-2 rounded-lg text-[11px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all flex items-center gap-1.5 whitespace-nowrap">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            CSV
                                        </a>
                                        <?php endif; ?>
                                        <button type="submit" name="update_request" value="1" class="px-5 py-2 rounded-lg text-[11px] font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 text-white hover:from-blue-500 hover:to-indigo-500 transition-all shadow-theme-sm flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Guardar respuesta
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            </div>
        </div>
    </main>
</div>

<script>
let arcoTypeFilter = '';

function filterArcoList() {
    const search = document.getElementById('arco-search').value.toLowerCase();
    const statusFilter = document.querySelector('.arco-status-tab-btn.bg-primary-500\\/15')?.dataset.status || 'all';
    document.querySelectorAll('[data-arco-item]').forEach(item => {
        const matchSearch = (item.dataset.search || '').includes(search);
        const matchStatus = statusFilter === 'all' || item.dataset.status === statusFilter;
        const matchType = !arcoTypeFilter || item.dataset.type === arcoTypeFilter;
        item.style.display = (matchSearch && matchStatus && matchType) ? '' : 'none';
    });
}

function setArcoStatusFilter(status) {
    document.querySelectorAll('.arco-status-tab-btn').forEach(btn => {
        btn.classList.remove('bg-primary-500/15', 'text-primary-300', 'border-primary-500/30');
        btn.classList.add('text-text-muted', 'hover:text-white', 'hover:bg-white/[0.04]', 'border-transparent');
    });
    const active = document.querySelector('.arco-status-tab-btn[data-status="' + status + '"]');
    if (active) {
        active.classList.remove('text-text-muted', 'hover:text-white', 'hover:bg-white/[0.04]', 'border-transparent');
        active.classList.add('bg-primary-500/15', 'text-primary-300', 'border-primary-500/30');
    }
    filterArcoList();
}

function setArcoTypeFilter(type) {
    arcoTypeFilter = (arcoTypeFilter === type) ? '' : type;
    document.querySelectorAll('.arco-type-chip').forEach(chip => {
        chip.classList.toggle('ring-1', chip.dataset.type === arcoTypeFilter);
        chip.classList.toggle('ring-current', chip.dataset.type === arcoTypeFilter);
        chip.classList.toggle('opacity-100', chip.dataset.type === arcoTypeFilter);
        chip.classList.toggle('opacity-80', chip.dataset.type !== arcoTypeFilter);
    });
    const clear = document.getElementById('arco-type-clear');
    if (clear) clear.classList.toggle('hidden', !arcoTypeFilter);
    filterArcoList();
}

function toggleArcoResp(id) {
    const el = document.getElementById('arco-resp-' + id);
    if (el) el.classList.toggle('hidden');
}

function copyArcoUrl(btn) {
    const text = document.getElementById('arco-public-url')?.textContent?.trim();
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        setTimeout(() => { btn.innerHTML = orig; }, 1500);
    });
}

async function generateCompliancePDF(resource) {
    const token = '<?= $token ?>';
    const btn = event.target.closest('button');
    const originalText = btn.innerHTML;

    try {
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Generando...';

        const response = await fetch(`/api-proxy.php?path=${encodeURIComponent('/api/compliance/' + resource + '/pdf')}&token=${encodeURIComponent(token)}`, {
            method: 'GET'
        });

        const data = await response.json();

        if (data.success) {
            if (data.pdfUrl) {
                window.open(data.pdfUrl, '_blank');
                alert('PDF generado exitosamente');
            } else if (data.html) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(data.html);
                printWindow.document.close();
                printWindow.onload = function() {
                    printWindow.print();
                };
                alert('PDF no disponible. Se abrirá una ventana para imprimir el documento.');
            }
        } else {
            alert('Error al generar PDF: ' + (data.error || 'Error desconocido'));
        }
    } catch (error) {
        console.error('Error generating PDF:', error);
        alert('Error al generar PDF: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
