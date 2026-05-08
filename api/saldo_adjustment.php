<?php
// ============================================================
// KitaCatat — API: Penyesuaian Saldo
// POST /api/saldo_adjustment.php
// ============================================================
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Auth
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// CSRF
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

$saldoRiil = (int)($input['saldo_riil'] ?? 0);
$mode      = $input['mode'] ?? 'now'; // 'now' atau 'historis'
$bulan     = (int)($input['bulan'] ?? 0); // 1-12
$tahun     = (int)($input['tahun'] ?? 0);

if ($saldoRiil <= 0) {
    echo json_encode(['success' => false, 'message' => 'Nominal tidak valid']);
    exit;
}

// Tentukan rentang tanggal
if ($mode === 'now') {
    $dateStart  = date('Y-m-01 00:00:00');
    $dateEnd    = date('Y-m-t 23:59:59');
    $trxDate    = null; // pakai NOW()
    $labelBulan = date('F Y');
} else {
    if ($bulan < 1 || $bulan > 12 || $tahun < 2000) {
        echo json_encode(['success' => false, 'message' => 'Bulan/tahun tidak valid']);
        exit;
    }
    // Validasi: tidak boleh bulan berjalan atau masa depan
    $targetTs  = mktime(0, 0, 0, $bulan, 1, $tahun);
    $thisMonth = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
    if ($targetTs >= $thisMonth) {
        echo json_encode(['success' => false, 'message' => 'Gunakan mode "Bulan Ini" untuk bulan berjalan']);
        exit;
    }
    $lastDay    = date('t', $targetTs);
    $dateStart  = date('Y-m-01 00:00:00', $targetTs);
    $dateEnd    = date("Y-m-{$lastDay} 23:59:59", $targetTs);
    $trxDate    = $dateEnd;
    $bulanNames = ['','Januari','Februari','Maret','April','Mei','Juni',
                   'Juli','Agustus','September','Oktober','November','Desember'];
    $labelBulan = $bulanNames[$bulan] . ' ' . $tahun;
}

// Hitung saldo KitaCatat periode tersebut
$stmt = $db->prepare(
    "SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL
       AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$row = $stmt->fetch();
$saldoPeriode = (int)($row['total_income'] ?? 0) - (int)($row['total_expense'] ?? 0);

// Hitung carry-over sebelum periode tersebut
$stmtCo = $db->prepare(
    "SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) -
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS carry_over
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL AND created_at < ?"
);
$stmtCo->execute([$userId, $dateStart]);
$carryOver   = (int)($stmtCo->fetchColumn() ?? 0);
$saldoSistem = $saldoPeriode + $carryOver;
$selisih     = $saldoRiil - $saldoSistem;

// Jika sudah cocok
if ($selisih === 0) {
    echo json_encode([
        'success'      => true,
        'match'        => true,
        'message'      => 'Saldo KitaCatat sudah cocok!',
        'saldo_sistem' => $saldoSistem,
        'label_bulan'  => $labelBulan,
    ]);
    exit;
}

// Kembalikan data selisih untuk konfirmasi user (step 1)
// Transaksi belum disimpan — user masih perlu input deskripsi
echo json_encode([
    'success'       => true,
    'match'         => false,
    'saldo_riil'    => $saldoRiil,
    'saldo_sistem'  => $saldoSistem,
    'saldo_periode' => $saldoPeriode,
    'carry_over'    => $carryOver,
    'selisih'       => $selisih,
    'tipe'          => $selisih > 0 ? 'income' : 'expense',
    'label_bulan'   => $labelBulan,
    'trx_date'      => $trxDate,
    'mode'          => $mode,
]);