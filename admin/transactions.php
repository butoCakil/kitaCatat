<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Manajemen Transaksi';
require_once __DIR__ . '/layout/header.php';

$db      = getDB();
$search  = trim($_GET['search']  ?? '');
$filter  = $_GET['filter']  ?? 'all'; // all | deleted
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = [];
$params = [];

if ($filter === 'deleted') {
    $where[] = 't.deleted_at IS NOT NULL';
} else {
    $where[] = 't.deleted_at IS NULL';
}

if ($search) {
    $where[]  = "(t.unique_code LIKE ? OR t.description LIKE ? OR u.name LIKE ? OR u.wa_number LIKE ?)";
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM transactions t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN categories c ON c.id = t.category_id
     $whereStr"
);
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = ceil($total / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT t.*, u.name AS user_name, u.wa_number, c.name AS category_name
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN categories c ON c.id = t.category_id
     $whereStr
     ORDER BY t.created_at DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $i => $v) $stmt->bindValue($i+1, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

function fRp(int $n): string { return 'Rp '.number_format($n,0,',','.'); }
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Manajemen Transaksi</h5>
        <p class="text-muted mb-0" style="font-size:13px">Semua transaksi platform</p>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Cari kode TXN, deskripsi, nama user, nomor WA..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="filter" class="form-select form-select-sm">
                    <option value="all"     <?= $filter==='all'    ?'selected':'' ?>>Transaksi Aktif</option>
                    <option value="deleted" <?= $filter==='deleted'?'selected':'' ?>>🗑️ Terhapus (Soft Delete)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="fa-solid fa-search me-1"></i>Cari
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($filter === 'deleted'): ?>
<div class="alert mb-3" style="background:#fef9c3;border:1px solid #fef08a;border-radius:var(--radius);font-size:13px;color:#854d0e">
    <i class="fa-solid fa-trash-arrow-up me-2"></i>
    Menampilkan transaksi yang dihapus (soft delete). Admin dapat memulihkan transaksi ini.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12.5px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">User</th>
                        <th class="py-3">Kode</th>
                        <th class="py-3">Deskripsi</th>
                        <th class="py-3">Kategori</th>
                        <th class="py-3">Tipe</th>
                        <th class="py-3 text-end">Nominal</th>
                        <th class="py-3 text-end">Tanggal</th>
                        <th class="py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr <?= $t['deleted_at'] ? 'style="opacity:.6"' : '' ?>>
                    <td class="px-4 py-2">
                        <div style="font-weight:600;font-size:12px"><?= htmlspecialchars($t['user_name']) ?></div>
                        <div style="font-size:10px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($t['wa_number']) ?></div>
                    </td>
                    <td class="py-2"><span class="txn-code"><?= htmlspecialchars($t['unique_code']) ?></span></td>
                    <td class="py-2"><?= htmlspecialchars($t['description'] ?? '—') ?></td>
                    <td class="py-2" style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($t['category_name'] ?? 'Lainnya') ?></td>
                    <td class="py-2">
                        <?php if ($t['type']==='income'): ?>
                            <span class="badge badge-active rounded-pill px-2" style="font-size:10px">Masuk</span>
                        <?php else: ?>
                            <span class="badge" style="background:#fee2e2;color:#dc2626;font-weight:600;font-size:10px" class="rounded-pill px-2">Keluar</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 text-end" style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:<?= $t['type']==='income'?'#16a34a':'#dc2626' ?>">
                        <?= $t['type']==='income'?'+':'-' ?><?= fRp((int)$t['amount']) ?>
                    </td>
                    <td class="py-2 text-end" style="font-size:11px;color:var(--text-muted)">
                        <?= date('d M Y', strtotime($t['created_at'])) ?>
                        <?php if ($t['deleted_at']): ?>
                        <br><span style="color:#dc2626;font-size:10px">Dihapus <?= date('d M', strtotime($t['deleted_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 text-center">
                        <?php if ($t['deleted_at']): ?>
                        <button class="btn btn-sm btn-outline-success"
                            style="padding:3px 8px;font-size:11px"
                            onclick="restoreTrx(<?= $t['id'] ?>, '<?= htmlspecialchars($t['unique_code']) ?>')"
                            title="Pulihkan transaksi">
                            <i class="fa-solid fa-trash-arrow-up"></i> Pulihkan
                        </button>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--text-muted)">Aktif</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
            <span style="font-size:12px;color:var(--text-muted)">
                <?= ($offset+1) ?>–<?= min($offset+$perPage,$total) ?> dari <?= number_format($total) ?>
            </span>
            <div class="d-flex gap-1">
                <?php for ($i=1;$i<=$pages;$i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter=<?= $filter ?>"
                   class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline-secondary' ?>"
                   style="font-size:11px;padding:4px 10px;min-width:32px"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
ob_start();
?>
<script>
async function restoreTrx(id, code) {
    if (!confirm('Pulihkan transaksi ' + code + '?')) return;
    const res  = await fetch('/api/admin/transactions.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'restore', transaction_id: id })
    });
    const data = await res.json();
    if (data.success) { alert('Transaksi berhasil dipulihkan.'); location.reload(); }
    else alert(data.message || 'Gagal.');
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
