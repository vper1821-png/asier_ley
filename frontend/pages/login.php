<?php
require_once __DIR__ . '/../config.php';

if (is_logged_in()) {
    header('Location: /dashboard');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $res = api_request('POST', '/api/auth/login', [
        'email' => $email,
        'password' => $password,
    ]);

    if (!empty($res['body']['token'])) {
        $_SESSION['token'] = $res['body']['token'];
        $_SESSION['user'] = $res['body']['user'] ?? ['email' => $email];

        if (!empty($res['body']['user']['isActive'])) {
            header('Location: /dashboard');
        } else {
            header('Location: /pending');
        }
        exit;
    } else {
        $error = $res['body']['error'] ?? 'Error al iniciar sesión. Verifica tus credenciales.';
    }
}

$pageTitle = 'Iniciar sesión';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-bg-base text-[13px] text-text-body flex items-center justify-center px-4">
    <div class="w-full max-w-sm px-6">
        <div class="flex flex-col items-center mb-8">
            <a href="/" class="inline-flex items-center gap-2.5 mb-4">
                <div class="w-10 h-10 rounded-xl overflow-hidden bg-bg-panel border border-border-theme flex items-center justify-center">
                    <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
                </div>
                <span class="text-xl font-bold text-white tracking-tight">SecureLab</span>
            </a>
            <h1 class="text-2xl font-bold text-white mb-1">Iniciar sesión</h1>
            <p class="text-sm text-text-muted">Accede a tu panel de control</p>
        </div>

        <form method="POST" class="space-y-4">
            <?php if ($error): ?>
            <div class="p-2.5 bg-red-500/10 border border-red-500/30 rounded-md text-red-400 text-xs text-center">
                <?= h($error) ?>
            </div>
            <?php endif; ?>

            <div>
                <label class="label-premium">Email</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>
                    </div>
                    <input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"
                           class="w-full bg-[#0f1419] border border-[#1f2937] rounded-md pl-9 pr-3 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-[#3b82f6] transition-colors"
                           placeholder="tu@email.com">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <label class="label-premium">Contraseña</label>
                    <button type="button" onclick="openForgotModal()" class="text-xs text-primary-400 hover:text-primary-300">¿Olvidaste tu contraseña?</button>
                </div>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <input id="password" type="password" name="password" required
                           class="w-full bg-[#0f1419] border border-border-theme rounded-md pl-9 pr-10 py-2 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 transition-colors"
                           placeholder="Tu contraseña">
                    <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-muted hover:text-text-body">
                        <svg id="eye-open" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <svg id="eye-slash" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">
                <span>Iniciar sesión</span>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </button>

            <p class="text-center text-xs text-text-muted pt-2">
                ¿No tienes cuenta?
                <a href="/register" class="text-primary-400 hover:text-primary-300 font-medium">Regístrate aquí</a>
            </p>
        </form>

        <div id="forgot-modal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
            <div class="bg-[#0b0b0f] border border-[#1a1a1f] rounded-lg w-full max-w-md">
                <div class="px-5 py-4 border-b border-[#1a1a1f] flex items-center justify-between">
                    <div>
                        <h3 class="text-[13px] font-semibold text-white">Restablecer contraseña</h3>
                        <p class="text-[10px] text-text-muted">Te enviaremos instrucciones a tu email</p>
                    </div>
                    <button type="button" onclick="closeForgotModal()" class="text-text-muted hover:text-text-body">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <form id="reset-form" onsubmit="sendReset(event)" class="p-5 space-y-4">
                    <div>
                        <label class="label-premium">Email</label>
                        <input id="reset-email" type="email" required class="input-premium w-full" placeholder="tu@email.com">
                    </div>
                    <div id="reset-success" class="hidden p-3 bg-green-500/10 border border-green-500/30 rounded text-green-400 text-[11px]">
                        Si el email existe en nuestra base de datos, recibirás las instrucciones para restablecer tu contraseña.
                    </div>
                    <div id="reset-error" class="hidden p-3 bg-red-500/10 border border-red-500/30 rounded text-red-400 text-[11px]"></div>
                </form>
                <div class="px-5 py-4 border-t border-[#1a1a1f] flex justify-end space-x-2">
                    <button type="button" onclick="closeForgotModal()" class="btn-secondary px-4 py-2">Cancelar</button>
                    <button type="submit" form="reset-form" id="reset-submit" class="btn-primary px-4 py-2">Enviar instrucciones</button>
                </div>
            </div>
        </div>

        <div class="mt-6 text-center">
            <a href="/" class="text-sm text-text-subtle hover:text-text-muted transition-colors">← Volver al inicio</a>
        </div>
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const open = document.getElementById('eye-open');
    const slash = document.getElementById('eye-slash');
    if (input.type === 'password') {
        input.type = 'text';
        open.classList.add('hidden');
        slash.classList.remove('hidden');
    } else {
        input.type = 'password';
        open.classList.remove('hidden');
        slash.classList.add('hidden');
    }
}

function openForgotModal() {
    document.getElementById('forgot-modal').classList.remove('hidden');
    document.getElementById('forgot-modal').classList.add('flex');
    const loginEmail = document.querySelector('input[name="email"]');
    const resetEmail = document.getElementById('reset-email');
    if (loginEmail && loginEmail.value) resetEmail.value = loginEmail.value;
    resetEmail.focus();
}

function closeForgotModal() {
    document.getElementById('forgot-modal').classList.add('hidden');
    document.getElementById('forgot-modal').classList.remove('flex');
    document.getElementById('reset-form').reset();
    document.getElementById('reset-success').classList.add('hidden');
    document.getElementById('reset-error').classList.add('hidden');
}

async function sendReset(e) {
    e.preventDefault();
    const email = document.getElementById('reset-email').value;
    const btn = document.getElementById('reset-submit');
    const success = document.getElementById('reset-success');
    const error = document.getElementById('reset-error');
    success.classList.add('hidden');
    error.classList.add('hidden');
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Enviando...';
    try {
        const res = await fetch('/api/auth/forgot-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        const data = await res.json();
        if (data.error) {
            error.textContent = data.error;
            error.classList.remove('hidden');
        } else {
            success.classList.remove('hidden');
            document.getElementById('reset-form').reset();
        }
    } catch (err) {
        error.textContent = 'Error de conexión';
        error.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
