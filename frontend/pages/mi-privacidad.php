<?php
$pageTitle = 'Mi Privacidad';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="min-h-screen bg-bg-base text-[13px] text-text-body p-4">
    <div class="max-w-3xl mx-auto pt-12 pb-16">
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Mi Portal de Privacidad</h1>
            <p class="text-text-muted">Consulta, gestiona y descarga tus datos personales — Ley 21.719</p>
        </div>

        <!-- Paso 1: Pedir código -->
        <div id="step-email" class="rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa tu email</h2>
            <input type="email" id="pt-email" placeholder="tu@email.cl" class="input-premium w-full mb-3">
            <button onclick="requestCode()" class="btn-primary w-full">Enviar código de verificación</button>
            <p class="text-[11px] text-text-muted mt-3 text-center">Te enviaremos un código de 6 dígitos. Válido por 10 minutos.</p>
        </div>

        <!-- Paso 2: Ingresar código -->
        <div id="step-code" class="hidden rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa el código</h2>
            <p class="text-[12px] text-text-muted mb-3" id="pt-email-display"></p>
            <input type="text" id="pt-code" maxlength="6" placeholder="000000" class="input-premium w-full text-center text-2xl tracking-[0.5em] font-mono mb-3">
            <button onclick="verifyCode()" class="btn-primary w-full">Verificar</button>
            <button onclick="showStep('email')" class="text-[11px] text-text-subtle mt-3 w-full">Volver</button>
        </div>

        <!-- Paso 3: Dashboard -->
        <div id="step-dashboard" class="hidden space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-[12px] text-text-muted">Hola, <span id="pt-user-email" class="text-white font-medium"></span></p>
                <button onclick="logoutPortal()" class="text-[11px] text-text-muted hover:text-red-400">Cerrar sesión</button>
            </div>
            <div id="pt-summary" class="grid grid-cols-3 gap-3"></div>
            <div id="pt-consents"></div>
            <div id="pt-arco"></div>
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">📥 Descargar mis datos</h3>
                <p class="text-[11px] text-text-muted mb-3">Descarga todos tus datos personales en formato estructurado (portabilidad - Art. 13).</p>
                <button onclick="downloadMyData()" class="btn-primary text-[12px]">Descargar JSON</button>
            </div>
        </div>

        <div class="text-center mt-6 text-[11px] text-text-subtle">
            <a href="/arco-solicitud" class="text-primary-400 hover:text-primary-300">Crear nueva solicitud ARCO</a>
        </div>
    </div>
</div>

<script>
const PT_KEY = 'portal_token';
const API = '/api-proxy.php?path=';

function showStep(s) {
    ['email', 'code', 'dashboard'].forEach(x => document.getElementById('step-' + x).classList.toggle('hidden', x !== s));
}

async function requestCode() {
    const email = document.getElementById('pt-email').value.trim();
    if (!email) return alert('Ingresa tu email');
    const res = await fetch(API + encodeURIComponent('/api/public/portal/request-code'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email })
    });
    const data = await res.json();
    if (data.success) {
        document.getElementById('pt-email-display').textContent = 'Código enviado a: ' + email;
        showStep('code');
        sessionStorage.setItem('portal_email', email);
    } else {
        alert(data.error || 'Error');
    }
}

async function verifyCode() {
    const email = sessionStorage.getItem('portal_email') || document.getElementById('pt-email').value.trim();
    const code = document.getElementById('pt-code').value.trim();
    const res = await fetch(API + encodeURIComponent('/api/public/portal/verify-code'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email, code })
    });
    const data = await res.json();
    if (data.success && data.token) {
        localStorage.setItem(PT_KEY, data.token);
        loadDashboard();
    } else {
        alert(data.error || 'Código inválido');
    }
}

function logoutPortal() {
    localStorage.removeItem(PT_KEY);
    showStep('email');
}

async function loadDashboard() {
    const token = localStorage.getItem(PT_KEY);
    if (!token) return showStep('email');
    const res = await fetch(API + encodeURIComponent('/api/public/portal/my-data'), {
        headers: {'Authorization': 'Bearer ' + token}
    });
    const data = await res.json();
    if (data.error) {
        logoutPortal();
        return;
    }
    showStep('dashboard');
    document.getElementById('pt-user-email').textContent = data.email;

    // Resumen
    document.getElementById('pt-summary').innerHTML = `
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Consentimientos</p>
            <p class="text-2xl font-bold text-white mt-1">${data.summary.activeConsents}/${data.summary.totalConsents}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Solicitudes ARCO</p>
            <p class="text-2xl font-bold text-white mt-1">${data.summary.openRequests}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Estado</p>
            <p class="text-2xl font-bold text-emerald-400 mt-1">Activo</p>
        </div>`;

    // Consentimientos
    if (data.consents.length > 0) {
        document.getElementById('pt-consents').innerHTML = `
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">✅ Tus consentimientos</h3>
                <div class="space-y-2">
                    ${data.consents.map(c => `
                        <div class="flex items-center justify-between p-3 rounded-xl border border-border-theme/50">
                            <div>
                                <p class="text-[12px] text-white">${escapeHtml(c.purpose || 'Sin especificar')}</p>
                                <p class="text-[10px] text-text-subtle">${c.revokedAt ? 'Revocado el ' + c.revokedAt.substring(0,10) : 'Activo'}</p>
                            </div>
                            ${!c.revokedAt ? `<button onclick="revokeConsent('${c._id}')" class="px-3 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20">Revocar</button>` : ''}
                        </div>
                    `).join('')}
                </div>
            </div>`;
    }

    // ARCO
    if (data.arcoRequests.length > 0) {
        document.getElementById('pt-arco').innerHTML = `
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">📬 Tus solicitudes ARCO</h3>
                <div class="space-y-2">
                    ${data.arcoRequests.map(r => `
                        <div class="p-3 rounded-xl border border-border-theme/50">
                            <p class="text-[12px] text-white">${escapeHtml(r.requestId || '')} — ${escapeHtml(r.tipo || '')}</p>
                            <p class="text-[10px] text-text-subtle">Estado: ${escapeHtml(r.status || 'pendiente')}</p>
                        </div>
                    `).join('')}
                </div>
            </div>`;
    }
}

async function revokeConsent(consentId) {
    if (!confirm('¿Revocar este consentimiento? Esta acción es definitiva.')) return;
    const token = localStorage.getItem(PT_KEY);
    const res = await fetch(API + encodeURIComponent('/api/public/portal/revoke-consent'), {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token},
        body: JSON.stringify({ consentId })
    });
    const data = await res.json();
    if (data.success) {
        alert('Consentimiento revocado. Recibirás confirmación por email.');
        loadDashboard();
    } else {
        alert(data.error || 'Error');
    }
}

function downloadMyData() {
    const token = localStorage.getItem(PT_KEY);
    window.open(API + encodeURIComponent('/api/public/portal/download') + '&token=' + encodeURIComponent(token), '_blank');
}

function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

// Auto-cargar si hay token
if (localStorage.getItem(PT_KEY)) loadDashboard();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>