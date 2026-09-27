<?php
namespace Cumplimiento;

class ProtocoloBrechas
{
    public static function manejar($usuario, $bd, string $metodo, array $cuerpo)
    {
        if ($metodo === 'POST') return self::guardar($usuario, $bd, $cuerpo);
        if ($metodo === 'GET')  return self::obtener($usuario, $bd);
        \json_error('método no soportado', 405);
    }

    private static function guardar($usuario, $bd, array $cuerpo)
    {
        $protocolo = [
            'userId'                 => $usuario['_id'],
            'protocolName'           => $cuerpo['protocolName']           ?? '',
            'protocolVersion'        => $cuerpo['protocolVersion']        ?? '',
            'approvalDate'           => $cuerpo['approvalDate']           ?? '',
            'nextReviewDate'         => $cuerpo['nextReviewDate']         ?? '',
            'protocolOwner'          => $cuerpo['protocolOwner']          ?? '',
            'approvedBy'             => $cuerpo['approvedBy']             ?? '',
            'scope'                  => $cuerpo['scope']                  ?? '',
            'definitions'            => $cuerpo['definitions']            ?? '',
            'detectionChannels'      => $cuerpo['detectionChannels']      ?? '',
            'maxDetectionTime'       => $cuerpo['maxDetectionTime']       ?? '',
            'severityLevels'         => $cuerpo['severityLevels']         ?? [],
            'incidentTypes'          => $cuerpo['incidentTypes']          ?? '',
            'internalReporting'      => $cuerpo['internalReporting']      ?? '',
            'csirtTeam'              => $cuerpo['csirtTeam']              ?? '',
            'containmentActions'     => $cuerpo['containmentActions']     ?? '',
            'evidencePreservation'   => $cuerpo['evidencePreservation']   ?? '',
            'maxContainmentTime'     => $cuerpo['maxContainmentTime']     ?? '',
            'autoEscalation'         => $cuerpo['autoEscalation']         ?? '',
            'assessmentMethodology'  => $cuerpo['assessmentMethodology']  ?? '',
            'apdpCriteria'           => $cuerpo['apdpCriteria']           ?? '',
            'subjectCriteria'        => $cuerpo['subjectCriteria']        ?? '',
            'likelyConsequences'     => $cuerpo['likelyConsequences']     ?? '',
            'apdpNotification'       => $cuerpo['apdpNotification']       ?? '',
            'subjectNotification'    => $cuerpo['subjectNotification']    ?? '',
            'thirdPartyNotification' => $cuerpo['thirdPartyNotification'] ?? '',
            'externalCommunication'  => $cuerpo['externalCommunication']  ?? '',
            'communicationTemplates' => $cuerpo['communicationTemplates'] ?? '',
            'recoveryPlan'           => $cuerpo['recoveryPlan']           ?? '',
            'closureCriteria'        => $cuerpo['closureCriteria']        ?? '',
            'correctivePreventive'   => $cuerpo['correctivePreventive']   ?? '',
            'rtoRpo'                 => $cuerpo['rtoRpo']                 ?? '',
            'postmortemReport'       => $cuerpo['postmortemReport']       ?? '',
            'lessonsLearned'         => $cuerpo['lessonsLearned']         ?? '',
            'protocolUpdate'         => $cuerpo['protocolUpdate']         ?? '',
            'drillsTesting'          => $cuerpo['drillsTesting']          ?? '',
            'annexes'                => $cuerpo['annexes']                ?? '',
            'createdAt'              => date('c'),
            'updatedAt'              => date('c'),
        ];

        $existente = $bd->findOne('compliance_breach_protocol', ['userId' => $usuario['_id']]);
        if ($existente) {
            $bd->updateOne('compliance_breach_protocol', ['_id' => $existente['_id']], $protocolo);
        } else {
            $bd->insertOne('compliance_breach_protocol', $protocolo);
        }

        $config = $bd->findOne('compliance_config', ['userId' => $usuario['_id']]) ?? [];
        $config['breachProtocolContent']   = 'documented';
        $config['breachProtocolUpdatedAt'] = date('c');
        if (empty($config['userId'])) $config['userId'] = $usuario['_id'];
        if (!empty($config['_id'])) {
            $bd->updateOne('compliance_config', ['_id' => $config['_id']], $config);
        } else {
            $bd->insertOne('compliance_config', $config);
        }

        \audit_log('breach_protocol_saved', [
            'protocolName'    => $protocolo['protocolName'],
            'protocolVersion' => $protocolo['protocolVersion'],
        ], $usuario['_id']);

        \json_response(['success' => true, 'message' => 'Protocolo de brechas guardado correctamente']);
    }

    private static function obtener($usuario, $bd)
    {
        $protocolo = $bd->findOne('compliance_breach_protocol', ['userId' => $usuario['_id']]);
        if ($protocolo) {
            unset($protocolo['_id'], $protocolo['userId']);
        }
        \json_response($protocolo ?? []);
    }
}