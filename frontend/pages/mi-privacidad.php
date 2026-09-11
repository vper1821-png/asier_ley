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

        <!-- ═══ CAPA 1 ═══ -->
        <div id="step-email" class="rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa tu email</h2>
            <input type="email" id="pt-email" placeholder="tu@email.cl" class="input-premium w-full mb-4" autocomplete="email">
            <div class="space-y-2">
                <button onclick="checkEmail()" id="pt-btn-check" class="btn-secondary w-full">🔍 Verificar si tienen mis datos</button>
                <button onclick="requestCode()" id="pt-btn-request" class="btn-primary w-full">🔐 Acceder a mi portal</button>
            </div>
            <p class="text-[11px] text-text-muted mt-3 text-center">¿No sabes si tenemos tus datos? Usa la primera opción.</p>
            <p id="pt-email-error" class="text-[11px] text-red-400 mt-2 text-center hidden"></p>
        </div>

        <!-- Selector de empresa -->
        <div id="step-company" class="hidden rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-2">Selecciona una empresa</h2>
            <p class="text-[12px] text-text-muted mb-4" id="pt-company-intro"></p>
            <div id="pt-company-list" class="space-y-2"></div>
            <button onclick="showStep('email')" class="text-[11px] text-text-subtle mt-4">Volver</button>
        </div>

        <!-- ═══ CAPA 3: código ═══ -->
        <div id="step-code" class="hidden rounded-2xl border border-border-theme bg-bg-panel/60 p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Ingresa el código</h2>
            <p class="text-[12px] text-text-muted mb-3" id="pt-email-display"></p>
            <input type="text" id="pt-code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" class="input-premium w-full text-center text-2xl tracking-[0.5em] font-mono mb-3" autocomplete="one-time-code">
            <button id="pt-btn-verify" onclick="verifyCode()" class="btn-primary w-full">Verificar</button>
            <div class="flex items-center justify-between mt-3">
                <button onclick="showStep('email')" class="text-[11px] text-text-subtle">Volver</button>
                <button id="pt-btn-resend" onclick="requestCode(true)" class="text-[11px] text-primary-400 disabled:opacity-40" disabled>Reenviar código</button>
            </div>
            <p id="pt-code-error" class="text-[11px] text-red-400 mt-2 text-center hidden"></p>
        </div>

        <!-- ═══ CAPA 3: dashboard ═══ -->
        <div id="step-dashboard" class="hidden space-y-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <p class="text-[12px] text-text-muted">Hola, <span id="pt-user-email" class="text-white font-medium"></span></p>
                <div class="flex items-center gap-3">
                    <button onclick="changeCompany()" id="pt-change-company" class="hidden text-[11px] text-primary-400 hover:text-primary-300">Cambiar empresa</button>
                    <button onclick="logoutPortal()" class="text-[11px] text-text-muted hover:text-red-400">Cerrar sesión</button>
                </div>
            </div>

            <div id="pt-company-badge"></div>
            <div id="pt-summary" class="grid grid-cols-2 md:grid-cols-4 gap-3"></div>
            <div id="pt-concrete-request"></div>
            <div id="pt-subject"></div>
            <div id="pt-treatment"></div>
            <div id="pt-retention"></div>
            <div id="pt-third-parties"></div>
            <div id="pt-consents"></div>
            <div id="pt-arco"></div>

            <!-- Datos concretos -->
            <div class="rounded-2xl border border-primary-500/30 bg-primary-500/5 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-2">🔐 Solicitar mis datos concretos</h3>
                <p class="text-[11px] text-text-muted mb-3">Para ver los datos específicos que la empresa tiene sobre ti (nombre, RUT, historial), el DPO validará tu identidad mediante cédula y selfie.</p>
                <button onclick="requestConcreteData()" class="btn-primary text-[12px]">Solicitar datos concretos</button>
            </div>

            <!-- Derechos ARCO -->
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">⚖️ Ejercer mis derechos ARCO</h3>
                <p class="text-[11px] text-text-muted mb-3">Solicita rectificación, supresión, oposición, portabilidad o bloqueo. El DPO evaluará cada solicitud.</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <button onclick="openArcoModal('rectificacion')" class="btn-secondary text-[11px]">Rectificación</button>
                    <button onclick="openArcoModal('supresion')"     class="btn-secondary text-[11px]">Supresión</button>
                    <button onclick="openArcoModal('oposicion')"     class="btn-secondary text-[11px]">Oposición</button>
                    <button onclick="openArcoModal('portabilidad')"  class="btn-secondary text-[11px]">Portabilidad</button>
                    <button onclick="openArcoModal('bloqueo')"       class="btn-secondary text-[11px]">Bloqueo</button>
                    <button onclick="openArcoModal('acceso')"        class="btn-secondary text-[11px]">Acceso</button>
                </div>
            </div>

            <!-- Descargar -->
            <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
                <h3 class="text-[13px] font-semibold text-white mb-3">📥 Descargar mis datos</h3>
                <p class="text-[11px] text-text-muted mb-3">Descarga tus datos en formato estructurado (Art. 13).</p>
                <div class="flex flex-wrap gap-2">
                    <button onclick="downloadMyData('json')" class="btn-primary text-[12px]">JSON</button>
                    <button onclick="downloadMyData('csv')"  class="btn-secondary text-[12px]">CSV</button>
                    <button onclick="downloadMyData('xml')"  class="btn-secondary text-[12px]">XML</button>
                </div>
            </div>

            <div id="pt-dpd"></div>
        </div>
    </div>
</div>

<!-- Modal ARCO -->
<div id="arco-modal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
    <div class="bg-bg-panel rounded-2xl border border-border-theme max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white" id="arco-modal-title">Nueva solicitud ARCO</h3>
            <button onclick="closeArcoModal()" class="text-text-muted hover:text-white">✕</button>
        </div>
        <input type="hidden" id="arco-tipo">
        <input type="hidden" id="arco-consent-id">
        <p class="text-[11px] text-text-muted mb-3" id="arco-help"></p>
        <textarea id="arco-descripcion" rows="5" class="input-premium w-full mb-3" placeholder="Describe tu solicitud con el mayor detalle posible (mínimo 20 caracteres)"></textarea>
        <p id="arco-error" class="text-[11px] text-red-400 mb-2 hidden"></p>
        <div class="flex gap-2">
            <button onclick="submitArco()" id="arco-submit" class="btn-primary flex-1">Enviar solicitud</button>
            <button onclick="closeArcoModal()" class="btn-secondary">Cancelar</button>
        </div>
    </div>
</div>

<!-- Modal identidad -->
<div id="identity-modal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
    <div class="bg-bg-panel rounded-2xl border border-border-theme max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-white">Validación de identidad</h3>
            <button onclick="closeIdentityModal()" class="text-text-muted hover:text-white">✕</button>
        </div>
        <p class="text-[11px] text-text-muted mb-4">Sube las siguientes imágenes para que el DPO valide tu identidad. Los archivos se eliminan una vez resuelta la solicitud.</p>
        <input type="hidden" id="identity-request-id">
        <label class="block mb-3">
            <span class="text-[11px] text-text-muted">Cédula — frente</span>
            <input type="file" id="identity-front" accept="image/*" class="input-premium w-full mt-1">
        </label>
        <label class="block mb-3">
            <span class="text-[11px] text-text-muted">Cédula — reverso</span>
            <input type="file" id="identity-back" accept="image/*" class="input-premium w-full mt-1">
        </label>
        <label class="block mb-4">
            <span class="text-[11px] text-text-muted">Selfie sosteniendo tu cédula</span>
            <input type="file" id="identity-selfie" accept="image/*" class="input-premium w-full mt-1">
        </label>
        <p id="identity-error" class="text-[11px] text-red-400 mb-2 hidden"></p>
        <div class="flex gap-2">
            <button onclick="submitIdentity()" id="identity-submit" class="btn-primary flex-1">Enviar documentos</button>
            <button onclick="closeIdentityModal()" class="btn-secondary">Cancelar</button>
        </div>
    </div>
</div>

<script>
const PT_KEY = 'portal_token';
const PT_EMAIL_KEY = 'portal_email';
const PT_COMPANY_KEY = 'portal_companyId';
const PT_COMPANY_NAME_KEY = 'portal_companyName';
const API = '/api-proxy.php?path=';

// ── UI ──
function showStep(s) {
    ['email','company','code','dashboard'].forEach(x => {
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
        btn.textContent = text || '…';
    } else {
        btn.disabled = false;
        if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
}

// ── CAPA 1: Consulta ──
async function checkEmail() {
    setError('pt-email-error', '');
    const email = (document.getElementById('pt-email').value || '').trim().toLowerCase();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        return setError('pt-email-error', 'Ingresa un email válido');
    }

    setLoading('pt-btn-check', true, 'Verificando…');
    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/check-email'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        const data = await res.json();

        if (res.status === 429) return setError('pt-email-error', data.error || 'Demasiadas consultas.');

        if (data.exists) {
            sessionStorage.setItem(PT_EMAIL_KEY, email);
            if (data.companies.length === 1) {
                sessionStorage.setItem(PT_COMPANY_KEY, data.companies[0].companyId);
                sessionStorage.setItem(PT_COMPANY_NAME_KEY, data.companies[0].name);
                requestCode();
            } else {
                showCompanySelector(data.companies);
            }
        } else {
            alert('ℹ️ No tenemos datos personales asociados a este email.\n\n'
                + 'Si crees que es un error, puedes presentar una solicitud ARCO.');
            if (confirm('¿Quieres crear una solicitud ARCO ahora?')) {
                window.location.href = '/arco-solicitud';
            }
        }
    } catch (e) {
        setError('pt-email-error', 'Error de conexión.');
    } finally {
        setLoading('pt-btn-check', false);
    }
}

function showCompanySelector(companies) {
    const list = document.getElementById('pt-company-list');
    list.innerHTML = companies.map(c => `
        <button onclick="selectCompany('${escapeAttr(c.companyId)}', '${escapeAttr(c.name)}')"
                class="w-full text-left p-4 rounded-xl border border-border-theme hover:border-primary-500/40 hover:bg-primary-500/5 transition">
            <p class="text-[13px] text-white font-medium">${escapeHtml(c.name)}</p>
            <p class="text-[11px] text-text-muted mt-1">
                ${c.consentsCount} tratamientos (${c.activeConsents} activos) · ${c.arcoRequests} solicitudes ARCO
            </p>
        </button>
    `).join('');
    document.getElementById('pt-company-intro').textContent = 'Encontramos tus datos en varias empresas. Selecciona con cuál quieres trabajar.';
    showStep('company');
}

function selectCompany(companyId, companyName) {
    sessionStorage.setItem(PT_COMPANY_KEY, companyId);
    sessionStorage.setItem(PT_COMPANY_NAME_KEY, companyName);
    requestCode();
}

// ── CAPA 3: Código ──
async function requestCode(isResend = false) {
    setError('pt-email-error', '');
    setError('pt-code-error', '');

    const emailInput = document.getElementById('pt-email');
    const email = (emailInput.value || sessionStorage.getItem(PT_EMAIL_KEY) || '').trim().toLowerCase();
    const companyId = sessionStorage.getItem(PT_COMPANY_KEY) || '';

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
            body: JSON.stringify({ email, companyId })
        });
        const data = await res.json();

        if (res.ok && data.success) {
            sessionStorage.setItem(PT_EMAIL_KEY, email);
            emailInput.value = email;
            document.getElementById('pt-email-display').textContent = 'Código enviado a: ' + email;
            showStep('code');
            startResendCooldown(60);
            if (data.dev_code) setError('pt-code-error', 'Modo dev — código: ' + data.dev_code);
        } else {
            setError(isResend ? 'pt-code-error' : 'pt-email-error', data.error || 'No se pudo enviar el código');
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

async function verifyCode() {
    setError('pt-code-error', '');
    const email = (sessionStorage.getItem(PT_EMAIL_KEY) || '').trim().toLowerCase();
    const code  = (document.getElementById('pt-code').value || '').trim();

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
    sessionStorage.removeItem(PT_COMPANY_KEY);
    sessionStorage.removeItem(PT_COMPANY_NAME_KEY);
    ['pt-summary','pt-subject','pt-treatment','pt-retention','pt-third-parties',
     'pt-consents','pt-arco','pt-dpd','pt-concrete-request','pt-company-badge'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = '';
    });
    document.getElementById('pt-user-email').textContent = '';
    showStep('email');
}

function changeCompany() {
    sessionStorage.removeItem(PT_COMPANY_KEY);
    sessionStorage.removeItem(PT_COMPANY_NAME_KEY);
    checkEmail();
}

// ── Dashboard ──
async function loadDashboard() {
    const token = localStorage.getItem(PT_KEY);
    if (!token) return showStep('email');

    const companyId = sessionStorage.getItem(PT_COMPANY_KEY);
    const url = API + encodeURIComponent('/api/public/portal/my-data') +
                (companyId ? '&companyId=' + encodeURIComponent(companyId) : '');

    try {
        const res = await fetch(url, { headers: { 'Authorization': 'Bearer ' + token } });
        if (res.status === 401) return logoutPortal();
        const data = await res.json();
        if (data.error) return logoutPortal();

        if (data.mode === 'select_company') {
            showCompanySelector(data.companies);
            return;
        }

        // Guardar companyId por si no estaba
        if (data.companyId) sessionStorage.setItem(PT_COMPANY_KEY, data.companyId);

        showStep('dashboard');
        document.getElementById('pt-user-email').textContent = data.email || '';

        // Botón cambiar empresa si el titular tiene más de una
        const hasMultiple = document.getElementById('pt-change-company');
        // Se detecta en checkEmail; aquí solo lo mostramos si guardamos companyId
        hasMultiple.classList.toggle('hidden', !sessionStorage.getItem(PT_COMPANY_KEY));

        renderCompanyBadge(data);
        renderSummary(data);
        renderConcreteRequest(data.concreteDataRequest);
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

// ── Render ──
function renderCompanyBadge(data) {
    const name = data.company?.name || '';
    document.getElementById('pt-company-badge').innerHTML = name
        ? `<div class="rounded-xl border border-primary-500/20 bg-primary-500/5 px-4 py-2 text-[11px]">
              <span class="text-text-subtle">Empresa:</span> <span class="text-white font-medium">${escapeHtml(name)}</span>
           </div>`
        : '';
}

function renderSummary(data) {
    const s = data.summary || {};
    document.getElementById('pt-summary').innerHTML = `
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Tratamientos</p>
            <p class="text-2xl font-bold text-white mt-1">${s.activeConsents ?? 0}/${s.totalConsents ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Solicitudes ARCO</p>
            <p class="text-2xl font-bold text-white mt-1">${s.openRequests ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Actividades RAT</p>
            <p class="text-2xl font-bold text-white mt-1">${s.totalTreatments ?? 0}</p>
        </div>
        <div class="rounded-xl border border-border-theme bg-bg-panel/60 p-4">
            <p class="text-[10px] text-text-subtle uppercase">Categorías</p>
            <p class="text-2xl font-bold text-white mt-1">${s.dataCategoriesCount ?? 0}</p>
        </div>`;
}

function renderConcreteRequest(req) {
    const el = document.getElementById('pt-concrete-request');
    if (!req) { el.innerHTML = ''; return; }

    const statusMap = {
        pending_identity:   'Pendiente de validación de identidad',
        pending_validation: 'Documentos en revisión por el DPO',
        in_progress:        'Preparando tu paquete de datos',
        completed:          'Completada',
        resolved:           'Completada',
        finished:           'Completada',
        rejected:           'Rechazada',
    };
    const identityMap = {
        pending:            'Pendiente',
        documents_uploaded: 'Documentos recibidos',
        verified:           'Identidad verificada',
        rejected:           'Rechazada',
    };

    const needsUpload = (req.identityStatus === 'pending') &&
                        (req.status === 'pending_identity' || req.status === 'pending');

    el.innerHTML = `
        <div class="rounded-2xl border border-primary-500/30 bg-primary-500/5 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-2">📋 Solicitud de datos concretos en curso</h3>
            <p class="text-[11px] text-text-muted"><b>ID:</b> ${escapeHtml(req.requestId)}</p>
            <p class="text-[11px] text-text-muted"><b>Estado:</b> ${statusMap[req.status] || req.status}</p>
            <p class="text-[11px] text-text-muted"><b>Identidad:</b> ${identityMap[req.identityStatus] || req.identityStatus}</p>
            <p class="text-[11px] text-text-muted"><b>Días restantes:</b> ${req.daysRemaining ?? 0} hábiles</p>
            ${needsUpload ? `<button onclick="openIdentityModal('${escapeAttr(req.requestId)}')" class="btn-primary text-[12px] mt-3">Subir cédula y selfie</button>` : ''}
        </div>`;
}

function renderSubject(data) {
    const subj = data.subject || {};
    const company = data.company || {};
    document.getElementById('pt-subject').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">👤 Tu información registrada</h3>
            <div class="grid grid-cols-2 gap-3 text-[12px]">
                <div><p class="text-[10px] text-text-subtle uppercase">Email</p><p class="text-white mt-1">${escapeHtml(subj.email || '')}</p></div>
                <div><p class="text-[10px] text-text-subtle uppercase">Nombre</p><p class="text-white mt-1">${escapeHtml(subj.name || '—')}</p></div>
                <div><p class="text-[10px] text-text-subtle uppercase">RUT</p><p class="text-white mt-1">${escapeHtml(subj.rut || '—')}</p></div>
                <div><p class="text-[10px] text-text-subtle uppercase">Responsable</p><p class="text-white mt-1">${escapeHtml(company.name || '—')}</p></div>
            </div>
        </div>`;
}

function renderTreatment(data) {
    const map  = data.treatmentMap || [];
    const cats = data.dataCategories || [];
    if (!map.length) { document.getElementById('pt-treatment').innerHTML = ''; return; }

    const catsHtml = cats.length
        ? `<div class="flex flex-wrap gap-1.5 mt-2">${cats.map(c => `<span class="px-2 py-0.5 rounded-md bg-primary-500/10 text-primary-300 text-[10px] border border-primary-500/20">${escapeHtml(c)}</span>`).join('')}</div>` : '';

    const mapHtml = map.map(t => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white font-medium">${escapeHtml(t.name || 'Sin nombre')}</p>
            ${t.purpose ? `<p class="text-[11px] text-text-muted mt-1"><b class="text-text-subtle">Finalidad:</b> ${escapeHtml(t.purpose)}</p>` : ''}
            ${t.legalBasisLabel ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Base legal:</b> <span class="px-1.5 py-0.5 rounded bg-bg-base/60 border border-border-theme/30 text-[10px]">${escapeHtml(t.legalBasisLabel)}</span></p>` : ''}
            ${t.dataCategories?.length ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Datos:</b> ${t.dataCategories.map(escapeHtml).join(', ')}</p>` : ''}
            ${t.retentionDays ? `<p class="text-[11px] text-text-muted"><b class="text-text-subtle">Retención:</b> ${t.retentionDays} días</p>` : ''}
            ${t.sensitive ? `<span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] bg-red-500/10 text-red-400 border border-red-500/20">Datos sensibles</span>` : ''}
            ${t.childrenData ? `<span class="inline-block mt-1 ml-1 px-2 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20">Datos de menores</span>` : ''}
        </div>
    `).join('');

    document.getElementById('pt-treatment').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-1">📋 Cómo tratamos tus datos</h3>
            <p class="text-[11px] text-text-muted mb-3">Categorías y finalidades declaradas por el responsable en su RAT.</p>
            ${catsHtml}
            <div class="space-y-2 mt-3">${mapHtml}</div>
        </div>`;
}

function renderRetention(data) {
    const ret = data.retention || {};
    const entries = Object.entries(ret);
    if (!entries.length) { document.getElementById('pt-retention').innerHTML = ''; return; }
    const rows = entries.map(([cat, days]) => `
        <div class="flex items-center justify-between p-2 rounded-lg border border-border-theme/40">
            <span class="text-[11px] text-white">${escapeHtml(cat)}</span>
            <span class="text-[11px] text-text-muted">${days} días</span>
        </div>`).join('');

    document.getElementById('pt-retention').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">⏳ Cuánto tiempo conservamos</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">${rows}</div>
        </div>`;
}

function renderThirdParties(data) {
    const proc  = data.processors || [];
    const trans = data.transfers || [];
    if (!proc.length && !trans.length) { document.getElementById('pt-third-parties').innerHTML = ''; return; }

    const procHtml = proc.map(p => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white">${escapeHtml(p.name)}</p>
            <p class="text-[10px] text-text-subtle">${escapeHtml(p.serviceType || '')} ${p.country ? '· ' + escapeHtml(p.country) : ''}</p>
        </div>`).join('');

    const transHtml = trans.map(t => `
        <div class="p-3 rounded-xl border border-border-theme/50">
            <p class="text-[12px] text-white">${escapeHtml(t.recipient || '')}</p>
            <p class="text-[10px] text-text-subtle">${escapeHtml(t.country || '')} ${t.mechanism ? '· ' + escapeHtml(t.mechanism) : ''}</p>
        </div>`).join('');

    document.getElementById('pt-third-parties').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">🤝 Con quién compartimos</h3>
            ${proc.length ? `<p class="text-[10px] uppercase text-text-subtle mb-2">Encargados</p><div class="space-y-2 mb-3">${procHtml}</div>` : ''}
            ${trans.length ? `<p class="text-[10px] uppercase text-text-subtle mb-2">Transferencias internacionales</p><div class="space-y-2">${transHtml}</div>` : ''}
        </div>`;
}

function renderConsents(data) {
    const consents = data.consents || [];
    if (!consents.length) { document.getElementById('pt-consents').innerHTML = ''; return; }

    const html = consents.map(c => {
        let buttonsHtml = '';
        if (c.active) {
            const acts = c.availableActions || [];
            if (acts.includes('revoke')) {
                buttonsHtml += `<button onclick="revokeConsent('${escapeAttr(c.id)}')" class="px-3 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20">Revocar</button>`;
            }
            if (acts.includes('oppose')) {
                buttonsHtml += `<button onclick="opposeConsent('${escapeAttr(c.id)}')" class="ml-2 px-3 py-1.5 rounded-lg text-[10px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/25 hover:bg-amber-500/20">Oponerse</button>`;
            }
            if (acts.includes('request_deletion')) {
                buttonsHtml += `<button onclick="requestDeletion('${escapeAttr(c.id)}')" class="ml-2 px-3 py-1.5 rounded-lg text-[10px] font-medium bg-red-500/10 text-red-400 border border-red-500/25 hover:bg-red-500/20">Solicitar supresión</button>`;
            }
        }

        let statusHtml = '';
        if (c.active) {
            statusHtml = `<p class="text-[10px] text-emerald-400">Activo desde ${String(c.grantedAt || '').substring(0,10)}</p>`;
        } else {
            const st = c.revocationStatus;
            if (st === 'pending_review') {
                statusHtml = `<p class="text-[10px] text-amber-400">Revocado el ${String(c.revokedAt || '').substring(0,10)} · Revisión del DPO en curso</p>`;
            } else if (st === 'confirmed') {
                statusHtml = `<p class="text-[10px] text-red-400">Revocado el ${String(c.revokedAt || '').substring(0,10)} · Cese confirmado</p>`;
            } else {
                statusHtml = `<p class="text-[10px] text-red-400">Revocado el ${String(c.revokedAt || '').substring(0,10)}</p>`;
            }
        }

        const explanationHtml = c.explanation
            ? `<p class="text-[10px] text-text-subtle italic mt-1">${escapeHtml(c.explanation)}</p>`
            : '';

        return `
            <div class="p-3 rounded-xl border border-border-theme/50">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1">
                        <p class="text-[12px] text-white font-medium">${escapeHtml(c.purpose || 'Sin especificar')}</p>
                        <p class="text-[10px] text-text-subtle mt-0.5">
                            <span class="px-1.5 py-0.5 rounded bg-bg-base/60 border border-border-theme/30">${escapeHtml(c.legalBasisLabel || c.legalBasis || '—')}</span>
                        </p>
                        ${statusHtml}
                        ${explanationHtml}
                    </div>
                    <div class="shrink-0">${buttonsHtml}</div>
                </div>
            </div>`;
    }).join('');

    document.getElementById('pt-consents').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-1">✅ Tus tratamientos y bases legales</h3>
            <p class="text-[11px] text-text-muted mb-3">Cada tratamiento tiene una base legal. Solo los basados en consentimiento pueden revocarse directamente.</p>
            <div class="space-y-2">${html}</div>
        </div>`;
}

function renderArco(data) {
    const reqs = data.arcoRequests || [];
    if (!reqs.length) { document.getElementById('pt-arco').innerHTML = ''; return; }

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
    const dpd      = data.company?.dpd || {};
    const policies = data.company?.policies || {};
    if (!dpd.name && !dpd.email && !policies.privacyUrl && !policies.cookiesUrl) {
        document.getElementById('pt-dpd').innerHTML = '';
        return;
    }

    document.getElementById('pt-dpd').innerHTML = `
        <div class="rounded-2xl border border-border-theme bg-bg-panel/60 p-5">
            <h3 class="text-[13px] font-semibold text-white mb-3">📞 Contacto y políticas</h3>
            ${(dpd.name || dpd.email) ? `
                <div class="mb-3">
                    <p class="text-[10px] uppercase text-text-subtle mb-1">Delegado de Protección de Datos (DPD)</p>
                    <p class="text-[12px] text-white">${escapeHtml(dpd.name || '—')}</p>
                    ${dpd.email ? `<p class="text-[11px] text-text-muted">${escapeHtml(dpd.email)}</p>` : ''}
                    ${dpd.phone ? `<p class="text-[11px] text-text-muted">${escapeHtml(dpd.phone)}</p>` : ''}
                </div>` : ''}
            ${(policies.privacyUrl || policies.cookiesUrl) ? `
                <div class="flex flex-wrap gap-2 mt-2">
                    ${policies.privacyUrl ? `<a href="${escapeAttr(policies.privacyUrl)}" target="_blank" class="text-[11px] text-primary-400 hover:text-primary-300">Política de Privacidad ↗</a>` : ''}
                    ${policies.cookiesUrl ? `<a href="${escapeAttr(policies.cookiesUrl)}" target="_blank" class="text-[11px] text-primary-400 hover:text-primary-300">Política de Cookies ↗</a>` : ''}
                </div>` : ''}
        </div>`;
}

// ── Acciones ──
async function revokeConsent(consentId) {
    if (!consentId) return;
    if (!confirm('¿Revocar este consentimiento? El cese del tratamiento es inmediato.')) return;

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
            alert(data.message || 'Consentimiento revocado.');
            loadDashboard();
        } else {
            alert(data.error || 'Error al revocar');
        }
    } catch (e) {
        alert('Error de conexión.');
    }
}

function opposeConsent(consentId) {
    openArcoModal('oposicion', consentId);
}
function requestDeletion(consentId) {
    openArcoModal('supresion', consentId);
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

async function requestConcreteData() {
    if (!confirm('Para ver tus datos concretos, el DPO validará tu identidad mediante cédula y selfie.\n\n¿Continuar?')) return;

    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();

    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/concrete-data'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
            body: JSON.stringify({ motivo: 'Solicitud desde portal del titular' })
        });
        if (res.status === 401) return logoutPortal();
        const data = await res.json();

        if (data.success) {
            alert('✅ Solicitud ' + data.requestId + ' creada.\n\n' + data.nextStep);
            await loadDashboard();
            openIdentityModal(data.requestId);
        } else {
            alert(data.error || 'Error al crear la solicitud');
        }
    } catch (e) {
        alert('Error de conexión.');
    }
}

// ── Modal identidad ──
function openIdentityModal(requestId) {
    document.getElementById('identity-request-id').value = requestId;
    document.getElementById('identity-front').value = '';
    document.getElementById('identity-back').value = '';
    document.getElementById('identity-selfie').value = '';
    setError('identity-error', '');
    document.getElementById('identity-modal').classList.remove('hidden');
}
function closeIdentityModal() {
    document.getElementById('identity-modal').classList.add('hidden');
}

async function submitIdentity() {
    setError('identity-error', '');
    const requestId = document.getElementById('identity-request-id').value;
    const front     = document.getElementById('identity-front').files[0];
    const back      = document.getElementById('identity-back').files[0];
    const selfie    = document.getElementById('identity-selfie').files[0];

    if (!requestId) return setError('identity-error', 'Falta el ID de solicitud');
    if (!front || !back || !selfie) return setError('identity-error', 'Debes subir las 3 imágenes');

    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();

    const fd = new FormData();
    fd.append('requestId', requestId);
    fd.append('cedula_frontal', front);
    fd.append('cedula_reverso', back);
    fd.append('selfie', selfie);

    setLoading('identity-submit', true, 'Subiendo…');
    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/upload-identity'), {
            method: 'POST',
            headers: { 'Authorization': 'Bearer ' + token },
            body: fd
        });
        if (res.status === 401) return logoutPortal();
        const data = await res.json();

        if (data.success) {
            alert('✅ ' + (data.message || 'Documentos enviados.'));
            closeIdentityModal();
            loadDashboard();
        } else {
            setError('identity-error', data.error || 'Error al subir los documentos');
        }
    } catch (e) {
        setError('identity-error', 'Error de conexión.');
    } finally {
        setLoading('identity-submit', false);
    }
}

// ── Modal ARCO ──
const ARCO_HELP = {
    acceso:        'Solicita conocer qué datos tenemos sobre ti.',
    rectificacion: 'Solicita corregir datos inexactos o incompletos.',
    supresion:     'Solicita eliminar tus datos. El DPO evaluará si procede según la base legal.',
    oposicion:     'Oponte al tratamiento de tus datos con motivos fundados. El DPO evaluará tu solicitud.',
    portabilidad:  'Solicita recibir tus datos en formato estructurado.',
    bloqueo:       'Solicita suspender temporalmente el tratamiento de tus datos.',
};

function openArcoModal(tipo, consentId = '') {
    document.getElementById('arco-tipo').value = tipo;
    document.getElementById('arco-consent-id').value = consentId || '';
    document.getElementById('arco-modal-title').textContent = 'Solicitud de ' + tipo.charAt(0).toUpperCase() + tipo.slice(1);
    document.getElementById('arco-help').textContent = ARCO_HELP[tipo] || '';
    document.getElementById('arco-descripcion').value = '';
    setError('arco-error', '');
    document.getElementById('arco-modal').classList.remove('hidden');
}
function closeArcoModal() {
    document.getElementById('arco-modal').classList.add('hidden');
}

async function submitArco() {
    setError('arco-error', '');
    const tipo        = document.getElementById('arco-tipo').value;
    const consentId   = document.getElementById('arco-consent-id').value;
    const descripcion = document.getElementById('arco-descripcion').value.trim();

    if (!tipo) return setError('arco-error', 'Falta el tipo de solicitud');
    if (descripcion.length < 20) return setError('arco-error', 'La descripción debe tener al menos 20 caracteres');

    const token = localStorage.getItem(PT_KEY);
    if (!token) return logoutPortal();

    const payload = { tipo, descripcion };
    if (consentId) payload.consentId = consentId;

    setLoading('arco-submit', true, 'Enviando…');
    try {
        const res = await fetch(API + encodeURIComponent('/api/public/portal/arco/create'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
            body: JSON.stringify(payload)
        });
        if (res.status === 401) return logoutPortal();
        const data = await res.json();

        if (data.success) {
            alert('✅ Solicitud ' + data.requestId + ' creada.\nPlazo de respuesta: ' + data.plazoDias + ' días hábiles.');
            closeArcoModal();
            loadDashboard();
        } else {
            setError('arco-error', data.error || 'Error al crear la solicitud');
        }
    } catch (e) {
        setError('arco-error', 'Error de conexión.');
    } finally {
        setLoading('arco-submit', false);
    }
}

// ── Utilidades ──
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function escapeAttr(s) {
    return String(s ?? '').replace(/[^a-zA-Z0-9_\-]/g, '');
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