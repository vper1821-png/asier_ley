<?php
// backend/agentes/Carpetas.php

namespace Agentes;

class Carpetas
{
    /** POST /api/folders/list */
    public static function listar()
    {
        $usuario = \Auth::requireAuth();
        $bd = \Database::getInstance();
        $folders = $bd->find('folders', ['userId' => $usuario['_id']]);
        usort($folders, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        \json_response($folders);
    }

    /** POST /api/folders/create */
    public static function crear()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $nombre = trim($cuerpo['name'] ?? '');
        if (!$nombre) \json_error('nombre requerido');

        $bd = \Database::getInstance();
        $existente = $bd->findOne('folders', ['userId' => $usuario['_id'], 'name' => $nombre]);
        if ($existente) \json_error('la carpeta ya existe');

        $bd->insertOne('folders', [
            'userId'    => $usuario['_id'],
            'name'      => $nombre,
            'createdAt' => date('c'),
        ]);
        \json_response(['success' => true]);
    }

    /** POST /api/folders/delete */
    public static function borrar()
    {
        $usuario = \Auth::requireAuth();
        $cuerpo = \get_body();
        $nombre = trim($cuerpo['name'] ?? '');
        if (!$nombre) \json_error('nombre requerido');

        $bd = \Database::getInstance();
        $bd->deleteOne('folders', ['userId' => $usuario['_id'], 'name' => $nombre]);

        $agentes = $bd->find('agents', ['userId' => $usuario['_id'], 'group' => $nombre]);
        foreach ($agentes as $a) {
            $bd->updateOne('agents', ['_id' => $a['_id']], ['group' => '']);
        }
        \json_response(['success' => true]);
    }
}