<?php
$pageTitle = 'Logs DB';
$currentPage = 'db-logs';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
// No pedir stats globales: evita leer toda la colección y ralentizar la página
$stats = [];
$limit = 100;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$logsRes = api_post_form('/api/databases/logs/list', ['token' => $token, 'limit' => $limit, 'offset' => $offset]);
$logs = is_array($logsRes) && empty($logsRes['error']) ? ($logsRes['logs'] ?? $logsRes) : [];
if (!is_array($logs)) $logs = [];
$total = (int)($logsRes['total'] ?? count($logs));
$totalPages = max(1, (int)ceil($total / $limit));

$operations = [];
$databases = [];
$engines = [];
foreach ($logs as $log) {
    $operation = strtoupper($log['operation'] ?? strtok(trim($log['query'] ?? ''), " \t\r\n") ?: 'QUERY');
    $database = $log['database'] ?? $log['databaseName'] ?? 'Sin base';
    $engine = $log['engine'] ?? 'database';
    $operations[$operation] = ($operations[$operation] ?? 0) + 1;
    $databases[$database] = true;
    $engines[$engine] = true;
}
arsort($operations);

function dbLogTypeConfig($operation) {
    return match (strtoupper($operation)) {
        'SELECT' => ['dot' => 'bg-sky-400', 'text' => 'text-sky-300', 'badge' => 'bg-sky-500/10 border-sky-500/20'],
        'INSERT', 'REPLACE' => ['dot' => 'bg-emerald-400', 'text' => 'text-emerald-300', 'badge' => 'bg-emerald-500/10 border-emerald-500/20'],
        'UPDATE' => ['dot' => 'bg-amber-400', 'text' => 'text-amber-300', 'badge' => 'bg-amber-500/10 border-amber-500/20'],
        'DELETE', 'DROP', 'TRUNCATE' => ['dot' => 'bg-red-400', 'text' => 'text-red-300', 'badge' => 'bg-red-500/10 border-red-500/20'],
        'CREATE', 'ALTER' => ['dot' => 'bg-violet-400', 'text' => 'text-violet-300', 'badge' => 'bg-violet-500/10 border-violet-500/20'],
        default => ['dot' => 'bg-slate-400', 'text' => 'text-slate-300', 'badge' => 'bg-slate-500/10 border-slate-500/20'],
    };
}
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 min-w-0 overflow-hidden bg-bg-base flex flex-col">
        <header class="hidden md:flex flex-shrink-0 border-b border-border-theme px-6 lg:px-8 py-4 items-center justify-between gap-4 bg-bg-surface/60 backdrop-blur-xl">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-violet-500/10 border border-violet-500/20 flex items-center justify-center text-violet-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </div>
                <div class="min-w-0"><h1 class="text-[16px] font-semibold text-text-heading">Actividad de bases de datos</h1><p class="text-[11px] text-text-subtle mt-0.5">Auditoría, trazabilidad y análisis de consultas</p></div>
            </div>
            <div class="flex items-center gap-2">
                <span class="hidden lg:inline-flex items-center gap-2 px-3 py-2 rounded-xl border border-border-theme bg-bg-panel text-[10px] text-text-muted"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span><?= count($logs) ?> eventos cargados</span>
                <button onclick="location.reload()" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl text-[11px] font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20 hover:bg-primary-500/15 transition-all"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Actualizar</button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto scrollbar-custom">
            <div class="w-full max-w-[1600px] mx-auto p-3 sm:p-5 lg:p-8 space-y-4 sm:space-y-6">
                <section class="app-card overflow-hidden p-4 sm:p-5 relative">
                    <div class="absolute inset-0 pointer-events-none bg-gradient-to-r from-violet-500/[0.06] via-transparent to-sky-500/[0.04]"></div>
                    <div class="relative flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                        <div><p class="text-[10px] font-semibold uppercase tracking-[.18em] text-violet-300">Observabilidad SQL</p><h2 class="text-xl sm:text-2xl font-bold text-text-heading mt-1">Consultas y actividad</h2><p class="text-[11px] sm:text-xs text-text-muted mt-2 max-w-2xl">Busca operaciones, revisa usuarios y bases afectadas, y abre cada consulta para analizarla sin perder contexto.</p></div>
                        <div class="flex items-center gap-2 text-[10px] text-text-subtle"><span>Última actualización</span><span class="font-mono text-text-body"><?= date('d/m/Y H:i') ?></span></div>
                    </div>
                </section>

                <section class="grid grid-cols-2 xl:grid-cols-4 gap-3">
                    <?php
                    $cards = [
                        ['label' => 'Consultas', 'value' => $stats['total'] ?? count($logs), 'sub' => 'Eventos auditados', 'color' => 'text-text-heading', 'bar' => 'bg-slate-400'],
                        ['label' => 'Lecturas', 'value' => $stats['selects'] ?? ($operations['SELECT'] ?? 0), 'sub' => 'Operaciones SELECT', 'color' => 'text-sky-300', 'bar' => 'bg-sky-400'],
                        ['label' => 'Escrituras', 'value' => $stats['writes'] ?? (($operations['INSERT'] ?? 0) + ($operations['UPDATE'] ?? 0) + ($operations['DELETE'] ?? 0)), 'sub' => 'Cambios de datos', 'color' => 'text-amber-300', 'bar' => 'bg-amber-400'],
                        ['label' => 'Riesgo', 'value' => $stats['suspicious'] ?? count(array_filter($logs, fn($l) => (float)($l['riskScore'] ?? 0) > 0)), 'sub' => 'Consultas señaladas', 'color' => 'text-red-300', 'bar' => 'bg-red-400'],
                    ];
                    foreach ($cards as $card): ?>
                    <div class="app-card app-kpi p-4 sm:p-5">
                        <span class="absolute left-0 top-4 bottom-4 w-1 rounded-r-full <?= $card['bar'] ?>"></span>
                        <p class="relative z-10 text-[9px] sm:text-[10px] font-semibold uppercase tracking-[.14em] text-text-subtle"><?= h($card['label']) ?></p>
                        <p class="relative z-10 text-2xl sm:text-3xl font-bold mt-2 <?= $card['color'] ?>"><?= h($card['value']) ?></p>
                        <p class="relative z-10 text-[9px] sm:text-[10px] text-text-muted mt-1"><?= h($card['sub']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </section>

                <?php if (!empty($operations)): ?>
                <section class="app-card p-4 sm:p-5">
                    <div class="flex items-center justify-between gap-3 mb-4"><div><h3 class="text-xs font-semibold text-text-heading">Distribución de operaciones</h3><p class="text-[10px] text-text-subtle mt-1">Volumen relativo por tipo SQL</p></div><span class="text-[10px] text-text-subtle"><?= count($operations) ?> tipos</span></div>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php $maxOperation = max($operations); foreach (array_slice($operations, 0, 6, true) as $operation => $count): $tc = dbLogTypeConfig($operation); ?>
                        <div class="rounded-xl border border-border-theme bg-bg-base/35 p-3">
                            <div class="flex items-center justify-between text-[10px] mb-2"><span class="font-mono font-semibold <?= $tc['text'] ?>"><?= h($operation) ?></span><span class="text-text-body font-semibold"><?= $count ?></span></div>
                            <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden"><div class="h-full rounded-full <?= $tc['dot'] ?>" style="width:<?= max(4, ($count / $maxOperation) * 100) ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <section class="app-toolbar sticky top-0 md:top-0 z-20 shadow-theme-sm">
                    <div class="relative flex-1 min-w-[220px]"><svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg><input id="log-search" type="search" placeholder="Buscar consulta, usuario, base de datos…" class="w-full h-10 rounded-xl bg-bg-input border border-border-theme text-text-heading pl-10 pr-3 text-xs focus:outline-none focus:border-primary-500" oninput="applyLogFilters()"></div>
                    <select id="log-operation" onchange="applyLogFilters()" class="h-10 rounded-xl bg-bg-input border border-border-theme text-text-body px-3 text-xs"><option value="">Todas las operaciones</option><?php foreach (array_keys($operations) as $operation): ?><option value="<?= h($operation) ?>"><?= h($operation) ?></option><?php endforeach; ?></select>
                    <select id="log-database" onchange="applyLogFilters()" class="h-10 rounded-xl bg-bg-input border border-border-theme text-text-body px-3 text-xs"><option value="">Todas las bases</option><?php foreach (array_keys($databases) as $database): ?><option value="<?= h($database) ?>"><?= h($database) ?></option><?php endforeach; ?></select>
                    <select id="log-risk" onchange="applyLogFilters()" class="h-10 rounded-xl bg-bg-input border border-border-theme text-text-body px-3 text-xs"><option value="">Todo riesgo</option><option value="risk">Con riesgo</option><option value="safe">Sin riesgo</option></select>
                    <button type="button" onclick="resetLogFilters()" class="h-10 px-3 rounded-xl border border-border-theme text-xs text-text-muted hover:text-text-heading">Limpiar</button>
                </section>

                <div class="flex items-center justify-between gap-3"><p id="log-result-count" class="text-[10px] text-text-subtle"><?= count($logs) ?> resultados</p><button type="button" onclick="toggleLogDensity()" class="text-[10px] text-primary-400 hover:text-primary-300">Cambiar densidad</button></div>

                <?php if (empty($logs)): ?>
                <section class="app-card p-8 sm:p-14 text-center"><div class="w-16 h-16 rounded-2xl bg-violet-500/10 border border-violet-500/20 flex items-center justify-center mx-auto mb-4 text-violet-300"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg></div><h3 class="text-base font-semibold text-text-heading">Todavía no hay actividad</h3><p class="text-xs text-text-muted max-w-lg mx-auto mt-2">Cuando el agente reciba conexiones y capture consultas, aparecerán aquí con su operación, usuario, origen y nivel de riesgo.</p><div class="flex flex-col sm:flex-row justify-center gap-2 mt-5"><a href="/databases" class="px-4 py-2.5 rounded-xl bg-primary-500/10 border border-primary-500/20 text-primary-400 text-xs">Revisar conexiones</a><a href="/agents" class="px-4 py-2.5 rounded-xl border border-border-theme text-text-body text-xs">Ver agentes</a></div></section>
                <?php else: ?>
                <section id="logs-container" class="space-y-2.5">
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
                    <article class="db-log-card app-card app-card-interactive overflow-hidden" data-search="<?= h(strtolower($query . ' ' . $database . ' ' . $dbUser . ' ' . $engine)) ?>" data-operation="<?= h($operation) ?>" data-database="<?= h($database) ?>" data-risk="<?= $risk > 0 ? 'risk' : 'safe' ?>">
                        <button type="button" onclick="toggleLogDetail('log-detail-<?= $index ?>', this)" class="log-summary w-full text-left p-3 sm:p-4 flex items-start gap-3 sm:gap-4">
                            <span class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0 <?= $tc['dot'] ?>"></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[9px] px-2 py-1 rounded-lg border font-mono font-semibold <?= $tc['badge'] ?> <?= $tc['text'] ?>"><?= h($operation) ?></span>
                                    <span class="text-[9px] px-2 py-1 rounded-lg border border-border-theme bg-bg-base/50 text-text-muted"><?= h($engine) ?></span>
                                    <?php if ($risk > 0): ?><span class="text-[9px] px-2 py-1 rounded-lg border border-red-500/20 bg-red-500/10 text-red-300">Riesgo <?= h($risk) ?></span><?php endif; ?>
                                    <span class="sm:ml-auto text-[9px] sm:text-[10px] text-text-subtle font-mono"><?= h(substr($timestamp, 0, 19)) ?></span>
                                </div>
                                <code class="block mt-2 text-[11px] sm:text-xs leading-relaxed text-text-body font-mono break-all sm:line-clamp-2"><?= h($query ?: 'Consulta no disponible') ?></code>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[9px] sm:text-[10px] text-text-subtle"><span>DB: <strong class="text-text-muted font-medium"><?= h($database) ?></strong></span><span>Usuario: <strong class="text-text-muted font-medium"><?= h($dbUser) ?></strong></span><span class="hidden sm:inline">Host: <strong class="text-text-muted font-medium"><?= h($log['host'] ?? '—') ?></strong></span></div>
                            </div>
                            <svg class="log-chevron w-4 h-4 text-text-subtle flex-shrink-0 mt-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="log-detail-<?= $index ?>" class="hidden border-t border-border-theme bg-bg-base/35 p-3 sm:p-4">
                            <div class="flex items-center justify-between gap-3 mb-2"><p class="text-[10px] uppercase tracking-wider text-text-subtle">Consulta completa</p><button type="button" onclick="copyLogQuery(this)" data-query="<?= h($query) ?>" class="px-2.5 py-1.5 rounded-lg border border-border-theme text-[10px] text-primary-400 hover:bg-primary-500/10">Copiar SQL</button></div>
                            <pre class="max-h-72 overflow-auto rounded-xl border border-border-theme bg-black/25 p-3 text-[11px] leading-relaxed text-text-body font-mono whitespace-pre-wrap break-words"><?= h($query) ?></pre>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </section>
                <section id="logs-empty-filter" class="hidden app-card p-10 text-center"><p class="text-sm font-semibold text-text-heading">No hay coincidencias</p><p class="text-[11px] text-text-muted mt-1">Prueba con otros filtros o limpia la búsqueda.</p></section>

                <?php if ($totalPages > 1): ?>
                <section class="app-card p-3 sm:p-4">
                    <div class="flex items-center justify-between gap-3">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-[11px] font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20 hover:bg-primary-500/15 transition-all">← Anterior</a>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-[11px] font-medium bg-bg-base text-text-subtle border border-border-theme opacity-50 cursor-not-allowed">← Anterior</span>
                        <?php endif; ?>
                        <span class="text-[11px] text-text-subtle">Página <?= h($page) ?> de <?= h($totalPages) ?> (<?= h($total) ?> eventos)</span>
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>" class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-[11px] font-medium bg-primary-500/10 text-primary-400 border border-primary-500/20 hover:bg-primary-500/15 transition-all">Siguiente →</a>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-3 py-2 rounded-xl text-[11px] font-medium bg-bg-base text-text-subtle border border-border-theme opacity-50 cursor-not-allowed">Siguiente →</span>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
let compactLogs = false;
function applyLogFilters() {
    const search = (document.getElementById('log-search')?.value || '').toLowerCase().trim();
    const operation = document.getElementById('log-operation')?.value || '';
    const database = document.getElementById('log-database')?.value || '';
    const risk = document.getElementById('log-risk')?.value || '';
    let visible = 0;
    document.querySelectorAll('.db-log-card').forEach(card => {
        const matches = (!search || card.dataset.search.includes(search)) && (!operation || card.dataset.operation === operation) && (!database || card.dataset.database === database) && (!risk || card.dataset.risk === risk);
        card.classList.toggle('hidden', !matches);
        if (matches) visible++;
    });
    const counter = document.getElementById('log-result-count');
    if (counter) counter.textContent = visible + (visible === 1 ? ' resultado' : ' resultados');
    document.getElementById('logs-empty-filter')?.classList.toggle('hidden', visible !== 0);
}
function resetLogFilters() {
    ['log-search', 'log-operation', 'log-database', 'log-risk'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    applyLogFilters();
}
function toggleLogDetail(id, button) {
    document.getElementById(id)?.classList.toggle('hidden');
    button.querySelector('.log-chevron')?.classList.toggle('rotate-180');
}
function toggleLogDensity() {
    compactLogs = !compactLogs;
    document.querySelectorAll('.log-summary').forEach(el => el.classList.toggle('sm:py-2', compactLogs));
}
async function copyLogQuery(button) {
    try { await navigator.clipboard.writeText(button.dataset.query || ''); button.textContent = 'Copiado'; setTimeout(() => button.textContent = 'Copiar SQL', 1200); } catch (e) { button.textContent = 'No disponible'; }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
