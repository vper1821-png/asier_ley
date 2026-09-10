<?php
// Shared PDF Generation Library for Compliance
class PDFGenerator {
    private $db;
    private $user;
    private $config;

    public function __construct($db, $user) {
        $this->db = $db;
        $this->user = $user;
        $filter = $this->getCompanyFilter();
        $this->config = $db->findOne('compliance_config', $filter) ?? [];
    }

    // ─── Helpers de empresa ─────────────────────────────────────
    private function getCompanyUserIds() {
        $isSuper = !empty($this->user['isAdmin']) || ($this->user['role'] ?? '') === 'superadmin';
        if ($isSuper) return null;

        $record = $this->db->findOne('users', ['_id' => $this->user['_id']]);
        $companyId = $record['companyId'] ?? ($this->user['_id'] ?? null);

        $ids = [];
        if ($companyId) {
            $ids[] = (string)$companyId;
            $subs = $this->db->find('users', ['companyId' => $companyId]);
            foreach ($subs as $s) {
                if (!empty($s['_id'])) $ids[] = (string)$s['_id'];
            }
        }
        if (!empty($this->user['_id'])) $ids[] = (string)$this->user['_id'];
        return array_values(array_unique(array_filter($ids)));
    }

    private function getCompanyFilter($field = 'userId') {
        $companyIds = $this->getCompanyUserIds();
        if ($companyIds === null) return [];
        return [$field => ['$in' => $companyIds]];
    }

    private function parseSolicitante($sol) {
        if (empty($sol)) return ['nombre' => 'Titular', 'rut' => '—', 'email' => '—', 'telefono' => null];
        if (is_string($sol)) {
            $decoded = json_decode($sol, true);
            if (is_array($decoded)) $sol = $decoded;
        }
        if (is_object($sol)) {
            $sol = json_decode(json_encode($sol), true) ?: [];
        }
        if (!is_array($sol)) return ['nombre' => 'Titular', 'rut' => '—', 'email' => '—', 'telefono' => null];
        return [
            'nombre'   => $sol['nombre']   ?? ($sol['name']  ?? 'Titular'),
            'rut'      => $sol['rut']      ?? ($sol['RUT']   ?? '—'),
            'email'    => $sol['email']    ?? '—',
            'telefono' => $sol['telefono'] ?? null,
        ];
    }

    private function getCompanyInfo() {
        return [
            'name' => htmlspecialchars($this->safeString($this->config['companyName'] ?? ($this->user['companyName'] ?? ($this->user['email'] ?? 'Empresa')))),
            'dpdName' => htmlspecialchars($this->safeString($this->config['dpdName'] ?? 'No asignado')),
            'dpdEmail' => htmlspecialchars($this->safeString($this->config['dpdEmail'] ?? 'No asignado')),
            'dpdPhone' => htmlspecialchars($this->safeString($this->config['dpdPhone'] ?? 'No asignado')),
            'dpdRut' => htmlspecialchars($this->safeString($this->config['dpdRut'] ?? '')),
            'companyRut' => htmlspecialchars($this->safeString($this->config['companyRut'] ?? '')),
            'apdpRegistered' => ($this->config['apdpRegistered'] === '1' || $this->config['apdpRegistered'] === true),
            'apdpRegistrationNumber' => htmlspecialchars($this->safeString($this->config['apdpRegistrationNumber'] ?? '')),
            'complianceLevel' => htmlspecialchars($this->safeString($this->config['complianceLevel'] ?? 'básico')),
        ];
    }

    private function safeString($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }

    private function getHeaderHTML($title, $subtitle = '') {
        $company = $this->getCompanyInfo();
        $generatedAt = date('d/m/Y H:i');

        return '<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>' . $title . ' - ' . $company['name'] . '</title>
<style>
@page { margin: 0; }
body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; margin: 0; padding: 0; color: #1a1a1a; font-size: 9px; line-height: 1.5; }
.footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; height: 22px; background: #f5f5f5; border-top: 0.5px solid #cccccc; color: #999999; font-size: 7px; padding: 6px 45px 0 45px; }
.cover { page-break-after: always; padding: 0; }
.cover-topline { height: 2px; background: #000000; width: 100%; }
.cover-body { padding: 0 45px; text-align: center; }
.cover-label { color: #777777; font-size: 9px; margin-top: 100px; }
.cover-law { color: #777777; font-size: 8px; margin-top: 6px; }
.cover-sep { border-top: 0.5px solid #000000; margin: 24px 60px 0 60px; }
.cover-company { color: #1a1a1a; font-size: 14px; font-weight: bold; margin-top: 26px; text-transform: uppercase; }
.cover-title { color: #1a1a1a; font-size: 18px; font-weight: bold; margin-top: 14px; }
.cover-sub { color: #555555; font-size: 10px; margin-top: 22px; }
.cover-sep2 { border-top: 0.5px solid #000000; margin: 22px 60px 0 60px; }
.cover-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 26px 60px 0 60px; padding: 8px 10px; text-align: center; }
.cover-box .lbl { color: #555555; font-size: 8px; }
.cover-box .val { color: #1a1a1a; font-size: 10px; font-weight: bold; margin-top: 3px; }
.cover-box2 { background: #f5f5f5; border: 0.5px solid #bbbbbb; margin: 14px 60px 0 60px; padding: 8px 10px; text-align: center; color: #1a1a1a; font-size: 8px; font-weight: bold; }
.page { page-break-before: always; }
.page-band { background: #000000; padding: 9px 45px 10px 45px; }
.band-sub { color: #ffffff; font-size: 8px; }
.band-title { color: #ffffff; font-size: 10px; font-weight: bold; margin-top: 2px; }
.content { padding: 20px 45px 50px 45px; }
.section { margin-bottom: 20px; page-break-inside: avoid; }
.section h2 { color: #1a1a1a; font-size: 14px; font-weight: bold; border-left: 4px solid #000000; padding: 2px 0 2px 10px; margin: 14px 0 10px 0; }
.section h3 { color: #1a1a1a; margin: 10px 0 6px 0; font-weight: bold; font-size: 10px; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px; }
.info-item { background: #f5f5f5; padding: 8px 10px; border-left: 4px solid #000000; }
.info-item label { font-weight: bold; color: #555555; display: block; margin-bottom: 3px; font-size: 8px; text-transform: uppercase; }
.info-item span { color: #1a1a1a; font-size: 9px; font-weight: bold; }
.status-box { background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px; text-align: center; margin-bottom: 15px; }
.status-box h3 { color: #1a1a1a; margin: 0 0 6px 0; font-size: 13px; font-weight: bold; }
.legal-notice { background: #f5f5f5; border: 0.5px solid #bbbbbb; padding: 12px; margin-top: 15px; page-break-inside: avoid; }
.legal-notice h3 { color: #1a1a1a; margin: 0 0 8px 0; font-size: 10px; font-weight: bold; }
.legal-notice p { margin: 4px 0; font-size: 8px; }
.legal-notice ul { margin: 6px 0; padding-left: 18px; }
.legal-notice li { margin-bottom: 3px; font-size: 8px; }
.data-table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 8px; }
.data-table th { background: #1a1a1a; color: #cccccc; font-size: 8px; font-weight: bold; text-align: left; padding: 7px 8px; border: none; }
.data-table td { color: #1a1a1a; font-size: 8px; padding: 5px 8px; border: none; border-bottom: 0.3px solid #e0e0e0; vertical-align: top; }
.data-table tr:nth-child(even) td { background: #f1f5f9; }
.checklist { background: #fff; padding: 6px; border: 0.5px solid #bbbbbb; }
.checklist-item { display: flex; align-items: flex-start; margin-bottom: 6px; padding: 6px; border-bottom: 0.3px solid #e0e0e0; font-size: 8px; color: #1a1a1a; }
.checklist-item:last-child { border-bottom: none; margin-bottom: 0; }
.badge { display: inline-block; padding: 2px 8px; border: 0.5px solid #000; font-size: 8px; font-weight: bold; background: #fff; }
.badge-success { color: #166534; }
.badge-warning { color: #4a4a4a; }
.badge-danger { color: #991b1b; }
.badge-info { color: #1e40af; }
@media print { .section { page-break-inside: avoid; } .legal-notice { page-break-inside: avoid; } }
</style></head>
<body>
<div class="footer-fixed">Ley 21.719 - ' . $title . ' · ' . $company['name'] . '</div>
<div class="cover">
<div class="cover-topline"></div>
<div class="cover-body">
<div class="cover-label">REPÚBLICA DE CHILE</div>
<div class="cover-law">Ley 21.719 - Protección de Datos Personales</div>
<div class="cover-sep"></div>
<div class="cover-company">' . $company['name'] . '</div>
<div class="cover-title">' . $title . '</div>
<div class="cover-sub">' . ($subtitle ?: 'Documento de Cumplimiento') . '</div>
<div class="cover-sep2"></div>
<div class="cover-box"><div class="lbl">FECHA DE EMISIÓN</div><div class="val">' . $generatedAt . '</div></div>
<div class="cover-box2">CLASIFICACIÓN: CONFIDENCIAL</div>
</div></div>
<div class="page">
<div class="page-band">
<div class="band-sub">' . $company['name'] . ' · Ley 21.719</div>
<div class="band-title">' . $title . '</div>
</div>
<div class="content">';
    }

    private function getFooterHTML($documentType) {
        $company = $this->getCompanyInfo();
        $generatedAt = date('d/m/Y H:i');
        return '        <div class="legal-notice">
            <h3>Información del documento</h3>
            <p><strong>Empresa:</strong> ' . $company['name'] . ' | <strong>DPD:</strong> ' . $company['dpdName'] . ' (' . $company['dpdEmail'] . ')</p>
            <p>Documento generado automáticamente por SecureLab el ' . $generatedAt . '.</p>
            <p>Válido como evidencia del cumplimiento de la Ley 21.719 - Protección de Datos Personales.</p>
        </div>
        </div>
    </div>
</body>
</html>';
    }

    // ─── Consent PDF ────────────────────────────────────────────
    public function generateConsentPDF($consentId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($consentId) $filter['_id'] = $consentId;
        $consents = $this->db->find('compliance_consents', $filter);

        $html = $this->getHeaderHTML('CERTIFICADO DE CONSENTIMIENTO', 'Ley 21.719 - Art. 12 - Protección de Datos Personales');

        $activeCount = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
        $revokedCount = count($consents) - $activeCount;
        $total = count($consents);
        $rate = $total > 0 ? round(($activeCount / $total) * 100, 1) : 0;
        $companyRut = $company['companyRut'] ?: 'No especificado';
        $apdpStatus = $company['apdpRegistered'] ? 'Registrado (' . $company['apdpRegistrationNumber'] . ')' : 'No registrado';

        $html .= '<div class="section"><div class="status-box"><h3>Estado del Registro: ' . $activeCount . ' Consentimientos Activos</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa Responsable:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>RUT Empresa:</label><span>' . $companyRut . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto DPD:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '<div class="info-item"><label>Nivel de Cumplimiento:</label><span>' . $company['complianceLevel'] . '</span></div>';
        $html .= '<div class="info-item"><label>Registro APDP:</label><span>' . $apdpStatus . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Activos:</label><span>' . $activeCount . '</span></div>';
        $html .= '<div class="info-item"><label>Revocados:</label><span>' . $revokedCount . '</span></div>';
        $html .= '<div class="info-item"><label>Tasa Actividad:</label><span>' . $rate . '%</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>3. Detalle</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Titular</th><th>RUT</th><th>Finalidad</th><th>Base Legal</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>';
            foreach ($consents as $c) {
                $name = htmlspecialchars($this->safeString($c['name'] ?? 'No especificado'));
                $rut = htmlspecialchars($this->safeString($c['rut'] ?? 'No especificado'));
                $purpose = htmlspecialchars($this->safeString($c['purpose'] ?? $c['treatmentPurpose'] ?? 'No especificado'));
                $legalBasis = htmlspecialchars($this->safeString($c['legalBasis'] ?? 'Consentimiento Art. 12'));
                $date = $c['createdAt'] ? $this->formatDate($c['createdAt']) : 'No registrado';
                $isRevoked = !empty($c['revokedAt']);
                $status = $isRevoked ? '<span class="badge badge-danger">Revocado</span>' : '<span class="badge badge-success">Activo</span>';
                $html .= '<tr><td>' . $name . '</td><td>' . $rut . '</td><td>' . $purpose . '</td><td>' . $legalBasis . '</td><td>' . $date . '</td><td>' . $status . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">No hay consentimientos registrados</div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="section"><h2>4. Requisitos de Cumplimiento - Art. 12</h2><div class="checklist">';
        foreach (['Libre - Sin presión','Informado - Claridad','Específico - Finalidades','Previo - Antes del tratamiento','Inequívoco - Acción afirmativa','Registro documentado','Mecanismo de revocación'] as $r) {
            $html .= '<div class="checklist-item">✔ ' . $r . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>5. Marco Legal</h2><div class="legal-notice"><h3>Artículo 12</h3><p>El consentimiento debe ser libre, informado, específico, previo e inequívoco. Puede ser revocado en cualquier momento sin efectos retroactivos.</p><p><strong>Sanciones:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p></div></div>';

        $html .= $this->getFooterHTML('Consentimientos');
        return $html;
    }

    // ─── Inventory PDF ──────────────────────────────────────────
    public function generateInventoryPDF($inventoryId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($inventoryId) $filter['_id'] = $inventoryId;
        $inventory = $this->db->find('compliance_inventory', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE ACTIVIDADES DE TRATAMIENTO (RAT)', 'Ley 21.719 - Art. 15 - Inventario de Datos Personales');

        $total = count($inventory);
        $sensitiveCount = count(array_filter($inventory, fn($i) => !empty($i['sensitive'])));
        $nonSensitive = $total - $sensitiveCount;

        $html .= '<div class="section"><div class="status-box"><h3>Estado del Inventario: ' . $total . ' Registros</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>RUT:</label><span>' . ($company['companyRut'] ?: 'No especificado') . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto DPD:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total BD:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Sensibles:</label><span>' . $sensitiveCount . '</span></div>';
        $html .= '<div class="info-item"><label>No sensibles:</label><span>' . $nonSensitive . '</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>3. Detalle</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Nombre BD</th><th>Finalidad</th><th>Base Legal</th><th>Categorías</th><th>Sensible</th></tr></thead><tbody>';
            foreach ($inventory as $item) {
                $name = htmlspecialchars($this->safeString($item['name'] ?? 'No especificado'));
                $purpose = htmlspecialchars($this->safeString($item['purpose'] ?? $item['treatmentPurpose'] ?? 'No especificado'));
                $legalBasis = htmlspecialchars($this->safeString($item['legalBasis'] ?? 'No especificado'));
                $categories = is_array($item['dataCategories'] ?? null) ? implode(', ', array_map('htmlspecialchars', $item['dataCategories'])) : htmlspecialchars($this->safeString($item['dataCategories'] ?? 'No especificado'));
                $sensitive = !empty($item['sensitive']) ? '<span class="badge badge-danger">Sí</span>' : '<span class="badge badge-success">No</span>';
                $html .= '<tr><td>' . $name . '</td><td>' . $purpose . '</td><td>' . $legalBasis . '</td><td>' . $categories . '</td><td>' . $sensitive . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">Sin registros</div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="section"><h2>4. Requisitos - Art. 15</h2><div class="checklist">';
        foreach (['Registro documentado','Identificación de finalidades','Base legal identificada','Categorías de datos','Responsable designado'] as $r) {
            $html .= '<div class="checklist-item">✔ ' . $r . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>5. Marco Legal</h2><div class="legal-notice"><h3>Artículo 15</h3><p>Los responsables deben mantener un registro actualizado de las bases de datos que contengan datos personales.</p><p><strong>Sanciones:</strong> Hasta 5.000 UTM</p></div></div>';

        $html .= $this->getFooterHTML('Inventario');
        return $html;
    }

    // ─── Breaches PDF ───────────────────────────────────────────
    public function generateBreachesPDF($breachId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($breachId) $filter['_id'] = $breachId;
        $breaches = $this->db->find('compliance_breaches', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE BRECHAS DE SEGURIDAD', 'Ley 21.719 - Art. 26 - Notificación de Incidentes');

        $total = count($breaches);
        $resolved = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved'));
        $critical = count(array_filter($breaches, fn($b) => ($b['severity'] ?? '') === 'critical'));
        $rate = $total > 0 ? round(($resolved / $total) * 100, 1) : 0;

        $html .= '<div class="section"><div class="status-box"><h3>Estado: ' . $resolved . '/' . $total . ' Resueltas</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '<div class="info-item"><label>APDP:</label><span>' . ($company['apdpRegistered'] ? 'Registrado' : 'No registrado') . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Resueltas:</label><span>' . $resolved . '</span></div>';
        $html .= '<div class="info-item"><label>Críticas:</label><span>' . $critical . '</span></div>';
        $html .= '<div class="info-item"><label>Tasa Resolución:</label><span>' . $rate . '%</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>3. Detalle</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Título</th><th>Fecha</th><th>Severidad</th><th>Estado</th><th>APDP</th></tr></thead><tbody>';
            foreach ($breaches as $b) {
                $title = htmlspecialchars($this->safeString($b['title'] ?? 'Sin título'));
                $date = $b['createdAt'] ? $this->formatDate($b['createdAt']) : 'No registrado';
                $sev = htmlspecialchars($this->safeString($b['severity'] ?? 'No especificado'));
                $status = htmlspecialchars($this->safeString($b['status'] ?? 'No especificado'));
                $notified = !empty($b['notifiedAPDP']) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-warning">No</span>';
                $html .= '<tr><td>' . $title . '</td><td>' . $date . '</td><td>' . $sev . '</td><td>' . $status . '</td><td>' . $notified . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">Sin brechas registradas</div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="section"><h2>4. Requisitos - Art. 26</h2><div class="checklist">';
        foreach (['Notificación APDP (72h)','Notificación a titulares (riesgo alto)','Registro de incidentes','Plan de respuesta'] as $r) {
            $html .= '<div class="checklist-item">✔ ' . $r . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>5. Marco Legal</h2><div class="legal-notice"><h3>Artículo 26</h3><p>Las brechas deben notificarse a la APDP sin dilación indebida, a más tardar 72h desde el conocimiento.</p><p><strong>Sanciones:</strong> Hasta 20.000 UTM</p></div></div>';

        $html .= $this->getFooterHTML('Brechas');
        return $html;
    }

    // ─── Trainings PDF ──────────────────────────────────────────
    public function generateTrainingsPDF($trainingId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($trainingId) $filter['_id'] = $trainingId;
        $trainings = $this->db->find('compliance_trainings', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE CAPACITACIONES', 'Ley 21.719 - Formación en Protección de Datos');

        $total = count($trainings);
        $completed = count(array_filter($trainings, fn($t) => !empty($t['completed'])));
        $signed = count(array_filter($trainings, fn($t) => !empty($t['signerName'])));
        $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $html .= '<div class="section"><div class="status-box"><h3>Estado: ' . $completed . '/' . $total . ' Completadas</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Completadas:</label><span>' . $completed . '</span></div>';
        $html .= '<div class="info-item"><label>Tasa:</label><span>' . $rate . '%</span></div>';
        $html .= '<div class="info-item"><label>Con firma:</label><span>' . $signed . '</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>3. Detalle</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Título</th><th>Descripción</th><th>Fecha</th><th>Estado</th><th>Firmante</th></tr></thead><tbody>';
            foreach ($trainings as $t) {
                $title = htmlspecialchars($this->safeString($t['title'] ?? 'Sin título'));
                $desc = htmlspecialchars($this->safeString($t['description'] ?? 'Sin descripción'));
                $date = $t['createdAt'] ? $this->formatDate($t['createdAt']) : 'No registrado';
                $status = !empty($t['completed']) ? '<span class="badge badge-success">Completada</span>' : '<span class="badge badge-warning">Pendiente</span>';
                $signer = htmlspecialchars($this->safeString($t['signerName'] ?? 'No firmado'));
                $html .= '<tr><td>' . $title . '</td><td>' . $desc . '</td><td>' . $date . '</td><td>' . $status . '</td><td>' . $signer . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">Sin capacitaciones</div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="section"><h2>4. Marco Legal</h2><div class="legal-notice"><h3>Capacitación</h3><p>El personal que trata datos debe recibir capacitación adecuada sobre obligaciones legales y medidas de seguridad.</p><p><strong>Sanciones:</strong> Hasta 5.000 UTM</p></div></div>';

        $html .= $this->getFooterHTML('Capacitaciones');
        return $html;
    }

    // ─── Pseudonymization PDF ───────────────────────────────────
    public function generatePseudonymizationPDF($ruleId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($ruleId) $filter['_id'] = $ruleId;
        $rules = $this->db->find('compliance_pseudonymization', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE SEUDONIMIZACIÓN', 'Ley 21.719 - Art. 14 Quáter - Medidas de Seguridad');

        $total = count($rules);
        $executed = count(array_filter($rules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed'])));
        $rate = $total > 0 ? round(($executed / $total) * 100, 1) : 0;

        $html .= '<div class="section"><div class="status-box"><h3>Estado: ' . $executed . '/' . $total . ' Ejecutadas</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total reglas:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Ejecutadas:</label><span>' . $executed . '</span></div>';
        $html .= '<div class="info-item"><label>Tasa:</label><span>' . $rate . '%</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>3. Detalle</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Nombre</th><th>Descripción</th><th>Campos</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>';
            foreach ($rules as $r) {
                $name = htmlspecialchars($this->safeString($r['name'] ?? 'Sin nombre'));
                $desc = htmlspecialchars($this->safeString($r['description'] ?? 'Sin descripción'));
                $fields = is_array($r['fields'] ?? null) ? implode(', ', array_map('htmlspecialchars', $r['fields'])) : htmlspecialchars($this->safeString($r['fields'] ?? 'No especificado'));
                $status = ($r['status'] ?? '') === 'executed' || !empty($r['executed']) ? '<span class="badge badge-success">Ejecutada</span>' : '<span class="badge badge-warning">Pendiente</span>';
                $date = $r['createdAt'] ? $this->formatDate($r['createdAt']) : 'No registrado';
                $html .= '<tr><td>' . $name . '</td><td>' . $desc . '</td><td>' . $fields . '</td><td>' . $status . '</td><td>' . $date . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">Sin reglas registradas</div></div>';
        }
        $html .= '</div>';
        $html .= '<div class="section"><h2>4. Marco Legal</h2><div class="legal-notice"><h3>Artículo 14 Quáter</h3><p>La seudonimización permite tratar datos sin atribuirlos a un titular sin información adicional.</p><p><strong>Sanciones:</strong> Hasta 5.000 UTM</p></div></div>';

        $html .= $this->getFooterHTML('Seudonimización');
        return $html;
    }

    // ─── ARCO Requests PDF (CORREGIDO: muestra nombre/RUT/email, no JSON) ───
    public function generateARCORequestsPDF($requestId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter('companyId');
        if ($requestId) $filter['_id'] = $requestId;
        $requests = $this->db->find('arco_requests', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE SOLICITUDES ARCO', 'Ley 21.719 - Arts. 8-13 - Derechos de los Titulares');

        $total = count($requests);
        $resolvedCount = count(array_filter($requests, fn($r) => in_array($r['status'] ?? '', ['resolved','completed'], true)));
        $rate = $total > 0 ? round(($resolvedCount / $total) * 100, 1) : 0;
        $complianceBadge = $total > 0 ? '<span class="badge badge-success">Canal Activo</span>' : '<span class="badge badge-warning">Pendiente</span>';

        $html .= '<div class="section"><div class="status-box"><h3>Estado: ' . $resolvedCount . '/' . $total . ' Resueltas</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Resueltas:</label><span>' . $resolvedCount . '</span></div>';
        $html .= '<div class="info-item"><label>Tasa Respuesta:</label><span>' . $rate . '%</span></div>';
        $html .= '<div class="info-item"><label>Cumplimiento:</label><span>' . $complianceBadge . '</span></div>';
        $html .= '</div></div>';

        $typeLabels = ['acceso'=>'Acceso','rectificacion'=>'Rectificación','cancelacion'=>'Cancelación','oposicion'=>'Oposición','portabilidad'=>'Portabilidad','supresion'=>'Supresión','bloqueo'=>'Bloqueo'];
        $statusLabels = ['pending'=>'Pendiente','in_progress'=>'En proceso','completed'=>'Completada','resolved'=>'Completada','rejected'=>'Rechazada'];

        $html .= '<div class="section"><h2>3. Detalle de Solicitudes</h2>';
        if ($total > 0) {
            $html .= '<table class="data-table"><thead><tr><th>Solicitante</th><th>Tipo</th><th>Fecha</th><th>Estado</th><th>Respuesta</th></tr></thead><tbody>';
            foreach ($requests as $r) {
                // ✅ CORREGIDO: parsear solicitante (array, JSON string o BSONDocument)
                $sol = $this->parseSolicitante($r['solicitante'] ?? null);
                $nombre = htmlspecialchars($sol['nombre']);
                $rut    = htmlspecialchars($sol['rut']);
                $email  = htmlspecialchars($sol['email']);
                $tipoKey = $r['tipo'] ?? $r['type'] ?? 'acceso';
                $tipo = htmlspecialchars($typeLabels[$tipoKey] ?? ucfirst((string)$tipoKey));
                $date = !empty($r['createdAt']) ? $this->formatDate($r['createdAt']) : 'No registrado';
                $statusKey = $r['status'] ?? 'pending';
                $statusText = htmlspecialchars($statusLabels[$statusKey] ?? ucfirst((string)$statusKey));
                $hasResponse = !empty($r['response']);
                $response = $hasResponse ? '<span class="badge badge-success">Respondida</span>' : '<span class="badge badge-warning">Pendiente</span>';

                $html .= '<tr>';
                $html .= '<td><strong>' . $nombre . '</strong><br><span style="font-size:7px;color:#666">' . $rut . '</span><br><span style="font-size:7px;color:#666">' . $email . '</span></td>';
                $html .= '<td>' . $tipo . '</td>';
                $html .= '<td>' . $date . '</td>';
                $html .= '<td>' . $statusText . '</td>';
                $html .= '<td>' . $response . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item">Sin solicitudes registradas</div></div>';
        }
        $html .= '</div>';

        $html .= '<div class="section"><h2>4. Requisitos - Arts. 8-13</h2><div class="checklist">';
        foreach (['Derecho de Acceso (Art. 8)','Rectificación (Art. 9)','Supresión (Art. 10)','Oposición (Art. 11)','Portabilidad (Art. 13)','Respuesta en 10 días hábiles'] as $r) {
            $html .= '<div class="checklist-item">✔ ' . $r . '</div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="section"><h2>5. Marco Legal</h2><div class="legal-notice"><h3>Arts. 8-13 Ley 21.719</h3><p>Los titulares tienen derecho de Acceso, Rectificación, Cancelación, Oposición y Portabilidad de sus datos. Las solicitudes deben responderse dentro de 10 días hábiles.</p><p><strong>Sanciones:</strong> Hasta 5.000 UTM</p></div></div>';

        $html .= $this->getFooterHTML('Solicitudes ARCO');
        return $html;
    }

    // ═══════════════════════════════════════════════════════════════
    // NUEVO: DPIA PDF
    // ═══════════════════════════════════════════════════════════════
    public function generateDPIAPDF($itemId = null) {
        $company = $this->getCompanyInfo();
        $filter = $this->getCompanyFilter();
        if ($itemId) $filter['_id'] = $itemId;
        $items = $this->db->find('compliance_dpia', $filter);

        $html = $this->getHeaderHTML('EVALUACIÓN DE IMPACTO (DPIA)', 'Ley 21.719 - Art. 14 quinquies - Evaluación de Impacto relativa a la Protección de Datos');

        $total = count($items);
        $approved = count(array_filter($items, fn($i) => ($i['status'] ?? '') === 'approved'));
        $rejected = count(array_filter($items, fn($i) => ($i['status'] ?? '') === 'rejected'));
        $pending = $total - $approved - $rejected;
        $highRisk = count(array_filter($items, fn($i) => in_array($i['riskLevel'] ?? '', ['high','critical']) && ($i['status'] ?? '') !== 'approved'));

        $html .= '<div class="section"><div class="status-box"><h3>Estado: ' . $approved . '/' . $total . ' Aprobadas</h3><p>Última actualización: ' . $this->formatDate(date('c')) . '</p></div></div>';
        $html .= '<div class="section"><h2>1. Información de la Empresa</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Empresa:</label><span>' . $company['name'] . '</span></div>';
        $html .= '<div class="info-item"><label>DPD:</label><span>' . $company['dpdName'] . '</span></div>';
        $html .= '<div class="info-item"><label>Contacto:</label><span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span></div>';
        $html .= '</div></div>';

        $html .= '<div class="section"><h2>2. Resumen</h2><div class="info-grid">';
        $html .= '<div class="info-item"><label>Total evaluaciones:</label><span>' . $total . '</span></div>';
        $html .= '<div class="info-item"><label>Aprobadas:</label><span>' . $approved . '</span></div>';
        $html .= '<div class="info-item"><label>Rechazadas:</label><span>' . $rejected . '</span></div>';
        $html .= '<div class="info-item"><label>Pendientes:</label><span>' . $pending . '</span></div>';
        $html .= '</div></div>';

        $statusMap = ['approved' => ['Aprobada', 'badge-success'], 'rejected' => ['Rechazada', 'badge-danger'], 'pending' => ['Pendiente', 'badge-warning']];
        $riskMap = ['low' => ['Bajo', 'badge-success'], 'medium' => ['Medio', 'badge-warning'], 'high' => ['Alto', 'badge-danger'], 'critical' => ['Crítico', 'badge-danger']];

        if ($total > 0) {
            foreach ($items as $i => $doc) {
                if ($i > 0) $html .= '<div style="page-break-before:always"></div>';
                $status = strtolower($doc['status'] ?? 'pending');
                $st = $statusMap[$status] ?? $statusMap['pending'];
                $rk = strtolower($doc['riskLevel'] ?? 'low');
                $rkl = $riskMap[$rk] ?? $riskMap['low'];

                $html .= '<div class="section"><h2>Evaluación #' . ($i + 1) . ': ' . htmlspecialchars($this->safeString($doc['name'] ?? 'Sin nombre')) . '</h2>';
                $html .= '<div class="info-grid">';
                $html .= '<div class="info-item"><label>ID:</label><span>' . htmlspecialchars((string)($doc['_id'] ?? '—')) . '</span></div>';
                $html .= '<div class="info-item"><label>Nombre:</label><span>' . htmlspecialchars($this->safeString($doc['name'] ?? '—')) . '</span></div>';
                $html .= '<div class="info-item"><label>Nivel de riesgo:</label><span><span class="badge ' . $rkl[1] . '">' . $rkl[0] . '</span></span></div>';
                $html .= '<div class="info-item"><label>Estado:</label><span><span class="badge ' . $st[1] . '">' . $st[0] . '</span></span></div>';
                $html .= '<div class="info-item"><label>Creado:</label><span>' . (!empty($doc['createdAt']) ? $this->formatDate($doc['createdAt']) : '—') . '</span></div>';
                $html .= '<div class="info-item"><label>Actualizado:</label><span>' . (!empty($doc['updatedAt']) ? $this->formatDate($doc['updatedAt']) : '—') . '</span></div>';
                if (!empty($doc['approvedAt'])) $html .= '<div class="info-item"><label>Aprobado:</label><span>' . $this->formatDate($doc['approvedAt']) . '</span></div>';
                if (!empty($doc['approvedByName'])) $html .= '<div class="info-item"><label>Aprobado por:</label><span>' . htmlspecialchars($this->safeString($doc['approvedByName'])) . ' (' . htmlspecialchars($this->safeString($doc['approvedByRole'] ?? '')) . ')</span></div>';
                if (!empty($doc['rejectedAt'])) $html .= '<div class="info-item"><label>Rechazado:</label><span>' . $this->formatDate($doc['rejectedAt']) . '</span></div>';
                if (!empty($doc['rejectionReason'])) $html .= '<div class="info-item"><label>Motivo rechazo:</label><span>' . htmlspecialchars($this->safeString($doc['rejectionReason'])) . '</span></div>';
                $html .= '</div>';

                if (!empty($doc['description'])) {
                    $html .= '<h3>Descripción del tratamiento</h3><p>' . nl2br(htmlspecialchars($this->safeString($doc['description']))) . '</p>';
                }
                if (!empty($doc['treatmentDescription'])) {
                    $html .= '<h3>Detalle del tratamiento</h3><p>' . nl2br(htmlspecialchars($this->safeString($doc['treatmentDescription']))) . '</p>';
                }
                if (!empty($doc['measures'])) {
                    $html .= '<h3>Medidas mitigadoras</h3><p>' . nl2br(htmlspecialchars($this->safeString($doc['measures']))) . '</p>';
                }
                $html .= '</div>';
            }
        } else {
            $html .= '<div class="section"><div class="checklist"><div class="checklist-item">Sin evaluaciones registradas</div></div></div>';
        }

        $html .= '<div class="section"><h2>Marco Legal</h2><div class="legal-notice"><h3>Art. 14 quinquies Ley 21.719</h3><p>La evaluación de impacto es obligatoria cuando un tratamiento entrañe un alto riesgo para los derechos y libertades de las personas.</p><p><strong>Sanciones:</strong> Hasta 10.000 UTM (Infracción Grave)</p></div></div>';

        $html .= $this->getFooterHTML('DPIA');
        return $html;
    }

    private function formatDate($date) {
        if (empty($date)) return 'No registrado';
        try { $dt = new DateTime($date); return $dt->format('d/m/Y H:i'); }
        catch (Exception $e) { return 'Fecha inválida'; }
    }

    public function generateGenericChecklistPDF($title, $data) {
        $company = $this->getCompanyInfo();
        $html = $this->getHeaderHTML($title);
        $html .= '<div class="section"><h2>Registro de ' . htmlspecialchars($title) . '</h2>';
        $html .= '<p><strong>Empresa:</strong> ' . $company['name'] . '</p>';
        $html .= '<p><strong>DPD:</strong> ' . $company['dpdName'] . ' (' . $company['dpdEmail'] . ')</p>';
        $html .= '<p><strong>Generado:</strong> ' . date('d/m/Y H:i:s') . '</p>';
        $html .= '<hr style="border:0;border-top:1px solid #ccc;margin:15px 0;">';
        if (empty($data)) {
            $html .= '<p>No hay datos registrados para esta sección.</p>';
        } else {
            $html .= '<table style="width:100%;border-collapse:collapse;">';
            foreach ($data as $key => $value) {
                if (is_array($value)) $value = implode(', ', $value);
                $html .= '<tr><td style="border:1px solid #ccc;padding:8px;width:35%;font-weight:bold;">' . htmlspecialchars($key) . '</td>';
                $html .= '<td style="border:1px solid #ccc;padding:8px;">' . nl2br(htmlspecialchars($this->safeString($value))) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $html .= '</div></body></html>';
        return $html;
    }

    public function generatePDFFile($html, $filename) {
        $pdfUrl = null;
        $pdfBase64 = null;
        try {
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('paperSize', 'A4');
            $options->set('orientation', 'portrait');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfContent = $dompdf->output();
            if (!empty($pdfContent)) {
                $reportsDir = __DIR__ . '/reports';
                if (!is_dir($reportsDir)) mkdir($reportsDir, 0755, true);
                $pdfFilename = $filename . '-' . date('Y-m-d-His') . '.pdf';
                $pdfPath = $reportsDir . '/' . $pdfFilename;
                if (file_put_contents($pdfPath, $pdfContent) !== false) {
                    chmod($pdfPath, 0644);
                    $pdfUrl = '/api/reports/download/' . $pdfFilename;
                }
                $pdfBase64 = base64_encode($pdfContent);
            }
        } catch (\Throwable $e) {
            error_log('PDF generation error: ' . $e->getMessage());
        }
        return [
            'pdfUrl' => $pdfUrl,
            'pdfBase64' => $pdfBase64,
            'html' => $html,
            'message' => $pdfUrl || $pdfBase64 ? 'PDF generado exitosamente' : 'PDF no disponible, se devuelve HTML para impresión'
        ];
    }
}