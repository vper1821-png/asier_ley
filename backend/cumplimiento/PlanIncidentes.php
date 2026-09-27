<?php
namespace Cumplimiento;

class PlanIncidentes
{
    public static function manejar($usuario, $bd, string $metodo, array $cuerpo)
    {
        if ($metodo === 'POST') return self::guardar($usuario, $bd, $cuerpo);
        if ($metodo === 'GET')  return self::obtener($usuario, $bd);
        \json_error('método no soportado', 405);
    }

    private static function guardar($usuario, $bd, array $cuerpo)
    {
        $plan = [
            'userId'                  => $usuario['_id'],
            'planName'                => $cuerpo['planName']                ?? '',
            'planVersion'             => $cuerpo['planVersion']             ?? '',
            'approvalDate'            => $cuerpo['approvalDate']            ?? '',
            'nextReviewDate'          => $cuerpo['nextReviewDate']          ?? '',
            'planOwner'               => $cuerpo['planOwner']               ?? '',
            'scope'                   => $cuerpo['scope']                   ?? '',
            'references'              => $cuerpo['references']              ?? '',
            'csirtRoles'              => $cuerpo['csirtRoles']              ?? '',
            'csirtPhone24'            => $cuerpo['csirtPhone24']            ?? '',
            'csirtEmail24'            => $cuerpo['csirtEmail24']            ?? '',
            'escalationToManagement'  => $cuerpo['escalationToManagement']  ?? '',
            'detectionChannels'       => $cuerpo['detectionChannels']       ?? '',
            'maxDetectionTime'        => $cuerpo['maxDetectionTime']        ?? '',
            'severityClassification'  => $cuerpo['severityClassification']  ?? [],
            'containmentActions'      => $cuerpo['containmentActions']      ?? '',
            'evidencePreservation'    => $cuerpo['evidencePreservation']    ?? '',
            'apdpNotification'        => $cuerpo['apdpNotification']        ?? '',
            'subjectNotification'     => $cuerpo['subjectNotification']     ?? '',
            'thirdPartyNotification'  => $cuerpo['thirdPartyNotification']  ?? '',
            'externalCommunication'   => $cuerpo['externalCommunication']   ?? '',
            'recoveryPlan'            => $cuerpo['recoveryPlan']            ?? '',
            'closureCriteria'         => $cuerpo['closureCriteria']         ?? '',
            'rtoRpo'                  => $cuerpo['rtoRpo']                  ?? '',
            'correctivePreventive'    => $cuerpo['correctivePreventive']    ?? '',
            'postmortemReport'        => $cuerpo['postmortemReport']        ?? '',
            'lessonsLearned'          => $cuerpo['lessonsLearned']          ?? '',
            'planUpdate'              => $cuerpo['planUpdate']              ?? '',
            'drillsTesting'           => $cuerpo['drillsTesting']           ?? '',
            'annexes'                 => $cuerpo['annexes']                 ?? '',
            'createdAt'               => date('c'),
            'updatedAt'               => date('c'),
        ];

        $existente = $bd->findOne('compliance_incident_response', ['userId' => $usuario['_id']]);
        if ($existente) {
            $bd->updateOne('compliance_incident_response', ['_id' => $existente['_id']], $plan);
        } else {
            $bd->insertOne('compliance_incident_response', $plan);
        }

        $config = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];
        $config['incidentResponsePlan']          = 'documented';
        $config['incidentResponsePlanUpdatedAt'] = date('c');
        if (empty($config['userId'])) $config['userId'] = $usuario['_id'];
        if (!empty($config['_id'])) {
            $bd->updateOne('compliance_config', ['_id' => $config['_id']], $config);
        } else {
            $bd->insertOne('compliance_config', $config);
        }

        \audit_log('incident_response_saved', [
            'planName'    => $plan['planName'],
            'planVersion' => $plan['planVersion'],
        ], $usuario['_id']);

        \json_response(['success' => true, 'message' => 'Plan de respuesta a incidentes guardado correctamente']);
    }

    private static function obtener($usuario, $bd)
    {
        $plan = $bd->findOne('compliance_incident_response', ['userId' => $usuario['_id']]);
        if ($plan) unset($plan['_id'], $plan['userId']);
        \json_response($plan ?? []);
    }
}