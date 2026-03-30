<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Manajemen User';
require_once __DIR__ . '/layout/header.php';

$db       = getDB();
$adminId  = (int)$_SESSION['admin_id'];
$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(u.name LIKE ? OR u.wa_number LIKE ? OR u.email LIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
if ($status === 'active')   { $where[] = 'u.is_active = 1'; }
if ($status === 'inactive') { $where[] = 'u.is_active = 0'; }

$whereStr = implode(' AND ', $where);

$total  = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr");
$total->execute($params);
$total  = (int)$total->fetchColumn();
$pages  = ceil($total / $perPage);
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT u.*,
        (SELECT COUNT(*) FROM transactions t WHERE t.user_id=u.id AND t.deleted_at IS NULL) AS trx_count,
        (SELECT MAX(t.created_at) FROM transactions t WHERE t.user_id=u.id) AS last_trx
     FROM users u WHERE $whereStr
     ORDER BY u.created_at DESC LIMIT :lim OFFSET :off"
);
foreach ($params as $i => $v) $stmt->bindValue($i+1, $v);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

function logAdmin(PDO $db, int $adminId, string $action, string $target, string $note = ''): void {
    $db->prepare("INSERT INTO admin_logs (admin_id, action, target, note, ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId, $action, $target, $note, $_SERVER['REMOTE_ADDR'] ?? '']);
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Manajemen User</h5>
        <p class="text-muted mb-0" style="font-size:13px">Total <?= number_format($total) ?> user terdaftar</p>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Cari nama, nomor WA, atau email..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="active"   <?= $status==='active'  ?'selected':'' ?>>Aktif</option>
                    <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="fa-solid fa-search me-1"></i>Cari
                </button>
            </div>
            <?php if ($search || $status): ?>
            <div class="col-6 col-md-1">
                <a href="/admin/users.php" class="btn btn-sm btn-outline-secondary w-100">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Tabel -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">User</th>
                        <th class="py-3">Nomor WA</th>
                        <th class="py-3">Transaksi</th>
                        <th class="py-3">Terakhir Aktif</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="px-4 py-3">
                        <div style="font-weight:600"><?= htmlspecialchars($u['name'] ?? '—') ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($u['email'] ?? '—') ?></div>
                    </td>
                    <td class="py-3" style="font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($u['wa_number']) ?></td>
                    <td class="py-3"><?= number_format($u['trx_count']) ?></td>
                    <td class="py-3" style="font-size:12px;color:var(--text-muted)">
                        <?= $u['last_trx'] ? date('d M Y', strtotime($u['last_trx'])) : '—' ?>
                    </td>
                    <td class="py-3">
                        <?php if ($u['is_active']): ?>
                            <span class="badge badge-active rounded-pill px-2">Aktif</span>
                        <?php else: ?>
                            <span class="badge badge-inactive rounded-pill px-2">Nonaktif</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <!-- Toggle aktif/nonaktif -->
                            <button class="btn btn-sm <?= $u['is_active']?'btn-outline-warning':'btn-outline-success' ?>"
                                style="padding:3px 8px;font-size:11px"
                                onclick="toggleUser(<?= $u['id'] ?>, <?= $u['is_active'] ?>, '<?= htmlspecialchars($u['name']) ?>')"
                                title="<?= $u['is_active']?'Nonaktifkan':'Aktifkan' ?>">
                                <i class="fa-solid <?= $u['is_active']?'fa-ban':'fa-check' ?>"></i>
                            </button>
                            <!-- Reset password -->
                            <button class="btn btn-sm btn-outline-secondary"
                                style="padding:3px 8px;font-size:11px"
                                onclick="resetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name']) ?>')"
                                title="Reset Password">
                                <i class="fa-solid fa-key"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top">
            <span style="font-size:12px;color:var(--text-muted)">
                Menampilkan <?= ($offset+1) ?>–<?= min($offset+$perPage,$total) ?> dari <?= number_format($total) ?>
            </span>
            <div class="d-flex gap-1">
                <?php for ($i=1; $i<=$pages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>"
                   class="btn btn-sm <?= $i===$page?'btn-primary':'btn-outline-secondary' ?>"
                   style="font-size:11px;padding:4px 10px;min-width:32px"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Reset Password -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:var(--radius)">
            <div class="modal-body p-4 text-center">
                <div style="width:48px;height:48px;background:#fef9c3;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
                    <i class="fa-solid fa-key" style="color:#ca8a04"></i>
                </div>
                <h6 class="fw-bold mb-1">Reset Password</h6>
                <p class="text-muted mb-3" style="font-size:13px">Reset password untuk <strong id="resetUserName"></strong></p>
                <div class="mb-3 text-start">
                    <label class="form-label" style="font-size:12px;font-weight:600">Password Baru</label>
                    <input type="password" id="newPassword" class="form-control form-control-sm" placeholder="Minimal 6 karakter">
                </div>
                <div class="d-flex gap-2 justify-content-center">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button class="btn btn-sm btn-warning" onclick="confirmReset()">Reset</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script>
const resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
let resetUserId = null;

async function toggleUser(id, isActive, name) {
    const action = isActive ? 'nonaktifkan' : 'aktifkan';
    if (!confirm(`${action.charAt(0).toUpperCase()+action.slice(1)} user "${name}"?`)) return;
    const res  = await fetch('/api/admin/users.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'toggle', user_id: id })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

function resetPassword(id, name) {
    resetUserId = id;
    document.getElementById('resetUserName').textContent = name;
    document.getElementById('newPassword').value = '';
    resetModal.show();
}

async function confirmReset() {
    const pass = document.getElementById('newPassword').value.trim();
    if (!pass || pass.length < 6) { alert('Password minimal 6 karakter.'); return; }
    const res  = await fetch('/api/admin/users.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'reset_password', user_id: resetUserId, password: pass })
    });
    const data = await res.json();
    if (data.success) { resetModal.hide(); alert('Password berhasil direset.'); }
    else alert(data.message || 'Gagal.');
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
