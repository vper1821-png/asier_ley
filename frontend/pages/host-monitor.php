<?php
$pageTitle = 'Host Monitor';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
$hostsRes = api_post_form('/api/host-monitor', ['token' => $token]);
$hosts = is_array($hostsRes) && empty($hostsRes['error']) ? $hostsRes : [];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'host-monitor'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <header class="flex-shrink-0 px-6 h-14 flex items-center border-b border-border-theme">
            <h1 class="text-sm font-semibold text-white">Host Monitor</h1>
        </header>
        <div class="flex-1 overflow-y-auto p-4">
            <div class="max-w-5xl">
                <?php if (empty($hosts)): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-12 text-center">
                    <div class="w-12 h-12 rounded-xl bg-bg-elevated border border-border-theme flex items-center justify-center mx-auto mb-4">
                        <svg class="w-6 h-6 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-white font-semibold mb-2">No hay hosts monitorizados</h3>
                    <p class="text-text-muted text-[13px]">Despliega agentes en tus servidores para ver datos de monitorización.</p>
                </div>
                <?php else: ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4">Hosts monitorizados</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead>
                                <tr class="text-[11px] text-text-subtle uppercase tracking-wider">
                                    <th class="text-left py-2 px-3">Hostname</th>
                                    <th class="text-left py-2 px-3">CPU</th>
                                    <th class="text-left py-2 px-3">RAM</th>
                                    <th class="text-left py-2 px-3">Disco</th>
                                    <th class="text-left py-2 px-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hosts as $host): ?>
                                <tr class="border-t border-border-theme">
                                    <td class="py-2.5 px-3 text-text-heading font-medium"><?= h($host['hostname'] ?? $host['agentId'] ?? 'N/A') ?></td>
                                    <td class="py-2.5 px-3 text-text-muted"><?= h($host['cpu'] ?? 'N/A') ?>%</td>
                                    <td class="py-2.5 px-3 text-text-muted"><?= h($host['ram'] ?? 'N/A') ?>%</td>
                                    <td class="py-2.5 px-3 text-text-muted"><?= h($host['disk'] ?? 'N/A') ?>%</td>
                                    <td class="py-2.5 px-3">
                                        <span class="inline-flex items-center gap-1.5 text-[11px] px-2 py-0.5 rounded-full <?= ($host['status'] ?? '') === 'online' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= ($host['status'] ?? '') === 'online' ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                                            <?= ($host['status'] ?? '') === 'online' ? 'Online' : 'Offline' ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $eventsRes = api_post_form('/api/host-monitor/events', ['token' => $token]);
                $events = is_array($eventsRes) && empty($eventsRes['error']) ? $eventsRes : [];
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5 mt-5">
                    <h3 class="text-[13px] font-semibold text-white mb-4">Eventos recientes</h3>
                    <?php if (empty($events)): ?>
                    <p class="text-text-muted text-[12px]">No hay eventos recientes del host.</p>
                    <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach (array_slice($events, 0, 20) as $ev): ?>
                        <div class="flex items-start gap-3 p-3 rounded-lg border border-border-theme bg-bg-elevated/40">
                            <div class="w-2 h-2 mt-1.5 rounded-full <?= ($ev['severity'] ?? '') === 'high' || ($ev['severity'] ?? '') === 'critical' ? 'bg-red-400' : 'bg-blue-400' ?>"></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-[12px] font-medium text-white"><?= h($ev['title'] ?? 'Evento') ?></p>
                                <p class="text-[11px] text-text-muted mt-0.5"><?= h($ev['detail'] ?? '') ?></p>
                                <p class="text-[10px] text-text-subtle mt-1"><?= h($ev['eventType'] ?? '') ?> · <?= h($ev['timestamp'] ?? '') ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
