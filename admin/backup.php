<?php
// ============================================================
// KitaCatat — Admin: Backup Manager
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Backup Database';
require_once __DIR__ . '/layout/header.php';

$backupDir = __DIR__ . '/../backups/';
$message   = '';
$msgType   = '';

// ── Aksi: Hapus file ─────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $target = basename($_POST['filename'] ?? '');
    // Validasi: hanya file backup_*.sql yang boleh dihapus
    if (preg_match('/^backup_\d{4}-\d{2}-\d{2}\.sql$/', $target)) {
        $path = $backupDir . $target;
        if (file_exists($path) && unlink($path)) {
            $message = "File <strong>{$target}</strong> berhasil dihapus.";
            $msgType = 'success';
        } else {
            $message = "Gagal menghapus file <strong>{$target}</strong>.";
            $msgType = 'danger';
        }
    } else {
        $message = 'Nama file tidak valid.';
        $msgType = 'danger';
    }
}

// ── Aksi: Backup manual ──────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'backup_now') {
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $date     = date('Y-m-d');
    $filename = $backupDir . 'backup_' . $date . '.sql';

    $cmd = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_NAME),
        escapeshellarg($filename)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($filename) && filesize($filename) > 0) {
        $size    = round(filesize($filename) / 1024, 1);
        $message = "Backup manual berhasil: <strong>backup_{$date}.sql</strong> ({$size} KB)";
        $msgType = 'success';
    } else {
        $message = 'Backup manual gagal. Periksa error log server.';
        $msgType = 'danger';
    }
}

// ── Aksi: Download ───────────────────────────────────────────
if (isset($_GET['download'])) {
    $target = basename($_GET['download']);
    if (preg_match('/^backup_\d{4}-\d{2}-\d{2}\.sql$/', $target)) {
        $path = $backupDir . $target;
        if (file_exists($path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $target . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
    $message = 'File tidak ditemukan.';
    $msgType = 'danger';
}

// ── Ambil daftar file backup ─────────────────────────────────
$files = [];
if (is_dir($backupDir)) {
    $raw = glob($backupDir . 'backup_*.sql');
    rsort($raw); // terbaru di atas
    foreach ($raw as $f) {
        $name  = basename($f);
        $size  = round(filesize($f) / 1024, 1);
        $mtime = filemtime($f);
        // Ambil tanggal dari nama file
        preg_match('/backup_(\d{4}-\d{2}-\d{2})\.sql/', $name, $m);
        $files[] = [
            'name'  => $name,
            'size'  => $size,
            'mtime' => $mtime,
            'date'  => $m[1] ?? '—',
        ];
    }
}

$totalSize = array_sum(array_column($files, 'size'));
$today     = date('Y-m-d');
?>

<!-- ── Flash message ──────────────────────────────────────── -->
<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-4" role="alert" style="font-size:13px;border-radius:10px">
    <i class="fa-solid fa-<?= $msgType==='success'?'circle-check':'circle-exclamation' ?> me-2"></i>
    <?= $message ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Stat row ───────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-database"></i></div>
            <div>
                <div class="stat-label">Total File Backup</div>
                <div class="stat-value"><?= count($files) ?></div>
                <div class="stat-sub">tersimpan di server</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-hard-drive"></i></div>
            <div>
                <div class="stat-label">Total Ukuran</div>
                <div class="stat-value"><?= number_format($totalSize, 1) ?> KB</div>
                <div class="stat-sub"><?= number_format($totalSize / 1024, 2) ?> MB</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="stat-label">Backup Terbaru</div>
                <div class="stat-value" style="font-size:14px"><?= !empty($files) ? $files[0]['date'] : '—' ?></div>
                <div class="stat-sub">otomatis jam 02.00</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon <?= !empty($files) && $files[0]['date'] === $today ? 'green' : 'red' ?>">
                <i class="fa-solid fa-<?= !empty($files) && $files[0]['date'] === $today ? 'circle-check' : 'circle-xmark' ?>"></i>
            </div>
            <div>
                <div class="stat-label">Status Hari Ini</div>
                <div class="stat-value" style="font-size:13px;font-weight:700;color:<?= !empty($files) && $files[0]['date'] === $today ? '#16a34a' : '#dc2626' ?>">
                    <?= !empty($files) && $files[0]['date'] === $today ? 'Sudah Backup' : 'Belum Backup' ?>
                </div>
                <div class="stat-sub">disimpan 14 hari</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Toolbar ────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <div style="font-size:14px;font-weight:700">Kelola Backup Database</div>
            <div style="font-size:12px;color:var(--text-muted)">Backup otomatis berjalan setiap hari pukul 02.00. Disimpan maksimal 14 hari.</div>
        </div>
        <!-- Tombol backup manual -->
        <form method="POST" onsubmit="return confirmBackup()">
            <input type="hidden" name="action" value="backup_now">
            <button type="submit" class="btn btn-sm" style="background:var(--admin-primary);color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px">
                <i class="fa-solid fa-play"></i> Backup Sekarang
            </button>
        </form>
    </div>
</div>

<!-- ── Tabel file backup ──────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <i class="fa-solid fa-folder-open me-2 text-muted"></i>Daftar File Backup
    </div>
    <div class="card-body p-0">
        <?php if (empty($files)): ?>
        <div class="text-center py-5" style="color:var(--text-muted);font-size:13px">
            <i class="fa-solid fa-database fa-2x mb-3 d-block" style="opacity:.3"></i>
            Belum ada file backup
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Nama File</th>
                        <th class="py-3">Tanggal</th>
                        <th class="py-3">Ukuran</th>
                        <th class="py-3">Dimodifikasi</th>
                        <th class="py-3 text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                <tr>
                    <td class="px-4 py-3">
                        <span style="font-family:var(--font-mono);font-size:12px;background:#f1f5f9;padding:3px 8px;border-radius:5px;border:1px solid var(--card-border)">
                            <?= htmlspecialchars($f['name']) ?>
                        </span>
                        <?php if ($f['date'] === $today): ?>
                        <span style="margin-left:6px;background:#dcfce7;color:#15803d;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px">Hari ini</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3" style="color:var(--text-secondary)"><?= $f['date'] ?></td>
                    <td class="py-3" style="font-family:var(--font-mono);font-size:12px"><?= $f['size'] ?> KB</td>
                    <td class="py-3" style="color:var(--text-muted);font-size:12px"><?= date('d M Y, H:i', $f['mtime']) ?></td>
                    <td class="py-3 text-end pe-4">
                        <div class="d-inline-flex gap-2">
                            <!-- Download -->
                            <a href="?download=<?= urlencode($f['name']) ?>"
                               class="btn btn-sm"
                               style="background:#dbeafe;color:#2563eb;border:none;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600"
                               title="Download">
                                <i class="fa-solid fa-download"></i>
                            </a>
                            <!-- Hapus -->
                            <form method="POST" onsubmit="return confirmDelete('<?= htmlspecialchars($f['name']) ?>')" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="filename" value="<?= htmlspecialchars($f['name']) ?>">
                                <button type="submit"
                                        class="btn btn-sm"
                                        style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600"
                                        title="Hapus">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmDelete(name) {
    return confirm('Hapus file "' + name + '"?\nFile yang dihapus tidak bisa dikembalikan.');
}
function confirmBackup() {
    return confirm('Jalankan backup database sekarang?');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>