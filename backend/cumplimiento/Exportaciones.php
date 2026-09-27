<?php
namespace Cumplimiento;

class Exportaciones
{
    /** GET /api/compliance/ropa-export */
    public static function ropa($bd)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ropa-export.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Recurso', 'Registros']);
        $colecciones = [
            'compliance_consents','compliance_inventory','compliance_breaches',
            'compliance_templates','compliance_trainings','compliance_dpia',
            'compliance_dpa','compliance_pseudonymization','compliance_processors',
            'compliance_transfers',
        ];
        foreach ($colecciones as $c) fputcsv($out, [$c, $bd->count($c)]);
        fclose($out);
        exit;
    }

    /** GET /api/compliance/labor-clause */
    public static function clausulaLaboral()
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="labor-clause.pdf"');
        echo "PDF de cláusula laboral en construcción.";
        exit;
    }

    /** POST /api/compliance/portability/export */
    public static function portabilidad(array $cuerpo, $bd)
    {
        $email  = strtolower(trim($cuerpo['titularEmail'] ?? ''));
        $formato = $cuerpo['format'] ?? 'json';
        if (!$email) \json_error('titularEmail requerido');

        $usuario = $bd->findOne('users', ['email' => $email]);
        if (!$usuario) \json_error('titular no encontrado', 404);
        unset($usuario['password']);

        $datos = [
            'titular'      => $usuario,
            'alerts'       => $bd->find('alerts', ['userId' => $usuario['_id']]),
            'payments'     => $bd->find('payments', ['userId' => $usuario['_id']]),
            'arcoRequests' => $bd->find('arco_requests', ['companyId' => $usuario['_id']]),
        ];

        if ($formato === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="portabilidad.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Campo', 'Valor']);
            fputcsv($out, ['titular',  json_encode($datos['titular'])]);
            fputcsv($out, ['alerts',   json_encode($datos['alerts'])]);
            fputcsv($out, ['payments', json_encode($datos['payments'])]);
            fclose($out);
            exit;
        }

        \json_response(['success' => true, 'data' => $datos]);
    }

    /** POST /api/compliance/transfer-validation */
    public static function validarTransferencia(array $cuerpo)
    {
        $pais = $cuerpo['country'] ?? '';
        $adecuado = in_array(strtolower($pais), [
            'andorra','argentina','canada','faeroe islands','guernsey','israel',
            'isle of man','jersey','new zealand','republic of korea','switzerland',
            'united kingdom','uruguay','usa',
        ], true);

        \json_response([
            'allowed'    => $adecuado,
            'adequacy'   => $adecuado,
            'safeguards' => $adecuado ? 'decisión de adecuación' : 'garantías adicionales necesarias',
            'message'    => $adecuado
                ? 'Transferencia permitida'
                : 'Se requieren garantías suplementarias para transferir datos',
        ]);
    }

    /** POST /api/compliance/companies/search */
    public static function buscarEmpresas(array $cuerpo, $bd)
    {
        $query = strtolower(trim($cuerpo['q'] ?? ''));
        if (strlen($query) < 2) \json_response(['companies' => []]);

        $configs = $bd->find('compliance_config', []);
        $resultados = [];
        $vistos = [];

        foreach ($configs as $cfg) {
            $nombre = strtolower($cfg['companyName'] ?? '');
            if (str_contains($nombre, $query)) {
                $cid = (string)($cfg['userId'] ?? '');
                if ($cid === '' || isset($vistos[$cid])) continue;
                $vistos[$cid] = true;
                $resultados[] = [
                    '_id'   => $cid,
                    'name'  => $cfg['companyName'] ?? '',
                    'email' => $cfg['dpdEmail']    ?? '',
                    'city'  => $cfg['city']        ?? '',
                ];
            }
        }

        $usuarios = $bd->find('users', []);
        foreach ($usuarios as $u) {
            $nombre = strtolower($u['companyName'] ?? '');
            if (!str_contains($nombre, $query)) continue;
            $cid = (string)($u['_id'] ?? '');
            if ($cid === '' || isset($vistos[$cid])) continue;
            $vistos[$cid] = true;
            $resultados[] = [
                '_id'   => $cid,
                'name'  => $u['companyName'] ?? '',
                'email' => $u['email']       ?? '',
                'city'  => $u['city']        ?? '',
            ];
        }

        \json_response(['companies' => array_slice($resultados, 0, 10)]);
    }
}