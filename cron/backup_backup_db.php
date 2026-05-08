<?php
// ============================================================
// KitaCatat — Cron: Backup Database Harian
// Setup di cPanel: Cron Jobs → setiap hari jam 02.00
// Command: php /home/dvttaulx/public_html/kitacatat.masbendz.com/cron/backup_db.php
// ============================================================
define('CRON_RUN', true);
require_once __DIR__ . '/../config/config.php';

$backupDir = __DIR__ . '/../backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

$date     = date('Y-m-d');
$filename = $backupDir . 'backup_' . $date . '.sql';

// Hapus backup lebih dari 14 hari
$files = glob($backupDir . 'backup_*.sql');
foreach ($files as $file) {
    if (filemtime($file) < strtotime('-14 days')) {
        unlink($file);
    }
}

// Jalankan mysqldump
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
    $size = round(filesize($filename) / 1024, 1);
    error_log("[KitaCatat Backup] ✅ Berhasil: {$filename} ({$size} KB)");
    echo "✅ Backup berhasil: backup_{$date}.sql ({$size} KB)\n";
} else {
    error_log("[KitaCatat Backup] ❌ Gagal backup database: " . implode(' ', $output));
    echo "❌ Backup gagal. Cek error log.\n";
}
