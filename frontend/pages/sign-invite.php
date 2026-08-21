<?php
$pageTitle = 'Firmar documento';
require_once __DIR__ . '/../includes/header.php';

$inviteToken = $_GET['token'] ?? '';
$error = '';
$success = false;
$document = null;

if ($inviteToken) {
    $res = api_request('POST', '/api/compliance/verify-invite', ['token' => $inviteToken]);
    if (!empty($res['body']['error'])) {
        $error = $res['body']['error'];
    } else {
        $document = $res['body'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inviteToken) {
    $res = api_request('POST', '/api/compliance/sign', [
        'inviteToken' => $inviteToken,
        'signature' => $_POST['signature'] ?? '',
        'name' => $_POST['name'] ?? '',
    ]);
    if (!empty($res['body']['success'])) {
        $success = true;
    } else {
        $error = $res['body']['error'] ?? 'Error al firmar.';
    }
}
?>

<div class="min-h-screen bg-bg-base text-[13px] text-text-body">
    <nav class="fixed top-0 left-0 right-0 z-50 bg-bg-base/80 backdrop-blur-xl border-b border-border-theme">
        <div class="max-w-7xl mx-auto px-6 h-14 flex items-center justify-between">
            <a href="/" class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-lg overflow-hidden bg-bg-panel flex items-center justify-center">
                    <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
                </div>
                <span class="text-[15px] font-bold text-white tracking-tight">SecureLab</span>
            </a>
            <a href="/" class="text-[12px] font-medium text-text-muted hover:text-text-heading transition-colors flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Volver
            </a>
        </div>
    </nav>

    <div class="min-h-screen flex items-center justify-center px-4 pt-20 pb-12">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">Firma de documento</h1>
            <p class="text-text-muted">Has sido invitado a firmar un documento de compliance</p>
        </div>

        <?php if ($success): ?>
        <div class="glass-card p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Documento firmado</h2>
            <p class="text-text-muted">El documento ha sido firmado correctamente.</p>
        </div>
        <?php elseif ($error && !$document): ?>
        <div class="glass-card p-8 text-center">
            <div class="w-14 h-14 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </div>
            <h2 class="text-xl font-bold text-white mb-2">Error</h2>
            <p class="text-text-muted"><?= h($error) ?></p>
        </div>
        <?php elseif ($document): ?>
        <div class="glass-card p-6">
            <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php endif; ?>
            <div class="mb-6 p-4 rounded-lg border border-border-theme bg-bg-elevated/50">
                <h3 class="text-white font-semibold mb-2"><?= h($document['title'] ?? 'Documento') ?></h3>
                <p class="text-sm text-text-muted"><?= h($document['description'] ?? 'Sin descripción') ?></p>
                <?php if (!empty($document['companyName'])): ?>
                <p class="text-[11px] text-text-subtle mt-2"><?= h($document['companyName']) ?></p>
                <?php endif; ?>
            </div>

            <form method="POST" class="space-y-4" onsubmit="return prepareSignature()">
                <div>
                    <label class="label-premium">Tu nombre completo</label>
                    <input type="text" name="name" required class="input-premium" placeholder="Nombre y apellido">
                </div>

                <div>
                    <label class="label-premium">Firma manuscrita</label>
                    <button type="button" onclick="openSignPad()"
                        class="w-full py-9 rounded-xl border-2 border-dashed border-border-theme bg-bg-elevated/40 hover:border-primary-500/40 transition-all flex flex-col items-center justify-center gap-2">
                        <svg class="w-6 h-6 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        <span class="text-[12px] text-text-muted">Haz clic para firmar con tu firma manuscrita</span>
                    </button>

                    <div id="sig-preview" class="hidden mt-3 rounded-xl border border-border-theme bg-white p-3">
                        <p class="text-[9px] text-slate-400 uppercase tracking-widest mb-1">Tu firma</p>
                        <img id="sig-preview-img" class="w-full h-24 object-contain" alt="Tu firma">
                    </div>
                    <input type="hidden" name="signature" id="sig-data">
                </div>

                <button type="submit" class="btn-glow w-full py-3 text-sm">Firmar documento</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ POPUP FIRMADOR ═══ -->
<div id="sign-pad-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm flex items-center justify-center z-[70] p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl w-full max-w-xl shadow-2xl">
        <div class="flex items-center justify-between px-6 py-4 border-b border-border-theme">
            <div>
                <h3 class="text-[14px] font-semibold text-white">Firma manuscrita</h3>
                <p class="text-[11px] text-text-muted mt-0.5">Dibuja tu firma con el mouse o el dedo</p>
            </div>
            <button onclick="closeSignPad()" class="text-text-muted hover:text-white transition-colors p-1.5 rounded-lg hover:bg-bg-elevated">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6">
            <div class="relative rounded-xl border-2 border-dashed border-border-theme bg-white overflow-hidden" style="height:220px">
                <canvas id="sig-canvas" class="w-full h-full block" style="touch-action:none;cursor:crosshair"></canvas>
                <span id="sig-placeholder" class="absolute inset-0 flex items-center justify-center text-slate-400 text-sm pointer-events-none select-none">Firma aquí</span>
            </div>
            <p id="sig-pad-status" class="text-[11px] text-text-subtle mt-2"></p>
            <div class="flex items-center justify-between gap-2 mt-3">
                <button type="button" onclick="clearSignature()" class="px-4 py-2 text-[11px] font-medium rounded-lg bg-bg-elevated border border-border-theme text-text-muted hover:text-text-body transition-all">Limpiar</button>
                <button type="button" onclick="confirmSignature()" class="px-5 py-2 text-[12px] font-semibold rounded-lg bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all">Aceptar firma</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const canvas = document.getElementById('sig-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let drawing = false;

    function setup() {
        const rect = canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        canvas.width = rect.width * dpr;
        canvas.height = rect.height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, rect.width, rect.height);
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#000000';
        const saved = document.getElementById('sig-data').value;
        if (saved) {
            const img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
            img.src = saved;
        }
    }
    setup();
    window.addEventListener('resize', setup);

    function pos(e) {
        const rect = canvas.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    }
    function start(e) {
        e.preventDefault();
        drawing = true;
        const p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        document.getElementById('sig-placeholder').classList.add('hidden');
        document.getElementById('sig-pad-status').textContent = '';
    }
    function move(e) {
        if (!drawing) return;
        e.preventDefault();
        const p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
    }
    function end() { drawing = false; }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);

    window.openSignPad = function () {
        const m = document.getElementById('sign-pad-modal');
        m.classList.remove('hidden');
        requestAnimationFrame(function () {
            setup();
        });
    };
    window.closeSignPad = function () {
        document.getElementById('sign-pad-modal').classList.add('hidden');
    };
    window.clearSignature = function () {
        const rect = canvas.getBoundingClientRect();
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, rect.width, rect.height);
        document.getElementById('sig-data').value = '';
        document.getElementById('sig-placeholder').classList.remove('hidden');
        document.getElementById('sig-pad-status').textContent = '';
    };
    window.confirmSignature = function () {
        const rect = canvas.getBoundingClientRect();
        const id = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const d = id.data;
        let hasInk = false;
        for (let i = 0; i < d.length; i += 4) {
            if (d[i] < 235 || d[i + 1] < 235 || d[i + 2] < 235) { hasInk = true; break; }
        }
        const status = document.getElementById('sig-pad-status');
        if (!hasInk) {
            status.textContent = 'La firma está vacía. Dibuja tu firma primero.';
            status.className = 'text-[11px] mt-2 text-red-400';
            return;
        }
        const dataUrl = canvas.toDataURL('image/png');
        document.getElementById('sig-data').value = dataUrl;
        document.getElementById('sig-preview-img').src = dataUrl;
        document.getElementById('sig-preview').classList.remove('hidden');
        document.getElementById('sig-pad-status').textContent = 'Firma aceptada.';
        status.className = 'text-[11px] mt-2 text-emerald-400';
        setTimeout(function () { document.getElementById('sign-pad-modal').classList.add('hidden'); }, 350);
    };
    window.prepareSignature = function () {
        const data = document.getElementById('sig-data').value;
        if (!data) {
            document.getElementById('sig-pad-status').textContent = 'Debes firmar el documento.';
            return false;
        }
        return true;
    };

    document.getElementById('sign-pad-modal').addEventListener('click', function (e) {
        if (e.target.id === 'sign-pad-modal') document.getElementById('sign-pad-modal').classList.add('hidden');
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
