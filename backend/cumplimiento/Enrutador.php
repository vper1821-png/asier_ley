<?php
namespace Cumplimiento;

class Enrutador
{
    public static function despachar()
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $prefijo = '/api/invisia/compliance/';
        if (strpos($uri, $prefijo) !== 0) $prefijo = '/api/compliance/';
        $path = trim(substr($uri, strlen($prefijo)), '/');
        $segmentos = explode('/', $path);
        $metodo = $_SERVER['REQUEST_METHOD'];
        $cuerpo = \get_body();

        if (empty($segmentos[0])) \json_error('ruta inválida');

        $recurso = $segmentos[0];
        $id      = $segmentos[1] ?? '';
        $accion  = $segmentos[2] ?? '';
        if ($id === 'pdf' && $accion === '') { $accion = 'pdf'; $id = ''; }

        $bd = \Database::getInstance();

        // ═══ Rutas públicas (sin auth) ═══
        if ($recurso === 'public' && ($segmentos[1] ?? '') === 'invites') {
            return Invitaciones::rutaPublica($segmentos[2] ?? '', $segmentos[3] ?? '', $metodo, $cuerpo, $bd);
        }
        if ($recurso === 'portability' && $id === 'export') {
            return Exportaciones::portabilidad($cuerpo, $bd);
        }
        if ($recurso === 'transfer-validation') {
            return Exportaciones::validarTransferencia($cuerpo);
        }
        if ($recurso === 'companies' && $id === 'search') {
            return Exportaciones::buscarEmpresas($cuerpo, $bd);
        }

        // ═══ A partir de aquí, requiere auth ═══
        $usuario = \Auth::requireAuth();
        $userIds = Alcance::idsDeEmpresa($usuario, $bd);
        $esSuper = ($userIds === null);

        // ═══ Recursos con handler propio ═══
        switch ($recurso) {
            case 'config':
                return $metodo === 'GET' ? Configuracion::obtener() : Configuracion::actualizar();

            case 'overview':
            case 'stats':
                return self::resumen($usuario, $bd, $esSuper, $userIds);

            case 'ropa-export':
                return Exportaciones::ropa($bd);

            case 'labor-clause':
                return Exportaciones::clausulaLaboral();

            case 'breach-protocol':
                return ProtocoloBrechas::manejar($usuario, $bd, $metodo, $cuerpo);

            case 'incident-response':
                return PlanIncidentes::manejar($usuario, $bd, $metodo, $cuerpo);

            case 'arco-requests':
                return ArcoCrud::manejar($usuario, $bd, $metodo, $id, $accion, $cuerpo);

            case 'inventory':
                $res = Inventario::manejar($usuario, $bd, $userIds, $esSuper, $metodo, $id, $accion, $cuerpo);
                if ($res !== false) return $res;
                break; // si no era un comando especial, cae al genérico

            case 'checklist':
                return Checklist::manejar($usuario, $bd, $userIds, $esSuper, $metodo, $id, $cuerpo);
        }

        // ═══ PDFs ═══
        $recursoNorm = str_replace('_', '-', $recurso);
        $pdfRecursos = [
            'consents','inventory','breaches','trainings','pseudonymization',
            'arco-requests','arco','incident_response','breach_protocol',
            'apdp','privacy','dpd','incident-response','breach-protocol','dpia',
        ];
        if ($accion === 'pdf' && in_array($recursoNorm, $pdfRecursos, true)) {
            return PdfCompliance::generar($recursoNorm);
        }

        // ═══ CRUD genérico ═══
        return CrudGenerico::manejar($recurso, $id, $accion, $metodo, $cuerpo, $usuario, $bd, $esSuper, $userIds);
    }

    private static function resumen($usuario, $bd, bool $esSuper, ?array $userIds)
    {
        $filtro = $esSuper ? [] : ['userId' => ['$in' => $userIds]];
        $data = [
            'consents'         => $bd->count('compliance_consents', $filtro),
            'inventory'        => $bd->count('compliance_inventory', $filtro),
            'breaches'         => $bd->count('compliance_breaches', $filtro),
            'templates'        => $bd->count('compliance_templates', $filtro),
            'trainings'        => $bd->count('compliance_trainings', $filtro),
            'dpia'             => $bd->count('compliance_dpia', $filtro),
            'dpa'              => $bd->count('compliance_dpa', $filtro),
            'pseudonymization' => $bd->count('compliance_pseudonymization', $filtro),
            'processors'       => $bd->count('compliance_processors', $filtro),
            'transfers'        => $bd->count('compliance_transfers', $filtro),
        ];
        \json_response(['success' => true, 'overview' => $data]);
    }
}