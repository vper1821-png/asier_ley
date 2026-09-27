<?php
namespace Cumplimiento;

class PanelDpo
{
    /** POST /api/compliance/revocations/pending */
    public static function listarRevocacionesPendientes()
    {
        $usuario = \Auth::requireAuth();
        if (!self::esDpoAutorizado($usuario)) \json_error('solo el DPO puede revisar revocaciones', 403);

        $bd = \Database::getInstance();
        $userIds = self::userIdsEmpresa($usuario, $bd);

        $items = $bd->find('compliance_consents', [
            'userId'           => ['$in' => $userIds],
            'revokedAt'        => ['$ne' => null],
            'revocationStatus' => 'pending_review',
        ], ['limit' => 500]);

        \json_response(['items' => $items]);
    }

    /** POST /api/compliance/revocations/review */
    public static function revisarRevocacion()
    {
        $usuario = \Auth::requireAuth();
        if (!self::esDpoAutorizado($usuario)) \json_error('solo el DPO puede revisar revocaciones', 403);

        $cuerpo = \get_body();
        $consentId   = $cuerpo['consentId'] ?? '';
        $efecto      = $cuerpo['effect']    ?? '';
        $notas       = trim((string)($cuerpo['notes'] ?? ''));
        $otrasBases  = $cuerpo['otherLegalBases'] ?? [];

        if (!$consentId) \json_error('consentId requerido');
        if (!in_array($efecto, ['cessation', 'partial'], true)) {
            \json_error('effect debe ser cessation o partial');
        }
        if ($efecto === 'partial' && empty($otrasBases)) {
            \json_error('debes indicar qué otras bases legales aplican');
        }

        $bd = \Database::getInstance();
        $consent = $bd->findOne('compliance_consents', ['_id' => $consentId]);
        if (!$consent) \json_error('consentimiento no encontrado', 404);

        $usuarioRec = $bd->findOne('users', ['_id' => $usuario['_id']]);
        $nombreRevisor = $usuarioRec['name'] ?? ($usuario['email'] ?? 'DPO');

        $bd->updateOne('compliance_consents', ['_id' => $consentId], [
            'revocationStatus' => 'confirmed',
            'revocationEffect' => $efecto,
            'revocationNotes'  => $notas,
            'otherLegalBases'  => $otrasBases,
            'reviewedAt'       => date('c'),
            'reviewedBy'       => (string)$usuario['_id'],
            'reviewedByName'   => $nombreRevisor,
        ]);

        \NotificationService::queue($bd, [
            'companyId'     => $consent['userId'] ?? '',
            'channel'       => 'email',
            'recipient'     => $consent['email'] ?? '',
            'recipientType' => 'titular',
            'templateCode'  => 'revocation_reviewed',
            'variables'     => [
                'purpose' => $consent['purpose'] ?? '',
                'effect'  => $efecto === 'cessation' ? 'cese total' : 'cese parcial',
                'notes'   => $notas,
            ],
        ]);

        \audit_log('revocation_reviewed', ['consentId' => $consentId, 'effect' => $efecto], $usuario['_id']);
        \json_response(['success' => true]);
    }

    /** POST /api/compliance/identity/pending */
    public static function listarIdentidadPendiente()
    {
        $usuario = \Auth::requireAuth();
        if (!self::esDpoAutorizado($usuario)) \json_error('solo el DPO puede ver estas solicitudes', 403);

        $bd = \Database::getInstance();
        $usuarioRec = $bd->findOne('users', ['_id' => $usuario['_id']]);
        $companyId  = $usuarioRec['companyId'] ?? $usuario['_id'];

        $items = $bd->find('arco_requests', [
            'companyId' => $companyId,
            'source'    => 'concrete_data',
            'status'    => ['$in' => ['pending_identity','pending_validation','in_progress']],
        ], ['limit' => 200]);

        foreach ($items as &$it) {
            if (!empty($it['identityDocuments'])) {
                foreach ($it['identityDocuments'] as $k => $doc) {
                    $it['identityDocuments'][$k]['path'] = basename($doc['path'] ?? '');
                }
            }
        }
        unset($it);

        \json_response(['items' => $items]);
    }

    /** POST /api/compliance/identity/verify */
    public static function verificarIdentidad()
    {
        $usuario = \Auth::requireAuth();
        if (!self::esDpoAutorizado($usuario)) \json_error('solo el DPO puede validar identidad', 403);

        $cuerpo = \get_body();
        $requestId = $cuerpo['requestId'] ?? '';
        $accion    = $cuerpo['action']    ?? 'approve';
        $motivo    = trim((string)($cuerpo['reason'] ?? ''));

        if (!$requestId) \json_error('requestId requerido');
        if (!in_array($accion, ['approve', 'reject'], true)) \json_error('action inválido');

        $bd = \Database::getInstance();
        $req = $bd->findOne('arco_requests', ['requestId' => $requestId, 'source' => 'concrete_data']);
        if (!$req) \json_error('solicitud no encontrada', 404);

        $ahora = date('c');

        if ($accion === 'reject') {
            $bd->updateOne('arco_requests', ['_id' => $req['_id']], [
                'identityStatus'       => 'rejected',
                'identityRejectedAt'   => $ahora,
                'identityRejectReason' => $motivo,
                'status'               => 'pending_identity',
                'updatedAt'            => $ahora,
            ]);

            \NotificationService::queue($bd, [
                'companyId'     => $req['companyId'] ?? '',
                'channel'       => 'email',
                'recipient'     => $req['solicitante']['email'] ?? '',
                'recipientType' => 'titular',
                'templateCode'  => 'identity_rejected',
                'variables'     => ['requestId' => $requestId, 'reason' => $motivo],
            ]);

            \json_response(['success' => true, 'status' => 'rejected']);
        }

        $bd->updateOne('arco_requests', ['_id' => $req['_id']], [
            'identityStatus'     => 'verified',
            'identityVerifiedAt' => $ahora,
            'identityVerifiedBy' => (string)$usuario['_id'],
            'status'             => 'in_progress',
            'updatedAt'          => $ahora,
        ]);

        \json_response(['success' => true, 'status' => 'verified']);
    }

    /** POST /api/compliance/identity/deliver */
    public static function entregarDatos()
    {
        $usuario = \Auth::requireAuth();
        if (!self::esDpoAutorizado($usuario)) \json_error('solo el DPO puede entregar datos', 403);

        $cuerpo = \get_body();
        $requestId = $cuerpo['requestId'] ?? '';
        $respuesta = trim((string)($cuerpo['response'] ?? ''));
        if (!$requestId) \json_error('requestId requerido');

        $bd = \Database::getInstance();
        $req = $bd->findOne('arco_requests', ['requestId' => $requestId, 'source' => 'concrete_data']);
        if (!$req) \json_error('solicitud no encontrada', 404);
        if (($req['identityStatus'] ?? '') !== 'verified') {
            \json_error('la identidad debe estar verificada primero', 400);
        }

        $tokenEntrega  = bin2hex(random_bytes(32));
        $hashToken     = hash('sha256', $tokenEntrega);
        $expiraEn      = date('c', time() + PORTAL_DELIVERY_TTL_HOURS * 3600);
        $ahora         = date('c');

        $bd->updateOne('arco_requests', ['_id' => $req['_id']], [
            'status'            => 'completed',
            'response'          => $respuesta ?: 'Datos personales entregados conforme al Art. 8 Ley 21.719.',
            'deliveryTokenHash' => $hashToken,
            'deliveryExpiresAt' => $expiraEn,
            'deliveryUrl'       => '/api/public/portal/deliver?token=' . urlencode($tokenEntrega),
            'resolvedAt'        => $ahora,
            'resolvedBy'        => (string)$usuario['_id'],
            'updatedAt'         => $ahora,
            'statusHistory'     => array_merge($req['statusHistory'] ?? [], [[
                'at'     => $ahora,
                'by'     => $usuario['email'] ?? 'DPO',
                'status' => 'completed',
                'kind'   => 'delivered',
                'note'   => 'Datos entregados por enlace temporal con vigencia de ' . PORTAL_DELIVERY_TTL_HOURS . ' horas.',
            ]]),
        ]);

        \NotificationService::queue($bd, [
            'companyId'     => $req['companyId'] ?? '',
            'channel'       => 'email',
            'recipient'     => $req['solicitante']['email'] ?? '',
            'recipientType' => 'titular',
            'templateCode'  => 'concrete_data_delivered',
            'variables'     => [
                'requestId'    => $requestId,
                'deliveryUrl'  => API_BASE_URL . '/api/public/portal/deliver?token=' . urlencode($tokenEntrega),
                'expiresHours' => PORTAL_DELIVERY_TTL_HOURS,
            ],
        ]);

        \audit_log('concrete_data_delivered', ['requestId' => $requestId], $usuario['_id']);

        \json_response([
            'success'     => true,
            'deliveryUrl' => API_BASE_URL . '/api/public/portal/deliver?token=' . urlencode($tokenEntrega),
            'expiresAt'   => $expiraEn,
        ]);
    }

    // ─── Helpers ───
    private static function esDpoAutorizado($usuario): bool
    {
        $rol = strtolower($usuario['role'] ?? '');
        return in_array($rol, ['dpo','dpd','admin','superadmin'], true);
    }

    private static function userIdsEmpresa($usuario, $bd): array
    {
        $rec = $bd->findOne('users', ['_id' => $usuario['_id']]);
        $companyId = $rec['companyId'] ?? $usuario['_id'];
        $subs = $bd->find('users', ['companyId' => $companyId]);
        $ids = array_map('strval', array_column($subs, '_id'));
        if (empty($ids)) $ids = [(string)$usuario['_id']];
        return $ids;
    }
}