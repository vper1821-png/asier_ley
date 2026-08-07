<?php
$pageTitle = 'Reportes';
$currentPage = 'reports';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $res = api_post_form('/api/reports/generate', ['token' => $token, 'type' => $_POST['report_type'] ?? 'compliance']);
    if (!empty($res['success']) || !empty($res['report'])) $msg = 'Reporte generado.';
    else $err = $res['error'] ?? 'Error al generar reporte.';
}

$reportsRes = api_post_form('/api/reports/list', ['token' => $token]);
$reports = is_array($reportsRes) && empty($reportsRes['error']) ? ($reportsRes['reports'] ?? $reportsRes) : [];
if (!is_array($reports)) $reports = [];

$monthStart = date('Y-m-01');
$thisMonth = array_filter($reports, fn($r) => ($r['createdAt'] ?? '') >= $monthStart);
$last = $reports[0] ?? null;
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Reportes</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($reports) ?> reportes · <?= count($thisMonth) ?> este mes</p>
            </div>
            <form method="POST" class="flex items-center gap-2">
                <select name="report_type" class="input-premium text-[11px] py-1.5">
                    <option value="compliance">Cumplimiento Ley 21.719</option>
                    <option value="security">Seguridad</option>
                    <option value="training">Capacitación</option>
                </select>
                <button type="submit" name="generate_report" value="1"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Generar reporte
                </button>
            </form>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 tour-detail-1">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <p class="text-[10px] font-medium text-text-muted uppercase tracking-widest mb-2">Total Reportes</p>
                    <p class="text-[22px] font-bold text-white leading-none"><?= count($reports) ?></p>
                    <p class="text-[10px] text-white/20 mt-1.5"><?= count($thisMonth) ?> este mes</p>
                </div>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <p class="text-[10px] font-medium text-text-muted uppercase tracking-widest mb-2">Reportes Este Mes</p>
                    <p class="text-[22px] font-bold text-[#22d3ee] leading-none"><?= count($thisMonth) ?></p>
                </div>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <p class="text-[10px] font-medium text-text-muted uppercase tracking-widest mb-2">Último Reporte</p>
                    <p class="text-[22px] font-bold text-[#22c55e] leading-none"><?= $last ? h(substr($last['createdAt'] ?? '-', 0, 10)) : '-' ?></p>
                </div>
            </div>

            <!-- Table -->
            <?php if (empty($reports)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center">
                <h3 class="text-white font-semibold mb-2">Sin reportes</h3>
                <p class="text-text-muted text-[12px]">Genera tu primer reporte de cumplimiento.</p>
            </div>
            <?php else: ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden tour-detail-2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[10px] text-text-subtle uppercase tracking-wider border-b border-white/[0.04]">
                                <th class="text-left py-2.5 px-4">Nombre</th>
                                <th class="text-left py-2.5 px-4">Tipo</th>
                                <th class="text-left py-2.5 px-4">Fecha</th>
                                <th class="text-left py-2.5 px-4">Estado</th>
                                <th class="text-right py-2.5 px-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.03]">
                            <?php foreach ($reports as $r): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors">
                                <td class="py-2.5 px-4 text-[12px] text-text-heading font-medium"><?= h($r['name'] ?? $r['title'] ?? ('Reporte ' . substr($r['_id'] ?? '', 0, 6))) ?></td>
                                <td class="py-2.5 px-4 text-[11px] text-text-muted"><?= h($r['type'] ?? 'compliance') ?></td>
                                <td class="py-2.5 px-4 text-[11px] text-text-subtle"><?= h(substr($r['createdAt'] ?? '', 0, 16)) ?></td>
                                <td class="py-2.5 px-4">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"><?= h($r['status'] ?? 'listo') ?></span>
                                </td>
                                <td class="py-2.5 px-4 text-right">
                                    <a href="/api/reports/download/<?= h($r['_id'] ?? '') ?>?token=<?= h($token) ?>" class="text-[11px] text-primary-400 hover:text-primary-300" target="_blank">Descargar PDF</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
