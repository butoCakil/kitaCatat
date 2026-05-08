<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Dashboard Admin';
require_once __DIR__ . '/layout/header.php';

$db = getDB();

// Statistik platform
$totalUsers    = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$totalTrx      = $db->query("SELECT COUNT(*) FROM transactions WHERE deleted_at IS NULL")->fetchColumn();
$totalDeleted  = $db->query("SELECT COUNT(*) FROM transactions WHERE deleted_at IS NOT NULL")->fetchColumn();
$totalGroups   = $db->query("SELECT COUNT(*) FROM groups")->fetchColumn();

// User aktif 7 hari terakhir
$activeUsers = $db->query(
    "SELECT COUNT(DISTINCT user_id) FROM transactions
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL"
)->fetchColumn();

// Transaksi hari ini
$todayTrx = $db->query(
    "SELECT COUNT(*) FROM transactions WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL"
)->fetchColumn();

// Transaksi bulan ini
$monthTrx = $db->query(
    "SELECT COUNT(*) FROM transactions
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND deleted_at IS NULL"
)->fetchColumn();

// Total nominal bulan ini
$monthAmount = $db->query(
    "SELECT SUM(amount) FROM transactions
     WHERE type='expense' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND deleted_at IS NULL"
)->fetchColumn();

// Pertumbuhan user per bulan (6 bulan)
$growthData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $cnt = $db->prepare(
        "SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $cnt->execute([$m]);
    $growthData[] = ['month' => date('M', strtotime($m.'-01')), 'count' => (int)$cnt->fetchColumn()];
}

// User terbaru
$newUsers = $db->query(
    "SELECT id, name, wa_number, email, created_at FROM users ORDER BY created_at DESC LIMIT 5"
)->fetchAll();

// Transaksi terbaru semua user
$recentTrx = $db->query(
    "SELECT t.unique_code, t.type, t.amount, t.description, t.created_at,
            u.name AS user_name, c.name AS category_name
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.deleted_at IS NULL
     ORDER BY t.created_at DESC LIMIT 8"
)->fetchAll();

// Admin logs terbaru
$adminLogs = $db->query(
    "SELECT al.*, a.name AS admin_name FROM admin_logs al
     JOIN admins a ON a.id = al.admin_id
     ORDER BY al.created_at DESC LIMIT 5"
)->fetchAll();

function fRp(int $n): string { return 'Rp '.number_format($n,0,',','.'); }
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="stat-label">Total User</div>
                <div class="stat-value"><?= $totalUsers ?></div>
                <div class="stat-sub"><?= $activeUsers ?> aktif 7 hari</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="stat-label">Total Transaksi</div>
                <div class="stat-value"><?= number_format($totalTrx) ?></div>
                <div class="stat-sub"><?= $todayTrx ?> hari ini</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon yellow"><i class="fa-solid fa-chart-bar"></i></div>
            <div>
                <div class="stat-label">Transaksi Bulan Ini</div>
                <div class="stat-value"><?= $monthTrx ?></div>
                <div class="stat-sub"><?= fRp((int)$monthAmount) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-users-rectangle"></i></div>
            <div>
                <div class="stat-label">Total Grup</div>
                <div class="stat-value"><?= $totalGroups ?></div>
                <div class="stat-sub"><?= $totalDeleted ?> trx terhapus</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Pertumbuhan User Chart -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa-solid fa-chart-line me-2 text-muted"></i>Pertumbuhan User (6 Bulan)</div>
            <div class="card-body">
                <canvas id="growthChart" height="120"></canvas>
            </div>
        </div>
    </div>

    <!-- User Terbaru -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-user-plus me-2 text-muted"></i>User Terbaru</span>
                <a href="/admin/users.php" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Semua</a>
            </div>
            <div class="card-body p-0">
                <?php foreach ($newUsers as $u): ?>
                <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
                    <div style="width:32px;height:32px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#2563eb;flex-shrink:0">
                        <?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div class="flex-fill">
                        <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($u['name'] ?? '—') ?></div>
                        <div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)"><?= htmlspecialchars($u['wa_number']) ?></div>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= date('d M', strtotime($u['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Transaksi Terbaru Platform -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Transaksi Terbaru Platform</span>
                <a href="/admin/transactions.php" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:12.5px">
                        <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                            <tr>
                                <th class="px-4 py-3">User</th>
                                <th class="py-3">Kode</th>
                                <th class="py-3">Deskripsi</th>
                                <th class="py-3 text-end pe-4">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentTrx as $t): ?>
                        <tr>
                            <td class="px-4 py-2" style="font-weight:600"><?= htmlspecialchars($t['user_name'] ?? '—') ?></td>
                            <td class="py-2"><span class="txn-code"><?= htmlspecialchars($t['unique_code']) ?></span></td>
                            <td class="py-2"><?= htmlspecialchars($t['description'] ?? '—') ?></td>
                            <td class="py-2 text-end pe-4" style="font-family:var(--font-mono);font-weight:600;color:<?= $t['type']==='income'?'#16a34a':'#dc2626' ?>">
                                <?= $t['type']==='income'?'+':'-' ?><?= fRp((int)$t['amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Logs -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-shield-halved me-2 text-muted"></i>Aksi Admin Terbaru</span>
                <a href="/admin/logs.php" class="btn btn-sm btn-outline-secondary" style="font-size:11px">Semua</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($adminLogs)): ?>
                <div class="text-center text-muted py-4" style="font-size:13px">Belum ada aktivitas</div>
                <?php else: ?>
                <?php foreach ($adminLogs as $log): ?>
                <div class="px-4 py-3 border-bottom">
                    <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($log['action']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($log['target'] ?? '') ?></div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                        <?= htmlspecialchars($log['admin_name']) ?> · <?= date('d M H:i', strtotime($log['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('growthChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($growthData, 'month')) ?>,
        datasets: [{
            label: 'User Baru',
            data: <?= json_encode(array_column($growthData, 'count')) ?>,
            backgroundColor: 'rgba(220,38,38,.8)',
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { ticks: { font: { size: 11 } }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false }, ticks: { font: { size: 12 } } }
        }
    }
});
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
