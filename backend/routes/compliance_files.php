<?php
// backend/routes/compliance_files.php
// Módulo de gestión de archivos con datos personales (Ley 21.719)
// Incluye: subida manual (usuario), análisis, mapeo, y recepción desde agente

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'txt'];
const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50 MB
const UPLOAD_DIR = __DIR__ . '/../uploads/';

// ─── Asegurar directorio de uploads ───
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// ================================================================
// 1. SUBIDA MANUAL (usuario)
// ================================================================
function upload() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();

    if (empty($_FILES['file'])) {
        json_error('No se recibió ningún archivo');
    }

    $file = $_FILES['file'];
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        json_error('Tipo de archivo no permitido. Solo: ' . implode(', ', ALLOWED_EXTENSIONS));
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        json_error('El archivo excede el tamaño máximo de 50 MB');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Error al subir el archivo (código ' . $file['error'] . ')');
    }

    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = UPLOAD_DIR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        json_error('No se pudo guardar el archivo');
    }

    $hash = hash_file('sha256', $targetPath);

    $doc = [
        'userId'       => $user['_id'],
        'sourceType'   => 'user',          // ← origen: usuario
        'agentId'      => null,
        'hostname'     => null,
        'path'         => null,
        'originalName' => $originalName,
        'safeName'     => $safeName,
        'ext'          => $ext,
        'size'         => $file['size'],
        'hash'         => $hash,
        'mimeType'     => mime_content_type($targetPath) ?: 'application/octet-stream',
        'status'       => 'pending',
        'analysisResult' => null,
        'createdAt'    => date('c'),
        'updatedAt'    => date('c'),
    ];

    $inserted = $db->insertOne('compliance_files', $doc);
    json_response([
        'success' => true,
        'fileId'  => $inserted['_id'],
        'message' => 'Archivo subido correctamente. Inicia el análisis para procesarlo.'
    ]);
}

// ================================================================
// 2. ANÁLISIS DE ARCHIVO (usuario)
// ================================================================
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

        $patterns = detectPatterns($data['headers'], $data['sample']);
        $inventoryItem = createInventoryItem($user['_id'], $fileId, $fileRecord['originalName'], $patterns, $data['rowCount']);

        $analysisResult = [
            'headers'      => $data['headers'],
            'sample'       => $data['sample'],
            'rowCount'     => $data['rowCount'],
            'patterns'     => $patterns,
            'inventoryId'  => $inventoryItem['_id'],
            'analyzedAt'   => date('c'),
            'analyzedBy'   => 'user',
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
        $db->updateOne('compliance_files', ['_id' => $fileId], ['status' => 'failed', 'updatedAt' => date('c')]);
        json_error('Error al analizar el archivo: ' . $e->getMessage());
    }
}

// ================================================================
// 3. FUNCIONES AUXILIARES DE ANÁLISIS (usuario)
// ================================================================
function analyzeExcel($filePath) {
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    if (empty($rows)) throw new Exception('El archivo Excel está vacío');
    $headers = array_shift($rows);
    $sample = array_slice($rows, 0, 20);
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
    $firstLine = array_shift($lines);
    $headers = explode("\t", $firstLine);
    if (count($headers) < 2) {
        $headers = ['contenido'];
        $lines = array_merge([$firstLine], $lines);
    }
    $sample = array_slice($lines, 0, 20);
    $rowCount = count($lines);
    $sample = array_map(function($line) use ($headers) {
        $parts = explode("\t", $line);
        while (count($parts) < count($headers)) $parts[] = '';
        return $parts;
    }, $sample);
    return ['headers' => $headers, 'sample' => $sample, 'rowCount' => $rowCount];
}

// ================================================================
// 4. DETECCIÓN DE PATRONES (compartida con agente)
// ================================================================
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
        foreach ($commonPatterns as $key => $pattern) {
            if (preg_match($pattern, $colNameLower)) {
                $matched[$key] = true;
            }
        }
        if (empty($matched)) {
            $sampleColumn = array_column($sample, $colIndex);
            $sampleColumn = array_filter($sampleColumn, 'trim');
            if (count($sampleColumn) > 0) {
                foreach ($commonPatterns as $key => $pattern) {
                    $matches = array_filter($sampleColumn, function($val) use ($pattern) {
                        return preg_match($pattern, $val) === 1;
                    });
                    if (count($matches) >= count($sampleColumn) * 0.5) {
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

// ================================================================
// 5. CREAR ÍTEM EN INVENTARIO (compartida)
// ================================================================
function createInventoryItem($userId, $fileId, $fileName, $patterns, $rowCount) {
    $db = Database::getInstance();
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

// ================================================================
// 6. LISTAR ARCHIVOS
// ================================================================
function listFiles() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $files = $db->find('compliance_files', ['userId' => $user['_id']]);
    json_response($files);
}

// ================================================================
// 7. ELIMINAR ARCHIVO
// ================================================================
function deleteFile() {
    $user = Auth::requireAuth();
    $body = get_body();
    $fileId = $body['fileId'] ?? '';
    if (!$fileId) json_error('fileId requerido');

    $db = Database::getInstance();
    $fileRecord = $db->findOne('compliance_files', ['_id' => $fileId, 'userId' => $user['_id']]);
    if (!$fileRecord) json_error('Archivo no encontrado', 404);

    // Eliminar físico (solo si es de usuario y tiene safeName)
    if (($fileRecord['sourceType'] ?? 'user') === 'user' && !empty($fileRecord['safeName'])) {
        $filePath = UPLOAD_DIR . $fileRecord['safeName'];
        if (file_exists($filePath)) unlink($filePath);
    }

    // Eliminar registro
    $db->deleteOne('compliance_files', ['_id' => $fileId]);

    // Eliminar inventario asociado
    if (!empty($fileRecord['analysisResult']['inventoryId'])) {
        $db->deleteOne('compliance_inventory', ['_id' => $fileRecord['analysisResult']['inventoryId']]);
    }
    json_response(['success' => true, 'message' => 'Archivo eliminado']);
}

// ================================================================
// 8. MAPEO MANUAL DE COLUMNAS
// ================================================================
function mapColumns() {
    $user = Auth::requireAuth();
    $body = get_body();
    $fileId = $body['fileId'] ?? '';
    $mapping = $body['mapping'] ?? [];
    if (!$fileId || empty($mapping)) json_error('fileId y mapping requeridos');

    $db = Database::getInstance();
    $fileRecord = $db->findOne('compliance_files', ['_id' => $fileId, 'userId' => $user['_id']]);
    if (!$fileRecord) json_error('Archivo no encontrado', 404);

    $analysis = $fileRecord['analysisResult'] ?? [];
    $analysis['manualMapping'] = $mapping;
    $analysis['manualMappedAt'] = date('c');

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

// ================================================================
// 9. NUEVO: ESCANEO POR AGENTE (reporte de archivos detectados)
// ================================================================
function agentScan() {
    $user = Auth::requireAuth();
    $body = get_body();

    $required = ['agentId', 'path', 'hash', 'fileType'];
    foreach ($required as $field) {
        if (empty($body[$field])) json_error("Campo '$field' requerido");
    }

    $db = Database::getInstance();

    // Buscar si ya existe (mismo agente y misma ruta)
    $existing = $db->findOne('compliance_files', [
        'agentId' => $body['agentId'],
        'path'    => $body['path'],
        'sourceType' => 'agent'
    ]);

    $doc = [
        'userId'        => $user['_id'],
        'sourceType'    => 'agent',
        'agentId'       => $body['agentId'],
        'hostname'      => $body['hostname'] ?? 'unknown',
        'path'          => $body['path'],
        'originalName'  => basename($body['path']),
        'ext'           => strtolower(pathinfo($body['path'], PATHINFO_EXTENSION)),
        'size'          => (int)($body['size'] ?? 0),
        'hash'          => $body['hash'],
        'mimeType'      => $body['mimeType'] ?? 'application/octet-stream',
        'status'        => 'analyzed',
        'analysisResult' => [
            'rowCount'    => (int)($body['rowCount'] ?? 0),
            'headers'     => array_keys($body['personalData'] ?? []),
            'patterns'    => $body['personalData'] ?? [],
            'sensitive'   => !empty($body['sensitive']),
            'analyzedAt'  => date('c'),
            'analyzedBy'  => 'agent',
        ],
        'createdAt'     => date('c'),
        'updatedAt'     => date('c'),
    ];

    if ($existing) {
        $db->updateOne('compliance_files', ['_id' => $existing['_id']], $doc);
        $fileId = $existing['_id'];
        $inventoryId = $existing['analysisResult']['inventoryId'] ?? null;
    } else {
        $inserted = $db->insertOne('compliance_files', $doc);
        $fileId = $inserted['_id'];
        $inventoryId = null;
    }

    // ── Crear/Actualizar el inventario (RAT) ──
    $categories = [];
    foreach ($body['personalData'] ?? [] as $col => $types) {
        $categories = array_merge($categories, $types);
    }
    $categories = array_unique($categories);

    $inventoryData = [
        'userId'         => $user['_id'],
        'sourceType'     => 'file',
        'sourceId'       => $fileId,
        'name'           => '📄 Archivo: ' . basename($body['path']),
        'dataCategories' => implode(', ', $categories),
        'records'        => (int)($body['rowCount'] ?? 0),
        'sensitive'      => !empty($body['sensitive']),
        'legalBasis'     => 'Pendiente de definir',
        'active'         => true,
        'storage'        => $body['hostname'] ?? 'Agente',
        'updatedAt'      => date('c'),
    ];

    if ($inventoryId) {
        $db->updateOne('compliance_inventory', ['_id' => $inventoryId], $inventoryData);
    } else {
        $inventoryData['createdAt'] = date('c');
        $inv = $db->insertOne('compliance_inventory', $inventoryData);
        $db->updateOne('compliance_files', ['_id' => $fileId], [
            'analysisResult.inventoryId' => $inv['_id']
        ]);
    }

    json_response([
        'success' => true,
        'fileId'  => $fileId,
        'message' => 'Archivo reportado por agente y registrado en inventario'
    ]);


}



// ─── Listar logs de auditoría de archivos ───
function listFileAuditLogs() {
    $user = Auth::requireAuth();
    $db = Database::getInstance();
    $limit = (int)($_GET['limit'] ?? 200);
    $skip = (int)($_GET['skip'] ?? 0);
    $filter = ['userId' => $user['_id']];
    $logs = $db->find('file_audit_logs', $filter, ['limit' => $limit, 'skip' => $skip]);
    $total = $db->count('file_audit_logs', $filter);
    json_response([
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'skip' => $skip,
    ]);
}

// ─── NUEVA función agentScan con auditoría ───
// Reemplaza la función agentScan() existente por esta versión mejorada
function agentScan() {
    $user = Auth::requireAuth();
    $body = get_body();

    $required = ['agentId', 'path', 'hash', 'fileType'];
    foreach ($required as $field) {
        if (empty($body[$field])) json_error("Campo '$field' requerido");
    }

    $db = Database::getInstance();

    // Verificar si ya existe
    $existing = $db->findOne('compliance_files', [
        'agentId' => $body['agentId'],
        'path'    => $body['path'],
        'sourceType' => 'agent'
    ]);

    // Preparar documento
    $doc = [
        'userId'        => $user['_id'],
        'sourceType'    => 'agent',
        'agentId'       => $body['agentId'],
        'hostname'      => $body['hostname'] ?? 'unknown',
        'path'          => $body['path'],
        'originalName'  => basename($body['path']),
        'ext'           => strtolower(pathinfo($body['path'], PATHINFO_EXTENSION)),
        'size'          => (int)($body['size'] ?? 0),
        'hash'          => $body['hash'],
        'mimeType'      => $body['mimeType'] ?? 'application/octet-stream',
        'status'        => 'analyzed',
        'user'          => $body['user'] ?? null,
        'analysisResult' => [
            'rowCount'    => (int)($body['rowCount'] ?? 0),
            'headers'     => array_keys($body['personalData'] ?? []),
            'patterns'    => $body['personalData'] ?? [],
            'sensitive'   => !empty($body['sensitive']),
            'analyzedAt'  => date('c'),
            'analyzedBy'  => 'agent',
            'user'        => $body['user'] ?? null,
        ],
        'createdAt'     => date('c'),
        'updatedAt'     => date('c'),
    ];

    if ($existing) {
        $db->updateOne('compliance_files', ['_id' => $existing['_id']], $doc);
        $fileId = $existing['_id'];
        $inventoryId = $existing['analysisResult']['inventoryId'] ?? null;
    } else {
        $inserted = $db->insertOne('compliance_files', $doc);
        $fileId = $inserted['_id'];
        $inventoryId = null;
    }

    // ── Crear/Actualizar el inventario (RAT) ──
    $categories = [];
    foreach ($body['personalData'] ?? [] as $col => $types) {
        $categories = array_merge($categories, $types);
    }
    $categories = array_unique($categories);

    $inventoryData = [
        'userId'         => $user['_id'],
        'sourceType'     => 'file',
        'sourceId'       => $fileId,
        'name'           => '📄 Archivo: ' . basename($body['path']),
        'dataCategories' => implode(', ', $categories),
        'records'        => (int)($body['rowCount'] ?? 0),
        'sensitive'      => !empty($body['sensitive']),
        'legalBasis'     => 'Pendiente de definir',
        'active'         => true,
        'storage'        => $body['hostname'] ?? 'Agente',
        'user'           => $body['user'] ?? null,
        'updatedAt'      => date('c'),
    ];

    if ($inventoryId) {
        $db->updateOne('compliance_inventory', ['_id' => $inventoryId], $inventoryData);
    } else {
        $inventoryData['createdAt'] = date('c');
        $inv = $db->insertOne('compliance_inventory', $inventoryData);
        $db->updateOne('compliance_files', ['_id' => $fileId], [
            'analysisResult.inventoryId' => $inv['_id']
        ]);
    }

    // ─── GUARDAR EN AUDITORÍA DE ARCHIVOS ───
    $db->insertOne('file_audit_logs', [
        'userId' => $user['_id'],
        'agentId' => $body['agentId'],
        'hostname' => $body['hostname'] ?? 'unknown',
        'path' => $body['path'],
        'user' => $body['user'] ?? null,
        'detectedAt' => date('c'),
        'categories' => array_keys($body['personalData'] ?? []),
        'sensitive' => !empty($body['sensitive']),
        'rowCount' => (int)($body['rowCount'] ?? 0),
        'fileType' => $body['fileType'] ?? 'unknown',
        'hash' => $body['hash'],
        'status' => 'processed',
    ]);

    // ─── GUARDAR EN LOG DE AUDITORÍA GENERAL ───
    $db->insertOne('audit_logs', [
        'userId' => $user['_id'],
        'action' => 'file_detected_by_agent',
        'details' => [
            'agentId' => $body['agentId'],
            'path' => $body['path'],
            'user' => $body['user'] ?? null,
            'sensitive' => !empty($body['sensitive']),
            'categories' => array_keys($body['personalData'] ?? []),
        ],
        'createdAt' => date('c'),
    ]);

    json_response([
        'success' => true,
        'fileId'  => $fileId,
        'message' => 'Archivo reportado por agente y registrado en inventario'
    ]);
}

