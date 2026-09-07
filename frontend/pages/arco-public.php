<?php
$pageTitle = 'Solicitud de Derechos ARCO — Ley 21.719';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/turnstile.php';

$error = '';
$step = 1; // 1: seleccionar empresa, 2: formulario, 3: éxito

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $step = 3;
    $requestId = $_GET['requestId'] ?? '';
    $requestEmail = $_GET['email'] ?? '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step']) && $_POST['step'] === '1') {
        // Paso 1: Empresa seleccionada, mostrar formulario
        $step = 2;
        $_SESSION['arco_company'] = $_POST['company_id'] ?? '';
        $_SESSION['arco_company_name'] = $_POST['company_name'] ?? '';
    }
}

$companyName = $_SESSION['arco_company_name'] ?? '';
?>
<div class="min-h-screen bg-bg-base text-[13px] text-text-body">
    <!-- Navegación superior -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-bg-base/90 backdrop-blur-xl border-b border-border-theme" aria-label="Navegación principal">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
            <a href="/" class="flex items-center gap-3" aria-label="Ir al inicio de SecureLab">
                <div class="w-7 h-7 rounded-lg overflow-hidden bg-bg-panel flex items-center justify-center">
                    <img src="/logo-nuevo.png" alt="SecureLab" class="w-full h-full object-contain">
                </div>
                <span class="text-[15px] font-bold text-white tracking-tight">SecureLab</span>
            </a>
            <a href="/" class="text-[12px] font-medium text-text-muted hover:text-text-heading transition-colors flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Volver
            </a>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-24 pb-16">
        <?php if ($step === 1): ?>
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- LANDING: Sección informativa sobre derechos ARCO (Ley 21.719)        -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <section class="relative overflow-hidden rounded-2xl border border-border-theme bg-gradient-to-br from-primary-600/10 via-bg-panel to-emerald-500/[0.04] mb-8" aria-labelledby="arco-hero-title">
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(59,130,246,0.08),transparent_50%)]" aria-hidden="true"></div>
            <div class="relative px-6 sm:px-10 py-10 sm:py-14 text-center">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-primary-500/10 border border-primary-500/25 mb-5">
                    <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-primary-300">Ley 21.719 · Protección de Datos Personales</span>
                </div>
                <h1 id="arco-hero-title" class="text-3xl sm:text-4xl md:text-5xl font-extrabold text-white leading-tight tracking-tight">
                    Ejerce tus derechos <span class="bg-gradient-to-r from-primary-400 to-emerald-400 bg-clip-text text-transparent">ARCO</span>
                </h1>
                <p class="mt-4 text-[14px] sm:text-[15px] text-text-muted max-w-2xl mx-auto leading-relaxed">
                    La Ley 21.719 te permite acceder, rectificar, cancelar, oponerte o portar tus datos personales.
                    Este canal permite enviar una solicitud formal a la empresa responsable, de forma gratuita y trazable.
                </p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3 text-[11px]">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.04] border border-white/[0.06] text-text-body">
                        <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Plazo legal: <strong class="text-emerald-400 font-semibold">10 días hábiles</strong>
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.04] border border-white/[0.06] text-text-body">
                        <svg class="w-3.5 h-3.5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Gratuito y trazable
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/[0.04] border border-white/[0.06] text-text-body">
                        <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Sin registro obligatorio
                    </span>
                </div>
            </div>
        </section>

        <!-- Derechos ARCO disponibles -->
        <section class="mb-10" aria-labelledby="derechos-title">
            <h2 id="derechos-title" class="text-[11px] font-bold text-text-subtle uppercase tracking-widest text-center mb-4">Derechos que puedes ejercer</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php
                $arcoRights = [
                    ['icon' => 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z', 'title' => 'Acceso', 'art' => 'Art. 8', 'desc' => 'Consultar qué datos personales tienen sobre ti.'],
                    ['icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z', 'title' => 'Rectificación', 'art' => 'Art. 9', 'desc' => 'Corregir datos erróneos, incompletos o desactualizados.'],
                    ['icon' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16', 'title' => 'Cancelación', 'art' => 'Art. 10', 'desc' => 'Eliminar tus datos cuando ya no sean necesarios.'],
                    ['icon' => 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z', 'title' => 'Oposición', 'art' => 'Art. 11', 'desc' => 'Oponerte al tratamiento de tus datos personales.'],
                    ['icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4', 'title' => 'Portabilidad', 'art' => 'Art. 13', 'desc' => 'Recibir tus datos en formato estructurado y portable.'],
                    ['icon' => 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z', 'title' => 'Bloqueo', 'art' => 'Art. 8 ter', 'desc' => 'Suspender temporalmente el tratamiento de tus datos.'],
                ];
                foreach ($arcoRights as $right):
                ?>
                <div class="rounded-xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-4 hover:border-border-theme/70 transition-colors">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-lg bg-primary-500/10 border border-primary-500/20 flex items-center justify-center flex-shrink-0" aria-hidden="true">
                            <svg class="w-4.5 h-4.5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= $right['icon'] ?>"/></svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-[12px] font-semibold text-white"><?= h($right['title']) ?></h3>
                                <span class="text-[9px] font-bold text-text-subtle bg-white/[0.04] px-1.5 py-0.5 rounded"><?= h($right['art']) ?></span>
                            </div>
                            <p class="text-[11px] text-text-muted mt-1 leading-relaxed"><?= h($right['desc']) ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Paso 1: Selección de empresa -->
        <section class="rounded-2xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-6 sm:p-8" aria-labelledby="empresa-title">
            <div class="text-center mb-6">
                <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4" aria-hidden="true">
                    <svg class="w-7 h-7 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/></svg>
                </div>
                <h2 id="empresa-title" class="text-2xl font-bold text-white mb-2">¿A qué empresa dirigirás tu solicitud?</h2>
                <p class="text-text-muted text-[13px]">Selecciona la organización responsable del tratamiento de tus datos.</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="company-form" novalidate>
                <input type="hidden" name="step" value="1">
                <div>
                    <label for="company-search" class="label-premium">Buscar empresa / organización <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" aria-hidden="true">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" name="company_search" id="company-search" required
                               role="combobox" aria-autocomplete="list" aria-controls="company-results" aria-expanded="false" aria-activedescendant=""
                               class="w-full bg-[#0f1419] border border-[#1f2937] rounded-lg pl-9 pr-3 py-3 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 transition-colors"
                               placeholder="Escribe el nombre de la empresa..." autocomplete="off">
                        <input type="hidden" name="company_id" id="company-id" required>
                        <input type="hidden" name="company_name" id="company-name" required>
                    </div>
                    <p class="text-[10px] text-text-muted mt-2">Escribe al menos 2 caracteres. Si la empresa no aparece, contacta a su área de datos directamente.</p>
                    <div id="company-results" class="hidden absolute z-10 w-full bg-bg-panel border border-border-theme rounded-lg mt-1 max-h-60 overflow-y-auto shadow-2xl" role="listbox" aria-label="Resultados de búsqueda de empresas"></div>
                </div>
                <button type="submit" class="btn-primary w-full py-3.5 text-sm font-semibold" disabled id="company-submit" aria-disabled="true">
                    <span>Continuar al formulario</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>
        </section>

        <!-- Aviso de privacidad -->
        <div class="mt-6 rounded-xl border border-border-theme bg-bg-base/40 p-4 text-center">
            <p class="text-[10px] text-text-subtle leading-relaxed">
                <strong class="text-text-muted">Importante:</strong> Los datos que ingreses se utilizarán únicamente para procesar tu solicitud ARCO y generar el comprobante.
                La empresa tiene un plazo máximo de <strong>10 días hábiles</strong> para responder, conforme a la Ley 21.719.
            </p>
        </div>

        <?php elseif ($step === 2): ?>
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- PASO 2: Formulario de datos del solicitante                          -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <section class="rounded-2xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-6 sm:p-8" aria-labelledby="form-title">
            <div class="mb-6">
                <div class="flex items-center gap-2 text-sm text-text-muted mb-2">
                    <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/></svg>
                    <span>Empresa seleccionada:</span>
                    <strong class="text-white"><?= h($companyName) ?></strong>
                </div>
                <button type="button" onclick="location.href='/arco-solicitud'" class="text-xs text-text-muted hover:text-text-heading underline focus:outline-none focus:ring-2 focus:ring-primary-500/40 rounded">
                    Cambiar empresa
                </button>
            </div>

            <div class="text-center mb-6">
                <h2 id="form-title" class="text-xl font-bold text-white">Formulario de Solicitud ARCO</h2>
                <p class="text-text-muted text-[12px] mt-1">Completa los campos obligatorios para formalizar tu solicitud.</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form class="space-y-5" id="arco-form" novalidate>
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="captchaToken" id="captchaToken">

                <!-- Progreso visual -->
                <div class="flex items-center gap-1 mb-6" role="progressbar" aria-label="Progreso del formulario" aria-valuenow="2" aria-valuemin="1" aria-valuemax="3">
                    <div class="flex-1 h-1.5 bg-emerald-500 rounded-full" aria-hidden="true"></div>
                    <div class="flex-1 h-1.5 bg-emerald-500 rounded-full" aria-hidden="true"></div>
                    <div class="flex-1 h-1.5 bg-white/10 rounded-full" aria-hidden="true"></div>
                </div>

                <!-- Datos del solicitante -->
                <fieldset class="space-y-4">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Datos del solicitante (titular de los datos)
                    </legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="arco-nombre" class="label-premium">Nombre completo <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                            <input type="text" id="arco-nombre" name="nombre" required aria-required="true" class="input-premium" value="<?= h($_POST['nombre'] ?? '') ?>" placeholder="Juan Pérez González">
                        </div>
                        <div>
                            <label for="rut-arco" class="label-premium">RUT <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                            <input type="text" id="rut-arco" name="rut" required aria-required="true" class="input-premium" placeholder="12.345.678-9" pattern="[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]{1}" value="<?= h($_POST['rut'] ?? '') ?>" aria-describedby="rut-hint">
                            <p id="rut-hint" class="text-[10px] text-text-muted mt-1">Formato: 12.345.678-9</p>
                        </div>
                        <div>
                            <label for="arco-email" class="label-premium">Email <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                            <input type="email" id="arco-email" name="email" required aria-required="true" class="input-premium" value="<?= h($_POST['email'] ?? '') ?>" placeholder="juan.perez@ejemplo.cl">
                        </div>
                        <div>
                            <label for="arco-telefono" class="label-premium">Teléfono <span class="text-text-subtle">(opcional)</span></label>
                            <input type="tel" id="arco-telefono" name="telefono" class="input-premium" value="<?= h($_POST['telefono'] ?? '') ?>" placeholder="+56 9 1234 5678">
                        </div>
                    </div>
                </fieldset>

                <!-- Tipo de solicitud -->
                <fieldset class="space-y-4 pt-4 border-t border-border-theme">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Tipo de derecho ARCO a ejercer
                    </legend>
                    <div>
                        <label for="arco-tipo" class="label-premium">Derecho <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                        <select id="arco-tipo" name="tipo" required aria-required="true" class="input-premium" aria-describedby="tipo-hint">
                            <option value="">Selecciona un derecho</option>
                            <optgroup label="Derechos principales (Ley 21.719)">
                                <option value="acceso" <?= ($_POST['tipo'] ?? '') === 'acceso' ? 'selected' : '' ?>>Acceso (Art. 8) — Consultar qué datos tienen sobre mí</option>
                                <option value="rectificacion" <?= ($_POST['tipo'] ?? '') === 'rectificacion' ? 'selected' : '' ?>>Rectificación (Art. 9) — Corregir datos erróneos o desactualizados</option>
                                <option value="cancelacion" <?= ($_POST['tipo'] ?? '') === 'cancelacion' ? 'selected' : '' ?>>Cancelación / Supresión (Art. 10) — Eliminar mis datos</option>
                                <option value="oposicion" <?= ($_POST['tipo'] ?? '') === 'oposicion' ? 'selected' : '' ?>>Oposición (Art. 11) — Oponerme al tratamiento</option>
                                <option value="portabilidad" <?= ($_POST['tipo'] ?? '') === 'portabilidad' ? 'selected' : '' ?>>Portabilidad (Art. 13) — Recibir mis datos en formato estructurado</option>
                                <option value="bloqueo" <?= ($_POST['tipo'] ?? '') === 'bloqueo' ? 'selected' : '' ?>>Bloqueo (Art. 8 ter) — Suspender temporalmente el tratamiento</option>
                            </optgroup>
                        </select>
                        <p id="tipo-hint" class="text-[10px] text-text-muted mt-1">Si no estás seguro, revisa la guía de derechos ARCO o contacta al responsable.</p>
                    </div>
                </fieldset>

                <!-- Descripción -->
                <fieldset class="space-y-4 pt-4 border-t border-border-theme">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Detalle de la solicitud
                    </legend>
                    <div>
                        <label for="arco-descripcion" class="label-premium">Descripción <span class="text-red-400" aria-hidden="true">*</span><span class="sr-only">requerido</span></label>
                        <textarea id="arco-descripcion" name="descripcion" rows="4" required aria-required="true" class="input-premium" placeholder="Describe tu solicitud: qué datos, desde cuándo, qué esperas que hagan..." aria-describedby="desc-hint"><?= h($_POST['descripcion'] ?? '') ?></textarea>
                        <p id="desc-hint" class="text-[10px] text-text-muted mt-1">Sé específico para agilizar la respuesta. Incluye fechas, referencias o documentos si los tienes.</p>
                    </div>
                </fieldset>

                <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY): ?>
                <div class="flex justify-center pt-4">
                    <div class="cf-turnstile" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
                </div>
                <?php endif; ?>

                <!-- Acciones -->
                <div class="pt-4 border-t border-border-theme flex flex-col sm:flex-row gap-3">
                    <button type="button" onclick="location.href='/arco-solicitud'" class="flex-1 px-4 py-3 rounded-lg text-[12px] font-medium bg-white/[0.05] hover:bg-white/[0.08] text-text-muted border border-white/[0.05] transition-all focus:outline-none focus:ring-2 focus:ring-primary-500/40">
                        Volver
                    </button>
                    <button type="submit" class="flex-1 px-4 py-3 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all shadow-theme-sm flex items-center justify-center gap-2 focus:outline-none focus:ring-2 focus:ring-emerald-500/40">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Enviar solicitud ARCO
                    </button>
                </div>
            </form>
        </section>

        <?php else: ?>
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <!-- PASO 3: Confirmación / Éxito                                         -->
        <!-- ══════════════════════════════════════════════════════════════════ -->
        <section class="rounded-2xl border border-border-theme bg-bg-panel/60 backdrop-blur-sm p-6 sm:p-8 text-center relative overflow-hidden shadow-2xl" aria-labelledby="success-title">
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-emerald-500 to-primary-500" aria-hidden="true"></div>
            <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto mb-4" aria-hidden="true">
                <svg class="w-8 h-8 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h2 id="success-title" class="text-2xl font-bold text-white mb-2">¡Solicitud enviada correctamente!</h2>
            <p class="text-text-muted mb-6">Tu solicitud ARCO ha sido registrada. Guarda tu número de referencia.</p>

            <div class="rounded-xl border border-border-theme bg-bg-base/60 p-5 mb-6 text-left">
                <p class="text-[10px] uppercase tracking-wider text-text-subtle mb-1">Número de referencia</p>
                <div class="flex items-center gap-2">
                    <p id="arco-ref" class="text-primary-400 font-mono text-xl font-bold"><?= h($requestId ?? 'N/A') ?></p>
                    <button type="button" onclick="copyArcoRef()" title="Copiar referencia" aria-label="Copiar número de referencia" class="p-1.5 rounded-md hover:bg-white/5 text-text-muted hover:text-white transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500/40">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </button>
                </div>
                <p class="text-[11px] text-text-subtle mt-2">Se ha enviado un comprobante a tu email. También puedes descargarlo ahora.</p>
            </div>

            <?php
            $pdfUrl = '';
            if (!empty($requestId) && !empty($requestEmail)) {
                $pdfUrl = API_BASE_URL_BROWSER . '/api/arco/requests/' . urlencode($requestId) . '/receipt?email=' . urlencode($requestEmail);
            }
            ?>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <?php if ($pdfUrl): ?>
                <a href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-[12px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all focus:outline-none focus:ring-2 focus:ring-red-500/40">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Descargar comprobante PDF
                </a>
                <?php endif; ?>
                <a href="/arco-solicitud" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-[12px] font-medium bg-primary-500/10 border border-primary-500/20 text-primary-400 hover:bg-primary-500/20 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500/40">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Nueva solicitud
                </a>
            </div>

            <p class="text-[11px] text-text-subtle mt-6">La empresa debe responder dentro de un plazo máximo de <strong>10 días hábiles</strong> conforme a la Ley 21.719.</p>
        </section>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('company-search');
    const companyIdInput = document.getElementById('company-id');
    const companyNameInput = document.getElementById('company-name');
    const resultsContainer = document.getElementById('company-results');
    const submitBtn = document.getElementById('company-submit');
    let debounceTimer = null;
    let selectedIndex = -1;
    let results = [];

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);
            selectedIndex = -1;
            this.setAttribute('aria-activedescendant', '');

            if (query.length < 2) {
                hideResults();
                submitBtn.disabled = true;
                submitBtn.setAttribute('aria-disabled', 'true');
                companyIdInput.value = '';
                companyNameInput.value = '';
                return;
            }

            debounceTimer = setTimeout(() => {
                searchCompanies(query);
            }, 300);
        });

        searchInput.addEventListener('keydown', function(e) {
            if (!resultsContainer.classList.contains('hidden') && results.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, results.length - 1);
                    updateSelection();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, 0);
                    updateSelection();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (selectedIndex >= 0 && results[selectedIndex]) {
                        selectCompany(results[selectedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    hideResults();
                }
            }
        });

        searchInput.addEventListener('blur', function() {
            setTimeout(hideResults, 200);
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2 && results.length > 0) {
                showResults();
            }
        });
    }

    function searchCompanies(query) {
        fetch('<?= API_BASE_URL_BROWSER ?>/api/compliant-companies/search?q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(data => {
                results = data.companies || data || [];
                renderResults();
            })
            .catch(() => {
                results = [];
                renderResults();
            });
    }

    function renderResults() {
        if (results.length === 0) {
            resultsContainer.innerHTML = '<div class="p-3 text-center text-text-muted text-sm" role="option" aria-disabled="true">No se encontraron empresas</div>';
            showResults();
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-disabled', 'true');
            return;
        }

        resultsContainer.innerHTML = results.map((c, i) => {
            const companyName = c.companyName || c.name || 'Empresa sin nombre';
            const companyId = c._id || c.id || '';
            return `
            <button type="button" class="company-result w-full text-left px-3 py-2.5 hover:bg-primary-500/10 border-b border-border-theme/50 last:border-0 transition-colors focus:bg-primary-500/10 focus:outline-none" data-index="${i}" data-id="${companyId}" data-name="${companyName}" role="option" id="company-option-${i}" aria-selected="false" tabindex="-1">
                <div class="font-medium text-white">${companyName}</div>
            </button>
            `;
        }).join('');

        resultsContainer.querySelectorAll('.company-result').forEach(btn => {
            btn.addEventListener('click', function() {
                selectCompany({
                    _id: this.dataset.id,
                    name: this.dataset.name
                });
            });
            btn.addEventListener('mouseenter', function() {
                selectedIndex = parseInt(this.dataset.index);
                updateSelection();
            });
        });

        showResults();
        submitBtn.disabled = false;
        submitBtn.setAttribute('aria-disabled', 'false');
    }

    function updateSelection() {
        resultsContainer.querySelectorAll('.company-result').forEach((btn, i) => {
            const isSelected = i === selectedIndex;
            btn.classList.toggle('bg-primary-500/10', isSelected);
            btn.setAttribute('aria-selected', isSelected);
        });
        if (selectedIndex >= 0) {
            searchInput.setAttribute('aria-activedescendant', 'company-option-' + selectedIndex);
        } else {
            searchInput.setAttribute('aria-activedescendant', '');
        }
    }

    function selectCompany(company) {
        companyIdInput.value = company._id;
        companyNameInput.value = company.name;
        searchInput.value = company.name;
        hideResults();
        submitBtn.disabled = false;
        submitBtn.setAttribute('aria-disabled', 'false');
        submitBtn.focus();
    }

    function showResults() {
        resultsContainer.classList.remove('hidden');
        searchInput.setAttribute('aria-expanded', 'true');
    }

    function hideResults() {
        resultsContainer.classList.add('hidden');
        searchInput.setAttribute('aria-expanded', 'false');
        selectedIndex = -1;
        searchInput.setAttribute('aria-activedescendant', '');
    }

    // Envío del formulario ARCO
    const arcoForm = document.getElementById('arco-form');
    if (arcoForm) {
        arcoForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY && TURNSTILE_SITE_KEY !== ''): ?>
            const turnstileResponse = document.querySelector('.cf-turnstile')?.querySelector('textarea')?.value;
            if (turnstileResponse) {
                document.getElementById('captchaToken').value = turnstileResponse;
            }
            <?php else: ?>
            document.getElementById('captchaToken').value = 'development-bypass';
            <?php endif; ?>

            const companyId = '<?= h($_SESSION['arco_company'] ?? '') ?>';
            const nombre = document.querySelector('input[name="nombre"]').value;
            const rut = document.querySelector('input[name="rut"]').value;
            const email = document.querySelector('input[name="email"]').value;
            const telefono = document.querySelector('input[name="telefono"]').value;
            const tipo = document.querySelector('select[name="tipo"]').value;
            const descripcion = document.querySelector('textarea[name="descripcion"]').value;
            const captchaToken = document.getElementById('captchaToken').value;

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Enviando...';

            try {
                const res = await fetch('<?= API_BASE_URL_BROWSER ?>/api/arco/requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        companyId,
                        solicitante: { nombre, rut, email, telefono },
                        tipo,
                        descripcion,
                        captchaToken
                    })
                });
                const data = await res.json();

                if (data.success) {
                    window.location.href = '/arco-solicitud?success=1&requestId=' + encodeURIComponent(data.requestId) + '&email=' + encodeURIComponent(email);
                } else {
                    showError(data.error || 'Error al enviar la solicitud.');
                    submitBtn.disabled = false;
                    submitBtn.setAttribute('aria-busy', 'false');
                    submitBtn.innerHTML = originalText;
                }
            } catch (err) {
                showError('Error de conexión. Intenta nuevamente.');
                submitBtn.disabled = false;
                submitBtn.setAttribute('aria-busy', 'false');
                submitBtn.innerHTML = originalText;
            }
        });
    }

    function showError(message) {
        const errorDiv = document.querySelector('.bg-red-500\\/10');
        if (errorDiv) {
            errorDiv.remove();
        }
        const form = document.getElementById('arco-form');
        if (form) {
            const errorHtml = `<div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm" role="alert">${message}</div>`;
            form.insertAdjacentHTML('afterbegin', errorHtml);
        }
    }

    function copyArcoRef() {
        const ref = document.getElementById('arco-ref')?.textContent || '';
        navigator.clipboard.writeText(ref).then(() => alert('Referencia copiada: ' + ref)).catch(() => {});
    }

    // Auto-formateo de RUT
    function formatRUT(value) {
        let rut = value.replace(/[^0-9kK]/g, '');
        if (rut.length === 0) return '';
        let dv = rut.slice(-1);
        let cuerpo = rut.slice(0, -1);
        if (cuerpo.length > 0) {
            cuerpo = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        return cuerpo + (cuerpo.length > 0 ? '-' : '') + dv;
    }

    const rutInput = document.getElementById('rut-arco');
    if (rutInput) {
        rutInput.addEventListener('input', function(e) {
            const cursorPos = this.selectionStart;
            const oldLength = this.value.length;
            this.value = formatRUT(this.value);
            const newLength = this.value.length;
            const cursorOffset = newLength - oldLength;
            this.setSelectionRange(cursorPos + cursorOffset, cursorPos + cursorOffset);
        });
    }
});
</script>
