<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/WASender.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success'=>false,'message'=>'Unauthorized']));
}

$db      = getDB();
$adminId = (int)$_SESSION['admin_id'];
$body    = json_decode(file_get_contents('php://input'), true) ?? [];

$targetType = $body['target_type'] ?? 'all';
$targets    = $body['targets']     ?? [];
$message    = trim($body['message'] ?? '');

if (empty($message)) exit(json_encode(['success'=>false,'message'=>'Pesan tidak boleh kosong.']));

// Ambil nomor tujuan
$numbers = [];
if ($targetType === 'all') {
    $stmt = $db->query("SELECT wa_number FROM users WHERE is_active=1");
    $numbers = array_column($stmt->fetchAll(), 'wa_number');
} else {
    // Specific: filter hanya yang terdaftar
    foreach ($targets as $wa) {
        $wa = preg_replace('/[^0-9]/', '', $wa);
        if (empty($wa)) continue;
        $stmt = $db->prepare("SELECT wa_number FROM users WHERE wa_number=? AND is_active=1");
        $stmt->execute([$wa]);
        if ($row = $stmt->fetch()) $numbers[] = $row['wa_number'];
    }
}

if (empty($numbers)) exit(json_encode(['success'=>false,'message'=>'Tidak ada user yang valid untuk dikirim.']));

// Kirim satu per satu
$sent   = 0;
$failed = 0;
foreach ($numbers as $wa) {
    $result = WASender::send($wa, $message);
    if ($result['success']) $sent++;
    else $failed++;
    // Jeda kecil agar tidak spam
    usleep(200000); // 0.2 detik
}

// Catat di admin_logs
$targetLabel = $targetType === 'all' ? "Semua user ({$sent} terkirim)" : "Spesifik: {$sent} terkirim, {$failed} gagal";
$db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
   ->execute([$adminId, 'broadcast.wa', $targetLabel, substr($message, 0, 200), $_SERVER['REMOTE_ADDR'] ?? '']);

exit(json_encode([
    'success' => true,
    'sent'    => $sent,
    'failed'  => $failed,
]));
