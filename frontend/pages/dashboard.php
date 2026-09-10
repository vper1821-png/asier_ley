<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user  = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';

// ── Fetch dashboard stats (KPIs) ──
$statsRes = api_post_form('/api/dashboard/stats', ['token' => $token]);
$statsOk  = is_array($statsRes) && isset($statsRes['stats']);

$s               = $statsOk ? $statsRes['stats']           : [];
$scores          = $statsOk ? ($statsRes['scores']          ?? []) : [];
$checklistMeta   = $statsOk ? ($statsRes['checklist']       ?? ['done'=>0,'total'=>10]) : ['done'=>0,'total'=>10];
$dbCompliance    = $statsOk ? ($statsRes['dbCompliance']    ?? []) : [];
$complianceItems = $statsOk ? ($statsRes['complianceItems'] ?? []) : [];
$companyName     = $statsRes['companyName'] ?? ($user['companyName'] ?? $user['email'] ?? '');
$userCount       = $statsRes['userCount']   ?? null;

// ── Valores clave ──
$onlineAgents    = (int)($s['onlineAgents']         ?? 0);
$totalAgents     = (int)($s['totalAgents']          ?? 0);
$totalDatabases  = (int)($s['totalDatabases']       ?? 0);
$compliantDBs    = (int)($s['compliantDBs']         ?? 0);
$nonCompliantDBs = (int)($s['nonCompliantDBs']      ?? 0);
$activeAlerts    = (int)($s['activeAlerts']         ?? 0);
$openBreaches    = (int)($s['openBreaches']         ?? 0);
$totalBreaches   = (int)($s['totalBreaches']        ?? 0);
$vulnUsers       = (int)($s['vulnerableUsersCount'] ?? 0);

$globalScore     = (int)($scores['global']         ?? 0);
$agentDBScore    = (int)($scores['agentDb']        ?? 0);
$complianceScore = (int)($scores['compliance']     ?? 0);
$hardeningScore  = (int)($scores['hardening']      ?? 0);
$hardeningDone   = (int)($scores['hardeningDone']  ?? 0);
$hardeningTotal  = (int)($scores['hardeningTotal'] ?? 6);
$checklistDone   = (int)($checklistMeta['done']    ?? 0);
$checklistTotal  = max(1, (int)($checklistMeta['total'] ?? 10));

$pctColor = $complianceScore >= 70 ? 'text-emerald-400' : ($complianceScore >= 40 ? 'text-yellow-400' : 'text-red-400');
$pctBar   = $complianceScore >= 70 ? 'bg-emerald-500'   : ($complianceScore >= 40 ? 'bg-yellow-500'   : 'bg-red-500');

function kpi_card($label, $value, $sub, $color, $icon, $big = true) {
    $size = $big ? 'text-[25px] sm:text-[28px]' : 'text-[21px] sm:text-[24px]';
    echo '<div class="app-card app-card-interactive app-kpi p-4 sm:p-5">';
    echo '<div class="relative z-10 flex items-center gap-2.5 mb-3">';
    echo '<div class="w-8 h-8 rounded-xl flex items-center justify-center" style="color:'.$color.';background-color:'.$color.'1f;border:1px solid '.$color.'33">'.$icon.'</div>';
    echo '<p class="text-[9px] sm:text-[10px] font-semibold text-text-subtle uppercase tracking-[.14em]">'.h($label).'</p>';
    echo '</div>';
    echo '<p class="relative z-10 '.$size.' font-bold tracking-tight leading-none" style="color:'.$color.'">'.h($value).'</p>';
    echo '<p class="relative z-10 text-[9px] sm:text-[10px] text-text-muted mt-2 font-medium truncate">'.h($sub).'</p>';
    echo '</div>';
}
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header -->
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Dashboard</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium">
                    <?= h($companyName) ?>
                    <?php if ($userCount !== null): ?>
                        · <span class="text-text-muted"><?= $userCount ?> usuario<?= $userCount === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-[10px] text-white/20 font-medium hidden sm:inline tabular-nums">Actualizado <?= date('H:i') ?></span>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] hover:border-white/[0.08] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">

            <?php if (!$statsOk): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/5 p-4">
                <p class="text-[12px] text-red-400 font-medium">No se pudieron cargar las estadísticas del dashboard.</p>
                <p class="text-[11px] text-red-300/70 mt-1">Verifica la conexión con el backend o recarga la página.</p>
            </div>
            <?php endif; ?>

            <!-- KPI grid principal -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
                <?php
                $icoAgents = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
                $icoDb     = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>';
                $icoShield = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
                $icoWarn   = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>';
                $icoUsers  = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/></svg>';

                kpi_card('Agentes', $onlineAgents, $totalAgents.' registrados', '#60a5fa', $icoAgents);
                kpi_card('Bases de Datos', $totalDatabases, ($s['totalTables'] ?? 0).' tablas · '.number_format($s['totalRecords'] ?? 0).' registros', '#34d399', $icoDb);
                kpi_card('Cumplimiento', $complianceScore.'%', $compliantDBs.' cumplen · '.$nonCompliantDBs.' no cumplen', $complianceScore >= 70 ? '#34d399' : '#f87171', $icoShield);
                kpi_card('Brechas', $openBreaches, $totalBreaches.' reportadas', $openBreaches > 0 ? '#f87171' : '#34d399', $icoWarn);
                kpi_card('Usuarios Vulnerables', $vulnUsers, 'Datos en riesgo', $vulnUsers > 0 ? '#f87171' : '#34d399', $icoUsers);
                ?>
            </div>

            <!-- Secondary stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <?php
                $icoBell   = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
                $icoScan   = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>';
                $icoReport = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
                $icoOnline = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z"/></svg>';

                kpi_card('Alertas Activas', $activeAlerts, 'Pendientes de revisión', $activeAlerts > 0 ? '#fb7185' : '#34d399', $icoBell, false);
                kpi_card('Escaneos', $s['completedScans'] ?? 0, ($s['totalScans'] ?? 0).' programados', '#818cf8', $icoScan, false);
                kpi_card('Reportes', $s['generatedReports'] ?? 0, 'Este mes', '#fbbf24', $icoReport, false);
                kpi_card('Agentes Online', $onlineAgents, $totalAgents.' registrados', '#22d3ee', $icoOnline, false);
                ?>
            </div>

            <!-- Tabs -->
            <div class="flex items-center gap-1 overflow-x-auto">
                <div class="flex rounded-lg bg-white/[0.02] border border-white/[0.04] p-0.5">
                    <button onclick="showDashTab('overview')"   data-dashtab="overview"   class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all bg-white/[0.06] text-white/80 whitespace-nowrap">Resumen</button>
                    <button onclick="showDashTab('ley21719')"   data-dashtab="ley21719"   class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50 whitespace-nowrap">Ley 21.719</button>
                    <button onclick="showDashTab('arco')"       data-dashtab="arco"       class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50 whitespace-nowrap">ARCO</button>
                    <button onclick="showDashTab('brechas')"    data-dashtab="brechas"    class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50 whitespace-nowrap">Brechas 72h</button>
                    <button onclick="showDashTab('archivos')"   data-dashtab="archivos"   class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50 whitespace-nowrap">Archivos &amp; PII</button>
                    <button onclick="showDashTab('auditoria')"  data-dashtab="auditoria"  class="dash-tab px-3.5 py-1.5 rounded-md text-[11px] font-medium transition-all text-text-subtle hover:text-text-heading/50 whitespace-nowrap">Auditoría</button>
                </div>
            </div>

            <!-- ══════════════ TAB: Resumen ══════════════ -->
            <div id="dashtab-overview" class="dashtab-content space-y-4">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1.5 px-5 py-3 rounded-xl border border-white/[0.04] bg-white/[0.01]">
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $totalDatabases ?></span> bases de datos</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $onlineAgents ?></span> agentes activos</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $openBreaches ?></span> brechas abiertas</span>
                    <span class="text-[11px] text-text-subtle font-medium"><span class="text-white/80 font-semibold"><?= $s['totalTables'] ?? 0 ?></span> tablas</span>
                    <span class="text-[11px] text-text-subtle font-medium ml-auto"><span class="font-semibold <?= $complianceScore >= 70 ? 'text-[#34d399]' : 'text-[#f87171]' ?>"><?= $complianceScore ?>%</span> cumplimiento global</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- Gauge global -->
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5 flex flex-col items-center justify-center">
                        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3 self-start">Cumplimiento Global</p>
                        <?php $gaugeColor = $globalScore >= 70 ? '#34d399' : ($globalScore >= 40 ? '#facc15' : '#f87171'); $gaugeCirc = M_PI * 60; ?>
                        <div class="relative w-40 h-24">
                            <svg viewBox="0 0 160 90" class="w-full h-full">
                                <path d="M 20 85 A 60 60 0 0 1 140 85" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" stroke-linecap="round"/>
                                <path d="M 20 85 A 60 60 0 0 1 140 85" fill="none" stroke="<?= $gaugeColor ?>" stroke-width="10" stroke-linecap="round"
                                      stroke-dasharray="<?= $gaugeCirc ?>" stroke-dashoffset="<?= $gaugeCirc * (1 - $globalScore / 100) ?>"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-end pb-1">
                                <span class="text-[22px] font-bold" style="color:<?= $gaugeColor ?>"><?= $globalScore ?>%</span>
                                <span class="text-[10px] text-text-subtle">Promedio de 3 áreas</span>
                            </div>
                        </div>
                        <p class="text-[9px] text-text-subtle/80 mt-2 text-center max-w-[220px]">Indicador orientativo basado en evidencia registrada; no constituye certificación legal.</p>
                    </div>

                    <!-- Donut -->
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
                                    <circle cx="55" cy="55" r="42" fill="none" stroke="#34d399" stroke-width="12"
                                            stroke-dasharray="<?= $donutCirc ?>" stroke-dashoffset="<?= $donutCirc * (1 - $cp / 100) ?>" stroke-linecap="round"/>
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

                    <!-- Top DBs -->
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Top Bases de Datos</p>
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

                <!-- Desglose por área -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-7 h-7 rounded-lg bg-yellow-500/10 border border-yellow-500/20 flex items-center justify-center text-yellow-400"><?= $icoAgents ?></div>
                            <div>
                                <p class="text-[11px] font-semibold text-text-heading">Agente &amp; Base de datos</p>
                                <p class="text-[18px] font-bold text-yellow-400 leading-none mt-0.5"><?= $agentDBScore ?>%</p>
                            </div>
                        </div>
                        <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden mb-3"><div class="h-full rounded-full bg-yellow-400" style="width:<?= $agentDBScore ?>%"></div></div>
                        <div class="space-y-1.5 text-[10px] text-text-subtle">
                            <p class="flex items-center justify-between"><span>Agentes online</span><span class="text-text-body font-medium"><?= $onlineAgents ?> / <?= $totalAgents ?></span></p>
                            <p class="flex items-center justify-between"><span>DBs cumplen</span><span class="text-text-body font-medium"><?= $compliantDBs ?> / <?= $totalDatabases ?></span></p>
                            <p class="text-text-muted pt-1 border-t border-white/[0.04] mt-2">Conectividad de agentes y estado de cumplimiento de bases de datos.</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-7 h-7 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400"><?= $icoShield ?></div>
                            <div>
                                <p class="text-[11px] font-semibold text-text-heading">Compliance</p>
                                <p class="text-[18px] font-bold text-emerald-400 leading-none mt-0.5"><?= $complianceScore ?>%</p>
                            </div>
                        </div>
                        <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden mb-3"><div class="h-full rounded-full bg-emerald-400" style="width:<?= $complianceScore ?>%"></div></div>
                        <div class="space-y-1.5 text-[10px] text-text-subtle">
                            <p class="flex items-center justify-between"><span>Tareas cumplidas</span><span class="text-text-body font-medium"><?= $checklistDone ?> / <?= $checklistTotal ?></span></p>
                            <p class="flex items-center justify-between"><span>Score ponderado</span><span class="text-text-body font-medium"><?= $complianceScore ?> / 100</span></p>
                            <p class="text-text-muted pt-1 border-t border-white/[0.04] mt-2">Avance en requisitos legales Ley 21.719 (peso por criticidad).</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-7 h-7 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold text-text-heading">Hardening</p>
                                <p class="text-[18px] font-bold text-red-400 leading-none mt-0.5"><?= $hardeningScore ?>%</p>
                            </div>
                        </div>
                        <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden mb-3"><div class="h-full rounded-full bg-red-400" style="width:<?= $hardeningScore ?>%"></div></div>
                        <div class="space-y-1.5 text-[10px] text-text-subtle">
                            <p class="flex items-center justify-between"><span>Medidas aplicadas</span><span class="text-text-body font-medium"><?= $hardeningDone ?> / <?= $hardeningTotal ?></span></p>
                            <p class="flex items-center justify-between"><span>Medidas pendientes</span><span class="text-text-body font-medium"><?= max(0, $hardeningTotal - $hardeningDone) ?></span></p>
                            <p class="text-text-muted pt-1 border-t border-white/[0.04] mt-2">Agentes online, DBs cumpliendo y ausencia de brechas abiertas.</p>
                        </div>
                    </div>
                </div>

                <!-- Detalle por DB -->
                <?php if (!empty($dbCompliance)): ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
                    <div class="px-5 py-3 border-b border-white/[0.04]">
                        <p class="text-[11px] font-semibold text-text-heading">Cumplimiento por Base de Datos — Ley 21.719</p>
                    </div>
                    <div class="divide-y divide-white/[0.03]">
                        <?php foreach ($dbCompliance as $d):
                            $isConnected = ($d['status'] ?? '') === 'connected';
                            $label = $d['compliant'] ? 'Cumple' : ($isConnected ? 'No cumple' : 'No conectada');
                            $color = $d['compliant'] ? 'text-[#34d399]' : 'text-[#f87171]';
                            $bg = $d['compliant'] ? 'bg-[#34d399]' : 'bg-[#f87171]';
                        ?>
                        <div class="px-5 py-3 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-[12px] font-medium text-text-heading truncate"><?= h($d['name']) ?> <span class="text-[10px] text-text-subtle">(<?= h($d['engine']) ?>)</span></p>
                                <p class="text-[10px] text-text-subtle mt-0.5"><?= $d['tables'] ?> tablas · <?= number_format($d['records']) ?> registros · <?= $d['breaches'] ?> brechas</p>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0 w-44">
                                <div class="flex-1 h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                                    <div class="h-full rounded-full <?= $bg ?>" style="width:<?= $d['compliant'] ? 100 : 8 ?>%"></div>
                                </div>
                                <span class="text-[11px] font-semibold <?= $color ?> w-20 text-right"><?= $label ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════ TAB: Ley 21.719 ══════════════ -->
            <div id="dashtab-ley21719" class="dashtab-content hidden">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h4 class="text-[14px] font-semibold text-text-heading">Checklist de Cumplimiento Ley 21.719</h4>
                            <p class="text-[11px] text-text-subtle mt-1"><?= $checklistDone ?> de <?= $checklistTotal ?> requisitos cumplidos · Score ponderado: <span class="font-semibold <?= $pctColor ?>"><?= $complianceScore ?>%</span></p>
                        </div>
                        <div class="text-right"><span class="text-[24px] font-bold <?= $pctColor ?>"><?= $complianceScore ?>%</span></div>
                    </div>
                    <div class="w-full bg-bg-elevated/50 rounded-full h-2.5 mb-5">
                        <div class="h-full rounded-full transition-all duration-700 <?= $pctBar ?>" style="width: <?= $complianceScore ?>%"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($complianceItems as $item): ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg <?= $item['done'] ? 'bg-emerald-500/[0.04]' : 'bg-bg-base/40' ?> border border-white/[0.04]">
                            <span class="mt-0.5 flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center text-[10px] <?= $item['done'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400' ?>">
                                <?php if ($item['done']): ?>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <?php else: ?>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?php endif; ?>
                            </span>
                            <div class="flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[12px] font-medium <?= $item['done'] ? 'text-emerald-300' : 'text-text-heading' ?>"><?= h($item['label']) ?></p>
                                    <span class="text-[9px] text-text-subtle font-mono bg-white/[0.03] px-1.5 py-0.5 rounded">peso <?= (int)($item['weight'] ?? 0) ?>%</span>
                                </div>
                                <p class="text-[10px] text-text-subtle mt-0.5"><?= h($item['desc']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══════════════ TAB: ARCO ══════════════ -->
            <div id="dashtab-arco" class="dashtab-content hidden">
                <div id="arco-loading" class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <p class="text-[11px] text-text-subtle">Cargando solicitudes ARCO…</p>
                </div>
                <div id="arco-body" class="hidden space-y-4"></div>
            </div>

            <!-- ══════════════ TAB: Brechas ══════════════ -->
            <div id="dashtab-brechas" class="dashtab-content hidden">
                <div id="breach-loading" class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <p class="text-[11px] text-text-subtle">Cargando brechas…</p>
                </div>
                <div id="breach-body" class="hidden space-y-4"></div>
            </div>

            <!-- ══════════════ TAB: Archivos & PII ══════════════ -->
            <div id="dashtab-archivos" class="dashtab-content hidden">
                <div id="files-loading" class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <p class="text-[11px] text-text-subtle">Cargando archivos monitoreados…</p>
                </div>
                <div id="files-body" class="hidden space-y-4"></div>
            </div>

            <!-- ══════════════ TAB: Auditoría ══════════════ -->
            <div id="dashtab-auditoria" class="dashtab-content hidden">
                <div id="audit-loading" class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-8 text-center">
                    <p class="text-[11px] text-text-subtle">Cargando actividad…</p>
                </div>
                <div id="audit-body" class="hidden space-y-4"></div>
            </div>

        </div>
    </main>
</div>

<script>
const DASH_TOKEN = <?= json_encode($token) ?>;
const loadedTabs = new Set();

// ── Fetch helper ──
async function dashFetch(path) {
    const res = await fetch(path, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ token: DASH_TOKEN })
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function kpiMini(label, value, sub, color) {
    return `
      <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-4">
        <p class="text-[9px] font-semibold text-text-subtle uppercase tracking-[.14em] mb-2">${esc(label)}</p>
        <p class="text-[20px] font-bold leading-none" style="color:${color}">${esc(value)}</p>
        <p class="text-[10px] text-text-muted mt-1.5">${esc(sub)}</p>
      </div>`;
}

// Convierte cualquier formato de piiType a un string legible
function piiLabel(t) {
    if (t === null || t === undefined) return '';
    if (typeof t === 'string') return t;
    if (typeof t === 'object') return t.type || t.name || t.label || JSON.stringify(t);
    return String(t);
}

// ── Tabs ──
function showDashTab(key) {
    document.querySelectorAll('.dashtab-content').forEach(el => el.classList.add('hidden'));
    document.getElementById('dashtab-' + key).classList.remove('hidden');
    document.querySelectorAll('.dash-tab').forEach(btn => {
        const active = btn.dataset.dashtab === key;
        btn.classList.toggle('bg-white/[0.06]', active);
        btn.classList.toggle('text-white/80', active);
        btn.classList.toggle('text-text-subtle', !active);
    });
    if (!loadedTabs.has(key)) {
        loadedTabs.add(key);
        if (key === 'arco')       loadArco();
        if (key === 'brechas')    loadBreaches();
        if (key === 'archivos')   loadFiles();
        if (key === 'auditoria')  loadAudit();
    }
}

// ═══════════════════════════════════════════════════════════
// ARCO — 10 días hábiles (Ley 21.719)
// ═══════════════════════════════════════════════════════════
async function loadArco() {
    const L = document.getElementById('arco-loading');
    const B = document.getElementById('arco-body');
    try {
        const d = await dashFetch('/api/dashboard/arco-summary');

        // Colección no encontrada → mensaje de ayuda
        if ((!d.recent || d.recent.length === 0) && (d.total || 0) === 0 && d._debug && d._debug.collection === null) {
            L.innerHTML = `
              <div class="rounded-xl border border-yellow-500/30 bg-yellow-500/5 p-6 text-center space-y-2">
                <p class="text-[12px] text-yellow-300 font-semibold">No se encontró la colección ARCO</p>
                <p class="text-[11px] text-yellow-200/70">
                  Prueba <code class="font-mono bg-black/30 px-1.5 py-0.5 rounded">/api/dashboard/arco-debug</code>
                  para ver la colección y campos reales.
                </p>
              </div>`;
            return;
        }

        L.classList.add('hidden');
        B.classList.remove('hidden');

        const slaDays   = d.slaDays || 10;
        const pending   = d.pending ?? 0;
        const inProg    = d.in_progress ?? 0;
        const completed = (d.completed ?? 0) + (d.finished ?? 0);
        const overdue   = d.overdue ?? 0;
        const avgDays   = d.avgBusinessDays ?? 0;
        const debugBadge = d._debug && d._debug.collection
            ? `<span class="text-[9px] text-text-subtle/50 font-mono">col: ${esc(d._debug.collection)} (${esc(d._debug.via || '')})</span>`
            : '';

        B.innerHTML = `
          <div class="flex items-center justify-between flex-wrap gap-2">
            <p class="text-[11px] text-text-subtle">SLA legal: <span class="text-white font-semibold">${slaDays} días hábiles</span> (Ley 21.719)</p>
            <div class="flex items-center gap-3">
              <a href="/arco.php" class="text-[11px] text-blue-400 hover:text-blue-300 underline underline-offset-2">Ir al módulo ARCO completo →</a>
              ${debugBadge}
            </div>
          </div>

          <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            ${kpiMini('Total', d.total ?? 0, 'Solicitudes recibidas', '#818cf8')}
            ${kpiMini('Pendientes', pending, 'Requieren atención', pending > 0 ? '#fbbf24' : '#34d399')}
            ${kpiMini('En proceso', inProg, 'Siendo gestionadas', '#60a5fa')}
            ${kpiMini('Completadas', completed, 'Respondidas al titular', '#34d399')}
            ${kpiMini('Vencidas', overdue, `SLA ${slaDays} días`, overdue > 0 ? '#f87171' : '#34d399')}
          </div>

          <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
            <div class="px-5 py-3 border-b border-white/[0.04] flex items-center justify-between">
              <p class="text-[11px] font-semibold text-text-heading">Últimas solicitudes</p>
              <span class="text-[10px] text-text-subtle">Prom. resolución: ${avgDays} días hábiles</span>
            </div>
            <div class="divide-y divide-white/[0.03]">
              ${(d.recent || []).length === 0
                ? '<p class="px-5 py-6 text-center text-[11px] text-text-subtle">Sin solicitudes ARCO para esta empresa.</p>'
                : d.recent.map(r => {
                    const isClosed = ['completed','finished','resolved','rejected'].includes(r.status);
                    const cls = r.overdue
                      ? 'text-red-400'
                      : (isClosed ? 'text-emerald-400'
                          : (typeof r.daysRemaining === 'number' && r.daysRemaining <= 3 ? 'text-yellow-400' : 'text-emerald-400'));
                    let label;
                    if (isClosed) {
                        label = 'Cerrada';
                    } else if (r.overdue) {
                        label = `Vencida ${Math.abs(r.daysRemaining)} d`;
                    } else if (typeof r.daysRemaining === 'number') {
                        label = `${r.daysRemaining} días restantes`;
                    } else {
                        label = 'En plazo';
                    }
                    const rid = String(r.requestId || r.id || '');
                    const shortId = rid ? `#AR-${rid.slice(-6).toUpperCase()}` : '';
                    return `
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                      <div class="min-w-0">
                        <p class="text-[12px] font-medium text-text-heading truncate">
                          ${esc(String(r.type || '').toUpperCase())} · ${esc(r.subject || '—')}
                        </p>
                        <p class="text-[10px] text-text-subtle mt-0.5 font-mono truncate">
                          ${esc(shortId)}${r.email ? ' · ' + esc(r.email) : ''}
                        </p>
                      </div>
                      <div class="text-right flex-shrink-0">
                        <p class="text-[11px] font-semibold ${cls}">${esc(label)}</p>
                        <p class="text-[9px] text-text-subtle mt-0.5">${esc(r.status || '')}</p>
                      </div>
                    </div>`;
                  }).join('')}
            </div>
          </div>`;
    } catch (e) {
        L.innerHTML = `<p class="text-[11px] text-red-400">Error al cargar ARCO: ${esc(e.message)}</p>`;
    }
}

// ═══════════════════════════════════════════════════════════
// Brechas — Notificación 72h a la Agencia
// ═══════════════════════════════════════════════════════════
async function loadBreaches() {
    const L = document.getElementById('breach-loading');
    const B = document.getElementById('breach-body');
    try {
        const d = await dashFetch('/api/dashboard/breach-timers');
        L.classList.add('hidden');
        B.classList.remove('hidden');
        B.innerHTML = `
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            ${kpiMini('Total', d.total ?? 0, 'Reportadas', '#818cf8')}
            ${kpiMini('Abiertas', d.open ?? 0, 'Sin resolver', (d.open ?? 0) > 0 ? '#f87171' : '#34d399')}
            ${kpiMini('Notificadas', d.notified ?? 0, 'A la Agencia', '#34d399')}
            ${kpiMini('Vencidas 72h', d.overdue72 ?? 0, 'SLA notificación', (d.overdue72 ?? 0) > 0 ? '#f87171' : '#34d399')}
          </div>
          <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
            <div class="px-5 py-3 border-b border-white/[0.04]">
              <p class="text-[11px] font-semibold text-text-heading">Brechas — Notificación a la Agencia (72 h)</p>
            </div>
            <div class="divide-y divide-white/[0.03]">
              ${(d.items || []).length === 0
                ? '<p class="px-5 py-6 text-center text-[11px] text-text-subtle">Sin brechas registradas.</p>'
                : d.items.map(b => `
                <div class="px-5 py-3 flex items-center justify-between gap-4">
                  <div class="min-w-0">
                    <p class="text-[12px] font-medium text-text-heading truncate">${esc(b.title)}</p>
                    <p class="text-[10px] text-text-subtle mt-0.5">
                      ${esc(b.severity)} · ${b.affectedRecords} registros afectados
                    </p>
                  </div>
                  <div class="text-right flex-shrink-0">
                    <p class="text-[11px] font-semibold ${b.overdue72 ? 'text-red-400' : (b.within72 ? 'text-yellow-400' : 'text-emerald-400')}">
                      ${b.notifiedAgencyAt ? 'Notificada' : (b.hoursSince !== null ? b.hoursSince + ' h' : '—')}
                    </p>
                    <p class="text-[9px] text-text-subtle mt-0.5">${esc(b.status)}</p>
                  </div>
                </div>`).join('')}
            </div>
          </div>`;
    } catch (e) {
        L.innerHTML = `<p class="text-[11px] text-red-400">Error al cargar brechas: ${esc(e.message)}</p>`;
    }
}

// ═══════════════════════════════════════════════════════════
// Archivos & PII — Schema real: analysisResult.patterns
// ═══════════════════════════════════════════════════════════
async function loadFiles() {
    const L = document.getElementById('files-loading');
    const B = document.getElementById('files-body');
    try {
        const d = await dashFetch('/api/dashboard/files-summary');
        L.classList.add('hidden');
        B.classList.remove('hidden');

        const bytes = Number(d.totalBytes || 0);
        const volLabel = bytes >= 1073741824 ? (bytes/1073741824).toFixed(2) + ' GB'
                       : bytes >= 1048576    ? (bytes/1048576).toFixed(1) + ' MB'
                       : bytes >= 1024       ? (bytes/1024).toFixed(1) + ' KB'
                       : bytes + ' B';

        const piiList   = Array.isArray(d.piiTypes)   ? d.piiTypes   : [];
        const extList   = Array.isArray(d.byExt)      ? d.byExt      : [];
        const agentList = Array.isArray(d.byAgent)    ? d.byAgent    : [];
        const recent    = Array.isArray(d.recent)     ? d.recent     : [];

        const maxPii   = Math.max(1, ...piiList.map(t => Number(t.count) || 0));
        const maxExt   = Math.max(1, ...extList.map(t => Number(t.count) || 0));
        const maxAgent = Math.max(1, ...agentList.map(t => Number(t.count) || 0));

        B.innerHTML = `
          <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            ${kpiMini('Archivos', d.total ?? 0, `${d.fromAgents ?? 0} de agentes · ${d.fromUsers ?? 0} manuales`, '#818cf8')}
            ${kpiMini('Con PII', d.withPii ?? 0, 'Datos sensibles', (d.withPii ?? 0) > 0 ? '#f87171' : '#34d399')}
            ${kpiMini('Volumen', volLabel, 'Total escaneado', '#22d3ee')}
            ${kpiMini('Tipos PII', piiList.length, 'Categorías detectadas', '#fbbf24')}
            ${kpiMini('Extensiones', extList.length, 'Formatos distintos', '#a78bfa')}
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
              <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Tipos de PII detectados</p>
              ${piiList.length === 0
                ? '<p class="text-[11px] text-text-subtle text-center py-4">Sin datos sensibles detectados.</p>'
                : piiList.map(t => `
                <div class="mb-2">
                  <div class="flex justify-between text-[10px] text-text-muted mb-1">
                    <span class="capitalize">${esc(t.type)}</span><span>${t.count}</span>
                  </div>
                  <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                    <div class="h-full rounded-full bg-[#f87171]" style="width:${Math.min(100,(t.count/maxPii)*100)}%"></div>
                  </div>
                </div>`).join('')}
            </div>

            <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
              <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Por extensión</p>
              ${extList.length === 0
                ? '<p class="text-[11px] text-text-subtle text-center py-4">Sin archivos.</p>'
                : extList.slice(0, 8).map(t => `
                <div class="mb-2">
                  <div class="flex justify-between text-[10px] text-text-muted mb-1">
                    <span class="uppercase font-mono">${esc(t.ext)}</span><span>${t.count}</span>
                  </div>
                  <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                    <div class="h-full rounded-full bg-[#22d3ee]" style="width:${Math.min(100,(t.count/maxExt)*100)}%"></div>
                  </div>
                </div>`).join('')}
            </div>

            <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] p-5">
              <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-3">Top agentes / orígenes</p>
              ${agentList.length === 0
                ? '<p class="text-[11px] text-text-subtle text-center py-4">Sin agentes reportando.</p>'
                : agentList.slice(0, 8).map(a => `
                <div class="mb-2">
                  <div class="flex justify-between text-[10px] text-text-muted mb-1">
                    <span class="truncate">${esc(a.agent)}</span><span>${a.count}</span>
                  </div>
                  <div class="h-1.5 rounded-full bg-white/[0.04] overflow-hidden">
                    <div class="h-full rounded-full bg-[#818cf8]" style="width:${Math.min(100,(a.count/maxAgent)*100)}%"></div>
                  </div>
                </div>`).join('')}
            </div>
          </div>

          <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
            <div class="px-5 py-3 border-b border-white/[0.04] flex items-center justify-between">
              <p class="text-[11px] font-semibold text-text-heading">Últimos archivos con datos sensibles</p>
              <span class="text-[10px] text-text-subtle">${recent.length} recientes</span>
            </div>
            <div class="divide-y divide-white/[0.03]">
              ${recent.length === 0
                ? '<p class="px-5 py-6 text-center text-[11px] text-text-subtle">Sin archivos con PII detectada.</p>'
                : recent.map(f => {
                    const isAgent = f.sourceType === 'agent';
                    const sourceBadge = isAgent
                      ? `<span class="text-[9px] px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20">${esc(f.hostname || 'agente')}</span>`
                      : `<span class="text-[9px] px-1.5 py-0.5 rounded bg-violet-500/10 text-violet-400 border border-violet-500/20">subida manual</span>`;
                    const types = Array.isArray(f.piiTypes) ? f.piiTypes : [];
                    return `
                    <div class="px-5 py-3 flex items-start justify-between gap-4">
                      <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                          <p class="text-[12px] font-medium text-text-heading truncate">${esc(f.name)}</p>
                          ${sourceBadge}
                          ${f.rows ? `<span class="text-[9px] text-text-subtle">${f.rows} filas</span>` : ''}
                          ${f.osUser ? `<span class="text-[9px] text-text-subtle">· OS: ${esc(f.osUser)}</span>` : ''}
                        </div>
                        ${f.path ? `<p class="text-[10px] text-text-subtle mt-0.5 truncate font-mono">${esc(f.path)}</p>` : ''}
                        <div class="flex flex-wrap gap-1 mt-1.5">
                          ${types.slice(0, 5).map(t => `<span class="text-[9px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 capitalize">${esc(t)}</span>`).join('')}
                          ${types.length > 5 ? `<span class="text-[9px] px-1.5 py-0.5 rounded bg-white/[0.04] text-text-subtle">+${types.length - 5}</span>` : ''}
                        </div>
                      </div>
                      <div class="text-right flex-shrink-0">
                        <p class="text-[10px] text-text-subtle tabular-nums">${esc((f.createdAt || '').slice(0, 16).replace('T', ' '))}</p>
                        <p class="text-[10px] text-text-subtle mt-0.5">${(f.size/1024).toFixed(1)} KB</p>
                      </div>
                    </div>`;
                  }).join('')}
            </div>
          </div>`;
    } catch (e) {
        L.innerHTML = `<p class="text-[11px] text-red-400">Error al cargar archivos: ${esc(e.message)}</p>`;
    }
}

// ═══════════════════════════════════════════════════════════
// Auditoría — filtrada por empresa (backend)
// ═══════════════════════════════════════════════════════════
async function loadAudit() {
    const L = document.getElementById('audit-loading');
    const B = document.getElementById('audit-body');
    try {
        const d = await dashFetch('/api/dashboard/recent-activity');
        L.classList.add('hidden');
        B.classList.remove('hidden');

        const items = Array.isArray(d.items) ? d.items : [];

        B.innerHTML = `
          <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden">
            <div class="px-5 py-3 border-b border-white/[0.04] flex items-center justify-between">
              <p class="text-[11px] font-semibold text-text-heading">Actividad reciente</p>
              <span class="text-[10px] text-text-subtle">${items.length} eventos</span>
            </div>
            ${items.length === 0
              ? `<div class="px-5 py-8 text-center">
                   <p class="text-[11px] text-text-subtle">Sin eventos de auditoría para esta empresa.</p>
                   <p class="text-[10px] text-text-subtle/70 mt-1">Verifica que exista una colección <code class="font-mono bg-black/30 px-1 py-0.5 rounded">activity_logs</code> o <code class="font-mono bg-black/30 px-1 py-0.5 rounded">audit_logs</code> con registros de tu empresa.</p>
                 </div>`
              : `<div class="divide-y divide-white/[0.03] max-h-[600px] overflow-y-auto scrollbar-custom">
                   ${items.map(i => {
                     const sev = String(i.severity || 'info').toLowerCase();
                     const dotCls = sev === 'critical' ? 'bg-red-400'
                                  : (sev === 'warning' || sev === 'warn') ? 'bg-yellow-400'
                                  : 'bg-emerald-400';
                     const dateStr = (i.createdAt || '').slice(0, 16).replace('T', ' ');
                     return `
                     <div class="px-5 py-3 flex items-center gap-3">
                       <span class="w-2 h-2 rounded-full flex-shrink-0 ${dotCls}"></span>
                       <div class="flex-1 min-w-0">
                         <p class="text-[12px] font-medium text-text-heading truncate">${esc(i.action || '')}</p>
                         <p class="text-[10px] text-text-subtle mt-0.5 truncate">
                           ${esc(i.user || '—')}${i.target ? ' · ' + esc(i.target) : ''}
                         </p>
                       </div>
                       <span class="text-[10px] text-text-subtle flex-shrink-0 tabular-nums">${esc(dateStr)}</span>
                     </div>`;
                   }).join('')}
                 </div>`}
          </div>`;
    } catch (e) {
        L.innerHTML = `<p class="text-[11px] text-red-400">Error al cargar auditoría: ${esc(e.message)}</p>`;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>