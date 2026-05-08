<?php
// ============================================================
// KitaCatat — Dashboard: Transaksi
// ============================================================
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Transaksi';
require_once __DIR__ . '/layout/header.php';

date_default_timezone_set('Asia/Jakarta');

$db     = getDB();
$userId = $_SESSION['user_id'];

// Ambil kategori untuk filter & form
$stmtCat = $db->prepare(
    "SELECT id, name, type, parent_id FROM categories
     WHERE is_default = 1 OR user_id = ?
     ORDER BY type, COALESCE(parent_id, id), parent_id IS NOT NULL, name"
);
$stmtCat->execute([$userId]);
$rawCats = $stmtCat->fetchAll();

// Kelompokkan: parent dulu, lalu anak-anaknya
$catParents  = [];
$catChildren = [];
foreach ($rawCats as $c) {
    if ($c['parent_id'] === null) {
        $catParents[$c['id']] = $c;
    } else {
        $catChildren[$c['parent_id']][] = $c;
    }
}

function formatRp(int $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Daftar Transaksi</h5>
        <p class="text-muted mb-0" style="font-size:13px">Semua catatan keuangan Anda</p>
    </div>
    <button class="btn btn-sm btn-primary" onclick="openAddModal()">
        <i class="fa-solid fa-plus me-1"></i> Tambah Manual
    </button>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Tipe</label>
                <select id="filterType" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="income">Pemasukan</option>
                    <option value="expense">Pengeluaran</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Kategori</label>
                <select id="filterCategory" class="form-select form-select-sm">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($catParents as $parent): ?>
                        <?php $children = $catChildren[$parent['id']] ?? []; ?>
                        <?php if (!empty($children)): ?>
                            <optgroup label="— <?= htmlspecialchars($parent['name']) ?> —">
                                <?php foreach ($children as $child): ?>
                                    <option value="<?= htmlspecialchars($child['name']) ?>">
                                        <?= htmlspecialchars($child['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php else: ?>
                            <option value="<?= htmlspecialchars($parent['name']) ?>">
                                <?= htmlspecialchars($parent['name']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Dari</label>
                <input type="date" id="filterDateStart" class="form-control form-control-sm"
                       value="<?= date('Y-m-01') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Sampai</label>
                <input type="date" id="filterDateEnd" class="form-control form-control-sm"
                       value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill" onclick="loadTransactions()">
                    <i class="fa-solid fa-filter me-1"></i>Filter
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="resetFilter()">
                    <i class="fa-solid fa-rotate-left"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Summary Bar -->
<div class="row g-2 mb-3" id="summaryBar" style="display:none!important"></div>

<!-- Tabel -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="trxTable" class="table table-hover mb-0" style="font-size:13px; width:100%">
                <thead style="background:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Kode</th>
                        <th class="py-3">Deskripsi</th>
                        <th class="py-3">Kategori</th>
                        <th class="py-3">Tipe</th>
                        <th class="py-3 text-end">Nominal</th>
                        <th class="py-3 text-end">Tanggal</th>
                        <th class="py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="trxBody">
                    <tr><td colspan="7" class="text-center py-4 text-muted">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top" id="paginationBar">
            <span id="paginationInfo" style="font-size:12px; color:var(--text-muted)"></span>
            <div id="paginationLinks" class="d-flex gap-1"></div>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: Tambah / Edit Transaksi
============================================================ -->
<div class="modal fade" id="trxModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--radius); border:1px solid var(--card-border)">
            <div class="modal-header border-bottom" style="padding:16px 20px">
                <h6 class="modal-title fw-bold" id="modalTitle">Tambah Transaksi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <input type="hidden" id="trxId">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Tipe</label>
                        <div class="d-flex gap-2">
                            <button type="button" id="btnExpense" onclick="setType('expense')"
                                class="btn btn-sm flex-fill active-type"
                                style="border:2px solid var(--danger);color:var(--danger);border-radius:8px;font-weight:600">
                                <i class="fa-solid fa-arrow-trend-down me-1"></i>Pengeluaran
                            </button>
                            <button type="button" id="btnIncome" onclick="setType('income')"
                                class="btn btn-sm flex-fill"
                                style="border:2px solid #e2e8f0;color:var(--text-secondary);border-radius:8px;font-weight:600">
                                <i class="fa-solid fa-arrow-trend-up me-1"></i>Pemasukan
                            </button>
                        </div>
                        <input type="hidden" id="trxType" value="expense">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Nominal (Rp)</label>
                        <input type="number" id="trxAmount" class="form-control"
                               placeholder="Contoh: 50000" min="1">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Deskripsi</label>
                        <input type="text" id="trxDesc" class="form-control"
                               placeholder="Contoh: Bensin pertamax" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Kategori</label>
                        <select id="trxCategory" class="form-select">
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($catParents as $parent): ?>
                                <?php $children = $catChildren[$parent['id']] ?? []; ?>
                                <?php if (!empty($children)): ?>
                                    <optgroup label="<?= htmlspecialchars($parent['name']) ?>" data-type="<?= $parent['type'] ?>">
                                        <?php foreach ($children as $child): ?>
                                            <option value="<?= $child['id'] ?>" data-type="<?= $child['type'] ?>">
                                                <?= htmlspecialchars($child['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php else: ?>
                                    <option value="<?= $parent['id'] ?>" data-type="<?= $parent['type'] ?>">
                                        <?= htmlspecialchars($parent['name']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Tanggal</label>
                        <input type="datetime-local" id="trxDate" class="form-control"
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top" style="padding:14px 20px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="saveTrx()">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Hapus -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:var(--radius)">
            <div class="modal-body text-center p-4">
                <div style="width:48px;height:48px;background:var(--danger-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    <i class="fa-solid fa-trash text-danger"></i>
                </div>
                <h6 class="fw-bold mb-1">Hapus Catatan?</h6>
                <p class="text-muted mb-3" style="font-size:13px">
                    <span id="deleteCode" class="txn-code"></span><br>
                    Tindakan ini tidak dapat dibatalkan.
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete()">Hapus</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// $extraScript = '<script src="/assets/js/transactions.js"></script>';
$extraScript = '<script src="/assets/js/transactions.js?v=' . filemtime(__DIR__ . '/../assets/js/transactions.js') . '"></script>';
require_once __DIR__ . '/layout/footer.php';
?>