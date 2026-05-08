<?php
// ============================================================
// KitaCatat — API: Simpan Penyesuaian Saldo
// POST /api/saldo_adjustment_save.php
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/NLPParser.php';
require_once __DIR__ . '/../core/TransactionManager.php';

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

$db      = getDB();
$userId  = (int)$_SESSION['user_id'];
$user    = $db->prepare("SELECT * FROM users WHERE id = ?")->execute([$userId]);
$user    = $db->query("SELECT * FROM users WHERE id = $userId")->fetch();

$selisih  = (int)($input['selisih']  ?? 0);
$desc     = trim($input['deskripsi'] ?? '');
$trxDate  = $input['trx_date'] ?? null;

if ($selisih === 0 || empty($desc)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$type   = $selisih > 0 ? 'income' : 'expense';
$amount = abs($selisih);

$manager = new TransactionManager($user, $db);
$result  = $manager->save([
    'type'          => $type,
    'amount'        => $amount,
    'description'   => 'Penyesuaian saldo: ' . $desc,
    'category_name' => 'Lainnya',
    'created_at'    => $trxDate,
], 'dashboard_saldo_adjustment');

if (!$result['success']) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan']);
    exit;
}

echo json_encode([
    'success'     => true,
    'unique_code' => $result['transaction']['unique_code'],
    'type'        => $type,
    'amount'      => $amount,
]);