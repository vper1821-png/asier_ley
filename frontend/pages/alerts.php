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

$statsRes = api_post_form('/api/alerts/stats', ['token' => $token]);
$stats = is_array($statsRes) && empty($statsRes['error']) ? $statsRes : [];

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
$critical = count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical' && empty($a['resolved'])));
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Alertas</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($active) ?> activas · <?= $critical ?> críticas</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" class="inline">
                    <button type="submit" name="delete_all" value="1" onclick="return confirm('¿Eliminar todas las alertas?')"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Eliminar todas</button>
                </form>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">Refrescar</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 tour-detail-1">
                <?php
                $statCards = [
                    ['label' => 'Total', 'value' => count($alerts), 'color' => '#94a3b8'],
                    ['label' => 'Críticas', 'value' => $critical, 'color' => '#f87171'],
                    ['label' => 'Activas', 'value' => count($active), 'color' => '#fb7185'],
                    ['label' => 'Resueltas', 'value' => count($alerts) - count($active), 'color' => '#34d399'],
                ];
                foreach ($statCards as $c): ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <p class="text-[10px] font-medium text-text-muted uppercase tracking-widest mb-2"><?= h($c['label']) ?></p>
                    <p class="text-[22px] font-bold tracking-tight leading-none" style="color:<?= $c['color'] ?>"><?= $c['value'] ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Severity filter -->
            <div class="flex items-center gap-1">
                <div class="flex rounded-lg bg-white/[0.02] border border-white/[0.04] p-0.5">
                    <a href="/alerts" class="px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all <?= !$sevFilter ? 'bg-white/[0.06] text-white/80' : 'text-text-subtle hover:text-text-heading/50' ?>">Todas</a>
                    <?php foreach ($sevConfig as $sev => $cfg): ?>
                    <a href="/alerts?severity=<?= $sev ?>" class="px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all <?= $sevFilter === $sev ? 'bg-white/[0.06] text-white/80' : 'text-text-subtle hover:text-text-heading/50' ?>"><?= h($cfg['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Alerts list -->
            <?php if (empty($alerts)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center">
                <h3 class="text-white font-semibold mb-2">Sin alertas</h3>
                <p class="text-text-muted text-[12px]">No hay alertas que coincidan con el filtro.</p>
            </div>
            <?php else: ?>
            <div class="space-y-2 tour-detail-2">
                <?php foreach ($alerts as $alert):
                    $sev = $sevConfig[$alert['severity'] ?? 'low'] ?? $sevConfig['low'];
                    $resolved = !empty($alert['resolved']) || !empty($alert['dismissed']);
                ?>
                <div class="rounded-xl border p-4 flex flex-col md:flex-row md:items-center gap-3 <?= $resolved ? 'border-white/[0.03] bg-white/[0.005] opacity-60' : 'border-white/[0.04] bg-white/[0.015]' ?>">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <span class="w-2 h-2 rounded-full flex-shrink-0 <?= $sev['dot'] ?>"></span>
                        <div class="min-w-0">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($alert['title'] ?? $alert['message'] ?? 'Alerta') ?></p>
                            <p class="text-[10px] text-text-subtle truncate"><?= h($alert['description'] ?? $alert['detail'] ?? '') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $sev['bg'] ?> <?= $sev['color'] ?>"><?= h($sev['label']) ?></span>
                        <span class="text-[10px] text-text-subtle"><?= h(substr($alert['createdAt'] ?? '', 0, 16)) ?></span>
                        <?php if (!$resolved): ?>
                        <form method="POST" class="inline-flex gap-1.5">
                            <input type="hidden" name="alert_id" value="<?= h($alert['_id'] ?? '') ?>">
                            <button type="submit" name="resolve_alert" value="1" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all">Resolver</button>
                            <button type="submit" name="dismiss_alert" value="1" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Descartar</button>
                        </form>
                        <?php else: ?>
                        <span class="text-[10px] text-emerald-400">Resuelta</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
