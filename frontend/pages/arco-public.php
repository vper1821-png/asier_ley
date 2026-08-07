<?php
$pageTitle = 'Solicitud ARCO';
require_once __DIR__ . '/../includes/header.php';

$error = '';
$success = false;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'solicitante' => [
            'nombre' => $_POST['nombre'] ?? '',
            'rut' => $_POST['rut'] ?? '',
            'email' => $_POST['email'] ?? '',
            'telefono' => $_POST['telefono'] ?? '',
        ],
        'tipo' => $_POST['tipo'] ?? 'acceso',
        'descripcion' => $_POST['descripcion'] ?? '',
        'captchaToken' => 'development-bypass',
    ];

    $res = api_request('POST', '/api/arco/requests', $data);
    if (!empty($res['body']['success'])) {
        $success = true;
        $result = $res['body'];
    } else {
        $error = $res['body']['error'] ?? 'Error al enviar la solicitud.';
    }
}
?>

<div class="min-h-screen bg-bg-base text-[13px] text-text-body">
    <nav class="fixed top-0 left-0 right-0 z-50 bg-bg-base/80 backdrop-blur-xl border-b border-border-theme">
        <div class="max-w-7xl mx-auto px-6 h-14 flex items-center justify-between">
            <a href="/" class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-lg overflow-hidden bg-bg-panel flex items-center justify-center">
                    <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
                </div>
                <span class="text-[15px] font-bold text-white tracking-tight">SecureLab</span>
            </a>
            <a href="/" class="text-[12px] font-medium text-text-muted hover:text-text-heading transition-colors flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Volver
            </a>
        </div>
    </nav>

    <div class="max-w-2xl mx-auto px-4 pt-28 pb-12">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Solicitud ARCO</h1>
            <p class="text-text-muted">Ejerce tus derechos de Acceso, Rectificación, Cancelación u Oposición</p>
        </div>

        <?php if ($success): ?>
        <div class="glass-card p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Solicitud enviada</h2>
            <p class="text-text-muted mb-2">Tu solicitud ha sido registrada con el ID:</p>
            <p class="text-primary-400 font-mono text-lg mb-4"><?= h($result['requestId'] ?? 'N/A') ?></p>
            <p class="text-sm text-text-muted">Recibirás una respuesta en tu email en un plazo máximo de 30 días.</p>
        </div>
        <?php else: ?>
        <div class="glass-card p-6">
            <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="label-premium">Nombre completo *</label>
                        <input type="text" name="nombre" required class="input-premium" value="<?= h($_POST['nombre'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label-premium">RUT *</label>
                        <input type="text" name="rut" required class="input-premium" placeholder="12.345.678-9" value="<?= h($_POST['rut'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label-premium">Email *</label>
                        <input type="email" name="email" required class="input-premium" value="<?= h($_POST['email'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="label-premium">Teléfono</label>
                        <input type="text" name="telefono" class="input-premium" value="<?= h($_POST['telefono'] ?? '') ?>">
                    </div>
                </div>
                <div>
                    <label class="label-premium">Tipo de solicitud *</label>
                    <select name="tipo" required class="input-premium">
                        <option value="acceso" <?= ($_POST['tipo'] ?? '') === 'acceso' ? 'selected' : '' ?>>Acceso - Consultar mis datos</option>
                        <option value="rectificacion" <?= ($_POST['tipo'] ?? '') === 'rectificacion' ? 'selected' : '' ?>>Rectificación - Corregir mis datos</option>
                        <option value="cancelacion" <?= ($_POST['tipo'] ?? '') === 'cancelacion' ? 'selected' : '' ?>>Cancelación - Eliminar mis datos</option>
                        <option value="oposicion" <?= ($_POST['tipo'] ?? '') === 'oposicion' ? 'selected' : '' ?>>Oposición - No tratar mis datos</option>
                    </select>
                </div>
                <div>
                    <label class="label-premium">Descripción</label>
                    <textarea name="descripcion" rows="3" class="input-premium" placeholder="Describe tu solicitud..."><?= h($_POST['descripcion'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn-glow w-full py-3 text-sm">Enviar solicitud</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
