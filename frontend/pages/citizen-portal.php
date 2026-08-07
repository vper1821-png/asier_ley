<?php
$pageTitle = 'Portal Ciudadano';
require_once __DIR__ . '/../includes/header.php';

$trackingId = $_GET['id'] ?? '';
$result = null;
$error = '';

if ($trackingId) {
    $res = api_request('POST', '/api/arco/track', ['trackingId' => $trackingId]);
    if (!empty($res['body']['error'])) {
        $error = $res['body']['error'];
    } else {
        $result = $res['body'];
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

    <div class="max-w-xl mx-auto px-4 pt-28 pb-12">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Seguimiento de solicitud</h1>
            <p class="text-text-muted">Consulta el estado de tu solicitud ARCO</p>
        </div>

        <div class="glass-card p-6">
            <form method="GET" class="flex gap-3 mb-6">
                <input type="text" name="id" required class="input-premium flex-1" placeholder="ID de seguimiento" value="<?= h($trackingId) ?>">
                <button type="submit" class="btn-glow px-6 py-2.5 text-sm flex-shrink-0">Buscar</button>
            </form>

            <?php if ($error): ?>
            <div class="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php elseif ($result): ?>
            <div class="p-4 rounded-lg border border-border-theme bg-bg-elevated/50">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-text-muted">Estado</span>
                    <span class="text-sm font-medium px-2.5 py-1 rounded-full <?= ($result['status'] ?? '') === 'completed' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' ?>">
                        <?= h($result['status'] ?? 'pending') ?>
                    </span>
                </div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm text-text-muted">Tipo</span>
                    <span class="text-sm text-text-body"><?= h($result['tipo'] ?? 'N/A') ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-text-muted">Fecha</span>
                    <span class="text-sm text-text-body"><?= h($result['createdAt'] ?? 'N/A') ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
