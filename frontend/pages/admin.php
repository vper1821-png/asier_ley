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
        $res = api_post_form('/api/admin/create-user', [
            'token' => $token,
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'name' => $_POST['name'] ?? '',
            'companyName' => $_POST['company'] ?? '',
            'companyId' => $_POST['company_id'] ?? '',
            'role' => $_POST['role'] ?? 'user'
        ]);
        $msg = !empty($res['success']) ? 'Usuario creado.' : ($res['error'] ?? 'Error al crear usuario.');
    } elseif (isset($_POST['update_user'])) {
        $res = api_post_form('/api/admin/update-user', [
            'token' => $token,
            'userId' => $_POST['user_id'] ?? '',
            'role' => $_POST['role'] ?? '',
            'companyName' => $_POST['company'] ?? '',
            'companyId' => $_POST['company_id'] ?? '',
            'isActive' => $_POST['is_active'] ?? '',
        ]);
        $msg = !empty($res['success']) ? 'Usuario actualizado.' : ($res['error'] ?? 'Error al actualizar usuario.');
    } elseif (isset($_POST['ticket_status'])) {
        $res = api_post_form('/api/tickets/status', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'status' => $_POST['new_status']]);
        $msg = !empty($res['success']) ? 'Ticket actualizado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['ticket_respond'])) {
        $res = api_post_form('/api/tickets/respond', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'message' => $_POST['response'] ?? '']);
        $msg = !empty($res['success']) ? 'Respuesta enviada.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['toggle_maintenance'])) {
        $res = api_post_form('/api/admin/maintenance/toggle', ['token' => $token, 'enabled' => $_POST['enabled'] ?? '', 'message' => $_POST['maintenance_message'] ?? '']);
        $msg = empty($res['error']) ? 'Mantenimiento actualizado.' : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['create_backup'])) {
        $res = api_post_form('/api/admin/data-reset/backup', ['token' => $token]);
        $msg = !empty($res['success']) ? 'Backup creado: ' . ($res['backupFile'] ?? '') : ($res['error'] ?? 'Error.');
    } elseif (isset($_POST['perform_reset'])) {
        $res = api_post_form('/api/admin/data-reset/execute', ['token' => $token, 'confirm' => $_POST['confirm'] ?? '', 'preserve_admins' => $_POST['preserve_admins'] ?? 'true']);
        $msg = !empty($res['success']) ? 'Reset completado exitosamente.' : ($res['error'] ?? 'Error.');
    }
}

// ── Fetch Data ──
$usersRes = api_post_form('/api/admin/users', ['token' => $token]);
$users = is_array($usersRes) && empty($usersRes['error']) ? $usersRes : [];

// ── Company options for create/edit (companyId -> companyName) ──
$companyOptions = [];
$usersByCompany = [];
foreach ($users as $u) {
    $cid = $u['companyId'] ?? $u['_id'] ?? '';
    $cname = $u['companyName'] ?? '';
    if ($cid && !isset($companyOptions[$cid])) {
        $companyOptions[$cid] = $cname ?: $cid;
    }
    $usersByCompany[$cid][] = $u;
}
uksort($usersByCompany, fn($a, $b) => strcmp($companyOptions[$a] ?? $a, $companyOptions[$b] ?? $b));

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
    $cid = $u['companyId'] ?? $u['_id'] ?? '';
    if (!$cid) $cid = $u['_id'] ?? '';
    if (!isset($companies[$cid])) {
        $companies[$cid] = ['user' => $u, 'agents' => []];
    } elseif (($u['_id'] ?? '') === $cid) {
        $companies[$cid]['user'] = $u;
    }
}
foreach ($agents as $a) {
    $cid = $a['companyId'] ?? $a['userId'] ?? '';
    if (!$cid) $cid = $a['userId'] ?? '';
    if (!isset($companies[$cid])) {
        $companies[$cid] = ['user' => ['_id' => $cid, 'email' => $a['companyEmail'] ?? '(eliminado)', 'companyName' => $a['companyName'] ?? '', 'isActive' => ($a['companyActive'] ?? true)], 'agents' => []];
    }
    $companies[$cid]['agents'][] = $a;
}

// ── Tab Titles ──
$tabTitles = [
    'overview' => 'Panel de Control',
    'companies' => 'Empresas & Equipos',
    'users' => 'Gestión de Usuarios',
    'tickets' => 'Tickets de Soporte',
    'logs' => 'Logs de Auditoría',
    'cleanup' => 'Limpieza de Datos',
    'settings' => 'Configuración',
    'data-reset' => 'Reset de Datos',
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
                ['id' => 'cleanup', 'label' => 'Limpieza de Datos'],
                ['id' => 'settings', 'label' => 'Configuración'],
                ['id' => 'data-reset', 'label' => 'Reset de Datos', 'superadmin_only' => true],
            ];
            foreach ($sidebarItems as $item):
                if (!empty($item['superadmin_only']) && !$isSuper) continue;
            ?>
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
                $adminUsers = count(array_filter($users, fn($u) => in_array($u['role'] ?? '', ['admin','superadmin','company_admin'])));
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
                <form method="POST" class="grid grid-cols-1 md:grid-cols-7 gap-3">
                    <input type="email" name="email" required placeholder="Email" class="input-premium">
                    <input type="text" name="name" placeholder="Nombre" class="input-premium">
                    <input type="password" name="password" required placeholder="Contraseña (mín. 8)" class="input-premium">
                    <input type="text" name="company" placeholder="Empresa (nueva o existente)" class="input-premium">
                    <select name="company_id" class="input-premium">
                        <option value="">Nueva empresa</option>
                        <?php foreach ($companyOptions as $cid => $cname): ?>
                        <option value="<?= h($cid) ?>"><?= h($cname) ?> · <?= h(substr($cid, -6)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="role" class="input-premium">
                        <option value="user">Usuario</option>
                        <option value="developer">Desarrollador</option>
                        <option value="dpo">DPO / DPD</option>
                        <option value="company_admin">Admin de empresa</option>
                        <option value="support">Soporte</option>
                        <?php if ($isSuper): ?><option value="superadmin">Superadmin</option><?php endif; ?>
                        <option value="admin">Admin global</option>
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
                <div class="space-y-4">
                    <?php
                    $roleLabels = [
                        'user' => 'Usuario',
                        'developer' => 'Desarrollador',
                        'dpo' => 'DPO / DPD',
                        'company_admin' => 'Admin empresa',
                        'support' => 'Soporte',
                        'admin' => 'Admin global',
                        'superadmin' => 'Superadmin',
                    ];
                    $roleClasses = [
                        'superadmin' => 'bg-rose-500/10 text-rose-400 border-rose-500/20',
                        'admin' => 'bg-primary-500/10 text-primary-400 border-primary-500/20',
                        'company_admin' => 'bg-violet-500/10 text-violet-400 border-violet-500/20',
                        'dpo' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
                        'developer' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',
                        'support' => 'bg-slate-500/10 text-slate-400 border-slate-500/20',
                        'user' => 'bg-white/[0.05] text-text-subtle border-white/[0.08]',
                    ];
                    foreach ($usersByCompany as $cid => $companyUsers):
                        $cname = $companyOptions[$cid] ?? $cid;
                        $owner = null;
                        foreach ($companyUsers as $cu) { if (($cu['_id'] ?? '') === $cid) { $owner = $cu; break; } }
                    ?>
                    <div class="rounded-xl border border-border-theme/25 overflow-hidden">
                        <div class="px-4 py-3 bg-bg-base/60 border-b border-border-theme/25 flex items-center justify-between">
                            <div class="flex items-center gap-2 min-w-0">
                                <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-primary-600/30 to-cyan-500/20 border border-primary-500/20 flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0">
                                    <?= h(strtoupper(mb_substr($cname, 0, 2))) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[12px] font-semibold text-white truncate"><?= h($cname) ?></p>
                                    <p class="text-[10px] text-text-subtle font-mono truncate"><?= h(substr($cid, -8)) ?> · <?= count($companyUsers) ?> usuarios</p>
                                </div>
                            </div>
                            <?php if ($owner): ?>
                            <span class="text-[9px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex-shrink-0">Titular</span>
                            <?php endif; ?>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-[12px]">
                                <thead><tr class="bg-bg-base/40 border-b border-border-theme/25 text-[10px] text-text-subtle uppercase tracking-wider">
                                    <th class="text-left py-2.5 px-3 font-semibold">Nombre / Email</th>
                                    <th class="text-left py-2.5 px-3 font-semibold">Rol</th>
                                    <th class="text-left py-2.5 px-3 font-semibold">Estado</th>
                                    <th class="text-left py-2.5 px-3 font-semibold">Acciones</th>
                                </tr></thead>
                                <tbody class="divide-y divide-border-theme/20">
                                    <?php foreach ($companyUsers as $u):
                                        $uActive = !empty($u['isActive']);
                                        $uRole = $u['role'] ?? 'user';
                                        $roleClass = $roleClasses[$uRole] ?? $roleClasses['user'];
                                        $roleLabel = $roleLabels[$uRole] ?? h($uRole);
                                        $isOwnerRow = (string)($u['_id'] ?? '') === (string)$cid;
                                    ?>
                                    <tr class="hover:bg-bg-base/40 transition-colors">
                                        <td class="py-2.5 px-3">
                                            <p class="text-text-heading font-medium"><?= h($u['name'] ?? '-') ?></p>
                                            <p class="text-[10px] text-text-subtle"><?= h($u['email'] ?? '') ?></p>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $roleClass ?>"><?= $roleLabel ?></span>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <span class="text-[10px] px-2 py-0.5 rounded-full <?= $uActive ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>"><?= $uActive ? 'Activo' : 'Suspendido' ?></span>
                                        </td>
                                        <td class="py-2.5 px-3">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <form method="POST" class="inline-flex gap-1.5 items-center">
                                                    <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                                    <select name="role" class="input-premium !py-1 !text-[11px]">
                                                        <?php foreach ($roleLabels as $rid => $rlbl): ?>
                                                        <option value="<?= h($rid) ?>" <?= $rid === $uRole ? 'selected' : '' ?>><?= h($rlbl) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <select name="is_active" class="input-premium !py-1 !text-[11px]">
                                                        <option value="1" <?= $uActive ? 'selected' : '' ?>>Activo</option>
                                                        <option value="0" <?= !$uActive ? 'selected' : '' ?>>Suspendido</option>
                                                    </select>
                                                    <input type="text" name="company" value="<?= h($u['companyName'] ?? '') ?>" class="input-premium !py-1 !text-[11px] w-28" placeholder="Empresa">
                                                    <select name="company_id" class="input-premium !py-1 !text-[11px]">
                                                        <option value="" <?= empty($u['companyId']) ? 'selected' : '' ?>>Sin asignar</option>
                                                        <?php foreach ($companyOptions as $optCid => $optCname): ?>
                                                        <option value="<?= h($optCid) ?>" <?= (string)$optCid === (string)($u['companyId'] ?? '') ? 'selected' : '' ?>><?= h($optCname) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" name="update_user" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-white/[0.05] border border-white/[0.1] text-text-muted hover:text-text-body hover:bg-white/[0.08] transition-all">Guardar</button>
                                                </form>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                                    <input type="hidden" name="new_state" value="<?= $uActive ? 'false' : 'true' ?>">
                                                    <button type="submit" name="toggle_active" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium <?= $uActive ? 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' ?> transition-all flex items-center gap-1">
                                                        <?= $uActive ? 'Suspender' : 'Activar' ?>
                                                    </button>
                                                </form>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                                    <button type="submit" name="reset_2fa" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-white/[0.03] border border-white/[0.08] text-text-muted hover:text-text-body hover:bg-white/[0.06] transition-all">2FA</button>
                                                </form>
                                                <?php if (!$isOwnerRow): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este usuario?')">
                                                    <input type="hidden" name="user_id" value="<?= h($u['_id'] ?? '') ?>">
                                                    <button type="submit" name="delete_user" value="1" class="px-2 py-1 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all">Eliminar</button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'tickets'): ?>
            <!-- ═══ TICKETS ═══ -->
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

            $selectedTicketId = $_GET['ticket_id'] ?? '';
            $selectedTicket = null;
            if ($selectedTicketId) {
                foreach ($allTickets as $t) {
                    if (($t['_id'] ?? '') === $selectedTicketId) {
                        $selectedTicket = $t;
                        break;
                    }
                }
            }
            ?>

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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div class="lg:col-span-1 space-y-4">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
                        <div class="flex flex-col gap-3">
                            <div class="relative">
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

                    <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                        <div class="p-3.5 space-y-2" id="admin-tickets-list">
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
                                $isSelected = $selectedTicketId === $tid;
                            ?>
                            <div class="admin-ticket-card p-3 rounded-xl border cursor-pointer transition-all duration-200 <?= $isSelected ? 'bg-primary-500/10 border-primary-500/40' : 'border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600' ?>"
                                 onclick="selectTicket('<?= h($tid) ?>')"
                                 data-status="<?= h($tStatus) ?>" data-search="<?= h(mb_strtolower(($t['subject'] ?? '') . ' ' . ($t['userEmail'] ?? '') . ' ' . $shortId)) ?>">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-[9px] font-mono text-cyan-400 font-medium px-1.5 py-0.5 rounded bg-cyan-950/40 border border-cyan-500/20">#TK-<?= h(strtoupper($shortId)) ?></span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                            <span class="w-1 h-1 rounded-full <?= $st['dot'] ?>"></span>
                                            <?= h($st['label']) ?>
                                        </span>
                                    </div>
                                    <h3 class="text-[12px] font-semibold text-text-heading truncate leading-snug"><?= h($t['subject'] ?? ($t['title'] ?? 'Ticket')) ?></h3>
                                    <div class="flex items-center gap-2 text-[10px] text-text-subtle">
                                        <span class="font-medium"><?= h($t['userEmail'] ?? '-') ?></span>
                                        <span>·</span>
                                        <span class="font-mono"><?= h($dateStr) ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm h-full">
                        <?php if ($selectedTicket): ?>
                            <?php
                                $tid = $selectedTicket['_id'] ?? '';
                                $tStatus = $selectedTicket['status'] ?? 'open';
                                $tPriority = $selectedTicket['priority'] ?? 'medium';
                                $st = $statusCfg[$tStatus] ?? $statusCfg['open'];
                                $pr = $prioCfg[$tPriority] ?? $prioCfg['medium'];
                                $shortId = substr($tid, -6);
                                $messages = $selectedTicket['messages'] ?? [];
                                $isClosed = $tStatus === 'closed';
                            ?>
                            <div class="p-6 border-b border-border-theme">
                                <div class="flex flex-col gap-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap mb-3">
                                                <span class="text-[10px] font-mono text-cyan-400 font-medium px-2 py-0.5 rounded bg-cyan-950/40 border border-cyan-500/20">#TK-<?= h(strtoupper($shortId)) ?></span>
                                                <span class="text-[10px] px-2 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                                    <span class="w-1 h-1 rounded-full <?= $st['dot'] ?>"></span>
                                                    <?= h($st['label']) ?>
                                                </span>
                                                <span class="text-[10px] px-2 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $pr['class'] ?>">
                                                    <span class="w-1 h-1 rounded-full <?= $pr['dot'] ?>"></span>
                                                    <?= h($pr['label']) ?>
                                                </span>
                                            </div>
                                            <h2 class="text-[16px] font-bold text-white leading-tight break-words"><?= h($selectedTicket['subject'] ?? ($selectedTicket['title'] ?? 'Ticket')) ?></h2>
                                            <p class="text-[11px] text-text-muted mt-2">Categoría: <?= h(strtoupper($selectedTicket['category'] ?? 'GENERAL')) ?></p>
                                        </div>
                                        <form method="POST" class="flex-shrink-0" onsubmit="return confirm('¿Cambiar estado del ticket?')">
                                            <input type="hidden" name="ticket_id" value="<?= h($tid) ?>">
                                            <input type="hidden" name="new_status" value="<?= $isClosed ? 'open' : 'closed' ?>">
                                            <button type="submit" name="ticket_status" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium transition-all flex items-center gap-1.5 <?= $isClosed ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' : 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <?= $isClosed ? 'Reabrir' : 'Cerrar' ?>
                                            </button>
                                        </form>
                                    </div>
                                    <?php if ($isClosed): ?>
                                    <div class="bg-amber-500/10 border border-amber-500/20 rounded-lg px-4 py-3 text-[11px] text-amber-400">
                                        Este ticket está cerrado. Reabre para enviar nuevas respuestas.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="p-5 space-y-4 max-h-[500px] overflow-y-auto">
                                <?php foreach ($messages as $msg): ?>
                                    <?php
                                        $isAdmin = ($msg['sender'] ?? '') === 'admin';
                                        $msgClass = $isAdmin ? 'bg-primary-500/10 border-primary-500/20 ml-auto' : 'bg-bg-surface/30 border-border-theme/50 mr-auto';
                                        $alignClass = $isAdmin ? 'justify-end' : 'justify-start';
                                    ?>
                                    <div class="flex <?= $alignClass ?> max-w-[90%] mb-4">
                                        <div class="<?= $msgClass ?> rounded-xl p-4 border">
                                            <div class="flex items-center gap-2 mb-2">
                                                <span class="text-[11px] font-semibold <?= $isAdmin ? 'text-primary-400' : 'text-text-heading' ?>"><?= $isAdmin ? 'Admin' : h($selectedTicket['userEmail'] ?? 'Usuario') ?></span>
                                                <span class="text-[10px] text-text-subtle"><?= h(substr($msg['timestamp'] ?? '', 0, 16)) ?></span>
                                            </div>
                                            <p class="text-[12px] text-text-body leading-relaxed break-words"><?= h($msg['text'] ?? '') ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($messages)): ?>
                                <div class="text-center py-12 text-text-subtle text-[12px]">
                                    No hay mensajes en este ticket.
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!$isClosed): ?>
                            <div class="p-5 border-t border-border-theme bg-bg-surface/20">
                                <form method="POST" class="flex flex-col gap-4">
                                    <input type="hidden" name="ticket_id" value="<?= h($tid) ?>">
                                    <div>
                                        <label class="block text-[11px] font-medium text-text-heading mb-2">Escribe una respuesta</label>
                                        <textarea name="response" required placeholder="Escribe tu respuesta aquí..." rows="4" class="input-premium w-full text-[12px] p-4 rounded-xl resize-none min-h-[100px]"></textarea>
                                    </div>
                                    <div class="flex justify-end">
                                        <button type="submit" name="ticket_respond" value="1" class="px-8 py-3 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-primary-600 to-cyan-600 hover:from-primary-500 hover:to-cyan-500 text-white transition-all shadow-lg shadow-primary-900/20 flex items-center gap-2">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                            Enviar Respuesta
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="flex flex-col items-center justify-center h-full py-20 px-4 text-center space-y-4">
                                <div class="w-16 h-16 rounded-2xl bg-white/[0.02] border border-white/[0.06] flex items-center justify-center text-text-subtle">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-text-heading">Selecciona un ticket</p>
                                    <p class="text-xs text-text-subtle mt-1">Haz clic en un ticket de la lista para ver los detalles</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <script>
            function selectTicket(ticketId) {
                window.location.href = '/admin?tab=tickets&ticket_id=' + ticketId;
            }
            </script>

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
                        <option value="cleanup_applied">
                        <option value="cleanup_repair_links">
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

            <?php elseif ($tab === 'cleanup'): ?>
            <!-- ═══ LIMPIEZA DE DATOS ═══ -->
            <div x-data="cleanupApp()" x-init="init()" x-cloak>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5 mb-5">
                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                        <div class="flex-1">
                            <label class="block text-[11px] font-medium text-text-heading mb-2">Empresa a diagnosticar</label>
                            <select x-model="selectedCompany" @change="diagnose()" class="input-premium w-full max-w-md">
                                <option value="">— Selecciona una empresa —</option>
                                <template x-for="c in companies" :key="c.companyId">
                                    <option :value="c.companyId" x-text="c.companyName + ' (' + (c.filesCount||0) + ' archivos, ' + (c.inventoryCount||0) + ' RAT)'"></option>
                                </template>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button @click="loadCompanies()" :disabled="loading.companies" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-white/[0.04] border border-white/[0.1] text-text-muted hover:text-text-body hover:bg-white/[0.08] transition-all flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" :class="loading.companies ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Recargar
                            </button>
                            <button @click="diagnose()" :disabled="!selectedCompany || loading.diagnose" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500/15 border border-primary-500/30 text-primary-400 hover:bg-primary-500/25 transition-all flex items-center gap-1.5 disabled:opacity-50">
                                <svg class="w-3.5 h-3.5" :class="loading.diagnose ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                Diagnosticar
                            </button>
                        </div>
                    </div>
                </div>

                <template x-if="alert.msg">
                    <div :class="'mb-5 px-4 py-2.5 rounded-lg text-[11px] ' + (alert.type === 'error' ? 'bg-red-500/10 border border-red-500/30 text-red-400' : 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400')" x-text="alert.msg"></div>
                </template>

                <template x-if="diagnosis">
                    <div class="space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div :class="'rounded-2xl border p-5 ' + healthColorClass()">
                                <p class="text-[10px] uppercase tracking-wide opacity-80" x-text="healthLabel()"></p>
                                <p class="text-[42px] font-bold leading-none mt-2" x-text="diagnosis.health.score"></p>
                                <p class="text-[10px] opacity-70 mt-1">/ 100</p>
                            </div>
                            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                                <p class="text-[10px] uppercase tracking-wide text-text-subtle">Archivos</p>
                                <p class="text-[26px] font-bold text-white mt-2" x-text="diagnosis.files.total"></p>
                                <p class="text-[10px] text-text-subtle mt-1">
                                    <span class="text-red-400" x-text="diagnosis.files.duplicates_extra"></span> duplicados ·
                                    <span class="text-amber-400" x-text="diagnosis.files.with_pii_without_inventory"></span> sin RAT
                                </p>
                            </div>
                            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                                <p class="text-[10px] uppercase tracking-wide text-text-subtle">Inventario (RAT)</p>
                                <p class="text-[26px] font-bold text-white mt-2" x-text="diagnosis.inventory.total"></p>
                                <p class="text-[10px] text-text-subtle mt-1">
                                    <span class="text-red-400" x-text="diagnosis.inventory.orphans"></span> huérfanos ·
                                    <span class="text-amber-400" x-text="diagnosis.inventory.with_noisy_categories"></span> con ruido
                                </p>
                            </div>
                            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                                <p class="text-[10px] uppercase tracking-wide text-text-subtle">Auditoría</p>
                                <p class="text-[26px] font-bold text-white mt-2" x-text="diagnosis.logs.file_audit_logs"></p>
                                <p class="text-[10px] text-text-subtle mt-1">registros de archivos</p>
                            </div>
                        </div>

                        <div x-show="diagnosis.health.issues.length" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                            <h3 class="text-[13px] font-semibold text-amber-400 mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                Problemas detectados
                            </h3>
                            <ul class="space-y-1.5">
                                <template x-for="(issue, i) in diagnosis.health.issues" :key="i">
                                    <li class="text-[11px] text-text-body flex items-start gap-2">
                                        <span class="w-1 h-1 rounded-full bg-amber-400 mt-1.5 flex-shrink-0"></span>
                                        <span x-text="issue"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                            <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                                <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Operaciones disponibles
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <button @click="preview('all')" :disabled="loading.preview" class="px-4 py-3 rounded-lg text-[11px] font-medium bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/20 transition-all flex items-center gap-2 justify-start disabled:opacity-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <span class="flex-1 text-left">Preview (dry-run)</span>
                                </button>
                                <button @click="repair()" :disabled="loading.repair" class="px-4 py-3 rounded-lg text-[11px] font-medium bg-violet-500/10 border border-violet-500/30 text-violet-400 hover:bg-violet-500/20 transition-all flex items-center gap-2 justify-start disabled:opacity-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                    <span class="flex-1 text-left">Reparar links huérfanos</span>
                                </button>
                                <button @click="previewAndApply()" :disabled="loading.apply" class="col-span-1 md:col-span-2 px-4 py-3 rounded-lg text-[11px] font-bold bg-gradient-to-r from-red-600 to-red-700 text-white hover:from-red-500 hover:to-red-600 transition-all flex items-center justify-center gap-2 disabled:opacity-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    <span>Ejecutar limpieza completa</span>
                                </button>
                            </div>
                        </div>

                        <template x-if="previewResult">
                            <div class="rounded-xl border border-cyan-500/30 bg-cyan-500/5 p-5">
                                <h3 class="text-[13px] font-semibold text-cyan-400 mb-4">Preview — qué se va a borrar (nada ejecutado aún)</h3>
                                <div class="space-y-3 text-[11px]">
                                    <div x-show="previewResult.dup_files" class="flex justify-between items-center px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <span class="text-text-body">Archivos duplicados</span>
                                        <div class="flex gap-3">
                                            <span class="text-emerald-400">Mantener: <b x-text="previewResult.dup_files?.will_keep || 0"></b></span>
                                            <span class="text-red-400">Eliminar: <b x-text="previewResult.dup_files?.will_delete || 0"></b></span>
                                        </div>
                                    </div>
                                    <div x-show="previewResult.dup_inventory" class="flex justify-between items-center px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <span class="text-text-body">RAT duplicados</span>
                                        <span class="text-red-400">Eliminar: <b x-text="previewResult.dup_inventory?.will_delete || 0"></b></span>
                                    </div>
                                    <div x-show="previewResult.orphan_inventory" class="flex justify-between items-center px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <span class="text-text-body">RAT huérfanos</span>
                                        <span class="text-red-400">Eliminar: <b x-text="previewResult.orphan_inventory?.will_delete || 0"></b></span>
                                    </div>
                                </div>
                                <div class="mt-4 flex gap-2">
                                    <button @click="apply()" :disabled="loading.apply" class="flex-1 px-4 py-2.5 rounded-lg text-[11px] font-bold bg-red-600 hover:bg-red-700 text-white transition-all disabled:opacity-50">
                                        Confirmar y aplicar
                                    </button>
                                    <button @click="previewResult = null" class="px-4 py-2.5 rounded-lg text-[11px] font-medium bg-white/[0.05] border border-white/[0.1] text-text-muted hover:text-text-body transition-all">
                                        Cancelar
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template x-if="applyResult">
                            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-5">
                                <h3 class="text-[13px] font-semibold text-emerald-400 mb-4">✅ Limpieza completada</h3>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-[11px]">
                                    <div class="px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <p class="text-text-subtle text-[10px]">Archivos borrados</p>
                                        <p class="text-white font-bold text-[16px]" x-text="applyResult.dup_files_deleted || 0"></p>
                                    </div>
                                    <div class="px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <p class="text-text-subtle text-[10px]">RAT borrados</p>
                                        <p class="text-white font-bold text-[16px]" x-text="applyResult.dup_inventory_deleted || 0"></p>
                                    </div>
                                    <div class="px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <p class="text-text-subtle text-[10px]">Huérfanos borrados</p>
                                        <p class="text-white font-bold text-[16px]" x-text="applyResult.orphan_inventory_deleted || 0"></p>
                                    </div>
                                    <div class="px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                                        <p class="text-text-subtle text-[10px]">Categorías limpiadas</p>
                                        <p class="text-white font-bold text-[16px]" x-text="applyResult.categories_cleaned || 0"></p>
                                    </div>
                                </div>
                                <button @click="diagnose(); applyResult = null; previewResult = null;" class="mt-4 px-4 py-2 rounded-lg text-[11px] font-medium bg-primary-500/15 border border-primary-500/30 text-primary-400 hover:bg-primary-500/25 transition-all">
                                    Volver a diagnosticar
                                </button>
                            </div>
                        </template>

                        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-[13px] font-semibold text-white">Historial de limpiezas</h3>
                                <button @click="loadAuditLog()" :disabled="loading.audit" class="text-[10px] text-primary-400 hover:text-primary-300 disabled:opacity-50">Ver historial</button>
                            </div>
                            <template x-if="auditLog && auditLog.length">
                                <div class="space-y-2 max-h-64 overflow-y-auto scrollbar-custom">
                                    <template x-for="(log, i) in auditLog" :key="i">
                                        <div class="px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25 flex justify-between items-center text-[11px]">
                                            <div>
                                                <p class="text-text-heading font-medium" x-text="log.action"></p>
                                                <p class="text-text-subtle text-[10px] mt-0.5" x-text="log.createdAt ? log.createdAt.substring(0, 19).replace('T', ' ') : ''"></p>
                                            </div>
                                            <span class="text-text-muted" x-text="log.userEmail || log.userId || ''"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!auditLog || !auditLog.length">
                                <p class="text-[11px] text-text-subtle text-center py-4">Sin historial cargado. Presiona "Ver historial".</p>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="!diagnosis">
                    <div class="rounded-2xl border border-dashed border-border-theme bg-bg-panel/30 p-12 text-center">
                        <svg class="w-12 h-12 mx-auto text-text-subtle mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                        <p class="text-text-muted text-[12px]">Selecciona una empresa y presiona <b class="text-primary-400">Diagnosticar</b> para comenzar.</p>
                    </div>
                </template>

                                <!-- ═══ ZONA DE PELIGRO — RESET TOTAL ═══ -->
                <template x-if="selectedCompany">
                    <div class="mt-8 rounded-2xl border-2 border-red-500/40 bg-red-500/[0.03] overflow-hidden">
                        <!-- Header de peligro -->
                        <div class="px-6 py-5 border-b border-red-500/30 bg-gradient-to-r from-red-500/10 to-red-600/5">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-red-500/20 border border-red-500/40 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-[15px] font-bold text-red-400">Zona de Peligro — Reset Total</h3>
                                    <p class="text-[11px] text-red-300/80 mt-0.5">
                                        Borra TODOS los datos de <strong x-text="companyName()"></strong> excepto los usuarios.
                                        Esta acción <strong>NO se puede deshacer</strong>.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="p-6 space-y-5">
                            <!-- Qué preservar -->
                            <div>
                                <label class="block text-[11px] font-semibold text-text-heading mb-3">Qué preservar</label>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/30 cursor-not-allowed">
                                        <input type="checkbox" checked disabled class="rounded border-border-theme">
                                        <span class="text-[11px] text-emerald-400 font-medium">Usuarios (obligatorio)</span>
                                    </label>
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.08] cursor-pointer hover:bg-white/[0.05]">
                                        <input type="checkbox" x-model="resetPreserve.config" class="rounded border-border-theme">
                                        <span class="text-[11px] text-text-body">Configuración</span>
                                    </label>
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.08] cursor-pointer hover:bg-white/[0.05]">
                                        <input type="checkbox" x-model="resetPreserve.payments" class="rounded border-border-theme">
                                        <span class="text-[11px] text-text-body">Pagos</span>
                                    </label>
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.08] cursor-pointer hover:bg-white/[0.05]">
                                        <input type="checkbox" x-model="resetPreserve.certifications" class="rounded border-border-theme">
                                        <span class="text-[11px] text-text-body">Certificaciones</span>
                                    </label>
                                    <label class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.08] cursor-pointer hover:bg-white/[0.05]">
                                        <input type="checkbox" x-model="resetPreserve.audit" class="rounded border-border-theme">
                                        <span class="text-[11px] text-text-body">Audit Logs</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Botones de acción -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <button @click="previewReset()" :disabled="resetLoading.preview"
                                    class="px-4 py-3 rounded-lg text-[11px] font-medium bg-amber-500/10 border border-amber-500/30 text-amber-400 hover:bg-amber-500/20 transition-all flex items-center gap-2 justify-center disabled:opacity-50">
                                    <svg class="w-4 h-4" :class="resetLoading.preview ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    1. Preview Reset
                                </button>
                                <button @click="backupCompany()" :disabled="resetLoading.backup"
                                    class="px-4 py-3 rounded-lg text-[11px] font-medium bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/20 transition-all flex items-center gap-2 justify-center disabled:opacity-50">
                                    <svg class="w-4 h-4" :class="resetLoading.backup ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                    </svg>
                                    2. Crear Backup
                                </button>
                                <button @click="resetCompany()" :disabled="resetLoading.reset || resetConfirmText !== 'DELETE_ALL_DATA'"
                                    class="px-4 py-3 rounded-lg text-[11px] font-bold bg-red-600 hover:bg-red-700 text-white transition-all flex items-center gap-2 justify-center disabled:opacity-40 disabled:cursor-not-allowed">
                                    <svg class="w-4 h-4" :class="resetLoading.reset ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    3. Reset Total
                                </button>
                            </div>

                            <!-- Backup info -->
                            <template x-if="backupInfo">
                                <div class="px-4 py-3 rounded-lg bg-cyan-500/10 border border-cyan-500/30 text-[11px]">
                                    <p class="text-cyan-400">
                                        ✅ Backup creado: <strong x-text="backupInfo.filename"></strong>
                                        (<span x-text="Math.round(backupInfo.size / 1024)"></span> KB)
                                    </p>
                                    <p class="text-text-subtle mt-1">
                                        También puedes descargarlo manualmente desde:
                                        <a :href="API + backupInfo.downloadUrl.replace('/api', '')" target="_blank" class="text-cyan-300 hover:text-cyan-200 underline ml-1">descargar</a>
                                    </p>
                                </div>
                            </template>

                            <!-- Preview del reset -->
                            <template x-if="resetPreview">
                                <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                                    <h4 class="text-[12px] font-semibold text-amber-400 mb-3">
                                        Preview — <span x-text="resetPreview.summary.documents_to_delete"></span> documentos serán eliminados
                                        en <span x-text="resetPreview.summary.collections_affected"></span> colecciones
                                    </h4>
                                    <div class="max-h-48 overflow-y-auto scrollbar-custom space-y-1">
                                        <template x-for="(count, col) in resetPreview.counts" :key="col">
                                            <div class="flex justify-between items-center px-3 py-1.5 rounded bg-bg-base/40 border border-border-theme/20 text-[10px]">
                                                <span class="text-text-body font-mono" x-text="col"></span>
                                                <span class="text-red-400 font-bold" x-text="count + ' docs'"></span>
                                            </div>
                                        </template>
                                    </div>
                                    <p class="text-[10px] text-emerald-400 mt-3">
                                        ✅ Usuarios preservados: <span x-text="resetPreview.preserved.users"></span>
                                    </p>
                                </div>
                            </template>

                            <!-- Confirmación por texto -->
                            <div>
                                <label class="block text-[11px] font-semibold text-red-400 mb-2">
                                    Para confirmar, escribe exactamente: <code class="px-1.5 py-0.5 bg-red-500/20 rounded text-red-300 font-mono">DELETE_ALL_DATA</code>
                                </label>
                                <input type="text"
                                    x-model="resetConfirmText"
                                    placeholder="DELETE_ALL_DATA"
                                    class="w-full px-4 py-2.5 rounded-lg bg-bg-base border-2 border-red-500/30 focus:border-red-500 text-[12px] text-white font-mono placeholder-text-subtle focus:outline-none transition-colors">
                                <p x-show="resetConfirmText && resetConfirmText !== 'DELETE_ALL_DATA'" class="text-[10px] text-amber-400 mt-1.5">
                                    ⚠️ El texto no coincide
                                </p>
                                <p x-show="resetConfirmText === 'DELETE_ALL_DATA'" class="text-[10px] text-emerald-400 mt-1.5">
                                    ✓ Confirmación válida. Puedes presionar Reset.
                                </p>
                            </div>

                            <!-- Resultado del reset -->
                            <template x-if="resetResult">
                                <div class="rounded-xl border-2 border-emerald-500/40 bg-emerald-500/5 p-5">
                                    <h4 class="text-[13px] font-semibold text-emerald-400 mb-3">✅ Reset completado</h4>
                                    <p class="text-[11px] text-text-body mb-3">
                                        <strong x-text="resetResult.deleted_total"></strong> documentos eliminados en total.
                                    </p>
                                    <div class="max-h-40 overflow-y-auto scrollbar-custom space-y-1">
                                        <template x-for="(count, col) in resetResult.by_collection" :key="col">
                                            <div class="flex justify-between items-center px-3 py-1.5 rounded bg-bg-base/40 border border-border-theme/20 text-[10px]">
                                                <span class="text-text-body font-mono" x-text="col"></span>
                                                <span class="text-emerald-400" x-text="count + ' eliminados'"></span>
                                            </div>
                                        </template>
                                    </div>
                                    <template x-if="resetResult.errors && resetResult.errors.length">
                                        <div class="mt-3 px-3 py-2 rounded bg-red-500/10 border border-red-500/20">
                                            <p class="text-[10px] text-red-400 font-bold mb-1">Errores:</p>
                                            <template x-for="(err, i) in resetResult.errors" :key="i">
                                                <p class="text-[10px] text-red-300 font-mono" x-text="err"></p>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>


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

            <?php elseif ($tab === 'data-reset'): ?>
            <!-- ═══ RESET DE DATOS ═══ -->
            <?php if (!$isSuper): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-8 text-center">
                <svg class="w-16 h-16 mx-auto text-red-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <h3 class="text-[16px] font-bold text-red-400 mb-2">Acceso Restringido</h3>
                <p class="text-text-subtle text-[12px]">Esta funcionalidad solo está disponible para superadministradores.</p>
            </div>
            <?php else: ?>
            <div class="space-y-5">
                <div class="rounded-xl border border-red-500/30 bg-gradient-to-r from-red-500/10 to-red-600/5 p-5">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-red-500/20 border border-red-500/30 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-[14px] font-bold text-red-400 mb-2">⚠️ ZONA DE PELIGRO - RESET COMPLETO DEL SISTEMA</h3>
                            <p class="text-[11px] text-text-subtle leading-relaxed">Esta operación eliminará permanentemente TODOS los datos del sistema excepto las cuentas de administrador. Esta acción NO se puede deshacer.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Datos que serán eliminados
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2a3 3 0 00-5.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2a3 3 0 015.356-1.857m0 0a3 3 0 10-4.788-3.538 3.001 3.001 0 004.788 3.538z"/></svg>
                            <span class="text-[11px] text-text-body">Usuarios (excepto admins)</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span class="text-[11px] text-text-body">Agentes y equipos</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                            <span class="text-[11px] text-text-body">Bases de datos</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span class="text-[11px] text-text-body">Reportes y documentos</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <span class="text-[11px] text-text-body">Tickets de soporte</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                            <span class="text-[11px] text-text-body">Logs de auditoría</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="text-[11px] text-text-body">Datos de cumplimiento</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-[11px] text-text-body">Alertas y notificaciones</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            <span class="text-[11px] text-text-body">Datos de hardening</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span class="text-[11px] text-text-body">Solicitudes ARCO</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-[11px] text-text-body">Pagos y facturación</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-red-500/5 border border-red-500/10">
                            <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                            <span class="text-[11px] text-text-body">Cache y datos temporales</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Datos que serán preservados
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/5 border border-emerald-500/10">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="text-[11px] text-text-body">Cuentas de administrador</span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-500/5 border border-emerald-500/10">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                            <span class="text-[11px] text-text-body">Configuración del sistema</span>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        Copia de Seguridad (Recomendado)
                    </h3>
                    <p class="text-[11px] text-text-subtle mb-4">Se recomienda crear una copia de seguridad antes de realizar el reset. La copia se guardará en el servidor.</p>
                    <form method="POST" class="inline">
                        <button type="submit" name="create_backup" value="1" class="px-6 py-3 rounded-lg text-[12px] font-medium bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 hover:bg-cyan-500/20 transition-all flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            Crear Copia de Seguridad
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-red-500/30 bg-red-500/5 p-5">
                    <h3 class="text-[13px] font-semibold text-red-400 mb-4 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Ejecutar Reset
                    </h3>
                    <form method="POST" onsubmit="return confirmReset(event)">
                        <div class="flex items-center gap-3 mb-4">
                            <input type="checkbox" id="preserve_admins" name="preserve_admins" value="true" checked class="rounded border-border-theme bg-bg-base text-red-500 focus:ring-red-500/20">
                            <label for="preserve_admins" class="text-[11px] text-text-body">Preservar cuentas de administrador</label>
                        </div>
                        <div class="flex items-start gap-3 mb-4">
                            <input type="checkbox" id="confirm_reset" name="confirm_reset" class="rounded border-border-theme bg-bg-base text-red-500 focus:ring-red-500/20 mt-1">
                            <label for="confirm_reset" class="text-[11px] text-text-subtle">Entiendo que esta acción eliminará permanentemente todos los datos y no se puede deshacer.</label>
                        </div>
                        <div class="flex items-start gap-3 mb-4">
                            <input type="checkbox" id="confirm_danger" name="confirm_danger" class="rounded border-border-theme bg-bg-base text-red-500 focus:ring-red-500/20 mt-1">
                            <label for="confirm_danger" class="text-[11px] text-text-subtle">Confirmo que quiero proceder con el reset completo del sistema.</label>
                        </div>
                        <div class="mb-4">
                            <label class="block text-[11px] font-medium text-text-heading mb-2">Escribe "RESET" para confirmar:</label>
                            <input type="text" name="confirm" placeholder="RESET" class="input-premium w-full max-w-xs" required pattern="RESET">
                        </div>
                        <button type="submit" name="perform_reset" value="1" class="px-8 py-3 rounded-lg text-[12px] font-bold bg-red-600 hover:bg-red-700 text-white transition-all flex items-center gap-2 shadow-lg shadow-red-900/20">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            EJECUTAR RESET COMPLETO
                        </button>
                    </form>
                </div>
            </div>

            <script>
            function confirmReset(e) {
                const confirmReset = document.getElementById('confirm_reset').checked;
                const confirmDanger = document.getElementById('confirm_danger').checked;
                const confirmText = e.target.confirm.value;

                if (!confirmReset) {
                    alert('Debes confirmar que entiendes que esta acción eliminará permanentemente todos los datos.');
                    return false;
                }
                if (!confirmDanger) {
                    alert('Debes confirmar que quieres proceder con el reset completo del sistema.');
                    return false;
                }
                if (confirmText !== 'RESET') {
                    alert('Debes escribir "RESET" para confirmar.');
                    return false;
                }

                if (!confirm('¿ESTÁS ABSOLUTAMENTE SEGURO?\n\nEsta acción eliminará TODOS los datos del sistema excepto las cuentas de administrador.\n\nEsta acción NO se puede deshacer.')) {
                    return false;
                }

                if (!confirm('ÚLTIMA OPORTUNIDAD PARA CANCELAR\n\n¿Realmente deseas continuar con el reset completo del sistema?')) {
                    return false;
                }

                return true;
            }
            </script>
            <?php endif; ?>

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
const API = '/api-proxy.php?path=/api';

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

// ── Shell (simplificado para este archivo) ──
function initShell() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = `
        <div class="rounded-xl border border-border-theme/40 bg-[#0a0e14] p-0 min-h-[400px] flex flex-col">
            <div class="border-b border-border-theme/40 p-3 bg-[#0d131a] flex items-center gap-2">
                <select id="shell-type-selector" class="bg-[#1a1f2e] border border-border-theme/30 rounded px-2 py-1 text-[11px] text-white focus:outline-none">
                    <option value="powershell">PowerShell</option>
                    <option value="cmd">CMD</option>
                    <option value="bash">Bash/WSL</option>
                </select>
                <div class="flex-1"></div>
                <button onclick="clearShell()" class="px-3 py-1 text-[10px] bg-red-500/20 border border-red-500/30 text-red-400 rounded hover:bg-red-500/30 transition-colors">Limpiar</button>
            </div>
            <div id="shell-output" class="flex-1 overflow-y-auto p-4 font-mono text-[11px] text-emerald-400 space-y-1 scrollbar-custom min-h-[280px] max-h-[480px]">
                <p class="text-text-subtle">Shell lista. Escribe un comando abajo.</p>
            </div>
            <div class="border-t border-border-theme/40 bg-[#0d131a] p-3">
                <div class="flex items-center gap-2">
                    <span id="shell-prompt" class="font-mono text-[11px] select-none text-blue-400">PS></span>
                    <input id="shell-input" type="text" placeholder="Escribe un comando..." class="flex-1 bg-transparent border-0 text-[12px] text-white font-mono focus:outline-none placeholder-text-subtle" autocomplete="off">
                </div>
            </div>
        </div>
    `;
    document.getElementById('shell-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); shellExec(); }
    });
    setToolStatus('Shell lista');
}

function clearShell() {
    const out = document.getElementById('shell-output');
    if (out) out.innerHTML = '<p class="text-text-subtle">Shell limpia.</p>';
}

function shellExec() {
    const inp = document.getElementById('shell-input');
    const cmd = inp.value.trim();
    if (!cmd) return;
    const out = document.getElementById('shell-output');
    out.innerHTML += '<p class="text-emerald-400"><span class="text-blue-400">PS></span> ' + esc(cmd) + '</p>';
    out.innerHTML += '<p class="text-text-subtle animate-pulse" id="shell-loading">Ejecutando...</p>';
    inp.value = '';
    out.scrollTop = out.scrollHeight;

    agentCmd(_agentId, 'shell_exec', { command: cmd }).then(res => {
        const loading = document.getElementById('shell-loading');
        if (loading) loading.remove();
        if (res && res.commandId) {
            let tries = 0;
            const timer = setInterval(() => {
                tries++;
                fetch(API + '/agents/' + encodeURIComponent(_agentId) + '/commands', { headers: h() })
                    .then(r => r.json()).then(cmds => {
                        if (!Array.isArray(cmds)) return;
                        const match = cmds.find(c => c._id === res.commandId && c.executed);
                        if (match) {
                            clearInterval(timer);
                            out.innerHTML += '<pre class="text-' + (match.status === 'error' ? 'red' : 'emerald') + '-400 whitespace-pre-wrap break-all">' + esc(match.result || 'Sin salida') + '</pre>';
                            out.scrollTop = out.scrollHeight;
                        } else if (tries >= 30) {
                            clearInterval(timer);
                            out.innerHTML += '<p class="text-red-400">Timeout (60s)</p>';
                        }
                    });
            }, 2000);
        } else {
            out.innerHTML += '<p class="text-red-400">' + esc(res.error || 'Error') + '</p>';
        }
    });
}

// ── Control Panel ──
function initControlPanel() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = `
        <div class="space-y-6">
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-red-400 mb-4">Seguridad y Bloqueo</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <button onclick="controlAction('lockdown')" class="p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 transition-all text-left">
                        <div class="text-lg mb-1">🔒</div>
                        <div class="font-medium text-red-400">Bloqueo Total</div>
                    </button>
                    <button onclick="controlAction('lockdown_silent')" class="p-4 bg-rose-500/10 border border-rose-500/20 rounded-lg hover:bg-rose-500/20 transition-all text-left">
                        <div class="text-lg mb-1">🔇</div>
                        <div class="font-medium text-rose-300">Bloqueo Silencioso</div>
                    </button>
                    <button onclick="controlAction('unlock')" class="p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-lg hover:bg-emerald-500/20 transition-all text-left">
                        <div class="text-lg mb-1">🔓</div>
                        <div class="font-medium text-emerald-400">Desbloquear</div>
                    </button>
                    <button onclick="controlAction('alarm')" class="p-4 bg-red-500/10 border border-red-500/20 rounded-lg hover:bg-red-500/20 transition-all text-left">
                        <div class="text-lg mb-1">🚨</div>
                        <div class="font-medium text-red-400">Alarma</div>
                    </button>
                </div>
            </div>
        </div>
    `;
    setToolStatus('Panel listo');
}

async function controlAction(action) {
    let params = {};
    if (action === 'lockdown' || action === 'lockdown_silent') {
        const reason = prompt('Motivo (opcional):');
        if (reason === null) return;
        params = { message: reason || 'Bloqueo desde panel' };
    }
    await agentCmd(_agentId, action, params);
}

// ── Forensics Panel ──
function initForensicsPanel() {
    const el = document.getElementById('toolsContent');
    el.innerHTML = `
        <div class="space-y-4">
            <div class="rounded-xl border border-border-theme/40 bg-[#0d131a] p-5">
                <h4 class="text-sm font-semibold text-amber-400 mb-4">Análisis Forense</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <button onclick="runForensics('Get-Process | Sort-Object CPU -Descending | Select-Object -First 20')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-amber-500/10 transition-all text-left">
                        <div class="font-medium text-amber-400">Top procesos por CPU</div>
                    </button>
                    <button onclick="runForensics('Get-NetTCPConnection -State Listen | Select-Object LocalAddress,LocalPort,OwningProcess')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-amber-500/10 transition-all text-left">
                        <div class="font-medium text-amber-400">Puertos escuchando</div>
                    </button>
                    <button onclick="runForensics('Get-ScheduledTask | Where-Object {$_.State -ne \\"Disabled\\"}')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-amber-500/10 transition-all text-left">
                        <div class="font-medium text-amber-400">Tareas programadas</div>
                    </button>
                    <button onclick="runForensics('Get-ItemProperty HKLM:\\\\Software\\\\Microsoft\\\\Windows\\\\CurrentVersion\\\\Run')" class="p-3 bg-[#1a1f2e] border border-border-theme/30 rounded-lg hover:bg-amber-500/10 transition-all text-left">
                        <div class="font-medium text-amber-400">Run Keys</div>
                    </button>
                </div>
            </div>
        </div>
    `;
    setToolStatus('Panel forense listo');
}

function runForensics(cmd) {
    if (!document.getElementById('shell-input')) {
        initShell();
    }
    const inp = document.getElementById('shell-input');
    if (inp) {
        inp.value = cmd;
        shellExec();
    }
}

// ── Notifications ──
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
        return '<tr class="hover:bg-bg-base/20 transition-colors"><td class="py-2 px-3 text-text-subtle font-mono whitespace-nowrap">' + esc((l.createdAt || '').substring(0, 19)) + '</td><td class="py-2 px-3"><span class="text-[9px] px-1.5 py-0.5 rounded-full border ' + badge.cls + '">' + badge.label + '</span></td><td class="py-2 px-3 text-text-body">' + esc(l.userEmail || l.userId || '-') + '</td><td class="py-2 px-3 text-text-muted">' + esc(l.companyName || '-') + '</td><td class="py-2 px-3 text-text-muted font-mono">' + esc(l.agentId ? l.agentId.substring(0, 16) : '-') + '</td><td class="py-2 px-3 text-text-subtle font-mono">' + esc(l.ip || '-') + '</td><td class="py-2 px-3 text-text-muted max-w-[200px] truncate">' + esc(typeof l.details === 'object' ? Object.keys(l.details || {}).join(', ') : (l.details || '')) + '</td></tr>';
    }).join('');
}

function logBadge(action) {
    const m = {
        'login_success': { label: 'Login OK', cls: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
        'login_failed': { label: 'Login Fallido', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
        'agent_registered': { label: 'Agente+', cls: 'bg-violet-500/10 text-violet-400 border-violet-500/20' },
        'agent_deleted': { label: 'Agente-', cls: 'bg-orange-500/10 text-orange-400 border-orange-500/20' },
        'lockdown_on': { label: 'Bloqueo ON', cls: 'bg-red-500/10 text-red-400 border-red-500/20' },
        'lockdown_off': { label: 'Bloqueo OFF', cls: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
        'cleanup_applied': { label: 'Limpieza', cls: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20' },
        'cleanup_repair_links': { label: 'Reparación', cls: 'bg-violet-500/10 text-violet-400 border-violet-500/20' },
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

// ── Cleanup App (Alpine.js) ──
function cleanupApp() {
    return {
        companies: [],
        selectedCompany: '',
        diagnosis: null,
        previewResult: null,
        applyResult: null,
        auditLog: null,
        alert: { msg: '', type: 'info' },
        loading: {
            companies: false,
            diagnose: false,
            preview: false,
            apply: false,
            repair: false,
            audit: false
        },

        // ── Reset total ──
        resetPreview: null,
        backupInfo: null,
        resetConfirmText: '',
        resetPreserve: {
            users: true,        // siempre true
            config: true,
            payments: false,
            certifications: false,
            audit: true,
        },
        resetResult: null,
        resetLoading: {
            preview: false,
            backup: false,
            reset: false,
        },

        init() {
            this.loadCompanies();
        },

        async apiCall(endpoint, payload = {}) {
            const res = await fetch(API + '/admin/cleanup/' + endpoint, {
                method: 'POST',
                headers: h(),
                body: JSON.stringify(payload)
            });
            return res.json();
        },

        showAlert(msg, type = 'info') {
            this.alert = { msg, type };
            setTimeout(() => { if (this.alert.msg === msg) this.alert.msg = ''; }, 8000);
        },

        async loadCompanies() {
            this.loading.companies = true;
            try {
                const res = await this.apiCall('companies');
                this.companies = res.companies || [];
                if (this.companies.length === 1 && !this.selectedCompany) {
                    this.selectedCompany = this.companies[0].companyId;
                    this.diagnose();
                }
            } catch (e) {
                this.showAlert('Error cargando empresas: ' + e.message, 'error');
            } finally {
                this.loading.companies = false;
            }
        },

        async diagnose() {
            if (!this.selectedCompany) return;
            this.loading.diagnose = true;
            this.diagnosis = null;
            this.previewResult = null;
            this.applyResult = null;
            try {
                const res = await this.apiCall('diagnose', { companyId: this.selectedCompany });
                if (res.success) {
                    this.diagnosis = res;
                } else {
                    this.showAlert(res.error || 'Error al diagnosticar', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.loading.diagnose = false;
            }
        },

        async preview(operation) {
            this.loading.preview = true;
            this.previewResult = null;
            try {
                const res = await this.apiCall('preview', {
                    companyId: this.selectedCompany,
                    operation
                });
                if (res.success) {
                    this.previewResult = res.result || {};
                } else {
                    this.showAlert(res.error || 'Error en preview', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.loading.preview = false;
            }
        },

        async previewAndApply() {
            await this.preview('all');
        },

        async apply() {
            if (!confirm('⚠️ ¿Confirmas la limpieza?\n\nSe eliminarán duplicados pero se conservará un registro de cada archivo.\nLos registros de auditoría NO se borran.')) {
                return;
            }
            this.loading.apply = true;
            try {
                const res = await this.apiCall('apply', {
                    companyId: this.selectedCompany,
                    operation: 'all',
                    confirm: true
                });
                if (res.success) {
                    this.applyResult = res.stats || {};
                    this.previewResult = null;
                    this.showAlert('Limpieza completada', 'success');
                } else {
                    this.showAlert(res.error || 'Error al aplicar', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.loading.apply = false;
            }
        },

        async repair() {
            if (!confirm('¿Reparar links huérfanos?\n\nEsto re-vincula items del RAT sin archivo asociado y completa agentId/hostname faltantes.')) {
                return;
            }
            this.loading.repair = true;
            try {
                const res = await this.apiCall('repair-links', {
                    companyId: this.selectedCompany,
                    confirm: true
                });
                if (res.success) {
                    this.showAlert('Reparación completada: ' + JSON.stringify(res.stats || {}), 'success');
                    this.diagnose();
                } else {
                    this.showAlert(res.error || 'Error al reparar', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.loading.repair = false;
            }
        },

        async loadAuditLog() {
            this.loading.audit = true;
            try {
                const res = await this.apiCall('audit-log', {
                    companyId: this.selectedCompany,
                    limit: 30
                });
                this.auditLog = res.logs || [];
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.loading.audit = false;
            }
        },


                // ── Reset total: métodos ──
        async previewReset() {
            this.resetLoading.preview = true;
            this.resetPreview = null;
            this.resetResult = null;
            try {
                const res = await this.apiCall('reset-preview', {
                    companyId: this.selectedCompany,
                    preserve: {
                        config: this.resetPreserve.config,
                        payments: this.resetPreserve.payments,
                        certifications: this.resetPreserve.certifications,
                        audit: this.resetPreserve.audit,
                    },
                });
                if (res.success) {
                    this.resetPreview = res;
                } else {
                    this.showAlert(res.error || 'Error en preview reset', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.resetLoading.preview = false;
            }
        },

        async backupCompany() {
            if (!confirm('¿Crear un backup completo de esta empresa?\n\nSe descargará un archivo JSON con TODOS los datos actuales.')) {
                return;
            }
            this.resetLoading.backup = true;
            try {
                const res = await this.apiCall('backup-company', {
                    companyId: this.selectedCompany,
                });
                if (res.success) {
                    this.backupInfo = res;
                    this.showAlert('Backup creado: ' + res.filename + ' (' + Math.round(res.size / 1024) + ' KB)', 'success');
                    // Disparar descarga automática
                    window.location.href = API + res.downloadUrl.replace('/api', '');
                } else {
                    this.showAlert(res.error || 'Error al crear backup', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.resetLoading.backup = false;
            }
        },

        async resetCompany() {
            if (this.resetConfirmText !== 'DELETE_ALL_DATA') {
                this.showAlert('Debes escribir DELETE_ALL_DATA para confirmar', 'error');
                return;
            }

            if (!confirm('⚠️ ¿BORRAR TODOS los datos de esta empresa?\n\nSe preservarán los usuarios y la configuración marcada.\n\nEsta acción NO se puede deshacer.')) {
                return;
            }
            if (!confirm('ÚLTIMA OPORTUNIDAD\n\n¿Realmente deseas continuar con el reset de ' + this.companyName() + '?')) {
                return;
            }

            this.resetLoading.reset = true;
            this.resetResult = null;
            try {
                const res = await this.apiCall('reset-company', {
                    companyId: this.selectedCompany,
                    confirm: 'DELETE_ALL_DATA',
                    requireBackup: !!this.backupInfo,
                    preserve: {
                        config: this.resetPreserve.config,
                        payments: this.resetPreserve.payments,
                        certifications: this.resetPreserve.certifications,
                        audit: this.resetPreserve.audit,
                    },
                });
                if (res.success) {
                    this.resetResult = res.stats;
                    this.resetPreview = null;
                    this.resetConfirmText = '';
                    this.showAlert('Reset completado. ' + res.stats.deleted_total + ' documentos eliminados.', 'success');
                    this.diagnose();
                } else {
                    this.showAlert(res.error || 'Error al resetear', 'error');
                }
            } catch (e) {
                this.showAlert('Error: ' + e.message, 'error');
            } finally {
                this.resetLoading.reset = false;
            }
        },

        companyName() {
            const c = this.companies.find(x => x.companyId === this.selectedCompany);
            return c ? c.companyName : this.selectedCompany;
        },

        healthColorClass() {
            if (!this.diagnosis) return 'border-border-theme bg-bg-panel/60';
            const s = this.diagnosis.health.score;
            if (s >= 90) return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-400';
            if (s >= 70) return 'border-cyan-500/40 bg-cyan-500/10 text-cyan-400';
            if (s >= 50) return 'border-amber-500/40 bg-amber-500/10 text-amber-400';
            return 'border-red-500/40 bg-red-500/10 text-red-400';
        },

        healthLabel() {
            if (!this.diagnosis) return 'Health';
            const s = this.diagnosis.health.score;
            if (s >= 90) return 'Excelente';
            if (s >= 70) return 'Bueno';
            if (s >= 50) return 'Regular';
            return 'Crítico';
        }
    };
}

// ── Init ──
if ('<?= $tab ?>' === 'logs') loadLogs();
<?php if ($expandUid): ?>
setTimeout(function() { var el = document.getElementById('co-<?= h($expandUid) ?>'); if (el) el.classList.remove('hidden'); }, 100);
<?php endif; ?>

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTools(); });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>