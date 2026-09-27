<?php
// backend/cumplimiento/Puntaje.php
// Cálculo de score + checklist + firma automática de capacitaciones.

namespace Cumplimiento;

class Puntaje
{
    /** POST /api/invisia/score */
    public static function calcular()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $userIds = Alcance::idsDeEmpresa($usuario, $bd);
        $esSuper = ($userIds === null);
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];

        $agentes    = $bd->count('agents', $filtro);
        $basesDatos = $bd->count('databases', $filtro);
        $alertas    = $bd->count('alerts', $filtro);
        $onboarding = $bd->findOne('onboarding', $esSuper ? [] : ['userId' => ['$in' => $userIds]]);

        $score = 0;
        $detalles = [];

        $scoreAgentes = $agentes > 0 ? 100 : 0;
        $score += $scoreAgentes * 0.3;
        $detalles['agents'] = ['label' => 'Agentes desplegados', 'score' => $scoreAgentes];

        $scoreBd = $basesDatos > 0 ? 100 : 0;
        $score += $scoreBd * 0.25;
        $detalles['databases'] = ['label' => 'Bases de datos monitorizadas', 'score' => $scoreBd];

        $scoreOnb = ($onboarding && !empty($onboarding['completed'])) ? 100 : 0;
        $score += $scoreOnb * 0.25;
        $detalles['onboarding'] = ['label' => 'Onboarding completado', 'score' => $scoreOnb];

        $scoreAlertas = $alertas > 0 ? 100 : 0;
        $score += $scoreAlertas * 0.2;
        $detalles['alerts'] = ['label' => 'Alertas configuradas', 'score' => $scoreAlertas];

        \json_response(['score' => round($score), 'details' => $detalles]);
    }

    /** POST /api/invisia/checklist */
    public static function checklistDetallado()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $userIds = Alcance::idsDeEmpresa($usuario, $bd);
        $esSuper = ($userIds === null);
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];

        $config       = $bd->findOne('compliance_config', $filtro) ?? [];
        $inventario   = $bd->find('compliance_inventory', $filtro);
        $consents     = $bd->find('compliance_consents', $filtro);
        $capacit      = $bd->find('compliance_trainings', $filtro);
        $dpia         = $bd->find('compliance_dpia', $filtro);
        $arco         = $bd->find('compliance_arco_requests', $filtro);
        $brechas      = $bd->find('compliance_breaches', $filtro);
        $seudonim     = $bd->find('compliance_pseudonymization', $filtro);
        $encargados   = $bd->find('compliance_processors', $filtro);
        $transfer     = $bd->find('compliance_transfers', $filtro);

        // Secciones documentadas manualmente en compliance_checklist
        $docs = $bd->find('compliance_checklist', $filtro);
        $seccionesDocs = [];
        foreach ($docs as $doc) {
            $seccion = $doc['section'] ?? '';
            $data = (array)($doc['data'] ?? []);
            $tieneDatos = false;
            foreach ($data as $v) {
                if (!empty($v) || (is_array($v) && count($v) > 0)) { $tieneDatos = true; break; }
            }
            if ($seccion !== '' && $tieneDatos) $seccionesDocs[] = $seccion;
        }
        $seccionesDocs = array_unique($seccionesDocs);

        $items = [
            ['id' => 'dpd',              'label' => 'DPD Designado',              'done' => (!empty($config['dpdName']) && !empty($config['dpdEmail'])) || in_array('dpd', $seccionesDocs)],
            ['id' => 'apdp',             'label' => 'Modelo certificado',          'done' => !empty($config['apdpRegistered']) && !empty($config['apdpRegistrationNumber'])],
            ['id' => 'inventory',        'label' => 'Inventario de Datos',         'done' => (count($inventario) > 0 && count(array_filter($inventario, fn($i) => !empty($i['name']) && !empty($i['legalBasis']))) > 0) || in_array('inventory', $seccionesDocs)],
            ['id' => 'privacy',          'label' => 'Política de Privacidad',      'done' => (!empty($config['privacyPolicyUrl']) || !empty($config['privacyPolicyContent'])) || in_array('privacy', $seccionesDocs)],
            ['id' => 'consents',         'label' => 'Consentimientos',             'done' => (count($consents) > 0 && count(array_filter($consents, fn($c) => empty($c['revokedAt']))) > 0) || in_array('consents', $seccionesDocs)],
            ['id' => 'breach_protocol',  'label' => 'Protocolo de Brechas',        'done' => !empty($config['breachProtocolUrl']) || !empty($config['breachProtocolContent'])],
            ['id' => 'arco',             'label' => 'Canal de derechos',           'done' => (count($arco) > 0 || !empty($config['arcoChannelUrl'])) || in_array('arco', $seccionesDocs)],
            ['id' => 'pseudonymization', 'label' => 'Seudonimización',             'done' => (count($seudonim) > 0 && count(array_filter($seudonim, fn($r) => ($r['status'] ?? '') === 'executed' || !empty($r['executed']))) > 0) || in_array('pseudonymization', $seccionesDocs)],
            ['id' => 'incident_response','label' => 'Plan de Respuesta a Incidentes','done' => !empty($config['incidentResponsePlan']) || !empty($config['incidentResponsePlanUrl'])],
            ['id' => 'training',         'label' => 'Capacitación',                'done' => (count($capacit) > 0 && count(array_filter($capacit, fn($t) => !empty($t['completed']))) > 0) || in_array('training', $seccionesDocs)],
        ];

        \json_response(['checklist' => $items]);
    }

    /** POST /api/invisia/auto-sign-training */
    public static function firmarCapacitacionAuto()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $cuerpo = \get_body();

        $capacitacionId = $cuerpo['trainingId'] ?? '';
        if (!$capacitacionId) \json_error('trainingId requerido');

        $capacitacion = $bd->findOne('compliance_trainings', [
            '_id' => $capacitacionId,
            'userId' => $usuario['_id'],
        ]);
        if (!$capacitacion) \json_error('Capacitación no encontrada', 404);

        $tokenInvitacion = bin2hex(random_bytes(16));
        $invitacion = [
            'userId'      => $usuario['_id'],
            'token'       => $tokenInvitacion,
            'title'       => $capacitacion['title'] ?? 'Capacitación',
            'description' => 'Firma para capacitación: ' . ($capacitacion['title'] ?? ''),
            'companyName' => $usuario['companyName'] ?? ($usuario['email'] ?? ''),
            'signed'      => false,
        ];

        $invitacionId = $bd->insertOne('compliance_invites', $invitacion);

        $bd->updateOne('compliance_trainings', ['_id' => $capacitacionId], [
            'inviteId'         => $invitacionId,
            'inviteAssignedAt' => date('c'),
        ]);

        \json_response([
            'success' => true,
            'message' => 'Invitación de firma creada exitosamente',
            'token'   => $tokenInvitacion,
        ]);
    }
}