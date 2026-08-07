<?php
require_once __DIR__ . '/../config.php';
require_login();

$pageTitle = 'Cuenta pendiente';
$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = api_post_form('/api/onboarding', [
        'token' => $token,
        'companyName' => $_POST['companyName'] ?? '',
        'domain' => $_POST['domain'] ?? '',
        'planType' => $_POST['planType'] ?? 'free',
    ]);
    if (!empty($res['success'])) {
        $success = 'Datos guardados. Tu cuenta será revisada por un administrador.';
    } else {
        $error = $res['error'] ?? 'Error al guardar los datos.';
    }
}

$onboarding = api_get('/api/onboarding', ['token' => $token]);
if (!is_array($onboarding)) $onboarding = [];

$companyName = $_POST['companyName'] ?? ($onboarding['companyName'] ?? $user['companyName'] ?? '');
$domain = $_POST['domain'] ?? ($onboarding['domain'] ?? $user['domain'] ?? '');
$planType = $_POST['planType'] ?? ($onboarding['planType'] ?? $user['planType'] ?? 'free');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-bg-base text-[13px] text-text-body flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-lg">
        <div class="w-16 h-16 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto mb-6">
            <svg class="w-8 h-8 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white text-center mb-3">Cuenta pendiente de activación</h1>
        <p class="text-text-muted text-center mb-8 max-w-md mx-auto">
            Completa la información de tu empresa para que un administrador pueda aprobar tu cuenta.
        </p>

        <?php if ($success): ?>
        <div class="mb-4 p-3 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400 text-[12px] text-center">
            <?= h($success) ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-400 text-[12px] text-center">
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="bg-bg-panel/60 border border-border-theme/25 rounded-2xl p-6 space-y-4">
            <div>
                <label class="label-premium">Nombre de la empresa</label>
                <input type="text" name="companyName" value="<?= h($companyName) ?>" required
                    class="input-premium w-full" placeholder="Ej: SecureLab SpA">
            </div>
            <div>
                <label class="label-premium">Dominio / Sitio web</label>
                <input type="text" name="domain" value="<?= h($domain) ?>"
                    class="input-premium w-full" placeholder="Ej: securelab.cl">
            </div>
            <div>
                <label class="label-premium">Plan solicitado</label>
                <select name="planType" class="input-premium w-full">
                    <option value="free" <?= $planType === 'free' ? 'selected' : '' ?>>Free</option>
                    <option value="Pro" <?= $planType === 'Pro' ? 'selected' : '' ?>>Pro</option>
                    <option value="Enterprise" <?= $planType === 'Enterprise' ? 'selected' : '' ?>>Enterprise</option>
                </select>
            </div>
            <button type="submit" class="btn-primary w-full">
                Guardar y solicitar aprobación
            </button>
        </form>

        <div class="mt-6 text-center">
            <a href="/logout" class="text-sm text-text-subtle hover:text-red-400 transition-colors">
                Cerrar sesión
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
