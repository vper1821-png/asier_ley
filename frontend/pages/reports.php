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

$typeLabels = [
    'compliance' => 'Cumplimiento',
    'security' => 'Seguridad',
    'training' => 'Capacitación',
];
$typeColors = [
    'compliance' => ['text' => 'text-blue-400', 'bg' => 'bg-blue-500/10', 'border' => 'border-blue-500/20', 'badge' => 'bg-blue-500/10 text-blue-400 border-blue-500/20'],
    'security' => ['text' => 'text-amber-400', 'bg' => 'bg-amber-500/10', 'border' => 'border-amber-500/20', 'badge' => 'bg-amber-500/10 text-amber-400 border-amber-500/20'],
    'training' => ['text' => 'text-emerald-400', 'bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-500/20', 'badge' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'],
];

$complianceCount = count(array_filter($reports, fn($r) => ($r['type'] ?? 'compliance') === 'compliance'));
$securityCount = count(array_filter($reports, fn($r) => ($r['type'] ?? '') === 'security'));
$trainingCount = count(array_filter($reports, fn($r) => ($r['type'] ?? '') === 'training'));
$readyCount = count(array_filter($reports, fn($r) => ($r['status'] ?? '') === 'listo'));
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">

        <!-- Top Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-600/30 to-blue-500/20 border border-cyan-500/30 flex items-center justify-center text-cyan-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight">Reportes</h1>
                    <p class="text-[11px] text-text-muted"><?= count($reports) ?> reportes · <?= count($thisMonth) ?> este mes</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" class="flex items-center gap-2">
                    <select name="report_type" class="input-premium text-[11px] py-1.5 w-48">
                        <option value="compliance">Cumplimiento Ley 21.719</option>
                        <option value="security">Seguridad</option>
                        <option value="training">Capacitación</option>
                    </select>
                    <button type="submit" name="generate_report" value="1"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white transition-all shadow-theme-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Generar
                    </button>
                </form>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <?php if ($msg): ?>
                <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs shadow-theme-sm">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= h($msg) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($err): ?>
                <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs shadow-theme-sm">
                    <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <span><?= h($err) ?></span>
                </div>
                <?php endif; ?>

                <!-- KPI Row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-500/10 border border-slate-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white"><?= count($reports) ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Total</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-cyan-400"><?= count($thisMonth) ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Este Mes</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-blue-400"><?= $complianceCount ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Cumplimiento</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-amber-400"><?= $securityCount ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Seguridad</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-emerald-400"><?= $trainingCount ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Capacitación</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($reports)): ?>
                <!-- Empty State -->
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-16 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg mb-2">Sin reportes</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">Genera tu primer reporte de cumplimiento usando el formulario superior.</p>
                </div>

                <?php else: ?>
                <!-- Reports List -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden">
                    <div class="divide-y divide-border-theme">
                        <?php foreach ($reports as $r):
                            $rType = $r['type'] ?? 'compliance';
                            $rColors = $typeColors[$rType] ?? $typeColors['compliance'];
                            $rLabel = $typeLabels[$rType] ?? ucfirst($rType);
                            $rStatus = $r['status'] ?? 'listo';
                        ?>
                        <div class="px-5 py-4 flex items-center gap-4 hover:bg-white/[0.01] transition-colors">
                            <!-- Type Icon -->
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 rounded-xl <?= $rColors['bg'] ?> border <?= $rColors['border'] ?> flex items-center justify-center">
                                    <?php if ($rType === 'compliance'): ?>
                                    <svg class="w-5 h-5 <?= $rColors['text'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    <?php elseif ($rType === 'security'): ?>
                                    <svg class="w-5 h-5 <?= $rColors['text'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    <?php else: ?>
                                    <svg class="w-5 h-5 <?= $rColors['text'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-medium text-white truncate"><?= h($r['name'] ?? $r['title'] ?? ('Reporte ' . substr($r['_id'] ?? '', 0, 6))) ?></p>
                                <div class="flex items-center gap-2.5 mt-1">
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full border <?= $rColors['badge'] ?> font-medium"><?= h($rLabel) ?></span>
                                    <span class="text-[10px] text-text-subtle"><?= h(substr($r['createdAt'] ?? '', 0, 16)) ?></span>
                                </div>
                            </div>
                            <!-- Status + Actions -->
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-medium"><?= h($rStatus) ?></span>
                                <a href="<?= API_BASE_URL_BROWSER ?>/api/reports/download/<?= h($r['_id'] ?? '') ?>?token=<?= h($token) ?>" target="_blank"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-white border border-white/[0.05] transition-all">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    PDF
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
