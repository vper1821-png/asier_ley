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
            'type' => $_POST['type'] ?? 'mysql',  // ✅ CAMBIADO: 'engine' → 'type'
            'host' => $_POST['host'] ?? '',
            'port' => $_POST['port'] ?? '',
            'database' => $_POST['database'] ?? '',
            'user' => $_POST['user'] ?? '',       // ✅ CAMBIADO: 'username' → 'user'
            'password' => $_POST['password'] ?? '',
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

$connected = count(array_filter($databases, fn($d) => ($d['status'] ?? '') === 'connected'));
$errored = count($databases) - $connected;

$engines = ['mysql' => 'MySQL', 'postgres' => 'PostgreSQL', 'mongodb' => 'MongoDB', 'mssql' => 'SQL Server', 'oracle' => 'Oracle', 'sqlite' => 'SQLite'];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Bases de Datos</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($databases) ?> registradas · <?= $connected ?> conectadas · <?= $errored ?> con error</p>
            </div>
            <button onclick="document.getElementById('new-db-form').classList.toggle('hidden')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white transition-all tour-detail-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nueva Base de Datos
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8 space-y-5 scrollbar-custom">
            <?php if ($msg): ?><div class="px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- New DB form -->
            <div id="new-db-form" class="hidden rounded-xl border border-white/[0.04] bg-white/[0.015] p-5">
                <p class="text-[12px] font-semibold text-white mb-4">Conectar base de datos</p>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="label-premium">Nombre</label>
                        <input type="text" name="name" required class="input-premium" placeholder="Mi base de datos">
                    </div>
                    <div>
                        <label class="label-premium">Motor</label>
                        <select name="type" class="input-premium">  <!-- ✅ CAMBIADO: 'engine' → 'type' -->
                            <?php foreach ($engines as $val => $label): ?>
                            <option value="<?= $val ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-premium">Host</label>
                        <input type="text" name="host" required class="input-premium" placeholder="localhost">
                    </div>
                    <div>
                        <label class="label-premium">Puerto</label>
                        <input type="text" name="port" class="input-premium" placeholder="3306">
                    </div>
                    <div>
                        <label class="label-premium">Base de datos</label>
                        <input type="text" name="database" required class="input-premium" placeholder="mydb">
                    </div>
                    <div>
                        <label class="label-premium">Usuario</label>
                        <input type="text" name="user" class="input-premium" placeholder="root">  <!-- ✅ CAMBIADO: 'username' → 'user' -->
                    </div>
                    <div>
                        <label class="label-premium">Contraseña</label>
                        <input type="password" name="password" class="input-premium">
                    </div>
                    <div class="md:col-span-3 flex justify-end gap-2">
                        <button type="button" onclick="document.getElementById('new-db-form').classList.add('hidden')" class="px-3 py-1.5 rounded-lg text-[11px] bg-bg-panel/80 border border-border-theme text-text-muted">Cancelar</button>
                        <button type="submit" name="connect_db" value="1" class="px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-blue-600 to-indigo-600 text-white">Conectar</button>
                    </div>
                </form>
            </div>

            <!-- DB list -->
            <?php if (empty($databases)): ?>
            <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-12 text-center">
                <div class="w-12 h-12 rounded-xl bg-bg-elevated border border-border-theme flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </div>
                <h3 class="text-white font-semibold mb-2">Sin bases de datos</h3>
                <p class="text-text-muted text-[12px]">Agrega una nueva base de datos para empezar.</p>
            </div>
            <?php else: ?>
            <div class="space-y-2 tour-detail-2">
                <div class="hidden lg:grid grid-cols-12 gap-4 px-4 pb-2 text-[10px] font-medium text-text-muted uppercase tracking-wider">
                    <div class="col-span-4">Base de datos</div>
                    <div class="col-span-2">Motor</div>
                    <div class="col-span-2">Estado</div>
                    <div class="col-span-4 text-right">Acciones</div>
                </div>
                <?php foreach ($databases as $d): $isConn = ($d['status'] ?? '') === 'connected'; ?>
                <div class="rounded-xl border border-white/[0.04] bg-white/[0.015] p-4 grid grid-cols-1 lg:grid-cols-12 gap-3 lg:gap-4 items-center">
                    <div class="lg:col-span-4 min-w-0">
                        <p class="text-[12px] font-medium text-text-heading truncate"><?= h($d['name'] ?? $d['database'] ?? 'db') ?></p>
                        <p class="text-[10px] text-text-subtle truncate"><?= h(($d['host'] ?? '') . ($d['port'] ? ':' . $d['port'] : '')) ?> · <?= h($d['database'] ?? '') ?></p>
                        <p class="text-[10px] text-text-subtle truncate">Tablas: <?= (int)($d['tables'] ?? 0) ?> · Registros: <?= (int)($d['totalRows'] ?? ($d['records'] ?? 0)) ?> · Cumple: <?= empty($d['compliant']) || ($d['compliant'] === true) ? 'Sí' : 'No' ?></p>
                    </div>
                    <div class="lg:col-span-2">
                        <span class="text-[11px] text-text-muted"><?= h($engines[$d['type'] ?? ''] ?? $d['type'] ?? '') ?></span>  <!-- ✅ CAMBIADO: 'engine' → 'type' -->
                    </div>
                    <div class="lg:col-span-2">
                        <span class="inline-flex items-center gap-1.5 text-[10px] px-2 py-0.5 rounded-full <?= $isConn ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?= $isConn ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                            <?= $isConn ? 'Conectada' : 'Error' ?>
                        </span>
                    </div>
                    <div class="lg:col-span-4 flex lg:justify-end gap-1.5 flex-wrap">
                        <form method="POST" class="inline-flex gap-1.5">
                            <input type="hidden" name="db_id" value="<?= h($d['_id'] ?? '') ?>">
                            <button type="submit" name="test_db" value="1" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Probar</button>
                            <button type="submit" name="scan_db" value="1" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-500/20 transition-all">Escanear</button>
                            <button type="submit" name="delete_db" value="1" onclick="return confirm('¿Eliminar esta base de datos?')" class="px-2.5 py-1.5 rounded-lg text-[10px] font-medium bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>