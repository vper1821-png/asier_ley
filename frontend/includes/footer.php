    </main>

<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
<!-- Guided tour -->
<div id="tour-overlay" class="hidden fixed inset-0 z-[90] bg-gradient-to-t from-primary-900/40 to-black/80 backdrop-blur-[3px]"></div>
<div id="tour-popover" class="hidden fixed z-[100] w-96 rounded-2xl border border-primary-500/40 bg-gradient-to-br from-bg-panel via-bg-panel to-primary-900/[0.08] shadow-[0_0_60px_rgba(59,130,246,0.25)] p-0 overflow-hidden ring-1 ring-white/[0.06]" style="max-width: 420px;">
    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-primary-600 via-cyan-500 to-primary-600"></div>
    <div class="p-5">
        <div class="flex items-start gap-4 mb-4">
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-primary-600/30 to-cyan-600/20 border border-primary-500/30 flex items-center justify-center text-primary-400 flex-shrink-0 shadow-lg shadow-primary-900/30">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <p id="tour-title" class="text-[14px] font-bold text-white leading-tight"></p>
                    <button onclick="endTour()" class="p-1 rounded-md text-text-subtle hover:text-white hover:bg-white/[0.06] transition-colors" title="Cerrar tour">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <p id="tour-text" class="text-[12px] text-text-subtle leading-relaxed"></p>
            </div>
        </div>
        <div class="flex items-center justify-between pt-4 border-t border-border-theme/30">
            <span id="tour-step-count" class="text-[10px] text-text-subtle font-mono bg-bg-elevated/50 px-2 py-1 rounded-lg border border-border-theme/30"></span>
            <div class="flex gap-2">
                <button id="tour-prev" onclick="tourStep(-1)" class="px-3 py-1.5 rounded-lg text-[10px] bg-bg-elevated border border-border-theme text-text-muted hover:text-text-body hover:bg-white/[0.05] transition-all">Anterior</button>
                <button id="tour-next" onclick="tourStep(1)" class="px-3 py-1.5 rounded-lg text-[10px] font-semibold bg-gradient-to-r from-primary-600 to-cyan-600 text-white hover:from-primary-500 hover:to-cyan-500 transition-all shadow-lg shadow-primary-900/20">Siguiente</button>
            </div>
        </div>
        <button id="tour-detail" onclick="toggleDetailMode()" class="hidden w-full mt-3 px-3 py-2 rounded-xl text-[11px] font-medium bg-white/[0.04] border border-dashed border-primary-500/40 text-primary-300 hover:bg-primary-500/10 hover:border-primary-500/60 hover:text-primary-200 transition-all flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/></svg>
            Explorar esta sección en detalle
        </button>
    </div>
    <div class="tour-arrow absolute w-3 h-3 bg-bg-panel border-l border-t border-primary-500/40 transform -rotate-45 -z-10"></div>
</div>

<?php require_once __DIR__ . '/support-chat.php'; ?>

<script>
// ── Guided tour (multi-página) ──
const TOUR_KEY = 'sl_tour_step';
const TOUR_STEPS = [
    { page: '/dashboard', sel: '.tour-sidebar-logo', title: 'Bienvenido a SecureLab', text: 'Tu plataforma integral de ciberseguridad y cumplimiento. Este recorrido rápido te muestra las secciones principales.' },
    { page: '/dashboard', sel: '.tour-detail-kpi-grid', title: 'Dashboard', text: 'Vista general de agentes, bases de datos, compliance y alertas en tiempo real.', detailSteps: [
        { sel: '.tour-pending-ring', title: 'Tareas pendientes', text: 'Porcentaje de cumplimiento global y acciones recomendadas.' },
        { sel: '.tour-nav-items', title: 'Navegación principal', text: 'Accede a agentes, alertas, bases de datos, compliance y más.' },
        { sel: '.tour-detail-kpi-grid', title: 'Indicadores clave', text: 'Resumen de agentes, BBDD, cumplimiento, brechas y usuarios.' },
        { sel: '.tour-detail-stats-grid', title: 'Actividad', text: 'Alertas activas, escaneos, reportes y agentes online.' },
        { sel: '.tour-detail-tabs', title: 'Vistas', text: 'Cambia entre bases de datos, usuarios vulnerables y estado normativo.' },
        { sel: '.tour-detail-summary', title: 'Resumen', text: 'Estado actual del sistema y acciones rápidas.' }
    ]},
    { page: '/agents', sel: 'main', title: 'Agentes de Seguridad', text: 'Gestiona endpoints Windows/Linux/macOS: monitorea, controla y ejecuta acciones remotas.', detailSteps: [
        { sel: '.tour-detail-1', title: 'Panel de agentes', text: 'Listado de todos los agentes instalados con estado, ubicación y versión.' },
        { sel: '.tour-detail-2', title: 'Acciones y logs', text: 'Acciones remotas, control de equipos y registro de eventos del agente.' }
    ]},
    { page: '/databases', sel: 'main', title: 'Bases de Datos', text: 'Conecta MySQL, PostgreSQL y SQL Server. Escanea datos personales y detecta información sensible.', detailSteps: [
        { sel: '.tour-detail-1', title: 'Conexiones', text: 'Añade y gestiona las conexiones a tus bases de datos.' },
        { sel: '.tour-detail-2', title: 'Inventario de datos', text: 'Tablas, columnas con datos personales y hallazgos del escaneo.' }
    ]},
    { page: '/compliance', sel: 'main', title: 'Cumplimiento', text: 'Ley 21.719: consentimientos, inventario, DPIA, brechas y capacitación.', detailSteps: [
        { sel: '.tour-detail-1', title: 'Requisitos normativos', text: 'Checklist interactivo del Reglamento de la Ley 21.719 y progreso de cumplimiento.' }
    ]},
    { page: '/hardening', sel: 'main', title: 'Hardening', text: 'Análisis de configuraciones débiles, recomendaciones y score de seguridad.', detailSteps: [
        { sel: '.tour-detail-1', title: 'Score y recomendaciones', text: 'Puntuación global de hardening y recomendaciones priorizadas.' },
        { sel: '.tour-detail-2', title: 'Controles aplicados', text: 'Recomendaciones detalladas y controles implementados.' }
    ]},
    { page: '/settings', sel: 'main', title: 'Configuración', text: 'Contraseña, 2FA, temas y preferencias de tu cuenta.', detailSteps: [
        { sel: 'main', title: 'Gestión de cuenta', text: 'Actualiza tu contraseña, configura el 2FA, elige temas y gestiona tus datos.' }
    ]},
    { page: '/dashboard', sel: '.tour-logout-btn', title: 'Cerrar sesión', text: 'Cierra tu sesión de forma segura cuando termines.' },
];
const DETAIL_KEY = 'sl_tour_detail';
let tourIdx = 0;
let detailMode = false;
let detailIdx = 0;
let detailSteps = [];

function startTour() {
    tourIdx = 0;
    detailMode = false;
    sessionStorage.setItem(TOUR_KEY, '0');
    sessionStorage.removeItem(DETAIL_KEY);
    goToTourStep();
}

function endTour() {
    sessionStorage.removeItem(TOUR_KEY);
    sessionStorage.removeItem(DETAIL_KEY);
    detailMode = false;
    document.getElementById('tour-overlay').classList.add('hidden');
    document.getElementById('tour-popover').classList.add('hidden');
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
}

function tourStep(dir) {
    if (detailMode) {
        detailIdx += dir;
        if (detailIdx < 0) {
            detailIdx = 0;
            detailMode = false;
            sessionStorage.removeItem(DETAIL_KEY);
        } else if (detailIdx >= detailSteps.length) {
            detailMode = false;
            tourIdx += 1;
            sessionStorage.setItem(TOUR_KEY, String(tourIdx));
            sessionStorage.removeItem(DETAIL_KEY);
            if (tourIdx >= TOUR_STEPS.length) { endTour(); return; }
        } else {
            sessionStorage.setItem(DETAIL_KEY, String(detailIdx));
        }
    } else {
        tourIdx += dir;
        if (tourIdx < 0) tourIdx = 0;
        if (tourIdx >= TOUR_STEPS.length) { endTour(); return; }
        sessionStorage.setItem(TOUR_KEY, String(tourIdx));
        sessionStorage.removeItem(DETAIL_KEY);
    }
    goToTourStep();
}

function toggleDetailMode() {
    const step = TOUR_STEPS[tourIdx];
    if (!step.detailSteps || detailMode) return;
    detailSteps = step.detailSteps;
    detailMode = true;
    detailIdx = 0;
    sessionStorage.setItem(DETAIL_KEY, '0');
    showTourStep();
}

function goToTourStep() {
    const step = detailMode ? detailSteps[detailIdx] : TOUR_STEPS[tourIdx];
    const rootStep = detailMode ? TOUR_STEPS[tourIdx] : step;
    if (rootStep.page && window.location.pathname !== rootStep.page) {
        window.location.href = rootStep.page;
        return;
    }
    document.getElementById('tour-overlay').classList.remove('hidden');
    showTourStep();
}

function showTourStep() {
    const step = detailMode ? detailSteps[detailIdx] : TOUR_STEPS[tourIdx];
    const total = detailMode ? detailSteps.length : TOUR_STEPS.length;
    const idx = detailMode ? detailIdx : tourIdx;
    let target = document.querySelector(step.sel);
    if (!target) target = document.querySelector('main') || document.body;
    if (!target) { endTour(); return; }

    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
    target.classList.add('tour-highlight');
    try { target.scrollIntoView({ behavior: 'smooth', block: (step.sel === 'main' ? 'start' : 'center') }); } catch (e) {}

    const pop = document.getElementById('tour-popover');
    if (!pop) return;
    document.getElementById('tour-title').textContent = step.title;
    document.getElementById('tour-text').textContent = step.text;
    document.getElementById('tour-step-count').textContent = (idx + 1) + ' / ' + total;
    document.getElementById('tour-prev').style.visibility = (idx === 0 && !detailMode) ? 'hidden' : 'visible';
    document.getElementById('tour-next').textContent = (idx === total - 1 && !detailMode) ? 'Finalizar' : (detailMode ? 'Siguiente área' : 'Siguiente');

    const detailBtn = document.getElementById('tour-detail');
    if (!detailMode && TOUR_STEPS[tourIdx] && TOUR_STEPS[tourIdx].detailSteps) {
        detailBtn.classList.remove('hidden');
    } else {
        detailBtn.classList.add('hidden');
    }
    pop.classList.remove('hidden');

    const rect = target.getBoundingClientRect();
    const popW = Math.min(420, window.innerWidth - 20);
    let top = rect.bottom + 16;
    let left = rect.left;
    if (step.sel === 'main' || rect.width === 0) { top = Math.round(window.innerHeight / 2 - 110); left = Math.round(window.innerWidth / 2 - popW / 2); }
    if (top + 240 > window.innerHeight) top = Math.max(10, rect.top - 230);
    if (left + popW > window.innerWidth) left = window.innerWidth - popW - 10;
    if (left < 10) left = 10;
    pop.style.top = top + 'px';
    pop.style.left = left + 'px';
    pop.style.width = popW + 'px';
}

// Reanudar tour tras navegar de página
document.addEventListener('DOMContentLoaded', () => {
    const saved = sessionStorage.getItem(TOUR_KEY);
    if (saved !== null) {
        tourIdx = parseInt(saved, 10) || 0;
        if (tourIdx >= TOUR_STEPS.length) { endTour(); return; }
        const d = sessionStorage.getItem(DETAIL_KEY);
        if (d !== null && TOUR_STEPS[tourIdx].detailSteps) {
            detailMode = true;
            detailIdx = parseInt(d, 10) || 0;
            detailSteps = TOUR_STEPS[tourIdx].detailSteps;
        } else { detailMode = false; }
        const step = detailMode ? detailSteps[detailIdx] : TOUR_STEPS[tourIdx];
        const rootStep = detailMode ? TOUR_STEPS[tourIdx] : step;
        if (!rootStep.page || window.location.pathname === rootStep.page) {
            document.getElementById('tour-overlay').classList.remove('hidden');
            setTimeout(showTourStep, 300);
        }
    }
});
</script>
<style>
.tour-highlight { position: relative; z-index: 95; outline: 3px solid var(--primary-500, #3b82f6); outline-offset: 4px; border-radius: 12px; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), 0 0 30px rgba(59, 130, 246, 0.25); background-color: var(--bg-panel, #0b1220); animation: tourPulse 2s infinite; }
@keyframes tourPulse { 0% { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), 0 0 20px rgba(59, 130, 246, 0.2); } 50% { box-shadow: 0 0 0 8px rgba(59, 130, 246, 0.08), 0 0 40px rgba(59, 130, 246, 0.35); } 100% { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15), 0 0 20px rgba(59, 130, 246, 0.2); } }
.chat-scroll::-webkit-scrollbar { width: 6px; }
.chat-scroll::-webkit-scrollbar-track { background: transparent; }
.chat-scroll::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.35); border-radius: 10px; }
.chat-scroll::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 0.55); }
.chat-scroll { scrollbar-width: thin; scrollbar-color: rgba(99, 102, 241, 0.35) transparent; }
</style>
<?php endif; ?>

<!-- Cookie consent banner -->
<div id="cookie-banner" class="hidden fixed bottom-0 left-0 right-0 z-50 bg-gray-900 border-t border-gray-700 text-white p-4 shadow-2xl">
    <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex-1">
            <p class="text-sm font-semibold mb-1">Este sitio utiliza cookies</p>
            <p class="text-xs text-text-muted">
                Utilizamos cookies para mejorar tu experiencia, análisis de tráfico y funcionalidad del sitio.
                Conforme al Art. 14 ter de la Ley 21.719, puedes gestionar tus preferencias.
                Consulta nuestra <a href="/politica-privacidad" class="text-primary-400 underline">Política de Privacidad</a>.
            </p>
        </div>
        <div class="flex gap-2 shrink-0">
            <button onclick="setCookieConsent('necessary')" class="px-4 py-2 text-xs bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">Solo Necesarias</button>
            <button onclick="setCookieConsent('all')" class="px-4 py-2 text-xs bg-primary-600 hover:bg-primary-500 rounded-lg transition-colors font-semibold">Aceptar Todas</button>
        </div>
    </div>
</div>
<script>
const COOKIE_KEY = 'invisia_cookie_consent';
function setCookieConsent(level) {
    const analytics = level === 'all';
    const marketing = level === 'all';
    localStorage.setItem(COOKIE_KEY, JSON.stringify({ necessary: true, analytics, marketing, timestamp: new Date().toISOString() }));
    const banner = document.getElementById('cookie-banner');
    if (banner) banner.classList.add('hidden');
}
document.addEventListener('DOMContentLoaded', function () {
    if (!localStorage.getItem(COOKIE_KEY)) {
        const banner = document.getElementById('cookie-banner');
        if (banner) banner.classList.remove('hidden');
    }
});
</script>

</body>
</html>
