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
$compliant = count(array_filter($databases, fn($d) => empty($d['compliant']) || ($d['compliant'] === true)));
$synced = count(array_filter($databases, fn($d) => !empty($d['last_scan'])));

$engines = [
    'mysql' => ['label' => 'MySQL', 'color' => '#f59e0b'],
    'postgres' => ['label' => 'PostgreSQL', 'color' => '#38bdf8'],
    'mongodb' => ['label' => 'MongoDB', 'color' => '#34d399'],
    'mssql' => ['label' => 'SQL Server', 'color' => '#f87171'],
    'oracle' => ['label' => 'Oracle', 'color' => '#fb923c'],
    'sqlite' => ['label' => 'SQLite', 'color' => '#94a3b8'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">

        <!-- Header -->
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04]">
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-500/20">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    </div>
                    <div>
                        <h1 class="text-[18px] font-bold text-white tracking-tight">Bases de Datos</h1>
                        <p class="text-[11px] text-text-subtle font-medium mt-0.5"><?= count($databases) ?> registradas · <?= $connected ?> conectadas · <?= $errored ?> con error</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="document.getElementById('new-db-form').classList.toggle('hidden')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white shadow-lg shadow-blue-600/20 transition-all tour-detail-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Nueva Base de Datos
                    </button>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto scrollbar-custom">
            <div class="max-w-7xl mx-auto px-5 md:px-8 py-6 space-y-6">

                <?php if ($msg): ?>
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[12px] font-medium">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?= h($msg) ?>
                </div>
                <?php endif; ?>

                <?php if ($err): ?>
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-[12px] font-medium">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <?= h($err) ?>
                </div>
                <?php endif; ?>

                <!-- KPI Row -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 flex items-center gap-3 hover:border-white/[0.1] transition-all">
                        <div class="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-text-muted uppercase tracking-widest">Total</p>
                            <p class="text-[22px] font-bold text-white leading-none mt-0.5"><?= count($databases) ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 flex items-center gap-3 hover:border-white/[0.1] transition-all">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-text-muted uppercase tracking-widest">Conectadas</p>
                            <p class="text-[22px] font-bold text-emerald-400 leading-none mt-0.5"><?= $connected ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 flex items-center gap-3 hover:border-white/[0.1] transition-all">
                        <div class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-text-muted uppercase tracking-widest">Con Error</p>
                            <p class="text-[22px] font-bold text-red-400 leading-none mt-0.5"><?= $errored ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 flex items-center gap-3 hover:border-white/[0.1] transition-all">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-text-muted uppercase tracking-widest">Cumplen</p>
                            <p class="text-[22px] font-bold text-blue-400 leading-none mt-0.5"><?= $compliant ?></p>
                        </div>
                    </div>
                    <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] p-4 flex items-center gap-3 hover:border-white/[0.1] transition-all">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-text-muted uppercase tracking-widest">Escaneadas</p>
                            <p class="text-[22px] font-bold text-purple-400 leading-none mt-0.5"><?= $synced ?></p>
                        </div>
                    </div>
                </div>

                <!-- New DB Form -->
                <div id="new-db-form" class="hidden rounded-xl border border-white/[0.06] bg-white/[0.02] p-6">
                    <div class="flex items-center gap-3 mb-5">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <p class="text-[14px] font-bold text-white">Conectar base de datos</p>
                    </div>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="label-premium">Nombre</label>
                            <input type="text" name="name" required class="input-premium" placeholder="Mi base de datos">
                        </div>
                        <div>
                            <label class="label-premium">Motor</label>
                            <select name="type" class="input-premium">
                                <?php foreach ($engines as $val => $e): ?>
                                <option value="<?= $val ?>"><?= h($e['label']) ?></option>
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
                            <input type="text" name="user" class="input-premium" placeholder="root">
                        </div>
                        <div>
                            <label class="label-premium">Contraseña</label>
                            <input type="password" name="password" class="input-premium">
                        </div>
                        <div class="md:col-span-3 flex justify-end gap-2 pt-2">
                            <button type="button" onclick="document.getElementById('new-db-form').classList.add('hidden')" class="px-4 py-2 rounded-lg text-[12px] font-medium bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body transition-all">Cancelar</button>
                            <button type="submit" name="connect_db" value="1" class="px-4 py-2 rounded-lg text-[12px] font-semibold bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-600/20 hover:from-blue-500 hover:to-indigo-500 transition-all">Conectar</button>
                        </div>
                    </form>
                </div>

                <!-- DB List Container -->
                <div class="rounded-xl border border-white/[0.06] bg-white/[0.02] overflow-hidden tour-detail-2">
                    <?php if (empty($databases)): ?>
                    <!-- Empty State -->
                    <div class="p-16 text-center">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-slate-500/10 to-slate-600/5 border border-white/[0.06] flex items-center justify-center mx-auto mb-5">
                            <svg class="w-8 h-8 text-text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        </div>
                        <h3 class="text-white font-bold text-[15px] mb-2">Sin bases de datos</h3>
                        <p class="text-text-muted text-[12px] max-w-xs mx-auto">Agrega una nueva base de datos para empezar a gestionar tus conexiones.</p>
                    </div>

                    <?php else: ?>
                    <div class="divide-y divide-white/[0.04]">
                        <?php foreach ($databases as $d):
                            $isConn = ($d['status'] ?? '') === 'connected';
                            $eng = $engines[$d['type'] ?? ''] ?? ['label' => $d['type'] ?? '', 'color' => '#94a3b8'];
                            $isCompliant = empty($d['compliant']) || ($d['compliant'] === true);
                            $tables = (int)($d['tables'] ?? 0);
                            $records = (int)($d['totalRows'] ?? ($d['records'] ?? 0));
                        ?>
                        <div class="px-5 py-4 hover:bg-white/[0.015] transition-colors">
                            <div class="flex items-center gap-4">
                                <!-- Engine Icon -->
                                <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style="background:<?= $eng['color'] ?>1a;color:<?= $eng['color'] ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
                                </div>

                                <!-- Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2.5 flex-wrap">
                                        <p class="text-[13px] font-semibold text-white truncate"><?= h($d['name'] ?? $d['database'] ?? 'db') ?></p>
                                        <span class="inline-flex items-center gap-1.5 text-[10px] px-2 py-0.5 rounded-full font-medium <?= $isConn ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $isConn ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                                            <?= $isConn ? 'Conectada' : 'Error' ?>
                                        </span>
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-medium border <?= $isCompliant ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20' ?>">
                                            <?= $isCompliant ? 'Cumple' : 'No cumple' ?>
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-text-subtle truncate mt-1">
                                        <span style="color:<?= $eng['color'] ?>; font-weight: 600;"><?= h($eng['label']) ?></span>
                                        <span class="mx-1.5 text-text-muted">·</span>
                                        <?= h(($d['host'] ?? '') . (!empty($d['port']) ? ':' . $d['port'] : '')) ?>
                                        <span class="mx-1.5 text-text-muted">·</span>
                                        <?= h($d['database'] ?? '') ?>
                                    </p>
                                </div>

                                <!-- Metadata -->
                                <div class="hidden sm:flex items-center gap-4 flex-shrink-0">
                                    <div class="text-center">
                                        <p class="text-[14px] font-bold text-white leading-none"><?= $tables ?></p>
                                        <p class="text-[9px] text-text-muted uppercase tracking-widest mt-1">Tablas</p>
                                    </div>
                                    <div class="w-px h-8 bg-white/[0.06]"></div>
                                    <div class="text-center">
                                        <p class="text-[14px] font-bold text-white leading-none"><?= number_format($records) ?></p>
                                        <p class="text-[9px] text-text-muted uppercase tracking-widest mt-1">Registros</p>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-1.5 flex-shrink-0">
                                    <form method="POST" class="inline-flex items-center gap-1.5">
                                        <input type="hidden" name="db_id" value="<?= h($d['_id'] ?? '') ?>">
                                        <button type="submit" name="test_db" value="1" class="px-3 py-1.5 rounded-lg text-[10px] font-semibold bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body hover:bg-bg-elevated transition-all">Probar</button>
                                        <button type="submit" name="scan_db" value="1" class="px-3 py-1.5 rounded-lg text-[10px] font-semibold bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-500/20 transition-all">Escanear</button>
                                        <button type="submit" name="delete_db" value="1" onclick="return confirm('¿Eliminar esta base de datos?')" class="px-3 py-1.5 rounded-lg text-[10px] font-semibold bg-red-900/10 border border-red-800/20 text-red-400 hover:bg-red-900/20 transition-all">Eliminar</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
