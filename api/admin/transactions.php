<?php
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success'=>false,'message'=>'Unauthorized']));
}

$db      = getDB();
$adminId = (int)$_SESSION['admin_id'];
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $body['action'] ?? '';

function adminLog(PDO $db, int $adminId, string $action, string $target, string $note=''):void {
    $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId,$action,$target,$note,$_SERVER['REMOTE_ADDR']??'']);
}

// Restore transaksi yang terhapus (soft delete)
if ($action === 'restore') {
    $txId = (int)($body['transaction_id'] ?? 0);
    if (!$txId) exit(json_encode(['success'=>false,'message'=>'ID tidak valid.']));

    $stmt = $db->prepare("SELECT unique_code FROM transactions WHERE id=? AND deleted_at IS NOT NULL");
    $stmt->execute([$txId]);
    $trx = $stmt->fetch();

    if (!$trx) exit(json_encode(['success'=>false,'message'=>'Transaksi tidak ditemukan atau belum dihapus.']));

    $db->prepare("UPDATE transactions SET deleted_at=NULL, updated_at=NOW() WHERE id=?")->execute([$txId]);

    adminLog($db, $adminId, 'transaction.restore', "TXN:{$trx['unique_code']}", 'Dipulihkan dari soft delete oleh admin');

    exit(json_encode(['success'=>true]));
}

http_response_code(400);
exit(json_encode(['success'=>false,'message'=>'Action tidak dikenal.']));
