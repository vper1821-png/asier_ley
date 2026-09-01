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
    if (isset($_POST['lockdown_action'])) {
        $res = api_post_form('/api/agents/lockdown', [
            'token' => $token,
            'agentId' => $_POST['agent_id'] ?? '',
            'action' => $_POST['lockdown_action'],
            'message' => $_POST['message'] ?? '',
        ]);
        if (!empty($res['success'])) {
            $msg = $_POST['lockdown_action'] === 'lock'
                ? 'Equipo bloqueado. El agente lo aplicará en unos segundos.'
                : 'Equipo desbloqueado. El agente lo restaurará en unos segundos.';
        } else {
            $err = $res['error'] ?? 'Error al aplicar la acción.';
        }
    } elseif (isset($_POST['send_cmd'])) {
        $params = '';
        if ($_POST['send_cmd'] === 'speak') {
            $params = json_encode(['text' => $_POST['text'] ?? ''], JSON_UNESCAPED_UNICODE);
        }
        $res = api_post_form('/api/agents/' . urlencode($_POST['agent_id'] ?? '') . '/command', [
            'token' => $token,
            'command' => $_POST['send_cmd'],
            'params' => $params,
        ]);
        if (!empty($res['success'])) $msg = 'Comando enviado al agente.';
        else $err = $res['error'] ?? 'Error al enviar comando.';
    } elseif (isset($_POST['delete_agent'])) {
        $res = api_post_form('/api/agents/' . urlencode($_POST['agent_id']) . '/delete', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Agente eliminado.';
        else $err = $res['error'] ?? 'Error al eliminar agente.';
    }
}

$agentsRes = api_post_form('/api/agents/list', ['token' => $token]);
$agents = is_array($agentsRes) && empty($agentsRes['error']) ? ($agentsRes['agents'] ?? $agentsRes) : [];
if (!is_array($agents)) $agents = [];

// Filtrar solo agentes online
$agents = array_filter($agents, fn($a) => ($a['status'] ?? '') === 'online');
$agents = array_values($agents); // Reindexar array

usort($agents, function($a, $b) {
    $ga = $a['group'] ?? '';
    $gb = $b['group'] ?? '';
    $sort = $_GET['sort'] ?? 'pinned';
    if ($sort === 'name') {
        if ($ga !== $gb) return strcmp($ga, $gb);
        $na = $a['name'] ?? $a['hostname'] ?? '';
        $nb = $b['name'] ?? $b['hostname'] ?? '';
        return strcmp($na, $nb);
    }
    if ($sort === 'date') {
        if ($ga !== $gb) return strcmp($ga, $gb);
        return strcmp($b['lastSeen'] ?? '', $a['lastSeen'] ?? '');
    }
    if ($ga === '' && $gb !== '') return 1;
    if ($gb === '' && $ga !== '') return -1;
    if ($ga !== $gb) return strcmp($ga, $gb);
    $pa = !empty($a['pinned']);
    $pb = !empty($b['pinned']);
    if ($pa !== $pb) return ($pb <=> $pa);
    $na = $a['name'] ?? $a['hostname'] ?? '';
    $nb = $b['name'] ?? $b['hostname'] ?? '';
    return strcmp($na, $nb);
});
$online = count($agents);

$foldersRes = api_post_form('/api/folders/list', ['token' => $token]);
$folders = is_array($foldersRes) && empty($foldersRes['error']) ? $foldersRes : [];
if (!is_array($folders)) $folders = [];

$hostsRes = api_post_form('/api/host-monitor', ['token' => $token]);
$hosts = is_array($hostsRes) && empty($hostsRes['error']) ? $hostsRes : [];
$hostsByAgent = [];
foreach ($hosts as $h) {
    if (!empty($h['agentId'])) $hostsByAgent[$h['agentId']] = $h;
}

$combined = [];
foreach ($agents as $a) {
    $aid = $a['agentId'] ?? $a['_id'] ?? '';
    $combined[] = ['agent' => $a, 'host' => $hostsByAgent[$aid] ?? []];
}
$combinedJson = json_encode($combined, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$platforms = [
    'win-x64' => ['label' => 'Windows x64', 'icon' => 'M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801'],
    'linux-x64' => ['label' => 'Linux x64', 'icon' => 'M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139z'],
    'macos-arm64' => ['label' => 'macOS ARM', 'icon' => 'M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Agentes</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><span id="agent-count"><?= count($agents) ?></span> registrados · <?= $online ?> online</p>
            </div>
            <div class="flex items-center gap-2">
                <input id="agent-search" type="text" placeholder="Buscar agente..." value="<?= h($_GET['search'] ?? '') ?>" oninput="filterAgents(this.value)" class="w-40 md:w-56 bg-bg-input border border-border-theme rounded-lg px-3 py-1.5 text-[12px] text-text-heading focus:border-primary-500 outline-none" />
                <select id="agent-sort" onchange="location.href = '/agents?sort=' + encodeURIComponent(this.value)" class="bg-bg-input border border-border-theme rounded-lg px-3 py-1.5 text-[12px] text-text-heading focus:border-primary-500 outline-none">
                    <option value="pinned" <?= ($_GET['sort'] ?? '') === 'pinned' ? 'selected' : '' ?>>Fijados primero</option>
                    <option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Nombre A-Z</option>
                    <option value="date" <?= ($_GET['sort'] ?? '') === 'date' ? 'selected' : '' ?>>Último latido</option>
                </select>
                <button onclick="location.reload()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted hover:text-text-body border border-white/[0.05] transition-all">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Refrescar
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Download agent -->
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 tour-detail-1">
                <p class="text-[12px] font-semibold text-white mb-1">Descargar agente</p>
                <p class="text-[11px] text-text-subtle mb-4">Instala el agente de SecureLab en tus endpoints para monitorearlos y poder bloquearlos remotamente.</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($platforms as $plat => $info): ?>
                    <?php if ($plat === 'win-x64'): ?>
                    <button onclick="downloadInstaller()" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-body hover:bg-bg-elevated hover:border-surface-600 transition-all">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4 4m4-4v4m0 0H4m4 4h16a2 2 0 002-2V6a2 2 0 00-2-2H4a2 2 0 00-2 2v4a2 2 0 002 2h16a2 2 0 002-2V6a2 2 0 00-2-2H4a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                        <?= h($info['label']) ?> (Instalador)
                    </button>
                    <?php else: ?>
                    <button onclick="downloadAgent('<?= h($plat) ?>')"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-body hover:bg-bg-elevated hover:border-surface-600 transition-all">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="<?= $info['icon'] ?>"/></svg>
                        <?= h($info['label']) ?>
                    </button>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <p class="text-[10px] text-text-subtle mt-3">Al descargar se crea un <strong>deploy</strong> vinculado a tu cuenta. El agente se preconfigura con tu API URL y token.</p>
            </div>

            <!-- Carpetas -->
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 tour-detail-1">
                <p class="text-[12px] font-semibold text-white mb-1">Carpetas</p>
                <p class="text-[11px] text-text-subtle mb-4">Crea, visualiza y borra carpetas para organizar agentes.</p>
                <div class="flex items-center gap-2 mb-3">
                    <input id="new-folder" type="text" placeholder="Nombre de nueva carpeta..." class="flex-1 min-w-0 bg-bg-input border border-border-theme rounded-lg px-3 py-2 text-[12px] text-text-heading focus:border-primary-500 outline-none" />
                    <button onclick="createFolder()" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-primary-600 hover:bg-primary-500 text-white transition-all">Crear</button>
                </div>
                <div id="folders-list" class="space-y-2">
                    <?php foreach ($folders as $f): $fn = h($f['name'] ?? ''); ?>
                    <div class="folder-row flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-border-theme/40 bg-bg-panel/40" data-folder="<?= $fn ?>">
                        <div class="flex items-center gap-2 min-w-0">
                            <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            <span class="text-[12px] text-text-heading truncate"><?= $fn ?></span>
                        </div>
                        <button onclick="deleteFolder('<?= addslashes($f['name'] ?? '') ?>')" title="Borrar carpeta" class="p-1.5 rounded-lg text-[11px] text-red-400 hover:bg-red-500/10 transition-all">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($folders)): ?>
                    <p class="text-[11px] text-text-subtle">No hay carpetas creadas todavía.</p>
                    <?php endif; ?>
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
                <?php $lastGroup = '___unset___'; foreach ($agents as $i => $agent): $isOnline = ($agent['status'] ?? '') === 'online'; $isLocked = !empty($agent['lockdown']['enabled']); $g = $agent['group'] ?? ''; if ($g !== $lastGroup): if ($lastGroup !== '___unset___') echo '</div></details>'; $lastGroup = $g; ?>
                <details class="agent-folder rounded-xl border border-border-theme/40 bg-bg-panel/40 overflow-hidden mb-3" data-group="<?= h($g) ?>" open>
                    <summary class="px-4 py-3 flex items-center justify-between cursor-pointer list-none hover:bg-white/[0.02]" onclick="return true">
                        <div class="flex items-center gap-3">
                            <svg class="w-4 h-4 text-primary-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                            <span class="text-[13px] font-medium text-text-heading"><?= $g ? h($g) : 'Sin sección' ?></span>
                            <span class="text-[10px] text-text-subtle folder-count">(0)</span>
                        </div>
                        <svg class="w-4 h-4 text-text-subtle chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="p-3 space-y-2 border-t border-border-theme/30">
                <?php endif; ?>
                <div class="agent-card rounded-xl border p-4 flex flex-col md:flex-row md:items-center gap-3 <?= $isLocked ? 'border-red-500/30 bg-red-500/[0.04]' : 'border-white/[0.04] bg-white/[0.015]' ?>"
                     data-search="<?= h(strtolower(($agent['name'] ?? '') . ' ' . ($agent['hostname'] ?? '') . ' ' . ($agent['agentId'] ?? '') . ' ' . $g)) ?>"
                     data-pinned="<?= !empty($agent['pinned']) ? '1' : '0' ?>"
                     data-group="<?= h($g) ?>">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $isOnline ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p id="agent-title-<?= $i ?>" class="text-[12px] font-medium text-text-heading truncate">
                                    <?php if (!empty($agent['pinned'])): ?><span title="Fijado" class="text-amber-400 mr-1">★</span><?php endif; ?>
                                    <?= h($agent['name'] ?? $agent['hostname'] ?? $agent['agentId'] ?? 'Agente') ?>
                                </p>
                                <?php if ($isLocked): ?>
                                <span class="inline-flex items-center gap-1 text-[9px] px-1.5 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/30">
                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    BLOQUEADO
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[10px] text-text-subtle truncate">
                                <?= h($agent['platform'] ?? $agent['os'] ?? '') ?> · <?= h($agent['ip'] ?? '') ?> · Último latido: <?= h(substr($agent['lastSeen'] ?? $agent['lastHeartbeat'] ?? 'N/A', 0, 16)) ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <?php 
                        $hostData = $hostsByAgent[$agent['agentId'] ?? $agent['_id'] ?? ''] ?? [];
                        $cpu = $hostData['cpu'] ?? 0;
                        $ram = $hostData['ram'] ?? 0;
                        ?>
                        <?php if ($cpu > 0 || $ram > 0): ?>
                        <span class="text-[10px] text-text-muted">CPU <?= h($cpu) ?>% · RAM <?= h($ram) ?>%</span>
                        <?php endif; ?>
                        <span class="inline-flex items-center gap-1.5 text-[10px] px-2 py-0.5 rounded-full <?= $isOnline ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?= $isOnline ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                            <?= $isOnline ? 'Online' : 'Offline' ?>
                        </span>
                        <button onclick="openAgentModal(<?= $i ?>)" title="Ver detalles"
                            class="p-2 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme text-text-muted hover:text-indigo-400 hover:bg-bg-elevated transition-all">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                        <button onclick="pinAgent(<?= $i ?>, '<?= h($agent['_id'] ?? '') ?>', <?= !empty($agent['pinned']) ? 'true' : 'false' ?>)" title="<?= !empty($agent['pinned']) ? 'Desfijar' : 'Fijar' ?>"
                            class="p-2 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme <?= !empty($agent['pinned']) ? 'text-amber-400 border-amber-500/30 hover:bg-amber-500/10' : 'text-text-muted hover:text-amber-400 hover:border-amber-500/30' ?> transition-all">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </button>
                        <button onclick="openEditModal(<?= $i ?>)" title="Editar nombre y carpeta"
                            class="p-2 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme text-text-muted hover:text-primary-400 hover:bg-bg-elevated transition-all">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        </button>
                        <button onclick="deleteAgent('<?= h($agent['agentId'] ?? $agent['_id'] ?? '') ?>','<?= addslashes($agent['name'] ?? $agent['hostname'] ?? '') ?>')" title="Eliminar agente"
                            class="p-2 rounded-lg text-[11px] bg-bg-panel/80 border border-red-500/20 text-red-400 hover:bg-red-500/10 hover:border-red-500/30 transition-all">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>
                <?php endforeach; if ($lastGroup !== '___unset___') echo '</div></details>'; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
.control-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.375rem; padding:0.75rem; border-radius:0.75rem; border-width:1px; font-size:11px; font-weight:600; transition:all 0.2s; min-height:72px; }
.control-btn svg { width:1.25rem; height:1.25rem; }
.diag-btn { display:flex; align-items:center; justify-content:center; gap:0.5rem; padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:11px; font-weight:500; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); color:#94a3b8; transition:all 0.2s; }
.diag-btn:hover { color:#67e8f9; border-color:rgba(34,211,238,0.4); background:rgba(34,211,238,0.05); }
.diag-btn svg { width:1rem; height:1rem; color:#22d3ee; }
.forense-btn { display:flex; align-items:center; justify-content:center; gap:0.5rem; padding:0.625rem 0.75rem; border-radius:0.5rem; font-size:11px; font-weight:500; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); color:#94a3b8; transition:all 0.2s; }
.forense-btn:hover { color:#c084fc; border-color:rgba(168,85,247,0.4); background:rgba(168,85,247,0.05); }
.forense-btn svg { width:1rem; height:1rem; color:#c084fc; }
.func-btn { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.75rem; padding:1.25rem 0.75rem; border-radius:0.875rem; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.025); color:#cbd5e1; transition:all 0.2s; min-height:120px; text-align:center; }
.func-btn:hover { background:rgba(255,255,255,0.06); border-color:rgba(255,255,255,0.15); color:#fff; transform:translateY(-2px); }
.func-btn svg { width:2rem; height:2rem; }
.func-btn.cyan { color:#22d3ee; }
.func-btn.cyan:hover { background:rgba(34,211,238,0.08); border-color:rgba(34,211,238,0.3); }
.func-btn.purple { color:#c084fc; }
.func-btn.purple:hover { background:rgba(168,85,247,0.08); border-color:rgba(168,85,247,0.3); }
</style>

<!-- Modal detalle del agente -->
<div id="agent-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-50 p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-6xl max-h-[94vh] overflow-y-auto scrollbar-custom shadow-2xl">
        <div id="agent-modal-body"></div>
    </div>
</div>

<!-- Modal resultado de funciones -->
<div id="result-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm items-center justify-center p-4" onclick="if (event.target.id === 'result-modal') closeResultModal()" style="z-index:100">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-5xl max-h-[92vh] overflow-y-auto scrollbar-custom shadow-2xl relative">
        <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme bg-bg-elevated/30 sticky top-0 z-10">
            <h3 id="result-modal-title" class="text-[14px] font-semibold text-white flex items-center gap-2">
                <span id="result-modal-icon"></span>
                <span id="result-modal-title-text">Resultado</span>
            </h3>
            <button onclick="closeResultModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="result-modal-body" class="p-5 min-h-[120px]"></div>
    </div>
</div>

<!-- Modal editar agente -->
<div id="edit-agent-modal" class="hidden fixed inset-0 bg-black/75 backdrop-blur-sm items-center justify-center z-50 p-4" onclick="if (event.target.id === 'edit-agent-modal') closeEditModal()">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-md shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
            <h3 class="text-[14px] font-semibold text-white">Editar agente</h3>
            <button onclick="closeEditModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="text-[11px] text-text-subtle">Nombre personalizado</label>
                <input id="edit-name" type="text" class="w-full mt-1 bg-bg-input border border-border-theme rounded-lg px-3 py-2 text-[12px] text-text-heading focus:border-primary-500 outline-none" placeholder="Nombre del agente">
            </div>
            <div>
                <label class="text-[11px] text-text-subtle">Carpeta / sección</label>
                <input id="edit-group" type="text" list="existing-groups" class="w-full mt-1 bg-bg-input border border-border-theme rounded-lg px-3 py-2 text-[12px] text-text-heading focus:border-primary-500 outline-none" placeholder="General, Oficina, etc.">
                <datalist id="existing-groups">
                    <?php foreach ($folders as $f): ?><option value="<?= h($f['name'] ?? '') ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeEditModal()" class="px-4 py-2 rounded-lg text-[12px] text-text-muted hover:bg-bg-elevated transition-all">Cancelar</button>
                <button onclick="saveEditAgent()" class="px-4 py-2 rounded-lg text-[12px] bg-primary-600 hover:bg-primary-500 text-white transition-all">Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal instalación Linux -->
<div id="linux-install-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm items-center justify-center z-50 p-4" onclick="if (event.target.id === 'linux-install-modal') closeLinuxInstallModal()">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-xl shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme bg-bg-elevated/30 sticky top-0 z-10">
            <h3 class="text-[14px] font-semibold text-white flex items-center gap-2">
                <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Instalar agente Linux
            </h3>
            <button onclick="closeLinuxInstallModal()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <p class="text-[12px] text-text-subtle">Copia y ejecuta este comando en el servidor Linux. El script descarga el binario, lo instala en <code class="text-primary-400">/opt/securelab-agent</code>, crea el servicio systemd y lo inicia.</p>

            <div class="relative">
                <pre id="linux-install-command" class="w-full bg-bg-base border border-border-theme rounded-lg p-4 text-[11px] font-mono text-text-body overflow-x-auto whitespace-pre-wrap break-all"></pre>
                <button onclick="copyLinuxInstallCommand()" class="absolute top-2 right-2 p-1.5 rounded-md bg-bg-elevated/80 border border-border-theme text-text-muted hover:text-white hover:border-primary-500 transition-all" title="Copiar comando">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </button>
            </div>

            <p class="text-[10px] text-text-subtle">Requiere acceso root. El token del agente ya está incrustado en el script y la configuración.</p>

            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeLinuxInstallModal()" class="px-4 py-2 rounded-lg text-[12px] text-text-muted hover:bg-bg-elevated transition-all">Cerrar</button>
                <a id="linux-install-script-link" href="#" target="_blank" class="px-4 py-2 rounded-lg text-[12px] bg-primary-600 hover:bg-primary-500 text-white transition-all inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4 4m4-4v4m0 0H4m4 4h16a2 2 0 002-2V6a2 2 0 00-2-2H4a2 2 0 00-2 2v4a2 2 0 002 2h16a2 2 0 002-2V6a2 2 0 00-2-2H4a2 2 0 00-2 2v4a2 2 0 002 2z"/></svg>
                    Descargar script
                </a>
            </div>
        </div>
    </div>
</div>

<script>
const SL_TOKEN = <?= json_encode($token) ?>;
let AGENTS = <?= $combinedJson ?>;
let currentAgentIdx = null;
let agentModalInterval = null;
let editAgentIdx = null;
let editAgentId = '';

// Helper para obtener el ID del agente (agentId o _id)
function getAgentId(agent) {
    return agent.agentId || agent._id || '';
}

function closeAgentModal() {
    document.getElementById('agent-modal').classList.add('hidden');
    document.getElementById('agent-modal').classList.remove('flex');
    if (agentModalInterval) { clearInterval(agentModalInterval); agentModalInterval = null; }
    currentAgentIdx = null;
}

function openEditModal(idx) {
    const d = AGENTS[idx];
    if (!d) return;
    const a = d.agent || {};
    editAgentIdx = idx;
    editAgentId = a.agentId || a._id || '';
    document.getElementById('edit-name').value = a.name || a.hostname || '';
    document.getElementById('edit-group').value = a.group || '';
    const m = document.getElementById('edit-agent-modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function closeEditModal() {
    document.getElementById('edit-agent-modal').classList.add('hidden');
    document.getElementById('edit-agent-modal').classList.remove('flex');
    editAgentIdx = null;
    editAgentId = '';
}

function saveEditAgent() {
    if (!editAgentId) return;
    const name = document.getElementById('edit-name').value.trim();
    const group = document.getElementById('edit-group').value.trim();
    fetch('/api/agents/' + encodeURIComponent(editAgentId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ name: name, group: group, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al guardar'); }
    }).catch(function(){ alert('Error de red'); });
}

function createFolder() {
    const input = document.getElementById('new-folder');
    const name = input.value.trim();
    if (!name) return;
    fetch('/api-proxy.php?path=/api/folders/create', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ name: name, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al crear carpeta'); }
    }).catch(function(){ alert('Error de red'); });
}

function deleteFolder(name) {
    if (!confirm('¿Borrar la carpeta "' + name + '"? Los agentes que estuvieran en ella volverán a "Sin sección".\n\nEscribe el nombre de la carpeta para confirmar:')) return;
    const check = prompt('Confirma escribiendo el nombre de la carpeta:');
    if (check !== name) { alert('No coincide'); return; }
    fetch('/api-proxy.php?path=/api/folders/delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ name: name, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al borrar carpeta'); }
    }).catch(function(){ alert('Error de red'); });
}

function fmtBytes(b) {
    if (!b) return '0 B';
    const u = ['B','KB','MB','GB','TB'];
    let i = 0, v = b;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return v.toFixed(1) + ' ' + u[i];
}

function fmtUptime(s) {
    if (!s) return 'N/A';
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return (d ? d + 'd ' : '') + h + 'h ' + m + 'm';
}

function showToast(msg, type = 'info') {
    const container = document.getElementById('toast-container') || (() => {
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'fixed bottom-4 right-4 z-50 flex flex-col gap-2';
        document.body.appendChild(c);
        return c;
    })();
    const el = document.createElement('div');
    el.className = `px-3 py-2 rounded-lg text-[11px] font-medium shadow-lg animate-slide-up ${type === 'success' ? 'bg-emerald-500/90 text-white' : (type === 'error' ? 'bg-red-500/90 text-white' : 'bg-primary-500/90 text-white')}`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => { el.classList.add('animate-fade-out'); setTimeout(() => el.remove(), 300); }, 3000);
}

function copyToClipboard(text) {
    if (!text) return;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast('Agent ID copiado', 'success')).catch(() => showToast('No se pudo copiar', 'error'));
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('Agent ID copiado', 'success');
    }
}

function openResultModal(title, iconSvg) {
    document.getElementById('result-modal-title-text').textContent = title;
    document.getElementById('result-modal-icon').innerHTML = iconSvg || '';
    document.getElementById('result-modal-body').innerHTML = '<p class="text-[11px] text-text-subtle animate-pulse flex items-center gap-2"><svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Solicitando datos al agente...</p>';
    const m = document.getElementById('result-modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function closeResultModal() {
    const m = document.getElementById('result-modal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}

function setResultContent(html) {
    document.getElementById('result-modal-body').innerHTML = html;
}

function showErrorResult(msg) {
    setResultContent('<div class="rounded-lg bg-red-500/10 border border-red-500/20 p-4 text-red-400 text-[12px]">' + esc(msg) + '</div>');
}

function agentAction(agentId, action, extra) {
    const url = action === 'lockdown' ? '/api-proxy.php?path=/api/agents/lockdown' : '/api-proxy.php?path=/api/agents/' + encodeURIComponent(agentId) + '/command';
    const payload = action === 'lockdown'
        ? { token: SL_TOKEN, agentId: agentId, action: extra.action, message: extra.message }
        : { token: SL_TOKEN, command: action, params: extra || {} };
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const el = document.getElementById('agent-action-msg');
                if (el) el.className = 'px-3 py-2 rounded-lg text-[11px] bg-emerald-500/10 border border-emerald-500/30 text-emerald-400';
                if (el) el.textContent = 'Comando enviado. Aplicando en el equipo...';
                setTimeout(() => location.reload(), 1200);
            }
        });
}

function openAgentModal(idx) {
    const d = AGENTS[idx];
    if (!d) return;
    const a = d.agent || {};
    const h = d.host || {};
    const isOnline = a.status === 'online';
    const isLocked = !!(a.lockdown && a.lockdown.enabled) || !!(h.lockdown && h.lockdown.enabled);
    const lockedInfo = (a.lockdown && a.lockdown.enabled) ? a.lockdown : (h.lockdown || {});
    const displayName = a.name || a.hostname || h.hostname || a.agentId || 'N/A';
    const diskTotal = h.diskTotal || 0;
    const diskUsed = h.diskUsed != null ? h.diskUsed : (diskTotal - (h.diskFree || 0));
    const diskPct = h.disk != null ? h.disk : (diskTotal > 0 ? Math.round((diskUsed / diskTotal) * 100) : 0);
    const agentId = getAgentId(a);
    const cpu = h.cpu != null ? h.cpu : (a.cpu != null ? a.cpu : 0);
    const ram = h.ram != null ? h.ram : (a.ram != null ? a.ram : 0);

    document.getElementById('agent-modal-body').innerHTML = `
    <!-- HEADER -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme bg-gradient-to-r from-bg-panel to-bg-elevated/40">
        <div class="flex items-center gap-4 min-w-0">
            <div class="w-14 h-14 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-lg ring-2 ring-offset-2 ring-offset-bg-panel ${isOnline ? 'bg-emerald-500/15 text-emerald-400 ring-emerald-500/30' : 'bg-red-500/15 text-red-400 ring-red-500/30'}">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h3 id="agent-modal-title" class="text-[20px] font-bold text-white tracking-tight truncate">${displayName}</h3>
                    <span class="inline-flex items-center gap-1.5 text-[10px] px-2.5 py-0.5 rounded-full font-medium ${isOnline ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'}">
                        <span class="w-1.5 h-1.5 rounded-full ${isOnline ? 'bg-emerald-400 animate-pulse' : 'bg-red-400'}"></span>${isOnline ? 'Online' : 'Offline'}
                    </span>
                    ${isLocked ? '<span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full bg-red-500/15 text-red-400 border border-red-500/30 font-medium"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> BLOQUEADO</span>' : ''}
                </div>
                <p class="text-[11px] text-text-subtle mt-1">${a.platform || h.platform || ''} ${a.arch || h.arch || ''}${h.os ? ' · ' + h.os : ''} · <span class="font-mono text-text-muted">${(a.agentId || '').substring(0, 24)}</span></p>
            </div>
        </div>
        <div class="flex items-center gap-1.5">
            <button onclick="copyToClipboard('${agentId}')" title="Copiar Agent ID" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-bg-elevated transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </button>

            <button onclick="openEditModal(currentAgentIdx)" title="Editar nombre y carpeta" class="p-2 rounded-lg text-text-muted hover:text-primary-400 hover:bg-bg-elevated transition-all">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            </button>
            <button onclick="closeAgentModal()" class="text-text-muted hover:text-white transition-colors p-2 rounded-lg hover:bg-bg-elevated ml-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <div id="agent-action-msg"></div>

    <div class="p-6 space-y-6">
        <!-- Centro de control -->
        <div class="rounded-2xl border border-red-500/20 bg-gradient-to-br from-red-950/20 via-bg-panel/60 to-amber-950/10 p-5 shadow-lg">
            <div class="flex items-center gap-2.5 mb-4">
                <div class="w-8 h-8 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <div>
                    <h4 class="text-[15px] font-bold text-white">Centro de control del equipo</h4>
                    <p class="text-[11px] text-text-subtle">Acciones de seguridad, energía y comunicación remota</p>
                </div>
            </div>

            ${isLocked ? `
            <div class="rounded-xl border border-red-500/30 bg-red-500/[0.08] p-4 mb-4 flex items-start gap-3">
                <svg class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <div>
                    <p class="text-[13px] font-semibold text-red-400">Equipo bloqueado</p>
                    <p class="text-[11px] text-text-muted">Motivo: ${lockedInfo.message || lockedInfo.reason || 'Sin especificar'} · Por: ${lockedInfo.setBy || 'Sistema'} · ${(lockedInfo.setAt || '').substring(0, 19)}</p>
                </div>
            </div>
            ` : ''}

            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                ${isLocked ? `
                <button onclick="agentAction('${agentId}','lockdown',{action:'unlock',message:''})" class="col-span-2 sm:col-span-2 control-btn bg-emerald-600 hover:bg-emerald-500 text-white border-emerald-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                    <span>Desbloquear</span>
                </button>
                ` : `
                <button onclick="agentAction('${agentId}','lockdown',{action:'lock',message:document.getElementById('lock-msg').value})" class="col-span-2 sm:col-span-2 control-btn bg-red-600 hover:bg-red-500 text-white border-red-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>Bloquear ahora</span>
                </button>
                `}

                <button onclick="confirmPower('${agentId}','power_restart','¿Reiniciar el equipo?')" class="control-btn bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border-amber-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Reiniciar</span>
                </button>

                <button onclick="confirmPower('${agentId}','power_suspend','¿Suspender el equipo?')" class="control-btn bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 border-blue-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <span>Suspender</span>
                </button>

                <button onclick="confirmPower('${agentId}','power_off','¿APAGAR el equipo?')" class="control-btn bg-slate-500/15 hover:bg-slate-500/25 text-slate-300 border-slate-500/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span>Apagar</span>
                </button>
            </div>

            <div class="mt-3">
                ${!isLocked ? `<textarea id="lock-msg" rows="2" placeholder="Motivo / mensaje para mostrar y anunciar en el equipo (ej: uso indebido del equipo)..." class="w-full input-premium text-[11px]"></textarea>` : ''}
            </div>
        </div>

        <!-- KPI row -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            ${kpi('CPU', (h.cpu != null ? h.cpu : a.cpu) + '%', cpu > 80 ? '#ef4444' : '#818cf8', 'sh')}
            ${kpi('RAM', (h.ram != null ? h.ram : a.ram) + '%', ram > 80 ? '#ef4444' : '#34d399', 'wa')}
            ${kpi('Disco', diskPct + '%', diskPct > 80 ? '#ef4444' : '#fbbf24', 'hd')}
            ${kpi('Uptime', fmtUptime(h.uptime), '#38bdf8', 'up')}
            ${kpi('Procesos', h.processes != null ? h.processes : 'N/A', '#a78bfa', 'pr')}
            ${kpi('Conexiones', h.connections != null ? h.connections : 'N/A', '#f472b6', 'cn')}
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Información y rendimiento -->
            <div class="space-y-6">
                <div class="rounded-xl border border-border-theme/60 bg-bg-panel/40 p-5">
                    ${sectionTitle('#818cf8', 'Información del equipo')}
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        ${detailBox('IP', a.ip || h.ip || 'N/A')}
                        ${detailBox('Usuario', a.user || h.user || 'N/A')}
                        ${detailBox('Versión', a.version || '2.0.0')}
                        ${detailBox('Último latido', (a.lastSeen || h.lastSeen || 'N/A').substring(0, 19))}
                        ${detailBox('Agent ID', (a.agentId || 'N/A').substring(0, 24))}
                        ${detailBox('Sistema', (a.platform || h.platform || '') + ' ' + (a.arch || ''))}
                    </div>
                </div>

                <div class="rounded-xl border border-border-theme/60 bg-bg-panel/40 p-5">
                    ${sectionTitle('#34d399', 'Rendimiento en tiempo real')}
                    <div class="space-y-5">
                        ${bigMeter('CPU', h.cpu, a.cpu, cpu > 80 ? '#ef4444' : '#818cf8')}
                        ${bigMeter('RAM', h.ram, a.ram, ram > 80 ? '#ef4444' : '#34d399')}
                        ${bigMeter('Disco', diskPct, null, diskPct > 80 ? '#ef4444' : '#fbbf24', diskTotal ? fmtBytes(diskUsed) + ' usado · ' + fmtBytes(diskTotal) + ' total' : null)}
                    </div>
                </div>
            </div>

            <!-- Diagnóstico en vivo -->
            <div class="space-y-6">
                <div class="rounded-xl border border-border-theme/60 bg-bg-panel/40 p-5">
                    ${sectionTitle('#22d3ee', 'Diagnóstico en vivo')}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button onclick="showProcesses('${agentId}')" class="func-btn cyan">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span class="text-[12px] font-semibold">Procesos</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Ver procesos activos</span>
                        </button>
                        <button onclick="showHealth('${agentId}')" class="func-btn cyan">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-[12px] font-semibold">Salud</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Snapshot del sistema</span>
                        </button>
                        <button onclick="showDefender('${agentId}')" class="func-btn cyan">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="text-[12px] font-semibold">Seguridad</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Estado de seguridad</span>
                        </button>
                        <button onclick="showScreenshot('${agentId}')" class="func-btn cyan">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="text-[12px] font-semibold">Captura</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Capturar pantalla</span>
                        </button>
                    </div>
                </div>

                <!-- Forense e historial -->
                <div class="rounded-xl border border-border-theme/60 bg-bg-panel/40 p-5">
                    ${sectionTitle('#a78bfa', 'Forense e historial')}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button onclick="showForensicsFiles('${agentId}')" class="func-btn purple">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m-3 9h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span class="text-[12px] font-semibold">Archivos</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Archivos recientes</span>
                        </button>
                        <button onclick="showForensicsDB('${agentId}')" class="func-btn purple">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                            <span class="text-[12px] font-semibold">BBDD</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Logs de base de datos</span>
                        </button>
                        <button onclick="showForensicsHost('${agentId}')" class="func-btn purple">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span class="text-[12px] font-semibold">Sistema</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Eventos del sistema</span>
                        </button>
                        <button onclick="showCommandHistory('${agentId}')" class="func-btn purple">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-[12px] font-semibold">Historial</span>
                            <span class="text-[9px] text-text-subtle font-normal leading-tight">Comandos ejecutados</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    `;

    currentAgentIdx = idx;
    const m = document.getElementById('agent-modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
    window.curAgentId = a.agentId || '';

    if (agentModalInterval) clearInterval(agentModalInterval);
    agentModalInterval = null;
}

function detailBox(label, value) {
    return `<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-3 py-2">
        <p class="text-[9px] font-medium text-text-subtle uppercase tracking-widest mb-1">${label}</p>
        <p class="text-[11px] text-text-body truncate">${value}</p>
    </div>`;
}

function sectionTitle(color, text) {
    return `<div class="flex items-center gap-2.5 mb-3">
        <span class="w-1.5 h-5 rounded-full" style="background:${color}"></span>
        <h4 class="text-[13px] font-semibold text-white tracking-tight">${text}</h4>
    </div>`;
}

const KPI_ICONS = {
    sh: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
    wa: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11a7 7 0 01-14 0M12 4v13m-3 3h6"/>',
    hd: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
    up: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    pr: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
    cn: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>'
};

function kpi(label, value, color, icon) {
    return `<div class="rounded-xl border border-border-theme bg-bg-elevated/40 p-3.5 flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:${color}1a;color:${color}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">${KPI_ICONS[icon] || KPI_ICONS.sh}</svg>
        </div>
        <div class="min-w-0">
            <p class="text-[9px] text-text-subtle uppercase tracking-wider">${label}</p>
            <p class="text-[16px] font-bold leading-none tracking-tight" style="color:${color}">${value}</p>
        </div>
    </div>`;
}

function bigMeter(label, val, altVal, color, extra) {
    const v = val != null ? val : (altVal != null ? altVal : 0);
    const pct = Math.max(0, Math.min(100, Math.round(Number(v) || 0)));
    return `<div>
        <div class="flex items-center justify-between mb-1.5">
            <p class="text-[11px] font-medium text-text-muted uppercase tracking-wider">${label}</p>
            <p class="text-[13px] font-bold" style="color:${color}">${pct}%</p>
        </div>
        <div class="h-2.5 rounded-full bg-white/[0.06] overflow-hidden">
            <div class="h-full rounded-full transition-all duration-700" style="width:${pct}%;background:linear-gradient(90deg, ${color}88, ${color})"></div>
        </div>
        ${extra ? '<p class="text-[10px] text-text-subtle mt-1.5">' + extra + '</p>' : ''}
    </div>`;
}

function meter(label, val, altVal, color, extra) {
    const v = val != null ? val : (altVal != null ? altVal : 0);
    const pct = Math.max(0, Math.min(100, Math.round(Number(v) || 0)));
    return `<div class="rounded-xl border border-border-theme bg-bg-elevated/40 p-3">
        <div class="flex items-center justify-between mb-1.5">
            <p class="text-[10px] font-medium text-text-muted uppercase tracking-wider">${label}</p>
            <p class="text-[11px] font-semibold" style="color:${color}">${pct}%</p>
        </div>
        <div class="h-1.5 rounded-full bg-white/[0.06] overflow-hidden">
            <div class="h-full rounded-full transition-all" style="width:${pct}%;background:${color}"></div>
        </div>
        ${extra ? '<p class="text-[9px] text-text-subtle mt-1.5">' + extra + '</p>' : ''}
    </div>`;
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function confirmPower(agentId, cmd, msg) {
    if (!confirm(msg)) return;
    agentAction(agentId, cmd, {});
}

const ICONS = {
    process: '<svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    health: '<svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    defender: '<svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
    screenshot: '<svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    files: '<svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-3 9h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
    db: '<svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>',
    host: '<svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    history: '<svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
};

function showProcesses(agentId) { openResultModal('Procesos activos', ICONS.process); agentRequestData(agentId, 'processes', renderProcesses, 'result-modal-body'); }
function showHealth(agentId) { openResultModal('Salud del sistema', ICONS.health); agentRequestData(agentId, 'health', renderHealth, 'result-modal-body'); }
function showDefender(agentId) { openResultModal('Estado de seguridad', ICONS.defender); agentRequestData(agentId, 'defender', renderDefender, 'result-modal-body'); }
function showScreenshot(agentId) { openResultModal('Captura de pantalla', ICONS.screenshot); agentRequestData(agentId, 'screenshot', renderShot, 'result-modal-body'); }
function showForensicsFiles(agentId) { openResultModal('Archivos recientes', ICONS.files); loadForensics(agentId, 'files', 'result-modal-body'); }
function showForensicsDB(agentId) { openResultModal('Logs de base de datos', ICONS.db); loadForensics(agentId, 'db', 'result-modal-body'); }
function showForensicsHost(agentId) { openResultModal('Eventos del sistema', ICONS.host); loadForensics(agentId, 'host', 'result-modal-body'); }
function showCommandHistory(agentId) { openResultModal('Historial de comandos', ICONS.history); loadCommandHistory(agentId, 'result-modal-body'); }

function agentRequestData(agentId, type, renderFn, boxId) {
    const box = document.getElementById(boxId);
    if (box) box.innerHTML = '<p class="text-[10px] text-text-subtle animate-pulse">Solicitando datos al agente...</p>';
    fetch('/api-proxy.php?path=/api/agents/request-data', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ agentId: agentId, type: type })
    }).then(function (r) { return r.json(); }).then(function () {
        let tries = 0;
        const poll = function () {
            tries++;
            fetch('/api-proxy.php?path=/api/agents/' + encodeURIComponent(agentId) + '/data&type=' + type)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.data != null) { renderFn(d.data, boxId); return; }
                    if (tries < 20) setTimeout(poll, 1500);
                    else if (box) box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin respuesta del agente.</p>';
                })
                .catch(function () { if (tries < 20) setTimeout(poll, 1500); else if (box) box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin respuesta del agente.</p>'; });
        };
        setTimeout(poll, 1500);
    }).catch(function () { if (box) box.innerHTML = '<p class="text-[10px] text-red-400">Error al solicitar datos.</p>'; });
}

function renderProcesses(data, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    if (!Array.isArray(data) || !data.length) { box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin procesos.</p>'; return; }
    const maxMem = Math.max.apply(null, data.map(function (p) { return p.memMB || 0; })) || 1;
    box.innerHTML =
        '<div class="flex items-center justify-between mb-2">' +
            '<p class="text-[11px] font-semibold text-white inline-flex items-center gap-2"><svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg> Procesos con más memoria</p>' +
            '<span class="text-[10px] px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">' + data.length + ' procesos</span>' +
        '</div>' +
        '<div class="overflow-hidden rounded-lg border border-border-theme">' +
        '<table class="w-full text-[10px]">' +
        '<thead><tr class="bg-bg-elevated/70 text-text-subtle uppercase tracking-wider text-[9px]"><th class="text-left px-2.5 py-2">Proceso</th><th class="text-right px-2 py-2">PID</th><th class="text-right px-2 py-2 w-24">Memoria</th><th class="text-right px-2 py-2">CPU(s)</th><th class="w-9"></th></tr></thead>' +
        '<tbody>' +
        data.map(function (p, i) {
            const memPct = maxMem ? Math.round((p.memMB || 0) / maxMem * 100) : 0;
            return '<tr class="' + (i % 2 ? 'bg-bg-base/30' : '') + ' hover:bg-bg-elevated/60 transition-colors border-t border-border-theme/30">' +
                '<td class="px-2.5 py-2"><div class="flex items-center gap-2 min-w-0">' +
                    '<span class="w-6 h-6 rounded-md bg-indigo-500/10 text-indigo-400 flex items-center justify-center flex-shrink-0"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></span>' +
                    '<span class="font-medium text-text-heading truncate max-w-[150px]">' + esc(p.name || '?') + '</span>' +
                '</div></td>' +
                '<td class="px-2 py-2 text-right font-mono text-text-subtle">' + p.pid + '</td>' +
                '<td class="px-2 py-2 text-right"><div class="flex items-center justify-end gap-1.5">' +
                    '<div class="w-12 h-1 rounded-full bg-white/[0.06] overflow-hidden"><div class="h-full" style="width:' + memPct + '%;background:#34d399"></div></div>' +
                    '<span class="text-text-muted">' + (p.memMB || 0) + ' MB</span>' +
                '</div></td>' +
                '<td class="px-2 py-2 text-right text-text-muted">' + p.cpu + '</td>' +
                '<td class="px-1.5 py-2 text-right">' +
                    '<button onclick="agentAction(\'' + (window.curAgentId || '') + '\',\'kill_process\',{pid:' + p.pid + '})" title="Terminar proceso" class="w-6 h-6 rounded-md bg-red-500/10 text-red-400 hover:bg-red-500/20 inline-flex items-center justify-center transition-all"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>' +
                '</td>' +
            '</tr>';
        }).join('') +
        '</tbody></table></div>';
}

function hStat(k, v) {
    return '<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-2 py-1.5"><p class="text-[8px] text-text-subtle uppercase tracking-widest">' + k + '</p><p class="text-[12px] font-bold text-white">' + v + '</p></div>';
}

function renderHealth(data, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    if (!data) { box.innerHTML = '<p class="text-[12px] text-text-subtle text-center py-8">Sin datos de salud.</p>'; return; }
    const used = (data.diskTotal || 0) - (data.diskFree || 0);
    const diskPct = data.diskTotal ? Math.round(used / data.diskTotal * 100) : 0;
    box.innerHTML = '<div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-1">' +
        hStat('CPU', (data.cpu || 0) + '%') + hStat('RAM', (data.memory || 0) + '%') + hStat('Disco', diskPct + '%') + hStat('Uptime', fmtUptime(data.uptime)) +
        '</div>' +
        (data.topProcesses && data.topProcesses.length
            ? '<p class="text-[9px] text-text-subtle mt-2 mb-1">Top procesos</p><div class="space-y-0.5">' + data.topProcesses.map(function (p) {
                return '<div class="flex justify-between text-[10px]"><span class="text-text-muted truncate">' + esc(p.name || '') + '</span><span class="text-text-subtle">' + p.memMB + ' MB</span></div>';
              }).join('') + '</div>'
            : '');
}

function sevBadge(ok) {
    return ok
        ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">ACTIVO</span>'
        : '<span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-full bg-red-500/10 text-red-400 border border-red-500/20">DESACTIVADO</span>';
}

function renderDefender(data, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    if (!data) { box.innerHTML = '<p class="text-[12px] text-text-subtle text-center py-8">Sin datos de seguridad.</p>'; return; }
    box.innerHTML = '<div class="grid grid-cols-2 gap-2 mt-1">' +
        '<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-2 py-1.5"><p class="text-[8px] text-text-subtle uppercase tracking-widest mb-1">Antivirus</p>' + sevBadge(data.antivirusEnabled) + '</div>' +
        '<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-2 py-1.5"><p class="text-[8px] text-text-subtle uppercase tracking-widest mb-1">Protección en tiempo real</p>' + sevBadge(data.realTimeProtection) + '</div>' +
        '<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-2 py-1.5"><p class="text-[8px] text-text-subtle uppercase tracking-widest mb-1">Firewall</p>' + sevBadge(data.firewallEnabled) + '</div>' +
        '<div class="rounded-lg border border-border-theme bg-bg-elevated/40 px-2 py-1.5"><p class="text-[8px] text-text-subtle uppercase tracking-widest mb-1">Firma</p><p class="text-[10px] text-text-muted">' + esc(data.signatureVersion || '-') + '</p></div>' +
        '</div>' +
        (data.signatureUpdated ? '<p class="text-[9px] text-text-subtle mt-2">Última actualización de firmas: ' + esc(data.signatureUpdated) + '</p>' : '') +
        (data.firewallProfiles ? '<p class="text-[9px] text-text-subtle mt-1">Perfiles firewall: ' + esc(data.firewallProfiles) + '</p>' : '');
}

function renderShot(data, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    if (!data || !data.image) { box.innerHTML = '<p class="text-[12px] text-text-subtle text-center py-8">Sin captura disponible.</p>'; return; }
    box.innerHTML = '<img src="' + data.image + '" class="w-full rounded-lg border border-border-theme" alt="Captura de pantalla">';
}

function loadCommandHistory(agentId, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    box.innerHTML = '<p class="text-[10px] text-text-subtle animate-pulse">Cargando historial...</p>';
    fetch('/api-proxy.php?path=/api/agents/' + encodeURIComponent(agentId) + '/commands', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}'
    }).then(function (r) { return r.json(); }).then(function (list) {
        if (!Array.isArray(list) || !list.length) { box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin comandos registrados.</p>'; return; }
        box.innerHTML = '<div class="rounded-lg border border-border-theme overflow-hidden mt-1"><table class="w-full text-[10px]"><tbody>' +
            list.slice(0, 15).map(function (c) {
                return '<tr class="border-t border-border-theme/30"><td class="px-2 py-1.5 text-text-muted">' + esc(String(c.command || '')) + '</td>' +
                    '<td class="px-2 py-1.5 text-right">' + (c.executed ? '<span class="text-emerald-400">OK</span>' : '<span class="text-yellow-400">pendiente</span>') + '</td>' +
                    '<td class="px-2 py-1.5 text-right text-text-subtle">' + esc((c.createdAt || '').substring(0, 16)) + '</td></tr>';
            }).join('') +
            '</tbody></table></div>';
    }).catch(function () { box.innerHTML = '<p class="text-[10px] text-red-400">Error al cargar historial.</p>'; });
}

function loadForensics(agentId, type, boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    box.innerHTML = '<p class="text-[10px] text-text-subtle animate-pulse">Cargando forense...</p>';
    fetch('/api-proxy.php?path=/api/agents/' + encodeURIComponent(agentId) + '/forensics&type=' + encodeURIComponent(type), {
        method: 'GET', headers: { 'Authorization': 'Bearer ' + SL_TOKEN }
    }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.success || !Array.isArray(res.events)) { box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin datos forenses.</p>'; return; }
        renderForensics(res.events, type, box);
    }).catch(function () { box.innerHTML = '<p class="text-[10px] text-red-400">Error de red.</p>'; });
}

function renderForensics(events, type, box) {
    if (!events.length) { box.innerHTML = '<p class="text-[10px] text-text-subtle">Sin eventos.</p>'; return; }
    const headers = type === 'files'
        ? '<th class="px-2 py-1.5 font-medium">Fecha</th><th class="px-2 py-1.5 font-medium">Evento</th><th class="px-2 py-1.5 font-medium">Ruta</th><th class="px-2 py-1.5 font-medium">Proceso</th>'
        : type === 'db'
            ? '<th class="px-2 py-1.5 font-medium">Fecha</th><th class="px-2 py-1.5 font-medium">BBDD</th><th class="px-2 py-1.5 font-medium">Consulta</th><th class="px-2 py-1.5 font-medium">Op</th>'
            : '<th class="px-2 py-1.5 font-medium">Fecha</th><th class="px-2 py-1.5 font-medium">Gravedad</th><th class="px-2 py-1.5 font-medium">Título</th><th class="px-2 py-1.5 font-medium">Detalle</th>';
    const rows = events.map(function (e) {
        const d = (e.createdAt || e.timestamp || '').substring ? (e.createdAt || e.timestamp || '').substring(0, 19) : '';
        if (type === 'files') {
            return '<tr class="border-t border-border-theme/30"><td class="px-2 py-1.5 text-[10px] font-mono text-text-muted">' + esc(d) + '</td><td class="px-2 py-1.5 text-[10px] text-text-body">' + esc(e.eventType || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-body break-all">' + esc(e.path || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-muted">' + esc(e.process || '') + '</td></tr>';
        }
        if (type === 'db') {
            return '<tr class="border-t border-border-theme/30"><td class="px-2 py-1.5 text-[10px] font-mono text-text-muted">' + esc(d) + '</td><td class="px-2 py-1.5 text-[10px] text-text-body">' + esc(e.database || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-body break-all">' + esc(e.query || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-muted">' + esc(e.operation || '') + '</td></tr>';
        }
        return '<tr class="border-t border-border-theme/30"><td class="px-2 py-1.5 text-[10px] font-mono text-text-muted">' + esc(d) + '</td><td class="px-2 py-1.5 text-[10px] text-text-body">' + esc(e.severity || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-body break-all">' + esc(e.title || '') + '</td><td class="px-2 py-1.5 text-[10px] text-text-muted">' + esc(String(e.detail || '').substring(0, 80)) + '</td></tr>';
    }).join('');
    box.innerHTML = '<div class="rounded-lg border border-border-theme overflow-hidden mt-1"><table class="w-full text-[10px] text-left"><thead class="bg-bg-elevated/60 text-text-subtle"><tr>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function filterAgents(q) {
    q = q.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.agent-card').forEach(function (card) {
        const match = !q || (card.dataset.search || '').includes(q);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.querySelectorAll('.agent-folder').forEach(function (folder) {
        const shown = folder.querySelectorAll('.agent-card:not([style*="display: none"])');
        const count = shown.length;
        const badge = folder.querySelector('.folder-count');
        if (badge) badge.textContent = '(' + count + ')';
        if (count > 0) {
            folder.style.display = '';
            if (q) folder.setAttribute('open', '');
        } else {
            folder.style.display = 'none';
        }
    });
    const c = document.getElementById('agent-count');
    if (c) c.textContent = visible;
}

function pinAgent(idx, agentId, pinned) {
    if (!agentId) return;
    fetch('/api/agents/' + encodeURIComponent(agentId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ pinned: !pinned, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al fijar'); }
    }).catch(function(){ alert('Error de red'); });
}

function promptRename(idx, agentId, current) {
    if (current === null) current = '';
    const name = prompt('Nuevo nombre para el agente:', current);
    if (name === null) return;
    fetch('/api/agents/' + encodeURIComponent(agentId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ name: name, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) {
            if (AGENTS[idx] && AGENTS[idx].agent) AGENTS[idx].agent.name = name;
            const listTitle = document.getElementById('agent-title-' + idx);
            if (listTitle) {
                const star = listTitle.querySelector('span');
                listTitle.innerHTML = (star ? star.outerHTML : '') + esc(name || '');
            }
            const modalTitle = document.getElementById('agent-modal-title');
            if (modalTitle) modalTitle.textContent = name || current;
        } else {
            alert(res.error || 'Error al renombrar');
        }
    }).catch(function(){ alert('Error de red'); });
}

function promptGroup(idx, agentId, current) {
    if (current === null) current = '';
    const group = prompt('Nombre de sección/carpeta:', current);
    if (group === null) return;
    fetch('/api/agents/' + encodeURIComponent(agentId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
        body: JSON.stringify({ group: group, token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al mover'); }
    }).catch(function(){ alert('Error de red'); });
}

function deleteAgent(agentId, hostname) {
    if (!confirm('¿Eliminar el agente "' + hostname + '" (' + agentId + ')?')) return;
    fetch('/api-proxy.php?path=/api/agents/' + encodeURIComponent(agentId) + '/delete', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + SL_TOKEN, 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: SL_TOKEN })
    }).then(function(r){ return r.json(); }).then(function(res){
        if (res.success) { location.reload(); } else { alert(res.error || 'Error al eliminar'); }
    }).catch(function(){ alert('Error de red'); });
}

async function downloadAgent(platform) {
    if (platform === 'linux-x64') {
        showLinuxInstallModal();
        return;
    }

    try {
        // 1. Crear deploy en el backend
        const deployRes = await fetch('/api-proxy.php?path=/api/agents/deploy', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
            body: JSON.stringify({ platform, userAgent: navigator.userAgent })
        });
        const deployData = await deployRes.json();
        
        if (!deployData.success) {
            throw new Error(deployData.error || 'Error creando deploy');
        }
        
        // 2. Descargar el archivo usando proxy (parámetros separados)
        const downloadUrl = '/api-proxy.php?path=/api/agents/download/' + platform + '&deploy=' + encodeURIComponent(deployData.deployId) + '&token=' + encodeURIComponent(SL_TOKEN);
        
        const res = await fetch(downloadUrl);
        if (!res.ok) throw new Error('Error al descargar agente');
        
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = platform === 'win-x64' ? 'SecureLabAgent-Installer.exe' : ('securelab-agent-' + platform);
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        // 3. Mostrar toast
        if (typeof showToast === 'function') {
            showToast('Deploy creado: ' + deployData.deployId + '. Descarga iniciada...', 'success');
        } else {
            alert('Deploy creado: ' + deployData.deployId + '. Descarga iniciada...');
        }
    } catch (err) {
        if (typeof showToast === 'function') {
            showToast('Error: ' + err.message, 'error');
        } else {
            alert('Error: ' + err.message);
        }
    }
}

async function downloadInstaller() {
    try {
        const res = await fetch('/api-proxy.php?path=/api/agents/download/win-x64&installer=1&token=' + encodeURIComponent(SL_TOKEN));
        if (!res.ok) throw new Error('Error al descargar instalador');
        
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'SecureLabAgent-Installer.exe';
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        if (typeof showToast === 'function') {
            showToast('Instalador descargado. El token ya está configurado dentro del instalador.', 'success');
        }
    } catch (err) {
        if (typeof showToast === 'function') {
            showToast('Error: ' + err.message, 'error');
        } else {
            alert('Error: ' + err.message);
        }
    }
}

async function showLinuxInstallModal() {
    const modal = document.getElementById('linux-install-modal');
    const box = document.getElementById('linux-install-command');
    const link = document.getElementById('linux-install-script-link');
    box.textContent = 'Generando comando de instalación...';
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    try {
        const res = await fetch('/api-proxy.php?path=/api/agents/install/linux', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + SL_TOKEN },
            body: JSON.stringify({ token: SL_TOKEN })
        });
        const data = await res.json();

        if (data.success && data.command) {
            box.textContent = data.command;
            if (link) link.href = data.scriptUrl || '#';
        } else {
            box.textContent = 'Error: ' + (data.error || 'No se pudo generar el comando');
        }
    } catch (err) {
        box.textContent = 'Error de red: ' + err.message;
    }
}

function closeLinuxInstallModal() {
    const modal = document.getElementById('linux-install-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function copyLinuxInstallCommand() {
    const box = document.getElementById('linux-install-command');
    if (!box || !box.textContent) return;
    navigator.clipboard.writeText(box.textContent).then(() => {
        if (typeof showToast === 'function') {
            showToast('Comando copiado al portapapeles', 'success');
        } else {
            alert('Comando copiado');
        }
    }).catch(() => alert('No se pudo copiar'));
}

filterAgents(document.getElementById('agent-search').value);

document.addEventListener('click', function (e) {
    if (e.target.id === 'agent-modal') closeAgentModal();
    if (e.target.id === 'edit-agent-modal') closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
