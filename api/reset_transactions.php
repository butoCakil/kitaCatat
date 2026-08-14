<?php
// ============================================================
// KitaCatat — API: Reset Saldo (hapus massal transaksi)
// POST /api/reset_transactions.php
// ============================================================
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$db     = getDB();
$userId = (int) $_SESSION['user_id'];

$stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

$password = $input['password'] ?? '';
if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Masukkan password untuk konfirmasi.']);
    exit;
}
if (!password_verify($password, $user['password'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Password tidak tepat.']);
    exit;
}

$scope = $input['scope'] ?? '';
$bulan = (int) ($input['bulan'] ?? 0);
$tahun = (int) ($input['tahun'] ?? 0);

if ($scope === 'month') {
    if ($bulan < 1 || $bulan > 12 || $tahun < 2000) {
        echo json_encode(['success' => false, 'message' => 'Bulan/tahun tidak valid']);
        exit;
    }
    $targetTs   = mktime(0, 0, 0, $bulan, 1, $tahun);
    $lastDay    = date('t', $targetTs);
    $dateStart  = date('Y-m-01 00:00:00', $targetTs);
    $dateEnd    = date("Y-m-{$lastDay} 23:59:59", $targetTs);
    $bulanNames = ['','Januari','Februari','Maret','April','Mei','Juni',
                   'Juli','Agustus','September','Oktober','November','Desember'];
    $label = $bulanNames[$bulan] . ' ' . $tahun;

    $stmtCount = $db->prepare(
        "SELECT COUNT(*) FROM transactions
         WHERE user_id = ? AND deleted_at IS NULL AND created_at BETWEEN ? AND ?"
    );
    $stmtCount->execute([$userId, $dateStart, $dateEnd]);
    $count = (int) $stmtCount->fetchColumn();

    $stmtDel = $db->prepare(
        "UPDATE transactions SET deleted_at = NOW()
         WHERE user_id = ? AND deleted_at IS NULL AND created_at BETWEEN ? AND ?"
    );
    $stmtDel->execute([$userId, $dateStart, $dateEnd]);

} elseif ($scope === 'all') {
    $label = 'semua transaksi';

    $stmtCount = $db->prepare(
        "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND deleted_at IS NULL"
    );
    $stmtCount->execute([$userId]);
    $count = (int) $stmtCount->fetchColumn();

    $stmtDel = $db->prepare(
        "UPDATE transactions SET deleted_at = NOW() WHERE user_id = ? AND deleted_at IS NULL"
    );
    $stmtDel->execute([$userId]);

} else {
    echo json_encode(['success' => false, 'message' => 'Scope tidak valid']);
    exit;
}

// Notifikasi WA
sendSecurityNotif($user, 'transactions_reset', [
    'count' => $count,
    'label' => $label,
]);

echo json_encode([
    'success' => true,
    'count'   => $count,
    'label'   => $label,
]);