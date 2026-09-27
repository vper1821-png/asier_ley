<?php
// backend/agentes/ConexionesDb.php

namespace Agentes;

class ConexionesDb
{
    /** GET /api/agents/{id}/db-connections */
    public static function listar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $_GET['agentId'] ?? ($_GET['id'] ?? ($cuerpo['agentId'] ?? ''));
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd = \Database::getInstance();
        $conns = $bd->find('agent_db_connections', ['agentId' => $agentId]);
        \json_response(['success' => true, 'connections' => $conns]);
    }

    /** POST /api/agents/{id}/db-connection */
    public static function crear()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $cuerpo['agentId'] ?? ($_GET['id'] ?? '');
        if (!$agentId) \json_error('agentId requerido');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $conn = [
            'userId'    => $usuario['_id'],
            'agentId'   => $agentId,
            'engine'    => $cuerpo['engine']   ?? '',
            'host'      => $cuerpo['host']     ?? '',
            'port'      => (int)($cuerpo['port'] ?? 0),
            'database'  => $cuerpo['database'] ?? '',
            'username'  => $cuerpo['username'] ?? '',
            'password'  => $cuerpo['password'] ?? '',
            'ssl'       => (bool)($cuerpo['ssl'] ?? false),
            'enabled'   => true,
            'createdAt' => date('c'),
        ];

        if (!$conn['engine'] || !$conn['host'] || !$conn['port'] || !$conn['database'] || !$conn['username']) {
            \json_error('Todos los campos son requeridos: engine, host, port, database, username');
        }
        $valid = ['mssql', 'postgres', 'mysql', 'mongodb', 'redis', 'sqlite'];
        if (!in_array($conn['engine'], $valid, true)) {
            \json_error('Engine no soportado. Validos: ' . implode(', ', $valid));
        }

        $bd = \Database::getInstance();
        $connId = $bd->insertOne('agent_db_connections', $conn);
        \json_response(['success' => true, 'connectionId' => $connId, 'connection' => $conn]);
    }

    /** POST /api/agents/{id}/db-connection/delete */
    public static function borrar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $agentId = $cuerpo['agentId'] ?? ($_GET['id'] ?? '');
        $connId  = $cuerpo['connectionId'] ?? '';
        if (!$agentId || !$connId) \json_error('agentId y connectionId requeridos');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd = \Database::getInstance();
        $bd->deleteOne('agent_db_connections', ['_id' => $connId, 'agentId' => $agentId]);
        \json_response(['success' => true]);
    }

    /** POST /api/agents/{id}/db-connection/test */
    public static function test()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $connId  = $cuerpo['connectionId'] ?? '';
        $agentId = $cuerpo['agentId'] ?? ($_GET['id'] ?? '');
        if (!$connId || !$agentId) \json_error('connectionId y agentId requeridos');

        $agente = AlcanceAgente::buscar($usuario, $agentId);
        if (!$agente) \json_error('agente no encontrado', 404);

        $bd = \Database::getInstance();
        $conn = $bd->findOne('agent_db_connections', ['_id' => $connId, 'agentId' => $agentId]);
        if (!$conn) \json_error('conexión no encontrada', 404);

        $resultado = self::probarConexion($conn);
        \json_response(['success' => $resultado['success'], 'message' => $resultado['message']]);
    }

    private static function probarConexion($conn): array
    {
        try {
            switch ($conn['engine']) {
                case 'mssql':
                    $dsn = sprintf(
                        "sqlserver://%s:%s@%s:%d?database=%s&connection+timeout=5",
                        $conn['username'], $conn['password'], $conn['host'], $conn['port'], $conn['database']
                    );
                    $pdo = new \PDO($dsn);
                    break;
                case 'postgres':
                    $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s", $conn['host'], $conn['port'], $conn['database']);
                    $pdo = new \PDO($dsn, $conn['username'], $conn['password']);
                    break;
                case 'mysql':
                    $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s", $conn['host'], $conn['port'], $conn['database']);
                    $pdo = new \PDO($dsn, $conn['username'], $conn['password']);
                    break;
                case 'mongodb':
                    $mongo = new \MongoDB\Client(sprintf(
                        "mongodb://%s:%s@%s:%d",
                        $conn['username'], $conn['password'], $conn['host'], $conn['port']
                    ));
                    $mongo->selectDatabase($conn['database'])->command(['ping' => 1]);
                    return ['success' => true, 'message' => 'Conexión exitosa'];
                default:
                    return ['success' => false, 'message' => 'Engine no soportado para test'];
            }
            $pdo->query('SELECT 1');
            return ['success' => true, 'message' => 'Conexión exitosa'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}