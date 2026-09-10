<?php
// Compliant Companies routes

/**
 * Búsqueda pública de empresas cumplidoras.
 * Devuelve UNA fila por empresa (deduplicada por companyId),
 * no una fila por usuario/subusuario.
 */
function search() {
    $search = $_GET['q'] ?? $_GET['search'] ?? ($_POST['q'] ?? ($_POST['search'] ?? ''));
    $search = trim((string)$search);
    $db = Database::getInstance();

    $results = [];

    // ─── Intento 1: usar aggregate (más eficiente, dedupe en Mongo) ───
    try {
        $match = ['isActive' => true];
        if ($search !== '') {
            $match['$or'] = [
                ['companyName' => ['$regex' => $search, '$options' => 'i']],
                ['email'       => ['$regex' => $search, '$options' => 'i']],
                ['companyRut'  => ['$regex' => $search, '$options' => 'i']],
                ['razonSocial' => ['$regex' => $search, '$options' => 'i']],
            ];
        }

        $pipeline = [
            ['$match' => $match],
            // Agrupar por companyId; si no existe, usar _id del propio user
            [
                '$group' => [
                    '_id'         => ['$ifNull' => ['$companyId', '$_id']],
                    'companyName' => ['$first' => '$companyName'],
                    'razonSocial' => ['$first' => '$razonSocial'],
                    'name'        => ['$first' => '$name'],
                    'email'       => ['$first' => '$email'],
                    'domain'      => ['$first' => '$domain'],
                    'companyRut'  => ['$first' => '$companyRut'],
                ]
            ],
            ['$sort' => ['companyName' => 1, 'name' => 1]],
            ['$limit' => 50],
        ];

        $rows = $db->aggregate('users', $pipeline);

        foreach ($rows as $r) {
            if (is_object($r)) {
                $r = json_decode(json_encode($r), true) ?: [];
            }
            $companyId = (string)($r['_id'] ?? '');
            if ($companyId === '') continue;

            $name = $r['companyName'] ?? ($r['razonSocial'] ?? ($r['name'] ?? ''));
            if ($name === '') continue;

            $results[] = [
                '_id'         => $companyId,
                'id'          => $companyId,
                'name'        => $name,
                'companyName' => $name,
                'email'       => $r['email']      ?? '',
                'domain'      => $r['domain']     ?? '',
                'companyRut'  => $r['companyRut'] ?? '',
            ];
        }

        if (!empty($results)) {
            json_response($results);
        }
    } catch (\Throwable $e) {
        // Si aggregate falla (fallback a JSON storage, por ejemplo), caemos al método clásico
        error_log('[compliantCompanies.search] aggregate falló: ' . $e->getMessage());
    }

    // ─── Fallback: find + dedupe en PHP ───
    $users = $db->find('users', ['isActive' => true]);
    $seen = [];

    foreach ($users as $c) {
        if (is_object($c)) {
            $c = json_decode(json_encode($c), true) ?: [];
        }

        $companyName = (string)($c['companyName'] ?? ($c['razonSocial'] ?? ($c['name'] ?? '')));
        $email       = (string)($c['email'] ?? '');

        // Filtro por texto (si hay)
        if ($search !== ''
            && stripos($companyName, $search) === false
            && stripos($email, $search) === false) {
            continue;
        }

        // Clave única = companyId (con fallback al _id del user)
        $key = (string)($c['companyId'] ?? ($c['_id'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            continue; // ya lo agregamos
        }
        $seen[$key] = true;

        $results[] = [
            '_id'         => $key,
            'id'          => $key,
            'name'        => $companyName,
            'companyName' => $companyName,
            'email'       => $email,
            'domain'      => (string)($c['domain'] ?? ''),
            'companyRut'  => (string)($c['companyRut'] ?? ''),
        ];
    }

    json_response($results);
}