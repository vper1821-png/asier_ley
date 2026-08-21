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
    'oposicion' => 'Oposición', 'portabilidad' => 'Portabilidad',
];
$typeIcons = [
    'acceso' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
    'rectificacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
    'cancelacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    'oposicion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>',
    'portabilidad' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
];
$statusCfg = [
    'pending' => ['label' => 'Pendiente', 'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20', 'dot' => 'bg-amber-400'],
    'in_progress' => ['label' => 'En proceso', 'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20', 'dot' => 'bg-blue-400'],
    'completed' => ['label' => 'Completada', 'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'dot' => 'bg-emerald-400'],
    'rejected' => ['label' => 'Rechazada', 'class' => 'bg-red-500/10 text-red-400 border-red-500/20', 'dot' => 'bg-red-400'],
];
$pending = count(array_filter($requests, fn($r) => ($r['status'] ?? 'pending') === 'pending'));
$inProgress = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'in_progress'));
$completed = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'completed'));
$rejected = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'rejected'));

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
                    </h1>
                    <p class="text-[11px] text-text-muted">Gestión de solicitudes de Acceso, Rectificación, Cancelación, Oposición y Portabilidad</p>
                </div>
            </div>
            <div class="flex items-center gap-2.5">
                <a href="/arco-solicitud" target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-blue-500/20 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Formulario Público
                </a>
                <button onclick="location.reload()" class="px-3 py-2 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-hidden flex flex-col p-4 sm:p-6 min-h-0 space-y-4">

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 flex-shrink-0">
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Total</p>
                        <p class="text-xl font-bold text-white font-mono mt-0.5"><?= count($requests) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                </div>

                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-amber-400/90 tracking-wider">Pendientes</p>
                        <p class="text-xl font-bold text-amber-400 font-mono mt-0.5"><?= $pending ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                    </div>
                </div>

                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-blue-400/90 tracking-wider">En Proceso</p>
                        <p class="text-xl font-bold text-blue-400 font-mono mt-0.5"><?= $inProgress ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>

                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-emerald-400/90 tracking-wider">Completadas</p>
                        <p class="text-xl font-bold text-emerald-400 font-mono mt-0.5"><?= $completed ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>

                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-red-400/90 tracking-wider">Rechazadas</p>
                        <p class="text-xl font-bold text-red-400 font-mono mt-0.5"><?= $rejected ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                </div>
            </div>

            <!-- Toast / Alerts -->
            <?php if ($msg): ?>
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs shadow-theme-sm flex-shrink-0">
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
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs shadow-theme-sm flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <span><?= h($err) ?></span>
                </div>
                <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-red-400 hover:text-red-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- Info banner -->
            <div class="px-4 py-3 rounded-xl bg-blue-500/[0.06] border border-blue-500/20 flex-shrink-0">
                <p class="text-[11px] text-text-body leading-relaxed">
                    <span class="font-semibold text-blue-300">Ley 21.719:</span> Debes responder las solicitudes ARCO dentro de un plazo máximo de <span class="font-semibold">30 días corridos</span>. Las solicitudes no atendidas pueden derivar en sanciones de la ANPD.
                </p>
            </div>

            <!-- Main Requests List -->
            <div class="flex-1 min-h-0 bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm flex flex-col">
                
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

                    <div class="flex items-center gap-1 overflow-x-auto scrollbar-none py-0.5" id="arco-status-filters">
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

                <!-- Requests Scrollable List -->
                <div class="flex-1 overflow-y-auto p-2.5 space-y-2 scrollbar-custom" id="arco-inbox-items">
                    <?php if (empty($requests)): ?>
                    <div class="flex flex-col items-center justify-center py-16 px-4 text-center space-y-3">
                        <div class="w-12 h-12 rounded-2xl bg-white/[0.02] border border-white/[0.06] flex items-center justify-center text-text-subtle">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-text-heading">Sin solicitudes ARCO</p>
                            <p class="text-[11px] text-text-subtle mt-0.5">Comparte el formulario público para que los titulares ejerzan sus derechos</p>
                        </div>
                        <a href="/arco-solicitud" target="_blank" class="px-3 py-1.5 rounded-lg bg-blue-600/20 text-blue-300 border border-blue-500/30 text-xs font-medium hover:bg-blue-600/30 transition-colors">
                            Ver Formulario Público
                        </a>
                    </div>
                    <?php else: ?>
                    <?php foreach ($requests as $r):
                        $st = $statusCfg[$r['status'] ?? 'pending'] ?? $statusCfg['pending'];
                        $rid = $r['requestId'] ?? $r['_id'] ?? '';
                        $shortId = substr($rid, -6);
                        $dateStr = substr($r['createdAt'] ?? '', 0, 10);
                        $reqType = $typeCfg[$r['type'] ?? $r['tipo'] ?? ''] ?? ucfirst($r['type'] ?? $r['tipo'] ?? 'Solicitud');
                        $typeKey = $r['type'] ?? $r['tipo'] ?? '';
                        $name = arcoName($r);
                        $email = arcoEmail($r);
                        $rut = arcoRut($r);
                        $searchText = mb_strtolower($name . ' ' . $email . ' ' . $rut . ' ' . $shortId . ' ' . $reqType);
                    ?>
                    <div data-arco-item
                         data-status="<?= h($r['status'] ?? 'pending') ?>"
                         data-search="<?= h($searchText) ?>"
                         class="group rounded-xl border transition-all duration-200 border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600 overflow-hidden">
                        
                        <!-- Card Header -->
                        <div class="p-3.5">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center border <?= $st['class'] ?>">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $typeIcons[$typeKey] ?? $typeIcons['acceso'] ?></svg>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[9px] font-mono text-blue-400 font-medium px-1.5 py-0.5 rounded bg-blue-950/40 border border-blue-500/20">
                                                #AR-<?= h(strtoupper($shortId)) ?>
                                            </span>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                                <span class="w-1.5 h-1.5 rounded-full <?= $st['dot'] ?>"></span>
                                                <?= h($st['label']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[9px] font-mono text-text-subtle"><?= h($dateStr) ?></span>
                            </div>

                            <!-- Titular Info -->
                            <div class="ml-10 space-y-1">
                                <h3 class="text-xs font-semibold text-text-heading leading-snug">
                                    <?= h($reqType) ?> — <?= h($name) ?>
                                </h3>
                                <div class="flex items-center gap-3 flex-wrap">
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
                                <p class="text-[11px] text-text-muted mt-1 line-clamp-2"><?= h($r['description'] ?? $r['details'] ?? $r['descripcion'] ?? '') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="px-3.5 py-2.5 border-t border-white/[0.04] bg-white/[0.01] flex items-center justify-between">
                            <span class="text-[10px] text-text-subtle font-mono">ID: <?= h($rid) ?></span>
                            <div class="flex items-center gap-1.5">
                                <button onclick="toggleArcoResp('<?= h($rid) ?>')"
                                    class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">
                                    Responder
                                </button>
                            </div>
                        </div>

                        <!-- Expandable Response Form -->
                        <div id="arco-resp-<?= h($rid) ?>" class="hidden px-3.5 pb-3.5 pt-1 border-t border-white/[0.04]">
                            <form method="POST" class="flex flex-col md:flex-row gap-2">
                                <input type="hidden" name="request_id" value="<?= h($rid) ?>">
                                <select name="status" class="input-premium md:w-40">
                                    <?php foreach ($statusCfg as $val => $cfg): ?>
                                    <option value="<?= $val ?>" <?= ($r['status'] ?? 'pending') === $val ? 'selected' : '' ?>><?= h($cfg['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="response" placeholder="Respuesta al titular..." class="input-premium flex-1" value="<?= h($r['response'] ?? '') ?>">
                                <button type="submit" name="update_request" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 text-white hover:from-blue-500 hover:to-indigo-500 transition-all">Guardar</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function filterArcoList() {
    const search = document.getElementById('arco-search').value.toLowerCase();
    const statusFilter = document.querySelector('.arco-status-tab-btn.bg-primary-500\\/15')?.dataset.status || 'all';
    document.querySelectorAll('[data-arco-item]').forEach(item => {
        const matchSearch = (item.dataset.search || '').includes(search);
        const matchStatus = statusFilter === 'all' || item.dataset.status === statusFilter;
        item.style.display = (matchSearch && matchStatus) ? '' : 'none';
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

function toggleArcoResp(id) {
    const el = document.getElementById('arco-resp-' + id);
    if (el) el.classList.toggle('hidden');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
