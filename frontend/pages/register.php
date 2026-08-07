<?php
require_once __DIR__ . '/../config.php';

if (is_logged_in()) {
    header('Location: /dashboard');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $name = $_POST['name'] ?? '';

    if ($password !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        $res = api_request('POST', '/api/auth/register', [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        if (!empty($res['body']['token'])) {
            $_SESSION['token'] = $res['body']['token'];
            $_SESSION['user'] = $res['body']['user'] ?? ['email' => $email];
            header('Location: /pending');
            exit;
        } else {
            $error = $res['body']['error'] ?? 'Error al crear la cuenta.';
        }
    }
}

$pageTitle = 'Crear cuenta';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-bg-base text-[13px] text-text-body flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-sm px-6">
        <div class="flex flex-col items-center mb-8">
            <a href="/" class="inline-flex items-center gap-2.5 mb-4">
                <div class="w-16 h-16 flex items-center justify-center">
                    <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
                </div>
            </a>
            <h1 class="text-2xl font-bold text-white mb-1">Crear cuenta</h1>
            <p class="text-sm text-text-muted">Comienza a proteger los datos de tu organización</p>
        </div>

        <form method="POST" class="space-y-4">
            <?php if ($error): ?>
            <div class="p-2.5 bg-red-500/10 border border-red-500/30 rounded-md text-red-400 text-xs text-center">
                <?= h($error) ?>
            </div>
            <?php endif; ?>

            <div>
                <label class="label-premium">Nombre de la Empresa</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <input type="text" name="name" required value="<?= h($_POST['name'] ?? '') ?>"
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="Nombre legal de tu empresa">
                </div>
            </div>

            <div>
                <label class="label-premium">
                    Dominio <span class="text-text-subtle font-normal">(opcional)</span>
                </label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    </div>
                    <input type="text" name="domain" value="<?= h($_POST['domain'] ?? '') ?>"
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="tudominio.com">
                </div>
            </div>

            <div>
                <label class="label-premium">Email</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>
                    </div>
                    <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="tu@email.com">
                </div>
            </div>

            <div>
                <label class="label-premium">Contraseña</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <input type="password" name="password" required
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="Mínimo 8 caracteres">
                </div>
            </div>

            <div>
                <label class="label-premium">Confirmar contraseña</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <input type="password" name="confirm_password" required
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="Repite tu contraseña">
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">
                <span>Crear cuenta</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </button>

            <p class="text-center text-xs text-text-muted pt-2">
                ¿Ya tienes cuenta?
                <a href="/login" class="text-primary-400 hover:text-primary-300 font-medium">Inicia sesión</a>
            </p>
        </form>

        <div class="mt-6 text-center">
            <a href="/" class="text-sm text-text-subtle hover:text-text-muted transition-colors">← Volver al inicio</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
