<?php
$pageTitle = 'Panel DPO';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
$arcoRes = api_post_form('/api/arco/requests', ['token' => $token]);
$requests = is_array($arcoRes) && empty($arcoRes['error']) ? $arcoRes : [];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'dpo'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <header class="flex-shrink-0 px-6 h-14 flex items-center border-b border-border-theme">
            <h1 class="text-sm font-semibold text-white">Delegado de Protección de Datos (DPO)</h1>
        </header>
        <div class="flex-1 overflow-y-auto p-4">
            <div class="max-w-5xl space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                        <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-wider mb-2">Solicitudes ARCO</p>
                        <p class="text-2xl font-bold text-white"><?= count($requests) ?></p>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                        <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-wider mb-2">Pendientes</p>
                        <p class="text-2xl font-bold text-amber-400"><?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'pending')) ?></p>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                        <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-wider mb-2">Completadas</p>
                        <p class="text-2xl font-bold text-emerald-400"><?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'completed')) ?></p>
                    </div>
                </div>

                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4">Solicitudes ARCO</h3>
                    <?php if (empty($requests)): ?>
                    <p class="text-text-muted text-sm text-center py-8">No hay solicitudes ARCO registradas.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="text-[11px] text-text-subtle uppercase tracking-wider">
                                    <th class="text-left py-2 px-3">Solicitante</th>
                                    <th class="text-left py-2 px-3">Tipo</th>
                                    <th class="text-left py-2 px-3">Estado</th>
                                    <th class="text-left py-2 px-3">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): ?>
                                <tr class="border-t border-border-theme">
                                    <td class="py-2.5 px-3 text-text-heading"><?= h($req['solicitante']['nombre'] ?? 'N/A') ?></td>
                                    <td class="py-2.5 px-3 text-text-muted"><?= h($req['tipo'] ?? 'N/A') ?></td>
                                    <td class="py-2.5 px-3">
                                        <span class="text-[11px] px-2 py-0.5 rounded-full <?= ($req['status'] ?? '') === 'completed' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' ?>">
                                            <?= h($req['status'] ?? 'pending') ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-text-subtle text-[11px]"><?= h($req['createdAt'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
