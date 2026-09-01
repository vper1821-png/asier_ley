<?php
$pageTitle = 'Host Monitor';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
$hostsRes = api_post_form('/api/host-monitor', ['token' => $token]);
$hosts = is_array($hostsRes) && empty($hostsRes['error']) ? $hostsRes : [];

function host_disk_pct($h) {
    if (!empty($h['diskTotal']) && $h['diskTotal'] > 0) {
        $free = $h['diskFree'] ?? ($h['diskTotal'] - ($h['diskUsed'] ?? 0));
        return max(0, min(100, round((($h['diskTotal'] - $free) / $h['diskTotal']) * 100, 1)));
    }
    $d = (float)($h['disk'] ?? 0);
    return $d > 100 ? 100 : max(0, min(100, round($d, 1)));
}

function fmt_bytes2($b) {
    if (!$b) return '0 B';
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < count($u)-1) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $u[$i];
}

$sevMap = [
    'critical' => ['label' => 'Crítico', 'color' => 'text-red-400', 'bg' => 'bg-red-500/15 border-red-500/30', 'dot' => 'bg-red-500'],
    'high'     => ['label' => 'Alto', 'color' => 'text-orange-400', 'bg' => 'bg-orange-500/15 border-orange-500/30', 'dot' => 'bg-orange-500'],
    'medium'   => ['label' => 'Medio', 'color' => 'text-yellow-400', 'bg' => 'bg-yellow-500/15 border-yellow-500/30', 'dot' => 'bg-yellow-500'],
    'low'      => ['label' => 'Bajo', 'color' => 'text-green-400', 'bg' => 'bg-green-500/15 border-green-500/30', 'dot' => 'bg-green-500'],
    'info'     => ['label' => 'Info', 'color' => 'text-blue-400', 'bg' => 'bg-blue-500/15 border-blue-500/30', 'dot' => 'bg-blue-500'],
];
$eventsRes = api_post_form('/api/host-monitor/events', ['token' => $token]);
$events = is_array($eventsRes) && empty($eventsRes['error']) ? $eventsRes : [];
$eventsJson = json_encode(array_slice($events, 0, 50), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$totalHosts = count($hosts);
$online = count(array_filter($hosts, fn($h) => ($h['status'] ?? '') === 'online'));
$offline = $totalHosts - $online;
$criticalEvts = count(array_filter($events, fn($e) => ($e['severity'] ?? '') === 'critical' || ($e['severity'] ?? '') === 'high'));
$avgCpu = $totalHosts > 0 ? round(array_sum(array_map(fn($h) => (float)($h['cpu'] ?? 0), $hosts)) / $totalHosts, 1) : 0;
$avgRam = $totalHosts > 0 ? round(array_sum(array_map(fn($h) => (float)($h['ram'] ?? 0), $hosts)) / $totalHosts, 1) : 0;
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'host-monitor'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        
        <!-- Top Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-600/30 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight">Host Monitor</h1>
                    <p class="text-[11px] text-text-muted">Monitoreo en tiempo real de endpoints y servidores</p>
                </div>
            </div>
            <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Refrescar
            </button>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- KPI Row -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white"><?= $totalHosts ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Total Hosts</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-emerald-400"><?= $online ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Online</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-red-400"><?= $offline ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Offline</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-amber-400"><?= $criticalEvts ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Alertas</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-indigo-400"><?= $avgCpu ?>%</p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">CPU Prom.</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($hosts)): ?>
                <!-- Empty State -->
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-16 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg mb-2">No hay hosts monitorizados</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">Despliega agentes en tus servidores para ver datos de monitorización en tiempo real.</p>
                </div>

                <?php else: ?>
                <!-- Hosts Grid -->
                <div>
                    <h2 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        Hosts (<?= $totalHosts ?>)
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        <?php foreach ($hosts as $host):
                            $dk = host_disk_pct($host);
                            $isOnline = ($host['status'] ?? '') === 'online';
                            $isLocked = !empty($host['lockdown']['enabled']);
                            $cpu = (float)($host['cpu'] ?? 0);
                            $ram = (float)($host['ram'] ?? 0);
                        ?>
                        <div class="group rounded-xl border border-border-theme/70 bg-bg-panel/60 hover:bg-bg-elevated hover:border-surface-600 overflow-hidden transition-all duration-200">
                            <!-- Host Header -->
                            <div class="p-4 pb-3">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-9 h-9 rounded-lg flex items-center justify-center border <?= $isOnline ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-red-500/10 border-red-500/20' ?>">
                                            <svg class="w-4.5 h-4.5 <?= $isOnline ? 'text-emerald-400' : 'text-red-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        </div>
                                        <div>
                                            <h3 class="text-[13px] font-semibold text-white leading-tight"><?= h($host['hostname'] ?? $host['agentId'] ?? 'N/A') ?></h3>
                                            <p class="text-[10px] text-text-subtle mt-0.5"><?= h($host['ip'] ?? '') ?> · <?= h($host['platform'] ?? '') ?></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <?php if ($isLocked): ?>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/30 font-medium">BLOQUEADO</span>
                                        <?php endif; ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full <?= $isOnline ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $isOnline ? 'bg-emerald-400 animate-pulse' : 'bg-red-400' ?>"></span>
                                            <?= $isOnline ? 'Online' : 'Offline' ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Metrics -->
                                <div class="space-y-2.5">
                                    <!-- CPU -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-[10px] text-text-subtle font-medium uppercase tracking-wider">CPU</span>
                                            <span class="text-[11px] font-mono <?= $cpu > 80 ? 'text-red-400' : ($cpu > 50 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= h($cpu) ?>%</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-500" style="width:<?= min(100, $cpu) ?>%;background:<?= $cpu > 80 ? '#ef4444' : ($cpu > 50 ? '#f59e0b' : '#10b981') ?>"></div>
                                        </div>
                                    </div>
                                    <!-- RAM -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-[10px] text-text-subtle font-medium uppercase tracking-wider">RAM</span>
                                            <span class="text-[11px] font-mono <?= $ram > 80 ? 'text-red-400' : ($ram > 50 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= h($ram) ?>%</span>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-500" style="width:<?= min(100, $ram) ?>%;background:<?= $ram > 80 ? '#ef4444' : ($ram > 50 ? '#f59e0b' : '#10b981') ?>"></div>
                                        </div>
                                    </div>
                                    <!-- Disk -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-[10px] text-text-subtle font-medium uppercase tracking-wider">Disco</span>
                                            <div class="flex items-center gap-2">
                                                <?php if (!empty($host['diskTotal'])): ?>
                                                <span class="text-[10px] text-text-subtle"><?= fmt_bytes2($host['diskUsed'] ?? 0) ?> / <?= fmt_bytes2($host['diskTotal']) ?></span>
                                                <?php endif; ?>
                                                <span class="text-[11px] font-mono <?= $dk > 80 ? 'text-red-400' : ($dk > 50 ? 'text-amber-400' : 'text-emerald-400') ?>"><?= h($dk) ?>%</span>
                                            </div>
                                        </div>
                                        <div class="w-full h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-500" style="width:<?= min(100, $dk) ?>%;background:<?= $dk > 80 ? '#ef4444' : ($dk > 50 ? '#f59e0b' : '#f59e0b') ?>"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Host Footer -->
                            <div class="px-4 py-2.5 border-t border-white/[0.04] bg-white/[0.01] flex items-center justify-between">
                                <span class="text-[10px] text-text-subtle font-mono">v<?= h($host['version'] ?? '?') ?></span>
                                <div class="flex items-center gap-2">

                                    <span class="text-[10px] text-text-subtle">User: <?= h($host['user'] ?? '?') ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php endif; ?>

                <!-- Events Section -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden">
                    <div class="px-5 py-4 border-b border-border-theme flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            Eventos Recientes
                            <span class="text-[10px] text-text-subtle font-normal">(<?= count($events) ?>)</span>
                        </h2>
                    </div>
                    <?php if (empty($events)): ?>
                    <div class="p-10 text-center">
                        <p class="text-text-muted text-[12px]">No hay eventos recientes del host.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-border-theme">
                        <?php foreach (array_slice($events, 0, 20) as $i => $ev):
                            $sev = $sevMap[$ev['severity'] ?? ''] ?? $sevMap['info'];
                            $evType = $ev['eventType'] ?? $ev['type'] ?? 'unknown';
                            $evTypeLabel = str_replace('_', ' ', $evType);
                            $ts = $ev['timestamp'] ?? '';
                        ?>
                        <div class="px-5 py-3.5 flex items-start gap-3 hover:bg-white/[0.01] transition-colors">
                            <div class="flex-shrink-0 pt-0.5">
                                <span class="w-2 h-2 rounded-full <?= $sev['dot'] ?>"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-0.5">
                                    <p class="text-[12px] font-medium text-white truncate"><?= h($ev['title'] ?? 'Evento') ?></p>
                                    <span class="text-[9px] px-1.5 py-0.5 rounded-full border <?= $sev['bg'] ?> <?= $sev['color'] ?> font-medium flex-shrink-0">
                                        <?= h($sev['label']) ?>
                                    </span>
                                </div>
                                <p class="text-[10px] text-text-subtle">
                                    <span class="uppercase tracking-wider"><?= h($evTypeLabel) ?></span>
                                    · <?= h(substr($ts, 0, 19)) ?>
                                    <?php if (!empty($ev['agentId'])): ?>
                                    · <span class="text-blue-400/70"><?= h(substr($ev['agentId'], -8)) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <button onclick="openEventModal(<?= $i ?>)" title="Ver detalle"
                                class="p-1.5 rounded-lg text-[11px] text-text-muted hover:text-indigo-400 hover:bg-bg-elevated transition-all flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Modal detalle evento -->
<div id="event-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-50 p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-xl max-h-[90vh] overflow-y-auto scrollbar-custom shadow-2xl">
        <div id="event-modal-body"></div>
    </div>
</div>

<script>
const EVENTS = <?= $eventsJson ?>;

function closeEventModal() {
    const el = document.getElementById('event-modal');
    el.classList.add('hidden'); el.classList.remove('flex');
}

function openEventModal(idx) {
    const ev = EVENTS[idx];
    if (!ev) return;
    const sev = ev.severity || 'info';
    const sevCfg = {
        critical: ['Crítico', '#f87171'],
        high: ['Alto', '#fb923c'],
        medium: ['Medio', '#facc15'],
        low: ['Bajo', '#34d399'],
        info: ['Info', '#60a5fa']
    };
    const sc = sevCfg[sev] || sevCfg.info;
    const details = [
        ['Título', ev.title || 'Evento'],
        ['Gravedad', sc[0]],
        ['Tipo de evento', (ev.eventType || ev.type || 'unknown').replace(/_/g, ' ')],
        ['Fecha', ev.timestamp || ''],
        ['Fuente', ev.source || 'agent'],
        ['Agente', ev.agentId || ''],
        ['ID', ev._id || ''],
    ];
    let rows = details.map(d => row(d[0], d[1])).join('');
    document.getElementById('event-modal-body').innerHTML = `
    <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
        <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full" style="background:${sc[1]}"></span>
            <h3 class="text-[14px] font-semibold text-white">Detalle del evento</h3>
        </div>
        <button onclick="closeEventModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">${rows}</div>
        ${ev.detail ? `
        <div class="mt-4">
            <p class="text-[10px] font-medium text-text-subtle uppercase tracking-widest mb-1.5">Descripción</p>
            <p class="text-[12px] text-text-body leading-relaxed rounded-lg border border-border-theme bg-bg-elevated/40 p-3 whitespace-pre-wrap">${ev.detail}</p>
        </div>` : ''}
    </div>`;
    const el = document.getElementById('event-modal');
    el.classList.remove('hidden'); el.classList.add('flex');
}

function row(k, v) {
    return `<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-3 py-2">
        <p class="text-[9px] font-medium text-text-subtle uppercase tracking-widest mb-1">${k}</p>
        <p class="text-[11px] text-text-body break-words">${v || 'N/A'}</p>
    </div>`;
}

document.addEventListener('click', function (e) {
    if (e.target.id === 'event-modal') closeEventModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
