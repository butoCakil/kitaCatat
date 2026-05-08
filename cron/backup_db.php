<?php
// ============================================================
// KitaCatat — Cron: Backup Database Harian + Upload ke Google Drive
// Setup di cPanel: Cron Jobs → setiap hari jam 02.00
// Command: php /home/dvttaulx/public_html/kitacatat.masbendz.com/cron/backup_db.php
// ============================================================
define('CRON_RUN', true);
require_once __DIR__ . '/../config/config.php';

$backupDir      = __DIR__ . '/../backups/';
$appsScriptUrl  = 'https://script.google.com/macros/s/AKfycby1X8Q92GAgyM_MShhkhCoXEAQNCIrE9-TnigaCjJcN_k3WuHWdtf_c1CLMO5tlBg3N/exec';
$driveFolderId  = '1O7fzWCqV9hwaNxXOmPobitmYDLdnMNht';

if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

$date     = date('Y-m-d');
$filename = $backupDir . 'backup_' . $date . '.sql';

// ============================================================
// 1. Hapus backup lokal lebih dari 14 hari
// ============================================================
$files = glob($backupDir . 'backup_*.sql');
foreach ($files as $file) {
    if (filemtime($file) < strtotime('-14 days')) {
        unlink($file);
    }
}

// ============================================================
// 2. Jalankan mysqldump
// ============================================================
$cmd = sprintf(
    'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_NAME),
    escapeshellarg($filename)
);

exec($cmd, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($filename) || filesize($filename) === 0) {
    $errMsg = "[KitaCatat Backup] ❌ Gagal backup database: " . implode(' ', $output);
    error_log($errMsg);
    echo $errMsg . "\n";
    exit(1);
}

$size = round(filesize($filename) / 1024, 1);
echo "✅ Backup lokal berhasil: backup_{$date}.sql ({$size} KB)\n";

// ============================================================
// 3. Upload ke Google Drive via Apps Script
// ============================================================
echo "⏳ Mengupload ke Google Drive via Apps Script...\n";

$sqlContent = file_get_contents($filename);
$payload    = json_encode([
    'filename'  => 'backup_' . $date . '.sql',
    'content'   => $sqlContent,
    'folder_id' => $driveFolderId,
]);

$ch = curl_init($appsScriptUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($result && ($result['status'] ?? '') === 'success') {
    echo "✅ Upload ke Google Drive berhasil: {$result['filename']}\n";
    error_log("[KitaCatat Backup] ✅ Upload Drive berhasil: backup_{$date}.sql");
} else {
    $errDetail = $result['message'] ?? $response;
    echo "❌ Upload ke Google Drive gagal: {$errDetail}\n";
    error_log("[KitaCatat Backup] ❌ Upload Drive gagal: {$errDetail}");
}

echo "✅ Selesai: backup_{$date}.sql ({$size} KB)\n";