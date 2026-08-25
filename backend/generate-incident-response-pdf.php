<?php
// SecureLab - Generar PDF del Plan de Respuesta a Incidentes
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

// Asegurar que siempre devolvamos JSON
header('Content-Type: application/json');

// Verificar que el autoloader de vendor existe
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    error_log('[PDF] Vendor autoloader not found');
    echo json_encode(['error' => 'Vendor autoloader not found']);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// Verificar que dompdf esté disponible
if (!class_exists('Dompdf\Dompdf')) {
    error_log('[PDF] Dompdf class not available');
    echo json_encode(['error' => 'Dompdf class not available']);
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Verificar autenticación
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? $_GET['token'] ?? $_POST['token'] ?? '';
if (empty($token)) {
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Token no proporcionado']);
    exit;
}

// Extract Bearer token if present
if (str_starts_with($token, 'Bearer ')) {
    $token = substr($token, 7);
}

try {
    $payload = Auth::verifyToken($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'Invalid token', 'message' => 'Token inválido: ' . $e->getMessage()]);
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

// Generar PDF usando dompdf
try {
    // Verificar que dompdf esté disponible
    if (!class_exists('Dompdf\Dompdf')) {
        throw new Exception('Dompdf no está disponible');
    }
    
    // Configurar opciones de dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false); // Deshabilitar remote para evitar errores
    $options->set('defaultFont', 'Arial');
    $options->set('tempDir', sys_get_temp_dir());
    
    // Crear instancia de Dompdf
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    // Obtener el contenido del PDF
    $pdfContent = $dompdf->output();
    
    if (empty($pdfContent)) {
        throw new Exception('El contenido del PDF está vacío');
    }
    
    // Asegurar que el directorio de reportes existe con permisos correctos
    $reportsDir = __DIR__ . '/../frontend/public/reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    
    // Asegurar permisos del directorio
    chmod($reportsDir, 0755);
    
    // Generar nombre de archivo único
    $pdfFilename = 'plan-respuesta-incidentes-' . date('Y-m-d-His') . '.pdf';
    $pdfPath = $reportsDir . '/' . $pdfFilename;
    
    // Guardar el PDF
    if (file_put_contents($pdfPath, $pdfContent) === false) {
        throw new Exception('Failed to save PDF file');
    }
    
    // Establecer permisos correctos
    chmod($pdfPath, 0644);
    
    // Asegurar ownership correcto (si es posible)
    if (function_exists('chown') && function_exists('posix_getpwuid')) {
        $wwwData = posix_getpwuid(posix_geteuid());
        if ($wwwData['name'] === 'root') {
            // Si estamos ejecutando como root, intentar cambiar a www-data
            @chown($pdfPath, 'www-data');
            @chgrp($pdfPath, 'www-data');
        }
    }
    
    // Construir URL completa para descargar (URL pública)
    $baseUrl = API_BASE_URL;
    $pdfUrl = $baseUrl . '/public/reports/' . $pdfFilename;
    
    echo json_encode([
        'success' => true,
        'pdfUrl' => $pdfUrl,
        'pdfFilename' => $pdfFilename,
        'html' => $html,
        'message' => 'PDF generado exitosamente'
    ]);
    
} catch (Exception $e) {
    error_log('[PDF Generation Error] ' . $e->getMessage());
    error_log('[PDF Generation Error] Trace: ' . $e->getTraceAsString());
    
    // Asegurar que siempre devolvemos JSON válido
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'pdfUrl' => null,
        'html' => $html,
        'error' => 'Error generando PDF: ' . $e->getMessage(),
        'message' => 'PDF no disponible, se devuelve HTML para impresión'
    ]);
    exit;
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
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            line-height: 1.6;
            color: #000;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #000;
            font-size: 20px;
            margin: 0 0 10px 0;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header p {
            color: #333;
            margin: 5px 0;
            font-size: 11px;
        }
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .section h2 {
            color: #000;
            font-size: 14px;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 15px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-item {
            background: #fff;
            padding: 10px;
            border-left: 3px solid #000;
        }
        .info-item label {
            font-weight: bold;
            color: #000;
            display: block;
            margin-bottom: 5px;
            font-size: 11px;
        }
        .info-item span {
            color: #333;
        }
        .status-box {
            background: #fff;
            border: 2px solid #000;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        .status-box h3 {
            color: #000;
            margin: 0 0 10px 0;
            font-size: 16px;
            font-weight: bold;
        }
        .checklist {
            background: #fff;
            padding: 15px;
            border: 1px solid #000;
        }
        .checklist-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding: 8px;
            background: #fff;
            border-bottom: 1px solid #ccc;
        }
        .checklist-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .checklist-item input[type="checkbox"] {
            margin-right: 10px;
            accent-color: #000;
        }
        .breaches-section {
            background: #fff;
            border: 1px solid #000;
            padding: 15px;
        }
        .breach-item {
            background: #fff;
            padding: 10px;
            margin-bottom: 10px;
            border-left: 3px solid #000;
        }
        .breach-item:last-child {
            margin-bottom: 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #000;
            text-align: center;
            color: #333;
            font-size: 10px;
        }
        .legal-notice {
            background: #fff;
            border: 1px solid #000;
            padding: 15px;
            margin-top: 20px;
            page-break-inside: avoid;
        }
        .legal-notice h3 {
            color: #000;
            margin: 0 0 10px 0;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .legal-notice ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .legal-notice li {
            margin-bottom: 5px;
        }
        @media print {
            body { padding: 20px; }
            .section { page-break-inside: avoid; }
            .legal-notice { page-break-inside: avoid; }
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
HTML;

    if ($resolvedCount > 0) {
        $html .= '<div class="breaches-section">';
        $html .= '<p>Incidentes resueltos anteriormente:</p>';
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
        $html .= '<div class="checklist">';
        $html .= '<div class="checklist-item"><input type="checkbox" checked disabled><span>No hay incidentes resueltos registrados</span></div>';
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