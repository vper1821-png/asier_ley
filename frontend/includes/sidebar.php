<?php
require_once __DIR__ . '/../config.php';
require_login();

$currentPage = $currentPage ?? 'dashboard';

$navItems = [
    ['id' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
    ['id' => 'agents', 'label' => 'Agentes', 'icon' => 'agents'],

    ['id' => 'alerts', 'label' => 'Alertas', 'icon' => 'alerts'],
    ['id' => 'reports', 'label' => 'Reportes', 'icon' => 'reports'],
    ['id' => 'databases', 'label' => 'Bases de Datos', 'icon' => 'databases'],
    ['id' => 'db-logs', 'label' => 'Logs DB', 'icon' => 'dbLogs'],
    ['id' => 'compliance', 'label' => 'Compliance', 'icon' => 'compliance'],
    ['id' => 'hardening', 'label' => 'Hardening', 'icon' => 'hardening'],
    ['id' => 'tickets', 'label' => 'Tickets', 'icon' => 'tickets'],
    ['id' => 'arco', 'label' => 'ARCO', 'icon' => 'arco'],
    ['id' => 'settings', 'label' => 'Ajustes', 'icon' => 'settings'],
];

function navIcon($name) {
    $icons = [
        'dashboard' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
        'agents' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
        'hostMonitor' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
        'alerts' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
        'reports' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
        'databases' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>',
        'compliance' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
        'hardening' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
        'dbLogs' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>',
        'tickets' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>',
        'arco' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
        'settings' => '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$collapsed = isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === '1';
$mobileOpen = $mobileSidebar ?? false;

$isAdminUser = !empty($_SESSION['user']['isAdmin']) || in_array($_SESSION['user']['role'] ?? '', ['admin', 'superadmin']);

$token = $_SESSION['token'] ?? '';

// Compliance score (green ring) - from detailed checklist
$complianceScore = 0;

// Agent & Database score (yellow ring)
$statsRes = api_post_form('/api/dashboard/stats', ['token' => $token]);
$s = is_array($statsRes) && isset($statsRes['stats']) ? $statsRes['stats'] : [];
$totalAgents = (int)($s['totalAgents'] ?? 0);
$onlineAgents = (int)($s['onlineAgents'] ?? 0);
$totalDatabases = (int)($s['totalDatabases'] ?? 0);
$compliantDBs = (int)($s['compliantDBs'] ?? 0);
$agentScore = $totalAgents > 0 ? round($onlineAgents / $totalAgents * 100) : 0;
$dbScore = $totalDatabases > 0 ? round($compliantDBs / $totalDatabases * 100) : 0;
$agentDBScore = (int)round(($agentScore + $dbScore) / 2);

// Hardening score (red ring)
$cfgRes = api_get('/api/invisia/compliance/config', ['token' => $token]);
$config = is_array($cfgRes) ? $cfgRes : [];
$measureOverrides = [];
if (!empty($config['measureOverrides'])) {
    $measureOverrides = is_string($config['measureOverrides']) ? json_decode($config['measureOverrides'], true) : $config['measureOverrides'];
    if (!is_array($measureOverrides)) $measureOverrides = [];
}
$hardeningIds = ['encryption','access_control','backup','logging','patching','ids_ips','dlp','waf','pseudonymization','incident_response'];
$hardeningDone = 0;
$hardeningTotal = count($hardeningIds);
foreach ($measureOverrides as $o) {
    if (!empty($o['completed']) && in_array($o['measureId'] ?? '', $hardeningIds, true)) {
        $hardeningDone++;
    }
}
$hardeningScore = $hardeningTotal ? (int)round($hardeningDone / $hardeningTotal * 100) : 0;

// Obtener checklist detallado de compliance
$checklistRes = api_post_form('/api/invisia/checklist', ['token' => $token]);
$checklistItems = is_array($checklistRes) && isset($checklistRes['checklist']) ? $checklistRes['checklist'] : [];
$checklistDone = count(array_filter($checklistItems, fn($t) => $t['done'] ?? false));
$checklistTotal = count($checklistItems);
$complianceScore = $checklistTotal ? (int)round($checklistDone / $checklistTotal * 100) : 0;

// Global score (average of the three)
$globalScore = (int)round(($agentDBScore + $complianceScore + $hardeningScore) / 3);

$scoreColor = function ($score) {
    return $score >= 70 ? 'text-emerald-400' : ($score >= 40 ? 'text-yellow-400' : 'text-red-400');
};
$circ = 2 * M_PI * 18;

function renderScoreRing($score, $colorClass, $sizeClass = 'w-10 h-10') {
    global $circ;
    $offset = $circ * (1 - $score / 100);
    echo '<div class="relative ' . $sizeClass . ' ' . $colorClass . ' flex-shrink-0" title="' . $score . '%">';
    echo '<svg class="w-full h-full -rotate-90" viewBox="0 0 48 48">';
    echo '<circle cx="24" cy="24" r="18" fill="none" stroke="currentColor" stroke-width="5" class="text-text-subtle opacity-20"/>';
    echo '<circle cx="24" cy="24" r="18" fill="none" stroke="currentColor" stroke-width="5" stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $offset . '" stroke-linecap="round"/>';
    echo '</svg>';
    echo '<span class="absolute inset-0 flex items-center justify-center text-[9px] font-bold text-text-heading">' . $score . '%</span>';
    echo '</div>';
}
?>
<aside class="flex flex-col bg-bg-base border-r border-border-theme transition-all duration-300 flex-shrink-0 <?= $collapsed ? 'w-16' : 'w-56' ?> hidden md:flex">
    <!-- Logo -->
    <div class="flex items-center <?= $collapsed ? 'justify-center px-0 py-3' : 'px-3 py-3 space-x-2' ?> border-b border-border-theme tour-sidebar-logo">
        <div class="w-7 h-7 rounded flex items-center justify-center overflow-hidden flex-shrink-0 bg-bg-panel">
            <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
        </div>
        <?php if (!$collapsed): ?>
        <div class="flex-1 min-w-0">
            <p class="text-[12px] text-white truncate font-medium">SecureLab</p>
            <p class="text-[9px] text-text-subtle truncate leading-tight mt-px">Cumplimiento ley 21.719</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pending compliance ring -->
    <?php if (!$collapsed): ?>
    <div class="px-4 py-3 border-b border-border-theme tour-pending-ring relative group">
        <p class="text-[10px] font-medium text-text-subtle uppercase tracking-wider mb-2">Tareas pendientes</p>
        <div class="flex items-center justify-between">
            <div class="flex flex-col items-center gap-1" title="Agente &amp; Base de datos">
                <?php renderScoreRing($agentDBScore, 'text-yellow-400', 'w-10 h-10'); ?>
                <span class="text-[9px] text-text-subtle">Agente &amp; DB</span>
            </div>
            <div class="flex flex-col items-center gap-1" title="Compliance">
                <?php renderScoreRing($complianceScore, 'text-emerald-400', 'w-10 h-10'); ?>
                <span class="text-[9px] text-text-subtle">Compliance</span>
            </div>
            <div class="flex flex-col items-center gap-1" title="Hardening">
                <?php renderScoreRing($hardeningScore, 'text-red-400', 'w-10 h-10'); ?>
                <span class="text-[9px] text-text-subtle">Hardening</span>
            </div>
        </div>

        <!-- Popup flotante con lista de tareas -->
        <div class="absolute left-full top-0 ml-2 w-72 bg-bg-panel border border-border-theme rounded-xl shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 p-4">
            <p class="text-[11px] font-semibold text-text-heading mb-3">Checklist de Cumplimiento</p>
            <div class="space-y-2">
                <?php foreach ($checklistItems as $task): ?>
                <div class="flex items-center justify-between gap-2 p-2 rounded-lg <?= $task['done'] ? 'bg-emerald-500/5' : 'bg-bg-surface/30' ?>">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-1.5 h-1.5 rounded-full <?= $task['done'] ? 'bg-emerald-400' : 'bg-yellow-400' ?> flex-shrink-0"></span>
                        <?php if (!$task['done']): ?>
                        <a href="<?= h($task['link']) ?>" class="text-[10px] text-text-body truncate hover:text-primary-300 transition-colors">
                            <?= h($task['label']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-[10px] text-text-body truncate"><?= h($task['label']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($task['done']): ?>
                    <span class="text-[9px] text-emerald-400/60 flex-shrink-0">✓</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto py-2 scrollbar-custom tour-nav-items">
        <div class="<?= $collapsed ? 'px-1' : 'px-3' ?>">
            <?php if (!$collapsed): ?>
            <p class="text-[10px] font-medium text-text-subtle uppercase tracking-wider mb-1.5 px-2">General</p>
            <?php endif; ?>
            <?php foreach ($navItems as $item): ?>
            <?php
            $hasPage = file_exists(__DIR__ . '/../pages/' . $item['id'] . '.php');
            $href = ($item['id'] === 'dashboard' || $hasPage) ? '/' . $item['id'] : '/dashboard?tab=' . $item['id'];
            ?>
            <a href="<?= h($href) ?>"
               class="flex items-center <?= $collapsed ? 'justify-center px-0 py-2.5' : 'px-2 py-2 space-x-2.5' ?> rounded-lg mb-0.5 transition-colors <?= $currentPage === $item['id'] ? 'bg-primary-500/10 text-primary-400 border border-primary-500/20' : 'text-text-muted hover:text-text-heading hover:bg-bg-elevated' ?>"
               title="<?= h($item['label']) ?>">
                <?= navIcon($item['icon']) ?>
                <?php if (!$collapsed): ?>
                <span class="text-[12px] font-medium truncate"><?= h($item['label']) ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </nav>

    <!-- Bottom actions -->
    <div class="px-3 py-3 border-t border-border-theme space-y-1.5">
        <button onclick="startTour && startTour()"
            class="w-full flex items-center justify-center <?= $collapsed ? '' : 'gap-2' ?> px-2.5 py-2 rounded-lg text-[11px] font-semibold bg-gradient-to-r from-primary-600/20 to-indigo-600/20 text-accent hover:from-primary-600/30 hover:to-indigo-600/30 hover:text-primary-300 transition-all duration-200 tour-start-btn">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <?php if (!$collapsed): ?><span>Tour guiado</span><?php endif; ?>
        </button>

        <button onclick="toggleThemePopup && toggleThemePopup()"
            class="w-full flex items-center <?= $collapsed ? 'justify-center' : 'justify-between' ?> px-2.5 py-2 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme text-text-muted cursor-pointer hover:bg-bg-elevated/80 hover:border-surface-600 hover:text-text-body transition-all duration-200 tour-theme-btn">
            <div class="flex items-center gap-2">
                <div id="theme-dot" class="w-3 h-3 rounded-full flex-shrink-0 bg-primary-500" style="box-shadow: 0 0 6px rgba(59,130,246,0.4)"></div>
                <?php if (!$collapsed): ?><span class="truncate font-medium" id="theme-label">Invisia Dark</span><?php endif; ?>
            </div>
            <?php if (!$collapsed): ?>
            <svg class="w-3 h-3 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            <?php endif; ?>
        </button>

        <?php if ($isAdminUser): ?>
        <a href="/admin"
            class="w-full flex items-center justify-center gap-1.5 px-2.5 py-2 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:bg-bg-elevated/80 hover:border-surface-600 hover:text-gray-200 transition-all duration-200">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <?php if (!$collapsed): ?><span>Panel Admin</span><?php endif; ?>
        </a>
        <?php endif; ?>

        <button onclick="toggleSidebarCollapsed()"
            class="hidden md:flex w-full items-center justify-center px-2.5 py-2 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme text-text-muted hover:bg-bg-elevated/80 hover:border-surface-600 hover:text-text-body transition-all duration-200">
            <svg class="w-3.5 h-3.5 transition-transform <?= $collapsed ? 'rotate-180' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
        </button>

        <div class="tour-notifications relative">
            <button onclick="toggleNotifications()"
                class="w-full flex items-center justify-center gap-1.5 px-2.5 py-2 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:bg-bg-elevated/80 hover:border-surface-600 hover:text-gray-200 transition-all duration-200">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <?php if (!$collapsed): ?><span>Notificaciones</span><?php endif; ?>
                <span id="notif-badge" class="hidden ml-1 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[9px] font-bold"></span>
            </button>
            <div id="notif-panel" class="hidden absolute bottom-full left-0 mb-2 w-72 max-h-80 overflow-y-auto rounded-xl border border-border-theme bg-bg-panel shadow-xl z-50 scrollbar-custom">
                <div class="px-3 py-2 border-b border-border-theme flex items-center justify-between">
                    <p class="text-[11px] font-semibold text-text-heading">Notificaciones</p>
                    <button onclick="markAllNotificationsRead()" class="text-[10px] text-primary-400 hover:text-primary-300">Marcar leídas</button>
                </div>
                <div id="notif-list" class="divide-y divide-border-theme">
                    <p class="px-3 py-4 text-[11px] text-text-subtle text-center">Sin notificaciones</p>
                </div>
            </div>
        </div>

        <a href="/logout"
            class="w-full flex items-center justify-center gap-1.5 px-2.5 py-2 rounded-lg text-[11px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 hover:border-red-700/30 transition-all duration-200 tour-logout-btn">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <?php if (!$collapsed): ?><span>Salir</span><?php endif; ?>
        </a>
    </div>

</aside>

<div id="mobile-nav-backdrop" class="mobile-nav-backdrop" onclick="closeMobileNav()"></div>
<aside id="mobile-nav-drawer" class="mobile-nav-drawer" aria-hidden="true">
    <div class="flex items-center gap-3 px-4 py-4 border-b border-border-theme">
        <div class="w-9 h-9 rounded-xl bg-bg-panel border border-border-theme overflow-hidden flex-shrink-0"><img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain"></div>
        <div class="flex-1 min-w-0"><p class="text-sm font-semibold text-text-heading truncate">SecureLab</p><p class="text-[10px] text-text-subtle truncate">Centro de cumplimiento</p></div>
        <button type="button" onclick="closeMobileNav()" class="w-9 h-9 rounded-xl border border-border-theme text-text-muted flex items-center justify-center" aria-label="Cerrar navegación"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="mx-4 mt-4 p-3 rounded-xl border border-border-theme bg-bg-panel/70 flex items-center justify-between">
        <div class="flex flex-col items-center gap-1" title="Agente &amp; Base de datos">
            <?php renderScoreRing($agentDBScore, 'text-yellow-400', 'w-11 h-11'); ?>
            <span class="text-[9px] text-text-subtle">Agente &amp; DB</span>
        </div>
        <div class="flex flex-col items-center gap-1" title="Compliance">
            <?php renderScoreRing($complianceScore, 'text-emerald-400', 'w-11 h-11'); ?>
            <span class="text-[9px] text-text-subtle">Compliance</span>
        </div>
        <div class="flex flex-col items-center gap-1" title="Hardening">
            <?php renderScoreRing($hardeningScore, 'text-red-400', 'w-11 h-11'); ?>
            <span class="text-[9px] text-text-subtle">Hardening</span>
        </div>
    </div>
    <nav class="flex-1 overflow-y-auto p-3 space-y-1">
        <?php foreach ($navItems as $item): $hasPage = file_exists(__DIR__ . '/../pages/' . $item['id'] . '.php'); $href = ($item['id'] === 'dashboard' || $hasPage) ? '/' . $item['id'] : '/dashboard?tab=' . $item['id']; ?>
        <a href="<?= h($href) ?>" class="mobile-nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl border <?= $currentPage === $item['id'] ? 'bg-primary-500/10 text-primary-400 border-primary-500/20' : 'text-text-muted border-transparent hover:bg-bg-elevated hover:text-text-heading' ?>">
            <span class="w-5 flex justify-center"><?= navIcon($item['icon']) ?></span><span class="text-[13px] font-medium"><?= h($item['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-3 border-t border-border-theme safe-bottom grid grid-cols-2 gap-2">
        <button type="button" onclick="toggleThemePopup();closeMobileNav()" class="px-3 py-2.5 rounded-xl border border-border-theme bg-bg-panel text-xs text-text-body">Tema</button>
        <a href="/logout" class="px-3 py-2.5 rounded-xl border border-red-500/20 bg-red-500/10 text-xs text-red-400 text-center">Salir</a>
    </div>
</aside>

<header class="app-mobile-header md:hidden">
    <button type="button" onclick="openMobileNav()" class="w-9 h-9 rounded-xl border border-border-theme bg-bg-panel text-text-body flex items-center justify-center" aria-label="Abrir navegación"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <div class="flex-1 min-w-0"><p class="text-[10px] uppercase tracking-wider text-text-subtle">SecureLab</p><p class="text-sm font-semibold text-text-heading truncate"><?= h($pageTitle ?? 'Panel') ?></p></div>
    <a href="/alerts" class="relative w-9 h-9 rounded-xl border border-border-theme bg-bg-panel text-text-muted flex items-center justify-center" aria-label="Alertas"><?= navIcon('alerts') ?><span id="mobile-notif-badge" class="hidden absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full bg-red-500 text-[8px] text-white items-center justify-center"></span></a>
</header>

<script>
function openMobileNav() {
    document.getElementById('mobile-nav-drawer')?.classList.add('is-open');
    document.getElementById('mobile-nav-backdrop')?.classList.add('is-open');
    document.getElementById('mobile-nav-drawer')?.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function closeMobileNav() {
    document.getElementById('mobile-nav-drawer')?.classList.remove('is-open');
    document.getElementById('mobile-nav-backdrop')?.classList.remove('is-open');
    document.getElementById('mobile-nav-drawer')?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}
function toggleSidebarCollapsed() {
    const cur = document.cookie.includes('sidebar_collapsed=1');
    document.cookie = 'sidebar_collapsed=' + (cur ? '0' : '1') + ';path=/;max-age=31536000';
    location.reload();
}

function toggleNotifications() {
    const panel = document.getElementById('notif-panel');
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden')) loadNotifications();
}

async function loadNotifications() {
    try {
        const res = await fetch('/api-proxy.php?path=/api/notifications/list', { method: 'POST' });
        const data = await res.json();
        const list = document.getElementById('notif-list');
        const items = Array.isArray(data) ? data : (data.notifications || []);
        if (!items.length) {
            list.innerHTML = '<p class="px-3 py-4 text-[11px] text-text-subtle text-center">Sin notificaciones</p>';
            return;
        }
        list.innerHTML = items.slice(0, 20).map(n =>
            '<div class="px-3 py-2.5 ' + (n.read ? 'opacity-60' : '') + '">' +
            '<p class="text-[11px] font-medium text-text-heading">' + escHtml(n.title || n.type || '') + '</p>' +
            '<p class="text-[10px] text-text-muted mt-0.5">' + escHtml(n.message || '') + '</p>' +
            '</div>'
        ).join('');
    } catch (e) { /* noop */ }
}

async function markAllNotificationsRead() {
    try {
        await fetch('/api-proxy.php?path=/api/notifications/read-all', { method: 'POST' });
        loadNotifications();
        updateNotifBadge();
    } catch (e) { /* noop */ }
}

async function updateNotifBadge() {
    try {
        const res = await fetch('/api-proxy.php?path=/api/notifications/unread-count', { method: 'POST' });
        const data = await res.json();
        const count = data.count ?? data.unread ?? 0;
        const badge = document.getElementById('notif-badge');
        const mobileBadge = document.getElementById('mobile-notif-badge');
        if (count > 0) {
            if (badge) { badge.textContent = count; badge.classList.remove('hidden'); }
            if (mobileBadge) { mobileBadge.textContent = count > 99 ? '99+' : count; mobileBadge.classList.remove('hidden'); mobileBadge.classList.add('flex'); }
        } else {
            if (badge) badge.classList.add('hidden');
            if (mobileBadge) { mobileBadge.classList.add('hidden'); mobileBadge.classList.remove('flex'); }
        }
    } catch (e) { /* noop */ }
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

document.addEventListener('click', (e) => {
    const panel = document.getElementById('notif-panel');
    if (panel && !panel.classList.contains('hidden') && !e.target.closest('.tour-notifications')) {
        panel.classList.add('hidden');
    }
});

updateNotifBadge();
</script>
