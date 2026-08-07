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
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Actividad DB</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($logs) ?> consultas registradas</p>
            </div>
            <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">Refrescar</button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 tour-detail-1">
                <?php
                $statCards = [
                    ['label' => 'Total consultas', 'value' => $stats['total'] ?? count($logs), 'color' => '#94a3b8'],
                    ['label' => 'SELECT', 'value' => $stats['selects'] ?? 0, 'color' => '#60a5fa'],
                    ['label' => 'Modificaciones', 'value' => $stats['writes'] ?? 0, 'color' => '#fbbf24'],
                    ['label' => 'Sospechosas', 'value' => $stats['suspicious'] ?? 0, 'color' => '#f87171'],
                ];
                foreach ($statCards as $c): ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <p class="text-[10px] font-medium text-text-muted uppercase tracking-widest mb-2"><?= h($c['label']) ?></p>
                    <p class="text-[22px] font-bold tracking-tight leading-none" style="color:<?= $c['color'] ?>"><?= h($c['value']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Logs table -->
            <?php if (empty($logs)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center">
                <h3 class="text-white font-semibold mb-2">Sin actividad</h3>
                <p class="text-text-muted text-[12px]">No hay consultas registradas todavía.</p>
            </div>
            <?php else: ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] overflow-hidden tour-detail-2">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[10px] text-text-subtle uppercase tracking-wider border-b border-white/[0.04]">
                                <th class="text-left py-2.5 px-4">Consulta</th>
                                <th class="text-left py-2.5 px-4">Base de datos</th>
                                <th class="text-left py-2.5 px-4">Usuario</th>
                                <th class="text-left py-2.5 px-4">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.03]">
                            <?php foreach (array_slice($logs, 0, 100) as $log): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors">
                                <td class="py-2.5 px-4">
                                    <code class="text-[10px] text-text-body font-mono break-all"><?= h(substr($log['query'] ?? '', 0, 120)) ?></code>
                                </td>
                                <td class="py-2.5 px-4 text-[11px] text-text-muted"><?= h($log['database'] ?? $log['databaseName'] ?? '') ?></td>
                                <td class="py-2.5 px-4 text-[11px] text-text-muted"><?= h($log['dbUser'] ?? $log['user'] ?? '') ?></td>
                                <td class="py-2.5 px-4 text-[11px] text-text-subtle whitespace-nowrap"><?= h(substr($log['createdAt'] ?? $log['timestamp'] ?? '', 0, 16)) ?></td>
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
