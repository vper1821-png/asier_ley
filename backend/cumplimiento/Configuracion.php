<?php
// backend/cumplimiento/Configuracion.php
// Config compartida por empresa (DPD, políticas, APDP, etc.).

namespace Cumplimiento;

class Configuracion
{
    /** POST /api/invisia/compliance/config */
    public static function actualizar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $cuerpo = \get_body();

        $userIds = Alcance::idsDeEmpresa($usuario, $bd);
        $esSuper = ($userIds === null);
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];

        $existente = $bd->findOne('compliance_config', $filtro);
        $config = $existente ?: ['userId' => $usuario['_id']];

        // Políticas: acepta JSON string o array
        $politicasRaw = null;
        if (isset($cuerpo['policies'])) {
            $politicasRaw = is_string($cuerpo['policies'])
                ? json_decode($cuerpo['policies'], true)
                : $cuerpo['policies'];
            if (!is_array($politicasRaw)) $politicasRaw = [];
        }

        $urlPrivacidad = $config['privacyPolicyUrl'] ?? '';
        $urlCookies   = $config['cookiesPolicyUrl'] ?? '';
        $retencion    = $config['dataRetentionPolicy'] ?? '';

        if ($politicasRaw !== null) {
            foreach ($politicasRaw as $p) {
                if (!is_array($p)) continue;
                $tipo = $p['type'] ?? '';
                if ($tipo === 'privacy'   && empty($urlPrivacidad) && !empty($p['url']))     $urlPrivacidad = $p['url'];
                if ($tipo === 'cookies'   && empty($urlCookies)   && !empty($p['url']))     $urlCookies   = $p['url'];
                if ($tipo === 'retention' && empty($retencion)    && !empty($p['content'])) $retencion    = $p['content'];
            }
        } else {
            $politicasRaw = $config['policies'] ?? [];
            if (!empty($cuerpo['privacyPolicyUrl']))    $urlPrivacidad = $cuerpo['privacyPolicyUrl'];
            if (!empty($cuerpo['cookiesPolicyUrl']))    $urlCookies   = $cuerpo['cookiesPolicyUrl'];
            if (!empty($cuerpo['dataRetentionPolicy'])) $retencion    = $cuerpo['dataRetentionPolicy'];
        }

        $actualizaciones = [
            'privacyPolicyUrl'        => $urlPrivacidad,
            'cookiesPolicyUrl'        => $urlCookies,
            'dataRetentionPolicy'     => $retencion,
            'policies'                => $politicasRaw,
            'dpdName'                 => $cuerpo['dpdName']                 ?? ($config['dpdName']                 ?? ''),
            'dpdRut'                  => $cuerpo['dpdRut']                  ?? ($config['dpdRut']                  ?? ''),
            'dpdEmail'                => $cuerpo['dpdEmail']                ?? ($config['dpdEmail']                ?? ''),
            'dpdPhone'                => $cuerpo['dpdPhone']                ?? ($config['dpdPhone']                ?? ''),
            'dpdTitle'                => $cuerpo['dpdTitle']                ?? ($config['dpdTitle']                ?? ''),
            'companyName'             => $cuerpo['companyName']             ?? ($config['companyName']             ?? ''),
            'companyRut'              => $cuerpo['companyRut']              ?? ($config['companyRut']              ?? ''),
            'dpdAddress'              => $cuerpo['dpdAddress']              ?? ($config['dpdAddress']              ?? ''),
            'dpdPublicUrl'            => $cuerpo['dpdPublicUrl']            ?? ($config['dpdPublicUrl']            ?? ''),
            'apdpRegistered'          => $cuerpo['apdpRegistered']          ?? ($config['apdpRegistered']          ?? ''),
            'apdpRegistrationNumber'  => $cuerpo['apdpRegistrationNumber']  ?? ($config['apdpRegistrationNumber']  ?? ''),
            'apdpRegistrationDate'    => $cuerpo['apdpRegistrationDate']    ?? ($config['apdpRegistrationDate']    ?? ''),
            'complianceLevel'         => $cuerpo['complianceLevel']         ?? ($config['complianceLevel']         ?? ''),
            'preventionModelDate'     => $cuerpo['preventionModelDate']     ?? ($config['preventionModelDate']     ?? ''),
            'measureOverrides'        => $cuerpo['measureOverrides']        ?? ($config['measureOverrides']        ?? ''),
        ];

        if ($existente) {
            $actualizaciones['updatedAt'] = date('c');
            $bd->updateOne('compliance_config', ['_id' => $existente['_id']], $actualizaciones);
        } else {
            $actualizaciones['userId']    = $usuario['_id'];
            $actualizaciones['createdAt'] = date('c');
            $bd->insertOne('compliance_config', $actualizaciones);
        }

        \json_response(['success' => true, 'message' => 'Configuración actualizada']);
    }

    /** GET /api/invisia/compliance/config */
    public static function obtener()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $filtro = Alcance::filtro($usuario, $bd);
        $config = $bd->findOne('compliance_config', $filtro) ?? [];
        \json_response($config);
    }
}