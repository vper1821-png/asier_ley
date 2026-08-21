<?php
require_once __DIR__ . '/../config.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$tab = $_GET['tab'] ?? 'measures';

// ── POST handlers (antes de HTML para poder redirigir) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_dpd'])) {
        api_post_form('/api/invisia/compliance/config', [
            'token' => $token,
            'dpdName' => $_POST['dpdName'] ?? '',
            'dpdEmail' => $_POST['dpdEmail'] ?? '',
            'dpdPhone' => $_POST['dpdPhone'] ?? '',
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

// ── Fetch data----------- ──
$statsRes = api_post_form('/api/dashboard/stats', ['token' => $token]);
$stats = $statsRes['stats'] ?? [];
$dbCompliance = $statsRes['dbCompliance'] ?? [];
$inventory = api_get('/api/invisia/compliance/inventory', ['token' => $token]);
if (!is_array($inventory) || isset($inventory['error'])) $inventory = [];
$breaches = api_get('/api/invisia/compliance/breaches', ['token' => $token]);
if (!is_array($breaches) || isset($breaches['error'])) $breaches = [];
$config = api_get('/api/invisia/compliance/config', ['token' => $token]);
if (!is_array($config)) $config = [];
$alertsRes = api_post_form('/api/alerts/list', ['token' => $token, 'limit' => '50']);
$alerts = $alertsRes['alerts'] ?? [];
$pseudoRules = api_get('/api/invisia/compliance/pseudonymization', ['token' => $token]);
if (!is_array($pseudoRules) || isset($pseudoRules['error'])) $pseudoRules = [];
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

$measures = [];
foreach ($HARDENING_DEFS as $def) {
    $done = false;
    switch ($def['id']) {
        case 'encryption': $done = $hasSslDbs || $totalDatabases > 0; break;
        case 'access_control': $done = $onlineAgents > 0; break;
        case 'backup': $done = $hasScannedRecently || $totalDatabases > 1; break;
        case 'logging': $done = $onlineAgents > 0 || $hasAlertsForDataDiscovery; break;
        case 'patching': $done = $onlineAgents > 0 && $hasScannedRecently; break;
        case 'ids_ips': $done = $onlineAgents > 0; break;
        case 'dlp':
            $done = $hasPersonalData;
            foreach ($inventory as $i) { if (!empty($i['securityMeasures'])) $done = true; }
            break;
        case 'waf': $done = $hasWaf; break;
        case 'pseudonymization':
            foreach ($pseudoRules as $r) { if (($r['status'] ?? '') === 'executed' || !empty($r['executed'])) $done = true; }
            foreach ($inventory as $i) {
                foreach (($i['securityMeasures'] ?? []) as $m) {
                    if (stripos($m, 'seudonim') !== false || stripos($m, 'pseudonym') !== false) $done = true;
                }
            }
            break;
        case 'incident_response':
            foreach ($breaches as $b) { if (($b['status'] ?? '') === 'resolved') $done = true; }
            break;
    }
    $ov = $getOverride($def['id']);
    $def['override'] = $ov;
    $def['done'] = $done || $ov !== null;
    $measures[] = $def;
}

$doneCount = count(array_filter($measures, fn($m) => $m['done']));
$total = count($measures);
$pct = $total ? (int)round($doneCount / $total * 100) : 0;
$riskLabel = $pct >= 70 ? 'Bajo' : ($pct >= 40 ? 'Medio' : 'Alto');
$pctColor = $pct >= 70 ? 'text-emerald-400' : ($pct >= 40 ? 'text-yellow-400' : 'text-red-400');
$barColor = $pct >= 70 ? 'bg-emerald-500' : ($pct >= 40 ? 'bg-yellow-500' : 'bg-red-500');

$dpdObligations = [
    ['label' => 'Supervisar el cumplimiento normativo', 'done' => !empty($config['dpdEmail'])],
    ['label' => 'Asesorar en evaluaciones de impacto', 'done' => !empty($config['companyName'])],
    ['label' => 'Atender solicitudes de titulares', 'done' => count($breaches) > 0],
    ['label' => 'Coordinar con la APDP', 'done' => !empty($config['apdpRegistered'])],
    ['label' => 'Capacitar al personal', 'done' => count($inventory) > 0],
    ['label' => 'Mantener registro de actividades', 'done' => $totalDatabases > 0],
    ['label' => 'Reportar brechas a la APDP', 'done' => count(array_filter($breaches, fn($b) => !empty($b['notifiedAPDP']))) > 0],
    ['label' => 'Realizar auditorías periódicas', 'done' => (int)($stats['totalAgents'] ?? 0) > 0],
];

$breachTimeline = [
    ['time' => '0-3 horas', 'action' => 'Alerta temprana al CSIRT (Ley 21.663)', 'severity' => 'critical'],
    ['time' => 'Inmediato', 'action' => 'Contener la brecha (aislar sistemas, revocar accesos)', 'severity' => 'critical'],
    ['time' => '24 horas', 'action' => 'Notificar a la APDP por medios expeditos (Art. 26)', 'severity' => 'high'],
    ['time' => '48 horas', 'action' => 'Informar a los titulares si hay datos sensibles, niños o datos económicos', 'severity' => 'high'],
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
?>

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
                                <?php if ($item['override']): ?>
                                <div class="flex flex-col items-center gap-1">
                                    <form method="POST">
                                        <input type="hidden" name="measure_id" value="<?= h($item['id']) ?>">
                                        <button type="submit" name="revoke_measure" value="1" class="text-[9px] text-text-subtle hover:text-red-400 font-medium transition-colors">Desactivar</button>
                                    </form>
                                    <?php if (!empty($item['override']['completedAt'])): ?>
                                    <span class="text-[8px] text-text-subtle"><?= date('d-m-Y', strtotime($item['override']['completedAt'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php else: ?>
                                <svg class="w-9 h-9" viewBox="0 0 36 36">
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-surface-700" stroke-width="3"/>
                                    <circle cx="18" cy="18" r="15" fill="none" class="stroke-gray-600" stroke-width="3" stroke-dasharray="94.25" stroke-dashoffset="70.69" stroke-linecap="round" transform="rotate(-90 18 18)"/>
                                </svg>
                                <button onclick="openMeasureModal('<?= h($item['id']) ?>')"
                                    class="text-[10px] px-2 py-0.5 rounded-md bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20 transition-all font-medium whitespace-nowrap">
                                    Completar
                                </button>
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
                                <button onclick="document.getElementById('dpd-modal').classList.remove('hidden')"
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

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-bg-base/40 border border-border-theme/25 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-red-400"><?= hIcon('alert') ?></span>
                                <h4 class="text-[12px] font-semibold text-text-heading">Ley 21.719 - APDP</h4>
                            </div>
                            <p class="text-[11px] text-text-muted leading-relaxed">Notificar a la Agencia de Protección de Datos Personales "por los medios más expeditos posibles y sin dilaciones indebidas". Si la brecha afecta datos sensibles, de niños o económicos, también debes informar a los titulares.</p>
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

        <!-- Modal DPD -->
        <div id="dpd-modal" class="hidden fixed inset-0 bg-black/65 flex items-center justify-center z-50 p-3 md:p-4">
            <div class="w-full max-w-md mx-auto bg-bg-panel border border-border-theme rounded-xl shadow-xl">
                <div class="flex items-center justify-between px-5 py-4 border-b border-white/[0.04]">
                    <h3 class="text-[13px] font-semibold text-text-heading">Registrar DPD</h3>
                    <button onclick="document.getElementById('dpd-modal').classList.add('hidden')" class="text-text-muted hover:text-text-heading transition-colors p-1 hover:bg-bg-elevated rounded-lg"><?= hIcon('xmark') ?></button>
                </div>
                <form method="POST" class="p-5 space-y-3">
                    <div>
                        <label class="block text-[10px] text-text-muted font-semibold uppercase tracking-widest mb-1.5">Nombre del DPD</label>
                        <input name="dpdName" value="<?= h($config['dpdName'] ?? '') ?>" placeholder="Nombre completo"
                            class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all placeholder-text-subtle">
                    </div>
                    <div>
                        <label class="block text-[10px] text-text-muted font-semibold uppercase tracking-widest mb-1.5">Email del DPD *</label>
                        <input name="dpdEmail" value="<?= h($config['dpdEmail'] ?? '') ?>" placeholder="dpd@tuempresa.cl" type="email" required
                            class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all placeholder-text-subtle">
                    </div>
                    <div>
                        <label class="block text-[10px] text-text-muted font-semibold uppercase tracking-widest mb-1.5">Teléfono del DPD</label>
                        <input name="dpdPhone" value="<?= h($config['dpdPhone'] ?? '') ?>" placeholder="+56 9 XXXX XXXX"
                            class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all placeholder-text-subtle">
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" onclick="document.getElementById('dpd-modal').classList.add('hidden')"
                            class="px-2.5 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                        <button type="submit" name="save_dpd" value="1"
                            class="px-2.5 py-1.5 text-[11px] font-medium rounded-lg bg-primary-500 hover:bg-primary-600 text-white transition-all">Guardar DPD</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Medida -->
        <div id="measure-modal" class="hidden fixed inset-0 bg-black/65 flex items-center justify-center z-50 p-3 md:p-4">
            <div class="w-full max-w-lg mx-auto bg-bg-panel border border-border-theme rounded-xl shadow-xl max-h-[85vh] overflow-y-auto scrollbar-custom">
                <div class="flex items-center justify-between px-5 py-4 border-b border-white/[0.04] sticky top-0 bg-bg-panel z-10">
                    <div class="flex items-center gap-2.5">
                        <span class="text-indigo-400 flex-shrink-0" id="measure-modal-icon"></span>
                        <div>
                            <h3 class="text-[13px] font-semibold text-text-heading" id="measure-modal-title"></h3>
                            <p class="text-[10px] text-text-muted mt-0.5">Completar medida de seguridad</p>
                        </div>
                    </div>
                    <button onclick="document.getElementById('measure-modal').classList.add('hidden')" class="text-text-muted hover:text-text-heading transition-colors p-1 hover:bg-bg-elevated rounded-lg flex-shrink-0"><?= hIcon('xmark') ?></button>
                </div>
                <form method="POST" class="p-5 space-y-4">
                    <input type="hidden" name="measure_id" id="measure-modal-id">
                    <div class="rounded-lg bg-indigo-500/[0.04] border border-indigo-500/15 px-3 py-2.5">
                        <p class="text-[11px] text-text-muted leading-relaxed" id="measure-modal-desc"></p>
                    </div>
                    <div class="space-y-3" id="measure-modal-fields">
                        <p class="text-[10px] font-semibold text-text-subtle uppercase tracking-widest">Detalles de la implementacion</p>
                    </div>
                    <div class="border-t border-white/[0.04] pt-3">
                        <label class="block text-[10px] text-text-muted font-semibold uppercase tracking-widest mb-1.5">Notas adicionales</label>
                        <textarea name="notes" placeholder="Comentarios o detalles extra..." rows="2"
                            class="w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all placeholder-text-subtle resize-none"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" onclick="document.getElementById('measure-modal').classList.add('hidden')"
                            class="px-2.5 py-1.5 text-[11px] font-medium rounded-lg bg-bg-elevated text-text-body border border-border-theme transition-all">Cancelar</button>
                        <button type="submit" name="save_measure" value="1"
                            class="px-3 py-1.5 text-[11px] font-medium rounded-lg bg-primary-500 hover:bg-primary-600 text-white transition-all">Marcar como Completado</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        const MEASURE_DEFS = <?= json_encode(array_map(fn($d) => ['id' => $d['id'], 'label' => $d['label'], 'desc' => $d['desc'], 'fields' => $d['fields']], $HARDENING_DEFS), JSON_UNESCAPED_UNICODE) ?>;

        function openMeasureModal(id) {
            const def = MEASURE_DEFS.find(d => d.id === id);
            if (!def) return;
            document.getElementById('measure-modal-id').value = def.id;
            document.getElementById('measure-modal-title').textContent = def.label;
            document.getElementById('measure-modal-desc').textContent = def.desc;
            const wrap = document.getElementById('measure-modal-fields');
            wrap.innerHTML = '<p class="text-[10px] font-semibold text-text-subtle uppercase tracking-widest">Detalles de la implementacion</p>';
            def.fields.forEach(f => {
                const div = document.createElement('div');
                let inputHtml = '';
                const cls = 'w-full bg-bg-base border border-border-theme text-[12px] text-white rounded-lg px-3 py-2 focus:outline-none focus:border-accent transition-all placeholder-text-subtle';
                if (f.type === 'select') {
                    inputHtml = '<select name="field_' + f.key + '" class="' + cls + '"><option value="">Seleccionar...</option>' +
                        f.options.map(o => '<option value="' + o + '">' + o + '</option>').join('') + '</select>';
                } else if (f.type === 'date') {
                    inputHtml = '<input type="date" name="field_' + f.key + '" class="' + cls + '">';
                } else if (f.type === 'url') {
                    inputHtml = '<input type="url" name="field_' + f.key + '" placeholder="https://..." class="' + cls + '">';
                } else {
                    inputHtml = '<input type="text" name="field_' + f.key + '" class="' + cls + '">';
                }
                div.innerHTML = '<label class="block text-[10px] text-text-muted font-semibold uppercase tracking-widest mb-1.5">' + f.label + '</label>' + inputHtml;
                wrap.appendChild(div);
            });
            document.getElementById('measure-modal').classList.remove('hidden');
        }
        </script>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
