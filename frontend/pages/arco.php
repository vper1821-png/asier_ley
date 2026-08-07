<?php
$pageTitle = 'Derechos ARCO';
$currentPage = 'arco';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {
    $res = api_post_form('/api/arco/requests/update', [
        'token' => $token,
        'requestId' => $_POST['request_id'] ?? '',
        'status' => $_POST['status'] ?? '',
        'response' => $_POST['response'] ?? '',
    ]);
    if (!empty($res['success'])) $msg = 'Solicitud actualizada.';
    else $err = $res['error'] ?? 'Error al actualizar.';
}

$reqRes = api_post_form('/api/arco/requests/list', ['token' => $token]);
$requests = is_array($reqRes) && empty($reqRes['error']) ? ($reqRes['requests'] ?? $reqRes) : [];
if (!is_array($requests)) $requests = [];

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
$pending = count(array_filter($requests, fn($r) => ($r['status'] ?? 'pending') === 'pending'));
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Derechos ARCO</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($requests) ?> solicitudes · <?= $pending ?> pendientes</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="/arco-solicitud" target="_blank" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Formulario público</a>
                <button onclick="location.reload()" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-white/[0.03] hover:bg-white/[0.06] text-text-muted border border-white/[0.05] transition-all">Refrescar</button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- Info banner -->
            <div class="px-4 py-3 rounded-lg bg-blue-500/[0.06] border border-blue-500/20">
                <p class="text-[11px] text-text-body leading-relaxed">
                    <span class="font-semibold text-blue-300">Ley 21.719:</span> Debes responder las solicitudes ARCO dentro de un plazo máximo de <span class="font-semibold">30 días corridos</span>. Las solicitudes no atendidas pueden derivar en sanciones de la ANPD.
                </p>
            </div>

            <?php if (empty($requests)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center tour-detail-1">
                <h3 class="text-white font-semibold mb-2">Sin solicitudes ARCO</h3>
                <p class="text-text-muted text-[12px]">Comparte el formulario público para que los titulares puedan ejercer sus derechos.</p>
            </div>
            <?php else: ?>
            <div class="space-y-2 tour-detail-1">
                <?php foreach ($requests as $r):
                    $st = $statusCfg[$r['status'] ?? 'pending'] ?? $statusCfg['pending'];
                    $rid = $r['requestId'] ?? $r['_id'] ?? '';
                ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <div class="flex flex-col md:flex-row md:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-[12px] font-medium text-text-heading truncate">
                                <?= h($typeCfg[$r['type'] ?? ''] ?? ucfirst($r['type'] ?? 'Solicitud')) ?> — <?= h($r['name'] ?? $r['requesterName'] ?? 'Titular') ?>
                            </p>
                            <p class="text-[10px] text-text-subtle truncate">
                                <?= h($r['email'] ?? $r['requesterEmail'] ?? '') ?> · Tracking: <?= h($r['trackingId'] ?? $rid) ?> · <?= h(substr($r['createdAt'] ?? '', 0, 16)) ?>
                            </p>
                            <?php if (!empty($r['description']) || !empty($r['details'])): ?>
                            <p class="text-[11px] text-text-muted mt-1.5"><?= h($r['description'] ?? $r['details']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $st['class'] ?>"><?= h($st['label']) ?></span>
                            <button onclick="document.getElementById('arco-resp-<?= h($rid) ?>').classList.toggle('hidden')"
                                class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Responder</button>
                        </div>
                    </div>
                    <div id="arco-resp-<?= h($rid) ?>" class="hidden mt-3 pt-3 border-t border-white/[0.04]">
                        <form method="POST" class="flex flex-col md:flex-row gap-2">
                            <input type="hidden" name="request_id" value="<?= h($rid) ?>">
                            <select name="status" class="input-premium md:w-40">
                                <?php foreach ($statusCfg as $val => $cfg): ?>
                                <option value="<?= $val ?>" <?= ($r['status'] ?? 'pending') === $val ? 'selected' : '' ?>><?= h($cfg['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="response" placeholder="Respuesta al titular..." class="input-premium flex-1" value="<?= h($r['response'] ?? '') ?>">
                            <button type="submit" name="update_request" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 text-white">Guardar</button>
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
