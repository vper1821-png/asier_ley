<?php
$pageTitle = 'Panel DPO';
require_once __DIR__ . '/../includes/header.php';
require_login();

$token = $_SESSION['token'] ?? '';
$arcoRes = api_post_form('/api/arco/requests', ['token' => $token]);
$requests = is_array($arcoRes) && empty($arcoRes['error']) ? $arcoRes : [];

$typeCfg = [
    'acceso' => 'Acceso', 'rectificacion' => 'Rectificación', 'cancelacion' => 'Cancelación',
    'oposicion' => 'Oposición', 'portabilidad' => 'Portabilidad',
];
$statusCfg = [
    'pending' => ['label' => 'Pendiente', 'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20'],
    'in_progress' => ['label' => 'En proceso', 'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20'],
    'completed' => ['label' => 'Completada', 'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'],
    'rejected' => ['label' => 'Rechazada', 'class' => 'bg-red-500/10 text-red-400 border-red-500/20'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'dpo'; require_once __DIR__ . '/../includes/sidebar.php'; ?>
    
    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">
        
        <!-- Top App Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-purple-600/30 to-pink-500/20 border border-purple-500/30 flex items-center justify-center text-purple-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        Panel DPO
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-mono bg-purple-500/10 text-purple-400 border border-purple-500/20">
                            Ley 21.719
                        </span>
                    </h1>
                    <p class="text-[11px] text-text-muted">Delegado de Protección de Datos - Gestión de solicitudes ARCO</p>
                </div>
            </div>

            <!-- Action Button -->
            <div class="flex items-center gap-2.5">
                <a href="/arco-solicitud" target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-500 hover:to-pink-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-purple-500/20 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Formulario Público
                </a>
                <button onclick="location.reload()" class="px-3 py-2 rounded-xl bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Main Body Workspace -->
        <div class="flex-1 overflow-hidden flex flex-col p-4 sm:p-6 min-h-0 space-y-4">

            <!-- Metrics Bento Strip -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 flex-shrink-0">
                <!-- Total -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Total Solicitudes</p>
                        <p class="text-xl font-bold text-white font-mono mt-0.5"><?= count($requests) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                </div>

                <!-- Pendientes -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-amber-400/90 tracking-wider">Pendientes</p>
                        <p class="text-xl font-bold text-amber-400 font-mono mt-0.5"><?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'pending')) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse"></span>
                    </div>
                </div>

                <!-- En proceso -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-blue-400/90 tracking-wider">En Proceso</p>
                        <p class="text-xl font-bold text-blue-400 font-mono mt-0.5"><?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'in_progress')) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>

                <!-- Completadas -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Completadas</p>
                        <p class="text-xl font-bold text-emerald-400 font-mono mt-0.5"><?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'completed')) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
            </div>

            <!-- Info banner -->
            <div class="px-4 py-3 rounded-xl bg-purple-500/[0.06] border border-purple-500/20 flex-shrink-0">
                <p class="text-[11px] text-text-body leading-relaxed">
                    <span class="font-semibold text-purple-300">Rol DPO:</span> Como Delegado de Protección de Datos, supervisa el cumplimiento de la Ley 21.719 y gestiona las solicitudes ARCO dentro del plazo legal de 30 días.
                </p>
            </div>

            <!-- Main Requests List -->
            <div class="flex-1 min-h-0 bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm flex flex-col">
                
                <!-- Search & Filter Controls -->
                <div class="p-3.5 border-b border-border-theme space-y-2.5 bg-bg-surface/30">
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle pointer-events-none">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" id="dpo-search" placeholder="Buscar por solicitante, email, tracking ID..."
                               oninput="filterDpoList()"
                               class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500/20 transition-all">
                    </div>

                    <!-- Status Filter Pills -->
                    <div class="flex items-center gap-1 overflow-x-auto scrollbar-none py-0.5" id="dpo-status-filters">
                        <button type="button" onclick="setDpoStatusFilter('all')" data-status="all"
                                class="dpo-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap">
                            Todos (<?= count($requests) ?>)
                        </button>
                        <button type="button" onclick="setDpoStatusFilter('pending')" data-status="pending"
                                class="dpo-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Pendientes (<?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'pending')) ?>)
                        </button>
                        <button type="button" onclick="setDpoStatusFilter('in_progress')" data-status="in_progress"
                                class="dpo-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            En Proceso (<?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'in_progress')) ?>)
                        </button>
                        <button type="button" onclick="setDpoStatusFilter('completed')" data-status="completed"
                                class="dpo-status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                            Completadas (<?= count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'completed')) ?>)
                        </button>
                    </div>
                </div>

                <!-- Requests Scrollable List -->
                <div class="flex-1 overflow-y-auto p-2.5 space-y-1.5 scrollbar-custom" id="dpo-inbox-items">
                    <?php if (empty($requests)): ?>
                    <div class="flex flex-col items-center justify-center py-16 px-4 text-center space-y-3">
                        <div class="w-12 h-12 rounded-2xl bg-white/[0.02] border border-white/[0.06] flex items-center justify-center text-text-subtle">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-text-heading">Sin solicitudes ARCO</p>
                            <p class="text-[11px] text-text-subtle mt-0.5">No hay solicitudes registradas en el sistema DPO</p>
                        </div>
                        <a href="/arco-solicitud" target="_blank" class="px-3 py-1.5 rounded-lg bg-purple-600/20 text-purple-300 border border-purple-500/30 text-xs font-medium hover:bg-purple-600/30 transition-colors">
                            Ver Formulario Público
                        </a>
                    </div>
                    <?php else: ?>
                    <?php foreach ($requests as $req):
                        $st = $statusCfg[$req['status'] ?? 'pending'] ?? $statusCfg['pending'];
                        $rid = $req['requestId'] ?? $req['_id'] ?? '';
                        $shortId = substr($rid, -6);
                        $dateStr = substr($req['createdAt'] ?? '', 0, 10);
                        $reqType = $typeCfg[$req['tipo'] ?? $req['type'] ?? ''] ?? ucfirst($req['tipo'] ?? $req['type'] ?? 'Solicitud');
                        $solicitanteNombre = $req['solicitante']['nombre'] ?? ($req['name'] ?? $req['requesterName'] ?? 'N/A');
                        $solicitanteEmail = $req['solicitante']['email'] ?? ($req['email'] ?? $req['requesterEmail'] ?? 'N/A');
                    ?>
                    <div data-dpo-item
                         data-status="<?= h($req['status'] ?? 'pending') ?>"
                         data-search="<?= h(mb_strtolower($solicitanteNombre . ' ' . $solicitanteEmail . ' ' . $shortId)) ?>"
                         class="block p-3 rounded-xl border transition-all duration-200 border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <span class="text-[9px] font-mono text-purple-400 font-medium px-1.5 py-0.5 rounded bg-purple-950/40 border border-purple-500/20">
                                #DPO-<?= h(strtoupper($shortId)) ?>
                            </span>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                    <?= h($st['label']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <h3 class="text-xs font-semibold text-text-heading truncate leading-snug">
                            <?= h($reqType) ?> — <?= h($solicitanteNombre) ?>
                        </h3>
                        
                        <p class="text-[11px] text-text-subtle truncate mt-1 leading-relaxed">
                            <?= h($solicitanteEmail) ?>
                        </p>

                        <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-white/[0.04] text-[10px] text-text-subtle">
                            <span class="font-mono"><?= h($dateStr) ?></span>
                            <span class="text-[10px] px-2 py-0.5 rounded-full border border-border-theme/50 text-text-muted">
                                Tracking: <?= h($shortId) ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function filterDpoList() {
    const search = document.getElementById('dpo-search').value.toLowerCase();
    const statusFilter = document.querySelector('.dpo-status-tab-btn.bg-primary-500\\/15')?.dataset.status || 'all';
    const items = document.querySelectorAll('[data-dpo-item]');
    
    items.forEach(item => {
        const searchText = item.dataset.search || '';
        const itemStatus = item.dataset.status || 'pending';
        
        const matchesSearch = searchText.includes(search);
        const matchesStatus = statusFilter === 'all' || itemStatus === statusFilter;
        
        item.style.display = (matchesSearch && matchesStatus) ? 'block' : 'none';
    });
}

function setDpoStatusFilter(status) {
    document.querySelectorAll('.dpo-status-tab-btn').forEach(btn => {
        btn.classList.remove('bg-primary-500/15', 'text-primary-300', 'border-primary-500/30');
        btn.classList.add('text-text-muted', 'hover:text-white', 'hover:bg-white/[0.04]', 'border-transparent');
    });
    
    const activeBtn = document.querySelector(`.dpo-status-tab-btn[data-status="${status}"]`);
    if (activeBtn) {
        activeBtn.classList.remove('text-text-muted', 'hover:text-white', 'hover:bg-white/[0.04]', 'border-transparent');
        activeBtn.classList.add('bg-primary-500/15', 'text-primary-300', 'border-primary-500/30');
    }
    
    filterDpoList();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
