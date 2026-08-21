    </main>

<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
<!-- Guided tour -->
<div id="tour-overlay" class="hidden fixed inset-0 z-[90] bg-black/60"></div>
<div id="tour-popover" class="hidden fixed z-[100] w-72 rounded-xl border border-border-theme bg-bg-panel shadow-2xl p-4">
    <p id="tour-title" class="text-[12px] font-semibold text-white mb-1"></p>
    <p id="tour-text" class="text-[11px] text-text-muted leading-relaxed mb-3"></p>
    <div class="flex items-center justify-between">
        <span id="tour-step-count" class="text-[10px] text-text-subtle"></span>
        <div class="flex gap-1.5">
            <button onclick="endTour()" class="px-2.5 py-1 rounded-lg text-[10px] bg-bg-elevated border border-border-theme text-text-muted hover:text-text-body">Salir</button>
            <button id="tour-prev" onclick="tourStep(-1)" class="px-2.5 py-1 rounded-lg text-[10px] bg-bg-elevated border border-border-theme text-text-muted hover:text-text-body">Anterior</button>
            <button id="tour-next" onclick="tourStep(1)" class="px-2.5 py-1 rounded-lg text-[10px] font-medium bg-primary-600 text-white hover:bg-primary-500">Siguiente</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/support-chat.php'; ?>

<script>
// ── Guided tour (multi-página) ──
const TOUR_KEY = 'sl_tour_step';
const TOUR_STEPS = [
    { page: '/dashboard', sel: '.tour-sidebar-logo', title: 'Bienvenido a SecureLab', text: 'Tu plataforma integral de ciberseguridad y cumplimiento de la Ley 21.719. Este recorrido te mostrará cada sección del sistema.' },
    { page: '/dashboard', sel: '.tour-pending-ring', title: 'Tareas pendientes', text: 'Aquí ves tu porcentaje de cumplimiento global. Complétalo al 100% siguiendo las recomendaciones.' },
    { page: '/dashboard', sel: '.tour-nav-items', title: 'Navegación Principal', text: 'Menú de navegación. Desde aquí accedes a todas las secciones: agentes, alertas, bases de datos, compliance, ARCO y más.' },
    { page: '/dashboard', sel: '.tour-detail-kpi-grid', title: 'Dashboard — Indicadores clave', text: 'Resumen en tiempo real de agentes activos, bases de datos monitoreadas, cumplimiento normativo, brechas abiertas y usuarios vulnerables.' },
    { page: '/dashboard', sel: '.tour-detail-stats-grid', title: 'Dashboard — Actividad', text: 'Alertas activas, escaneos realizados, reportes generados y agentes online.' },
    { page: '/dashboard', sel: '.tour-detail-tabs', title: 'Dashboard — Vistas', text: 'Cambia entre el resumen de bases de datos, usuarios vulnerables y el estado de la Ley 21.719.' },
    { page: '/agents', sel: 'main', title: 'Agentes de Seguridad', text: 'Gestiona los agentes instalados en tus endpoints: descarga instaladores para Windows, Linux y macOS, monitorea su estado y ejecuta acciones remotas.' },
    { page: '/host-monitor', sel: 'main', title: 'Monitor de Host', text: 'Monitorea el estado de tus servidores y hosts en tiempo real: uptime, métricas de rendimiento e historial de eventos.' },
    { page: '/alerts', sel: 'main', title: 'Sistema de Alertas', text: 'Recibe notificaciones en tiempo real sobre eventos de seguridad críticos: intrusiones, brechas y seguimiento de resolución.' },
    { page: '/reports', sel: 'main', title: 'Reportes', text: 'Genera reportes detallados de cumplimiento y seguridad para auditorías. Exporta en PDF y consulta el historial de reportes generados.' },
    { page: '/databases', sel: 'main', title: 'Bases de Datos', text: 'Conecta y monitorea tus bases de datos (MySQL, PostgreSQL, SQL Server) con escaneo automático de datos personales y detección de datos sensibles.' },
    { page: '/db-logs', sel: 'main', title: 'Logs de Base de Datos', text: 'Registro cronológico de todos los eventos de tus bases de datos: escaneos, conexiones, cambios de esquema y accesos.' },
    { page: '/compliance', sel: 'main', title: 'Cumplimiento Normativo', text: 'Módulo completo de la Ley 21.719: consentimientos, inventario de datos, DPIAs, gestión de brechas y capacitación.' },
    { page: '/hardening', sel: 'main', title: 'Hardening de Seguridad', text: 'Análisis de endurecimiento para identificar configuraciones débiles: análisis de configuraciones, recomendaciones y score global.' },
    { page: '/tickets', sel: 'main', title: 'Soporte Técnico', text: 'Sistema de soporte integrado: crea tickets con prioridad, haz seguimiento de estado y consulta el historial de conversaciones.' },
    { page: '/arco', sel: 'main', title: 'Solicitudes ARCO', text: 'Gestiona las solicitudes de Acceso, Rectificación, Cancelación y Oposición de los titulares de datos, con seguimiento de plazos legales.' },
    { page: '/dpo', sel: 'main', title: 'Panel del DPO', text: 'Dashboard dedicado para el Delegado de Protección de Datos: vista consolidada, métricas de protección y reportes para autoridades.' },
    { page: '/settings', sel: 'main', title: 'Configuración', text: 'Administra tu cuenta y preferencias de seguridad: contraseña, autenticación 2FA y gestión de tus datos personales.' },
    { page: '/dashboard', sel: '.tour-theme-btn', title: 'Personalización Visual', text: 'Personaliza la apariencia con 12 temas predefinidos o crea tus propios colores desde el selector de temas del sidebar.' },
    { page: '/dashboard', sel: '.tour-start-btn', title: 'Tour guiado', text: 'Puedes volver a iniciar este tour cuando quieras desde aquí.' },
    { page: '/dashboard', sel: '.tour-notifications', title: 'Notificaciones', text: 'Campana de notificaciones con alertas de seguridad y novedades de la plataforma en tiempo real.' },
    { page: '/dashboard', sel: '.tour-logout-btn', title: 'Cerrar Sesión', text: 'Cierra tu sesión de forma segura cuando termines. Se recomienda en equipos compartidos.' },
    { page: '/dashboard', sel: '#sc-root', title: 'Asistente Virtual', text: 'El chat de soporte está disponible 24/7 para resolver tus dudas. Haz clic en el ícono de la esquina inferior derecha.' },
];
let tourIdx = 0;

function startTour() {
    tourIdx = 0;
    sessionStorage.setItem(TOUR_KEY, '0');
    goToTourStep();
}

function endTour() {
    sessionStorage.removeItem(TOUR_KEY);
    document.getElementById('tour-overlay').classList.add('hidden');
    document.getElementById('tour-popover').classList.add('hidden');
    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
}

function tourStep(dir) {
    tourIdx += dir;
    if (tourIdx < 0) tourIdx = 0;
    if (tourIdx >= TOUR_STEPS.length) { endTour(); return; }
    sessionStorage.setItem(TOUR_KEY, String(tourIdx));
    goToTourStep();
}

function goToTourStep() {
    const step = TOUR_STEPS[tourIdx];
    if (step.page && window.location.pathname !== step.page) {
        window.location.href = step.page;
        return;
    }
    document.getElementById('tour-overlay').classList.remove('hidden');
    showTourStep();
}

function showTourStep() {
    const step = TOUR_STEPS[tourIdx];
    let target = document.querySelector(step.sel);
    if (!target) target = document.querySelector('main') || document.body;

    document.querySelectorAll('.tour-highlight').forEach(el => el.classList.remove('tour-highlight'));
    target.classList.add('tour-highlight');
    try { target.scrollIntoView({ behavior: 'smooth', block: step.sel === 'main' ? 'start' : 'center' }); } catch (e) {}

    const pop = document.getElementById('tour-popover');
    document.getElementById('tour-title').textContent = step.title;
    document.getElementById('tour-text').textContent = step.text;
    document.getElementById('tour-step-count').textContent = (tourIdx + 1) + ' / ' + TOUR_STEPS.length;
    document.getElementById('tour-prev').style.visibility = tourIdx === 0 ? 'hidden' : 'visible';
    document.getElementById('tour-next').textContent = tourIdx === TOUR_STEPS.length - 1 ? 'Finalizar' : 'Siguiente';
    pop.classList.remove('hidden');

    const rect = target.getBoundingClientRect();
    let top = rect.bottom + 10;
    let left = rect.left;
    if (step.sel === 'main') { top = Math.round(window.innerHeight / 2 - 90); left = Math.round(window.innerWidth / 2 - 150); }
    if (top + 180 > window.innerHeight) top = Math.max(10, rect.top - 190);
    if (left + 300 > window.innerWidth) left = window.innerWidth - 310;
    pop.style.top = top + 'px';
    pop.style.left = Math.max(10, left) + 'px';
}

// Reanudar tour tras navegar de página
document.addEventListener('DOMContentLoaded', () => {
    const saved = sessionStorage.getItem(TOUR_KEY);
    if (saved !== null) {
        tourIdx = parseInt(saved, 10) || 0;
        if (tourIdx >= TOUR_STEPS.length) { endTour(); return; }
        const step = TOUR_STEPS[tourIdx];
        if (!step.page || window.location.pathname === step.page) {
            document.getElementById('tour-overlay').classList.remove('hidden');
            setTimeout(showTourStep, 300);
        }
    }
});
</script>
<style>
.tour-highlight { position: relative; z-index: 95; outline: 2px solid var(--primary-500, #3b82f6); outline-offset: 3px; border-radius: 10px; background-color: var(--bg-panel, #0b1220); }
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
