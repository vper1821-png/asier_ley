<?php
$pageTitle = 'Tickets';
$currentPage = 'tickets';
require_once __DIR__ . '/../includes/header.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_ticket'])) {
        $res = api_post_form('/api/tickets/create', [
            'token' => $token,
            'subject' => $_POST['subject'] ?? '',
            'message' => $_POST['message'] ?? '',
            'priority' => $_POST['priority'] ?? 'medium',
        ]);
        if (!empty($res['success']) || !empty($res['_id'])) $msg = 'Ticket creado.';
        else $err = $res['error'] ?? 'Error al crear ticket.';
    } elseif (isset($_POST['reply_ticket'])) {
        $res = api_post_form('/api/tickets/' . urlencode($_POST['ticket_id']) . '/reply', ['token' => $token, 'message' => $_POST['reply'] ?? '']);
        if (!empty($res['success'])) $msg = 'Respuesta enviada.'; else $err = $res['error'] ?? 'Error.';
    } elseif (isset($_POST['close_ticket'])) {
        $res = api_post_form('/api/tickets/close', ['token' => $token, 'id' => $_POST['ticket_id']]);
        if (!empty($res['success'])) $msg = 'Ticket cerrado.'; else $err = $res['error'] ?? 'Error.';
    }
}

$ticketsRes = api_post_form('/api/tickets/all', ['token' => $token]);
$tickets = is_array($ticketsRes) && empty($ticketsRes['error']) ? ($ticketsRes['tickets'] ?? $ticketsRes) : [];
if (!is_array($tickets)) $tickets = [];

$selectedId = $_GET['ticket'] ?? '';
$selected = null;
foreach ($tickets as $t) {
    if (($t['_id'] ?? '') === $selectedId) { $selected = $t; break; }
}

$statusCfg = [
    'open' => ['label' => 'Abierto', 'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'],
    'pending' => ['label' => 'Pendiente', 'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20'],
    'closed' => ['label' => 'Cerrado', 'class' => 'bg-white/[0.04] text-text-subtle border-white/[0.06]'],
];
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col">
        <div class="flex-shrink-0 px-5 md:px-8 py-5 border-b border-white/[0.04] flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h2 class="text-[15px] font-semibold text-white tracking-tight">Tickets de soporte</h2>
                <p class="text-[11px] text-text-subtle mt-0.5 font-medium"><?= count($tickets) ?> tickets</p>
            </div>
            <button onclick="document.getElementById('new-ticket-form').classList.toggle('hidden')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-medium bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white transition-all tour-detail-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nuevo Ticket
            </button>
        </div>

        <div class="flex-1 overflow-hidden w-full px-4 md:px-8 pb-8 pt-4">
            <?php if ($msg): ?><div class="mb-3 px-4 py-2.5 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[11px]"><?= h($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="mb-3 px-4 py-2.5 rounded-lg bg-red-500/10 border border-red-500/30 text-red-400 text-[11px]"><?= h($err) ?></div><?php endif; ?>

            <!-- New ticket form -->
            <div id="new-ticket-form" class="hidden mb-4 rounded-xl border border-white/[0.04] bg-white/[0.015] p-5">
                <form method="POST" class="space-y-3">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="md:col-span-2">
                            <label class="label-premium">Asunto</label>
                            <input type="text" name="subject" required class="input-premium" placeholder="Describe el problema">
                        </div>
                        <div>
                            <label class="label-premium">Prioridad</label>
                            <select name="priority" class="input-premium">
                                <option value="low">Baja</option>
                                <option value="medium" selected>Media</option>
                                <option value="high">Alta</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="label-premium">Mensaje</label>
                        <textarea name="message" rows="3" required class="input-premium" placeholder="Detalles del problema..."></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" name="create_ticket" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-cyan-600 to-blue-600 text-white">Crear ticket</button>
                    </div>
                </form>
            </div>

            <div class="h-full rounded-xl border border-white/[0.04] bg-white/[0.015] flex overflow-hidden">
                <!-- Ticket list -->
                <aside class="hidden md:block w-64 flex-shrink-0 border-r border-border-theme/50 overflow-y-auto bg-white/[0.015] tour-detail-2">
                    <div class="p-3">
                        <p class="text-[10px] text-text-subtle uppercase tracking-wide font-medium mb-2 px-1">Historial</p>
                        <?php if (empty($tickets)): ?>
                        <p class="text-[11px] text-text-subtle px-1 py-4 text-center">Sin tickets</p>
                        <?php else: ?>
                        <?php foreach ($tickets as $t): $st = $statusCfg[$t['status'] ?? 'open'] ?? $statusCfg['open']; ?>
                        <a href="/tickets?ticket=<?= h($t['_id'] ?? '') ?>"
                           class="block px-2.5 py-2 rounded-lg mb-1 transition-colors <?= $selectedId === ($t['_id'] ?? '') ? 'bg-primary-500/10 border border-primary-500/20' : 'hover:bg-bg-elevated' ?>">
                            <p class="text-[11px] font-medium text-text-heading truncate"><?= h($t['subject'] ?? 'Ticket') ?></p>
                            <div class="flex items-center justify-between mt-1">
                                <span class="text-[9px] px-1.5 py-0.5 rounded-full border <?= $st['class'] ?>"><?= h($st['label']) ?></span>
                                <span class="text-[9px] text-text-subtle"><?= h(substr($t['createdAt'] ?? '', 0, 10)) ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>

                <!-- Ticket detail -->
                <div class="flex-1 overflow-y-auto p-5">
                    <?php if (!$selected): ?>
                    <div class="h-full flex items-center justify-center">
                        <p class="text-[11px] text-text-subtle">Selecciona un ticket para ver la conversación.</p>
                    </div>
                    <?php else: $st = $statusCfg[$selected['status'] ?? 'open'] ?? $statusCfg['open']; ?>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <p class="text-[13px] font-semibold text-white"><?= h($selected['subject'] ?? 'Ticket') ?></p>
                            <p class="text-[10px] text-text-subtle mt-0.5">Creado el <?= h(substr($selected['createdAt'] ?? '', 0, 16)) ?></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-2 py-0.5 rounded-full border <?= $st['class'] ?>"><?= h($st['label']) ?></span>
                            <?php if (($selected['status'] ?? '') !== 'closed'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="ticket_id" value="<?= h($selected['_id']) ?>">
                                <button type="submit" name="close_ticket" value="1" class="px-2.5 py-1 rounded-lg text-[10px] bg-bg-panel/80 border border-border-theme text-text-muted hover:text-text-body">Cerrar</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="space-y-3 mb-4">
                        <?php foreach (($selected['messages'] ?? []) as $m): $mine = ($m['from'] ?? '') !== 'support' && ($m['role'] ?? '') !== 'admin'; ?>
                        <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>">
                            <div class="max-w-[75%] rounded-xl px-3.5 py-2.5 <?= $mine ? 'bg-primary-600/20 border border-primary-500/20' : 'bg-bg-panel border border-border-theme' ?>">
                                <p class="text-[11px] text-text-body whitespace-pre-wrap"><?= h($m['message'] ?? $m['text'] ?? '') ?></p>
                                <p class="text-[9px] text-text-subtle mt-1"><?= h(substr($m['createdAt'] ?? $m['at'] ?? '', 0, 16)) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (($selected['status'] ?? '') !== 'closed'): ?>
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="ticket_id" value="<?= h($selected['_id']) ?>">
                        <input type="text" name="reply" required placeholder="Escribe una respuesta..." class="input-premium flex-1">
                        <button type="submit" name="reply_ticket" value="1" class="px-4 py-2 rounded-lg text-[11px] font-medium bg-gradient-to-r from-cyan-600 to-blue-600 text-white">Enviar</button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
