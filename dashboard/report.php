<?php
// ============================================================
// KitaCatat — Dashboard: Laporan & Rekap
// ============================================================
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Laporan';
require_once __DIR__ . '/layout/header.php';

$db = getDB();
$userId = (int) $_SESSION['user_id'];

// Ambil grup yang diikuti user
$stmtGroups = $db->prepare(
    "SELECT g.id, g.name, g.is_shared, gm.role
     FROM groups g
     JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
     ORDER BY g.name"
);
$stmtGroups->execute([$userId]);
$userGroups = $stmtGroups->fetchAll();

function formatRp(int $n): string
{
    return 'Rp ' . number_format($n, 0, ',', '.');
}
?>

<style>
    .trend-chart-box {
        position: relative;
        width: 100%;
        height: 320px;
    }

    @media (max-width: 768px) {
        .trend-chart-box {
            height: 480px;
        }
    }

    .trend-chart-box canvas {
        position: absolute !important;
        width: 100% !important;
        height: 100% !important;
    }

    /* Legend manual */
    .chart-legend-manual {
        display: flex;
        gap: 18px;
        font-size: 12px;
        color: #64748b;
        margin-top: 10px;
        flex-wrap: wrap;
    }

    .chart-legend-manual .dot {
        display: inline-block;
        width: 10px;
        height: 10px;
        border-radius: 3px;
        margin-right: 6px;
    }

    .dot.income {
        background: #16a34a;
    }

    .dot.expense {
        background: #dc2626;
    }

    .dot.saldo {
        background: #7c3aed;
    }

    .card-body {
        padding: 12px !important;
    }

    .chart-legend-manual span {
        cursor: pointer;
        user-select: none;
        opacity: 1;
        transition: 0.2s;
    }

    .chart-legend-manual span.off {
        opacity: 0.3;
    }
</style>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Laporan Keuangan</h5>
        <p class="text-muted mb-0" style="font-size:13px">Rekap detail berdasarkan periode dan lingkup</p>
    </div>
</div>

<!-- Filter Panel -->
<div class="card mb-4">
    <div class="card-header"><i class="fa-solid fa-sliders me-2 text-muted"></i>Filter Laporan</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label"
                    style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Dari</label>
                <input type="date" id="rDateStart" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label"
                    style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Sampai</label>
                <input type="date" id="rDateEnd" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label"
                    style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Lingkup</label>
                <select id="rScope" class="form-select form-select-sm">
                    <option value="personal">Pribadi</option>
                    <?php foreach ($userGroups as $g): ?>
                        <option value="group_<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label"
                    style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Preset</label>
                <select id="rPreset" class="form-select form-select-sm" onchange="applyPreset(this.value)">
                    <option value="">— Pilih Preset —</option>
                    <option value="this_month">Bulan Ini</option>
                    <option value="last_month">Bulan Lalu</option>
                    <option value="last_3">3 Bulan Terakhir</option>
                    <option value="last_6">6 Bulan Terakhir</option>
                    <option value="this_year">Tahun Ini</option>
                </select>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-primary flex-fill" onclick="loadReport()">
                    <i class="fa-solid fa-chart-bar me-1"></i>Tampilkan
                </button>
                <!-- Export Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                        title="Export" style="padding-left:10px;padding-right:10px">
                        <i class="fa-solid fa-download"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="font-size:13px;min-width:160px">
                        <li>
                            <button class="dropdown-item" onclick="exportCSV()">
                                <i class="fa-solid fa-file-csv me-2 text-muted"></i>Export CSV
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item" onclick="exportExcel()">
                                <i class="fa-solid fa-file-excel me-2 text-muted"
                                    style="color:#16a34a!important"></i>Export Excel
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item" onclick="exportPDF()">
                                <i class="fa-solid fa-file-pdf me-2" style="color:#dc2626"></i>Export PDF
                            </button>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <button class="dropdown-item" onclick="printReport()">
                                <i class="fa-solid fa-print me-2 text-muted"></i>Print
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stat Summary -->
<div class="row g-3 mb-4" id="reportStats" style="display:none">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon income"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div>
                <div class="stat-label">Total Pemasukan</div>
                <div class="stat-value" id="statIncome">—</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon expense"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div>
                <div class="stat-label">Total Pengeluaran</div>
                <div class="stat-value" id="statExpense">—</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon balance"><i class="fa-solid fa-scale-balanced"></i></div>
            <div>
                <div class="stat-label">Saldo</div>
                <div class="stat-value" id="statBalance">—</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fa-solid fa-receipt"></i></div>
            <div>
                <div class="stat-label">Transaksi</div>
                <div class="stat-value" id="statCount">—</div>
            </div>
        </div>
    </div>
</div>

<!-- Chart & Breakdown -->
<div class="row g-3 mb-4" id="reportCharts" style="display:none">
    <!-- Trend Harian -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-chart-line me-2 text-muted"></i>Tren Harian</span>
                <!-- Toggle saldo -->
                <label
                    style="font-size:12px;color:var(--text-secondary);cursor:pointer;display:flex;align-items:center;gap:6px;margin:0">
                    <input type="checkbox" id="toggleSaldo" checked onchange="toggleSaldoLine(this.checked)"
                        style="accent-color:#7c3aed;cursor:pointer">
                    <span style="font-size:11px;font-weight:600;color:#7c3aed">
                        <i class="fa-solid fa-circle me-1" style="font-size:8px"></i>Saldo Kumulatif
                    </span>
                </label>
            </div>
            <div class="card-body">
                <div class="trend-chart-box">
                    <canvas id="trendChart"></canvas>
                </div>

                <div class="chart-legend-manual">
                    <span onclick="toggleDataset(0)">
                        <i class="dot income"></i> Pemasukan
                    </span>
                    <span onclick="toggleDataset(1)">
                        <i class="dot expense"></i> Pengeluaran
                    </span>
                    <span onclick="toggleDataset(2)">
                        <i class="dot saldo"></i> Saldo
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pie Pengeluaran -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="fa-solid fa-chart-pie me-2 text-muted"></i>Komposisi Pengeluaran
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="pieChart" style="max-height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Breakdown Kategori -->
<div class="row g-3 mb-4" id="reportBreakdown" style="display:none">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-arrow-trend-down me-2 text-muted"></i>Pengeluaran per
                Kategori</div>
            <div class="card-body p-0" id="expenseBreakdown"></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-arrow-trend-up me-2 text-muted"></i>Pemasukan per Kategori
            </div>
            <div class="card-body p-0" id="incomeBreakdown"></div>
        </div>
    </div>
</div>

<!-- Tabel Detail Transaksi -->
<div class="card" id="reportTable" style="display:none">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-list me-2 text-muted"></i>Detail Transaksi</span>
        <span id="reportTableCount" class="text-muted" style="font-size:12px"></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead
                    style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">
                    <tr>
                        <th class="px-4 py-3">Kode</th>
                        <th class="py-3">Deskripsi</th>
                        <th class="py-3">Kategori</th>
                        <th class="py-3">Tipe</th>
                        <th class="py-3 text-end">Nominal</th>
                        <th class="py-3 text-end pe-4">Tanggal</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Empty State -->
<div id="reportEmpty" style="display:none">
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fa-solid fa-chart-bar fa-3x text-muted d-block mb-3" style="opacity:.3"></i>
            <h6 class="text-muted fw-normal">Tidak ada data untuk periode ini</h6>
            <p class="text-muted mb-0" style="font-size:13px">Coba ubah rentang tanggal atau lingkup laporan</p>
        </div>
    </div>
</div>


<?php
$extraScript = '<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>';
$extraScript .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>';
$extraScript .= '<script src="/assets/js/report.js?v=2"></script>';
require_once __DIR__ . '/layout/footer.php';
?>