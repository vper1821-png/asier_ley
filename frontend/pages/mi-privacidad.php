<?php
$pageTitle = 'Mi Privacidad';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="min-h-screen bg-bg-base text-[13px] text-text-body p-4">
    <div class="max-w-4xl mx-auto pt-12 pb-16">
        <div class="text-center mb-8">
            <div class="w-16 h-16 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Mi Portal de Privacidad</h1>
            <p class="text-text-muted">Consulta, gestiona y descarga tus datos personales — Ley 21.719</p>
        </div>

        <!-- Paso 1: Email -->
        <div id="step-email" class="rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa tu email</h2>
            <input type="email" id="pt-email" placeholder="tu@email.cl" class="input-premium w-full mb-3" autocomplete="email">
            <button id="pt-btn-request" onclick="requestCode()" class="btn-primary w-full">Enviar código de verificación</button>
            <p class="text-[11px] text-text-muted mt-3 text-center">Te enviaremos un código de 6 dígitos. Válido por 10 minutos.</p>
            <p id="pt-email-error" class="text-[11px] text-red-400 mt-2 text-center hidden"></p>
        </div>

        <!-- Paso 2: Código -->
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

            <!-- Resumen -->
            <div id="pt-summary" class="grid grid-cols-2 md:grid-cols-4 gap-3"></div>

            <!-- Datos personales -->
            <div id="pt-subject"></div>

            <!-- Mapa de tratamiento (RAT) -->
            <div id="pt-treatment"></div>

            <!-- Retención por categoría -->
            <div id="pt-retention"></div>

            <!-- Encargados y transferencias -->
            <div id="pt-third-parties"></div>

            <!-- Consentimientos -->
            <div id="pt-consents"></div>

            <!-- Solicitudes ARCO -->
            <div id="pt-arco"></div>

            <!-- Derechos -->
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">⚖️ Ejercer mis derechos</h3>
                <p class="text-[11px] text-text-muted mb-3">Solicita acceso, rectificación, supresión, oposición, portabilidad o bloqueo de tus datos.</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <a href="/arco-solicitud?tipo=acceso"        class="btn-secondary text-[11px] text-center">Acceso</a>
                    <a href="/arco-solicitud?tipo=rectificacion" class="btn-secondary text-[11px] text-center">Rectificación</a>
                    <a href="/arco-solicitud?tipo=supresion"     class="btn-secondary text-[11px] text-center">Supresión</a>
                    <a href="/arco-solicitud?tipo=oposicion"     class="btn-secondary text-[11px] text-center">Oposición</a>
                    <a href="/arco-solicitud?tipo=portabilidad"  class="btn-secondary text-[11px] text-center">Portabilidad</a>
                    <a href="/arco-solicitud?tipo=bloqueo"       class="btn-secondary text-[11px] text-center">Bloqueo</a>
                </div>
            </div>

            <!-- Descargar -->
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">📥 Descargar mis datos</h3>
                <p class="text-[11px] text-text-muted mb-3">Descarga todos tus datos personales en formato estructurado (Art. 13 — Portabilidad).</p>
                <div class="flex flex-wrap gap-2">
                    <button onclick="downloadMyData('json')" class="btn-primary text-[12px]">JSON</button>
                    <button onclick="downloadMyData('csv')"  class="btn-secondary text-[12px]">CSV</button>
                    <button onclick="downloadMyData('xml')"  class="btn-secondary text-[12px]">XML</button>
                </div>
            </div>

            <!-- DPD -->
            <div id="pt-dpd"></div>
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

// ── UI helpers ──
function showStep(s) {
    ['email', 'code', 'dashboard'].forEach(x => {
        document.getElementById('step-' + x).classList.toggle('hidden', x !== s);
    });
}

function setError(id, msg) {
    const el = document.getElementById(id);
    if (!el) return;
    if (msg) { el.textContent = msg; el.classList.remove('hidden'); }
    else { el.textContent = ''; el.classList.add('hidden'); }
}

function setLoading(id, loading, text) {
    const btn = document.getElementById(id);
    if (!btn) return;
    if (loading) {
        btn.dataset.original = btn.dataset.original || btn.textContent;
        btn.disabled = true;
        btn.textContent = text || 'Enviando…';
    } else {
        btn.disabled = false;
        if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
}

// ── Paso 1 ──
async function requestCode(isResend = false) {
    setError('pt-email-error', '');
    setError('pt-code-error', '');

    const emailInput = document.getElementById('pt-email');
    const email = (emailInput.value || sessionStorage.getItem(PT_EMAIL_KEY) || '').trim().toLowerCase();

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        setError(isResend ? 'pt-code-error' : 'pt-email-error', 'Ingresa un email válido');
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
            if (data.dev_code) {
                setError('pt-code-error', 'Modo dev — código: ' + data.dev_code);
            }
        } else {
            const msg = data.error || 'No se pudo enviar el código';
            setError(isResend ? 'pt-code-error' : 'pt-email-error', msg);
        }
    } catch (e) {
        setError(isResend ? 'pt-code-error' : 'pt-email-error', 'Error de conexión.');
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
            clearInterval(resendTimer); resendTimer = null;
            btn.disabled = false; btn.textContent = 'Reenviar código';
            return;
        }
        btn.textContent = `Reenviar en ${remaining}s`;
        remaining--;
    };
    tick();
    resendTimer = setInterval(tick, 1000);
}

// ── Paso 2 ──
async function verifyCode() {
    setError('pt-code-error', '');
    const email = (sessionStorage.getItem(PT_EMAIL_KEY) || document.getElementById('pt-email').value || '').trim().toLowerCase();
    const code = (document.getElementById('pt-code').value || '').trim();

    if (!email) return setError('pt-code-error', 'Falta el email.');
    if (!/^\d{6}$/.test(code)) return setError('pt-code-error', 'El código debe tener 6 dígitos');

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
        setError('pt-code-error', 'Error de conexión.');
    } finally {
        setLoading('pt-btn-verify', false);
    }
}

function logoutPortal() {
    localStorage.removeItem(PT_KEY);
    sessionStorage.removeItem(PT_EMAIL_KEY);
    ['pt-summary','pt-subject','pt-treatment','pt-retention','pt-third-parties',
     'pt-consents','pt-arco','pt-dpd'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = '';
    });
    document.getElementById('pt-user-email').textContent = '';
    showStep('email');
}

// ── Dashboard ──
async function loadDashboard() {
    const token = localStorage.getItem(PT_KEY);
    if (!token) return showStep('email');

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/my-data'), {
            method: 'GET',
            headers: { 'Authorization': 'Bearer ' + token }
        });

        if (res.status === 401) return logoutPortal();
        const data = await res.json();
        if (data.error) return logoutPortal();

        showStep('dashboard');
        document.getElementById('pt-user-email').textContent = data.email || '';

        renderSummary(data);
        renderSubject(data);
        renderTreatment(data);
        renderRetention(data);
        renderThirdParties(data);
        renderConsents(data);
        renderArco(data);
        renderDpd(data);
    } catch (e) {
        console.error('loadDashboard error', e);
    }
}

// ── Bloques ──
function renderSummary(data) {
    const s = data.summary || {};
    document.getElementById('pt-summary').innerHTML = `
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Consentimientos</p>
            <p class="text-2xl font-bold text-white mt-1">${s.activeConsents ?? 0}/${s.totalConsents ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Solicitudes ARCO</p>
            <p class="text-2xl font-bold text-white mt-1">${s.openRequests ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Tratamientos</p>
            <p class="text-2xl font-bold text-white mt-1">${s.totalTreatments ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Categorías</p>
            <p class="text-2xl font-bold text-white mt-1">${s.dataCategoriesCount ?? 0}</p>
        </div>`;
}

function renderSubject(data) {
    const subj = data.subject || {};
    const company = data.company || {};
    document.getElementById('pt-subject').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">👤 Tu información registrada</h3>
            <div class="grid grid-cols-2 gap-3 text-[12px]">
                <div>
                    <p class="text-[10px] text-text-subtle uppercase">Email</p>
                    <p class="text-white mt-1">${escapeHtml(subj.email || '')}</p>
                </div>
                <div>
                    <p class="text-[10px] text-text-subtle uppercase">Nombre</p>
                    <p class="text-white mt-1">${escapeHtml(subj.name || '—')}</p>
                </div>
                <div>
                    <p class="text-[10px] text-text-subtle uppercase">RUT</p>
                    <p class="text-white mt-1">${escapeHtml(subj.rut || '—')}</p>
                </div>
                <div>
                    <p class="text-[10px] text-text-subtle uppercase">Responsable</p>
                    <p class="text-white mt-1">${escapeHtml(company.name || '—')}</p>
                </div>
            </div>
        </div>`;
}

function renderTreatment(data) {
    const map = data.treatmentMap || [];
    const cats = data.dataCategories || [];

    if (!map.length) {
        document.getElementById('pt-treatment').innerHTML = '';
        return;
    }

    const catsHtml = cats.length
        ? `<div class="flex flex-wrap gap-1.5 mt-2">
             ${cats.map(c => `<span class="px-2 py-0.5 rounded-md bg-primary-500/10 text-primary-300 text-[10px] border border-primary-500/20">${escapeHtml(c)}</span>`).join('')}
           </div>`
        : '';

    const mapHtml = map.map(t => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white font-medium">${escapeHtml(t.name || 'Sin nombre')}</p>
            ${t.purpose ? `<p class="text-[11px] text-text-muted mt-1"><b class="text-text-subtle">Finalidad:</b> ${escapeHtml(t.purpose)}</p>` : ''}
            ${t.legalBasis ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Base legal:</b> ${escapeHtml(t.legalBasis)}</p>` : ''}
            ${t.dataCategories?.length ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Datos:</b> ${t.dataCategories.map(escapeHtml).join(', ')}</p>` : ''}
            ${t.retentionDays ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Retención:</b> ${t.retentionDays} días</p>` : ''}
            ${t.sensitive ? `<span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] bg-red-500/10 text-red-400 border border-red-500/20">Datos sensibles</span>` : ''}
            ${t.childrenData ? `<span class="inline-block mt-1 ml-1 px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">Datos de menores</span>` : ''}
        </div>
    `).join('');

    document.getElementById('pt-treatment').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-1">📋 Cómo tratamos tus datos</h3>
            <p class="text-[11px] text-text-muted mb-3">Categorías y finalidades declaradas por el responsable.</p>
            ${catsHtml}
            <div class="space-y-2 mt-3">${mapHtml}</div>
        </div>`;
}

function renderRetention(data) {
    const ret = data.retention || {};
    const entries = Object.entries(ret);
    if (!entries.length) {
        document.getElementById('pt-retention').innerHTML = '';
        return;
    }
    const rows = entries.map(([cat, days]) => `
        <div class="flex items-center justify-between p-2 rounded-lg border border-border-theme/40">
            <span class="text-[11px] text-white">${escapeHtml(cat)}</span>
            <span class="text-[11px] text-text-muted">${days} días</span>
        </div>
    `).join('');

    document.getElementById('pt-retention').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">⏳ Cuánto tiempo conservamos</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">${rows}</div>
        </div>`;
}

function renderThirdParties(data) {
    const proc = data.processors || [];
    const trans = data.transfers || [];
    if (!proc.length && !trans.length) {
        document.getElementById('pt-third-parties').innerHTML = '';
        return;
    }

    const procHtml = proc.map(p => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white">${escapeHtml(p.name)}</p>
            <p class="text-[10px] text-text-subtle">${escapeHtml(p.serviceType || '')} ${p.country ? '· ' + escapeHtml(p.country) : ''}</p>
        </div>
    `).join('');

    const transHtml = trans.map(t => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white">${escapeHtml(t.recipient || '')}</p>
            <p class="text-[10px] text-text-subtle">${escapeHtml(t.country || '')} ${t.mechanism ? '· ' + escapeHtml(t.mechanism) : ''}</p>
        </div>
    `).join('');

    document.getElementById('pt-third-parties').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">🤝 Con quién compartimos</h3>
            ${proc.length ? `<p class="text-[10px] uppercase text-text-subtle mb-2">Encargados</p><div class="space-y-2 mb-3">${procHtml}</div>` : ''}
            ${trans.length ? `<p class="text-[10px] uppercase text-text-subtle mb-2">Transferencias internacionales</p><div class="space-y-2">${transHtml}</div>` : ''}
        </div>`;
}

function renderConsents(data) {
    const consents = data.consents || [];
    if (!consents.length) {
        document.getElementById('pt-consents').innerHTML = '';
        return;
    }

    const html = consents.map(c => `
        <div class="flex items-center justify-between p-3 rounded-xl border border-border-theme/50">
            <div>
                <p class="text-[12px] text-white">${escapeHtml(c.purpose || 'Sin especificar')}</p>
                <p class="text-[10px] text-text-subtle">
                    ${c.active
                        ? 'Activo desde ' + (c.grantedAt ? String(c.grantedAt).substring(0,10) : '—')
                        : 'Revocado el ' + String(c.revokedAt || '').substring(0,10)}
                </p>
            </div>
            ${c.revocable
                ? `<button onclick="revokeConsent('${escapeAttr(c.id)}')" class="px-3 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20">Revocar</button>`
                : ''}
        </div>
    `).join('');

    document.getElementById('pt-consents').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">✅ Tus consentimientos</h3>
            <div class="space-y-2">${html}</div>
        </div>`;
}

function renderArco(data) {
    const reqs = data.arcoRequests || [];
    if (!reqs.length) {
        document.getElementById('pt-arco').innerHTML = '';
        return;
    }

    const html = reqs.map(r => {
        const badge = r.overdue
            ? '<span class="px-2 py-0.5 rounded text-[10px] bg-red-500/10 text-red-400 border border-red-500/20">Vencida</span>'
            : r.closed
                ? '<span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Cerrada</span>'
                : '<span class="px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">En plazo</span>';

        return `
            <div class="p-3 rounded-xl border border-border-theme/50">
                <div class="flex items-center justify-between">
                    <p class="text-[12px] text-white font-medium">${escapeHtml(r.requestId)} — ${escapeHtml(r.type)}</p>
                    ${badge}
                </div>
                <p class="text-[10px] text-text-subtle mt-1">
                    ${r.closed ? 'Cerrada' : 'Quedan ' + r.daysRemaining + ' días hábiles'}
                    ${r.createdAt ? ' · Creada ' + String(r.createdAt).substring(0,10) : ''}
                </p>
                ${r.response ? `<p class="text-[11px] text-text-muted mt-2 p-2 rounded bg-bg-base/50">${escapeHtml(r.response.substring(0, 200))}</p>` : ''}
            </div>`;
    }).join('');

    document.getElementById('pt-arco').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">📬 Tus solicitudes ARCO</h3>
            <div class="space-y-2">${html}</div>
        </div>`;
}

function renderDpd(data) {
    const dpd = data.company?.dpd || {};
    const policies = data.company?.policies || {};
    const hasContact = dpd.name || dpd.email;
    const hasPolicies = policies.privacyUrl || policies.cookiesUrl;

    if (!hasContact && !hasPolicies) {
        document.getElementById('pt-dpd').innerHTML = '';
        return;
    }

    document.getElementById('pt-dpd').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">📞 Contacto y políticas</h3>
            ${hasContact ? `
                <div class="mb-3">
                    <p class="text-[10px] uppercase text-text-subtle mb-1">Delegado de Protección de Datos (DPD)</p>
                    <p class="text-[12px] text-white">${escapeHtml(dpd.name || '—')}</p>
                    ${dpd.email ? `<p class="text-[11px] text-text-muted">${escapeHtml(dpd.email)}</p>` : ''}
                    ${dpd.phone ? `<p class="text-[11px] text-text-muted">${escapeHtml(dpd.phone)}</p>` : ''}
                </div>` : ''}
            ${hasPolicies ? `
                <div class="flex flex-wrap gap-2 mt-2">
                    ${policies.privacyUrl ? `<a href="${escapeAttr(policies.privacyUrl)}" target="_blank" class="text-[11px] text-primary-400 hover:text-primary-300">Política de Privacidad ↗</a>` : ''}
                    ${policies.cookiesUrl ? `<a href="${escapeAttr(policies.cookiesUrl)}" target="_blank" class="text-[11px] text-primary-400 hover:text-primary-300">Política de Cookies ↗</a>` : ''}
                </div>` : ''}
        </div>`;
}

// ── Acciones ──
async function revokeConsent(consentId) {
    if (!consentId) return;
    if (!confirm('¿Revocar este consentimiento? Esta acción es definitiva.')) return;

    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/revoke-consent'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
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

function downloadMyData(format = 'json') {
    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();
    window.open(
        API + encodeURIComponent('/api/public/portal/download') +
        '&format=' + encodeURIComponent(format) +
        '&token=' + encodeURIComponent(token),
        '_blank'
    );
}

// ── Utilidades ──
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) {
    return String(s ?? '').replace(/[^a-zA-Z0-9_\-.:\/?#&=]/g, '');
}

// ── Init ──
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