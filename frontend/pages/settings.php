<?php
$pageTitle = 'Ajustes del Sistema';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';

$success = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (isset($_POST['update_profile']) || $action === 'update_profile') {
        $activeTab = 'profile';
        $name = trim($_POST['name'] ?? '');
        $res = api_request('POST', '/api/account/update', [
            'token' => $token,
            'name' => $name,
            'companyName' => $name,
        ]);
        if (empty($res['body']['error'])) {
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['companyName'] = $name;
            $user['name'] = $name;
            $user['companyName'] = $name;
            $success = 'Información de perfil actualizada con éxito.';
        } else {
            $error = $res['body']['error'];
        }
    } elseif (isset($_POST['change_email']) || $action === 'change_email') {
        $activeTab = 'profile';
        $newEmail = trim($_POST['new_email'] ?? '');
        $currentPassword = $_POST['email_password'] ?? '';
        
        $res = api_request('POST', '/api/account/change-email', [
            'token' => $token,
            'newEmail' => $newEmail,
            'password' => $currentPassword,
        ]);
        
        if (empty($res['body']['error']) && !empty($res['body']['success'])) {
            $_SESSION['user']['email'] = $newEmail;
            $user['email'] = $newEmail;
            if (!empty($res['body']['token'])) {
                $_SESSION['token'] = $res['body']['token'];
                $token = $res['body']['token'];
            }
            $success = 'Dirección de correo electrónico modificada a ' . htmlspecialchars($newEmail) . '.';
        } else {
            $error = $res['body']['error'] ?? 'No se pudo actualizar el email. Verifica tu contraseña actual.';
        }
    } elseif (isset($_POST['change_password']) || $action === 'change_password') {
        $activeTab = 'security';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 8) {
            $error = 'La nueva contraseña debe contener al menos 8 caracteres.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'Las contraseñas ingresadas no coinciden.';
        } else {
            $res = api_request('POST', '/api/account/change-password', [
                'token' => $token,
                'currentPassword' => $_POST['current_password'] ?? '',
                'newPassword' => $newPass,
            ]);
            if (empty($res['body']['error']) && !empty($res['body']['success'])) {
                if (!empty($res['body']['token'])) {
                    $_SESSION['token'] = $res['body']['token'];
                    $token = $res['body']['token'];
                }
                $success = 'Contraseña de seguridad actualizada correctamente. Las demás sesiones quedan cerradas.';
            } else {
                $error = $res['body']['error'] ?? 'Error al actualizar contraseña. Verifica tu contraseña actual.';
            }
        }
    } elseif (isset($_POST['logout_all']) || $action === 'logout_all') {
        $activeTab = 'security';
        $res = api_request('POST', '/api/account/logout-all', ['token' => $token]);
        if (empty($res['body']['error']) && !empty($res['body']['token'])) {
            $_SESSION['token'] = $res['body']['token'];
            $token = $res['body']['token'];
            $success = 'Todas las demás sesiones se han cerrado. Este dispositivo permanece activo.';
        } else {
            $error = $res['body']['error'] ?? 'No se pudieron cerrar las sesiones.';
        }
    } elseif (isset($_POST['setup_2fa']) || $action === 'setup_2fa') {
        $activeTab = '2fa';
        $res = api_post_form('/api/2fa/setup', ['token' => $token]);
        if (!empty($res['secret'])) {
            $_SESSION['pending_2fa'] = [
                'secret' => $res['secret'],
                'otpauth' => $res['qrCodeUrl'] ?? ($res['otpauth'] ?? ($res['otpauthUrl'] ?? ''))
            ];
            $success = 'Código secreto 2FA generado. Escanea el código QR y verifícalo a continuación.';
        } else {
            $error = $res['error'] ?? 'Error al iniciar la configuración de autenticación 2FA.';
        }
    } elseif (isset($_POST['verify_2fa']) || $action === 'verify_2fa') {
        $activeTab = '2fa';
        $res = api_post_form('/api/2fa/verify', ['token' => $token, 'code' => $_POST['code_2fa'] ?? '']);
        if (!empty($res['success'])) {
            $success = '¡Autenticación de dos factores (2FA) activada con éxito!';
            $_SESSION['user']['twoFactorEnabled'] = true;
            $user['twoFactorEnabled'] = true;
            unset($_SESSION['pending_2fa']);
        } else {
            $error = $res['error'] ?? 'Código de verificación 2FA inválido o expirado.';
        }
    } elseif (isset($_POST['cancel_2fa']) || $action === 'cancel_2fa') {
        $activeTab = '2fa';
        unset($_SESSION['pending_2fa']);
        $success = 'Configuración de 2FA cancelada.';
    } elseif (isset($_POST['disable_2fa']) || $action === 'disable_2fa') {
        $activeTab = '2fa';
        $res = api_post_form('/api/2fa/disable', [
            'token' => $token,
            'code' => $_POST['code_2fa'] ?? '',
            'password' => $_POST['password_2fa'] ?? ''
        ]);
        if (!empty($res['success'])) {
            $success = 'Autenticación de dos factores (2FA) desactivada correctamente.';
            $_SESSION['user']['twoFactorEnabled'] = false;
            $user['twoFactorEnabled'] = false;
        } else {
            $error = $res['error'] ?? 'Error al desactivar 2FA. Verifica tu contraseña y código.';
        }
    }
}

// User details
$displayName = $user['name'] ?? ($user['companyName'] ?? 'Usuario');
$userEmail = $user['email'] ?? '';
$userRole = strtoupper($user['role'] ?? (!empty($user['isAdmin']) ? 'SUPERADMIN' : 'USUARIO'));
$planType = strtoupper($user['planType'] ?? 'ENTERPRISE');
$twoFaActive = !empty($user['twoFactorEnabled']);
$initials = mb_strtoupper(mb_substr($displayName ?: ($userEmail ?: 'U'), 0, 2));
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'settings'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">
        <!-- Top App Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-primary-600/30 to-cyan-500/20 border border-primary-500/30 flex items-center justify-center text-primary-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        Ajustes y Preferencias
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-mono bg-primary-500/10 text-primary-400 border border-primary-500/20">
                            <?= h($planType) ?>
                        </span>
                    </h1>
                    <p class="text-[11px] text-text-muted">Gestiona la seguridad, perfil empresarial y configuraciones globales</p>
                </div>
            </div>

            <!-- Quick Account Pill -->
            <div class="flex items-center gap-2.5 self-start sm:self-auto">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-bg-elevated/70 border border-border-theme">
                    <div class="w-6 h-6 rounded-lg bg-primary-500/20 border border-primary-500/30 flex items-center justify-center text-[10px] font-bold text-primary-300">
                        <?= h($initials) ?>
                    </div>
                    <div class="text-left leading-none">
                        <span class="text-[11px] font-medium text-white block max-w-[130px] truncate"><?= h($displayName) ?></span>
                        <span class="text-[9px] text-text-subtle uppercase tracking-wider font-mono"><?= h($userRole) ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Scrollable Body -->
        <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- Alert Notifications -->
                <?php if ($success): ?>
                <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 shadow-theme-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-emerald-200">Operación exitosa</p>
                            <p class="text-[11px] text-emerald-300/90"><?= h($success) ?></p>
                        </div>
                    </div>
                    <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-emerald-400 hover:text-emerald-200 p-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3.5 rounded-2xl bg-red-500/10 border border-red-500/25 text-red-300 shadow-theme-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-red-500/20 border border-red-500/30 flex items-center justify-center shrink-0">
                            <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-red-200">Atención</p>
                            <p class="text-[11px] text-red-300/90"><?= h($error) ?></p>
                        </div>
                    </div>
                    <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-red-400 hover:text-red-200 p-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <?php endif; ?>

                <!-- Responsive Layout Grid: Navigation Tabs (Left/Top) + Main Panels (Right) -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <!-- Left Navigation Column / Tab Bar -->
                    <div class="lg:col-span-3 space-y-4">
                        <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-2.5 backdrop-blur-md shadow-theme-sm">
                            <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-wider px-3 py-2">Secciones</p>
                            <nav class="space-y-1" id="settings-nav">
                                <button type="button" onclick="switchSettingsTab('profile')" data-tab="profile"
                                        class="tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 <?= $activeTab === 'profile' ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30 font-medium' : 'text-text-muted hover:text-white hover:bg-white/[0.03] border border-transparent' ?>">
                                    <div class="flex items-center gap-3">
                                        <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        </div>
                                        <span class="text-xs">Perfil y Empresa</span>
                                    </div>
                                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>

                                <button type="button" onclick="switchSettingsTab('security')" data-tab="security"
                                        class="tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 <?= $activeTab === 'security' ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30 font-medium' : 'text-text-muted hover:text-white hover:bg-white/[0.03] border border-transparent' ?>">
                                    <div class="flex items-center gap-3">
                                        <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        </div>
                                        <span class="text-xs">Contraseña y Accesos</span>
                                    </div>
                                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>

                                <button type="button" onclick="switchSettingsTab('2fa')" data-tab="2fa"
                                        class="tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 <?= $activeTab === '2fa' ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30 font-medium' : 'text-text-muted hover:text-white hover:bg-white/[0.03] border border-transparent' ?>">
                                    <div class="flex items-center gap-3">
                                        <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                        </div>
                                        <span class="text-xs">Seguridad 2FA</span>
                                    </div>
                                    <span class="text-[9px] px-2 py-0.5 rounded-full font-mono <?= $twoFaActive ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30' : 'bg-white/[0.05] text-text-subtle border border-white/[0.05]' ?>">
                                        <?= $twoFaActive ? 'ON' : 'OFF' ?>
                                    </span>
                                </button>

                                <button type="button" onclick="switchSettingsTab('session')" data-tab="session"
                                        class="tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 <?= $activeTab === 'session' ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30 font-medium' : 'text-text-muted hover:text-white hover:bg-white/[0.03] border border-transparent' ?>">
                                    <div class="flex items-center gap-3">
                                        <div class="w-7 h-7 rounded-lg flex items-center justify-center bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        </div>
                                        <span class="text-xs">Sesión y Dispositivo</span>
                                    </div>
                                    <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </nav>
                        </div>

                        <!-- Mini Compliance & Security Summary Card -->
                        <div class="bg-gradient-to-br from-bg-panel/90 via-bg-panel/60 to-primary-950/20 border border-border-theme rounded-2xl p-4 backdrop-blur-md space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] font-semibold text-white flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                    Estado de Seguridad
                                </span>
                                <span class="text-[10px] font-mono text-emerald-400 font-bold">ACTIVO</span>
                            </div>
                            <div class="space-y-1.5 text-[11px]">
                                <div class="flex justify-between py-1 border-b border-white/[0.04] text-text-muted">
                                    <span>Cifrado de cuenta:</span>
                                    <span class="font-mono text-white font-medium">AES-256 GCM</span>
                                </div>
                                <div class="flex justify-between py-1 border-b border-white/[0.04] text-text-muted">
                                    <span>Normativa:</span>
                                    <span class="text-cyan-400 font-medium">Ley 21.719</span>
                                </div>
                                <div class="flex justify-between py-1 text-text-muted">
                                    <span>2FA Status:</span>
                                    <span class="<?= $twoFaActive ? 'text-emerald-400' : 'text-amber-400' ?> font-medium">
                                        <?= $twoFaActive ? 'Habilitado' : 'Recomendado' ?>
                                    </span>
                                </div>
                            </div>
                            <a href="/compliance" class="block w-full text-center py-2 px-3 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.06] text-primary-400 hover:text-primary-300 text-[11px] font-medium transition-colors">
                                Ver Centro de Cumplimiento →
                            </a>
                        </div>
                    </div>

                    <!-- Right Main Content Panels -->
                    <div class="lg:col-span-9 space-y-6">

                        <!-- TAB 1: PERFIL Y EMPRESA -->
                        <div id="tab-panel-profile" class="tab-panel space-y-6 <?= $activeTab === 'profile' ? '' : 'hidden' ?>">
                            
                            <!-- Profile Information Card -->
                            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                                <div class="px-6 py-4.5 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-bold text-white">Datos de Identificación y Empresa</h2>
                                            <p class="text-[11px] text-text-muted">Actualiza el nombre corporativo asignado a esta cuenta</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-mono px-2.5 py-1 rounded-lg bg-bg-base border border-border-theme text-text-muted">
                                        ID: <?= h(substr($user['_id'] ?? 'user_000', 0, 10)) ?>...
                                    </span>
                                </div>

                                <div class="p-6">
                                    <form method="POST" class="space-y-5">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                            <!-- Nombre o Empresa -->
                                            <div class="space-y-1.5">
                                                <label class="label-premium flex items-center justify-between">
                                                    <span>Nombre Completo / Empresa</span>
                                                    <span class="text-[10px] text-text-subtle">Visible en reportes</span>
                                                </label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                    </div>
                                                    <input type="text" name="name" required
                                                           value="<?= h($displayName) ?>"
                                                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                           placeholder="Nombre de empresa o titular">
                                                </div>
                                            </div>

                                            <!-- Rol en el sistema -->
                                            <div class="space-y-1.5">
                                                <label class="label-premium">Nivel de Privilegios / Rol</label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                                    </div>
                                                    <input type="text" disabled
                                                           value="<?= h($userRole) ?> (Plan <?= h($planType) ?>)"
                                                           class="w-full bg-[#0a0e14]/50 border border-border-theme/70 rounded-xl pl-9 pr-3 py-2.5 text-xs text-text-muted font-mono cursor-not-allowed">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="pt-2 flex justify-end">
                                            <button type="submit" name="update_profile"
                                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-primary-500/20 transition-all duration-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                Guardar Cambios de Perfil
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Email Modification Card -->
                            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                                <div class="px-6 py-4.5 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-bold text-white">Correo Electrónico de la Cuenta</h2>
                                            <p class="text-[11px] text-text-muted">Utilizado para acceso al panel y alertas de seguridad</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-mono px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        Verificado
                                    </span>
                                </div>

                                <div class="p-6">
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="action" value="change_email">
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                            <div class="space-y-1.5">
                                                <label class="label-premium">Nuevo Correo Electrónico</label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>
                                                    </div>
                                                    <input type="email" name="new_email" required
                                                           value="<?= h($userEmail) ?>"
                                                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                           placeholder="nuevo@correo.com">
                                                </div>
                                            </div>

                                            <div class="space-y-1.5">
                                                <label class="label-premium">Confirmar Contraseña Actual</label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                    </div>
                                                    <input type="password" name="email_password" required
                                                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                           placeholder="Tu contraseña de acceso">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="pt-2 flex justify-end">
                                            <button type="submit" name="change_email"
                                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-bg-elevated hover:bg-surface-700 text-text-heading border border-border-theme hover:border-surface-600 text-xs font-semibold transition-all duration-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                Actualizar Email
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: CONTRASEÑA Y ACCESOS -->
                        <div id="tab-panel-security" class="tab-panel space-y-6 <?= $activeTab === 'security' ? '' : 'hidden' ?>">
                            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                                <div class="px-6 py-4.5 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-bold text-white">Actualización de Contraseña</h2>
                                            <p class="text-[11px] text-text-muted">Recomendamos usar al menos 12 caracteres combinando letras, números y símbolos</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-mono px-2.5 py-1 rounded-lg bg-white/[0.04] text-text-subtle border border-white/[0.06]">
                                        Bcrypt 10 Rounds
                                    </span>
                                </div>

                                <div class="p-6">
                                    <form method="POST" class="space-y-5" onsubmit="return validatePasswordForm(this)">
                                        <input type="hidden" name="action" value="change_password">

                                        <!-- Contraseña actual -->
                                        <div class="space-y-1.5 max-w-lg">
                                            <label class="label-premium">Contraseña Actual</label>
                                            <div class="relative">
                                                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                </div>
                                                <input type="password" id="cur-pass" name="current_password" required
                                                       class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-10 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                       placeholder="Introduce tu contraseña actual">
                                                <button type="button" onclick="togglePassView('cur-pass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-subtle hover:text-white transition-colors">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                            <!-- Nueva contraseña -->
                                            <div class="space-y-1.5">
                                                <label class="label-premium">Nueva Contraseña</label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                                    </div>
                                                    <input type="password" id="new-pass" name="new_password" required minlength="8"
                                                           oninput="checkPasswordStrength(this.value)"
                                                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-10 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                           placeholder="Mínimo 8 caracteres">
                                                    <button type="button" onclick="togglePassView('new-pass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-subtle hover:text-white transition-colors">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                    </button>
                                                </div>

                                                <!-- Strength Meter -->
                                                <div class="pt-1.5 space-y-1">
                                                    <div class="flex gap-1 h-1.5 w-full bg-white/[0.04] rounded-full overflow-hidden p-0.5">
                                                        <div id="str-1" class="h-full w-1/4 rounded-full bg-transparent transition-all"></div>
                                                        <div id="str-2" class="h-full w-1/4 rounded-full bg-transparent transition-all"></div>
                                                        <div id="str-3" class="h-full w-1/4 rounded-full bg-transparent transition-all"></div>
                                                        <div id="str-4" class="h-full w-1/4 rounded-full bg-transparent transition-all"></div>
                                                    </div>
                                                    <p id="str-text" class="text-[10px] text-text-subtle font-medium">Seguridad de contraseña</p>
                                                </div>
                                            </div>

                                            <!-- Confirmar contraseña -->
                                            <div class="space-y-1.5">
                                                <label class="label-premium">Confirmar Nueva Contraseña</label>
                                                <div class="relative">
                                                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    </div>
                                                    <input type="password" id="conf-pass" name="confirm_password" required minlength="8"
                                                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-10 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-all"
                                                           placeholder="Repite la nueva contraseña">
                                                    <button type="button" onclick="togglePassView('conf-pass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-subtle hover:text-white transition-colors">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="pt-2 flex justify-end">
                                            <button type="submit" name="change_password"
                                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold shadow-theme-sm transition-all duration-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                Guardar Nueva Contraseña
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: AUTENTICACIÓN 2FA -->
                        <div id="tab-panel-2fa" class="tab-panel space-y-6 <?= $activeTab === '2fa' ? '' : 'hidden' ?>">
                            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                                <div class="px-6 py-4.5 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-bold text-white">Doble Factor de Autenticación (TOTP 2FA)</h2>
                                            <p class="text-[11px] text-text-muted">Protección criptográfica para accesos no autorizados mediante Google Authenticator o Authy</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-mono px-3 py-1 rounded-full border <?= $twoFaActive ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 font-semibold' : 'bg-red-500/10 text-red-400 border-red-500/30' ?>">
                                        <?= $twoFaActive ? '● 2FA ACTIVO' : '○ NO CONFIGURADO' ?>
                                    </span>
                                </div>

                                <div class="p-6">
                                    <?php if (!empty($_SESSION['pending_2fa'])): ?>
                                    <!-- Setup in progress with QR -->
                                    <div class="space-y-6 max-w-xl">
                                        <div class="p-4 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-200 text-xs">
                                            <p class="font-semibold text-white mb-1">Paso 1: Escanear código QR</p>
                                            <p class="text-[11px] text-indigo-300">Abre tu aplicación de autenticación (Google Authenticator, Microsoft Authenticator o 1Password) y escanea la imagen a continuación.</p>
                                        </div>

                                        <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6 p-4 rounded-2xl bg-black/40 border border-border-theme">
                                            <div id="qr-2fa-container" class="shrink-0 p-3 bg-white rounded-2xl shadow-xl flex items-center justify-center">
                                                <div id="qr-2fa" style="width:140px;height:140px"></div>
                                            </div>
                                            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
                                            <script>
                                            document.addEventListener('DOMContentLoaded', function () {
                                                new QRCode(document.getElementById('qr-2fa'), {
                                                    text: <?= json_encode($_SESSION['pending_2fa']['otpauth']) ?>,
                                                    width: 140,
                                                    height: 140,
                                                    colorDark: '#000000',
                                                    colorLight: '#ffffff',
                                                    correctLevel: QRCode.CorrectLevel.M
                                                });
                                            });
                                            </script>

                                            <div class="space-y-3 flex-1 min-w-0">
                                                <div>
                                                    <p class="text-[10px] uppercase tracking-wider text-text-subtle font-semibold mb-1">Clave secreta manual:</p>
                                                    <div class="flex items-center gap-2">
                                                        <code id="secret-code" class="text-xs font-mono text-cyan-300 bg-cyan-950/40 px-2.5 py-1.5 rounded-lg border border-cyan-500/30 break-all select-all">
                                                            <?= h($_SESSION['pending_2fa']['secret']) ?>
                                                        </code>
                                                        <button type="button" onclick="copySecretCode()" title="Copiar secreto" class="p-2 rounded-lg bg-bg-elevated hover:bg-surface-700 border border-border-theme text-text-body hover:text-white transition-colors">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                        </button>
                                                    </div>
                                                </div>
                                                <p class="text-[11px] text-text-muted">Si no puedes escanear el código, introduce manualmente esta clave en la app.</p>
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <p class="text-xs font-semibold text-white">Paso 2: Introduce el código de 6 dígitos generado</p>
                                            <form method="POST" class="flex flex-col sm:flex-row gap-3">
                                                <input type="hidden" name="action" value="verify_2fa">
                                                <input type="text" name="code_2fa" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                                                       placeholder="000000"
                                                       class="bg-[#0a0e14] border border-border-theme rounded-xl px-4 py-2.5 text-center font-mono text-base tracking-[0.3em] text-white focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 sm:w-48">
                                                <button type="submit" name="verify_2fa" value="1"
                                                        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-primary-600 hover:from-indigo-500 hover:to-primary-500 text-white text-xs font-semibold shadow-theme-sm transition-all duration-200">
                                                    Verificar y Confirmar 2FA
                                                </button>
                                                <button type="submit" name="cancel_2fa" value="1"
                                                        class="px-4 py-2.5 rounded-xl bg-bg-elevated hover:bg-surface-700 text-text-muted hover:text-white border border-border-theme text-xs font-medium transition-all">
                                                    Cancelar
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <?php elseif ($twoFaActive): ?>
                                    <!-- 2FA Active state -->
                                    <div class="space-y-5 max-w-xl">
                                        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-start gap-3.5">
                                            <div class="w-8 h-8 rounded-xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                            <div class="space-y-1">
                                                <p class="text-xs font-bold text-emerald-200">Tu cuenta está protegida con 2FA</p>
                                                <p class="text-[11px] text-emerald-300/80">Cada vez que inicies sesión se solicitará el token dinámico de 6 dígitos generado por tu dispositivo autenticador.</p>
                                            </div>
                                        </div>

                                        <div class="pt-2">
                                            <button type="button" onclick="document.getElementById('disable-2fa-modal').classList.remove('hidden')"
                                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-red-950/20 hover:bg-red-950/40 text-red-400 border border-red-800/30 text-xs font-medium transition-all">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                Desactivar Autenticación 2FA
                                            </button>
                                        </div>

                                        <!-- Disable 2FA Modal Dialog -->
                                        <div id="disable-2fa-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
                                            <div class="bg-bg-panel border border-border-theme rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
                                                <div class="flex items-center justify-between border-b border-border-theme pb-3">
                                                    <h3 class="text-sm font-bold text-white flex items-center gap-2 text-red-400">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                                        Confirmar desactivación de 2FA
                                                    </h3>
                                                    <button type="button" onclick="document.getElementById('disable-2fa-modal').classList.add('hidden')" class="text-text-subtle hover:text-white">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                                <p class="text-xs text-text-muted">Para desactivar el doble factor de autenticación, introduce tu código 2FA actual y tu contraseña:</p>
                                                <form method="POST" class="space-y-3">
                                                    <input type="hidden" name="action" value="disable_2fa">
                                                    <div>
                                                        <label class="label-premium">Código 2FA actual</label>
                                                        <input type="text" name="code_2fa" required maxlength="6" placeholder="000000" class="input-premium font-mono">
                                                    </div>
                                                    <div>
                                                        <label class="label-premium">Contraseña actual de la cuenta</label>
                                                        <input type="password" name="password_2fa" required placeholder="Tu contraseña" class="input-premium">
                                                    </div>
                                                    <div class="flex justify-end gap-2 pt-2">
                                                        <button type="button" onclick="document.getElementById('disable-2fa-modal').classList.add('hidden')" class="btn-secondary text-xs">Cancelar</button>
                                                        <button type="submit" name="disable_2fa" value="1" class="btn-danger text-xs">Desactivar 2FA</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <?php else: ?>
                                    <!-- 2FA Inactive state -->
                                    <div class="space-y-4 max-w-xl">
                                        <p class="text-xs text-text-body leading-relaxed">
                                            Añade una capa de seguridad crítica a tu cuenta corporativa. Con 2FA habilitado, nadie podrá acceder a tu cuenta aunque conozcan tu contraseña sin tener acceso físico a tu dispositivo.
                                        </p>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="setup_2fa">
                                            <button type="submit" name="setup_2fa" value="1"
                                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-primary-600 hover:from-indigo-500 hover:to-primary-500 text-white text-xs font-semibold shadow-theme-sm transition-all duration-200">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                Comenzar Configuración de 2FA
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 4: SESIÓN Y DISPOSITIVO -->
                        <div id="tab-panel-session" class="tab-panel space-y-6 <?= $activeTab === 'session' ? '' : 'hidden' ?>">
                            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm">
                                <div class="px-6 py-4.5 border-b border-border-theme flex items-center justify-between bg-white/[0.01]">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        </div>
                                        <div>
                                            <h2 class="text-sm font-bold text-white">Sesión Activa y Entorno de Acceso</h2>
                                            <p class="text-[11px] text-text-muted">Metadatos de conexión, navegador y protocolos de seguridad activos</p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-mono px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        Sesión Encriptada
                                    </span>
                                </div>

                                <div class="p-6 space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div class="p-4 rounded-xl bg-bg-base/70 border border-border-theme space-y-1">
                                            <p class="text-[10px] uppercase font-semibold text-text-subtle">Dirección IP</p>
                                            <p class="text-xs font-mono text-white font-medium"><?= h($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></p>
                                        </div>
                                        <div class="p-4 rounded-xl bg-bg-base/70 border border-border-theme space-y-1">
                                            <p class="text-[10px] uppercase font-semibold text-text-subtle">Protocolo de Transporte</p>
                                            <p class="text-xs font-mono text-cyan-400 font-medium">
                                                <?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'HTTPS / TLS 1.3' : 'HTTP Seguro (Proxy)' ?>
                                            </p>
                                        </div>
                                        <div class="p-4 rounded-xl bg-bg-base/70 border border-border-theme space-y-1">
                                            <p class="text-[10px] uppercase font-semibold text-text-subtle">Token de Autenticación</p>
                                            <p class="text-xs font-mono text-emerald-400 font-medium">JWT HS256 (Activo)</p>
                                        </div>
                                    </div>

                                    <div class="p-4 rounded-xl bg-bg-base/50 border border-border-theme space-y-1">
                                        <p class="text-[10px] uppercase font-semibold text-text-subtle">Agente de Navegación (User-Agent)</p>
                                        <p class="text-[11px] font-mono text-text-muted break-all"><?= h($_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido') ?></p>
                                    </div>

                                    <div class="pt-2 flex justify-end">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="logout_all">
                                            <button type="submit" name="logout_all" value="1"
                                                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-red-950/20 hover:bg-red-950/40 text-red-400 border border-red-800/30 text-xs font-semibold transition-all">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                                Cerrar Todas las Sesiones
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
function switchSettingsTab(tabId) {
    // Hide all panels
    document.querySelectorAll('.tab-panel').forEach(el => el.classList.add('hidden'));
    
    // Show active panel
    const panel = document.getElementById('tab-panel-' + tabId);
    if (panel) panel.classList.remove('hidden');

    // Update buttons styling
    document.querySelectorAll('#settings-nav .tab-btn').forEach(btn => {
        const isCurrent = btn.getAttribute('data-tab') === tabId;
        if (isCurrent) {
            btn.className = 'tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 bg-primary-500/15 text-primary-300 border border-primary-500/30 font-medium';
        } else {
            btn.className = 'tab-btn w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-left transition-all duration-200 text-text-muted hover:text-white hover:bg-white/[0.03] border border-transparent';
        }
    });

    // Update URL query param without jumping
    if (history.replaceState) {
        history.replaceState(null, null, '?tab=' + tabId);
    }
}

function togglePassView(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (!inp) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.innerHTML = '<svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/></svg>';
    } else {
        inp.type = 'password';
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
    }
}

function checkPasswordStrength(val) {
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const s1 = document.getElementById('str-1');
    const s2 = document.getElementById('str-2');
    const s3 = document.getElementById('str-3');
    const s4 = document.getElementById('str-4');
    const txt = document.getElementById('str-text');

    const colors = {
        0: 'bg-transparent',
        1: 'bg-red-500',
        2: 'bg-amber-500',
        3: 'bg-blue-500',
        4: 'bg-emerald-500'
    };

    const textLabels = {
        0: 'Seguridad de contraseña',
        1: 'Contraseña débil',
        2: 'Contraseña regular',
        3: 'Contraseña buena',
        4: '¡Contraseña excelente y robusta!'
    };

    s1.className = 'h-full w-1/4 rounded-full transition-all ' + (score >= 1 ? colors[score] : 'bg-transparent');
    s2.className = 'h-full w-1/4 rounded-full transition-all ' + (score >= 2 ? colors[score] : 'bg-transparent');
    s3.className = 'h-full w-1/4 rounded-full transition-all ' + (score >= 3 ? colors[score] : 'bg-transparent');
    s4.className = 'h-full w-1/4 rounded-full transition-all ' + (score >= 4 ? colors[score] : 'bg-transparent');

    txt.textContent = textLabels[score] || 'Seguridad de contraseña';
    txt.className = 'text-[10px] font-medium ' + (score <= 1 ? 'text-red-400' : (score === 2 ? 'text-amber-400' : (score === 3 ? 'text-blue-400' : 'text-emerald-400')));
}

function validatePasswordForm(form) {
    const p1 = form.new_password.value;
    const p2 = form.confirm_password.value;
    if (p1.length < 8) {
        alert('La nueva contraseña debe tener al menos 8 caracteres.');
        return false;
    }
    if (p1 !== p2) {
        alert('Las contraseñas nuevas no coinciden.');
        return false;
    }
    return true;
}

function copySecretCode() {
    const code = document.getElementById('secret-code');
    if (!code) return;
    navigator.clipboard.writeText(code.textContent.trim()).then(() => {
        alert('¡Clave secreta copiada al portapapeles!');
    }).catch(() => {
        prompt('Copia esta clave manualmente:', code.textContent.trim());
    });
}



// Auto-switch to tab from URL hash/query if provided
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam) switchSettingsTab(tabParam);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
