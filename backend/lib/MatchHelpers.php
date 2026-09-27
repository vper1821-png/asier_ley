<?php
// backend/lib/MatchHelpers.php
// Helpers de normalización de rutas para el motor de plantillas.

if (!class_exists('MatchHelpers')) {

class MatchHelpers
{
    public static function normalizePath($p): string
    {
        return str_replace('\\', '/', (string)$p);
    }

    public static function stripAccents($s): string
    {
        static $map = [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
            'À'=>'A','È'=>'E','Ì'=>'I','Ò'=>'O','Ù'=>'U',
        ];
        return strtr((string)$s, $map);
    }
}

if (!function_exists('normalizePathForMatch')) {
    function normalizePathForMatch($p) { return MatchHelpers::normalizePath($p); }
}
if (!function_exists('stripAccentsForMatch')) {
    function stripAccentsForMatch($s) { return MatchHelpers::stripAccents($s); }
}

}