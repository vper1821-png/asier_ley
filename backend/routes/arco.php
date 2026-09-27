<?php
// backend/routes/arco.php
// ═══════════════════════════════════════════════════════════════
// Entrypoint delgado. Mantiene las firmas de funciones globales
// que index.php invoca directamente. Toda la lógica vive en
// backend/arco/*.php
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/Tenant.php';
require_once __DIR__ . '/../lib/Bson.php';
require_once __DIR__ . '/../lib/MatchHelpers.php';

$base = __DIR__ . '/../arco/';
require_once $base . 'AlcanceArco.php';
require_once $base . 'Crear.php';
require_once $base . 'Listar.php';
require_once $base . 'Actualizar.php';
require_once $base . 'GenerarRespuesta.php';
require_once $base . 'Seguimiento.php';
require_once $base . 'ExportarPortabilidad.php';
require_once $base . 'PdfRespuesta.php';
require_once $base . 'PdfComprobante.php';

// ═══════════════════════════════════════════════════════════════
// Endpoints expuestos como funciones globales
// (compatibilidad con index.php)
// ═══════════════════════════════════════════════════════════════

function create()             { \Arco\Crear::ejecutar(); }
function listRequests()       { \Arco\Listar::ejecutar(); }
function updateRequest()      { \Arco\Actualizar::ejecutar(); }
function generateResponse()   { \Arco\GenerarRespuesta::ejecutar(); }
function track()              { \Arco\Seguimiento::ejecutar(); }
function exportPortabilidad() { \Arco\ExportarPortabilidad::ejecutar(); }
function downloadResponse()   { \Arco\PdfRespuesta::ejecutar(); }
function downloadReceipt()    { \Arco\PdfComprobante::ejecutar(); }

// ═══════════════════════════════════════════════════════════════
// Aliases de compatibilidad (por si algún otro archivo usa los
// helpers originales directamente)
// ═══════════════════════════════════════════════════════════════

if (!function_exists('arcoToArray')) {
    function arcoToArray($v) { return \BsonHelpers::toArray($v); }
}

if (!function_exists('arcoIdConditions')) {
    function arcoIdConditions($campo, $id) {
        return \Arco\AlcanceArco::idConditions($campo, $id);
    }
}

if (!function_exists('arcoCompanyIds')) {
    function arcoCompanyIds($usuario, $bd) {
        return \Arco\AlcanceArco::companyIds($usuario, $bd);
    }
}

if (!function_exists('arcoCanAccess')) {
    function arcoCanAccess($usuario, $bd, $req) {
        return \Arco\AlcanceArco::puedeAcceder($usuario, $bd, $req);
    }
}