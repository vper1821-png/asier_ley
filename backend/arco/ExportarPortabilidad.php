<?php
// backend/arco/ExportarPortabilidad.php
// GET /api/arco/requests/export-portabilidad

namespace Arco;

class ExportarPortabilidad
{
    public static function ejecutar()
    {
        $usuario   = \Auth::requireAuth();
        $requestId = $_GET['requestId'] ?? ($_GET['id'] ?? '');
        if (!$requestId) \json_error('requestId requerido');

        $bd = \Database::getInstance();
        $req = $bd->findOne('arco_requests', ['requestId' => $requestId]);
        if (!$req) \json_error('solicitud no encontrada', 404);

        $req = \BsonHelpers::toArray($req);

        if (!AlcanceArco::puedeAcceder($usuario, $bd, $req)) {
            \json_error('acceso denegado', 403);
        }

        $formato = strtolower($_GET['format'] ?? 'json');
        $uid     = $req['companyId'] ?? $usuario['_id'];

        // Solicitante: array plano
        $solicitante = $req['solicitante'] ?? [];
        if (is_string($solicitante)) $solicitante = json_decode($solicitante, true) ?: [];
        if (!is_array($solicitante))  $solicitante = [];

        $email = $solicitante['email'] ?? ($req['email'] ?? '');
        $rut   = $solicitante['rut']   ?? ($req['rut']   ?? '');
        $nombre= $solicitante['nombre']?? ($req['name']  ?? '');

        $datos = [
            'solicitante' => [
                'nombre' => $nombre,
                'rut'    => $rut,
                'email'  => $email,
            ],
            'consentimientos' => $bd->find('compliance_consents', [
                'userId' => $uid,
                '$or'    => [
                    ['email' => $email],
                    ['rut'   => $rut],
                ],
            ]),
            'arco_requests' => $bd->find('arco_requests', [
                'companyId' => $uid,
                '$or' => [
                    ['solicitante.email' => $email],
                    ['solicitante.rut'   => $rut],
                ],
            ]),
            'inventario' => $bd->find('compliance_inventory', ['userId' => $uid]),
            'brechas'    => $bd->find('compliance_breaches', [
                'userId' => $uid,
                '$or' => [
                    ['affectedEmail' => $email],
                    ['affectedRut'   => $rut],
                ],
            ]),
            'capacitaciones' => $bd->find('compliance_trainings', [
                'userId'        => $uid,
                'employeeEmail' => $email,
            ]),
        ];

        if ($formato === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="portabilidad_' . $requestId . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Colección', 'Campo', 'Valor']);
            foreach ($datos as $coleccion => $items) {
                foreach ($items as $item) {
                    foreach ($item as $k => $v) {
                        if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                        fputcsv($out, [$coleccion, $k, $v]);
                    }
                }
            }
            fclose($out);
            exit;
        }

        // JSON por defecto
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="portabilidad_' . $requestId . '.json"');
        echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}