let currentPage   = 1;
let deleteTarget  = null;
const modal       = new bootstrap.Modal(document.getElementById("trxModal"));
const deleteModal = new bootstrap.Modal(document.getElementById("deleteModal"));

// ============================================================
// Load & Render Transaksi
// ============================================================
async function loadTransactions(page = 1) {
    currentPage = page;
    const type      = document.getElementById("filterType").value;
    const category  = document.getElementById("filterCategory").value;
    const dateStart = document.getElementById("filterDateStart").value;
    const dateEnd   = document.getElementById("filterDateEnd").value;

    const params = new URLSearchParams({ page, type, category, date_start: dateStart, date_end: dateEnd });

    try {
        const res  = await fetch("/api/transactions.php?" + params);
        const data = await res.json();

        renderTable(data.data ?? []);
        renderPagination(data.pagination ?? {});
        renderSummary(data.summary ?? {});
    } catch(e) {
        document.getElementById("trxBody").innerHTML =
            "<tr><td colspan=\"7\" class=\"text-center py-4 text-danger\">Gagal memuat data.</td></tr>";
    }
}

function renderTable(rows) {
    const tbody = document.getElementById("trxBody");
    if (!rows.length) {
        tbody.innerHTML = "<tr><td colspan=\"7\" class=\"text-center py-5 text-muted\"><i class=\"fa-solid fa-inbox fa-2x d-block mb-2\"></i>Tidak ada transaksi</td></tr>";
        return;
    }
    tbody.innerHTML = rows.map(t => `
        <tr>
            <td class="px-4 py-3"><span class="txn-code">${t.unique_code}</span></td>
            <td class="py-3">${escHtml(t.description ?? "-")}</td>
            <td class="py-3" style="font-size:12px;color:var(--text-secondary)">
                <i class="fa-solid ${escHtml(t.category_icon ?? "fa-tag")} me-1"></i>
                ${escHtml(t.category_name ?? "Lainnya")}
            </td>
            <td class="py-3">
                ${t.type === "income"
                    ? "<span class=\"badge badge-income rounded-pill px-2\">Masuk</span>"
                    : "<span class=\"badge badge-expense rounded-pill px-2\">Keluar</span>"}
            </td>
            <td class="py-3 text-end pe-3" style="font-family:var(--font-mono);font-weight:600;color:${t.type==="income"?"var(--primary)":"var(--danger)"}">
                ${t.type==="income"?"+":"-"}${formatRp(t.amount)}
            </td>
            <td class="py-3 text-end pe-3" style="font-size:12px;color:var(--text-muted)">${formatDate(t.created_at)}</td>
            <td class="py-3 text-center">
                <button class="btn btn-sm btn-outline-secondary me-1" style="padding:3px 8px;font-size:11px"
                    onclick="openEditModal(${JSON.stringify(t).replace(/"/g, "&quot;")})">
                    <i class="fa-solid fa-pen"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" style="padding:3px 8px;font-size:11px"
                    onclick="openDeleteModal(${escHtml(JSON.stringify({id:t.id,code:t.unique_code}))})">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
    `).join("");
}

function renderSummary(s) {
    const bar = document.getElementById("summaryBar");
    if (!s.total_income && !s.total_expense) { bar.style.display="none"; return; }
    bar.style.display="";
    const saldo = (s.total_income ?? 0) - (s.total_expense ?? 0);
    bar.innerHTML = `
        <div class="col-12 col-sm-4"><div class="stat-card py-2 px-3">
            <div class="stat-icon income" style="width:36px;height:36px;font-size:14px"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div><div class="stat-label">Pemasukan</div><div class="stat-value" style="font-size:13px">${formatRp(s.total_income??0)}</div></div>
        </div></div>
        <div class="col-12 col-sm-4"><div class="stat-card py-2 px-3">
            <div class="stat-icon expense" style="width:36px;height:36px;font-size:14px"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div><div class="stat-label">Pengeluaran</div><div class="stat-value" style="font-size:13px">${formatRp(s.total_expense??0)}</div></div>
        </div></div>
        <div class="col-12 col-sm-4"><div class="stat-card py-2 px-3">
            <div class="stat-icon balance" style="width:36px;height:36px;font-size:14px"><i class="fa-solid fa-scale-balanced"></i></div>
            <div><div class="stat-label">Saldo</div><div class="stat-value" style="font-size:13px;color:${saldo>=0?"var(--primary)":"var(--danger)"}">${formatRp(Math.abs(saldo))}</div></div>
        </div></div>
    `;
}

function renderPagination(p) {
    document.getElementById("paginationInfo").textContent =
        p.total ? `Menampilkan ${p.from}–${p.to} dari ${p.total} transaksi` : "";

    const links = document.getElementById("paginationLinks");
    if (!p.total_pages || p.total_pages <= 1) { links.innerHTML=""; return; }

    let html = "";
    html += `<button class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:4px 10px"
        ${p.current_page<=1?"disabled":""} onclick="loadTransactions(${p.current_page-1})">
        <i class="fa-solid fa-chevron-left"></i></button>`;
    for (let i=1; i<=p.total_pages; i++) {
        if (i===1||i===p.total_pages||Math.abs(i-p.current_page)<=1) {
            html += `<button class="btn btn-sm ${i===p.current_page?"btn-primary":"btn-outline-secondary"}"
                style="font-size:11px;padding:4px 10px;min-width:32px" onclick="loadTransactions(${i})">${i}</button>`;
        } else if (Math.abs(i-p.current_page)===2) {
            html += `<span class="px-1" style="font-size:12px;line-height:30px;color:var(--text-muted)">…</span>`;
        }
    }
    html += `<button class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:4px 10px"
        ${p.current_page>=p.total_pages?"disabled":""} onclick="loadTransactions(${p.current_page+1})">
        <i class="fa-solid fa-chevron-right"></i></button>`;
    links.innerHTML = html;
}

// ============================================================
// Modal Tambah
// ============================================================
function openAddModal() {
    document.getElementById("modalTitle").textContent = "Tambah Transaksi";
    document.getElementById("trxId").value      = "";
    document.getElementById("trxAmount").value  = "";
    document.getElementById("trxDesc").value    = "";
    document.getElementById("trxDate").value    = new Date().toISOString().slice(0,16);
    setType("expense");
    modal.show();
}

// ============================================================
// Modal Edit
// ============================================================
function openEditModal(t) {
    document.getElementById("modalTitle").textContent = "Edit Transaksi";
    document.getElementById("trxId").value       = t.id;
    document.getElementById("trxAmount").value   = t.amount;
    document.getElementById("trxDesc").value     = t.description ?? "";
    document.getElementById("trxDate").value     = t.created_at ? t.created_at.slice(0,16) : "";
    document.getElementById("trxCategory").value = t.category_id ?? "";
    setType(t.type);
    modal.show();
}

// ============================================================
// Simpan (Tambah / Edit)
// ============================================================
async function saveTrx() {
    const id      = document.getElementById("trxId").value;
    const amount  = parseInt(document.getElementById("trxAmount").value);
    const desc    = document.getElementById("trxDesc").value.trim();
    const type    = document.getElementById("trxType").value;
    const catId   = document.getElementById("trxCategory").value;
    const date    = document.getElementById("trxDate").value;

    if (!amount || amount <= 0) { alert("Nominal tidak valid."); return; }
    if (!desc)                  { alert("Deskripsi wajib diisi."); return; }

    const payload = { amount, description: desc, type, category_id: catId, created_at: date };
    const url     = id ? `/api/transactions.php?id=${id}` : "/api/transactions.php";
    const method  = id ? "PUT" : "POST";

    try {
        const res  = await fetch(url, { method, headers:{"Content-Type":"application/json"}, body: JSON.stringify(payload) });
        const data = await res.json();
        if (data.success) { modal.hide(); loadTransactions(currentPage); }
        else { alert(data.message ?? "Gagal menyimpan."); }
    } catch(e) { alert("Gagal menyimpan."); }
}

// ============================================================
// Hapus
// ============================================================
function openDeleteModal(t) {
    deleteTarget = t;
    document.getElementById("deleteCode").textContent = t.code;
    deleteModal.show();
}

async function confirmDelete() {
    if (!deleteTarget) return;
    try {
        const res  = await fetch(`/api/transactions.php?id=${deleteTarget.id}`, { method: "DELETE" });
        const data = await res.json();
        if (data.success) { deleteModal.hide(); loadTransactions(currentPage); }
        else { alert(data.message ?? "Gagal menghapus."); }
    } catch(e) { alert("Gagal menghapus."); }
}

// ============================================================
// Helpers
// ============================================================
function setType(type) {
    document.getElementById("trxType").value = type;
    const btnExp = document.getElementById("btnExpense");
    const btnInc = document.getElementById("btnIncome");
    if (type === "expense") {
        btnExp.style.cssText = "border:2px solid var(--danger);color:#fff;background:var(--danger);border-radius:8px;font-weight:600;flex:1";
        btnInc.style.cssText = "border:2px solid #e2e8f0;color:var(--text-secondary);background:#fff;border-radius:8px;font-weight:600;flex:1";
    } else {
        btnInc.style.cssText = "border:2px solid var(--primary);color:#fff;background:var(--primary);border-radius:8px;font-weight:600;flex:1";
        btnExp.style.cssText = "border:2px solid #e2e8f0;color:var(--text-secondary);background:#fff;border-radius:8px;font-weight:600;flex:1";
    }
    // Filter opsi kategori sesuai tipe
    document.querySelectorAll("#trxCategory option").forEach(opt => {
        opt.hidden = opt.dataset.type && opt.dataset.type !== type;
    });
}

function resetFilter() {
    document.getElementById("filterType").value      = "";
    document.getElementById("filterCategory").value  = "";
    document.getElementById("filterDateStart").value = new Date().toISOString().slice(0,7) + "-01";
    document.getElementById("filterDateEnd").value   = new Date().toISOString().slice(0,10);
    loadTransactions(1);
}

function formatRp(n) {
    return "Rp\u00a0" + parseInt(n).toLocaleString("id-ID");
}

function formatDate(d) {
    if (!d) return "-";
    const dt = new Date(d);
    return dt.toLocaleDateString("id-ID",{day:"2-digit",month:"short",year:"numeric"})
        + ", " + dt.toLocaleTimeString("id-ID",{hour:"2-digit",minute:"2-digit"});
}

function escHtml(str) {
    if (typeof str !== "string") return str;
    return str.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

// Load saat halaman dibuka
loadTransactions();