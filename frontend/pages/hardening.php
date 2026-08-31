<?php
require_once __DIR__ . '/../config.php';
require_login();

// Add enhanced CSS for wizard animations
$wizardCSS = '
<style>
.wizard-step-transition {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.wizard-step-content {
    animation: slideIn 0.4s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.wizard-step-content.slide-left {
    animation: slideInLeft 0.4s ease-out;
}

@keyframes slideInLeft {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.wizard-input:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
}

.wizard-btn {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.wizard-btn:hover {
    transform: translateY(-2px);
}

.wizard-btn:active {
    transform: translateY(0);
}

.progress-glow {
    box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
}

.step-pulse {
    animation: stepPulse 2s ease-in-out infinite;
}

@keyframes stepPulse {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
    }
    50% {
        box-shadow: 0 0 0 8px rgba(16, 185, 129, 0);
    }
}

/* Validation styles */
.input-invalid {
    border-color: #ef4444 !important;
    animation: shake 0.5s ease-in-out;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.input-valid {
    border-color: #22c55e !important;
}

.validation-message {
    font-size: 10px;
    color: #ef4444;
    margin-top: 4px;
    animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Mobile responsive improvements */
@media (max-width: 768px) {
    .dpd-step-indicator span,
    .measure-step-indicator span {
        font-size: 8px;
    }
    
    .wizard-btn {
        padding: 0.5rem 1rem;
        font-size: 10px;
    }
}

/* Toast notifications */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.toast {
    min-width: 300px;
    padding: 16px 20px;
    border-radius: 12px;
    background: var(--bg-elevated);
    border: 1px solid var(--border-theme);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
    display: flex;
    align-items: center;
    gap: 12px;
    animation: toastSlideIn 0.3s ease-out;
}

.toast.success {
    border-color: rgba(34, 197, 94, 0.3);
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.1), rgba(20, 83, 45, 0.1));
}

.toast.error {
    border-color: rgba(239, 68, 68, 0.3);
    background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(127, 29, 29, 0.1));
}

.toast.info {
    border-color: rgba(59, 130, 246, 0.3);
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(30, 58, 138, 0.1));
}

.toast-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.toast.success .toast-icon {
    background: rgba(34, 197, 94, 0.2);
    color: #22c55e;
}

.toast.error .toast-icon {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.toast.info .toast-icon {
    background: rgba(59, 130, 246, 0.2);
    color: #3b82f6;
}

.toast-content {
    flex: 1;
}

.toast-title {
    font-weight: 600;
    font-size: 13px;
    color: var(--text-heading);
    margin-bottom: 2px;
}

.toast-message {
    font-size: 11px;
    color: var(--text-muted);
}

.toast-close {
    background: none;
    border: none;
    color: var(--text-subtle);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s;
}

.toast-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-heading);
}

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes toastSlideOut {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(100%);
    }
}

.toast.hiding {
    animation: toastSlideOut 0.3s ease-out forwards;
}
</style>
';

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$tab = $_GET['tab'] ?? 'measures';

// ── POST handlers (antes de HTML para poder redirigir) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_dpd'])) {
        api_post_form('/api/invisia/compliance/config', [
            'token' => $token,
            'dpdName' => $_POST['dpdName'] ?? '',
            'dpdRut' => $_POST['dpdRut'] ?? '',
            'dpdEmail' => $_POST['dpdEmail'] ?? '',
            'dpdPhone' => $_POST['dpdPhone'] ?? '',
            'dpdTitle' => $_POST['dpdTitle'] ?? '',
            'companyName' => $_POST['companyName'] ?? '',
            'companyRut' => $_POST['companyRut'] ?? '',
            'dpdAddress' => $_POST['dpdAddress'] ?? '',
            'dpdPublicUrl' => $_POST['dpdPublicUrl'] ?? '',
            'apdpRegistered' => $_POST['apdpRegistered'] ?? '',
            'apdpRegistrationNumber' => $_POST['apdpRegistrationNumber'] ?? '',
            'apdpRegistrationDate' => $_POST['apdpRegistrationDate'] ?? '',
            'complianceLevel' => $_POST['complianceLevel'] ?? '',
            'preventionModelDate' => $_POST['preventionModelDate'] ?? '',
        ]);
        header('Location: /hardening?tab=dpd');
        exit;
    }
    if (isset($_POST['save_measure']) || isset($_POST['revoke_measure'])) {
        $cfg = api_get('/api/invisia/compliance/config', ['token' => $token]);
        $overrides = [];
        if (!empty($cfg['measureOverrides'])) {
            $overrides = is_string($cfg['measureOverrides']) ? json_decode($cfg['measureOverrides'], true) : $cfg['measureOverrides'];
            if (!is_array($overrides)) $overrides = [];
        }
        $mid = $_POST['measure_id'] ?? '';
        $overrides = array_values(array_filter($overrides, fn($o) => ($o['measureId'] ?? '') !== $mid));
        if (isset($_POST['save_measure'])) {
            $fieldData = [];
            foreach ($_POST as $k => $v) {
                if (strpos($k, 'field_') === 0 && $v !== '') $fieldData[substr($k, 6)] = $v;
            }
            $overrides[] = [
                'measureId' => $mid,
                'completed' => true,
                'notes' => $_POST['notes'] ?? '',
                'evidence' => $fieldData['evidenceUrl'] ?? '',
                'fieldData' => json_encode($fieldData),
                'completedAt' => date('c'),
            ];
        }
        api_post_form('/api/invisia/compliance/config', ['token' => $token, 'measureOverrides' => json_encode($overrides)]);
        header('Location: /hardening?tab=measures');
        exit;
    }
}

// ── Fetch data ──
$statsRes = api_post_form('/api/dashboard/stats', ['token' => $token]);
$stats = $statsRes['stats'] ?? [];
$dbCompliance = $statsRes['dbCompliance'] ?? [];
$inventory = api_get('/api/invisia/compliance/inventory', ['token' => $token]);
if (!is_array($inventory) || isset($inventory['error'])) $inventory = [];
$breaches = api_get('/api/invisia/compliance/breaches', ['token' => $token]);
if (!is_array($breaches) || isset($breaches['error'])) $breaches = [];
$config = api_get('/api/invisia/compliance/config', ['token' => $token]);
if (!is_array($config)) $config = [];
$measureOverrides = [];
if (!empty($config['measureOverrides'])) {
    $measureOverrides = is_string($config['measureOverrides']) ? json_decode($config['measureOverrides'], true) : $config['measureOverrides'];
    if (!is_array($measureOverrides)) $measureOverrides = [];
}
$alertsRes = api_post_form('/api/alerts/list', ['token' => $token, 'limit' => '50']);
$alerts = $alertsRes['alerts'] ?? [];
$pseudoRules = api_get('/api/invisia/compliance/pseudonymization', ['token' => $token]);
if (!is_array($pseudoRules) || isset($pseudoRules['error'])) $pseudoRules = [];
$consents = api_get('/api/invisia/compliance/consents', ['token' => $token]);
if (!is_array($consents) || isset($consents['error'])) $consents = [];
$trainings = api_get('/api/invisia/compliance/trainings', ['token' => $token]);
if (!is_array($trainings) || isset($trainings['error'])) $trainings = [];
$hasWaf = false;
if (!empty($user['domain'])) {
    $wafRes = api_get('/api/hardening/check-waf', ['domain' => $user['domain']]);
    $hasWaf = !empty($wafRes['waf']) || !empty($wafRes['hasWaf']);
}

// ── Definiciones de medidas (idéntico a React HARDENING_DEFS) ──
$HARDENING_DEFS = [
    ['id' => 'encryption', 'label' => 'Cifrado de Datos', 'desc' => 'Cifrado en reposo y en transito para todos los datos personales (AES-256 / TLS 1.3)', 'icon' => 'lock',
        'fields' => [
            ['key' => 'algorithm', 'type' => 'select', 'label' => 'Algoritmo de cifrado', 'options' => ['AES-256', 'AES-128', 'ChaCha20', 'Otro']],
            ['key' => 'tlsVersion', 'type' => 'select', 'label' => 'Version TLS', 'options' => ['TLS 1.3', 'TLS 1.2', 'TLS 1.1']],
            ['key' => 'scope', 'type' => 'select', 'label' => 'Alcance', 'options' => ['Todos los datos', 'Solo datos sensibles', 'Solo bases de datos']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (politica de cifrado)'],
        ]],
    ['id' => 'access_control', 'label' => 'Control de Acceso', 'desc' => 'Politica de minimo privilegio, autenticacion multifactor y revision periodica de accesos', 'icon' => 'users',
        'fields' => [
            ['key' => 'mfaEnabled', 'type' => 'select', 'label' => 'Autenticacion multifactor', 'options' => ['Si - Todos los usuarios', 'Si - Solo administradores', 'No implementado']],
            ['key' => 'accessReviewFreq', 'type' => 'select', 'label' => 'Frecuencia de revision de accesos', 'options' => ['Mensual', 'Trimestral', 'Semestral', 'Anual']],
            ['key' => 'policyUrl', 'type' => 'url', 'label' => 'URL de politica de control de acceso'],
        ]],
    ['id' => 'backup', 'label' => 'Backups Cifrados', 'desc' => 'Copias de seguridad cifradas con prueba de restauracion al menos cada 3 meses', 'icon' => 'database',
        'fields' => [
            ['key' => 'backupFreq', 'type' => 'select', 'label' => 'Frecuencia de backup', 'options' => ['Diario', 'Semanal', 'Mensual']],
            ['key' => 'encryption', 'type' => 'select', 'label' => 'Cifrado de backups', 'options' => ['Si - AES-256', 'Si - Otro', 'No']],
            ['key' => 'lastRestoreTest', 'type' => 'date', 'label' => 'Fecha ultima prueba de restauracion'],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (log de restauracion)'],
        ]],
    ['id' => 'logging', 'label' => 'Registro de Auditoria', 'desc' => 'Logs de acceso a datos personales con registro de quien, cuando y que dato fue accedido', 'icon' => 'fileText',
        'fields' => [
            ['key' => 'logScope', 'type' => 'select', 'label' => 'Alcance del logging', 'options' => ['Todos los accesos a datos personales', 'Solo accesos a datos sensibles', 'Solo accesos administrativos']],
            ['key' => 'retentionDays', 'type' => 'select', 'label' => 'Retencion de logs', 'options' => ['30 dias', '90 dias', '180 dias', '365 dias', 'Mas de 1 ano']],
            ['key' => 'siemIntegrated', 'type' => 'select', 'label' => 'Integracion SIEM', 'options' => ['Si', 'No']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL del sistema de logs o evidencia'],
        ]],
    ['id' => 'patching', 'label' => 'Gestion de Parches', 'desc' => 'Actualizacion de seguridad en menos de 30 dias para vulnerabilidades criticas', 'icon' => 'settings',
        'fields' => [
            ['key' => 'patchPolicy', 'type' => 'select', 'label' => 'Politica de parches', 'options' => ['Menos de 7 dias (criticas)', 'Menos de 30 dias (criticas)', 'Menos de 90 dias', 'Sin politica formal']],
            ['key' => 'autoPatch', 'type' => 'select', 'label' => 'Parches automaticos', 'options' => ['Habilitados', 'Solo en staging', 'Deshabilitados']],
            ['key' => 'lastPatchDate', 'type' => 'date', 'label' => 'Fecha ultimo parche critico aplicado'],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de politica de parches'],
        ]],
    ['id' => 'ids_ips', 'label' => 'IDS/IPS', 'desc' => 'Sistema de deteccion y prevencion de intrusiones en la red interna', 'icon' => 'alert',
        'fields' => [
            ['key' => 'solution', 'type' => 'select', 'label' => 'Solucion implementada', 'options' => ['Snort', 'Suricata', 'Zeek (Bro)', 'Fortinet', 'Palo Alto', 'Cisco IPS', 'Otro']],
            ['key' => 'mode', 'type' => 'select', 'label' => 'Modo de operacion', 'options' => ['Deteccion (IDS)', 'Prevencion (IPS)', 'Ambos']],
            ['key' => 'coverage', 'type' => 'select', 'label' => 'Cobertura', 'options' => ['Toda la red', 'Solo segmento de datos', 'Solo perimetro']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (configuracion SIEM/IDS)'],
        ]],
    ['id' => 'dlp', 'label' => 'DLP (Data Loss Prevention)', 'desc' => 'Prevencion de fuga de datos sensibles mediante monitoreo de salida de informacion', 'icon' => 'shield',
        'fields' => [
            ['key' => 'solution', 'type' => 'select', 'label' => 'Solucion DLP', 'options' => ['Symantec DLP', 'McAfee DLP', 'Forcepoint DLP', 'Microsoft Purview', 'Zscaler', 'Custom/Propio', 'Otro']],
            ['key' => 'channels', 'type' => 'select', 'label' => 'Canales monitoreados', 'options' => ['Email + Web + Endpoint', 'Solo Email', 'Solo Web', 'Solo Endpoint']],
            ['key' => 'alertMode', 'type' => 'select', 'label' => 'Modo de alerta', 'options' => ['Bloqueo automatico', 'Alerta + revision manual', 'Solo logging']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (politica DLP)'],
        ]],
    ['id' => 'waf', 'label' => 'WAF (Web Application Firewall)', 'desc' => 'Firewall de aplicaciones web para proteger APIs y formularios que capturan datos', 'icon' => 'globe',
        'fields' => [
            ['key' => 'provider', 'type' => 'select', 'label' => 'Proveedor WAF', 'options' => ['Cloudflare', 'AWS WAF', 'Akamai', 'Imperva', 'F5 ASM', 'ModSecurity', 'Sucuri', 'Otro']],
            ['key' => 'deployment', 'type' => 'select', 'label' => 'Despliegue', 'options' => ['Cloud (SaaS)', 'On-premise', 'Hibrido']],
            ['key' => 'ruleset', 'type' => 'select', 'label' => 'Ruleset', 'options' => ['OWASP Core Rule Set', 'Personalizado', 'Default del proveedor']],
            ['key' => 'domainProtected', 'type' => 'text', 'label' => 'Dominio protegido'],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (config WAF)'],
        ]],
    ['id' => 'pseudonymization', 'label' => 'Seudonimizacion', 'desc' => 'Tecnica de reemplazo de identificadores directos por seudonimos en bases de datos', 'icon' => 'search',
        'fields' => [
            ['key' => 'technique', 'type' => 'select', 'label' => 'Tecnica utilizada', 'options' => ['Tokenizacion', 'Hashing (SHA-256)', 'Cifrado reversible', 'Masking', 'Otro']],
            ['key' => 'scope', 'type' => 'select', 'label' => 'Alcance', 'options' => ['Todos los identificadores directos', 'Solo RUT/DNI', 'Solo emails', 'Solo datos sensibles']],
            ['key' => 'keyManagement', 'type' => 'select', 'label' => 'Gestion de claves', 'options' => ['KMS dedicado', 'HSM', 'Vault', 'Manual']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL de evidencia (reglas de seudonimizacion)'],
        ]],
    ['id' => 'incident_response', 'label' => 'Plan de Respuesta a Incidentes', 'desc' => 'Procedimiento documentado para contener, erradicar y recuperarse de brechas de seguridad', 'icon' => 'alert',
        'fields' => [
            ['key' => 'planStatus', 'type' => 'select', 'label' => 'Estado del plan', 'options' => ['Documentado y probado', 'Documentado sin probar', 'En desarrollo', 'No existe']],
            ['key' => 'lastDrill', 'type' => 'date', 'label' => 'Fecha ultimo simulacro (drill)'],
            ['key' => 'teamSize', 'type' => 'select', 'label' => 'Tamano del equipo de respuesta', 'options' => ['1-3 personas', '4-10 personas', 'Mas de 10']],
            ['key' => 'evidenceUrl', 'type' => 'url', 'label' => 'URL del plan de respuesta a incidentes'],
        ]],
];

// Crear array de campos por ID para facilitar el acceso
$MEASURE_FIELDS_BY_ID = [];
foreach ($HARDENING_DEFS as $def) {
    $MEASURE_FIELDS_BY_ID[$def['id']] = $def['fields'];
}

// ── Auto-detección (idéntico a React) ──
$hasSslDbs = false; $hasPersonalData = false; $hasScannedRecently = false;
foreach ($dbCompliance as $dbc) {
    if (!empty($dbc['tables'])) {
        $hasSslDbs = true;
        foreach ($dbc['tables'] as $t) {
            if (!empty($t['personalDataColumns']) || !empty($t['columns'])) $hasPersonalData = true;
        }
    }
    if (!empty($dbc['lastScanned']) && strtotime($dbc['lastScanned']) > time() - 7 * 86400) $hasScannedRecently = true;
}
$hasAlertsForDataDiscovery = false;
foreach ($alerts as $a) { if (($a['category'] ?? '') === 'data_discovery') $hasAlertsForDataDiscovery = true; }

$overrides = [];
if (!empty($config['measureOverrides'])) {
    $overrides = is_string($config['measureOverrides']) ? json_decode($config['measureOverrides'], true) : $config['measureOverrides'];
    if (!is_array($overrides)) $overrides = [];
}
$getOverride = function ($id) use ($overrides) {
    foreach ($overrides as $o) { if (($o['measureId'] ?? '') === $id && !empty($o['completed'])) return $o; }
    return null;
};

$onlineAgents = (int)($stats['onlineAgents'] ?? 0);
$totalDatabases = (int)($stats['totalDatabases'] ?? 0);

// ── Cálculo preciso de Hardening (solo basado en overrides marcados) ──
$measures = [];
foreach ($HARDENING_DEFS as $def) {
    $ov = $getOverride($def['id']);
    // Solo consideramos una medida como "hecha" si está marcada explícitamente en overrides
    $done = $ov !== null;
    $def['override'] = $ov;
    $def['done'] = $done;
    $measures[] = $def;
}

$hardeningDone = count(array_filter($measures, fn($m) => $m['done']));
$hardeningTotal = count($measures);
$hardeningPct = $hardeningTotal ? (int)round($hardeningDone / $hardeningTotal * 100) : 0;

// Para compatibilidad con el HTML existente
$doneCount = $hardeningDone;
$total = $hardeningTotal;

// ── Cálculo preciso de Compliance (basado en completitud real) ──
$complianceMetrics = [
    // DPD Designado: debe tener email, nombre y teléfono
    'dpd' => !empty($config['dpdEmail']) && !empty($config['dpdName']) && !empty($config['dpdPhone']),
    // Registro APDP: debe estar registrado y tener número de registro
    'apdp' => ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true) && !empty($config['apdpRegistrationNumber']),
    // Inventario: debe tener items y deben estar completos (nombre, legalBasis, dataCategories)
    'inventory' => count($inventory) > 0 && count(array_filter($inventory, fn($i) => 
        !empty($i['name']) && !empty($i['legalBasis']) && !empty($i['dataCategories'])
    )) > 0,
    // Política de Privacidad: debe tener URL pública
    'privacyPolicy' => !empty($config['privacyPolicyUrl']),
    // Consentimientos: debe haber consentimientos activos (no revocados)
    'consents' => count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0,
    // Protocolo de Brechas: debe haber protocolo documentado (breach protocol)
    'breachProtocol' => count($breaches) > 0 || !empty($config['breachProtocolUrl']),
    // Portal ARCO: siempre activo
    'arco' => true,
    // Seudonimización: debe haber reglas ejecutadas
    'pseudonymization' => count(array_filter($pseudoRules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0,
    // Plan de Respuesta a Incidentes: debe haber breaches resueltos o protocolo
    'incidentResponse' => count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved')) > 0 || !empty($config['incidentResponsePlan']),
    // Capacitación: debe haber capacitaciones completadas
    'training' => count(array_filter($trainings, fn($t) => !empty($t['completed']))) > 0,
];

$complianceDone = count(array_filter($complianceMetrics, fn($c) => $c));
$complianceTotal = count($complianceMetrics);
$compliancePct = $complianceTotal ? (int)round($complianceDone / $complianceTotal * 100) : 0;

// Usar el porcentaje de hardening para esta página
$pct = $hardeningPct;

$riskLabel = $pct >= 70 ? 'Bajo' : ($pct >= 40 ? 'Medio' : 'Alto');
$pctColor = $pct >= 70 ? 'text-emerald-400' : ($pct >= 40 ? 'text-yellow-400' : 'text-red-400');
$barColor = $pct >= 70 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-yellow-500' : 'bg-red-500');

$dpdObligations = [
    ['label' => 'Supervisar el cumplimiento normativo', 'done' => !empty($config['dpdEmail'])],
    ['label' => 'Asesorar en evaluaciones de impacto', 'done' => !empty($config['companyName'])],
    ['label' => 'Atender solicitudes de titulares', 'done' => count($breaches) > 0],
    ['label' => 'Coordinar con la APDP', 'done' => ($config['apdpRegistered'] === '1' || $config['apdpRegistered'] === true)],
    ['label' => 'Capacitar al personal', 'done' => count($inventory) > 0],
    ['label' => 'Mantener registro de actividades', 'done' => $totalDatabases > 0],
    ['label' => 'Reportar brechas a la APDP', 'done' => count(array_filter($breaches, fn($b) => !empty($b['notifiedAPDP']))) > 0],
    ['label' => 'Realizar auditorías periódicas', 'done' => (int)($stats['totalAgents'] ?? 0) > 0],
];

$breachTimeline = [
    ['time' => '0-3 horas', 'action' => 'Alerta temprana al CSIRT (Ley 21.663)', 'severity' => 'critical'],
    ['time' => 'Inmediato', 'action' => 'Contener la brecha (aislar sistemas, revocar accesos)', 'severity' => 'critical'],
    ['time' => 'Sin dilación indebida', 'action' => 'Notificar a la autoridad por los medios más expeditos posibles, según corresponda (Art. 14 sexies)', 'severity' => 'high'],
    ['time' => 'Sin dilación indebida', 'action' => 'Informar a los titulares cuando la brecha pueda afectar sus derechos, especialmente ante datos sensibles, de niños o económicos', 'severity' => 'high'],
    ['time' => '72 horas', 'action' => 'Reporte completo al CSIRT con análisis forense', 'severity' => 'medium'],
    ['time' => '10 días', 'action' => 'Documentar completamente el incidente y las acciones tomadas', 'severity' => 'medium'],
    ['time' => '30 días', 'action' => 'Implementar medidas correctivas y actualizar el plan de respuesta', 'severity' => 'low'],
];

// SVG icons (idénticos a React I)
function hIcon($name, $cls = 'w-4 h-4') {
    $paths = [
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        'xmark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        'search' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>',
        'info' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'lock' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
        'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>',
        'database' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
        'fileText' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
        'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'globe' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>',
        'pen' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>',
    ];
    return '<svg class="' . $cls . '" fill="none" viewBox="0 0 24 24" stroke="currentColor">' . ($paths[$name] ?? '') . '</svg>';
}

$tabs = [
    ['id' => 'measures', 'label' => 'Medidas Técnicas', 'icon' => 'shield'],
    ['id' => 'dpd', 'label' => 'DPD', 'icon' => 'users'],
    ['id' => 'breach', 'label' => 'Protocolo Brechas', 'icon' => 'alert'],
];

$pageTitle = 'Hardening';
$currentPage = 'hardening';
require_once __DIR__ . '/../includes/header.php';
echo $wizardCSS;
?>

<style>
input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]):not([type="button"]):not([type="file"]),
textarea,
select { background-color: #0f0f14 !important; color: #f9fafb !important; border-color: rgba(255,255,255,0.12) !important; }
::placeholder { color: #9ca3af !important; opacity: 1; }
input:-webkit-autofill,
textarea:-webkit-autofill,
select:-webkit-autofill { -webkit-text-fill-color: #f9fafb !important; -webkit-box-shadow: 0 0 0px 1000px #0f0f14 inset !important; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <!-- Header -->
        <header class="flex-shrink-0 bg-bg-base border-b border-white/[0.04]">
            <div class="w-full px-4 md:px-8 pt-4 pb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center flex-shrink-0">
                        <?= hIcon('lock', 'w-5 h-5') ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wider font-medium">Endurecimiento de seguridad</p>
                        <h1 class="text-[18px] md:text-[20px] font-bold text-text-heading tracking-tight">Hardening</h1>
                    </div>
                </div>
            </div>
            <div class="w-full px-4 md:px-8 pb-0">
                <nav class="flex gap-1 -mb-px overflow-x-auto">
                    <?php foreach ($tabs as $t): $isActive = $tab === $t['id']; ?>
                    <a href="/hardening?tab=<?= $t['id'] ?>"
                        class="flex items-center gap-1.5 px-3 py-2.5 text-[11px] font-medium border-b-2 transition-colors whitespace-nowrap <?= $isActive ? 'border-indigo-400 text-text-heading' : 'border-transparent text-text-muted hover:text-text-body hover:border-white/[0.1]' ?>">
                        <span class="<?= $isActive ? 'text-indigo-400' : 'text-text-subtle' ?>"><?= hIcon($t['icon']) ?></span>
                        <?= h($t['label']) ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto scrollbar-custom">
        <?php if ($tab === 'measures'): ?>
            <div class="p-4 md:p-8 w-full space-y-4 md:space-y-6">
                <!-- KPIs -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-5 tour-detail-1">
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 relative overflow-hidden group hover:border-indigo-500/30 transition-all duration-200">
                        <div class="absolute -right-8 -top-8 w-24 h-24 bg-indigo-500/5 rounded-full blur-xl pointer-events-none"></div>
                        <div class="absolute left-0 top-2 bottom-2 w-1.5 rounded-full bg-indigo-400"></div>
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="text-indigo-400"><?= hIcon('shield') ?></span>
                            <span class="text-[10px] text-text-subtle font-semibold uppercase tracking-wider">Medidas Implementadas</span>
                        </div>
                        <p class="text-[26px] font-bold leading-none tracking-tight text-text-heading"><?= $doneCount ?>/<?= $total ?></p>
                        <p class="text-[10px] text-text-subtle mt-2"><?= $pct ?>% completado</p>
                    </div>
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 relative overflow-hidden group hover:border-emerald-500/30 transition-all duration-200">
                        <div class="absolute -right-8 -top-8 w-24 h-24 bg-emerald-500/5 rounded-full blur-xl pointer-events-none"></div>
                        <div class="absolute left-0 top-2 bottom-2 w-1.5 rounded-full bg-emerald-400"></div>
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="text-emerald-400"><?= hIcon('check') ?></span>
                            <span class="text-[10px] text-text-subtle font-semibold uppercase tracking-wider">Cumplidas</span>
                        </div>
                        <p class="text-[26px] font-bold leading-none tracking-tight text-emerald-400"><?= $doneCount ?></p>
                    </div>
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 relative overflow-hidden group hover:border-yellow-500/30 transition-all duration-200">
                        <div class="absolute -right-8 -top-8 w-24 h-24 bg-yellow-500/5 rounded-full blur-xl pointer-events-none"></div>
                        <div class="absolute left-0 top-2 bottom-2 w-1.5 rounded-full bg-yellow-400"></div>
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="text-yellow-400"><?= hIcon('xmark') ?></span>
                            <span class="text-[10px] text-text-subtle font-semibold uppercase tracking-wider">Pendientes</span>
                        </div>
                        <p class="text-[26px] font-bold leading-none tracking-tight text-yellow-400"><?= $total - $doneCount ?></p>
                    </div>
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5 relative overflow-hidden group hover:border-cyan-500/30 transition-all duration-200">
                        <div class="absolute -right-8 -top-8 w-24 h-24 bg-cyan-500/5 rounded-full blur-xl pointer-events-none"></div>
                        <div class="absolute left-0 top-2 bottom-2 w-1.5 rounded-full bg-cyan-400"></div>
                        <div class="flex items-center gap-2.5 mb-3">
                            <span class="text-cyan-400"><?= hIcon('info') ?></span>
                            <span class="text-[10px] text-text-subtle font-semibold uppercase tracking-wider">Riesgo Residual</span>
                        </div>
                        <p class="text-[26px] font-bold leading-none tracking-tight text-cyan-400"><?= $riskLabel ?></p>
                    </div>
                </div>

                <!-- Progreso por medida -->
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-[12px] font-semibold text-white flex items-center gap-2">
                            <span class="text-indigo-400"><?= hIcon('shield') ?></span>
                            <span>Progreso por Medida</span>
                        </h4>
                        <span class="text-[10px] text-text-subtle"><?= $doneCount ?>/<?= $total ?> completadas</span>
                    </div>
                    <div class="flex gap-1.5">
                        <?php foreach ($measures as $i => $m): ?>
                        <div class="group/bar relative flex-1 min-w-0">
                            <div class="h-9 rounded-md transition-all duration-300 flex items-center justify-center cursor-default <?= $m['done'] ? 'bg-emerald-500/15 border border-emerald-500/25' : 'bg-bg-elevated/60 border border-border-theme/50 hover:border-surface-600/50' ?>">
                                <?php if ($m['done']): ?>
                                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                <?php else: ?>
                                <span class="text-text-subtle text-[9px] font-mono font-semibold"><?= $i + 1 ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="absolute -top-8 left-1/2 -translate-x-1/2 bg-bg-elevated text-white text-[9px] px-2 py-1 rounded opacity-0 group-hover/bar:opacity-100 transition-opacity whitespace-nowrap pointer-events-none z-20 border border-border-theme/50 shadow-lg">
                                <?= h($m['label']) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Medidas -->
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-6">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="text-[15px] font-semibold text-text-heading">Medidas de Seguridad Técnicas y Organizativas</h3>
                            <p class="text-[12px] text-text-muted mt-1">Art. 25 Ley 21.719 — Datos reales: <?= (int)($stats['totalAgents'] ?? 0) ?> agente(s) (<?= $onlineAgents ?> online), <?= $totalDatabases ?> base(s) de datos (<?= (int)($stats['totalTables'] ?? 0) ?> tablas, <?= (int)($stats['totalRecords'] ?? 0) ?> registros), <?= (int)($stats['totalBreaches'] ?? 0) ?> brecha(s), <?= count($inventory) ?> item(s) inventario.</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="text-[32px] font-bold leading-none <?= $pctColor ?>"><?= $pct ?>%</span>
                            <p class="text-[11px] text-text-muted mt-1">Implementado</p>
                        </div>
                    </div>
                    <div class="w-full bg-bg-elevated/50 rounded-full h-2.5 mb-6">
                        <div class="h-full rounded-full transition-all duration-700 <?= $barColor ?>" style="width: <?= $pct ?>%"></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 tour-detail-2">
                        <?php foreach ($measures as $item): ?>
                        <div class="flex items-start gap-3 p-4 rounded-lg border transition-all duration-200 <?= $item['done'] ? 'bg-emerald-500/[0.04] border-emerald-500/20' : 'bg-bg-base/40 border-border-theme/25 hover:border-surface-600/40 hover:bg-bg-panel/30' ?>">
                            <span class="mt-0.5 <?= $item['done'] ? 'text-emerald-400' : 'text-text-subtle' ?>"><?= hIcon($item['icon']) ?></span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[12px] font-medium <?= $item['done'] ? 'text-emerald-300' : 'text-text-muted' ?>"><?= h($item['label']) ?></span>
                                    <?php if ($item['done']): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border bg-emerald-500/10 text-emerald-400 border-emerald-500/20"><?= hIcon('check', 'w-3 h-3') ?> Implementado</span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-md border bg-yellow-500/10 text-yellow-400 border-yellow-500/20"><?= hIcon('xmark', 'w-3 h-3') ?> Pendiente</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[11px] text-text-subtle mt-1"><?= h($item['desc']) ?></p>
                            </div>
                            <div class="flex-shrink-0 relative ml-2 self-center flex flex-col items-center gap-1.5">
                                <?php if ($item['done']): ?>
                                <svg class="w-9 h-9" viewBox="0 0 36 36">
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-emerald-500/15" stroke-width="3"/>
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-emerald-400" stroke-width="3" stroke-dasharray="94.25" stroke-dashoffset="0" stroke-linecap="round" transform="rotate(-90 18 18)"/>
                                    <path d="M12 18l4 4 8-8" fill="none" class="stroke-emerald-400" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <div class="flex flex-col items-center gap-1">
                                    <?php if ($item['override']): ?>
                                    <?php if (!empty($item['override']['completedAt'])): ?>
                                    <span class="text-[8px] text-text-subtle"><?= date('d-m-Y', strtotime($item['override']['completedAt'])) ?></span>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <div class="flex flex-col gap-1">
                                        <button type="button" onclick="openMeasureModal('<?= h($item['id']) ?>')"
                                            class="text-[10px] px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 hover:bg-blue-500/20 transition-all font-medium whitespace-nowrap">
                                            Editar
                                        </button>
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="measure_id" value="<?= h($item['id']) ?>">
                                            <button type="submit" name="revoke_measure" value="1" class="text-[10px] px-2 py-0.5 rounded-md bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 transition-all font-medium whitespace-nowrap">Eliminar</button>
                                        </form>
                                        <button onclick="downloadMeasurePDF('<?= h($item['id']) ?>', '<?= h($item['label']) ?>')"
                                            class="text-[9px] px-2 py-0.5 rounded-md bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 transition-all font-medium whitespace-nowrap">
                                            PDF
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <svg class="w-9 h-9" viewBox="0 0 36 36">
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-surface-700" stroke-width="3"/>
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-gray-600" stroke-width="3" stroke-dasharray="94.25" stroke-dashoffset="70.69" stroke-linecap="round" transform="rotate(-90 18 18)"/>
                                </svg>
                                <div class="flex flex-col gap-1">
                                    <button type="button" onclick="openMeasureModal('<?= h($item['id']) ?>')"
                                        class="text-[10px] px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 hover:bg-blue-500/20 transition-all font-medium whitespace-nowrap">
                                        Completar
                                    </button>
                                    <button onclick="downloadMeasurePDF('<?= h($item['id']) ?>', '<?= h($item['label']) ?>')"
                                        class="text-[9px] px-2 py-0.5 rounded-md bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 transition-all font-medium whitespace-nowrap">
                                        PDF
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recomendaciones + estándares -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-indigo-400"><?= hIcon('info') ?></span>
                            <h4 class="text-[12px] font-semibold text-text-heading">Recomendaciones para Cumplir Art. 25</h4>
                        </div>
                        <ul class="space-y-2">
                            <?php foreach ([
                                'Realizar evaluaciones de impacto (EIPD) antes de nuevos tratamientos',
                                'Documentar todas las medidas de seguridad implementadas',
                                'Revisar y actualizar medidas al menos anualmente',
                                'Contratar auditorías externas de seguridad periódicas',
                                'Mantener un registro de incidentes y lecciones aprendidas',
                            ] as $rec): ?>
                            <li class="flex items-start gap-2 text-[11px] text-text-muted">
                                <span class="text-indigo-400 mt-0.5 flex-shrink-0">›</span>
                                <?= h($rec) ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-cyan-400"><?= hIcon('fileText') ?></span>
                            <h4 class="text-[12px] font-semibold text-text-heading">Estándares de Referencia</h4>
                        </div>
                        <div class="space-y-2">
                            <?php foreach ([
                                ['std' => 'ISO 27001', 'desc' => 'Sistema de Gestión de Seguridad de la Información'],
                                ['std' => 'NIST CSF', 'desc' => 'Framework de Ciberseguridad (identificar, proteger, detectar, responder, recuperar)'],
                                ['std' => 'OWASP Top 10', 'desc' => 'Riesgos de seguridad en aplicaciones web'],
                                ['std' => 'ENS Chile', 'desc' => 'Estándar Nacional de Seguridad (próximamente)'],
                            ] as $s): ?>
                            <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-base/40 border border-border-theme/25 hover:border-cyan-500/20 hover:bg-bg-panel/30 transition-all duration-200">
                                <span class="text-[12px] font-semibold text-cyan-400 font-mono"><?= h($s['std']) ?></span>
                                <span class="text-[10px] text-text-subtle"><?= h($s['desc']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'dpd'): ?>
            <div class="p-4 md:p-8 w-full space-y-4 md:space-y-6">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-3 md:p-6">
                    <div class="flex items-start gap-4 mb-6">
                        <div class="w-14 h-14 rounded-xl bg-bg-elevated/60 border border-border-theme/40 flex items-center justify-center text-indigo-400 flex-shrink-0"><?= hIcon('users', 'w-5 h-5') ?></div>
                        <div>
                            <h3 class="text-[15px] font-semibold text-text-heading">Delegado de Protección de Datos (DPD)</h3>
                            <p class="text-[12px] text-text-muted mt-1">Art. 28 Ley 21.719 — El DPD es la figura responsable de supervisar el cumplimiento normativo, asesorar en evaluaciones de impacto y actuar como punto de contacto con la APDP.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-5">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-[12px] font-semibold text-text-heading">Información del DPD</h4>
                                <button onclick="openDpdWizard()"
                                    class="flex items-center gap-1 text-[10px] text-accent hover:text-primary-300 font-medium">
                                    <?= hIcon('pen', 'w-3 h-3') ?> Registrar / Editar
                                </button>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="text-[11px] text-text-muted">Nombre</span>
                                    <span class="text-[11px] text-white font-medium"><?= h($config['dpdName'] ?? 'No designado') ?></span>
                                </div>
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="text-[11px] text-text-muted">Email</span>
                                    <span class="text-[11px] text-white font-mono"><?= h($config['dpdEmail'] ?? '-') ?></span>
                                </div>
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="text-[11px] text-text-muted">Teléfono</span>
                                    <span class="text-[11px] text-white font-medium"><?= h($config['dpdPhone'] ?? '-') ?></span>
                                </div>
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="text-[11px] text-text-muted">Nivel Cumplimiento</span>
                                    <span class="text-[11px] text-white font-medium"><?= h($config['complianceLevel'] ?? 'básico') ?></span>
                                </div>
                                <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="text-[11px] text-text-muted">Registro APDP</span>
                                    <span class="text-[11px] font-medium <?= !empty($config['apdpRegistered']) ? 'text-emerald-400' : 'text-red-400' ?>"><?= !empty($config['apdpRegistered']) ? '✓ Registrado' : '✗ No registrado' ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-5">
                            <h4 class="text-[12px] font-semibold text-white mb-3">Obligaciones del DPD</h4>
                            <div class="space-y-1.5">
                                <?php foreach ($dpdObligations as $obl): ?>
                                <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-bg-elevated/40 border border-border-theme/25">
                                    <span class="<?= $obl['done'] ? 'text-emerald-400' : 'text-text-subtle' ?> flex-shrink-0"><?= hIcon($obl['done'] ? 'check' : 'xmark') ?></span>
                                    <span class="text-[11px] text-text-muted"><?= h($obl['label']) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-5">
                        <div class="flex items-start gap-3">
                            <div class="w-9 h-9 rounded-lg bg-indigo-500/10 flex items-center justify-center text-indigo-400 flex-shrink-0"><?= hIcon('info') ?></div>
                            <div>
                                <p class="text-[12px] text-text-body font-medium mb-1">Modelo de Prevención de Infracciones</p>
                                <p class="text-[11px] text-text-subtle leading-relaxed">El Art. 28 permite adoptar voluntariamente un modelo de prevención de infracciones, que incluye la designación del DPD, la implementación de un sistema de prevención y su certificación ante la APDP. Este modelo funciona como atenuante en caso de fiscalización, pudiendo reducir significativamente las multas aplicables. En empresas pequeñas, el dueño o una jefatura puede asumir el rol de DPD.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'breach'): ?>
            <div class="p-4 md:p-8 w-full space-y-4 md:space-y-6">
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-3 md:p-6">
                    <h3 class="text-[15px] font-semibold text-white mb-1">Protocolo de Notificación de Brechas</h3>
                    <p class="text-[12px] text-text-muted mb-5">Art. 26 Ley 21.719 + Ley 21.663 Marco de Ciberseguridad. Las brechas de datos personales deben notificarse a la APDP por los medios más expeditos posibles y sin dilaciones indebidas. Tienes <span class="text-white font-medium"><?= (int)($stats['totalBreaches'] ?? 0) ?></span> brecha(s) registrada(s), <span class="text-red-400"><?= (int)($stats['openBreaches'] ?? 0) ?></span> abierta(s).<?php $activeAlerts = count(array_filter($alerts, fn($a) => ($a['status'] ?? '') === 'active')); if (count($alerts)): ?> <span class="text-cyan-400">· <?= $activeAlerts ?> alerta(s) activa(s) de seguridad</span><?php endif; ?></p>

                    <!-- Línea de tiempo (solo lectura) -->
                    <div class="relative mb-6">
                        <div class="absolute left-[17px] top-2 bottom-2 w-0.5 bg-gradient-to-b from-red-500/50 via-yellow-500/40 via-cyan-500/40 to-gray-500/30 rounded-full"></div>
                        <div class="space-y-5">
                            <?php foreach ($breachTimeline as $i => $step):
                                $sev = $step['severity'];
                                $circleCls = $sev === 'critical' ? 'border-red-500 bg-red-500/20 text-red-400 shadow-[0_0_10px_rgba(239,68,68,0.25)]'
                                    : ($sev === 'high' ? 'border-yellow-500 bg-yellow-500/20 text-yellow-400'
                                    : ($sev === 'medium' ? 'border-cyan-500 bg-cyan-500/20 text-cyan-400' : 'border-gray-600 bg-gray-600/20 text-text-muted'));
                                $timeCls = $sev === 'critical' ? 'text-red-400' : ($sev === 'high' ? 'text-yellow-400' : ($sev === 'medium' ? 'text-cyan-400' : 'text-text-muted'));
                                $badgeCls = $sev === 'critical' ? 'bg-red-500/15 text-red-400 border border-red-500/20'
                                    : ($sev === 'high' ? 'bg-yellow-500/15 text-yellow-400 border border-yellow-500/20'
                                    : ($sev === 'medium' ? 'bg-cyan-500/15 text-cyan-400 border border-cyan-500/20' : 'bg-gray-500/15 text-text-muted border border-gray-500/20'));
                            ?>
                            <div class="relative flex items-start gap-4">
                                <div class="relative flex-shrink-0 z-10">
                                    <div class="w-8 h-8 rounded-full border-2 flex items-center justify-center text-[9px] font-bold <?= $circleCls ?>">
                                        <?php if ($sev === 'critical'): ?>
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                        <?php elseif ($sev === 'high'): ?>
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01"/></svg>
                                        <?php else: ?>
                                        <span><?= $i + 1 ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($sev === 'critical'): ?>
                                    <div class="absolute -inset-1.5 rounded-full border border-red-500/30 animate-pulse"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0 pt-0.5 bg-bg-base/30 rounded-lg p-3 border border-transparent hover:border-border-theme/40 hover:bg-bg-panel/25 transition-all duration-200">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-[11px] font-bold <?= $timeCls ?>"><?= h($step['time']) ?></span>
                                        <span class="px-1.5 py-0.5 text-[8px] font-semibold rounded uppercase <?= $badgeCls ?>"><?= h($sev) ?></span>
                                    </div>
                                    <p class="text-[12px] text-text-body"><?= h($step['action']) ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    

                    <!-- Referencia legal rápida -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-red-400"><?= hIcon('alert') ?></span>
                                <h4 class="text-[12px] font-semibold text-text-heading">Ley 21.719 - APDP</h4>
                            </div>
                            <p class="text-[11px] text-text-muted leading-relaxed">Notificar a la Agencia de Protección de Datos Personales "por los medios más expeditos posibles y sin dilaciones indebidas". Si la brecha afecta datos sensibles, de niños o económicos, también debes informar a los titulares. Multas hasta 20.000 UTM (gravísimas).</p>
                        </div>
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-cyan-400"><?= hIcon('fileText') ?></span>
                                <h4 class="text-[12px] font-semibold text-text-heading">Ley 21.663 - CSIRT</h4>
                            </div>
                            <p class="text-[11px] text-text-muted leading-relaxed">Ciertas empresas deben reportar al CSIRT Nacional: alerta temprana en 3 horas, reporte completo en 72 horas. Son dos leyes distintas con dos organismos distintos. Una misma brecha puede activar dos relojes en paralelo.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        </div>

        <!-- Modal DPD Profesional (Art. 28 Ley 21.719) - Wizard Style -->
        <div id="dpd-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50 p-3 md:p-4">
            <div class="w-full max-w-3xl mx-auto bg-gradient-to-br from-bg-panel to-bg-elevated border border-border-theme rounded-2xl shadow-2xl max-h-[90vh] overflow-hidden flex flex-col animate-fade-in-up">
                <!-- Wizard Header with Progress -->
                <div class="flex-shrink-0 bg-gradient-to-r from-indigo-900/20 via-purple-900/20 to-indigo-900/20 border-b border-white/[0.08]">
                    <div class="px-6 py-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500/20 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.0 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <div>
                                    <h3 class="text-[14px] font-bold text-text-heading">Asistente de Configuración DPD</h3>
                                    <p class="text-[11px] text-text-muted">Art. 28 Ley 21.719 - Delegado de Protección de Datos</p>
                                </div>
                            </div>
                            <button onclick="closeDpdWizard()" class="text-text-muted hover:text-text-heading transition-all p-2 hover:bg-white/[0.1] rounded-xl">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        
                        <!-- Progress Steps -->
                        <div class="flex items-center justify-between relative">
                            <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-0.5 bg-white/[0.1]"></div>
                            <div id="dpd-progress-line" class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-gradient-to-r from-emerald-500 to-teal-500 transition-all duration-500" style="width: 0%"></div>
                            
                            <div class="dpd-step-indicator flex flex-col items-center relative z-10" data-step="1">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500 to-teal-500 border-2 border-emerald-400 flex items-center justify-center text-white font-bold text-[11px] shadow-lg shadow-emerald-500/25 transition-all duration-300">1</div>
                                <span class="text-[9px] text-emerald-400 mt-1.5 font-medium whitespace-nowrap">Identificación</span>
                            </div>
                            <div class="dpd-step-indicator flex flex-col items-center relative z-10" data-step="2">
                                <div class="w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300">2</div>
                                <span class="text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap">Empresa</span>
                            </div>
                            <div class="dpd-step-indicator flex flex-col items-center relative z-10" data-step="3">
                                <div class="w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300">3</div>
                                <span class="text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap">Registro APDP</span>
                            </div>
                            <div class="dpd-step-indicator flex flex-col items-center relative z-10" data-step="4">
                                <div class="w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300">4</div>
                                <span class="text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap">Obligaciones</span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Wizard Content -->
                <form method="POST" id="dpd-form" class="flex-1 overflow-y-auto scrollbar-custom">
                    <input type="hidden" name="save_dpd" value="1">
                    
                    <div class="p-6">
                        <!-- Step 1: Identificación -->
                        <div class="dpd-step-content" data-step="1">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-[10px]">1</span>
                                    Identificación del DPD
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Complete la información personal del Delegado de Protección de Datos</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-emerald-500/[0.03] to-transparent border border-emerald-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            Nombre completo <span class="text-red-400">*</span>
                                        </label>
                                        <input name="dpdName" value="<?= h($config['dpdName'] ?? '') ?>" required placeholder="Juan Pérez González"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all placeholder-text-subtle">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            RUT <span class="text-red-400">*</span>
                                        </label>
                                        <input name="dpdRut" id="dpdRut" value="<?= h($config['dpdRut'] ?? '') ?>" required placeholder="12.345.678-9"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all placeholder-text-subtle font-mono" pattern="[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]{1}">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            Email <span class="text-red-400">*</span>
                                        </label>
                                        <input name="dpdEmail" value="<?= h($config['dpdEmail'] ?? '') ?>" required placeholder="dpd@empresa.cl" type="email"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all placeholder-text-subtle">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            Teléfono <span class="text-red-400">*</span>
                                        </label>
                                        <input name="dpdPhone" value="<?= h($config['dpdPhone'] ?? '') ?>" required placeholder="+56 9 1234 5678"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all placeholder-text-subtle font-mono">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Cargo oficial</label>
                                        <select name="dpdTitle" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all">
                                            <option value="dpd">Delegado de Protección de Datos (DPD)</option>
                                            <option value="dpd_adjunto">DPD Adjunto / Suplente</option>
                                            <option value="privacy_officer">Privacy Officer / Chief Privacy Officer</option>
                                            <option value="legal_counsel">Abogado / Asesor Legal</option>
                                            <option value="ciso">CISO / Jefe Seguridad Información</option>
                                            <option value="otro">Otro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Empresa y Contacto -->
                        <div class="dpd-step-content hidden" data-step="2">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-blue-500/20 flex items-center justify-center text-blue-400 text-[10px]">2</span>
                                    Empresa y Contacto Oficial
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Art. 28.3 - Información para publicación de datos de contacto</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-blue-500/[0.03] to-transparent border border-blue-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            Empresa / Responsable <span class="text-red-400">*</span>
                                        </label>
                                        <input name="companyName" value="<?= h($config['companyName'] ?? '') ?>" required placeholder="Nombre legal de la empresa"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/20 transition-all placeholder-text-subtle">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">RUT Empresa</label>
                                        <input name="companyRut" id="companyRut" value="<?= h($config['companyRut'] ?? '') ?>" placeholder="76.123.456-7"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/20 transition-all placeholder-text-subtle font-mono">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Dirección sede DPD</label>
                                        <input name="dpdAddress" value="<?= h($config['dpdAddress'] ?? '') ?>" placeholder="Calle 123, Oficina 456, Santiago"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/20 transition-all placeholder-text-subtle">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">URL publicación DPD</label>
                                        <input name="dpdPublicUrl" value="<?= h($config['dpdPublicUrl'] ?? '') ?>" placeholder="https://empresa.cl/dpd"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/20 transition-all placeholder-text-subtle font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Registro APDP -->
                        <div class="dpd-step-content hidden" data-step="3">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-amber-500/20 flex items-center justify-center text-amber-400 text-[10px]">3</span>
                                    Registro APDP y Nivel de Cumplimiento
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Art. 31 - Inscripción obligatoria en Registro APDP</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-amber-500/[0.03] to-transparent border border-amber-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            ¿Registrado en APDP? <span class="text-red-400">*</span>
                                        </label>
                                        <select name="apdpRegistered" required class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all">
                                            <option value="1" <?= !empty($config['apdpRegistered']) ? 'selected' : '' ?>>Sí - Registrado</option>
                                            <option value="0" <?= empty($config['apdpRegistered']) ? 'selected' : '' ?>>No - Pendiente registro</option>
                                            <option value="en_proceso">En proceso de registro</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Nº Registro APDP</label>
                                        <input name="apdpRegistrationNumber" value="<?= h($config['apdpRegistrationNumber'] ?? '') ?>" placeholder="APDP-2024-001234"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder-text-subtle font-mono">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Fecha registro</label>
                                        <input name="apdpRegistrationDate" value="<?= h($config['apdpRegistrationDate'] ?? '') ?>" type="date"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            Nivel de cumplimiento <span class="text-red-400">*</span>
                                        </label>
                                        <select name="complianceLevel" required class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all">
                                            <option value="basico" <?= ($config['complianceLevel'] ?? '') === 'basico' ? 'selected' : '' ?>>Básico</option>
                                            <option value="intermedio" <?= ($config['complianceLevel'] ?? '') === 'intermedio' ? 'selected' : '' ?>>Intermedio</option>
                                            <option value="avanzado" <?= ($config['complianceLevel'] ?? '') === 'avanzado' ? 'selected' : '' ?>>Avanzado</option>
                                            <option value="certificado" <?= ($config['complianceLevel'] ?? '') === 'certificado' ? 'selected' : '' ?>>Certificado (Modelo Prevención)</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Fecha certificación</label>
                                        <input name="preventionModelDate" value="<?= h($config['preventionModelDate'] ?? '') ?>" type="date"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Certificado por</label>
                                        <input name="preventionModelCertifier" value="<?= h($config['preventionModelCertifier'] ?? '') ?>" placeholder="Entidad certificadora"
                                            class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-amber-500 focus:ring-1 focus:ring-amber-500/20 transition-all placeholder-text-subtle">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Obligaciones -->
                        <div class="dpd-step-content hidden" data-step="4">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-purple-500/20 flex items-center justify-center text-purple-400 text-[10px]">4</span>
                                    Obligaciones del DPD y Evidencia
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Art. 28 - Seleccione las obligaciones cumplidas y adjunte evidencias</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-purple-500/[0.03] to-transparent border border-purple-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="supervision" <?= (!empty($config['obligation_supervision']) || !empty($config['dpdEmail'])) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Supervisar cumplimiento normativo</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="eipd_advice" <?= !empty($config['obligation_eipd']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Asesorar en EIPD (Art. 29)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="arco_attention" <?= !empty($config['obligation_arco']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Atender solicitudes ARCO (Art. 8-13)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="apdp_coordination" <?= !empty($config['apdpRegistered']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Coordinar con APDP (Art. 28.2)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="training" <?= !empty($config['obligation_training']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Capacitar al personal (Art. 28.c)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="register_activities" <?= !empty($config['obligation_register']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Mantener RAT (Art. 14)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="breach_reporting" <?= !empty($config['obligation_breach']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Reportar brechas a APDP (Art. 26)</span>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 rounded-lg bg-bg-base/40 border border-border-theme hover:border-purple-500/30 hover:bg-bg-base/60 transition-all cursor-pointer group">
                                        <input type="checkbox" name="obligations[]" value="audits" <?= !empty($config['obligation_audits']) ? 'checked' : '' ?> class="w-4 h-4 rounded border-border-theme text-purple-500 focus:ring-purple-500/20 transition-all">
                                        <span class="text-[11px] text-text-body group-hover:text-text-heading transition-colors">Auditorías periódicas</span>
                                    </label>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-medium text-text-body">URL de evidencias</label>
                                    <input name="dpdEvidenceUrl" value="<?= h($config['dpdEvidenceUrl'] ?? '') ?>" placeholder="https://drive.empresa.cl/dpd-evidencias"
                                        class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500/20 transition-all placeholder-text-subtle font-mono">
                                    <p class="text-[10px] text-text-subtle">Certificados, actas, reportes y documentación de cumplimiento</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wizard Footer -->
                    <div class="flex-shrink-0 px-6 py-4 bg-bg-elevated/50 border-t border-white/[0.08]">
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="closeDpdWizard()" id="dpd-cancel-btn"
                                class="px-5 py-2.5 text-[11px] font-medium rounded-xl bg-bg-base text-text-body border border-border-theme hover:bg-bg-elevated hover:border-surface-600 hover:text-text-heading transition-all flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Cancelar
                            </button>
                            <div class="flex items-center gap-3">
                                <button type="button" onclick="prevDpdStep()" id="dpd-prev-btn" class="hidden px-5 py-2.5 text-[11px] font-medium rounded-xl bg-bg-base text-text-body border border-border-theme hover:bg-bg-elevated hover:border-surface-600 hover:text-text-heading transition-all flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    Anterior
                                </button>
                                <button type="button" onclick="nextDpdStep()" id="dpd-next-btn" class="px-6 py-2.5 text-[11px] font-semibold rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white transition-all flex items-center gap-2 shadow-lg shadow-indigo-500/25 hover:shadow-indigo-500/35">
                                    Siguiente
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                <button type="submit" id="dpd-submit-btn" class="hidden px-6 py-2.5 text-[11px] font-semibold rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all flex items-center gap-2 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/35">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Guardar Configuración
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Medida Profesional (Art. 25 Ley 21.719 - Medidas de Seguridad) - Wizard Style -->
        <div id="measure-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm flex items-center justify-center z-50 p-3 md:p-4">
            <div class="w-full max-w-4xl mx-auto bg-gradient-to-br from-bg-panel to-bg-elevated border border-border-theme rounded-2xl shadow-2xl max-h-[90vh] overflow-hidden flex flex-col animate-fade-in-up">
                <!-- Wizard Header with Progress -->
                <div class="flex-shrink-0 bg-gradient-to-r from-indigo-900/20 via-purple-900/20 to-cyan-900/20 border-b border-white/[0.08]">
                    <div class="px-6 py-5">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500/20 to-purple-500/20 border border-indigo-500/30 flex items-center justify-center" id="measure-modal-icon">
                                </div>
                                <div>
                                    <h3 class="text-[14px] font-bold text-text-heading" id="measure-modal-title"></h3>
                                    <p class="text-[11px] text-text-muted">Implementar medida de seguridad — Art. 25 Ley 21.719</p>
                                </div>
                            </div>
                            <button onclick="closeMeasureModal()" class="text-text-muted hover:text-text-heading transition-all p-2 hover:bg-white/[0.1] rounded-xl">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        
                        <!-- Progress Steps -->
                        <div class="flex items-center justify-between relative">
                            <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-0.5 bg-white/[0.1]"></div>
                            <div id="measure-progress-line" class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-gradient-to-r from-indigo-500 to-purple-500 transition-all duration-500" style="width: 0%"></div>
                            
                            <div class="measure-step-indicator flex flex-col items-center relative z-10" data-step="1">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 border-2 border-indigo-400 flex items-center justify-center text-white font-bold text-[11px] shadow-lg shadow-indigo-500/25 transition-all duration-300">1</div>
                                <span class="text-[9px] text-indigo-400 mt-1.5 font-medium whitespace-nowrap">Detalles</span>
                            </div>
                            <div class="measure-step-indicator flex flex-col items-center relative z-10" data-step="2">
                                <div class="w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300">2</div>
                                <span class="text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap">Evidencia</span>
                            </div>
                            <div class="measure-step-indicator flex flex-col items-center relative z-10" data-step="3">
                                <div class="w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300">3</div>
                                <span class="text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap">Verificación</span>
                            </div>
                        </div>
                    </div>
                </div>
                <form method="POST" id="measure-form" class="flex-1 overflow-y-auto scrollbar-custom">
                    <input type="hidden" name="measure_id" id="measure-modal-id">
                    
                    <div class="p-6">
                        <!-- Description -->
                        <div class="mb-5 rounded-xl bg-gradient-to-br from-indigo-500/[0.05] to-transparent border border-indigo-500/10 px-4 py-3">
                            <p class="text-[11px] text-text-body leading-relaxed" id="measure-modal-desc"></p>
                        </div>

                        <!-- Step 1: Detalles de Implementación -->
                        <div class="measure-step-content" data-step="1">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-indigo-500/20 flex items-center justify-center text-indigo-400 text-[10px]">1</span>
                                    Detalles de la Implementación
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Configure los parámetros técnicos de la medida de seguridad</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-indigo-500/[0.03] to-transparent border border-indigo-500/10 rounded-xl p-5 space-y-4" id="measure-modal-fields">
                                <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-widest">Complete la información requerida</p>
                                
                                <!-- Campos estáticos para incident_response -->
                                <div id="incident-response-fields" class="hidden space-y-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Estado del plan</label>
                                        <select name="field_planStatus" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/20 transition-all">
                                            <option value="">Seleccionar...</option>
                                            <option value="Documentado y probado">Documentado y probado</option>
                                            <option value="Documentado sin probar">Documentado sin probar</option>
                                            <option value="En desarrollo">En desarrollo</option>
                                            <option value="No existe">No existe</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Fecha último simulacro (drill)</label>
                                        <input type="date" name="field_lastDrill" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/20 transition-all">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Tamaño del equipo de respuesta</label>
                                        <select name="field_teamSize" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/20 transition-all">
                                            <option value="">Seleccionar...</option>
                                            <option value="1-3 personas">1-3 personas</option>
                                            <option value="4-10 personas">4-10 personas</option>
                                            <option value="Más de 10">Más de 10</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">URL del plan de respuesta a incidentes</label>
                                        <input type="url" name="field_evidenceUrl" placeholder="https://empresa.cl/plan-incidentes" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/20 transition-all font-mono">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Evidencia y Validación -->
                        <div class="measure-step-content hidden" data-step="2">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-[10px]">2</span>
                                    Evidencia y Validación
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Art. 25 - Demostrabilidad de la implementación</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-emerald-500/[0.03] to-transparent border border-emerald-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body flex items-center gap-1">
                                            URL de evidencia
                                        </label>
                                        <input type="url" name="field_evidenceUrl" placeholder="https://gitlab.empresa.cl/seguridad/politica-cifrado" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all font-mono">
                                        <p class="text-[10px] text-text-subtle">Políticas, configs, logs, certificados, reportes</p>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Tipo de evidencia</label>
                                        <select name="field_evidenceType" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all">
                                            <option value="politica">Política / Procedimiento documentado</option>
                                            <option value="config">Configuración técnica (screenshot, export)</option>
                                            <option value="log">Logs de auditoría / SIEM</option>
                                            <option value="certificado">Certificación externa (ISO 27001, SOC 2)</option>
                                            <option value="test">Resultado de prueba / Pen test</option>
                                            <option value="contrato">Contrato con proveedor / Encargado</option>
                                            <option value="otro">Otro</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Responsable implementación</label>
                                        <input type="text" name="field_implementer" placeholder="Nombre / Cargo / Equipo" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Fecha implementación</label>
                                        <input type="date" name="field_implementedAt" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all" value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Próxima revisión</label>
                                        <input type="date" name="field_nextReview" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all" placeholder="Recomendado: 12 meses">
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-[11px] font-medium text-text-body">Notas adicionales</label>
                                    <textarea name="notes" placeholder="Comentarios, limitaciones, excepciones, riesgos residuales..." rows="3" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 transition-all resize-none"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Verificación de Efectividad -->
                        <div class="measure-step-content hidden" data-step="3">
                            <div class="mb-5">
                                <h4 class="text-[13px] font-semibold text-text-heading flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-cyan-500/20 flex items-center justify-center text-cyan-400 text-[10px]">3</span>
                                    Verificación de Efectividad
                                </h4>
                                <p class="text-[11px] text-text-muted mt-1">Art. 25.2 - Revisión periódica de la medida</p>
                            </div>
                            
                            <div class="bg-gradient-to-br from-cyan-500/[0.03] to-transparent border border-cyan-500/10 rounded-xl p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">¿Probada/Verificada?</label>
                                        <select name="field_verified" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                                            <option value="no">No verificada</option>
                                            <option value="si_interna">Sí - Verificación interna</option>
                                            <option value="si_externa">Sí - Auditoría externa</option>
                                            <option value="continua">Monitoreo continuo (SIEM/SOC)</option>
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Fecha última verificación</label>
                                        <input type="date" name="field_lastVerified" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Resultado verificación</label>
                                        <select name="field_verificationResult" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                                            <option value="exitosa">Exitosa - Cumple objetivo</option>
                                            <option value="parcial">Parcial - Mejoras necesarias</option>
                                            <option value="fallida">Fallida - No cumple</option>
                                            <option value="pendiente">Pendiente</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Hallazgos / Observaciones</label>
                                        <textarea name="field_findings" rows="2" placeholder="Hallazgos de la verificación, acciones correctivas..." class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all resize-none"></textarea>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-[11px] font-medium text-text-body">Próxima verificación programada</label>
                                        <input type="date" name="field_nextVerification" class="w-full bg-bg-base/80 border border-border-theme text-[12px] text-white rounded-lg px-4 py-2.5 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wizard Footer -->
                    <div class="flex-shrink-0 px-6 py-4 bg-bg-elevated/50 border-t border-white/[0.08]">
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="closeMeasureModal()"
                                class="px-5 py-2.5 text-[11px] font-medium rounded-xl bg-bg-base text-text-body border border-border-theme hover:bg-bg-elevated hover:border-surface-600 hover:text-text-heading transition-all flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Cancelar
                            </button>
                            <div class="flex items-center gap-3">
                                <button type="button" onclick="prevMeasureStep()" id="measure-prev-btn" class="hidden px-5 py-2.5 text-[11px] font-medium rounded-xl bg-bg-base text-text-body border border-border-theme hover:bg-bg-elevated hover:border-surface-600 hover:text-text-heading transition-all flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    Anterior
                                </button>
                                <button type="button" onclick="nextMeasureStep()" id="measure-next-btn" class="px-6 py-2.5 text-[11px] font-semibold rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white transition-all flex items-center gap-2 shadow-lg shadow-indigo-500/25 hover:shadow-indigo-500/35">
                                    Siguiente
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                <button type="submit" name="save_measure" value="1" id="measure-submit-btn" class="hidden px-6 py-2.5 text-[11px] font-semibold rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white transition-all flex items-center gap-2 shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/35">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Marcar como Implementada
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </main>
</div>

<script>
try {
    console.log('HARDENING.PHP LOADED - ' + new Date().toISOString());
    console.log('File version: 2024-08-28-clean');

    // Definiciones de medidas - JSON simplificado
    const MEASURE_DEFS_RAW = <?= json_encode($HARDENING_DEFS, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const MEASURE_DEFS = MEASURE_DEFS_RAW.map(d => ({
        id: d.id,
        label: d.label,
        desc: d.desc,
        fields: d.fields
    }));

    console.log('MEASURE_DEFS loaded:', MEASURE_DEFS ? MEASURE_DEFS.length + ' measures' : 'ERROR');

    window.MEASURE_OVERRIDES = <?= json_encode($measureOverrides, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];

    // Funciones globales definidas al final para asegurar disponibilidad
window.currentMeasureStep = 1;
window.totalMeasureSteps = 3;
window.currentMeasureData = {};

// Información legal sobre la Ley 21.719
const leyInfo = {
    1: {
        title: 'Detalles de la Implementación',
        subtitle: 'Art. 25 Ley 21.719 - Medidas de Seguridad',
        description: 'Las organizaciones deben implementar medidas técnicas y organizativas apropiadas para garantizar un nivel de seguridad adecuado al riesgo.',
        instructions: 'Configure los parámetros técnicos de la medida de seguridad seleccionada.'
    },
    2: {
        title: 'Evidencia de Cumplimiento',
        subtitle: 'Art. 25 Ley 21.719 - Documentación',
        description: 'Las medidas de seguridad implementadas deben estar documentadas y ser objeto de revisión y actualización periódica.',
        instructions: 'Adjunte la evidencia que demuestre la implementación de la medida de seguridad (URL de políticas, logs, configuraciones).'
    },
    3: {
        title: 'Verificación y Validación',
        subtitle: 'Art. 25 Ley 21.719 - Evaluación',
        description: 'Las medidas deben ser evaluadas regularmente para verificar su eficacia y actualizadas cuando sea necesario.',
        instructions: 'Confirme la verificación de la implementación y su eficacia.'
    }
};

window.openMeasureModal = function(id, fields) {
    console.log('openMeasureModal called with id:', id, 'fields:', fields);
    
    const modal = document.getElementById('measure-modal');
    if (!modal) {
        console.error('Modal not found');
        return;
    }
    
    modal.classList.remove('hidden');
    
    const modalId = document.getElementById('measure-modal-id');
    if (modalId) modalId.value = id;
    
    // Buscar la medida en MEASURE_DEFS si está disponible
    let def = null;
    if (typeof MEASURE_DEFS !== 'undefined') {
        def = MEASURE_DEFS.find(d => d.id === id);
    }
    
    // Si no se encontró en MEASURE_DEFS, usar los campos pasados como parámetro
    if (!def && fields) {
        def = { id: id, label: id, desc: '', fields: fields };
    }
    
    console.log('Using def:', def);
    
    // Load existing override data for editing
    const overrides = (typeof window.MEASURE_OVERRIDES !== 'undefined' ? window.MEASURE_OVERRIDES : []);
    const override = overrides.find(o => (o.measureId || '') === id) || null;
    
    // Guardar datos actuales
    window.currentMeasureData = { id: id, def: def, override: override };
    
    const modalTitle = document.getElementById('measure-modal-title');
    if (modalTitle) modalTitle.textContent = def ? def.label : id;
    
    const modalDesc = document.getElementById('measure-modal-desc');
    if (modalDesc) modalDesc.textContent = def ? def.desc : '';
    
    // Actualizar información legal del paso actual
    updateStepInfo(1);
    
    // Actualizar contenido del paso
    updateStepContent(1);
    
    // Pre-fill saved values in all wizard steps
    setTimeout(fillMeasureFields, 0);
    
    window.currentMeasureStep = 1;
    window.updateMeasureWizardUI();
};

function fillMeasureFields() {
    const data = window.currentMeasureData.override;
    if (!data) return;
    const form = document.getElementById('measure-form');
    if (!form) return;
    const fieldData = (typeof data.fieldData === 'string') ? JSON.parse(data.fieldData || '{}') : (data.fieldData || {});
    if (data.notes) {
        const notes = form.querySelector('[name="notes"]');
        if (notes) notes.value = data.notes;
    }
    Object.keys(fieldData).forEach(key => {
        const el = form.querySelector('[name="field_' + key + '"]');
        if (!el) return;
        if (el.type === 'checkbox') {
            el.checked = fieldData[key] === '1' || fieldData[key] === true || fieldData[key] === 'on';
        } else if (el.tagName === 'SELECT' && el.multiple) {
            const values = Array.isArray(fieldData[key]) ? fieldData[key] : [fieldData[key]];
            Array.from(el.options).forEach(opt => {
                opt.selected = values.includes(opt.value);
            });
        } else if (el.tagName === 'SELECT') {
            el.value = fieldData[key] || '';
            if (el.value !== fieldData[key] && el.value === '') {
                // Try matching case-insensitively for robustness
                const val = String(fieldData[key]).toLowerCase();
                Array.from(el.options).forEach(opt => {
                    if (opt.value.toLowerCase() === val || opt.text.toLowerCase() === val) {
                        el.value = opt.value;
                    }
                });
            }
        } else {
            el.value = fieldData[key] || '';
        }
    });
}

function updateStepInfo(step) {
    const info = leyInfo[step];
    if (!info) return;
    
    const stepTitle = document.getElementById('measure-step-title');
    const stepSubtitle = document.getElementById('measure-step-subtitle');
    const stepDesc = document.getElementById('measure-step-desc');
    const stepInstructions = document.getElementById('measure-step-instructions');
    
    if (stepTitle) stepTitle.textContent = info.title;
    if (stepSubtitle) stepSubtitle.textContent = info.subtitle;
    if (stepDesc) stepDesc.textContent = info.description;
    if (stepInstructions) stepInstructions.textContent = info.instructions;
}

window.closeMeasureModal = function() {
    const modal = document.getElementById('measure-modal');
    if (modal) modal.classList.add('hidden');
};

window.nextMeasureStep = function() {
    if (window.currentMeasureStep < window.totalMeasureSteps) {
        window.currentMeasureStep++;
        updateStepContent(window.currentMeasureStep);
        updateStepInfo(window.currentMeasureStep);
        window.updateMeasureWizardUI();
    }
};

window.prevMeasureStep = function() {
    if (window.currentMeasureStep > 1) {
        window.currentMeasureStep--;
        updateStepContent(window.currentMeasureStep);
        updateStepInfo(window.currentMeasureStep);
        window.updateMeasureWizardUI();
    }
};

function updateStepContent(step) {
    const wrap = document.getElementById('measure-modal-fields');
    const stepContents = document.querySelectorAll('.measure-step-content');
    if (!wrap) return;

    const def = window.currentMeasureData.def;

    // Mostrar/ocultar contenedores de cada paso y quitar required de campos ocultos
    stepContents.forEach(el => {
        const s = parseInt(el.dataset.step || '0', 10);
        const active = s === step;
        el.classList.toggle('hidden', !active);
        if (!active) {
            el.querySelectorAll('[required]').forEach(inp => inp.removeAttribute('required'));
        }
    });

    if (step === 1) {
        // Paso 1: Detalles de implementación
        if (def && def.fields && def.fields.length > 0) {
            wrap.innerHTML = '<div class="space-y-4">' +
                '<div class="bg-indigo-500/10 border border-indigo-500/20 rounded-lg p-4">' +
                '<p class="text-[11px] text-indigo-300 mb-2"><strong>Art. 25 Ley 21.719:</strong> Las organizaciones deben adoptar las medidas de seguridad adecuadas al riesgo.</p>' +
                '</div>' +
                def.fields.map(f => {
                    let input = '<input type="text" name="field_' + f.key + '" required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all">';
                    if (f.type === 'select') {
                        input = '<select name="field_' + f.key + '" required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all"><option value="">Seleccionar...</option>' +
                            f.options.map(o => '<option value="' + o + '">' + o + '</option>').join('') + '</select>';
                    } else if (f.type === 'date') {
                        input = '<input type="date" name="field_' + f.key + '" required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all">';
                    } else if (f.type === 'url') {
                        input = '<input type="url" name="field_' + f.key + '" placeholder="https://..." required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all">';
                    }
                    return '<div class="space-y-2"><label class="text-[11px] font-medium text-text-heading block">' + f.label + '</label>' + input + '</div>';
                }).join('') + '</div>';
        } else {
            // Campos genéricos si no hay campos definidos
            wrap.innerHTML = '<div class="space-y-4">' +
                '<div class="bg-indigo-500/10 border border-indigo-500/20 rounded-lg p-4 mb-4">' +
                '<p class="text-[11px] text-indigo-300"><strong>Art. 25 Ley 21.719:</strong> Esta medida debe estar documentada. Complete la siguiente información:</p>' +
                '</div>' +
                '<div class="space-y-3">' +
                '<div class="space-y-2"><label class="text-[11px] font-medium text-text-heading block">Fecha de implementación</label><input type="date" name="implementation_date" required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all"></div>' +
                '<div class="space-y-2"><label class="text-[11px] font-medium text-text-heading block">Responsable de implementación</label><input type="text" name="responsible" required placeholder="Nombre del responsable" class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all"></div>' +
                '<div class="space-y-2"><label class="text-[11px] font-medium text-text-heading block">URL de evidencia</label><input type="url" name="evidence_url" placeholder="https://..." required class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-4 py-3 focus:outline-none focus:border-indigo-500 transition-all"></div>' +
                '</div>';
        }
    }
}

window.updateMeasureWizardUI = function() {
    const indicators = document.querySelectorAll('.measure-step-indicator');
    if (indicators.length === 0) return;
    
    indicators.forEach((ind, idx) => {
        const step = idx + 1;
        const circle = ind.querySelector('div');
        const label = ind.querySelector('span');
        
        if (step < window.currentMeasureStep) {
            circle.className = 'w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 border-2 border-indigo-400 flex items-center justify-center text-white font-bold text-[11px]';
            circle.innerHTML = '✓';
            if (label) label.className = 'text-[9px] text-indigo-400 mt-1.5 font-medium whitespace-nowrap';
        } else if (step === window.currentMeasureStep) {
            circle.className = 'w-8 h-8 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 border-2 border-indigo-400 flex items-center justify-center text-white font-bold text-[11px]';
            circle.innerHTML = step;
            if (label) label.className = 'text-[9px] text-indigo-400 mt-1.5 font-medium whitespace-nowrap';
        } else {
            circle.className = 'w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px]';
            circle.innerHTML = step;
            if (label) label.className = 'text-[9px] text-text-subtle mt-1.5 font-medium whitespace-nowrap';
        }
    });
    
    const prevBtn = document.getElementById('measure-prev-btn');
    const nextBtn = document.getElementById('measure-next-btn');
    const submitBtn = document.getElementById('measure-submit-btn');
    
    if (prevBtn) prevBtn.classList.toggle('hidden', window.currentMeasureStep === 1);
    if (nextBtn) nextBtn.classList.toggle('hidden', window.currentMeasureStep === window.totalMeasureSteps);
    if (submitBtn) submitBtn.classList.toggle('hidden', window.currentMeasureStep !== window.totalMeasureSteps);
};

window.downloadMeasurePDF = function(measureId, measureLabel) {
    const def = (typeof MEASURE_DEFS !== 'undefined' && MEASURE_DEFS.find(d => d.id === measureId)) ||
                (typeof window.currentMeasureData !== 'undefined' && window.currentMeasureData.def) ||
                { label: measureLabel, desc: 'Medida de seguridad' };

    const overrides = (typeof window.MEASURE_OVERRIDES !== 'undefined' ? window.MEASURE_OVERRIDES : []);
    const override = overrides.find(o => (o.measureId || '') === measureId) || {};
    let fieldData = (typeof override.fieldData === 'string') ? JSON.parse(override.fieldData || '{}') : (override.fieldData || {});

    const escapeHtml = (text) => {
        if (text === null || text === undefined) return '';
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    let rows = '';
    if (Object.keys(fieldData).length === 0) {
        rows = '<tr><td colspan="2" style="padding:10px;border:1px solid #000;">No hay datos registrados para esta medida.</td></tr>';
    } else {
        Object.keys(fieldData).forEach(key => {
            const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            rows += '<tr><td style="padding:10px;border:1px solid #000;font-weight:bold;width:40%;">' + escapeHtml(label) + '</td><td style="padding:10px;border:1px solid #000;">' + escapeHtml(fieldData[key]) + '</td></tr>';
        });
    }

    const evidence = escapeHtml(override.evidence || '');
    const notes = escapeHtml(override.notes || '');
    const completedAt = override.completedAt ? new Date(override.completedAt).toLocaleDateString('es-CL') : 'No registrado';

    // Generar HTML formal con información legal - SIN transparencias, máximo contraste
    const html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' + escapeHtml(def.label) + '</title><style>body{font-family:"Times New Roman",Times,serif;font-size:14px;line-height:1.8;color:#000000;max-width:800px;margin:0 auto;padding:40px;background-color:#ffffff;}.header{text-align:center;border-bottom:3px solid #000000;padding-bottom:20px;margin-bottom:30px;}.header h1{color:#000000;font-size:22px;margin:0 0 10px 0;font-weight:bold;text-transform:uppercase;letter-spacing:1px;}.header p{color:#000000;font-size:14px;margin:5px 0;}.section{margin-bottom:25px;page-break-inside:avoid;background-color:#ffffff;padding:20px 0;}.section h2{color:#000000;font-size:16px;border-bottom:2px solid #000000;padding-bottom:8px;margin-bottom:15px;font-weight:bold;text-transform:uppercase;}.legal-box{background-color:#eeeeee;padding:20px;margin:20px 0;border-left:4px solid #000000;}.legal-box h3{color:#000000;margin:0 0 10px 0;font-size:15px;}.legal-box p{color:#111111;margin:0;font-size:13px;line-height:1.6;}.footer{margin-top:40px;padding-top:20px;border-top:2px solid #000000;text-align:center;padding-bottom:20px;}.footer p{color:#000000;margin:5px 0;font-size:11px;}table{width:100%;border-collapse:collapse;margin:15px 0;}td,th{border:1px solid #000;padding:10px;text-align:left;}</style></head><body><div class="header"><h1>' + escapeHtml(def.label) + '</h1><p>Ley 21.719 - Protección de Datos Personales</p><p><strong>SecureLab Admin</strong></p></div><div class="section"><h2>Descripción</h2><p style="color:#000000;font-size:14px;">' + escapeHtml(def.desc) + '</p></div><div class="legal-box"><h3>Marco Legal - Artículo 25 Ley 21.719</h3><p>Las organizaciones responsables de bases de datos deberán adoptar las medidas técnicas y organizativas necesarias para garantizar la seguridad de los datos personales, impidiendo accesos no autorizados, su destrucción, pérdida o alteración accidental o ilícita.</p></div><div class="section"><h2>Datos Registrados</h2><table>' + rows + '</table></div><div class="section"><h2>Evidencia y Notas</h2><p><strong>URL de evidencia:</strong> ' + (evidence ? evidence : 'No registrada') + '</p><p><strong>Notas:</strong> ' + (notes ? notes : 'Sin notas') + '</p><p><strong>Fecha de completitud:</strong> ' + completedAt + '</p></div><div class="footer"><p>Documento generado automáticamente por SecureLab</p><p>' + new Date().toLocaleDateString('es-CL') + '</p></div></body></html>'; // teString('es-CL') + '</p></div><div class="footer"><p>Este documento ha sido generado automáticamente por SecureLab</p><p>Fecha de generación: ' + new Date().toLocaleString('es-CL') + '</p><p>Este documento es válido como evidencia del cumplimiento de la Ley 21.719</p></div></body></html>';
    
    // Usar html2pdf.js para generar PDF directamente
    if (typeof html2pdf !== 'undefined') {
        const element = document.createElement('div');
        element.innerHTML = html;
        document.body.appendChild(element);
        
        const opt = {
            margin: [10, 10, 10, 10],
            filename: 'medida-' + measureId + '.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { 
                scale: 2,
                useCORS: true,
                letterRendering: true
            },
            jsPDF: { 
                unit: 'mm', 
                format: 'a4', 
                orientation: 'portrait'
            },
            pagebreak: { mode: 'avoid-all', before: '.section' }
        };
        
        html2pdf().set(opt).from(element).save();
        
        // Remover elemento temporal
        setTimeout(() => {
            document.body.removeChild(element);
        }, 1000);
    } else {
        alert('Error: librería html2pdf no está cargada. Por favor recargue la página.');
    }
};

function downloadHTML(html, measureId) {
    const blob = new Blob([html], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'medida-' + measureId + '.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// DPD Wizard functions
window.openDpdWizard = function(step) {
    const modal = document.getElementById('dpd-modal');
    if (modal) modal.classList.remove('hidden');
    window.currentDpdStep = (step && step >= 1 && step <= 4) ? step : 1;
    updateDpdWizardUI();
};

window.closeDpdWizard = function() {
    const modal = document.getElementById('dpd-modal');
    if (modal) modal.classList.add('hidden');
};

window.goToDpdStep = function(step) {
    window.currentDpdStep = step;
    updateDpdWizardUI();
};

window.nextDpdStep = function() {
    if (window.currentDpdStep < 4) {
        window.currentDpdStep++;
        updateDpdWizardUI();
    }
};

window.prevDpdStep = function() {
    if (window.currentDpdStep > 1) {
        window.currentDpdStep--;
        updateDpdWizardUI();
    }
};

window.updateDpdWizardUI = function() {
    const step = window.currentDpdStep || 1;

    document.querySelectorAll('.dpd-step-content').forEach(el => {
        el.classList.toggle('hidden', parseInt(el.dataset.step) !== step);
    });

    document.querySelectorAll('.dpd-step-indicator').forEach(el => {
        const s = parseInt(el.dataset.step);
        const num = el.querySelector('div');
        const label = el.querySelector('span');
        if (s === step) {
            num.className = 'w-8 h-8 rounded-full bg-gradient-to-br from-emerald-500 to-teal-500 border-2 border-emerald-400 flex items-center justify-center text-white font-bold text-[11px] shadow-lg shadow-emerald-500/25 transition-all duration-300';
            label.classList.remove('text-text-subtle');
            label.classList.add('text-emerald-400');
        } else if (s < step) {
            num.className = 'w-8 h-8 rounded-full bg-emerald-500/20 border-2 border-emerald-400 flex items-center justify-center text-emerald-400 font-bold text-[11px] transition-all duration-300';
            label.classList.remove('text-text-subtle');
            label.classList.add('text-emerald-400');
        } else {
            num.className = 'w-8 h-8 rounded-full bg-bg-elevated border-2 border-border-theme flex items-center justify-center text-text-subtle font-bold text-[11px] transition-all duration-300';
            label.classList.remove('text-emerald-400');
            label.classList.add('text-text-subtle');
        }
    });

    const line = document.getElementById('dpd-progress-line');
    if (line) line.style.width = ((step - 1) / 3 * 100) + '%';

    const prevBtn = document.getElementById('dpd-prev-btn');
    const nextBtn = document.getElementById('dpd-next-btn');
    const submitBtn = document.getElementById('dpd-submit-btn');
    if (prevBtn) prevBtn.classList.toggle('hidden', step === 1);
    if (nextBtn) nextBtn.classList.toggle('hidden', step === 4);
    if (submitBtn) submitBtn.classList.toggle('hidden', step !== 4);
};

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'dpd' && params.get('open') === 'apdp') {
        openDpdWizard(3);
    }
});

} catch (e) {
    console.error('Error en script de hardening:', e);
    console.error('Stack:', e.stack);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
