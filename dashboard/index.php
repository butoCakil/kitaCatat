<?php
// ============================================================
// KitaCatat — Dashboard: Overview
// ============================================================
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Dashboard';
require_once __DIR__ . '/layout/header.php';

$db = getDB();
$userId = $_SESSION['user_id'];

// --- Periode bulan ini ---
$dateStart = date('Y-m-01 00:00:00');
$dateEnd = date('Y-m-t 23:59:59');

// --- Filter periode ---
$period = $_GET['period'] ?? 'this_month';
$firstOfThisMonth = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
if ($period === 'last_month') {
    $dateStart = date('Y-m-01 00:00:00', strtotime('-1 month', $firstOfThisMonth));
    $dateEnd   = date('Y-m-t 23:59:59',  strtotime('-1 month', $firstOfThisMonth));
} else if ($period === '2_month_ago'){
    $dateStart = date('Y-m-01 00:00:00', strtotime('-2 month', $firstOfThisMonth));
    $dateEnd   = date('Y-m-t 23:59:59',  strtotime('-2 month', $firstOfThisMonth));
}
// 'this_month' tetap pakai $dateStart/$dateEnd yang sudah ada di atas

// ===============================
// Ringkasan bulan ini
// ===============================
$stmt = $db->prepare(
    "SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
        COUNT(*) AS total_trx
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL
       AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$summary = $stmt->fetch();

$totalIncome = (int) ($summary['total_income'] ?? 0);
$totalExpense = (int) ($summary['total_expense'] ?? 0);
$totalTrx = (int) ($summary['total_trx'] ?? 0);
$saldo = $totalIncome - $totalExpense;

// ===============================
// Financial Metrics
// ===============================

// Expense Ratio
$expenseRatio = $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0;

// Saving (ambil dari kategori Tabungan / Investasi / Dana Darurat jika ada)
$stmt = $db->prepare(
    "SELECT SUM(t.amount) 
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories parent ON parent.id = c.parent_id
     WHERE t.user_id = ?
       AND t.type = 'expense'
       AND t.deleted_at IS NULL
       AND t.created_at BETWEEN ? AND ?
       AND (
            c.name LIKE '%Tabungan%' OR
            c.name LIKE '%Investasi%' OR
            c.name LIKE '%Dana Darurat%' OR
            parent.name LIKE '%Tabungan%' OR
            parent.name LIKE '%Investasi%' OR
            parent.name LIKE '%Dana Darurat%'
       )"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$totalSaving = (int) $stmt->fetchColumn();

// Saving Rate
$savingRate = $totalIncome > 0 ? ($totalSaving / $totalIncome) * 100 : 0;

// ===============================
// Data bulan lalu
// ===============================
// "bulan sebelumnya" relatif terhadap periode aktif
$firstOfPeriod = mktime(0, 0, 0, (int)date('n', strtotime($dateStart)), 1, (int)date('Y', strtotime($dateStart)));
$lastStart = date('Y-m-01 00:00:00', strtotime('-1 month', $firstOfPeriod));
$lastEnd   = date('Y-m-t 23:59:59',  strtotime('-1 month', $firstOfPeriod));

$stmt = $db->prepare(
    "SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL
       AND created_at BETWEEN ? AND ?"
);
$stmt->execute([$userId, $lastStart, $lastEnd]);
$lastSummary = $stmt->fetch();

$lastIncome  = (int) ($lastSummary['total_income']  ?? 0);
$lastExpense = (int) ($lastSummary['total_expense'] ?? 0);

// Saldo sebelumnya = kumulatif SEMUA transaksi sebelum periode aktif
$stmtPrev = $db->prepare(
    "SELECT
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) -
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS saldo_sebelumnya
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL
       AND created_at < ?"
);
$stmtPrev->execute([$userId, $dateStart]);
$carryOver = (int) ($stmtPrev->fetchColumn() ?? 0);

$lastSaldo = $lastIncome - $lastExpense; // tetap dipakai untuk %perubahan

// ===============================
// Perubahan dari bulan lalu
// ===============================
$incomeChange = $lastIncome > 0 ? (($totalIncome - $lastIncome) / $lastIncome) * 100 : 0;
$expenseChange = $lastExpense > 0 ? (($totalExpense - $lastExpense) / $lastExpense) * 100 : 0;

// ===============================
// Financial Health Score
// ===============================
$score = 0;

// Saving rate (30 poin)
if ($savingRate >= 30)
    $score += 30;
elseif ($savingRate >= 20)
    $score += 25;
elseif ($savingRate >= 10)
    $score += 15;
elseif ($savingRate > 0)
    $score += 5;

// Expense ratio (25 poin)
if ($expenseRatio < 50)
    $score += 25;
elseif ($expenseRatio < 70)
    $score += 20;
elseif ($expenseRatio < 90)
    $score += 10;
elseif ($expenseRatio <= 100)
    $score += 5;

// Cashflow (25 poin)
if ($saldo > 0)
    $score += 25;
elseif ($saldo == 0)
    $score += 10;

// Expense trend (10 poin)
if ($expenseChange < 0)
    $score += 10;
elseif ($expenseChange < 10)
    $score += 5;

// Income trend (10 poin)
if ($incomeChange > 0)
    $score += 10;
elseif ($incomeChange > -10)
    $score += 5;

// ===============================
// --- Komposisi & top pengeluaran — dikelompokkan ke parent kategori ---
// Logika:
//   - Kategori default sistem langsung    → tampil sebagai dirinya
//   - Sub-kategori user (punya parent_id) → digabung ke parentnya
//   - Custom tanpa parent (orphan)        → digabung ke "Lainnya"
$stmt = $db->prepare(
    "SELECT
        COALESCE(parent.id,   CASE WHEN c.is_default=1 THEN c.id   ELSE 0   END) AS group_id,
        COALESCE(parent.name, CASE WHEN c.is_default=1 THEN c.name ELSE 'Lainnya' END) AS group_name,
        COALESCE(parent.icon, CASE WHEN c.is_default=1 THEN c.icon ELSE 'fa-tag' END) AS group_icon,
        SUM(t.amount) AS total
     FROM transactions t
     LEFT JOIN categories c      ON c.id      = t.category_id
     LEFT JOIN categories parent ON parent.id = c.parent_id
     WHERE t.user_id   = ?
       AND t.type      = 'expense'
       AND t.deleted_at IS NULL
       AND t.created_at BETWEEN ? AND ?
     GROUP BY group_id, group_name, group_icon
     HAVING total > 0
     ORDER BY total DESC"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$groupedExpense = $stmt->fetchAll();

// Hitung total & persentase
$pieTotal = array_sum(array_column($groupedExpense, 'total'));
foreach ($groupedExpense as &$g) {
    $g['pct'] = $pieTotal > 0 ? round($g['total'] / $pieTotal * 100, 1) : 0;
}
unset($g);

// Alias untuk kompatibilitas bagian HTML
$pieExpense = $groupedExpense;
$topExpense = $groupedExpense;

// --- Detail sub-kategori per group_id (untuk breakdown bar) ---
// Ambil semua sub-kategori user yang ada transaksinya bulan ini
$stmt = $db->prepare(
    "SELECT
    COALESCE(parent.id, CASE WHEN c.is_default=1 THEN c.id ELSE 0 END) AS group_id,
        c.id AS category_id,
        c.parent_id,
        c.name  AS sub_name,
        c.icon  AS sub_icon,
        SUM(t.amount) AS sub_total
    FROM transactions t
    LEFT JOIN categories c      ON c.id      = t.category_id
    LEFT JOIN categories parent ON parent.id = c.parent_id
    WHERE t.user_id    = ?
    AND t.type       = 'expense'
    AND t.deleted_at IS NULL
    AND t.created_at BETWEEN ? AND ?
    GROUP BY group_id, c.id, c.parent_id, c.name, c.icon
    HAVING sub_total > 0
    ORDER BY group_id, sub_total DESC"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$subDetails = $stmt->fetchAll();

// Kelompokkan sub per group_id
$subByGroup = [];
$parentDirect = [];

// ===============================
// Behavior Insight
// ===============================

// Top category
$topCategoryName = '';
$topCategoryPct = 0;
$topSubName = '';
$topSubPct = 0;

if (!empty($groupedExpense)) {
    $topCategory = $groupedExpense[0];
    $topCategoryName = $topCategory['group_name'];
    $topCategoryPct = $topCategory['pct'];

    $gid = $topCategory['group_id'];
}

// Rata-rata pengeluaran harian
if ($period === 'this_month') {
    $days = max(1, (int) date('j')); // hari yang sudah berlalu di bulan ini
} else {
    $days = max(1, (strtotime($dateEnd) - strtotime($dateStart)) / 86400 + 1);
}
$avgDailyExpense = $totalExpense / $days;

// Label kontekstual untuk card
$avgDailyLabel = $period === 'this_month'
    ? 'Aktual · ' . $days . ' hari berlalu'
    : 'Realisasi · bulan penuh';

// ===============================
// Forecast Akhir Bulan (REVISI)
// ===============================
$daysInMonth   = (int) date('t', strtotime($dateStart));
$daysPassed    = ($period === 'this_month')
    ? (int) date('j')
    : $daysInMonth; // bulan lalu/2 bulan lalu → sudah full bulan
$daysRemaining = ($period === 'this_month') ? max(0, $daysInMonth - $daysPassed) : 0;

// Prediksi pengeluaran sisa hari
$predictedRemainingExpense = $avgDailyExpense * $daysRemaining;

// Prediksi total pengeluaran 1 bulan
$predictedExpense = $totalExpense + $predictedRemainingExpense;

// Prediksi saldo akhir bulan (berdasarkan saldo sekarang)
$predictedBalance = $saldo - $predictedRemainingExpense;

// ===============================
// Generate Insight
// ===============================
$insights = [];

// Trend Insight
if ($expenseChange > 0) {
    $insights[] = "Pengeluaran Anda <b>naik " . round($expenseChange, 1) . "%</b> dibanding bulan lalu.";
} elseif ($expenseChange < 0) {
    $insights[] = "Pengeluaran Anda <b>turun " . abs(round($expenseChange, 1)) . "%</b> dibanding bulan lalu.";
}

if ($incomeChange > 0) {
    $insights[] = "Pemasukan Anda <b>naik " . round($incomeChange, 1) . "%</b> dibanding bulan lalu.";
} elseif ($incomeChange < 0) {
    $insights[] = "Pemasukan Anda <b>turun " . abs(round($incomeChange, 1)) . "%</b> dibanding bulan lalu.";
}

// Cashflow Insight
if ($saldo > 0) {
    $insights[] = "Bulan ini Anda mengalami <b>Surplus</b> sebesar <b>" . formatRp($saldo) . "</b>.";
} elseif ($saldo < 0) {
    $insights[] = "Bulan ini Anda mengalami <b>Defisit</b> sebesar <b>" . formatRp(abs($saldo)) . "</b>.";
} else {
    $insights[] = "Pemasukan dan pengeluaran bulan ini seimbang.";
}

// Expense Ratio Insight
if ($expenseRatio >= 100) {
    $insights[] = "Pengeluaran Anda <b>" . round($expenseRatio, 1) . "%</b> dari pemasukan. <b style='color:#dc2626'>Berbahaya</b> karena melebihi pemasukan.";
} elseif ($expenseRatio >= 80) {
    $insights[] = "Pengeluaran Anda <b>" . round($expenseRatio, 1) . "%</b> dari pemasukan. Sudah cukup tinggi, perlu dikendalikan.";
} elseif ($expenseRatio >= 50) {
    $insights[] = "Pengeluaran Anda <b>" . round($expenseRatio, 1) . "%</b> dari pemasukan. Masih dalam batas aman.";
} else {
    $insights[] = "Pengeluaran Anda hanya <b>" . round($expenseRatio, 1) . "%</b> dari pemasukan. Sangat baik.";
}

// Saving Rate Insight
if ($savingRate >= 30) {
    $insights[] = "Saving rate Anda <b>" . round($savingRate, 1) . "%</b>. <b>Sangat Baik</b>.";
} elseif ($savingRate >= 20) {
    $insights[] = "Saving rate Anda <b>" . round($savingRate, 1) . "%</b>. Sudah baik.";
} elseif ($savingRate >= 10) {
    $insights[] = "Saving rate Anda <b>" . round($savingRate, 1) . "%</b>. Cukup, tapi masih bisa ditingkatkan.";
} elseif ($savingRate > 0) {
    $insights[] = "Saving rate Anda <b>" . round($savingRate, 1) . "%</b>. Masih rendah.";
} else {
    $insights[] = "Bulan ini belum ada tabungan dari pemasukan Anda.";
}

// Financial Health Score Insight
if ($score >= 90) {
    $insights[] = "Skor kesehatan keuangan Anda <b>$score / 100</b>. Kondisi keuangan <b>Sangat Baik</b>.";
} elseif ($score >= 70) {
    $insights[] = "Skor kesehatan keuangan Anda <b>$score / 100</b>. Kondisi keuangan <b>Baik</b>.";
} elseif ($score >= 40) {
    $insights[] = "Skor kesehatan keuangan Anda <b>$score / 100</b>. Kondisi keuangan <b>Cukup</b>.";
} else {
    $insights[] = "Skor kesehatan keuangan Anda <b>$score / 100</b>. Perlu perhatian serius.";
}

// Top Category Insight
if ($topCategoryName) {
    $insights[] = "Pengeluaran terbesar Anda ada di <b>$topCategoryName</b> sebesar <b>$topCategoryPct%</b> dari total pengeluaran.";

    if ($topSubName) {
        $insights[] = "Pengeluaran terbesar di kategori $topCategoryName adalah <b>$topSubName</b> sebesar <b>$topSubPct%</b> dari kategori tersebut.";
    }
}

// Average Daily Expense
$insights[] = "Rata-rata pengeluaran harian Anda sekitar <b>" . formatRp((int) $avgDailyExpense). "</b> per hari.";

// ===============================
// Forecast Insight
// ===============================
$insights[] = "Jika pola pengeluaran seperti sekarang berlanjut, total pengeluaran bulan ini diperkirakan <b>" . formatRp((int) $predictedExpense) . "</b>.";

if ($predictedBalance > 0) {
    $insights[] = "Saldo Anda di akhir bulan diperkirakan masih <b>" . formatRp((int) $predictedBalance) . "</b>.";
} else {
    $insights[] = "Dengan pola saat ini, Anda berpotensi <b>defisit " . formatRp((int) abs($predictedBalance)) . "</b> di akhir bulan.";
}

// Burn Rate
$burnDays = $avgDailyExpense > 0 ? $saldo / $avgDailyExpense : 0;

if ($burnDays > 0) {
    $insights[] = "Dengan rata-rata pengeluaran saat ini, saldo Anda bisa bertahan sekitar <b>" . floor($burnDays) . " hari</b>.";
}

// ===============================

foreach ($subDetails as $s) {
    // Jika parent_id NULL → berarti transaksi langsung ke parent
    if ($s['parent_id'] == null) {
        $parentDirect[$s['group_id']] = $s;
    } else {
        $subByGroup[$s['group_id']][] = $s;
    }
}

// Tambahkan parent langsung sebagai sub HANYA jika ada sub lain
foreach ($parentDirect as $groupId => $parentRow) {
    if (!empty($subByGroup[$groupId])) {
        $subByGroup[$groupId][] = [
            'sub_name' => $parentRow['sub_name'],
            'sub_icon' => $parentRow['sub_icon'],
            'sub_total' => $parentRow['sub_total'],
        ];
    }
}

// Top Sub (diisi di sini karena $subByGroup baru tersedia)
if (!empty($gid) && !empty($subByGroup[$gid])) {
    $topSub = $subByGroup[$gid][0];
    $topSubName = $topSub['sub_name'];
    $topSubPct = $groupedExpense[0]['total'] > 0
        ? round($topSub['sub_total'] / $groupedExpense[0]['total'] * 100, 1)
        : 0;
}

// --- 10 transaksi terakhir ---
$stmt = $db->prepare(
    "SELECT t.*, c.name AS category_name, c.icon AS category_icon
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.deleted_at IS NULL
       AND t.created_at BETWEEN ? AND ?
     ORDER BY t.created_at DESC LIMIT 10"
);
$stmt->execute([$userId, $dateStart, $dateEnd]);
$recentTrx = $stmt->fetchAll();

// --- Data chart 6 bulan terakhir (1 query) ---
$chart6Start = date('Y-m-01 00:00:00', strtotime('-5 months'));

$stmt = $db->prepare(
    "SELECT
        YEAR(created_at)  AS yr,
        MONTH(created_at) AS mo,
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS inc,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS exp
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL
       AND created_at >= ?
     GROUP BY YEAR(created_at), MONTH(created_at)
     ORDER BY yr ASC, mo ASC"
);
$stmt->execute([$userId, $chart6Start]);
$chartRows = $stmt->fetchAll();

// Index hasil query by "Y-m" agar mudah di-lookup
$chartByMonth = [];
foreach ($chartRows as $row) {
    $key = $row['yr'] . '-' . str_pad($row['mo'], 2, '0', STR_PAD_LEFT);
    $chartByMonth[$key] = $row;
}

// Susun label & data untuk 6 bulan berurutan
$chartLabels  = [];
$chartIncome  = [];
$chartExpense = [];
for ($i = 5; $i >= 0; $i--) {
    // $key   = date('Y-m', strtotime("-$i months"));
    $key   = date('Y-m', strtotime("-$i months", strtotime(date('Y-m-01'))));
    $label = date('M y', strtotime($key . '-01'));
    $row   = $chartByMonth[$key] ?? null;

    $chartLabels[]  = $label;
    $chartIncome[]  = (int) ($row['inc'] ?? 0);
    $chartExpense[] = (int) ($row['exp'] ?? 0);
}

function formatRp(int $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<style>
    .sub-group {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.35s ease;
    }

    .sub-group.open {
        max-height: 500px;
        display: flex;
        flex-direction: column;
    }

    .parent-toggle:hover {
        background: rgba(0, 0, 0, 0.03);
    }

    .progress-bar-parent {
        background: linear-gradient(90deg, #ef4444, #f97316);
    }

    .progress-bar-sub {
        background: linear-gradient(90deg, #fca5a5, #fdba74);
    }

    .pie-legend-item.off {
        opacity: 0.4;
        text-decoration: line-through;
    }
    
    .insight-hidden {
        display: none;
    }
    
    #insightDetail, #insightSimple {
        transition: all 0.25s ease;
    }
</style>

<!-- Filter Periode -->
<div class="d-flex align-items-center gap-2 mb-3" style="font-size:13px">
    <span class="text-muted me-1"><i class="fa-solid fa-calendar me-1"></i>Periode:</span>
    <a href="?period=this_month"
       class="btn btn-sm <?= $period === 'this_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">
        Bulan Ini
    </a>
    <a href="?period=last_month"
       class="btn btn-sm <?= $period === 'last_month' ? 'btn-primary' : 'btn-outline-secondary' ?>">
        Bulan Lalu
    </a>
    <a href="?period=2_month_ago"
       class="btn btn-sm <?= $period === '2_month_ago' ? 'btn-primary' : 'btn-outline-secondary' ?>">
       2 Bulan Lalu
    </a>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon income"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div>
                <div class="stat-label">Pemasukan</div>
                <div class="stat-value"><?= formatRp($totalIncome) ?></div>
                <div class="stat-sub">
                    <?php if ($lastIncome > 0): ?>
                        <?php $sign = $incomeChange >= 0 ? '+' : ''; ?>
                        <span style="color:<?= $incomeChange >= 0 ? 'var(--primary)' : 'var(--danger)' ?>">
                            <i class="fa-solid <?= $incomeChange >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>" style="font-size:10px"></i>
                            <?= $sign . round($incomeChange, 1) ?>%
                        </span>
                    <?php else: ?>
                        Bulan ini
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div>
                <div class="stat-label">Pengeluaran</div>
                <div class="stat-value"><?= formatRp($totalExpense) ?></div>
                <div class="stat-sub">
                    <?php if ($lastExpense > 0): ?>
                        <?php $sign = $expenseChange >= 0 ? '+' : ''; ?>
                        <span style="color:<?= $expenseChange <= 0 ? 'var(--primary)' : 'var(--danger)' ?>">
                            <i class="fa-solid <?= $expenseChange >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>" style="font-size:10px"></i>
                            <?= $sign . round($expenseChange, 1) ?>%
                        </span>
                    <?php else: ?>
                        Bulan ini
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="fa-solid fa-scale-balanced"></i></div>
            <div>
                <div class="stat-label">Saldo</div>
                <div class="stat-value" style="color:<?= $saldo >= 0 ? 'var(--primary)' : 'var(--danger)' ?>">
                    <?= formatRp(abs($saldo)) ?>
                </div>
                <!--<div class="stat-sub"><?= $saldo >= 0 ? 'Surplus' : 'Defisit' ?></div>-->
                <?php if ($carryOver !== 0): ?>
                    <?php $totalSaldo = $saldo + $carryOver; ?>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:3px;line-height:1.4">
                        <?= $carryOver >= 0 ? '＋' : '−' ?> <span style="color:<?= $carryOver >= 0 ? 'var(--primary)' : 'var(--danger)' ?>"><?= formatRp(abs($carryOver)) ?></span>
                        sebelumnya<br>
                        <span style="color:<?= $totalSaldo >= 0 ? 'var(--primary)' : 'var(--danger)' ?>;font-weight:600"><?= formatRp(abs($totalSaldo)) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="stat-label">Transaksi</div>
                <div class="stat-value"><?= $totalTrx ?></div>
                <div class="stat-sub"><?= $period === 'this_month' ? 'Bulan ini' : ($period === 'last_month' ? 'Bulan lalu' : '2 Bulan lalu') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Insight Box -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between parent-toggle-insight" style="cursor:pointer">
        <span>
            <i class="fa-solid fa-chevron-down me-2 toggle-insight-arrow"></i>
            <i class="fa-solid fa-lightbulb me-2 text-warning"></i>
            Insight Keuangan <?= $period === 'this_month' ? 'Bulan Ini' : ($period === 'last_month' ? 'Bulan Lalu' : '2 Bulan Lalu') ?>
        </span>
    
        <span class="text-muted" style="font-size:11px">
            <?= date('F Y', strtotime($dateStart)) ?>
        </span>
    </div>
    <div class="card-body p-3">
        <div id="insightDetail">
            <!-- SEMUA isi insight yang sekarang -->
    
    
            <?php
            // --- Helper badge ---
            function insightBadge(string $color, string $text): string {
                $map = [
                    'green'  => ['#EAF3DE','#27500A'],
                    'red'    => ['#FCEBEB','#791F1F'],
                    'amber'  => ['#FAEEDA','#633806'],
                    'blue'   => ['#E6F1FB','#0C447C'],
                    'purple' => ['#EEEDFE','#3C3489'],
                    'teal'   => ['#E1F5EE','#085041'],
                ];
                [$bg, $fg] = $map[$color] ?? ['#F1EFE8','#2C2C2A'];
                return "<span style='display:inline-flex;align-items:center;gap:4px;font-size:11px;
                    font-weight:500;padding:3px 9px;border-radius:20px;
                    background:{$bg};color:{$fg}'>{$text}</span>";
            }
            function insightBar(float $pct, string $color = '#E24B4A', float $max = 100): string {
                $w = min(100, round($pct / $max * 100));
                return "<div style='height:6px;background:var(--bs-gray-200);border-radius:4px;margin-top:8px;overflow:hidden'>
                          <div style='width:{$w}%;height:100%;background:{$color};border-radius:4px'></div>
                        </div>";
            }
            $ic = function(string $emoji, string $bg) {
                return "<div style='width:28px;height:28px;border-radius:8px;display:flex;align-items:center;
                            justify-content:center;font-size:14px;background:{$bg};flex-shrink:0'>{$emoji}</div>";
            };
            ?>
    
            <style>
            .ik-section { font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.06em;
                color:var(--text-muted);margin:0 0 8px;padding:0 2px }
            .ik-grid2 { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px }
            .ik-grid3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px }
            .ik-card  { background:var(--bs-white);border:0.5px solid rgba(0,0,0,.1);
                border-radius:12px;padding:14px 16px }
            .ik-card.al { border-left:3px solid rgba(0, 0, 0, 0.5); border-bottom:1px solid rgba(0, 0, 0, 0.2); padding-left:14px; padding-bottom: 8px; }
            .ik-lbl   { font-size:11px;color:var(--text-muted);margin-bottom:4px }
            .ik-val   { font-size:20px;font-weight:500;color:var(--text-primary);line-height:1.2 }
            .ik-sub   { font-size:12px;color:var(--text-muted);margin-top:3px }
            .ik-ch    { display:flex;align-items:center;gap:10px;margin-bottom:12px }
            .ik-ch-ico { width:28px;height:28px;border-radius:8px;display:flex;align-items:center;
                justify-content:center;font-size:14px;flex-shrink:0 }
            .ik-ch-ttl { font-size:13px;font-weight:500;color:var(--text-primary) }
            .ik-ch-sub { font-size:11px;color:var(--text-muted);margin-top:1px }
            .ik-row   { display:flex;align-items:center;justify-content:space-between;
                padding:2px 0;border-bottom:0.5px solid rgba(0,0,0,.06) }
            .ik-row:last-child { border-bottom:none }
            .ik-fc    { display:flex;align-items:flex-start;gap:10px;
                padding:2px 0;border-bottom:0.5px solid rgba(0,0,0,.06) }
            .ik-fc:last-child { border-bottom:none }
            .ik-fc-txt { font-size:12px;color:var(--text-muted);line-height:1.6 }
            .ik-fc-txt strong { color:var(--text-primary);font-weight:500 }
            .ik-gauge-row { display:flex;align-items:center;gap:8px }
            .ik-gauge-bar { flex:1;height:7px;background:var(--bs-gray-200);border-radius:4px;overflow:hidden }
            @media (max-width:576px){
                .ik-grid2,.ik-grid3{grid-template-columns:1fr}
            }
            </style>
            
            <!-- === 2. KESEHATAN KEUANGAN === -->
            <p class="ik-section" style="margin-top:4px">Kesehatan Keuangan</p>
            <div class="ik-grid2">
                <!-- Health Score -->
                <?php
                $scoreBadge = $score >= 90 ? insightBadge('teal','Sangat Baik')
                            : ($score >= 70 ? insightBadge('purple','Baik')
                            : ($score >= 40 ? insightBadge('amber','Cukup Baik')
                            : insightBadge('red','Perlu perhatian')));
                $scoreCircumference = 175.93; // 2 * pi * 28
                $scoreDashOffset = $scoreCircumference - ($score / 100 * $scoreCircumference);
                $scoreStroke = $score >= 90 ? '#1D9E75' : ($score >= 70 ? '#534AB7' : ($score >= 40 ? '#BA7517' : '#A32D2D'));
                ?>
                <div class="ik-card al">
                    <div class="ik-ch">
                        <div class="ik-ch-ico" style="background:#EEEDFE">📊</div>
                        <div><div class="ik-ch-ttl">Skor Kesehatan Keuangan</div><div class="ik-ch-sub">Dari 4 indikator</div></div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:center;margin-bottom:10px">
                        <div style="position:relative;width:72px;height:72px;margin-bottom:8px">
                            <svg width="72" height="72" viewBox="0 0 72 72">
                                <circle cx="36" cy="36" r="28" fill="none" stroke="rgba(0,0,0,.08)" stroke-width="7"/>
                                <circle cx="36" cy="36" r="28" fill="none" stroke="<?= $scoreStroke ?>"
                                    stroke-width="7" stroke-dasharray="<?= $scoreCircumference ?>"
                                    stroke-dashoffset="<?= round($scoreDashOffset,1) ?>"
                                    stroke-linecap="round" transform="rotate(-90 36 36)"/>
                            </svg>
                            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:18px;font-weight:500;color:var(--text-primary)"><?= $score ?></div>
                        </div>
                        <?= $scoreBadge ?>
                    </div>
                    <div style="border-top:0.5px solid rgba(0,0,0,.07);padding-top:8px">
                        <?php
                        $ranks = [
                            ['90–100','Sangat baik','#1D9E75'],
                            ['70–89', 'Baik',       '#534AB7'],
                            ['40–69', 'Cukup Baik',      '#BA7517'],
                            ['<40',   'Perlu perhatian','#A32D2D'],
                        ];
                        $curRange = $score >= 90 ? 0 : ($score >= 70 ? 1 : ($score >= 40 ? 2 : 3));
                        foreach ($ranks as $ri => $r): ?>
                            <div class="ik-row">
                                <span style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:6px">
                                    <span style="width:8px;height:8px;border-radius:50%;background:<?= $ri===$curRange ? $r[2] : 'rgba(0,0,0,.15)' ?>;display:inline-block"></span>
                                    Skor <?= $r[0] ?>
                                </span>
                                <span style="font-size:12px;font-weight:<?= $ri===$curRange?'500':'400' ?>;color:<?= $ri===$curRange?$r[2]:'var(--text-muted)' ?>">
                                    <?= $r[1] ?><?= $ri===$curRange?' ✓':'' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Saving Rate -->
                <?php
                $srColor  = $savingRate >= 30 ? '#1D9E75' : ($savingRate >= 20 ? '#3B6D11' : ($savingRate >= 10 ? '#BA7517' : '#A32D2D'));
                $srLabel  = $savingRate >= 30 ? 'Ideal ✓' : ($savingRate >= 20 ? 'Sudah baik ✓' : ($savingRate >= 10 ? 'Cukup, tingkatkan' : 'Masih rendah'));
                $srBadge  = $savingRate >= 20 ? insightBadge('teal',$srLabel) : ($savingRate >= 10 ? insightBadge('amber',$srLabel) : insightBadge('red',$srLabel));
                ?>
                <div class="ik-card al">
                    <div class="ik-ch">
                        <div class="ik-ch-ico" style="background:#E1F5EE">💰</div>
                        <div><div class="ik-ch-ttl">Saving Rate</div><div class="ik-ch-sub">Tabungan / investasi</div></div>
                    </div>
                    <div class="ik-val" style="font-size:26px;color:<?= $srColor ?>"><?= round($savingRate,1) ?>%</div>
                    <?= insightBar($savingRate,'#1D9E75',40) ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;font-size:11px;color:var(--text-muted)">
                        <span>Minimal ideal: 20%</span>
                        <?= $srBadge ?>
                    </div>
                    <div style="margin-top:10px;border-top:0.5px solid rgba(0,0,0,.07);padding-top:8px">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Posisi kamu:</div>
                        <div class="ik-gauge-row">
                            <span style="font-size:11px;color:var(--text-muted)">0%</span>
                            <div class="ik-gauge-bar">
                                <div style="width:<?= min(100,round($savingRate/30*100)) ?>%;height:100%;background:linear-gradient(90deg,#639922,#1D9E75)"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-muted)">30%+</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:3px">
                            <span>Rendah</span><span>Cukup</span><span>Baik</span><span>Ideal</span>
                        </div>
                    </div>
                </div>
            </div>
    
            <!-- === 1. RINGKASAN === -->
            <p class="ik-section">Ringkasan Bulan Ini</p>
            <div class="ik-grid2">
                <!-- Cashflow -->
                <?php
                $cashColor   = $saldo >= 0 ? '#0F6E56' : '#A32D2D';
                $cashBadge   = $saldo >= 0 ? insightBadge('teal','Surplus bulan ini') : insightBadge('red','Defisit bulan ini');
                $cashBgBorder= $saldo >= 0 ? '#0F6E56' : '#A32D2D';
                ?>
                <!--<div class="ik-card al" style="border-color:<?= $cashBgBorder ?>">-->
                <!--    <div class="ik-lbl">Cashflow</div>-->
                <!--    <div class="ik-val" style="color:<?= $cashColor ?>"><?= formatRp(abs($saldo)) ?></div>-->
                <!--    <div class="ik-sub">Selisih pemasukan &amp; pengeluaran</div>-->
                <!--    <?= $cashBadge ?>-->
                <!--</div>-->
                <!-- Expense Ratio -->
                <?php
                // Logika Warna (Hex)
                $erColor = $expenseRatio < 50  ? '#1D9E75'   // Teal (Sangat Hemat)
                         : ($expenseRatio < 70 ? '#1D9E75'   // Teal (Ideal/Aman)
                         : ($expenseRatio < 90 ? '#BA7517'   // Amber (Waspada)
                         :                       '#A32D2D')); // Red (Bahaya)
                
                // Logika Badge & Teks
                $erBadge = $expenseRatio >= 100 ? insightBadge('red',   'Bahaya — Defisit (Lebih besar pasak daripada tiang)')
                         : ($expenseRatio >= 90  ? insightBadge('red',   'Tinggi — Sangat sedikit ruang tabungan')
                         : ($expenseRatio >= 70  ? insightBadge('amber', 'Waspada — Perlu evaluasi pengeluaran')
                         : ($expenseRatio >= 50  ? insightBadge('teal',  'Ideal — Pengelolaan keuangan sehat')
                         :                         insightBadge('teal',  'Sangat Hemat — Ruang investasi sangat besar'))));
                ?>
    
                <div class="ik-card al">
                    <div class="ik-lbl">Expense ratio (Rasio Pengeluaran)</div>
                    <div class="ik-val"><?= round($expenseRatio,1) ?>%</div>
                    <?= insightBar($expenseRatio, $erColor) ?>
                    <div style="margin-top:8px"><?= $erBadge ?></div>
                </div>
    
                <?php
                $iSign = $incomeChange >= 0 ? '+' : '';
                $iBadge = $incomeChange > 0 ? insightBadge('green','Tren positif')
                        : ($incomeChange >= -10 ? insightBadge('amber','Sedikit turun')
                        : insightBadge('red','Perlu diperhatikan'));
                $eSign = $expenseChange >= 0 ? '+' : '';
                $eBadge = $expenseChange < 0 ? insightBadge('green','Pengeluaran turun')
                        : ($expenseChange < 10 ? insightBadge('amber','Stabil')
                        : insightBadge('amber','Perhatikan konsistensi'));
                ?>
                <div class="ik-card al">
                    <div class="ik-sub" style="text-align: center;"><?= $lastIncome > 0 ? 'Dibanding bulan sebelumnya' : 'Belum ada data bulan lalu' ?></div>
                    <div class="" style="display: flex; justify-content: space-around;">
                        <div style="text-align: center;">
                            <div class="ik-lbl">Pemasukan</div>
                            <div class="ik-val" style="font-size:17px"><?= $iSign.round($incomeChange,1) ?>%</div>
                            <div style="margin-top:6px"><?= $iBadge ?></div>
                        </div>
                        
                        <div style="text-align: center;">
                            <div class="ik-lbl">Pengeluaran</div>
                            <div class="ik-val" style="font-size:17px"><?= $eSign.round($expenseChange,1) ?>%</div>
                            <!--<div class="ik-sub"><?= $lastExpense > 0 ? 'Dibanding bulan sebelumnya' : 'Belum ada data bulan lalu' ?></div>-->
                            <div style="margin-top:6px"><?= $eBadge ?></div>
                        </div>
                    </div>
                </div>
            </div>
    
            <!-- === 3. POLA PENGELUARAN === -->
            <p class="ik-section" style="margin-top:4px">Pola Pengeluaran</p>
            <div class="ik-grid2">
                <!-- Top Kategori -->
                <div class="ik-card al">
                    <div class="ik-ch">
                        <div class="ik-ch-ico" style="background:#FAEEDA">🏆</div>
                        <div><div class="ik-ch-ttl">Kategori terbesar</div><div class="ik-ch-sub"><?= round($topCategoryPct,1) ?>% dari total pengeluaran</div></div>
                    </div>
                    <div class="ik-val" style="font-size:18px;color:#633806"><?= htmlspecialchars($topCategoryName ?: '-') ?></div>
                    <?= insightBar($topCategoryPct,'#BA7517') ?>
                    <?php if ($topCategoryName): ?>
                        <?php
                        $isSaving = stripos($topCategoryName,'tabung')!==false || stripos($topCategoryName,'investasi')!==false || stripos($topCategoryName,'dana darurat')!==false;
                        echo '<div style="font-size:11px;color:var(--text-muted);margin-top:6px">';
                        echo $isSaving ? 'Positif — pengeluaran terbesar adalah tabungan/investasi' : 'Pertimbangkan apakah bisa dioptimalkan';
                        echo '</div>';
                        ?>
                    <?php endif; ?>
                </div>
    
                <!-- Rata-rata harian -->
                <div class="ik-card al">
                    <div class="ik-ch">
                        <div class="ik-ch-ico" style="background:#E6F1FB">📅</div>
                        <div><div class="ik-ch-ttl">Rata-rata harian</div><div class="ik-ch-sub"><?= $avgDailyLabel ?></div></div>
                    </div>
                    <div class="ik-val" style="font-size:17px"><?= formatRp((int)$avgDailyExpense) ?></div>
                    <div style="margin-top:10px;border-top:0.5px solid rgba(0,0,0,.07);padding-top:8px">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px">Setara dengan:</div>
                        <div class="ik-row"><span class="ik-lbl">Mingguan</span><span style="font-size:12px;font-weight:500"><?= formatRp((int)($avgDailyExpense*7)) ?></span></div>
                        <?php if ($period === 'this_month'): ?>
                        <div class="ik-row"><span class="ik-lbl">Proyeksi akhir bulan</span><span style="font-size:12px;font-weight:500"><?= formatRp((int)$predictedExpense) ?></span></div>
                        <?php else: ?>
                        <div class="ik-row"><span class="ik-lbl">Total bulan tersebut</span><span style="font-size:12px;font-weight:500"><?= formatRp((int)$totalExpense) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
    
            <?php if($period === 'this_month'): ?>
                <!-- === 4. PROYEKSI === -->
                <p class="ik-section" style="margin-top:4px">Proyeksi Akhir Bulan</p>
                <div class="ik-card al">
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:#FAEEDA">📈</div>
                        <div class="ik-fc-txt">
                            <strong>Total pengeluaran diprediksi</strong> — <strong style="color:#101010"><?= formatRp((int)$predictedExpense) ?></strong> jika pola saat ini berlanjut hingga akhir bulan.
                        </div>
                    </div>
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:<?= $predictedBalance >= 0 ? '#E1F5EE' : '#FCEBEB' ?>">💵</div>
                        <div class="ik-fc-txt">
                            <strong>Saldo akhir bulan</strong> —
                            <?php if ($predictedBalance >= 0): ?>
                                diperkirakan masih positif <strong style="color:#0F6E56"><?= formatRp((int)$predictedBalance) ?></strong>. Keuangan masih aman.
                            <?php else: ?>
                                berpotensi <strong style="color:#A32D2D">defisit <?= formatRp((int)abs($predictedBalance)) ?></strong>. Segera kendalikan pengeluaran.
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($burnDays > 0): ?>
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:#FCEBEB">⏳</div>
                        <div class="ik-fc-txt">
                            <strong>Burn rate</strong> — dengan rata-rata harian saat ini, saldo cukup untuk <strong style="color:#A32D2D"><?= floor($burnDays) ?> hari</strong> ke depan.
                            <?= $burnDays < 14 ? ' Pastikan ada pemasukan masuk segera.' : '' ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Realisasi bulan yang sudah selesai -->
                <p class="ik-section" style="margin-top:4px">Realisasi Bulan Tersebut</p>
                <div class="ik-card al">
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:#E1F5EE">✅</div>
                        <div class="ik-fc-txt">
                            <strong>Total pengeluaran aktual</strong> — <strong style="color:#101010"><?= formatRp((int)$totalExpense) ?></strong> selama bulan tersebut.
                        </div>
                    </div>
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:<?= $saldo >= 0 ? '#E1F5EE' : '#FCEBEB' ?>">💵</div>
                        <div class="ik-fc-txt">
                            <strong>Saldo bulan tersebut</strong> —
                            <?php if ($saldo >= 0): ?>
                                surplus <strong style="color:#0F6E56"><?= formatRp((int)$saldo) ?></strong>. Bulan yang sehat.
                            <?php else: ?>
                                defisit <strong style="color:#A32D2D"><?= formatRp((int)abs($saldo)) ?></strong> pada bulan tersebut.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ik-fc">
                        <div class="ik-ch-ico" style="background:#E6F1FB">📊</div>
                        <div class="ik-fc-txt">
                            <strong>Rata-rata harian</strong> — pengeluaran per hari sebesar <strong style="color:#101010"><?= formatRp((int)$avgDailyExpense) ?></strong> sepanjang bulan tersebut.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div id="insightSimple" style="display:none">
            <ul style="font-size:13px; padding-left:18px; margin-bottom:0">
                <?php foreach ($insights as $text): ?>
                    <li style="margin-bottom:6px"><?= $text ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<style>
    .pie-legend-item.off {
        opacity: 0.4;
        text-decoration: line-through;
    }
</style>

<!-- Chart & Top Expense -->
<div class="row g-3 mb-2">
    <!-- Bar Chart -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-chart-bar me-2 text-muted"></i>Pemasukan vs Pengeluaran</span>
                <span class="text-muted" style="font-size:12px">6 Bulan Terakhir</span>
            </div>
            <div class="card-body">
                <canvas id="barChart" height="150"></canvas>
            </div>
        </div>
    </div>

    <!-- Pie Chart + Top Expense Kategori -->
    <div class="col-lg-4">
        <!-- Pie Chart Kategori -->
        <div class="card h-100">
            <div class="card-header">
                <i class="fa-solid fa-chart-pie me-2 text-muted"></i>Komposisi Pengeluaran
                <span class="text-muted ms-1" style="font-size:12px"><?= $period === 'this_month' ? 'Bulan Ini' : ($period === 'last_month' ? 'Bulan Lalu' : '2 Bulan Lalu') ?></span>
            </div>
            <div class="card-body" style="padding:14px 16px 12px">
                <?php if (empty($pieExpense)): ?>
                    <div class="pie-legend-item" data-index="<?= $i ?>" style="display:flex;align-items:center;justify-content:space-between;
                                gap:6px;font-size:11.5px;line-height:1.3;cursor:pointer">
                        Belum ada data bulan ini
                    </div>
                <?php else: ?>
                    <!-- Canvas dibungkus div dengan ukuran terbatas -->
                    <div style="position:relative;width:100%;max-width:200px;margin:0 auto 12px">
                        <canvas id="pieChart"></canvas>
                    </div>
                    <!-- Legend dengan % dan nominal -->
                    <div style="display:flex;flex-direction:column;gap:5px">
                        <?php
                        $pieColors = [
                            '#dc2626',
                            '#f97316',
                            '#eab308',
                            '#16a34a',
                            '#0891b2',
                            '#7c3aed',
                            '#db2777',
                            '#059669',
                            '#ea580c',
                            '#2563eb',
                            '#65a30d',
                            '#0e7490',
                            '#9333ea',
                            '#e11d48',
                            '#d97706',
                        ];
                        foreach ($pieExpense as $i => $cat):
                            $color = $pieColors[$i % count($pieColors)];
                            ?>
                            <div class="pie-legend-item" data-index="<?= $i ?>" style="display:flex;align-items:center;justify-content:space-between;
                                        gap:6px;font-size:11.5px;line-height:1.3;cursor:pointer">
                                <div style="display:flex;align-items:center;gap:6px;min-width:0">
                                    <span style="width:9px;height:9px;background:<?= $color ?>;
                                  border-radius:2px;flex-shrink:0;display:inline-block"></span>
                                    <span style="color:var(--text-primary);overflow:hidden;
                                  text-overflow:ellipsis;white-space:nowrap"
                                        title="<?= htmlspecialchars($cat['group_name'] ?? 'Lainnya') ?>">
                                        <?= htmlspecialchars($cat['group_name'] ?? 'Lainnya') ?>
                                    </span>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                                    <span class="legend-pct" data-index="<?= $i ?>"
                                        style="font-weight:700;color:var(--danger);font-size:11px">
                                        <?= $cat['pct'] ?>%
                                    </span>
                                    <span style="color:var(--text-muted);font-family:var(--font-mono,monospace);
                                  font-size:10.5px">
                                        <?= 'Rp ' . number_format((int) $cat['total'], 0, ',', '.') ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-2">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-fire me-2 text-muted"></i>Pengeluaran per Kategori</span>
                <div style="display:flex;align-items:center;gap:10px">
                    <!--<a href="#" id="expandAll" style="font-size:11px">Expand All</a>-->
                    <!--<a href="#" id="collapseAll" style="font-size:11px">Collapse All</a>-->
                    <span class="text-muted" style="font-size:11px"><?= count($topExpense) ?> kategori
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topExpense)): ?>
                    <div class="text-center text-muted py-4" style="font-size:13px">
                        Belum ada data bulan ini
                    </div>
                <?php else: ?>
                    <?php
                    $totalAll = array_sum(array_column($topExpense, 'total'));
                    foreach ($topExpense as $i => $item):
                        $barPct = $totalAll > 0 ? round(($item['total'] / $totalAll) * 100) : 0;
                        $subs = $subByGroup[$item['group_id']] ?? [];
                        $hasSubs = !empty($subs);
                        ?>
                        <div class="<?= $i < count($topExpense) - 1 ? 'border-bottom' : '' ?>">

                            <!-- Baris Parent -->
                            <div class="px-4 pt-3 <?= $hasSubs ? 'pb-2 parent-toggle' : 'pb-3' ?>" <?= $hasSubs ? 'data-group="' . $item['group_id'] . '"' : '' ?> style="<?= $hasSubs ? 'cursor:pointer' : '' ?>">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span style="font-size:12.5px;font-weight:600;display:flex;align-items:center">
                                        <?php if ($hasSubs) { ?>
                                            <i class="fa-solid fa-chevron-right me-2 toggle-arrow text-muted"
                                                data-group="<?= $item['group_id']; ?>" style="font-size:10px"></i>
                                        <?php } else { ?>
                                            <span style="width:12px;display:inline-block"></span>
                                        <?php } ?>

                                        <i class="fa-solid <?= htmlspecialchars($item['group_icon'] ?? 'fa-tag') ?> me-2 text-muted"
                                            style="font-size:11px"></i>
                                        <?= htmlspecialchars($item['group_name'] ?? 'Lainnya') ?>
                                        <?php if ($hasSubs) { ?>
                                            <span style="font-size:10px;color:var(--text-muted);margin-left:6px">
                                                (
                                                <?= count($subs); ?>)
                                            </span>
                                        <?php } ?>
                                    </span>


                                    <div style="display:flex;align-items:center;gap:8px">
                                        <span style="font-size:11px;font-weight:700;color:var(--danger)">
                                            <?= $item['pct'] ?>%
                                        </span>
                                        <span style="font-size:11.5px;font-weight:700;font-family:var(--font-mono)">
                                            <?= formatRp((int) $item['total']) ?>
                                        </span>
                                    </div>
                                </div>

                                <?php
                                $barColors = [
                                    'linear-gradient(90deg,#dc2626,#ef4444)',
                                    'linear-gradient(90deg,#f97316,#fb923c)',
                                    'linear-gradient(90deg,#eab308,#facc15)',
                                    'linear-gradient(90deg,#0891b2,#22d3ee)',
                                    'linear-gradient(90deg,#16a34a,#4ade80)'
                                ];

                                $color = $barColors[$i % count($barColors)];
                                ?>
                                <!-- Bar parent — penuh -->
                                <div class="progress" style="height:4px;border-radius:4px">
                                    <!-- <div class="progress-bar bg-danger" style="width:<?= $barPct ?>%"></div> -->

                                    <div class="progress-bar" style="width:<?= $barPct ?>%; background:<?= $color ?>"></div>
                                </div>
                            </div>

                            <?php if ($hasSubs): ?>
                                <!-- Sub-kategori breakdown -->
                                <div class="sub-group" data-group="<?= $item['group_id'] ?>"
                                    style="padding:4px 16px 10px 36px;display:none;flex-direction:column;gap:5px">
                                    <?php foreach ($subs as $sub):
                                        // Persentase sub relatif terhadap total parent
                                        $subPct = $item['total'] > 0 ? round($sub['sub_total'] / $item['total'] * 100, 1) : 0;
                                        $subBarPct = $item['total'] > 0 ? round($sub['sub_total'] / $item['total'] * 100) : 0;
                                        ?>
                                        <div>
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <span
                                                    style="font-size:11px;color:var(--text-secondary);display:flex;align-items:center;gap:5px">
                                                    <i class="fa-solid fa-turn-down-right" style="font-size:9px;opacity:.4"></i>
                                                    <i class="fa-solid <?= htmlspecialchars($sub['sub_icon'] ?? 'fa-tag') ?>"
                                                        style="font-size:10px;opacity:.6"></i>
                                                    <?= htmlspecialchars(
                                                        $sub['sub_name'] == $item['group_name']
                                                        ? $sub['sub_name'] . ' (langsung)'
                                                        : $sub['sub_name']
                                                    ) ?>
                                                </span>
                                                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                                                    <span
                                                        style="font-size:10px;color:var(--danger);font-weight:600"><?= $subPct ?>%</span>
                                                    <span style="font-size:10.5px;color:var(--text-muted);font-family:var(--font-mono)">
                                                        <?= formatRp((int) $sub['sub_total']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <!-- Bar sub — proporsional terhadap parent -->
                                            <div style="background:#f1f5f9;border-radius:3px;height:3px">
                                                <div
                                                    style="width:<?= $subBarPct ?>%;height:3px;border-radius:3px;background:rgba(220,38,38,.35)">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Transaksi Terakhir -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Transaksi Terakhir <span class="text-muted" style="font-size:12px;font-weight:400"><?= $period === 'this_month' ? 'Bulan Ini' : ($period === 'last_month' ? 'Bulan Lalu' : '2 Bulan Lalu') ?></span></span>
        <a href="/dashboard/transactions.php" class="btn btn-sm btn-outline-secondary" style="font-size:12px">
            Lihat Semua <i class="fa-solid fa-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentTrx)): ?>
            <div class="text-center text-muted py-5">
                <i class="fa-solid fa-inbox fa-2x mb-3 d-block"></i>
                Belum ada transaksi. Coba kirim pesan WA seperti:<br>
                <code style="font-size:12px">Bensin 50rb</code>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead
                        style="background:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted)">
                        <tr>
                            <th class="px-4 py-3">Kode</th>
                            <th class="py-3">Deskripsi</th>
                            <th class="py-3">Kategori</th>
                            <th class="py-3">Tipe</th>
                            <th class="py-3 text-end pe-4">Nominal</th>
                            <th class="py-3 text-end pe-4">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTrx as $trx): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="txn-code"><?= htmlspecialchars($trx['unique_code']) ?></span>
                                </td>
                                <td class="py-3"><?= htmlspecialchars($trx['description'] ?? '-') ?></td>
                                <td class="py-3">
                                    <span style="font-size:12px; color:var(--text-secondary)">
                                        <i class="fa-solid <?= htmlspecialchars($trx['category_icon'] ?? 'fa-tag') ?> me-1"></i>
                                        <?= htmlspecialchars($trx['category_name'] ?? 'Lainnya') ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <?php if ($trx['type'] === 'income'): ?>
                                        <span class="badge badge-income rounded-pill px-2">Masuk</span>
                                    <?php else: ?>
                                        <span class="badge badge-expense rounded-pill px-2">Keluar</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-end pe-4" style="font-family:var(--font-mono); font-weight:600;
                        color:<?= $trx['type'] === 'income' ? 'var(--primary)' : 'var(--danger)' ?>">
                                    <?= $trx['type'] === 'income' ? '+' : '-' ?>         <?= formatRp((int) $trx['amount']) ?>
                                </td>
                                <td class="py-3 text-end pe-4" style="color:var(--text-muted); font-size:12px">
                                    <?= date('d M, H:i', strtotime($trx['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
ob_start();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script
    src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
<script>
    // Harus di luar window.onload agar terdaftar sebelum chart dibuat
    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }

    var pieChartInstance = null;
    window.addEventListener('load', function () {

        // ============================================================
        // Bar Chart — Pemasukan vs Pengeluaran 6 Bulan
        // ============================================================
        var barEl = document.getElementById('barChart');
        if (barEl) {
            new Chart(barEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chartLabels); ?>,
                    datasets: [
                        {
                            label: 'Pemasukan',
                            data: <?php echo json_encode($chartIncome); ?>,
                            backgroundColor: 'rgba(22,163,74,.85)',
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'Pengeluaran',
                            data: <?php echo json_encode($chartExpense); ?>,
                            backgroundColor: 'rgba(220,38,38,.75)',
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 12 } },
                        tooltip: {
                            callbacks: {
                                label: function (c) { return ' Rp ' + c.raw.toLocaleString('id-ID'); }
                            }
                        },
                        datalabels: { display: false }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: function (v) {
                                    if (v >= 1000000) {
                                        var jt = v / 1000000;
                                        return 'Rp ' + (Number.isInteger(jt) ? jt : parseFloat(jt.toFixed(1))) + ' jt';
                                    } else if (v >= 1000) {
                                        return 'Rp ' + Math.round(v / 1000) + ' rb';
                                    } else {
                                        return 'Rp ' + v;
                                    }
                                },
                                font: { size: 11 }
                            },
                            grid: { color: '#f1f5f9' }
                        },
                        x: { grid: { display: false }, ticks: { font: { size: 12 } } }
                    }
                }
            });
        }

        // ============================================================
        // Pie Chart — Komposisi Pengeluaran Bulan Ini
        // ============================================================
        <?php if (!empty($pieExpense)): ?>
            var pieEl = document.getElementById('pieChart');
            if (pieEl) {
                var pieData = <?= json_encode(array_map(fn($c) => (int) $c['total'], $pieExpense)) ?>;
                var pieLabels = <?= json_encode(array_column($pieExpense, 'group_name')) ?>;
                var pieColors = <?= json_encode(array_slice([
                    '#dc2626',
                    '#f97316',
                    '#eab308',
                    '#16a34a',
                    '#0891b2',
                    '#7c3aed',
                    '#db2777',
                    '#059669',
                    '#ea580c',
                    '#2563eb',
                    '#65a30d',
                    '#0e7490',
                    '#9333ea',
                    '#e11d48',
                    '#d97706',
                ], 0, count($pieExpense))) ?>;

                pieChartInstance = new Chart(pieEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: pieLabels,
                        datasets: [{
                            data: pieData,
                            backgroundColor: pieColors,
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        cutout: '55%',
                        plugins: {
                            legend: { display: false },
                            datalabels: {
                                font: {
                                    weight: 'bold',
                                    size: 11
                                },
                                color: '#fff',
                                display: function (ctx) {
                                    var chart = ctx.chart;
                                    var data = chart.data.datasets[0].data;

                                    var total = 0;
                                    data.forEach(function (v, i) {
                                        if (chart.getDataVisibility(i)) {
                                            total += v;
                                        }
                                    });

                                    var value = data[ctx.dataIndex];
                                    var pct = total ? value / total * 100 : 0;

                                    return pct >= 5;
                                },
                                formatter: function (value, ctx) {
                                    var chart = ctx.chart;
                                    var data = chart.data.datasets[0].data;

                                    var total = 0;
                                    data.forEach(function (v, i) {
                                        if (chart.getDataVisibility(i)) {
                                            total += v;
                                        }
                                    });

                                    var pct = total ? (value / total * 100).toFixed(1) : 0;
                                    return pct + '%';
                                }
                            }
                        }
                    }
                });

                updatePieLegend();
            }
        <?php endif; ?>

        // Toggle pie legend
        document.querySelectorAll('.pie-legend-item').forEach(function (el) {
            el.addEventListener('click', function () {
                if (!pieChartInstance) return;

                var index = parseInt(this.getAttribute('data-index'));

                pieChartInstance.toggleDataVisibility(index);
                pieChartInstance.update();

                this.classList.toggle('off');

                updatePieLegend(); // update persen legend
            });
        });

        function updatePieLegend() {
            if (!pieChartInstance) return;

            var data = pieChartInstance.data.datasets[0].data;
            var total = 0;

            data.forEach(function (v, i) {
                if (pieChartInstance.getDataVisibility(i)) {
                    total += v;
                }
            });

            document.querySelectorAll('.legend-pct').forEach(function (el) {
                var index = el.getAttribute('data-index');
                var value = data[index];

                var pct = total ? (value / total * 100).toFixed(1) : 0;
                el.innerText = pct + '%';
            });
        }

    }); // end window.onload
</script>

<script>
    // Toggle sub kategori
    document.querySelectorAll('.parent-toggle').forEach(function (parent) {
        parent.addEventListener('click', function () {
            var groupId = this.getAttribute('data-group');
            document.querySelectorAll('.sub-group[data-group="' + groupId + '"]')
                .forEach(function (el) {
                    if (el.style.display === 'none') {
                        el.style.display = 'flex';
                    } else {
                        el.style.display = 'none';
                    }
                });
        });
    });
</script>
<script>
    // Toggle parent
    document.querySelectorAll('.parent-toggle').forEach(function (parent) {
        parent.addEventListener('click', function () {
            var groupId = this.getAttribute('data-group');
            var subs = document.querySelector('.sub-group[data-group="' + groupId + '"]');
            var arrow = document.querySelector('.toggle-arrow[data-group="' + groupId + '"]');

            if (!subs) return;

            if (subs.classList.contains('open')) {
                subs.classList.remove('open');
                arrow.classList.remove('fa-chevron-down');
                arrow.classList.add('fa-chevron-right');
            } else {
                subs.classList.add('open');
                arrow.classList.remove('fa-chevron-right');
                arrow.classList.add('fa-chevron-down');
            }
        });
    });

    var btnExpand = document.getElementById('expandAll');
    if (btnExpand) {
        btnExpand.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.sub-group').forEach(el => el.classList.add('open'));
            document.querySelectorAll('.toggle-arrow').forEach(el => {
                el.classList.remove('fa-chevron-right');
                el.classList.add('fa-chevron-down');
            });
        });
    }
    
    var btnCollapse = document.getElementById('collapseAll');
    if (btnCollapse) {
        btnCollapse.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.sub-group').forEach(el => el.classList.remove('open'));
            document.querySelectorAll('.toggle-arrow').forEach(el => {
                el.classList.remove('fa-chevron-down');
                el.classList.add('fa-chevron-right');
            });
        });
    }
    
    var toggleBtn = document.getElementById('toggleInsightMode');
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e){
            e.preventDefault();
    
            var detail = document.getElementById('insightDetail');
            var simple = document.getElementById('insightSimple');
    
            if (detail.style.display === 'none') {
                detail.style.display = 'block';
                simple.style.display = 'none';
                this.innerText = 'Sederhanakan';
            } else {
                detail.style.display = 'none';
                simple.style.display = 'block';
                this.innerText = 'Lihat Detail';
            }
        });
    }
    
    document.querySelector('.parent-toggle-insight').addEventListener('click', function () {
        var detail = document.getElementById('insightDetail');
        var simple = document.getElementById('insightSimple');
        var arrow = document.querySelector('.toggle-insight-arrow');
    
        if (detail.style.display === 'none') {
            detail.style.display = 'block';
            simple.style.display = 'none';
    
            arrow.classList.remove('fa-chevron-right');
            arrow.classList.add('fa-chevron-down');
        } else {
            detail.style.display = 'none';
            simple.style.display = 'block';
    
            arrow.classList.remove('fa-chevron-down');
            arrow.classList.add('fa-chevron-right');
        }
    });
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>