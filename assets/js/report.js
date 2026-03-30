
let trendChartInst = null;
let pieChartInst = null;
let reportData = null;

// ============================================================
// Preset tanggal
// ============================================================
function applyPreset(val) {
    const now = new Date();
    let start, end = now.toISOString().slice(0, 10);

    if (val === "this_month") {
        start = now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-01";
    } else if (val === "last_month") {
        const lm = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const le = new Date(now.getFullYear(), now.getMonth(), 0);
        start = lm.toISOString().slice(0, 10);
        end = le.toISOString().slice(0, 10);
    } else if (val === "last_3") {
        const d = new Date(now); d.setMonth(d.getMonth() - 3);
        start = d.toISOString().slice(0, 10);
    } else if (val === "last_6") {
        const d = new Date(now); d.setMonth(d.getMonth() - 6);
        start = d.toISOString().slice(0, 10);
    } else if (val === "this_year") {
        start = now.getFullYear() + "-01-01";
    } else return;

    document.getElementById("rDateStart").value = start;
    document.getElementById("rDateEnd").value = end;
}

// ============================================================
// Load Report
// ============================================================
async function loadReport() {
    const dateStart = document.getElementById("rDateStart").value;
    const dateEnd = document.getElementById("rDateEnd").value;
    const scope = document.getElementById("rScope").value;

    if (!dateStart || !dateEnd) { alert("Pilih rentang tanggal."); return; }

    try {
        const params = new URLSearchParams({ date_start: dateStart, date_end: dateEnd, scope });
        const res = await fetch("/api/report.php?" + params);
        reportData = await res.json();

        if (!reportData.success || !reportData.data.total_transactions) {
            hideAll();
            document.getElementById("reportEmpty").style.display = "";
            return;
        }

        renderStats(reportData.data);
        renderTrendChart(reportData.data.daily_trend ?? []);
        renderPieChart(reportData.data.expense_by_category ?? []);
        renderBreakdown(reportData.data);
        renderTable(reportData.data.transactions ?? []);
        showAll();

        // Paksa chart resize setelah muncul
        setTimeout(() => {
            if (trendChartInst) trendChartInst.resize();
            if (pieChartInst) pieChartInst.resize();
        }, 300);
    } catch (e) {
        alert("Gagal memuat laporan.");
    }
}

function showAll() {
    ["reportStats", "reportCharts", "reportBreakdown", "reportTable"].forEach(id => {
        document.getElementById(id).style.display = "";
    });
    document.getElementById("reportEmpty").style.display = "none";
}

function hideAll() {
    ["reportStats", "reportCharts", "reportBreakdown", "reportTable"].forEach(id => {
        document.getElementById(id).style.display = "none";
    });
}

// ============================================================
// Render Stats
// ============================================================
function renderStats(d) {
    const saldo = (d.total_income ?? 0) - (d.total_expense ?? 0);
    document.getElementById("statIncome").textContent = formatRp(d.total_income ?? 0);
    document.getElementById("statExpense").textContent = formatRp(d.total_expense ?? 0);
    document.getElementById("statBalance").textContent = formatRp(Math.abs(saldo));
    document.getElementById("statBalance").style.color = saldo >= 0 ? "var(--primary)" : "var(--danger)";
    document.getElementById("statCount").textContent = d.total_transactions ?? 0;
}

// ============================================================
// Render Trend Chart (Line) — dengan saldo kumulatif
// ============================================================
function renderTrendChart(daily) {
    Chart.defaults.font.family = "Inter, system-ui, sans-serif";
    Chart.defaults.color = "#334155";

    const isMobile = window.innerWidth < 768;

    const labels = daily.map(d => {
        const dt = new Date(d.date);
        return dt.toLocaleDateString("id-ID", { day: "2-digit", month: "short" });
    });

    const income = daily.map(d => Number(d.income) || 0);
    const expense = daily.map(d => Number(d.expense) || 0);

    let cumulative = 0;
    const saldo = daily.map(d => {
        cumulative += (Number(d.income) || 0) - (Number(d.expense) || 0);
        return cumulative;
    });

    const showSaldo = document.getElementById("toggleSaldo")?.checked ?? true;

    if (trendChartInst) trendChartInst.destroy();

    trendChartInst = new Chart(document.getElementById("trendChart"), {
        type: "line",
        data: {
            labels,
            datasets: [
                {
                    label: "Pemasukan",
                    data: income,
                    borderColor: "#16a34a",
                    backgroundColor: "rgba(22,163,74,0.08)",
                    tension: 0.35,
                    fill: true,
                    pointRadius: 2,
                    borderWidth: 2,
                    yAxisID: "yMain",
                },
                {
                    label: "Pengeluaran",
                    data: expense,
                    borderColor: "#dc2626",
                    backgroundColor: "rgba(220,38,38,0.06)",
                    tension: 0.35,
                    fill: true,
                    pointRadius: 2,
                    borderWidth: 2,
                    yAxisID: "yMain",
                },
                {
                    label: "Saldo",
                    data: saldo,
                    borderColor: "#7c3aed",
                    borderDash: [6, 4],
                    tension: 0.4,
                    fill: false,
                    pointRadius: 1.5,
                    borderWidth: 2,
                    yAxisID: "ySaldo",
                    hidden: !showSaldo,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,

            layout: {
                padding: { top: 0, right: 10, left: 0, bottom: 0 }
            },

            interaction: {
                mode: "index",
                intersect: false
            },

            plugins: {
                legend: {
                    display: false   // Legend dimatikan → pakai legend manual
                },
                tooltip: {
                    backgroundColor: "#0f172a",
                    titleColor: "#fff",
                    bodyColor: "#e5e7eb",
                    padding: 10,
                    callbacks: {
                        label: c => {
                            return " " + c.dataset.label + ": " + formatRp(Math.abs(c.raw));
                        }
                    }
                }
            },

            scales: {
                x: {
                    ticks: {
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: isMobile ? 5 : 10,
                        font: { size: 10 }
                    },
                    grid: { display: false }
                },

                yMain: {
                    position: "left",
                    ticks: {
                        font: { size: 10 },
                        callback: v => {
                            if (v >= 1000000) return "Rp" + (v / 1000000).toFixed(1) + " jt";
                            if (v >= 1000) return "Rp" + (v / 1000).toFixed(0) + " rb";
                            return "Rp" + v;
                        }
                    },
                    grid: {
                        color: "rgba(0,0,0,0.05)"
                    }
                },

                ySaldo: {
                    position: "right",
                    display: showSaldo,
                    ticks: {
                        font: { size: 10 },
                        color: "#7c3aed",
                        callback: v => {
                            v = Math.abs(v);
                            if (v >= 1000000) return "Rp" + (v / 1000000).toFixed(1) + " jt";
                            if (v >= 1000) return "Rp" + (v / 1000).toFixed(0) + " rb";
                            return "Rp" + v;
                        }
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });
}

// Toggle saldo line on/off tanpa reload chart
function toggleSaldoLine(show) {
    if (!trendChartInst) return;
    const meta = trendChartInst.getDatasetMeta(2);
    meta.hidden = !show;
    trendChartInst.options.scales.ySaldo.display = show;
    trendChartInst.update();
}

function toggleDataset(index) {
    if (!trendChartInst) return;

    const meta = trendChartInst.getDatasetMeta(index);
    meta.hidden = meta.hidden === null ? true : null;

    // Toggle axis saldo
    if (index === 2) {
        trendChartInst.options.scales.ySaldo.display = meta.hidden !== true;
    }

    // Toggle opacity legend
    const legends = document.querySelectorAll(".chart-legend-manual span");
    legends[index].classList.toggle("off");

    trendChartInst.update();
}

// ============================================================
// Render Pie Chart
// ============================================================
function renderPieChart(categories) {
    const colors = ["#16a34a", "#dc2626", "#0891b2", "#d97706", "#7c3aed",
        "#db2777", "#059669", "#ea580c", "#2563eb", "#65a30d"];

    if (pieChartInst) pieChartInst.destroy();
    if (!categories.length) return;

    pieChartInst = new Chart(document.getElementById("pieChart").getContext("2d"), {
        type: "doughnut",
        data: {
            labels: categories.map(c => c.category),
            datasets: [{
                data: categories.map(c => c.total),
                backgroundColor: colors.slice(0, categories.length),
                borderWidth: 2, borderColor: "#fff"
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: "bottom", labels: { font: { size: 11 }, boxWidth: 12 } },
                tooltip: { callbacks: { label: c => " " + formatRp(c.raw) } }
            }
        }
    });
}

// ============================================================
// Render Breakdown Kategori
// ============================================================
function renderBreakdown(d) {
    renderCategoryList("expenseBreakdown", d.expense_by_category ?? [], "expense");
    renderCategoryList("incomeBreakdown", d.income_by_category ?? [], "income");
}

function renderCategoryList(elId, items, type) {
    const el = document.getElementById(elId);
    if (!items.length) {
        el.innerHTML = `<div class="text-center text-muted py-4" style="font-size:13px">Tidak ada data</div>`;
        return;
    }

    const totalAll = items.reduce((sum, i) => sum + Number(i.total), 0);
    const color = type === "expense" ? "var(--danger)" : "var(--primary)";

    let html = "";

    items.forEach((item, i) => {
        const percentParent = totalAll ? (item.total / totalAll * 100) : 0;

        html += `
        <div class="px-4 py-3 ${i < items.length - 1 ? "border-bottom" : ""}">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span style="font-size:13px;font-weight:700">
                    <i class="fa-solid ${item.icon ?? "fa-tag"} me-2 text-muted" style="font-size:11px"></i>
                    ${item.category ?? "Lainnya"}
                </span>
                <span style="font-size:12px;font-weight:700;font-family:var(--font-mono)">
                    ${formatRp(item.total)}
                </span>
            </div>

            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="progress flex-fill" style="height:5px;border-radius:4px;background:#f1f5f9">
                    <div class="progress-bar" style="width:${percentParent.toFixed(1)}%;background:${color}"></div>
                </div>
                <span style="font-size:10px;color:var(--text-muted);width:38px;text-align:right">
                    ${percentParent.toFixed(1)}%
                </span>
            </div>
        `;

        // === SUBCATEGORY ===
        if (item.children && item.children.length) {
            const totalParent = item.total;

            item.children.forEach(sub => {
                const percentSub = totalParent ? (sub.total / totalParent * 100) : 0;

                html += `
                <div class="ps-4 pe-1 pb-2">
                    <div class="d-flex justify-content-between" style="font-size:12px">
                        <span>
                            <i class="fa-solid ${sub.icon ?? "fa-tag"} me-2 text-muted" style="font-size:10px"></i>
                            ${sub.category === item.category ? sub.category + " (langsung)" : sub.category}
                        </span>
                        <span style="font-weight:600">${percentSub.toFixed(1)}%</span>
                    </div>
                    <div class="progress" style="height:3px;background:#f1f5f9">
                        <div class="progress-bar" style="width:${percentSub.toFixed(1)}%;background:#94a3b8"></div>
                    </div>
                </div>
                `;
            });
        }

        html += `</div>`;
    });

    el.innerHTML = html;
}

// ============================================================
// Render Tabel Detail
// ============================================================
function renderTable(rows) {
    document.getElementById("reportTableCount").textContent = rows.length + " transaksi";
    const tbody = document.getElementById("reportTableBody");
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-muted">Tidak ada transaksi</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map(t => `
        <tr>
            <td class="px-4 py-3"><span class="txn-code">${t.unique_code}</span></td>
            <td class="py-3">${t.description ?? "-"}</td>
            <td class="py-3" style="font-size:12px;color:var(--text-secondary)">
                <i class="fa-solid ${t.category_icon ?? "fa-tag"} me-1"></i>${t.category_name ?? "Lainnya"}
            </td>
            <td class="py-3">
                ${t.type === "income"
            ? `<span class="badge badge-income rounded-pill px-2">Masuk</span>`
            : `<span class="badge badge-expense rounded-pill px-2">Keluar</span>`}
            </td>
            <td class="py-3 text-end" style="font-family:var(--font-mono);font-weight:600;
                color:${t.type === "income" ? "var(--primary)" : "var(--danger)"}">
                ${t.type === "income" ? "+" : "-"}${formatRp(t.amount)}
            </td>
            <td class="py-3 text-end pe-4" style="font-size:12px;color:var(--text-muted)">
                ${formatDate(t.created_at)}
            </td>
        </tr>
    `).join("");
}

// ============================================================
// Export CSV (via server)
// ============================================================
function exportCSV() {
    const dateStart = document.getElementById("rDateStart").value;
    const dateEnd = document.getElementById("rDateEnd").value;
    const scope = document.getElementById("rScope").value;
    if (!dateStart || !dateEnd) { alert("Pilih rentang tanggal dulu."); return; }
    window.location = `/api/report.php?date_start=${dateStart}&date_end=${dateEnd}&scope=${scope}&export=csv`;
}

// ============================================================
// Export Excel (client-side via SheetJS)
// ============================================================
function exportExcel() {
    if (!reportData?.data?.transactions?.length) {
        alert("Tampilkan laporan terlebih dahulu."); return;
    }

    const d = reportData.data;
    const dateStart = document.getElementById("rDateStart").value;
    const dateEnd = document.getElementById("rDateEnd").value;
    const scopeEl = document.getElementById("rScope");
    const scopeName = scopeEl.options[scopeEl.selectedIndex].text;

    // === Sheet 1: Ringkasan ===
    const saldo = (d.total_income ?? 0) - (d.total_expense ?? 0);
    const summaryRows = [
        ["Laporan Keuangan — KitaCatat"],
        ["Periode", `${dateStart} s/d ${dateEnd}`],
        ["Lingkup", scopeName],
        ["Dibuat", new Date().toLocaleString("id-ID")],
        [],
        ["Ringkasan"],
        ["Total Pemasukan", d.total_income ?? 0],
        ["Total Pengeluaran", d.total_expense ?? 0],
        ["Saldo", saldo],
        ["Jumlah Transaksi", d.total_transactions ?? 0],
    ];

    // === Sheet 2: Transaksi ===
    const txnHeader = ["Kode", "Deskripsi", "Kategori", "Tipe", "Nominal", "Tanggal"];
    const txnRows = d.transactions.map(t => [
        t.unique_code,
        t.description ?? "-",
        t.category_name ?? "Lainnya",
        t.type === "income" ? "Pemasukan" : "Pengeluaran",
        t.type === "income" ? Number(t.amount) : -Number(t.amount),
        formatDate(t.created_at),
    ]);

    // === Sheet 3: Tren Harian ===
    const daily = d.daily_trend ?? [];
    const trendHeader = ["Tanggal", "Pemasukan", "Pengeluaran", "Saldo Kumulatif"];
    let cum = 0;
    const trendRows = daily.map(row => {
        cum += (Number(row.income) || 0) - (Number(row.expense) || 0);
        return [row.date, Number(row.income) || 0, Number(row.expense) || 0, cum];
    });

    // === Sheet 4: Pengeluaran per Kategori ===
    const expHeader = ["Kategori", "Total", "% dari Total"];
    const totalExp = d.total_expense || 1;
    const expRows = (d.expense_by_category ?? []).map(c => [
        c.category ?? "Lainnya",
        Number(c.total),
        Number((c.total / totalExp * 100).toFixed(1)),
    ]);

    // === Sheet 5: Pemasukan per Kategori ===
    const incHeader = ["Kategori", "Total", "% dari Total"];
    const totalInc = d.total_income || 1;
    const incRows = (d.income_by_category ?? []).map(c => [
        c.category ?? "Lainnya",
        Number(c.total),
        Number((c.total / totalInc * 100).toFixed(1)),
    ]);

    // Build workbook
    const wb = XLSX.utils.book_new();

    const wsSummary = XLSX.utils.aoa_to_sheet(summaryRows);
    wsSummary["!cols"] = [{ wch: 22 }, { wch: 20 }];
    XLSX.utils.book_append_sheet(wb, wsSummary, "Ringkasan");

    const wsTxn = XLSX.utils.aoa_to_sheet([txnHeader, ...txnRows]);
    wsTxn["!cols"] = [{ wch: 18 }, { wch: 30 }, { wch: 18 }, { wch: 14 }, { wch: 16 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, wsTxn, "Transaksi");

    const wsTrend = XLSX.utils.aoa_to_sheet([trendHeader, ...trendRows]);
    wsTrend["!cols"] = [{ wch: 14 }, { wch: 16 }, { wch: 16 }, { wch: 18 }];
    XLSX.utils.book_append_sheet(wb, wsTrend, "Tren Harian");

    const wsExp = XLSX.utils.aoa_to_sheet([expHeader, ...expRows]);
    wsExp["!cols"] = [{ wch: 22 }, { wch: 16 }, { wch: 12 }];
    XLSX.utils.book_append_sheet(wb, wsExp, "Pengeluaran");

    const wsInc = XLSX.utils.aoa_to_sheet([incHeader, ...incRows]);
    wsInc["!cols"] = [{ wch: 22 }, { wch: 16 }, { wch: 12 }];
    XLSX.utils.book_append_sheet(wb, wsInc, "Pemasukan");

    const fileName = `laporan_kitacatat_${dateStart}_${dateEnd}.xlsx`;
    XLSX.writeFile(wb, fileName);
}

// ============================================================
// Export PDF & Print — buka popup window bersih
// ============================================================
function exportPDF() { openPrintWindow(); }
function printReport() { openPrintWindow(); }

// ============================================================
// openPrintWindow — buka halaman bersih untuk print / save PDF
// ============================================================
function openPrintWindow() {
    if (!reportData?.data) {
        alert("Tampilkan laporan terlebih dahulu."); return;
    }

    const d = reportData.data;
    const dateStart = document.getElementById("rDateStart").value;
    const dateEnd = document.getElementById("rDateEnd").value;
    const scopeEl = document.getElementById("rScope");
    const scopeName = scopeEl.options[scopeEl.selectedIndex].text;
    const saldo = (d.total_income ?? 0) - (d.total_expense ?? 0);

    // Tren harian + saldo kumulatif
    let cum = 0;
    const trendRows = (d.daily_trend ?? []).map(row => {
        cum += (Number(row.income) || 0) - (Number(row.expense) || 0);
        return `<tr>
            <td>${row.date}</td>
            <td style="color:#16a34a;text-align:right">${formatRp(Number(row.income) || 0)}</td>
            <td style="color:#dc2626;text-align:right">${formatRp(Number(row.expense) || 0)}</td>
            <td style="color:#7c3aed;text-align:right;font-weight:600">${cum < 0 ? "-" : ""}${formatRp(Math.abs(cum))}</td>
        </tr>`;
    }).join("");

    // Pengeluaran per kategori
    const expRows = (d.expense_by_category ?? []).map(c => `<tr>
        <td>${c.category ?? "Lainnya"}</td>
        <td style="text-align:right;color:#dc2626">${formatRp(c.total)}</td>
        <td style="text-align:right;color:#64748b">${(c.total / (d.total_expense || 1) * 100).toFixed(1)}%</td>
    </tr>`).join("");

    // Pemasukan per kategori
    const incRows = (d.income_by_category ?? []).map(c => `<tr>
        <td>${c.category ?? "Lainnya"}</td>
        <td style="text-align:right;color:#16a34a">${formatRp(c.total)}</td>
        <td style="text-align:right;color:#64748b">${(c.total / (d.total_income || 1) * 100).toFixed(1)}%</td>
    </tr>`).join("");

    // Transaksi detail
    const txnRows = (d.transactions ?? []).map(t => `<tr>
        <td style="font-size:10px;color:#64748b">${t.unique_code}</td>
        <td>${t.description ?? "-"}</td>
        <td>${t.category_name ?? "Lainnya"}</td>
        <td>${t.type === "income" ? "Masuk" : "Keluar"}</td>
        <td style="text-align:right;color:${t.type === "income" ? "#16a34a" : "#dc2626"};font-weight:600">
            ${t.type === "income" ? "+" : "-"}${formatRp(t.amount)}
        </td>
        <td style="color:#64748b;font-size:10px">${formatDate(t.created_at)}</td>
    </tr>`).join("");

    const html = `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan KitaCatat — ${dateStart} s/d ${dateEnd}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #0f172a; padding: 24px 32px; }
  h2 { font-size: 18px; margin-bottom: 4px; }
  h3 { font-size: 13px; margin: 20px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
  .meta { font-size: 11px; color: #64748b; margin-bottom: 16px; }
  .summary { display: flex; gap: 12px; margin-bottom: 4px; }
  .summary-box { flex: 1; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
  .summary-label { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
  .summary-value { font-size: 15px; font-weight: 700; margin-top: 3px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #e2e8f0; padding: 5px 8px; text-align: left; font-size: 11px; }
  th { background: #f8fafc; font-weight: 700; }
  .two-col { display: flex; gap: 16px; }
  .two-col > div { flex: 1; min-width: 0; }
  .toolbar { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
  .btn-print { background: #2563eb; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-size: 13px; cursor: pointer; font-weight: 600; }
  .btn-close-win { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; padding: 8px 14px; border-radius: 6px; font-size: 13px; cursor: pointer; }
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

<h2>Laporan Keuangan — KitaCatat</h2>
<div class="meta">
    Periode: <strong>${dateStart} s/d ${dateEnd}</strong> &nbsp;·&nbsp;
    Lingkup: <strong>${scopeName}</strong> &nbsp;·&nbsp;
    Dicetak: ${new Date().toLocaleString("id-ID")}
</div>

<div class="summary">
    <div class="summary-box">
        <div class="summary-label">Total Pemasukan</div>
        <div class="summary-value" style="color:#16a34a">${formatRp(d.total_income ?? 0)}</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Total Pengeluaran</div>
        <div class="summary-value" style="color:#dc2626">${formatRp(d.total_expense ?? 0)}</div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Saldo</div>
        <div class="summary-value" style="color:${saldo >= 0 ? "#16a34a" : "#dc2626"}">
            ${saldo < 0 ? "-" : ""}${formatRp(Math.abs(saldo))}
        </div>
    </div>
    <div class="summary-box">
        <div class="summary-label">Jumlah Transaksi</div>
        <div class="summary-value">${d.total_transactions ?? 0}</div>
    </div>
</div>

<h3>Tren Harian</h3>
<table>
    <thead><tr>
        <th>Tanggal</th>
        <th style="text-align:right">Pemasukan</th>
        <th style="text-align:right">Pengeluaran</th>
        <th style="text-align:right;color:#7c3aed">Saldo Kumulatif</th>
    </tr></thead>
    <tbody>${trendRows || '<tr><td colspan="4" style="text-align:center;color:#94a3b8">Tidak ada data</td></tr>'}</tbody>
</table>

<h3>Pengeluaran &amp; Pemasukan per Kategori</h3>
<div class="two-col">
    <div>
        <table>
            <thead><tr><th>Pengeluaran</th><th style="text-align:right">Total</th><th style="text-align:right">%</th></tr></thead>
            <tbody>${expRows || '<tr><td colspan="3" style="text-align:center;color:#94a3b8">Tidak ada data</td></tr>'}</tbody>
        </table>
    </div>
    <div>
        <table>
            <thead><tr><th>Pemasukan</th><th style="text-align:right">Total</th><th style="text-align:right">%</th></tr></thead>
            <tbody>${incRows || '<tr><td colspan="3" style="text-align:center;color:#94a3b8">Tidak ada data</td></tr>'}</tbody>
        </table>
    </div>
</div>

<h3>Detail Transaksi</h3>
<table>
    <thead><tr>
        <th>Kode</th><th>Deskripsi</th><th>Kategori</th>
        <th>Tipe</th><th style="text-align:right">Nominal</th><th>Tanggal</th>
    </tr></thead>
    <tbody>${txnRows || '<tr><td colspan="6" style="text-align:center;color:#94a3b8">Tidak ada transaksi</td></tr>'}</tbody>
</table>

</body>
</html>`;

    const win = window.open("", "_blank", "width=960,height=720,scrollbars=yes,resizable=yes");
    if (!win) {
        alert("Popup diblokir browser. Izinkan popup untuk kitacatat.masbendz.com lalu coba lagi.");
        return;
    }
    win.document.write(html);
    win.document.close();
}

// ============================================================
// Helpers
// ============================================================
function formatRp(n) {
    return "Rp\u00a0" + parseInt(n).toLocaleString("id-ID");
}

function formatDate(d) {
    if (!d) return "-";
    const dt = new Date(d);
    return dt.toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" })
        + ", " + dt.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
}

// Load otomatis bulan ini saat halaman dibuka
window.addEventListener("DOMContentLoaded", () => {
    applyPreset("this_month");
    loadReport();
});
