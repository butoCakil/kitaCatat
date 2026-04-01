<?php
// ============================================================
// KitaCatat — API: Transactions
// GET    /api/transactions.php         → list + filter + pagination
// POST   /api/transactions.php         → tambah baru
// PUT    /api/transactions.php?id=X    → edit
// DELETE /api/transactions.php?id=X    → hapus (soft delete)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/NLPParser.php';
require_once __DIR__ . '/../core/TransactionManager.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

// Paksa login
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$db     = getDB();
$userId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Ambil data user untuk notifikasi
function getTrxUser(PDO $db, int $userId): array {
    $s = $db->prepare("SELECT id, name, wa_number, email FROM users WHERE id = ? LIMIT 1");
    $s->execute([$userId]);
    return $s->fetch() ?: [];
}

// ============================================================
// GET — List transaksi dengan filter & pagination
// ============================================================
if ($method === 'GET' && !$id) {
    $page      = max(1, (int)($_GET['page']       ?? 1));
    $perPage   = 15;
    $type      = $_GET['type']       ?? '';
    $category  = $_GET['category']   ?? '';
    $dateStart = $_GET['date_start'] ?? date('Y-m-01');
    $dateEnd   = $_GET['date_end']   ?? date('Y-m-d');

    // Normalisasi tanggal
    $dateStart = $dateStart . ' 00:00:00';
    $dateEnd   = $dateEnd   . ' 23:59:59';

    // WHERE conditions
    $where  = ["t.user_id = :user_id", "t.deleted_at IS NULL",
               "t.created_at BETWEEN :date_start AND :date_end"];
    $params = [':user_id' => $userId, ':date_start' => $dateStart, ':date_end' => $dateEnd];

    if ($type && in_array($type, ['income', 'expense'])) {
        $where[]           = "t.type = :type";
        $params[':type']   = $type;
    }

    if ($category) {
        $where[]              = "c.name = :category";
        $params[':category']  = $category;
    }

    $whereStr = implode(' AND ', $where);

    // Count total
    $stmtCount = $db->prepare(
        "SELECT COUNT(*) FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $whereStr"
    );
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();

    // Summary (income & expense total)
    $stmtSum = $db->prepare(
        "SELECT
            SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) AS total_expense
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $whereStr"
    );
    $stmtSum->execute($params);
    $summary = $stmtSum->fetch();

    // Data
    $offset = ($page - 1) * $perPage;
    $stmtData = $db->prepare(
        "SELECT t.id, t.unique_code, t.type, t.amount, t.description,
                t.category_id, t.source, t.created_at,
                c.name AS category_name, c.icon AS category_icon
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $whereStr
         ORDER BY t.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) $stmtData->bindValue($k, $v);
    $stmtData->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmtData->execute();
    $rows = $stmtData->fetchAll();

    $totalPages = (int) ceil($total / $perPage);

    exit(json_encode([
        'success' => true,
        'data'    => $rows,
        'summary' => [
            'total_income'  => (int)($summary['total_income']  ?? 0),
            'total_expense' => (int)($summary['total_expense'] ?? 0),
        ],
        'pagination' => [
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'from'         => $total ? $offset + 1 : 0,
            'to'           => min($offset + $perPage, $total),
        ],
    ]));
}

// ============================================================
// POST — Tambah transaksi baru
// ============================================================
if ($method === 'POST') {
    csrfVerify();
    $body = json_decode(file_get_contents('php://input'), true);

    $type       = $body['type']        ?? '';
    $amount     = (int)($body['amount'] ?? 0);
    $desc       = trim($body['description'] ?? '');
    $categoryId = (int)($body['category_id'] ?? 0);
    $createdAt  = $body['created_at']  ?? date('Y-m-d H:i:s');

    if (!in_array($type, ['income','expense'])) {
        exit(json_encode(['success'=>false,'message'=>'Tipe tidak valid.']));
    }
    if ($amount <= 0) {
        exit(json_encode(['success'=>false,'message'=>'Nominal tidak valid.']));
    }
    if (empty($desc)) {
        exit(json_encode(['success'=>false,'message'=>'Deskripsi wajib diisi.']));
    }

    // Generate unique code
    $date     = date('Ymd', strtotime($createdAt));
    $stmtSeq  = $db->prepare("SELECT COUNT(*) FROM transactions WHERE unique_code LIKE :prefix");
    $stmtSeq->execute([':prefix' => "TXN-{$date}-%"]);
    $seq      = (int)$stmtSeq->fetchColumn() + 1;
    $uniqueCode = "TXN-{$date}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

    $stmt = $db->prepare(
        "INSERT INTO transactions
            (unique_code, user_id, type, amount, description, category_id, source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'dashboard', ?)"
    );
    $stmt->execute([$uniqueCode, $userId, $type, $amount, $desc, $categoryId ?: null, $createdAt]);

    $trxUser = getTrxUser($db, $userId);
    if ($trxUser) {
        sendSecurityNotif($trxUser, 'transaction_action', [
            'action'      => 'ditambahkan',
            'description' => $desc,
            'amount'      => $amount,
            'unique_code' => $uniqueCode,
            'type'        => $type,
        ]);
    }
    exit(json_encode(['success' => true, 'unique_code' => $uniqueCode]));
}

// ============================================================
// PUT — Edit transaksi
// ============================================================
if ($method === 'PUT' && $id) {
    csrfVerify();
    // Pastikan milik user ini
    $check = $db->prepare("SELECT id FROM transactions WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) {
        exit(json_encode(['success'=>false,'message'=>'Transaksi tidak ditemukan.']));
    }

    $body       = json_decode(file_get_contents('php://input'), true);
    $type       = $body['type']        ?? null;
    $amount     = isset($body['amount']) ? (int)$body['amount'] : null;
    $desc       = isset($body['description']) ? trim($body['description']) : null;
    $categoryId = isset($body['category_id']) ? (int)$body['category_id'] : null;
    $createdAt  = $body['created_at']  ?? null;

    $sets = [];
    $params = [];

    if ($type && in_array($type, ['income','expense'])) {
        $sets[] = 'type = ?'; $params[] = $type;
    }
    if ($amount && $amount > 0) {
        $sets[] = 'amount = ?'; $params[] = $amount;
    }
    if ($desc !== null && $desc !== '') {
        $sets[] = 'description = ?'; $params[] = $desc;
    }
    if ($categoryId) {
        $sets[] = 'category_id = ?'; $params[] = $categoryId;
    }
    if ($createdAt) {
        $sets[] = 'created_at = ?'; $params[] = $createdAt;
    }

    if (empty($sets)) {
        exit(json_encode(['success'=>false,'message'=>'Tidak ada data yang diubah.']));
    }

    $sets[]   = 'updated_at = NOW()';
    $params[] = $id;
    $params[] = $userId;

    // Ambil data lama sebelum update
    $oldStmt = $db->prepare("SELECT description, amount, type, unique_code FROM transactions WHERE id=? AND user_id=?");
    $oldStmt->execute([$id, $userId]);
    $oldData = $oldStmt->fetch();

    $stmt = $db->prepare("UPDATE transactions SET " . implode(', ', $sets) . " WHERE id=? AND user_id=?");
    $stmt->execute($params);

    // Jika kategori diubah → pelajari keyword baru
    if ($categoryId) {
        $trxCheck = $db->prepare("SELECT description, type FROM transactions WHERE id=? AND user_id=?");
        $trxCheck->execute([$id, $userId]);
        $trxData = $trxCheck->fetch();
        if ($trxData && !empty($trxData['description'])) {
            $userArr = ['id' => $userId];
            $manager = new TransactionManager($userArr, $db);
            $manager->learnKeywords($trxData['description'], $trxData['type'], $categoryId);
        }
    }

    $trxUser = getTrxUser($db, $userId);
    if ($trxUser) {
        sendSecurityNotif($trxUser, 'transaction_action', [
            'action'        => 'diubah',
            'description'   => $desc ?? $oldData['description'] ?? '-',
            'amount'        => $amount ?? $oldData['amount'] ?? 0,
            'type'          => $type ?? $oldData['type'] ?? '-',
            'unique_code'   => $oldData['unique_code'] ?? '-',
            'old_desc'      => $oldData['description'] ?? '-',
            'old_amount'    => $oldData['amount'] ?? 0,
            'new_desc'      => $desc ?? null,
            'new_amount'    => $amount ?? null,
        ]);
    }
    exit(json_encode(['success' => true]));
}

// ============================================================
// DELETE — Soft delete transaksi
// ============================================================
if ($method === 'DELETE' && $id) {
    csrfVerify();
    $check = $db->prepare("SELECT id FROM transactions WHERE id=? AND user_id=? AND deleted_at IS NULL");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) {
        exit(json_encode(['success'=>false,'message'=>'Transaksi tidak ditemukan.']));
    }

    // Ambil data sebelum dihapus untuk notif
    $trxRow = $db->prepare("SELECT description, amount, type, unique_code FROM transactions WHERE id = ?");
    $trxRow->execute([$id]);
    $trxRowData = $trxRow->fetch();

    $stmt = $db->prepare("UPDATE transactions SET deleted_at=NOW() WHERE id=? AND user_id=?");
    $stmt->execute([$id, $userId]);

    $trxUser = getTrxUser($db, $userId);
    if ($trxUser && $trxRowData) {
        sendSecurityNotif($trxUser, 'transaction_action', [
            'action'      => 'dihapus',
            'description' => $trxRowData['description'],
            'amount'      => $trxRowData['amount'],
            'type'        => $trxRowData['type'],
            'unique_code' => $trxRowData['unique_code'],
        ]);
    }
    exit(json_encode(['success' => true]));
}

http_response_code(405);
exit(json_encode(['success' => false, 'message' => 'Method not allowed']));