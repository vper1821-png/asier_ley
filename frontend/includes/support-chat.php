<!-- Support chat (paridad con SupportChat.jsx) -->
<div id="sc-root"></div>
<script>
(function () {
const ARTICLES = [
    { title: '¿Qué es la Ley 21.719?', query: '¿Qué es la Ley 21.719?', category: 'Ley 21.719', content: 'La Ley 21.719 es la nueva Ley de Protección de Datos Personales de Chile, publicada el 17 de agosto de 2023. Regula el tratamiento de datos personales y establece derechos para los titulares, obligaciones para los responsables y sanciones por incumplimiento.' },
    { title: 'Ámbito de aplicación', query: '¿A quiénes aplica la Ley 21.719?', category: 'Ley 21.719', content: 'Aplica a personas naturales o jurídicas, públicas o privadas, que traten datos personales en Chile. También alcanza extraterritorialmente a entidades extranjeras que traten datos de titulares ubicados en Chile.' },
    { title: 'Principios de la ley', query: '¿Cuáles son los principios de la Ley 21.719?', category: 'Ley 21.719', content: 'Los principios son: licitud, lealtad, finalidad, proporcionalidad, calidad, responsabilidad proactiva, seguridad, información al titular y respeto a los derechos ARCO.' },
    { title: 'Diferencias con la Ley 19.628', query: '¿Cuáles son las diferencias con la Ley 19.628?', category: 'Ley 21.719', content: 'Crea la APDP, exige consentimiento explícito, sanciones de hasta 20.000 UTM, designación de DPD, notificación de brechas en 72 horas, portabilidad y responsabilidad proactiva.' },
    { title: 'Sanciones por incumplimiento', query: '¿Cuáles son las sanciones por incumplir la ley?', category: 'Ley 21.719', content: 'Las sanciones incluyen advertencias, multas de hasta 10.000 UTM, inhabilitación temporal y cierre del sitio web en casos graves de incumplimiento reiterado.' },
    { title: 'Vacancia de la ley', query: '¿Cuál es la vacancia de la Ley 21.719?', category: 'Ley 21.719', content: 'La ley entró en vigor de forma gradual. Muchas obligaciones tienen un plazo de vacancia de 24 meses desde su publicación, permitiendo a las organizaciones adaptarse progresivamente.' },
    { title: 'Agencia APDP', query: '¿Qué es la APDP?', category: 'Ley 21.719', content: 'La Agencia de Protección de Datos Personales (APDP) es el organismo fiscalizador encargado de velar por el cumplimiento de la normativa, resolver reclamos e imponer sanciones.' },
    { title: 'Registro ante la APDP', query: '¿Debo registrarme en la APDP?', category: 'Ley 21.719', content: 'Sí, los responsables de tratamiento deben registrarse ante la APDP, indicando datos tratados, finalidades, categorías de titulares, medidas de seguridad y DPD designado.' },
    { title: 'Datos personales según la ley', query: '¿Qué son los datos personales?', category: 'Protección de datos', content: 'Son cualquier información concerniente a una persona natural identificada o identificable. Incluye nombre, RUT, correo, teléfono, dirección, datos biométricos, financieros, etc.' },
    { title: 'Datos sensibles', query: '¿Qué son los datos sensibles?', category: 'Protección de datos', content: 'Son datos que requieren mayor protección por su naturaleza: origen racial, opiniones políticas, creencias religiosas, datos de salud, biométricos, genéticos, vida sexual y datos de menores.' },
    { title: 'Obligaciones del responsable', query: '¿Qué obligaciones tiene mi empresa?', category: 'Protección de datos', content: 'Debes realizar inventario de datos, obtener consentimiento, designar DPD, registrarte en APDP, implementar medidas de seguridad, atender derechos ARCO y notificar brechas.' },
    { title: 'Consentimiento informado', query: '¿Cómo debe ser el consentimiento?', category: 'Protección de datos', content: 'Debe ser libre, específico, informado, inequívoco y revocable. Para datos sensibles debe ser explícito y por escrito. No puede estar en cláusulas genéricas.' },
    { title: 'Revocación del consentimiento', query: '¿Se puede revocar el consentimiento?', category: 'Protección de datos', content: 'Sí, el titular puede revocar su consentimiento en cualquier momento sin expresión de causa. La revocación debe ser tan fácil como otorgarlo.' },
    { title: 'Encargados de tratamiento', query: '¿Qué es un encargado de tratamiento?', category: 'Protección de datos', content: 'Es quien trata datos personales por cuenta del responsable. Debe existir un contrato que defina el tratamiento, confidencialidad, seguridad y devolución o eliminación de datos.' },
    { title: 'Transferencia internacional', query: '¿Puedo transferir datos fuera de Chile?', category: 'Protección de datos', content: 'Solo a países con nivel adecuado de protección, o mediante consentimiento explícito, ejecución de contrato, cláusulas contractuales tipo o normas corporativas vinculantes aprobadas.' },
    { title: 'Evaluación de impacto', query: '¿Qué es una evaluación de impacto?', category: 'Protección de datos', content: 'Es un análisis de riesgos que debe realizarse cuando el tratamiento pueda afectar significativamente los derechos de los titulares, especialmente con datos sensibles o nuevas tecnologías.' },
    { title: 'Plan de cumplimiento', query: '¿Cómo cumplir con la Ley 21.719?', category: 'Cumplimiento normativo', content: 'El plan incluye: inventario de datos, mapeo de flujos, actualización de consentimientos, designación de DPD, registro en APDP, medidas de seguridad, atención ARCO y plan de respuesta a brechas.' },
    { title: 'Inventario de datos', query: '¿Qué debe incluir el inventario de datos?', category: 'Cumplimiento normativo', content: 'Debe incluir categorías de datos, finalidades, base legal, titulares, origen, transferencias, plazos de conservación, medidas de seguridad y evaluación de riesgos.' },
    { title: 'Registro de actividades', query: '¿Debo llevar un registro de actividades?', category: 'Cumplimiento normativo', content: 'Sí, debes documentar las actividades de tratamiento: qué datos tratas, con qué finalidad, quién tiene acceso, cómo se protegen y durante cuánto tiempo se conservan.' },
    { title: 'Auditoría de cumplimiento', query: '¿Cómo auditar el cumplimiento?', category: 'Cumplimiento normativo', content: 'Realiza revisiones periódicas del inventario, consentimientos, solicitudes ARCO, medidas de seguridad, contratos con encargados y registros de brechas. Documenta hallazgos y planes de mejora.' },
    { title: 'Reportes de cumplimiento', query: '¿Cómo generar reportes de cumplimiento?', category: 'Cumplimiento normativo', content: 'La plataforma permite generar reportes PDF con el estado de cumplimiento, inventario, brechas, consentimientos y medidas de seguridad implementadas.' },
    { title: 'Derechos ARCO', query: '¿Qué son los derechos ARCO?', category: 'Cumplimiento normativo', content: 'Acceso, Rectificación, Cancelación y Oposición. La Ley 21.719 agrega la Portabilidad. El responsable debe responder en 10 días hábiles, prorrogables por 10 más.' },
    { title: 'Designación del DPD', query: '¿Cómo designar un DPD?', category: 'Cumplimiento normativo', content: 'Debes designar a una persona natural o jurídica con conocimientos especializados, sin conflicto de intereses. Puede ser interno o externo y debe registrarse ante la APDP.' },
    { title: 'Contratos con encargados', query: '¿Qué cláusulas deben tener los contratos?', category: 'Cumplimiento normativo', content: 'Deben definir el objeto, duración, naturaleza de datos, obligaciones del encargado, subcontratación, devolución/eliminación, confidencialidad y responsabilidades ante incumplimiento.' },
    { title: 'Conectar mi base de datos', query: '¿Cómo conectar mi base de datos?', category: 'Uso de la plataforma', content: 'Ve a la sección Bases de Datos, haz clic en "Agregar BD", completa los datos de conexión (host, puerto, usuario, contraseña) y ejecuta el escaneo de seguridad.' },
    { title: 'Servicios de la plataforma', query: '¿Qué servicios ofrece esta plataforma?', category: 'Uso de la plataforma', content: 'La plataforma ofrece escaneo de seguridad, gestión de bases de datos, cumplimiento normativo, gestión de consentimientos, reporte de brechas, derechos ARCO y reportes.' },
    { title: 'SecureLab Agent', query: '¿Qué es el SecureLab Agent?', category: 'Uso de la plataforma', content: 'Es un agente endpoint que se instala en tus servidores para monitoreo continuo, escaneo local de bases de datos y comunicación cifrada con la plataforma vía WebSocket.' },
    { title: 'Instalar el agente', query: '¿Cómo instalar el agente SecureLab?', category: 'Uso de la plataforma', content: 'Descarga el agente para tu sistema operativo, ejecuta `securelab-agent install` y configura el token de conexión que aparece en la plataforma. El agente se ejecutará como servicio.' },
    { title: 'Gestionar consentimientos', query: '¿Cómo gestionar consentimientos?', category: 'Uso de la plataforma', content: 'Ve a la sección Consentimientos, crea un nuevo consentimiento definiendo finalidad, datos involucrados y versión. Puedes registrar aceptaciones y revocaciones de los titulares.' },
    { title: 'Reportar una brecha', query: '¿Cómo reportar una brecha?', category: 'Uso de la plataforma', content: 'En la sección Brechas crea un nuevo reporte indicando fecha de detección, datos afectados, descripción, medidas correctivas y titulares notificados. La plataforma te ayuda a cumplir el plazo de 72 horas.' },
    { title: 'Generar reportes', query: '¿Cómo generar reportes?', category: 'Uso de la plataforma', content: 'Ve a Reportes, selecciona el tipo (cumplimiento, escaneo, inventario) y el período. La plataforma genera un PDF descargable con los datos y estado actual.' },
    { title: 'Cómo reportar una brecha', query: '¿Cómo reportar una brecha de seguridad?', category: 'Brechas de seguridad', content: 'Para reportar una brecha de seguridad debes identificar el incidente, evaluar el riesgo, notificar a la APDP dentro de las 72 horas en casos graves, y documentar las medidas correctivas.' },
    { title: 'Plazo de notificación', query: '¿En cuánto tiempo debo notificar una brecha?', category: 'Brechas de seguridad', content: 'La APDP debe ser notificada dentro de las 72 horas de conocido el incidente. Los titulares afectados deben ser informados si existe alto riesgo para sus derechos.' },
    { title: 'Contención del incidente', query: '¿Cómo contener una brecha?', category: 'Brechas de seguridad', content: 'Aisla los sistemas afectados, revoca credenciales comprometidas, aplica parches de seguridad, detiene el acceso no autorizado y preserva evidencias para análisis forense.' },
    { title: 'Evaluación de riesgo', query: '¿Cómo evaluar el riesgo de una brecha?', category: 'Brechas de seguridad', content: 'Analiza qué datos fueron comprometidos, número de titulares afectados, sensibilidad de la información, probabilidad de daño y si existe riesgo de discriminación, daño económico o moral.' },
    { title: 'Notificación a titulares', query: '¿Cómo notificar a los titulares afectados?', category: 'Brechas de seguridad', content: 'La notificación debe ser clara, en lenguaje sencillo, describir la brecha, datos afectados, medidas adoptadas y recomendaciones. Debe realizarse por canales directos cuando sea posible.' },
    { title: 'Documentación de brechas', query: '¿Qué documentar de una brecha?', category: 'Brechas de seguridad', content: 'Registra fecha de detección, descripción del incidente, datos afectados, titulares involucrados, medidas de contención, notificaciones realizadas, lecciones aprendidas y acciones correctivas.' },
    { title: 'Plan de respuesta', query: '¿Cómo crear un plan de respuesta a incidentes?', category: 'Brechas de seguridad', content: 'Define roles, procedimientos de detección, contención, erradicación, recuperación, comunicación y notificación. Realiza simulacros periódicos para mantenerlo actualizado.' },
];

const COLLECTIONS = [
    { title: 'Ley 21.719', count: '8 artículos' },
    { title: 'Protección de datos', count: '8 artículos' },
    { title: 'Cumplimiento normativo', count: '8 artículos' },
    { title: 'Uso de la plataforma', count: '8 artículos' },
    { title: 'Brechas de seguridad', count: '7 artículos' },
];

const DEFAULT_BUTTONS = [
    { label: 'Ley 21.719', query: '¿Qué es la Ley 21.719?' },
    { label: 'Datos personales', query: '¿Qué son los datos personales?' },
    { label: 'Brechas', query: '¿Cómo reportar una brecha de seguridad?' },
    { label: 'Conectar BD', query: '¿Cómo conectar mi base de datos?' },
];

const WELCOME_MSG = { role: 'bot', text: '¡Hola! Soy el **Asistente Virtual de Invisia/SecureLab**.\n\nPuedo ayudarte con la **Ley 21.719** de Protección de Datos Personales de Chile y los servicios de la plataforma.' };
const OUT_OF_SCOPE_MSG = 'Lo siento, solo puedo ayudarte con temas relacionados a la **Ley 21.719 de Protección de Datos Personales de Chile** y los **servicios de la plataforma Invisia/SecureLab**.';

// ── State ──
const S = { open: false, tab: 'home', minimized: false, expanded: false, activeCategory: null, selectedArticle: null, messages: [WELCOME_MSG], loading: false, search: '' };
const root = document.getElementById('sc-root');
const PANEL_BG = 'background-color: var(--bg-base, #0b0b0f);';
const CARD_BG = 'background-color: var(--surface-900, #13131a);';

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

function md(text) {
    if (!text) return '';
    text = String(text).replace(/\\n/g, '\n');
    let html = esc(text);
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold text-white">$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    html = html.replace(/`([^`]+)`/g, '<code class="bg-white/10 px-1 rounded text-[11px] break-all whitespace-pre-wrap">$1</code>');
    html = html.replace(/^[-*] (.+)$/gm, '<div class="flex gap-2 items-start"><span class="text-blue-400 mt-0.5">•</span><span>$1</span></div>');
    html = html.replace(/\n\n/g, '<br/><br/>');
    html = html.replace(/\n/g, '<br/>');
    return html;
}

function icoChat() { return '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>'; }
function icoX() { return '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>'; }
function icoChevron() { return '<svg class="w-4 h-4 text-text-subtle flex-shrink-0" fill="currentColor" viewBox="0 0 16 16"><path d="M5.428 4.709A.85.85 0 0 1 6.65 3.9L10.352 7.6a.85.85 0 0 1 0 1.2L6.65 12.503a.85.85 0 1 1-1.2-1.2L8.55 8.2 5.45 5.1a.85.85 0 0 1-.022-1.19z"/></svg>'; }
function icoSearch() { return '<svg class="absolute left-3.5 top-3 w-4 h-4 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8" stroke-width="2"/><path d="m21 21-4.35-4.35" stroke-width="2" stroke-linecap="round"/></svg>'; }
function searchBox(ph) { return '<div class="relative mb-4">' + icoSearch() + '<input data-sc="search" value="' + esc(S.search) + '" placeholder="' + ph + '" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-border-theme text-sm text-white placeholder-text-subtle focus:outline-none focus:border-blue-600/50" style="' + CARD_BG + '"></div>'; }

function filteredArticles() { const q = S.search.toLowerCase(); return ARTICLES.filter(a => a.title.toLowerCase().includes(q) || a.category.toLowerCase().includes(q)); }

// ── Views ──
function renderLauncher() {
    return '<button data-sc="open" class="fixed z-[200] bottom-4 right-4 md:bottom-6 md:right-6 w-14 h-14 md:w-16 md:h-16 rounded-full bg-blue-800 hover:bg-blue-700 text-white shadow-2xl shadow-blue-800/40 transition-all hover:scale-105 flex items-center justify-center" title="Abrir asistente">' + icoChat() + '</button>';
}

function renderMinimized() {
    return '<div data-sc="restore" class="fixed z-[200] bottom-4 right-4 md:bottom-6 md:right-6 px-5 py-3 rounded-full border border-border-theme shadow-xl cursor-pointer flex items-center gap-3" style="' + CARD_BG + '">' +
        '<div class="w-8 h-8 rounded-full bg-blue-800 flex items-center justify-center text-white text-[10px] font-bold">IA</div>' +
        '<span class="text-sm font-medium text-white">Asistente Invisia</span>' +
        '<button data-sc="close" class="p-1.5 rounded-lg text-text-muted hover:text-white hover:bg-red-500/20 transition-colors">' + icoX() + '</button></div>';
}

function renderHome() {
    const arts = filteredArticles().slice(0, 4);
    return '<div class="flex-1 overflow-y-auto chat-scroll">' +
        '<div class="relative h-[180px] flex flex-col items-center justify-center text-center p-6 overflow-hidden" style="' + PANEL_BG + '">' +
        '<div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle at 30% 30%, rgba(99,102,241,0.5) 1px, transparent 1px); background-size: 24px 24px;"></div>' +
        '<div class="relative flex -space-x-3 mb-4">' +
        '<div class="w-12 h-12 rounded-full bg-blue-800 border-2 border-bg-base flex items-center justify-center text-white text-[10px] font-bold">IA</div>' +
        '<div class="w-12 h-12 rounded-full bg-surface-700 border-2 border-bg-base flex items-center justify-center text-white text-[10px] font-bold" style="background-color:#2a2a35">DP</div>' +
        '<div class="w-12 h-12 rounded-full bg-blue-900 border-2 border-bg-base flex items-center justify-center text-white text-[10px] font-bold">LG</div></div>' +
        '<h2 class="relative text-2xl font-bold text-white mb-1">Hola 👋</h2>' +
        '<p class="relative text-text-muted text-sm">¿En qué podemos ayudarte?</p></div>' +
        '<div class="p-5 -mt-6 relative z-10">' +
        '<button data-sc="tab-messages" class="w-full py-3.5 px-5 rounded-xl shadow-lg shadow-black/30 border border-border-theme flex items-center justify-between group hover:border-blue-600/50 transition-colors" style="' + CARD_BG + '">' +
        '<span class="text-white font-semibold text-sm">Envíanos un mensaje</span>' +
        '<div class="w-8 h-8 rounded-full bg-blue-800/25 text-blue-400 flex items-center justify-center group-hover:bg-blue-800 group-hover:text-white transition-colors">' +
        '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 16 16"><path d="M4.394 14.7 13.75 9.3c1-.577 1-2.02 0-2.598L4.394 1.3A1.5 1.5 0 0 0 2.144 2.6v3.438l4.059 1.088c.494.132.494.833 0 .966l-4.06 1.087v4.224a1.5 1.5 0 0 0 2.25 1.299"/></svg></div></button></div>' +
        '<div class="px-5 pb-5">' + searchBox('Buscar ayuda') +
        '<div class="rounded-2xl border border-border-theme overflow-hidden shadow-sm" style="' + CARD_BG + '">' +
        arts.map((a, i) => '<button data-sc="ask" data-q="' + esc(a.query) + '" class="w-full flex items-center justify-between px-4 py-3.5 text-left ' + (i !== arts.length - 1 ? 'border-b border-border-theme' : '') + ' hover:bg-white/5 transition-colors">' +
            '<span class="text-sm text-text-body font-medium">' + esc(a.title) + '</span>' + icoChevron() + '</button>').join('') +
        '</div></div></div>';
}

function renderHelp() {
    const q = S.search.toLowerCase();
    const cols = COLLECTIONS.filter(c => c.title.toLowerCase().includes(q));
    return '<div class="flex-1 overflow-y-auto p-5 chat-scroll">' + searchBox('Buscar en ayuda') +
        '<h3 class="text-xs font-semibold text-text-subtle uppercase tracking-wide mb-3">Categorías</h3>' +
        '<div class="rounded-2xl border border-border-theme overflow-hidden shadow-sm" style="' + CARD_BG + '">' +
        cols.map((c, i) => '<button data-sc="category" data-cat="' + esc(c.title) + '" class="w-full flex items-center justify-between px-4 py-3.5 text-left ' + (i !== cols.length - 1 ? 'border-b border-border-theme' : '') + ' hover:bg-white/5 transition-colors">' +
            '<div class="text-left"><p class="text-sm text-text-body font-medium">' + esc(c.title) + '</p><p class="text-[11px] text-text-subtle mt-0.5">' + esc(c.count) + '</p></div>' + icoChevron() + '</button>').join('') +
        '</div></div>';
}

function renderMessages() {
    let inner = S.messages.map((m, i) => {
        let out = '<div><div class="flex ' + (m.role === 'user' ? 'justify-end' : 'justify-start') + '">' +
            '<div class="max-w-[84%] min-w-0 break-words overflow-hidden px-4 py-2.5 text-[13px] leading-relaxed ' +
            (m.role === 'user' ? 'bg-blue-800 text-white rounded-2xl rounded-br-md' : 'border border-border-theme text-text-body rounded-2xl rounded-bl-md shadow-sm') + '"' +
            (m.role === 'user' ? '' : ' style="' + CARD_BG + '"') + '>' +
            (m.role === 'bot' ? '<div class="assistant-msg">' + md(m.text) + '</div>' : esc(m.text)) + '</div></div>';
        if (m.role === 'bot' && m.quick_actions && i === S.messages.length - 1 && !S.loading) {
            out += '<div class="flex flex-wrap gap-2 mt-2">' + m.quick_actions.map(qa =>
                '<button data-sc="ask" data-q="' + esc(qa.query || '') + '" class="text-[11px] px-3 py-1.5 rounded-full border border-border-theme text-text-muted hover:text-white hover:border-blue-600/50 hover:bg-blue-800/20 transition-colors" style="' + CARD_BG + '">' + esc(qa.label) + '</button>').join('') + '</div>';
        }
        return out + '</div>';
    }).join('');
    if (S.loading) {
        inner += '<div class="flex justify-start"><div class="border border-border-theme rounded-2xl rounded-bl-md px-4 py-3 shadow-sm" style="' + CARD_BG + '"><div class="flex gap-1.5">' +
            '<span class="w-2 h-2 rounded-full bg-blue-400 animate-bounce"></span>' +
            '<span class="w-2 h-2 rounded-full bg-blue-400 animate-bounce" style="animation-delay:150ms"></span>' +
            '<span class="w-2 h-2 rounded-full bg-blue-400 animate-bounce" style="animation-delay:300ms"></span></div></div></div>';
    }
    return '<div data-sc="msglist" class="flex-1 overflow-y-auto p-5 space-y-4 chat-scroll">' + inner + '</div>' +
        '<div class="px-4 py-3 border-t border-border-theme flex-shrink-0" style="' + CARD_BG + '">' +
        '<div class="flex gap-2 items-end p-1.5 rounded-2xl border border-border-theme focus-within:border-blue-600/50 transition-colors" style="' + PANEL_BG + '">' +
        '<input data-sc="input" placeholder="Escribe un mensaje..." ' + (S.loading ? 'disabled' : '') + ' class="flex-1 bg-transparent border-0 text-[13px] text-white px-3 py-2.5 focus:outline-none placeholder-text-subtle disabled:opacity-50">' +
        '<button data-sc="send" ' + (S.loading ? 'disabled' : '') + ' class="px-4 py-2 rounded-xl text-white text-[12px] font-semibold bg-blue-800 hover:bg-blue-700 disabled:opacity-40 transition-colors">Enviar</button></div></div>';
}

function renderCategory() {
    const arts = ARTICLES.filter(a => a.category === S.activeCategory);
    const dims = S.expanded ? 'md:w-[520px] md:h-[680px]' : 'md:w-[380px] md:h-[600px]';
    const frame = 'fixed z-[210] bottom-0 right-0 left-0 top-0 rounded-none md:bottom-6 md:right-6 md:left-auto md:top-auto md:rounded-3xl border border-border-theme shadow-2xl shadow-black/50 flex flex-col overflow-hidden transition-all duration-300 w-full h-full ' + dims;
    const expandIco = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' + (S.expanded ? 'M4 14h6v6M20 10h-6V4M14 10l7-7M4 20l7-7' : 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4') + '"/></svg>';

    if (S.selectedArticle) {
        const a = S.selectedArticle;
        const rt = Math.max(1, Math.round((a.content || '').split(' ').length / 180));
        return '<div class="' + frame + '" style="' + PANEL_BG + '">' +
            '<div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0" style="' + CARD_BG + '">' +
            '<button data-sc="article-back" class="flex items-center gap-2 text-text-muted hover:text-white transition-colors text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>Volver</button>' +
            '<div class="flex items-center gap-1">' +
            '<button data-sc="expand" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-white/10 transition-colors">' + expandIco + '</button>' +
            '<button data-sc="cat-close" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-red-500/20 transition-colors">' + icoX() + '</button></div></div>' +
            '<div class="flex-1 overflow-y-auto chat-scroll">' +
            '<div class="relative h-[120px] flex items-end p-5 overflow-hidden" style="background: linear-gradient(135deg, rgba(30,64,175,0.35) 0%, rgba(30,58,138,0.25) 100%)">' +
            '<div class="relative"><span class="inline-block text-[10px] font-semibold uppercase tracking-wider text-blue-300 bg-blue-800/25 border border-blue-600/30 px-2.5 py-1 rounded-full mb-2">' + esc(a.category) + '</span>' +
            '<h2 class="text-lg font-bold text-white leading-tight">' + esc(a.title) + '</h2></div></div>' +
            '<div class="p-5">' +
            '<div class="flex items-center gap-3 text-[11px] text-text-subtle mb-4 pb-4 border-b border-border-theme">' +
            '<span class="flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 6v6l4 2"/></svg>' + rt + ' min de lectura</span>' +
            '<span class="flex items-center gap-1.5">Guía Invisia</span></div>' +
            '<p class="text-[13px] text-text-body leading-relaxed mb-5">' + esc(a.content) + '</p>' +
            '<div class="p-4 rounded-2xl bg-blue-800/15 border border-blue-600/20 mb-5">' +
            '<p class="text-[11px] font-semibold text-blue-300 uppercase tracking-wide mb-1.5">💡 Punto clave</p>' +
            '<p class="text-[12px] text-text-body leading-relaxed">Recuerda que el cumplimiento de la <strong class="text-white">Ley 21.719</strong> es progresivo: documenta cada paso que implementes y apóyate en las herramientas de la plataforma para automatizar el proceso.</p></div>' +
            '<div class="flex flex-col gap-2">' +
            '<button data-sc="article-ask" data-q="' + esc(a.query) + '" class="w-full py-2.5 rounded-xl bg-blue-800 hover:bg-blue-700 text-white text-[12px] font-semibold transition-colors">Preguntar al asistente sobre esto</button>' +
            '<button data-sc="article-back" class="w-full py-2.5 rounded-xl border border-border-theme text-text-muted hover:text-white text-[12px] font-medium transition-colors" style="' + CARD_BG + '">Ver más artículos</button>' +
            '</div></div></div></div>';
    }

    return '<div class="' + frame + '" style="' + PANEL_BG + '">' +
        '<div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0" style="' + CARD_BG + '">' +
        '<div><p class="text-[15px] font-bold text-white">' + esc(S.activeCategory) + '</p><p class="text-[11px] text-text-muted">Artículos de ayuda</p></div>' +
        '<div class="flex items-center gap-1">' +
        '<button data-sc="expand" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-white/10 transition-colors">' + expandIco + '</button>' +
        '<button data-sc="cat-close" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-red-500/20 transition-colors">' + icoX() + '</button></div></div>' +
        '<div class="flex-1 overflow-y-auto p-5 space-y-3 chat-scroll">' +
        arts.map((a, i) => '<div class="p-4 rounded-2xl border border-border-theme" style="' + CARD_BG + '">' +
            '<h4 class="text-sm font-semibold text-white mb-2">' + esc(a.title) + '</h4>' +
            '<p class="text-[12px] text-text-body leading-relaxed mb-3">' + esc(a.content.length > 140 ? a.content.slice(0, 140) + '…' : a.content) + '</p>' +
            '<div class="flex gap-2">' +
            '<button data-sc="article-open" data-idx="' + ARTICLES.indexOf(a) + '" class="text-[11px] px-3 py-1.5 rounded-lg bg-blue-800 hover:bg-blue-700 text-white transition-colors">Ver blog</button>' +
            '<button data-sc="cat-ask" data-q="' + esc(a.query) + '" class="text-[11px] px-3 py-1.5 rounded-lg bg-blue-800/20 border border-blue-600/30 text-blue-300 hover:bg-blue-800/30 transition-colors">Preguntar al asistente</button>' +
            '</div></div>').join('') +
        '</div></div>';
}

function tabBtn(id, label, iconSvg) {
    const active = S.tab === id;
    return '<button data-sc="tab-' + id + '" class="flex flex-col items-center gap-1 px-4 py-2 rounded-xl transition-colors ' + (active ? 'text-blue-400 bg-blue-800/15' : 'text-text-subtle hover:text-white hover:bg-white/5') + '">' + iconSvg + '<span class="text-[10px] font-medium">' + label + '</span></button>';
}

function renderPanel() {
    let body = '';
    if (S.tab === 'home') body = renderHome();
    else if (S.tab === 'help') body = renderHelp();
    else body = renderMessages();

    const homeIco = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>';
    const helpIco = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/></svg>';
    const msgIco = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>';
    const minIco = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>';

    return (S.activeCategory ? renderCategory() : '') +
        '<div data-sc="panel" class="fixed z-[200] bottom-0 right-0 left-0 top-0 w-full h-full rounded-none md:bottom-6 md:right-6 md:left-auto md:top-auto md:w-[380px] md:h-[600px] md:rounded-3xl border border-border-theme shadow-2xl shadow-black/40 flex flex-col overflow-hidden transition-all" style="' + PANEL_BG + '">' +
        '<div class="flex items-center justify-between px-5 py-4 border-b border-border-theme flex-shrink-0" style="' + CARD_BG + '">' +
        '<div><p class="text-[15px] font-bold text-white tracking-tight">Asistente Invisia</p>' +
        '<div class="flex items-center gap-1.5 mt-0.5"><span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span><p class="text-[11px] text-text-muted">En línea</p></div></div>' +
        '<div class="flex items-center gap-1">' +
        '<button data-sc="minimize" title="Minimizar" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-white/10 transition-colors">' + minIco + '</button>' +
        '<button data-sc="close" title="Cerrar" class="p-2 rounded-lg text-text-muted hover:text-white hover:bg-red-500/20 transition-colors">' + icoX() + '</button></div></div>' +
        '<div class="flex-1 flex flex-col min-h-0">' + body + '</div>' +
        '<div class="flex items-center justify-around px-2 py-2 border-t border-border-theme flex-shrink-0" style="' + CARD_BG + '">' +
        tabBtn('home', 'Inicio', homeIco) + tabBtn('help', 'Ayuda', helpIco) + tabBtn('messages', 'Mensajes', msgIco) +
        '</div></div>';
}

function render() {
    if (!S.open) { root.innerHTML = renderLauncher(); return; }
    if (S.minimized) { root.innerHTML = renderMinimized(); return; }
    root.innerHTML = renderPanel();
    const list = root.querySelector('[data-sc="msglist"]');
    if (list) list.scrollTop = list.scrollHeight;
    const inp = root.querySelector('[data-sc="input"]');
    if (inp && !S.loading) inp.focus();
}

// ── API (client-side rule-based, no Ollama) ──
function matchArticle(query) {
    const q = query.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    let best = null, bestScore = 0;
    for (const a of ARTICLES) {
        const title = a.title.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const qwords = a.query.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').split(/\s+/);
        let score = 0;
        for (const w of qwords) { if (q.includes(w)) score += w.length; }
        const twords = q.split(/\s+/);
        for (const tw of twords) { if (tw.length > 2 && title.includes(tw)) score += tw.length; }
        if (q.includes('arco')) score += 5;
        if (q.includes('brecha') || q.includes('incidente')) score += 3;
        if (q.includes('consentimiento')) score += 3;
        if (q.includes('dpo') || q.includes('delegado')) score += 3;
        if (q.includes('apdp') || q.includes('agencia')) score += 3;
        if (q.includes('sancion') || q.includes('multa')) score += 3;
        if (q.includes('datos') && q.includes('sensible')) score += 4;
        if (q.includes('portabilidad')) score += 4;
        if (q.includes('revoc')) score += 3;
        if (q.includes('encargado')) score += 3;
        if (q.includes('transferencia') || q.includes('internacional')) score += 3;
        if (q.includes('evaluacion') || q.includes('impacto')) score += 3;
        if (q.includes('registro') && q.includes('actividad')) score += 3;
        if (q.includes('inventario')) score += 3;
        if (q.includes('contrat')) score += 2;
        if (q.includes('agente') || q.includes('install')) score += 2;
        if (q.includes('base') && q.includes('datos') && !q.includes('personales')) score += 2;
        if (q.includes('escaneo') || q.includes('scan')) score += 2;
        if (q.includes('consent')) score += 2;
        if (q.includes('reporte') || q.includes('report')) score += 2;
        if (q.includes('plan') && q.includes('cumpl')) score += 3;
        if (q.includes('plazo') || q.includes('notific')) score += 2;
        if (q.includes('contencion') || q.includes('contener')) score += 2;
        if (q.includes('notificar') && q.includes('titular')) score += 2;
        if (q.includes('document')) score += 1;
        if (q.includes('auditor')) score += 2;
        if (q.includes('diferencia') || q.includes('ley') && q.includes('19628')) score += 4;
        if (q.includes('vacancia') || q.includes('vigencia')) score += 3;
        if (q.includes('registro') && q.includes('apdp')) score += 3;
        if (q.includes('principio')) score += 3;
        if (q.includes('obligacion')) score += 2;
        if (q.includes('como') && q.includes('cumplir')) score += 3;
        if (score > bestScore) { bestScore = score; best = a; }
    }
    return bestScore >= 3 ? best : null;
}

function getGreeting() {
    const greetings = ['¡Hola!', 'Hola, ¿en qué puedo ayudarte?', '¡Bienvenido!'];
    return greetings[Math.floor(Math.random() * greetings.length)];
}

function getFollowUp(article) {
    const related = ARTICLES.filter(a => a.category === article.category && a !== article).slice(0, 3);
    if (!related.length) return DEFAULT_BUTTONS;
    return related.map(a => ({ label: a.title.length > 25 ? a.title.slice(0, 25) + '…' : a.title, query: a.query }));
}

async function send(text) {
    text = (text || '').trim();
    if (!text || S.loading) return;
    S.messages.push({ role: 'user', text });
    S.loading = true;
    S.tab = 'messages';
    S.activeCategory = null;
    S.selectedArticle = null;
    render();
    const article = matchArticle(text);
    if (article) {
        const quick = getFollowUp(article);
        S.messages.push({ role: 'bot', text: article.content, quick_actions: quick });
    } else {
        const qLower = text.toLowerCase();
        let fallbackText = '';
        if (qLower.includes('hola') || qLower.includes('buenos') || qLower.includes('buenas')) {
            fallbackText = getGreeting() + ' Soy el asistente de Invisia/SecureLab. Puedo ayudarte con la **Ley 21.719 de Protección de Datos** y el uso de la plataforma.';
        } else if (qLower.includes('gracias') || qLower.includes('thank')) {
            fallbackText = '¡De nada! Si tienes otra pregunta sobre la Ley 21.719 o la plataforma, no dudes en preguntar.';
        } else {
            fallbackText = OUT_OF_SCOPE_MSG;
        }
        S.messages.push({ role: 'bot', text: fallbackText, quick_actions: DEFAULT_BUTTONS });
    }
    S.loading = false;
    render();
}

// ── Events (delegated) ──
root.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-sc]');
    if (!btn) return;
    const action = btn.getAttribute('data-sc');
    switch (action) {
        case 'open': S.open = true; S.tab = 'home'; render(); break;
        case 'close': e.stopPropagation(); S.open = false; S.tab = 'home'; S.minimized = false; S.expanded = false; S.activeCategory = null; S.selectedArticle = null; render(); break;
        case 'restore': S.minimized = false; render(); break;
        case 'minimize': S.minimized = true; render(); break;
        case 'expand': S.expanded = !S.expanded; render(); break;
        case 'tab-home': S.tab = 'home'; S.search = ''; render(); break;
        case 'tab-help': S.tab = 'help'; S.search = ''; render(); break;
        case 'tab-messages': S.tab = 'messages'; render(); break;
        case 'category': S.activeCategory = btn.getAttribute('data-cat'); S.expanded = true; S.selectedArticle = null; render(); break;
        case 'cat-close': S.activeCategory = null; S.selectedArticle = null; S.expanded = false; render(); break;
        case 'article-open': S.selectedArticle = ARTICLES[parseInt(btn.getAttribute('data-idx'), 10)] || null; render(); break;
        case 'article-back': S.selectedArticle = null; render(); break;
        case 'article-ask': case 'cat-ask': case 'ask': send(btn.getAttribute('data-q')); break;
        case 'send': { const inp = root.querySelector('[data-sc="input"]'); if (inp) send(inp.value); break; }
    }
});

root.addEventListener('input', function (e) {
    const el = e.target.closest('[data-sc="search"]');
    if (!el) return;
    S.search = el.value;
    const pos = el.selectionStart;
    render();
    const again = root.querySelector('[data-sc="search"]');
    if (again) { again.focus(); again.setSelectionRange(pos, pos); }
});

root.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && e.target.closest('[data-sc="input"]')) {
        e.preventDefault();
        send(e.target.value);
    }
});

render();
})();
</script>
