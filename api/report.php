<?php
// ============================================================
// KitaCatat — API: Report
// GET /api/report.php?date_start=&date_end=&scope=personal|group_X
// GET /api/report.php?...&export=csv  → download CSV
// ============================================================
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$db = getDB();
$userId = (int) $_SESSION['user_id'];

$dateStart = ($_GET['date_start'] ?? date('Y-m-01')) . ' 00:00:00';
$dateEnd = ($_GET['date_end'] ?? date('Y-m-d')) . ' 23:59:59';
$scope = $_GET['scope'] ?? 'personal';
$export = $_GET['export'] ?? '';

// ============================================================
// Resolve user IDs berdasarkan scope
// ============================================================
$userIds = [$userId];

if (str_starts_with($scope, 'group_')) {
    $groupId = (int) substr($scope, 6);

    // Pastikan user adalah anggota grup ini
    $chk = $db->prepare("SELECT g.is_shared FROM groups g JOIN group_members gm ON gm.group_id=g.id WHERE g.id=? AND gm.user_id=?");
    $chk->execute([$groupId, $userId]);
    $grp = $chk->fetch();

    if ($grp) {
        if ($grp['is_shared']) {
            // Shared: ambil semua anggota grup
            $stmt = $db->prepare("SELECT user_id FROM group_members WHERE group_id=?");
            $stmt->execute([$groupId]);
            $userIds = array_column($stmt->fetchAll(), 'user_id');
        } else {
            // Tidak shared: hanya transaksi yang memang ber-group_id ini
            $userIds = null; // flag khusus
        }
    }
}

// ============================================================
// Build WHERE clause
// ============================================================
if ($userIds === null) {
    // Mode grup non-shared: filter by group_id
    $where = "t.group_id = :group_id AND t.deleted_at IS NULL AND t.created_at BETWEEN :ds AND :de";
    $params = [':group_id' => $groupId, ':ds' => $dateStart, ':de' => $dateEnd];
} else {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $where = "t.user_id IN ($ph) AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?";
    $params = [...$userIds, $dateStart, $dateEnd];
}

// ============================================================
// Query utama
// ============================================================
function runQuery(PDO $db, string $where, array $params, bool $named = false): array
{
    // Summary
    $stmtSum = $db->prepare(
        "SELECT
            SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) AS total_expense,
            COUNT(*) AS total_transactions
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $where"
    );
    $named ? $stmtSum->execute($params) : $stmtSum->execute($params);
    $summary = $stmtSum->fetch();

    // Expense by parent category
    $stmtExp = $db->prepare(
        "SELECT 
        COALESCE(p.name, c.name) AS category,
        COALESCE(p.icon, c.icon) AS icon,
        SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE $where AND t.type='expense'
     GROUP BY COALESCE(p.id, c.id), COALESCE(p.name, c.name), COALESCE(p.icon, c.icon)
     ORDER BY total DESC LIMIT 10"
    );
    $stmtExp->execute($params);
    $expByCat = $stmtExp->fetchAll();

    // Expense subcategory
    $stmtExpSub = $db->prepare(
        "SELECT 
        COALESCE(p.id, c.id) AS parent_id,
        c.name AS category,
        c.icon,
        SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE $where AND t.type='expense'
     GROUP BY parent_id, c.id, c.name, c.icon
     ORDER BY total DESC"
    );
    $stmtExpSub->execute($params);
    $expSub = $stmtExpSub->fetchAll();

    // Income by parent category
    $stmtInc = $db->prepare(
        "SELECT 
        COALESCE(p.name, c.name) AS category,
        COALESCE(p.icon, c.icon) AS icon,
        SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE $where AND t.type='income'
     GROUP BY COALESCE(p.id, c.id), COALESCE(p.name, c.name), COALESCE(p.icon, c.icon)
     ORDER BY total DESC LIMIT 10"
    );
    $stmtInc->execute($params);
    $incByCat = $stmtInc->fetchAll();

    // Income subcategory
    $stmtIncSub = $db->prepare(
        "SELECT 
        COALESCE(p.id, c.id) AS parent_id,
        c.name AS category,
        c.icon,
        SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE $where AND t.type='income'
     GROUP BY parent_id, c.id, c.name, c.icon
     ORDER BY total DESC"
    );
    $stmtIncSub->execute($params);
    $incSub = $stmtIncSub->fetchAll();

    // Daily trend
    $stmtDaily = $db->prepare(
        "SELECT
            DATE(t.created_at) AS date,
            SUM(CASE WHEN t.type='income'  THEN t.amount ELSE 0 END) AS income,
            SUM(CASE WHEN t.type='expense' THEN t.amount ELSE 0 END) AS expense
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $where
         GROUP BY DATE(t.created_at)
         ORDER BY date ASC"
    );
    $stmtDaily->execute($params);
    $daily = $stmtDaily->fetchAll();

    // Semua transaksi (untuk tabel & export)
    $stmtTrx = $db->prepare(
        "SELECT t.id, t.unique_code, t.type, t.amount, t.description,
                t.created_at, c.name AS category_name, c.icon AS category_icon
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE $where
         ORDER BY t.created_at DESC
         LIMIT 500"
    );
    $stmtTrx->execute($params);
    $transactions = $stmtTrx->fetchAll();

    // Gabungkan parent + subcategory berdasarkan parent_id
    $expMap = [];
    $parentDirect = []; // Menyimpan transaksi langsung ke parent

    // Parent map
    $stmtParent = $db->prepare("
    SELECT 
        COALESCE(p.id, c.id) AS parent_id,
        COALESCE(p.name, c.name) AS parent_name
    FROM categories c
    LEFT JOIN categories p ON p.id = c.parent_id
    GROUP BY parent_id, parent_name
");
    $stmtParent->execute();
    $parentRows = $stmtParent->fetchAll();

    $parentIdToName = [];
    foreach ($parentRows as $row) {
        $parentIdToName[$row['parent_id']] = $row['parent_name'];
    }

    // Build parent map
    foreach ($expByCat as $p) {
        $p['children'] = [];
        $expMap[$p['category']] = $p;
    }

    // Masukkan sub ke parent
    foreach ($expSub as $s) {
        $parentName = $parentIdToName[$s['parent_id']] ?? null;

        if ($parentName && isset($expMap[$parentName])) {

            // Jika transaksi langsung ke parent
            if ($parentName === $s['category']) {
                $parentDirect[$parentName] = $s['total'];
            } else {
                $expMap[$parentName]['children'][] = [
                    'category' => $s['category'],
                    'icon' => $s['icon'],
                    'total' => $s['total']
                ];
            }
        }
    }

    // Tambahkan parent langsung sebagai child HANYA jika parent punya subcategory
    foreach ($parentDirect as $parentName => $total) {
        if ($total > 0 && isset($expMap[$parentName])) {

            // Cek apakah parent punya subcategory
            if (count($expMap[$parentName]['children']) > 0) {
                $expMap[$parentName]['children'][] = [
                    'category' => $parentName,
                    'icon' => 'fa-level-up-alt',
                    'total' => $total
                ];
            }
        }
    }

    $expByCat = array_values($expMap);

    // Gabungkan parent + subcategory untuk income
    $incMap = [];
    $parentDirectInc = [];

    // Build parent map
    foreach ($incByCat as $p) {
        $p['children'] = [];
        $incMap[$p['category']] = $p;
    }

    // Masukkan sub ke parent
    foreach ($incSub as $s) {
        $parentName = $parentIdToName[$s['parent_id']] ?? null;

        if ($parentName && isset($incMap[$parentName])) {

            // Jika transaksi langsung ke parent
            if ($parentName === $s['category']) {
                $parentDirectInc[$parentName] = $s['total'];
            } else {
                $incMap[$parentName]['children'][] = [
                    'category' => $s['category'],
                    'icon' => $s['icon'],
                    'total' => $s['total']
                ];
            }
        }
    }

    // Tambahkan parent langsung sebagai child hanya jika punya sub
    foreach ($parentDirectInc as $parentName => $total) {
        if ($total > 0 && isset($incMap[$parentName])) {
            if (count($incMap[$parentName]['children']) > 0) {
                $incMap[$parentName]['children'][] = [
                    'category' => $parentName,
                    'icon' => 'fa-level-up-alt',
                    'total' => $total
                ];
            }
        }
    }

    $incByCat = array_values($incMap);

    return [
        'total_income' => (int) ($summary['total_income'] ?? 0),
        'total_expense' => (int) ($summary['total_expense'] ?? 0),
        'total_transactions' => (int) ($summary['total_transactions'] ?? 0),
        'expense_by_category' => $expByCat,
        'income_by_category' => $incByCat,
        'daily_trend' => $daily,
        'transactions' => $transactions,
    ];
}

// ============================================================
// Jalankan query
// ============================================================
$useNamed = ($userIds === null);

if ($useNamed) {
    $data = runQuery($db, $where, $params, true);
} else {
    $data = runQuery($db, $where, $params, false);
}

// ============================================================
// Export CSV
// ============================================================
if ($export === 'csv') {
    $filename = 'kitacatat_laporan_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM untuk Excel
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kode', 'Deskripsi', 'Kategori', 'Tipe', 'Nominal (Rp)', 'Tanggal']);

    foreach ($data['transactions'] as $t) {
        fputcsv($out, [
            $t['unique_code'],
            $t['description'] ?? '-',
            $t['category_name'] ?? 'Lainnya',
            $t['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran',
            $t['amount'],
            date('d/m/Y H:i', strtotime($t['created_at'])),
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// JSON Response
// ============================================================
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'data' => $data]);
