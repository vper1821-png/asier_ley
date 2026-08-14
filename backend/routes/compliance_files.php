<?php
// backend/routes/compliance_files.php
// Módulo de gestión de archivos con datos personales (Ley 21.719)

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'txt'];
const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB
const UPLOAD_DIR = __DIR__ . '/../uploads/';

// ─── Asegurar directorio de uploads ───
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ─── Función de subida ───
function upload() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    if (empty($_FILES['file'])) {
        json_error('No se recibió ningún archivo');
    }

    $file = $_FILES['file'];
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // Validar extensión
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        json_error('Tipo de archivo no permitido. Solo: ' . implode(', ', ALLOWED_EXTENSIONS));
    }

    // Validar tamaño
    if ($file['size'] > MAX_FILE_SIZE) {
        json_error('El archivo excede el tamaño máximo de 50 MB');
    }

    // Validar error de subida
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Error al subir el archivo (código ' . $file['error'] . ')');
    }

    // Generar nombre seguro y mover
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = UPLOAD_DIR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        json_error('No se pudo guardar el archivo');
    }

    // Cifrar archivo (opcional, pero recomendado)
    // cifrarArchivo($targetPath); // Implementar si se desea

    // Calcular hash SHA-256
    $hash = hash_file('sha256', $targetPath);

    // Guardar metadatos en MongoDB
    $doc = [
        'userId'       => $user['_id'],
        'originalName' => $originalName,
        'safeName'     => $safeName,
        'ext'          => $ext,
        'size'         => $file['size'],
        'hash'         => $hash,
        'mimeType'     => mime_content_type($targetPath) ?: 'application/octet-stream',
        'status'       => 'pending', // pending, analyzing, analyzed, failed
        'analysisResult' => null,
        'createdAt'    => date('c'),
        'updatedAt'    => date('c'),
    ];

    $inserted = $db->insertOne('compliance_files', $doc);
    // Devolver el ID para análisis posterior
    json_response([
        'success' => true,
        'fileId'  => $inserted['_id'],
        'message' => 'Archivo subido correctamente. Inicia el análisis para procesarlo.'
    ]);
}

// ─── Análisis del archivo ───
function analyze() {
    $user = Auth::requireAuth();
    $body = get_body();
    $fileId = $body['fileId'] ?? '';

    if (!$fileId) json_error('fileId requerido');

    $db = Database::getInstance();
    $fileRecord = $db->findOne('compliance_files', ['_id' => $fileId, 'userId' => $user['_id']]);
    if (!$fileRecord) json_error('Archivo no encontrado', 404);

    if ($fileRecord['status'] === 'analyzed') {
        json_response(['success' => true, 'message' => 'El archivo ya fue analizado', 'result' => $fileRecord['analysisResult']]);
    }

    $filePath = UPLOAD_DIR . $fileRecord['safeName'];
    if (!file_exists($filePath)) {
        json_error('El archivo físico no existe', 404);
    }

    // Actualizar estado a 'analyzing'
    $db->updateOne('compliance_files', ['_id' => $fileId], ['status' => 'analyzing']);

    try {
        $ext = $fileRecord['ext'];
        $data = [];

        if (in_array($ext, ['xlsx', 'xls'])) {
            $data = analyzeExcel($filePath);
        } elseif ($ext === 'csv') {
            $data = analyzeCsv($filePath);
        } elseif ($ext === 'txt') {
            $data = analyzeTxt($filePath);
        } else {
            throw new Exception('Extensión no soportada para análisis');
        }

        // Detectar patrones de datos personales
        $patterns = detectPatterns($data['headers'], $data['sample']);

        // Crear ítem en compliance_inventory automáticamente
        $inventoryItem = createInventoryItem($user['_id'], $fileId, $fileRecord['originalName'], $patterns, $data['rowCount']);

        // Guardar resultado del análisis
        $analysisResult = [
            'headers'      => $data['headers'],
            'sample'       => $data['sample'],
            'rowCount'     => $data['rowCount'],
            'patterns'     => $patterns,
            'inventoryId'  => $inventoryItem['_id'],
            'analyzedAt'   => date('c'),
        ];

        $db->updateOne('compliance_files', ['_id' => $fileId], [
            'status'         => 'analyzed',
            'analysisResult' => $analysisResult,
            'updatedAt'      => date('c'),
        ]);

        json_response([
            'success' => true,
            'message' => 'Análisis completado',
            'result'  => $analysisResult,
        ]);

    } catch (Exception $e) {
        $db->updateOne('compliance_files', ['_id' => $fileId], [
            'status' => 'failed',
            'updatedAt' => date('c'),
        ]);
        json_error('Error al analizar el archivo: ' . $e->getMessage());
    }
}

// ─── Funciones auxiliares de análisis ───

function analyzeExcel($filePath) {
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);

    if (empty($rows)) {
        throw new Exception('El archivo Excel está vacío');
    }

    $headers = array_shift($rows); // Primera fila como cabeceras
    $sample = array_slice($rows, 0, 20); // Primeras 20 filas
    $rowCount = count($rows);

    return ['headers' => $headers, 'sample' => $sample, 'rowCount' => $rowCount];
}

function analyzeCsv($filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) throw new Exception('No se pudo abrir el CSV');

    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) throw new Exception('El CSV no tiene cabeceras o está vacío');

    $sample = [];
    $rowCount = 0;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($rowCount < 20) $sample[] = $row;
        $rowCount++;
    }
    fclose($handle);

    return ['headers' => $headers, 'sample' => $sample, 'rowCount' => $rowCount];
}

function analyzeTxt($filePath) {
    $content = file_get_contents($filePath);
    if ($content === false) throw new Exception('No se pudo leer el archivo TXT');

    $lines = explode("\n", $content);
    $lines = array_filter($lines, 'trim');
    if (empty($lines)) throw new Exception('El archivo TXT está vacío');

    // Tratar la primera línea como cabeceras (si parece una línea de encabezados)
    $firstLine = array_shift($lines);
    $headers = explode("\t", $firstLine); // Por defecto tabulación, se puede mejorar
    if (count($headers) < 2) {
        // Si no tiene múltiples columnas, considerar todo como una sola columna
        $headers = ['contenido'];
        $lines = array_merge([$firstLine], $lines);
    }

    $sample = array_slice($lines, 0, 20);
    $rowCount = count($lines);

    // Convertir cada línea en array (para consistencia)
    $sample = array_map(function($line) use ($headers) {
        $parts = explode("\t", $line);
        // Rellenar para que coincida con el número de cabeceras
        while (count($parts) < count($headers)) $parts[] = '';
        return $parts;
    }, $sample);

    return ['headers' => $headers, 'sample' => $sample, 'rowCount' => $rowCount];
}

// ─── Detección de patrones de datos personales ───
function detectPatterns($headers, $sample) {
    $patterns = [];
    $commonPatterns = [
        'rut'    => '/^[0-9]{1,2}\.[0-9]{3}\.[0-9]{3}-[0-9kK]$/',
        'email'  => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
        'phone'  => '/^(\+56|0)[0-9]{9}$/',
        'name'   => '/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+( [A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)+$/',
        'address'=> '/^[A-Za-z0-9#ñÑáéíóúÁÉÍÓÚ\s,.-]+$/',
    ];

    foreach ($headers as $colIndex => $colName) {
        $colNameLower = strtolower(trim($colName));
        $matched = [];

        // Buscar en el nombre de la columna
        foreach ($commonPatterns as $key => $pattern) {
            if (preg_match($pattern, $colNameLower)) {
                $matched[$key] = true;
            }
        }

        // Si no se detectó por nombre, probar con muestras
        if (empty($matched)) {
            $sampleColumn = array_column($sample, $colIndex);
            $sampleColumn = array_filter($sampleColumn, 'trim');
            if (count($sampleColumn) > 0) {
                foreach ($commonPatterns as $key => $pattern) {
                    $matches = array_filter($sampleColumn, function($val) use ($pattern) {
                        return preg_match($pattern, $val) === 1;
                    });
                    if (count($matches) >= count($sampleColumn) * 0.5) { // si más del 50% coincide
                        $matched[$key] = true;
                    }
                }
            }
        }

        if (!empty($matched)) {
            $patterns[$colName] = array_keys($matched);
        }
    }

    return $patterns;
}

// ─── Crear ítem en inventario ───
function createInventoryItem($userId, $fileId, $fileName, $patterns, $rowCount) {
    $db = Database::getInstance();

    // Construir categorías a partir de los patrones detectados
    $categories = [];
    foreach ($patterns as $col => $types) {
        $categories = array_merge($categories, $types);
    }
    $categories = array_unique($categories);

    $doc = [
        'userId'        => $userId,
        'sourceType'    => 'file',
        'sourceId'      => $fileId,
        'name'          => 'Datos desde archivo: ' . $fileName,
        'dataCategories'=> implode(', ', $categories),
        'records'       => $rowCount,
        'sensitive'     => in_array('rut', $categories) || in_array('email', $categories),
        'legalBasis'    => 'Pendiente de definir',
        'active'        => true,
        'createdAt'     => date('c'),
        'updatedAt'     => date('c'),
        'retentionDays' => null,
        'deletionScheduledAt' => null,
    ];

    return $db->insertOne('compliance_inventory', $doc);
}

// ─── Listar archivos del usuario ───
function listFiles() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $files = $db->find('compliance_files', ['userId' => $user['_id']]);
    json_response($files);
}

// ─── Eliminar archivo (físico y lógico) ───
function deleteFile() {
    $user = Auth::requireAuth();
    $body = get_body();
    $fileId = $body['fileId'] ?? '';

    if (!$fileId) json_error('fileId requerido');

    $db = Database::getInstance();
    $fileRecord = $db->findOne('compliance_files', ['_id' => $fileId, 'userId' => $user['_id']]);
    if (!$fileRecord) json_error('Archivo no encontrado', 404);

    // Eliminar archivo físico
    $filePath = UPLOAD_DIR . $fileRecord['safeName'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Eliminar el registro de la base de datos
    $db->deleteOne('compliance_files', ['_id' => $fileId]);

    // Opcional: Eliminar también el ítem de inventario asociado (si existe)
    if (!empty($fileRecord['analysisResult']['inventoryId'])) {
        $db->deleteOne('compliance_inventory', ['_id' => $fileRecord['analysisResult']['inventoryId']]);
    }

    json_response(['success' => true, 'message' => 'Archivo eliminado']);
}

// ─── Mapeo manual de columnas ───
function mapColumns() {
    $user = Auth::requireAuth();
    $body = get_body();
    $fileId = $body['fileId'] ?? '';
    $mapping = $body['mapping'] ?? []; // Ej: {"columna1": "nombre", "columna2": "rut"}

    if (!$fileId || empty($mapping)) json_error('fileId y mapping requeridos');

    $db = Database::getInstance();
    $fileRecord = $db->findOne('compliance_files', ['_id' => $fileId, 'userId' => $user['_id']]);
    if (!$fileRecord) json_error('Archivo no encontrado', 404);

    // Actualizar el análisis con el mapeo manual
    $analysis = $fileRecord['analysisResult'] ?? [];
    $analysis['manualMapping'] = $mapping;
    $analysis['manualMappedAt'] = date('c');

    // Actualizar el inventario con las categorías mapeadas
    $inventoryId = $analysis['inventoryId'] ?? null;
    if ($inventoryId) {
        $categories = array_values($mapping);
        $categories = array_unique($categories);
        $db->updateOne('compliance_inventory', ['_id' => $inventoryId], [
            'dataCategories' => implode(', ', $categories),
            'sensitive' => in_array('rut', $categories) || in_array('email', $categories),
            'updatedAt' => date('c'),
        ]);
    }

    $db->updateOne('compliance_files', ['_id' => $fileId], [
        'analysisResult' => $analysis,
        'updatedAt' => date('c'),
    ]);

    json_response(['success' => true, 'message' => 'Mapeo guardado']);
}