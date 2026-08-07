<?php
require_once __DIR__ . '/../config.php';
require_admin();

$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';
$tab = $_GET['tab'] ?? 'overview';

// ── Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_active'])) {
        $res = api_post_form('/api/admin/update-user', ['token' => $token, 'userId' => $_POST['user_id'], 'isActive' => $_POST['new_state']]);
        if (!empty($res['success'])) $msg = 'Usuario actualizado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['set_plan'])) {
        $res = api_post_form('/api/admin/update-user', ['token' => $token, 'userId' => $_POST['user_id'], 'planType' => $_POST['plan']]);
        if (!empty($res['success'])) $msg = 'Plan actualizado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['reset_2fa'])) {
        $res = api_post_form('/api/admin/reset-2fa', ['token' => $token, 'userId' => $_POST['user_id']]);
        if (!empty($res['success'])) $msg = '2FA reseteado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['delete_user'])) {
        $res = api_post_form('/api/admin/delete-user-full', ['token' => $token, 'userId' => $_POST['user_id']]);
        if (!empty($res['success'])) $msg = 'Usuario eliminado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['create_user'])) {
        $res = api_post_form('/api/admin/create-user', [
            'token' => $token,
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'companyName' => $_POST['company'] ?? '',
            'planType' => $_POST['plan'] ?? 'free',
            'role' => $_POST['role'] ?? 'user',
        ]);
        if (!empty($res['success'])) $msg = 'Usuario creado.'; else $err = $res['error'] ?? 'Error al crear usuario.';
    } elseif (isset($_POST['verify_payment'])) {
        $res = api_post_form('/api/payments/verify', ['token' => $token, 'paymentId' => $_POST['payment_id'], 'approved' => $_POST['approved']]);
        if (!empty($res['success'])) $msg = 'Pago procesado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['ticket_status'])) {
        $res = api_post_form('/api/tickets/status', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'status' => $_POST['new_status']]);
        if (!empty($res['success'])) $msg = 'Ticket actualizado.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['ticket_respond'])) {
        $res = api_post_form('/api/tickets/respond', ['token' => $token, 'ticketId' => $_POST['ticket_id'], 'message' => $_POST['response'] ?? '']);
        if (!empty($res['success'])) $msg = 'Respuesta enviada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['toggle_maintenance'])) {
        $res = api_post_form('/api/admin/maintenance/toggle', ['token' => $token, 'enabled' => $_POST['enabled'] ?? '', 'message' => $_POST['maintenance_message'] ?? '']);
        if (empty($res['error'])) $msg = 'Modo mantenimiento actualizado.'; else $err = $res['error'];
    }
}

// ── Data (según pestaña) ──
$usersRes = api_post_form('/api/admin/users', ['token' => $token]);
$users = is_array($usersRes) && empty($usersRes['error']) ? $usersRes : [];

$ticketsRes = api_post_form('/api/tickets/all', ['token' => $token]);
$allTickets = is_array($ticketsRes) && empty($ticketsRes['error']) ? ($ticketsRes['tickets'] ?? $ticketsRes) : [];
if (!is_array($allTickets)) $allTickets = [];
$openTickets = count(array_filter($allTickets, fn($t) => ($t['status'] ?? '') === 'open'));

$alertsRes = api_post_form('/api/admin/alerts', ['token' => $token]);
$adminAlerts = is_array($alertsRes) && empty($alertsRes['error']) ? $alertsRes : [];

$pendingPayments = [];
if (in_array($tab, ['overview', 'payments'])) {
    $payRes = api_post_form('/api/payments/pending', ['token' => $token]);
    $pendingPayments = is_array($payRes) && empty($payRes['error']) ? ($payRes['payments'] ?? $payRes) : [];
    if (!is_array($pendingPayments)) $pendingPayments = [];
}

$auditLogs = [];
if ($tab === 'logs') {
    $logsRes = api_post_form('/api/admin/audit-logs', ['token' => $token]);
    $auditLogs = is_array($logsRes) && empty($logsRes['error']) ? ($logsRes['logs'] ?? $logsRes) : [];
    if (!is_array($auditLogs)) $auditLogs = [];
}

$maintenance = [];
if ($tab === 'settings') {
    $mRes = api_post_form('/api/admin/maintenance/status', ['token' => $token]);
    $maintenance = is_array($mRes) ? $mRes : [];
}

$suspendedUsers = count(array_filter($users, fn($u) => empty($u['isActive'])));
$incidentCount = $openTickets + $suspendedUsers;

function aIcon($name) {
    $paths = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>',
        'incidents' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
        'alerts' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
        'payments' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',
        'tickets' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>',
        'logs' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
    ];
    return '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . ($paths[$name] ?? '') . '</svg>';
}

$sidebarItems = [
    ['id' => 'overview', 'label' => 'Resumen', 'icon' => 'overview', 'count' => 0],
    ['id' => 'incidents', 'label' => 'Incidentes', 'icon' => 'incidents', 'count' => $incidentCount],
    ['id' => 'users', 'label' => 'Usuarios', 'icon' => 'users', 'count' => 0],
    ['id' => 'alerts', 'label' => 'Alertas', 'icon' => 'alerts', 'count' => 0],
    ['id' => 'payments', 'label' => 'Pagos', 'icon' => 'payments', 'count' => 0],
    ['id' => 'tickets', 'label' => 'Tickets', 'icon' => 'tickets', 'count' => $openTickets],
    ['id' => 'logs', 'label' => 'Logs de Auditoría', 'icon' => 'logs', 'count' => 0],
    ['id' => 'settings', 'label' => 'Configuración', 'icon' => 'settings', 'count' => 0],
];
$tabTitles = [
    'overview' => 'Panel de Control',
    'incidents' => 'Incidentes',
    'users' => 'Gestión de Usuarios',
    'alerts' => 'Alertas del Sistema',
    'payments' => 'Pagos Pendientes',
    'tickets' => 'Tickets de Soporte',
    'logs' => 'Logs de Auditoría',
    'settings' => 'Configuración del Sistema',
];

$pageTitle = 'Panel de Administración';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <!-- Sidebar de administración (igual a React AdminPanel) -->
    <aside class="w-56 bg-bg-base border-r border-border-theme flex flex-col flex-shrink-0">
        <div class="px-3 py-3 border-b border-border-theme flex items-center space-x-2">
            <div class="w-7 h-7 rounded bg-bg-panel flex items-center justify-center overflow-hidden">
                <img src="/logo-nuevo.png" alt="Logo" class="w-full h-full object-contain">
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-[12px] text-white truncate font-medium">Panel Admin</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-2 scrollbar-custom">
            <div class="px-3 mb-4">
                <p class="text-[10px] font-medium text-text-subtle uppercase tracking-wider mb-1.5">Gestión</p>
                <?php foreach ($sidebarItems as $item): ?>
                <a href="/admin?tab=<?= $item['id'] ?>"
                    class="w-full flex items-center space-x-2 px-2 py-1.5 rounded text-[12px] transition-colors <?= $tab === $item['id'] ? 'bg-bg-panel text-text-heading' : 'text-text-muted hover:bg-bg-panel hover:text-text-heading' ?>">
                    <?= aIcon($item['icon']) ?>
                    <span><?= h($item['label']) ?></span>
                    <?php if ($item['count'] > 0): ?>
                    <span class="ml-auto bg-red-500/20 text-red-400 text-[9px] px-1.5 py-0.5 rounded"><?= $item['count'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>

        <div class="px-3 py-3 border-t border-border-theme">
            <a href="/dashboard"
                class="w-full flex items-center justify-center space-x-2 px-2 py-1.5 rounded text-[12px] text-text-muted hover:bg-bg-panel hover:text-text-heading transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span>Volver al Dashboard</span>
            </a>
            <button onclick="toggleThemePopup && toggleThemePopup()"
                class="w-full flex items-center justify-between px-2 py-1.5 mt-1.5 rounded text-[11px] bg-bg-panel border border-border-theme text-text-muted hover:text-text-heading transition-colors tour-theme-btn">
                <div class="flex items-center gap-1.5">
                    <div id="theme-dot" class="w-2.5 h-2.5 rounded-full border border-surface-600 bg-primary-500"></div>
                    <span id="theme-label">Tema</span>
                </div>
                <svg class="w-3 h-3 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
        </div>
    </aside>

    <!-- Contenido principal -->
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="px-6 py-4 border-b border-border-theme flex-shrink-0 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-text-heading"><?= h($tabTitles[$tab] ?? 'Panel de Control') ?></h2>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-primary-500/10 text-primary-400 border border-primary-500/20"><?= h($_SESSION['user']['role'] ?? 'admin') ?></span>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <?php if ($tab === 'overview'): ?>
            <!-- ═══ Overview ═══ -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ([
                    ['label' => 'Usuarios registrados', 'value' => count($users), 'sub' => 'este mes', 'color' => 'text-white'],
                    ['label' => 'Usuarios activos', 'value' => count($users) - $suspendedUsers, 'sub' => 'de ' . count($users) . ' totales', 'color' => 'text-emerald-400'],
                    ['label' => 'Tickets abiertos', 'value' => $openTickets, 'sub' => 'requieren atención', 'color' => $openTickets ? 'text-amber-400' : 'text-white'],
                    ['label' => 'Alertas del sistema', 'value' => count($adminAlerts), 'sub' => 'configuradas', 'color' => 'text-cyan-400'],
                ] as $stat): ?>
                <div class="bg-bg-panel/60 border border-border-theme/25 rounded-lg p-4">
                    <span class="text-[10px] text-text-muted tracking-wide"><?= h($stat['label']) ?></span>
                    <p class="text-[24px] font-bold mt-1.5 leading-none <?= $stat['color'] ?>"><?= h($stat['value']) ?></p>
                    <p class="text-[10px] text-text-subtle mt-1.5"><?= h($stat['sub']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Últimos usuarios -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-[13px] font-semibold text-white">Últimos usuarios</h3>
                        <a href="/admin?tab=users" class="text-[10px] text-primary-400 hover:text-primary-300">Ver todos →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($users, 0, 6) as $u): ?>
                        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-6 h-6 rounded-full bg-primary-600 flex items-center justify-center text-white text-[9px] font-bold flex-shrink-0"><?= h(strtoupper(substr($u['email'] ?? 'U', 0, 2))) ?></div>
                                <span class="text-[11px] text-text-body truncate"><?= h($u['email'] ?? '') ?></span>
                            </div>
                            <span class="text-[9px] px-1.5 py-0.5 rounded-full <?= !empty($u['isActive']) ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' ?>"><?= !empty($u['isActive']) ? 'Activo' : 'Pendiente' ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?><p class="text-[11px] text-text-subtle text-center py-4">Sin usuarios.</p><?php endif; ?>
                    </div>
                </div>
                <!-- Últimos tickets -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-[13px] font-semibold text-white">Últimos tickets</h3>
                        <a href="/admin?tab=tickets" class="text-[10px] text-primary-400 hover:text-primary-300">Ver todos →</a>
                    </div>
                    <div class="space-y-2">
                        <?php foreach (array_slice($allTickets, 0, 6) as $t): ?>
                        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25">
                            <span class="text-[11px] text-text-body truncate"><?= h($t['subject'] ?? $t['title'] ?? 'Ticket') ?></span>
                            <span class="text-[9px] px-1.5 py-0.5 rounded-full flex-shrink-0 <?= ($t['status'] ?? '') === 'open' ? 'bg-amber-500/10 text-amber-400' : 'bg-emerald-500/10 text-emerald-400' ?>"><?= h($t['status'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($allTickets)): ?><p class="text-[11px] text-text-subtle text-center py-4">Sin tickets.</p><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php elseif ($tab === 'incidents'): ?>
            <!-- ═══ Incidentes ═══ -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-bg-panel/60 border border-border-theme/25 rounded-lg p-4">
                    <span class="text-[10px] text-text-muted tracking-wide">Tickets abiertos</span>
                    <p class="text-[24px] font-bold mt-1.5 leading-none <?= $openTickets ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $openTickets ?></p>
                </div>
                <div class="bg-bg-panel/60 border border-border-theme/25 rounded-lg p-4">
                    <span class="text-[10px] text-text-muted tracking-wide">Usuarios suspendidos / pendientes</span>
                    <p class="text-[24px] font-bold mt-1.5 leading-none <?= $suspendedUsers ? 'text-red-400' : 'text-emerald-400' ?>"><?= $suspendedUsers ?></p>
                </div>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Tickets que requieren atención</h3>
                <?php $openList = array_filter($allTickets, fn($t) => ($t['status'] ?? '') === 'open'); ?>
                <?php if (empty($openList)): ?>
                <p class="text-[11px] text-text-subtle text-center py-6">No hay incidentes activos. Todo en orden ✓</p>
                <?php else: foreach ($openList as $t): ?>
                <div class="flex items-center justify-between px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25 mb-2">
                    <div class="min-w-0">
                        <p class="text-[12px] text-text-heading truncate"><?= h($t['subject'] ?? $t['title'] ?? 'Ticket') ?></p>
                        <p class="text-[10px] text-text-subtle"><?= h($t['userEmail'] ?? $t['email'] ?? '') ?> · <?= h(substr($t['createdAt'] ?? '', 0, 16)) ?></p>
                    </div>
                    <a href="/admin?tab=tickets" class="text-[10px] text-primary-400 hover:text-primary-300 flex-shrink-0">Gestionar →</a>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <?php elseif ($tab === 'users'): ?>
            <!-- ═══ Usuarios ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Crear usuario</h3>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                    <input type="email" name="email" required placeholder="Email" class="input-premium">
                    <input type="password" name="password" required placeholder="Contraseña (mín. 8)" class="input-premium">
                    <input type="text" name="company" placeholder="Empresa" class="input-premium">
                    <select name="role" class="input-premium">
                        <option value="user">Usuario</option>
                        <option value="support">Soporte</option>
                        <option value="finance">Finanzas</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" name="create_user" value="1" class="px-3 py-2 rounded-lg text-[11px] font-medium bg-primary-500 hover:bg-primary-600 text-white transition-all">Crear</button>
                </form>
            </div>

            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Usuarios (<?= count($users) ?>)</h3>
                <?php if (empty($users)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay usuarios registrados.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12px]">
                        <thead>
                            <tr class="bg-bg-base/60 border-b border-border-theme text-[10px] text-text-muted uppercase tracking-wider">
                                <th class="text-left py-3 px-3 font-semibold">Email</th>
                                <th class="text-left py-3 px-3 font-semibold">Empresa</th>
                                <th class="text-left py-3 px-3 font-semibold">Estado</th>
                                <th class="text-left py-3 px-3 font-semibold">Rol</th>
                                <th class="text-left py-3 px-3 font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-theme/30">
                            <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-bg-base/30 transition-colors">
                                <td class="py-2.5 px-3 text-text-heading"><?= h($u['email'] ?? 'N/A') ?></td>
                                <td class="py-2.5 px-3 text-text-muted"><?= h($u['companyName'] ?? $u['name'] ?? '-') ?></td>
                                <td class="py-2.5 px-3">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full <?= !empty($u['isActive']) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' ?>">
                                        <?= !empty($u['isActive']) ? 'Activo' : 'Pendiente' ?>
                                    </span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <?php if (!empty($u['isAdmin']) || in_array($u['role'] ?? '', ['admin', 'superadmin'])): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-primary-500/10 text-primary-400 border border-primary-500/20"><?= h($u['role'] ?? 'admin') ?></span>
                                    <?php else: ?>
                                    <span class="text-text-subtle text-[10px]"><?= h($u['role'] ?? 'user') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <a href="/admin/user/<?= h($u['_id'] ?? '') ?>" class="text-[10px] text-primary-400 hover:text-primary-300 transition-colors">Ver</a>
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

            <?php elseif ($tab === 'alerts'): ?>
            <!-- ═══ Alertas ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Alertas del sistema (<?= count($adminAlerts) ?>)</h3>
                <?php if (empty($adminAlerts)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay alertas del sistema.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($adminAlerts as $alert): ?>
                    <div class="p-3 rounded-lg border border-border-theme/25 bg-bg-base/40">
                        <div class="flex items-center justify-between">
                            <span class="text-[12px] text-text-body"><?= h($alert['title'] ?? $alert['message'] ?? 'Sin título') ?></span>
                            <span class="text-[10px] text-text-subtle"><?= h(substr($alert['createdAt'] ?? '', 0, 16)) ?></span>
                        </div>
                        <?php if (!empty($alert['description'])): ?><p class="text-[10px] text-text-subtle mt-1"><?= h($alert['description']) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'payments'): ?>
            <!-- ═══ Pagos ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Pagos pendientes de verificación (<?= count($pendingPayments) ?>)</h3>
                <?php if (empty($pendingPayments)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay pagos pendientes.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($pendingPayments as $p): ?>
                    <div class="p-3 rounded-lg border border-border-theme/25 bg-bg-base/40 flex flex-col md:flex-row md:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-[12px] text-text-heading"><?= h($p['userEmail'] ?? $p['email'] ?? 'Usuario') ?></p>
                            <p class="text-[10px] text-text-subtle mt-0.5">$<?= h($p['amount'] ?? '0') ?> USD · <?= h(($p['month'] ?? '') . '/' . ($p['year'] ?? '')) ?> · <?= h(substr($p['createdAt'] ?? '', 0, 16)) ?></p>
                        </div>
                        <form method="POST" class="flex gap-1.5 flex-shrink-0">
                            <input type="hidden" name="payment_id" value="<?= h($p['_id'] ?? '') ?>">
                            <button type="submit" name="verify_payment" value="1" onclick="this.form.approved.value='true'" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20 transition-all">Aprobar</button>
                            <button type="submit" name="verify_payment" value="1" onclick="this.form.approved.value='false'" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all">Rechazar</button>
                            <input type="hidden" name="approved" value="true">
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'tickets'): ?>
            <!-- ═══ Tickets ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Todos los tickets (<?= count($allTickets) ?>)</h3>
                <?php if (empty($allTickets)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay tickets.</p>
                <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($allTickets as $t): $isOpen = ($t['status'] ?? '') === 'open'; ?>
                    <div class="p-3 rounded-lg border border-border-theme/25 bg-bg-base/40">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-[12px] text-text-heading"><?= h($t['subject'] ?? $t['title'] ?? 'Ticket') ?></p>
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

            <?php elseif ($tab === 'logs'): ?>
            <!-- ═══ Logs de Auditoría ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Logs de auditoría (<?= count($auditLogs) ?>)</h3>
                <?php if (empty($auditLogs)): ?>
                <p class="text-text-muted text-sm text-center py-8">No hay logs registrados.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-[11px]">
                        <thead>
                            <tr class="bg-bg-base/60 border-b border-border-theme text-[10px] text-text-muted uppercase tracking-wider">
                                <th class="text-left py-2.5 px-3 font-semibold">Fecha</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Usuario</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Acción</th>
                                <th class="text-left py-2.5 px-3 font-semibold">Detalle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-theme/30">
                            <?php foreach (array_slice($auditLogs, 0, 100) as $log): ?>
                            <tr class="hover:bg-bg-base/30 transition-colors">
                                <td class="py-2 px-3 text-text-subtle font-mono"><?= h(substr($log['createdAt'] ?? $log['timestamp'] ?? '', 0, 19)) ?></td>
                                <td class="py-2 px-3 text-text-body"><?= h($log['userEmail'] ?? $log['email'] ?? $log['userId'] ?? '-') ?></td>
                                <td class="py-2 px-3 text-text-heading"><?= h($log['action'] ?? $log['event'] ?? '-') ?></td>
                                <td class="py-2 px-3 text-text-muted truncate max-w-xs"><?= h(is_string($log['details'] ?? '') ? ($log['details'] ?? '-') : json_encode($log['details'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($tab === 'settings'): ?>
            <!-- ═══ Configuración ═══ -->
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-1">Modo mantenimiento</h3>
                <p class="text-[11px] text-text-subtle mb-4">Cuando está activo, los usuarios no-admin ven un aviso de mantenimiento.</p>
                <?php $mOn = !empty($maintenance['maintenanceMode']); ?>
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-[11px] <?= $mOn ? 'text-amber-400' : 'text-emerald-400' ?> font-medium"><?= $mOn ? '⚠ Mantenimiento ACTIVO' : '✓ Sistema operativo' ?></span>
                </div>
                <form method="POST" class="flex flex-col sm:flex-row gap-2">
                    <input type="hidden" name="enabled" value="<?= $mOn ? 'false' : 'true' ?>">
                    <input type="text" name="maintenance_message" placeholder="Mensaje de mantenimiento (opcional)" value="<?= h($maintenance['maintenanceMessage'] ?? '') ?>" class="input-premium flex-1">
                    <button type="submit" name="toggle_maintenance" value="1"
                        class="px-4 py-2 rounded-lg text-[11px] font-medium <?= $mOn ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' : 'bg-amber-500/10 border border-amber-500/20 text-amber-400 hover:bg-amber-500/20' ?> transition-all">
                        <?= $mOn ? 'Desactivar mantenimiento' : 'Activar mantenimiento' ?>
                    </button>
                </form>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-4">Información del sistema</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Versión</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono">2.0.0 (PHP)</p>
                    </div>
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Usuarios totales</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono"><?= count($users) ?></p>
                    </div>
                    <div class="px-3 py-2.5 rounded-lg bg-bg-base/40 border border-border-theme/25">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider">Tickets totales</p>
                        <p class="text-[12px] text-text-heading mt-1 font-mono"><?= count($allTickets) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
