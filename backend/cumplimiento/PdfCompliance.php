<?php
// backend/cumplimiento/PdfCompliance.php
// Dispatcher de generación de PDFs de cumplimiento.
// Delega en PDFGenerator.php (que vive un nivel arriba, en backend/).

namespace Cumplimiento;

class PdfCompliance
{
    public static function generar(string $recurso)
    {
        try {
            $usuario = \Auth::requireAuth();
            $bd = \Database::getInstance();

            // ✅ FIX: un solo nivel arriba, no dos.
            // __DIR__ = /var/www/html/cumplimiento
            // __DIR__/.. = /var/www/html   ← aquí está PDFGenerator.php
            $pdfGeneratorPath = __DIR__ . '/../PDFGenerator.php';

            if (!is_file($pdfGeneratorPath)) {
                \error_log('[PdfCompliance] PDFGenerator.php no encontrado en ' . $pdfGeneratorPath);
                \json_error('PDFGenerator.php no encontrado en el servidor', 500);
            }
            require_once $pdfGeneratorPath;

            if (!class_exists('PDFGenerator')) {
                \error_log('[PdfCompliance] Clase PDFGenerator no definida tras require');
                \json_error('Clase PDFGenerator no disponible', 500);
            }

            $generador = new \PDFGenerator($bd, $usuario);
            $itemId = $_GET['id'] ?? null;

            switch ($recurso) {
                case 'consents':
                    $html = $generador->generateConsentPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'consentimientos');
                    break;

                case 'inventory':
                    $html = $generador->generateInventoryPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'inventario');
                    break;

                case 'breaches':
                    $html = $generador->generateBreachesPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'brechas');
                    break;

                case 'trainings':
                    $html = $generador->generateTrainingsPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'capacitaciones');
                    break;

                case 'pseudonymization':
                    $html = $generador->generatePseudonymizationPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'seudonimizacion');
                    break;

                case 'arco-requests':
                    $html = $generador->generateARCORequestsPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'solicitudes-arco');
                    break;

                case 'dpia':
                    $html = $generador->generateDPIAPDF($itemId);
                    $res  = $generador->generatePDFFile($html, 'dpia');
                    break;

                case 'arco':
                    $doc = $bd->findOne('compliance_checklist', [
                        'userId'  => $usuario['_id'],
                        'section' => 'arco',
                    ]);
                    $data = (array)($doc['data'] ?? []);
                    array_walk_recursive($data, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Canal de Derechos ARCO', $data);
                    $res  = $generador->generatePDFFile($html, 'arco');
                    break;

                case 'incident-response':
                case 'incident_response':
                    $irDoc = $bd->findOne('compliance_incident_response', ['userId' => $usuario['_id']])
                          ?? $bd->findOne('compliance_checklist', [
                                'userId'  => $usuario['_id'],
                                'section' => 'incident_response',
                             ]);
                    $irData = (array)(!empty($irDoc['data']) ? $irDoc['data'] : $irDoc);
                    array_walk_recursive($irData, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Plan de Respuesta a Incidentes', $irData);
                    $res  = $generador->generatePDFFile($html, 'respuesta-incidentes');
                    break;

                case 'breach-protocol':
                case 'breach_protocol':
                    $bpDoc = $bd->findOne('compliance_breach_protocol', ['userId' => $usuario['_id']]) ?? [];
                    $bpCfg = $bd->findOne('compliance_config',          ['userId' => $usuario['_id']]) ?? [];
                    $bpData = array_merge((array)$bpDoc, array_filter([
                        'URL del protocolo'       => $bpCfg['breachProtocolUrl']     ?? null,
                        'Contenido del protocolo' => $bpCfg['breachProtocolContent'] ?? null,
                    ]));
                    array_walk_recursive($bpData, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Protocolo de Brechas', $bpData);
                    $res  = $generador->generatePDFFile($html, 'protocolo-brechas');
                    break;

                case 'apdp':
                    $cfg = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];
                    $data = array_filter([
                        'Registrado ante la APDP' => isset($cfg['apdpRegistered']) ? ($cfg['apdpRegistered'] ? 'Sí' : 'No') : null,
                        'Número de registro'      => $cfg['apdpRegistrationNumber'] ?? null,
                        'Fecha de registro'       => $cfg['apdpRegistrationDate']   ?? null,
                        'Entidad certificadora'   => $cfg['apdpEntity']             ?? null,
                        'Observaciones'           => $cfg['apdpNotes']              ?? null,
                    ]);
                    array_walk_recursive($data, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Modelo de Prevención Certificado (APDP)', $data);
                    $res  = $generador->generatePDFFile($html, 'modelo-certificado');
                    break;

                case 'privacy':
                    $cfg = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];
                    $data = array_filter([
                        'URL de la política'       => $cfg['privacyPolicyUrl']       ?? null,
                        'Contenido de la política' => $cfg['privacyPolicyContent']   ?? null,
                        'URL política de cookies'  => $cfg['cookiesPolicyUrl']       ?? null,
                        'Última actualización'     => $cfg['privacyPolicyUpdatedAt'] ?? ($cfg['updatedAt'] ?? null),
                    ]);
                    array_walk_recursive($data, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Política de Privacidad', $data);
                    $res  = $generador->generatePDFFile($html, 'politica-privacidad');
                    break;

                case 'dpd':
                    $cfg = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];
                    $data = array_filter([
                        'Nombre del DPD'       => $cfg['dpdName']            ?? null,
                        'Email del DPD'        => $cfg['dpdEmail']           ?? null,
                        'Teléfono'             => $cfg['dpdPhone']           ?? null,
                        'Fecha de designación' => $cfg['dpdAppointmentDate'] ?? null,
                        'Registro ante APDP'   => $cfg['dpdApdpRecord']      ?? null,
                    ]);
                    array_walk_recursive($data, function (&$item) {
                        if (is_array($item) || is_object($item)) {
                            $item = json_encode($item, JSON_UNESCAPED_UNICODE);
                        }
                    });
                    $html = $generador->generateGenericChecklistPDF('Delegado de Protección de Datos (DPD)', $data);
                    $res  = $generador->generatePDFFile($html, 'dpd-designado');
                    break;

                default:
                    \json_error('Recurso no soportado para generación de PDF', 400);
            }

            \json_response([
                'success'   => true,
                'pdfUrl'    => $res['pdfUrl']    ?? null,
                'pdfBase64' => $res['pdfBase64'] ?? null,
                'html'      => $res['html']      ?? null,
                'message'   => $res['message']   ?? '',
            ]);

        } catch (\Throwable $e) {
            \error_log('PdfCompliance error (' . $recurso . '): ' . $e->getMessage()
                     . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            \json_error('No se pudo generar el documento: ' . $e->getMessage(), 500);
        }
    }
}