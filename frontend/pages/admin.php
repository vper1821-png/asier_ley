<?php
require_once __DIR__ . '/../config.php';
require_admin();

$token = $_SESSION['token'] ?? '';
$isSuper = ($_SESSION['user']['role'] ?? '') === 'superadmin' || (!empty($_SESSION['user']['isAdmin']) && ($_SESSION['user']['role'] ?? '') === 'superadmin');
if (!$isSuper && !empty($_SESSION['user']['isAdmin'])) {
    $isSuper = true;
}
$msg = '';
$err = '';
$tab = $_GET['tab'] ?? 'overview';
$expandUid = $_GET['uid'] ?? '';

// ── POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_active'])) {
        $res = api_post_form('/api/admin/update-user', ['token' => $token, 'userId' => $_POST['user_id'], 'isActive' => $_POST['new_state']]);
        $msg = !empty($res['success']) ? 'Estado actualizado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['reset_2fa'])) {
        $res = api_post_form('/api/admin/reset-2fa', ['token' => $token, 'userId' => $_POST['user_id']]);
        $msg = !empty($res['success']) ? '2FA reseteado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['delete_user'])) {
        $res = api_post_form('/api/admin/delete-user-full', ['token' => $token, 'userId' => $_POST['user_id']]);
        $msg = !empty($res['success']) ? 'Usuario eliminado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['create_user'])) {
        $res = api_post_form('/api/admin/create-user', ['token' => $token, 'email' => $_POST['email'] ?? '', 'password' => $_POST['password'] ?? '', 'companyName' => $_POST['company'] ?? '', 'role' => $_POST['role'] ?? 'user']);
        $msg = !empty($res['success']) ? 'Usuario creado.' : ($res['error'] ?? 'Error al crear usuario.');
    } elseif (isset($_POST['ticket_status'])) {
        $res = api_post_form('/api/tickets/status', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'status' => $_POST['new_status']]);
        $msg = !empty($res['success']) ? 'Ticket actualizado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['ticket_respond'])) {
        $res = api_post_form('/api/tickets/respond', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'message' => $_POST['response'] ?? '']);
        $msg = !empty($res['success']) ? 'Respuesta enviada.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['toggle_maintenance'])) {
        $res = api_post_form('/api/admin/maintenance/toggle', ['token' => $token, 'enabled' => $_POST['enabled'] ?? '', 'message' => $_POST['maintenance_message'] ?? '']);
        $msg = empty($res['error']) ? 'Mantenimiento actualizado.' : ($res['error'] ?? 'Error.');
    }
}

// ── Fetch Data ──
$usersRes = api_post_form('/api/admin/users', ['token' => $token]);
$users = is_array($usersRes) && empty($usersRes['error']) ? $usersRes : [];

$ticketsRes = api_post_form('/api/tickets/all', ['token' => $token]);
$allTickets = is_array($ticketsRes) && empty($ticketsRes['error']) ? ($ticketsRes['tickets'] ?? $ticketsRes) : [];
if (!is_array($allTickets)) $allTickets = [];

$alertsRes = api_post_form('/api/admin/alerts', ['token' => $token]);
$adminAlerts = is_array($alertsRes) && empty($alertsRes['error']) ? $alertsRes : [];

$agents = [];
$agentsRes = api_post_form('/api/agents/list', ['token' => $token]);
if (is_array($agentsRes) && empty($agentsRes['error'])) $agents = $agentsRes;

$maintenance = [];
if ($tab === 'settings') {
    $mRes = api_post_form('/api/admin/maintenance/status', ['token' => $token]);
    $maintenance = is_array($mRes) ? $mRes : [];
}

// ── Counts ──
$openTickets = count(array_filter($allTickets, fn($t) => ($t['status'] ?? '') === 'open'));
$suspendedUsers = count(array_filter($users, fn($u) => empty($u['isActive'])));
$onlineAgents = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));
$totalAgents = count($agents);

// ── Companies Grouping ──
$companies = [];
foreach ($users as $u) {
    $uid = $u['_id'] ?? '';
    $companies[$uid] = ['user' => $u, 'agents' => []];
}
foreach ($agents as $a) {
    $uid = $a['userId'] ?? '';
    if (!isset($companies[$uid])) {
        $companies[$uid] = ['user' => ['_id' => $uid, 'email' => $a['companyEmail'] ?? '(eliminado)', 'companyName' => $a['companyName'] ?? '', 'isActive' => ($a['companyActive'] ?? true)], 'agents' => []];
    }
    $companies[$uid]['agents'][] = $a;
}

// ── Tab Titles ──
$tabTitles = [
    'overview' => 'Panel de Control',
    'companies' => 'Empresas & Equipos',
    'users' => 'Gestión de Usuarios',
    'tickets' => 'Tickets de Soporte',
    'alerts' => 'Alertas del Sistema',
    'logs' => 'Logs de Auditoría',
    'settings' => 'Configuración',
];

$pageTitle = 'Panel de Administración';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-56 bg-bg-base border-r border-border-theme flex flex-col flex-shrink-0">
        <div class="px-3 py-3 border-b border-border-theme flex items-center space-x-2">
            <div class="w-7 h-7 rounded bg-bg-panel flex items-center justify-center overflow-hidden">
                <img src="/logo-nuevo.png" alt="Logo" class="w-full h-full object-contain">
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[12px] text-white truncate font-medium">Panel Admin</p>
                <?php if ($isSuper): ?>
                <span class="text-[9px] px-1.5 py-0.5 rounded-full bg-violet-500/20 text-violet-400 border border-violet-500/30">SUPERADMIN</span>
                <?php endif; ?>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto py-2 scrollbar-custom px-2 space-y-0.5">
            <?php
            $sidebarItems = [
                ['id' => 'overview', 'label' => 'Resumen', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
                ['id' => 'companies', 'label' => 'Empresas & Equipos', 'count' => $totalAgents],
                ['id' => 'users', 'label' => 'Usuarios', 'count' => count($users)],
                ['id' => 'tickets', 'label' => 'Tickets', 'count' => $openTickets],
                ['id' => 'alerts', 'label' => 'Alertas', 'count' => count($adminAlerts)],
                ['id' => 'logs', 'label' => 'Logs de Auditoría'],
                ['id' => 'settings', 'label' => 'Configuración'],
            ];
            foreach ($sidebarItems as $item): ?>
            <a href="/admin?tab=<?= $item['id'] ?>" class="flex items-center gap-2 px-2.5 py-2 rounded-lg text-[12px] transition-colors <?= $tab === $item['id'] ? 'bg-primary-500/15 text-primary-400 border border-primary-500/20' : 'text-text-muted hover:bg-bg-panel hover:text-text-heading border border-transparent' ?>">
                <span class="flex-1"><?= h($item['label']) ?></span>
                <?php if (($item['count'] ?? 0) > 0): ?>
                <span class="bg-red-500/20 text-red-400 text-[9px] px-1.5 py-0.5 rounded font-mono"><?= $item['count'] ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="px-2 py-2 border-t border-border-theme">
            <a href="/dashboard" class="flex items-center justify-center gap-2 px-2 py-2 rounded-lg text-[11px] text-text-muted hover:bg-bg-panel hover:text-text-heading transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Volver al Dashboard
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="px-6 py-4 border-b border-border-theme flex-shrink-0 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-text-heading"><?= h($tabTitles[$tab] ?? 'Panel de Control') ?></h2>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-primary-500/10 text-primary-400 border border-primary-500/20"><?= h($_SESSION['user']['role'] ?? 'admin') ?></span>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <?php if ($tab === 'overview'): ?>
            <!-- ═══ RESUMEN ═══ -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ([
                    ['label' => 'Empresas', 'value' => count($companies), 'color' => 'text-white'],
                    ['label' => 'Equipos', 'value' => $onlineAgents . '/' . $totalAgents, 'sub' => 'online/total', 'color' => $onlineAgents ? 'text-emerald-400' : 'text-amber-400'],
                    ['label' => 'Usuarios', 'value' => count($users), 'sub' => $suspendedUsers . ' suspendidos', 'color' => 'text-cyan-400'],
                    ['label' => 'Tickets abiertos', 'value' => $openTickets, 'color' => $openTickets ? 'text-amber-400' : 'text-emerald-400'],
                ] as $s): ?>
                <div class="bg-bg-panel/60 border border-border-theme/25 rounded-lg p-4">
                    <span class="text-[10px] text-text-muted tracking-wide"><?= h($s['label']) ?></span>
                    <p class="text-[24px] font-bold mt-1.5 leading-none <?= $s['color'] ?>"><?= h($s['value']) ?></p>
                    <?php if (!empty($s['sub'])): ?><p class="text-[10px] text-text-subtle mt-1"><?= h($s['sub']) ?></p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-[13px] font-semibold text-white">Últimos usuarios</h3>
                        <a href="/admin?tab=users" class="text-[10px] text-primary-400 hover:text-primary-300">Ver todos →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($users, 0, 5) as $u): ?>
                        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-5 h-5 rounded-full bg-primary-600 flex items-center justify-center text-white text-[8px] font-bold flex-shrink-0"><?= h(strtoupper(substr($u['email'] ?? 'U', 0, 2))) ?></div>
                                <span class="text-[11px] text-text-body truncate"><?= h($u['email'] ?? '') ?></span>
                            </div>
                            <span class="text-[9px] px-1.5 py-0.5 rounded-full <?= !empty($u['isActive']) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' ?>"><?= !empty($u['isActive']) ? 'Activo' : 'Suspendido' ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?><p class="text-[11px] text-text-subtle text-center py-4">Sin usuarios.</p><?php endif; ?>
                    </div>
                </div>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-[13px] font-semibold text-white">Equipos recientes</h3>
                        <a href="/admin?tab=companies" class="text-[10px] text-primary-400 hover:text-primary-300">Ver todos →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($agents, 0, 5) as $a): ?>
                        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-2 h-2 rounded-full flex-shrink-0 <?= ($a['status'] ?? '') === 'online' ? 'bg-emerald-400' : 'bg-red-400' ?>"></div>
                                <span class="text-[11px] text-text-body truncate"><?= h($a['hostname'] ?? $a['agentId'] ?? '') ?></span>
                            </div>
                            <span class="text-[9px] text-text-subtle"><?= h($a['platform'] ?? '') ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($agents)): ?><p class="text-[11px] text-text-subtle text-center py-4">Sin equipos registrados.</p><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php elseif ($tab === 'companies'): ?>
            <!-- ═══ EMPRESAS & EQUIPOS ═══ -->
            <div class="flex items-center gap-3 mb-4">
                <div class="relative flex-1 max-w-xs">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="companySearch" oninput="filterCompanies(this.value)" placeholder="Buscar empresa o equipo..." class="input-premium pl-9 w-full">
                </div>
                <span class="text-[10px] text-text-subtle" id="companyCount"><?= count($companies) ?> empresas · <?= $totalAgents ?> equipos · <?= $onlineAgents ?> online</span>
            </div>

            <?php
            $companyColors = ['from-primary-600/20 to-primary-800/10', 'from-cyan-600/20 to-cyan-800/10', 'from-violet-600/20 to-violet-800/10', 'from-emerald-600/20 to-emerald-800/10', 'from-amber-600/20 to-amber-800/10', 'from-rose-600/20 to-rose-800/10'];
            $ci = 0;
            ?>
            <?php foreach ($companies as $uid => $co):
                $cu = $co['user'];
                $ca = $co['agents'];
                $caOnline = count(array_filter($ca, fn($a) => ($a['status'] ?? '') === 'online'));
                $email = $cu['email'] ?? '';
                $cname = $cu['companyName'] ?? $email;
                $isActive = !empty($cu['isActive']);
                $searchStr = strtolower($cname . ' ' . $email . ' ' . implode(' ', array_map(fn($a) => ($a['hostname'] ?? ''), $ca)));
                $bgGrad = $companyColors[$ci % count($companyColors)];
                $ci++;
            ?>
            <div class="company-card rounded-2xl border border-border-theme bg-gradient-to-br <?= $bgGrad ?> bg-bg-panel/60 overflow-hidden backdrop-blur-sm shadow-theme-sm hover:border-white/[0.08] transition-all duration-300" data-search="<?= h($searchStr) ?>">
                <!-- Header expandible -->
                <div class="p-5 flex items-center gap-4 cursor-pointer hover:bg-white/[0.02] transition-colors" onclick="toggleCompany('<?= h($uid) ?>')">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary-600/40 to-primary-800/30 border border-primary-500/30 flex items-center justify-center text-white text-[13px] font-bold flex-shrink-0 shadow-lg shadow-primary-900/20"><?= h(strtoupper(mb_substr($cname, 0, 2))) ?></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-semibold text-white truncate"><?= h($cname) ?></p>
                        <p class="text-[10px] text-text-subtle truncate mt-0.5"><?= h($email) ?></p>
                    </div>
                    <div class="flex items-center gap-2.5 flex-shrink-0">
                        <div class="flex flex-col items-end gap-1">
                            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $isActive ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/25' : 'bg-red-500/15 text-red-400 border border-red-500/25' ?>"><?= $isActive ? 'Activo' : 'Suspendido' ?></span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20 flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                                    <?= count($ca) ?>
                                </span>
                                <?php if ($caOnline > 0): ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                    <?= $caOnline ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-text-subtle transition-transform duration-300 <?= $expandUid === $uid ? 'rotate-180' : '' ?> chevron-<?= h($uid) ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </div>
                </div>

                <!-- Admin actions -->
                <div class="px-5 pb-2 flex gap-1.5 flex-wrap">
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <input type="hidden" name="new_state" value="<?= $isActive ? 'false' : 'true' ?>">
                        <button type="submit" name="toggle_active" value="1" class="px-2.5 py-1 rounded-lg text-[10px] font-medium <?= $isActive ? 'bg-amber-500/10 border border-amber-500/25 text-amber-400 hover:bg-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 hover:bg-emerald-500/20' ?> transition-all flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $isActive ? 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636' : 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                            <?= $isActive ? 'Suspender' : 'Activar' ?>
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <button type="submit" name="reset_2fa" value="1" class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-white/[0.03] border border-white/[0.08] text-text-muted hover:text-text-body hover:bg-white/[0.06] transition-all flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset 2FA
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <button type="submit" name="delete_user" value="1" onclick="return confirm('¿Eliminar empresa <?= h($cname) ?> y todos sus datos?')" class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/25 text-red-400 hover:bg-red-500/20 transition-all flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Eliminar
                        </button>
                    </form>
                </div>

                <!-- Panel de equipos expandible -->
                <div id="co-<?= h($uid) ?>" class="<?= $expandUid === $uid ? '' : 'hidden' ?> border-t border-white/[0.06]">
                    <?php if (empty($ca)): ?>
                    <p class="text-[11px] text-text-subtle text-center py-6">Sin equipos registrados. Instala el agente en los equipos de esta empresa.</p>
                    <?php else: ?>
                    <div class="p-4 space-y-3">
                        <?php foreach ($ca as $a):
                            $isOnline = ($a['status'] ?? '') === 'online';
                            $ld = !empty($a['lockdown']['enabled']);
                            $cpu = $a['metrics']['cpu'] ?? 0;
                            $ram = $a['metrics']['memory'] ?? 0;
                        ?>
                        <div class="rounded-xl border <?= $isOnline ? 'border-emerald-500/15 bg-emerald-500/[0.03]' : 'border-white/[0.06] bg-white/[0.02]' ?> p-4 hover:border-white/[0.12] transition-all">
                            <div class="flex items-start gap-3">
                                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1 <?= $isOnline ? 'bg-emerald-400 shadow-lg shadow-emerald-500/30' : 'bg-red-400 shadow-lg shadow-red-500/30' ?>"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-[12px] font-semibold text-white"><?= h($a['hostname'] ?? $a['agentId'] ?? '') ?></p>
                                        <?php if ($ld): ?><span class="text-[9px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-400 border border-red-500/25 font-medium">BLOQUEADO</span><?php endif; ?>
                                        <span class="text-[9px] text-text-subtle font-mono"><?= h($a['ip'] ?? '') ?></span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded bg-white/[0.04] text-text-subtle border border-white/[0.06]"><?= h($a['platform'] ?? '') ?> / <?= h($a['arch'] ?? '') ?></span>
                                        <?php if ($a['version']): ?><span class="text-[9px] text-text-subtle font-mono">v<?= h($a['version']) ?></span><?php endif; ?>
                                    </div>
                                    <p class="text-[10px] text-text-subtle mt-1">Última conexión: <?= h(substr($a['lastSeen'] ?? '', 0, 16)) ?></p>
                                    <?php if ($isOnline && ($cpu > 0 || $ram > 0)): ?>
                                    <div class="flex items-center gap-4 mt-2">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[9px] text-text-subtle">CPU</span>
                                            <div class="w-16 h-1.5 bg-white/[0.05] rounded-full overflow-hidden"><div class="h-full rounded-full <?= ($cpu > 80) ? 'bg-red-500' : (($cpu > 50) ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= min(100, $cpu) ?>%"></div></div>
                                            <span class="text-[9px] text-text-subtle font-mono"><?= round($cpu) ?>%</span>
                                        </div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[9px] text-text-subtle">RAM</span>
                                            <div class="w-16 h-1.5 bg-white/[0.05] rounded-full overflow-hidden"><div class="h-full rounded-full <?= ($ram > 80) ? 'bg-red-500' : (($ram > 50) ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width:<?= min(100, $ram) ?>%"></div></div>
                                            <span class="text-[9px] text-text-subtle font-mono"><?= round($ram) ?>%</span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Control buttons (superadmin) -->
                            <?php if ($isSuper): ?>
                            <div class="mt-3 pt-3 border-t border-white/[0.04]">
                                <p class="text-[9px] text-text-subtle uppercase tracking-wider mb-2 font-semibold">Control Remoto</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <button onclick="openTools('<?= h($a['agentId'] ?? '') ?>','<?= h($a['hostname'] ?? '') ?>','processes')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-blue-500/10 border border-blue-500/20 text-blue-400 hover:bg-blue-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                                        Procesos
                                    </button>
                                    <button onclick="openTools('<?= h($a['agentId'] ?? '') ?>','<?= h($a['hostname'] ?? '') ?>','health')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 hover:bg-cyan-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                        Salud
                                    </button>
                                    <button onclick="openTools('<?= h($a['agentId'] ?? '') ?>','<?= h($a['hostname'] ?? '') ?>','screenshot')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-violet-500/10 border border-violet-500/20 text-violet-400 hover:bg-violet-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        Captura
                                    </button>
                                    <div class="w-px h-6 bg-white/[0.06] self-center"></div>
                                    <button onclick="doLock('<?= h($a['agentId'] ?? '') ?>','lock')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        Bloquear
                                    </button>
                                    <button onclick="doLock('<?= h($a['agentId'] ?? '') ?>','unlock')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                        Desbloquear
                                    </button>
                                    <button onclick="doTimedLock('<?= h($a['agentId'] ?? '') ?>')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-orange-500/10 border border-orange-500/20 text-orange-400 hover:bg-orange-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Temporal
                                    </button>
                                    <div class="w-px h-6 bg-white/[0.06] self-center"></div>
                                    <button onclick="doSpeak('<?= h($a['agentId'] ?? '') ?>')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M9 10a1 1 0 011-1h1a1 1 0 011 1v3a1 1 0 01-1 1h-1a1 1 0 01-1-1v-3z"/></svg>
                                        Hablar
                                    </button>
                                    <button onclick="doAlarm('<?= h($a['agentId'] ?? '') ?>',true)" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                        Alarma
                                    </button>
                                    <div class="w-px h-6 bg-white/[0.06] self-center"></div>
                                    <button onclick="powerAct('<?= h($a['agentId'] ?? '') ?>','restart')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-white/[0.04] border border-white/[0.08] text-text-muted hover:text-text-body hover:bg-white/[0.06] transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Reiniciar
                                    </button>
                                    <button onclick="powerAct('<?= h($a['agentId'] ?? '') ?>','suspend')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-white/[0.04] border border-white/[0.08] text-text-muted hover:text-text-body hover:bg-white/[0.06] transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                                        Suspender
                                    </button>
                                    <div class="w-px h-6 bg-white/[0.06] self-center"></div>
                                    <button onclick="deleteAgent('<?= h($a['agentId'] ?? '') ?>','<?= h($a['hostname'] ?? '') ?>')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-600/10 border border-red-600/20 text-red-300 hover:bg-red-600/20 transition-all flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Eliminar
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($companies)): ?>
            <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-12 text-center">
                <svg class="w-10 h-10 mx-auto text-text-subtle mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                <p class="text-text-muted text-[12px]">Sin empresas registradas.</p>
            </div>
            <?php endif; ?>

            <?php elseif ($tab === 'users'): ?>
            <!-- ═══ USUARIOS ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Crear usuario</h3>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <input type="email" name="email" required placeholder="Email" class="input-premium">
                    <input type="password" name="password" required placeholder="Contraseña (mín. 8)" class="input-premium">
                    <input type="text" name="company" placeholder="Empresa" class="input-premium">
                    <select name="role" class="input-premium">
                        <option value="user">Usuario</option>
                        <option value="support">Soporte</option>
                        <option value="admin">Admin</option>
                        <?php if ($isSuper): ?><option value="superadmin">Superadmin</option><?php endif; ?>
                    </select>
                    <button type="submit" name="create_user" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Crear</button>
                </form>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Usuarios (<?= count($users) ?>)</h3>
                <?php if (empty($users)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay usuarios.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead><tr class="bg-bg-base/60 border-b border-border-theme text-[10px] text-text-muted uppercase tracking-wider">
                            <th class="text-left py-3 px-3 font-semibold">Email</th>
                            <th class="text-left py-3 px-3 font-semibold">Empresa</th>
                            <th class="text-left py-3 px-3 font-semibold">Estado</th>
                            <th class="text-left py-3 px-3 font-semibold">Rol</th>
                            <th class="text-left py-3 px-3 font-semibold">Acciones</th>
                        </tr></thead>
                        <tbody class="divide-y divide-border-theme/30">
                            <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-bg-base/30 transition-colors">
                                <td class="py-2.5 px-3 text-text-heading"><?= h($u['email'] ?? '') ?></td>
                                <td class="py-2.5 px-3 text-text-muted"><?= h($u['companyName'] ?? '-') ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= !empty($u['isActive']) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>"><?= !empty($u['isActive']) ? 'Activo' : 'Suspendido' ?></span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php if (in_array($u['role'] ?? '', ['admin', 'superadmin'])): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-primary-500/10 text-primary-400 border border-primary-500/20"><?= h($u['role']) ?></span>
                                    <?php else: ?>
                                    <span class="text-text-subtle text-[10px]"><?= h($u['role'] ?? 'user') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin?tab=companies&uid=<?= h($u['_id'] ?? '') ?>" class="text-[10px] text-primary-400 hover:text-primary-300 transition-colors">Ver</a>
                                        <form method="POST" class="inline-flex gap-1.5">
                                            <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                            <input type="hidden" name="new_state" value="<?= !empty($u['isActive']) ? 'false' : 'true' ?>">
                                            <button type="submit" name="toggle_active" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium <?= !empty($u['isActive']) ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' ?> transition-all"><?= !empty($u['isActive']) ? 'Suspender' : 'Activar' ?></button>
                                            <button type="submit" name="reset_2fa" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Reset 2FA</button>
                                            <button type="submit" name="delete_user" value="1" onclick="return confirm('¿Eliminar este usuario y todos sus datos?')" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all">Eliminar</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'tickets'): ?>
            <!-- ═══ TICKETS ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Tickets de Soporte (<?= count($allTickets) ?>)</h3>
                <?php if (empty($allTickets)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay tickets.</p>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($allTickets as $t): $isOpen = ($t['status'] ?? '') === 'open'; ?>
                    <div class="p-4 rounded-lg border border-border-theme/25 bg-bg-base/40">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[12px] text-text-heading font-medium"><?= h($t['subject'] ?? $t['title'] ?? 'Ticket') ?></p>
                                <p class="text-[10px] text-text-subtle mt-0.5"><?= h($t['userEmail'] ?? $t['email'] ?? '') ?> · <?= h(substr($t['createdAt'] ?? '', 0, 16)) ?></p>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <span class="text-[9px] px-2 py-0.5 rounded-full <?= $isOpen ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' ?>"><?= h($t['status'] ?? '-') ?></span>
                                <?php if ($isOpen): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="ticket_id" value="<?= h($t['_id'] ?? '') ?>">
                                    <input type="hidden" name="new_status" value="closed">
                                    <button type="submit" name="ticket_status" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Cerrar</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isOpen): ?>
                        <form method="POST" class="mt-2 flex gap-2">
                            <input type="hidden" name="ticket_id" value="<?= h($t['_id'] ?? '') ?>">
                            <input type="text" name="response" required placeholder="Responder al ticket..." class="input-premium flex-1">
                            <button type="submit" name="ticket_respond" value="1" class="px-3 py-1.5 rounded-lg text-[10px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Enviar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'alerts'): ?>
            <!-- ═══ ALERTAS ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Alertas del Sistema (<?= count($adminAlerts) ?>)</h3>
                <?php if (empty($adminAlerts)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay alertas configuradas.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($adminAlerts as $a): ?>
                    <div class="p-3 rounded-lg border border-border-theme/25 bg-bg-base/40 flex items-center justify-between">
                        <div>
                            <span class="text-[12px] text-text-body"><?= h($a['title'] ?? $a['message'] ?? 'Alerta') ?></span>
                            <?php if (!empty($a['description'])): ?><p class="text-[10px] text-text-subtle mt-0.5"><?= h($a['description']) ?></p><?php endif; ?>
                        </div>
                        <span class="text-[9px] text-text-subtle flex-shrink-0"><?= h(substr($a['createdAt'] ?? '', 0, 16)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'logs'): ?>
            <!-- ═══ LOGS DE AUDITORÍA ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-[13px] font-semibold text-white">Logs de Auditoría del Sistema</h3>
                    <div class="flex items-center gap-2">
                        <span id="logsCount" class="text-[10px] text-text-subtle">-</span>
                        <label class="flex items-center gap-1.5 text-[10px] text-text-muted cursor-pointer">
                            <input type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()" class="rounded border-border-theme bg-bg-base text-primary-500">
                            Auto-refresh 15s
                        </label>
                        <button onclick="exportCSV()" class="px-2 py-1 rounded text-[9px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all">Exportar CSV</button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select id="fUser" onchange="loadLogs()" class="input-premium text-[10px] py-1 max-w-[180px]">
                        <option value="">Todos los usuarios</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= h($u['_id'] ?? '') ?>"><?= h($u['email'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="fAgent" onchange="loadLogs()" class="input-premium text-[10px] py-1 max-w-[180px]">
                        <option value="">Todos los equipos</option>
                        <?php foreach ($agents as $a): ?>
                        <option value="<?= h($a['agentId'] ?? '') ?>"><?= h($a['hostname'] ?? $a['agentId'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="fAction" oninput="loadLogs()" placeholder="Filtrar por acción..." list="actionList" class="input-premium text-[10px] py-1 max-w-[160px]">
                    <datalist id="actionList">
                        <option value="login_success">
                        <option value="login_failed">
                        <option value="user_registered">
                        <option value="agent_registered">
                        <option value="agent_deleted">
                        <option value="agent_command">
                        <option value="lockdown_on">
                        <option value="lockdown_off">
                        <option value="admin_user_created">
                        <option value="admin_user_updated">
                        <option value="admin_user_deleted">
                        <option value="admin_password_reset">
                        <option value="admin_2fa_reset">
                        <option value="maintenance_toggled">
                    </datalist>
                    <input type="date" id="fFrom" onchange="loadLogs()" class="input-premium text-[10px] py-1 max-w-[140px]" placeholder="Desde">
                    <input type="date" id="fTo" onchange="loadLogs()" class="input-premium text-[10px] py-1 max-w-[140px]" placeholder="Hasta">
                    <input type="text" id="fQ" oninput="debounceLogs()" placeholder="Buscar texto..." class="input-premium text-[10px] py-1 max-w-[160px]">
                    <select id="fLimit" onchange="loadLogs()" class="input-premium text-[10px] py-1 max-w-[100px]">
                        <option value="100">100</option>
                        <option value="300" selected>300</option>
                        <option value="1000">1000</option>
                    </select>
                </div>
                <div class="overflow-x-auto max-h-[55vh] overflow-y-auto scrollbar-custom">
                    <table class="w-full text-[11px]">
                        <thead class="sticky top-0 bg-bg-panel z-10">
                            <tr class="border-b border-border-theme text-[9px] text-text-subtle uppercase tracking-wider">
                                <th class="text-left py-2.5 px-3 font-semibold">Fecha</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Acción</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Usuario</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Empresa</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Equipo</th>
                                <th class="text-left py-2.5 px-3 font-semibold">IP</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Detalle</th>
                            </tr>
                        </thead>
                        <tbody id="logsBody" class="divide-y divide-border-theme/20">
                            <tr><td colspan="7" class="text-center py-6 text-text-subtle">Cargando logs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($tab === 'settings'): ?>
            <!-- ═══ CONFIGURACIÓN ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-1">Modo Mantenimiento</h3>
                <p class="text-[11px] text-text-subtle mb-4">Cuando está activo, los usuarios no-admin ven un aviso de mantenimiento.</p>
                <?php $mOn = !empty($maintenance['maintenanceMode']); ?>
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-[11px] <?= $mOn ? 'text-amber-400' : 'text-emerald-400' ?> font-medium"><?= $mOn ? '⚠ Mantenimiento ACTIVO' : '✓ Sistema operativo' ?></span>
                </div>
                <form method="POST" class="flex flex-col sm:flex-row gap-2">
                    <input type="hidden" name="enabled" value="<?= $mOn ? 'false' : 'true' ?>">
                    <input type="text" name="maintenance_message" placeholder="Mensaje de mantenimiento (opcional)" value="<?= h($maintenance['maintenanceMessage'] ?? '') ?>" class="input-premium flex-1">
                    <button type="submit" name="toggle_maintenance" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium <?= $mOn ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' : 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' ?> transition-all">
                        <?= $mOn ? 'Desactivar mantenimiento' : 'Activar mantenimiento' ?>
                    </button>
                </form>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Información del Sistema</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Versión</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono">2.0.0</p>
                    </div>
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Usuarios</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono"><?= count($users) ?></p>
                    </div>
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Equipos</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono"><?= $totalAgents ?></p>
                    </div>
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Tickets</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono"><?= count($allTickets) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ═══ Agent Tools Modal ═══ -->
<div id="toolsOverlay" class="hidden fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="w-full max-w-4xl max-h-[90vh] bg-bg-panel border border-border-theme rounded-2xl flex flex-col overflow-hidden shadow-2xl">
        <div class="px-5 py-3 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
            <div class="flex items-center gap-3">
                <div class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse" id="toolsDot"></div>
                <h3 class="text-[13px] font-semibold text-white" id="toolsTitle">-</h3>
            </div>
            <div class="flex items-center gap-2">
                <span id="toolsStatus" class="text-[10px] text-text-subtle"></span>
                <button onclick="closeTools()" class="p-1.5 rounded-lg hover:bg-white/[0.05] text-text-muted hover:text-white transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="px-5 py-2 border-b border-border-theme/50 flex gap-1">
            <button onclick="toolTab('processes')" id="tab-proc" class="tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium bg-primary-500/15 text-primary-400 border border-primary-500/20 transition-all">Procesos</button>
            <button onclick="toolTab('health')" id="tab-health" class="tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all">Salud</button>
            <button onclick="toolTab('screenshot')" id="tab-screenshot" class="tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all">Captura</button>
            <button onclick="toolTab('shell')" id="tab-shell" class="tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all">Shell</button>
        </div>
        <div id="toolsContent" class="flex-1 overflow-y-auto p-5 min-h-[300px] scrollbar-custom">
            <p class="text-text-subtle text-center py-10 text-[11px]">Selecciona una pestaña para solicitar datos al agente.</p>
        </div>
    </div>
</div>

<script>
const SL_TOKEN = <?= json_encode($token) ?>;
const IS_SUPER = <?= $isSuper ? 'true' : 'false' ?>;
const AGENTS = <?= json_encode(array_values($agents), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const API = '/api';

// ── Companies ──
function toggleCompany(uid) {
    const el = document.getElementById('co-' + uid);
    const chev = document.querySelector('.chevron-' + uid);
    if (el) el.classList.toggle('hidden');
    if (chev) chev.classList.toggle('rotate-180');
}

function filterCompanies(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.company-card').forEach(c => {
        const s = c.dataset.search || '';
        c.style.display = s.includes(q) ? '' : 'none';
    });
}

// ── Agent Tools Modal ──
let _agentId = '', _agentName = '', _pollTimer = null, _pollTries = 0, _currentTab = 'processes';

function h() { return { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN }; }

function openTools(agentId, hostname, tab) {
    _agentId = agentId;
    _agentName = hostname || agentId;
    document.getElementById('toolsTitle').textContent = _agentName;
    document.getElementById('toolsOverlay').classList.remove('hidden');
    toolTab(tab || 'processes');
}

function closeTools() {
    document.getElementById('toolsOverlay').classList.add('hidden');
    stopPoll();
}

function toolTab(t) {
    _currentTab = t;
    document.querySelectorAll('.tool-tab').forEach(b => {
        b.className = 'tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all border ';
        if (b.id === 'tab-' + (t === 'screenshot' ? 'screenshot' : t === 'health' ? 'health' : t === 'shell' ? 'shell' : 'proc')) {
            b.className += 'bg-primary-500/15 text-primary-400 border-primary-500/20';
        } else {
            b.className += 'text-text-muted hover:bg-white/[0.03] border-transparent';
        }
    });
    if (t === 'shell') {
        initShell();
        return;
    }
    setToolStatus('Solicitando ' + t + '...');
    reqData(t);
}

function setToolStatus(s) { document.getElementById('toolsStatus').textContent = s; }

function reqData(type) {
    stopPoll();
    fetch(API + '/agents/request-data', { method: 'POST', headers: h(), body: JSON.stringify({ agentId: _agentId, type }) })
        .then(r => r.json()).then(() => startPoll(type))
        .catch(() => setToolStatus('Error de red'));
}

function startPoll(type) {
    _pollTries = 0;
    _pollTimer = setInterval(() => {
        _pollTries++;
        fetch(API + '/agents/' + encodeURIComponent(_agentId) + '/data?type=' + type, { headers: h() })
            .then(r => r.json()).then(res => {
                if (res && res.data) { stopPoll(); renderData(type, res.data); }
                else if (_pollTries >= 10) { stopPoll(); setToolStatus('Sin respuesta del agente (¿offline?)'); }
            }).catch(() => {});
    }, 2000);
}

function stopPoll() { if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; } }

function renderData(type, data) {
    const el = document.getElementById('toolsContent');
    setToolStatus('');
    if (type === 'processes') {
        if (!Array.isArray(data)) { el.innerHTML = '<p class="text-text-subtle text-center py-6">Sin datos.</p>'; return; }
        let html = '<div class="text-[10px] text-text-subtle mb-2">' + data.length + ' procesos activos</div><div class="overflow-x-auto"><table class="w-full text-[11px]"><thead><tr class="border-b border-border-theme text-[9px] text-text-subtle uppercase tracking-wider"><th class="text-left py-2 px-2">Nombre</th><th class="text-left py-2 px-2">PID</th><th class="text-left py-2 px-2">CPU</th><th class="text-left py-2 px-2">Mem</th><th class="text-right py-2 px-2">Acción</th></tr></thead><tbody class="divide-y divide-border-theme/20">';
        data.slice(0, 80).forEach(p => {
            const mem = typeof p.memory === 'number' ? (p.memory > 1024 ? (p.memory / 1024).toFixed(1) + ' GB' : p.memory.toFixed(0) + ' MB') : (p.memory || '-');
            html += '<tr class="hover:bg-bg-base/20"><td class="py-1.5 px-2 text-text-heading max-w-[200px] truncate">' + esc(p.name || '') + '</td><td class="py-1.5 px-2 text-text-muted font-mono">' + esc(p.pid || '') + '</td><td class="py-1.5 px-2 text-text-muted">' + esc(p.cpu != null ? p.cpu + '%' : '-') + '</td><td class="py-1.5 px-2 text-text-muted">' + mem + '</td><td class="py-1.5 px-2 text-right"><button onclick="killProc(\'' + esc(p.pid || '') + '\')" class="px-1.5 py-0.5 rounded text-[9px] bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20">Kill</button></td></tr>';
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    } else if (type === 'health') {
        const d = typeof data === 'object' ? data : {};
        const cpu = d.cpu ?? d.cpuPercent ?? 0;
        const ram = d.memory ?? d.memoryPercent ?? d.ram ?? 0;
        const disk = d.disk ?? d.diskPercent ?? 0;
        const uptime = d.uptime ?? 0;
        const uptimeH = uptime > 3600 ? Math.floor(uptime / 3600) + 'h ' + Math.floor((uptime % 3600) / 60) + 'm' : Math.floor(uptime / 60) + 'm';
        const bar = (v, c) => '<div class="h-2 w-full bg-white/[0.05] rounded-full overflow-hidden"><div class="h-full rounded-full ' + c + '" style="width:' + Math.min(100, v) + '%"></div></div>';
        let html = '<div class="grid grid-cols-3 gap-4 mb-4">';
        html += '<div><div class="text-[10px] text-text-subtle mb-1">CPU</div>' + bar(cpu, cpu > 80 ? 'bg-red-500' : cpu > 50 ? 'bg-amber-500' : 'bg-emerald-500') + '<div class="text-[12px] text-text-heading mt-1 font-mono">' + cpu.toFixed(1) + '%</div></div>';
        html += '<div><div class="text-[10px] text-text-subtle mb-1">RAM</div>' + bar(ram, ram > 80 ? 'bg-red-500' : ram > 50 ? 'bg-amber-500' : 'bg-emerald-500') + '<div class="text-[12px] text-text-heading mt-1 font-mono">' + ram.toFixed(1) + '%</div></div>';
        html += '<div><div class="text-[10px] text-text-subtle mb-1">Disco</div>' + bar(disk, disk > 90 ? 'bg-red-500' : disk > 70 ? 'bg-amber-500' : 'bg-emerald-500') + '<div class="text-[12px] text-text-heading mt-1 font-mono">' + disk.toFixed(1) + '%</div></div>';
        html += '</div><div class="text-[11px] text-text-muted">Uptime: <span class="text-text-heading font-mono">' + uptimeH + '</span></div>';
        if (d.topProcesses && d.topProcesses.length) {
            html += '<div class="mt-3 text-[10px] text-text-subtle mb-1">Top procesos</div><div class="space-y-1">';
            d.topProcesses.slice(0, 8).forEach(p => { html += '<div class="text-[11px] text-text-body">' + esc(p.name || p) + '</div>'; });
            html += '</div>';
        }
        el.innerHTML = html;
    } else if (type === 'screenshot') {
        let imgSrc = '';
        if (typeof data === 'string') imgSrc = data.startsWith('http') ? data : 'data:image/png;base64,' + data;
        else if (data && data.image) imgSrc = data.image.startsWith('http') ? data.image : 'data:image/png;base64,' + data.image;
        else if (data && data.data) imgSrc = 'data:image/png;base64,' + data.data;
        if (imgSrc) {
            el.innerHTML = '<img src="' + imgSrc + '" class="rounded-lg max-w-full mx-auto border border-border-theme">';
            el.querySelector('img').onerror = function() { el.innerHTML = '<p class="text-red-400 text-center py-6">Error al cargar imagen.</p>'; };
        } else {
            el.innerHTML = '<p class="text-text-subtle text-center py-10">Sin captura disponible.</p>';
        }
    }
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function killProc(pid) {
    if (!pid) return;
    agentCmd(_agentId, 'kill_process', { pid: pid }).then(() => {
        setTimeout(() => reqData('processes'), 1500);
    });
}

// ── Agent Commands ──
function agentCmd(agentId, command, params) {
    return fetch(API + '/agents/' + encodeURIComponent(agentId) + '/command', { method: 'POST', headers: h(), body: JSON.stringify({ command, params: params || {} }) })
        .then(r => r.json()).then(res => { setToolStatus(res.success ? '✓ Comando enviado' : (res.error || 'Error')); return res; })
        .catch(() => { setToolStatus('Error de red'); return {}; });
}

function doLock(agentId, action) {
    const msg = action === 'lock' ? prompt('Motivo del bloqueo (opcional):') : '';
    if (action === 'lock' && msg === null) return;
    fetch(API + '/agents/lockdown', { method: 'POST', headers: h(), body: JSON.stringify({ agentId, action, message: msg || '' }) })
        .then(r => r.json()).then(res => { alert(res.success ? '✓ ' + (action === 'lock' ? 'Bloqueado' : 'Desbloqueado') : (res.error || 'Error')); location.reload(); })
        .catch(() => alert('Error de red'));
}

function doTimedLock(agentId) {
    const mins = prompt('Minutos de bloqueo temporal:');
    if (!mins) return;
    agentCmd(agentId, 'lock_timed', { minutes: parseInt(mins) || 5 });
}

function doSpeak(agentId) {
    const text = prompt('Mensaje que el equipo debe leer en voz alta:');
    if (!text) return;
    agentCmd(agentId, 'speak', { text, message: text });
}

function doAlarm(agentId, on) {
    agentCmd(agentId, on ? 'alarm' : 'alarm_stop', {});
}

function powerAct(agentId, kind) {
    const label = kind === 'restart' ? 'Reiniciar' : 'Suspender';
    if (!confirm('¿' + label + ' el equipo?')) return;
    agentCmd(agentId, 'power_' + kind, {});
}

function deleteAgent(agentId, name) {
    if (!confirm('¿Eliminar permanentemente el equipo "' + name + '"?')) return;
    fetch(API + '/agents/' + encodeURIComponent(agentId) + '/delete', { method: 'POST', headers: h() })
        .then(r => r.json()).then(res => { alert(res.success ? '✓ Equipo eliminado' : (res.error || 'Error')); location.reload(); })
        .catch(() => alert('Error de red'));
}

// ── Shell ──
let _shellHistory = [], _shellIdx = -1, _shellPollTimer = null, _shellCmdId = null;

function initShell() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = '<div id="shell-box" class="rounded-xl border border-border-theme/40 bg-[#0a0e14] p-0 min-h-[300px] flex flex-col">' +
        '<div id="shell-output" class="flex-1 overflow-y-auto p-4 font-mono text-[11px] text-emerald-400 space-y-1 min-h-[240px] max-h-[400px] scrollbar-custom">' +
        '<p class="text-text-subtle">SecureLab Shell — ejecuta comandos remotos en el equipo</p>' +
        '<p class="text-text-subtle">Escribe un comando y presiona Enter.</p></div>' +
        '<div class="border-t border-border-theme/40 flex items-center gap-2 px-4 py-2">' +
        '<span class="text-emerald-400 font-mono text-[11px] select-none">$&gt;</span>' +
        '<input id="shell-input" type="text" placeholder="Escribe un comando..." class="flex-1 bg-transparent border-0 text-[12px] text-white font-mono focus:outline-none placeholder-text-subtle" autocomplete="off">' +
        '</div></div>';
    setToolStatus('');
    const inp = document.getElementById('shell-input');
    if (inp) {
        inp.focus();
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); shellExec(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); shellNav(-1); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); shellNav(1); }
        });
    }
}

function shellNav(dir) {
    _shellIdx += dir;
    if (_shellIdx < 0) _shellIdx = 0;
    if (_shellIdx >= _shellHistory.length) _shellIdx = _shellHistory.length - 1;
    const inp = document.getElementById('shell-input');
    if (inp && _shellHistory[_shellIdx]) inp.value = _shellHistory[_shellIdx];
}

function shellExec() {
    const inp = document.getElementById('shell-input');
    const out = document.getElementById('shell-output');
    if (!inp || !out) return;
    const cmd = inp.value.trim();
    if (!cmd) return;
    _shellHistory.push(cmd);
    _shellIdx = _shellHistory.length;
    inp.value = '';

    const line = document.createElement('p');
    line.innerHTML = '<span class="text-blue-400">$&gt;</span> ' + escH(cmd);
    out.appendChild(line);

    const loading = document.createElement('p');
    loading.className = 'text-text-subtle animate-pulse';
    loading.textContent = 'Ejecutando...';
    out.appendChild(loading);
    out.scrollTop = out.scrollHeight;

    setToolStatus('Ejecutando...');
    agentCmd(_agentId, 'shell_exec', { command: cmd }).then(res => {
        if (res && res.commandId) { _shellCmdId = res.commandId; startShellPoll(); }
        else { loading.textContent = res.error || 'Error al enviar comando'; loading.className = 'text-red-400'; }
    });
}

function startShellPoll() {
    if (_shellPollTimer) clearInterval(_shellPollTimer);
    let tries = 0;
    _shellPollTimer = setInterval(() => {
        tries++;
        fetch(API + '/agents/' + encodeURIComponent(_agentId) + '/commands', { headers: h() })
            .then(r => r.json()).then(cmds => {
                if (!Array.isArray(cmds)) return;
                const match = cmds.find(c => c._id === _shellCmdId && c.executed);
                if (match) {
                    clearInterval(_shellPollTimer); _shellPollTimer = null;
                    const out = document.getElementById('shell-output');
                    if (!out) return;
                    const lines = out.querySelectorAll('p.animate-pulse');
                    lines.forEach(l => l.remove());
                    const result = match.result || match.status || 'Sin resultado';
                    const isError = match.status === 'error' || (match.error && match.error !== false);
                    const pre = document.createElement('pre');
                    pre.className = (isError ? 'text-red-400' : 'text-emerald-400') + ' whitespace-pre-wrap break-all text-[11px] leading-relaxed';
                    pre.textContent = result;
                    out.appendChild(pre);
                    out.scrollTop = out.scrollHeight;
                    setToolStatus('');
                    _shellCmdId = null;
                } else if (tries >= 30) {
                    clearInterval(_shellPollTimer); _shellPollTimer = null;
                    const out = document.getElementById('shell-output');
                    const lines = out ? out.querySelectorAll('p.animate-pulse') : [];
                    lines.forEach(l => { l.textContent = 'Sin respuesta (timeout 60s)'; l.className = 'text-amber-400'; });
                    setToolStatus('Timeout');
                }
            }).catch(() => {});
    }, 2000);
}

function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
let _logsDebounce = null, _autoRefreshInterval = null;

function loadLogs() {
    const body = {
        userId: document.getElementById('fUser').value,
        agentId: document.getElementById('fAgent').value,
        action: document.getElementById('fAction').value,
        from: document.getElementById('fFrom').value,
        to: document.getElementById('fTo').value,
        q: document.getElementById('fQ').value,
        limit: parseInt(document.getElementById('fLimit').value) || 300,
    };
    fetch(API + '/admin/audit-logs', { method: 'POST', headers: h(), body: JSON.stringify(body) })
        .then(r => r.json()).then(res => {
            const logs = res.logs || res || [];
            document.getElementById('logsCount').textContent = logs.length + ' registros';
            renderLogs(Array.isArray(logs) ? logs : []);
        }).catch(() => {});
}

function renderLogs(logs) {
    const body = document.getElementById('logsBody');
    if (!logs.length) { body.innerHTML = '<tr><td colspan="7" class="text-center py-6 text-text-subtle">Sin resultados</td></tr>'; return; }
    body.innerHTML = logs.map(l => {
        const badge = logBadge(l.action || '');
        return '<tr class="hover:bg-bg-base/20 transition-colors"><td class="py-2 px-3 text-text-subtle font-mono whitespace-nowrap">' + esc((l.createdAt || '').substring(0, 19)) + '</td><td class="py-2 px-3"><span class="text-[9px] px-1.5 py-0.5 rounded-full border ' + badge.cls + '">' + badge.label + '</span></td><td class="py-2 px-3 text-text-body">' + esc(l.userEmail || l.userId || '-') + '</td><td class="py-2 px-3 text-text-muted">' + esc(l.companyName || '-') + '</td><td class="py-2 px-3 text-text-muted font-mono">' + esc(l.agentId ? l.agentId.substring(0, 16) : '-') + '</td><td class="py-2 px-3 text-text-subtle font-mono">' + esc(l.ip || '-') + '</td><td class="py-2 px-3 text-text-muted max-w-[200px] truncate" title="' + esc(JSON.stringify(l.details || {})) + '">' + esc(typeof l.details === 'object' ? Object.keys(l.details || {}).join(', ') : (l.details || '')) + '</td></tr>';
    }).join('');
}

function logBadge(action) {
    const m = {
        'login_success': { label: 'Login OK', cls: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
        'login_failed': { label: 'Login Fallido', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
        'user_registered': { label: 'Registro', cls: 'bg-sky-500/10 text-sky-400 border-sky-500/20' },
        'agent_registered': { label: 'Agente+', cls: 'bg-violet-500/10 text-violet-400 border-violet-500/20' },
        'agent_deleted': { label: 'Agente-', cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
        'agent_command': { label: 'Comando', cls: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20' },
        'lockdown_on': { label: 'Bloqueo ON', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
        'lockdown_off': { label: 'Bloqueo OFF', cls: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
        'admin_user_created': { label: 'Crea User', cls: 'bg-blue-500/10 text-blue-400 border-blue-500/20' },
        'admin_user_updated': { label: 'Edit User', cls: 'bg-blue-500/10 text-blue-400 border-blue-500/20' },
        'admin_user_deleted': { label: 'Del User', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
        'admin_password_reset': { label: 'Reset Pass', cls: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
        'admin_2fa_reset': { label: 'Reset 2FA', cls: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
        'maintenance_toggled': { label: 'Mantenimiento', cls: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
    };
    return m[action] || { label: action || '?', cls: 'bg-white/[0.04] text-text-subtle border-white/[0.06]' };
}

function debounceLogs() { clearTimeout(_logsDebounce); _logsDebounce = setTimeout(loadLogs, 400); }

function toggleAutoRefresh() {
    if (document.getElementById('autoRefresh').checked) {
        _autoRefreshInterval = setInterval(loadLogs, 15000);
        loadLogs();
    } else if (_autoRefreshInterval) {
        clearInterval(_autoRefreshInterval);
        _autoRefreshInterval = null;
    }
}

function exportCSV() {
    const rows = document.querySelectorAll('#logsBody tr');
    if (!rows.length) return;
    let csv = 'Fecha,Acción,Usuario,Empresa,Equipo,IP,Detalle\n';
    rows.forEach(r => {
        const cells = r.querySelectorAll('td');
        if (cells.length >= 7) csv += Array.from(cells).map(c => '"' + c.textContent.replace(/"/g, '""').trim() + '"').join(',') + '\n';
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'audit-logs-' + new Date().toISOString().substring(0, 10) + '.csv';
    a.click();
}

// ── Init ──
if ('<?= $tab ?>' === 'logs') loadLogs();
<?php if ($expandUid): ?>
setTimeout(function() { var el = document.getElementById('co-<?= h($expandUid) ?>'); if (el) el.classList.remove('hidden'); }, 100);
<?php endif; ?>

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTools(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
