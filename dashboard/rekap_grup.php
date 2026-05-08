<?php
// ============================================================
// KitaCatat — Dashboard: Rekap Grup
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Rekap Grup';
require_once __DIR__ . '/layout/header.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Ambil semua grup yang diikuti user
$stmtGroups = $db->prepare(
    "SELECT g.id, g.name, g.alias, g.is_shared, gm.role
     FROM groups g
     JOIN group_members gm ON gm.group_id = g.id
     WHERE gm.user_id = ?
     ORDER BY g.name"
);
$stmtGroups->execute([$userId]);
$groups = $stmtGroups->fetchAll();

// Filter
$selectedGroupId = (int)($_GET['group_id'] ?? ($groups[0]['id'] ?? 0));
$period          = $_GET['period'] ?? 'this_month';
$scope           = $_GET['scope']  ?? 'group'; // group = semua anggota, personal = hanya saya

// Jumlah transaksi yang ditampilkan (0 = semua)
$limitOptions = [5, 10, 20, 50, 100, 0];
$limitRaw     = $_GET['limit'] ?? '20';
$limit        = in_array((int)$limitRaw, $limitOptions) ? (int)$limitRaw : 20;

// Hitung rentang tanggal
$today = date('Y-m-d');
switch ($period) {
    case 'this_month':
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-t');
        $label    = 'Bulan Ini (' . date('F Y') . ')';
        break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Bulan Lalu (' . date('F Y', strtotime('last month')) . ')';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'Tahun Ini (' . date('Y') . ')';
        break;
    case 'last_7':
        $dateFrom = date('Y-m-d', strtotime('-6 days'));
        $dateTo   = $today;
        $label    = '7 Hari Terakhir';
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-t');
        $label    = 'Bulan Ini';
}

// Ambil anggota grup yang dipilih
$members = [];
if ($selectedGroupId) {
    $stmtMembers = $db->prepare(
        "SELECT u.id, u.name, u.wa_number, gm.role
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?
         ORDER BY gm.role DESC, u.name"
    );
    $stmtMembers->execute([$selectedGroupId]);
    $members = $stmtMembers->fetchAll();
}

// Ambil data rekap per anggota
$memberStats = [];
$totalGroupIncome  = 0;
$totalGroupExpense = 0;

if ($selectedGroupId && !empty($members)) {
    $scopeUserIds = ($scope === 'personal') ? [$userId] : array_column($members, 'id');

    foreach ($scopeUserIds as $mId) {
        $stmt = $db->prepare(
            "SELECT
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
                COUNT(*) AS trx_count
             FROM transactions
             WHERE user_id = ?
               AND (group_id = ? OR group_id IS NULL)
               AND DATE(created_at) BETWEEN ? AND ?
               AND deleted_at IS NULL"
        );
        $stmt->execute([$mId, $selectedGroupId, $dateFrom, $dateTo]);
        $stat = $stmt->fetch();

        // Cari nama anggota
        $memberName = 'User';
        foreach ($members as $m) {
            if ((int)$m['id'] === (int)$mId) {
                $memberName = $m['name'];
                break;
            }
        }

        $inc = (int)($stat['total_income']  ?? 0);
        $exp = (int)($stat['total_expense'] ?? 0);
        $totalGroupIncome  += $inc;
        $totalGroupExpense += $exp;

        $memberStats[] = [
            'user_id'       => $mId,
            'name'          => $memberName,
            'total_income'  => $inc,
            'total_expense' => $exp,
            'trx_count'     => (int)($stat['trx_count'] ?? 0),
            'saldo'         => $inc - $exp,
        ];
    }
}

// Top 5 kategori pengeluaran grup
$topCategories = [];
if ($selectedGroupId) {
    $scopeUserIds = ($scope === 'personal') ? [$userId] : array_column($members, 'id');
    $placeholders = implode(',', array_fill(0, count($scopeUserIds), '?'));
    $params = array_merge($scopeUserIds, [$selectedGroupId, $dateFrom, $dateTo]);

    $stmtTop = $db->prepare(
        "SELECT c.name, c.icon, SUM(t.amount) AS total, COUNT(*) AS cnt
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id IN ($placeholders)
           AND (t.group_id = ? OR t.group_id IS NULL)
           AND t.type = 'expense'
           AND DATE(t.created_at) BETWEEN ? AND ?
           AND t.deleted_at IS NULL
         GROUP BY c.id, c.name, c.icon
         ORDER BY total DESC LIMIT 5"
    );
    $stmtTop->execute($params);
    $topCategories = $stmtTop->fetchAll();
}

// Transaksi terbaru grup
$recentTrx = [];
if ($selectedGroupId) {
    $scopeUserIds = ($scope === 'personal') ? [$userId] : array_column($members, 'id');
    $placeholders = implode(',', array_fill(0, count($scopeUserIds), '?'));
    $params = array_merge($scopeUserIds, [$selectedGroupId, $dateFrom, $dateTo]);

    $limitClause = $limit > 0 ? "LIMIT {$limit}" : "";
    $stmtTrx = $db->prepare(
        "SELECT t.*, u.name AS user_name, c.name AS category_name, c.icon AS category_icon
         FROM transactions t
         JOIN users u ON u.id = t.user_id
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id IN ($placeholders)
           AND (t.group_id = ? OR t.group_id IS NULL)
           AND DATE(t.created_at) BETWEEN ? AND ?
           AND t.deleted_at IS NULL
         ORDER BY t.created_at DESC {$limitClause}"
    );
    $stmtTrx->execute($params);
    $recentTrx = $stmtTrx->fetchAll();
}

function fRp(int $n): string { return 'Rp ' . number_format($n, 0, ',', '.'); }
$selectedGroup = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === $selectedGroupId) { $selectedGroup = $g; break; }
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Rekap Grup</h5>
        <p class="text-muted mb-0" style="font-size:13px">
            Ringkasan keuangan grup — <?= htmlspecialchars($label) ?>
        </p>
    </div>
</div>

<?php if (empty($groups)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div style="font-size:48px;margin-bottom:12px">👥</div>
        <h6 class="fw-bold mb-2">Belum ada grup</h6>
        <p class="text-muted mb-4" style="font-size:13px">Buat atau bergabung ke grup terlebih dahulu.</p>
        <a href="/dashboard/groups.php" class="btn btn-sm btn-primary">
            <i class="fa-solid fa-plus me-1"></i>Kelola Grup
        </a>
    </div>
</div>
<?php else: ?>

<!-- Filter Bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--text-muted)">GRUP</label>
                <select name="group_id" class="form-select form-select-sm">
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $g['id']==$selectedGroupId?'selected':'' ?>>
                        <?= htmlspecialchars($g['name']) ?>
                        <?= $g['is_shared'] ? '(Shared)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--text-muted)">PERIODE</label>
                <select name="period" class="form-select form-select-sm">
                    <option value="this_month" <?= $period==='this_month'?'selected':'' ?>>Bulan Ini</option>
                    <option value="last_month" <?= $period==='last_month'?'selected':'' ?>>Bulan Lalu</option>
                    <option value="last_7"     <?= $period==='last_7'    ?'selected':'' ?>>7 Hari Terakhir</option>
                    <option value="this_year"  <?= $period==='this_year' ?'selected':'' ?>>Tahun Ini</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--text-muted)">LINGKUP</label>
                <select name="scope" class="form-select form-select-sm">
                    <option value="group"    <?= $scope==='group'   ?'selected':'' ?>>Semua Anggota</option>
                    <option value="personal" <?= $scope==='personal'?'selected':'' ?>>Hanya Saya</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:11px;font-weight:600;color:var(--text-muted)">TAMPILKAN</label>
                <select name="limit" class="form-select form-select-sm">
                    <option value="5"   <?= $limit===5  ?'selected':'' ?>>5 transaksi</option>
                    <option value="10"  <?= $limit===10 ?'selected':'' ?>>10 transaksi</option>
                    <option value="20"  <?= $limit===20 ?'selected':'' ?>>20 transaksi</option>
                    <option value="50"  <?= $limit===50 ?'selected':'' ?>>50 transaksi</option>
                    <option value="100" <?= $limit===100?'selected':'' ?>>100 transaksi</option>
                    <option value="0"   <?= $limit===0  ?'selected':'' ?>>Semua</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-fill">
                    <i class="fa-solid fa-filter me-1"></i>Tampilkan
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        onclick="printRekap()" title="Print / Simpan PDF"
                        style="padding-left:11px;padding-right:11px;flex-shrink:0">
                    <i class="fa-solid fa-print"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedGroupId && !empty($memberStats)): ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div>
                <div class="stat-label">Total Pemasukan</div>
                <div class="stat-value" style="font-size:clamp(13px,3vw,16px)"><?= fRp($totalGroupIncome) ?></div>
                <div class="stat-sub"><?= $label ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div>
                <div class="stat-label">Total Pengeluaran</div>
                <div class="stat-value" style="font-size:clamp(13px,3vw,16px)"><?= fRp($totalGroupExpense) ?></div>
                <div class="stat-sub"><?= $label ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php $saldoGrup = $totalGroupIncome - $totalGroupExpense; ?>
        <div class="stat-card">
            <div class="stat-icon <?= $saldoGrup >= 0 ? 'green' : 'red' ?>">
                <i class="fa-solid fa-scale-balanced"></i>
            </div>
            <div>
                <div class="stat-label">Saldo</div>
                <div class="stat-value" style="font-size:clamp(13px,3vw,16px);color:<?= $saldoGrup>=0?'#16a34a':'#dc2626' ?>">
                    <?= fRp(abs($saldoGrup)) ?>
                </div>
                <div class="stat-sub"><?= $saldoGrup >= 0 ? 'Surplus' : 'Defisit' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="stat-label">Anggota Aktif</div>
                <div class="stat-value"><?= count($memberStats) ?></div>
                <div class="stat-sub"><?= count($members) ?> total anggota</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Kontribusi per Anggota -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <i class="fa-solid fa-users me-2 text-muted"></i>Kontribusi per Anggota
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:13px">
                        <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                            <tr>
                                <th class="px-4 py-3">Anggota</th>
                                <th class="py-3 text-end">Pemasukan</th>
                                <th class="py-3 text-end">Pengeluaran</th>
                                <th class="py-3 text-end pe-4">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($memberStats as $ms): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width:30px;height:30px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#2563eb;flex-shrink:0">
                                        <?= strtoupper(substr($ms['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:600"><?= htmlspecialchars($ms['name']) ?></div>
                                        <div style="font-size:11px;color:var(--text-muted)"><?= $ms['trx_count'] ?> transaksi</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 text-end" style="color:#16a34a;font-weight:600;font-size:12px">
                                +<?= fRp($ms['total_income']) ?>
                            </td>
                            <td class="py-3 text-end" style="color:#dc2626;font-weight:600;font-size:12px">
                                -<?= fRp($ms['total_expense']) ?>
                            </td>
                            <td class="py-3 text-end pe-4" style="font-weight:700;font-size:12px;color:<?= $ms['saldo']>=0?'#16a34a':'#dc2626' ?>">
                                <?= fRp(abs($ms['saldo'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Pengeluaran -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <i class="fa-solid fa-fire me-2 text-muted"></i>Top Pengeluaran
            </div>
            <div class="card-body p-0">
                <?php if (empty($topCategories)): ?>
                <div class="text-center text-muted py-4" style="font-size:13px">Belum ada data</div>
                <?php else: ?>
                <?php foreach ($topCategories as $i => $cat): ?>
                <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
                    <div style="width:32px;height:32px;background:#fee2e2;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#dc2626;flex-shrink:0">
                        <i class="fa-solid <?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?>"></i>
                    </div>
                    <div class="flex-fill">
                        <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($cat['name'] ?? 'Lainnya') ?></div>
                        <div style="font-size:11px;color:var(--text-muted)"><?= $cat['cnt'] ?> transaksi</div>
                    </div>
                    <div style="font-size:13px;font-weight:700;color:#dc2626"><?= fRp((int)$cat['total']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row g-3 mb-4" id="chartSection">
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-chart-pie me-2 text-muted"></i>Komposisi Pengeluaran
            </div>
            <div class="card-body" style="position:relative;height:260px">
                <canvas id="pieChart"></canvas>
                <div id="pieEmpty" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;font-size:13px;color:var(--text-muted)">
                    Belum ada data
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-chart-bar me-2 text-muted"></i>Pemasukan vs Pengeluaran per Anggota
            </div>
            <div class="card-body" style="position:relative;height:260px">
                <canvas id="barChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Transaksi Terbaru -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Transaksi Terbaru Grup</span>
        <span class="text-muted" style="font-size:12px">
            <?= count($recentTrx) ?> transaksi
            <?php if ($limit > 0): ?>
            <span style="font-size:11px;background:#f1f5f9;padding:1px 7px;border-radius:4px;margin-left:4px">
                limit <?= $limit ?>
            </span>
            <?php else: ?>
            <span style="font-size:11px;background:#f1f5f9;padding:1px 7px;border-radius:4px;margin-left:4px">
                semua
            </span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentTrx)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">Belum ada transaksi</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="py-3">Anggota</th>
                        <th class="py-3">Deskripsi</th>
                        <th class="py-3">Kategori</th>
                        <th class="py-3 text-end pe-4">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recentTrx as $t): ?>
                <tr>
                    <td class="px-4 py-2" style="font-size:12px;color:var(--text-muted);white-space:nowrap">
                        <?= date('d M Y', strtotime($t['created_at'])) ?>
                    </td>
                    <td class="py-2" style="font-weight:600"><?= htmlspecialchars($t['user_name']) ?></td>
                    <td class="py-2"><?= htmlspecialchars($t['description'] ?? '—') ?></td>
                    <td class="py-2">
                        <span style="font-size:11.5px;color:var(--text-muted)">
                            <i class="fa-solid <?= htmlspecialchars($t['category_icon'] ?? 'fa-tag') ?> me-1"></i>
                            <?= htmlspecialchars($t['category_name'] ?? 'Lainnya') ?>
                        </span>
                    </td>
                    <td class="py-2 text-end pe-4" style="font-weight:700;font-size:12px;color:<?= $t['type']==='income'?'#16a34a':'#dc2626' ?>">
                        <?= $t['type']==='income'?'+':'-' ?><?= fRp((int)$t['amount']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($selectedGroupId): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div style="font-size:40px;margin-bottom:12px">📊</div>
        <h6 class="fw-bold mb-2">Belum ada data</h6>
        <p class="text-muted" style="font-size:13px">Tidak ada transaksi pada periode ini.</p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
// Data untuk chart
$pieLabels  = json_encode(array_column($topCategories, 'name') ?: ['Tidak ada data']);
$pieData    = json_encode(array_map(fn($c) => (int)$c['total'], $topCategories) ?: [0]);
$barLabels  = json_encode(array_column($memberStats, 'name'));
$barIncome  = json_encode(array_column($memberStats, 'total_income'));
$barExpense = json_encode(array_column($memberStats, 'total_expense'));

ob_start();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Pie chart — komposisi pengeluaran
const pieLabels  = <?= $pieLabels ?>;
const pieData    = <?= $pieData ?>;
const hasData    = pieData.some(v => v > 0);

if (hasData) {
    new Chart(document.getElementById('pieChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieData,
                backgroundColor: [
                    '#dc2626','#f97316','#eab308','#16a34a',
                    '#0891b2','#7c3aed','#db2777','#64748b'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { font: { size: 11 }, boxWidth: 12, padding: 8 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ' ' + ctx.label + ': Rp ' + ctx.raw.toLocaleString('id-ID');
                        }
                    }
                }
            }
        }
    });
} else {
    document.getElementById('pieChart').style.display = 'none';
    document.getElementById('pieEmpty').style.display = 'flex';
}

// Bar chart — per anggota
const barLabels  = <?= $barLabels ?>;
const barIncome  = <?= $barIncome ?>;
const barExpense = <?= $barExpense ?>;

if (barLabels.length > 0) {
    new Chart(document.getElementById('barChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: barLabels,
            datasets: [
                {
                    label: 'Pemasukan',
                    data: barIncome,
                    backgroundColor: 'rgba(22,163,74,.8)',
                    borderRadius: 5,
                },
                {
                    label: 'Pengeluaran',
                    data: barExpense,
                    backgroundColor: 'rgba(220,38,38,.75)',
                    borderRadius: 5,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 12 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' Rp ' + ctx.raw.toLocaleString('id-ID')
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: v => 'Rp ' + (v/1000000).toFixed(1) + 'jt', font: { size: 10 } },
                    grid: { color: '#f1f5f9' }
                },
                x: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
}
}); // end DOMContentLoaded

// ============================================================
// Print / PDF — popup window bersih
// ============================================================
const REKAP_DATA = {
    groupName  : <?= json_encode($selectedGroup['name'] ?? '') ?>,
    label      : <?= json_encode($label) ?>,
    scope      : <?= json_encode($scope === 'personal' ? 'Hanya Saya' : 'Semua Anggota') ?>,
    limit      : <?= json_encode($limit === 0 ? 'Semua' : $limit) ?>,
    totalIncome : <?= (int)$totalGroupIncome ?>,
    totalExpense: <?= (int)$totalGroupExpense ?>,
    members    : <?= json_encode(array_map(fn($m) => [
        'name'          => $m['name'],
        'total_income'  => $m['total_income'],
        'total_expense' => $m['total_expense'],
        'saldo'         => $m['saldo'],
        'trx_count'     => $m['trx_count'],
    ], $memberStats)) ?>,
    topCategories: <?= json_encode(array_map(fn($c) => [
        'name'  => $c['name'] ?? 'Lainnya',
        'total' => (int)$c['total'],
        'cnt'   => (int)$c['cnt'],
    ], $topCategories)) ?>,
    transactions: <?= json_encode(array_map(fn($t) => [
        'created_at'    => $t['created_at'],
        'user_name'     => $t['user_name'],
        'description'   => $t['description'] ?? '—',
        'category_name' => $t['category_name'] ?? 'Lainnya',
        'type'          => $t['type'],
        'amount'        => (int)$t['amount'],
    ], $recentTrx)) ?>,
};

function fRpJs(n) {
    return 'Rp\u00a0' + parseInt(n).toLocaleString('id-ID');
}

function printRekap() {
    const d = REKAP_DATA;
    if (!d.groupName) { alert('Tidak ada data untuk dicetak.'); return; }

    const saldo = d.totalIncome - d.totalExpense;

    // Tabel anggota
    const memberRows = d.members.map(m => `<tr>
        <td>${m.name}</td>
        <td style="text-align:right;color:#16a34a">${fRpJs(m.total_income)}</td>
        <td style="text-align:right;color:#dc2626">${fRpJs(m.total_expense)}</td>
        <td style="text-align:right;font-weight:700;color:${m.saldo >= 0 ? '#16a34a' : '#dc2626'}">
            ${m.saldo < 0 ? '-' : ''}${fRpJs(Math.abs(m.saldo))}
        </td>
        <td style="text-align:center">${m.trx_count}</td>
    </tr>`).join('');

    // Top kategori
    const catRows = d.topCategories.map(c => `<tr>
        <td>${c.name}</td>
        <td style="text-align:right;color:#dc2626">${fRpJs(c.total)}</td>
        <td style="text-align:center;color:#64748b">${c.cnt}×</td>
    </tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:#94a3b8">Tidak ada data</td></tr>';

    // Transaksi
    const txnRows = d.transactions.map(t => `<tr>
        <td style="color:#64748b;font-size:10px;white-space:nowrap">
            ${new Date(t.created_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})}
        </td>
        <td style="font-weight:600">${t.user_name}</td>
        <td>${t.description}</td>
        <td>${t.category_name}</td>
        <td style="text-align:right;font-weight:700;color:${t.type==='income'?'#16a34a':'#dc2626'}">
            ${t.type==='income'?'+':'-'}${fRpJs(t.amount)}
        </td>
    </tr>`).join('') || '<tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada transaksi</td></tr>';

    const html = `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Rekap Grup — ${d.groupName}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #0f172a; padding: 24px 32px; }
  h2 { font-size: 17px; margin-bottom: 4px; }
  h3 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; text-transform: uppercase; letter-spacing: .5px; color: #475569; }
  .meta { font-size: 11px; color: #64748b; margin-bottom: 16px; }
  .summary { display: flex; gap: 10px; margin-bottom: 4px; }
  .summary-box { flex: 1; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px 13px; }
  .summary-label { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; }
  .summary-value { font-size: 14px; font-weight: 700; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
  th, td { border: 1px solid #e2e8f0; padding: 5px 8px; text-align: left; font-size: 11px; }
  th { background: #f8fafc; font-weight: 700; }
  .toolbar { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
  .btn-print { background: #2563eb; color: #fff; border: none; padding: 7px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600; }
  .btn-close-win { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; padding: 7px 13px; border-radius: 6px; font-size: 13px; cursor: pointer; }
  @media print {
    .toolbar { display: none !important; }
    body { padding: 0 16px; }
  }
</style>
</head>
<body>

<div class="toolbar">
    <button class="btn-close-win" onclick="window.close()">✕ Tutup</button>
    <button class="btn-print" onclick="window.print()">🖨 Print / Simpan PDF</button>
</div>

<h2>Rekap Grup — ${d.groupName}</h2>
<div class="meta">
    Periode: <strong>${d.label}</strong> &nbsp;·&nbsp;
    Lingkup: <strong>${d.scope}</strong> &nbsp;·&nbsp;
    Transaksi ditampilkan: <strong>${d.limit}</strong> &nbsp;·&nbsp;
    Dicetak: ${new Date().toLocaleString('id-ID')}
</div>

<div class="summary">
    <div class="summary-box">
        <div class="summary-label">Total Pemasukan</div>
        <div class="summary-value" style="color:#16a34a">${fRpJs(d.totalIncome)}</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Pengeluaran</div>
        <div class="summary-value" style="color:#dc2626">${fRpJs(d.totalExpense)}</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Saldo</div>
        <div class="summary-value" style="color:${saldo >= 0 ? '#16a34a' : '#dc2626'}">
            ${saldo < 0 ? '-' : ''}${fRpJs(Math.abs(saldo))}
        </div>
    </div>
</div>

<h3>Rekap per Anggota</h3>
<table>
    <thead><tr>
        <th>Nama</th>
        <th style="text-align:right">Pemasukan</th>
        <th style="text-align:right">Pengeluaran</th>
        <th style="text-align:right">Saldo</th>
        <th style="text-align:center">Trx</th>
    </tr></thead>
    <tbody>${memberRows || '<tr><td colspan="5" style="text-align:center;color:#94a3b8">Tidak ada data</td></tr>'}</tbody>
</table>

<h3>Top Kategori Pengeluaran</h3>
<table>
    <thead><tr><th>Kategori</th><th style="text-align:right">Total</th><th style="text-align:center">Frekuensi</th></tr></thead>
    <tbody>${catRows}</tbody>
</table>

<h3>Transaksi Terbaru (${d.limit === 'Semua' ? 'semua' : 'limit ' + d.limit})</h3>
<table>
    <thead><tr>
        <th>Tanggal</th><th>Anggota</th><th>Deskripsi</th><th>Kategori</th>
        <th style="text-align:right">Nominal</th>
    </tr></thead>
    <tbody>${txnRows}</tbody>
</table>

</body>
</html>`;

    const win = window.open('', '_blank', 'width=960,height=720,scrollbars=yes,resizable=yes');
    if (!win) {
        alert('Popup diblokir browser. Izinkan popup untuk situs ini lalu coba lagi.');
        return;
    }
    win.document.write(html);
    win.document.close();
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
