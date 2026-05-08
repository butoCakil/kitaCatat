<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'System Logs';
require_once __DIR__ . '/layout/header.php';

$db      = getDB();
$tab     = $_GET['tab']    ?? 'incoming';
$subtab  = $_GET['subtab'] ?? 'scheduled';

// ============================================================
// Per-page options (file-based & DB-based share same param)
// ============================================================
$allowedPerPage = [15, 30, 50, 100, 500];
$limitInput     = (int)($_GET['limit'] ?? 50);
$perPage        = in_array($limitInput, $allowedPerPage) ? $limitInput : 50;

// ============================================================
// TAB 1 — Incoming WA Log (dari file)
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
// TAB 1b — Outgoing WA Log (dari file)
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
// TAB 2 — Audit Admin (dari DB)
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
    // Path yang dikecualikan (relative dari rootDir)
    $excludedRelPaths = [
        'logs/incoming.log',
        'logs/outgoing.log',
    ];
    
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getSize() === 0) continue;
    
        $filename  = $file->getFilename();
        $extension = strtolower($file->getExtension());
        $relPath   = str_replace($rootDir . '/', '', $file->getPathname());
    
        // Kecualikan file PHP
        if ($extension === 'php') continue;
    
        // Kecualikan incoming.log dan outgoing.log
        if (in_array(str_replace('\\', '/', $relPath), $excludedRelPaths)) continue;
    
        // Lolos jika: ekstensi .log, ATAU nama file mengandung kata "log" (case-insensitive)
        $isLogFile = $extension === 'log'
            || stripos($filename, 'log') !== false;
    
        if (!$isLogFile) continue;
    
        $errorFiles[] = [
            'path'     => $file->getPathname(),
            'rel_path' => $relPath,
            'size'     => $file->getSize(),
            'mtime'    => $file->getMTime(),
        ];
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

// Helper: bangun URL dengan limit + reset page
function urlWithLimit(int $limit, string $tab, string $subtab = ''): string {
    $q = ['tab' => $tab, 'limit' => $limit];
    if ($subtab) $q['subtab'] = $subtab;
    return '?' . http_build_query($q);
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

<?php
// ============================================================
// Helper: Per-page selector widget (reusable)
// ============================================================
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
        <div style="background:#0f172a;padding:16px;overflow-x:auto;max-height:600px;overflow-y:auto">
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
        <div style="background:#0f172a;padding:16px;overflow-x:auto;max-height:600px;overflow-y:auto">
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

<!-- Sub-tabs -->
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

<!-- Per-page selector untuk Log DB -->
<div class="d-flex justify-content-end mb-3">
    <?= perPageSelector($perPage, 'dblog', $subtab) ?>
</div>

<?php /* --- Sub-tab: Scheduled Logs --- */ ?>
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

<?php /* --- Sub-tab: Pending Shared --- */ ?>
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

<?php /* --- Sub-tab: OTP Register --- */ ?>
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
    <!-- Sidebar: daftar file error_log -->
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
                       class="d-block text-decoration-none px-3 py-2"
                       style="border-bottom:1px solid var(--card-border);<?= $isSelected ? 'background:#fef2f2' : '' ?>">
                        <div style="font-size:12px;font-weight:600;color:<?= $isSelected ? 'var(--admin-primary)' : 'var(--text-primary)' ?>;word-break:break-all">
                            <i class="fa-solid fa-file-lines me-1" style="font-size:11px;opacity:.5"></i>
                            <?= htmlspecialchars($ef['rel_path']) ?>
                        </div>
                        <div class="d-flex gap-2 mt-1">
                            <span style="font-size:10.5px;color:var(--text-muted)"><?= $sizeLabel ?></span>
                            <span style="font-size:10.5px;color:var(--text-muted)">· <?= date('d M Y H:i', $ef['mtime']) ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main: isi file yang dipilih -->
    <div class="col-md-8 col-lg-9">
        <?php if (!$selectedErrFile): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <i class="fa-solid fa-arrow-left fa-lg d-block mb-2" style="opacity:.3"></i>
                <span style="font-size:13px">Pilih file error_log di sebelah kiri untuk melihat isinya</span>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span style="font-family:var(--font-mono);font-size:12px;word-break:break-all">
                    <i class="fa-solid fa-file-lines me-2 text-muted"></i><?= htmlspecialchars($errFilePath) ?>
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?= perPageSelector($perPage, 'errorlog', '', ['errfile' => $errFilePath]) ?>
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="clearErrorLog('<?= htmlspecialchars(addslashes($errFilePath)) ?>')"
                            style="font-size:11px;padding:3px 10px;flex-shrink:0">
                        <i class="fa-solid fa-trash me-1"></i>Clear File
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($errFileContent)): ?>
                <div class="text-center text-muted py-4" style="font-size:13px">File kosong</div>
                <?php else: ?>
                <div style="background:#0f172a;padding:16px;overflow-x:auto;max-height:560px;overflow-y:auto">
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
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; /* end tab */ ?>

<?php
ob_start();
?>
<script>
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
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>