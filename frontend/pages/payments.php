<?php
$pageTitle = 'Pagos';
$currentPage = 'payments';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $res = api_post_form('/api/payments/submit', [
        'token' => $token,
        'amount' => $_POST['amount'] ?? '',
        'reference' => $_POST['reference'] ?? '',
        'month' => $_POST['month'] ?? date('n'),
        'year' => $_POST['year'] ?? date('Y'),
    ]);
    if (!empty($res['success'])) $msg = 'Pago informado. Será verificado por el equipo.';
    else $err = $res['error'] ?? 'Error al informar pago.';
}

$infoRes = api_post_form('/api/payments/my-info', ['token' => $token]);
$info = is_array($infoRes) && empty($infoRes['error']) ? $infoRes : [];
$payments = $info['payments'] ?? [];
if (!is_array($payments)) $payments = [];

$paymentStatus = $user['paymentStatus'] ?? 'active';
$statusCfg = [
    'active'  => ['label' => 'Cuenta activa', 'color' => 'text-emerald-400', 'bg' => 'bg-emerald-500/[0.06]', 'border' => 'border-emerald-500/20'],
    'pending' => ['label' => 'Pago pendiente', 'color' => 'text-amber-400', 'bg' => 'bg-amber-500/[0.06]', 'border' => 'border-amber-500/20'],
    'overdue' => ['label' => 'Pago vencido', 'color' => 'text-red-400', 'bg' => 'bg-red-500/[0.06]', 'border' => 'border-red-500/20'],
];
$status = $statusCfg[$paymentStatus] ?? $statusCfg['active'];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04]">
            <h2 class="text-[15px] font-semibold text-white tracking-tight">Pagos</h2>
            <p class="text-[11px] text-text-subtle mt-0.5 font-medium">Plan: <?= h($user['planType'] ?? 'free') ?></p>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Status card -->
            <div class="<?= $status['bg'] ?> <?= $status['border'] ?> border rounded-xl p-5 tour-detail-1">
                <div class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-xl <?= $status['bg'] ?> border <?= $status['border'] ?> flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 <?= $status['color'] ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[13px] font-semibold <?= $status['color'] ?>"><?= h($status['label']) ?></p>
                        <p class="text-[11px] text-text-muted mt-1">Plan <span class="font-medium text-text-body"><?= h($user['planType'] ?? 'free') ?></span>
                            <?php if (!empty($info['customPrice'])): ?> · <?= h($info['customPrice']) ?> UF/mes<?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Report payment -->
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Informar transferencia</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="label-premium">Monto (USD)</label>
                        <input type="number" step="0.01" name="amount" required class="input-premium" placeholder="100">
                    </div>
                    <div>
                        <label class="label-premium">Referencia</label>
                        <input type="text" name="reference" required class="input-premium" placeholder="Nº operación">
                    </div>
                    <div>
                        <label class="label-premium">Mes</label>
                        <select name="month" class="input-premium">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-premium">Año</label>
                        <select name="year" class="input-premium">
                            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="md:col-span-4 flex justify-end">
                        <button type="submit" name="submit_payment" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 text-white">Informar pago</button>
                    </div>
                </form>
            </div>

            <!-- History -->
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden tour-detail-2">
                <div class="px-5 py-4 border-b border-border-theme/20 flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg border border-white/[0.04] bg-white/[0.01] flex items-center justify-center text-emerald-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <p class="text-[12px] font-semibold text-white">Historial de pagos</p>
                </div>
                <?php if (empty($payments)): ?>
                <p class="px-5 py-8 text-[11px] text-text-subtle text-center">Sin pagos registrados.</p>
                <?php else: ?>
                <div class="divide-y divide-white/[0.03]">
                    <?php foreach ($payments as $p): ?>
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-[12px] text-text-heading font-medium">$<?= h($p['amount'] ?? 0) ?> USD — <?= h(($p['month'] ?? '') . '/' . ($p['year'] ?? '')) ?></p>
                            <p class="text-[10px] text-text-subtle">Ref: <?= h($p['reference'] ?? '-') ?> · <?= h(substr($p['createdAt'] ?? '', 0, 10)) ?></p>
                        </div>
                        <span class="text-[10px] px-2 py-0.5 rounded-full border <?= ($p['status'] ?? '') === 'verified' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20' ?>">
                            <?= ($p['status'] ?? '') === 'verified' ? 'Verificado' : 'Pendiente' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
