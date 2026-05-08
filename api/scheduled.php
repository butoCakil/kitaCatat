<?php
// ============================================================
// KitaCatat — API: Scheduled Transactions
// ============================================================
require_once __DIR__ . '/../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ============================================================
// Hitung next_run berdasarkan frekuensi
// ============================================================
function calcNextRun(string $freq, ?int $dom, ?int $dow, ?string $onceDate): string {
    $today = date('Y-m-d');

    if ($freq === 'once') {
        return $onceDate ?: $today;
    }

    if ($freq === 'daily') {
        return date('Y-m-d', strtotime('+1 day'));
    }

    if ($freq === 'weekly' && $dow !== null) {
        $current = (int)date('w');
        $diff    = ($dow - $current + 7) % 7;
        if ($diff === 0) $diff = 7;
        return date('Y-m-d', strtotime("+{$diff} days"));
    }

    if ($freq === 'monthly' && $dom !== null) {
        $thisMonth = date('Y-m-') . str_pad($dom, 2, '0', STR_PAD_LEFT);
        if ($thisMonth >= $today) return $thisMonth;
        return date('Y-m-', strtotime('first day of next month')) . str_pad($dom, 2, '0', STR_PAD_LEFT);
    }

    if ($freq === 'yearly' && $dom !== null) {
        $thisYear = date('Y-') . date('m-') . str_pad($dom, 2, '0', STR_PAD_LEFT);
        if ($thisYear >= $today) return $thisYear;
        return (date('Y') + 1) . '-' . date('m-') . str_pad($dom, 2, '0', STR_PAD_LEFT);
    }

    return $today;
}

// ============================================================
// CREATE
// ============================================================
if ($action === 'create') {
    $title    = trim($body['title'] ?? '');
    $type     = $body['type'] ?? '';
    $catId    = (int)($body['category_id'] ?? 0) ?: null;
    $amount   = isset($body['amount']) && $body['amount'] > 0 ? (int)$body['amount'] : null;
    $freq     = $body['frequency'] ?? 'monthly';
    $mode     = $body['mode'] ?? 'confirm';
    $dom      = isset($body['day_of_month']) ? (int)$body['day_of_month'] : null;
    $dow      = isset($body['day_of_week'])  ? (int)$body['day_of_week']  : null;
    $once     = $body['once_date'] ?? null;
    $rMax     = max(1, min(10, (int)($body['reminder_max'] ?? 3)));
    $rInt     = max(1, min(7,  (int)($body['reminder_interval'] ?? 1)));

    if (!$title) exit(json_encode(['success'=>false,'message'=>'Nama wajib diisi.']));
    if (!in_array($type, ['income','expense'])) exit(json_encode(['success'=>false,'message'=>'Tipe tidak valid.']));

    $nextRun = calcNextRun($freq, $dom, $dow, $once);

    $stmt = $db->prepare(
        "INSERT INTO scheduled_transactions
            (user_id, title, type, amount, category_id, frequency,
             day_of_month, day_of_week, next_run, mode,
             reminder_max, reminder_interval, start_date)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $userId, $title, $type, $amount, $catId, $freq,
        $dom, $dow, $nextRun, $mode, $rMax, $rInt, $nextRun
    ]);

    exit(json_encode(['success' => true, 'id' => $db->lastInsertId()]));
}

// ============================================================
// UPDATE
// ============================================================
if ($action === 'update') {
    $id    = (int)($body['id'] ?? 0);
    $title = trim($body['title'] ?? '');
    $type  = $body['type'] ?? '';
    $catId = (int)($body['category_id'] ?? 0) ?: null;
    $amount = isset($body['amount']) && $body['amount'] > 0 ? (int)$body['amount'] : null;
    $freq  = $body['frequency'] ?? 'monthly';
    $mode  = $body['mode'] ?? 'confirm';
    $dom   = isset($body['day_of_month']) ? (int)$body['day_of_month'] : null;
    $dow   = isset($body['day_of_week'])  ? (int)$body['day_of_week']  : null;
    $once  = $body['once_date'] ?? null;
    $rMax  = max(1, min(10, (int)($body['reminder_max'] ?? 3)));
    $rInt  = max(1, min(7,  (int)($body['reminder_interval'] ?? 1)));

    if (!$id || !$title) exit(json_encode(['success'=>false,'message'=>'Data tidak lengkap.']));

    $nextRun = calcNextRun($freq, $dom, $dow, $once);

    $stmt = $db->prepare(
        "UPDATE scheduled_transactions SET
            title=?, type=?, amount=?, category_id=?, frequency=?,
            day_of_month=?, day_of_week=?, next_run=?, mode=?,
            reminder_max=?, reminder_interval=?
         WHERE id=? AND user_id=?"
    );
    $stmt->execute([
        $title, $type, $amount, $catId, $freq,
        $dom, $dow, $nextRun, $mode, $rMax, $rInt,
        $id, $userId
    ]);

    exit(json_encode(['success' => true]));
}

// ============================================================
// TOGGLE AKTIF/NONAKTIF
// ============================================================
if ($action === 'toggle') {
    $id   = (int)($body['id'] ?? 0);
    $stmt = $db->prepare("SELECT is_active FROM scheduled_transactions WHERE id=? AND user_id=?");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();
    if (!$row) exit(json_encode(['success'=>false,'message'=>'Tidak ditemukan.']));

    $new = $row['is_active'] ? 0 : 1;
    $db->prepare("UPDATE scheduled_transactions SET is_active=? WHERE id=? AND user_id=?")
       ->execute([$new, $id, $userId]);

    exit(json_encode(['success' => true, 'is_active' => $new]));
}

// ============================================================
// DELETE
// ============================================================
if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    $db->prepare("DELETE FROM scheduled_logs WHERE scheduled_id=?")->execute([$id]);
    $db->prepare("DELETE FROM scheduled_transactions WHERE id=? AND user_id=?")->execute([$id, $userId]);
    exit(json_encode(['success' => true]));
}

http_response_code(400);
exit(json_encode(['success' => false, 'message' => 'Action tidak dikenal.']));
