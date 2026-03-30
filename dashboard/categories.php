<?php
// ============================================================
// KitaCatat — Dashboard: Kategori
// ============================================================
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Kategori';
require_once __DIR__ . '/layout/header.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Ambil kategori global (induk)
$stmtGlobal = $db->prepare(
    "SELECT * FROM categories
     WHERE is_default = 1 AND user_id IS NULL
     ORDER BY type ASC, name ASC"
);
$stmtGlobal->execute();
$globalCats = $stmtGlobal->fetchAll();

// Ambil kategori custom user (beserta nama induknya)
$stmtCustom = $db->prepare(
    "SELECT c.*, p.name AS parent_name, p.icon AS parent_icon
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.user_id = ? AND c.is_default = 0
     ORDER BY c.type ASC, p.name ASC, c.name ASC"
);
$stmtCustom->execute([$userId]);
$customCats = $stmtCustom->fetchAll();

// Kelompokkan custom per parent_id (null = tanpa induk)
$customByParent = [];
foreach ($customCats as $c) {
    $customByParent[$c['parent_id'] ?? 'none'][] = $c;
}

// Helper: pisah expense/income
$globalExpenses = array_filter($globalCats, fn($c) => $c['type'] === 'expense');
$globalIncomes  = array_filter($globalCats, fn($c) => $c['type'] === 'income');
$orphanExpenses = array_filter($customByParent['none'] ?? [], fn($c) => $c['type'] === 'expense');
$orphanIncomes  = array_filter($customByParent['none'] ?? [], fn($c) => $c['type'] === 'income');
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Kategori</h5>
        <p class="text-muted mb-0" style="font-size:13px">Kelola kategori transaksi Anda</p>
    </div>
    <button class="btn btn-sm btn-primary" onclick="openAddModal()">
        <i class="fa-solid fa-plus me-1"></i>Tambah Kategori
    </button>
</div>

<div class="row g-3">
<?php foreach ([
    ['type'=>'expense','label'=>'Pengeluaran','globals'=>$globalExpenses,'orphans'=>$orphanExpenses,'dot'=>'var(--danger)'],
    ['type'=>'income', 'label'=>'Pemasukan',  'globals'=>$globalIncomes, 'orphans'=>$orphanIncomes, 'dot'=>'var(--primary)'],
] as $col): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <span style="width:8px;height:8px;background:<?= $col['dot'] ?>;border-radius:50%;display:inline-block"></span>
                <?= $col['label'] ?>
                <span class="ms-auto text-muted" style="font-size:11px">
                    <?= count($col['globals']) + count($col['orphans']) + array_sum(array_map(
                        fn($g) => count($customByParent[$g['id']] ?? []),
                        $col['globals']
                    )) ?> kategori
                </span>
            </div>
            <div class="card-body p-0">

            <?php foreach ($col['globals'] as $g):
                $children = array_filter($customByParent[$g['id']] ?? [], fn($c) => $c['type'] === $col['type']);
            ?>
                <!-- Kategori Global (induk) -->
                <div class="px-4 py-3 border-bottom" style="background:#fafafa">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:34px;height:34px;background:<?= $col['type']==='expense'?'var(--danger-light)':'var(--primary-light)' ?>;border-radius:9px;
                                display:flex;align-items:center;justify-content:center;font-size:13px;color:<?= $col['type']==='expense'?'var(--danger)':'var(--primary)' ?>">
                                <i class="fa-solid <?= htmlspecialchars($g['icon'] ?? 'fa-tag') ?>"></i>
                            </div>
                            <div>
                                <div style="font-size:13px;font-weight:700"><?= htmlspecialchars($g['name']) ?></div>
                                <div style="font-size:10.5px;color:var(--text-muted)">
                                    Sistem · <?= count($children) ?> sub-kategori
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" style="padding:2px 9px;font-size:11px"
                            onclick="openAddModal('<?= $col['type'] ?>',<?= $g['id'] ?>,'<?= htmlspecialchars(addslashes($g['name'])) ?>')"
                            title="Tambah sub-kategori">
                            <i class="fa-solid fa-plus me-1"></i>Sub
                        </button>
                    </div>
                </div>

                <?php foreach ($children as $child): ?>
                <!-- Sub-kategori user -->
                <div class="d-flex align-items-center justify-content-between border-bottom"
                     style="padding:10px 16px 10px 52px">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-turn-down-right text-muted" style="font-size:10px;opacity:.4"></i>
                        <div style="width:28px;height:28px;background:<?= $col['type']==='expense'?'var(--danger-light)':'var(--primary-light)' ?>;border-radius:7px;
                            display:flex;align-items:center;justify-content:center;font-size:12px;color:<?= $col['type']==='expense'?'var(--danger)':'var(--primary)' ?>">
                            <i class="fa-solid <?= htmlspecialchars($child['icon'] ?? 'fa-tag') ?>"></i>
                        </div>
                        <div>
                            <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($child['name']) ?></div>
                            <div style="font-size:10px;color:var(--text-muted)">Custom</div>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary" style="padding:2px 7px;font-size:11px"
                            onclick="openEditModal(<?= htmlspecialchars(json_encode($child)) ?>)">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" style="padding:2px 7px;font-size:11px"
                            onclick="deleteCategory(<?= $child['id'] ?>, '<?= htmlspecialchars(addslashes($child['name'])) ?>')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>

            <?php endforeach; ?>

            <?php if (!empty($col['orphans'])): ?>
                <!-- Sub-kategori tanpa induk -->
                <div class="px-4 py-2 border-bottom" style="background:#fafafa">
                    <span style="font-size:10.5px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">
                        Tanpa Induk
                    </span>
                </div>
                <?php foreach ($col['orphans'] as $child): ?>
                <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:34px;height:34px;background:<?= $col['type']==='expense'?'var(--danger-light)':'var(--primary-light)' ?>;border-radius:9px;
                            display:flex;align-items:center;justify-content:center;font-size:13px;color:<?= $col['type']==='expense'?'var(--danger)':'var(--primary)' ?>">
                            <i class="fa-solid <?= htmlspecialchars($child['icon'] ?? 'fa-tag') ?>"></i>
                        </div>
                        <div>
                            <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($child['name']) ?></div>
                            <div style="font-size:10.5px;color:var(--text-muted)">Custom · tanpa induk</div>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary" style="padding:2px 7px;font-size:11px"
                            onclick="openEditModal(<?= htmlspecialchars(json_encode($child)) ?>)">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" style="padding:2px 7px;font-size:11px"
                            onclick="deleteCategory(<?= $child['id'] ?>, '<?= htmlspecialchars(addslashes($child['name'])) ?>')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($col['globals']) && empty($col['orphans'])): ?>
                <div class="text-center text-muted py-4" style="font-size:13px">Belum ada kategori</div>
            <?php endif; ?>

            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ============================================================
     Modal Tambah/Edit
     ============================================================ -->
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content" style="border-radius:var(--radius)">
            <div class="modal-header border-bottom" style="padding:16px 20px">
                <h6 class="modal-title fw-bold" id="catModalTitle">Tambah Kategori</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <input type="hidden" id="catId">

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Tipe</label>
                    <select id="catType" class="form-select" onchange="updatePreviewColor();updateParentOptions()">
                        <option value="expense">Pengeluaran</option>
                        <option value="income">Pemasukan</option>
                    </select>
                </div>

                <!-- Induk Kategori -->
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">
                        Induk Kategori
                        <span style="font-weight:400;color:var(--text-muted)">(opsional)</span>
                    </label>
                    <select id="catParent" class="form-select">
                        <option value="">— Tanpa Induk —</option>
                    </select>
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Pilih kategori sistem sebagai induk agar lebih terorganisir
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Nama Kategori</label>
                    <input type="text" id="catName" class="form-control"
                           placeholder="Contoh: Listrik, Pulsa, Bonus Proyek" maxlength="50">
                </div>

                <!-- Icon Picker -->
                <div class="mb-1">
                    <label class="form-label" style="font-size:12px;font-weight:600">Icon</label>

                    <div class="d-flex gap-2 align-items-center mb-2">
                        <div id="iconPreviewBox"
                            style="width:46px;height:46px;border-radius:12px;display:flex;align-items:center;
                                   justify-content:center;font-size:20px;flex-shrink:0;
                                   background:var(--danger-light);color:var(--danger);transition:all .2s">
                            <i class="fa-solid fa-tag" id="iconPreviewEl"></i>
                        </div>
                        <div style="flex:1">
                            <button type="button" id="btnTogglePicker"
                                class="btn btn-sm btn-outline-secondary w-100 text-start"
                                onclick="toggleIconPicker()"
                                style="font-size:12px;padding:7px 12px">
                                <i class="fa-solid fa-grid-2 me-2"></i>
                                <span id="iconSelectedName" style="font-family:monospace;color:var(--text-secondary)">fa-tag</span>
                                <i class="fa-solid fa-chevron-down ms-auto float-end mt-1" style="font-size:10px;opacity:.5"></i>
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="catIcon" value="fa-tag">

                    <div id="iconPickerPanel" style="display:none;border:1px solid var(--card-border);
                        border-radius:var(--radius-sm);background:#fff;overflow:hidden;margin-top:4px">
                        <div style="padding:10px 12px;border-bottom:1px solid var(--card-border);background:#f8fafc">
                            <input type="text" id="iconSearch" class="form-control form-control-sm mb-2"
                                   placeholder="Cari nama icon..." oninput="filterIcons(this.value)"
                                   style="font-size:12px">
                            <div id="groupTabs" style="display:flex;gap:4px;flex-wrap:wrap"></div>
                        </div>
                        <div id="iconGrid"
                            style="display:grid;grid-template-columns:repeat(9,1fr);gap:3px;
                                   padding:10px 12px;max-height:220px;overflow-y:auto"></div>
                        <div style="padding:8px 12px;border-top:1px solid var(--card-border);background:#f8fafc;
                                    display:flex;align-items:center;gap:8px">
                            <span style="font-size:11px;color:var(--text-muted);white-space:nowrap">Manual:</span>
                            <input type="text" id="iconManual" class="form-control form-control-sm"
                                   placeholder="fa-..." style="font-size:12px;font-family:monospace"
                                   oninput="applyManualIcon(this.value)">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top" style="padding:14px 20px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnSave" onclick="saveCategory()">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<?php
// Data PHP di-output dulu sebelum HEREDOC
$jsGlobalCats = json_encode(array_map(fn($c) => [
    'id'   => $c['id'],
    'name' => $c['name'],
    'type' => $c['type'],
], $globalCats));

ob_start();
echo "<script>\n";
echo "const GLOBAL_CATS = {$jsGlobalCats};\n";
?>
const catModal = new bootstrap.Modal(document.getElementById("catModal"));

function updateParentOptions(selectedParentId = null) {
    const type   = document.getElementById("catType").value;
    const select = document.getElementById("catParent");
    const filtered = GLOBAL_CATS.filter(c => c.type === type);
    select.innerHTML = '<option value="">— Tanpa Induk —</option>'
        + filtered.map(c =>
            `<option value="${c.id}" ${c.id == selectedParentId ? 'selected' : ''}>${c.name}</option>`
        ).join('');
}

const ICON_GROUPS = {
    "Semua": null,
    "🍔 Makanan": ["fa-utensils","fa-burger","fa-pizza-slice","fa-ice-cream","fa-cake-candles","fa-apple-whole","fa-egg","fa-fish","fa-drumstick-bite","fa-bread-slice","fa-coffee","fa-mug-hot","fa-wine-glass","fa-martini-glass","fa-lemon","fa-carrot","fa-pepper-hot","fa-bowl-rice","fa-bowl-food","fa-jar"],
    "🚗 Transport": ["fa-car","fa-motorcycle","fa-bus","fa-train","fa-plane","fa-taxi","fa-truck","fa-bicycle","fa-ship","fa-rocket","fa-gas-pump","fa-road","fa-map-location-dot","fa-route","fa-parking"],
    "🛒 Belanja": ["fa-shopping-bag","fa-shopping-cart","fa-store","fa-bag-shopping","fa-shirt","fa-shoe-prints","fa-glasses","fa-hat-cowboy","fa-ring","fa-gem","fa-scissors","fa-brush","fa-palette","fa-wand-magic-sparkles"],
    "🏠 Rumah": ["fa-house","fa-building","fa-couch","fa-bed","fa-toilet","fa-door-open","fa-window-maximize","fa-hammer","fa-wrench","fa-screwdriver-wrench","fa-bolt","fa-droplet","fa-fire","fa-snowflake","fa-fan","fa-tv","fa-blender","fa-plug","fa-lightbulb","fa-lock"],
    "💊 Kesehatan": ["fa-heart-pulse","fa-syringe","fa-pills","fa-tooth","fa-eye","fa-ear","fa-lungs","fa-hospital","fa-stethoscope","fa-kit-medical","fa-dumbbell","fa-person-walking","fa-spa","fa-leaf","fa-seedling"],
    "📚 Pendidikan": ["fa-graduation-cap","fa-book","fa-book-open","fa-pencil","fa-pen","fa-ruler","fa-calculator","fa-laptop","fa-chalkboard","fa-school","fa-microscope","fa-flask","fa-atom","fa-globe","fa-language"],
    "💰 Keuangan": ["fa-money-bill-wave","fa-coins","fa-piggy-bank","fa-wallet","fa-credit-card","fa-receipt","fa-file-invoice","fa-chart-line","fa-chart-bar","fa-chart-pie","fa-bank","fa-briefcase","fa-handshake","fa-percent","fa-arrow-trend-up","fa-arrow-trend-down","fa-sack-dollar","fa-landmark","fa-vault","fa-dollar-sign"],
    "🎮 Hiburan": ["fa-film","fa-music","fa-gamepad","fa-headphones","fa-camera","fa-image","fa-ticket","fa-star","fa-trophy","fa-medal","fa-dice","fa-chess","fa-puzzle-piece","fa-paintbrush","fa-microphone","fa-guitar","fa-drum","fa-tv","fa-podcast","fa-radio"],
    "👨‍👩 Sosial": ["fa-gift","fa-heart","fa-hand-holding-heart","fa-people-group","fa-users","fa-user-tie","fa-children","fa-baby","fa-dog","fa-cat","fa-tree","fa-sun","fa-umbrella","fa-balloon","fa-party-horn","fa-champagne-glasses","fa-cake-candles","fa-flower","fa-hand-sparkles","fa-person"],
    "📦 Lainnya": ["fa-tag","fa-ellipsis","fa-box","fa-boxes-stacked","fa-archive","fa-paper-plane","fa-envelope","fa-phone","fa-mobile","fa-wifi","fa-print","fa-fax","fa-clipboard","fa-note-sticky","fa-folder","fa-cloud","fa-shield-halved","fa-key","fa-crown","fa-circle-info"],
};
const ALL_ICONS = [...new Set(Object.entries(ICON_GROUPS).filter(([k])=>k!=="Semua").flatMap(([,v])=>v))];
let activeGroup = "Semua";

function getTypeColors(type) {
    const style = getComputedStyle(document.documentElement);
    return type === "income"
        ? { bg: style.getPropertyValue("--primary-light").trim()||"#dbeafe", fg: style.getPropertyValue("--primary").trim()||"#2563eb" }
        : { bg: style.getPropertyValue("--danger-light").trim()||"#fee2e2",  fg: style.getPropertyValue("--danger").trim()||"#dc2626" };
}

function updatePreviewColor() {
    const type = document.getElementById("catType").value;
    const colors = getTypeColors(type);
    const box = document.getElementById("iconPreviewBox");
    box.style.background = colors.bg;
    box.style.color      = colors.fg;
    document.getElementById("btnSave").className = type === "income" ? "btn btn-sm btn-primary" : "btn btn-sm btn-danger";
    filterIcons(document.getElementById("iconSearch").value);
}

function renderGroupTabs() {
    const type   = document.getElementById("catType").value;
    const style  = getComputedStyle(document.documentElement);
    const activeCol = type === "income"
        ? (style.getPropertyValue("--primary").trim()||"#2563eb")
        : (style.getPropertyValue("--danger").trim()||"#dc2626");
    document.getElementById("groupTabs").innerHTML = Object.keys(ICON_GROUPS).map(g =>
        `<button type="button" onclick="setGroup('${g.replace(/'/g,"\\'")}');event.stopPropagation()"
            style="font-size:11px;padding:3px 9px;border-radius:20px;border:1.5px solid;cursor:pointer;transition:all .15s;white-space:nowrap;
                   ${activeGroup===g ? `background:${activeCol};color:#fff;border-color:${activeCol}` : "background:#fff;color:var(--text-secondary);border-color:var(--card-border)"}"
        >${g}</button>`).join("");
}
function setGroup(g) { activeGroup=g; document.getElementById("iconSearch").value=""; renderGroupTabs(); renderIcons(getGroupIcons(g)); }
function getGroupIcons(g) { return g==="Semua"?ALL_ICONS:(ICON_GROUPS[g]??ALL_ICONS); }
function renderIcons(list) {
    const cur = document.getElementById("catIcon").value;
    const colors = getTypeColors(document.getElementById("catType").value);
    document.getElementById("iconGrid").innerHTML = list.length
        ? list.map(icon=>`<div onclick="selectIcon('${icon}');event.stopPropagation()" title="${icon}"
            style="aspect-ratio:1;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;cursor:pointer;transition:all .12s;
                   ${icon===cur?`background:${colors.bg};color:${colors.fg};border:2px solid ${colors.fg}`:"background:#f8fafc;color:#64748b;border:2px solid transparent"}">
            <i class="fa-solid ${icon}"></i></div>`).join("")
        : `<div style="grid-column:1/-1;text-align:center;padding:16px;font-size:12px;color:var(--text-muted)">Tidak ditemukan</div>`;
}
function filterIcons(q) { renderIcons(q?getGroupIcons(activeGroup).filter(i=>i.toLowerCase().includes(q.toLowerCase())):getGroupIcons(activeGroup)); }
function selectIcon(icon) {
    document.getElementById("catIcon").value=icon;
    document.getElementById("iconSelectedName").textContent=icon;
    document.getElementById("iconManual").value=icon;
    document.getElementById("iconPreviewEl").className="fa-solid "+icon;
    renderIcons(getGroupIcons(activeGroup));
}
function applyManualIcon(val) {
    const icon=val.trim(); if(!icon) return;
    document.getElementById("catIcon").value=icon;
    document.getElementById("iconSelectedName").textContent=icon;
    document.getElementById("iconPreviewEl").className="fa-solid "+icon;
}
function toggleIconPicker() {
    const panel=document.getElementById("iconPickerPanel");
    if(panel.style.display!=="none"){panel.style.display="none";}
    else{panel.style.display="block";renderGroupTabs();renderIcons(getGroupIcons(activeGroup));document.getElementById("iconSearch").focus();}
}
document.getElementById("catModal").addEventListener("click",function(e){
    const panel=document.getElementById("iconPickerPanel");
    const btn=document.getElementById("btnTogglePicker");
    const box=document.getElementById("iconPreviewBox");
    if(panel&&panel.style.display!=="none"&&!panel.contains(e.target)&&!btn.contains(e.target)&&!box.contains(e.target))
        panel.style.display="none";
});

function openAddModal(type = "expense", parentId = null, parentName = null) {
    document.getElementById("catModalTitle").textContent = "Tambah Kategori";
    document.getElementById("catId").value   = "";
    document.getElementById("catName").value = "";
    document.getElementById("catType").value = type;
    document.getElementById("iconPickerPanel").style.display = "none";
    activeGroup = "Semua";
    selectIcon("fa-tag");
    updatePreviewColor();
    updateParentOptions(parentId);
    catModal.show();
}

function openEditModal(c) {
    document.getElementById("catModalTitle").textContent = "Edit Kategori";
    document.getElementById("catId").value   = c.id;
    document.getElementById("catName").value = c.name;
    document.getElementById("catType").value = c.type;
    document.getElementById("iconPickerPanel").style.display = "none";
    activeGroup = "Semua";
    selectIcon(c.icon ?? "fa-tag");
    updatePreviewColor();
    updateParentOptions(c.parent_id);
    catModal.show();
}

async function saveCategory() {
    const id       = document.getElementById("catId").value;
    const name     = document.getElementById("catName").value.trim();
    const type     = document.getElementById("catType").value;
    const icon     = document.getElementById("catIcon").value.trim() || "fa-tag";
    const parentId = document.getElementById("catParent").value || null;
    if (!name) { alert("Nama kategori wajib diisi."); return; }

    const method = id ? "PUT" : "POST";
    const url    = id ? `/api/categories.php?id=${id}` : "/api/categories.php";
    const res    = await fetch(url, { method, headers:{"Content-Type":"application/json"},
        body: JSON.stringify({ name, type, icon, parent_id: parentId ? parseInt(parentId) : null }) });
    const data   = await res.json();
    if (data.success) { catModal.hide(); location.reload(); }
    else alert(data.message ?? "Gagal menyimpan.");
}

async function deleteCategory(id, name) {
    if (!confirm(`Hapus kategori "${name}"?`)) return;
    const res  = await fetch(`/api/categories.php?id=${id}`, { method: "DELETE" });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message ?? "Gagal menghapus.");
}
<?php
echo "</script>\n";
$extraScript = ob_get_clean();

require_once __DIR__ . '/layout/footer.php';
?>
