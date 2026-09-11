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
            <input type="email" id="pt-email" placeholder="tu@email.cl" class="input-premium w-full mb-3" autocomplete="email">
            <button id="pt-btn-request" onclick="requestCode()" class="btn-primary w-full">Enviar código de verificación</button>
            <p class="text-[11px] text-text-muted mt-3 text-center">Te enviaremos un código de 6 dígitos. Válido por 10 minutos.</p>
            <p id="pt-email-error" class="text-[11px] text-red-400 mt-2 text-center hidden"></p>
        </div>

        <!-- Paso 2: Ingresar código -->
        <div id="step-code" class="hidden rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa el código</h2>
            <p class="text-[12px] text-text-muted mb-3" id="pt-email-display"></p>
            <input type="text" id="pt-code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" class="input-premium w-full text-center text-2xl tracking-[0.5em] font-mono mb-3" autocomplete="one-time-code">
            <button id="pt-btn-verify" onclick="verifyCode()" class="btn-primary w-full">Verificar</button>
            <div class="flex items-center justify-between mt-3">
                <button onclick="showStep('email')" class="text-[11px] text-text-subtle hover:text-text-muted">Volver</button>
                <button id="pt-btn-resend" onclick="requestCode(true)" class="text-[11px] text-primary-400 hover:text-primary-300 disabled:opacity-40 disabled:cursor-not-allowed" disabled>Reenviar código</button>
            </div>
            <p id="pt-code-error" class="text-[11px] text-red-400 mt-2 text-center hidden"></p>
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
const PT_EMAIL_KEY = 'portal_email';
const API = '/api-proxy.php?path=';

function showStep(s) {
    ['email', 'code', 'dashboard'].forEach(x => {
        document.getElementById('step-' + x).classList.toggle('hidden', x !== s);
    });
}

function setError(elId, msg) {
    const el = document.getElementById(elId);
    if (!el) return;
    if (msg) { el.textContent = msg; el.classList.remove('hidden'); }
    else { el.textContent = ''; el.classList.add('hidden'); }
}

function setLoading(btnId, loading, textLoading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    if (loading) {
        btn.dataset.original = btn.dataset.original || btn.textContent;
        btn.disabled = true;
        btn.textContent = textLoading || 'Enviando…';
    } else {
        btn.disabled = false;
        if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
}

async function requestCode(isResend = false) {
    setError('pt-email-error', '');
    setError('pt-code-error', '');

    const emailInput = document.getElementById('pt-email');
    const email = (emailInput.value || sessionStorage.getItem(PT_EMAIL_KEY) || '').trim().toLowerCase();

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (isResend) setError('pt-code-error', 'Ingresa un email válido');
        else setError('pt-email-error', 'Ingresa un email válido');
        return;
    }

    const btnId = isResend ? 'pt-btn-resend' : 'pt-btn-request';
    setLoading(btnId, true, isResend ? 'Reenviando…' : 'Enviando…');

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/request-code'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        const data = await res.json();

        if (res.ok && data.success) {
            sessionStorage.setItem(PT_EMAIL_KEY, email);
            emailInput.value = email;

            document.getElementById('pt-email-display').textContent = 'Código enviado a: ' + email;
            showStep('code');
            startResendCooldown(60);

            // Modo dev: si el backend expone dev_code, lo mostramos para pruebas
            if (data.dev_code) {
                console.warn('[DEV] código de desarrollo:', data.dev_code);
                setError('pt-code-error', 'Modo dev — código: ' + data.dev_code);
            }
        } else {
            const msg = data.error || 'No se pudo enviar el código';
            if (isResend) setError('pt-code-error', msg);
            else setError('pt-email-error', msg);
        }
    } catch (e) {
        const msg = 'Error de conexión. Intenta nuevamente.';
        if (isResend) setError('pt-code-error', msg);
        else setError('pt-email-error', msg);
    } finally {
        setLoading(btnId, false);
    }
}

let resendTimer = null;
function startResendCooldown(seconds) {
    const btn = document.getElementById('pt-btn-resend');
    if (!btn) return;
    let remaining = seconds;
    btn.disabled = true;
    if (resendTimer) clearInterval(resendTimer);

    const tick = () => {
        if (remaining <= 0) {
            clearInterval(resendTimer);
            resendTimer = null;
            btn.disabled = false;
            btn.textContent = 'Reenviar código';
            return;
        }
        btn.textContent = `Reenviar en ${remaining}s`;
        remaining--;
    };
    tick();
    resendTimer = setInterval(tick, 1000);
}

async function verifyCode() {
    setError('pt-code-error', '');

    const email = (sessionStorage.getItem(PT_EMAIL_KEY) || document.getElementById('pt-email').value || '').trim().toLowerCase();
    const code = (document.getElementById('pt-code').value || '').trim();

    if (!email) {
        setError('pt-code-error', 'Falta el email. Vuelve al paso anterior.');
        return;
    }
    if (!/^\d{6}$/.test(code)) {
        setError('pt-code-error', 'El código debe tener 6 dígitos');
        return;
    }

    setLoading('pt-btn-verify', true, 'Verificando…');

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/verify-code'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, code })
        });
        const data = await res.json();

        if (res.ok && data.success && data.token) {
            localStorage.setItem(PT_KEY, data.token);
            sessionStorage.removeItem(PT_EMAIL_KEY);
            document.getElementById('pt-code').value = '';
            await loadDashboard();
        } else {
            setError('pt-code-error', data.error || 'Código inválido o expirado');
        }
    } catch (e) {
        setError('pt-code-error', 'Error de conexión. Intenta nuevamente.');
    } finally {
        setLoading('pt-btn-verify', false);
    }
}

function logoutPortal() {
    localStorage.removeItem(PT_KEY);
    sessionStorage.removeItem(PT_EMAIL_KEY);
    document.getElementById('pt-summary').innerHTML = '';
    document.getElementById('pt-consents').innerHTML = '';
    document.getElementById('pt-arco').innerHTML = '';
    document.getElementById('pt-user-email').textContent = '';
    showStep('email');
}

async function loadDashboard() {
    const token = localStorage.getItem(PT_KEY);
    if (!token) { showStep('email'); return; }

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/my-data'), {
            method: 'GET',
            headers: { 'Authorization': 'Bearer ' + token }
        });

        if (res.status === 401) { logoutPortal(); return; }

        const data = await res.json();
        if (data.error) { logoutPortal(); return; }

        showStep('dashboard');
        document.getElementById('pt-user-email').textContent = data.email || '';

        const s = data.summary || { activeConsents: 0, totalConsents: 0, openRequests: 0 };
        document.getElementById('pt-summary').innerHTML = `
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
                <p class="text-[10px] text-text-subtle uppercase">Consentimientos</p>
                <p class="text-2xl font-bold text-white mt-1">${s.activeConsents}/${s.totalConsents}</p>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
                <p class="text-[10px] text-text-subtle uppercase">Solicitudes ARCO</p>
                <p class="text-2xl font-bold text-white mt-1">${s.openRequests}</p>
            </div>
            <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
                <p class="text-[10px] text-text-subtle uppercase">Estado</p>
                <p class="text-2xl font-bold text-emerald-400 mt-1">Activo</p>
            </div>`;

        renderConsents(data.consents || []);
        renderArco(data.arcoRequests || []);
    } catch (e) {
        console.error('loadDashboard error', e);
    }
}

function renderConsents(consents) {
    const container = document.getElementById('pt-consents');
    if (!consents.length) { container.innerHTML = ''; return; }

    container.innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">✅ Tus consentimientos</h3>
            <div class="space-y-2">
                ${consents.map(c => `
                    <div class="flex items-center justify-between p-3 rounded-xl border border-border-theme/50">
                        <div>
                            <p class="text-[12px] text-white">${escapeHtml(c.purpose || 'Sin especificar')}</p>
                            <p class="text-[10px] text-text-subtle">
                                ${c.revokedAt
                                    ? 'Revocado el ' + escapeHtml(String(c.revokedAt).substring(0, 10))
                                    : 'Activo'}
                            </p>
                        </div>
                        ${!c.revokedAt
                            ? `<button onclick="revokeConsent('${escapeAttr(c._id)}')" class="px-3 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20">Revocar</button>`
                            : ''}
                    </div>
                `).join('')}
            </div>
        </div>`;
}

function renderArco(requests) {
    const container = document.getElementById('pt-arco');
    if (!requests.length) { container.innerHTML = ''; return; }

    container.innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">📬 Tus solicitudes ARCO</h3>
            <div class="space-y-2">
                ${requests.map(r => `
                    <div class="p-3 rounded-xl border border-border-theme/50">
                        <p class="text-[12px] text-white">${escapeHtml(r.requestId || '')} — ${escapeHtml(r.tipo || '')}</p>
                        <p class="text-[10px] text-text-subtle">Estado: ${escapeHtml(r.status || 'pendiente')}</p>
                    </div>
                `).join('')}
            </div>
        </div>`;
}

async function revokeConsent(consentId) {
    if (!consentId) return;
    if (!confirm('¿Revocar este consentimiento? Esta acción es definitiva.')) return;

    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/revoke-consent'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ consentId })
        });

        if (res.status === 401) return logoutPortal();

        const data = await res.json();
        if (data.success) {
            alert('Consentimiento revocado. Recibirás confirmación por email.');
            loadDashboard();
        } else {
            alert(data.error || 'Error al revocar');
        }
    } catch (e) {
        alert('Error de conexión. Intenta nuevamente.');
    }
}

function downloadMyData() {
    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();
    window.open(API + encodeURIComponent('/api/public/portal/download') + '&token=' + encodeURIComponent(token), '_blank');
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) {
    return String(s).replace(/[^a-zA-Z0-9_\-]/g, '');
}

(function init() {
    const token = localStorage.getItem(PT_KEY);
    if (token) {
        loadDashboard();
    } else {
        const savedEmail = sessionStorage.getItem(PT_EMAIL_KEY);
        if (savedEmail) document.getElementById('pt-email').value = savedEmail;
        showStep('email');
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>