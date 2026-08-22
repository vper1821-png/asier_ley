<?php
require_once __DIR__ . '/../config.php';

if (is_logged_in()) {
    header('Location: /dashboard');
    exit;
}

$error = '';
$success = false;

// Handle session storage via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'session') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $token = $body['token'] ?? '';
    $user = $body['user'] ?? [];

    if ($token && $user) {
        $_SESSION['token'] = $token;
        $_SESSION['user'] = $user;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
    }
    exit;
}

$pageTitle = 'Crear cuenta';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/turnstile.php';
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

        <form class="space-y-4" id="register-form">
            <input type="hidden" name="captchaToken" id="captchaToken">
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

            <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY): ?>
            <div class="flex justify-center">
                <div class="cf-turnstile" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
            </div>
            <?php endif; ?>

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

<script>
document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY && TURNSTILE_SITE_KEY !== ''): ?>
    const turnstileResponse = document.querySelector('.cf-turnstile')?.querySelector('textarea')?.value;
    if (turnstileResponse) {
        document.getElementById('captchaToken').value = turnstileResponse;
    }
    <?php else: ?>
    // Development: generate dummy token
    document.getElementById('captchaToken').value = 'development-bypass';
    <?php endif; ?>

    const email = document.querySelector('input[name="email"]').value;
    const password = document.querySelector('input[name="password"]').value;
    const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
    const name = document.querySelector('input[name="name"]').value;
    const captchaToken = document.getElementById('captchaToken').value;

    if (password !== confirmPassword) {
        showError('Las contraseñas no coinciden.');
        return;
    }

    if (password.length < 8) {
        showError('La contraseña debe tener al menos 8 caracteres.');
        return;
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span>Creando cuenta...</span>';

    try {
        const res = await fetch('<?= API_BASE_URL_BROWSER ?>/api/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, name, captchaToken })
        });
        const data = await res.json();

        if (data.token) {
            // Store in session via PHP
            await fetch('/register?action=session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: data.token, user: data.user })
            });

            window.location.href = '/pending';
        } else {
            showError(data.error || 'Error al crear la cuenta.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    } catch (err) {
        showError('Error de conexión. Intenta nuevamente.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
});

function showError(message) {
    const errorDiv = document.querySelector('.bg-red-500\\/10');
    if (errorDiv) {
        errorDiv.remove();
    }
    const form = document.getElementById('register-form');
    const errorHtml = `<div class="p-2.5 bg-red-500/10 border border-red-500/30 rounded-md text-red-400 text-xs text-center">${message}</div>`;
    form.insertAdjacentHTML('afterbegin', errorHtml);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
