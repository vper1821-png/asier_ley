<?php
// Shared PDF Generation Library for Compliance
class PDFGenerator {
    private $db;
    private $user;
    private $config;

    public function __construct($db, $user) {
        $this->db = $db;
        $this->user = $user;
        $this->config = $db->findOne('compliance_config', ['userId' => $user['_id']]) ?? [];
    }

    private function getCompanyInfo() {
        return [
            'name' => htmlspecialchars($this->config['companyName'] ?? ($this->user['companyName'] ?? ($this->user['email'] ?? 'Empresa'))),
            'dpdName' => htmlspecialchars($this->config['dpdName'] ?? 'No asignado'),
            'dpdEmail' => htmlspecialchars($this->config['dpdEmail'] ?? 'No asignado'),
            'dpdPhone' => htmlspecialchars($this->config['dpdPhone'] ?? 'No asignado'),
            'dpdRut' => htmlspecialchars($this->config['dpdRut'] ?? ''),
            'companyRut' => htmlspecialchars($this->config['companyRut'] ?? ''),
            'apdpRegistered' => ($this->config['apdpRegistered'] === '1' || $this->config['apdpRegistered'] === true),
            'apdpRegistrationNumber' => htmlspecialchars($this->config['apdpRegistrationNumber'] ?? ''),
            'complianceLevel' => htmlspecialchars($this->config['complianceLevel'] ?? 'básico'),
        ];
    }

    private function getHeaderHTML($title, $subtitle = '') {
        $company = $this->getCompanyInfo();
        $generatedAt = date('d/m/Y H:i:s');

        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . ' - ' . $company['name'] . '</title>
    <style>
        body {
            font-family: \'Times New Roman\', Times, serif;
            font-size: 11px;
            line-height: 1.5;
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
            font-size: 18px;
            margin: 0 0 8px 0;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header p {
            color: #333;
            margin: 4px 0;
            font-size: 10px;
        }
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .section h2 {
            color: #000;
            font-size: 13px;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
            margin-bottom: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .section h3 {
            color: #000;
            margin: 10px 0 6px 0;
            font-weight: bold;
            font-size: 11px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 15px;
        }
        .info-item {
            background: #fff;
            padding: 8px 10px;
            border-left: 3px solid #000;
        }
        .info-item label {
            font-weight: bold;
            color: #000;
            display: block;
            margin-bottom: 3px;
            font-size: 10px;
        }
        .info-item span {
            color: #333;
            font-size: 11px;
        }
        .status-box {
            background: #fff;
            border: 2px solid #000;
            padding: 12px;
            text-align: center;
            margin-bottom: 15px;
        }
        .status-box h3 {
            color: #000;
            margin: 0 0 6px 0;
            font-size: 14px;
            font-weight: bold;
        }
        .legal-notice {
            background: #fff;
            border: 1px solid #000;
            padding: 12px;
            margin-top: 15px;
            page-break-inside: avoid;
        }
        .legal-notice h3 {
            color: #000;
            margin: 0 0 8px 0;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .legal-notice p {
            margin: 4px 0;
            font-size: 10px;
        }
        .legal-notice ul {
            margin: 6px 0;
            padding-left: 18px;
        }
        .legal-notice li {
            margin-bottom: 3px;
            font-size: 10px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 10px;
        }
        .data-table th {
            background: #000;
            color: white;
            padding: 6px 8px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #000;
        }
        .data-table td {
            border: 1px solid #000;
            padding: 6px 8px;
        }
        .data-table tr:nth-child(even) {
            background: #f0f0f0;
        }
        .checklist {
            background: #fff;
            padding: 10px;
            border: 1px solid #000;
        }
        .checklist-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 6px;
            padding: 6px;
            background: #fff;
            border-bottom: 1px solid #ccc;
            font-size: 10px;
        }
        .checklist-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .checklist-item input[type="checkbox"] {
            margin-right: 8px;
            margin-top: 2px;
            accent-color: #000;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #000;
            text-align: center;
            color: #333;
            font-size: 9px;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #000;
            border-radius: 2px;
            font-size: 9px;
            font-weight: bold;
            background: #fff;
        }
        @media print {
            body { padding: 20px; }
            .section { page-break-inside: avoid; }
            .legal-notice { page-break-inside: avoid; }
            .footer { display: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . $title . '</h1>
        <p>' . $subtitle . '</p>
        <p><strong>' . $company['name'] . '</strong></p>
    </div>';

        return $html;
    }

    private function getFooterHTML($documentType) {
        $generatedAt = date('d/m/Y H:i:s');
        $company = $this->getCompanyInfo();

        $html = '<div class="footer">
        <p>Este documento ha sido generado automáticamente por SecureLab</p>
        <p>Fecha de generación: ' . $generatedAt . '</p>
        <p>Este documento es válido como evidencia del cumplimiento de la Ley 21.719 - Protección de Datos Personales</p>
        <p>Empresa: ' . $company['name'] . ' | DPD: ' . $company['dpdName'] . ' (' . $company['dpdEmail'] . ')</p>
    </div>
</body>
</html>';

        return $html;
    }

    // Generate Consent PDF
    public function generateConsentPDF($consentId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['userId' => $this->user['_id']];
        if ($consentId) {
            $filter['_id'] = $consentId;
        }
        $consents = $this->db->find('compliance_consents', $filter);

        $html = $this->getHeaderHTML('CERTIFICADO DE CONSENTIMIENTO', 'Ley 21.719 - Art. 12 - Protección de Datos Personales');

        $activeCount = count(array_filter($consents, fn($c) => empty($c['revokedAt'])));
        $revokedCount = count($consents) - $activeCount;
        $total = count($consents);
        $rate = $total > 0 ? round(($activeCount / $total) * 100, 1) : 0;

        $companyRut = $company['companyRut'] ?: 'No especificado';
        $apdpStatus = $company['apdpRegistered'] ? 'Registrado (' . $company['apdpRegistrationNumber'] . ')' : 'No registrado';
        $registryChecked = $activeCount > 0 ? 'checked' : '';
        $revocationChecked = $revokedCount > 0 ? 'checked' : '';

        $html .= '<div class="section">
        <div class="status-box">
            <h3>Estado del Registro: ' . $activeCount . ' Consentimientos Activos</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>RUT Empresa:</label>
                <span>' . $companyRut . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
            <div class="info-item">
                <label>Nivel de Cumplimiento:</label>
                <span>' . $company['complianceLevel'] . '</span>
            </div>
            <div class="info-item">
                <label>Registro APDP:</label>
                <span>' . $apdpStatus . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen de Consentimientos</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Consentimientos:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Consentimientos Activos:</label>
                <span>' . $activeCount . '</span>
            </div>
            <div class="info-item">
                <label>Consentimientos Revocados:</label>
                <span>' . $revokedCount . '</span>
            </div>
            <div class="info-item">
                <label>Tasa de Actividad:</label>
                <span>' . $rate . '%</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle de Consentimientos</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Titular</th>
                        <th>RUT</th>
                        <th>Finalidad</th>
                        <th>Base Legal</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($consents as $consent) {
                $name = htmlspecialchars($consent['name'] ?? 'No especificado');
                $rut = htmlspecialchars($consent['rut'] ?? 'No especificado');
                $purpose = htmlspecialchars($consent['purpose'] ?? $consent['treatmentPurpose'] ?? 'No especificado');
                $legalBasis = htmlspecialchars($consent['legalBasis'] ?? 'Consentimiento Art. 12');
                $date = $consent['createdAt'] ? $this->formatDate($consent['createdAt']) : 'No registrado';
                $isRevoked = !empty($consent['revokedAt']);
                $status = $isRevoked ? '<span class="badge badge-error">Revocado</span>' : '<span class="badge badge-success">Activo</span>';

                $html .= '<tr>
                    <td>' . $name . '</td>
                    <td>' . $rut . '</td>
                    <td>' . $purpose . '</td>
                    <td>' . $legalBasis . '</td>
                    <td>' . $date . '</td>
                    <td>' . $status . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay consentimientos registrados</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento - Art. 12 Ley 21.719</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Consentimiento Libre</strong> - Sin presión ni coacción</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Consentimiento Informado</strong> - Con información clara sobre el tratamiento</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Consentimiento Específico</strong> - Para finalidades determinadas</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Consentimiento Previo</strong> - Otorgado antes del tratamiento</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Consentimiento Inequívoco</strong> - Mediante acción afirmativa clara</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" ' . $registryChecked . ' disabled>
                <span><strong>Registro de Consentimientos</strong> - Evidencia documentada de obtención</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" ' . $revocationChecked . ' disabled>
                <span><strong>Mecanismo de Revocación</strong> - Capacidad de revocar en cualquier momento</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Artículo 12 - Consentimiento del Titular</h3>
            <p>El consentimiento debe ser libre, informado, específico, previo e inequívoco. Puede ser revocado en cualquier momento sin efectos retroactivos.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Consentimientos');
        return $html;
    }

    // Generate Inventory PDF
    public function generateInventoryPDF($inventoryId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['userId' => $this->user['_id']];
        if ($inventoryId) {
            $filter['_id'] = $inventoryId;
        }
        $inventory = $this->db->find('compliance_inventory', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE ACTIVIDADES DE TRATAMIENTO (RAT)', 'Ley 21.719 - Art. 15 - Inventario de Datos Personales');

        $total = count($inventory);
        $sensitiveCount = count(array_filter($inventory, fn($i) => !empty($i['sensitive'])));
        $nonSensitiveCount = $total - $sensitiveCount;
        $complianceBadge = $total > 0 ? '<span class="badge badge-success">Cumple</span>' : '<span class="badge badge-warning">Pendiente</span>';

        $html .= '<div class="section">
        <div class="status-box">
            <h3>Estado del Inventario: ' . $total . ' Registros</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>RUT Empresa:</label>
                <span>' . ($company['companyRut'] ?: 'No especificado') . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen del Inventario</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Bases de Datos:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Datos Sensibles:</label>
                <span>' . $sensitiveCount . '</span>
            </div>
            <div class="info-item">
                <label>Datos No Sensibles:</label>
                <span>' . $nonSensitiveCount . '</span>
            </div>
            <div class="info-item">
                <label>Cumplimiento Art. 15:</label>
                <span>' . $complianceBadge . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle del Inventario</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Nombre BD</th>
                        <th>Finalidad</th>
                        <th>Base Legal</th>
                        <th>Categorías</th>
                        <th>Sensible</th>
                        <th>Responsable</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($inventory as $item) {
                $name = htmlspecialchars($item['name'] ?? 'No especificado');
                $purpose = htmlspecialchars($item['purpose'] ?? $item['treatmentPurpose'] ?? 'No especificado');
                $legalBasis = htmlspecialchars($item['legalBasis'] ?? 'No especificado');
                $categories = is_array($item['dataCategories']) ? implode(', ', array_map('htmlspecialchars', $item['dataCategories'])) : htmlspecialchars($item['dataCategories'] ?? 'No especificado');
                $sensitive = !empty($item['sensitive']) ? '<span class="badge badge-error">Sí</span>' : '<span class="badge badge-success">No</span>';
                $responsible = htmlspecialchars($item['responsible'] ?? $item['owner'] ?? 'No especificado');

                $html .= '<tr>
                    <td>' . $name . '</td>
                    <td>' . $purpose . '</td>
                    <td>' . $legalBasis . '</td>
                    <td>' . $categories . '</td>
                    <td>' . $sensitive . '</td>
                    <td>' . $responsible . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay registros de inventario</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento - Art. 15 Ley 21.719</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Registro Documentado</strong> - Inventario actualizado de bases de datos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Identificación de Finalidades</strong> - Propósito del tratamiento claramente definido</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Base Legal Identificada</strong> - Fundamento jurídico del tratamiento</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Categorías de Datos</strong> - Tipos de datos personales tratados</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Responsable Designado</strong> - Persona a cargo del tratamiento</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Artículo 15 - Registro de Actividades de Tratamiento</h3>
            <p>Los responsables deben mantener un registro actualizado de las bases de datos que contengan datos personales, incluyendo finalidades, base legal, categorías de datos y medidas de seguridad.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Inventario');
        return $html;
    }

    // Generate Breaches PDF
    public function generateBreachesPDF($breachId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['userId' => $this->user['_id']];
        if ($breachId) {
            $filter['_id'] = $breachId;
        }
        $breaches = $this->db->find('compliance_breaches', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE BRECHAS DE SEGURIDAD', 'Ley 21.719 - Art. 26 - Notificación de Incidentes');

        $total = count($breaches);
        $resolvedCount = count(array_filter($breaches, fn($b) => ($b['status'] ?? '') === 'resolved'));
        $criticalCount = count(array_filter($breaches, fn($b) => ($b['severity'] ?? '') === 'critical'));
        $rate = $total > 0 ? round(($resolvedCount / $total) * 100, 1) : 0;
        $statusClass = $criticalCount > 0 ? 'warning' : '';

        $html .= '<div class="section">
        <div class="status-box ' . $statusClass . '">
            <h3>Estado del Registro: ' . $resolvedCount . '/' . $total . ' Resueltas</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
            <div class="info-item">
                <label>Registro APDP:</label>
                <span>' . ($company['apdpRegistered'] ? 'Registrado' : 'No registrado') . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen de Brechas</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Brechas:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Brechas Resueltas:</label>
                <span>' . $resolvedCount . '</span>
            </div>
            <div class="info-item">
                <label>Brechas Críticas:</label>
                <span>' . $criticalCount . '</span>
            </div>
            <div class="info-item">
                <label>Tasa de Resolución:</label>
                <span>' . $rate . '%</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle de Brechas</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Fecha</th>
                        <th>Severidad</th>
                        <th>Estado</th>
                        <th>Notificada APDP</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($breaches as $breach) {
                $title = htmlspecialchars($breach['title'] ?? 'Sin título');
                $date = $breach['createdAt'] ? $this->formatDate($breach['createdAt']) : 'No registrado';
                $severity = htmlspecialchars($breach['severity'] ?? 'No especificado');
                $status = htmlspecialchars($breach['status'] ?? 'No especificado');
                $notifiedAPDP = !empty($breach['notifiedAPDP']) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-warning">No</span>';

                $html .= '<tr>
                    <td>' . $title . '</td>
                    <td>' . $date . '</td>
                    <td>' . $severity . '</td>
                    <td>' . $status . '</td>
                    <td>' . $notifiedAPDP . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay brechas registradas</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento - Art. 26 Ley 21.719</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Notificación a APDP</strong> - Dentro de 72 horas desde el conocimiento</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Notificación a Titulares</strong> - Si hay riesgo alto para sus derechos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Registro de Incidentes</strong> - Documentación de brechas y respuestas</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Plan de Respuesta</strong> - Procedimientos establecidos para gestión</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Artículo 26 - Notificación de Brechas de Seguridad</h3>
            <p>Las brechas de seguridad deben ser notificadas a la APDP sin dilación indebida y, a más tardar, dentro de las 72 horas desde que el responsable tuvo conocimiento.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 20.000 UTM (Infracción Gravísima)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Brechas');
        return $html;
    }

    // Generate Trainings PDF
    public function generateTrainingsPDF($trainingId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['userId' => $this->user['_id']];
        if ($trainingId) {
            $filter['_id'] = $trainingId;
        }
        $trainings = $this->db->find('compliance_trainings', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE CAPACITACIONES', 'Ley 21.719 - Formación en Protección de Datos');

        $total = count($trainings);
        $completedCount = count(array_filter($trainings, fn($t) => !empty($t['completed'])));
        $signedCount = count(array_filter($trainings, fn($t) => !empty($t['signerName'])));
        $rate = $total > 0 ? round(($completedCount / $total) * 100, 1) : 0;

        $html .= '<div class="section">
        <div class="status-box">
            <h3>Estado del Registro: ' . $completedCount . '/' . $total . ' Completadas</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen de Capacitaciones</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Capacitaciones:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Capacitaciones Completadas:</label>
                <span>' . $completedCount . '</span>
            </div>
            <div class="info-item">
                <label>Tasa de Finalización:</label>
                <span>' . $rate . '%</span>
            </div>
            <div class="info-item">
                <label>Personal Capacitado:</label>
                <span>' . $signedCount . ' con firma</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle de Capacitaciones</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Descripción</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Firmante</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($trainings as $training) {
                $title = htmlspecialchars($training['title'] ?? 'Sin título');
                $description = htmlspecialchars($training['description'] ?? 'Sin descripción');
                $date = $training['createdAt'] ? $this->formatDate($training['createdAt']) : 'No registrado';
                $status = !empty($training['completed']) ? '<span class="badge badge-success">Completada</span>' : '<span class="badge badge-warning">Pendiente</span>';
                $signer = htmlspecialchars($training['signerName'] ?? 'No firmado');

                $html .= '<tr>
                    <td>' . $title . '</td>
                    <td>' . $description . '</td>
                    <td>' . $date . '</td>
                    <td>' . $status . '</td>
                    <td>' . $signer . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay capacitaciones registradas</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Capacitación del Personal</strong> - Formación en protección de datos personales</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Registro de Asistencia</strong> - Evidencia de participación</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Evaluación de Conocimiento</strong> - Verificación de comprensión</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Actualización Periódica</strong> - Refuerzo continuo de conocimientos</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Capacitación en Protección de Datos</h3>
            <p>El personal que trata datos personales debe recibir capacitación adecuada sobre las obligaciones legales y medidas de seguridad aplicables.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Capacitaciones');
        return $html;
    }

    // Generate Pseudonymization PDF
    public function generatePseudonymizationPDF($ruleId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['userId' => $this->user['_id']];
        if ($ruleId) {
            $filter['_id'] = $ruleId;
        }
        $rules = $this->db->find('compliance_pseudonymization', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE SEUDONIMIZACIÓN', 'Ley 21.719 - Art. 14 Quáter - Medidas de Seguridad');

        $total = count($rules);
        $executedCount = count(array_filter($rules, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed'])));
        $rate = $total > 0 ? round(($executedCount / $total) * 100, 1) : 0;
        $complianceBadge = $executedCount > 0 ? '<span class="badge badge-success">Cumple</span>' : '<span class="badge badge-warning">Pendiente</span>';

        $html .= '<div class="section">
        <div class="status-box">
            <h3>Estado del Registro: ' . $executedCount . '/' . $total . ' Reglas Ejecutadas</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen de Reglas de Seudonimización</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Reglas:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Reglas Ejecutadas:</label>
                <span>' . $executedCount . '</span>
            </div>
            <div class="info-item">
                <label>Tasa de Implementación:</label>
                <span>' . $rate . '%</span>
            </div>
            <div class="info-item">
                <label>Cumplimiento Art. 14 Quáter:</label>
                <span>' . $complianceBadge . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle de Reglas</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Campos</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($rules as $rule) {
                $name = htmlspecialchars($rule['name'] ?? 'Sin nombre');
                $description = htmlspecialchars($rule['description'] ?? 'Sin descripción');
                $fields = is_array($rule['fields']) ? implode(', ', array_map('htmlspecialchars', $rule['fields'])) : htmlspecialchars($rule['fields'] ?? 'No especificado');
                $status = ($rule['status'] ?? '') === 'executed' || !empty($rule['executed']) ? '<span class="badge badge-success">Ejecutada</span>' : '<span class="badge badge-warning">Pendiente</span>';
                $date = $rule['createdAt'] ? $this->formatDate($rule['createdAt']) : 'No registrado';

                $html .= '<tr>
                    <td>' . $name . '</td>
                    <td>' . $description . '</td>
                    <td>' . $fields . '</td>
                    <td>' . $status . '</td>
                    <td>' . $date . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay reglas de seudonimización registradas</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento - Art. 14 Quáter Ley 21.719</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Seudonimización de Datos</strong> - Aplicación de técnicas de ofuscación</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Irreversibilidad Controlada</strong> - Proceso reversible solo con clave separada</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Almacenamiento Seguro</strong> - Claves de reversión protegidas</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Documentación</strong> - Registro de reglas y procedimientos</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Artículo 14 Quáter - Seudonimización</h3>
            <p>La seudonimización es una medida de seguridad que permite tratar datos personales de forma que no puedan atribuirse a un titular sin el uso de información adicional.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Seudonimización');
        return $html;
    }

    // Generate ARCO Requests PDF
    public function generateARCORequestsPDF($requestId = null) {
        $company = $this->getCompanyInfo();
        $filter = ['companyId' => $this->user['_id']];
        if ($requestId) {
            $filter['_id'] = $requestId;
        }
        $requests = $this->db->find('arco_requests', $filter);

        $html = $this->getHeaderHTML('REGISTRO DE SOLICITUDES ARCO', 'Ley 21.719 - Arts. 8-13 - Derechos de los Titulares');

        $total = count($requests);
        $resolvedCount = count(array_filter($requests, fn($r) => ($r['status'] ?? '') === 'resolved'));
        $rate = $total > 0 ? round(($resolvedCount / $total) * 100, 1) : 0;
        $complianceBadge = $total > 0 ? '<span class="badge badge-success">Canal Activo</span>' : '<span class="badge badge-warning">Pendiente</span>';

        $html .= '<div class="section">
        <div class="status-box">
            <h3>Estado del Registro: ' . $resolvedCount . '/' . $total . ' Resueltas</h3>
            <p>Última actualización: ' . $this->formatDate(date('c')) . '</p>
        </div>
    </div>

    <div class="section">
        <h2>1. Información de la Empresa</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Empresa Responsable:</label>
                <span>' . $company['name'] . '</span>
            </div>
            <div class="info-item">
                <label>Delegado de Protección de Datos (DPD):</label>
                <span>' . $company['dpdName'] . '</span>
            </div>
            <div class="info-item">
                <label>Contacto DPD:</label>
                <span>' . $company['dpdEmail'] . ' | ' . $company['dpdPhone'] . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>2. Resumen de Solicitudes ARCO</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Total de Solicitudes:</label>
                <span>' . $total . '</span>
            </div>
            <div class="info-item">
                <label>Solicitudes Resueltas:</label>
                <span>' . $resolvedCount . '</span>
            </div>
            <div class="info-item">
                <label>Tasa de Respuesta:</label>
                <span>' . $rate . '%</span>
            </div>
            <div class="info-item">
                <label>Cumplimiento Arts. 8-13:</label>
                <span>' . $complianceBadge . '</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>3. Detalle de Solicitudes</h2>';

        if ($total > 0) {
            $html .= '<table class="data-table">
                <thead>
                    <tr>
                        <th>Solicitante</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Respuesta</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($requests as $request) {
                $solicitante = htmlspecialchars($request['solicitante'] ?? $request['name'] ?? 'No especificado');
                $tipo = htmlspecialchars($request['tipo'] ?? $request['type'] ?? 'No especificado');
                $date = $request['createdAt'] ? $this->formatDate($request['createdAt']) : 'No registrado';
                $status = htmlspecialchars($request['status'] ?? 'No especificado');
                $response = !empty($request['response']) ? '<span class="badge badge-success">Respondida</span>' : '<span class="badge badge-warning">Pendiente</span>';

                $html .= '<tr>
                    <td>' . $solicitante . '</td>
                    <td>' . $tipo . '</td>
                    <td>' . $date . '</td>
                    <td>' . $status . '</td>
                    <td>' . $response . '</td>
                </tr>';
            }

            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="checklist"><div class="checklist-item"><input type="checkbox" checked disabled><span>No hay solicitudes ARCO registradas</span></div></div>';
        }

        $html .= '</div>

    <div class="section">
        <h2>4. Requisitos de Cumplimiento - Arts. 8-13 Ley 21.719</h2>
        <div class="checklist">
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Derecho de Acceso (Art. 8)</strong> - Confirmación y copia de datos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Derecho de Rectificación (Art. 9)</strong> - Corrección de datos inexactos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Derecho de Supresión (Art. 10)</strong> - Eliminación de datos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Derecho de Oposición (Art. 11)</strong> - Oposición al tratamiento</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Derecho de Portabilidad (Art. 13)</strong> - Transferencia de datos</span>
            </div>
            <div class="checklist-item">
                <input type="checkbox" checked disabled>
                <span><strong>Respuesta en 10 días hábiles</strong> - Plazo legal de respuesta</span>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>5. Marco Legal</h2>
        <div class="legal-notice">
            <h3>Derechos ARCO - Arts. 8-13</h3>
            <p>Los titulares tienen derecho de Acceso, Rectificación, Cancelación, Oposición y Portabilidad de sus datos personales. Las solicitudes deben ser respondidas dentro de los 10 días hábiles.</p>
            <p><strong>Sanciones por incumplimiento:</strong> Multa hasta 5.000 UTM (Infracción Leve)</p>
        </div>
    </div>';

        $html .= $this->getFooterHTML('Solicitudes ARCO');
        return $html;
    }

    private function formatDate($date) {
        if (empty($date)) return 'No registrado';
        try {
            $dt = new DateTime($date);
            return $dt->format('d/m/Y H:i');
        } catch (Exception $e) {
            return 'Fecha inválida';
        }
    }

    public function generatePDFFile($html, $filename) {
        $pdfUrl = null;
        $pdfBase64 = null;

        try {
            // Use dompdf instead of wkhtmltopdf
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'Times New Roman');
            $options->set('paperSize', 'A4');
            $options->set('orientation', 'portrait');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Get PDF content
            $pdfContent = $dompdf->output();

            if (!empty($pdfContent)) {
                $reportsDir = __DIR__ . '/reports';
                if (!is_dir($reportsDir)) {
                    mkdir($reportsDir, 0755, true);
                }
                $pdfFilename = $filename . '-' . date('Y-m-d-His') . '.pdf';
                $pdfPath = $reportsDir . '/' . $pdfFilename;

                if (file_put_contents($pdfPath, $pdfContent) !== false) {
                    chmod($pdfPath, 0644);
                    $pdfUrl = '/api/reports/download/' . $pdfFilename;
                }

                $pdfBase64 = base64_encode($pdfContent);
            }
        } catch (Exception $e) {
            // Log error but continue with HTML fallback
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