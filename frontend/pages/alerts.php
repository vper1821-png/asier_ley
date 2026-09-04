<?php
$pageTitle = 'Alertas';
$currentPage = 'alerts';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

// ── POST Handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resolve_alert'])) {
        $res = api_post_form('/api/alerts/resolve', ['token' => $token, 'alertId' => $_POST['alert_id']]);
        if (!empty($res['success'])) $msg = 'Alerta resuelta.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['dismiss_alert'])) {
        $res = api_post_form('/api/alerts/dismiss', ['token' => $token, 'alertId' => $_POST['alert_id']]);
        if (!empty($res['success'])) $msg = 'Alerta descartada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['mark_read'])) {
        $res = api_post_form('/api/alerts/read', ['token' => $token, 'alertId' => $_POST['alert_id']]);
        if (!empty($res['success'])) $msg = 'Alerta marcada como leída.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['delete_all'])) {
        $res = api_post_form('/api/alerts/delete-all', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Alertas eliminadas.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['bulk_resolve'])) {
        $ids = json_decode($_POST['alert_ids'] ?? '[]', true);
        if ($ids) {
            $res = api_post_form('/api/alerts/resolve-bulk', ['token' => $token, 'alertIds' => json_encode($ids)]);
            if (!empty($res['success'])) $msg = $res['resolved'] . ' alertas resueltas.'; else $err = $res['error'] ?? 'Error.';
        }
    } elseif (isset($_POST['bulk_dismiss'])) {
        $ids = json_decode($_POST['alert_ids'] ?? '[]', true);
        if ($ids) {
            foreach ($ids as $id) {
                api_post_form('/api/alerts/dismiss', ['token' => $token, 'alertId' => $id]);
            }
            $msg = count($ids) . ' alertas descartadas.';
        }
    } elseif (isset($_POST['mark_read'])) {
        $ids = json_decode($_POST['alert_ids'] ?? '[]', true);
        if ($ids) {
            foreach ($ids as $id) {
                api_post_form('/api/alerts/read', ['token' => $token, 'alertId' => $id]);
            }
            $msg = count($ids) . ' alertas marcadas como leídas.';
        }
    }
}

// ── Fetch Data ─────────────────────────────────────────────────
$limit = 50;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$sevFilter = $_GET['severity'] ?? '';
$catFilter = $_GET['category'] ?? '';
$srcFilter = $_GET['source'] ?? '';
$artFilter = $_GET['article'] ?? '';
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? ''; // active, resolved, dismissed
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort'] ?? 'createdAt';
$sortDir = $_GET['dir'] ?? 'desc';
$viewMode = $_GET['view'] ?? 'list'; // list, timeline, cards

$apiFilters = [
    'token' => $token,
    'limit' => $limit,
    'offset' => $offset,
];
if ($sevFilter) $apiFilters['severity'] = $sevFilter;
if ($catFilter) $apiFilters['category'] = $catFilter;
if ($srcFilter) $apiFilters['source'] = $srcFilter;
if ($artFilter) $apiFilters['article'] = $artFilter;
if ($search) $apiFilters['search'] = $search;
if ($statusFilter) $apiFilters['status'] = $statusFilter;
if ($dateFrom) $apiFilters['date_from'] = $dateFrom;
if ($dateTo) $apiFilters['date_to'] = $dateTo;
if ($sortBy) $apiFilters['sort'] = $sortBy;
if ($sortDir) $apiFilters['dir'] = $sortDir;

$alertsRes = api_post_form('/api/alerts/list', $apiFilters);
$alerts = is_array($alertsRes) && empty($alertsRes['error']) ? ($alertsRes['alerts'] ?? []) : [];
if (!is_array($alerts)) $alerts = [];
$total = (int)($alertsRes['total'] ?? count($alerts));
$stats = is_array($alertsRes) && empty($alertsRes['error']) ? ($alertsRes['stats'] ?? []) : [];
$totalFiltered = count($alerts);
$totalPages = max(1, (int)ceil($total / $limit));

// ── Estadísticas ───────────────────────────────────────────────
$activeCount = $stats['active'] ?? 0;
$resolvedCount = $stats['resolved'] ?? 0;
$dismissedCount = $stats['dismissed'] ?? 0;
$critical = $stats['critical'] ?? 0;
$high = $stats['high'] ?? 0;
$unread = $stats['unread'] ?? 0;
$trendData = $stats['trend'] ?? [];
$sevDistribution = $stats['severity'] ?? ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

// Alertas más recientes (últimas 24h) en la página actual
$recentAlerts = array_filter($alerts, fn($a) => ($a['createdAt'] ?? '') >= date('Y-m-d\TH:i:s', strtotime('-24 hours')));
$recentCount = count($recentAlerts);

$alertsJson = json_encode(array_values($alerts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$filteredJson = $alertsJson;

// ── Helpers ────────────────────────────────────────────────────
function sevBadge($s) {
    return [
        'critical' => ['Crítica', 'bg-red-500/10 text-red-300 border border-red-500/30', 'bg-red-500'],
        'high'     => ['Alta',     'bg-orange-500/10 text-orange-300 border border-orange-500/30', 'bg-orange-400'],
        'medium'   => ['Media',    'bg-amber-500/10 text-amber-300 border border-amber-500/25', 'bg-amber-400'],
        'low'      => ['Baja',     'bg-sky-500/10 text-sky-300 border border-sky-500/25', 'bg-sky-400'],
    ][$s ?? 'low'] ?? ['Baja', 'bg-sky-500/10 text-sky-300 border border-sky-500/25', 'bg-sky-400'];
}
function catLabel($c) {
    return [
        'breach_notification' => 'Notificación Brechas (Art. 26)',
        'data_subject_rights' => 'Derechos ARCO (Art. 8-13)',
        'consent_management'  => 'Consentimientos (Art. 12)',
        'security_monitoring' => 'Monitoreo Seguridad (Art. 25)',
        'file_integrity'      => 'Integridad Archivos (Art. 25)',
        'database_access'     => 'Acceso BBDD (Art. 25)',
        'audit_trail'         => 'Auditoría (Art. 25)',
        'file_audit'          => 'Auditoría Archivos',
        'compliance_breach'   => 'Brecha Cumplimiento',
    ][$c] ?? $c;
}
function srcLabel($s) {
    return [
        'compliance_breach' => 'Brecha',
        'arco_request'      => 'ARCO',
        'consent_change'    => 'Consentimiento',
        'host_event'        => 'Host',
        'file_event'        => 'Archivo',
        'database_log'      => 'BBDD',
        'file_audit'        => 'Auditoría Archivo',
        'audit_log'         => 'Auditoría',
        'agent'             => 'Agente',
        'system'            => 'Sistema',
    ][$s] ?? $s;
}
function cIcon($name, $cls = 'w-4 h-4') {
    $paths = [
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'database' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
        'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>',
        'fileText' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        'xmark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        'search' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
        'download' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>',
        'timeline' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'filter' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>',
        'eye' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
        'trash' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
        'refresh' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>',
        'chevronDown' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>',
        'chevronUp' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>',
        'list' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>',
        'grid' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12M4 12h16"/>',
    ];
    return '<svg class="' . $cls . '" fill="none" viewBox="0 0 24 24" stroke="currentColor">' . ($paths[$name] ?? '') . '</svg>';
}
?>
<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header -->
        <header class="flex-shrink-0 border-b border-border-theme px-4 md:px-8 pt-4 pb-3 bg-bg-base">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-500/10 text-red-400 border border-red-500/20 flex items-center justify-center flex-shrink-0">
                        <?= cIcon('alert', 'w-5 h-5') ?>
                    </div>
                    <div>
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider font-medium">Centro de Alertas · Ley 21.719</p>
                        <h1 class="text-[18px] md:text-[20px] font-bold text-text-heading tracking-tight inline-flex items-center gap-2">Alertas de Cumplimiento <?= infoIcon('Alertas automáticas generadas por eventos de seguridad, brechas, solicitudes ARCO, consentimientos y monitoreo de agentes.') ?></h1>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] transition-all" title="Refrescar">
                        <?= cIcon('refresh', 'w-3 h-3') ?>
                        Refrescar
                    </button>
                    <a href="/alerts-export?format=csv" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white transition-all" title="Exportar CSV">
                        <?= cIcon('download', 'w-3 h-3') ?>
                        Exportar
                    </a>
                </div>
            </div>

            <!-- Stats Bar -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-2 md:gap-3 mb-4">
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-slate-500/10 border border-slate-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('list', 'w-4 h-4 text-slate-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-white"><?= $total ?></p><p class="text-[9px] text-text-muted">Total</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('alert', 'w-4 h-4 text-red-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-red-400"><?= $critical ?></p><p class="text-[9px] text-text-muted">Críticas activas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-orange-500/10 border border-orange-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('alert', 'w-4 h-4 text-orange-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-orange-400"><?= $high ?></p><p class="text-[9px] text-text-muted">Altas activas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-pink-500/10 border border-pink-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('alert', 'w-4 h-4 text-pink-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-pink-400"><?= $activeCount ?></p><p class="text-[9px] text-text-muted">Activas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('check', 'w-4 h-4 text-emerald-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-emerald-400"><?= $resolvedCount ?></p><p class="text-[9px] text-text-muted">Resueltas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('xmark', 'w-4 h-4 text-amber-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-amber-400"><?= $dismissedCount ?></p><p class="text-[9px] text-text-muted">Descartadas</p></div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center flex-shrink-0"><?= cIcon('eye', 'w-4 h-4 text-blue-400') ?></div>
                    <div class="min-w-0"><p class="text-[18px] md:text-[20px] font-bold text-blue-400"><?= $unread ?></p><p class="text-[9px] text-text-muted">No leídas</p></div>
                </div>
            </div>

            <!-- Enhanced Analytics Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <!-- Trend Analysis -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-[12px] font-semibold text-text-heading">Tendencia últimos 7 días</h3>
                        <span class="text-[10px] text-text-subtle">Últimas 24h: <?= $recentCount ?> alertas</span>
                    </div>
                    <div class="flex items-end gap-2 h-24">
                        <?php foreach ($trendData as $point): ?>
                        <div class="flex-1 flex flex-col items-center justify-end gap-1">
                            <div class="text-center">
                                <?php if ($point['critical'] > 0): ?>
                                <span class="text-[10px] font-bold text-red-400"><?= $point['critical'] ?></span>
                                <span class="text-[9px] text-text-muted">c</span>
                                <?php endif; ?>
                                <span class="text-[10px] font-medium text-text-body"><?= $point['count'] ?></span>
                            </div>
                            <div class="w-full bg-gradient-to-t from-red-500/20 to-red-500/5 rounded-t-sm border-t border-red-500/30" style="height: <?= max(10, min(80, $point['count'] * 3)) ?>px; min-height: 4px;"></div>
                            <span class="text-[9px] text-text-subtle"><?= $point['date'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Severity Distribution -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4">
                    <h3 class="text-[12px] font-semibold text-text-heading mb-3">Distribución por Severidad</h3>
                    <div class="space-y-2">
                        <?php foreach ($sevDistribution as $sev => $count): ?>
                        <?php
                        $sevInfo = [
                            'critical' => ['label' => 'Crítica', 'color' => 'from-red-500 to-red-600', 'bg' => 'bg-red-500', 'text' => 'text-red-400'],
                            'high' => ['label' => 'Alta', 'color' => 'from-orange-500 to-orange-600', 'bg' => 'bg-orange-500', 'text' => 'text-orange-400'],
                            'medium' => ['label' => 'Media', 'color' => 'from-amber-500 to-amber-600', 'bg' => 'bg-amber-500', 'text' => 'text-amber-400'],
                            'low' => ['label' => 'Baja', 'color' => 'from-sky-500 to-sky-600', 'bg' => 'bg-sky-500', 'text' => 'text-sky-400']
                        ][$sev] ?? ['label' => 'Desconocido', 'color' => 'from-gray-500 to-gray-600', 'bg' => 'bg-gray-500', 'text' => 'text-gray-400'];
                        $percentage = $total > 0 ? round(($count / $total) * 100) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-[11px] text-text-body flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full <?= $sevInfo['bg'] ?>"></span>
                                    <?= $sevInfo['label'] ?>
                                </span>
                                <span class="text-[11px] font-medium <?= $sevInfo['text'] ?>"><?= $count ?> (<?= $percentage ?>%)</span>
                            </div>
                            <div class="w-full bg-bg-elevated rounded-full h-1.5">
                                <div class="bg-gradient-to-r <?= $sevInfo['color'] ?> h-1.5 rounded-full transition-all" style="width: <?= max(2, $percentage) ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/40 p-3 md:p-4">
                <div class="flex flex-col md:flex-row gap-3 mb-3">
                    <!-- Buscador -->
                    <div class="flex-1 relative min-w-0">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" id="alert-search" value="<?= h($search) ?>" placeholder="Buscar por título, mensaje, agente, artículo legal, tipo de evento..." class="w-full bg-bg-input border border-border-theme text-[12px] text-white rounded-lg pl-10 pr-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="applyFilters()">
                    </div>

                    <!-- Filtro Severidad -->
                    <select id="filter-severity" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[140px]" onchange="applyFilters()">
                        <option value="">Todas las severidades</option>
                        <option value="critical" <?= $sevFilter === 'critical' ? 'selected' : '' ?>>🔴 Crítica</option>
                        <option value="high" <?= $sevFilter === 'high' ? 'selected' : '' ?>>🟠 Alta</option>
                        <option value="medium" <?= $sevFilter === 'medium' ? 'selected' : '' ?>>🟡 Media</option>
                        <option value="low" <?= $sevFilter === 'low' ? 'selected' : '' ?>>🟢 Baja</option>
                    </select>

                    <!-- Filtro Categoría Legal -->
                    <select id="filter-category" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[180px]" onchange="applyFilters()">
                        <option value="">Todas las categorías</option>
                        <option value="breach_notification" <?= $catFilter === 'breach_notification' ? 'selected' : '' ?>>⚖️ Notificación Brechas (Art. 26)</option>
                        <option value="data_subject_rights" <?= $catFilter === 'data_subject_rights' ? 'selected' : '' ?>>👤 Derechos ARCO (Art. 8-13)</option>
                        <option value="consent_management" <?= $catFilter === 'consent_management' ? 'selected' : '' ?>>✍️ Consentimientos (Art. 12)</option>
                        <option value="security_monitoring" <?= $catFilter === 'security_monitoring' ? 'selected' : '' ?>>🛡️ Monitoreo Seguridad (Art. 25)</option>
                        <option value="file_integrity" <?= $catFilter === 'file_integrity' ? 'selected' : '' ?>>📁 Integridad Archivos (Art. 25)</option>
                        <option value="database_access" <?= $catFilter === 'database_access' ? 'selected' : '' ?>>🗄️ Acceso BBDD (Art. 25)</option>
                        <option value="audit_trail" <?= $catFilter === 'audit_trail' ? 'selected' : '' ?>>📋 Auditoría (Art. 25)</option>
                        <option value="compliance_breach" <?= $catFilter === 'compliance_breach' ? 'selected' : '' ?>>⚠️ Brecha Cumplimiento</option>
                    </select>

                    <!-- Filtro Fuente -->
                    <select id="filter-source" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[140px]" onchange="applyFilters()">
                        <option value="">Todas las fuentes</option>
                        <option value="compliance_breach" <?= $srcFilter === 'compliance_breach' ? 'selected' : '' ?>>Brecha</option>
                        <option value="arco_request" <?= $srcFilter === 'arco_request' ? 'selected' : '' ?>>ARCO</option>
                        <option value="consent_change" <?= $srcFilter === 'consent_change' ? 'selected' : '' ?>>Consentimiento</option>
                        <option value="host_event" <?= $srcFilter === 'host_event' ? 'selected' : '' ?>>Host</option>
                        <option value="file_event" <?= $srcFilter === 'file_event' ? 'selected' : '' ?>>Archivo</option>
                        <option value="database_log" <?= $srcFilter === 'database_log' ? 'selected' : '' ?>>BBDD</option>
                        <option value="file_audit" <?= $srcFilter === 'file_audit' ? 'selected' : '' ?>>Auditoría Archivo</option>
                        <option value="audit_log" <?= $srcFilter === 'audit_log' ? 'selected' : '' ?>>Auditoría</option>
                        <option value="agent" <?= $srcFilter === 'agent' ? 'selected' : '' ?>>Agente</option>
                    </select>

                    <!-- Filtro Artículo Legal -->
                    <select id="filter-article" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[160px]" onchange="applyFilters()">
                        <option value="">Todos los artículos</option>
                        <option value="Art. 26 Ley 21.719" <?= $artFilter === 'Art. 26 Ley 21.719' ? 'selected' : '' ?>>Art. 26 - Notificación Brechas</option>
                        <option value="Art. 8 Ley 21.719" <?= $artFilter === 'Art. 8 Ley 21.719' ? 'selected' : '' ?>>Art. 8 - Derecho Acceso</option>
                        <option value="Art. 9 Ley 21.719" <?= $artFilter === 'Art. 9 Ley 21.719' ? 'selected' : '' ?>>Art. 9 - Rectificación</option>
                        <option value="Art. 10 Ley 21.719" <?= $artFilter === 'Art. 10 Ley 21.719' ? 'selected' : '' ?>>Art. 10 - Cancelación</option>
                        <option value="Art. 11 Ley 21.719" <?= $artFilter === 'Art. 11 Ley 21.719' ? 'selected' : '' ?>>Art. 11 - Oposición</option>
                        <option value="Art. 12 Ley 21.719" <?= $artFilter === 'Art. 12 Ley 21.719' ? 'selected' : '' ?>>Art. 12 - Consentimiento</option>
                        <option value="Art. 13 Ley 21.719" <?= $artFilter === 'Art. 13 Ley 21.719' ? 'selected' : '' ?>>Art. 13 - Bases Licitud</option>
                        <option value="Art. 25 Ley 21.719" <?= $artFilter === 'Art. 25 Ley 21.719' ? 'selected' : '' ?>>Art. 25 - Medidas Seguridad</option>
                        <option value="Art. 16 Ley 21.719" <?= $artFilter === 'Art. 16 Ley 21.719' ? 'selected' : '' ?>>Art. 16 - Datos Sensibles</option>
                        <option value="Art. 17 Ley 21.719" <?= $artFilter === 'Art. 17 Ley 21.719' ? 'selected' : '' ?>>Art. 17 - Datos Niños</option>
                        <option value="Art. 21 Ley 21.719" <?= $artFilter === 'Art. 21 Ley 21.719' ? 'selected' : '' ?>>Art. 21 - Transferencias</option>
                        <option value="Art. 28 Ley 21.719" <?= $artFilter === 'Art. 28 Ley 21.719' ? 'selected' : '' ?>>Art. 28 - DPD</option>
                        <option value="Art. 29 Ley 21.719" <?= $artFilter === 'Art. 29 Ley 21.719' ? 'selected' : '' ?>>Art. 29 - EIPD</option>
                        <option value="Art. 30 Ley 21.719" <?= $artFilter === 'Art. 30 Ley 21.719' ? 'selected' : '' ?>>Art. 30 - Seudonimización</option>
                    </select>

                    <!-- Filtro Estado -->
                    <select id="filter-status" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all w-auto min-w-[140px]" onchange="applyFilters()">
                        <option value="">Todos los estados</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>🟢 Activas</option>
                        <option value="resolved" <?= $statusFilter === 'resolved' ? 'selected' : '' ?>>✅ Resueltas</option>
                        <option value="dismissed" <?= $statusFilter === 'dismissed' ? 'selected' : '' ?>>❌ Descartadas</option>
                    </select>

                    <!-- Filtro Fechas -->
                    <div class="flex items-center gap-2">
                        <input type="date" id="filter-date-from" value="<?= h($dateFrom) ?>" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="applyFilters()" title="Desde">
                        <span class="text-text-muted">→</span>
                        <input type="date" id="filter-date-to" value="<?= h($dateTo) ?>" class="bg-bg-input border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all" onchange="applyFilters()" title="Hasta">
                    </div>
                </div>

                <!-- Chips de filtros activos + botón limpiar -->
                <div class="flex flex-wrap items-center gap-2" id="active-filters">
                    <?php if ($sevFilter): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-red-500/15 text-red-400 border border-red-500/30 rounded-full">Severidad: <?= sevBadge($sevFilter)[0] ?><button onclick="clearFilter('severity')" class="ml-1 text-red-300 hover:text-red-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($catFilter): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-blue-500/15 text-blue-400 border border-blue-500/30 rounded-full">Categoría: <?= h(catLabel($catFilter)) ?><button onclick="clearFilter('category')" class="ml-1 text-blue-300 hover:text-blue-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($srcFilter): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-purple-500/15 text-purple-400 border border-purple-500/30 rounded-full">Fuente: <?= h(srcLabel($srcFilter)) ?><button onclick="clearFilter('source')" class="ml-1 text-purple-300 hover:text-purple-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($artFilter): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-indigo-500/15 text-indigo-400 border border-indigo-500/30 rounded-full">Artículo: <?= h($artFilter) ?><button onclick="clearFilter('article')" class="ml-1 text-indigo-300 hover:text-indigo-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($statusFilter): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 rounded-full">Estado: <?= $statusFilter === 'active' ? 'Activas' : ($statusFilter === 'resolved' ? 'Resueltas' : 'Descartadas') ?><button onclick="clearFilter('status')" class="ml-1 text-emerald-300 hover:text-emerald-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($dateFrom || $dateTo): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-amber-500/15 text-amber-400 border border-amber-500/30 rounded-full">Fecha: <?= h($dateFrom ?: 'inicio') ?> → <?= h($dateTo ?: 'fin') ?><button onclick="clearFilter('date')" class="ml-1 text-amber-300 hover:text-amber-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($search): ?><span class="inline-flex items-center gap-1 px-2 py-1 text-[10px] bg-slate-500/15 text-slate-400 border border-slate-500/30 rounded-full">Buscar: "<?= h($search) ?>"<button onclick="clearFilter('search')" class="ml-1 text-slate-300 hover:text-slate-100"><?= cIcon('xmark', 'w-3 h-3') ?></button></span><?php endif; ?>
                    <?php if ($sevFilter || $catFilter || $srcFilter || $artFilter || $statusFilter || $dateFrom || $dateTo || $search): ?>
                        <button onclick="clearAllFilters()" class="px-2 py-1 text-[10px] text-text-muted hover:text-white transition-colors">Limpiar todo</button>
                    <?php endif; ?>
                </div>

                <!-- Resultados + vista -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mt-3 pt-3 border-t border-border-theme/30">
                    <div class="text-[11px] text-text-subtle">
                        Mostrando <span class="font-semibold text-white"><?= $totalFiltered ?></span> de <span class="font-semibold text-white"><?= $total ?></span> alertas
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="filter-sort" class="bg-bg-input border border-border-theme text-[11px] text-white rounded-lg px-2 py-1.5 focus:outline-none focus:border-accent transition-all" onchange="applyFilters()">
                            <option value="createdAt" <?= $sortBy === 'createdAt' ? 'selected' : '' ?>>Fecha (nuevas primero)</option>
                            <option value="severity" <?= $sortBy === 'severity' ? 'selected' : '' ?>>Severidad</option>
                            <option value="category" <?= $sortBy === 'category' ? 'selected' : '' ?>>Categoría legal</option>
                            <option value="source" <?= $sortBy === 'source' ? 'selected' : '' ?>>Fuente</option>
                            <option value="lawArticle" <?= $sortBy === 'lawArticle' ? 'selected' : '' ?>>Artículo legal</option>
                        </select>
                        <select id="filter-dir" class="bg-bg-input border border-border-theme text-[11px] text-white rounded-lg px-2 py-1.5 focus:outline-none focus:border-accent transition-all" onchange="applyFilters()">
                            <option value="desc" <?= $sortDir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            <option value="asc" <?= $sortDir === 'asc' ? 'selected' : '' ?>>Asc</option>
                        </select>
                        <div class="flex rounded-lg bg-white/[0.02] border border-white/[0.04] p-0.5" role="group" aria-label="Vista">
                            <button onclick="setView('list')" id="view-list" class="px-2.5 py-1.5 rounded-md text-[11px] transition-all <?= $viewMode === 'list' ? 'bg-primary-500/20 text-primary-300' : 'text-text-muted hover:text-white' ?>" title="Lista" aria-label="Vista lista"><?= cIcon('list', 'w-3.5 h-3.5') ?></button>
                            <button onclick="setView('cards')" id="view-cards" class="px-2.5 py-1.5 rounded-md text-[11px] transition-all <?= $viewMode === 'cards' ? 'bg-primary-500/20 text-primary-300' : 'text-text-muted hover:text-white' ?>" title="Tarjetas" aria-label="Vista tarjetas"><?= cIcon('grid', 'w-3.5 h-3.5') ?></button>
                            <button onclick="setView('timeline')" id="view-timeline" class="px-2.5 py-1.5 rounded-md text-[11px] transition-all <?= $viewMode === 'timeline' ? 'bg-primary-500/20 text-primary-300' : 'text-text-muted hover:text-white' ?>" title="Timeline" aria-label="Vista timeline"><?= cIcon('timeline', 'w-3.5 h-3.5') ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">
                <?php if ($msg): ?>
                <div class="px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-[11px] flex items-center gap-2.5">
                    <?= cIcon('check', 'w-4 h-4 text-emerald-400') ?><span><?= h($msg) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-[11px] flex items-center gap-2.5">
                    <?= cIcon('alert', 'w-4 h-4 text-red-400') ?><span><?= h($err) ?></span>
                </div>
                <?php endif; ?>

                <!-- Bulk Actions Bar (aparece cuando hay selección) -->
                <div id="bulk-actions-bar" class="hidden fixed bottom-4 right-4 md:bottom-6 md:right-6 z-40 bg-bg-panel border border-primary-500/30 rounded-xl shadow-xl p-3 md:p-4 flex items-center gap-2">
                    <span id="bulk-count" class="text-[12px] font-medium text-white"></span>
                    <button onclick="bulkAction('resolve')" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-all"><?= cIcon('check', 'w-3 h-3') ?> Resolver</button>
                    <button onclick="bulkAction('dismiss')" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-amber-600 hover:bg-amber-500 text-white transition-all"><?= cIcon('xmark', 'w-3 h-3') ?> Descartar</button>
                    <button onclick="bulkAction('read')" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-blue-600 hover:bg-blue-500 text-white transition-all"><?= cIcon('eye', 'w-3 h-3') ?> Marcar leídas</button>
                    <button onclick="clearSelection()" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-bg-elevated text-text-muted border border-border-theme hover:bg-bg-elevated/80 transition-all">Cancelar</button>
                </div>

                <?php if (empty($alerts)): ?>
                <!-- Empty State -->
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-12 md:p-16 text-center">
                    <div class="w-16 h-16 md:w-20 md:h-20 rounded-2xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 md:w-10 md:h-10 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg md:text-xl mb-2">Sin alertas</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">No hay alertas que coincidan con los filtros seleccionados.</p>
                    <button onclick="clearAllFilters()" class="mt-4 px-4 py-2 rounded-lg text-[11px] font-medium bg-primary-500/20 hover:bg-primary-500/30 text-primary-300 border border-primary-500/30 transition-all">Limpiar filtros</button>
                </div>

                <?php else: ?>
                <!-- Vista: Lista (default) -->
                <div id="view-list-container" class="<?= $viewMode === 'list' ? '' : 'hidden' ?>">
                    <div class="space-y-2">
                        <?php foreach ($alerts as $ai => $alert):
                            $sev = sevBadge($alert['severity'] ?? 'low');
                            $resolvedAlert = !empty($alert['resolved']) || !empty($alert['dismissed']);
                            $read = !empty($alert['read']);
                            $cat = $alert['category'] ?? 'general';
                            $src = $alert['source'] ?? 'unknown';
                            $art = $alert['lawArticle'] ?? '';
                            $deadline = $alert['deadline'] ?? '';
                            $requiresAPDP = !empty($alert['requiresAPDPNotification']);
                            $requiresSubject = !empty($alert['requiresSubjectNotification']);
                            
                            // Color de borde según severidad
                            $borderColor = [
                                'critical' => 'border-red-500/30 hover:border-red-500/50',
                                'high' => 'border-orange-500/30 hover:border-orange-500/50',
                                'medium' => 'border-amber-500/30 hover:border-amber-500/50',
                                'low' => 'border-sky-500/30 hover:border-sky-500/50'
                            ][$alert['severity'] ?? 'low'] ?? 'border-sky-500/30 hover:border-sky-500/50';
                            
                            // Fondo sutil según severidad
                            $bgColor = [
                                'critical' => 'bg-red-500/[0.03] hover:bg-red-500/[0.06]',
                                'high' => 'bg-orange-500/[0.03] hover:bg-orange-500/[0.06]',
                                'medium' => 'bg-amber-500/[0.03] hover:bg-amber-500/[0.06]',
                                'low' => 'bg-sky-500/[0.03] hover:bg-sky-500/[0.06]'
                            ][$alert['severity'] ?? 'low'] ?? 'bg-sky-500/[0.03] hover:bg-sky-500/[0.06]';
                        ?>
                            <div class="px-5 py-4 rounded-xl border <?= $borderColor ?> <?= $bgColor ?> <?= $resolvedAlert ? 'opacity-50' : '' ?> <?= !$read ? 'ring-1 ring-blue-500/20' : '' ?> transition-all duration-200 hover:shadow-lg" data-alert-id="<?= h($alert['_id'] ?? '') ?>" data-alert-index="<?= $ai ?>">
                                <div class="flex items-start gap-4">
                                    <!-- Status icon -->
                                    <div class="flex-shrink-0 mt-1">
                                        <?php if ($resolvedAlert): ?>
                                        <div class="w-8 h-8 rounded-full bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center">
                                            <?= cIcon('check', 'w-4 h-4 text-emerald-400') ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="w-8 h-8 rounded-full bg-amber-500/20 border border-amber-500/30 flex items-center justify-center">
                                            <?= cIcon('alert', 'w-4 h-4 text-amber-400') ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Main content -->
                                    <div class="flex-1 min-w-0">
                                        <!-- Title row -->
                                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                                            <h3 class="text-[13px] font-semibold text-text-heading"><?= h($alert['source'] ?? 'Auditoria') ?>: <?= h($alert['title'] ?? $alert['message'] ?? 'Alerta') ?></h3>
                                        </div>
                                        
                                        <!-- Badges row -->
                                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                                            <span class="text-[8px] px-2 py-1 rounded-full border <?= $sev[1] ?> font-medium"><?= h($sev[0]) ?></span>
                                            <?php if ($art): ?>
                                            <span class="text-[8px] px-2 py-1 rounded-full bg-indigo-500/15 text-indigo-300 border border-indigo-500/30 font-medium"><?= h($art) ?></span>
                                            <?php endif; ?>
                                            <?php if ($resolvedAlert): ?>
                                            <span class="text-[8px] px-2 py-1 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 font-medium">Resuelta</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Details -->
                                        <div class="text-[10px] text-text-muted space-y-1">
                                            <?php if (!empty($alert['resource'])): ?>
                                            <p>Recurso: <?= h($alert['resource']) ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($alert['user'])): ?>
                                            <p>Usuario: <?= h($alert['user']) ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($alert['ip'])): ?>
                                            <p>IP: <?= h($alert['ip']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Footer -->
                                        <div class="flex items-center gap-2 text-[9px] text-text-subtle mt-2">
                                            <span><?= date('H:i', strtotime($alert['createdAt'] ?? '')) ?></span>
                                            <span>·</span>
                                            <span><?= h($alert['source'] ?? 'Auditoria') ?></span>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex flex-col gap-2 flex-shrink-0">
                                        <?php if (!$read): ?>
                                        <button onclick="event.stopPropagation(); markAsRead('<?= h($alert['_id'] ?? '') ?>')" class="p-2 rounded-lg text-blue-400 hover:bg-blue-500/10 transition-all z-10 relative" title="Marcar como leída">
                                            <?= cIcon('eye', 'w-4 h-4') ?>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!$resolvedAlert): ?>
                                        <button onclick="event.stopPropagation(); openAlertModal(<?= $ai ?>)" class="p-2 rounded-lg text-indigo-400 hover:bg-indigo-500/10 transition-all z-10 relative" title="Ver detalles">
                                            <?= cIcon('eye', 'w-4 h-4') ?>
                                        </button>
                                        <button onclick="event.stopPropagation(); resolveSingle('<?= h($alert['_id'] ?? '') ?>')" class="p-2 rounded-lg text-emerald-400 hover:bg-emerald-500/10 transition-all z-10 relative" title="Resolver">
                                            <?= cIcon('check', 'w-4 h-4') ?>
                                        </button>
                                        <button onclick="event.stopPropagation(); dismissSingle('<?= h($alert['_id'] ?? '') ?>')" class="p-2 rounded-lg text-amber-400 hover:bg-amber-500/10 transition-all z-10 relative" title="Descartar">
                                            <?= cIcon('xmark', 'w-4 h-4') ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                    </div>
                </div>
                </div>

                <!-- Vista: Tarjetas -->
                <div id="view-cards-container" class="<?= $viewMode === 'cards' ? '' : 'hidden' ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php foreach ($alerts as $ai => $alert):
                            $sev = sevBadge($alert['severity'] ?? 'low');
                            $resolvedAlert = !empty($alert['resolved']) || !empty($alert['dismissed']);
                            $read = !empty($alert['read']);
                            $cat = $alert['category'] ?? 'general';
                            $src = $alert['source'] ?? 'unknown';
                            $art = $alert['lawArticle'] ?? '';
                            $deadline = $alert['deadline'] ?? '';
                            
                            // Color de borde según severidad
                            $borderColor = [
                                'critical' => 'border-red-500/30 hover:border-red-500/50',
                                'high' => 'border-orange-500/30 hover:border-orange-500/50',
                                'medium' => 'border-amber-500/30 hover:border-amber-500/50',
                                'low' => 'border-sky-500/30 hover:border-sky-500/50'
                            ][$alert['severity'] ?? 'low'] ?? 'border-sky-500/30 hover:border-sky-500/50';
                            
                            // Fondo sutil según severidad
                            $bgColor = [
                                'critical' => 'bg-red-500/[0.03] hover:bg-red-500/[0.06]',
                                'high' => 'bg-orange-500/[0.03] hover:bg-orange-500/[0.06]',
                                'medium' => 'bg-amber-500/[0.03] hover:bg-amber-500/[0.06]',
                                'low' => 'bg-sky-500/[0.03] hover:bg-sky-500/[0.06]'
                            ][$alert['severity'] ?? 'low'] ?? 'bg-sky-500/[0.03] hover:bg-sky-500/[0.06]';
                        ?>
                        <div class="rounded-xl border <?= $borderColor ?> <?= $bgColor ?> p-4 <?= $resolvedAlert ? 'opacity-50' : '' ?> <?= !$read ? 'ring-1 ring-blue-500/20' : '' ?> transition-all duration-200" data-alert-id="<?= h($alert['_id'] ?? '') ?>" data-alert-index="<?= $ai ?>">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full <?= $sev[2] ?>"></span>
                                    <span class="text-[9px] px-2 py-0.5 rounded-full border <?= $sev[1] ?> font-medium"><?= h($sev[0]) ?></span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <input type="checkbox" class="alert-checkbox w-3.5 h-3.5 rounded border-border-theme text-primary-600 focus:ring-primary-500" onchange="toggleSelection(this)" value="<?= h($alert['_id'] ?? '') ?>">
                                    <?php if (!$read): ?><span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span><?php endif; ?>
                                </div>
                            </div>
                            <h4 class="text-[12px] font-semibold text-white mb-1 line-clamp-2"><?= h($alert['title'] ?? $alert['message'] ?? 'Alerta') ?></h4>
                            <p class="text-[10px] text-text-muted mb-2 line-clamp-3"><?= h($alert['message'] ?? $alert['description'] ?? $alert['detail'] ?? '') ?></p>
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <?php if ($art): ?><span class="text-[8px] px-1.5 py-0.5 rounded bg-purple-500/15 text-purple-400 border border-purple-500/20"><?= h($art) ?></span><?php endif; ?>
                                <?php if ($cat !== 'general'): ?><span class="text-[8px] px-1.5 py-0.5 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/20"><?= h(catLabel($cat)) ?></span><?php endif; ?>
                                <span class="text-[8px] px-1.5 py-0.5 rounded bg-blue-500/15 text-blue-400 border border-blue-500/20"><?= h(srcLabel($src)) ?></span>
                                <?php if ($deadline): ?><span class="text-[8px] px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 border border-amber-500/20"><?= cIcon('calendar', 'w-2.5 h-2.5') ?> <?= h(substr($deadline, 0, 10)) ?></span><?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t border-border-theme/30">
                                <span class="text-[9px] text-text-subtle font-mono"><?= h(substr($alert['createdAt'] ?? '', 0, 16)) ?></span>
                                <div class="flex items-center gap-1">
                                    <button onclick="openAlertModal(<?= $ai ?>)" class="p-1 rounded-lg text-text-muted hover:text-indigo-400 hover:bg-bg-elevated transition-all" title="Ver detalle"><?= cIcon('eye', 'w-3.5 h-3.5') ?></button>
                                    <?php if (!$resolvedAlert): ?>
                                    <button onclick="resolveSingle('<?= h($alert['_id'] ?? '') ?>')" class="p-1 rounded-lg text-text-muted hover:text-emerald-400 hover:bg-emerald-500/10 transition-all" title="Resolver"><?= cIcon('check', 'w-3.5 h-3.5') ?></button>
                                    <button onclick="dismissSingle('<?= h($alert['_id'] ?? '') ?>')" class="p-1 rounded-lg text-text-muted hover:text-amber-400 hover:bg-amber-500/10 transition-all" title="Descartar"><?= cIcon('xmark', 'w-3.5 h-3.5') ?></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Vista: Timeline -->
                <div id="view-timeline-container" class="<?= $viewMode === 'timeline' ? '' : 'hidden' ?>">
                    <div class="relative">
                        <div class="absolute left-6 top-0 bottom-0 w-0.5 bg-gradient-to-b from-red-500/50 via-orange-500/40 via-yellow-500/40 to-gray-500/30 rounded-full"></div>
                        <div class="space-y-4 pl-14">
                            <?php
                            $grouped = [];
                            foreach ($alerts as $alert) {
                                $date = substr($alert['createdAt'] ?? '', 0, 10);
                                $grouped[$date][] = $alert;
                            }
                            krsort($grouped);
                            foreach ($grouped as $date => $dayAlerts): ?>
                            <div class="mb-6">
                                <div class="relative flex items-center mb-3">
                                    <div class="absolute left-[-14px] top-1/2 -translate-y-1/2 w-3 h-3 rounded-full bg-primary-500 border-2 border-bg-base"></div>
                                    <span class="text-[10px] font-bold text-primary-400 bg-bg-panel/80 px-2 py-0.5 rounded"><?= h($date) ?></span>
                                    <span class="text-[9px] text-text-muted ml-2">(<?= count($dayAlerts) ?> alertas)</span>
                                </div>
                                <div class="space-y-3">
                                    <?php foreach ($dayAlerts as $alert):
                                        $sev = sevBadge($alert['severity'] ?? 'low');
                                        $resolvedAlert = !empty($alert['resolved']) || !empty($alert['dismissed']);
                                    ?>
                                    <div class="relative flex items-start gap-3 bg-bg-panel/40 border border-border-theme/40 rounded-lg p-3 hover:border-border-theme/60 transition-colors <?= $resolvedAlert ? 'opacity-60' : '' ?>">
                                        <div class="flex-shrink-0 w-8 h-8 rounded-full border-2 flex items-center justify-center <?= $sev[1] ?>"><?= cIcon($resolvedAlert ? 'check' : 'alert', 'w-4 h-4') ?></div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                                <span class="text-[11px] font-medium text-white"><?= h($alert['title'] ?? 'Alerta') ?></span>
                                                <span class="text-[8px] px-1.5 py-0.5 rounded-full border <?= $sev[1] ?>"><?= h($sev[0]) ?></span>
                                                <?php if ($alert['lawArticle'] ?? ''): ?><span class="text-[7px] px-1 py-0.5 rounded bg-indigo-500/15 text-indigo-400 border border-indigo-500/20"><?= h(str_replace('Ley 21.719', '', $alert['lawArticle'])) ?></span><?php endif; ?>
                                                <?php if ($alert['deadline'] ?? ''): ?><span class="text-[8px] px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 border border-amber-500/20"><?= cIcon('calendar', 'w-2.5 h-2.5') ?> <?= h(substr($alert['deadline'], 0, 10)) ?></span><?php endif; ?>
                                                <?php if ($resolvedAlert): ?><span class="text-[8px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400 border border-emerald-500/20"><?= !empty($alert['resolved']) ? 'Resuelta' : 'Descartada' ?></span><?php endif; ?>
                                            </div>
                                            <p class="text-[10px] text-text-muted"><?= h($alert['message'] ?? '') ?></p>
                                            <div class="flex items-center gap-2 mt-1 text-[8px] text-text-subtle">
                                                <span><?= h(substr($alert['createdAt'] ?? '', 11, 5)) ?></span>
                                                <?php if ($alert['source'] ?? ''): ?><span>·</span><span><?= h(srcLabel($alert['source'])) ?></span><?php endif; ?>
                                                <?php if ($alert['agentId'] ?? ''): ?><span>·</span><span class="font-mono"><?= h(substr($alert['agentId'], -8)) ?></span><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php endif; ?>

                <?php
                $prevUrl = '?' . http_build_query(array_merge($_GET, ['page' => $page - 1]));
                $nextUrl = '?' . http_build_query(array_merge($_GET, ['page' => $page + 1]));
                ?>
                <?php if ($totalPages > 1): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3 md:p-4 flex items-center justify-between gap-4 mt-4">
                    <?php if ($page > 1): ?>
                    <a href="<?= h($prevUrl) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-body border border-white/[0.05] transition-all">
                        ← Anterior
                    </a>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium bg-white/[0.02] text-text-muted border border-white/[0.05] cursor-not-allowed opacity-50">← Anterior</span>
                    <?php endif; ?>
                    <span class="text-[11px] text-text-subtle">Página <?= h($page) ?> de <?= h($totalPages) ?> (<?= h($total) ?> alertas)</span>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?= h($nextUrl) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-body border border-white/[0.05] transition-all">
                        Siguiente →
                    </a>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[12px] font-medium bg-white/[0.02] text-text-muted border border-white/[0.05] cursor-not-allowed opacity-50">Siguiente →</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- Modal Detalle Alerta Completo -->
<div id="alert-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-50 p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto scrollbar-custom shadow-2xl">
        <div id="alert-modal-body"></div>
    </div>
</div>

<script>
const ALERTS = <?= $filteredJson ?>;
const ALL_ALERTS = <?= $alertsJson ?>;
const SL_TOKEN = <?= json_encode($token) ?>;

function cIcon(name, cls) { return ''; }

let selectedAlerts = new Set();
let currentView = '<?= h($viewMode) ?>';

function applyFilters() {
    const params = new URLSearchParams();
    const sev = document.getElementById('filter-severity').value;
    const cat = document.getElementById('filter-category').value;
    const src = document.getElementById('filter-source').value;
    const art = document.getElementById('filter-article').value;
    const sta = document.getElementById('filter-status').value;
    const df = document.getElementById('filter-date-from').value;
    const dt = document.getElementById('filter-date-to').value;
    const search = document.getElementById('alert-search').value;
    const sort = document.getElementById('filter-sort').value;
    const dir = document.getElementById('filter-dir').value;
    const view = currentView;

    if (sev) params.set('severity', sev);
    if (cat) params.set('category', cat);
    if (src) params.set('source', src);
    if (art) params.set('article', art);
    if (sta) params.set('status', sta);
    if (df) params.set('date_from', df);
    if (dt) params.set('date_to', dt);
    if (search) params.set('search', search);
    if (sort) params.set('sort', sort);
    if (dir) params.set('dir', dir);
    if (view) params.set('view', view);

    window.location.search = params.toString();
}

function setView(view) {
    document.getElementById('view-list').classList.toggle('bg-primary-500/20', view === 'list');
    document.getElementById('view-list').classList.toggle('text-primary-300', view === 'list');
    document.getElementById('view-cards').classList.toggle('bg-primary-500/20', view === 'cards');
    document.getElementById('view-cards').classList.toggle('text-primary-300', view === 'cards');
    document.getElementById('view-timeline').classList.toggle('bg-primary-500/20', view === 'timeline');
    document.getElementById('view-timeline').classList.toggle('text-primary-300', view === 'timeline');
    currentView = view;
    document.getElementById('filter-sort').dispatchEvent(new Event('change'));
}

function clearFilter(key) {
    const el = document.getElementById('filter-' + key);
    if (el) {
        if (el.tagName === 'SELECT') el.value = '';
        else el.value = '';
    }
    if (key === 'date') {
        document.getElementById('filter-date-from').value = '';
        document.getElementById('filter-date-to').value = '';
    }
    if (key === 'search') {
        document.getElementById('alert-search').value = '';
    }
    applyFilters();
}

function clearAllFilters() {
    ['filter-severity','filter-category','filter-source','filter-article','filter-status'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('filter-date-from').value = '';
    document.getElementById('filter-date-to').value = '';
    document.getElementById('alert-search').value = '';
    applyFilters();
}

function toggleSelection(cb) {
    const id = cb.value;
    if (cb.checked) selectedAlerts.add(id);
    else selectedAlerts.delete(id);
    updateBulkBar();
}

function clearSelection() {
    selectedAlerts.clear();
    document.querySelectorAll('.alert-checkbox').forEach(cb => cb.checked = false);
    updateBulkBar();
}

function updateBulkBar() {
    const bar = document.getElementById('bulk-actions-bar');
    const count = document.getElementById('bulk-count');
    if (selectedAlerts.size > 0) {
        bar.classList.remove('hidden');
        count.textContent = selectedAlerts.size + ' alertas seleccionadas';
    } else {
        bar.classList.add('hidden');
    }
}

function bulkAction(action) {
    const ids = Array.from(selectedAlerts);
    if (!ids.length) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="alert_ids" value=\'' + JSON.stringify(ids) + '\'>' +
                     '<input type="hidden" name="bulk_' + action + '" value="1">';
    document.body.appendChild(form);
    form.submit();
}

function markAsRead(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="alert_id" value="' + id + '"><input type="hidden" name="mark_read" value="1">';
    document.body.appendChild(form);
    form.submit();
}

function resolveSingle(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="alert_id" value="' + id + '"><input type="hidden" name="resolve_alert" value="1">';
    document.body.appendChild(form);
    form.submit();
}

function dismissSingle(id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="alert_id" value="' + id + '"><input type="hidden" name="dismiss_alert" value="1">';
    document.body.appendChild(form);
    form.submit();
}

function closeAlertModal() {
    const el = document.getElementById('alert-modal');
    el.classList.add('hidden');
    el.classList.remove('flex');
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
    const read = !!al.read;

    const details = [
        ['Título', al.title || al.message || 'Alerta'],
        ['Gravedad', sc[0]],
        ['Estado', resolved ? (al.resolved ? 'Resuelta' : 'Descartada') : 'Activa'],
        ['Leída', read ? 'Sí' : 'No'],
        ['Fecha', al.createdAt || ''],
        ['Tipo de evento', (al.eventType || al.type || 'generic').replace(/_/g, ' ')],
        ['Fuente', al.source || 'agent'],
        ['Categoría legal', al.category ? catLabel(al.category) : 'General'],
        ['Artículo Ley 21.719', al.lawArticle || 'N/A'],
        ['Agente', al.agentId || 'N/A'],
        ['ID', al._id || ''],
    ];

    if (al.deadline) details.push(['Plazo legal', al.deadline.substring(0, 19)]);
    if (al.requiresAPDPNotification) details.push(['Requiere notificación APDP', '⚠️ SÍ - Art. 26']);
    if (al.requiresSubjectNotification) details.push(['Requiere notificación a titulares', '⚠️ SÍ - Art. 26.4']);
    if (al.riskScore !== undefined) details.push(['Risk Score', al.riskScore + '/10']);

    const rows = details.map(d => `<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-3 py-2">
        <p class="text-[9px] font-medium text-text-subtle uppercase tracking-widest mb-1">${d[0]}</p>
        <p class="text-[11px] text-text-body break-words">${d[1] || 'N/A'}</p>
    </div>`).join('');

    const message = al.message || al.description || al.detail || '';
    const detailsObj = al.details || {};

    let extraSections = '';
    if (Object.keys(detailsObj).length) {
        const detailRows = Object.entries(detailsObj).map(([k, v]) => `<tr class="border-t border-border-theme/30"><td class="px-2 py-1.5 text-[9px] font-medium text-text-subtle">${esc(k)}</td><td class="px-2 py-1.5 text-[9px] text-text-body break-all">${esc(JSON.stringify(v))}</td></tr>`).join('');
        extraSections = `
        <div class="mt-4">
            <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-2">Datos completos (JSON)</p>
            <div class="rounded-lg border border-border-theme overflow-x-auto">
                <table class="w-full text-[9px]"><tbody>${detailRows}</tbody></table>
            </div>
        </div>`;
    }

    // Acciones legales según categoría
    let legalActions = '';
    if (al.category === 'breach_notification' || al.source === 'compliance_breach') {
        legalActions = `
        <div class="mt-4 p-3 rounded-lg border border-red-500/30 bg-red-500/[0.04]">
            <p class="text-[10px] font-medium text-red-400 mb-2">⚠ Acciones requeridas por Art. 26 Ley 21.719</p>
            <div class="space-y-1 text-[10px] text-text-body">
                ${!al.requiresAPDPNotification ? '<div class="flex items-center gap-2 text-red-400"><span class="w-3.5 h-3.5">✕</span> Notificar a APDP (sin dilación indebida, máx 72h)</div>' : '<div class="flex items-center gap-2 text-emerald-400"><span class="w-3.5 h-3.5">✓</span> APDP notificada</div>'}
                ${!al.requiresSubjectNotification ? '<div class="flex items-center gap-2 text-amber-400"><span class="w-3.5 h-3.5">✕</span> Notificar a titulares si riesgo alto (Art. 26.4)</div>' : '<div class="flex items-center gap-2 text-emerald-400"><span class="w-3.5 h-3.5">✓</span> Titulares notificados</div>'}
                <div class="flex items-center gap-2 text-blue-400"><span class="w-3.5 h-3.5">📄</span> Documentar: naturaleza, categorías, nº titulares, consecuencias, medidas</div>
                <div class="flex items-center gap-2 text-blue-400"><span class="w-3.5 h-3.5">📅</span> Registrar en compliance_breaches para trazabilidad</div>
            </div>
        </div>`;
    } else if (al.category === 'data_subject_rights' || al.source === 'arco_request') {
        legalActions = `
        <div class="mt-4 p-3 rounded-lg border border-blue-500/30 bg-blue-500/[0.04]">
            <p class="text-[10px] font-medium text-blue-400 mb-2">👥 Solicitud ARCO - Art. 8-13 Ley 21.719</p>
            <div class="space-y-1 text-[10px] text-text-body">
                <div>Plazo legal: 10 días hábiles desde recepción</div>
                <div>Responder: acceso, rectificación, cancelación, oposición o portabilidad</div>
                <div>Registrar en arco_requests para trazabilidad</div>
            </div>
        </div>`;
    } else if (al.category === 'consent_management' || al.source === 'consent_change') {
        legalActions = `
        <div class="mt-4 p-3 rounded-lg border border-amber-500/30 bg-amber-500/[0.04]">
            <p class="text-[10px] font-medium text-amber-400 mb-2">📄 Consentimiento revocado/expirado - Art. 12 Ley 21.719</p>
            <div class="space-y-1 text-[10px] text-text-body">
                <div>Cesear tratamiento basado en este consentimiento</div>
                <div>Actualizar compliance_consents (revokedAt / endDate)</div>
                <div>Verificar si afecta otros tratamientos (base legal consentimiento)</div>
            </div>
        </div>`;
    }

    document.getElementById('alert-modal-body').innerHTML = `
    <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
        <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full" style="background:${sc[1]}"></span>
            <h3 class="text-[14px] font-semibold text-white">Detalle de la alerta</h3>
        </div>
        <button onclick="closeAlertModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
            ✕
        </button>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">${rows}</div>
        ${message ? `
        <div class="mt-4">
            <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-1.5">Mensaje</p>
            <p class="text-[12px] text-text-body leading-relaxed rounded-lg border border-border-theme bg-bg-elevated/40 p-3 whitespace-pre-wrap">${esc(message)}</p>
        </div>` : ''}
        ${legalActions}
        ${extraSections}
    </div>
    <div class="px-6 py-4 border-t border-border-theme bg-bg-elevated/30 flex items-center justify-end gap-2">
        ${!resolved ? `
        <button onclick="resolveSingle('${al._id}'); closeAlertModal()" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-emerald-600 hover:bg-emerald-500 text-white transition-all flex items-center gap-1.5">
            ✓ Resolver
        </button>
        <button onclick="dismissSingle('${al._id}'); closeAlertModal()" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-amber-600 hover:bg-amber-500 text-white transition-all flex items-center gap-1.5">
            ✕ Descartar
        </button>
        ` : `
        <span class="text-[11px] text-text-muted">${resolved ? (al.resolved ? '✅ Resuelta' : '❌ Descartada') : ''}</span>
        `}
        <button onclick="markRead('${al._id}'); closeAlertModal()" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-blue-600 hover:bg-blue-500 text-white transition-all flex items-center gap-1.5">
            👁 Marcar leída
        </button>
    </div>`;

    const el = document.getElementById('alert-modal');
    el.classList.remove('hidden');
    el.classList.add('flex');
}

function markRead(id) {
    fetch('/api/alerts/read', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ alertId: id, token: SL_TOKEN })
    });
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function catLabel(c) {
    return {
        'breach_notification': 'Notificación Brechas (Art. 26)',
        'data_subject_rights': 'Derechos ARCO (Art. 8-13)',
        'consent_management': 'Consentimientos (Art. 12)',
        'security_monitoring': 'Monitoreo Seguridad (Art. 25)',
        'file_integrity': 'Integridad Archivos (Art. 25)',
        'database_access': 'Acceso BBDD (Art. 25)',
        'audit_trail': 'Auditoría (Art. 25)',
        'file_audit': 'Auditoría Archivos',
        'compliance_breach': 'Brecha Cumplimiento',
    }[c] || c;
}

// Cerrar modal con click fuera
document.addEventListener('click', function(e) {
    if (e.target.id === 'alert-modal') closeAlertModal();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAlertModal();
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT') {
        e.preventDefault();
        document.getElementById('alert-search').focus();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>