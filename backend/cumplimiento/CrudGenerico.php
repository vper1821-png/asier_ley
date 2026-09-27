<?php
namespace Cumplimiento;

class CrudGenerico
{
    /** Colecciones válidas para el CRUD genérico. */
    private const COLECCIONES_PERMITIDAS = [
        'consents','inventory','breaches','templates','trainings','dpia','dpa',
        'pseudonymization','invites','processors','transfers','public_policy','packs',
    ];

    public static function manejar(
        string $recurso,
        string $id,
        string $accion,
        string $metodo,
        array  $cuerpo,
        $usuario,
        $bd,
        bool   $esSuper,
        ?array $userIds
    ) {
        if (!in_array($recurso, self::COLECCIONES_PERMITIDAS, true)) {
            \json_error('recurso no soportado', 404);
        }

        $coleccion = 'compliance_' . $recurso;

        // ── BULK: insertar muchos a la vez ──
        if ($id === 'bulk' && $metodo === 'POST') {
            return self::crearBulk($coleccion, $recurso, $usuario, $bd, $cuerpo);
        }

        // ── INVITES: acciones especiales ──
        if ($recurso === 'invites' && $id && $metodo === 'POST') {
            if ($accion === 'assign-training') return self::inviteAsignarTraining($coleccion, $id, $usuario, $bd, $cuerpo);
            if ($accion === 'unassign')        return self::inviteDesasignar($coleccion, $id, $usuario, $bd);
        }

        // ── GET lista ──
        if ($metodo === 'GET' && !$id) {
            return self::listar($coleccion, $usuario, $bd, $esSuper, $userIds);
        }

        // ── GET detalle ──
        if ($metodo === 'GET' && $id) {
            return self::detalle($coleccion, $id, $usuario, $bd, $esSuper, $userIds);
        }

        // ── POST crear ──
        if ($metodo === 'POST' && !$id) {
            return self::crear($coleccion, $recurso, $usuario, $bd, $cuerpo);
        }

        // ── PUT actualizar ──
        if ($metodo === 'PUT' && $id) {
            return self::actualizar($coleccion, $id, $recurso, $usuario, $bd, $cuerpo, $esSuper, $userIds);
        }

        // ── DELETE uno ──
        if ($metodo === 'DELETE' && $id) {
            return self::eliminarUno($coleccion, $id, $usuario, $bd, $esSuper, $userIds);
        }

        // ── DELETE todos ──
        if ($metodo === 'DELETE' && !$id) {
            return self::eliminarTodos($coleccion, $usuario, $bd, $esSuper, $userIds);
        }

        // ── POST con acción (approve/reject/revoke/etc.) ──
        if ($metodo === 'POST' && $id && $accion) {
            return self::accion($coleccion, $recurso, $id, $accion, $usuario, $bd, $cuerpo, $esSuper, $userIds);
        }

        \json_error('método no soportado', 405);
    }

    // ═══════════════════════════════════════════════════════════
    // Handlers
    // ═══════════════════════════════════════════════════════════

    private static function crearBulk($coleccion, $recurso, $usuario, $bd, array $cuerpo)
    {
        $items = $cuerpo['items'] ?? ($cuerpo['invites'] ?? $cuerpo);
        if (!is_array($items) || empty($items)) \json_error('items requerido');

        $creados = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $doc = $item;
            unset($doc['token']);
            $doc['userId']    = $usuario['_id'];
            $doc['createdAt'] = date('c');

            if ($recurso === 'invites') {
                $doc['token']       = bin2hex(random_bytes(16));
                $doc['signed']      = false;
                $doc['companyName'] = $doc['companyName'] ?? ($usuario['companyName'] ?? '');
            }
            $creados[] = $bd->insertOne($coleccion, $doc);
        }
        \json_response(['success' => true, 'created' => count($creados), 'items' => $creados]);
    }

    private static function listar($coleccion, $usuario, $bd, bool $esSuper, ?array $userIds)
    {
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];

        if (!empty($_GET['active'])) {
            $filtro['active'] = filter_var($_GET['active'], FILTER_VALIDATE_BOOLEAN);
        }

        $items = $bd->find($coleccion, $filtro);

        if (!empty($_GET['search'])) {
            $q = strtolower($_GET['search']);
            $items = array_values(array_filter($items, fn($it) =>
                str_contains(strtolower($it['name']        ?? ''), $q) ||
                str_contains(strtolower($it['email']       ?? ''), $q) ||
                str_contains(strtolower($it['title']       ?? ''), $q) ||
                str_contains(strtolower($it['description'] ?? ''), $q)
            ));
        }

        \json_response($items);
    }

    private static function detalle($coleccion, $id, $usuario, $bd, bool $esSuper, ?array $userIds)
    {
        $filtro = ['_id' => $id];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $item = $bd->findOne($coleccion, $filtro);
        if (!$item) \json_error('elemento no encontrado', 404);
        \json_response($item);
    }

    private static function crear($coleccion, $recurso, $usuario, $bd, array $cuerpo)
    {
        $item = $cuerpo;
        unset($item['token']);
        $item['userId']    = $usuario['_id'];
        $item['createdAt'] = date('c');

        if ($recurso === 'invites') {
            $item['token']  = bin2hex(random_bytes(16));
            $item['signed'] = false;
        }

        // Packs y templates nacen activos
        if (in_array($recurso, ['packs', 'templates'], true)
            && !array_key_exists('active', $item)) {
            $item['active'] = true;
        }

        $creado = $bd->insertOne($coleccion, $item);
        \json_response(['success' => true, $recurso => $creado]);
    }

    private static function actualizar($coleccion, $id, $recurso, $usuario, $bd, array $cuerpo, bool $esSuper, ?array $userIds)
    {
        $filtro = ['_id' => $id];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $existente = $bd->findOne($coleccion, $filtro);
        if (!$existente) \json_error('elemento no encontrado', 404);

        $actualizaciones = $cuerpo;
        unset($actualizaciones['_id'], $actualizaciones['userId']);
        $actualizaciones['updatedAt'] = date('c');

        if ($recurso === 'inventory') {
            $actualizaciones['needsReview'] = false;
            $actualizaciones['reviewedAt']  = date('c');
            $actualizaciones['reviewedBy']  = (string)$usuario['_id'];
        }

        $bd->updateOne($coleccion, ['_id' => $id], $actualizaciones);
        \json_response(['success' => true]);
    }

    private static function eliminarUno($coleccion, $id, $usuario, $bd, bool $esSuper, ?array $userIds)
    {
        $filtro = ['_id' => $id];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $existente = $bd->findOne($coleccion, $filtro);
        if (!$existente) \json_error('elemento no encontrado', 404);

        $bd->deleteOne($coleccion, ['_id' => $id]);

        \audit_log('compliance_delete', [
            'collection' => $coleccion,
            'itemId'     => $id,
            'itemName'   => $existente['name'] ?? ($existente['title'] ?? ''),
        ], $usuario['_id']);

        \json_response(['success' => true]);
    }

    private static function eliminarTodos($coleccion, $usuario, $bd, bool $esSuper, ?array $userIds)
    {
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];
        $all = $bd->find($coleccion, $filtro);

        foreach ($all as $it) {
            $bd->deleteOne($coleccion, ['_id' => $it['_id']]);
        }

        \audit_log('compliance_delete_all', [
            'collection' => $coleccion,
            'deleted'    => count($all),
        ], $usuario['_id']);

        \json_response(['success' => true, 'deleted' => count($all)]);
    }

    private static function accion($coleccion, $recurso, $id, $accion, $usuario, $bd, array $cuerpo, bool $esSuper, ?array $userIds)
    {
        $filtro = ['_id' => $id];
        if (!$esSuper) $filtro['userId'] = ['$in' => $userIds];

        $existente = $bd->findOne($coleccion, $filtro);
        if (!$existente) \json_error('elemento no encontrado', 404);

        $extra = $cuerpo['response'] ?? ($cuerpo['notes'] ?? '');
        $ahora = date('c');
        $cambios = ['updatedAt' => $ahora];

        switch ($accion) {
            case 'revoke':
                $cambios += ['active' => false, 'revokedAt' => $ahora];
                break;

            case 'resolve':
                $cambios += ['status' => 'resolved', 'resolvedAt' => $ahora, 'resolution' => $extra];
                break;

            case 'approve':
                if ($recurso === 'dpia' && !Alcance::esDpo($usuario, $bd)) {
                    \json_error('Solo el DPO/DPD puede aprobar evaluaciones de impacto', 403);
                }
                $cambios += [
                    'status'         => 'approved',
                    'approvedAt'     => $ahora,
                    'approvedBy'     => (string)$usuario['_id'],
                    'approvedByRole' => $usuario['role'] ?? 'dpo',
                    'approvedByName' => $usuario['name'] ?? ($usuario['email'] ?? ''),
                ];
                break;

            case 'reject':
                if ($recurso === 'dpia' && !Alcance::esDpo($usuario, $bd)) {
                    \json_error('Solo el DPO/DPD puede rechazar evaluaciones de impacto', 403);
                }
                $cambios += [
                    'status'          => 'rejected',
                    'rejectedAt'      => $ahora,
                    'rejectedBy'      => (string)$usuario['_id'],
                    'rejectedByRole'  => $usuario['role'] ?? 'dpo',
                    'rejectionReason' => $extra,
                ];
                break;

            case 'complete':
                $cambios += ['completed' => true, 'completedAt' => $ahora];
                break;

            case 'unsign':
                $cambios += ['signed' => false, 'unsignedAt' => $ahora];
                if ($recurso === 'invites') {
                    $bd->updateOne('compliance_trainings', ['inviteId' => $id], [
                        'signature' => null, 'signatureType' => null, 'signerName' => null,
                        'signedAt' => null, 'inviteId' => null, 'signatureAssignedAt' => null,
                        'completed' => false, 'completedAt' => null,
                    ]);
                    $bd->updateOne($coleccion, ['_id' => $id], [
                        'assignedTrainingId' => null, 'assignedTrainingName' => null, 'assignedAt' => null,
                    ]);
                }
                break;

            case 'execute':
                $cambios += ['executed' => true, 'executedAt' => $ahora];
                break;

            case 'revert':
                $cambios += ['executed' => false, 'revertedAt' => $ahora];
                break;

            case 'notify_apdp':
                $cambios += [
                    'notifiedAPDP'              => true,
                    'apdpNotifiedAt'            => $ahora,
                    'apdpNotificationMethod'    => $cuerpo['method'] ?? 'portal',
                    'apdpNotificationRef'       => $cuerpo['ref']    ?? '',
                ];
                break;

            case 'notify_subjects':
                $cambios += [
                    'notifiedSubjects'    => true,
                    'subjectsNotifiedAt'  => $ahora,
                    'notificationChannel' => $cuerpo['channel'] ?? 'email',
                    'notificationRef'     => $cuerpo['ref']     ?? '',
                ];
                break;

            default:
                \json_error('acción no soportada', 400);
        }

        $bd->updateOne($coleccion, ['_id' => $id], $cambios);
        \json_response(['success' => true]);
    }

    private static function inviteAsignarTraining($coleccion, $id, $usuario, $bd, array $cuerpo)
    {
        $inv = $bd->findOne($coleccion, ['_id' => $id, 'userId' => $usuario['_id']]);
        if (!$inv) \json_error('invitación no encontrada', 404);
        if (empty($inv['signed'])) \json_error('la invitación aún no está firmada');

        $trainingId = $cuerpo['trainingId'] ?? '';
        if (!$trainingId) \json_error('trainingId requerido');

        $cap = $bd->findOne('compliance_trainings', ['_id' => $trainingId, 'userId' => $usuario['_id']]);
        if (!$cap) \json_error('capacitación no encontrada', 404);

        $bd->updateOne('compliance_trainings', ['_id' => $trainingId], [
            'signature'           => $inv['signature']     ?? '',
            'signatureType'       => $inv['signatureType'] ?? 'image',
            'signerName'          => $inv['signerName']    ?? '',
            'signedAt'            => $inv['signedAt']      ?? date('c'),
            'inviteId'            => $id,
            'signatureAssignedAt' => date('c'),
            'completed'           => true,
            'completedAt'         => date('c'),
        ]);
        $bd->updateOne($coleccion, ['_id' => $id], [
            'assignedTrainingId'   => $trainingId,
            'assignedTrainingName' => $cap['title'] ?? '',
            'assignedAt'           => date('c'),
        ]);
        \json_response(['success' => true]);
    }

    private static function inviteDesasignar($coleccion, $id, $usuario, $bd)
    {
        $inv = $bd->findOne($coleccion, ['_id' => $id, 'userId' => $usuario['_id']]);
        if (!$inv) \json_error('invitación no encontrada', 404);

        $limpiar = [
            'signature' => null, 'signatureType' => null, 'signerName' => null,
            'signedAt' => null, 'inviteId' => null, 'signatureAssignedAt' => null,
            'completed' => false, 'completedAt' => null,
        ];

        $vinculada = $inv['assignedTrainingId'] ?? '';
        if ($vinculada) {
            $bd->updateOne('compliance_trainings', ['_id' => $vinculada], $limpiar);
        }
        $bd->updateOne('compliance_trainings', ['inviteId' => $id], $limpiar);
        $bd->updateOne($coleccion, ['_id' => $id], [
            'assignedTrainingId'   => null,
            'assignedTrainingName' => null,
            'assignedAt'           => null,
        ]);
        \json_response(['success' => true]);
    }
}