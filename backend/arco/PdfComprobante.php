<?php
// backend/arco/PdfComprobante.php
// GET /api/arco/requests/{id}/receipt — Comprobante de recepción (público).

namespace Arco;

class PdfComprobante
{
    public static function ejecutar()
    {
        $requestId = $_GET['requestId'] ?? '';
        $email     = $_GET['email']     ?? '';

        if (!$requestId) \json_error('requestId requerido');

        $bd = \Database::getInstance();
        $req = $bd->findOne('arco_requests', ['requestId' => $requestId]);
        if (!$req) \json_error('solicitud no encontrada', 404);

        $req = \BsonHelpers::toArray($req);

        // Solicitante
        $solicitante = $req['solicitante'] ?? [];
        if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
        if (!is_array($solicitante))  $solicitante = [];

        $emailSolicitante = $solicitante['email'] ?? ($req['email'] ?? '');
        if ($email && strtolower($email) !== strtolower($emailSolicitante)) {
            \json_error('verificación de email fallida', 403);
        }

        $empresa = \BsonHelpers::toArray(
            $bd->findOne('users', ['_id' => ($req['companyId'] ?? '')]) ?? []
        );
        $nombreEmpresa = $empresa['companyName'] ?? ($empresa['name'] ?? 'Empresa');
        $nombreDpd  = $empresa['dpdName']  ?? '—';
        $emailDpd   = $empresa['dpdEmail'] ?? '—';

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

        $nombre      = $solicitante['nombre'] ?? ($req['name']  ?? 'Titular');
        $rut         = $solicitante['rut']    ?? ($req['rut']   ?? '—');
        $emailVista  = $solicitante['email']  ?? ($req['email'] ?? '—');
        $fechaSolicitud = substr(($req['createdAt'] ?? date('c')), 0, 10);
        $fechaComprobante = date('d/m/Y');

        $h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');

        $html  = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'>";
        $html .= "<title>Comprobante ARCO - {$h($tipoTexto)}</title>";
        $html .= "<style>
            @page{margin:0}
            body{font-family:'DejaVu Sans',Arial,sans-serif;font-size:10px;line-height:1.6;color:#1a1a1a;margin:0}
            .footer-fixed{position:fixed;bottom:0;left:0;right:0;height:22px;background:#f5f5f5;border-top:0.5px solid #cccccc;color:#999999;font-size:7px;padding:6px 45px 0 45px}
            .topline{height:2px;background:#000000}
            .head-wrap{padding:30px 60px 0 60px;text-align:center}
            .head-label{color:#777777;font-size:9px}
            .head-law{color:#777777;font-size:8px;margin-top:4px}
            .head-sep{border-top:0.5px solid #000000;margin:18px 40px 0 40px}
            .head-company{color:#1a1a1a;font-size:14px;font-weight:bold;margin-top:16px;text-transform:uppercase}
            .head-title{color:#1a1a1a;font-size:15px;font-weight:bold;margin-top:8px}
            .head-box{background:#f5f5f5;border:0.5px solid #bbbbbb;margin:16px 40px 0 40px;padding:7px 10px;color:#1a1a1a;font-size:8px;font-weight:bold}
            .content{padding:20px 60px 50px 60px}
            .meta{margin-bottom:16px;background:#f5f5f5;border:0.5px solid #bbbbbb;padding:12px 14px}
            .meta div{margin-bottom:4px;font-size:9px}
            .label{font-weight:bold;color:#555555;font-size:8px;text-transform:uppercase}
            .subject{font-size:12px;font-weight:bold;margin:18px 0 12px;border-left:4px solid #000000;padding:2px 0 2px 10px}
            .body p{margin-bottom:10px;text-align:justify;font-size:10px}
            .data-table{width:100%;border-collapse:collapse;margin:12px 0}
            .data-table td{border-bottom:0.3px solid #e0e0e0;padding:6px 8px;font-size:9px}
            .stamp{display:inline-block;margin-top:26px;padding:8px 15px;border:1.5px dashed #166534;color:#166534;font-weight:bold;font-size:10px}
        </style></head><body>";

        $html .= "<div class='footer-fixed'>Ley 21.719 - Comprobante de Recepción ARCO · {$h($nombreEmpresa)}</div>";
        $html .= "<div class='topline'></div>";
        $html .= "<div class='head-wrap'>";
        $html .= "<div class='head-label'>REPÚBLICA DE CHILE</div>";
        $html .= "<div class='head-law'>Ley 21.719 - Protección de Datos Personales</div>";
        $html .= "<div class='head-sep'></div>";
        $html .= "<div class='head-company'>{$h($nombreEmpresa)}</div>";
        $html .= "<div class='head-title'>Comprobante de Recepción - {$h($tipoTexto)}</div>";
        $html .= "<div class='head-box'>CLASIFICACIÓN: CONFIDENCIAL · DPD: {$h($nombreDpd)} ({$h($emailDpd)})</div>";
        $html .= "</div>";
        $html .= "<div class='content'>";

        $html .= "<div class='meta'>";
        $html .= "<div><span class='label'>Número de solicitud:</span> {$h($requestId)}</div>";
        $html .= "<div><span class='label'>Derecho ejercido:</span> {$h($tipoTexto)}</div>";
        $html .= "<div><span class='label'>Fecha de recepción:</span> {$h($fechaSolicitud)}</div>";
        $html .= "<div><span class='label'>Fecha de comprobante:</span> {$h($fechaComprobante)}</div>";
        $html .= "</div>";

        $html .= "<div class='meta'>";
        $html .= "<div><span class='label'>Titular:</span> {$h($nombre)}</div>";
        $html .= "<div><span class='label'>RUT:</span> {$h($rut)}</div>";
        $html .= "<div><span class='label'>Email:</span> {$h($emailVista)}</div>";
        $html .= "</div>";

        $html .= "<table class='data-table'>";
        $html .= "<tr><td class='label'>Descripción de la solicitud</td><td>"
               . $h($req['descripcion'] ?? 'Sin descripción adicional.') . "</td></tr>";
        $html .= "</table>";

        $html .= "<div class='body'>";
        $html .= "<p>De conformidad con la Ley 21.719, la presente solicitud ha sido registrada por el responsable del tratamiento. El plazo máximo de respuesta es de 30 días corridos, pudiendo extenderse por un plazo adicional de hasta 30 días cuando concurren causas justificadas y se notifica oportunamente al titular.</p>";
        $html .= "<p>El titular podrá hacer seguimiento de esta solicitud mediante el número de referencia <strong>{$h($requestId)}</strong> y el email declarado en el formulario.</p>";
        $html .= "</div>";

        $html .= "<div class='stamp'>SOLICITUD RECIBIDA</div>";
        $html .= "</div></body></html>";

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="comprobante_arco_' . $requestId . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}