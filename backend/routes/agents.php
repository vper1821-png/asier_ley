<?php
// backend/routes/agents.php
// ═══════════════════════════════════════════════════════════════
// Entrypoint delgado. Mantiene las firmas de funciones globales
// que index.php invoca. Toda la lógica vive en backend/agentes/*.php
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/Tenant.php';
require_once __DIR__ . '/../lib/Bson.php';
require_once __DIR__ . '/../lib/MatchHelpers.php';

$base = __DIR__ . '/../agentes/';
require_once $base . 'AlcanceAgente.php';
require_once $base . 'Registro.php';
require_once $base . 'Heartbeat.php';
require_once $base . 'Listado.php';
require_once $base . 'Comandos.php';
require_once $base . 'Lockdown.php';
require_once $base . 'Eliminar.php';
require_once $base . 'Descarga.php';
require_once $base . 'InstaladorLinux.php';
require_once $base . 'Escaneo.php';
require_once $base . 'Forense.php';
require_once $base . 'Sensible.php';
require_once $base . 'ConexionesDb.php';
require_once $base . 'Carpetas.php';

// ═══════════════════════════════════════════════════════════════
// Endpoints expuestos como funciones globales (compatibilidad index.php)
// ═══════════════════════════════════════════════════════════════

function register()           { \Agentes\Registro::registrar(); }
function autoRegister()       { \Agentes\Registro::autoRegistrar(); }
function createDeploy()       { \Agentes\Registro::crearDeploy(); }
function downloadToken()      { \Agentes\Registro::tokenDescarga(); }

function heartbeat()          { \Agentes\Heartbeat::ejecutar(); }

function listAll()            { \Agentes\Listado::listar(); }
function combined()           { \Agentes\Listado::combinado(); }
function getAgent()           { \Agentes\Listado::detalle(); }
function getAgentData()       { \Agentes\Listado::datos(); }

function sendCommand()        { \Agentes\Comandos::enviar(); }
function requestData()        { \Agentes\Comandos::solicitarDatos(); }
function listCommands()       { \Agentes\Comandos::listar(); }
function message()            { \Agentes\Comandos::mensaje(); }

function setLockdown()        { \Agentes\Lockdown::aplicar(); }

function deleteAgent()        { \Agentes\Eliminar::borrar(); }
function updateAgent()        { \Agentes\Eliminar::actualizar(); }

function download()           { \Agentes\Descarga::descargar(); }
function downloadBinary()     { \Agentes\Descarga::descargarBinario(); }

function linuxInstall()       { \Agentes\InstaladorLinux::ejecutar(); }

function scanState()          { \Agentes\Escaneo::consultar(); }
function forceRescan()        { \Agentes\Escaneo::forzar(); }
function scanStateReport()    { \Agentes\Escaneo::reportar(); }

function forensics()          { \Agentes\Forense::eventos(); }
function getAgentLogs()       { \Agentes\Forense::logs(); }

function sensitiveInventory() { \Agentes\Sensible::inventario(); }

function dbConnectionList()   { \Agentes\ConexionesDb::listar(); }
function dbConnectionCreate() { \Agentes\ConexionesDb::crear(); }
function dbConnectionDelete() { \Agentes\ConexionesDb::borrar(); }
function dbConnectionTest()   { \Agentes\ConexionesDb::test(); }

function folderList()         { \Agentes\Carpetas::listar(); }
function folderCreate()       { \Agentes\Carpetas::crear(); }
function folderDelete()       { \Agentes\Carpetas::borrar(); }

// ═══════════════════════════════════════════════════════════════
// Aliases de compatibilidad (funciones auxiliares usadas por otros módulos)
// ═══════════════════════════════════════════════════════════════

if (!function_exists('isSuperAdminUser')) {
    function isSuperAdminUser($u) { return \Tenant::isSuperAdmin($u); }
}
if (!function_exists('findAgentFor')) {
    function findAgentFor($usuario, $agentId) {
        return \Agentes\AlcanceAgente::buscar($usuario, $agentId);
    }
}
if (!function_exists('generateAgentId')) {
    function generateAgentId() {
        return \Agentes\AlcanceAgente::generarAgentId();
    }
}
if (!function_exists('testDBConnection')) {
    function testDBConnection($conn) {
        // Usado por el módulo de databases; sigue disponible como utilidad
        $refl = new \ReflectionClass('\Agentes\ConexionesDb');
        $m = $refl->getMethod('probarConexion');
        $m->setAccessible(true);
        return $m->invoke(null, $conn);
    }
}