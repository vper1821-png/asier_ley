<?php
$pageTitle = 'Centro de Soporte y Tickets';
$currentPage = 'tickets';
require_once __DIR__ . '/../config.php';
require_login();

$user = $_SESSION['user'] ?? [];
$token = $_SESSION['token'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (isset($_POST['create_ticket']) || $action === 'create_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['message'] ?? ($_POST['description'] ?? ''));
        $priority = $_POST['priority'] ?? 'medium';
        $category = $_POST['category'] ?? 'general';

        if (empty($subject) || empty($description)) {
            $err = 'Por favor completa el asunto y el mensaje del ticket.';
        } else {
            $fullMessage = (!empty($category) && $category !== 'general') ? "[Categoría: " . strtoupper($category) . "]\n\n" . $description : $description;
            
            $res = api_post_form('/api/tickets/create', [
                'token' => $token,
                'subject' => $subject,
                'description' => $fullMessage,
                'message' => $fullMessage,
                'priority' => $priority,
            ]);

            if (!empty($res['success']) || !empty($res['_id']) || !empty($res['ticket']['_id'])) {
                $newId = $res['ticket']['_id'] ?? ($res['_id'] ?? ($res['ticketId'] ?? ''));
                if ($newId === '') {
                    $chk = api_post_form('/api/tickets/all', ['token' => $token]);
                    $list = is_array($chk) && empty($chk['error']) ? ($chk['tickets'] ?? $chk) : [];
                    foreach ((array)$list as $t) {
                        if (($t['subject'] ?? '') === $subject) $newId = $t['_id'] ?? '';
                    }
                }
                header('Location: /tickets?ticket=' . urlencode($newId) . '&created=1');
                exit;
            } else {
                $err = $res['error'] ?? 'Error al crear el ticket de soporte.';
            }
        }
    } elseif (isset($_POST['reply_ticket']) || $action === 'reply_ticket') {
        $ticketId = $_POST['ticket_id'] ?? '';
        $replyText = trim($_POST['reply'] ?? ($_POST['message'] ?? ''));
        
        if (!empty($ticketId) && !empty($replyText)) {
            $authorName = $user['name'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Usuario'));
            $res = api_post_form('/api/tickets/respond', [
                'token' => $token,
                'ticketId' => $ticketId,
                'message' => $replyText,
                'agentName' => $authorName,
            ]);
            
            if (!empty($res['success'])) {
                header('Location: /tickets?ticket=' . urlencode($ticketId) . '&replied=1');
                exit;
            } else {
                $err = $res['error'] ?? 'Error al enviar la respuesta.';
            }
        }
    } elseif (isset($_POST['close_ticket']) || $action === 'close_ticket') {
        $ticketId = $_POST['ticket_id'] ?? '';
        if (!empty($ticketId)) {
            $res = api_post_form('/api/tickets/close', [
                'token' => $token,
                'ticketId' => $ticketId,
                'id' => $ticketId
            ]);
            if (!empty($res['success'])) {
                header('Location: /tickets?ticket=' . urlencode($ticketId) . '&closed=1');
                exit;
            } else {
                $err = $res['error'] ?? 'Error al cerrar el ticket.';
            }
        }
    } elseif (isset($_POST['reopen_ticket']) || $action === 'reopen_ticket') {
        $ticketId = $_POST['ticket_id'] ?? '';
        if (!empty($ticketId)) {
            $res = api_post_form('/api/tickets/status', [
                'token' => $token,
                'ticketId' => $ticketId,
                'id' => $ticketId,
                'status' => 'open'
            ]);
            if (!empty($res['success'])) {
                header('Location: /tickets?ticket=' . urlencode($ticketId) . '&reopened=1');
                exit;
            } else {
                $err = $res['error'] ?? 'Error al reabrir el ticket.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';

// Fetch tickets
$ticketsRes = api_post_form('/api/tickets/all', ['token' => $token]);
$tickets = is_array($ticketsRes) && empty($ticketsRes['error']) ? ($ticketsRes['tickets'] ?? $ticketsRes) : [];
if (!is_array($tickets)) $tickets = [];

// Sort tickets by date descending
usort($tickets, function($a, $b) {
    $ta = strtotime($a['updatedAt'] ?? ($a['createdAt'] ?? 'now'));
    $tb = strtotime($b['updatedAt'] ?? ($b['createdAt'] ?? 'now'));
    return $tb <=> $ta;
});

$selectedId = $_GET['ticket'] ?? '';
$createdFlag = ($_GET['created'] ?? '') === '1';
$repliedFlag = ($_GET['replied'] ?? '') === '1';
$closedFlag = ($_GET['closed'] ?? '') === '1';
$reopenedFlag = ($_GET['reopened'] ?? '') === '1';

if ($createdFlag) $msg = 'Ticket de soporte creado con éxito. Un especialista responderá a la brevedad.';
if ($repliedFlag) $msg = 'Respuesta enviada correctamente.';
if ($closedFlag) $msg = 'El ticket ha sido marcado como resuelto/cerrado.';
if ($reopenedFlag) $msg = 'El ticket ha sido reabierto.';

$selected = null;
if ($selectedId) {
    foreach ($tickets as $t) {
        if (($t['_id'] ?? '') === $selectedId) {
            $selected = $t;
            break;
        }
    }
}
// Auto-select first ticket on desktop if none selected and tickets exist
if (!$selected && !empty($tickets) && empty($_GET['new'])) {
    $selected = $tickets[0];
    $selectedId = $selected['_id'] ?? '';
}

$statusCfg = [
    'open'        => ['label' => 'Abierto',      'class' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/25', 'dot' => 'bg-emerald-400'],
    'in_progress' => ['label' => 'En Atención',  'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/25',     'dot' => 'bg-amber-400'],
    'pending'     => ['label' => 'Pendiente',    'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/25',     'dot' => 'bg-amber-400'],
    'closed'      => ['label' => 'Cerrado',      'class' => 'bg-white/[0.04] text-text-subtle border-white/[0.08]',   'dot' => 'bg-slate-500'],
];

$prioCfg = [
    'low'      => ['label' => 'Baja',     'class' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',     'dot' => 'bg-blue-400'],
    'medium'   => ['label' => 'Media',    'class' => 'bg-amber-500/10 text-amber-400 border-amber-500/20',   'dot' => 'bg-amber-400'],
    'high'     => ['label' => 'Alta',     'class' => 'bg-orange-500/10 text-orange-400 border-orange-500/20', 'dot' => 'bg-orange-400'],
    'critical' => ['label' => 'Crítica',  'class' => 'bg-red-500/10 text-red-400 border-red-500/20',       'dot' => 'bg-red-400'],
];

$countBy = ['total' => count($tickets), 'open' => 0, 'in_progress' => 0, 'closed' => 0];
foreach ($tickets as $t) {
    $s = $t['status'] ?? 'open';
    if ($s === 'closed') $countBy['closed']++;
    elseif ($s === 'in_progress' || $s === 'pending') $countBy['in_progress']++;
    else $countBy['open']++;
}

$userDisplayName = $user['name'] ?? ($user['companyName'] ?? ($user['email'] ?? 'Usuario'));
$userInitials = mb_strtoupper(mb_substr($userDisplayName, 0, 2));
?>

<div class="flex h-screen bg-bg-base text-[13px] text-text-body overflow-hidden">
    <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

    <main class="flex-1 overflow-hidden bg-bg-base flex flex-col min-w-0">
        
        <!-- Top App Bar -->
        <header class="flex-shrink-0 border-b border-border-theme px-6 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/50 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-600/30 to-blue-500/20 border border-cyan-500/30 flex items-center justify-center text-cyan-400 shadow-theme-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 000 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 000-4V7a2 2 0 00-2-2H5z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-base font-bold text-white tracking-tight flex items-center gap-2">
                        Centro de Soporte y Tickets
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-mono bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                            SLA 24/7
                        </span>
                    </h1>
                    <p class="text-[11px] text-text-muted">Asistencia técnica especializada en ciberseguridad, agentes y cumplimiento Ley 21.719</p>
                </div>
            </div>

            <!-- Action Button -->
            <div class="flex items-center gap-2.5">
                <button type="button" onclick="openNewTicketModal()"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-cyan-500/20 transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Abrir Nuevo Ticket
                </button>
            </div>
        </header>

        <!-- Main Body Workspace -->
        <div class="flex-1 overflow-hidden flex flex-col p-4 sm:p-6 min-h-0 space-y-4">

            <!-- Metrics Bento Strip -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5 flex-shrink-0">
                <!-- Total -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Total Incidencias</p>
                        <p class="text-xl font-bold text-white font-mono mt-0.5"><?= $countBy['total'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                </div>

                <!-- Abiertos -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-emerald-400/90 tracking-wider">Tickets Abiertos</p>
                        <p class="text-xl font-bold text-emerald-400 font-mono mt-0.5"><?= $countBy['open'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    </div>
                </div>

                <!-- En progreso -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-amber-400/90 tracking-wider">En Atención</p>
                        <p class="text-xl font-bold text-amber-400 font-mono mt-0.5"><?= $countBy['in_progress'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>

                <!-- Resueltos -->
                <div class="bg-bg-panel/70 border border-border-theme rounded-2xl p-3.5 backdrop-blur-md flex items-center justify-between">
                    <div>
                        <p class="text-[10px] uppercase font-semibold text-text-subtle tracking-wider">Resueltos / Cerrados</p>
                        <p class="text-xl font-bold text-text-body font-mono mt-0.5"><?= $countBy['closed'] ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-xl bg-white/[0.03] border border-white/[0.06] flex items-center justify-center text-text-muted">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
            </div>

            <!-- Toast / Alerts -->
            <?php if ($msg): ?>
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs shadow-theme-sm flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span><?= h($msg) ?></span>
                </div>
                <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-emerald-400 hover:text-emerald-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <?php if ($err): ?>
            <div class="animate-fade-in-up flex items-center justify-between gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-xs shadow-theme-sm flex-shrink-0">
                <div class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                    <span><?= h($err) ?></span>
                </div>
                <button type="button" onclick="this.closest('.animate-fade-in-up').remove()" class="text-red-400 hover:text-red-200">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- Main Dual Pane View (Left: Ticket Inbox List, Right: Conversation Workspace) -->
            <div class="flex-1 min-h-0 bg-bg-panel/80 border border-border-theme rounded-2xl overflow-hidden backdrop-blur-md shadow-theme-sm flex flex-col md:flex-row">
                
                <!-- Left Pane: Tickets Inbox -->
                <div class="w-full md:w-80 lg:w-96 flex-shrink-0 border-b md:border-b-0 md:border-r border-border-theme flex flex-col bg-bg-base/40 <?= $selected && isset($_GET['ticket']) ? 'hidden md:flex' : 'flex' ?>">
                    
                    <!-- Search & Filter Controls -->
                    <div class="p-3.5 border-b border-border-theme space-y-2.5 bg-bg-surface/30">
                        <div class="relative">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-subtle pointer-events-none">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            </div>
                            <input type="text" id="ticket-search" placeholder="Buscar por asunto, ID o contenido..."
                                   oninput="filterTicketsList()"
                                   class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/20 transition-all">
                        </div>

                        <!-- Status Filter Pills -->
                        <div class="flex items-center gap-1 overflow-x-auto scrollbar-none py-0.5" id="status-filters">
                            <button type="button" onclick="setStatusFilter('all')" data-status="all"
                                    class="status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap">
                                Todos (<?= count($tickets) ?>)
                            </button>
                            <button type="button" onclick="setStatusFilter('open')" data-status="open"
                                    class="status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                                Abiertos (<?= $countBy['open'] ?>)
                            </button>
                            <button type="button" onclick="setStatusFilter('in_progress')" data-status="in_progress"
                                    class="status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                                En Atención (<?= $countBy['in_progress'] ?>)
                            </button>
                            <button type="button" onclick="setStatusFilter('closed')" data-status="closed"
                                    class="status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap">
                                Cerrados (<?= $countBy['closed'] ?>)
                            </button>
                        </div>
                    </div>

                    <!-- Tickets Scrollable List -->
                    <div class="flex-1 overflow-y-auto p-2.5 space-y-1.5 scrollbar-custom" id="ticket-inbox-items">
                        <?php if (empty($tickets)): ?>
                        <div class="flex flex-col items-center justify-center py-16 px-4 text-center space-y-3">
                            <div class="w-12 h-12 rounded-2xl bg-white/[0.02] border border-white/[0.06] flex items-center justify-center text-text-subtle">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-text-heading">Sin tickets registrados</p>
                                <p class="text-[11px] text-text-subtle mt-0.5">Crea tu primera consulta para recibir asistencia</p>
                            </div>
                            <button type="button" onclick="openNewTicketModal()" class="px-3 py-1.5 rounded-lg bg-cyan-600/20 text-cyan-300 border border-cyan-500/30 text-xs font-medium hover:bg-cyan-600/30 transition-colors">
                                + Nuevo Ticket
                            </button>
                        </div>
                        <?php else: ?>
                        <?php foreach ($tickets as $t):
                            $tid = $t['_id'] ?? '';
                            $tSubject = $t['subject'] ?? ($t['title'] ?? 'Ticket sin asunto');
                            $tStatus = $t['status'] ?? 'open';
                            $tPriority = $t['priority'] ?? 'medium';
                            $st = $statusCfg[$tStatus] ?? $statusCfg['open'];
                            $pr = $prioCfg[$tPriority] ?? $prioCfg['medium'];
                            $isActive = ($selectedId === $tid);
                            $msgCount = count($t['messages'] ?? []);
                            $lastMsg = !empty($t['messages']) ? end($t['messages']) : null;
                            $snippet = $lastMsg ? ($lastMsg['content'] ?? ($lastMsg['message'] ?? ($t['description'] ?? ''))) : ($t['description'] ?? '');
                            $shortId = substr($tid, -6);
                            $dateStr = substr($t['updatedAt'] ?? ($t['createdAt'] ?? ''), 0, 10);
                        ?>
                        <a href="/tickets?ticket=<?= urlencode($tid) ?>"
                           data-ticket-item
                           data-status="<?= h($tStatus) ?>"
                           data-search="<?= h(mb_strtolower($tSubject . ' ' . $snippet . ' ' . $shortId)) ?>"
                           class="block p-3 rounded-xl border transition-all duration-200 <?= $isActive
                               ? 'bg-primary-500/15 border-primary-500/35 shadow-[0_0_15px_rgba(59,130,246,0.15)] ring-1 ring-primary-500/20'
                               : 'border-border-theme/70 bg-bg-surface/30 hover:bg-bg-elevated hover:border-surface-600' ?>">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <span class="text-[9px] font-mono text-cyan-400 font-medium px-1.5 py-0.5 rounded bg-cyan-950/40 border border-cyan-500/20">
                                    #TK-<?= h(strtoupper($shortId)) ?>
                                </span>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[9px] px-1.5 py-0.5 rounded-full border inline-flex items-center gap-1 font-medium <?= $st['class'] ?>">
                                        <span class="w-1 h-1 rounded-full <?= $st['dot'] ?>"></span>
                                        <?= h($st['label']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <h3 class="text-xs font-semibold <?= $isActive ? 'text-white' : 'text-text-heading' ?> truncate leading-snug">
                                <?= h($tSubject) ?>
                            </h3>
                            
                            <p class="text-[11px] text-text-subtle truncate mt-1 leading-relaxed">
                                <?= h($snippet ?: 'Sin contenido adicional') ?>
                            </p>

                            <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-white/[0.04] text-[10px] text-text-subtle">
                                <span class="inline-flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $pr['dot'] ?>"></span>
                                    Prioridad <?= h($pr['label']) ?>
                                </span>
                                <div class="flex items-center gap-2">
                                    <?php if ($msgCount > 1): ?>
                                    <span class="inline-flex items-center gap-1 font-mono text-text-muted">
                                        <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                        <?= $msgCount ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="font-mono"><?= h($dateStr) ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Pane: Active Ticket Conversation Workspace -->
                <div class="flex-1 flex flex-col min-w-0 bg-bg-panel/40 overflow-hidden <?= (!$selected && !isset($_GET['ticket'])) ? 'hidden md:flex' : 'flex' ?>">
                    <?php if (!$selected): ?>
                    <!-- Empty selection placeholder -->
                    <div class="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-4">
                        <div class="w-16 h-16 rounded-3xl bg-gradient-to-tr from-cyan-500/10 to-primary-600/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 shadow-theme">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                        </div>
                        <div class="max-w-sm space-y-1">
                            <h2 class="text-sm font-bold text-white">Bandeja de Conversaciones</h2>
                            <p class="text-xs text-text-muted">Selecciona un ticket de la lista para ver el historial de mensajes o abre una nueva consulta técnica.</p>
                        </div>

                        <!-- FAQ Help suggestions -->
                        <div class="pt-4 max-w-md w-full grid grid-cols-1 gap-2 text-left">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-text-subtle px-1">Temas Frecuentes de Asistencia:</p>
                            <button type="button" onclick="presetTicketSubject('Duda sobre Obligaciones de Registro RAT (Ley 21.719)', 'compliance')"
                                    class="p-2.5 rounded-xl bg-bg-surface/50 border border-border-theme hover:border-cyan-500/40 text-xs text-text-body hover:text-white transition-all flex items-center justify-between">
                                <span>📜 Cumplimiento y RAT (Ley 21.719)</span>
                                <span class="text-cyan-400 text-[11px]">Abrir ticket →</span>
                            </button>
                            <button type="button" onclick="presetTicketSubject('Error en conexión de agente Host Monitor', 'agent')"
                                    class="p-2.5 rounded-xl bg-bg-surface/50 border border-border-theme hover:border-cyan-500/40 text-xs text-text-body hover:text-white transition-all flex items-center justify-between">
                                <span>🖥️ Despliegue de Agentes de Monitoreo</span>
                                <span class="text-cyan-400 text-[11px]">Abrir ticket →</span>
                            </button>
                            <button type="button" onclick="presetTicketSubject('Reglas de Hardening y Bloqueo WAF', 'security')"
                                    class="p-2.5 rounded-xl bg-bg-surface/50 border border-border-theme hover:border-cyan-500/40 text-xs text-text-body hover:text-white transition-all flex items-center justify-between">
                                <span>🛡️ Seguridad Perimetral y WAF</span>
                                <span class="text-cyan-400 text-[11px]">Abrir ticket →</span>
                            </button>
                        </div>
                    </div>
                    <?php else:
                        $sId = $selected['_id'] ?? '';
                        $sSubject = $selected['subject'] ?? ($selected['title'] ?? 'Ticket');
                        $sStatus = $selected['status'] ?? 'open';
                        $sPriority = $selected['priority'] ?? 'medium';
                        $sCreatedAt = $selected['createdAt'] ?? '';
                        $sClosed = ($sStatus === 'closed');
                        $st = $statusCfg[$sStatus] ?? $statusCfg['open'];
                        $pr = $prioCfg[$sPriority] ?? $prioCfg['medium'];
                        $messages = $selected['messages'] ?? [];
                    ?>
                    
                    <!-- Ticket Header in View -->
                    <div class="px-5 py-3.5 border-b border-border-theme flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-bg-surface/40 flex-shrink-0">
                        <div class="flex items-center gap-3 min-w-0">
                            <!-- Mobile back button -->
                            <a href="/tickets" class="md:hidden p-1.5 rounded-lg bg-bg-elevated border border-border-theme text-text-muted hover:text-white">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </a>

                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[10px] font-mono font-bold text-cyan-400 bg-cyan-950/50 px-2 py-0.5 rounded border border-cyan-500/30">
                                        #TK-<?= h(strtoupper(substr($sId, -6))) ?>
                                    </span>
                                    <h2 class="text-sm font-bold text-white truncate max-w-md"><?= h($sSubject) ?></h2>
                                </div>
                                <p class="text-[10px] text-text-subtle mt-0.5">
                                    Iniciado el <?= h(substr($sCreatedAt, 0, 16)) ?>
                                </p>
                            </div>
                        </div>

                        <!-- Ticket Status & Actions -->
                        <div class="flex items-center gap-2 flex-shrink-0 self-end sm:self-center">
                            <span class="text-[10px] px-2.5 py-1 rounded-full border font-medium inline-flex items-center gap-1.5 <?= $st['class'] ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $st['dot'] ?>"></span>
                                <?= h($st['label']) ?>
                            </span>

                            <span class="text-[10px] px-2.5 py-1 rounded-full border font-medium inline-flex items-center gap-1.5 <?= $pr['class'] ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $pr['dot'] ?>"></span>
                                <?= h($pr['label']) ?>
                            </span>

                            <?php if (!$sClosed): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="close_ticket">
                                <input type="hidden" name="ticket_id" value="<?= h($sId) ?>">
                                <button type="submit" name="close_ticket" value="1" onclick="return confirm('¿Marcar este ticket como resuelto y cerrarlo?')"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-red-950/20 hover:bg-red-950/40 text-red-400 border border-red-800/30 transition-all">
                                    Cerrar Ticket
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="reopen_ticket">
                                <input type="hidden" name="ticket_id" value="<?= h($sId) ?>">
                                <button type="submit" name="reopen_ticket" value="1"
                                        class="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-emerald-950/20 hover:bg-emerald-950/40 text-emerald-400 border border-emerald-800/30 transition-all">
                                    Reabrir Ticket
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Messages Timeline Scrollable Area -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-4 scrollbar-custom" id="ticket-thread-scroll">
                        
                        <!-- Initial ticket description if no messages or prepended -->
                        <?php if (empty($messages) && !empty($selected['description'])): ?>
                        <div class="flex items-start gap-3 max-w-2xl">
                            <div class="w-8 h-8 rounded-xl bg-cyan-600/20 border border-cyan-500/30 flex items-center justify-center text-cyan-300 font-bold text-xs shrink-0">
                                <?= h($userInitials) ?>
                            </div>
                            <div class="space-y-1 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-white"><?= h($userDisplayName) ?></span>
                                    <span class="text-[10px] text-text-subtle"><?= h(substr($sCreatedAt, 0, 16)) ?></span>
                                </div>
                                <div class="p-3.5 rounded-2xl rounded-tl-none bg-bg-surface/80 border border-border-theme text-xs text-text-body leading-relaxed whitespace-pre-wrap">
                                    <?= h($selected['description']) ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Message Bubbles -->
                        <?php foreach ($messages as $idx => $m):
                            $mRole = $m['role'] ?? ($m['from'] ?? 'user');
                            $isSupport = in_array($mRole, ['support', 'admin', 'agent']);
                            $mAuthor = $m['authorName'] ?? ($isSupport ? 'Soporte SecureLab' : $userDisplayName);
                            $mContent = $m['content'] ?? ($m['message'] ?? ($m['text'] ?? ''));
                            $mDate = substr($m['createdAt'] ?? ($m['at'] ?? ''), 0, 16);
                        ?>
                        <div class="flex items-start gap-3 <?= $isSupport ? 'flex-row' : 'flex-row-reverse' ?> max-w-2xl <?= $isSupport ? 'mr-auto' : 'ml-auto' ?>">
                            <!-- Avatar -->
                            <div class="w-8 h-8 rounded-xl shrink-0 flex items-center justify-center text-xs font-bold shadow-theme-sm <?= $isSupport
                                ? 'bg-gradient-to-br from-indigo-600 to-primary-600 text-white border border-indigo-400/30'
                                : 'bg-gradient-to-br from-cyan-600/30 to-blue-600/30 text-cyan-300 border border-cyan-500/30' ?>">
                                <?= $isSupport
                                    ? '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>'
                                    : h($userInitials) ?>
                            </div>

                            <!-- Bubble Content -->
                            <div class="space-y-1 flex-1 <?= $isSupport ? 'text-left' : 'text-right' ?>">
                                <div class="flex items-center gap-2 <?= $isSupport ? 'justify-start' : 'justify-end' ?>">
                                    <span class="text-xs font-semibold text-white"><?= h($mAuthor) ?></span>
                                    <?php if ($isSupport): ?>
                                    <span class="text-[9px] px-1.5 py-0.2 rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-medium">ESPECIALISTA</span>
                                    <?php endif; ?>
                                    <span class="text-[10px] text-text-subtle font-mono"><?= h($mDate) ?></span>
                                </div>

                                <div class="p-3.5 rounded-2xl text-xs leading-relaxed whitespace-pre-wrap text-left <?= $isSupport
                                    ? 'bg-bg-elevated/90 border border-indigo-500/20 text-text-heading rounded-tl-none shadow-theme-sm'
                                    : 'bg-gradient-to-br from-primary-600/30 to-blue-700/25 border border-primary-500/30 text-white rounded-tr-none shadow-theme-sm' ?>">
                                    <?= h($mContent) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if ($sClosed): ?>
                        <div class="flex justify-center pt-4">
                            <div class="px-4 py-2 rounded-2xl bg-bg-surface border border-border-theme flex items-center gap-2 text-text-subtle text-xs">
                                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Este ticket se encuentra cerrado y resuelto. Puedes reabrirlo si requieres asistencia continua.</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Reply Bar -->
                    <?php if (!$sClosed): ?>
                    <div class="p-3.5 border-t border-border-theme bg-bg-surface/50 backdrop-blur-md flex-shrink-0">
                        <form method="POST" class="flex gap-2 items-end">
                            <input type="hidden" name="action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?= h($sId) ?>">
                            
                            <div class="flex-1 relative">
                                <textarea name="reply" rows="2" required
                                          placeholder="Escribe tu respuesta o consulta aquí..."
                                          class="w-full bg-[#0a0e14] border border-border-theme rounded-xl px-3.5 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all resize-none"></textarea>
                            </div>

                            <button type="submit" name="reply_ticket" value="1"
                                    class="px-5 py-3 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-cyan-500/20 transition-all duration-200 flex items-center gap-1.5 self-stretch justify-center">
                                <span>Enviar</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<!-- Modal: Abrir Nuevo Ticket -->
<div id="new-ticket-modal" class="hidden fixed inset-0 z-50 bg-black/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-bg-panel border border-border-theme rounded-2xl max-w-xl w-full p-6 space-y-5 shadow-2xl animate-fade-in-up">
        
        <div class="flex items-center justify-between border-b border-border-theme pb-3.5">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-white">Abrir Nuevo Ticket de Soporte</h3>
                    <p class="text-[11px] text-text-muted">Un ingeniero de seguridad atenderá tu solicitud</p>
                </div>
            </div>
            <button type="button" onclick="closeNewTicketModal()" class="text-text-subtle hover:text-white p-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_ticket">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Categoría -->
                <div class="space-y-1.5">
                    <label class="label-premium">Área o Módulo</label>
                    <select name="category" id="nt-category" class="input-premium">
                        <option value="compliance">📜 Cumplimiento Ley 21.719</option>
                        <option value="agent">🖥️ Agentes & Host Monitor</option>
                        <option value="database">🗄️ Bases de Datos & Logs</option>
                        <option value="hardening">🛡️ Hardening & WAF</option>
                        <option value="account">👤 Cuenta & Facturación</option>
                        <option value="general" selected>⚙️ Soporte General</option>
                    </select>
                </div>

                <!-- Prioridad -->
                <div class="space-y-1.5">
                    <label class="label-premium">Nivel de Prioridad</label>
                    <select name="priority" id="nt-priority" class="input-premium">
                        <option value="low">Baja (Consultas y sugerencias)</option>
                        <option value="medium" selected>Media (Incidencias estándar)</option>
                        <option value="high">Alta (Afectación de servicio)</option>
                        <option value="critical">Crítica (Incidente de seguridad)</option>
                    </select>
                </div>
            </div>

            <!-- Asunto -->
            <div class="space-y-1.5">
                <label class="label-premium">Asunto del Ticket</label>
                <div class="relative">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted pointer-events-none">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                    </div>
                    <input type="text" name="subject" id="nt-subject" required
                           class="w-full bg-[#0a0e14] border border-border-theme rounded-xl pl-9 pr-3 py-2.5 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all"
                           placeholder="Describe brevemente el motivo del contacto">
                </div>
            </div>

            <!-- Mensaje detallado -->
            <div class="space-y-1.5">
                <label class="label-premium flex items-center justify-between">
                    <span>Descripción Detallada</span>
                    <span class="text-[10px] text-text-subtle">Incluye pasos o detalles relevantes</span>
                </label>
                <textarea name="message" id="nt-message" rows="4" required
                          class="w-full bg-[#0a0e14] border border-border-theme rounded-xl p-3 text-xs text-white placeholder-text-subtle focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all resize-none"
                          placeholder="Describe qué ocurrió, qué módulos están involucrados o qué duda regulatoria necesitas resolver..."></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-border-theme">
                <button type="button" onclick="closeNewTicketModal()" class="btn-secondary text-xs px-4 py-2.5 rounded-xl">
                    Cancelar
                </button>
                <button type="submit" name="create_ticket" value="1"
                        class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white text-xs font-semibold shadow-theme-sm hover:shadow-cyan-500/20 transition-all duration-200 inline-flex items-center gap-2">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Registrar Ticket
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentStatusFilter = 'all';

function setStatusFilter(st) {
    currentStatusFilter = st;
    document.querySelectorAll('.status-tab-btn').forEach(btn => {
        const isCurrent = btn.getAttribute('data-status') === st;
        if (isCurrent) {
            btn.className = 'status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all bg-primary-500/15 text-primary-300 border border-primary-500/30 whitespace-nowrap';
        } else {
            btn.className = 'status-tab-btn px-2.5 py-1 rounded-lg text-[10px] font-medium transition-all text-text-muted hover:text-white hover:bg-white/[0.04] border border-transparent whitespace-nowrap';
        }
    });
    filterTicketsList();
}

function filterTicketsList() {
    const q = (document.getElementById('ticket-search')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#ticket-inbox-items a[data-ticket-item]').forEach(el => {
        const itemStatus = el.getAttribute('data-status') || 'open';
        const itemSearch = el.getAttribute('data-search') || '';

        const matchesStatus = (currentStatusFilter === 'all') || (itemStatus === currentStatusFilter);
        const matchesQuery = !q || (itemSearch.indexOf(q) !== -1);

        if (matchesStatus && matchesQuery) {
            el.style.display = 'block';
        } else {
            el.style.display = 'none';
        }
    });
}

function openNewTicketModal() {
    const modal = document.getElementById('new-ticket-modal');
    if (modal) {
        modal.classList.remove('hidden');
        document.getElementById('nt-subject')?.focus();
    }
}

function closeNewTicketModal() {
    const modal = document.getElementById('new-ticket-modal');
    if (modal) modal.classList.add('hidden');
}

function presetTicketSubject(subject, category) {
    openNewTicketModal();
    const subjInp = document.getElementById('nt-subject');
    const catInp = document.getElementById('nt-category');
    if (subjInp) subjInp.value = subject;
    if (catInp && category) catInp.value = category;
    document.getElementById('nt-message')?.focus();
}

// Auto-scroll chat thread to bottom
document.addEventListener('DOMContentLoaded', () => {
    const thread = document.getElementById('ticket-thread-scroll');
    if (thread) {
        thread.scrollTop = thread.scrollHeight;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
