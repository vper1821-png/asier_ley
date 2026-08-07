<?php
$pageTitle = 'SecureLab - Cumplimiento Ley 21.719';
require_once __DIR__ . '/../includes/header.php';

// Fetch public alerts
$alerts = [];
$alertsRes = api_post_form('/api/admin/alerts/public');
if (is_array($alertsRes)) {
    $alerts = array_filter($alertsRes, fn($a) => empty($a['showOnLanding']) || $a['showOnLanding'] !== false);
}

function getIcon($name) {
    $icons = [
        'shield' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
        'check' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
        'lock' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
        'search' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
        'globe' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>',
        'arrowRight' => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>',
        'users' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
        'document' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
        'clock' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
        'star' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>',
        'menu' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>',
        'xmark' => '<svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
        'chevronDown' => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
        'alert' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$sections = [
    ['id' => 'inicio', 'label' => 'Inicio'],
    ['id' => 'ley', 'label' => 'La Ley'],
    ['id' => 'servicios', 'label' => 'Servicios'],
    ['id' => 'empresas', 'label' => 'Empresas'],
    ['id' => 'contacto', 'label' => 'Contacto'],
];

$search = trim($_GET['search'] ?? '');
$companies = [];
if ($search !== '') {
    $companiesRes = api_post_form('/api/compliant-companies', ['search' => $search]);
    if (is_array($companiesRes) && empty($companiesRes['error'])) {
        $companies = $companiesRes;
    }
}
?>

<?php if (!empty($alerts)): ?>
<div class='bg-red-900/40 border-b border-red-500/30 text-white'>
    <div class='max-w-7xl mx-auto px-6 py-2.5 text-center text-[12px]'>
        <?php foreach ($alerts as $alert): ?>
            <span class='font-medium'><?= h($alert['message'] ?? '') ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Nav -->
<header id='landing-header' class='fixed top-0 left-0 right-0 z-50 transition-all duration-300 bg-transparent'>
    <div class='max-w-7xl mx-auto px-6 h-16 flex items-center justify-between'>
        <a href='/' class='flex items-center gap-3'>
            <div class='w-8 h-8 rounded-lg overflow-hidden bg-bg-panel flex items-center justify-center'>
                <img src='/logo-nuevo.png' alt='SecureLab' class='w-full h-full object-contain'>
            </div>
            <span class='text-[15px] font-bold text-white tracking-tight'>SecureLab</span>
        </a>

        <nav class='hidden md:flex items-center gap-1'>
            <?php foreach ($sections as $s): ?>
                <button data-target='<?= $s['id'] ?>' class='scroll-link px-3 py-1.5 text-[12px] text-text-muted hover:text-text-heading hover:bg-white/[0.06] rounded-lg transition-all duration-200'>
                    <?= $s['label'] ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class='flex items-center gap-3'>
            <a href='/login' class='hidden md:inline-flex px-4 py-1.5 text-[12px] font-medium text-text-body hover:text-text-heading transition-colors'>Iniciar Sesión</a>
            <a href='/register' class='px-4 py-1.5 text-[12px] font-medium rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 transition-all duration-200'>Registrarse</a>
            <button id='menu-button' class='md:hidden p-2 text-text-muted hover:text-text-heading'><?= getIcon('menu') ?></button>
        </div>
    </div>

    <div id='mobile-menu' class='hidden md:hidden bg-bg-panel/95 backdrop-blur-xl border-t border-border-theme px-6 py-4 space-y-2'>
        <?php foreach ($sections as $s): ?>
            <button data-target='<?= $s['id'] ?>' class='scroll-link block w-full text-left px-3 py-2 text-[13px] text-text-muted hover:text-text-heading hover:bg-white/[0.06] rounded-lg'><?= $s['label'] ?></button>
        <?php endforeach; ?>
        <a href='/login' class='block px-3 py-2 text-[13px] text-text-body hover:text-text-heading'>Iniciar Sesión</a>
    </div>
</header>

<!-- Hero -->
<section id='inicio' class='relative min-h-screen flex items-center overflow-hidden'>
    <div class='absolute inset-0 bg-gradient-to-b from-cyan-500/5 via-transparent to-bg-base'></div>
    <div class='absolute top-1/4 left-1/4 w-96 h-96 bg-cyan-500/10 rounded-full blur-3xl'></div>
    <div class='absolute bottom-1/4 right-1/4 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl'></div>

    <div class='relative max-w-7xl mx-auto px-6 py-32 text-center'>
        <div class='inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 text-[11px] font-medium mb-8'>
            <span class='w-2 h-2 rounded-full bg-cyan-400 animate-pulse'></span>
            Ley 21.719 — Protección de Datos Personales en Chile
        </div>
        <h1 class='text-5xl md:text-7xl font-bold text-white leading-tight mb-6 tracking-tight'>
            Cumplimiento <span class='text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-emerald-400'>Simplificado</span>
        </h1>
        <p class='text-lg md:text-xl text-text-muted max-w-2xl mx-auto mb-10 leading-relaxed'>
            La nueva Ley de Protección de Datos Personales exige que tu empresa implemente medidas concretas.
            Te ayudamos a cumplir con cada artículo, automatizar procesos y proteger a tus titulares.
        </p>
        <div class='flex items-center justify-center gap-4'>
            <a href='/register' class='px-8 py-3 text-[14px] font-semibold rounded-xl bg-gradient-to-r from-cyan-500 to-emerald-500 text-white hover:from-cyan-400 hover:to-emerald-400 shadow-lg shadow-cyan-500/25 transition-all duration-200'>Comenzar Ahora</a>
            <button data-target='ley' class='scroll-link px-8 py-3 text-[14px] font-medium rounded-xl border border-border-theme text-text-body hover:bg-white/[0.06] hover:border-surface-600 transition-all duration-200'>Conoce la Ley</button>
        </div>
        <div class='mt-16 grid grid-cols-3 gap-8 max-w-2xl mx-auto'>
            <?php
            $hero_items = [
                ['n' => 'Automático', 'd' => 'Escaneo y mapeo de datos personales'],
                ['n' => 'Cumplimiento', 'd' => 'Checklist completo Ley 21.719'],
                ['n' => 'Consulta tu precio', 'd' => 'Soluciones adaptadas a tu empresa'],
            ];
            foreach ($hero_items as $item): ?>
                <div class='text-center'>
                    <p class='text-[22px] font-bold text-white mb-1'><?= h($item['n']) ?></p>
                    <p class='text-[11px] text-text-muted'><?= h($item['d']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- La Ley -->
<section id='ley' class='py-24'>
    <div class='max-w-7xl mx-auto px-6'>
        <div class='text-center mb-16'>
            <span class='inline-block px-3 py-1 text-[10px] font-semibold uppercase tracking-widest rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 mb-4'>Obligaciones Legales</span>
            <h2 class='text-3xl md:text-5xl font-bold text-white mb-4'>Pilares de la Ley 21.719</h2>
            <p class='text-text-muted max-w-2xl mx-auto leading-relaxed'>
                La normativa exige medidas concretas. SecureLab te guía en cada una de ellas para que estés protegido.
            </p>
        </div>
        <div class='grid md:grid-cols-2 lg:grid-cols-3 gap-5'>
            <?php
            $leyItems = [
                ['title' => 'Registro de actividades de tratamiento', 'desc' => 'Documenta y mantiene actualizado el registro de tratamientos que realiza tu organización.'],
                ['title' => 'Evaluación de impacto en la protección de datos', 'desc' => 'Identifica y mitiga riesgos antes de implementar nuevos tratamientos de datos personales.'],
                ['title' => 'Delegado de Protección de Datos', 'desc' => 'Designa un DPO y gestiona sus funciones, comunicación y reportes al regulador.'],
                ['title' => 'Notificación de violaciones de seguridad', 'desc' => 'Reporta brechas a la autoridad y a los titulares afectados dentro de los plazos legales.'],
                ['title' => 'Derechos ARCO de los titulares', 'desc' => 'Canaliza y responde solicitudes de acceso, rectificación, cancelación y oposición.'],
                ['title' => 'Transferencias internacionales', 'desc' => 'Asegura el cumplimiento en el envío de datos personales fuera de Chile.'],
            ];
            foreach ($leyItems as $item): ?>
            <div class='bg-bg-base/60 border border-border-theme rounded-xl p-5 hover:border-emerald-500/20 transition-all duration-300'>
                <div class='w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-3'>
                    <?= getIcon('check') ?>
                </div>
                <h3 class='text-[13px] font-semibold text-white mb-1.5'><?= h($item['title']) ?></h3>
                <p class='text-[11px] text-text-muted leading-relaxed'><?= h($item['desc']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Servicios -->
<section id='servicios' class='py-24 bg-bg-panel/20'>
    <div class='max-w-7xl mx-auto px-6'>
        <div class='text-center mb-16'>
            <span class='inline-block px-3 py-1 text-[10px] font-semibold uppercase tracking-widest rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 mb-4'>Nuestra Solución</span>
            <h2 class='text-3xl md:text-5xl font-bold text-white mb-4'>Todo lo que necesitas para cumplir</h2>
            <p class='text-text-muted max-w-2xl mx-auto leading-relaxed'>
                Desde el escaneo automático de bases de datos hasta la gestión de solicitudes ARCO, te cubrimos en cada etapa.
            </p>
        </div>
        <div class='grid md:grid-cols-2 lg:grid-cols-4 gap-5'>
            <?php
            $servicios = [
                ['icon' => 'search', 't' => 'Escaneo Automático', 'd' => 'Descubre automáticamente bases de datos en tu infraestructura y clasifica los datos personales que contienen.'],
                ['icon' => 'document', 't' => 'Inventario de Datos', 'd' => 'Mantén un registro actualizado de tus bases de datos con su nivel de riesgo y base legal.'],
                ['icon' => 'check', 't' => 'Gestión de Consentimientos', 'd' => 'Genera, almacena y gestiona consentimientos explícitos por finalidad, con prueba de auditoría.'],
                ['icon' => 'shield', 't' => 'Portal ARCO', 'd' => 'Portal para que los titulares ejerzan sus derechos y panel de gestión con tiempos de respuesta.'],
                ['icon' => 'users', 't' => 'Notificación de Brechas', 'd' => 'Detección, clasificación y notificación automática a la APDP dentro del plazo legal de 48 horas.'],
                ['icon' => 'clock', 't' => 'Capacitaciones', 'd' => 'Registro y seguimiento de capacitaciones en protección de datos para tu equipo.'],
                ['icon' => 'star', 't' => 'Reportes PDF', 'd' => 'Genera informes de cumplimiento listos para auditoría con un solo clic.'],
                ['icon' => 'lock', 't' => 'DPD Integrado', 'd' => 'Designa tu Delegado de Protección de Datos y recibe alertas inteligentes de cumplimiento.'],
            ];
            foreach ($servicios as $s): ?>
            <div class='bg-bg-base/60 border border-border-theme rounded-xl p-5 hover:border-emerald-500/20 transition-all duration-300 group'>
                <div class='w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-3 group-hover:bg-emerald-500/20 transition-colors'>
                    <?= getIcon($s['icon']) ?>
                </div>
                <h3 class='text-[13px] font-semibold text-white mb-1.5'><?= h($s['t']) ?></h3>
                <p class='text-[11px] text-text-muted leading-relaxed'><?= h($s['d']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Empresas Cumplidoras -->
<section id='empresas' class='py-24 relative'>
    <div class='absolute inset-0 bg-gradient-to-b from-transparent via-cyan-500/5 to-transparent'></div>
    <div class='relative max-w-7xl mx-auto px-6'>
        <div class='text-center mb-16'>
            <span class='inline-block px-3 py-1 text-[10px] font-semibold uppercase tracking-widest rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 mb-4'>Portal de Datos</span>
            <h2 class='text-3xl md:text-5xl font-bold text-white mb-4'>Ejerce tus Derechos ARCO</h2>
            <p class='text-text-muted max-w-2xl mx-auto leading-relaxed'>
                Busca la empresa que trata tus datos personales y accede a su portal de solicitudes para ejercer tus derechos.
            </p>
        </div>

        <div class='max-w-xl mx-auto'>
            <form method='GET' class='relative mb-8'>
                <input type='text' name='search' value='<?= h($_GET['search'] ?? '') ?>'
                       placeholder='Busca una empresa por nombre o RUT...'
                       class='w-full bg-bg-panel border border-border-theme text-[14px] text-white rounded-xl pl-11 pr-4 py-4 focus:outline-none focus:border-cyan-500/50 placeholder-text-subtle transition-all'>
                <span class='absolute left-4 top-1/2 -translate-y-1/2 text-text-muted'><?= getIcon('search') ?></span>
            </form>

            <?php if ($search !== '' && !empty($companies)): ?>
            <div class='space-y-3'>
                <?php foreach ($companies as $c): ?>
                <?php $name = h($c['companyName'] ?? $c['name'] ?? 'Empresa'); $rut = h($c['rut'] ?? $c['email'] ?? ''); ?>
                <div class='bg-bg-panel/60 border border-border-theme/50 rounded-xl p-4 flex items-center gap-4'>
                    <div class='w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500/20 to-emerald-500/20 border border-cyan-500/20 flex items-center justify-center text-cyan-400 font-bold text-lg flex-shrink-0'>
                        <?= strtoupper(substr($name, 0, 1)) ?>
                    </div>
                    <div class='flex-1 min-w-0'>
                        <h4 class='text-[14px] font-semibold text-text-heading truncate'><?= $name ?></h4>
                        <p class='text-[11px] text-text-muted'>RUT: <?= $rut ?></p>
                    </div>
                    <a href='/arco-solicitud' class='px-3 py-2 rounded-lg bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 hover:bg-cyan-500/20 transition-all text-[12px] font-medium flex items-center gap-1'>
                        <?= getIcon('document') ?> ARCO
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($search !== ''): ?>
            <div class='text-center py-8 text-text-muted text-[13px]'>
                No se encontraron empresas para "<?= h($search) ?>".
            </div>
            <?php else: ?>
            <div class='text-center py-8 text-text-muted text-[13px]'>
                Introduce un nombre o email para buscar empresas.
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class='py-24 relative overflow-hidden'>
    <div class='absolute inset-0 bg-gradient-to-r from-cyan-500/10 via-emerald-500/10 to-cyan-500/10'></div>
    <div class='absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-emerald-500/20 rounded-full blur-3xl'></div>
    <div class='relative max-w-4xl mx-auto px-6 text-center'>
        <h2 class='text-3xl md:text-5xl font-bold text-white mb-6'>¿Listo para cumplir con la ley?</h2>
        <p class='text-text-muted max-w-2xl mx-auto mb-10 leading-relaxed text-[15px]'>
            Únete a las empresas que ya están preparadas para la Ley 21.719. Comienza hoy y escala cuando lo necesites.
        </p>
        <a href='/register' class='px-10 py-4 text-[15px] font-semibold rounded-xl bg-gradient-to-r from-cyan-500 to-emerald-500 text-white hover:from-cyan-400 hover:to-emerald-400 shadow-lg shadow-emerald-500/25 transition-all duration-200'>Crear Cuenta</a>
    </div>
</section>

<!-- Contacto -->
<section id='contacto' class='py-16 border-t border-border-theme'>
    <div class='max-w-7xl mx-auto px-6'>
        <div class='grid md:grid-cols-4 gap-8'>
            <div>
                <div class='flex items-center gap-2 mb-4'>
                    <div class='w-7 h-7 rounded-lg overflow-hidden bg-bg-panel flex items-center justify-center'>
                        <img src='/logo-nuevo.png' alt='SecureLab' class='w-full h-full object-contain'>
                    </div>
                    <span class='text-[14px] font-bold text-text-heading'>SecureLab</span>
                </div>
                <p class='text-[11px] text-text-subtle leading-relaxed'>
                    Plataforma integral de cumplimiento para la Ley 21.719 de Protección de Datos Personales en Chile.
                </p>
            </div>
            <div>
                <h4 class='text-[11px] font-semibold text-white uppercase tracking-wider mb-3'>Enlaces</h4>
                <div class='space-y-2'>
                    <?php foreach ($sections as $s): ?>
                        <button data-target='<?= $s['id'] ?>' class='scroll-link block text-[12px] text-text-muted hover:text-text-body transition-colors'><?= $s['label'] ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h4 class='text-[11px] font-semibold text-white uppercase tracking-wider mb-3'>Recursos</h4>
                <div class='space-y-2'>
                    <a href='/login' class='block text-[12px] text-text-muted hover:text-text-body transition-colors'>Iniciar Sesión</a>
                    <a href='/register' class='block text-[12px] text-text-muted hover:text-text-body transition-colors'>Registrarse</a>
                    <a href='/arco-solicitud' class='block text-[12px] text-text-muted hover:text-text-body transition-colors'>Derechos ARCO (Ley 21.719)</a>
                </div>
            </div>
            <div>
                <h4 class='text-[11px] font-semibold text-white uppercase tracking-wider mb-3'>Contacto</h4>
                <div class='space-y-2 text-[12px] text-text-muted'>
                    <p>contacto@securelab.cl</p>
                    <p>+56 9 9744 7411</p>
                    <p class='text-[10px] text-text-subtle'>L-V 9:00 - 18:00 CLT</p>
                </div>
            </div>
        </div>
        <div class='mt-10 pt-6 border-t border-border-theme text-center text-[10px] text-text-subtle'>
            &copy; <?= date('Y') ?> SecureLab. Todos los derechos reservados. Ley 21.719 — Chile.
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const header = document.getElementById('landing-header');
    const menuBtn = document.getElementById('menu-button');
    const mobileMenu = document.getElementById('mobile-menu');
    let menuOpen = false;

    const onScroll = () => {
        if (window.scrollY > 40) {
            header.classList.add('bg-bg-base/90', 'backdrop-blur-xl', 'border-b', 'border-border-theme');
            header.classList.remove('bg-transparent');
        } else {
            header.classList.remove('bg-bg-base/90', 'backdrop-blur-xl', 'border-b', 'border-border-theme');
            header.classList.add('bg-transparent');
        }
    };

    const toggleMenu = () => {
        menuOpen = !menuOpen;
        if (menuOpen) {
            mobileMenu.classList.remove('hidden');
            menuBtn.innerHTML = <?= json_encode(getIcon('xmark')) ?>;
        } else {
            mobileMenu.classList.add('hidden');
            menuBtn.innerHTML = <?= json_encode(getIcon('menu')) ?>;
        }
    };

    document.querySelectorAll('.scroll-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = e.currentTarget.dataset.target;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
            if (menuOpen) toggleMenu();
        });
    });

    window.addEventListener('scroll', onScroll);
    menuBtn.addEventListener('click', toggleMenu);
    onScroll();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
