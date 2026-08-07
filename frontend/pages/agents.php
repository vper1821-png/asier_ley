<?php
$pageTitle = 'Agentes';
$currentPage = 'agents';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_agent'])) {
        $res = api_post_form('/api/agents/' . urlencode($_POST['agent_id']) . '/delete', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Agente eliminado.';
        else $err = $res['error'] ?? 'Error al eliminar agente.';
    } elseif (isset($_POST['send_command'])) {
        $res = api_post_form('/api/agents/' . urlencode($_POST['agent_id']) . '/command', ['token' => $token, 'command' => $_POST['command'] ?? '']);
        if (!empty($res['success'])) $msg = 'Comando enviado.';
        else $err = $res['error'] ?? 'Error al enviar comando.';
    }
}

$agentsRes = api_post_form('/api/agents/list', ['token' => $token]);
$agents = is_array($agentsRes) && empty($agentsRes['error']) ? ($agentsRes['agents'] ?? $agentsRes) : [];
if (!is_array($agents)) $agents = [];
$online = count(array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online'));

$platforms = [
    'win-x64' => ['label' => 'Windows x64', 'icon' => 'M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801'],
    'linux-x64' => ['label' => 'Linux x64', 'icon' => 'M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139z'],
    'macos-arm64' => ['label' => 'macOS ARM', 'icon' => 'M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header -->
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Agentes</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($agents) ?> registrados · <?= $online ?> online</p>
            </div>
            <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] transition-all">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Refrescar
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Download agent -->
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 tour-detail-1">
                <p class="text-[12px] font-semibold text-white mb-1">Descargar agente</p>
                <p class="text-[11px] text-text-subtle mb-4">Instala el agente de SecureLab en tus endpoints para monitorearlos.</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($platforms as $plat => $info): ?>
                    <a href="/download-agent?platform=<?= h($plat) ?>"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-body hover:bg-bg-elevated hover:border-surface-600 transition-all">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="<?= $info['icon'] ?>"/></svg>
                        <?= h($info['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Agents list -->
            <?php if (empty($agents)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center">
                <div class="w-12 h-12 rounded-xl bg-bg-elevated border border-border-theme flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-white font-semibold mb-2">No hay agentes</h3>
                <p class="text-text-muted text-[12px]">Descarga e instala el agente en tus endpoints para empezar.</p>
            </div>
            <?php else: ?>
            <div class="space-y-2 tour-detail-2">
                <?php foreach ($agents as $agent): $isOnline = ($agent['status'] ?? '') === 'online'; ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4 flex flex-col md:flex-row md:items-center gap-3">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $isOnline ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[12px] font-medium text-text-heading truncate"><?= h($agent['hostname'] ?? $agent['agentId'] ?? 'Agente') ?></p>
                            <p class="text-[10px] text-text-subtle truncate">
                                <?= h($agent['platform'] ?? $agent['os'] ?? '') ?> · <?= h($agent['ip'] ?? '') ?> · Último latido: <?= h(substr($agent['lastSeen'] ?? $agent['lastHeartbeat'] ?? 'N/A', 0, 16)) ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <?php if (isset($agent['cpu']) || isset($agent['ram'])): ?>
                        <span class="text-[10px] text-text-muted">CPU <?= h($agent['cpu'] ?? '0') ?>% · RAM <?= h($agent['ram'] ?? '0') ?>%</span>
                        <?php endif; ?>
                        <span class="inline-flex items-center gap-1.5 text-[10px] px-2 py-0.5 rounded-full <?= $isOnline ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?= $isOnline ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                            <?= $isOnline ? 'Online' : 'Offline' ?>
                        </span>
                        <form method="POST" class="inline-flex gap-1.5">
                            <input type="hidden" name="agent_id" value="<?= h($agent['agentId'] ?? $agent['_id'] ?? '') ?>">
                            <button type="submit" name="send_command" value="1" onclick="this.form.command.value='restart'"
                                class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body hover:bg-bg-elevated transition-all">Reiniciar</button>
                            <input type="hidden" name="command" value="restart">
                            <button type="submit" name="delete_agent" value="1" onclick="return confirm('¿Eliminar este agente?')"
                                class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
