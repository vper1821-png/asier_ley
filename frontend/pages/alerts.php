<?php
$pageTitle = 'Alertas';
$currentPage = 'alerts';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resolve_alert'])) {
        $res = api_post_form('/api/alerts/resolve', ['token' => $token, 'alertId' => $_POST['alert_id']]);
        if (!empty($res['success'])) $msg = 'Alerta resuelta.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['dismiss_alert'])) {
        $res = api_post_form('/api/alerts/dismiss', ['token' => $token, 'alertId' => $_POST['alert_id']]);
        if (!empty($res['success'])) $msg = 'Alerta descartada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['delete_all'])) {
        $res = api_post_form('/api/alerts/delete-all', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Alertas eliminadas.'; else $err = $res['error'] ?? 'Error.';
    }
}

$alertsRes = api_post_form('/api/alerts/list', ['token' => $token]);
$alerts = is_array($alertsRes) && empty($alertsRes['error']) ? ($alertsRes['alerts'] ?? $alertsRes) : [];
if (!is_array($alerts)) $alerts = [];

$sevFilter = $_GET['severity'] ?? '';
if ($sevFilter) $alerts = array_filter($alerts, fn($a) => ($a['severity'] ?? '') === $sevFilter);

$sevConfig = [
    'critical' => ['label' => 'Crítica', 'color' => 'text-red-400', 'bg' => 'bg-red-500/10 border-red-500/20', 'dot' => 'bg-red-500'],
    'high'     => ['label' => 'Alta', 'color' => 'text-orange-400', 'bg' => 'bg-orange-500/10 border-orange-500/20', 'dot' => 'bg-orange-500'],
    'medium'   => ['label' => 'Media', 'color' => 'text-yellow-400', 'bg' => 'bg-yellow-500/10 border-yellow-500/20', 'dot' => 'bg-yellow-500'],
    'low'      => ['label' => 'Baja', 'color' => 'text-green-400', 'bg' => 'bg-green-500/10 border-green-500/20', 'dot' => 'bg-green-500'],
];

$active = array_filter($alerts, fn($a) => empty($a['resolved']) && empty($a['dismissed']));
$total = count($alerts);
$activeCount = count($active);
$critical = count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical' && empty($a['resolved'])));
$resolved = $total - $activeCount;
$high = count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'high' && empty($a['resolved'])));
$alertsJson = json_encode(array_values($alerts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        
        <!-- Top Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-red-600/30 to-orange-500/20 border border-red-500/30 flex items-center justify-center text-red-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight">Alertas</h1>
                    <p class="text-[11px] text-text-muted"><?= $activeCount ?> activas · <?= $critical ?> críticas</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" class="inline">
                    <button type="submit" name="delete_all" value="1" onclick="return confirm('¿Eliminar todas las alertas?')"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Eliminar todas</button>
                </form>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <?php if ($msg): ?>
                <div class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs shadow-theme-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span><?= h($msg) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs shadow-theme-sm">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        <span><?= h($err) ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- KPI Row -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-500/10 border border-slate-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white"><?= $total ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Total</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-red-400"><?= $critical ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Críticas</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-orange-400"><?= $high ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Altas</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-pink-500/10 border border-pink-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-pink-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-pink-400"><?= $activeCount ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Activas</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-emerald-400"><?= $resolved ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Resueltas</p>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="flex rounded-xl bg-white/[0.02] border border-white/[0.04] p-0.5">
                        <a href="/alerts" class="px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all <?= !$sevFilter ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30' : 'text-text-muted hover:text-white border border-transparent' ?>">Todas</a>
                        <?php foreach ($sevConfig as $sev => $cfg): ?>
                        <a href="/alerts?severity=<?= $sev ?>" class="px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all <?= $sevFilter === $sev ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30' : 'text-text-muted hover:text-white border border-transparent' ?>"><?= h($cfg['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($alerts)): ?>
                <!-- Empty State -->
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-16 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg mb-2">Sin alertas</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">No hay alertas que coincidan con el filtro seleccionado.</p>
                </div>

                <?php else: ?>
                <!-- Alerts List -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden">
                    <div class="divide-y divide-border-theme">
                        <?php foreach ($alerts as $ai => $alert):
                            $sev = $sevConfig[$alert['severity'] ?? 'low'] ?? $sevConfig['low'];
                            $resolvedAlert = !empty($alert['resolved']) || !empty($alert['dismissed']);
                        ?>
                        <div class="px-5 py-4 flex items-start gap-4 hover:bg-white/[0.01] transition-colors <?= $resolvedAlert ? 'opacity-50' : '' ?>">
                            <!-- Severity dot -->
                            <div class="flex-shrink-0 pt-1">
                                <span class="w-2.5 h-2.5 rounded-full <?= $sev['dot'] ?>"></span>
                            </div>
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <p class="text-[13px] font-medium text-white truncate"><?= h($alert['title'] ?? $alert['message'] ?? 'Alerta') ?></p>
                                    <span class="text-[9px] px-1.5 py-0.5 rounded-full border <?= $sev['bg'] ?> <?= $sev['color'] ?> font-medium flex-shrink-0">
                                        <?= h($sev['label']) ?>
                                    </span>
                                    <?php if ($resolvedAlert): ?>
                                    <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-medium flex-shrink-0">Resuelta</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[11px] text-text-muted truncate"><?= h($alert['message'] ?? $alert['description'] ?? $alert['detail'] ?? '') ?></p>
                                <div class="flex items-center gap-3 mt-1.5">
                                    <span class="text-[10px] text-text-subtle"><?= h(substr($alert['createdAt'] ?? '', 0, 16)) ?></span>
                                    <?php if (!empty($alert['source'])): ?>
                                    <span class="text-[10px] text-blue-400/70"><?= h($alert['source']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($alert['agentId'])): ?>
                                    <span class="text-[10px] text-text-subtle font-mono">· <?= h(substr($alert['agentId'], -8)) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Actions -->
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <button onclick="openAlertModal(<?= $ai ?>)" title="Ver detalle"
                                    class="p-1.5 rounded-lg text-[11px] text-text-muted hover:text-indigo-400 hover:bg-bg-elevated transition-all">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                                <?php if (!$resolvedAlert): ?>
                                <form method="POST" class="inline-flex gap-1">
                                    <input type="hidden" name="alert_id" value="<?= h($alert['_id'] ?? '') ?>">
                                    <button type="submit" name="resolve_alert" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all">Resolver</button>
                                    <button type="submit" name="dismiss_alert" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-white/[0.03] border border-white/[0.06] text-text-muted hover:text-white transition-all">Descartar</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<!-- Modal detalle alerta -->
<div id="alert-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-50 p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto scrollbar-custom shadow-2xl">
        <div id="alert-modal-body"></div>
    </div>
</div>

<script>
const ALERTS = <?= $alertsJson ?>;

function closeAlertModal() {
    const el = document.getElementById('alert-modal');
    el.classList.add('hidden'); el.classList.remove('flex');
}

function openAlertModal(idx) {
    const al = ALERTS[idx];
    if (!al) return;
    const sev = al.severity || 'low';
    const sevCfg = {
        critical: ['Crítico', '#f87171'],
        high: ['Alto', '#fb923c'],
        medium: ['Medio', '#facc15'],
        low: ['Bajo', '#34d399'],
        info: ['Info', '#60a5fa']
    };
    const sc = sevCfg[sev] || sevCfg.low;
    const resolved = !!(al.resolved || al.dismissed);
    const details = [
        ['Título', al.title || al.message || 'Alerta'],
        ['Gravedad', sc[0]],
        ['Estado', resolved ? 'Resuelta' : 'Activa'],
        ['Fecha', al.createdAt || ''],
        ['Tipo de evento', (al.eventType || al.type || 'generic').replace(/_/g, ' ')],
        ['Fuente', al.source || 'agent'],
        ['Agente', al.agentId || ''],
        ['ID', al._id || ''],
    ];
    const rows = details.map(d => `<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-3 py-2">
        <p class="text-[9px] font-medium text-text-subtle uppercase tracking-widest mb-1">${d[0]}</p>
        <p class="text-[11px] text-text-body break-words">${d[1] || 'N/A'}</p>
    </div>`).join('');
    const message = al.message || al.description || al.detail || '';
    document.getElementById('alert-modal-body').innerHTML = `
    <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
        <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full" style="background:${sc[1]}"></span>
            <h3 class="text-[14px] font-semibold text-white">Detalle de la alerta</h3>
        </div>
        <button onclick="closeAlertModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">${rows}</div>
        ${message ? `
        <div class="mt-4">
            <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-1.5">Mensaje</p>
            <p class="text-[12px] text-text-body leading-relaxed rounded-lg border border-border-theme bg-bg-elevated/40 p-3 whitespace-pre-wrap">${message}</p>
        </div>` : ''}
    </div>`;
    const el = document.getElementById('alert-modal');
    el.classList.remove('hidden'); el.classList.add('flex');
}

document.addEventListener('click', function (e) {
    if (e.target.id === 'alert-modal') closeAlertModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
