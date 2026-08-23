<?php
$pageTitle = 'Panel de Control de Privacidad del Equipo';
require_once __DIR__ . '/../includes/header.php';
require_login();

$agentId = $_GET['agentId'] ?? '';
$token = $_SESSION['token'] ?? '';

$error = '';
$host = null;
$arcoRequests = [];
$sensitiveEvents = [];
$sensitiveCount = 0;
$config = [];

if ($agentId) {
    $res = api_post_form('/api/host-privacy/summary', ['token' => $token, 'agentId' => $agentId]);
    if (!empty($res['error'])) {
        $error = $res['error'];
    } else {
        $host = $res['host'] ?? null;
        $arcoRequests = $res['arcoRequests'] ?? [];
        $sensitiveEvents = $res['sensitiveEvents'] ?? [];
        $sensitiveCount = $res['sensitiveEventsCount'] ?? 0;
        $config = $res['complianceConfig'] ?? [];
    }
}

$types = [
    'acceso' => ['Acceso', 'solicita conocer los datos personales almacenados en este equipo.'],
    'rectificacion' => ['Rectificación', 'solicita corregir datos personales incorrectos.'],
    'cancelacion' => ['Cancelación', 'solicita eliminar datos personales cuando ya no son necesarios.'],
    'oposicion' => ['Oposición', 'solicita dejar de tratar datos personales para fines específicos.'],
    'portabilidad' => ['Portabilidad', 'solicita recibir los datos personales en formato electrónico.'],
    'supresion' => ['Supresión', 'solicita eliminar datos personales (derecho al olvido).'],
    'bloqueo' => ['Bloqueo', 'solicita conservar los datos sin tratarlos mientras se resuelve una controversia.'],
    'oposicion_automatizada' => ['Oposición a Decisiones Automatizadas', 'solicita no ser objeto de decisiones basadas únicamente en tratamiento automatizado.'],
];

$typeIcons = [
    'acceso' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
    'rectificacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
    'cancelacion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    'oposicion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>',
    'portabilidad' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>',
    'supresion' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
    'bloqueo' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
    'oposicion_automatizada' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
];

$statusCfg = [
    'pending' => ['Pendiente', 'bg-amber-500/10 text-amber-400 border-amber-500/20'],
    'in_progress' => ['En proceso', 'bg-blue-500/10 text-blue-400 border-blue-500/20'],
    'completed' => ['Completada', 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'],
    'rejected' => ['Rechazada', 'bg-red-500/10 text-red-400 border-red-500/20'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php $currentPage = 'host-monitor'; require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">
        <!-- Top Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <a href="/host-monitor" class="text-text-muted hover:text-text-body transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-purple-600/30 to-pink-500/20 border border-purple-500/30 flex items-center justify-center text-purple-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight">Panel de Control de Privacidad del Equipo</h1>
                    <p class="text-[11px] text-text-muted">Derechos del titular · Ley 21.719 · Chile</p>
                </div>
            </div>
            <?php if ($host): ?>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-400 border border-purple-500/20 font-mono"><?= h($host['agentId'] ?? $agentId) ?></span>
            <?php endif; ?>
        </header>

        <div class="flex-1 overflow-y-auto p-6 scrollbar-custom">
            <div class="max-w-7xl mx-auto space-y-6">

                <?php if ($error): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-red-400 text-sm"><?= h($error) ?></div>
                <?php elseif (!$host): ?>
                <div class="rounded-2xl border border-border-theme bg-bg-panel/40 p-16 text-center">
                    <p class="text-text-muted">Selecciona un equipo desde el <a href="/host-monitor" class="text-primary-400 hover:text-primary-300">Host Monitor</a>.</p>
                </div>
                <?php else: ?>

                <!-- Host Info Header -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-white"><?= h($host['hostname'] ?? $host['agentId'] ?? 'N/A') ?></h2>
                            <p class="text-[10px] text-text-subtle"><?= h($host['platform'] ?? '') ?> · <?= h($host['user'] ?? '') ?> · <?= h($host['ip'] ?? '') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[10px] px-2.5 py-1 rounded-full bg-purple-500/10 text-purple-400 border border-purple-500/20">
                            <?= $sensitiveCount ?> datos sensibles detectados
                        </span>
                        <span class="text-[10px] px-2.5 py-1 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20">
                            <?= count($arcoRequests) ?> solicitudes ARCO
                        </span>
                    </div>
                </div>

                <!-- Chile Law Badge -->
                <div class="rounded-xl border border-purple-500/20 bg-gradient-to-r from-purple-900/20 to-pink-900/10 p-4 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg bg-purple-500/15 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 1m3-1v.01M10 12l3-1m-3 1l-3 1m3-1v.01M6 15l2.975 1.214M17 15l-2.975 1.214M21 15l-2.975 1.214M12 15v4m0 0h-1.5m1.5 0h1.5"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-white">Protegido bajo la Ley N° 21.719 de Chile</h3>
                        <p class="text-[11px] text-text-muted mt-0.5">Este panel permite ejercer los derechos del titular de datos personales: acceso, rectificación, cancelación, oposición, portabilidad, supresión, bloqueo y oposición a decisiones automatizadas.</p>
                    </div>
                </div>

                <!-- ARCO Rights Grid -->
                <div>
                    <h3 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Ejercer derechos ARCO y adicionales
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <?php foreach ($types as $tipo => $info):
                            $icon = $typeIcons[$tipo] ?? $typeIcons['acceso'];
                            $title = $info[0];
                            $desc = $info[1];
                        ?>
                        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 hover:border-purple-500/30 hover:bg-bg-panel/80 transition-all group">
                            <div class="w-9 h-9 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center mb-3 group-hover:bg-purple-500/20 transition-colors">
                                <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $icon ?></svg>
                            </div>
                            <h4 class="text-[13px] font-semibold text-white mb-1"><?= h($title) ?></h4>
                            <p class="text-[11px] text-text-subtle mb-3 leading-relaxed"><?= h($desc) ?></p>
                            <form method="POST" action="/api-proxy.php?path=/api/host-privacy/arco" class="arco-form" onsubmit="return submitArco(this)">
                                <input type="hidden" name="token" value="<?= h($token) ?>">
                                <input type="hidden" name="agentId" value="<?= h($agentId) ?>">
                                <input type="hidden" name="tipo" value="<?= h($tipo) ?>">
                                <input type="hidden" name="descripcion" value="Solicitud de <?= h(strtolower($title)) ?> sobre datos personales del equipo <?= h($host['hostname'] ?? $agentId) ?> (<?= h($agentId) ?>).">
                                <button type="submit" class="w-full py-2 rounded-lg bg-white/[0.03] hover:bg-purple-500/20 border border-white/[0.05] hover:border-purple-500/30 text-text-body hover:text-purple-300 text-[11px] font-medium transition-all flex items-center justify-center gap-1.5">
                                    <span class="arco-label">Solicitar</span>
                                    <svg class="w-3.5 h-3.5 arco-loader hidden animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Compliance Actions -->
                <div>
                    <h3 class="text-sm font-semibold text-white mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        Acciones de cumplimiento
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <!-- Breach Report -->
                        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4 hover:border-red-500/30 hover:bg-bg-panel/80 transition-all">
                            <div class="w-9 h-9 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center mb-3">
                                <svg class="w-4.5 h-4.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                            </div>
                            <h4 class="text-[13px] font-semibold text-white mb-1">Notificar violación de seguridad</h4>
                            <p class="text-[11px] text-text-subtle mb-3">Reporta una brecha de datos personales en este equipo.</p>
                            <form method="POST" action="/api-proxy.php?path=/api/host-privacy/breach" class="breach-form" onsubmit="return submitBreach(this)">
                                <input type="hidden" name="token" value="<?= h($token) ?>">
                                <input type="hidden" name="agentId" value="<?= h($agentId) ?>">
                                <textarea name="descripcion" rows="2" required placeholder="Describe el incidente..." class="w-full mb-2 rounded-lg bg-white/[0.03] border border-white/[0.05] text-text-body text-[11px] p-2 focus:border-red-500/50 focus:outline-none resize-none"></textarea>
                                <input type="number" name="afectados" min="0" placeholder="N° de personas afectadas" class="w-full mb-2 rounded-lg bg-white/[0.03] border border-white/[0.05] text-text-body text-[11px] p-2 focus:border-red-500/50 focus:outline-none">
                                <button type="submit" class="w-full py-2 rounded-lg bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-300 text-[11px] font-medium transition-all">Reportar brecha</button>
                            </form>
                        </div>

                        <!-- Consents -->
                        <a href="/compliance?tab=consents" class="block rounded-xl border border-border-theme bg-bg-panel/60 p-4 hover:border-blue-500/30 hover:bg-bg-panel/80 transition-all">
                            <div class="w-9 h-9 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center mb-3">
                                <svg class="w-4.5 h-4.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </div>
                            <h4 class="text-[13px] font-semibold text-white mb-1">Gestionar consentimientos</h4>
                            <p class="text-[11px] text-text-subtle">Revisa y administra los consentimientos de tratamiento de datos.</p>
                        </a>

                        <!-- Training -->
                        <a href="/compliance?tab=trainings" class="block rounded-xl border border-border-theme bg-bg-panel/60 p-4 hover:border-emerald-500/30 hover:bg-bg-panel/80 transition-all">
                            <div class="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mb-3">
                                <svg class="w-4.5 h-4.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                            </div>
                            <h4 class="text-[13px] font-semibold text-white mb-1">Capacitación y firmas</h4>
                            <p class="text-[11px] text-text-subtle">Asigna y rastrea capacitaciones de protección de datos.</p>
                        </a>
                    </div>
                </div>

                <!-- Recent ARCO Requests -->
                <?php if (!empty($arcoRequests)): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden">
                    <div class="px-5 py-4 border-b border-border-theme">
                        <h3 class="text-sm font-semibold text-white">Solicitudes recientes de este equipo</h3>
                    </div>
                    <div class="divide-y divide-border-theme">
                        <?php foreach ($arcoRequests as $req):
                            $status = $statusCfg[$req['status'] ?? 'pending'] ?? $statusCfg['pending'];
                            $tipoLabel = $types[$req['tipo'] ?? 'acceso'][0] ?? 'Otro';
                        ?>
                        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-white/[0.01]">
                            <div>
                                <p class="text-[12px] font-medium text-white"><?= h($tipoLabel) ?></p>
                                <p class="text-[10px] text-text-subtle"><?= h(substr($req['createdAt'] ?? '', 0, 19)) ?> · <?= h($req['requestId'] ?? '') ?></p>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full border <?= h($status[1]) ?>"><?= h($status[0]) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Sensitive Events -->
                <?php if (!empty($sensitiveEvents)): ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 overflow-hidden">
                    <div class="px-5 py-4 border-b border-border-theme flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-white">Últimos datos sensiles detectados</h3>
                        <a href="/db-logs" class="text-[11px] text-primary-400 hover:text-primary-300">Ver todos</a>
                    </div>
                    <div class="divide-y divide-border-theme">
                        <?php foreach ($sensitiveEvents as $ev): ?>
                        <div class="px-5 py-3.5 hover:bg-white/[0.01]">
                            <p class="text-[12px] text-white truncate"><?= h($ev['path'] ?? 'N/A') ?></p>
                            <p class="text-[10px] text-text-subtle"><?= h($ev['eventType'] ?? 'evento') ?> · <?= h(substr($ev['timestamp'] ?? '', 0, 19)) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Privacy Policies -->
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
                    <h3 class="text-sm font-semibold text-white mb-3">Políticas de privacidad</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <a href="<?= h($config['privacyPolicyUrl'] ?? '/privacy') ?>" target="_blank" class="rounded-lg bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.05] p-3 text-[11px] text-text-body hover:text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Política de Privacidad
                        </a>
                        <a href="<?= h($config['cookiesPolicyUrl'] ?? '/privacy') ?>" target="_blank" class="rounded-lg bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.05] p-3 text-[11px] text-text-body hover:text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
                            Política de Cookies
                        </a>
                        <a href="/compliance?tab=privacy" class="rounded-lg bg-white/[0.03] hover:bg-white/[0.06] border border-white/[0.05] p-3 text-[11px] text-text-body hover:text-white transition-all flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Política de Retención
                        </a>
                    </div>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
async function submitArco(form) {
    const btn = form.querySelector('button[type="submit"]');
    const label = form.querySelector('.arco-label');
    const loader = form.querySelector('.arco-loader');
    btn.disabled = true;
    label.classList.add('hidden');
    loader.classList.remove('hidden');

    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    try {
        const res = await fetch(form.action, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.success || json.requestId) {
            label.textContent = 'Solicitado ✓';
            label.classList.remove('hidden');
            loader.classList.add('hidden');
            btn.classList.add('bg-emerald-500/20', 'border-emerald-500/30', 'text-emerald-300');
            setTimeout(() => location.reload(), 800);
        } else {
            throw new Error(json.error || 'Error');
        }
    } catch (e) {
        label.textContent = 'Error';
        label.classList.remove('hidden');
        loader.classList.add('hidden');
        btn.disabled = false;
        alert('Error al crear solicitud: ' + e.message);
    }
    return false;
}

async function submitBreach(form) {
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Reportando...';

    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    try {
        const res = await fetch(form.action, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        const json = await res.json();
        if (json.success) {
            btn.textContent = 'Reportado ✓';
            btn.classList.add('bg-emerald-500/20', 'text-emerald-300');
            setTimeout(() => location.reload(), 800);
        } else {
            throw new Error(json.error || 'Error');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = 'Reportar brecha';
        alert('Error al reportar: ' + e.message);
    }
    return false;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
