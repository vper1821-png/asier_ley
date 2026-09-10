<?php
$certId = $_GET['certId'] ?? '';
$data = null;
if ($certId) {
    $res = api_request('GET', '/api/certification/verify/' . urlencode($certId));
    if (empty($res['body']['error'])) $data = $res['body'];
}
$pageTitle = 'Verificación de Certificado';
require_once __DIR__ . '/../includes/header.php';

$status = $data['status'] ?? 'unknown';
$isExpired = !empty($data['isExpired']);
$isRevoked = $status === 'revoked';

if ($isRevoked) {
    $badgeClass = 'bg-red-500/15 text-red-400 border-red-500/30';
    $badgeText  = 'CERTIFICADO REVOCADO';
    $badgeIcon  = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
} elseif ($isExpired) {
    $badgeClass = 'bg-amber-500/15 text-amber-400 border-amber-500/30';
    $badgeText  = 'CERTIFICADO EXPIRADO';
    $badgeIcon  = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
} elseif ($status === 'issued') {
    $badgeClass = 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
    $badgeText  = 'CERTIFICADO VÁLIDO';
    $badgeIcon  = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>';
} else {
    $badgeClass = 'bg-white/5 text-text-subtle border-white/10';
    $badgeText  = 'ESTADO DESCONOCIDO';
    $badgeIcon  = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
}
?>
<div class="min-h-screen bg-bg-base flex items-center justify-center p-6">
    <div class="max-w-2xl w-full bg-bg-panel border border-border-theme rounded-2xl p-8 shadow-2xl">
        <div class="text-center mb-6">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Verificación de Certificado</h1>
            <p class="text-[11px] text-text-muted">Ley 21.719 — Protección de Datos Personales · Chile</p>
        </div>

        <?php if (!$data): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/5 p-6 text-center space-y-2">
                <div class="w-12 h-12 rounded-full bg-red-500/15 border border-red-500/30 flex items-center justify-center mx-auto text-red-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </div>
                <p class="text-red-400 font-semibold text-sm">Certificado no encontrado o inválido</p>
                <p class="text-[11px] text-red-300/70">Verifica que el ID de certificado sea correcto.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <div class="flex items-center justify-center gap-2">
                    <span class="inline-flex items-center gap-1.5 text-[11px] px-3 py-1.5 rounded-full border font-mono font-bold <?= $badgeClass ?>">
                        <?= $badgeIcon ?>
                        <?= h($badgeText) ?>
                    </span>
                </div>

                <?php if ($isRevoked && !empty($data['revokedReason'])): ?>
                <div class="p-3 rounded-lg bg-red-500/10 border border-red-500/20">
                    <p class="text-[11px] text-red-300"><b>Motivo de revocación:</b> <?= h($data['revokedReason']) ?></p>
                    <?php if (!empty($data['revokedAt'])): ?>
                    <p class="text-[10px] text-red-300/70 mt-1">Revocado el <?= h(date('d/m/Y H:i', strtotime($data['revokedAt']))) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">ID Certificado</p>
                        <p class="text-[12px] text-white font-mono break-all"><?= h($data['certId']) ?></p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">Score</p>
                        <p class="text-[12px] <?= ((int)$data['score']) >= 90 ? 'text-emerald-400' : 'text-amber-400' ?> font-bold">
                            <?= (int)$data['score'] ?>%
                        </p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">Empresa</p>
                        <p class="text-[12px] text-white"><?= h($data['company']['name']) ?></p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">RUT</p>
                        <p class="text-[12px] text-white font-mono"><?= h($data['company']['rut']) ?></p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">DPD</p>
                        <p class="text-[12px] text-white"><?= h($data['dpd']['name'] ?? '—') ?></p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">Emitido</p>
                        <p class="text-[12px] text-white">
                            <?= !empty($data['issuedAt']) ? h(date('d/m/Y', strtotime($data['issuedAt']))) : '—' ?>
                        </p>
                    </div>
                    <div class="rounded-lg border border-border-theme bg-bg-elevated/40 p-3 sm:col-span-2">
                        <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">Vigencia hasta</p>
                        <p class="text-[12px] <?= $isExpired ? 'text-red-400' : 'text-white' ?>">
                            <?= !empty($data['expiresAt']) ? h(date('d/m/Y', strtotime($data['expiresAt']))) : '—' ?>
                            <?= $isExpired ? ' (expirado)' : '' ?>
                        </p>
                    </div>
                </div>

                <div class="mt-4 p-3 rounded-lg bg-black/20 border border-white/[0.05]">
                    <p class="text-[9px] text-text-subtle uppercase tracking-widest mb-1">Hash de integridad SHA-256</p>
                    <p class="text-[10px] text-text-muted font-mono break-all"><?= h($data['masterHash']) ?></p>
                </div>

                <p class="text-[10px] text-text-subtle text-center pt-2">
                    Documento emitido conforme al procedimiento de certificación de la Ley 21.719.
                </p>
            </div>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <a href="/" class="text-[11px] text-text-subtle hover:text-text-muted transition-colors">← Volver al inicio</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>