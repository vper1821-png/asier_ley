<?php
$pageTitle = 'Firmar documento';
require_once __DIR__ . '/../includes/header.php';

$inviteToken = $_GET['token'] ?? '';
$error = '';
$success = false;
$document = null;

if ($inviteToken) {
    $res = api_request('POST', '/api/compliance/verify-invite', ['token' => $inviteToken]);
    if (!empty($res['body']['error'])) {
        $error = $res['body']['error'];
    } else {
        $document = $res['body'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inviteToken) {
    $res = api_request('POST', '/api/compliance/sign', [
        'inviteToken' => $inviteToken,
        'signature' => $_POST['signature'] ?? '',
        'name' => $_POST['name'] ?? '',
    ]);
    if (!empty($res['body']['success'])) {
        $success = true;
    } else {
        $error = $res['body']['error'] ?? 'Error al firmar.';
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

    <div class="min-h-screen flex items-center justify-center px-4 pt-20 pb-12">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Firma de documento</h1>
            <p class="text-text-muted">Has sido invitado a firmar un documento de compliance</p>
        </div>

        <?php if ($success): ?>
        <div class="glass-card p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Documento firmado</h2>
            <p class="text-text-muted">El documento ha sido firmado correctamente.</p>
        </div>
        <?php elseif ($error && !$document): ?>
        <div class="glass-card p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Error</h2>
            <p class="text-text-muted"><?= h($error) ?></p>
        </div>
        <?php elseif ($document): ?>
        <div class="glass-card p-6">
            <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php endif; ?>
            <div class="mb-6 p-4 rounded-lg border border-border-theme bg-bg-elevated/50">
                <h3 class="text-white font-semibold mb-2"><?= h($document['title'] ?? 'Documento') ?></h3>
                <p class="text-sm text-text-muted"><?= h($document['description'] ?? 'Sin descripción') ?></p>
            </div>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="label-premium">Tu nombre completo</label>
                    <input type="text" name="name" required class="input-premium" placeholder="Nombre y apellido">
                </div>
                <div>
                    <label class="label-premium">Firma</label>
                    <input type="text" name="signature" required class="input-premium" placeholder="Escribe tu nombre como firma">
                </div>
                <button type="submit" class="btn-glow w-full py-3 text-sm">Firmar documento</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
