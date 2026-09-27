<?php
namespace Cumplimiento;

class Checklist
{
    public static function manejar($usuario, $bd, $userIds, $esSuper, string $metodo, string $id, array $cuerpo)
    {
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];

        // Listar todas las secciones documentadas
        if (!$id) {
            $docs = $bd->find('compliance_checklist', $filtro);
            $secciones = [];
            foreach ($docs as $d) {
                if (empty($d['section'])) continue;
                $data = (array)($d['data'] ?? []);
                $tieneDatos = is_array($data) && count(array_filter(
                    $data,
                    fn($v) => $v !== '' && $v !== null && $v !== []
                )) > 0;
                if ($tieneDatos) $secciones[] = $d['section'];
            }
            \json_response(['success' => true, 'sections' => $secciones]);
        }

        // GET: obtener una sección
        if ($metodo === 'GET') {
            $doc = $bd->findOne('compliance_checklist', [
                'userId'  => ['$in' => $userIds],
                'section' => $id,
            ]);
            \json_response((array)($doc['data'] ?? []));
        }

        // POST: guardar una sección
        if ($metodo === 'POST') {
            $data = $cuerpo;
            unset($data['token']);
            $existente = $bd->findOne('compliance_checklist', [
                'userId'  => $usuario['_id'],
                'section' => $id,
            ]);
            $doc = [
                'userId'    => $usuario['_id'],
                'section'   => $id,
                'data'      => $data,
                'updatedAt' => date('c'),
            ];
            if ($existente) {
                $bd->updateOne('compliance_checklist', ['_id' => $existente['_id']], $doc);
            } else {
                $doc['createdAt'] = date('c');
                $bd->insertOne('compliance_checklist', $doc);
            }
            \json_response(['success' => true, 'message' => 'Documentación guardada']);
        }

        // DELETE: eliminar sección + limpiar datos derivados
        if ($metodo === 'DELETE') {
            $bd->deleteOne('compliance_checklist', ['userId' => $usuario['_id'], 'section' => $id]);
            if ($id === 'breach_protocol')   $bd->deleteOne('compliance_breach_protocol', ['userId' => $usuario['_id']]);
            if ($id === 'incident_response') $bd->deleteOne('compliance_incident_response', ['userId' => $usuario['_id']]);
            if ($id === 'dpd')    $bd->updateOne('compliance_config', ['userId' => $usuario['_id']], ['dpdName' => null, 'dpdEmail' => null, 'dpdPhone' => null]);
            if ($id === 'apdp')   $bd->updateOne('compliance_config', ['userId' => $usuario['_id']], ['apdpRegistered' => null, 'apdpRegistrationNumber' => null]);
            if ($id === 'privacy')$bd->updateOne('compliance_config', ['userId' => $usuario['_id']], ['privacyPolicyUrl' => null, 'cookiesPolicyUrl' => null, 'dataRetentionPolicy' => null]);
            if ($id === 'arco')   $bd->updateOne('compliance_config', ['userId' => $usuario['_id']], ['arcoChannelUrl' => null]);
            \json_response(['success' => true, 'message' => 'Control eliminado']);
        }

        \json_error('método no soportado', 405);
    }
}