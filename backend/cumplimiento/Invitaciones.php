<?php
namespace Cumplimiento;

class Invitaciones
{
    /** POST /api/compliance/verify-invite */
    public static function verificar()
    {
        $cuerpo = \get_body();
        $token = $cuerpo['token'] ?? '';
        if (!$token) \json_error('token requerido');

        $bd = \Database::getInstance();
        $inv = $bd->findOne('compliance_invites', ['token' => $token]);
        if (!$inv) \json_error('invitación no encontrada');
        if (!empty($inv['signed'])) \json_error('documento ya firmado');

        \json_response([
            'title'       => $inv['title']       ?? 'Documento de Compliance',
            'description' => $inv['description'] ?? '',
            'companyName' => $inv['companyName'] ?? '',
        ]);
    }

    /** POST /api/compliance/sign */
    public static function firmar()
    {
        $cuerpo = \get_body();
        $token     = $cuerpo['inviteToken'] ?? '';
        $firma     = $cuerpo['signature']   ?? '';
        $nombre    = $cuerpo['name']        ?? '';

        if (!$token || !$firma) \json_error('datos requeridos');

        $bd = \Database::getInstance();
        $inv = $bd->findOne('compliance_invites', ['token' => $token]);
        if (!$inv) \json_error('invitación no encontrada');
        if (!empty($inv['signed'])) \json_error('documento ya firmado');

        $bd->updateOne('compliance_invites', ['token' => $token], [
            'signed'        => true,
            'signature'     => $firma,
            'signatureType' => str_starts_with($firma, 'data:image/') ? 'image' : 'text',
            'signerName'    => $nombre,
            'signedAt'      => date('c'),
        ]);

        \json_response(['success' => true]);
    }

    /** Rutas públicas /api/compliance/public/invites/{token}[/submit] */
    public static function rutaPublica(string $token, string $accion, string $metodo, array $cuerpo, $bd)
    {
        if (!$token) \json_error('token requerido');
        $inv = $bd->findOne('compliance_invites', ['token' => $token]);
        if (!$inv) \json_error('invitación no encontrada', 404);

        if ($accion === 'submit' && $metodo === 'POST') {
            if (!empty($inv['signed'])) \json_error('documento ya firmado');

            $bd->updateOne('compliance_invites', ['token' => $token], [
                'signed'        => true,
                'signature'     => $cuerpo['signature']  ?? '',
                'signatureType' => str_starts_with($cuerpo['signature'] ?? '', 'data:image/') ? 'image' : 'text',
                'signerName'    => $cuerpo['name']  ?? ($cuerpo['signerName'] ?? ''),
                'signerEmail'   => $cuerpo['email'] ?? '',
                'signedAt'      => date('c'),
            ]);
            \json_response(['success' => true]);
        }

        \json_response([
            'title'       => $inv['title']       ?? 'Documento de Compliance',
            'description' => $inv['description'] ?? '',
            'companyName' => $inv['companyName'] ?? '',
        ]);
    }
}