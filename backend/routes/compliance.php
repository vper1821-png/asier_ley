<?php
// backend/routes/compliance.php
// ═══════════════════════════════════════════════════════════════
// Entrypoint delgado. Mantiene las firmas de funciones globales
// que index.php invoca (crud, score, updateConfig, etc.).
// Toda la lógica vive en backend/cumplimiento/*.php
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../lib/Tenant.php';
require_once __DIR__ . '/../lib/Bson.php';
require_once __DIR__ . '/../lib/MatchHelpers.php';
require_once __DIR__ . '/../lib/NotificationService.php';

// Constantes portables
if (!defined('PORTAL_DELIVERY_TTL_HOURS')) define('PORTAL_DELIVERY_TTL_HOURS', 48);
if (!defined('PORTAL_ARCO_SLA_DAYS'))     define('PORTAL_ARCO_SLA_DAYS', 10);
if (!defined('API_BASE_URL'))             define('API_BASE_URL', getenv('API_BASE_URL') ?: '');

// Módulos del dominio
$base = __DIR__ . '/../cumplimiento/';
require_once $base . 'Alcance.php';
require_once $base . 'Configuracion.php';
require_once $base . 'Puntaje.php';
require_once $base . 'ProtocoloBrechas.php';
require_once $base . 'PlanIncidentes.php';
require_once $base . 'Invitaciones.php';
require_once $base . 'Checklist.php';
require_once $base . 'ArcoCrud.php';
require_once $base . 'Exportaciones.php';
require_once $base . 'Inventario.php';
require_once $base . 'Plantillas.php';
require_once $base . 'PanelDpo.php';
require_once $base . 'PoliticaPublica.php';
require_once $base . 'PdfCompliance.php';
require_once $base . 'CrudGenerico.php';
require_once $base . 'Enrutador.php';

// ═══════════════════════════════════════════════════════════════
// Endpoints expuestos como funciones globales (compatibilidad index.php)
// ═══════════════════════════════════════════════════════════════

function score()             { \Cumplimiento\Puntaje::calcular(); }
function detailedChecklist() { \Cumplimiento\Puntaje::checklistDetallado(); }
function autoSignTraining()  { \Cumplimiento\Puntaje::firmarCapacitacionAuto(); }

function updateConfig()      { \Cumplimiento\Configuracion::actualizar(); }
function getConfig()         { \Cumplimiento\Configuracion::obtener(); }

function verifyInvite()      { \Cumplimiento\Invitaciones::verificar(); }
function sign()              { \Cumplimiento\Invitaciones::firmar(); }

function generatePublicPolicy()   { \Cumplimiento\PoliticaPublica::generar(); }

function listPendingRevocations() { \Cumplimiento\PanelDpo::listarRevocacionesPendientes(); }
function reviewRevocation()       { \Cumplimiento\PanelDpo::revisarRevocacion(); }
function listIdentityPending()    { \Cumplimiento\PanelDpo::listarIdentidadPendiente(); }
function verifyIdentity()         { \Cumplimiento\PanelDpo::verificarIdentidad(); }
function deliverConcreteData()    { \Cumplimiento\PanelDpo::entregarDatos(); }

function applyPackToAgents()      { \Cumplimiento\Plantillas::aplicarPack(); }
function previewPackApply()       { \Cumplimiento\Plantillas::previsualizarPack(); }
function unassignPackFromAgent()  { \Cumplimiento\Plantillas::desasignarPack(); }
function clusterInventory()       { \Cumplimiento\Plantillas::agruparInventario(); }

// Compatibilidad con los nombres antiguos
function inventoryApplyTemplate() { \Cumplimiento\Inventario::manejar(
    \Auth::requireAuth(),
    \Database::getInstance(),
    \Cumplimiento\Alcance::idsDeEmpresa(\Auth::requireAuth(), \Database::getInstance()),
    \Cumplimiento\Alcance::esAdminGlobal(\Auth::requireAuth()),
    'POST', $_GET['id'] ?? '', 'apply-template', \get_body()
); }

// El dispatcher principal del catch-all
function crud() {
    \Cumplimiento\Enrutador::despachar();
}