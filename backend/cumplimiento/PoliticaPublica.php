<?php
namespace Cumplimiento;

class PoliticaPublica
{
    /** GET /api/compliance/public-policy?token=... */
    public static function generar()
    {
        $token = $_GET['token'] ?? '';
        if (!$token) { header('HTTP/1.1 401 Unauthorized'); echo 'Token requerido'; exit; }

        $decoded = \Auth::verifyToken($token);
        if (!$decoded) { header('HTTP/1.1 401 Unauthorized'); echo 'Token inválido'; exit; }

        $bd = \Database::getInstance();
        $usuario = $bd->findOne('users', ['_id' => $decoded['userId']]);
        if (!$usuario) { header('HTTP/1.1 401 Unauthorized'); echo 'Usuario no encontrado'; exit; }

        $config = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];

        // Sanitización
        $nombreEmpresa = \BsonHelpers::h($config['companyName'] ?? ($usuario['companyName'] ?? ($usuario['email'] ?? 'Empresa')));
        $nombreDpd     = \BsonHelpers::h($config['dpdName']    ?? '—');
        $emailDpd      = \BsonHelpers::h($config['dpdEmail']   ?? '—');
        $telefonoDpd   = \BsonHelpers::h($config['dpdPhone']   ?? '—');
        $direccionDpd  = \BsonHelpers::h($config['dpdAddress'] ?? '');
        $urlDpd        = \BsonHelpers::h($config['dpdPublicUrl'] ?? '');

        $inventario  = $bd->find('compliance_inventory',  ['userId' => $usuario['_id']]);
        $consents    = $bd->find('compliance_consents',   ['userId' => $usuario['_id']]);
        $brechas     = $bd->find('compliance_breaches',   ['userId' => $usuario['_id']]);
        $encargados  = $bd->find('compliance_processors', ['userId' => $usuario['_id']]);
        $transfer    = $bd->find('compliance_transfers',  ['userId' => $usuario['_id']]);

        // Inventario limpio
        $invLimpio = [];
        foreach ($inventario as $inv) {
            $invLimpio[] = [
                'name'       => \BsonHelpers::str($inv['name'] ?? ''),
                'purpose'    => \BsonHelpers::str($inv['purpose'] ?? ''),
                'legalBasis' => \BsonHelpers::str($inv['legalBasis'] ?? ''),
                'categories' => \BsonHelpers::arr($inv['dataCategories'] ?? null),
            ];
        }

        // Categorías únicas
        $catMap = [];
        foreach ($invLimpio as $inv) {
            foreach ($inv['categories'] as $c) {
                $c = trim((string)$c);
                if ($c !== '') $catMap[$c] = true;
            }
        }
        $catList = array_keys($catMap);

        $consentsActivos = 0;
        foreach ($consents as $c) if (empty($c['revokedAt'])) $consentsActivos++;
        $brechasResueltas = 0;
        foreach ($brechas as $b) if (($b['status'] ?? '') === 'resolved') $brechasResueltas++;

        // ═══ HTML ═══
        $html  = "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'>";
        $html .= "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        $html .= "<title>Política de Privacidad — {$nombreEmpresa}</title>";
        $html .= "<style>
            body{font-family:'Inter',Arial,sans-serif;line-height:1.7;color:#1a1a1a;max-width:900px;margin:0 auto;padding:40px 20px;background:#fafafa}
            .header{border-bottom:2px solid #1a1a1a;padding-bottom:20px;margin-bottom:40px}
            .header h1{font-size:28px;font-weight:700;margin:0 0 10px}
            .header p{color:#555;margin:0}
            .meta{background:#f5f5f5;padding:15px 20px;border-radius:8px;margin-bottom:30px;font-size:14px}
            section{margin-bottom:40px}
            h2{font-size:22px;font-weight:600;color:#1a1a1a;border-left:4px solid #2563eb;padding-left:15px;margin-bottom:15px}
            h3{font-size:18px;font-weight:600;margin:20px 0 10px}
            ul{padding-left:20px}
            li{margin-bottom:8px}
            .dpd-card{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:20px;margin:20px 0}
            .footer{border-top:1px solid #ddd;padding-top:20px;margin-top:40px;color:#666;font-size:14px}
            @media print{body{background:#fff;padding:0}.footer{display:none}}
        </style></head><body>";

        $html .= "<div class='header'><h1>Política de Privacidad</h1>";
        $html .= "<p>{$nombreEmpresa} · Ley 21.719 · Protección de Datos Personales</p></div>";
        $html .= "<div class='meta'><strong>Versión:</strong> 1.0 | ";
        $html .= "<strong>Fecha:</strong> " . date('d/m/Y') . " | ";
        $html .= "<strong>Responsable:</strong> {$nombreEmpresa}</div>";

        // 1. Identidad
        $html .= "<section><h2>1. Identidad del Responsable</h2>";
        $html .= "<p><strong>Nombre:</strong> {$nombreEmpresa}</p>";
        $html .= "<p><strong>Contacto DPD:</strong> {$nombreDpd} — {$emailDpd} — {$telefonoDpd}</p>";
        if ($direccionDpd !== '') $html .= "<p><strong>Dirección:</strong> {$direccionDpd}</p>";
        if ($urlDpd !== '') $html .= "<p><strong>Sitio web DPD:</strong> <a href='{$urlDpd}'>{$urlDpd}</a></p>";
        $html .= "</section>";

        // 2. Finalidades
        $html .= "<section><h2>2. Finalidades y Base Legal del Tratamiento</h2>";
        $html .= "<p>Tratamos sus datos personales para las siguientes finalidades, con la base legal correspondiente:</p>";
        if (empty($invLimpio)) {
            $html .= "<p><em>El responsable no ha declarado todavía las finalidades del tratamiento.</em></p>";
        } else {
            $html .= "<ul>";
            foreach ($invLimpio as $inv) {
                $p = $inv['purpose'] !== '' ? $inv['purpose'] : $inv['name'];
                $b = $inv['legalBasis'] !== '' ? $inv['legalBasis'] : 'No especificada';
                $c = implode(', ', $inv['categories']);
                $html .= "<li><strong>" . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . "</strong>";
                $html .= " — Base legal: " . htmlspecialchars($b, ENT_QUOTES, 'UTF-8');
                if ($c !== '') $html .= " — Categorías: " . htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
                $html .= "</li>";
            }
            $html .= "</ul>";
        }
        $html .= "</section>";

        // 3. Categorías
        $html .= "<section><h2>3. Categorías de Datos Tratados</h2>";
        if (empty($catList)) {
            $html .= "<p><em>No hay categorías declaradas todavía.</em></p>";
        } else {
            $html .= "<ul>";
            foreach ($catList as $cat) {
                $html .= "<li>" . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . "</li>";
            }
            $html .= "</ul>";
        }
        $html .= "</section>";

        // 4. Derechos
        $html .= "<section><h2>4. Derechos del Titular (Art. 4-13 Ley 21.719)</h2>";
        $html .= "<ul>";
        $html .= "<li><strong>Acceso (Art. 8):</strong> Obtener confirmación y copia de sus datos.</li>";
        $html .= "<li><strong>Rectificación (Art. 9):</strong> Corregir datos inexactos o incompletos.</li>";
        $html .= "<li><strong>Supresión (Art. 10):</strong> Solicitar eliminación cuando ya no sean necesarios.</li>";
        $html .= "<li><strong>Oposición (Art. 11):</strong> Oponerse al tratamiento en ciertos casos.</li>";
        $html .= "<li><strong>Portabilidad (Art. 13):</strong> Recibir sus datos en formato estructurado.</li>";
        $html .= "<li><strong>Bloqueo (Art. 8 ter):</strong> Suspender temporalmente el tratamiento.</li>";
        $html .= "</ul>";
        $html .= "<p>Para ejercer sus derechos, contacte al DPD en: {$emailDpd}</p>";
        $html .= "</section>";

        // 5. Consentimiento
        $html .= "<section><h2>5. Consentimiento (Art. 12)</h2>";
        $html .= "<p>Cuando el tratamiento se base en consentimiento, este es libre, informado, específico, previo e inequívoco. Puede revocarlo en cualquier momento contactando al DPD.</p>";
        $html .= "<p>Total de consentimientos activos registrados: " . $consentsActivos . "</p>";
        $html .= "</section>";

        // 6. Cesiones y transferencias
        $html .= "<section><h2>6. Cesiones y Transferencias Internacionales (Art. 15, 21, 27)</h2>";
        $html .= "<p>No cedemos datos a terceros salvo obligación legal, ejecución de contrato o consentimiento. Las transferencias internacionales se realizan con garantías adecuadas.</p>";

        if (count($encargados) > 0) {
            $html .= "<h3>Encargados del tratamiento</h3><ul>";
            foreach ($encargados as $p) {
                $n = \BsonHelpers::str($p['name'] ?? '');
                $s = \BsonHelpers::str($p['serviceType'] ?? '');
                $c = \BsonHelpers::str($p['country'] ?? '');
                $html .= "<li><strong>" . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . "</strong>";
                if ($s !== '') $html .= " — " . htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
                if ($c !== '') $html .= " (" . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . ")";
                $html .= "</li>";
            }
            $html .= "</ul>";
        }

        if (count($transfer) > 0) {
            $html .= "<h3>Transferencias internacionales</h3><ul>";
            foreach ($transfer as $t) {
                $c = \BsonHelpers::str($t['destinationCountry'] ?? '');
                $m = \BsonHelpers::str($t['mechanism'] ?? '');
                $html .= "<li>" . htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
                if ($m !== '') $html .= " — " . htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                $html .= "</li>";
            }
            $html .= "</ul>";
        }
        $html .= "</section>";

        // 7. Seguridad
        $html .= "<section><h2>7. Medidas de Seguridad (Art. 14 quinquies, 25, 26)</h2>";
        $html .= "<p>Implementamos medidas técnicas y organizativas: cifrado, control de acceso, registro de accesos, evaluación de impacto (DPIA), plan de respuesta a incidentes.</p>";
        $html .= "<p>Incidentes de seguridad registrados: " . count($brechas) . " (resueltos: " . $brechasResueltas . ")</p>";
        $html .= "</section>";

        // 8. Retención
        $html .= "<section><h2>8. Retención de Datos (Art. 14)</h2>";
        $html .= "<p>Los datos se conservan solo el tiempo necesario para la finalidad del tratamiento o mientras exista obligación legal.</p>";
        $html .= "</section>";

        // 9. DPD
        $html .= "<section><h2>9. Delegado de Protección de Datos (Art. 28)</h2>";
        $html .= "<div class='dpd-card'><h3>Contacto DPD</h3>";
        $html .= "<p><strong>Nombre:</strong> {$nombreDpd}</p>";
        $html .= "<p><strong>Email:</strong> {$emailDpd}</p>";
        $html .= "<p><strong>Teléfono:</strong> {$telefonoDpd}</p>";
        if ($direccionDpd !== '') $html .= "<p><strong>Dirección:</strong> {$direccionDpd}</p>";
        if ($urlDpd !== '') $html .= "<p><strong>Sitio web:</strong> <a href='{$urlDpd}'>{$urlDpd}</a></p>";
        $html .= "</div></section>";

        // 10. APDP
        $html .= "<section><h2>10. Reclamaciones ante la APDP</h2>";
        $html .= "<p>Si considera que sus derechos no han sido respetados, puede presentar reclamación ante la Agencia de Protección de Datos Personales (APDP) en www.apdp.cl</p>";
        $html .= "</section>";

        $html .= "<div class='footer'>";
        $html .= "<p>Política de Privacidad generada automáticamente por SecureLab — Ley 21.719 — Protección de Datos Personales — Chile</p>";
        $html .= "<p>Fecha de última actualización: " . date('d/m/Y') . "</p>";
        $html .= "</div></body></html>";

        // ─── Versionado inmutable (Art. 14 ter) ───
        try {
            $companyId  = $config['companyId'] ?? $usuario['_id'];
            $hashPol    = hash('sha256', $html);

            $ultimaVersion = $bd->findOne('compliance_policy_versions',
                ['companyId' => $companyId],
                ['sort' => ['version' => -1]]
            );

            if (!$ultimaVersion || ($ultimaVersion['hash'] ?? '') !== $hashPol) {
                $proxVersion = ((int)($ultimaVersion['version'] ?? 0)) + 1;
                $publicadoEn = date('c');

                $bd->insertOne('compliance_policy_versions', [
                    'companyId'       => $companyId,
                    'userId'          => $usuario['_id'],
                    'version'         => $proxVersion,
                    'html'            => $html,
                    'hash'            => $hashPol,
                    'publishedAt'     => $publicadoEn,
                    'publishedBy'     => (string)$usuario['_id'],
                    'publishedByName' => $usuario['name'] ?? ($usuario['email'] ?? 'Usuario'),
                    'companyName'     => $config['companyName'] ?? '',
                    'dpdName'         => $config['dpdName'] ?? '',
                    'dpdEmail'        => $config['dpdEmail'] ?? '',
                    'apdpRegistered'  => $config['apdpRegistered'] ?? '',
                ]);

                if (!empty($config['_id'])) {
                    $bd->updateOne('compliance_config', ['_id' => $config['_id']], [
                        'publishedPolicyVersion' => $proxVersion,
                        'publishedPolicyHash'    => $hashPol,
                        'publishedPolicyAt'      => $publicadoEn,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[PoliticaPublica] Versionado falló: ' . $e->getMessage());
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}