<?php
$pageTitle = 'Política de Privacidad';
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
        <h1 class="text-3xl font-bold text-white mb-8">Política de Privacidad - Ley 21.719</h1>
        <div class="prose prose-invert max-w-none space-y-6 text-text-body leading-relaxed">
            <p>De conformidad con la Ley 21.719 de Protección de Datos Personales de Chile, SecureLab actúa como responsable del tratamiento de datos personales.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">Base legal del tratamiento</h2>
            <p>El tratamiento de datos personales se realiza sobre la base del consentimiento del titular y la ejecución del contrato de servicios.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">Finalidad del tratamiento</h2>
            <p>Los datos son tratados para la prestación de servicios de ciberseguridad, monitorización de bases de datos y cumplimiento normativo.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">Transferencia internacional</h2>
            <p>Los datos pueden ser transferidos a servidores ubicados fuera de Chile, siempre garantizando niveles adecuados de protección.</p>
            <h2 class="text-xl font-semibold text-white mt-8 mb-4">Plazo de conservación</h2>
            <p>Los datos se conservan durante la vigencia del contrato y hasta 5 años después de su terminación por obligaciones legales.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
