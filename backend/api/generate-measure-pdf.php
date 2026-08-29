<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../PDFGenerator.php';

header('Content-Type: application/json');

// Validar token
$token = $_POST['token'] ?? '';
if (empty($token)) {
    echo json_encode(['success' => false, 'error' => 'Token no proporcionado']);
    exit;
}

// Validar usuario
$user = api_get('/api/user', ['token' => $token]);
if (empty($user) || !empty($user['error'])) {
    echo json_encode(['success' => false, 'error' => 'Token inválido']);
    exit;
}

// Obtener HTML y nombre de archivo
$html = $_POST['html'] ?? '';
$filename = $_POST['filename'] ?? 'medida-' . date('Y-m-d');

if (empty($html)) {
    echo json_encode(['success' => false, 'error' => 'HTML no proporcionado']);
    exit;
}

try {
    // Crear directorio de PDFs si no existe
    $pdfDir = __DIR__ . '/../pdfs';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    // Generar nombre de archivo único
    $pdfFilename = $filename . '-' . uniqid() . '.pdf';
    $pdfPath = $pdfDir . '/' . $pdfFilename;

    // Generar PDF usando dompdf
    $pdfGenerator = new PDFGenerator();
    $pdfGenerator->generatePDF($html, $pdfPath);

    // Verificar que el archivo se creó
    if (!file_exists($pdfPath)) {
        echo json_encode(['success' => false, 'error' => 'No se pudo generar el archivo PDF']);
        exit;
    }

    // Retornar URL del PDF
    $pdfUrl = '/pdfs/' . $pdfFilename;
    echo json_encode(['success' => true, 'pdfUrl' => $pdfUrl]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error al generar PDF: ' . $e->getMessage()]);
}