<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$tab = $_GET['tab'] ?? 'dashboard';
$currentPage = $tab;

// Fetch dashboard stats (KPIs)
$statsRes = api_post_form('/api/dashboard/stats', ['token' => $token]);
$s = is_array($statsRes) && isset($statsRes['stats']) ? $statsRes['stats'] : [];
$dbCompliance = $statsRes['dbCompliance'] ?? [];

// Fetch alerts for recent list
$alertsRes = api_post_form('/api/alerts', ['token' => $token]);
$alerts = is_array($alertsRes) && empty($alertsRes['error']) ? $alertsRes : [];

// UF value (mindicador.cl, cached in session for 6h)
$ufValue = null;
if (!empty($_SESSION['uf_cache']) && $_SESSION['uf_cache']['ts'] > time() - 21600) {
    $ufValue = $_SESSION['uf_cache']['value'];
} else {
    $ufCh = curl_init('https://mindicador.cl/api/uf');
    curl_setopt_array($ufCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
    $ufRaw = curl_exec($ufCh);
    curl_close($ufCh);
    if ($ufRaw) {
        $ufJson = json_decode($ufRaw, true);
        $ufValue = $ufJson['serie'][0]['valor'] ?? null;
        if ($ufValue) $_SESSION['uf_cache'] = ['value' => $ufValue, 'ts' => time()];
    }
}

$complianceScore = (int)($s['complianceScore'] ?? 0);
$compliantDBs = (int)($s['compliantDBs'] ?? 0);
$nonCompliantDBs = (int)($s['nonCompliantDBs'] ?? 0);
$totalDBsForGauge = max(1, $compliantDBs + $nonCompliantDBs);

function kpi_card($label, $value, $sub, $color, $icon, $big = true) {
    $size = $big ? 'text-[26px]' : 'text-[22px]';
    echo '<div class="relative overflow-hidden rounded-xl border border-white/[0.04] bg-white/[0.015] p-4 hover:border-white/[0.08] hover:bg-white/[0.025] transition-all duration-200">';
    echo '<div class="flex items-center gap-2 mb-3">';
    echo '<div class="w-7 h-7 rounded-lg flex items-center justify-center" style="color:' . $color . ';background-color:' . $color . '26">' . $icon . '</div>';
    echo '<p class="text-[10px] font-medium text-text-muted uppercase tracking-widest">' . h($label) . '</p>';
    echo '</div>';
    echo '<p class="' . $size . ' font-bold tracking-tight leading-none" style="color:' . $color . '">' . h($value) . '</p>';
    echo '<p class="text-[10px] text-white/20 mt-1.5 font-medium truncate">' . h($sub) . '</p>';
    echo '</div>';
}
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Top bar -->
        <header class="md:hidden flex items-center gap-3 px-3 py-2.5 border-b border-border-theme flex-shrink-0">
            <button onclick="document.querySelector('aside').classList.toggle('hidden');document.querySelector('aside').classList.toggle('fixed');document.querySelector('aside').classList.toggle('inset-y-0');document.querySelector('aside').classList.toggle('left-0');document.querySelector('aside').classList.toggle('z-50');"
                    class="p-1.5 rounded-lg text-text-muted hover:text-text-heading hover:bg-bg-elevated transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="flex items-center gap-2 flex-1 min-w-0">
                <div class="w-6 h-6 rounded overflow-hidden flex-shrink-0 bg-bg-panel">
                    <img src="/logo-nuevo.png" alt="" class="w-full h-full object-contain">
                </div>
                <span class="text-[13px] font-medium text-white truncate">
                    <?php
                    $tabLabels = [
                        'dashboard' => 'Dashboard', 'agents' => 'Agentes', 'host-monitor' => 'Host Monitor',
                        'alerts' => 'Alertas', 'reports' => 'Reportes', 'databases' => 'Bases de Datos',
                        'db-logs' => 'Logs DB', 'compliance' => 'Compliance', 'hardening' => 'Hardening',
                        'tickets' => 'Tickets', 'arco' => 'ARCO', 'dpo' => 'DPO', 'settings' => 'Ajustes',
                    ];
                    echo h($tabLabels[$tab] ?? 'Dashboard');
                    ?>
                </span>
            </div>
            <div class="w-7 h-7 rounded-full bg-primary-600 flex items-center justify-center text-white text-[10px] font-bold">
                <?= h(strtoupper(substr($user['email'] ?? 'U', 0, 2))) ?>
            </div>
        </header>

        <!-- Ley 21.719 banner -->
        <div class="flex-shrink-0 mx-4 md:mx-6 mt-3 px-4 py-2.5 rounded-lg bg-blue-500/[0.06] border border-blue-500/20 flex items-start gap-2.5" id="ley-banner">
            <svg class="w-4 h-4 text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <p class="text-[11px] text-text-body leading-relaxed flex-1">
                <span class="font-semibold text-blue-300">Aviso Importante – Ley N.º 21.719 (Protección de Datos Personales)</span><br>
                Si tu organización trata datos personales, recuerda que debes iniciar el proceso de adecuación con al menos 6 meses de anticipación para cumplir con las exigencias de la Ley N.º 21.719. Prepárate con tiempo: te permitirá implementar las medidas necesarias y evitar riesgos de incumplimiento cuando la normativa entre en plena vigencia.
            </p>
            <button onclick="document.getElementById('ley-banner').remove()" class="text-text-subtle hover:text-text-heading flex-shrink-0">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Header -->
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Dashboard</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= h($user['companyName'] ?? $user['email'] ?? '') ?></p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-[10px] text-white/20 font-medium hidden sm:inline tabular-nums">Actualizado <?= date('H:i') ?></span>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] hover:border-white/[0.08] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </div>

        <!-- Content area -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <!-- KPI grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 tour-detail-kpi-grid">
                <?php
                $iconAgents = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
                $iconDb = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>';
                $iconShield = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
                $iconWarn = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>';
                $iconUsers = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/></svg>';

                kpi_card('Agentes', $s['onlineAgents'] ?? 0, ($s['totalAgents'] ?? 0) . ' registrados', '#60a5fa', $iconAgents);
                kpi_card('Bases de Datos', $s['totalDatabases'] ?? 0, ($s['totalTables'] ?? 0) . ' tablas · ' . number_format($s['totalRecords'] ?? 0) . ' registros', '#34d399', $iconDb);
                kpi_card('Cumplimiento', $complianceScore . '%', $compliantDBs . ' cumplen · ' . $nonCompliantDBs . ' no cumplen', $complianceScore >= 70 ? '#34d399' : '#f87171', $iconShield);
                kpi_card('Brechas', $s['openBreaches'] ?? 0, ($s['totalBreaches'] ?? 0) . ' reportadas', ($s['openBreaches'] ?? 0) > 0 ? '#f87171' : '#34d399', $iconWarn);
                kpi_card('Usuarios Vulnerables', $s['vulnerableUsersCount'] ?? 0, 'Datos en riesgo', ($s['vulnerableUsersCount'] ?? 0) > 0 ? '#f87171' : '#34d399', $iconUsers);
                ?>
            </div>

            <!-- UF value -->
            <?php if ($ufValue): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] px-5 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-9 h-9 rounded-lg bg-white/[0.03] border border-white/[0.05] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[10px] text-text-subtle uppercase tracking-widest font-medium">Valor UF Hoy</p>
                        <p class="text-[16px] font-semibold text-white mt-0.5 tracking-tight">$<?= number_format($ufValue, 2, ',', '.') ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[10px] text-text-subtle font-medium">Plan: <span class="text-text-body font-semibold"><?= h($user['planType'] ?? 'Gratuito') ?></span></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Secondary stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 tour-detail-stats-grid">
                <?php
                $iconBell = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
                $iconScan = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>';
                $iconReport = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
                $iconOnline = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/></svg>';

                kpi_card('Alertas Activas', $s['activeAlerts'] ?? 0, 'Pendientes de revisión', ($s['activeAlerts'] ?? 0) > 0 ? '#fb7185' : '#34d399', $iconBell, false);
                kpi_card('Escaneos', $s['completedScans'] ?? 0, ($s['totalScans'] ?? 0) . ' programados', '#818cf8', $iconScan, false);
                kpi_card('Reportes', $s['generatedReports'] ?? 0, 'Este mes', '#fbbf24', $iconReport, false);
                kpi_card('Agentes Online', $s['onlineAgents'] ?? 0, ($s['totalAgents'] ?? 0) . ' registrados', '#22d3ee', $iconOnline, false);
                ?>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 tour-detail-tabs">
                <div class="flex rounded-lg bg-white/[0.02] border border-white/[0.04] p-0.5">
                    <button onclick="showDashTab('overview')" data-dashtab="overview" class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all bg-white/[0.06] text-white/80">Resumen DB</button>
                    <button onclick="showDashTab('vulnerable')" data-dashtab="vulnerable" class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50">Vulnerables</button>
                    <button onclick="showDashTab('ley21719')" data-dashtab="ley21719" class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50">Ley 21.719</button>
                </div>
            </div>

            <!-- Tab: overview -->
            <div id="dashtab-overview" class="dashtab-content space-y-4">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1.5 px-5 py-3 rounded-xl border border-white/[0.04] bg-white/[0.01] tour-detail-summary">
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $s['totalDatabases'] ?? 0 ?></span> bases de datos</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $s['onlineAgents'] ?? 0 ?></span> agentes activos</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $s['openBreaches'] ?? 0 ?></span> brechas abiertas</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $s['totalTables'] ?? 0 ?></span> tablas</span>
                    <span class="text-[11px] text-text-subtle font-medium ml-auto"><span class="font-semibold <?= $complianceScore >= 70 ? 'text-[#34d399]' : 'text-[#f87171]' ?>"><?= $complianceScore ?>%</span> cumplimiento global</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- Gauge: Cumplimiento Global -->
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5 flex flex-col items-center justify-center">
                        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3 self-start">Cumplimiento Global</p>
                        <?php $gaugeColor = $complianceScore >= 70 ? '#34d399' : '#f87171'; $gaugeCirc = M_PI * 60; ?>
                        <div class="relative w-40 h-24">
                            <svg viewBox="0 0 160 90" class="w-full h-full">
                                <path d="M 20 85 A 60 60 0 0 1 140 85" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" stroke-linecap="round"/>
                                <path d="M 20 85 A 60 60 0 0 1 140 85" fill="none" stroke="<?= $gaugeColor ?>" stroke-width="10" stroke-linecap="round" stroke-dasharray="<?= $gaugeCirc ?>" stroke-dashoffset="<?= $gaugeCirc * (1 - $complianceScore / 100) ?>"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-end pb-1">
                                <span class="text-[22px] font-bold" style="color: <?= $gaugeColor ?>"><?= $complianceScore ?>%</span>
                                <span class="text-[10px] text-text-subtle">Ley 21.719</span>
                            </div>
                        </div>
                        <p class="text-[10px] text-text-subtle mt-2"><?= $compliantDBs ?> de <?= $compliantDBs + $nonCompliantDBs ?> DBs</p>
                    </div>

                    <!-- Donut: Distribución de Cumplimiento -->
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5 flex flex-col justify-center">
                        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Distribución de Cumplimiento</p>
                        <?php
                        $total = max(1, count($dbCompliance));
                        $cp = count($dbCompliance) > 0 ? ($compliantDBs / $total) * 100 : 0;
                        $donutCirc = 2 * M_PI * 42;
                        ?>
                        <div class="flex flex-col sm:flex-row items-center gap-5">
                            <div class="relative w-28 h-28 flex-shrink-0">
                                <svg viewBox="0 0 110 110" class="w-full h-full -rotate-90">
                                    <circle cx="55" cy="55" r="42" fill="none" stroke="rgba(248,113,113,0.35)" stroke-width="12"/>
                                    <circle cx="55" cy="55" r="42" fill="none" stroke="#34d399" stroke-width="12" stroke-dasharray="<?= $donutCirc ?>" stroke-dashoffset="<?= $donutCirc * (1 - $cp / 100) ?>" stroke-linecap="round"/>
                                </svg>
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="text-[13px] font-bold text-white"><?= $compliantDBs ?> DBs</span>
                                    <span class="text-[9px] text-text-subtle">cumplen</span>
                                </div>
                            </div>
                            <div class="flex-1 w-full space-y-2">
                                <div>
                                    <div class="flex items-center justify-between text-[10px] text-text-muted mb-1"><span>Cumplen</span><span><?= round($cp) ?>%</span></div>
                                    <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden"><div class="h-full rounded-full bg-[#34d399]" style="width:<?= $cp ?>%"></div></div>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between text-[10px] text-text-muted mb-1"><span>No cumplen</span><span><?= round(100 - $cp) ?>%</span></div>
                                    <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden"><div class="h-full rounded-full bg-[#f87171]" style="width:<?= 100 - $cp ?>%"></div></div>
                                </div>
                                <div class="flex items-center gap-4 mt-1">
                                    <span class="flex items-center gap-2 text-[11px] text-text-muted font-medium"><span class="w-2 h-2 rounded-full bg-[#34d399]"></span> <?= $compliantDBs ?> cumplen</span>
                                    <span class="flex items-center gap-2 text-[11px] text-text-muted font-medium"><span class="w-2 h-2 rounded-full bg-[#f87171]"></span> <?= $nonCompliantDBs ?> no cumplen</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top DBs por cumplimiento -->
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Top Bases de Datos por Cumplimiento</p>
                        <?php if (empty($dbCompliance)): ?>
                        <p class="text-[11px] text-text-subtle text-center py-6">Sin bases de datos conectadas</p>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach (array_slice($dbCompliance, 0, 5) as $d): $pct = $d['compliant'] ? 100 : 0; ?>
                            <div>
                                <div class="flex items-center justify-between text-[10px] text-text-muted mb-1">
                                    <span class="truncate"><?= h($d['name']) ?></span><span><?= $pct ?>%</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                                    <div class="h-full rounded-full <?= $d['compliant'] ? 'bg-[#34d399]' : 'bg-[#f87171]' ?>" style="width:<?= max(4, $pct) ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cumplimiento por DB detalle -->
                <?php if (!empty($dbCompliance)): ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
                    <div class="px-5 py-3 border-b border-white/[0.04]">
                        <p class="text-[11px] font-semibold text-text-heading">Cumplimiento por Base de Datos — Ley 21.719</p>
                    </div>
                    <div class="divide-y divide-white/[0.03]">
                        <?php foreach ($dbCompliance as $d): ?>
                        <div class="px-5 py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[12px] font-medium text-text-heading truncate"><?= h($d['name']) ?> <span class="text-[10px] text-text-subtle">(<?= h($d['engine']) ?>)</span></p>
                                <p class="text-[10px] text-text-subtle mt-0.5"><?= $d['tables'] ?> tablas · <?= number_format($d['records']) ?> registros · <?= $d['breaches'] ?> brechas de seguridad</p>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0 w-40">
                                <div class="flex-1 h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                                    <div class="h-full rounded-full <?= $d['compliant'] ? 'bg-[#34d399]' : 'bg-[#f87171]' ?>" style="width:<?= $d['compliant'] ? 100 : 8 ?>%"></div>
                                </div>
                                <span class="text-[11px] font-semibold <?= $d['compliant'] ? 'text-[#34d399]' : 'text-[#f87171]' ?>"><?= $d['compliant'] ? '100%' : '0%' ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: vulnerable -->
            <div id="dashtab-vulnerable" class="dashtab-content hidden">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <?php if (($s['vulnerableUsersCount'] ?? 0) === 0): ?>
                    <p class="text-[12px] text-text-heading font-medium">Sin usuarios vulnerables detectados</p>
                    <p class="text-[11px] text-text-subtle mt-1">No se han encontrado credenciales débiles ni comprometidas.</p>
                    <?php else: ?>
                    <p class="text-[12px] text-red-400 font-medium"><?= $s['vulnerableUsersCount'] ?> usuarios vulnerables</p>
                    <p class="text-[11px] text-text-subtle mt-1">Revisa el módulo de monitoreo de usuarios para más detalle.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: ley21719 -->
            <div id="dashtab-ley21719" class="dashtab-content hidden">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <p class="text-[12px] text-text-heading font-medium">Cumplimiento Ley 21.719: <?= $complianceScore ?>%</p>
                    <p class="text-[11px] text-text-subtle mt-1">Consulta el módulo de Compliance para gestionar consentimientos, DPIAs, brechas e inventario.</p>
                    <a href="/compliance" class="btn-primary text-[11px] inline-block mt-4">Ir a Compliance</a>
                </div>
            </div>

            <!-- Recent Alerts -->
            <?php if (!empty($alerts)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                <h3 class="text-[12px] font-semibold text-white mb-4">Alertas recientes</h3>
                <div class="space-y-2">
                    <?php foreach (array_slice($alerts, 0, 5) as $alert): ?>
                    <div class="flex items-center justify-between p-3 rounded-lg border border-border-theme bg-bg-elevated/50">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-2 h-2 rounded-full flex-shrink-0 <?= ($alert['severity'] ?? 'low') === 'critical' ? 'bg-red-500' : (($alert['severity'] ?? '') === 'high' ? 'bg-orange-500' : (($alert['severity'] ?? '') === 'medium' ? 'bg-yellow-500' : 'bg-green-500')) ?>"></span>
                            <span class="text-[12px] text-text-body truncate"><?= h($alert['title'] ?? $alert['message'] ?? 'Sin título') ?></span>
                        </div>
                        <span class="text-[10px] text-text-subtle flex-shrink-0 ml-3"><?= h(substr($alert['createdAt'] ?? '', 0, 16)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
function showDashTab(key) {
    document.querySelectorAll('.dashtab-content').forEach(el => el.classList.add('hidden'));
    document.getElementById('dashtab-' + key).classList.remove('hidden');
    document.querySelectorAll('.dash-tab').forEach(btn => {
        const active = btn.dataset.dashtab === key;
        btn.classList.toggle('bg-white/[0.06]', active);
        btn.classList.toggle('text-white/80', active);
        btn.classList.toggle('text-text-subtle', !active);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
