<?php
// SecureLab - Generar PDF del Plan de Respuesta a Incidentes
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

header('Content-Type: application/json');

// Verificar autenticación
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $payload = JWT::decode($token, JWT_SECRET, ['HS256']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$db = Database::getInstance();

// Obtener configuración y datos necesarios
$config = $db->findOne('config', []) ?? [];
$breaches = $db->find('breaches', []) ?? [];
$users = $db->find('users', []) ?? [];

// Obtener datos del Plan de Respuesta a Incidentes
$overrides = !empty($config['measureOverrides']) ? (is_string($config['measureOverrides']) ? json_decode($config['measureOverrides'], true) : $config['measureOverrides']) : [];
$incidentResponseOverride = null;
foreach ($overrides as $o) {
    if (($o['measureId'] ?? '') === 'incident_response') {
        $incidentResponseOverride = $o;
        break;
    }
}

// Datos del plan
$planStatus = $incidentResponseOverride['fields']['planStatus'] ?? 'No existe';
$lastDrill = $incidentResponseOverride['fields']['lastDrill'] ?? null;
$teamSize = $incidentResponseOverride['fields']['teamSize'] ?? null;
$evidenceUrl = $incidentResponseOverride['fields']['evidenceUrl'] ?? null;
$companyName = $config['companyName'] ?? 'Empresa';
$dpdName = $config['dpdName'] ?? 'No asignado';
$dpdEmail = $config['dpdEmail'] ?? 'No asignado';

// Brechas resueltas (evidencia del plan)
$resolvedBreaches = array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved');

// Generar HTML para el PDF
$html = generateIncidentResponsePDF([
    'companyName' => $companyName,
    'dpdName' => $dpdName,
    'dpdEmail' => $dpdEmail,
    'planStatus' => $planStatus,
    'lastDrill' => $lastDrill,
    'teamSize' => $teamSize,
    'evidenceUrl' => $evidenceUrl,
    'resolvedBreaches' => $resolvedBreaches,
    'generatedAt' => date('d/m/Y H:i:s'),
]);

// Guardar HTML temporal
$tempFile = tempnam(sys_get_temp_dir(), 'incident_response_');
$htmlFile = $tempFile . '.html';
file_put_contents($htmlFile, $html);

// Generar PDF usando wkhtmltopdf (si está disponible) o guardar HTML
$pdfUrl = null;
if (file_exists('/usr/bin/wkhtmltopdf')) {
    $pdfFile = $tempFile . '.pdf';
    exec("/usr/bin/wkhtmltopdf --page-size A4 --orientation portrait --margin-top 10mm --margin-bottom 10mm --margin-left 10mm --margin-right 10mm '{$htmlFile}' '{$pdfFile}' 2>&1", $output, $returnCode);

    if ($returnCode === 0 && file_exists($pdfFile)) {
        // Mover PDF a directorio público
        $publicDir = __DIR__ . '/../frontend/public/docs';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }
        $pdfFilename = 'plan-respuesta-incidentes-' . date('Y-m-d-His') . '.pdf';
        $pdfPath = $publicDir . '/' . $pdfFilename;
        rename($pdfFile, $pdfPath);
        $pdfUrl = '/frontend/public/docs/' . $pdfFilename;
    }
}

// Limpiar archivos temporales
@unlink($htmlFile);
@unlink($tempFile);

if ($pdfUrl) {
    echo json_encode([
        'success' => true,
        'pdfUrl' => $pdfUrl,
        'html' => $html
    ]);
} else {
    // Si no se puede generar PDF, devolver el HTML para que el usuario lo imprima
    echo json_encode([
        'success' => true,
        'pdfUrl' => null,
        'html' => $html,
        'message' => 'PDF no disponible, se devuelve HTML para impresión'
    ]);
}

function generateIncidentResponsePDF($data) {
    $companyName = htmlspecialchars($data['companyName']);
    $dpdName = htmlspecialchars($data['dpdName']);
    $dpdEmail = htmlspecialchars($data['dpdEmail']);
    $planStatus = htmlspecialchars($data['planStatus']);
    $lastDrill = $data['lastDrill'] ? date('d/m/Y', strtotime($data['lastDrill'])) : 'No registrado';
    $teamSize = $data['teamSize'] ? htmlspecialchars($data['teamSize']) : 'No especificado';
    $evidenceUrl = $data['evidenceUrl'] ? htmlspecialchars($data['evidenceUrl']) : 'No especificado';
    $generatedAt = $data['generatedAt'];
    $resolvedCount = count($data['resolvedBreaches']);

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plan de Respuesta a Incidentes - {$companyName}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1e40af;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #1e40af;
            font-size: 24px;
            margin: 0 0 10px 0;
        }
        .header p {
            color: #666;
            margin: 0;
        }
        .section {
            margin-bottom: 25px;
        }
        .section h2 {
            color: #1e40af;
            font-size: 16px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            background: #f9fafb;
            padding: 10px;
            border-left: 3px solid #1e40af;
        }
        .info-item label {
            font-weight: bold;
            color: #374151;
            display: block;
            margin-bottom: 5px;
        }
        .info-item span {
            color: #6b7280;
        }
        .status-box {
            background: #f0fdf4;
            border: 2px solid #22c55e;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        .status-box h3 {
            color: #16a34a;
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        .checklist {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
        }
        .checklist-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px;
            background: white;
            border-radius: 4px;
        }
        .checklist-item:last-child {
            margin-bottom: 0;
        }
        .checklist-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .breaches-section {
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 15px;
            border-radius: 8px;
        }
        .breach-item {
            background: white;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            border-left: 3px solid #ef4444;
        }
        .breach-item:last-child {
            margin-bottom: 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #9ca3af;
            font-size: 10px;
        }
        .legal-notice {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .legal-notice h3 {
            color: #92400e;
            margin: 0 0 10px 0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>PLAN DE RESPUESTA A INCIDENTES</h1>
        <p>Ley 21.719 - Protección de Datos Personales</p>
        <p><strong>{$companyName}</strong></p>
    </div>

    <div class="section">
        <div class="status-box">
            <h3>Estado del Plan: {$planStatus}</h3>
            <p>Última actualización: {$generatedAt}</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información General</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>{$companyName}</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>{$dpdName} ({$dpdEmail})</span>
            </div>
            <div class="info-item">
                <label>Estado del Plan:</label>
                <span>{$planStatus}</span>
            </div>
            <div class="info-item">
                <label>Último Simulacro (Drill):</label>
                <span>{$lastDrill}</span>
            </div>
            <div class="info-item">
                <label>Tamaño del Equipo de Respuesta:</label>
                <span>{$teamSize}</span>
            </div>
            <div class="info-item">
                <label>URL del Plan:</label>
                <span>{$evidenceUrl}</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Componentes del Plan de Respuesta</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Equipo de Respuesta a Incidentes (CSIRT)</strong> - Definición de roles y responsabilidades</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Procedimientos de Detección</strong> - Fuentes de detección y criterios de clasificación</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Procedimientos de Contención</strong> - Acciones inmediatas para limitar el impacto</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Procedimientos de Erradicación</strong> - Eliminación de la causa raíz</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Procedimientos de Recuperación</strong> - Restauración desde backups verificados</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Procedimientos de Notificación</strong> - APDP, titulares, CSIRT (Art. 26 Ley 21.719)</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Post-Incidente</strong> - Análisis causa raíz y lecciones aprendidas</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Evidencia de Incidentes Gestionados</h2>
        <p><strong>Total de incidentes resueltos: {$resolvedCount}</strong></p>
        {$resolvedCount > 0 ? '<div class="breaches-section">' : '<div class="checklist">'}
        {$resolvedCount > 0 ? '<p>Incidentes resueltos anteriormente:</p>' : '<div class="checklist-item"><input type="checkbox" checked disabled><span>No hay incidentes resueltos registrados</span></div>'}
HTML;

    if ($resolvedCount > 0) {
        foreach ($data['resolvedBreaches'] as $breach) {
            $breachTitle = htmlspecialchars($breach['title'] ?? 'Sin título');
            $breachDate = $breach['resolvedAt'] ? date('d/m/Y H:i', strtotime($breach['resolvedAt'])) : 'No registrado';
            $breachSeverity = htmlspecialchars($breach['severity'] ?? 'No especificado');
            
            $html .= <<<HTML
            <div class="breach-item">
                <strong>{$breachTitle}</strong><br>
                <small>Fecha de resolución: {$breachDate} | Severidad: {$breachSeverity}</small>
            </div>
HTML;
        }
        $html .= '</div>';
    } else {
        $html .= '</div>';
    }

    $html .= <<<HTML
    </div>

    <div class="section">
        <h2>4. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Ley 21.719 - Protección de Datos Personales</h3>
            <p><strong>Artículo 26 - Notificación de brechas de seguridad:</strong></p>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Notificación a la Agencia de Protección de Datos Personales (APDP) sin dilación indebida</li>
                <li>Máximo 72 horas desde el conocimiento de la brecha</li>
                <li>Notificación a titulares si hay riesgo alto para sus derechos</li>
                <li>Multas hasta 20.000 UTM por incumplimiento</li>
            </ul>
            <p style="margin-top: 10px;"><strong>Ley 21.663 - Ciberseguridad:</strong></p>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Notificación al CSIRT Chile dentro de las primeras 3 horas</li>
                <li>Reporte completo dentro de las 72 horas</li>
            </ul>
        </div>
    </div>

    <div class="section">
        <h2>5. Contactos de Emergencia</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>APDP:</label>
                <span>dpd@apdp.gob.cl | +56 2 XXXX XXXX</span>
            </div>
            <div class="info-item">
                <label>CSIRT Chile:</label>
                <span>csirt@gob.cl | +56 2 XXXX XXXX</span>
            </div>
            <div class="info-item">
                <label>Fiscalía:</label>
                <span>Denuncias informáticas</span>
            </div>
            <div class="info-item">
                <label>DPD Interno:</label>
                <span>{$dpdName} ({$dpdEmail})</span>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Este documento ha sido generado automáticamente por SecureLab</p>
        <p>Fecha de generación: {$generatedAt}</p>
        <p>Este documento es válido como evidencia del cumplimiento del Art. 26 de la Ley 21.719</p>
    </div>
</body>
</html>
HTML;
}