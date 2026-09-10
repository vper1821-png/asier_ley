<?php
// Generador de PDFs para el módulo de certificación

class CertificateGenerator {
    private $db;
    private $user;
    private $scope;

    public function __construct($db, $user, $scope) {
        $this->db = $db;
        $this->user = $user;
        $this->scope = $scope;
    }

    private function h($s) {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private function header($title, $subtitle = '', $company = '') {
        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . $this->h($title) . '</title>
        <style>
            @page { margin: 0; }
            body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10px; line-height: 1.6; color: #1a1a1a; margin: 0; }
            .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
            .topline { height: 2px; background: #000000; }
            .head-wrap { padding: 30px 60px 0 60px; text-align: center; }
            .head-label { color: #777777; font-size: 9px; }
            .head-law { color: #777777; font-size: 8px; margin-top: 4px; }
            .head-sep { border-top: 0.5px solid #000000; margin: 18px 40px 0 40px; }
            .head-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 16px; text-transform: uppercase; }
            .head-title { color: #1a1a1a; font-size: 15px; font-weight: bold; margin-top: 8px; }
            .head-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 16px 40px 0 40px; padding: 7px 10px; color: #1a1a1a; font-size: 8px; font-weight: bold; }
            .content { padding: 20px 60px 50px 60px; }
            .meta { margin-bottom: 16px; background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px 14px; }
            .meta div { margin-bottom: 4px; font-size: 9px; }
            .label { font-weight: bold; color: #555555; font-size: 8px; text-transform: uppercase; }
            .subject { font-size: 12px; font-weight: bold; margin: 18px 0 12px; border-left: 4px solid #000000; padding: 2px 0 2px 10px; }
            .data-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
            .data-table th { background: #1a1a1a; color: #cccccc; font-size: 8px; font-weight: bold; text-align: left; padding: 6px 8px; }
            .data-table td { border-bottom: 0.3px solid #e0e0e0; padding: 6px 8px; font-size: 9px; vertical-align: top; }
            .data-table tr:nth-child(even) td { background: #f1f5f9; }
            .sig-box { margin-top: 45px; text-align: center; }
            .stamp { display: inline-block; margin-top: 26px; padding: 8px 15px; border: 1.5px dashed #166534; color: #166534; font-weight: bold; font-size: 10px; }
            .cover { page-break-after: always; text-align: center; padding: 0; }
            .cover-topline { height: 2px; background: #000000; width: 100%; }
            .cover-body { padding: 0 45px; }
            .cover-label { color: #777777; font-size: 9px; margin-top: 100px; }
            .cover-law { color: #777777; font-size: 8px; margin-top: 6px; }
            .cover-sep { border-top: 0.5px solid #000000; margin: 24px 60px 0 60px; }
            .cover-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 26px; text-transform: uppercase; }
            .cover-title { color: #1a1a1a; font-size: 22px; font-weight: bold; margin-top: 14px; }
            .cover-sub { color: #555555; font-size: 10px; margin-top: 22px; }
            .cover-sep2 { border-top: 0.5px solid #000000; margin: 22px 60px 0 60px; }
            .cover-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 26px 60px 0 60px; padding: 8px 10px; }
            .cover-box .lbl { color: #555555; font-size: 8px; }
            .cover-box .val { color: #1a1a1a; font-size: 10px; font-weight: bold; margin-top: 3px; }
            .page { page-break-before: always; }
        </style></head><body>
        <div class="footer-fixed">Ley 21.719 - Certificación de Cumplimiento · ' . $this->h($company) . '</div>
        <div class="topline"></div>
        <div class="head-wrap">
            <div class="head-label">REPÚBLICA DE CHILE</div>
            <div class="head-law">Ley 21.719 - Protección de Datos Personales</div>
            <div class="head-sep"></div>
            <div class="head-company">' . $this->h($company) . '</div>
            <div class="head-title">' . $this->h($title) . '</div>
            ' . ($subtitle ? '<div class="head-box">' . $this->h($subtitle) . '</div>' : '') . '
        </div>
        <div class="content">';
    }

    private function footer() {
        return '</div></body></html>';
    }

    public function generateDocument($def, $sourceData, $company) {
        $code = $def['code'];
        $title = $def['name'];
        $subtitle = 'Documento ' . $code . ' - Certificación Ley 21.719';

        $html = $this->header($title, $subtitle, $company['companyName']);

        switch ($code) {
            case 'DOC-01': $html .= $this->renderDPD($sourceData); break;
            case 'DOC-02': $html .= $this->renderAPDP($sourceData); break;
            case 'DOC-03': $html .= $this->renderPolicy($sourceData, 'Política de Privacidad'); break;
            case 'DOC-04': $html .= $this->renderPolicy($sourceData, 'Política de Cookies'); break;
            case 'DOC-05': $html .= $this->renderRetention($sourceData); break;
            case 'DOC-06':
            case 'DOC-07':
            case 'DOC-09':
            case 'DOC-10': $html .= $this->renderInventory($sourceData, $code); break;
            case 'DOC-08': $html .= $this->renderConsents($sourceData); break;
            case 'DOC-11': $html .= $this->renderDpia($sourceData); break;
            case 'DOC-13': $html .= $this->renderPseudo($sourceData); break;
            case 'DOC-14': $html .= $this->renderProcessors($sourceData); break;
            case 'DOC-15': $html .= $this->renderTransfers($sourceData); break;
            case 'DOC-17': $html .= $this->renderBreachProtocol($sourceData); break;
            case 'DOC-18': $html .= $this->renderIncidentResponse($sourceData); break;
            case 'DOC-19': $html .= $this->renderBreaches($sourceData); break;
            case 'DOC-20': $html .= $this->renderArcoChannel($sourceData, $company); break;
            case 'DOC-22': $html .= $this->renderArcoHistory($sourceData); break;
            case 'DOC-23': $html .= $this->renderTrainings($sourceData); break;
            case 'DOC-24': $html .= $this->renderDeclaration($company); break;
            default: $html .= '<p>Documento sin generación automática.</p>';
        }

        $html .= '<div class="sig-box"><p>Atentamente,</p><p><strong>' . $this->h($company['dpdName']) . '</strong><br>Delegado de Protección de Datos<br>' . $this->h($company['dpdEmail']) . '</p></div>';

        return $html . $this->footer();
    }

    private function renderDPD($cfg) {
        $h = fn($v) => $this->h($v);
        $html = '<div class="meta">
            <div><span class="label">Nombre del DPD:</span> ' . $h($cfg['dpdName'] ?? '—') . '</div>
            <div><span class="label">Email:</span> ' . $h($cfg['dpdEmail'] ?? '—') . '</div>
            <div><span class="label">Teléfono:</span> ' . $h($cfg['dpdPhone'] ?? '—') . '</div>
            <div><span class="label">RUT:</span> ' . $h($cfg['dpdRut'] ?? '—') . '</div>
        </div>';
        $html .= '<div class="subject">Fundamento legal</div>';
        $html .= '<p>De conformidad con el artículo 28 de la Ley 21.719, el responsable del tratamiento designa formalmente al Delegado de Protección de Datos (DPD) cuyos datos se detallan. El DPD actúa como punto de contacto con la Agencia de Protección de Datos Personales (APDP) y con los titulares.</p>';
        return $html;
    }

    private function renderAPDP($cfg) {
        $h = fn($v) => $this->h($v);
        $html = '<div class="meta">
            <div><span class="label">Estado:</span> ' . (!empty($cfg['apdpRegistered']) ? 'Registrado' : 'No registrado') . '</div>
            <div><span class="label">Número de registro:</span> ' . $h($cfg['apdpRegistrationNumber'] ?? '—') . '</div>
            <div><span class="label">Fecha de registro:</span> ' . $h($cfg['apdpRegistrationDate'] ?? '—') . '</div>
        </div>';
        $html .= '<div class="subject">Fundamento legal</div>';
        $html .= '<p>De conformidad con el artículo 31 de la Ley 21.719, el responsable del tratamiento se encuentra inscrito en el Registro Nacional de la Agencia de Protección de Datos Personales (APDP).</p>';
        return $html;
    }

    private function renderPolicy($cfg, $title) {
        $h = fn($v) => $this->h($v);
        $urlKey = str_contains($title, 'Cookies') ? 'cookiesPolicyUrl' : 'privacyPolicyUrl';
        $contentKey = str_contains($title, 'Cookies') ? 'cookiesPolicyContent' : 'privacyPolicyContent';
        $url = $cfg[$urlKey] ?? '';
        $content = $cfg[$contentKey] ?? '';

        $html = '<div class="meta">';
        $html .= '<div><span class="label">URL publicada:</span> ' . ($url ? $h($url) : 'No configurada') . '</div>';
        $html .= '</div>';
        if ($content) {
            $html .= '<div class="subject">Contenido</div>';
            foreach (preg_split('/\r?\n/', $content) as $line) {
                if (trim($line) !== '') $html .= '<p>' . $h($line) . '</p>';
            }
        }
        $html .= '<div class="subject">Fundamento legal</div>';
        $html .= '<p>De conformidad con el artículo 14 ter de la Ley 21.719, el responsable mantiene publicada y accesible la presente política para los titulares.</p>';
        return $html;
    }

    private function renderRetention($cfg) {
        $h = fn($v) => $this->h($v);
        $content = $cfg['dataRetentionPolicy'] ?? '';
        $html = '<div class="subject">Política de Retención de Datos</div>';
        if ($content) {
            foreach (preg_split('/\r?\n/', $content) as $line) {
                if (trim($line) !== '') $html .= '<p>' . $h($line) . '</p>';
            }
        } else {
            $html .= '<p>Sin contenido definido.</p>';
        }
        return $html;
    }

    private function renderInventory($items, $code) {
        $h = fn($v) => $this->h($v);
        if (!is_array($items) || empty($items)) return '<p>Sin actividades de tratamiento registradas.</p>';

        $filter = $items;
        if ($code === 'DOC-07') $filter = array_filter($items, fn($i) => !empty($i['legalBasis']));
        if ($code === 'DOC-09') $filter = array_filter($items, fn($i) => !empty($i['sensitive']) || !empty($i['childrenData']));
        if ($code === 'DOC-10') $filter = array_filter($items, fn($i) => !empty($i['risk']));

        $html = '<table class="data-table"><thead><tr><th>Nombre</th><th>Finalidad</th><th>Base legal</th><th>Riesgo</th></tr></thead><tbody>';
        foreach ($filter as $i) {
            $html .= '<tr><td>' . $h($i['name'] ?? '—') . '</td>'
                  . '<td>' . $h($i['purpose'] ?? '—') . '</td>'
                  . '<td>' . $h($i['legalBasis'] ?? '—') . '</td>'
                  . '<td>' . $h($i['risk'] ?? 'bajo') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function renderConsents($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin consentimientos registrados.</p>';
        $html = '<table class="data-table"><thead><tr><th>Titular</th><th>Email</th><th>Finalidad</th><th>Estado</th></tr></thead><tbody>';
        foreach ($items as $c) {
            $active = empty($c['revokedAt']);
            $html .= '<tr><td>' . $h($c['name'] ?? '—') . '</td>'
                  . '<td>' . $h($c['email'] ?? '—') . '</td>'
                  . '<td>' . $h($c['purpose'] ?? '—') . '</td>'
                  . '<td>' . ($active ? 'Activo' : 'Revocado') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderDpia($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin evaluaciones de impacto registradas.</p>';
        $html = '<table class="data-table"><thead><tr><th>Nombre</th><th>Riesgo</th><th>Estado</th><th>Aprobada por</th></tr></thead><tbody>';
        foreach ($items as $d) {
            $html .= '<tr><td>' . $h($d['name'] ?? '—') . '</td>'
                  . '<td>' . $h($d['riskLevel'] ?? '—') . '</td>'
                  . '<td>' . $h($d['status'] ?? 'pendiente') . '</td>'
                  . '<td>' . $h($d['approvedByName'] ?? '—') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderPseudo($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin reglas de seudonimización registradas.</p>';
        $html = '<table class="data-table"><thead><tr><th>Nombre</th><th>Técnica</th><th>Alcance</th><th>Estado</th></tr></thead><tbody>';
        foreach ($items as $r) {
            $html .= '<tr><td>' . $h($r['name'] ?? '—') . '</td>'
                  . '<td>' . $h($r['technique'] ?? '—') . '</td>'
                  . '<td>' . $h($r['scope'] ?? '—') . '</td>'
                  . '<td>' . (!empty($r['executed']) ? 'Ejecutada' : 'Pendiente') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderProcessors($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin encargados del tratamiento registrados.</p>';
        $html = '<table class="data-table"><thead><tr><th>Nombre</th><th>Servicio</th><th>País</th><th>Contrato DPA</th></tr></thead><tbody>';
        foreach ($items as $p) {
            $html .= '<tr><td>' . $h($p['name'] ?? '—') . '</td>'
                  . '<td>' . $h($p['serviceType'] ?? '—') . '</td>'
                  . '<td>' . $h($p['country'] ?? '—') . '</td>'
                  . '<td>' . ($p['hasContract'] === 'si' ? 'Sí' : 'No') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderTransfers($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin transferencias internacionales registradas.</p>';
        $html = '<table class="data-table"><thead><tr><th>País</th><th>Destinatario</th><th>Mecanismo</th></tr></thead><tbody>';
        foreach ($items as $t) {
            $html .= '<tr><td>' . $h($t['destinationCountry'] ?? '—') . '</td>'
                  . '<td>' . $h($t['recipient'] ?? '—') . '</td>'
                  . '<td>' . $h($t['mechanism'] ?? '—') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderBreachProtocol($item) {
        if (empty($item)) return '<p>Sin protocolo documentado.</p>';
        $h = fn($v) => $this->h($v);
        $html = '<div class="meta">';
        $html .= '<div><span class="label">Nombre:</span> ' . $h($item['protocolName'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Versión:</span> ' . $h($item['protocolVersion'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Aprobación:</span> ' . $h($item['approvalDate'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Responsable:</span> ' . $h($item['protocolOwner'] ?? '—') . '</div>';
        $html .= '</div>';
        if (!empty($item['scope'])) $html .= '<div class="subject">Alcance</div><p>' . $h($item['scope']) . '</p>';
        return $html;
    }

    private function renderIncidentResponse($item) {
        if (empty($item)) return '<p>Sin plan de respuesta documentado.</p>';
        $h = fn($v) => $this->h($v);
        $html = '<div class="meta">';
        $html .= '<div><span class="label">Plan:</span> ' . $h($item['planName'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Versión:</span> ' . $h($item['planVersion'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Responsable:</span> ' . $h($item['planOwner'] ?? '—') . '</div>';
        $html .= '</div>';
        if (!empty($item['scope'])) $html .= '<div class="subject">Alcance</div><p>' . $h($item['scope']) . '</p>';
        return $html;
    }

    private function renderBreaches($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin brechas registradas. Registro limpio.</p>';
        $html = '<table class="data-table"><thead><tr><th>Fecha</th><th>Título</th><th>Severidad</th><th>Estado</th></tr></thead><tbody>';
        foreach ($items as $b) {
            $html .= '<tr><td>' . $h(substr($b['createdAt'] ?? '', 0, 10)) . '</td>'
                  . '<td>' . $h($b['title'] ?? '—') . '</td>'
                  . '<td>' . $h($b['severity'] ?? '—') . '</td>'
                  . '<td>' . $h($b['status'] ?? '—') . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderArcoChannel($cfg, $company) {
        $h = fn($v) => $this->h($v);
        $html = '<div class="meta">';
        $html .= '<div><span class="label">Empresa:</span> ' . $h($company['companyName']) . '</div>';
        $html .= '<div><span class="label">DPD:</span> ' . $h($company['dpdName']) . '</div>';
        $html .= '<div><span class="label">Email DPD:</span> ' . $h($company['dpdEmail']) . '</div>';
        $html .= '<div><span class="label">Canal público:</span> /arco-solicitud</div>';
        $html .= '</div>';
        $html .= '<div class="subject">Derechos disponibles</div>';
        $html .= '<p>Acceso, Rectificación, Cancelación, Oposición, Portabilidad, Supresión, Bloqueo.</p>';
        $html .= '<div class="subject">Plazo legal</div>';
        $html .= '<p>10 días hábiles desde la recepción de la solicitud (Art. 11 Ley 21.719).</p>';
        return $html;
    }

    private function renderArcoHistory($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin solicitudes ARCO registradas.</p>';
        $html = '<table class="data-table"><thead><tr><th>ID</th><th>Titular</th><th>Tipo</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        foreach ($items as $r) {
            $sol = is_array($r['solicitante'] ?? null) ? $r['solicitante'] : [];
            $html .= '<tr><td>' . $h($r['requestId'] ?? '—') . '</td>'
                  . '<td>' . $h($sol['nombre'] ?? '—') . '</td>'
                  . '<td>' . $h($r['tipo'] ?? '—') . '</td>'
                  . '<td>' . $h($r['status'] ?? '—') . '</td>'
                  . '<td>' . $h(substr($r['createdAt'] ?? '', 0, 10)) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderTrainings($items) {
        $h = fn($v) => $this->h($v);
        if (empty($items)) return '<p>Sin capacitaciones registradas.</p>';
        $html = '<table class="data-table"><thead><tr><th>Título</th><th>Asistente</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
        foreach ($items as $t) {
            $html .= '<tr><td>' . $h($t['title'] ?? '—') . '</td>'
                  . '<td>' . $h($t['attendee'] ?? '—') . '</td>'
                  . '<td>' . (!empty($t['completed']) ? 'Completada' : 'Pendiente') . '</td>'
                  . '<td>' . $h($t['date'] ?? substr($t['createdAt'] ?? '', 0, 10)) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function renderDeclaration($company) {
        $h = fn($v) => $this->h($v);
        $html = '<p>La empresa <strong>' . $h($company['companyName']) . '</strong>, representada por su Delegado de Protección de Datos <strong>' . $h($company['dpdName']) . '</strong>, declara bajo su responsabilidad que:</p>';
        $html .= '<ul>';
        $html .= '<li>Ha implementado las medidas técnicas y organizativas exigidas por la Ley 21.719.</li>';
        $html .= '<li>Mantiene el Registro de Actividades de Tratamiento (RAT) actualizado.</li>';
        $html .= '<li>Ha designado formalmente un DPD conforme al Art. 28.</li>';
        $html .= '<li>Ha publicado su Política de Privacidad y canal de derechos ARCO.</li>';
        $html .= '<li>Cuenta con protocolos de brechas y respuesta a incidentes.</li>';
        $html .= '<li>Realiza capacitaciones periódicas al personal.</li>';
        $html .= '</ul>';
        $html .= '<p>La presente declaración se emite como parte del expediente de certificación de cumplimiento normativo.</p>';
        return $html;
    }

    public function generateMasterCertificate($cert) {
        $h = fn($v) => $this->h($v);
        $company = $cert['companySnapshot'] ?? [];
        $issuedAt = !empty($cert['issuedAt']) ? date('d/m/Y H:i', strtotime($cert['issuedAt'])) : '—';
        $expiresAt = !empty($cert['expiresAt']) ? date('d/m/Y', strtotime($cert['expiresAt'])) : '—';

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Certificado ' . $h($cert['certId']) . '</title>
        <style>
            @page { margin: 0; }
            body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10px; line-height: 1.6; color: #1a1a1a; margin: 0; }
            .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
            .cover { page-break-after: always; padding: 0; }
            .cover-topline { height: 2px; background: #000000; width: 100%; }
            .cover-body { padding: 0 45px; text-align: center; }
            .cover-label { color: #777777; font-size: 9px; margin-top: 110px; }
            .cover-law { color: #777777; font-size: 8px; margin-top: 6px; }
            .cover-sep { border-top: 0.5px solid #000000; margin: 26px 60px 0 60px; }
            .cover-company { color: #1a1a1a; font-size: 16px; font-weight: bold; margin-top: 30px; text-transform: uppercase; }
            .cover-title { color: #1a1a1a; font-size: 26px; font-weight: bold; margin-top: 14px; }
            .cover-sub { color: #555555; font-size: 11px; margin-top: 24px; }
            .cover-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 30px 80px 0 80px; padding: 12px; }
            .cover-box .lbl { color: #555555; font-size: 8px; }
            .cover-box .val { color: #1a1a1a; font-size: 12px; font-weight: bold; margin-top: 3px; }
            .cover-stamp { display: inline-block; margin-top: 40px; padding: 12px 24px; border: 2px solid #166534; color: #166534; font-weight: bold; font-size: 14px; letter-spacing: 1px; }
            .page { page-break-before: always; }
            .content { padding: 20px 60px 50px 60px; }
            .meta { margin-bottom: 18px; background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px 14px; }
            .meta div { margin-bottom: 4px; font-size: 9px; }
            .label { font-weight: bold; color: #555555; font-size: 8px; text-transform: uppercase; }
            .subject { font-size: 13px; font-weight: bold; margin: 22px 0 12px; border-left: 4px solid #000000; padding: 2px 0 2px 10px; }
            .data-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
            .data-table th { background: #1a1a1a; color: #cccccc; font-size: 8px; font-weight: bold; text-align: left; padding: 6px 8px; }
            .data-table td { border-bottom: 0.3px solid #e0e0e0; padding: 6px 8px; font-size: 9px; }
            .sig-box { margin-top: 50px; text-align: center; }
            .hash-box { margin-top: 30px; padding: 10px; background: #f8f8f8; border: 0.5px dashed #999; font-family: "DejaVu Sans Mono", monospace; font-size: 8px; color: #555; }

        </style></head><body>
        <div class="footer-fixed">Certificado de Cumplimiento Ley 21.719 · ' . $h($company['name'] ?? '') . '</div>';

        $html .= '<div class="cover"><div class="cover-topline"></div><div class="cover-body">';
        $html .= '<div class="cover-label">REPÚBLICA DE CHILE</div>';
        $html .= '<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>';
        $html .= '<div class="cover-sep"></div>';
        $html .= '<div class="cover-company">' . $h($company['name'] ?? '—') . '</div>';
        $html .= '<div class="cover-title">CERTIFICADO DE CUMPLIMIENTO</div>';
        $html .= '<div class="cover-sub">Documento oficial de certificación normativa</div>';
        $html .= '<div class="cover-box"><div class="lbl">NÚMERO DE CERTIFICADO</div><div class="val">' . $h($cert['certId']) . '</div></div>';
        $html .= '<div class="cover-box"><div class="lbl">SCORE DE CUMPLIMIENTO</div><div class="val">' . (int)($cert['score'] ?? 0) . '%</div></div>';
        $html .= '<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . $h($issuedAt) . '</div></div>';
        $html .= '<div class="cover-box"><div class="lbl">VIGENCIA HASTA</div><div class="val">' . $h($expiresAt) . '</div></div>';
        $html .= '<div class="cover-stamp">CERTIFICADO VÁLIDO</div>';
        $html .= '</div></div>';

        $html .= '<div class="page"></div><div class="content">';
        $html .= '<div class="subject">Información del Responsable</div>';
        $html .= '<div class="meta">';
        $html .= '<div><span class="label">Empresa:</span> ' . $h($company['name'] ?? '—') . '</div>';
        $html .= '<div><span class="label">RUT:</span> ' . $h($company['rut'] ?? '—') . '</div>';
        $html .= '<div><span class="label">DPD:</span> ' . $h($company['dpdName'] ?? '—') . '</div>';
        $html .= '<div><span class="label">Email DPD:</span> ' . $h($company['dpdEmail'] ?? '—') . '</div>';
        $html .= '</div>';

        $html .= '<div class="subject">Documentos Certificados</div>';
        $html .= '<table class="data-table"><thead><tr><th>Código</th><th>Documento</th><th>Estado</th><th>Versión</th></tr></thead><tbody>';
        foreach (($cert['documents'] ?? []) as $d) {
            $html .= '<tr><td>' . $h($d['code'] ?? '—') . '</td>'
                  . '<td>' . $h($d['name'] ?? '—') . '</td>'
                  . '<td>' . $h($d['status'] ?? '—') . '</td>'
                  . '<td>' . (int)($d['version'] ?? 0) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="subject">Verificación de Integridad</div>';
        $html .= '<div class="hash-box">';
        $html .= '<strong>Hash SHA-256 maestro:</strong> ' . $h($cert['masterHash'] ?? '—') . '<br>';
        $html .= '<strong>URL de verificación pública:</strong> ' . $h($cert['verifyUrl'] ?? '—') . '<br>';
        $html .= 'Cualquier alteración de este documento invalida su validez.';
        $html .= '</div>';

        $html .= '<div class="sig-box"><p>Atentamente,</p>';
        $html .= '<p><strong>' . $h($company['dpdName'] ?? 'DPD') . '</strong><br>Delegado de Protección de Datos<br>' . $h($company['dpdEmail'] ?? '') . '</p></div>';
        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Renderiza un PDF, lo guarda en backend/reports/ (raíz) y devuelve URL + hash.
     * Se guarda en la raíz para que /api/reports/download/{filename} pueda servirlo.
     */
    public function renderPDF($html, $filename) {
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();
        $hash = hash('sha256', $output);

        $reportsDir = __DIR__ . '/reports';
        if (!is_dir($reportsDir)) mkdir($reportsDir, 0755, true);

        $filePath = $reportsDir . '/' . $filename;
        file_put_contents($filePath, $output);
        @chmod($filePath, 0644);

        return [
            'url'  => '/api/reports/download/' . $filename,
            'hash' => $hash,
        ];
    }
}