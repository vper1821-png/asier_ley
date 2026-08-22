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
                    ['label' => 'Empresas', 'value' => count($companies), 'sub' => 'registradas', 'color' => 'from-violet-500/20 to-violet-700/10', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                    ['label' => 'Equipos', 'value' => $onlineAgents . '/' . $totalAgents, 'sub' => 'online / total', 'color' => 'from-emerald-500/20 to-emerald-700/10', 'icon' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
                    ['label' => 'Usuarios', 'value' => count($users), 'sub' => $suspendedUsers . ' suspendidos', 'color' => 'from-cyan-500/20 to-cyan-700/10', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                    ['label' => 'Tickets abiertos', 'value' => $openTickets, 'sub' => 'pendientes', 'color' => 'from-amber-500/20 to-amber-700/10', 'icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
                ] as $s): ?>
                <div class="relative overflow-hidden rounded-xl border border-border-theme/25 bg-gradient-to-br <?= $s['color'] ?> bg-bg-panel/60 p-4 shadow-theme-sm">
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-2">
                            <svg class="w-4 h-4 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $s['icon'] ?>"/></svg>
                            <span class="text-[10px] text-white/70 tracking-wide uppercase"><?= h($s['label']) ?></span>
                        </div>
                        <p class="text-[26px] font-bold leading-none text-white"><?= h($s['value']) ?></p>
                        <?php if (!empty($s['sub'])): ?><p class="text-[10px] text-white/60 mt-1.5"><?= h($s['sub']) ?></p><?php endif; ?>
                    </div>
                    <div class="absolute -right-3 -bottom-3 w-16 h-16 rounded-full bg-white/5 blur-xl"></div>
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
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-violet-500/10 to-violet-700/5 p-4">
                    <p class="text-[10px] text-violet-400 uppercase tracking-wide">Empresas</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= count($companies) ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-emerald-500/10 to-emerald-700/5 p-4">
                    <p class="text-[10px] text-emerald-400 uppercase tracking-wide">Equipos online</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $onlineAgents ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-amber-500/10 to-amber-700/5 p-4">
                    <p class="text-[10px] text-amber-400 uppercase tracking-wide">Equipos offline</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $totalAgents - $onlineAgents ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-cyan-500/10 to-cyan-700/5 p-4">
                    <p class="text-[10px] text-cyan-400 uppercase tracking-wide">Total equipos</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $totalAgents ?></p>
                </div>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3 mb-4">
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
                <div class="px-5 pb-3 flex gap-2 flex-wrap">
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <input type="hidden" name="new_state" value="<?= $isActive ? 'false' : 'true' ?>">
                        <button type="submit" name="toggle_active" value="1" class="px-3 py-1.5 rounded-lg text-[11px] font-medium shadow-sm <?= $isActive ? 'bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 hover:shadow-amber-500/10' : 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 hover:shadow-emerald-500/10' ?> transition-all flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $isActive ? 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636' : 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' ?>"/></svg>
                            <?= $isActive ? 'Suspender' : 'Activar' ?>
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <button type="submit" name="reset_2fa" value="1" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.04] border border-white/[0.1] text-text-muted hover:text-text-body hover:bg-white/[0.08] hover:border-white/[0.15] transition-all flex items-center gap-1.5 shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset 2FA
                        </button>
                    </form>
                    <form method="POST" class="inline">
                        <input type="hidden" name="user_id" value="<?= h($uid) ?>">
                        <button type="submit" name="delete_user" value="1" onclick="return confirm('¿Eliminar empresa <?= h($cname) ?> y todos sus datos?')" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-red-500/10 border border-red-500/30 text-red-400 hover:bg-red-500/20 hover:shadow-red-500/10 transition-all flex items-center gap-1.5 shadow-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
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
                                    <button onclick="openTools('<?= h($a['agentId'] ?? '') ?>','<?= h($a['hostname'] ?? '') ?>','processes')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-blue-500/10 border border-blue-500/20 text-blue-400 hover:bg-blue-500/20 hover:scale-105 transition-all flex items-center gap-1.5">
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
                                    <button onclick="doSilentLock('<?= h($a['agentId'] ?? '') ?>')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-rose-500/10 border border-rose-500/20 text-rose-300 hover:bg-rose-500/20 transition-all flex items-center gap-1.5" title="Bloquear sin sonido">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" clip-rule="evenodd"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"/></svg>
                                        Sin Sonido
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
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                <?php
                $activeUsers = count(array_filter($users, fn($u) => !empty($u['isActive'])));
                $suspendedUsers = count(array_filter($users, fn($u) => empty($u['isActive'])));
                $adminUsers = count(array_filter($users, fn($u) => in_array($u['role'] ?? '', ['admin','superadmin'])));
                ?>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-emerald-500/10 to-emerald-700/5 p-4">
                    <p class="text-[10px] text-emerald-400 uppercase tracking-wide">Activos</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $activeUsers ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-amber-500/10 to-amber-700/5 p-4">
                    <p class="text-[10px] text-amber-400 uppercase tracking-wide">Suspendidos</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $suspendedUsers ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-primary-500/10 to-primary-700/5 p-4">
                    <p class="text-[10px] text-primary-400 uppercase tracking-wide">Admins</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= $adminUsers ?></p>
                </div>
                <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-cyan-500/10 to-cyan-700/5 p-4">
                    <p class="text-[10px] text-cyan-400 uppercase tracking-wide">Total</p>
                    <p class="text-[22px] font-bold text-white mt-1"><?= count($users) ?></p>
                </div>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    Crear usuario
                </h3>
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
                    <button type="submit" name="create_user" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Crear
                    </button>
                </form>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2a3 3 0 00-5.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2a3 3 0 015.356-1.857m0 0a3 3 0 10-4.788-3.538 3.001 3.001 0 004.788 3.538z"/></svg>
                    Usuarios registrados
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 ml-auto"><?= count($users) ?></span>
                </h3>
                <?php if (empty($users)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay usuarios.</p>
                <?php else: ?>
                <div class="overflow-x-auto rounded-lg border border-border-theme/25">
                    <table class="w-full text-[12px]">
                        <thead><tr class="bg-bg-base/80 border-b border-border-theme text-[10px] text-text-subtle uppercase tracking-wider">
                            <th class="text-left py-3 px-3 font-semibold">Email</th>
                            <th class="text-left py-3 px-3 font-semibold">Empresa</th>
                            <th class="text-left py-3 px-3 font-semibold">Estado</th>
                            <th class="text-left py-3 px-3 font-semibold">Rol</th>
                            <th class="text-left py-3 px-3 font-semibold">Acciones</th>
                        </tr></thead>
                        <tbody class="divide-y divide-border-theme/20">
                            <?php foreach ($users as $u):
                                $uActive = !empty($u['isActive']);
                                $uRole = $u['role'] ?? 'user';
                                $uAdmin = in_array($uRole, ['admin','superadmin']);
                            ?>
                            <tr class="hover:bg-bg-base/40 transition-colors">
                                <td class="py-2.5 px-3 text-text-heading font-medium"><?= h($u['email'] ?? '') ?></td>
                                <td class="py-2.5 px-3 text-text-muted"><?= h($u['companyName'] ?? '-') ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= $uActive ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>"><?= $uActive ? 'Activo' : 'Suspendido' ?></span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= $uAdmin ? 'bg-primary-500/10 text-primary-400 border border-primary-500/20' : 'bg-white/[0.05] text-text-subtle border border-white/[0.08]' ?>"><?= h($uRole) ?></span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin?tab=companies&uid=<?= h($u['_id'] ?? '') ?>" class="text-[10px] text-primary-400 hover:text-primary-300 transition-colors flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            Ver
                                        </a>
                                        <form method="POST" class="inline-flex gap-1.5">
                                            <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                            <input type="hidden" name="new_state" value="<?= $uActive ? 'false' : 'true' ?>">
                                            <button type="submit" name="toggle_active" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium <?= $uActive ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' ?> transition-all flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                                <?= $uActive ? 'Suspender' : 'Activar' ?>
                                            </button>
                                            <button type="submit" name="reset_2fa" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-white/[0.03] border border-white/[0.08] text-text-muted hover:text-text-body hover:bg-white/[0.06] transition-all flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                2FA
                                            </button>
                                            <button type="submit" name="delete_user" value="1" onclick="return confirm('¿Eliminar este usuario y todos sus datos?')" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                Eliminar
                                            </button>
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
            <!-- ═══ CENTRO DE SOPORTE Y TICKETS (ADMIN) ═══ -->
            <?php
            $statusCfg = [
                'open'        => ['label' => 'Abierto',      'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/25', 'dot' => 'bg-emerald-400'],
                'in_progress' => ['label' => 'En Atención',  'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/25',     'dot' => 'bg-amber-400'],
                'pending'     => ['label' => 'Pendiente',    'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/25',     'dot' => 'bg-amber-400'],
                'closed'      => ['label' => 'Cerrado',      'class' => 'bg-white/[0.04] text-text-subtle border-white/[0.08]',   'dot' => 'bg-slate-500'],
            ];
            $prioCfg = [
                'low'      => ['label' => 'Baja',     'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',     'dot' => 'bg-blue-400'],
                'medium'   => ['label' => 'Media',    'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',   'dot' => 'bg-amber-400'],
                'high'     => ['label' => 'Alta',     'class' => 'bg-orange-500/10 text-orange-400 border-orange-500/20', 'dot' => 'bg-orange-400'],
                'critical' => ['label' => 'Crítica',  'class' => 'bg-red-500/10 text-red-400 border-red-500/20',       'dot' => 'bg-red-400'],
            ];
            $countBy = ['total' => count($allTickets), 'open' => 0, 'in_progress' => 0, 'closed' => 0];
            foreach ($allTickets as $t) {
                $s = $t['status'] ?? 'open';
                if ($s === 'closed') $countBy['closed']++;
                elseif ($s === 'in_progress' || $s === 'pending') $countBy['in_progress']++;
                else $countBy['open']++;
            }
            ?>

            <!-- Metrics Bento -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-5">
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Total Incidencias</p>
                        <p class="text-xl font-bold text-white font-mono mt-0.5"><?= $countBy['total'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                </div>
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-emerald-400/90 tracking-wider">Tickets Abiertos</p>
                        <p class="text-xl font-bold text-emerald-400 font-mono mt-0.5"><?= $countBy['open'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    </div>
                </div>
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-amber-400/90 tracking-wider">En Atención</p>
                        <p class="text-xl font-bold text-amber-400 font-mono mt-0.5"><?= $countBy['in_progress'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Resueltos / Cerrados</p>
                        <p class="text-xl font-bold text-text-body font-mono mt-0.5"><?= $countBy['closed'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 mb-5">
                <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
                    <div class="relative max-w-xs w-full">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle pointer-events-none">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" id="admin-ticket-search" placeholder="Buscar por asunto, usuario o ID..." oninput="filterAdminTickets()"
                               class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                    </div>
                    <div class="flex items-center gap-1.5 overflow-x-auto scrollbar-none">
                        <button type="button" onclick="setAdminTicketFilter('all')" data-filter="all" class="admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap">Todos (<?= $countBy['total'] ?>)</button>
                        <button type="button" onclick="setAdminTicketFilter('open')" data-filter="open" class="admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">Abiertos (<?= $countBy['open'] ?>)</button>
                        <button type="button" onclick="setAdminTicketFilter('in_progress')" data-filter="in_progress" class="admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">En Atención (<?= $countBy['in_progress'] ?>)</button>
                        <button type="button" onclick="setAdminTicketFilter('closed')" data-filter="closed" class="admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">Cerrados (<?= $countBy['closed'] ?>)</button>
                    </div>
                </div>
            </div>

            <!-- Tickets Inbox -->
            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm p-3.5 space-y-2" id="admin-tickets-list">
                <?php if (empty($allTickets)): ?>
                <div class="flex flex-col items-center justify-center py-16 px-4 text-center space-y-3">
                    <div class="w-12 h-12 rounded-2xl bg-white/[0.02] border border-white/[0.06] flex items-center justify-center text-text-subtle">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    </div>
                    <p class="text-xs font-semibold text-text-heading">Sin tickets registrados</p>
                </div>
                <?php else: ?>
                <?php foreach ($allTickets as $t):
                    $tid = $t['_id'] ?? '';
                    $tStatus = $t['status'] ?? 'open';
                    $tPriority = $t['priority'] ?? 'medium';
                    $st = $statusCfg[$tStatus] ?? $statusCfg['open'];
                    $pr = $prioCfg[$tPriority] ?? $prioCfg['medium'];
                    $shortId = substr($tid, -6);
                    $dateStr = substr($t['updatedAt'] ?? ($t['createdAt'] ?? ''), 0, 16);
                    $snippet = $t['description'] ?? '';
                    $msgCount = count($t['messages'] ?? []);
                    $isClosed = $tStatus === 'closed';
                ?>
                <div class="admin-ticket-card p-3.5 rounded-xl border border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600 transition-all duration-200"
                     data-status="<?= h($tStatus) ?>" data-search="<?= h(mb_strtolower(($t['subject'] ?? '') . ' ' . ($t['userEmail'] ?? '') . ' ' . $shortId)) ?>">
                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap mb-1.5">
                                <span class="text-[9px] font-mono text-cyan-400 font-medium px-1.5 py-0.5 rounded bg-cyan-950/40 border border-cyan-500/20">#TK-<?= h(strtoupper($shortId)) ?></span>
                                <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                    <span class="w-1 h-1 rounded-full <?= $st['dot'] ?>"></span>
                                    <?= h($st['label']) ?>
                                </span>
                                <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $pr['class'] ?>">
                                    <span class="w-1 h-1 rounded-full <?= $pr['dot'] ?>"></span>
                                    <?= h($pr['label']) ?>
                                </span>
                            </div>
                            <h3 class="text-[13px] font-semibold text-text-heading truncate leading-snug"><?= h($t['subject'] ?? ($t['title'] ?? 'Ticket')) ?></h3>
                            <p class="text-[11px] text-text-subtle truncate mt-1 leading-relaxed"><?= h($snippet ?: 'Sin contenido adicional') ?></p>
                            <div class="flex items-center gap-2 mt-2 text-[10px] text-text-subtle">
                                <span class="font-medium"><?= h($t['userEmail'] ?? '-') ?></span>
                                <span>·</span>
                                <span class="font-mono"><?= h($dateStr) ?></span>
                                <?php if ($msgCount > 0): ?>
                                <span class="inline-flex items-center gap-1 text-text-muted">
                                    <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                    <?= $msgCount ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 flex-shrink-0 w-full sm:w-auto">
                            <form method="POST" class="inline" onsubmit="return confirm('¿Cambiar estado del ticket?')">
                                <input type="hidden" name="ticket_id" value="<?= h($tid) ?>">
                                <input type="hidden" name="new_status" value="<?= $isClosed ? 'open' : 'closed' ?>">
                                <button type="submit" name="ticket_status" value="1" class="px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all flex items-center gap-1.5 <?= $isClosed ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 hover:shadow-emerald-500/10' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 hover:shadow-emerald-500/10' ?> shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <?= $isClosed ? 'Reabrir' : 'Cerrar' ?>
                                </button>
                            </form>
                            <form method="POST" class="w-full sm:w-auto flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                <input type="hidden" name="ticket_id" value="<?= h($tid) ?>">
                                <input type="text" name="response" required placeholder="Escribe una respuesta..." class="input-premium flex-1 min-w-[240px] text-[11px] py-1.5 px-3 rounded-lg">
                                <button type="submit" name="ticket_respond" value="1" class="px-3 py-1.5 rounded-lg text-[11px] font-semibold bg-gradient-to-r from-primary-600 to-cyan-600 hover:from-primary-500 hover:to-cyan-500 text-white transition-all shadow-lg shadow-primary-900/20 flex items-center justify-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                    Responder
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
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
            <!-- ═══ INFORMACIÓN DEL SISTEMA ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Información del Sistema
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-sky-500/10 to-sky-700/5 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-sky-500/15 flex items-center justify-center text-sky-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] text-sky-400 uppercase tracking-wide">Versión</p>
                            <p class="text-[16px] font-bold text-white mt-0.5 font-mono">2.0.0</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-cyan-500/10 to-cyan-700/5 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-cyan-500/15 flex items-center justify-center text-cyan-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2a3 3 0 00-5.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2a3 3 0 015.356-1.857m0 0a3 3 0 10-4.788-3.538 3.001 3.001 0 004.788 3.538z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] text-cyan-400 uppercase tracking-wide">Usuarios</p>
                            <p class="text-[16px] font-bold text-white mt-0.5 font-mono"><?= count($users) ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-emerald-500/10 to-emerald-700/5 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-emerald-500/15 flex items-center justify-center text-emerald-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] text-emerald-400 uppercase tracking-wide">Equipos</p>
                            <p class="text-[16px] font-bold text-white mt-0.5 font-mono"><?= $totalAgents ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme/25 bg-gradient-to-br from-amber-500/10 to-amber-700/5 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-500/15 flex items-center justify-center text-amber-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] text-amber-400 uppercase tracking-wide">Tickets</p>
                            <p class="text-[16px] font-bold text-white mt-0.5 font-mono"><?= count($allTickets) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ═══ Agent Tools Modal ═══ -->
<div id="toolsOverlay" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="w-full max-w-6xl max-h-[95vh] bg-bg-panel/95 border border-border-theme/40 rounded-2xl flex flex-col overflow-hidden shadow-2xl shadow-primary-900/20 ring-1 ring-white/[0.04]">
        <div class="px-6 py-4 border-b border-border-theme/50 flex items-center justify-between bg-gradient-to-r from-bg-panel to-bg-elevated/30">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-600/30 to-indigo-600/20 border border-primary-500/30 flex items-center justify-center text-primary-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-white" id="toolsTitle">-</h3>
                    <p class="text-[10px] text-text-subtle">Control remoto del endpoint</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span id="toolsStatus" class="text-[10px] text-text-subtle"></span>
                <button onclick="closeTools()" class="p-2 rounded-lg hover:bg-white/[0.05] text-text-muted hover:text-white transition-colors border border-border-theme/30 hover:border-white/[0.1]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="px-6 py-3 border-b border-border-theme/50 flex gap-2 flex-wrap bg-bg-elevated/20">
            <button onclick="toolTab('processes')" id="tab-proc" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium bg-primary-500/15 text-primary-400 border border-primary-500/20 transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                Procesos
            </button>
            <button onclick="toolTab('health')" id="tab-health" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                Salud
            </button>
            <button onclick="toolTab('screenshot')" id="tab-screenshot" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Captura
            </button>
            <button onclick="toolTab('shell')" id="tab-shell" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Shell
            </button>
            <button onclick="toolTab('control')" id="tab-control" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                Control
            </button>
            <button onclick="toolTab('forensics')" id="tab-forensics" class="tool-tab px-4 py-2 rounded-lg text-[11px] font-medium text-text-muted hover:bg-white/[0.03] border border-transparent transition-all flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                Forense
            </button>
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

// ── Tickets ──
let _adminTicketFilter = 'all';
function setAdminTicketFilter(status) {
    _adminTicketFilter = status;
    document.querySelectorAll('.admin-ticket-filter').forEach(b => {
        if (b.dataset.filter === status) {
            b.className = 'admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap';
        } else {
            b.className = 'admin-ticket-filter px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap';
        }
    });
    filterAdminTickets();
}

function filterAdminTickets() {
    const q = (document.getElementById('admin-ticket-search')?.value || '').toLowerCase();
    const cards = document.querySelectorAll('.admin-ticket-card');
    let visible = 0;
    cards.forEach(c => {
        const status = c.dataset.status || '';
        const search = (c.dataset.search || '').toLowerCase();
        const matchesStatus = _adminTicketFilter === 'all' || status === _adminTicketFilter || (_adminTicketFilter === 'in_progress' && (status === 'in_progress' || status === 'pending'));
        const matchesSearch = search.includes(q);
        const show = matchesStatus && matchesSearch;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const emptyEl = document.getElementById('admin-tickets-empty');
    if (emptyEl) emptyEl.style.display = visible === 0 ? 'block' : 'none';
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
    const tabMap = { screenshot: 'screenshot', health: 'health', shell: 'shell', control: 'control', forensics: 'forensics', proc: 'proc' };
    document.querySelectorAll('.tool-tab').forEach(b => {
        b.className = 'tool-tab px-3 py-1.5 rounded-lg text-[11px] font-medium transition-all border ';
        if (b.id === 'tab-' + (tabMap[t] || 'proc')) {
            b.className += 'bg-primary-500/15 text-primary-400 border-primary-500/20';
        } else {
            b.className += 'text-text-muted hover:bg-white/[0.03] border-transparent';
        }
    });
    if (t === 'shell') {
        initShell();
        return;
    }
    if (t === 'control') {
        initControlPanel();
        return;
    }
    if (t === 'forensics') {
        initForensicsPanel();
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
        let raw = '';
        if (typeof data === 'string') raw = data;
        else if (data && data.image) raw = data.image;
        else if (data && data.data) raw = data.data;
        let imgSrc = '';
        if (raw) {
            if (raw.startsWith('http')) imgSrc = raw;
            else if (raw.startsWith('data:')) imgSrc = raw;
            else imgSrc = 'data:image/png;base64,' + raw;
        }
        if (imgSrc) {
            el.innerHTML = '<img src="' + imgSrc + '" class="rounded-lg max-w-full mx-auto border border-border-theme" alt="captura">';
            el.querySelector('img').onerror = function() { el.innerHTML = '<p class="text-red-400 text-center py-6">Error al cargar imagen (formato inválido).</p>'; };
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

function doSilentLock(agentId) {
    const msg = prompt('Motivo del bloqueo silencioso (sin sonido):');
    if (msg === null) return;
    agentCmd(agentId, 'lockdown_silent', { message: msg || 'ESTE EQUIPO ESTÁ BLOQUEADO POR SEGURIDAD' });
}

function doSilentTimedLock(agentId) {
    const mins = prompt('Minutos de bloqueo temporal silencioso:');
    if (!mins) return;
    const msg = prompt('Mensaje (opcional):', 'EQUIPO BLOQUEADO TEMPORALMENTE POR SEGURIDAD');
    if (msg === null) return;
    agentCmd(agentId, 'lock_timed_silent', { minutes: parseInt(mins) || 5, message: msg || '' });
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

// ── Enhanced Shell & Remote Control Panel ──
let _shellSessions = [];
let _activeShellId = null;
let _shellNextId = 1;
const MAX_SHELLS = 10;

const SHELL_PRESETS = {
    powershell: { label: 'PowerShell', prompt: 'PS>', color: 'text-blue-400', hint: 'PowerShell 7+ / 5.1' },
    cmd: { label: 'CMD', prompt: 'C:>', color: 'text-amber-400', hint: 'Command Prompt clásico' },
    bash: { label: 'Bash/WSL', prompt: '$', color: 'text-green-400', hint: 'Linux/WSL/Git Bash' },
};

const QUICK_COMMANDS = {
    system: [
        { label: '📋 Procesos', cmd: 'Get-Process | Sort-Object CPU -Descending | Select-Object -First 20 Name,Id,CPU,WS', shell: 'powershell' },
        { label: '💾 Disco', cmd: 'Get-Volume | Format-Table DriveLetter,FileSystemLabel,Size,SizeRemaining', shell: 'powershell' },
        { label: '🧠 RAM', cmd: 'Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory', shell: 'powershell' },
        { label: '🔌 Red', cmd: 'Get-NetAdapter | Format-Table Name,InterfaceDescription,Status,LinkSpeed', shell: 'powershell' },
        { label: '🛡️ Defender', cmd: 'Get-MpComputerStatus', shell: 'powershell' },
        { label: '📦 Servicios', cmd: 'Get-Service | Where-Object {$_.Status -eq "Running"} | Format-Table Name,DisplayName,Status', shell: 'powershell' },
        { label: '🔥 Firewall', cmd: 'Get-NetFirewallProfile | Format-Table Name,Enabled,DefaultInboundAction', shell: 'powershell' },
        { label: '👥 Usuarios', cmd: 'Get-LocalUser | Format-Table Name,Enabled,LastLogon', shell: 'powershell' },
    ],
    security: [
        { label: '🔐 Bloquear equipo', cmd: 'lockdown', shell: 'cmd', isAgentCmd: true },
        { label: '🔇 Bloquear SIN SONIDO', cmd: 'lockdown_silent', shell: 'cmd', isAgentCmd: true },
        { label: '⏱️ Bloqueo temporal (5 min)', cmd: 'lock_timed', shell: 'cmd', isAgentCmd: true, params: { minutes: 5, message: 'Bloqueo temporal desde admin panel' } },
        { label: '🔕 Temporal SIN SONIDO (5 min)', cmd: 'lock_timed_silent', shell: 'cmd', isAgentCmd: true, params: { minutes: 5, message: 'Bloqueo temporal silencioso desde admin panel' } },
        { label: '🔓 Desbloquear', cmd: 'unlock', shell: 'cmd', isAgentCmd: true },
        { label: '🚨 Alarma ON', cmd: 'alarm', shell: 'cmd', isAgentCmd: true },
        { label: '🔇 Alarma OFF', cmd: 'alarm_stop', shell: 'cmd', isAgentCmd: true },
        { label: '🗣️ Hablar mensaje', cmd: 'speak', shell: 'cmd', isAgentCmd: true, prompt: 'Mensaje a reproducir:' },
    ],
    maintenance: [
        { label: '🔄 Reiniciar equipo', cmd: 'power_restart', shell: 'cmd', isAgentCmd: true },
        { label: '⏻ Apagar equipo', cmd: 'power_off', shell: 'cmd', isAgentCmd: true },
        { label: '😴 Suspender', cmd: 'power_suspend', shell: 'cmd', isAgentCmd: true },
        { label: '🧹 Limpiar temp', cmd: 'Remove-Item -Path $env:TEMP\\* -Force -ErrorAction SilentlyContinue', shell: 'powershell' },
        { label: '📋 Event Viewer (últimos 50)', cmd: 'Get-WinEvent -LogName System -MaxEvents 50 | Format-Table TimeCreated,Id,LevelDisplayName,Message -AutoSize', shell: 'powershell' },
        { label: '🔧 Actualizaciones pendientes', cmd: 'Get-WindowsUpdate -MicrosoftUpdate -AcceptAll -Install -IgnoreReboot', shell: 'powershell' },
    ],
    forensics: [
        { label: '📁 Archivos recientes', cmd: 'Get-ChildItem -Path C:\\Users -Recurse -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-1)} | Select-Object FullName,LastWriteTime,Length | Sort-Object LastWriteTime -Desc | Select-Object -First 30', shell: 'powershell' },
        { label: '🔍 Puertos abiertos', cmd: 'Get-NetTCPConnection -State Listen | Format-Table LocalAddress,LocalPort,OwningProcess', shell: 'powershell' },
        { label: '📡 Conexiones activas', cmd: 'Get-NetTCPConnection -State Established | Format-Table LocalAddress,LocalPort,RemoteAddress,RemotePort,OwningProcess', shell: 'powershell' },
        { label: '📂 Archivos abiertos', cmd: 'Get-Process | ForEach-Object { $_.Modules } | Select-Object FileName,ModuleName | Sort-Object FileName -Unique', shell: 'powershell' },
        { label: '🕵️ Scheduled Tasks', cmd: 'Get-ScheduledTask | Where-Object {$_.State -ne "Disabled"} | Format-Table TaskName,State,LastRunTime', shell: 'powershell' },
        { label: '🔑 Registro Run keys', cmd: 'Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run, HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', shell: 'powershell' },
    ],
    network: [
        { label: '🌐 IP pública', cmd: 'Invoke-RestMethod -Uri "https://api.ipify.org" -UseBasicParsing', shell: 'powershell' },
        { label: '📍 Traceroute a 8.8.8.8', cmd: 'tracert 8.8.8.8', shell: 'cmd' },
        { label: '🔍 DNS Flush', cmd: 'Clear-DnsClientCache; ipconfig /flushdns', shell: 'powershell' },
        { label: '📊 Netstat', cmd: 'netstat -ano | findstr :80', shell: 'cmd' },
        { label: '🛡️ Puertos escuchando', cmd: 'Get-NetTCPConnection -State Listen | Select-Object LocalPort,OwningProcess | Sort-Object LocalPort', shell: 'powershell' },
        { label: '📡 WiFi networks', cmd: 'netsh wlan show networks mode=bssid', shell: 'cmd' },
        { label: '🔌 ARP table', cmd: 'arp -a', shell: 'cmd' },
        { label: '🌐 Routing', cmd: 'Get-NetRoute | Format-Table DestinationPrefix,NextHop,InterfaceAlias,RouteMetric', shell: 'powershell' },
    ],
    users: [
        { label: '👥 Local users', cmd: 'Get-LocalUser | Select-Object Name,Enabled,LastLogon,PasswordLastSet', shell: 'powershell' },
        { label: '👥 Logged users', cmd: 'query user', shell: 'cmd' },
        { label: '👤 Admin group', cmd: 'Get-LocalGroupMember -Group "Administrators"', shell: 'powershell' },
        { label: '🔑 Password policy', cmd: 'net accounts', shell: 'cmd' },
        { label: '🔐 RDP sessions', cmd: 'qwinsta', shell: 'cmd' },
    ],
    usb: [
        { label: '🔌 USB devices', cmd: 'Get-PnpDevice -Class USB | Select-Object Name,Status,InstanceId', shell: 'powershell' },
        { label: '💾 Disks', cmd: 'Get-Disk | Select-Object Number,Model,Size,HealthStatus', shell: 'powershell' },
        { label: '📀 CD/DVD drives', cmd: 'Get-CimInstance -ClassName Win32_CDROMDrive | Select-Object Name,Drive', shell: 'powershell' },
        { label: '🔒 BitLocker status', cmd: 'Get-BitLockerVolume | Select-Object MountPoint,VolumeStatus,ProtectionStatus', shell: 'powershell' },
    ],
    persistence: [
        { label: '🔑 Run keys', cmd: 'Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run, HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', shell: 'powershell' },
        { label: '🕵️ Scheduled tasks', cmd: 'Get-ScheduledTask | Where-Object {$_.State -ne "Disabled"} | Select-Object TaskName,State,LastRunTime', shell: 'powershell' },
        { label: '📂 Startup folders', cmd: 'Get-ChildItem "$env:ProgramData\\Microsoft\\Windows\\Start Menu\\Programs\\Startup","$env:APPDATA\\Microsoft\\Windows\\Start Menu\\Programs\\Startup"', shell: 'powershell' },
        { label: '🛠️ WMI subscriptions', cmd: 'Get-WmiObject -Class __EventFilter -Namespace "root\\subscription" | Select-Object Name,Query', shell: 'powershell' },
        { label: '🐕 Services (auto)', cmd: 'Get-Service | Where-Object {$_.StartType -eq "Automatic"} | Select-Object Name,DisplayName,Status', shell: 'powershell' },
    ],
    processes: [
        { label: '🔫 Kill by name', cmd: 'taskkill /F /IM notepad.exe', shell: 'cmd', prompt: 'Nombre del proceso a matar (ej: notepad.exe):' },
        { label: '📊 Top CPU', cmd: 'Get-Process | Sort-Object CPU -Descending | Select-Object -First 20 Name,Id,CPU,WS', shell: 'powershell' },
        { label: '🧠 Top memory', cmd: 'Get-Process | Sort-Object WS -Descending | Select-Object -First 20 Name,Id,WS,CPU', shell: 'powershell' },
        { label: '🕵️ Process tree', cmd: 'wmic process get Name,ProcessId,ParentProcessId,CommandLine', shell: 'cmd' },
    ],
    logs: [
        { label: '📋 System errors', cmd: 'Get-WinEvent -FilterHashtable @{LogName="System"; Level=1,2} -MaxEvents 20 | Format-Table TimeCreated,Id,LevelDisplayName,Message -AutoSize', shell: 'powershell' },
        { label: '🔐 Security logins', cmd: 'Get-WinEvent -FilterHashtable @{LogName="Security"; ID=4624,4625,4634} -MaxEvents 20 | Format-Table TimeCreated,Id,Message -AutoSize', shell: 'powershell' },
        { label: '⚠️ Application errors', cmd: 'Get-WinEvent -LogName Application -MaxEvents 20 | Format-Table TimeCreated,Id,LevelDisplayName,Message -AutoSize', shell: 'powershell' },
    ],
    hardware: [
        { label: '💻 System info', cmd: 'Get-ComputerInfo | Select-Object WindowsProductName,WindowsVersion,TotalPhysicalMemory,CsProcessors', shell: 'powershell' },
        { label: '🌡️ CPU info', cmd: 'Get-CimInstance Win32_Processor | Select-Object Name,NumberOfCores,MaxClockSpeed,LoadPercentage', shell: 'powershell' },
        { label: '🎮 GPU', cmd: 'Get-CimInstance Win32_VideoController | Select-Object Name,AdapterRAM,VideoModeDescription', shell: 'powershell' },
        { label: '🖥️ Monitors', cmd: 'Get-CimInstance Win32_DesktopMonitor | Select-Object Name,ScreenWidth,ScreenHeight', shell: 'powershell' },
    ],
    software: [
        { label: '📦 Installed programs', cmd: 'Get-ItemProperty HKLM:\\Software\\Wow6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\* | Select-Object DisplayName,DisplayVersion,Publisher | Sort-Object DisplayName', shell: 'powershell' },
        { label: '🔥 Hotfixes', cmd: 'Get-HotFix | Select-Object HotFixID,InstalledOn,Description', shell: 'powershell' },
        { label: '🧊 Environment vars', cmd: 'Get-ChildItem Env: | Format-Table Name,Value', shell: 'powershell' },
    ],
    ioc: [
        { label: '🐕 Find mimikatz', cmd: 'Get-ChildItem -Path C:\\ -Filter *mimikatz* -Recurse -ErrorAction SilentlyContinue', shell: 'powershell' },
        { label: '🔍 Suspicious exes', cmd: 'Get-ChildItem -Path C:\\Users -Recurse -Include *.exe -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-7)}', shell: 'powershell' },
        { label: '📡 Listening + PID', cmd: 'Get-NetTCPConnection -State Listen | Select-Object LocalAddress,LocalPort,OwningProcess', shell: 'powershell' },
    ],
};

// ── Control Panel (Device Control) ──
function initControlPanel() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = `
        <div class="space-y-6">
            <!-- Security Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-red-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Seguridad y Bloqueo
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <button onclick="controlAction('lockdown')" class="control-btn p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 hover:border-red-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔒</div>
                        <div class="font-medium text-red-400">Bloqueo Total</div>
                        <div class="text-[10px] text-text-subtle">Con sonido + TTS</div>
                    </button>
                    <button onclick="controlAction('lockdown_silent')" class="control-btn p-4 bg-rose-500/10 border border-rose-500/20 rounded-lg hover:bg-rose-500/20 hover:border-rose-500/40 transition-all text-left relative">
                        <div class="text-lg mb-1">🔇</div>
                        <div class="font-medium text-rose-300">Bloqueo Silencioso</div>
                        <div class="text-[10px] text-text-subtle">Overlay sin sonido</div>
                        <span class="absolute top-2 right-2 w-2 h-2 bg-rose-400 rounded-full animate-pulse"></span>
                    </button>
                    <button onclick="controlAction('lock_timed')" class="control-btn p-4 bg-amber-500/10 border border-amber-500/20 rounded-lg hover:bg-amber-500/20 hover:border-amber-500/40 transition-all text-left">
                        <div class="text-lg mb-1">⏱️</div>
                        <div class="font-medium text-amber-400">Bloqueo Temporal</div>
                        <div class="text-[10px] text-text-subtle">Con sonido</div>
                    </button>
                    <button onclick="controlAction('lock_timed_silent')" class="control-btn p-4 bg-orange-500/10 border border-orange-500/20 rounded-lg hover:bg-orange-500/20 hover:border-orange-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔕</div>
                        <div class="font-medium text-orange-300">Temporal Silencioso</div>
                        <div class="text-[10px] text-text-subtle">Minutos sin sonido</div>
                    </button>
                    <button onclick="controlAction('unlock')" class="control-btn p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 hover:border-emerald-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔓</div>
                        <div class="font-medium text-emerald-400">Desbloquear</div>
                        <div class="text-[10px] text-text-subtle">Quita bloqueo actual</div>
                    </button>
                </div>
            </div>

            <!-- Alarm Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-orange-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    Alarmas y Alertas
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <button onclick="controlAction('alarm')" class="control-btn p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 hover:border-red-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🚨</div>
                        <div class="font-medium text-red-400">Alarma INTRUSO</div>
                        <div class="text-[10px] text-text-subtle">Volumen máximo</div>
                    </button>
                    <button onclick="controlAction('alarm_stop')" class="control-btn p-4 bg-gray-500/10 border border-gray-500/20 rounded-lg hover:bg-gray-500/20 hover:border-gray-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔇</div>
                        <div class="font-medium text-gray-400">Detener Alarma</div>
                        <div class="text-[10px] text-text-subtle">Silenciar</div>
                    </button>
                </div>
            </div>

            <!-- Power Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-purple-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Control de Energía
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <button onclick="controlAction('power_restart')" class="control-btn p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg hover:bg-blue-500/20 hover:border-blue-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔄</div>
                        <div class="font-medium text-blue-400">Reiniciar</div>
                        <div class="text-[10px] text-text-subtle">En 15 segundos</div>
                    </button>
                    <button onclick="controlAction('power_off')" class="control-btn p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 hover:border-red-500/40 transition-all text-left">
                        <div class="text-lg mb-1">⏻</div>
                        <div class="font-medium text-red-400">Apagar</div>
                        <div class="text-[10px] text-text-subtle">En 15 segundos</div>
                    </button>
                    <button onclick="controlAction('power_suspend')" class="control-btn p-4 bg-amber-500/10 border border-amber-500/20 rounded-lg hover:bg-amber-500/20 hover:border-amber-500/40 transition-all text-left">
                        <div class="text-lg mb-1">😴</div>
                        <div class="font-medium text-amber-400">Suspender</div>
                        <div class="text-[10px] text-text-subtle">Sleep/Hibernate</div>
                    </button>
                </div>
            </div>

            <!-- Network/Firewall Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-cyan-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Red y Firewall
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <button onclick="controlAction('block_ip')" class="control-btn p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 hover:border-red-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🚫</div>
                        <div class="font-medium text-red-400">Bloquear IP</div>
                        <div class="text-[10px] text-text-subtle">Firewall rule</div>
                    </button>
                    <button onclick="controlAction('unblock_ip')" class="control-btn p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 hover:border-emerald-500/40 transition-all text-left">
                        <div class="text-lg mb-1">✅</div>
                        <div class="font-medium text-emerald-400">Desbloquear IP</div>
                        <div class="text-[10px] text-text-subtle">Quitar regla</div>
                    </button>
                    <button onclick="controlAction('apply_firewall_rule')" class="control-btn p-4 bg-cyan-500/10 border border-cyan-500/20 rounded-lg hover:bg-cyan-500/20 hover:border-cyan-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🛡️</div>
                        <div class="font-medium text-cyan-400">Regla Firewall</div>
                        <div class="text-[10px] text-text-subtle">Personalizada</div>
                    </button>
                    <button onclick="controlAction('block_user')" class="control-btn p-4 bg-violet-500/10 border border-violet-500/20 rounded-lg hover:bg-violet-500/20 hover:border-violet-500/40 transition-all text-left">
                        <div class="text-lg mb-1">👤</div>
                        <div class="font-medium text-violet-400">Bloquear Usuario</div>
                        <div class="text-[10px] text-text-subtle">Login local</div>
                    </button>
                </div>
            </div>

            <!-- Quick Info Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-sky-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Información Rápida
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <button onclick="requestDataAndShow('processes')" class="control-btn p-4 bg-blue-500/10 border border-blue-500/20 rounded-lg hover:bg-blue-500/20 hover:border-blue-500/40 transition-all text-left">
                        <div class="text-lg mb-1">📋</div>
                        <div class="font-medium text-blue-400">Procesos</div>
                        <div class="text-[10px] text-text-subtle">Lista completa</div>
                    </button>
                    <button onclick="requestDataAndShow('health')" class="control-btn p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 hover:border-emerald-500/40 transition-all text-left">
                        <div class="text-lg mb-1">💚</div>
                        <div class="font-medium text-emerald-400">Salud Sistema</div>
                        <div class="text-[10px] text-text-subtle">CPU/RAM/Disco</div>
                    </button>
                    <button onclick="requestDataAndShow('screenshot')" class="control-btn p-4 bg-purple-500/10 border border-purple-500/20 rounded-lg hover:bg-purple-500/20 hover:border-purple-500/40 transition-all text-left">
                        <div class="text-lg mb-1">📸</div>
                        <div class="font-medium text-purple-400">Captura Pantalla</div>
                        <div class="text-[10px] text-text-subtle">Screenshot actual</div>
                    </button>
                    <button onclick="requestDataAndShow('defender')" class="control-btn p-4 bg-orange-500/10 border border-orange-500/20 rounded-lg hover:bg-orange-500/20 hover:border-orange-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🛡️</div>
                        <div class="font-medium text-orange-400">Defender Status</div>
                        <div class="text-[10px] text-text-subtle">Antivirus state</div>
                    </button>
                </div>
            </div>

            <!-- Advanced Actions -->
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-pink-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    Control Avanzado
                </h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <button onclick="controlAction('kill_process')" class="control-btn p-4 bg-rose-500/10 border border-rose-500/20 rounded-lg hover:bg-rose-500/20 hover:border-rose-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔫</div>
                        <div class="font-medium text-rose-400">Matar proceso</div>
                        <div class="text-[10px] text-text-subtle">Por PID</div>
                    </button>
                    <button onclick="controlAction('uninstall')" class="control-btn p-4 bg-red-700/10 border border-red-700/20 rounded-lg hover:bg-red-700/20 hover:border-red-700/40 transition-all text-left">
                        <div class="text-lg mb-1">🗑️</div>
                        <div class="font-medium text-red-400">Desinstalar agente</div>
                        <div class="text-[10px] text-text-subtle">Remover endpoint</div>
                    </button>
                    <button onclick="controlAction('unblock_user')" class="control-btn p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 hover:border-emerald-500/40 transition-all text-left">
                        <div class="text-lg mb-1">✅</div>
                        <div class="font-medium text-emerald-400">Desbloquear usuario</div>
                        <div class="text-[10px] text-text-subtle">Login local</div>
                    </button>
                    <button onclick="controlAction('restart')" class="control-btn p-4 bg-amber-500/10 border border-amber-500/20 rounded-lg hover:bg-amber-500/20 hover:border-amber-500/40 transition-all text-left">
                        <div class="text-lg mb-1">🔄</div>
                        <div class="font-medium text-amber-400">Reiniciar agente</div>
                        <div class="text-[10px] text-text-subtle">Servicio agente</div>
                    </button>
                </div>
            </div>
        </div>
    `;
    setToolStatus('Panel de control listo');
}

async function controlAction(action) {
    let params = {};
    let confirmMsg = '';

    switch (action) {
        case 'lockdown':
            const reason = prompt('Motivo del bloqueo (opcional):');
            if (reason === null) return;
            params = { message: reason || 'Bloqueo desde panel de administración' };
            confirmMsg = '¿BLOQUEAR totalmente el equipo? (Overlay + sonido/TTS, teclado/mouse deshabilitados)';
            break;
        case 'lockdown_silent':
            const reasonSilent = prompt('Motivo del bloqueo silencioso (opcional):');
            if (reasonSilent === null) return;
            params = { message: reasonSilent || 'Bloqueo silencioso desde panel de administración' };
            confirmMsg = '¿BLOQUEAR el equipo SIN SONIDO? (Overlay visual, teclado/mouse deshabilitados)';
            break;
        case 'lock_timed':
            const mins = prompt('Minutos de bloqueo:', '5');
            if (!mins) return;
            const msg = prompt('Mensaje (opcional):', 'Bloqueo temporal desde admin panel');
            params = { minutes: parseInt(mins) || 5, message: msg || '' };
            confirmMsg = `Bloquear por ${mins} minutos?`;
            break;
        case 'lock_timed_silent':
            const minsSilent = prompt('Minutos de bloqueo temporal silencioso:', '5');
            if (!minsSilent) return;
            const msgSilent = prompt('Mensaje (opcional):', 'Bloqueo temporal silencioso desde admin panel');
            params = { minutes: parseInt(minsSilent) || 5, message: msgSilent || '' };
            confirmMsg = `Bloquear silenciosamente por ${minsSilent} minutos?`;
            break;
        case 'unlock':
            confirmMsg = '¿Desbloquear el equipo?';
            break;
        case 'alarm':
            confirmMsg = '¿ACTIVAR alarma de intruso a VOLUMEN MÁXIMO?';
            break;
        case 'alarm_stop':
            confirmMsg = '¿Detener alarma?';
            break;
        case 'power_restart':
            confirmMsg = '¿REINICIAR el equipo en 15 segundos?';
            break;
        case 'power_off':
            confirmMsg = '¿APAGAR el equipo en 15 segundos?';
            break;
        case 'power_suspend':
            confirmMsg = '¿Suspender el equipo?';
            break;
        case 'speak':
            const text = prompt('Mensaje a reproducir por TTS:');
            if (!text) return;
            params = { text, message: text };
            break;
        case 'block_ip':
            const ip = prompt('IP a bloquear (ej: 192.168.1.100):');
            if (!ip) return;
            params = { ip };
            confirmMsg = `Bloquear IP ${ip} en firewall?`;
            break;
        case 'unblock_ip':
            const ip2 = prompt('IP a desbloquear:');
            if (!ip2) return;
            params = { ip: ip2 };
            confirmMsg = `Desbloquear IP ${ip2}?`;
            break;
        case 'apply_firewall_rule':
            const ruleAction = prompt('Acción (allow/block):', 'block');
            const protocol = prompt('Protocolo (TCP/UDP):', 'TCP');
            const port = prompt('Puerto (ej: 80, 443, 3389):');
            const direction = prompt('Dirección (inbound/outbound):', 'inbound');
            if (!port) return;
            params = { action: ruleAction || 'block', protocol: protocol || 'TCP', port, direction: direction || 'inbound' };
            confirmMsg = `Aplicar regla firewall: ${ruleAction} ${protocol} puerto ${port} ${direction}?`;
            break;
        case 'block_user':
            const user = prompt('Nombre de usuario a bloquear:');
            if (!user) return;
            params = { username: user };
            confirmMsg = `Bloquear login local del usuario ${user}?`;
            break;
        case 'unblock_user':
            const userUnblock = prompt('Nombre de usuario a desbloquear:');
            if (!userUnblock) return;
            params = { username: userUnblock };
            confirmMsg = `Desbloquear login local del usuario ${userUnblock}?`;
            break;
        case 'kill_process':
            const pid = prompt('PID del proceso a matar:');
            if (!pid || isNaN(parseInt(pid))) return;
            params = { pid: pid };
            confirmMsg = `Matar proceso PID ${pid}?`;
            break;
        case 'uninstall':
            confirmMsg = '¿DESINSTALAR el agente SecureLab de este equipo?';
            break;
        case 'restart':
            confirmMsg = '¿Reiniciar el servicio del agente?';
            break;
        default:
            return;
    }

    if (confirmMsg && !confirm(confirmMsg)) return;

    setToolStatus(`Ejecutando ${action}...`);
    const res = await agentCmd(_agentId, action, params);
    if (res.success) {
        setToolStatus('✓ ' + (res.result || 'Comando ejecutado'));
        // Show result in a toast-like message
        showToast(res.result || 'Comando ejecutado correctamente', 'success');
    } else {
        setToolStatus('✗ ' + (res.error || 'Error'));
        showToast(res.error || 'Error ejecutando comando', 'error');
    }
}

function requestDataAndShow(type) {
    setToolStatus('Solicitando ' + type + '...');
    reqData(type);
}

// ── Forensics Panel ──
function initForensicsPanel() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = `
        <div class="space-y-6">
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-amber-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Acciones Forenses Rápidas
                </h4>
                <p class="text-[11px] text-text-subtle mb-4">Estos comandos se ejecutan via Shell (PowerShell) en el equipo remoto.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2" id="forensics-grid"></div>
            </div>

            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-pink-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.312.37-2.37.94-.632 1.543-.826 2.37-2.37.94-.632 1.543-.826 2.37-2.37zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Análisis de Compromiso (IOC)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2" id="ioc-grid"></div>
            </div>

            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-sky-400 mb-4 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Persistencia y Autostart
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2" id="persist-grid"></div>
            </div>
        </div>
    `;

    // Forensics quick commands
    const forensicsCmds = [
        { label: '📁 Archivos modificados (24h)', cmd: 'Get-ChildItem -Path C:\\Users -Recurse -ErrorAction SilentlyContinue | Where-Object {$_.LastWriteTime -gt (Get-Date).AddDays(-1)} | Select-Object FullName,LastWriteTime,Length | Sort-Object LastWriteTime -Desc | Select-Object -First 30', desc: 'Busca archivos recientes en perfiles de usuario' },
        { label: '🔍 Puertos escuchando', cmd: 'Get-NetTCPConnection -State Listen | Select-Object LocalAddress,LocalPort,OwningProcess | Sort-Object LocalPort', desc: 'Lista puertos abiertos y proceso propietario' },
        { label: '📡 Conexiones establecidas', cmd: 'Get-NetTCPConnection -State Established | Select-Object LocalAddress,LocalPort,RemoteAddress,RemotePort,OwningProcess | Sort-Object RemoteAddress', desc: 'Conexiones activas de red' },
        { label: '📂 Archivos abiertos por procesos', cmd: 'Get-Process | ForEach-Object { $_.Modules } | Select-Object FileName,ModuleName | Sort-Object FileName -Unique', desc: 'DLLs y ejecutables cargados en memoria' },
        { label: '🕵️ Tareas programadas', cmd: 'Get-ScheduledTask | Where-Object {$_.State -ne "Disabled"} | Select-Object TaskName,State,LastRunTime,NextRunTime,Actions | Format-List', desc: 'Tareas de Windows activas' },
        { label: '🔑 Run Keys (Registro)', cmd: 'Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run, HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run, HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\RunOnce, HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\RunOnce', desc: 'Auto-start del registro' },
        { label: '👥 Usuarios locales', cmd: 'Get-LocalUser | Select-Object Name,Enabled,LastLogon,PasswordExpires,PasswordRequired,UserMayChangePassword | Format-Table', desc: 'Cuentas locales del sistema' },
        { label: '🔐 Sesiones activas', cmd: 'query user', shell: 'cmd', desc: 'Usuarios logueados actualmente' },
        { label: '📋 Eventos seguridad (últimos 50)', cmd: 'Get-WinEvent -LogName Security -MaxEvents 50 | Select-Object TimeCreated,Id,LevelDisplayName,Message | Format-Table -AutoSize', desc: 'Auditoría de seguridad reciente' },
    ];

    const iocCmds = [
        { label: '🔍 Buscar Mimikatz', cmd: 'Get-ChildItem -Path C:\\ -Recurse -ErrorAction SilentlyContinue -Filter "*mimikatz*" | Select-Object FullName,LastWriteTime,Length', desc: 'Busca herramientas de credential dumping' },
        { label: '🔍 Buscar herramientas hacking', cmd: 'Get-ChildItem -Path C:\\ -Recurse -ErrorAction SilentlyContinue -Filter "*procdump*", "*psexec*", "*wmiexec*", "*smbexec*", "*crackmapexec*", "*bloodhound*", "*sharphound*", "*certify*", "*rubeus*" | Select-Object FullName,LastWriteTime', desc: 'Herramientas comunes de post-exploitation' },
        { label: '🔍 Scripts sospechosos', cmd: 'Get-ChildItem -Path C:\\Users -Recurse -ErrorAction SilentlyContinue -Include "*.ps1","*.bat","*.cmd","*.vbs","*.js" | Where-Object {$_.Length -gt 1KB -and $_.LastWriteTime -gt (Get-Date).AddDays(-7)} | Select-Object FullName,LastWriteTime,Length', desc: 'Scripts recientes en perfiles' },
        { label: '🔍 Procesos sin firma', cmd: 'Get-Process | Where-Object {$_.Path -and (Get-AuthenticodeSignature $_.Path).Status -ne "Valid"} | Select-Object Name,Id,Path,CPU | Sort-Object CPU -Desc', desc: 'Ejecutables sin firma digital válida' },
        { label: '🔍 Conexiones externas raras', cmd: 'Get-NetTCPConnection -State Established | Where-Object {$_.RemoteAddress -notmatch "^(127\\.|10\\.|192\\.168\\.|172\\.(1[6-9]|2[0-9]|3[0-1])\\.)"} | Select-Object LocalPort,RemoteAddress,RemotePort,OwningProcess | Sort-Object RemoteAddress', desc: 'Conexiones a IPs públicas no RFC1918' },
    ];

    const persistCmds = [
        { label: '📋 Servicios sospechosos', cmd: 'Get-Service | Where-Object {$_.StartType -eq "Automatic" -and $_.Status -eq "Running"} | Select-Object Name,DisplayName,StartType,Status,ServiceName | Format-Table', desc: 'Servicios de inicio automático' },
        { label: '📋 Drivers cargados', cmd: 'Get-WmiObject Win32_SystemDriver | Where-Object {$_.StartMode -eq "Auto"} | Select-Object Name,DisplayName,PathName,StartMode,State | Format-Table', desc: 'Drivers de kernel en auto-start' },
        { label: '📋 WMI Event Subscriptions', cmd: 'Get-WmiObject -Namespace "root\\subscription" -Class __EventFilter | Select-Object Name,Query,EventNamespace', desc: 'Suscripciones WMI persistentes' },
        { label: '📋 Browser Helper Objects', cmd: 'Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Browser Helper Objects\\* | Select-Object *', desc: 'BHOs de Internet Explorer/Edge' },
        { label: '📋 Shell Extensions', cmd: 'Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Shell Extensions\\Approved\\* | Select-Object *', desc: 'Extensiones de shell registradas' },
    ];

    const grid = document.getElementById('forensics-grid');
    grid.innerHTML = forensicsCmds.map(c => `
        <button onclick="runForensicsCmd('${escH(c.cmd)}', '${c.shell || 'powershell'}')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-primary/10 hover:border-primary/30 transition-all text-left">
            <div class="font-medium text-amber-400">${c.label}</div>
            <div class="text-[10px] text-text-subtle mt-1">${c.desc}</div>
        </button>
    `).join('');

    const iocGrid = document.getElementById('ioc-grid');
    iocGrid.innerHTML = iocCmds.map(c => `
        <button onclick="runForensicsCmd('${escH(c.cmd)}', '${c.shell || 'powershell'}')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-red-500/10 hover:border-red-500/30 transition-all text-left">
            <div class="font-medium text-red-400">${c.label}</div>
            <div class="text-[10px] text-text-subtle mt-1">${c.desc}</div>
        </button>
    `).join('');

    const persistGrid = document.getElementById('persist-grid');
    persistGrid.innerHTML = persistCmds.map(c => `
        <button onclick="runForensicsCmd('${escH(c.cmd)}', '${c.shell || 'powershell'}')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-pink-500/10 hover:border-pink-500/30 transition-all text-left">
            <div class="font-medium text-pink-400">${c.label}</div>
            <div class="text-[10px] text-text-subtle mt-1">${c.desc}</div>
        </button>
    `).join('');

    setToolStatus('Panel forense listo');
}

async function runForensicsCmd(cmd, shell) {
    if (!_shellSessions.length) createShellSession(shell);
    const session = getActiveSession();
    if (session.shell !== shell) {
        session.shell = shell;
        session.history = [];
        session.historyIdx = -1;
        renderShellPanel();
    }
    const inp = document.getElementById('shell-input-' + _activeShellId);
    if (inp) {
        inp.value = cmd;
        inp.focus();
        shellExec(_activeShellId);
    }
}

function showToast(msg, type) {
    const toast = document.createElement('div');
    toast.className = `fixed bottom-6 right-6 z-50 px-4 py-3 rounded-lg text-sm font-medium shadow-lg animate-slide-up ${
        type === 'success' ? 'bg-emerald-500/90 text-white border border-emerald-500/30' :
        'bg-red-500/90 text-white border border-red-500/30'
    }`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.add('animate-fade-out'); setTimeout(() => toast.remove(), 300); }, 4000);
}

function initShell() {
    if (!_shellSessions.length) createShellSession('powershell');
    renderShellPanel();
    setToolStatus('');
    bindShellEvents();
}

function getActiveSession() {
    return _shellSessions.find(s => s.id === _activeShellId) || _shellSessions[0] || null;
}

function createShellSession(shell, label) {
    if (_shellSessions.length >= MAX_SHELLS) {
        showToast('Máximo ' + MAX_SHELLS + ' shells permitidas', 'error');
        return null;
    }
    const id = _shellNextId++;
    const name = label || 'Shell ' + id;
    _shellSessions.push({
        id,
        name,
        shell: shell || 'powershell',
        history: [],
        historyIdx: -1,
        output: [],
        cmdId: null,
        pollTimer: null,
        draft: ''
    });
    _activeShellId = id;
    return id;
}

function closeShellSession(id) {
    const idx = _shellSessions.findIndex(s => s.id === id);
    if (idx < 0) return;
    const s = _shellSessions[idx];
    if (s.pollTimer) clearInterval(s.pollTimer);
    _shellSessions.splice(idx, 1);
    if (_shellSessions.length === 0) {
        createShellSession('powershell');
    } else {
        _activeShellId = _shellSessions[Math.min(idx, _shellSessions.length - 1)].id;
    }
    renderShellPanel();
    bindShellEvents();
}

function switchShell(id) {
    const session = _shellSessions.find(s => s.id === id);
    if (!session) return;
    _activeShellId = id;
    renderShellTabs();
    renderShellSessions();
    updateShellToolbar();
}

function renameShell(id) {
    const s = _shellSessions.find(s => s.id === id);
    if (!s) return;
    const newName = prompt('Nombre de la sesión:', s.name);
    if (newName !== null && newName.trim() !== '') {
        s.name = newName.trim().substring(0, 30);
        renderShellTabs();
    }
}

function renderShellPanel() {
    const el = document.getElementById('toolsContent');
    if (!el) return;
    el.innerHTML = `
        <div id="shell-box" class="rounded-xl border border-border-theme/40 bg-[#0a0e14] p-0 min-h-[450px] flex flex-col">
            <!-- Session tabs -->
            <div class="border-b border-border-theme/40 p-2 bg-[#0d131a] flex items-center gap-1 overflow-x-auto scrollbar-custom" id="shell-tabs"></div>
            <!-- Toolbar -->
            <div class="border-b border-border-theme/40 p-3 bg-[#0d131a] flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-text-subtle uppercase tracking-wide">Tipo:</span>
                    <select id="shell-type-selector" class="bg-[#1a1f2e] border border-border-theme/30 rounded px-2 py-1 text-[11px] text-white focus:outline-none focus:border-primary">
                        ${Object.entries(SHELL_PRESETS).map(([k,v]) => `<option value="${k}">${v.label}</option>`).join('')}
                    </select>
                </div>
                <div class="w-px h-6 bg-border-theme/30 mx-1"></div>
                <div class="flex items-center gap-1" id="quick-tabs">
                    ${Object.keys(QUICK_COMMANDS).map((k,i) => `<button data-tab="${k}" class="quick-tab px-2 py-1 text-[10px] rounded ${i===0?'bg-primary/20 text-primary':'text-text-subtle hover:text-white'}">${k.charAt(0).toUpperCase()+k.slice(1)}</button>`).join('')}
                </div>
                <div class="flex-1"></div>
                <div class="flex items-center gap-2">
                    <button id="shell-new" class="px-3 py-1 text-[10px] bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 rounded hover:bg-emerald-500/30 transition-colors" title="Nueva shell">+ Nuevo</button>
                    <button id="shell-rename" class="px-3 py-1 text-[10px] bg-amber-500/20 border border-amber-500/30 text-amber-400 rounded hover:bg-amber-500/30 transition-colors">Renombrar</button>
                    <button id="shell-clear" class="px-3 py-1 text-[10px] bg-red-500/20 border border-red-500/30 text-red-400 rounded hover:bg-red-500/30 transition-colors">Limpiar</button>
                    <button id="shell-export" class="px-3 py-1 text-[10px] bg-sky-500/20 border border-sky-500/30 text-sky-400 rounded hover:bg-sky-500/30 transition-colors">Exportar</button>
                </div>
            </div>

            <!-- Quick Commands Panel -->
            <div id="quick-panel" class="border-b border-border-theme/40 p-3 bg-[#0d131a] max-h-48 overflow-y-auto hidden">
                <div id="quick-commands-grid" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2"></div>
            </div>

            <!-- Sessions container -->
            <div id="shell-sessions" class="flex-1 overflow-y-auto p-0 min-h-[280px] max-h-[480px] scrollbar-custom relative"></div>
        </div>
    `;
    renderShellTabs();
    renderShellSessions();
    renderQuickCommands(Object.keys(QUICK_COMMANDS)[0]);
    updateShellToolbar();
}

function renderShellTabs() {
    const tabs = document.getElementById('shell-tabs');
    if (!tabs) return;
    tabs.innerHTML = _shellSessions.map(s => {
        const active = s.id === _activeShellId;
        return `
            <button data-id="${s.id}" class="shell-tab flex items-center gap-2 px-3 py-1.5 text-[11px] rounded-lg border transition-all whitespace-nowrap ${active ? 'bg-primary/20 border-primary/40 text-primary' : 'bg-[#1a1f2e] border-border-theme/30 text-text-subtle hover:text-white hover:bg-white/[0.04]'}">
                <span class="shell-tab-name">${escH(s.name)}</span>
                <span class="text-[9px] opacity-70">${s.shell}</span>
                ${_shellSessions.length > 1 ? `<span class="shell-close ml-1 hover:text-red-400" data-close="${s.id}" title="Cerrar">×</span>` : ''}
            </button>
        `;
    }).join('');

    tabs.querySelectorAll('.shell-tab').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (e.target.dataset.close) {
                closeShellSession(parseInt(e.target.dataset.close));
            } else {
                switchShell(parseInt(btn.dataset.id));
            }
        });
    });
}

function renderShellSessions() {
    const container = document.getElementById('shell-sessions');
    if (!container) return;
    container.innerHTML = _shellSessions.map(s => {
        const preset = SHELL_PRESETS[s.shell];
        const isActive = s.id === _activeShellId;
        return `
            <div class="shell-session absolute inset-0 flex flex-col ${isActive ? '' : 'hidden'}" data-session="${s.id}">
                <div id="shell-output-${s.id}" class="flex-1 overflow-y-auto p-4 font-mono text-[11px] text-emerald-400 space-y-1 scrollbar-custom">
                    ${s.output.map(o => renderOutputLine(o, s.shell)).join('')}
                </div>
                <div class="border-t border-border-theme/40 bg-[#0d131a] p-3">
                    <div class="flex items-center gap-2">
                        <span class="font-mono text-[11px] select-none ${preset.color}">${preset.prompt}</span>
                        <input id="shell-input-${s.id}" type="text" value="${escH(s.draft || '')}" placeholder="Escribe un comando..." class="flex-1 bg-transparent border-0 text-[12px] text-white font-mono focus:outline-none placeholder-text-subtle" autocomplete="off" spellcheck="false">
                        <span class="text-[10px] text-text-subtle font-mono">~</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    if (_activeShellId) {
        const inp = document.getElementById('shell-input-' + _activeShellId);
        if (inp) inp.focus();
    }
    _shellSessions.forEach(s => {
        const out = document.getElementById('shell-output-' + s.id);
        if (out) out.scrollTop = out.scrollHeight;
    });
}

function renderOutputLine(o, shell) {
    const preset = SHELL_PRESETS[shell];
    if (o.type === 'cmd') return `<p class="text-emerald-400"><span class="${preset.color}">${escH(preset.prompt)}</span> ${escH(o.text)}</p>`;
    if (o.type === 'info') return `<p class="text-text-subtle">${escH(o.text)}</p>`;
    if (o.type === 'loading') return `<p class="text-text-subtle animate-pulse" id="shell-loading-${o.id || 0}">${escH(o.text)}</p>`;
    if (o.type === 'error') return `<pre class="text-red-400 whitespace-pre-wrap break-all text-[11px] leading-relaxed">${escH(o.text)}</pre>`;
    if (o.type === 'success') return `<pre class="text-emerald-400 whitespace-pre-wrap break-all text-[11px] leading-relaxed">${escH(o.text)}</pre>`;
    if (o.type === 'suggest') return `<p class="text-sky-400">${escH(o.text)}</p>`;
    return `<pre class="text-emerald-400 whitespace-pre-wrap break-all text-[11px] leading-relaxed">${escH(o.text)}</pre>`;
}

function updateShellToolbar() {
    const session = getActiveSession();
    if (!session) return;
    const sel = document.getElementById('shell-type-selector');
    if (sel) sel.value = session.shell;
}

function appendToSession(sid, text, type) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s) return;
    s.output.push({ type, text });
    const out = document.getElementById('shell-output-' + sid);
    if (out && sid === _activeShellId) {
        out.insertAdjacentHTML('beforeend', renderOutputLine({ type, text }, s.shell));
        out.scrollTop = out.scrollHeight;
    }
}

function clearActiveSession() {
    const s = getActiveSession();
    if (!s) return;
    s.output = [];
    s.history = [];
    s.historyIdx = -1;
    s.draft = '';
    renderShellSessions();
    const inp = document.getElementById('shell-input-' + s.id);
    if (inp) inp.focus();
}

function bindShellEvents() {
    _shellSessions.forEach(s => {
        const inp = document.getElementById('shell-input-' + s.id);
        if (!inp) return;
        if (s.id === _activeShellId) inp.focus();
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); shellExec(s.id); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); shellNav(s.id, -1); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); shellNav(s.id, 1); }
            else if (e.key === 'Tab') { e.preventDefault(); tabComplete(s.id); }
        });
        inp.addEventListener('input', function() {
            s.draft = inp.value;
        });
    });

    const typeSel = document.getElementById('shell-type-selector');
    if (typeSel) typeSel.addEventListener('change', (e) => {
        const s = getActiveSession();
        if (s) {
            s.shell = e.target.value;
            s.history = [];
            s.historyIdx = -1;
            renderShellTabs();
            renderShellSessions();
        }
    });

    document.querySelectorAll('.quick-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.quick-tab').forEach(b => b.className = 'quick-tab px-2 py-1 text-[10px] rounded text-text-subtle hover:text-white');
            btn.className = 'quick-tab px-2 py-1 text-[10px] rounded bg-primary/20 text-primary';
            renderQuickCommands(btn.dataset.tab);
            document.getElementById('quick-panel').classList.remove('hidden');
        });
    });

    const newBtn = document.getElementById('shell-new');
    if (newBtn) newBtn.addEventListener('click', () => {
        createShellSession('powershell');
        renderShellPanel();
        bindShellEvents();
    });

    const renameBtn = document.getElementById('shell-rename');
    if (renameBtn) renameBtn.addEventListener('click', () => renameShell(_activeShellId));

    const clearBtn = document.getElementById('shell-clear');
    if (clearBtn) clearBtn.addEventListener('click', clearActiveSession);

    const exportBtn = document.getElementById('shell-export');
    if (exportBtn) exportBtn.addEventListener('click', exportShellHistory);
}

function renderQuickCommands(category) {
    const grid = document.getElementById('quick-commands-grid');
    const cmds = QUICK_COMMANDS[category] || [];
    grid.innerHTML = cmds.map(c => `
        <button class="quick-cmd p-2 text-[10px] bg-[#1a1f2e] border border-border-theme/30 rounded text-left hover:bg-primary/10 hover:border-primary/30 transition-all"
                data-cmd="${escH(c.cmd)}"
                data-shell="${c.shell}"
                data-agent="${c.isAgentCmd ? '1' : '0'}"
                data-prompt="${c.prompt ? escH(c.prompt) : ''}"
                data-params="${c.params ? escH(JSON.stringify(c.params)) : ''}"
                title="${c.shell === 'powershell' ? 'PS' : c.shell === 'cmd' ? 'CMD' : 'Bash'}">
            <span class="block text-primary font-medium">${c.label}</span>
            <span class="text-[9px] text-text-subtle truncate">${c.cmd.substring(0, 40)}${c.cmd.length>40?'...':''}</span>
        </button>
    `).join('');

    grid.querySelectorAll('.quick-cmd').forEach(btn => {
        btn.addEventListener('click', () => executeQuickCommand(btn));
    });
}

async function executeQuickCommand(btn) {
    const cmd = btn.dataset.cmd;
    const shell = btn.dataset.shell;
    const isAgent = btn.dataset.agent === '1';
    const prompt = btn.dataset.prompt;
    const params = btn.dataset.params ? JSON.parse(btn.dataset.params) : {};

    if (prompt) {
        const val = prompt(prompt);
        if (!val) return;
        params.message = val;
    }

    if (isAgent) {
        await runAgentCommand(cmd, params);
    } else {
        if (!_shellSessions.length) createShellSession(shell);
        const s = getActiveSession();
        if (s.shell !== shell) {
            s.shell = shell;
            s.history = [];
            s.historyIdx = -1;
            renderShellTabs();
            renderShellSessions();
            updateShellToolbar();
            bindShellEvents();
        }
        const inp = document.getElementById('shell-input-' + s.id);
        if (inp) {
            inp.value = cmd;
            s.draft = cmd;
            shellExec(s.id);
        }
    }
}

function shellNav(sid, dir) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s) return;
    s.historyIdx += dir;
    if (s.historyIdx < 0) s.historyIdx = 0;
    if (s.historyIdx >= s.history.length) s.historyIdx = s.history.length - 1;
    const inp = document.getElementById('shell-input-' + sid);
    if (inp && s.history[s.historyIdx]) {
        inp.value = s.history[s.historyIdx];
        s.draft = inp.value;
    }
}

function tabComplete(sid) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s) return;
    const inp = document.getElementById('shell-input-' + sid);
    const val = inp.value.trim();
    const completions = {
        powershell: ['Get-Process','Get-Service','Get-ChildItem','Get-Item','Set-Item','Remove-Item','New-Item','Copy-Item','Move-Item','Invoke-Expression','Start-Process','Stop-Process','Get-EventLog','Get-WinEvent','Get-NetAdapter','Get-NetFirewallRule','Get-LocalUser','Get-LocalGroup'],
        cmd: ['dir','cd','copy','move','del','type','find','tasklist','taskkill','netstat','ipconfig','ping','tracert','nslookup','systeminfo','whoami'],
        bash: ['ls','cd','cp','mv','rm','cat','grep','find','ps','top','netstat','ss','ip','ping','traceroute','whoami','systemctl','journalctl'],
    };
    const list = completions[s.shell] || [];
    const matches = list.filter(c => c.toLowerCase().startsWith(val.toLowerCase()));
    if (matches.length === 1) {
        inp.value = matches[0] + ' ';
        s.draft = inp.value;
    } else if (matches.length > 1) {
        appendToSession(sid, 'Sugerencias: ' + matches.join(', '), 'suggest');
    }
}

function shellExec(sid) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s) return;
    const inp = document.getElementById('shell-input-' + sid);
    if (!inp) return;
    const cmd = inp.value.trim();
    if (!cmd) return;
    s.history.push(cmd);
    s.historyIdx = s.history.length;
    s.draft = '';
    inp.value = '';

    appendToSession(sid, cmd, 'cmd');

    const loadingId = 'loading-' + Date.now();
    appendToSession(sid, 'Ejecutando...', 'loading');
    s.output[s.output.length - 1].id = loadingId;
    s.loadingId = loadingId;

    setToolStatus('Ejecutando...');
    agentCmd(_agentId, 'shell_exec', { command: cmd }).then(res => {
        if (res && res.commandId) {
            s.cmdId = res.commandId;
            startShellPoll(sid);
        } else {
            replaceLoading(sid, res.error || 'Error al enviar comando', 'error');
        }
    });
}

function replaceLoading(sid, text, type) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s) return;
    const idx = s.output.findIndex(o => o.type === 'loading');
    if (idx >= 0) s.output.splice(idx, 1);
    const out = document.getElementById('shell-output-' + sid);
    if (out && sid === _activeShellId) {
        const loading = out.querySelector('p.animate-pulse');
        if (loading) loading.remove();
    }
    appendToSession(sid, text, type);
}

async function runAgentCommand(command, params) {
    const s = getActiveSession();
    if (!s) return;
    appendToSession(s.id, `Ejecutando comando de agente: ${command}...`, 'info');
    setToolStatus(`Ejecutando ${command}...`);
    const res = await agentCmd(_agentId, command, params);
    if (res.success) {
        appendToSession(s.id, `✓ ${res.result || 'Comando ejecutado correctamente'}`, 'success');
    } else {
        appendToSession(s.id, `✗ ${res.error || 'Error ejecutando comando'}`, 'error');
    }
    setToolStatus('');
}

function startShellPoll(sid) {
    const s = _shellSessions.find(x => x.id === sid);
    if (!s || !s.cmdId) return;
    if (s.pollTimer) clearInterval(s.pollTimer);
    let tries = 0;
    s.pollTimer = setInterval(() => {
        tries++;
        fetch(API + '/agents/' + encodeURIComponent(_agentId) + '/commands', { headers: h() })
            .then(r => r.json()).then(cmds => {
                if (!Array.isArray(cmds)) return;
                const match = cmds.find(c => c._id === s.cmdId && c.executed);
                if (match) {
                    clearInterval(s.pollTimer); s.pollTimer = null;
                    replaceLoading(sid, match.result || 'Sin resultado', match.status === 'error' ? 'error' : 'success');
                    setToolStatus('');
                    s.cmdId = null;
                } else if (tries >= 30) {
                    clearInterval(s.pollTimer); s.pollTimer = null;
                    replaceLoading(sid, 'Sin respuesta (timeout 60s)', 'error');
                    setToolStatus('Timeout');
                }
            }).catch(() => {});
    }, 2000);
}

function exportShellHistory() {
    const s = getActiveSession();
    if (!s) return;
    const lines = s.output.map(o => {
        if (o.type === 'cmd') return `${SHELL_PRESETS[s.shell].prompt} ${o.text}`;
        return o.text;
    }).join('\n');
    const blob = new Blob(['\uFEFF' + lines], { type: 'text/plain;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `shell-session-${_agentId}-${s.name.replace(/\s+/g,'-')}-${new Date().toISOString().slice(0,19).replace(/:/g,'-')}.txt`;
    a.click();
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

// ── Agent Download ──
async function downloadAgent(platform) {
    const btn = event?.target?.closest('button');
    if (btn) btn.disabled = true;
    
    // Map platform to agent platform format
    const platformMap = {
        'windows': 'win-x64',
        'linux': 'linux-x64',
        'darwin': 'mac-x64'
    };
    const agentPlatform = platformMap[platform] || platform;
    
    try {
        // 1. Crear deploy en el backend
        const deployRes = await fetch(API + '/admin/agent-deploy', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
            body: JSON.stringify({ platform: agentPlatform, userAgent: navigator.userAgent })
        });
        const deployData = await deployRes.json();
        
        if (!deployData.success) {
            throw new Error(deployData.error || 'Error creando deploy');
        }
        
        // 2. Mostrar info del deploy
        const deployInfo = document.getElementById('deploy-' + platform);
        if (deployInfo) {
            deployInfo.textContent = 'Deploy ID: ' + deployData.deployId;
            deployInfo.classList.remove('hidden');
        }
        
        // 3. Descargar el archivo (pasar token para autenticación)
        const downloadUrl = API + '/agent/download/' + agentPlatform + '?deploy=' + encodeURIComponent(deployData.deployId) + '&token=' + encodeURIComponent(SL_TOKEN);
        window.location.href = downloadUrl;
        
        showToast('Deploy creado: ' + deployData.deployId + '. Descarga iniciada...', 'success');
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ── Init ──
if ('<?= $tab ?>' === 'logs') loadLogs();
<?php if ($expandUid): ?>
setTimeout(function() { var el = document.getElementById('co-<?= h($expandUid) ?>'); if (el) el.classList.remove('hidden'); }, 100);
<?php endif; ?>

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTools(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
