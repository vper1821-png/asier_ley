<?php
$pageTitle = 'Certificación Ley 21.719';
$currentPage = 'certification';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

$dashboardRes = api_post_form('/api/certification/dashboard', ['token' => $token]);
$dashboard = is_array($dashboardRes) && empty($dashboardRes['error']) ? $dashboardRes : [];

$score    = (int)($dashboard['score'] ?? 0);
$canIssue = !empty($dashboard['canIssue']);
$blockers = $dashboard['blockers'] ?? [];
$documents = $dashboard['documents'] ?? [];
$byChapter = $dashboard['byChapter'] ?? [];
$lastCert  = $dashboard['lastCert'] ?? null;
$isSuperAdmin = !empty($dashboard['isSuperAdmin']);

$scoreColor = $score >= 90 ? 'text-emerald-400' : ($score >= 70 ? 'text-amber-400' : 'text-red-400');
$scoreBar   = $score >= 90 ? 'bg-emerald-500'   : ($score >= 70 ? 'bg-amber-500'   : 'bg-red-500');
$scoreRing  = $score >= 90 ? '#34d399'          : ($score >= 70 ? '#fbbf24'        : '#f87171');

$statusCfg = [
    'signed'         => ['label' => 'Firmado',        'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/25', 'dot' => 'bg-emerald-400'],
    'approved'       => ['label' => 'Aprobado',       'class' => 'bg-teal-500/10 text-teal-400 border-teal-500/25',          'dot' => 'bg-teal-400'],
    'ready'          => ['label' => 'Listo',          'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/25',          'dot' => 'bg-blue-400'],
    'draft'          => ['label' => 'Borrador',       'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/25',       'dot' => 'bg-amber-400'],
    'pending_manual' => ['label' => 'Requiere carga', 'class' => 'bg-purple-500/10 text-purple-400 border-purple-500/25',    'dot' => 'bg-purple-400'],
    'missing'        => ['label' => 'Faltante',       'class' => 'bg-red-500/10 text-red-400 border-red-500/25',             'dot' => 'bg-red-400'],
    'rejected'       => ['label' => 'Rechazado',      'class' => 'bg-red-500/10 text-red-400 border-red-500/25',             'dot' => 'bg-red-400'],
    'expired'        => ['label' => 'Expirado',       'class' => 'bg-gray-500/10 text-gray-400 border-gray-500/25',          'dot' => 'bg-gray-400'],
];
?>
<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-600/30 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        Certificación Ley 21.719
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            24 documentos
                        </span>
                    </h1>
                    <p class="text-[11px] text-text-muted">Expediente completo de cumplimiento normativo</p>
                </div>
            </div>
            <div class="flex items-center gap-2.5">
                <button onclick="location.reload()" class="px-3 py-2 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all" title="Actualizar">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 sm:p-6 min-h-0 scrollbar-custom">
            <div class="max-w-[1400px] mx-auto space-y-4">

            <?php if ($isSuperAdmin): ?>
                <div class="px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/25 text-amber-300 text-[11px] flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-amber-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    Estás como superadmin. Para gestionar la certificación de una empresa específica, inicia sesión con un usuario de esa empresa.
                </div>
            <?php endif; ?>

            <?php if ($msg): ?>
            <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs">
                <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span><?= h($msg) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($err): ?>
            <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs">
                <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                <span><?= h($err) ?></span>
            </div>
            <?php endif; ?>

            <!-- Hero: score + issue -->
            <div class="relative overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-950/30 via-bg-panel to-teal-950/20 p-5 sm:p-6">
                <div class="absolute -top-20 -right-20 w-64 h-64 rounded-full bg-emerald-500/10 blur-3xl pointer-events-none"></div>
                <div class="relative flex flex-col md:flex-row md:items-center gap-6">
                    <div class="flex-shrink-0">
                        <div class="relative w-32 h-32">
                            <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90">
                                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10"/>
                                <circle cx="60" cy="60" r="52" fill="none" stroke="<?= $scoreRing ?>" stroke-width="10"
                                        stroke-dasharray="<?= 2 * M_PI * 52 ?>"
                                        stroke-dashoffset="<?= 2 * M_PI * 52 * (1 - $score / 100) ?>"
                                        stroke-linecap="round"/>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-3xl font-bold <?= $scoreColor ?> font-mono"><?= $score ?>%</span>
                                <span class="text-[9px] text-text-subtle uppercase tracking-widest">Score</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-2 h-2 rounded-full <?= $score >= 90 ? 'bg-emerald-400' : ($score >= 70 ? 'bg-amber-400' : 'bg-red-400') ?> animate-pulse"></span>
                            <span class="text-[10px] font-semibold uppercase tracking-widest text-text-subtle">
                                <?php if ($score >= 90): ?>
                                    Apto para certificación
                                <?php elseif ($score >= 70): ?>
                                    Apto con observaciones
                                <?php else: ?>
                                    No apto para certificación
                                <?php endif; ?>
                            </span>
                        </div>
                        <h2 class="text-lg font-bold text-white tracking-tight leading-snug">
                            Certificado de Cumplimiento Ley 21.719
                        </h2>
                        <p class="text-[12px] text-text-muted mt-1.5 leading-relaxed max-w-2xl">
                            El expediente incluye los 24 documentos obligatorios organizados en 8 capítulos.
                            Se requiere un score mínimo del <span class="text-white font-semibold">90%</span> para emitir el certificado.
                        </p>

                        <?php if (!empty($blockers)): ?>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <?php foreach ($blockers as $b): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?= h($b) ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex-shrink-0 w-full md:w-auto flex flex-col gap-2.5">
                        <button onclick="issueCertificate()" <?= $canIssue ? '' : 'disabled' ?>
                            class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl text-[12px] font-semibold transition-all <?= $canIssue ? 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-theme-sm hover:shadow-emerald-500/20' : 'bg-white/[0.03] text-text-subtle border border-white/[0.06] cursor-not-allowed' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>
                            </svg>
                            Emitir Certificación
                        </button>

                        <?php if ($lastCert): ?>
                        <div class="rounded-lg border border-emerald-500/25 bg-emerald-500/[0.06] p-2.5">
                            <p class="text-[9px] uppercase tracking-widest text-emerald-300 mb-1">Última certificación</p>
                            <p class="text-[11px] font-mono text-white break-all"><?= h($lastCert['certId']) ?></p>
                            <div class="flex gap-1.5 mt-2">
                                <a href="/api-proxy.php?path=<?= urlencode('/api/certification/download/' . $lastCert['certId'] . '/master') ?>" target="_blank"
                                    class="text-[10px] px-2 py-1 rounded-lg bg-white/[0.04] border border-white/[0.07] text-text-muted hover:text-white transition-all">
                                    PDF maestro
                                </a>
                                <a href="/api-proxy.php?path=<?= urlencode('/api/certification/download/' . $lastCert['certId'] . '/zip') ?>" target="_blank"
                                    class="text-[10px] px-2 py-1 rounded-lg bg-white/[0.04] border border-white/[0.07] text-text-muted hover:text-white transition-all">
                                    ZIP
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Chapters summary -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2.5">
                <?php foreach ($byChapter as $chap):
                    $chapPct = $chap['weight'] > 0 ? round($chap['score'] / $chap['weight'] * 100) : 0;
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-3">
                    <p class="text-[9px] uppercase tracking-widest text-text-subtle truncate"><?= h($chap['name']) ?></p>
                    <p class="text-lg font-bold text-white font-mono mt-1"><?= $chapPct ?>%</p>
                    <div class="w-full h-1 rounded-full bg-white/[0.06] overflow-hidden mt-2">
                        <div class="h-full rounded-full <?= $chapPct >= 90 ? 'bg-emerald-400' : ($chapPct >= 70 ? 'bg-amber-400' : 'bg-red-400') ?>" style="width:<?= $chapPct ?>%"></div>
                    </div>
                    <p class="text-[9px] text-text-subtle mt-1.5"><?= $chap['done'] ?>/<?= $chap['total'] ?> listos</p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden">
                <div class="p-3.5 border-b border-border-theme space-y-2.5 bg-bg-surface/30">
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle pointer-events-none">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" id="cert-search" placeholder="Buscar por código o nombre de documento..."
                               oninput="filterCertDocs()"
                               class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all">
                    </div>

                    <div class="flex items-center gap-1 overflow-x-auto scrollbar-none py-0.5" id="cert-status-filters">
                        <button type="button" onclick="setCertFilter('all')" data-status="all"
                                class="cert-status-tab px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap">
                            Todos (<?= count($documents) ?>)
                        </button>
                        <button type="button" onclick="setCertFilter('pending')" data-status="pending"
                                class="cert-status-tab px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Pendientes
                        </button>
                        <button type="button" onclick="setCertFilter('ready')" data-status="ready"
                                class="cert-status-tab px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Listos
                        </button>
                        <button type="button" onclick="setCertFilter('done')" data-status="done"
                                class="cert-status-tab px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Aprobados/Firmados
                        </button>
                    </div>
                </div>

                <!-- Documents list grouped by chapter -->
                <div class="p-2.5 space-y-2" id="cert-docs-list">
                    <?php
                    $grouped = [];
                    foreach ($documents as $d) {
                        $grouped[$d['chapter']][] = $d;
                    }
                    ksort($grouped);

                    foreach ($grouped as $chapCode => $docs):
                        $chapName = $docs[0]['chapterName'] ?? $chapCode;
                    ?>
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.01] overflow-hidden mb-2 cert-chapter-group">
                        <div class="px-3.5 py-2.5 bg-gradient-to-r from-emerald-950/30 via-bg-panel to-transparent border-b border-white/[0.04] flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/25"><?= h($chapCode) ?></span>
                                <span class="text-[11px] font-semibold text-text-heading"><?= h($chapName) ?></span>
                            </div>
                            <span class="text-[10px] text-text-subtle font-mono"><?= count($docs) ?> docs</span>
                        </div>
                        <div class="divide-y divide-white/[0.03]">
                            <?php foreach ($docs as $d):
                                $st = $statusCfg[$d['status']] ?? $statusCfg['missing'];
                                $isDone = in_array($d['status'], ['signed', 'approved'], true);
                                $isReady = $d['status'] === 'ready';
                                $needsManual = !$d['auto'];
                                $filterKey = $isDone ? 'done' : ($isReady ? 'ready' : 'pending');
                                $searchText = mb_strtolower($d['code'] . ' ' . $d['name']);
                            ?>
                            <div class="cert-doc-row px-3.5 py-3 flex items-center gap-3 hover:bg-white/[0.015] transition-colors"
                                 data-status="<?= h($filterKey) ?>"
                                 data-search="<?= h($searchText) ?>">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 border <?= $st['class'] ?>">
                                    <?php if ($isDone): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <?php elseif ($isReady): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/></svg>
                                    <?php elseif ($needsManual): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    <?php else: ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <?php endif; ?>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-[10px] font-mono text-text-subtle"><?= h($d['code']) ?></span>
                                        <span class="text-[12px] font-medium text-text-heading truncate"><?= h($d['name']) ?></span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-medium border <?= $st['class'] ?>">
                                            <span class="w-1 h-1 rounded-full <?= $st['dot'] ?>"></span>
                                            <?= h($st['label']) ?>
                                        </span>
                                        <?php if ($d['version'] > 0): ?>
                                        <span class="text-[9px] text-text-subtle font-mono">v<?= (int)$d['version'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($d['signedByName'] || $d['approvedByName']): ?>
                                    <p class="text-[10px] text-text-subtle mt-0.5">
                                        <?= !empty($d['signedByName']) ? 'Firmado por ' . h($d['signedByName']) : 'Aprobado por ' . h($d['approvedByName']) ?>
                                    </p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <?php if ($d['pdfUrl']): ?>
                                    <a href="<?= h($d['pdfUrl']) ?>" target="_blank"
                                       class="p-1.5 rounded-lg text-text-muted hover:text-indigo-400 hover:bg-indigo-500/10 transition-all"
                                       title="Descargar PDF">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </a>
                                    <?php endif; ?>

                                    <?php if ($d['canGenerate']): ?>
                                    <button onclick="generateCertDoc('<?= h($d['code']) ?>')"
                                            class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-indigo-500/10 border border-indigo-500/25 text-indigo-400 hover:bg-indigo-500/20 transition-all">
                                        <?= $d['version'] > 0 ? 'Regenerar' : 'Generar' ?>
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($d['version'] > 0 && $d['status'] !== 'approved' && $d['status'] !== 'signed'): ?>
                                    <button onclick="updateCertDocStatus('<?= h($d['code']) ?>', 'approve')"
                                            class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-teal-500/10 border border-teal-500/25 text-teal-400 hover:bg-teal-500/20 transition-all">
                                        Aprobar
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($d['status'] === 'approved'): ?>
                                    <button onclick="signCertDoc('<?= h($d['code']) ?>')"
                                            class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 hover:bg-emerald-500/20 transition-all">
                                        Firmar
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($needsManual && !$d['pdfUrl']): ?>
                                    <span class="px-2 py-1 rounded-lg text-[9px] font-medium bg-purple-500/10 border border-purple-500/25 text-purple-400">
                                        Carga manual
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="cert-docs-empty" class="hidden p-8 text-center">
                    <p class="text-[11px] text-text-subtle">Sin resultados para los filtros aplicados.</p>
                </div>
            </div>

            </div>
        </div>
    </main>
</div>

<!-- Modal firma -->
<div id="sign-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-md shadow-2xl p-6">
        <h3 class="text-[14px] font-bold text-white mb-4">Firmar documento</h3>
        <form id="sign-form" onsubmit="submitSign(event)">
            <input type="hidden" id="sign-code">
            <div class="space-y-3">
                <div>
                    <label class="block text-[10px] font-semibold text-text-subtle uppercase tracking-widest mb-1.5">Nombre del firmante</label>
                    <input type="text" id="sign-name" required class="w-full bg-[#0a0e14] border border-border-theme rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-[10px] font-semibold text-text-subtle uppercase tracking-widest mb-1.5">Confirmación</label>
                    <label class="flex items-center gap-2 text-[11px] text-text-body">
                        <input type="checkbox" id="sign-confirm" required class="w-4 h-4 rounded border-border-theme text-emerald-600">
                        Confirmo que revisé y apruebo este documento
                    </label>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('sign-modal').classList.add('hidden')"
                            class="px-4 py-2 rounded-lg text-[11px] font-medium bg-bg-elevated border border-border-theme text-text-muted">Cancelar</button>
                    <button type="submit"
                            class="px-5 py-2 rounded-lg text-[11px] font-semibold bg-gradient-to-r from-emerald-600 to-teal-600 text-white">Firmar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const CERT_TOKEN = <?= json_encode($token) ?>;
let certFilter = 'all';

function setCertFilter(status) {
    certFilter = status;
    document.querySelectorAll('.cert-status-tab').forEach(btn => {
        const active = btn.dataset.status === status;
        btn.className = 'cert-status-tab px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all whitespace-nowrap ' +
            (active
                ? 'bg-primary-500/15 text-primary-300 border border-primary-500/30'
                : 'text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent');
    });
    filterCertDocs();
}

function filterCertDocs() {
    const q = (document.getElementById('cert-search')?.value || '').toLowerCase().trim();
    let visible = 0;

    document.querySelectorAll('.cert-doc-row').forEach(row => {
        const status = row.dataset.status || 'pending';
        const search = row.dataset.search || '';
        const okStatus = certFilter === 'all' || status === certFilter;
        const okSearch = !q || search.indexOf(q) !== -1;
        const show = okStatus && okSearch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.querySelectorAll('.cert-chapter-group').forEach(group => {
        const anyVisible = group.querySelectorAll('.cert-doc-row:not([style*="display: none"])').length > 0;
        group.style.display = anyVisible ? '' : 'none';
    });

    const empty = document.getElementById('cert-docs-empty');
    if (empty) empty.classList.toggle('hidden', visible > 0);
}

async function generateCertDoc(code) {
    if (!confirm('¿Generar PDF del documento ' + code + '?')) return;
    try {
        const res = await fetch('/api-proxy.php?path=' + encodeURIComponent('/api/certification/documents/generate'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: CERT_TOKEN, code })
        });
        const data = await res.json();
        if (data.success) {
            alert('PDF generado. Recargando...');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
        }
    } catch (e) { alert('Error: ' + e.message); }
}

async function updateCertDocStatus(code, action) {
    if (!confirm('¿' + (action === 'approve' ? 'Aprobar' : action) + ' el documento ' + code + '?')) return;
    try {
        const res = await fetch('/api-proxy.php?path=' + encodeURIComponent('/api/certification/documents/status'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: CERT_TOKEN, code, action })
        });
        const data = await res.json();
        if (data.success) location.reload();
        else alert('Error: ' + (data.error || 'desconocido'));
    } catch (e) { alert('Error: ' + e.message); }
}

function signCertDoc(code) {
    document.getElementById('sign-code').value = code;
    document.getElementById('sign-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('sign-name').focus(), 100);
}

async function submitSign(e) {
    e.preventDefault();
    const code = document.getElementById('sign-code').value;
    const name = document.getElementById('sign-name').value;
    if (!code || !name) return;
    try {
        const res = await fetch('/api-proxy.php?path=' + encodeURIComponent('/api/certification/documents/status'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: CERT_TOKEN, code, action: 'sign', signatureData: name })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('sign-modal').classList.add('hidden');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
        }
    } catch (e) { alert('Error: ' + e.message); }
}

async function issueCertificate() {
    if (!confirm('¿Emitir el certificado de cumplimiento? Esta acción generará el documento oficial y no se puede deshacer.')) return;
    try {
        const res = await fetch('/api-proxy.php?path=' + encodeURIComponent('/api/certification/issue'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: CERT_TOKEN })
        });
        const data = await res.json();
        if (data.success) {
            alert('¡Certificado emitido!\n\nID: ' + data.certId + '\nScore: ' + data.score + '%\n\nPuedes descargarlo desde el panel.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'desconocido'));
        }
    } catch (e) { alert('Error: ' + e.message); }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>