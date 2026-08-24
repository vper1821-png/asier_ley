<?php
$pageTitle = 'Logs DB';
$currentPage = 'db-logs';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';

$statsRes = api_post_form('/api/databases/logs/stats', ['token' => $token]);
$stats = is_array($statsRes) && empty($statsRes['error']) ? $statsRes : [];

$logsRes = api_post_form('/api/databases/logs/list', ['token' => $token]);
$logs = is_array($logsRes) && empty($logsRes['error']) ? ($logsRes['logs'] ?? $logsRes) : [];
if (!is_array($logs)) $logs = [];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">

        <!-- Top Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-purple-600/30 to-violet-500/20 border border-purple-500/30 flex items-center justify-center text-purple-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight">Logs DB</h1>
                    <p class="text-[11px] text-text-muted"><?= count($logs) ?> consultas registradas</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <!-- KPI Row -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 tour-detail-1">
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-500/10 border border-slate-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white"><?= $stats['total'] ?? count($logs) ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Total consultas</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-blue-400"><?= $stats['selects'] ?? 0 ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">SELECT</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-amber-400"><?= $stats['writes'] ?? 0 ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Modificaciones</p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-red-400"><?= $stats['suspicious'] ?? 0 ?></p>
                            <p class="text-[10px] text-text-muted uppercase tracking-wider">Sospechosas</p>
                        </div>
                    </div>
                </div>

                <?php if (empty($logs)): ?>
                <!-- Empty State -->
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-16 text-center">
                    <div class="w-16 h-16 rounded-2xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                        <svg class="w-8 h-8 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    </div>
                    <h3 class="text-white font-semibold text-lg mb-2">Sin actividad</h3>
                    <p class="text-text-muted text-[13px] max-w-md mx-auto">No hay consultas registradas todavia.</p>
                </div>

                <?php else: ?>
                <!-- Logs List -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden tour-detail-2">
                    <div class="divide-y divide-border-theme">
                        <?php foreach ($logs as $log):
                            $queryType = strtoupper(substr(trim($log['query'] ?? ''), 0, 6));
                            $typeConfig = [
                                'SELECT' => ['color' => 'bg-blue-500', 'text' => 'text-blue-400', 'bg' => 'bg-blue-500/10 border-blue-500/20'],
                                'INSERT' => ['color' => 'bg-amber-500', 'text' => 'text-amber-400', 'bg' => 'bg-amber-500/10 border-amber-500/20'],
                                'UPDATE' => ['color' => 'bg-amber-500', 'text' => 'text-amber-400', 'bg' => 'bg-amber-500/10 border-amber-500/20'],
                                'DELETE' => ['color' => 'bg-red-500', 'text' => 'text-red-400', 'bg' => 'bg-red-500/10 border-red-500/20'],
                                'CREATE' => ['color' => 'bg-emerald-500', 'text' => 'text-emerald-400', 'bg' => 'bg-emerald-500/10 border-emerald-500/20'],
                                'ALTER'  => ['color' => 'bg-purple-500', 'text' => 'text-purple-400', 'bg' => 'bg-purple-500/10 border-purple-500/20'],
                                'DROP'   => ['color' => 'bg-red-500', 'text' => 'text-red-400', 'bg' => 'bg-red-500/10 border-red-500/20'],
                            ];
                            $tc = $typeConfig[$queryType] ?? ['color' => 'bg-slate-500', 'text' => 'text-slate-400', 'bg' => 'bg-slate-500/10 border-slate-500/20'];
                        ?>
                        <div class="px-5 py-4 flex items-start gap-4 hover:bg-white/[0.01] transition-colors">
                            <!-- Type dot -->
                            <div class="flex-shrink-0 pt-1.5">
                                <span class="w-2.5 h-2.5 rounded-full <?= $tc['color'] ?>"></span>
                            </div>
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-[9px] px-1.5 py-0.5 rounded-full border <?= $tc['bg'] ?> <?= $tc['text'] ?> font-medium flex-shrink-0"><?= h($queryType) ?></span>
                                    <code class="text-[11px] text-text-body font-mono truncate flex-1"><?= h(substr($log['query'] ?? '', 0, 100)) ?></code>
                                </div>
                                <div class="flex items-center gap-3 mt-1.5">
                                    <span class="text-[10px] text-text-muted flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7"/></svg>
                                        <?= h($log['database'] ?? $log['databaseName'] ?? '') ?>
                                    </span>
                                    <span class="text-[10px] text-text-muted flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <?= h($log['dbUser'] ?? $log['user'] ?? '') ?>
                                    </span>
                                    <span class="text-[10px] text-text-subtle whitespace-nowrap">
                                        <?= h(substr($log['createdAt'] ?? $log['timestamp'] ?? '', 0, 16)) ?>
                                    </span>
                                </div>
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
