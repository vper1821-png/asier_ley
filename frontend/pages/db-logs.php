<?php
$pageTitle = 'Logs DB';
$currentPage = 'db-logs';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
$statsRes = api_post_form('/api/databases/logs/stats', ['token' => $token]);
$stats = is_array($statsRes) && empty($statsRes['error']) ? $statsRes : [];
$limit = 100;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filtros server-side (aplican a todo el histórico, no solo a la página cargada)
$fSearch = trim($_GET['q'] ?? '');
$fOp = trim($_GET['operation'] ?? '');
$fDb = trim($_GET['database'] ?? '');
$fEngine = trim($_GET['engine'] ?? '');
$fRisk = trim($_GET['risk'] ?? '');

$logsRes = api_post_form('/api/databases/logs/list', [
    'token' => $token,
    'limit' => $limit,
    'offset' => $offset,
    'search' => $fSearch,
    'operation' => $fOp,
    'database' => $fDb,
    'engine' => $fEngine,
    'risk' => $fRisk,
]);
$logs = is_array($logsRes) && empty($logsRes['error']) ? ($logsRes['logs'] ?? $logsRes) : [];
if (!is_array($logs)) $logs = [];
$total = (int)($logsRes['total'] ?? count($logs));
$totalPages = max(1, (int)ceil($total / $limit));

$operations = [];
$databases = [];
$engines = [];
$riskCount = 0;
$selects = 0;
$writes = 0;
$ddl = 0;
foreach ($logs as $log) {
    $operation = strtoupper($log['operation'] ?? strtok(trim($log['query'] ?? ''), " \t\r\n") ?: 'QUERY');
    $database = $log['database'] ?? $log['databaseName'] ?? 'Sin base';
    $engine = $log['engine'] ?? 'database';
    $operations[$operation] = ($operations[$operation] ?? 0) + 1;
    $databases[$database] = true;
    $engines[$engine] = true;
    if ((float)($log['riskScore'] ?? 0) > 0) $riskCount++;
    if (in_array($operation, ['SELECT', 'SHOW', 'DESCRIBE'], true)) $selects++;
    if (in_array($operation, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) $writes++;
    if (in_array($operation, ['CREATE', 'ALTER', 'DROP', 'TRUNCATE'], true)) $ddl++;
}
arsort($operations);
// Asegurar que los valores filtrados actualmente aparezcan en los selects
if ($fOp !== '' && !isset($operations[$fOp])) $operations = [$fOp => 0] + $operations;
if ($fDb !== '' && !isset($databases[$fDb])) $databases[$fDb] = true;
if ($fEngine !== '' && !isset($engines[$fEngine])) $engines[$fEngine] = true;

// Querystring para mantener los filtros en la paginación
$filterQS = function ($extra = []) {
    return http_build_query(array_filter(array_merge([
        'q' => $GLOBALS['fSearch'] ?? '',
        'operation' => $GLOBALS['fOp'] ?? '',
        'database' => $GLOBALS['fDb'] ?? '',
        'engine' => $GLOBALS['fEngine'] ?? '',
        'risk' => $GLOBALS['fRisk'] ?? '',
    ], $extra), fn($v) => $v !== '' && $v !== null));
};

function dbLogTypeConfig($operation) {
    return match (strtoupper($operation)) {
        'SELECT', 'SHOW', 'DESCRIBE' => [
            'dot' => 'bg-sky-400', 'text' => 'text-sky-300', 'badge' => 'bg-sky-500/10 border-sky-500/25',
            'border' => 'border-sky-500/25 hover:border-sky-500/45', 'bg' => 'bg-sky-500/[0.03] hover:bg-sky-500/[0.06]',
            'iconBox' => 'bg-sky-500/15 border-sky-500/30 text-sky-400',
        ],
        'INSERT', 'REPLACE' => [
            'dot' => 'bg-emerald-400', 'text' => 'text-emerald-300', 'badge' => 'bg-emerald-500/10 border-emerald-500/25',
            'border' => 'border-emerald-500/25 hover:border-emerald-500/45', 'bg' => 'bg-emerald-500/[0.03] hover:bg-emerald-500/[0.06]',
            'iconBox' => 'bg-emerald-500/15 border-emerald-500/30 text-emerald-400',
        ],
        'UPDATE' => [
            'dot' => 'bg-amber-400', 'text' => 'text-amber-300', 'badge' => 'bg-amber-500/10 border-amber-500/25',
            'border' => 'border-amber-500/25 hover:border-amber-500/45', 'bg' => 'bg-amber-500/[0.03] hover:bg-amber-500/[0.06]',
            'iconBox' => 'bg-amber-500/15 border-amber-500/30 text-amber-400',
        ],
        'DELETE', 'DROP', 'TRUNCATE' => [
            'dot' => 'bg-red-400', 'text' => 'text-red-300', 'badge' => 'bg-red-500/10 border-red-500/25',
            'border' => 'border-red-500/30 hover:border-red-500/50', 'bg' => 'bg-red-500/[0.04] hover:bg-red-500/[0.07]',
            'iconBox' => 'bg-red-500/15 border-red-500/30 text-red-400',
        ],
        'CREATE', 'ALTER' => [
            'dot' => 'bg-violet-400', 'text' => 'text-violet-300', 'badge' => 'bg-violet-500/10 border-violet-500/25',
            'border' => 'border-violet-500/25 hover:border-violet-500/45', 'bg' => 'bg-violet-500/[0.03] hover:bg-violet-500/[0.06]',
            'iconBox' => 'bg-violet-500/15 border-violet-500/30 text-violet-400',
        ],
        default => [
            'dot' => 'bg-slate-400', 'text' => 'text-slate-300', 'badge' => 'bg-slate-500/10 border-slate-500/25',
            'border' => 'border-border-theme hover:border-surface-600', 'bg' => 'bg-bg-panel/40 hover:bg-bg-panel/70',
            'iconBox' => 'bg-slate-500/15 border-slate-500/30 text-slate-400',
        ],
    };
}
function dbLogIcon($operation, $cls = 'w-4 h-4') {
    $paths = [
        'SELECT' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        'SHOW' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        'INSERT' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/>',
        'UPDATE' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
        'DELETE' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        'DROP' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        'TRUNCATE' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        'CREATE' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>',
        'ALTER' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
    ];
    $p = $paths[strtoupper($operation)] ?? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>';
    return '<svg class="' . $cls . '" fill="none" viewBox="0 0 24 24" stroke="currentColor">' . $p . '</svg>';
}
$totalEvents = (int)($stats['total'] ?? $total);
$selectTotal = (int)($stats['selects'] ?? $selects);
$writeTotal = (int)($stats['writes'] ?? $writes);
$riskTotal = (int)($stats['suspicious'] ?? $riskCount);
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header -->
        <header class="flex-shrink-0 border-b border-border-theme px-4 md:px-8 pt-4 pb-3 bg-bg-base">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-400 border border-violet-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    </div>
                    <div>
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider font-medium">Auditoría SQL · Ley 21.719 Art. 25</p>
                        <h1 class="text-[18px] md:text-[20px] font-bold text-text-heading tracking-tight inline-flex items-center gap-2">Actividad de Bases de Datos <?= infoIcon('Registro de consultas capturadas por los agentes: operación, usuario, base de datos, origen y nivel de riesgo.') ?></h1>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="hidden lg:inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-white/[0.05] bg-white/[0.03] text-[10px] text-text-muted"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span><?= count($logs) ?> eventos cargados</span>
                    <a href="/api-proxy.php?path=<?= urlencode('/api/databases/logs/download') ?>&amp;<?= h($filterQS()) ?>"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 border border-emerald-500/25 transition-all" title="Descargar todos los logs (con los filtros actuales) en CSV">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Descargar CSV
                    </a>
                    <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] transition-all" title="Refrescar">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Refrescar
                    </button>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 md:gap-3 mb-4">
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-slate-500/10 border border-slate-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-white"><?= $totalEvents ?></p><p class="text-[9px] text-text-muted">Total eventos</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-sky-400"><?= $selectTotal ?></p><p class="text-[9px] text-text-muted">Lecturas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-amber-400"><?= $writeTotal ?></p><p class="text-[9px] text-text-muted">Escrituras</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-violet-500/10 border border-violet-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-violet-400"><?= $ddl ?></p><p class="text-[9px] text-text-muted">Estructura (DDL)</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    </div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-red-400"><?= $riskTotal ?></p><p class="text-[9px] text-text-muted">Con riesgo</p></div>
                </div>
            </div>

            <?php if (!empty($operations)): ?>
            <!-- Operation Distribution -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-[12px] font-semibold text-text-heading">Distribución de operaciones</h3>
                    <span class="text-[10px] text-text-subtle"><?= count($operations) ?> tipos detectados</span>
                </div>
                <div class="space-y-2">
                    <?php $maxOperation = max($operations); $opTotal = array_sum($operations); foreach (array_slice($operations, 0, 6, true) as $operation => $count):
                        $tc = dbLogTypeConfig($operation);
                        $pct = $opTotal > 0 ? round(($count / $opTotal) * 100) : 0;
                    ?>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-[11px] text-text-body flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full <?= $tc['dot'] ?>"></span>
                                <span class="font-mono font-semibold <?= $tc['text'] ?>"><?= h($operation) ?></span>
                            </span>
                            <span class="text-[11px] font-medium <?= $tc['text'] ?>"><?= $count ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="w-full bg-bg-elevated rounded-full h-1.5">
                            <div class="h-1.5 rounded-full <?= $tc['dot'] ?> transition-all" style="width: <?= max(2, $pct) ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters & Search -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/40 p-3 md:p-4">
                <form method="get" class="flex flex-col md:flex-row gap-3 mb-3">
                    <div class="flex-1 relative min-w-0">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="search" name="q" id="log-search" value="<?= h($fSearch) ?>" placeholder="Buscar consulta, usuario, base de datos, host… (Enter para filtrar)" class="w-full bg-bg-input border border-border-theme text-[12px] text-white rounded-lg pl-10 pr-3 py-2 focus:outline-none focus:border-accent transition-all">
                    </div>
                    <select name="operation" id="log-operation" onchange="this.form.submit()" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[150px]">
                        <option value="">Todas las operaciones</option>
                        <?php foreach (array_keys($operations) as $operation): ?><option value="<?= h($operation) ?>" <?= $fOp === $operation ? 'selected' : '' ?>><?= h($operation) ?></option><?php endforeach; ?>
                    </select>
                    <select name="database" id="log-database" onchange="this.form.submit()" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[150px]">
                        <option value="">Todas las bases</option>
                        <?php foreach (array_keys($databases) as $database): ?><option value="<?= h($database) ?>" <?= $fDb === $database ? 'selected' : '' ?>><?= h($database) ?></option><?php endforeach; ?>
                    </select>
                    <select name="engine" id="log-engine" onchange="this.form.submit()" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[130px]">
                        <option value="">Todos los motores</option>
                        <?php foreach (array_keys($engines) as $engine): ?><option value="<?= h($engine) ?>" <?= $fEngine === $engine ? 'selected' : '' ?>><?= h(ucfirst($engine)) ?></option><?php endforeach; ?>
                    </select>
                    <select name="risk" id="log-risk" onchange="this.form.submit()" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[130px]">
                        <option value="">Todo riesgo</option>
                        <option value="risk" <?= $fRisk === 'risk' ? 'selected' : '' ?>>Con riesgo</option>
                        <option value="safe" <?= $fRisk === 'safe' ? 'selected' : '' ?>>Sin riesgo</option>
                    </select>
                    <button type="submit" class="px-3 py-2 rounded-lg border border-accent/40 bg-accent/10 text-[11px] font-semibold text-accent hover:bg-accent/20 transition-all whitespace-nowrap">Filtrar</button>
                    <a href="?" class="inline-flex items-center px-3 py-2 rounded-lg border border-border-theme text-[11px] text-text-muted hover:text-white hover:bg-white/[0.04] transition-all whitespace-nowrap">Limpiar</a>
                </form>

                <!-- Resultados -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-3 border-t border-border-theme/30">
                    <div class="text-[11px] text-text-subtle">
                        Mostrando <span class="font-semibold text-white" id="log-result-count"><?= count($logs) ?></span> de <span class="font-semibold text-white"><?= $total ?></span> eventos
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="toggleLogDensity()" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-[10px] text-text-muted hover:text-white bg-white/[0.02] border border-white/[0.04] transition-all" title="Cambiar densidad">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            Densidad
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-4">

                <?php if (empty($logs)): ?>
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-12 md:p-16 text-center">
                    <div class="w-16 h-16 md:w-20 md:h-20 rounded-2xl bg-violet-500/10 border border-violet-500/20 flex items-center justify-center mx-auto mb-5 text-violet-400">
                        <svg class="w-8 h-8 md:w-10 md:h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg md:text-xl mb-2">Todavía no hay actividad</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">Cuando el agente capture consultas sobre las bases de datos monitoreadas, aparecerán aquí con su operación, usuario, origen y nivel de riesgo.</p>
                    <div class="flex flex-col sm:flex-row justify-center gap-2 mt-5">
                        <a href="/databases" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-primary-500/20 hover:bg-primary-500/30 text-primary-300 border border-primary-500/30 transition-all">Revisar conexiones</a>
                        <a href="/agents" class="px-4 py-2 rounded-lg text-[11px] font-medium border border-border-theme text-text-body hover:bg-white/[0.04] transition-all">Ver agentes</a>
                    </div>
                </div>

                <?php else: ?>
                <div id="logs-container" class="space-y-2">
                    <?php foreach ($logs as $index => $log):
                        $query = trim($log['query'] ?? '');
                        $operation = strtoupper($log['operation'] ?? strtok($query, " \t\r\n") ?: 'QUERY');
                        $database = $log['database'] ?? $log['databaseName'] ?? 'Sin base';
                        $dbUser = $log['dbUser'] ?? $log['user'] ?? 'Sin usuario';
                        $engine = $log['engine'] ?? 'database';
                        $timestamp = $log['createdAt'] ?? $log['timestamp'] ?? '';
                        $risk = (float)($log['riskScore'] ?? 0);
                        $tc = dbLogTypeConfig($operation);
                    ?>
                    <article class="db-log-card px-5 py-4 rounded-xl border <?= $tc['border'] ?> <?= $tc['bg'] ?> transition-all duration-200 hover:shadow-lg"
                             data-search="<?= h(strtolower($query . ' ' . $database . ' ' . $dbUser . ' ' . $engine . ' ' . ($log['host'] ?? ''))) ?>"
                             data-operation="<?= h($operation) ?>" data-database="<?= h($database) ?>" data-engine="<?= h($engine) ?>"
                             data-risk="<?= $risk > 0 ? 'risk' : 'safe' ?>">
                        <button type="button" onclick="toggleLogDetail('log-detail-<?= $index ?>', this)" class="log-summary w-full text-left flex items-start gap-4">
                            <!-- Type icon -->
                            <div class="flex-shrink-0 mt-0.5">
                                <div class="w-9 h-9 rounded-xl border <?= $tc['iconBox'] ?> flex items-center justify-center">
                                    <?= dbLogIcon($operation, 'w-4 h-4') ?>
                                </div>
                            </div>

                            <div class="flex-1 min-w-0">
                                <!-- Badges row -->
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <span class="text-[9px] px-2 py-1 rounded-lg border font-mono font-semibold <?= $tc['badge'] ?> <?= $tc['text'] ?>"><?= h($operation) ?></span>
                                    <span class="text-[9px] px-2 py-1 rounded-lg border border-border-theme bg-bg-base/50 text-text-muted"><?= h(ucfirst($engine)) ?></span>
                                    <span class="text-[9px] px-2 py-1 rounded-lg border border-white/[0.06] bg-white/[0.03] text-text-muted font-mono"><?= h($database) ?></span>
                                    <?php if ($risk > 0): ?>
                                    <span class="text-[9px] px-2 py-1 rounded-lg border border-red-500/30 bg-red-500/10 text-red-300 font-medium inline-flex items-center gap-1">
                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                        Riesgo <?= h($risk) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Query -->
                                <code class="block text-[11px] sm:text-xs leading-relaxed text-text-body font-mono break-all line-clamp-2"><?= h($query ?: 'Consulta no disponible') ?></code>

                                <!-- Meta footer -->
                                <div class="flex items-center gap-2 text-[9px] text-text-subtle mt-2 flex-wrap">
                                    <span class="font-mono"><?= h(substr($timestamp, 0, 19)) ?></span>
                                    <span>·</span>
                                    <span>Usuario: <strong class="text-text-muted font-medium"><?= h($dbUser) ?></strong></span>
                                    <?php if (!empty($log['host'])): ?>
                                    <span class="hidden sm:inline">·</span>
                                    <span class="hidden sm:inline">Host: <strong class="text-text-muted font-medium"><?= h($log['host']) ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <svg class="log-chevron w-4 h-4 text-text-subtle flex-shrink-0 mt-1.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="log-detail-<?= $index ?>" class="hidden mt-3 pt-3 border-t border-white/[0.06]">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <p class="text-[10px] uppercase tracking-wider text-text-subtle">Consulta completa</p>
                                <button type="button" onclick="copyLogQuery(this)" data-query="<?= h($query) ?>" class="px-2.5 py-1.5 rounded-lg border border-border-theme text-[10px] text-primary-400 hover:bg-primary-500/10 transition-all">Copiar SQL</button>
                            </div>
                            <pre class="max-h-72 overflow-auto rounded-xl border border-border-theme bg-black/25 p-3 text-[11px] leading-relaxed text-text-body font-mono whitespace-pre-wrap break-words scrollbar-custom"><?= h($query) ?></pre>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/40 p-3 md:p-4">
                    <div class="flex items-center justify-between gap-3">
                        <?php if ($page > 1): ?>
                            <a href="?<?= h($filterQS(['page' => $page - 1])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20 hover:bg-primary-500/15 transition-all">← Anterior</a>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-bg-base text-text-subtle border border-border-theme opacity-50 cursor-not-allowed">← Anterior</span>
                        <?php endif; ?>
                        <span class="text-[11px] text-text-subtle">Página <?= h($page) ?> de <?= h($totalPages) ?> · <?= h($total) ?> eventos</span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= h($filterQS(['page' => $page + 1])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20 hover:bg-primary-500/15 transition-all">Siguiente →</a>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-bg-base text-text-subtle border border-border-theme opacity-50 cursor-not-allowed">Siguiente →</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
let compactLogs = false;
function toggleLogDetail(id, button) {
    document.getElementById(id)?.classList.toggle('hidden');
    button.querySelector('.log-chevron')?.classList.toggle('rotate-180');
}
function toggleLogDensity() {
    compactLogs = !compactLogs;
    document.querySelectorAll('.db-log-card').forEach(el => {
        el.classList.toggle('py-4', !compactLogs);
        el.classList.toggle('py-2', compactLogs);
    });
}
async function copyLogQuery(button) {
    try { await navigator.clipboard.writeText(button.dataset.query || ''); button.textContent = 'Copiado'; setTimeout(() => button.textContent = 'Copiar SQL', 1200); } catch (e) { button.textContent = 'No disponible'; }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
