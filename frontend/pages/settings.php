<?php
$pageTitle = 'Ajustes';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $res = api_request('POST', '/api/account/update', [
            'token' => $token,
            'name' => $_POST['name'] ?? '',
        ]);
        if (empty($res['body']['error'])) {
            $_SESSION['user']['name'] = $_POST['name'] ?? '';
            $success = 'Perfil actualizado correctamente.';
        } else {
            $error = $res['body']['error'];
        }
    } elseif (isset($_POST['setup_2fa'])) {
        $res = api_post_form('/api/2fa/setup', ['token' => $token]);
        if (!empty($res['secret'])) {
            $_SESSION['pending_2fa'] = ['secret' => $res['secret'], 'otpauth' => $res['qrCodeUrl'] ?? ($res['otpauth'] ?? ($res['otpauthUrl'] ?? ''))];
        } else {
            $error = $res['error'] ?? 'Error al iniciar configuración 2FA.';
        }
    } elseif (isset($_POST['verify_2fa'])) {
        $res = api_post_form('/api/2fa/verify', ['token' => $token, 'code' => $_POST['code_2fa'] ?? '']);
        if (!empty($res['success'])) {
            $success = '2FA activado correctamente.';
            $_SESSION['user']['twoFactorEnabled'] = true;
            unset($_SESSION['pending_2fa']);
        } else {
            $error = $res['error'] ?? 'Código inválido.';
        }
    } elseif (isset($_POST['disable_2fa'])) {
        $res = api_post_form('/api/2fa/disable', ['token' => $token, 'code' => $_POST['code_2fa'] ?? '', 'password' => $_POST['password_2fa'] ?? '']);
        if (!empty($res['success'])) {
            $success = '2FA desactivado.';
            $_SESSION['user']['twoFactorEnabled'] = false;
        } else {
            $error = $res['error'] ?? 'Error al desactivar 2FA.';
        }
    } elseif (isset($_POST['change_password'])) {
        if (($_POST['new_password'] ?? '') !== ($_POST['confirm_password'] ?? '')) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            $res = api_request('POST', '/api/account/change-password', [
                'token' => $token,
                'currentPassword' => $_POST['current_password'] ?? '',
                'newPassword' => $_POST['new_password'] ?? '',
            ]);
            if (empty($res['body']['error'])) {
                $success = 'Contraseña cambiada correctamente.';
            } else {
                $error = $res['body']['error'];
            }
        }
    }
}
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'settings'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <header class="flex-shrink-0 border-b border-border-theme px-6 h-14 flex items-center">
            <h1 class="text-sm font-semibold text-white">Ajustes</h1>
        </header>
        <div class="flex-1 overflow-y-auto p-4">
            <div class="max-w-2xl space-y-6">
                <?php if ($success): ?>
                <div class="flex items-center gap-2 px-4 py-3 text-[12px] rounded-xl border bg-emerald-500/10 text-emerald-400 border-emerald-500/20">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?= h($success) ?>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="flex items-center gap-2 px-4 py-3 text-[12px] rounded-xl border bg-red-500/10 text-red-400 border-red-500/20">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <?= h($error) ?>
                </div>
                <?php endif; ?>

                <!-- Profile -->
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden relative">
                    <div class="absolute left-0 top-3 bottom-3 w-1 rounded-full bg-cyan-500 shadow-[0_0_10px_rgba(6,182,212,0.5)]"></div>
                    <div class="flex items-center gap-4 px-6 py-4 border-b border-border-theme bg-bg-base/20">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-[13px] font-semibold text-white tracking-wide">Perfil</h3>
                            <p class="text-[11px] text-text-muted">Información básica de la cuenta</p>
                        </div>
                    </div>
                    <div class="p-6 space-y-4">
                        <form method="POST" class="space-y-4">
                            <div>
                                <label class="label-premium">Email</label>
                                <input type="email" disabled class="input-premium" value="<?= h($user['email'] ?? '') ?>">
                            </div>
                            <div>
                                <label class="label-premium">Nombre / Empresa</label>
                                <input type="text" name="name" class="input-premium" value="<?= h($user['name'] ?? $user['companyName'] ?? '') ?>">
                            </div>
                            <button type="submit" name="update_profile" class="px-5 py-2.5 text-[12px] font-medium rounded-xl transition-all bg-bg-elevated text-text-body border border-border-theme hover:bg-bg-elevated hover:text-text-heading">Guardar cambios</button>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden relative">
                    <div class="absolute left-0 top-3 bottom-3 w-1 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div>
                    <div class="flex items-center gap-4 px-6 py-4 border-b border-border-theme bg-bg-base/20">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-[13px] font-semibold text-white tracking-wide">Cambiar contraseña</h3>
                            <p class="text-[11px] text-text-muted">Actualiza tu contraseña de acceso</p>
                        </div>
                    </div>
                    <div class="p-6 space-y-4">
                        <form method="POST" class="space-y-4">
                            <div>
                                <label class="label-premium">Contraseña actual</label>
                                <input type="password" name="current_password" required class="input-premium">
                            </div>
                            <div>
                                <label class="label-premium">Nueva contraseña</label>
                                <input type="password" name="new_password" required class="input-premium" placeholder="Mínimo 8 caracteres">
                            </div>
                            <div>
                                <label class="label-premium">Confirmar nueva contraseña</label>
                                <input type="password" name="confirm_password" required class="input-premium">
                            </div>
                            <button type="submit" name="change_password" class="px-5 py-2.5 text-[12px] font-medium rounded-xl transition-all bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20">Cambiar contraseña</button>
                        </form>
                    </div>
                </div>

                <!-- 2FA -->
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden relative">
                    <div class="absolute left-0 top-3 bottom-3 w-1 rounded-full bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.5)]"></div>
                    <div class="flex items-center gap-4 px-6 py-4 border-b border-border-theme bg-bg-base/20">
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-[13px] font-semibold text-white tracking-wide">Autenticación de dos factores (2FA)</h3>
                            <p class="text-[11px] text-text-muted">Protege tu cuenta con TOTP (Google Authenticator, Authy...)</p>
                        </div>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border <?= !empty($user['twoFactorEnabled']) ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-white/[0.04] text-text-subtle border-white/[0.06]' ?>">
                            <?= !empty($user['twoFactorEnabled']) ? 'Activado' : 'Desactivado' ?>
                        </span>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php if (!empty($_SESSION['pending_2fa'])): ?>
                        <div class="space-y-3">
                            <p class="text-[11px] text-text-body">Escanea este código en tu app de autenticación o introduce el secreto manualmente:</p>
                            <div class="flex flex-col sm:flex-row items-center gap-4">
                                <div id="qr-2fa" class="rounded-lg border border-border-theme bg-white p-1.5" style="width:152px;height:152px"></div>
                                <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
                                <script>
                                document.addEventListener('DOMContentLoaded', function () {
                                    new QRCode(document.getElementById('qr-2fa'), {
                                        text: <?= json_encode($_SESSION['pending_2fa']['otpauth']) ?>,
                                        width: 140, height: 140,
                                        colorDark: '#000000', colorLight: '#ffffff',
                                    });
                                });
                                </script>
                                <div class="flex-1">
                                    <p class="text-[10px] text-text-subtle mb-1">Secreto:</p>
                                    <code class="text-[11px] text-text-body font-mono break-all bg-bg-input px-2 py-1 rounded border border-border-theme"><?= h($_SESSION['pending_2fa']['secret']) ?></code>
                                </div>
                            </div>
                            <form method="POST" class="flex gap-2">
                                <input type="text" name="code_2fa" required maxlength="6" placeholder="Código de 6 dígitos" class="input-premium w-44">
                                <button type="submit" name="verify_2fa" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-indigo-600 to-purple-600 text-white">Verificar y activar</button>
                            </form>
                        </div>
                        <?php elseif (!empty($user['twoFactorEnabled'])): ?>
                        <form method="POST" class="flex flex-col sm:flex-row gap-2">
                            <input type="text" name="code_2fa" placeholder="Código 2FA" class="input-premium sm:w-40">
                            <input type="password" name="password_2fa" placeholder="Contraseña" class="input-premium sm:w-48">
                            <button type="submit" name="disable_2fa" value="1" onclick="return confirm('¿Desactivar 2FA?')" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Desactivar 2FA</button>
                        </form>
                        <?php else: ?>
                        <form method="POST">
                            <button type="submit" name="setup_2fa" value="1" class="px-5 py-2.5 text-[12px] font-medium rounded-xl transition-all bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20">Configurar 2FA</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
