<?php
// backend/lib/Bson.php
// Helpers universales para convertir BSON ↔ PHP de forma segura.
// Centraliza lo que antes estaba duplicado en compliance.php, arco.php y public_portal.php.

if (!class_exists('BsonHelpers')) {

class BsonHelpers
{
    /** Convierte cualquier valor a string legible. */
    public static function str($v): string
    {
        if ($v === null) return '';
        if (is_scalar($v)) return (string)$v;
        if ($v instanceof \MongoDB\Model\BSONDocument) $v = $v->getArrayCopy();
        if ($v instanceof \MongoDB\Model\BSONArray)      $v = $v->getArrayCopy();
        if (is_array($v)) {
            $parts = [];
            foreach ($v as $x) {
                if (is_scalar($x)) $parts[] = (string)$x;
            }
            return implode(', ', $parts);
        }
        if (is_object($v) && method_exists($v, 'getArrayCopy')) {
            return self::str($v->getArrayCopy());
        }
        return '';
    }

    /** Convierte cualquier valor a array plano de escalares. */
    public static function arr($v): array
    {
        if ($v === null) return [];
        if (is_array($v)) return array_values($v);
        if ($v instanceof \MongoDB\Model\BSONDocument) return array_values($v->getArrayCopy());
        if ($v instanceof \MongoDB\Model\BSONArray)      return array_values($v->getArrayCopy());
        if (is_object($v) && method_exists($v, 'getArrayCopy')) {
            return array_values($v->getArrayCopy());
        }
        if (is_string($v)) {
            return array_values(array_filter(
                array_map('trim', explode(',', $v)),
                fn($x) => $x !== ''
            ));
        }
        if (is_scalar($v)) return [(string)$v];
        return [];
    }

    /** Escapa para HTML. */
    public static function h($s): string
    {
        return htmlspecialchars(self::str($s), ENT_QUOTES, 'UTF-8');
    }

    /** Convierte recursivamente BSON a array PHP. */
    public static function toArray($v)
    {
        if ($v === null) return null;
        if ($v instanceof \MongoDB\Model\BSONDocument
         || $v instanceof \MongoDB\Model\BSONArray) {
            $v = $v->getArrayCopy();
        } elseif ($v instanceof \Traversable) {
            $v = iterator_to_array($v);
        } elseif (is_object($v)) {
            $v = get_object_vars($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) $out[$k] = self::toArray($item);
            return $out;
        }
        return $v;
    }
}

// ─── Aliases de compatibilidad (borrar cuando todo esté migrado) ───
if (!function_exists('_pub_bson_str')) function _pub_bson_str($v): string { return BsonHelpers::str($v); }
if (!function_exists('_pub_bson_arr')) function _pub_bson_arr($v): array  { return BsonHelpers::arr($v); }
if (!function_exists('_pub_h'))        function _pub_h($s): string        { return BsonHelpers::h($s); }
if (!function_exists('arcoToArray'))   function arcoToArray($v)           { return BsonHelpers::toArray($v); }

}