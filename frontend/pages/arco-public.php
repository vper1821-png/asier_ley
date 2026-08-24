<?php
$pageTitle = 'Solicitud ARCO';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/turnstile.php';

$error = '';
$step = 1; // 1: select company, 2: form

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['step']) && $_POST['step'] === '1') {
        // Step 1: Company selected, show form
        $step = 2;
        $_SESSION['arco_company'] = $_POST['company_id'] ?? '';
        $_SESSION['arco_company_name'] = $_POST['company_name'] ?? '';
    }
}

// Get company name for display
$companyName = $_SESSION['arco_company_name'] ?? '';
?>
<div class="min-h-screen bg-bg-base text-[13px] text-text-body">
    <nav class="fixed top-0 left-0 right-0 z-50 bg-bg-base/90 backdrop-blur-xl border-b border-border-theme">
        <div class="max-w-3xl mx-auto px-6 h-14 flex items-center justify-between">
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
        <!-- Progress indicator -->
        <div class="mb-8">
            <div class="flex items-center justify-center gap-4 md:gap-8 mb-2">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center text-[11px] font-bold <?= $step >= 1 ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : 'border-gray-600 bg-gray-600/20 text-text-muted' ?>">
                        1
                    </div>
                    <span class="text-[12px] font-medium <?= $step >= 1 ? 'text-white' : 'text-text-muted' ?>">Empresa</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center text-[11px] font-bold <?= $step >= 2 ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : 'border-gray-600 bg-gray-600/20 text-text-muted' ?>">
                        2
                    </div>
                    <span class="text-[12px] font-medium <?= $step >= 2 ? 'text-white' : 'text-text-muted' ?>">Datos</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center text-[11px] font-bold <?= $step >= 3 ? 'border-emerald-500 bg-emerald-500/20 text-emerald-400' : 'border-gray-600 bg-gray-600/20 text-text-muted' ?>">
                        3
                    </div>
                    <span class="text-[12px] font-medium <?= $step >= 3 ? 'text-white' : 'text-text-muted' ?>">Envío</span>
                </div>
            </div>
            <div class="h-1.5 bg-gray-700 rounded-full overflow-hidden">
                <div class="h-full bg-gradient-to-r from-emerald-500 to-primary-500 rounded-full transition-all duration-500" style="width: <?= $step === 1 ? '33%' : ($step === 2 ? '66%' : '100%') ?>"></div>
            </div>
        </div>

        <?php if ($step === 1): ?>
        <!-- Step 1: Select Company -->
        <div class="glass-card p-8">
            <div class="text-center mb-8">
                <div class="w-14 h-14 rounded-2xl bg-primary-500/10 border border-primary-500/20 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-7 h-7 text-primary-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/></svg>
                </div>
                <h1 class="text-2xl font-bold text-white mb-2">Solicitud de Derechos ARCO</h1>
                <p class="text-text-muted">Selecciona la empresa u organización a la que quieres dirigir tu solicitud</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="company-form">
                <input type="hidden" name="step" value="1">
                <div>
                    <label class="label-premium">Buscar empresa / organización *</label>
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <input type="text" name="company_search" id="company-search" required
                               class="w-full bg-[#0f1419] border border-[#1f2937] rounded-md pl-9 pr-3 py-2.5 text-sm text-white placeholder-text-subtle focus:outline-none focus:border-[#3b82f6] transition-colors"
                               placeholder="Escribe el nombre de la empresa..." autocomplete="off">
                        <input type="hidden" name="company_id" id="company-id" required>
                        <input type="hidden" name="company_name" id="company-name" required>
                    </div>
                    <p class="text-[10px] text-text-muted mt-1">Escribe al menos 2 caracteres para buscar. Si no aparece, contacta a la empresa directamente.</p>
                    <div id="company-results" class="hidden absolute z-10 w-full bg-bg-panel border border-border-theme rounded-md mt-1 max-h-60 overflow-y-auto"></div>
                </div>
                <button type="submit" class="btn-primary w-full py-3" disabled id="company-submit">
                    <span>Continuar</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>
        </div>
        <?php else: ?>
        <!-- Step 2: Form -->
        <div class="glass-card p-8">
            <div class="mb-6">
                <div class="flex items-center gap-2 text-sm text-text-muted mb-2">
                    <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/></svg>
                    <span>Empresa seleccionada:</span>
                    <strong class="text-white"><?= h($companyName) ?></strong>
                </div>
                <button type="button" onclick="location.href='/arco-solicitud'" class="text-xs text-text-muted hover:text-text-heading underline">Cambiar empresa</button>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm"><?= h($error) ?></div>
            <?php endif; ?>

            <form class="space-y-5" id="arco-form">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="captchaToken" id="captchaToken">
                
                <!-- Progress steps for form -->
                <div class="flex items-center gap-1 mb-6" role="progressbar" aria-label="Progreso del formulario">
                    <div class="flex-1 h-1 bg-emerald-500 rounded-l"></div>
                    <div class="flex-1 h-1 bg-emerald-500"></div>
                    <div class="flex-1 h-1 bg-gray-700 rounded-r"></div>
                </div>

                <!-- Datos del solicitante -->
                <fieldset class="space-y-4">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Datos del solicitante (titular de los datos)
                    </legend>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="label-premium">Nombre completo *</label>
                            <input type="text" name="nombre" required class="input-premium" value="<?= h($_POST['nombre'] ?? '') ?>" placeholder="Juan Pérez González">
                        </div>
                        <div>
                            <label class="label-premium">RUT *</label>
                            <input type="text" id="rut-arco" name="rut" required class="input-premium" placeholder="12.345.678-9" pattern="[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]{1}" value="<?= h($_POST['rut'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="label-premium">Email *</label>
                            <input type="email" name="email" required class="input-premium" value="<?= h($_POST['email'] ?? '') ?>" placeholder="juan.perez@ejemplo.cl">
                        </div>
                        <div>
                            <label class="label-premium">Teléfono</label>
                            <input type="tel" name="telefono" class="input-premium" value="<?= h($_POST['telefono'] ?? '') ?>" placeholder="+56 9 1234 5678">
                        </div>
                    </div>
                </fieldset>

                <!-- Tipo de solicitud -->
                <fieldset class="space-y-4 pt-4 border-t border-border-theme">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Tipo de derecho ARCO a ejercer
                    </legend>
                    <div>
                        <label class="label-premium">Derecho *</label>
                        <select name="tipo" required class="input-premium">
                            <option value="">Selecciona un derecho</option>
                            <optgroup label="Derechos principales (Ley 21.719)">
                                <option value="acceso" <?= ($_POST['tipo'] ?? '') === 'acceso' ? 'selected' : '' ?>>Acceso (Art. 8) - Consultar qué datos tienen sobre mí</option>
                                <option value="rectificacion" <?= ($_POST['tipo'] ?? '') === 'rectificacion' ? 'selected' : '' ?>>Rectificación (Art. 9) - Corregir datos erróneos o desactualizados</option>
                                <option value="cancelacion" <?= ($_POST['tipo'] ?? '') === 'cancelacion' ? 'selected' : '' ?>>Cancelación / Supresión (Art. 10) - Eliminar mis datos</option>
                                <option value="oposicion" <?= ($_POST['tipo'] ?? '') === 'oposicion' ? 'selected' : '' ?>>Oposición (Art. 11) - Oponerme al tratamiento</option>
                                <option value="portabilidad" <?= ($_POST['tipo'] ?? '') === 'portabilidad' ? 'selected' : '' ?>>Portabilidad (Art. 13) - Recibir mis datos en formato estructurado</option>
                                <option value="bloqueo" <?= ($_POST['tipo'] ?? '') === 'bloqueo' ? 'selected' : '' ?>>Bloqueo (Art. 8 ter) - Suspender temporalmente el tratamiento</option>
                            </optgroup>
                        </select>
                        <p class="text-[10px] text-text-muted mt-1">Consulta la <a href="/info-derechos-arco" class="text-primary-400 hover:underline" target="_blank">guía de derechos ARCO</a> si no estás seguro.</p>
                    </div>
                </fieldset>

                <!-- Descripción -->
                <fieldset class="space-y-4 pt-4 border-t border-border-theme">
                    <legend class="text-[12px] font-semibold text-white flex items-center gap-2 mb-4">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 001-1V4a2 2 0 114 0v1a1 1 0 001-1h3a1 1 0 001-1V4a2 2 0 114 0v1a1 1 0 001-1h-3a1 1 0 10-1-1"/></svg>
                        Detalle de la solicitud
                    </legend>
                    <div>
                        <label class="label-premium">Descripción *</label>
                        <textarea name="descripcion" rows="4" required class="input-premium" placeholder="Describe detalladamente tu solicitud: qué datos, desde cuándo, qué esperas que hagan..."><?= h($_POST['descripcion'] ?? '') ?></textarea>
                        <p class="text-[10px] text-text-muted mt-1">Sé específico para agilizar la respuesta. Incluye referencias, fechas o documentos si los tienes.</p>
                    </div>
                </fieldset>

                <?php if (defined('TURNSTILE_SITE_KEY') && TURNSTILE_SITE_KEY): ?>
                <div class="flex justify-center pt-4">
                    <div class="cf-turnstile" data-sitekey="<?= h(TURNSTILE_SITE_KEY) ?>"></div>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <div class="pt-4 border-t border-border-theme flex gap-3">
                    <button type="button" onclick="location.href='/arco-solicitud'" class="flex-1 px-4 py-3 rounded-lg text-[12px] font-medium bg-white/[0.05] hover:bg-white/[0.08] text-text-muted border border-white/[0.05] transition-all">
                        Volver
                    </button>
                    <button type="submit" class="flex-1 px-4 py-3 rounded-lg text-[12px] font-medium bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all shadow-theme-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        Enviar solicitud ARCO
                    </button>
                </div>
            </form>
        </div>
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
            
            if (query.length < 2) {
                hideResults();
                submitBtn.disabled = true;
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
            resultsContainer.innerHTML = '<div class="p-3 text-center text-text-muted text-sm">No se encontraron empresas</div>';
            showResults();
            submitBtn.disabled = true;
            return;
        }

        resultsContainer.innerHTML = results.map((c, i) => {
            const companyName = c.companyName || c.name || 'Empresa sin nombre';
            const companyId = c._id || c.id || '';
            return `
            <button type="button" class="company-result w-full text-left px-3 py-2.5 hover:bg-primary-500/10 border-b border-border-theme/50 last:border-0 transition-colors" data-index="${i}" data-id="${companyId}" data-name="${companyName}">
                <div class="font-medium text-white">${companyName}</div>
            </button>
            `;
        }).join('');

        // Add click handlers
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
    }

    function updateSelection() {
        resultsContainer.querySelectorAll('.company-result').forEach((btn, i) => {
            btn.classList.toggle('bg-primary-500/10', i === selectedIndex);
        });
    }

    function selectCompany(company) {
        companyIdInput.value = company._id;
        companyNameInput.value = company.name;
        searchInput.value = company.name;
        hideResults();
        submitBtn.disabled = false;
        submitBtn.focus();
    }

    function showResults() {
        resultsContainer.classList.remove('hidden');
    }

    function hideResults() {
        resultsContainer.classList.add('hidden');
        selectedIndex = -1;
    }

    // Turnstile captcha handling for ARCO form
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
            // Development: generate dummy token
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
                    submitBtn.innerHTML = originalText;
                }
            } catch (err) {
                showError('Error de conexión. Intenta nuevamente.');
                submitBtn.disabled = false;
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
            const errorHtml = `<div class="mb-6 p-3 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 text-sm">${message}</div>`;
            form.insertAdjacentHTML('afterbegin', errorHtml);
        }
    }

    // Check for success parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        const requestId = urlParams.get('requestId') || '';
        const email = urlParams.get('email') || '';

        // Build PDF receipt URL
        const pdfUrl = requestId
            ? ('<?= API_BASE_URL_BROWSER ?>/api/arco/requests/' + encodeURIComponent(requestId) + '/receipt?email=' + encodeURIComponent(email))
            : '';

        const container = document.querySelector('.max-w-3xl');
        if (container) {
            container.innerHTML = `
                <div class="bg-bg-panel border border-border-theme rounded-xl p-8 text-center relative overflow-hidden shadow-2xl">
                    <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-emerald-500 to-primary-500"></div>
                    <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-2">¡Solicitud enviada correctamente!</h2>
                    <p class="text-text-muted mb-4">Tu solicitud ARCO ha sido registrada. Guarda tu número de referencia.</p>

                    <div class="rounded-xl border border-border-theme bg-bg-base/60 p-5 mb-5 text-left">
                        <p class="text-[10px] uppercase tracking-wider text-text-subtle mb-1">Número de referencia</p>
                        <div class="flex items-center gap-2">
                            <p id="arco-ref" class="text-primary-400 font-mono text-xl font-bold">${requestId || 'N/A'}</p>
                            <button onclick="copyArcoRef()" title="Copiar referencia" class="p-1.5 rounded-md hover:bg-white/5 text-text-muted hover:text-white transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                        </div>
                        <p class="text-[11px] text-text-subtle mt-2">Se ha enviado un comprobante a tu email. También puedes descargarlo ahora.</p>
                    </div>

                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                        ${pdfUrl ? `<a href="${pdfUrl}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-[12px] font-medium bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Descargar comprobante PDF
                        </a>` : ''}
                        <a href="/arco-solicitud" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-[12px] font-medium bg-primary-500/10 border border-primary-500/20 text-primary-400 hover:bg-primary-500/20 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Nueva solicitud
                        </a>
                    </div>

                    <p class="text-[11px] text-text-subtle mt-5">Recibirás una respuesta en tu email en un plazo máximo de 30 días hábiles.</p>
                </div>
            `;
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
        rutInput.addEventListener('blur', function() {
            this.value = formatRUT(this.value);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>