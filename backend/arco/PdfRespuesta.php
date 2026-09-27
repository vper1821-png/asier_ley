<?php
// backend/arco/PdfRespuesta.php
// GET /api/arco/requests/{id}/document

namespace Arco;

class PdfRespuesta
{
    public static function ejecutar()
    {
        $usuario   = \Auth::requireAuth();
        $requestId = $_GET['requestId'] ?? ($_GET['id'] ?? '');
        if (!$requestId) \json_error('requestId requerido');

        $bd = \Database::getInstance();
        $req = $bd->findOne('arco_requests', ['requestId' => $requestId]);
        if (!$req) \json_error('solicitud no encontrada', 404);

        // Convertir a array plano (evita problemas con BSONDocument)
        $req = \BsonHelpers::toArray($req);

        // Verificación de acceso
        if (!AlcanceArco::puedeAcceder($usuario, $bd, $req)) {
            if (empty($req['companyId'])) {
                \json_error('solicitud pública - solo administradores pueden acceder', 403);
            }
            \json_error('acceso denegado', 403);
        }

        $config = \BsonHelpers::toArray(
            $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? []
        );
        $nombreEmpresa = $config['companyName']
                       ?? ($usuario['companyName'] ?? ($usuario['email'] ?? 'Empresa'));
        $nombreDpd  = $config['dpdName']  ?? '—';
        $emailDpd   = $config['dpdEmail'] ?? '—';

        // Etiqueta del tipo
        $tipo = $req['tipo'] ?? ($req['type'] ?? 'acceso');
        $etiquetasTipo = [
            'acceso'        => 'Acceso',
            'rectificacion' => 'Rectificación',
            'cancelacion'   => 'Cancelación',
            'oposicion'     => 'Oposición',
            'portabilidad'  => 'Portabilidad',
            'supresion'     => 'Supresión',
            'bloqueo'       => 'Bloqueo',
        ];
        $tipoTexto = $etiquetasTipo[$tipo] ?? ucfirst($tipo);

        // Solicitante
        $solicitante = $req['solicitante'] ?? [];
        if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
        if (!is_array($solicitante))  $solicitante = [];

        $nombre = $solicitante['nombre'] ?? ($req['name']  ?? 'Titular');
        $rut    = $solicitante['rut']    ?? ($req['rut']   ?? '—');
        $email  = $solicitante['email']  ?? ($req['email'] ?? '—');

        $fechaSolicitud = substr(($req['createdAt'] ?? date('c')), 0, 10);
        $rawRespuesta = $req['respondedAt']
                     ?? ($req['resolvedAt']
                     ?? ($req['finishedAt']
                     ?? ($req['updatedAt'] ?? date('c'))));
        $fechaRespuesta = date('d/m/Y', strtotime($rawRespuesta));
        $generadoEn     = date('d/m/Y H:i');

        // Etiquetas de estado
        $etiquetasEstado = [
            'pending'     => 'Pendiente',
            'in_progress' => 'En proceso',
            'completed'   => 'Completada',
            'resolved'    => 'Completada',
            'finished'    => 'Terminada',
            'rejected'    => 'Rechazada',
        ];
        $estadoTexto = $etiquetasEstado[$req['status'] ?? 'pending'] ?? ucfirst($req['status'] ?? 'pendiente');

        // Responsable
        $respondidoPor = $req['respondedBy'] ?? null;
        if (!$respondidoPor || $respondidoPor === 'kp') {
            if ($nombreDpd !== '—')          $respondidoPor = $nombreDpd;
            elseif (!empty($usuario['name']))  $respondidoPor = $usuario['name'];
            elseif (!empty($usuario['email'])) $respondidoPor = $usuario['email'];
            else                               $respondidoPor = 'Responsable';
        }

        // Historial
        $historial = $req['statusHistory'] ?? [];
        if (!is_array($historial)) $historial = [];

        $h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

        // Párrafos legales por tipo
        $parrafosLegales = [
            'acceso' => [
                'De conformidad con el artículo 8 de la Ley 21.719, usted tiene derecho a acceder a los datos personales que el responsable del tratamiento mantiene bajo su nombre.',
                'A continuación se detalla la información disponible asociada a su identidad en nuestros registros. Si existieran datos adicionales que no sean posibles de incluir en este documento, serán entregados en un plazo adicional no mayor a 30 días corridos, conforme al artículo 8 de la Ley 21.719.',
                'El acceso se otorga sin costo alguno y la presente respuesta no exime al titular de ejercer otros derechos reconocidos por la ley (rectificación, cancelación, oposición o portabilidad).',
            ],
            'rectificacion' => [
                'De conformidad con el artículo 9 de la Ley 21.719, el titular tiene derecho a rectificar los datos personales que resulten inexactos, erróneos, engañosos o desactualizados.',
                'Una vez recibida la solicitud y verificada la identidad del titular, el responsable del tratamiento procederá a corregir los datos indicados dentro del plazo legal de 30 días corridos. En caso de no ser procedente la rectificación, se informarán los motivos debidamente fundamentados.',
                'La rectificación será comunicada a terceros que hubieren recibido los datos, cuando ello sea posible y no resulte desproporcionado.',
            ],
            'cancelacion' => [
                'De conformidad con el artículo 10 de la Ley 21.719, el titular tiene derecho a solicitar la cancelación o supresión de sus datos personales cuando la finalidad del tratamiento no existiere o hubiere dejado de ser necesaria.',
                'El responsable del tratamiento evaluará la solicitud dentro del plazo de 30 días corridos. Si concurren causales de cancelación, los datos serán bloqueados y posteriormente eliminados, salvo en los casos lícitos de conservación previstos en la ley (por ejemplo, obligaciones legales o contractuales).',
                'La cancelación no procederá respecto de datos cuya conservación sea necesaria para el cumplimiento de una obligación legal o la ejecución de un contrato.',
            ],
            'oposicion' => [
                'De conformidad con el artículo 11 de la Ley 21.719, el titular tiene derecho a oponerse al tratamiento de sus datos personales en determinadas circunstancias, salvo que concurran causas legítimas que prevalezcan sobre los derechos del titular.',
                'El responsable del tratamiento analizará la solicitud dentro del plazo legal de 30 días corridos. Si la oposición resulta procedente, se suspenderá el tratamiento afectado y se informará a terceros a quienes se hubieren transferido los datos, cuando sea posible.',
                'La oposición no procederá cuando el tratamiento sea necesario para el cumplimiento de una obligación legal, la ejecución de un contrato o el interés legítimo debidamente ponderado.',
            ],
            'portabilidad' => [
                'De conformidad con el artículo 13 de la Ley 21.719, el titular tiene derecho a obtener una copia de sus datos personales en un formato estructurado, de uso común y lectura mecánica, para poder transmitirlos a otro responsable del tratamiento.',
                'El responsable del tratamiento entregará la información en el formato solicitado o en un formato interoperable de uso común, dentro del plazo legal de 30 días corridos. Los datos se transmitirán de manera segura y se acompañarán de la información necesaria para su comprensión.',
                'El derecho de portabilidad se limita a los datos personales proporcionados por el titular y no se extiende a datos inferidos o derivados del tratamiento.',
            ],
            'supresion' => [
                'De conformidad con el artículo 7 de la Ley 21.719, el titular tiene derecho a obtener la supresión o eliminación de sus datos personales cuando los datos ya no sean necesarios para los fines para los que fueron recogidos, cuando el titular retire su consentimiento y no exista otra base legal, cuando se oponga al tratamiento y no prevalezcan motivos legítimos, o cuando los datos hayan sido tratados ilícitamente.',
                'El responsable del tratamiento procederá a la supresión de los datos en un plazo máximo de 30 días corridos desde la recepción de la solicitud, salvo que exista una obligación legal de conservación que impida la eliminación. En tal caso, los datos se bloquearán y solo se conservarán para la atención de posibles responsabilidades derivadas del tratamiento.',
                'La supresión será comunicada a terceros a quienes se hubieren transferido los datos, cuando ello sea posible y no resulte desproporcionado.',
            ],
            'bloqueo' => [
                'De conformidad con el artículo 8 ter de la Ley 21.719, el titular tiene derecho a solicitar el bloqueo temporal de sus datos personales mientras se resuelva una solicitud de rectificación, supresión u oposición.',
                'El bloqueo implica la identificación y reserva de los datos, impidiendo su tratamiento para cualquier finalidad distinta a la de atender la solicitud del titular. Los datos bloqueados no podrán ser utilizados, comunicados ni cedidos mientras dure el bloqueo.',
                'El responsable del tratamiento deberá informar al titular de la procedencia del bloqueo y de su levantamiento una vez resuelta la solicitud principal.',
            ],
        ];
        $cuerpoLegal = $parrafosLegales[$tipo] ?? $parrafosLegales['acceso'];

        $hashVerificacion = strtoupper(substr(hash(
            'sha256',
            $requestId . '|' . $rawRespuesta . '|' . ($req['status'] ?? '')
        ), 0, 16));

        $etiquetasCambio = [
            'status'          => 'Cambio de estado',
            'response'        => 'Respuesta agregada',
            'status+response' => 'Estado + respuesta',
        ];

        // ═══ HTML ═══
        $html  = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'>";
        $html .= "<title>Respuesta ARCO - {$h($tipoTexto)}</title>";
        $html .= "<style>
            @page { margin: 0; }
            body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; line-height: 1.6; color: #1a1a1a; margin: 0; }
            .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
            .footer-page { float: right; color: #666; }
            .topline { height: 2px; background: #000000; }
            .head-wrap { padding: 30px 60px 0 60px; text-align: center; }
            .head-label { color: #777777; font-size: 9px; }
            .head-law { color: #777777; font-size: 8px; margin-top: 4px; }
            .head-sep { border-top: 0.5px solid #000000; margin: 18px 40px 0 40px; }
            .head-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 16px; text-transform: uppercase; }
            .head-title { color: #1a1a1a; font-size: 15px; font-weight: bold; margin-top: 8px; }
            .head-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 16px 40px 0 40px; padding: 7px 10px; color: #1a1a1a; font-size: 8px; font-weight: bold; }
            .content { padding: 20px 60px 50px 60px; }
            .meta { margin-bottom: 18px; background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px 14px; }
            .meta div { margin-bottom: 4px; font-size: 9px; }
            .label { font-weight: bold; color: #555555; font-size: 8px; text-transform: uppercase; }
            .subject { font-size: 12px; font-weight: bold; margin: 20px 0 12px; border-left: 4px solid #000000; padding: 2px 0 2px 10px; }
            .body p { margin-bottom: 10px; text-align: justify; font-size: 10px; }
            .data-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
            .data-table th { background: #1a1a1a; color: #cccccc; font-size: 8px; font-weight: bold; text-align: left; padding: 6px 8px; }
            .data-table td { border-bottom: 0.3px solid #e0e0e0; padding: 6px 8px; font-size: 9px; vertical-align: top; }
            .signature { margin-top: 45px; }
            .signature p { margin: 4px 0; font-size: 10px; }
            .entry { border-left: 3px solid #000000; padding: 6px 0 6px 10px; margin: 10px 0 14px; page-break-inside: avoid; }
            .entry-head { font-size: 9px; color: #555555; margin: 0 0 6px; }
            .entry-head strong { color: #1a1a1a; }
            .entry-body p { font-size: 10px; text-align: justify; margin: 0 0 6px; }
            .badge { display: inline-block; padding: 1px 6px; border: 0.5px solid #888; border-radius: 8px; font-size: 7px; color: #333; background: #fafafa; }
            .status-pending { background: #fef3c7; border-color: #d97706; color: #92400e; }
            .status-progress { background: #dbeafe; border-color: #2563eb; color: #1e40af; }
            .status-completed { background: #d1fae5; border-color: #059669; color: #065f46; }
            .status-finished { background: #ccfbf1; border-color: #0d9488; color: #115e59; }
            .status-rejected { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
            .empty-note { font-size: 10px; color: #555555; font-style: italic; }
            .verify { margin-top: 30px; padding: 8px 12px; background: #f8f8f8; border: 0.5px dashed #999; font-size: 8px; color: #555; font-family: 'DejaVu Sans Mono', monospace; }
        </style></head><body>";

        $html .= "<div class='footer-fixed'>Ley 21.719 - Respuesta a Derecho ARCO · {$h($nombreEmpresa)} <span class='footer-page'>Generado el {$h($generadoEn)}</span></div>";
        $html .= "<div class='topline'></div>";
        $html .= "<div class='head-wrap'>";
        $html .= "<div class='head-label'>REPÚBLICA DE CHILE</div>";
        $html .= "<div class='head-law'>Ley 21.719 - Protección de Datos Personales</div>";
        $html .= "<div class='head-sep'></div>";
        $html .= "<div class='head-company'>{$h($nombreEmpresa)}</div>";
        $html .= "<div class='head-title'>Respuesta a Solicitud de {$h($tipoTexto)}</div>";
        $html .= "<div class='head-box'>CLASIFICACIÓN: CONFIDENCIAL · DPD: {$h($nombreDpd)} ({$h($emailDpd)})</div>";
        $html .= "</div>";
        $html .= "<div class='content'>";

        $html .= "<div class='meta'>";
        $html .= "<div><span class='label'>Número de solicitud:</span> {$h($requestId)}</div>";
        $html .= "<div><span class='label'>Tipo de derecho:</span> {$h($tipoTexto)}</div>";
        $html .= "<div><span class='label'>Fecha de recepción:</span> {$h($fechaSolicitud)}</div>";
        $html .= "<div><span class='label'>Fecha de respuesta:</span> {$h($fechaRespuesta)}</div>";
        $html .= "<div><span class='label'>Estado:</span> {$h($estadoTexto)}</div>";
        $html .= "<div><span class='label'>Gestionada por:</span> {$h($respondidoPor)}</div>";
        $html .= "</div>";

        $html .= "<div class='subject'>Respuesta a solicitud de {$h($tipoTexto)} - Ley 21.719</div>";
        $html .= "<div class='meta'>";
        $html .= "<div><span class='label'>Titular:</span> {$h($nombre)}</div>";
        $html .= "<div><span class='label'>RUT:</span> {$h($rut)}</div>";
        $html .= "<div><span class='label'>Email:</span> {$h($email)}</div>";
        $html .= "</div>";

        $html .= "<div class='body'>";
        foreach ($cuerpoLegal as $p) $html .= "<p>{$h($p)}</p>";
        $html .= "</div>";

        $textoRespuesta = trim((string)($req['response'] ?? ''));
        $html .= "<div class='subject'>Respuesta del responsable</div>";
        if ($textoRespuesta !== '') {
            foreach (preg_split('/\r?\n/', $textoRespuesta) as $linea) {
                $linea = trim($linea);
                if ($linea !== '') $html .= "<p style='text-align:justify;font-size:10px;margin-bottom:8px'>{$h($linea)}</p>";
            }
            $html .= "<p style='font-size:8px;color:#555555;margin-top:6px'>Registrada por {$h($respondidoPor)} el {$h($fechaRespuesta)}</p>";
        } else {
            $html .= "<p class='empty-note'>Aún no se ha registrado una respuesta específica para esta solicitud.</p>";
        }

        if (!empty($historial)) {
            $html .= "<div class='subject' style='font-size:11px;margin-top:20px'>Historial de gestión</div>";
            $html .= "<table class='data-table'>";
            $html .= "<tr><th style='width:100px'>Fecha</th><th style='width:80px'>Estado</th>"
                   . "<th style='width:120px'>Responsable</th><th>Tipo de cambio</th></tr>";

            foreach ($historial as $ev) {
                $fechaEv  = !empty($ev['at']) ? date('d/m/Y H:i', strtotime($ev['at'])) : '—';
                $estadoEv = $etiquetasEstado[$ev['status'] ?? ''] ?? ucfirst($ev['status'] ?? '—');
                $porEv    = $ev['by'] ?? '—';
                $tipoEv   = $etiquetasCambio[$ev['kind'] ?? ''] ?? '—';

                $claseEstado = 'badge';
                switch ($ev['status'] ?? '') {
                    case 'pending':     $claseEstado .= ' status-pending';   break;
                    case 'in_progress': $claseEstado .= ' status-progress';  break;
                    case 'completed':
                    case 'resolved':    $claseEstado .= ' status-completed'; break;
                    case 'finished':    $claseEstado .= ' status-finished';  break;
                    case 'rejected':    $claseEstado .= ' status-rejected';  break;
                }

                $html .= "<tr><td>{$h($fechaEv)}</td>"
                       . "<td><span class='{$claseEstado}'>{$h($estadoEv)}</span></td>"
                       . "<td>{$h($porEv)}</td>"
                       . "<td>{$h($tipoEv)}</td></tr>";
            }
            $html .= "</table>";

            // Cuerpo completo de cada respuesta
            $html .= "<div style='margin-top:14px'>";
            $numEntrada = 0;
            foreach ($historial as $ev) {
                $respuestaCompleta = trim((string)($ev['response'] ?? ''));
                if ($respuestaCompleta === '') continue;

                $numEntrada++;
                $fechaEv  = !empty($ev['at']) ? date('d/m/Y H:i', strtotime($ev['at'])) : '—';
                $estadoEv = $etiquetasEstado[$ev['status'] ?? ''] ?? ucfirst($ev['status'] ?? '—');
                $porEv    = $ev['by'] ?? '—';

                $html .= "<div class='entry'>";
                $html .= "<p class='entry-head'><strong>Entrada #{$numEntrada}</strong> · {$h($fechaEv)} · "
                       . "Estado: <strong>{$h($estadoEv)}</strong> · Responsable: {$h($porEv)}</p>";
                $html .= "<div class='entry-body'>";
                foreach (preg_split('/\r?\n/', $respuestaCompleta) as $linea) {
                    $linea = trim($linea);
                    if ($linea !== '') $html .= "<p>{$h($linea)}</p>";
                }
                $html .= "</div></div>";
            }

            if ($numEntrada === 0) {
                $html .= "<p class='empty-note'>Sin respuestas registradas aún.</p>";
            }
            $html .= "</div>";
        }

        $html .= "<div class='signature'><p>Atentamente,</p>";
        $html .= "<p><strong>{$h($nombreDpd)}</strong><br>Delegado de Protección de Datos</p></div>";

        $html .= "<div class='verify'>";
        $html .= "<strong>Verificación de integridad:</strong> {$h($hashVerificacion)}<br>";
        $html .= "Documento emitido conforme al procedimiento de derechos ARCO establecido por la Ley 21.719. ";
        $html .= "Cualquier alteración posterior invalida esta verificación.";
        $html .= "</div>";

        $html .= "</div></body></html>";

        // ─── Render ───
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="respuesta_arco_' . $requestId . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}