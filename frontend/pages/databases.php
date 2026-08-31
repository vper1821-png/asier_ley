<?php
$pageTitle = 'Bases de Datos';
$currentPage = 'databases';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['connect_db'])) {
        $res = api_post_form('/api/databases/connect', [
            'token' => $token,
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? 'mysql',
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? '',
            'database' => $_POST['database'] ?? '',
            'user' => $_POST['user'] ?? '',
            'password' => $_POST['password'] ?? '',
            'agentId' => $_POST['agentId'] ?? '',
        ]);
        if (!empty($res['success']) || !empty($res['_id'])) $msg = 'Base de datos conectada.';
        else $err = $res['error'] ?? 'Error al conectar.';
    } elseif (isset($_POST['delete_db'])) {
        $res = api_post_form('/api/databases/' . urlencode($_POST['db_id']) . '/delete', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Base de datos eliminada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['test_db'])) {
        $res = api_post_form('/api/databases/' . urlencode($_POST['db_id']) . '/test', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Conexión OK.'; else $err = $res['error'] ?? 'Fallo de conexión.';
    } elseif (isset($_POST['scan_db'])) {
        $res = api_post_form('/api/databases/' . urlencode($_POST['db_id']) . '/scan', ['token' => $token]);
        if (!empty($res['success'])) $msg = 'Escaneo iniciado.'; else $err = $res['error'] ?? 'Error al escanear.';
    }
}

$dbsRes = api_post_form('/api/databases/list', ['token' => $token]);
$databases = is_array($dbsRes) && empty($dbsRes['error']) ? ($dbsRes['databases'] ?? $dbsRes) : [];
if (!is_array($databases)) $databases = [];

$agentsRes = api_post_form('/api/agents/list', ['token' => $token]);
$agents = is_array($agentsRes) && empty($agentsRes['error']) ? $agentsRes : [];
if (!is_array($agents)) $agents = [];

$connected = count(array_filter($databases, fn($d) => ($d['status'] ?? '') === 'connected'));
$errored = count($databases) - $connected;
$compliant = count(array_filter($databases, fn($d) => empty($d['compliant']) || ($d['compliant'] === true)));
$synced = count(array_filter($databases, fn($d) => !empty($d['lastScan']) || !empty($d['last_scan'])));

$engines = [
    'mysql' => ['label' => 'MySQL', 'color' => '#f59e0b'],
    'mariadb' => ['label' => 'MariaDB', 'color' => '#c08457'],
    'postgres' => ['label' => 'PostgreSQL', 'color' => '#38bdf8'],
    'postgresql' => ['label' => 'PostgreSQL', 'color' => '#38bdf8'],
    'mongodb' => ['label' => 'MongoDB', 'color' => '#34d399'],
    'mssql' => ['label' => 'SQL Server', 'color' => '#f87171'],
    'oracle' => ['label' => 'Oracle', 'color' => '#fb923c'],
    'sqlite' => ['label' => 'SQLite', 'color' => '#94a3b8'],
];

$totalDatabases = count($databases);
$connectionRate = $totalDatabases > 0 ? (int) round(($connected / $totalDatabases) * 100) : 0;
$complianceRate = $totalDatabases > 0 ? (int) round(($compliant / $totalDatabases) * 100) : 0;
$scanRate = $totalDatabases > 0 ? (int) round(($synced / $totalDatabases) * 100) : 0;
$showNewForm = !empty($err) && isset($_POST['connect_db']);
$selectedEngine = isset($_POST['connect_db']) ? ($_POST['type'] ?? 'mysql') : 'mysql';

$formatDatabaseDate = static function ($value) {
    if (empty($value)) return 'Pendiente';
    if (is_array($value) || is_object($value)) return 'No disponible';

    try {
        return (new DateTime((string) $value))->format('d/m/Y · H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
};
?>

<style>
    .databases-workspace {
        --db-radius: 12px;
        --db-radius-sm: 8px;
        --db-control-height: 44px;
        background: var(--bg-base);
        color: var(--text-body);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .databases-workspace *,
    .databases-workspace *::before,
    .databases-workspace *::after {
        box-sizing: border-box;
    }

    .databases-workspace .db-main {
        min-width: 0;
        min-height: 0;
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        background: var(--bg-base);
    }

    .databases-workspace .db-container {
        width: 100%;
        max-width: 1480px;
        margin: 0 auto;
    }

    .databases-workspace .db-page-header {
        flex: 0 0 auto;
        padding: 22px 28px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-base);
    }

    .databases-workspace .db-header-layout {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 24px;
    }

    .databases-workspace .db-heading-group {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .databases-workspace .db-heading-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--accent-border);
        border-radius: 10px;
        background: var(--accent-subtle);
        color: var(--accent);
    }

    .databases-workspace .db-kicker {
        margin: 0 0 4px;
        color: var(--text-subtle);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .13em;
        line-height: 1.2;
        text-transform: uppercase;
    }

    .databases-workspace .db-page-title {
        margin: 0;
        color: var(--text-heading);
        font-size: clamp(18px, 2vw, 23px);
        font-weight: 700;
        letter-spacing: -.025em;
        line-height: 1.25;
    }

    .databases-workspace .db-page-subtitle {
        margin: 5px 0 0;
        color: var(--text-muted);
        font-size: 11px;
        line-height: 1.55;
    }

    .databases-workspace .db-header-actions {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .databases-workspace .db-header-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 38px;
        padding: 0 12px;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius-sm);
        background: var(--bg-panel);
        color: var(--text-muted);
        font-size: 11px;
        white-space: nowrap;
    }

    .databases-workspace .db-header-status strong {
        color: var(--text-heading);
        font-weight: 650;
    }

    .databases-workspace .db-status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--text-subtle);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--text-subtle) 12%, transparent);
    }

    .databases-workspace .db-status-dot.is-online {
        background: var(--success);
        box-shadow: 0 0 0 3px rgb(var(--success-rgb) / .12);
    }

    .databases-workspace .db-primary-button,
    .databases-workspace .db-secondary-button,
    .databases-workspace .db-action-button,
    .databases-workspace .db-icon-button {
        border: 0;
        font: inherit;
        cursor: pointer;
        text-decoration: none;
    }

    .databases-workspace .db-primary-button {
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0 14px;
        border: 1px solid var(--accent);
        border-radius: var(--db-radius-sm);
        background: var(--accent);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
        transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease;
    }

    .databases-workspace .db-primary-button:hover {
        border-color: var(--accent-hover);
        background: var(--accent-hover);
        box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 22%, transparent);
    }

    .databases-workspace .db-primary-button svg,
    .databases-workspace .db-secondary-button svg,
    .databases-workspace .db-action-button svg {
        width: 15px;
        height: 15px;
        flex: 0 0 auto;
    }

    .databases-workspace .db-scroll-area {
        min-height: 0;
        flex: 1 1 auto;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-gutter: stable;
    }

    .databases-workspace .db-content {
        padding: 24px 28px 40px;
    }

    .databases-workspace .db-stack {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .databases-workspace .db-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        border: 1px solid;
        border-radius: var(--db-radius-sm);
        font-size: 11px;
        font-weight: 550;
        line-height: 1.55;
    }

    .databases-workspace .db-alert svg {
        width: 17px;
        height: 17px;
        flex: 0 0 auto;
        margin-top: 1px;
    }

    .databases-workspace .db-alert--success {
        border-color: rgb(var(--success-rgb) / .22);
        background: rgb(var(--success-rgb) / .07);
        color: color-mix(in srgb, var(--success) 82%, var(--text-heading));
    }

    .databases-workspace .db-alert--error {
        border-color: rgb(var(--danger-rgb) / .22);
        background: rgb(var(--danger-rgb) / .07);
        color: color-mix(in srgb, var(--danger) 82%, var(--text-heading));
    }

    .databases-workspace .db-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    .databases-workspace .db-kpi-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
    }

    .databases-workspace .db-kpi-card {
        min-width: 0;
        min-height: 102px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 14px;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius);
        background: var(--bg-panel);
        box-shadow: 0 8px 24px color-mix(in srgb, var(--shadow-color) 24%, transparent);
    }

    .databases-workspace .db-kpi-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .databases-workspace .db-kpi-label {
        margin: 0;
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .08em;
        line-height: 1.3;
        text-transform: uppercase;
    }

    .databases-workspace .db-kpi-icon {
        width: 28px;
        height: 28px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-color);
        border-radius: 7px;
        background: var(--bg-elevated);
        color: var(--text-muted);
    }

    .databases-workspace .db-kpi-icon svg {
        width: 14px;
        height: 14px;
    }

    .databases-workspace .db-kpi-card.is-success .db-kpi-icon,
    .databases-workspace .db-kpi-card.is-success .db-kpi-value {
        color: var(--success);
    }

    .databases-workspace .db-kpi-card.is-warning .db-kpi-icon,
    .databases-workspace .db-kpi-card.is-warning .db-kpi-value {
        color: var(--warning);
    }

    .databases-workspace .db-kpi-card.is-accent .db-kpi-icon,
    .databases-workspace .db-kpi-card.is-accent .db-kpi-value {
        color: var(--accent);
    }

    .databases-workspace .db-kpi-value-row {
        display: flex;
        align-items: baseline;
        gap: 7px;
        margin-top: 12px;
    }

    .databases-workspace .db-kpi-value {
        color: var(--text-heading);
        font-size: 24px;
        font-weight: 700;
        letter-spacing: -.035em;
        line-height: 1;
    }

    .databases-workspace .db-kpi-context {
        min-width: 0;
        overflow: hidden;
        color: var(--text-subtle);
        font-size: 9px;
        font-weight: 550;
        line-height: 1.4;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-panel {
        overflow: hidden;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius);
        background: var(--bg-panel);
        box-shadow: 0 12px 32px color-mix(in srgb, var(--shadow-color) 28%, transparent);
    }

    .databases-workspace .db-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        padding: 16px 18px;
        border-bottom: 1px solid var(--border-subtle);
        background: var(--bg-panel);
    }

    .databases-workspace .db-panel-heading {
        min-width: 0;
        display: flex;
        align-items: flex-start;
        gap: 11px;
    }

    .databases-workspace .db-panel-heading-icon {
        width: 31px;
        height: 31px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-elevated);
        color: var(--text-muted);
    }

    .databases-workspace .db-panel-heading-icon svg {
        width: 15px;
        height: 15px;
    }

    .databases-workspace .db-panel-title {
        margin: 0;
        color: var(--text-heading);
        font-size: 13px;
        font-weight: 700;
        letter-spacing: -.01em;
        line-height: 1.35;
    }

    .databases-workspace .db-panel-description {
        max-width: 720px;
        margin: 4px 0 0;
        color: var(--text-subtle);
        font-size: 10px;
        line-height: 1.55;
    }

    .databases-workspace .db-icon-button {
        width: 32px;
        height: 32px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-color);
        border-radius: 7px;
        background: transparent;
        color: var(--text-muted);
        transition: background-color .16s ease, border-color .16s ease, color .16s ease;
    }

    .databases-workspace .db-icon-button:hover {
        border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color));
        background: var(--bg-elevated);
        color: var(--text-heading);
    }

    .databases-workspace .db-icon-button svg {
        width: 15px;
        height: 15px;
    }

    .databases-workspace .db-form-body {
        padding: 18px;
    }

    .databases-workspace .db-form-note {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 16px;
        padding: 11px 12px;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius-sm);
        background: var(--bg-base);
        color: var(--text-muted);
        font-size: 10px;
        line-height: 1.55;
    }

    .databases-workspace .db-form-note svg {
        width: 16px;
        height: 16px;
        flex: 0 0 auto;
        margin-top: 1px;
        color: var(--accent);
    }

    .databases-workspace .db-form-sections {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .databases-workspace .db-fieldset {
        min-width: 0;
        margin: 0;
        padding: 14px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: color-mix(in srgb, var(--bg-base) 62%, transparent);
    }

    .databases-workspace .db-fieldset legend {
        padding: 0 6px;
        color: var(--text-body);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .databases-workspace .db-fields-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 13px 11px;
    }

    .databases-workspace .db-field--full {
        grid-column: 1 / -1;
    }

    .databases-workspace .db-label {
        display: flex;
        align-items: center;
        gap: 4px;
        margin: 0 0 6px;
        color: var(--text-body);
        font-size: 10px;
        font-weight: 650;
        line-height: 1.3;
    }

    .databases-workspace .db-required {
        color: var(--danger);
    }

    .databases-workspace .db-control {
        width: 100%;
        min-height: var(--db-control-height);
        display: block;
        padding: 0 11px;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius-sm);
        outline: none;
        background: var(--bg-input);
        color: var(--text-heading);
        font-family: inherit;
        font-size: 12px;
        line-height: 1.4;
        transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease;
    }

    .databases-workspace .db-control::placeholder {
        color: var(--text-placeholder);
    }

    .databases-workspace .db-control:hover {
        border-color: color-mix(in srgb, var(--text-subtle) 45%, var(--border-color));
    }

    .databases-workspace .db-control:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-subtle);
        background: var(--bg-base);
    }

    .databases-workspace .db-field-help {
        min-height: 14px;
        margin: 5px 0 0;
        color: var(--text-subtle);
        font-size: 9px;
        line-height: 1.45;
    }

    .databases-workspace .db-password-wrap {
        position: relative;
    }

    .databases-workspace .db-password-wrap .db-control {
        padding-right: 66px;
    }

    .databases-workspace .db-password-toggle {
        position: absolute;
        top: 50%;
        right: 7px;
        transform: translateY(-50%);
        padding: 5px 6px;
        border: 0;
        border-radius: 5px;
        background: transparent;
        color: var(--text-muted);
        font-family: inherit;
        font-size: 9px;
        font-weight: 700;
        cursor: pointer;
    }

    .databases-workspace .db-password-toggle:hover {
        background: var(--bg-elevated);
        color: var(--text-heading);
    }

    .databases-workspace .db-form-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border-subtle);
    }

    .databases-workspace .db-form-required-note {
        margin: 0;
        color: var(--text-subtle);
        font-size: 9px;
        line-height: 1.45;
    }

    .databases-workspace .db-form-buttons {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
    }

    .databases-workspace .db-secondary-button {
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 0 13px;
        border: 1px solid var(--border-color);
        border-radius: var(--db-radius-sm);
        background: transparent;
        color: var(--text-body);
        font-size: 11px;
        font-weight: 650;
        transition: background-color .16s ease, border-color .16s ease, color .16s ease;
    }

    .databases-workspace .db-secondary-button:hover {
        border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color));
        background: var(--bg-elevated);
        color: var(--text-heading);
    }

    .databases-workspace .db-list-count {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 9px;
        border: 1px solid var(--border-color);
        border-radius: 7px;
        background: var(--bg-base);
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 650;
        white-space: nowrap;
    }

    .databases-workspace .db-list-count strong {
        color: var(--text-heading);
        font-size: 11px;
    }

    .databases-workspace .db-list-columns,
    .databases-workspace .db-record {
        display: grid;
        grid-template-columns: minmax(190px, 1.25fr) minmax(175px, 1.15fr) minmax(125px, .75fr) minmax(130px, .75fr) minmax(155px, .9fr) minmax(270px, auto);
        column-gap: 16px;
    }

    .databases-workspace .db-list-columns {
        align-items: center;
        min-height: 36px;
        padding: 0 18px;
        border-bottom: 1px solid var(--border-subtle);
        background: var(--bg-elevated);
        color: var(--text-subtle);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .databases-workspace .db-records {
        display: flex;
        flex-direction: column;
    }

    .databases-workspace .db-record {
        align-items: center;
        padding: 16px 18px;
        border-bottom: 1px solid var(--border-subtle);
        transition: background-color .16s ease;
    }

    .databases-workspace .db-record:last-child {
        border-bottom: 0;
    }

    .databases-workspace .db-record:hover {
        background: color-mix(in srgb, var(--accent) 3.5%, var(--bg-panel));
    }

    .databases-workspace .db-cell {
        min-width: 0;
    }

    .databases-workspace .db-cell-label {
        display: none;
        margin: 0 0 6px;
        color: var(--text-subtle);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .databases-workspace .db-identity {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 0;
    }

    .databases-workspace .db-engine-icon {
        --db-engine-color: var(--text-muted);
        width: 36px;
        height: 36px;
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid color-mix(in srgb, var(--db-engine-color) 28%, var(--border-color));
        border-radius: 8px;
        background: color-mix(in srgb, var(--db-engine-color) 9%, var(--bg-panel));
        color: var(--db-engine-color);
    }

    .databases-workspace .db-engine-icon svg {
        width: 17px;
        height: 17px;
    }

    .databases-workspace .db-identity-copy {
        min-width: 0;
    }

    .databases-workspace .db-name {
        overflow: hidden;
        margin: 0;
        color: var(--text-heading);
        font-size: 12px;
        font-weight: 700;
        line-height: 1.4;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-engine-name {
        overflow: hidden;
        margin: 3px 0 0;
        color: var(--text-subtle);
        font-size: 9px;
        font-weight: 600;
        line-height: 1.35;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-endpoint {
        overflow: hidden;
        margin: 0;
        color: var(--text-body);
        font-family: "JetBrains Mono", monospace;
        font-size: 10px;
        font-weight: 550;
        line-height: 1.45;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-database-name {
        overflow: hidden;
        margin: 4px 0 0;
        color: var(--text-subtle);
        font-size: 9px;
        line-height: 1.4;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-database-name span {
        color: var(--text-muted);
        font-weight: 600;
    }

    .databases-workspace .db-badges {
        display: flex;
        align-items: flex-start;
        flex-direction: column;
        gap: 5px;
    }

    .databases-workspace .db-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        width: max-content;
        max-width: 100%;
        padding: 4px 7px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--bg-base);
        color: var(--text-muted);
        font-size: 9px;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
    }

    .databases-workspace .db-badge-dot {
        width: 5px;
        height: 5px;
        flex: 0 0 auto;
        border-radius: 50%;
        background: currentColor;
    }

    .databases-workspace .db-badge--online {
        border-color: rgb(var(--success-rgb) / .2);
        background: rgb(var(--success-rgb) / .07);
        color: var(--success);
    }

    .databases-workspace .db-badge--pending {
        border-color: rgb(var(--warning-rgb) / .2);
        background: rgb(var(--warning-rgb) / .07);
        color: var(--warning);
    }

    .databases-workspace .db-badge--error,
    .databases-workspace .db-badge--noncompliant {
        border-color: rgb(var(--danger-rgb) / .2);
        background: rgb(var(--danger-rgb) / .07);
        color: var(--danger);
    }

    .databases-workspace .db-badge--compliant {
        border-color: var(--accent-border);
        background: var(--accent-subtle);
        color: var(--accent);
    }

    .databases-workspace .db-metrics {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, auto));
        justify-content: start;
        gap: 14px;
    }

    .databases-workspace .db-metric-value {
        margin: 0;
        color: var(--text-heading);
        font-size: 12px;
        font-weight: 700;
        line-height: 1.3;
    }

    .databases-workspace .db-metric-label {
        margin: 2px 0 0;
        color: var(--text-subtle);
        font-size: 8px;
        font-weight: 650;
        letter-spacing: .06em;
        line-height: 1.35;
        text-transform: uppercase;
    }

    .databases-workspace .db-activity-line {
        overflow: hidden;
        margin: 0;
        color: var(--text-muted);
        font-size: 9px;
        line-height: 1.5;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .databases-workspace .db-activity-line + .db-activity-line {
        margin-top: 3px;
    }

    .databases-workspace .db-activity-line span {
        color: var(--text-subtle);
    }

    .databases-workspace .db-latency {
        color: var(--text-body);
        font-family: "JetBrains Mono", monospace;
        font-weight: 600;
    }

    .databases-workspace .db-actions-form {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
    }

    .databases-workspace .db-action-button {
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 0 9px;
        border: 1px solid var(--border-color);
        border-radius: 7px;
        background: var(--bg-base);
        color: var(--text-muted);
        font-size: 9px;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
        transition: background-color .16s ease, border-color .16s ease, color .16s ease;
    }

    .databases-workspace .db-action-button:hover {
        border-color: color-mix(in srgb, var(--text-subtle) 50%, var(--border-color));
        background: var(--bg-elevated);
        color: var(--text-heading);
    }

    .databases-workspace .db-action-button--scan {
        border-color: var(--accent-border);
        background: var(--accent-subtle);
        color: var(--accent);
    }

    .databases-workspace .db-action-button--scan:hover {
        border-color: color-mix(in srgb, var(--accent) 48%, var(--border-color));
        background: color-mix(in srgb, var(--accent) 16%, var(--bg-panel));
        color: var(--accent);
    }

    .databases-workspace .db-action-button--delete {
        border-color: rgb(var(--danger-rgb) / .18);
        background: rgb(var(--danger-rgb) / .05);
        color: color-mix(in srgb, var(--danger) 80%, var(--text-muted));
    }

    .databases-workspace .db-action-button--delete:hover {
        border-color: rgb(var(--danger-rgb) / .32);
        background: rgb(var(--danger-rgb) / .1);
        color: var(--danger);
    }

    .databases-workspace .db-empty-state {
        padding: 54px 24px 58px;
        text-align: center;
    }

    .databases-workspace .db-empty-icon {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: var(--bg-elevated);
        color: var(--text-muted);
    }

    .databases-workspace .db-empty-icon svg {
        width: 22px;
        height: 22px;
    }

    .databases-workspace .db-empty-title {
        margin: 0;
        color: var(--text-heading);
        font-size: 14px;
        font-weight: 700;
        line-height: 1.4;
    }

    .databases-workspace .db-empty-copy {
        max-width: 410px;
        margin: 7px auto 16px;
        color: var(--text-muted);
        font-size: 10px;
        line-height: 1.65;
    }

    @media (max-width: 1279px) {
        .databases-workspace .db-list-columns {
            display: none;
        }

        .databases-workspace .db-record {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            align-items: start;
            gap: 16px;
        }

        .databases-workspace .db-cell-label {
            display: block;
        }

        .databases-workspace .db-record-actions {
            grid-column: 1 / -1;
            padding-top: 12px;
            border-top: 1px solid var(--border-subtle);
        }

        .databases-workspace .db-record-actions .db-cell-label {
            display: none;
        }

        .databases-workspace .db-actions-form {
            justify-content: flex-start;
        }
    }

    @media (max-width: 1099px) {
        .databases-workspace .db-kpi-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .databases-workspace .db-form-sections {
            grid-template-columns: 1fr;
        }

        .databases-workspace .db-fieldset {
            padding: 15px;
        }
    }

    @media (max-width: 767px) {
        .databases-workspace {
            width: 100%;
            min-height: 100dvh;
            height: 100dvh;
            flex-direction: column;
        }

        .databases-workspace > .app-mobile-header {
            width: 100%;
            flex: 0 0 auto;
        }

        .databases-workspace .db-main {
            width: 100%;
        }

        .databases-workspace .db-page-header {
            padding: 16px;
        }

        .databases-workspace .db-header-layout,
        .databases-workspace .db-header-actions {
            align-items: stretch;
            flex-direction: column;
        }

        .databases-workspace .db-header-layout {
            gap: 15px;
        }

        .databases-workspace .db-header-actions {
            gap: 8px;
        }

        .databases-workspace .db-header-status,
        .databases-workspace .db-primary-button {
            width: 100%;
        }

        .databases-workspace .db-header-status {
            justify-content: center;
        }

        .databases-workspace .db-content {
            padding: 16px 14px calc(28px + env(safe-area-inset-bottom, 0px));
        }

        .databases-workspace .db-stack {
            gap: 14px;
        }

        .databases-workspace .db-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .databases-workspace .db-kpi-card {
            min-height: 96px;
            padding: 12px;
        }

        .databases-workspace .db-kpi-card:last-child {
            grid-column: 1 / -1;
        }

        .databases-workspace .db-panel-header {
            padding: 14px;
        }

        .databases-workspace .db-form-body {
            padding: 14px;
        }

        .databases-workspace .db-fields-grid {
            grid-template-columns: 1fr;
        }

        .databases-workspace .db-field--full {
            grid-column: auto;
        }

        .databases-workspace .db-control {
            font-size: 16px;
        }

        .databases-workspace .db-form-actions {
            align-items: stretch;
            flex-direction: column;
        }

        .databases-workspace .db-form-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
        }

        .databases-workspace .db-record {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 12px;
            padding: 15px 14px;
        }

        .databases-workspace .db-record-identity,
        .databases-workspace .db-record-actions {
            grid-column: 1 / -1;
        }

        .databases-workspace .db-record-actions {
            margin-top: 1px;
        }

        .databases-workspace .db-actions-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .databases-workspace .db-action-button {
            min-height: 40px;
            padding: 0 7px;
            font-size: 10px;
        }

        .databases-workspace .db-empty-state {
            padding: 42px 18px 46px;
        }
    }

    @media (max-width: 420px) {
        .databases-workspace .db-heading-icon {
            width: 38px;
            height: 38px;
        }

        .databases-workspace .db-page-subtitle {
            font-size: 10px;
        }

        .databases-workspace .db-kpi-value {
            font-size: 21px;
        }

        .databases-workspace .db-record {
            grid-template-columns: 1fr;
        }

        .databases-workspace .db-record-identity,
        .databases-workspace .db-record-actions {
            grid-column: auto;
        }

        .databases-workspace .db-metrics {
            gap: 24px;
        }

        .databases-workspace .db-actions-form {
            grid-template-columns: 1fr;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .databases-workspace *,
        .databases-workspace *::before,
        .databases-workspace *::after {
            scroll-behavior: auto !important;
            transition-duration: .01ms !important;
        }
    }
</style>

<div class="databases-workspace flex h-screen overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="db-main">

        <!-- Header -->
        <header class="db-page-header">
            <div class="db-container db-header-layout">
                <div class="db-heading-group">
                    <div class="db-heading-icon" aria-hidden="true">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3" stroke-width="1.8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                    </div>
                    <div>
                        <p class="db-kicker">Infraestructura / Orígenes de datos</p>
                        <h1 class="db-page-title">Gestión de bases de datos</h1>
                        <p class="db-page-subtitle">Administra conexiones, valida su disponibilidad y mantén actualizado el inventario de datos.</p>
                    </div>
                </div>

                <div class="db-header-actions">
                    <div class="db-header-status" aria-label="Resumen de disponibilidad">
                        <span class="db-status-dot <?= $connected > 0 ? 'is-online' : '' ?>" aria-hidden="true"></span>
                        <?php if ($totalDatabases > 0): ?>
                            <span><strong><?= $connected ?></strong> de <?= $totalDatabases ?> operativas</span>
                        <?php else: ?>
                            <span>Sin conexiones registradas</span>
                        <?php endif; ?>
                    </div>
                    <button type="button" data-db-form-open aria-controls="new-db-form" aria-expanded="<?= $showNewForm ? 'true' : 'false' ?>" class="db-primary-button tour-detail-1">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14M5 12h14"/></svg>
                        Añadir conexión
                    </button>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="db-scroll-area scrollbar-custom">
            <div class="db-container db-content">
                <div class="db-stack">

                    <?php if ($msg): ?>
                    <div class="db-alert db-alert--success" role="status" aria-live="polite">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span><?= h($msg) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($err): ?>
                    <div class="db-alert db-alert--error" role="alert">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        <span><?= h($err) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- KPI Row -->
                    <section aria-labelledby="database-overview-title">
                        <h2 id="database-overview-title" class="db-sr-only">Resumen de bases de datos</h2>
                        <div class="db-kpi-grid">
                            <article class="db-kpi-card">
                                <div class="db-kpi-top">
                                    <p class="db-kpi-label">Total registradas</p>
                                    <span class="db-kpi-icon" aria-hidden="true">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3" stroke-width="1.8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                                    </span>
                                </div>
                                <div class="db-kpi-value-row">
                                    <strong class="db-kpi-value"><?= number_format($totalDatabases, 0, ',', '.') ?></strong>
                                    <span class="db-kpi-context">en inventario</span>
                                </div>
                            </article>

                            <article class="db-kpi-card is-success">
                                <div class="db-kpi-top">
                                    <p class="db-kpi-label">Operativas</p>
                                    <span class="db-kpi-icon" aria-hidden="true">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                </div>
                                <div class="db-kpi-value-row">
                                    <strong class="db-kpi-value"><?= $connected ?></strong>
                                    <span class="db-kpi-context"><?= $connectionRate ?>% disponible</span>
                                </div>
                            </article>

                            <article class="db-kpi-card is-warning">
                                <div class="db-kpi-top">
                                    <p class="db-kpi-label">Por revisar</p>
                                    <span class="db-kpi-icon" aria-hidden="true">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.9" d="M12 8v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                                    </span>
                                </div>
                                <div class="db-kpi-value-row">
                                    <strong class="db-kpi-value"><?= $errored ?></strong>
                                    <span class="db-kpi-context">sin conexión activa</span>
                                </div>
                            </article>

                            <article class="db-kpi-card is-accent">
                                <div class="db-kpi-top">
                                    <p class="db-kpi-label">Conformes</p>
                                    <span class="db-kpi-icon" aria-hidden="true">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3l7 3v5c0 4.55-2.94 8.74-7 10-4.06-1.26-7-5.45-7-10V6l7-3zM9 12l2 2 4-4"/></svg>
                                    </span>
                                </div>
                                <div class="db-kpi-value-row">
                                    <strong class="db-kpi-value"><?= $compliant ?></strong>
                                    <span class="db-kpi-context"><?= $complianceRate ?>% del total</span>
                                </div>
                            </article>

                            <article class="db-kpi-card is-accent">
                                <div class="db-kpi-top">
                                    <p class="db-kpi-label">Escaneadas</p>
                                    <span class="db-kpi-icon" aria-hidden="true">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 11a8 8 0 10-2.34 5.66M20 4v7h-7"/></svg>
                                    </span>
                                </div>
                                <div class="db-kpi-value-row">
                                    <strong class="db-kpi-value"><?= $synced ?></strong>
                                    <span class="db-kpi-context"><?= $scanRate ?>% inventariado</span>
                                </div>
                            </article>
                        </div>
                    </section>

                    <!-- New DB Form -->
                    <section id="new-db-form" class="db-panel <?= $showNewForm ? '' : 'hidden' ?>" aria-labelledby="new-db-form-title" aria-hidden="<?= $showNewForm ? 'false' : 'true' ?>">
                        <div class="db-panel-header">
                            <div class="db-panel-heading">
                                <span class="db-panel-heading-icon" aria-hidden="true">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 5v14M5 12h14"/></svg>
                                </span>
                                <div>
                                    <h2 id="new-db-form-title" class="db-panel-title">Registrar una conexión</h2>
                                    <p class="db-panel-description">Completa la identificación, el destino y las credenciales del origen de datos.</p>
                                </div>
                            </div>
                            <button type="button" data-db-form-close class="db-icon-button" aria-label="Cerrar formulario de conexión">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <form method="POST" class="db-form-body" autocomplete="off">
                            <div class="db-form-note">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 11V8a4 4 0 00-8 0v3m0 0h12a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2v-6a2 2 0 012-2zm8-6h6v14a2 2 0 01-2 2h-2"/></svg>
                                <span>Utiliza una cuenta de servicio con los permisos mínimos necesarios. La plataforma usará estos datos para probar la conexión y ejecutar los escaneos solicitados.</span>
                            </div>

                            <div class="db-form-sections">
                                <fieldset class="db-fieldset">
                                    <legend>1. Identificación</legend>
                                    <div class="db-fields-grid">
                                        <div class="db-field db-field--full">
                                            <label for="db-name" class="db-label">Nombre visible <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <input id="db-name" type="text" name="name" required class="db-control" value="<?= isset($_POST['connect_db']) ? h($_POST['name'] ?? '') : '' ?>" placeholder="Ej. ERP Producción">
                                            <p class="db-field-help">Nombre interno para reconocer la conexión.</p>
                                        </div>
                                        <div class="db-field db-field--full">
                                            <label for="db-type" class="db-label">Motor de base de datos <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <select id="db-type" name="type" class="db-control" required>
                                                <?php foreach ($engines as $val => $e): ?>
                                                <option value="<?= h($val) ?>" <?= $selectedEngine === $val ? 'selected' : '' ?>><?= h($e['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="db-field-help">Selecciona el controlador compatible con el servidor.</p>
                                        </div>
                                    </div>
                                </fieldset>

                                <fieldset class="db-fieldset">
                                    <legend>2. Agente asignado</legend>
                                    <div class="db-fields-grid">
                                        <div class="db-field db-field--full">
                                            <label for="db-agent" class="db-label">Agente de monitoreo <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <select id="db-agent" name="agentId" class="db-control" required>
                                                <option value="">Seleccione un agente...</option>
                                                <?php foreach ($agents as $agent): ?>
                                                <?php $agentValue = h($agent['agentId'] ?? $agent['_id'] ?? ''); ?>
                                                <option value="<?= $agentValue ?>" <?= (isset($_POST['connect_db']) && ($_POST['agentId'] ?? '') === ($agent['agentId'] ?? $agent['_id'] ?? '')) ? 'selected' : '' ?>>
                                                    <?= h(($agent['hostname'] ?? $agent['agentId'] ?? $agent['_id'] ?? 'Agente sin nombre') . ' (' . ($agent['status'] ?? 'desconocido') . ')') ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="db-field-help">Agente que ejecutará el escaneo y monitoreo sobre esta base de datos.</p>
                                        </div>
                                    </div>
                                </fieldset>

                                <fieldset class="db-fieldset">
                                    <legend>3. Servidor y esquema</legend>
                                    <div class="db-fields-grid">
                                        <div class="db-field db-field--full">
                                            <label for="db-host" class="db-label">Host o dirección IP <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <input id="db-host" type="text" name="host" required class="db-control" value="<?= isset($_POST['connect_db']) ? h($_POST['host'] ?? '') : '' ?>" placeholder="db.empresa.cl" spellcheck="false">
                                            <p class="db-field-help">Dominio o IP accesible desde el agente.</p>
                                        </div>
                                        <div class="db-field">
                                            <label for="db-port" class="db-label">Puerto <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <input id="db-port" type="text" name="port" required inputmode="numeric" class="db-control" value="<?= isset($_POST['connect_db']) ? h($_POST['port'] ?? '') : '' ?>" placeholder="3306">
                                            <p id="db-port-help" class="db-field-help">Puerto habitual de MySQL: 3306.</p>
                                        </div>
                                        <div class="db-field">
                                            <label for="db-database" class="db-label">Base o esquema <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <input id="db-database" type="text" name="database" required class="db-control" value="<?= isset($_POST['connect_db']) ? h($_POST['database'] ?? '') : '' ?>" placeholder="produccion" spellcheck="false">
                                            <p class="db-field-help">Nombre exacto del catálogo a analizar.</p>
                                        </div>
                                    </div>
                                </fieldset>

                                <fieldset class="db-fieldset">
                                    <legend>4. Credenciales</legend>
                                    <div class="db-fields-grid">
                                        <div class="db-field db-field--full">
                                            <label for="db-user" class="db-label">Usuario de servicio <span class="db-required" aria-hidden="true">*</span><span class="db-sr-only">obligatorio</span></label>
                                            <input id="db-user" type="text" name="user" required autocomplete="username" class="db-control" value="<?= isset($_POST['connect_db']) ? h($_POST['user'] ?? '') : '' ?>" placeholder="svc_auditoria" spellcheck="false">
                                            <p class="db-field-help">Prefiere una cuenta dedicada y de solo lectura.</p>
                                        </div>
                                        <div class="db-field db-field--full">
                                            <label for="db-password" class="db-label">Contraseña</label>
                                            <div class="db-password-wrap">
                                                <input id="db-password" type="password" name="password" autocomplete="current-password" class="db-control" placeholder="Introduce la contraseña">
                                                <button type="button" class="db-password-toggle" data-password-toggle aria-controls="db-password" aria-pressed="false">Mostrar</button>
                                            </div>
                                            <p class="db-field-help">Déjala vacía únicamente si el servidor no la requiere.</p>
                                        </div>
                                    </div>
                                </fieldset>
                            </div>

                            <div class="db-form-actions">
                                <p class="db-form-required-note"><span class="db-required" aria-hidden="true">*</span> Campos obligatorios para registrar la conexión.</p>
                                <div class="db-form-buttons">
                                    <button type="button" data-db-form-close class="db-secondary-button">Cancelar</button>
                                    <button type="submit" name="connect_db" value="1" class="db-primary-button">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Guardar conexión
                                    </button>
                                </div>
                            </div>
                        </form>
                    </section>

                    <!-- DB List Container -->
                    <section class="db-panel tour-detail-2" aria-labelledby="database-list-title">
                        <div class="db-panel-header">
                            <div class="db-panel-heading">
                                <span class="db-panel-heading-icon" aria-hidden="true">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h16M4 12h16M4 17h10"/></svg>
                                </span>
                                <div>
                                    <h2 id="database-list-title" class="db-panel-title">Conexiones registradas</h2>
                                    <p class="db-panel-description">Consulta estado, destino, volumen y actividad; ejecuta pruebas o escaneos desde cada registro.</p>
                                </div>
                            </div>
                            <span class="db-list-count"><strong><?= $totalDatabases ?></strong> <?= $totalDatabases === 1 ? 'conexión' : 'conexiones' ?></span>
                        </div>

                        <?php if (empty($databases)): ?>
                        <!-- Empty State -->
                        <div class="db-empty-state">
                            <div class="db-empty-icon" aria-hidden="true">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3" stroke-width="1.6"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                            </div>
                            <h3 class="db-empty-title">Aún no hay conexiones registradas</h3>
                            <p class="db-empty-copy">Añade el primer origen de datos para validar su disponibilidad y construir el inventario de tablas y registros.</p>
                            <button type="button" data-db-form-open aria-controls="new-db-form" aria-expanded="<?= $showNewForm ? 'true' : 'false' ?>" class="db-primary-button">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14M5 12h14"/></svg>
                                Añadir primera conexión
                            </button>
                        </div>

                        <?php else: ?>
                        <div class="db-list-columns" aria-hidden="true">
                            <span>Conexión</span>
                            <span>Destino</span>
                            <span>Estado</span>
                            <span>Inventario</span>
                            <span>Actividad</span>
                            <span>Acciones</span>
                        </div>

                        <div class="db-records">
                            <?php foreach ($databases as $d):
                                $statusRaw = strtolower(trim((string) ($d['status'] ?? 'configured')));
                                $isConn = $statusRaw === 'connected';
                                $eng = $engines[$d['type'] ?? ''] ?? ['label' => $d['type'] ?? 'Desconocido', 'color' => '#94a3b8'];
                                $isCompliant = empty($d['compliant']) || ($d['compliant'] === true);
                                $tables = (int) ($d['tables'] ?? 0);
                                $records = (int) ($d['totalRows'] ?? ($d['records'] ?? 0));
                                $databaseName = trim((string) ($d['name'] ?? $d['database'] ?? 'Base de datos'));
                                $host = trim((string) ($d['host'] ?? ''));
                                $port = trim((string) ($d['port'] ?? ''));
                                $endpoint = $host !== '' ? $host . ($port !== '' ? ':' . $port : '') : 'Destino no informado';
                                $schemaName = trim((string) ($d['database'] ?? ''));
                                $lastTest = $formatDatabaseDate($d['lastTest'] ?? ($d['last_test'] ?? null));
                                $lastScan = $formatDatabaseDate($d['lastScan'] ?? ($d['last_scan'] ?? null));
                                $latency = isset($d['latency']) && $d['latency'] !== '' ? (int) $d['latency'] : null;

                                if ($isConn) {
                                    $statusLabel = 'Operativa';
                                    $statusClass = 'db-badge--online';
                                } elseif (in_array($statusRaw, ['configured', 'pending', 'testing', 'scanning'], true)) {
                                    $statusLabel = in_array($statusRaw, ['testing', 'scanning'], true) ? 'En proceso' : 'Pendiente de prueba';
                                    $statusClass = 'db-badge--pending';
                                } else {
                                    $statusLabel = 'Requiere revisión';
                                    $statusClass = 'db-badge--error';
                                }
                            ?>
                            <article class="db-record">
                                <div class="db-cell db-record-identity">
                                    <p class="db-cell-label">Conexión</p>
                                    <div class="db-identity">
                                        <!-- Engine Icon -->
                                        <span class="db-engine-icon" style="--db-engine-color: <?= h($eng['color']) ?>" aria-hidden="true">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3" stroke-width="1.7"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                                        </span>

                                        <!-- Info -->
                                        <div class="db-identity-copy">
                                            <h3 class="db-name" title="<?= h($databaseName) ?>"><?= h($databaseName) ?></h3>
                                            <p class="db-engine-name"><?= h($eng['label']) ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="db-cell">
                                    <p class="db-cell-label">Destino</p>
                                    <p class="db-endpoint" title="<?= h($endpoint) ?>"><?= h($endpoint) ?></p>
                                    <p class="db-database-name">Base: <span><?= $schemaName !== '' ? h($schemaName) : 'No informada' ?></span></p>
                                </div>

                                <div class="db-cell">
                                    <p class="db-cell-label">Estado</p>
                                    <div class="db-badges">
                                        <span class="db-badge <?= $statusClass ?>"><span class="db-badge-dot" aria-hidden="true"></span><?= h($statusLabel) ?></span>
                                        <span class="db-badge <?= $isCompliant ? 'db-badge--compliant' : 'db-badge--noncompliant' ?>"><?= $isCompliant ? 'Conforme' : 'No conforme' ?></span>
                                    </div>
                                </div>

                                <!-- Metadata -->
                                <div class="db-cell">
                                    <p class="db-cell-label">Inventario</p>
                                    <div class="db-metrics">
                                        <div>
                                            <p class="db-metric-value"><?= number_format($tables, 0, ',', '.') ?></p>
                                            <p class="db-metric-label">Tablas</p>
                                        </div>
                                        <div>
                                            <p class="db-metric-value"><?= number_format($records, 0, ',', '.') ?></p>
                                            <p class="db-metric-label">Registros</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="db-cell">
                                    <p class="db-cell-label">Actividad</p>
                                    <p class="db-activity-line"><span>Prueba:</span> <?= h($lastTest) ?><?= $latency !== null ? ' · ' : '' ?><?php if ($latency !== null): ?><strong class="db-latency"><?= $latency ?> ms</strong><?php endif; ?></p>
                                    <p class="db-activity-line"><span>Escaneo:</span> <?= h($lastScan) ?></p>
                                </div>

                                <!-- Actions -->
                                <div class="db-cell db-record-actions">
                                    <p class="db-cell-label">Acciones</p>
                                    <form method="POST" class="db-actions-form">
                                        <input type="hidden" name="db_id" value="<?= h($d['_id'] ?? '') ?>">
                                        <button type="submit" name="test_db" value="1" class="db-action-button" aria-label="Probar conexión <?= h($databaseName) ?>">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 12h3l2-5 4 10 2-5h5"/></svg>
                                            Probar
                                        </button>
                                        <button type="submit" name="scan_db" value="1" class="db-action-button db-action-button--scan" aria-label="Escanear <?= h($databaseName) ?>">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6" stroke-width="1.8"/><path stroke-linecap="round" stroke-width="1.8" d="M16 16l4 4M8 11h6M11 8v6"/></svg>
                                            Escanear
                                        </button>
                                        <button type="submit" name="delete_db" value="1" onclick="return confirm('¿Eliminar esta base de datos?')" class="db-action-button db-action-button--delete" aria-label="Eliminar <?= h($databaseName) ?>">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg>
                                            Eliminar
                                        </button>
                                    </form>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </section>

                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    const root = document.querySelector('.databases-workspace');
    const formPanel = document.getElementById('new-db-form');
    if (!root || !formPanel) return;

    const openButtons = root.querySelectorAll('[data-db-form-open]');
    const closeButtons = root.querySelectorAll('[data-db-form-close]');
    const nameInput = document.getElementById('db-name');

    function setFormOpen(isOpen, shouldFocus) {
        formPanel.classList.toggle('hidden', !isOpen);
        formPanel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        openButtons.forEach(function (button) {
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        if (isOpen && shouldFocus) {
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            formPanel.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            window.setTimeout(function () {
                if (nameInput) nameInput.focus({ preventScroll: true });
            }, reduceMotion ? 0 : 250);
        }
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setFormOpen(true, true);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setFormOpen(false, false);
            const firstOpenButton = openButtons.item(0);
            if (firstOpenButton) firstOpenButton.focus({ preventScroll: true });
        });
    });

    const passwordInput = document.getElementById('db-password');
    const passwordToggle = root.querySelector('[data-password-toggle]');
    if (passwordInput && passwordToggle) {
        passwordToggle.addEventListener('click', function () {
            const showPassword = passwordInput.type === 'password';
            passwordInput.type = showPassword ? 'text' : 'password';
            passwordToggle.textContent = showPassword ? 'Ocultar' : 'Mostrar';
            passwordToggle.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
        });
    }

    const engineSelect = document.getElementById('db-type');
    const portInput = document.getElementById('db-port');
    const portHelp = document.getElementById('db-port-help');
    const enginePorts = {
        mysql: ['3306', 'MySQL'],
        mariadb: ['3306', 'MariaDB'],
        postgres: ['5432', 'PostgreSQL'],
        postgresql: ['5432', 'PostgreSQL'],
        mongodb: ['27017', 'MongoDB'],
        mssql: ['1433', 'SQL Server'],
        oracle: ['1521', 'Oracle'],
        sqlite: ['0', 'SQLite']
    };

    function updatePortGuidance() {
        if (!engineSelect || !portInput || !portHelp) return;
        const engine = enginePorts[engineSelect.value] || ['', 'el motor seleccionado'];
        portInput.placeholder = engine[0] || 'Puerto del servicio';
        portHelp.textContent = engine[0]
            ? 'Puerto habitual de ' + engine[1] + ': ' + engine[0] + '.'
            : 'Indica el puerto configurado en el servidor.';
    }

    if (engineSelect) {
        engineSelect.addEventListener('change', updatePortGuidance);
        updatePortGuidance();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
