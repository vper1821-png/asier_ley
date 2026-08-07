<?php
$pageTitle = 'Monitor de Usuario';
require_once __DIR__ . '/../includes/header.php';
require_admin();

$userId = $_GET['userId'] ?? '';
$token = $_SESSION['token'] ?? '';

$userData = null;
$error = '';

if ($userId) {
    $res = api_post_form('/api/admin/user/' . $userId, ['token' => $token]);
    if (!empty($res['error'])) {
        $error = $res['error'];
    } else {
        $userData = $res;
    }
}
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'settings'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <header class="flex-shrink-0 px-6 h-14 flex items-center justify-between border-b border-border-theme">
            <div class="flex items-center gap-3">
                <a href="/admin" class="text-text-muted hover:text-text-body transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <h1 class="text-sm font-semibold text-white">Monitor de Usuario</h1>
            </div>
        </header>
        <div class="flex-1 overflow-y-auto p-4">
            <div class="max-w-4xl">
                <?php if ($error): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-8 text-center">
                    <p class="text-red-400"><?= h($error) ?></p>
                </div>
                <?php elseif ($userData): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4">Información del usuario</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <p class="text-[10px] text-text-subtle uppercase tracking-wider mb-1">Email</p>
                            <p class="text-sm text-text-heading"><?= h($userData['email'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-text-subtle uppercase tracking-wider mb-1">Nombre</p>
                            <p class="text-sm text-text-heading"><?= h($userData['name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-text-subtle uppercase tracking-wider mb-1">Estado</p>
                            <span class="text-[11px] px-2 py-0.5 rounded-full <?= !empty($userData['isActive']) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' ?>">
                                <?= !empty($userData['isActive']) ? 'Activo' : 'Pendiente' ?>
                            </span>
                        </div>
                        <div>
                            <p class="text-[10px] text-text-subtle uppercase tracking-wider mb-1">Admin</p>
                            <p class="text-sm text-text-heading"><?= !empty($userData['isAdmin']) ? 'Sí' : 'No' ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
