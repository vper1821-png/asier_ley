<?php
$pageTitle = 'Privacidad';
require_once __DIR__ . '/../includes/header.php';
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
    <div class="max-w-3xl mx-auto px-4 pt-28 pb-12">
        <h1 class="text-3xl font-bold text-white mb-8">Política de Privacidad</h1>
        <div class="prose prose-invert max-w-none space-y-6 text-text-body leading-relaxed">
            <p>En SecureLab, nos tomamos muy en serio la protección de tus datos personales. Esta política describe cómo recopilamos, usamos y protegemos tu información.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">1. Información que recopilamos</h2>
            <p>Recopilamos información que nos proporcionas directamente: nombre, email, información de la empresa y datos de configuración de agentes de seguridad.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">2. Uso de la información</h2>
            <p>Utilizamos tu información para proporcionar, mantener y mejorar nuestros servicios de ciberseguridad y cumplimiento normativo.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">3. Protección de datos</h2>
            <p>Implementamos medidas de seguridad técnicas y organizativas para proteger tus datos contra acceso no autorizado, alteración, divulgación o destrucción.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">4. Derechos ARCO</h2>
            <p>Puedes ejercer tus derechos de Acceso, Rectificación, Cancelación y Oposición sobre tus datos personales a través de nuestro <a href="/arco-solicitud" class="text-primary-400 hover:text-primary-300">portal ARCO</a>.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
