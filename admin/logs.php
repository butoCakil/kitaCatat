<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'System Logs';
require_once __DIR__ . '/layout/header.php';

$db      = getDB();
$tab     = $_GET['tab']    ?? 'incoming';
$subtab  = $_GET['subtab'] ?? 'scheduled';

// ============================================================
// Per-page options
// ============================================================
$allowedPerPage = [15, 30, 50, 100, 500];
$limitInput     = (int)($_GET['limit'] ?? 50);
$perPage        = in_array($limitInput, $allowedPerPage) ? $limitInput : 50;

// ============================================================
// TAB 1 — Incoming WA Log
// ============================================================
$incomingLog = [];
$logFile     = __DIR__ . '/../logs/incoming.log';
$page        = max(1, (int)($_GET['page'] ?? 1));
if (file_exists($logFile)) {
    $lines       = array_reverse(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $total       = count($lines);
    $incomingLog = array_slice($lines, ($page - 1) * $perPage, $perPage);
} else {
    $total = 0;
}
$pages = ceil($total / $perPage);

// ============================================================
// TAB 1b — Outgoing WA Log
// ============================================================
$outgoingLog = [];
$outLogFile  = __DIR__ . '/../logs/outgoing.log';
$outPage     = max(1, (int)($_GET['opage'] ?? 1));
if (file_exists($outLogFile)) {
    $outLines    = array_reverse(file($outLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $outTotal    = count($outLines);
    $outgoingLog = array_slice($outLines, ($outPage - 1) * $perPage, $perPage);
} else {
    $outTotal = 0;
}
$outPages = ceil($outTotal / $perPage);

// ============================================================
// TAB — PERCAKAPAN (parse kedua log, gabung per nomor)
// ============================================================
$convUsers      = [];   // [ wa_number => ['name'=>..., 'count'=>...] ]
$convMessages   = [];   // [ wa_number => [ ['dir'=>'in'/'out', 'ts'=>..., 'msg'=>...], ... ] ]
$convFilter     = $_GET['conv_user']  ?? '';
$convDateFilter = $_GET['conv_date']  ?? '';
$convPage       = max(1, (int)($_GET['cpage'] ?? 1));

if ($tab === 'conversation') {
    // Parse incoming
    if (file_exists($logFile)) {
        $allIn = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($allIn as $line) {
            // Format: 2026-03-30 07:26:45 | FROM: 628xxx | NAME: MasBen | MSG: Rekap
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| FROM: (\d+) \| NAME: ([^|]+) \| MSG: (.+)$/', $line, $m)) continue;
            [$_, $ts, $num, $name, $msg] = $m;
            $name = trim($name);
            $num  = trim($num);
            if (!isset($convUsers[$num])) {
                $convUsers[$num] = ['name' => $name, 'count' => 0, 'last_ts' => $ts];
            }
            $convUsers[$num]['count']++;
            if ($ts > $convUsers[$num]['last_ts']) {
                $convUsers[$num]['last_ts'] = $ts;
                $convUsers[$num]['name']    = $name; // update nama terbaru
            }
            $convMessages[$num][] = ['dir' => 'in', 'ts' => $ts, 'msg' => trim($msg)];
        }
    }
    // Parse outgoing
    if (file_exists($outLogFile)) {
        $allOut = file($outLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($allOut as $line) {
            // Format: 2026-03-30 07:26:46 | TO: 628xxx | STATUS: OK | success!... | MSG: ...
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| TO: (\d+) \| STATUS: (\w+) \| [^|]+ \| MSG: (.+)$/', $line, $m)) continue;
            [$_, $ts, $num, $status, $msg] = $m;
            $num = trim($num);
            if (!isset($convUsers[$num])) {
                $convUsers[$num] = ['name' => $num, 'count' => 0, 'last_ts' => $ts];
            }
            $convUsers[$num]['count']++;
            if ($ts > $convUsers[$num]['last_ts']) $convUsers[$num]['last_ts'] = $ts;
            $convMessages[$num][] = ['dir' => 'out', 'ts' => $ts, 'msg' => trim($msg), 'status' => $status];
        }
    }
    // Sort user list by last activity desc
    uasort($convUsers, fn($a, $b) => strcmp($b['last_ts'], $a['last_ts']));

    // Sort messages per user by timestamp asc
    foreach ($convMessages as $num => &$msgs) {
        usort($msgs, fn($a, $b) => strcmp($a['ts'], $b['ts']));
    }
    unset($msgs);
}

// ============================================================
// TAB 2 — Audit Admin
// ============================================================
$adminLogs = $db->prepare(
    "SELECT al.*, a.name AS admin_name FROM admin_logs al
     JOIN admins a ON a.id = al.admin_id
     ORDER BY al.created_at DESC LIMIT $perPage"
);
$adminLogs->execute();
$adminLogs = $adminLogs->fetchAll();

// ============================================================
// TAB 3 — Log DB
// ============================================================
$scheduledLogs = $db->prepare(
    "SELECT sl.*, u.name AS user_name, u.wa_number,
            st.title AS sched_title
     FROM scheduled_logs sl
     LEFT JOIN users u  ON u.id  = sl.user_id
     LEFT JOIN scheduled_transactions st ON st.id = sl.scheduled_id
     ORDER BY sl.created_at DESC LIMIT $perPage"
);
$scheduledLogs->execute();
$scheduledLogs = $scheduledLogs->fetchAll();

$pendingShared = $db->prepare(
    "SELECT ps.*, u.name AS user_name, u.wa_number,
            t.description AS txn_desc, t.amount, t.type AS txn_type
     FROM pending_shared ps
     LEFT JOIN users u        ON u.id = ps.user_id
     LEFT JOIN transactions t ON t.id = ps.transaction_id
     ORDER BY ps.created_at DESC LIMIT $perPage"
);
$pendingShared->execute();
$pendingShared = $pendingShared->fetchAll();

$otpLogs = $db->prepare(
    "SELECT * FROM otp_register ORDER BY created_at DESC LIMIT $perPage"
);
$otpLogs->execute();
$otpLogs = $otpLogs->fetchAll();

// ============================================================
// TAB 4 — Error Log
// ============================================================
$errorFiles = [];
if ($tab === 'errorlog') {
    $rootDir  = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if (
            $file->isFile() &&
            stripos($file->getFilename(), 'error_log') !== false &&
            $file->getSize() > 0
        ) {
            $errorFiles[] = [
                'path'     => $file->getPathname(),
                'rel_path' => str_replace($rootDir . '/', '', $file->getPathname()),
                'size'     => $file->getSize(),
                'mtime'    => $file->getMTime(),
            ];
        }
    }
    usort($errorFiles, fn($a, $b) => $b['mtime'] - $a['mtime']);
}

$selectedErrFile = null;
$errFileContent  = [];
$errFilePath     = $_GET['errfile'] ?? '';
if ($tab === 'errorlog' && $errFilePath !== '') {
    $rootDir    = dirname(__DIR__);
    $realTarget = realpath($rootDir . '/' . ltrim($errFilePath, '/'));
    if ($realTarget && strpos($realTarget, realpath($rootDir)) === 0 && file_exists($realTarget)) {
        $selectedErrFile = $realTarget;
        $rawLines        = file($realTarget, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $errFilContent   = array_reverse($rawLines);
        $errTotal        = count($errFilContent);
        $errPage         = max(1, (int)($_GET['epage'] ?? 1));
        $errPages        = ceil($errTotal / $perPage);
        $errFileContent  = array_slice($errFilContent, ($errPage - 1) * $perPage, $perPage);
    }
}

// ============================================================
// Helpers
// ============================================================
function statusBadge(string $status): string {
    $map = [
        'pending'   => ['#fef9c3','#854d0e'],
        'confirmed' => ['#dcfce7','#15803d'],
        'skipped'   => ['#f1f5f9','#64748b'],
        'snoozed'   => ['#ede9fe','#6d28d9'],
        'waiting'   => ['#fef9c3','#854d0e'],
        'cancelled' => ['#fee2e2','#b91c1c'],
        'auto'      => ['#dbeafe','#1d4ed8'],
    ];
    [$bg, $fg] = $map[$status] ?? ['#f1f5f9','#64748b'];
    return "<span style=\"background:{$bg};color:{$fg};font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:4px\">{$status}</span>";
}

function fmtRp(int $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function urlWithLimit(int $limit, string $tab, string $subtab = ''): string {
    $q = ['tab' => $tab, 'limit' => $limit];
    if ($subtab) $q['subtab'] = $subtab;
    return '?' . http_build_query($q);
}

function perPageSelector(int $current, string $tab, string $subtab = '', array $extra = []): string {
    $options = [15, 30, 50, 100, 500];
    $labels  = [15 => '15', 30 => '30', 50 => '50', 100 => '100', 500 => '500 (maks)'];
    $html    = '<div class="d-flex align-items-center gap-1">'
             . '<span style="font-size:11px;color:var(--text-muted)">Tampilkan:</span>';
    foreach ($options as $opt) {
        $q   = array_merge(['tab' => $tab, 'limit' => $opt], $subtab ? ['subtab' => $subtab] : [], $extra);
        $url = '?' . http_build_query($q);
        $active = $opt === $current;
        $html .= '<a href="' . $url . '" style="font-size:11px;padding:2px 8px;border-radius:4px;text-decoration:none;'
               . ($active
                    ? 'background:var(--admin-primary);color:#fff;font-weight:600'
                    : 'background:#f1f5f9;color:var(--text-secondary)')
               . '">' . $labels[$opt] . '</a>';
    }
    $html .= '</div>';
    return $html;
}
?>

<div class="mb-4">
    <h5 class="mb-1 fw-bold">System Logs</h5>
    <p class="text-muted mb-0" style="font-size:13px">Monitor aktivitas sistem dan audit trail admin</p>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" style="border-color:var(--card-border)">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'incoming' ? 'active' : '' ?>"
           href="?tab=incoming&limit=<?= $perPage ?>" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-message me-2"></i>Pesan Masuk
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'outgoing' ? 'active' : '' ?>"
           href="?tab=outgoing&limit=<?= $perPage ?>" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-paper-plane me-2"></i>Pesan Keluar
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'conversation' ? 'active' : '' ?>"
           href="?tab=conversation" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-comments me-2"></i>Percakapan
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>"
           href="?tab=audit&limit=<?= $perPage ?>" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-shield-halved me-2"></i>Audit Admin
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'dblog' ? 'active' : '' ?>"
           href="?tab=dblog&subtab=scheduled&limit=<?= $perPage ?>" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-database me-2"></i>Log DB
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'errorlog' ? 'active' : '' ?>"
           href="?tab=errorlog&limit=<?= $perPage ?>" style="font-size:13px;font-weight:600">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>Error Log
        </a>
    </li>
</ul>

<?php /* ======================================================
   TAB 1 — PESAN MASUK WA
   ====================================================== */ ?>
<?php if ($tab === 'incoming'): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="fa-solid fa-terminal me-2 text-muted"></i>Incoming Messages Log</span>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?= perPageSelector($perPage, 'incoming') ?>
            <span class="text-muted" style="font-size:12px"><?= number_format($total) ?> entri</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="copyLogContent('incoming-log-box')"
                style="font-size:11px;padding:3px 10px">
                <i class="fa-solid fa-copy me-1"></i>Copy
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="clearLog()"
                style="font-size:11px;padding:3px 10px">
                <i class="fa-solid fa-trash me-1"></i>Clear Log
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($incomingLog)): ?>
        <div class="text-center text-muted py-5">
            <i class="fa-solid fa-file-slash fa-2x d-block mb-2" style="opacity:.3"></i>
            Log kosong atau LOG_INCOMING nonaktif
        </div>
        <?php else: ?>
        <div id="incoming-log-box" style="background:#0f172a;padding:16px;overflow-x:auto;max-height:600px;overflow-y:auto">
            <?php foreach ($incomingLog as $line):
                $color = '#94a3b8';
                if (stripos($line, 'Error') !== false) $color = '#f87171';
                if (strpos($line, 'FROM:')  !== false) $color = '#6ee7b7';
                if (strpos($line, 'MSG:')   !== false) $color = '#fde68a';
            ?>
            <div style="font-family:var(--font-mono);font-size:12px;color:<?= $color ?>;padding:2px 0;line-height:1.5;white-space:pre-wrap"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
            <span style="font-size:12px;color:var(--text-muted)">Halaman <?= $page ?> dari <?= $pages ?></span>
            <div class="d-flex gap-1">
                <?php if ($page > 1): ?>
                <a href="?tab=incoming&limit=<?= $perPage ?>&page=<?= $page - 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">← Prev</a>
                <?php endif; ?>
                <?php if ($page < $pages): ?>
                <a href="?tab=incoming&limit=<?= $perPage ?>&page=<?= $page + 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ======================================================
   TAB 1b — PESAN KELUAR WA
   ====================================================== */ ?>
<?php elseif ($tab === 'outgoing'): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="fa-solid fa-paper-plane me-2 text-muted"></i>Outgoing Messages Log</span>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?= perPageSelector($perPage, 'outgoing') ?>
            <span class="text-muted" style="font-size:12px"><?= number_format($outTotal) ?> entri</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="copyLogContent('outgoing-log-box')"
                style="font-size:11px;padding:3px 10px">
                <i class="fa-solid fa-copy me-1"></i>Copy
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="clearOutgoingLog()"
                style="font-size:11px;padding:3px 10px">
                <i class="fa-solid fa-trash me-1"></i>Clear Log
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($outgoingLog)): ?>
        <div class="text-center text-muted py-5">
            <i class="fa-solid fa-file-slash fa-2x d-block mb-2" style="opacity:.3"></i>
            Log kosong atau LOG_OUTGOING nonaktif
        </div>
        <?php else: ?>
        <div id="outgoing-log-box" style="background:#0f172a;padding:16px;overflow-x:auto;max-height:600px;overflow-y:auto">
            <?php foreach ($outgoingLog as $line):
                $color = '#94a3b8';
                if (strpos($line, 'STATUS: FAIL') !== false) $color = '#f87171';
                if (strpos($line, 'STATUS: OK')   !== false) $color = '#6ee7b7';
            ?>
            <div style="font-family:var(--font-mono);font-size:12px;color:<?= $color ?>;padding:2px 0;line-height:1.5;white-space:pre-wrap"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($outPages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
            <span style="font-size:12px;color:var(--text-muted)">Halaman <?= $outPage ?> dari <?= $outPages ?></span>
            <div class="d-flex gap-1">
                <?php if ($outPage > 1): ?>
                <a href="?tab=outgoing&limit=<?= $perPage ?>&opage=<?= $outPage - 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">← Prev</a>
                <?php endif; ?>
                <?php if ($outPage < $outPages): ?>
                <a href="?tab=outgoing&limit=<?= $perPage ?>&opage=<?= $outPage + 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php /* ======================================================
   TAB — PERCAKAPAN
   ====================================================== */ ?>
<?php elseif ($tab === 'conversation'): ?>

<?php
// ---- Filter & paginasi pesan untuk user terpilih ----
$selectedMsgs  = [];
$convMsgTotal  = 0;
$convMsgPages  = 1;
if ($convFilter !== '' && isset($convMessages[$convFilter])) {
    $allMsgs = $convMessages[$convFilter];
    // Filter tanggal jika ada
    if ($convDateFilter !== '') {
        $allMsgs = array_values(array_filter($allMsgs, fn($m) => substr($m['ts'], 0, 10) === $convDateFilter));
    }
    // Balik agar terbaru di atas, lalu paginate, lalu balik lagi agar tampil kronologis
    $allMsgs      = array_reverse($allMsgs);
    $convMsgTotal = count($allMsgs);
    $convMsgPages = max(1, (int)ceil($convMsgTotal / $perPage));
    $convPage     = min($convPage, $convMsgPages);
    $selectedMsgs = array_reverse(array_slice($allMsgs, ($convPage - 1) * $perPage, $perPage));
}
// Daftar tanggal unik untuk user terpilih (untuk dropdown filter)
$convDateOptions = [];
if ($convFilter !== '' && isset($convMessages[$convFilter])) {
    foreach ($convMessages[$convFilter] as $m) {
        $d = substr($m['ts'], 0, 10);
        if (!in_array($d, $convDateOptions)) $convDateOptions[] = $d;
    }
    rsort($convDateOptions);
}
?>

<div class="row g-0" style="height:600px;min-height:400px;overflow:hidden">

    <!-- ===== Panel kiri: daftar user ===== -->
    <div class="col-md-3" style="border-right:1px solid var(--card-border);display:flex;flex-direction:column">
        <div class="px-3 py-2 border-bottom" style="background:#f8fafc">
            <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                <i class="fa-solid fa-users me-1"></i>Pengguna (<?= count($convUsers) ?>)
            </span>
        </div>
        <div style="overflow-y:auto;flex:1">
            <?php if (empty($convUsers)): ?>
            <div class="text-center text-muted py-5 px-3" style="font-size:13px">
                <i class="fa-solid fa-inbox fa-2x d-block mb-2" style="opacity:.3"></i>
                Belum ada log percakapan
            </div>
            <?php else: ?>
            <?php foreach ($convUsers as $num => $udata):
                $isActive  = ($convFilter === $num);
                $inCount   = count(array_filter($convMessages[$num] ?? [], fn($m) => $m['dir'] === 'in'));
                $outCount  = count(array_filter($convMessages[$num] ?? [], fn($m) => $m['dir'] === 'out'));
                $lastTs    = $udata['last_ts'];
                $activeUrl = '?tab=conversation&conv_user=' . urlencode($num) . '&limit=' . $perPage;
            ?>
            <a href="<?= $activeUrl ?>"
               style="display:block;padding:10px 14px;border-bottom:1px solid var(--card-border);text-decoration:none;
                      background:<?= $isActive ? 'var(--admin-primary)' : 'transparent' ?>;
                      color:<?= $isActive ? '#fff' : 'inherit' ?>">
                <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= htmlspecialchars($udata['name']) ?>
                </div>
                <div style="font-size:10.5px;font-family:var(--font-mono);opacity:.75;margin-top:1px">
                    <?= htmlspecialchars($num) ?>
                </div>
                <div style="font-size:10.5px;margin-top:3px;opacity:.8">
                    <span title="Masuk" style="margin-right:6px">
                        <i class="fa-solid fa-arrow-down" style="font-size:9px"></i> <?= $inCount ?>
                    </span>
                    <span title="Keluar">
                        <i class="fa-solid fa-arrow-up" style="font-size:9px"></i> <?= $outCount ?>
                    </span>
                    <span style="float:right;font-size:10px;opacity:.7"><?= date('d/m H:i', strtotime($lastTs)) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== Panel kanan: percakapan ===== -->
    <div class="col-md-9" style="display:flex;flex-direction:column;min-height:0;overflow:hidden">

        <?php if ($convFilter === '' || !isset($convMessages[$convFilter])): ?>
        <!-- Placeholder kosong -->
        <div style="flex:1;display:flex;align-items:center;justify-content:center">
            <div class="text-center text-muted">
                <i class="fa-solid fa-comment-slash fa-3x mb-3" style="opacity:.2"></i>
                <div style="font-size:13px">Pilih pengguna di panel kiri<br>untuk melihat percakapan</div>
            </div>
        </div>

        <?php else:
            $udata = $convUsers[$convFilter];
        ?>
        <!-- Header user terpilih -->
        <div class="px-4 py-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2"
             style="background:#f8fafc">
            <div>
                <div style="font-weight:700;font-size:14px"><?= htmlspecialchars($udata['name']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($convFilter) ?></div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Filter tanggal -->
                <form method="get" class="d-flex align-items-center gap-1" style="margin:0">
                    <input type="hidden" name="tab" value="conversation">
                    <input type="hidden" name="conv_user" value="<?= htmlspecialchars($convFilter) ?>">
                    <input type="hidden" name="limit" value="<?= $perPage ?>">
                    <select name="conv_date" onchange="this.form.submit()"
                        style="font-size:11px;padding:3px 6px;border:1px solid #e2e8f0;border-radius:5px;background:#fff">
                        <option value="">Semua tanggal</option>
                        <?php foreach ($convDateOptions as $d): ?>
                        <option value="<?= $d ?>" <?= $convDateFilter === $d ? 'selected' : '' ?>>
                            <?= date('d M Y', strtotime($d)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?= perPageSelector($perPage, 'conversation', '', ['conv_user' => $convFilter, 'conv_date' => $convDateFilter]) ?>
                <button class="btn btn-sm btn-outline-secondary" onclick="copyConversation()"
                    style="font-size:11px;padding:3px 10px">
                    <i class="fa-solid fa-copy me-1"></i>Copy
                </button>
            </div>
        </div>

        <!-- Area chat -->
        <div id="conv-chat-area" style="flex:1;min-height:0;max-height:520px;overflow-y:auto;padding:16px 20px;background:#f1f5f9">
            <?php if (empty($selectedMsgs)): ?>
            <div class="text-center text-muted py-5" style="font-size:13px">
                Tidak ada pesan <?= $convDateFilter ? 'pada tanggal ini' : '' ?>
            </div>
            <?php else: ?>
            <?php
            $prevDate = '';
            foreach ($selectedMsgs as $m):
                $msgDate = date('d M Y', strtotime($m['ts']));
                $msgTime = date('H:i:s', strtotime($m['ts']));
                $isIn    = $m['dir'] === 'in';
                $isFail  = !$isIn && ($m['status'] ?? 'OK') === 'FAIL';
            ?>
            <?php if ($msgDate !== $prevDate): $prevDate = $msgDate; ?>
            <div style="text-align:center;margin:12px 0 8px">
                <span style="background:#e2e8f0;color:#64748b;font-size:10.5px;padding:2px 10px;border-radius:10px">
                    <?= $msgDate ?>
                </span>
            </div>
            <?php endif; ?>

            <div data-dir="<?= $isIn ? 'in' : 'out' ?>" data-ts="<?= htmlspecialchars($m['ts']) ?>"
                 style="display:flex;justify-content:<?= $isIn ? 'flex-start' : 'flex-end' ?>;margin-bottom:6px">
                <div style="max-width:72%;min-width:80px">
                    <div class="bubble-text" style="
                        background:<?= $isIn ? '#ffffff' : ($isFail ? '#fee2e2' : '#dcfce7') ?>;
                        border:1px solid <?= $isIn ? '#e2e8f0' : ($isFail ? '#fca5a5' : '#86efac') ?>;
                        border-radius:<?= $isIn ? '4px 12px 12px 12px' : '12px 4px 12px 12px' ?>;
                        padding:7px 11px;
                        font-size:12.5px;
                        line-height:1.5;
                        word-break:break-word;
                        color:#1e293b;
                        box-shadow:0 1px 2px rgba(0,0,0,.06)
                    "><?= nl2br(htmlspecialchars($m['msg'])) ?></div>
                    <div style="font-size:10px;color:#94a3b8;margin-top:2px;text-align:<?= $isIn ? 'left' : 'right' ?>;padding:0 2px">
                        <?= $msgTime ?>
                        <?php if (!$isIn): ?>
                        <?php if ($isFail): ?>
                        <span style="color:#ef4444;margin-left:4px"><i class="fa-solid fa-circle-xmark" style="font-size:9px"></i> FAIL</span>
                        <?php else: ?>
                        <span style="color:#16a34a;margin-left:4px"><i class="fa-solid fa-check" style="font-size:9px"></i></span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Paginasi chat -->
        <?php if ($convMsgPages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-2 border-top" style="background:#f8fafc">
            <span style="font-size:12px;color:var(--text-muted)"><?= number_format($convMsgTotal) ?> pesan · Hal <?= $convPage ?> / <?= $convMsgPages ?></span>
            <div class="d-flex gap-1">
                <?php if ($convPage > 1): ?>
                <a href="?tab=conversation&conv_user=<?= urlencode($convFilter) ?>&conv_date=<?= urlencode($convDateFilter) ?>&limit=<?= $perPage ?>&cpage=<?= $convPage - 1 ?>"
                   class="btn btn-sm btn-outline-secondary" style="font-size:11px">← Prev</a>
                <?php endif; ?>
                <?php if ($convPage < $convMsgPages): ?>
                <a href="?tab=conversation&conv_user=<?= urlencode($convFilter) ?>&conv_date=<?= urlencode($convDateFilter) ?>&limit=<?= $perPage ?>&cpage=<?= $convPage + 1 ?>"
                   class="btn btn-sm btn-outline-secondary" style="font-size:11px">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; /* end if convFilter */ ?>
    </div><!-- /col kanan -->
</div><!-- /row -->

<?php /* ======================================================
   TAB 2 — AUDIT ADMIN
   ====================================================== */ ?>
<?php elseif ($tab === 'audit'): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="fa-solid fa-shield-halved me-2 text-muted"></i>Audit Trail Admin</span>
        <div class="d-flex align-items-center gap-3">
            <?= perPageSelector($perPage, 'audit') ?>
            <span class="text-muted" style="font-size:12px"><?= count($adminLogs) ?> entri</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($adminLogs)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">Belum ada aktivitas admin</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="py-3">Admin</th>
                        <th class="py-3">Aksi</th>
                        <th class="py-3">Target</th>
                        <th class="py-3">Catatan</th>
                        <th class="py-3">IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($adminLogs as $log): ?>
                <tr>
                    <td class="px-4 py-2" style="font-size:11px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M Y H:i', strtotime($log['created_at'])) ?>
                    </td>
                    <td class="py-2" style="font-weight:600"><?= htmlspecialchars($log['admin_name']) ?></td>
                    <td class="py-2">
                        <span style="font-family:var(--font-mono);font-size:11.5px;background:#f1f5f9;padding:2px 6px;border-radius:4px">
                            <?= htmlspecialchars($log['action']) ?>
                        </span>
                    </td>
                    <td class="py-2" style="font-size:12px"><?= htmlspecialchars($log['target'] ?? '—') ?></td>
                    <td class="py-2" style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars(substr($log['note'] ?? '', 0, 50)) ?></td>
                    <td class="py-2" style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php /* ======================================================
   TAB 3 — LOG DB
   ====================================================== */ ?>
<?php elseif ($tab === 'dblog'): ?>

<ul class="nav nav-pills mb-3 gap-1">
    <li class="nav-item">
        <a class="nav-link <?= $subtab === 'scheduled' ? 'active' : '' ?>"
           href="?tab=dblog&subtab=scheduled&limit=<?= $perPage ?>"
           style="font-size:12px;padding:5px 14px;<?= $subtab !== 'scheduled' ? 'color:var(--text-secondary);background:#f1f5f9' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left me-1"></i>Scheduled Logs
            <span class="ms-1" style="background:rgba(0,0,0,.1);border-radius:10px;padding:1px 6px;font-size:11px"><?= count($scheduledLogs) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $subtab === 'pending' ? 'active' : '' ?>"
           href="?tab=dblog&subtab=pending&limit=<?= $perPage ?>"
           style="font-size:12px;padding:5px 14px;<?= $subtab !== 'pending' ? 'color:var(--text-secondary);background:#f1f5f9' : '' ?>">
            <i class="fa-solid fa-share-nodes me-1"></i>Pending Shared
            <span class="ms-1" style="background:rgba(0,0,0,.1);border-radius:10px;padding:1px 6px;font-size:11px"><?= count($pendingShared) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $subtab === 'otp' ? 'active' : '' ?>"
           href="?tab=dblog&subtab=otp&limit=<?= $perPage ?>"
           style="font-size:12px;padding:5px 14px;<?= $subtab !== 'otp' ? 'color:var(--text-secondary);background:#f1f5f9' : '' ?>">
            <i class="fa-solid fa-key me-1"></i>OTP Register
            <span class="ms-1" style="background:rgba(0,0,0,.1);border-radius:10px;padding:1px 6px;font-size:11px"><?= count($otpLogs) ?></span>
        </a>
    </li>
</ul>

<div class="d-flex justify-content-end mb-3">
    <?= perPageSelector($perPage, 'dblog', $subtab) ?>
</div>

<?php if ($subtab === 'scheduled'): ?>
<div class="card">
    <div class="card-header"><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Scheduled Transaction Logs</div>
    <div class="card-body p-0">
        <?php if (empty($scheduledLogs)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">Belum ada data</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12.5px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="py-3">User</th>
                        <th class="py-3">Jadwal</th>
                        <th class="py-3">Due Date</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Reminded</th>
                        <th class="py-3">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scheduledLogs as $sl): ?>
                <tr>
                    <td class="px-4 py-2" style="font-size:11px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M Y H:i', strtotime($sl['created_at'])) ?>
                    </td>
                    <td class="py-2">
                        <div style="font-weight:600;font-size:12.5px"><?= htmlspecialchars($sl['user_name'] ?? '—') ?></div>
                        <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($sl['wa_number'] ?? '') ?></div>
                    </td>
                    <td class="py-2"><?= htmlspecialchars($sl['sched_title'] ?? '—') ?></td>
                    <td class="py-2" style="font-family:var(--font-mono);font-size:11.5px"><?= htmlspecialchars($sl['due_date'] ?? '—') ?></td>
                    <td class="py-2"><?= statusBadge($sl['status']) ?></td>
                    <td class="py-2" style="text-align:center;font-family:var(--font-mono)"><?= (int)$sl['reminded_count'] ?>x</td>
                    <td class="py-2" style="font-size:11px;color:var(--text-muted)">
                        <?= $sl['resolved_at'] ? date('d M Y H:i', strtotime($sl['resolved_at'])) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($subtab === 'pending'): ?>
<div class="card">
    <div class="card-header"><i class="fa-solid fa-share-nodes me-2 text-muted"></i>Pending Shared Transactions</div>
    <div class="card-body p-0">
        <?php if (empty($pendingShared)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">Belum ada data</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12.5px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="py-3">User</th>
                        <th class="py-3">Transaksi</th>
                        <th class="py-3">Target Groups</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Resolved</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingShared as $ps): ?>
                <tr>
                    <td class="px-4 py-2" style="font-size:11px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M Y H:i', strtotime($ps['created_at'])) ?>
                    </td>
                    <td class="py-2">
                        <div style="font-weight:600;font-size:12.5px"><?= htmlspecialchars($ps['user_name'] ?? '—') ?></div>
                        <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($ps['wa_number'] ?? '') ?></div>
                    </td>
                    <td class="py-2">
                        <?php if ($ps['txn_desc']): ?>
                        <div><?= htmlspecialchars($ps['txn_desc']) ?></div>
                        <div style="font-size:11.5px;color:<?= $ps['txn_type']==='income'?'#16a34a':'#dc2626' ?>;font-family:var(--font-mono)">
                            <?= ($ps['txn_type']==='income'?'+':'−') . fmtRp((int)$ps['amount']) ?>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--text-muted)">ID #<?= $ps['transaction_id'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2" style="font-size:11.5px;max-width:180px;word-break:break-all">
                        <?= htmlspecialchars(substr($ps['target_groups'] ?? '—', 0, 80)) ?>
                    </td>
                    <td class="py-2"><?= statusBadge($ps['status']) ?></td>
                    <td class="py-2" style="font-size:11px;color:var(--text-muted)">
                        <?= $ps['resolved_at'] ? date('d M Y H:i', strtotime($ps['resolved_at'])) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($subtab === 'otp'): ?>
<div class="card">
    <div class="card-header"><i class="fa-solid fa-key me-2 text-muted"></i>OTP Register Log</div>
    <div class="card-body p-0">
        <?php if (empty($otpLogs)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">Belum ada data</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12.5px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="py-3">WA Number</th>
                        <th class="py-3">OTP</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Expires</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($otpLogs as $otp):
                    $expired     = strtotime($otp['expires_at']) < time();
                    $used        = (bool)$otp['is_used'];
                    $statusLabel = $used ? 'used' : ($expired ? 'expired' : 'active');
                ?>
                <tr>
                    <td class="px-4 py-2" style="font-size:11px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M Y H:i', strtotime($otp['created_at'])) ?>
                    </td>
                    <td class="py-2" style="font-family:var(--font-mono)"><?= htmlspecialchars($otp['wa_number']) ?></td>
                    <td class="py-2">
                        <span style="font-family:var(--font-mono);font-size:13px;letter-spacing:2px;font-weight:600">
                            <?= htmlspecialchars($otp['otp_code']) ?>
                        </span>
                    </td>
                    <td class="py-2"><?= statusBadge($statusLabel) ?></td>
                    <td class="py-2" style="font-size:11px;color:var(--text-muted)">
                        <?= date('d M Y H:i', strtotime($otp['expires_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; /* end subtab */ ?>

<?php /* ======================================================
   TAB 4 — ERROR LOG
   ====================================================== */ ?>
<?php elseif ($tab === 'errorlog'): ?>

<div class="row g-3">
    <div class="col-md-4 col-lg-3">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-folder-open me-2 text-muted"></i>File Ditemukan</span>
                <span style="font-size:11px;background:#fee2e2;color:#b91c1c;padding:2px 7px;border-radius:4px;font-weight:600">
                    <?= count($errorFiles) ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($errorFiles)): ?>
                <div class="text-center text-muted py-4 px-3" style="font-size:13px">
                    <i class="fa-solid fa-circle-check fa-2x d-block mb-2" style="color:#16a34a;opacity:.7"></i>
                    Tidak ada file error_log ditemukan
                </div>
                <?php else: ?>
                <div style="max-height:520px;overflow-y:auto">
                    <?php foreach ($errorFiles as $ef):
                        $isSelected = isset($selectedErrFile) && $selectedErrFile === $ef['path'];
                        $sizeLabel  = $ef['size'] > 1024 * 1024
                            ? round($ef['size'] / 1024 / 1024, 1) . ' MB'
                            : round($ef['size'] / 1024, 1) . ' KB';
                    ?>
                    <a href="?tab=errorlog&limit=<?= $perPage ?>&errfile=<?= urlencode($ef['rel_path']) ?>"
                       style="display:block;padding:9px 14px;border-bottom:1px solid var(--card-border);text-decoration:none;
                              background:<?= $isSelected ? '#fff7ed' : 'transparent' ?>">
                        <div style="font-size:11.5px;font-weight:600;color:<?= $isSelected ? '#c2410c' : 'inherit' ?>;word-break:break-all">
                            <?= htmlspecialchars($ef['rel_path']) ?>
                        </div>
                        <div style="font-size:10.5px;color:var(--text-muted);margin-top:2px">
                            <?= $sizeLabel ?> · <?= date('d M H:i', $ef['mtime']) ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8 col-lg-9">
        <?php if ($selectedErrFile && !empty($errFileContent)): ?>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span style="font-size:12px">
                    <i class="fa-solid fa-file-lines me-2 text-muted"></i><?= htmlspecialchars($errFilePath) ?>
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?= perPageSelector($perPage, 'errorlog', '', ['errfile' => $errFilePath]) ?>
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyLogContent('error-log-box')"
                        style="font-size:11px;padding:3px 10px;flex-shrink:0">
                        <i class="fa-solid fa-copy me-1"></i>Copy
                    </button>
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="clearErrorLog('<?= htmlspecialchars(addslashes($errFilePath)) ?>')"
                            style="font-size:11px;padding:3px 10px;flex-shrink:0">
                        <i class="fa-solid fa-trash me-1"></i>Clear File
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="error-log-box" style="background:#0f172a;padding:16px;overflow-x:auto;max-height:560px;overflow-y:auto">
                    <?php foreach ($errFileContent as $line):
                        $c = '#94a3b8';
                        if (stripos($line, 'Fatal')   !== false) $c = '#f87171';
                        if (stripos($line, 'Warning') !== false) $c = '#fde68a';
                        if (stripos($line, 'Notice')  !== false) $c = '#7dd3fc';
                        if (stripos($line, 'Parse')   !== false) $c = '#f87171';
                    ?>
                    <div style="font-family:var(--font-mono);font-size:11.5px;color:<?= $c ?>;padding:2px 0;line-height:1.5;white-space:pre-wrap"><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if ($errPages > 1): ?>
                <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
                    <span style="font-size:12px;color:var(--text-muted)">Halaman <?= $errPage ?> dari <?= $errPages ?></span>
                    <div class="d-flex gap-1">
                        <?php if ($errPage > 1): ?>
                        <a href="?tab=errorlog&limit=<?= $perPage ?>&errfile=<?= urlencode($errFilePath) ?>&epage=<?= $errPage - 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">← Prev</a>
                        <?php endif; ?>
                        <?php if ($errPage < $errPages): ?>
                        <a href="?tab=errorlog&limit=<?= $perPage ?>&errfile=<?= urlencode($errFilePath) ?>&epage=<?= $errPage + 1 ?>" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Next →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php elseif ($errFilePath !== ''): ?>
        <div class="card"><div class="card-body text-muted text-center py-5">File tidak ditemukan atau tidak valid.</div></div>
        <?php else: ?>
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="fa-solid fa-arrow-left fa-2x d-block mb-2" style="opacity:.3"></i>
            Pilih file error log di panel kiri
        </div></div>
        <?php endif; ?>
    </div>
</div>

<?php endif; /* end tab */ ?>

<?php
ob_start();
?>
<script>
// ============================================================
// Copy tombol: salin teks dari elemen log box
// ============================================================
function copyLogContent(boxId) {
    const box = document.getElementById(boxId);
    if (!box) return;
    const lines = box.querySelectorAll('div');
    const text  = Array.from(lines).map(d => d.textContent).join('\n').trim();
    navigator.clipboard.writeText(text).then(() => {
        showCopyToast('Log disalin ke clipboard (' + lines.length + ' baris)');
    }).catch(() => {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        showCopyToast('Log disalin ke clipboard');
    });
}

// ============================================================
// Copy percakapan: format teks chat sederhana
// ============================================================
function copyConversation() {
    const area = document.getElementById('conv-chat-area');
    if (!area) return;
    // Ambil semua bubble + timestamp
    const bubbles = area.querySelectorAll('[data-dir]');
    if (!bubbles.length) {
        // fallback: ambil semua text
        const text = area.innerText.trim();
        navigator.clipboard.writeText(text).then(() => showCopyToast('Percakapan disalin'));
        return;
    }
    const lines = Array.from(bubbles).map(b => {
        const dir  = b.dataset.dir === 'in' ? '← USER' : '→ BOT ';
        const ts   = b.dataset.ts  || '';
        const msg  = b.querySelector('.bubble-text')?.textContent?.trim() || '';
        return `[${ts}] ${dir}: ${msg}`;
    });
    navigator.clipboard.writeText(lines.join('\n')).then(() => {
        showCopyToast('Percakapan disalin (' + lines.length + ' pesan)');
    });
}

function showCopyToast(msg) {
    let t = document.getElementById('copy-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'copy-toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;'
            + 'padding:8px 16px;border-radius:8px;font-size:13px;z-index:9999;'
            + 'box-shadow:0 4px 12px rgba(0,0,0,.25);transition:opacity .3s';
        document.body.appendChild(t);
    }
    t.textContent = '✓ ' + msg;
    t.style.opacity = '1';
    clearTimeout(t._to);
    t._to = setTimeout(() => { t.style.opacity = '0'; }, 2500);
}

// ============================================================
// Clear log actions
// ============================================================
async function clearLog() {
    if (!confirm('Hapus semua isi incoming.log?')) return;
    const res  = await fetch('/api/admin/logs.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'clear_incoming' })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

async function clearOutgoingLog() {
    if (!confirm('Hapus semua isi outgoing.log?')) return;
    const res  = await fetch('/api/admin/logs.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'clear_outgoing' })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

async function clearErrorLog(relPath) {
    if (!confirm('Hapus isi file: ' + relPath + '?')) return;
    const res  = await fetch('/api/admin/logs.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'clear_error_log', path: relPath })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

// Auto-scroll chat ke paling bawah saat pertama load
(function() {
    const area = document.getElementById('conv-chat-area');
    if (area) area.scrollTop = area.scrollHeight;
})();
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>