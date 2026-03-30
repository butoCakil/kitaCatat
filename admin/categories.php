<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Kategori Global';
require_once __DIR__ . '/layout/header.php';

$db = getDB();

$stmt = $db->prepare(
    "SELECT c.*,
        -- Jumlah transaksi langsung ke kategori ini
        (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS usage_count,
        -- Jumlah sub-kategori user yang berinduk ke kategori ini
        (SELECT COUNT(*) FROM categories sc WHERE sc.parent_id = c.id) AS child_count,
        -- Jumlah transaksi ke semua sub-kategori user
        (SELECT COUNT(*) FROM transactions t
         JOIN categories sc ON sc.id = t.category_id
         WHERE sc.parent_id = c.id AND t.deleted_at IS NULL) AS child_usage_count
     FROM categories c
     WHERE c.is_default = 1 AND c.user_id IS NULL
     ORDER BY c.type, c.name"
);
$stmt->execute();
$categories = $stmt->fetchAll();
$expenses   = array_filter($categories, fn($c) => $c['type']==='expense');
$incomes    = array_filter($categories, fn($c) => $c['type']==='income');

// Ambil sub-kategori user per parent_id untuk keperluan collapsible
$subStmt = $db->prepare(
    "SELECT c.*, u.name AS user_name, u.wa_number,
        (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id AND t.deleted_at IS NULL) AS usage_count
     FROM categories c
     JOIN users u ON u.id = c.user_id
     WHERE c.parent_id IS NOT NULL AND c.is_default = 0
     ORDER BY c.parent_id, c.name"
);
$subStmt->execute();
$allSubCats = $subStmt->fetchAll();

// Kelompokkan sub-kategori per parent_id
$subByParent = [];
foreach ($allSubCats as $s) {
    $subByParent[$s['parent_id']][] = $s;
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Kategori Global</h5>
        <p class="text-muted mb-0" style="font-size:13px">Kategori default yang tersedia untuk semua user</p>
    </div>
    <button class="btn btn-sm btn-danger" onclick="openAddModal()">
        <i class="fa-solid fa-plus me-1"></i>Tambah Kategori
    </button>
</div>

<div class="alert mb-4" style="background:#fef9c3;border:1px solid #fef08a;border-radius:var(--radius);font-size:13px;color:#854d0e">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>
    Perubahan kategori global berdampak ke <strong>semua user</strong>. Hapus kategori yang masih dipakai transaksi akan mengubah kategori transaksi tersebut menjadi kosong.
</div>

<div class="row g-3">
<?php foreach ([
    ['label'=>'Pengeluaran','cats'=>$expenses,'color'=>'#dc2626','bg'=>'#fee2e2'],
    ['label'=>'Pemasukan',  'cats'=>$incomes, 'color'=>'#16a34a','bg'=>'#dcfce7'],
] as $col): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <span style="width:8px;height:8px;background:<?= $col['color'] ?>;border-radius:50%;display:inline-block"></span>
                <?= $col['label'] ?>
                <span class="ms-auto text-muted" style="font-size:11px"><?= count($col['cats']) ?> kategori</span>
            </div>
            <div class="card-body p-0">
                <?php foreach ($col['cats'] as $cat):
                    $subs        = $subByParent[$cat['id']] ?? [];
                    $totalUsage  = (int)$cat['usage_count'] + (int)$cat['child_usage_count'];
                    $hasChildren = count($subs) > 0;
                ?>
                <!-- Kategori Global -->
                <div class="border-bottom">
                    <div class="d-flex align-items-center justify-content-between px-4 py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:36px;height:36px;background:<?= $col['bg'] ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;color:<?= $col['color'] ?>">
                                <i class="fa-solid <?= htmlspecialchars($cat['icon']??'fa-tag') ?>"></i>
                            </div>
                            <div>
                                <div style="font-size:13px;font-weight:600"><?= htmlspecialchars($cat['name']) ?></div>
                                <div style="font-size:11px;color:var(--text-muted)">
                                    
                                    <?= number_format($totalUsage) ?> transaksi
                                    </span>
                                    <?php if ($hasChildren): ?>
                                    &nbsp;·&nbsp;
                                    <span style="color:<?= $col['color'] ?>;font-weight:600">
                                        <?= count($subs) ?> sub
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-1 align-items-center">
                            <?php if ($hasChildren): ?>
                            <button class="btn btn-sm btn-outline-secondary"
                                style="padding:3px 8px;font-size:11px"
                                onclick="toggleSub(<?= $cat['id'] ?>)"
                                title="Lihat sub-kategori">
                                <i class="fa-solid fa-layer-group" id="subIcon<?= $cat['id'] ?>"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" style="padding:3px 8px;font-size:11px"
                                onclick='openEditModal(<?= htmlspecialchars(json_encode($cat)) ?>)'>
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" style="padding:3px 8px;font-size:11px"
                                onclick="deleteCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>', <?= $totalUsage ?>, <?= count($subs) ?>)">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>

                    <?php if ($hasChildren): ?>
                    <!-- Sub-kategori user (collapsible) -->
                    <div id="sub<?= $cat['id'] ?>" style="display:none;border-top:1px dashed #e2e8f0;background:#fafbfc">
                        <?php foreach ($subs as $sub): ?>
                        <div class="d-flex align-items-center justify-content-between border-bottom"
                             style="padding:8px 16px 8px 56px">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-turn-down-right text-muted" style="font-size:10px;opacity:.35"></i>
                                <div style="width:26px;height:26px;background:<?= $col['bg'] ?>;border-radius:6px;
                                    display:flex;align-items:center;justify-content:center;font-size:11px;color:<?= $col['color'] ?>">
                                    <i class="fa-solid <?= htmlspecialchars($sub['icon']??'fa-tag') ?>"></i>
                                </div>
                                <div>
                                    <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($sub['name']) ?></span>
                                    <span style="font-size:10.5px;color:var(--text-muted);margin-left:6px">
                                        <?= htmlspecialchars($sub['user_name']) ?>
                                        · <?= number_format($sub['usage_count']) ?> trx
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div style="padding:6px 16px 6px 56px">
                            <span style="font-size:10.5px;color:var(--text-muted)">
                                Total sub-kategori: <?= count($subs) ?> dari <?= count(array_unique(array_column($subs,'user_id'))) ?> user
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
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
                <h6 class="modal-title fw-bold" id="catModalTitle">Tambah Kategori Global</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <input type="hidden" id="catId">

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Tipe</label>
                    <select id="catType" class="form-select" onchange="updatePreviewColor()">
                        <option value="expense">Pengeluaran</option>
                        <option value="income">Pemasukan</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Nama Kategori</label>
                    <input type="text" id="catName" class="form-control"
                           placeholder="Contoh: Transportasi, Bonus Tahunan" maxlength="50">
                </div>

                <!-- Icon Picker -->
                <div class="mb-1">
                    <label class="form-label" style="font-size:12px;font-weight:600">Icon</label>

                    <div class="d-flex gap-2 align-items-center mb-2">
                        <!-- Preview box -->
                        <div id="iconPreviewBox"
                            style="width:46px;height:46px;border-radius:12px;display:flex;align-items:center;
                                   justify-content:center;font-size:20px;flex-shrink:0;
                                   background:#fee2e2;color:#dc2626;transition:all .2s">
                            <i class="fa-solid fa-tag" id="iconPreviewEl"></i>
                        </div>
                        <div style="flex:1">
                            <button type="button" id="btnTogglePicker"
                                class="btn btn-sm btn-outline-secondary w-100 text-start"
                                onclick="toggleIconPicker()"
                                style="font-size:12px;padding:7px 12px">
                                <i class="fa-solid fa-grid-2 me-2"></i>
                                <span id="iconSelectedName" style="font-family:var(--font-mono);color:var(--text-secondary)">fa-tag</span>
                                <i class="fa-solid fa-chevron-down ms-auto float-end mt-1" style="font-size:10px;opacity:.5"></i>
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="catIcon" value="fa-tag">

                    <!-- Icon Picker Panel -->
                    <div id="iconPickerPanel" style="display:none;border:1px solid var(--card-border);
                        border-radius:var(--radius-sm);background:#fff;overflow:hidden;margin-top:4px">

                        <!-- Search + filter group -->
                        <div style="padding:10px 12px;border-bottom:1px solid var(--card-border);background:#f8fafc">
                            <input type="text" id="iconSearch" class="form-control form-control-sm mb-2"
                                   placeholder="&#xf002; Cari nama icon..." oninput="filterIcons(this.value)"
                                   style="font-size:12px">
                            <div id="groupTabs" style="display:flex;gap:4px;flex-wrap:wrap"></div>
                        </div>

                        <!-- Grid -->
                        <div id="iconGrid"
                            style="display:grid;grid-template-columns:repeat(9,1fr);gap:3px;
                                   padding:10px 12px;max-height:220px;overflow-y:auto"></div>

                        <!-- Footer: input manual -->
                        <div style="padding:8px 12px;border-top:1px solid var(--card-border);background:#f8fafc;
                                    display:flex;align-items:center;gap:8px">
                            <span style="font-size:11px;color:var(--text-muted);white-space:nowrap">Manual:</span>
                            <input type="text" id="iconManual" class="form-control form-control-sm"
                                   placeholder="fa-..." style="font-size:12px;font-family:var(--font-mono)"
                                   oninput="applyManualIcon(this.value)">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top" style="padding:14px 20px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-danger" id="btnSave" onclick="saveCategory()">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script>
const catModal = new bootstrap.Modal(document.getElementById('catModal'));

// ============================================================
// Icon data — dikelompokkan per kategori
// ============================================================
const ICON_GROUPS = {
    "Semua": null, // null = tampilkan semua
    "🍔 Makanan": [
        "fa-utensils","fa-burger","fa-pizza-slice","fa-ice-cream","fa-cake-candles",
        "fa-apple-whole","fa-egg","fa-fish","fa-drumstick-bite","fa-bread-slice",
        "fa-coffee","fa-mug-hot","fa-wine-glass","fa-martini-glass","fa-lemon",
        "fa-carrot","fa-pepper-hot","fa-bowl-rice","fa-bowl-food","fa-jar",
    ],
    "🚗 Transport": [
        "fa-car","fa-motorcycle","fa-bus","fa-train","fa-plane",
        "fa-taxi","fa-truck","fa-bicycle","fa-ship","fa-rocket",
        "fa-gas-pump","fa-road","fa-map-location-dot","fa-route","fa-parking",
    ],
    "🛒 Belanja": [
        "fa-shopping-bag","fa-shopping-cart","fa-store","fa-bag-shopping",
        "fa-shirt","fa-shoe-prints","fa-glasses","fa-hat-cowboy","fa-ring",
        "fa-gem","fa-scissors","fa-brush","fa-palette","fa-wand-magic-sparkles",
    ],
    "🏠 Rumah": [
        "fa-house","fa-building","fa-couch","fa-bed","fa-toilet",
        "fa-door-open","fa-window-maximize","fa-hammer","fa-wrench","fa-screwdriver-wrench",
        "fa-bolt","fa-droplet","fa-fire","fa-snowflake","fa-fan",
        "fa-tv","fa-blender","fa-plug","fa-lightbulb","fa-lock",
    ],
    "💊 Kesehatan": [
        "fa-heart-pulse","fa-syringe","fa-pills","fa-tooth","fa-eye",
        "fa-ear","fa-lungs","fa-hospital","fa-stethoscope","fa-kit-medical",
        "fa-dumbbell","fa-person-walking","fa-spa","fa-leaf","fa-seedling",
    ],
    "📚 Pendidikan": [
        "fa-graduation-cap","fa-book","fa-book-open","fa-pencil","fa-pen",
        "fa-ruler","fa-calculator","fa-laptop","fa-chalkboard","fa-school",
        "fa-microscope","fa-flask","fa-atom","fa-globe","fa-language",
    ],
    "💰 Keuangan": [
        "fa-money-bill-wave","fa-coins","fa-piggy-bank","fa-wallet","fa-credit-card",
        "fa-receipt","fa-file-invoice","fa-chart-line","fa-chart-bar","fa-chart-pie",
        "fa-bank","fa-briefcase","fa-handshake","fa-percent","fa-arrow-trend-up",
        "fa-arrow-trend-down","fa-sack-dollar","fa-landmark","fa-vault","fa-dollar-sign",
    ],
    "🎮 Hiburan": [
        "fa-film","fa-music","fa-gamepad","fa-headphones","fa-camera",
        "fa-image","fa-ticket","fa-star","fa-trophy","fa-medal",
        "fa-dice","fa-chess","fa-puzzle-piece","fa-paintbrush","fa-microphone",
        "fa-guitar","fa-drum","fa-tv","fa-podcast","fa-radio",
    ],
    "👨‍👩 Sosial": [
        "fa-gift","fa-heart","fa-hand-holding-heart","fa-people-group","fa-users",
        "fa-user-tie","fa-children","fa-baby","fa-dog","fa-cat",
        "fa-tree","fa-sun","fa-umbrella","fa-balloon","fa-party-horn",
        "fa-champagne-glasses","fa-cake-candles","fa-flower","fa-hand-sparkles","fa-person",
    ],
    "📦 Lainnya": [
        "fa-tag","fa-ellipsis","fa-box","fa-boxes-stacked","fa-archive",
        "fa-paper-plane","fa-envelope","fa-phone","fa-mobile","fa-wifi",
        "fa-print","fa-fax","fa-clipboard","fa-note-sticky","fa-folder",
        "fa-cloud","fa-shield-halved","fa-key","fa-crown","fa-circle-info",
    ],
};

// Flatten semua icon untuk mode "Semua"
const ALL_ICONS = [...new Set(Object.entries(ICON_GROUPS)
    .filter(([k]) => k !== 'Semua')
    .flatMap(([, v]) => v))];

let activeGroup = 'Semua';

// ============================================================
// Render grup tab
// ============================================================
function renderGroupTabs() {
    const container = document.getElementById('groupTabs');
    container.innerHTML = Object.keys(ICON_GROUPS).map(group => `
        <button type="button" onclick="setGroup('${group.replace(/'/g,"\\'")}');event.stopPropagation()"
            style="font-size:11px;padding:3px 9px;border-radius:20px;border:1.5px solid;cursor:pointer;
                   transition:all .15s;white-space:nowrap;
                   ${activeGroup === group
                       ? 'background:var(--admin-primary);color:#fff;border-color:var(--admin-primary)'
                       : 'background:#fff;color:var(--text-secondary);border-color:var(--card-border)'}"
        >${group}</button>
    `).join('');
}

function setGroup(group) {
    activeGroup = group;
    document.getElementById('iconSearch').value = '';
    renderGroupTabs();
    renderIcons(getGroupIcons(group));
}

function getGroupIcons(group) {
    return group === 'Semua' ? ALL_ICONS : (ICON_GROUPS[group] ?? ALL_ICONS);
}

// ============================================================
// Render icon grid
// ============================================================
function renderIcons(list) {
    const currentIcon = document.getElementById('catIcon').value;
    const type        = document.getElementById('catType').value;
    const activeCol   = type === 'income' ? '#16a34a' : '#dc2626';
    const activeBg    = type === 'income' ? '#dcfce7' : '#fee2e2';

    document.getElementById('iconGrid').innerHTML = list.length
        ? list.map(icon => {
            const isActive = icon === currentIcon;
            return `<div onclick="selectIcon('${icon}');event.stopPropagation()" title="${icon}"
                style="aspect-ratio:1;border-radius:8px;display:flex;align-items:center;justify-content:center;
                       font-size:14px;cursor:pointer;transition:all .12s;
                       ${isActive
                           ? `background:${activeBg};color:${activeCol};border:2px solid ${activeCol}`
                           : 'background:#f8fafc;color:#64748b;border:2px solid transparent'}">
                <i class="fa-solid ${icon}"></i>
            </div>`;
        }).join('')
        : '<div style="grid-column:1/-1;text-align:center;padding:16px;font-size:12px;color:var(--text-muted)">Icon tidak ditemukan</div>';
}

function filterIcons(q) {
    const base = getGroupIcons(activeGroup);
    renderIcons(q ? base.filter(i => i.toLowerCase().includes(q.toLowerCase())) : base);
}

// ============================================================
// Pilih icon
// ============================================================
function selectIcon(icon) {
    document.getElementById('catIcon').value            = icon;
    document.getElementById('iconSelectedName').textContent = icon;
    document.getElementById('iconManual').value         = icon;
    updatePreviewIcon(icon);
    renderIcons(getGroupIcons(activeGroup)); // refresh highlight
}

function applyManualIcon(val) {
    const icon = val.trim();
    if (!icon) return;
    document.getElementById('catIcon').value            = icon;
    document.getElementById('iconSelectedName').textContent = icon;
    updatePreviewIcon(icon);
}

function updatePreviewIcon(icon) {
    document.getElementById('iconPreviewEl').className = 'fa-solid ' + icon;
}

function updatePreviewColor() {
    const type  = document.getElementById('catType').value;
    const box   = document.getElementById('iconPreviewBox');
    const btn   = document.getElementById('btnSave');
    if (type === 'income') {
        box.style.background = '#dcfce7';
        box.style.color      = '#16a34a';
        btn.className        = 'btn btn-sm btn-success';
    } else {
        box.style.background = '#fee2e2';
        box.style.color      = '#dc2626';
        btn.className        = 'btn btn-sm btn-danger';
    }
    // Refresh grid agar warna aktif ikut berubah
    const q = document.getElementById('iconSearch').value;
    filterIcons(q);
}

// ============================================================
// Toggle picker
// ============================================================
function toggleIconPicker() {
    const panel = document.getElementById('iconPickerPanel');
    const isOpen = panel.style.display !== 'none';
    if (isOpen) {
        panel.style.display = 'none';
    } else {
        panel.style.display = 'block';
        renderGroupTabs();
        renderIcons(getGroupIcons(activeGroup));
        document.getElementById('iconSearch').focus();
    }
}

// Tutup picker saat klik di luar modal-body
document.getElementById('catModal').addEventListener('click', function(e) {
    const panel  = document.getElementById('iconPickerPanel');
    const btn    = document.getElementById('btnTogglePicker');
    const box    = document.getElementById('iconPreviewBox');
    if (panel && panel.style.display !== 'none'
        && !panel.contains(e.target)
        && !btn.contains(e.target)
        && !box.contains(e.target)) {
        panel.style.display = 'none';
    }
});

// ============================================================
// Modal open/close
// ============================================================
function openAddModal() {
    document.getElementById('catModalTitle').textContent = 'Tambah Kategori Global';
    document.getElementById('catId').value   = '';
    document.getElementById('catName').value = '';
    document.getElementById('catType').value = 'expense';
    document.getElementById('iconPickerPanel').style.display = 'none';
    activeGroup = 'Semua';
    selectIcon('fa-tag');
    updatePreviewColor();
    catModal.show();
}

function openEditModal(c) {
    document.getElementById('catModalTitle').textContent = 'Edit Kategori';
    document.getElementById('catId').value   = c.id;
    document.getElementById('catName').value = c.name;
    document.getElementById('catType').value = c.type;
    document.getElementById('iconPickerPanel').style.display = 'none';
    activeGroup = 'Semua';
    selectIcon(c.icon || 'fa-tag');
    updatePreviewColor();
    catModal.show();
}

// ============================================================
// Save & Delete
// ============================================================
// Toggle sub-kategori collapsible
function toggleSub(id) {
    const el   = document.getElementById('sub' + id);
    const icon = document.getElementById('subIcon' + id);
    if (el.style.display === 'none') {
        el.style.display = 'block';
        icon.className   = 'fa-solid fa-layer-group';
        icon.style.color = 'var(--admin-primary)';
    } else {
        el.style.display = 'none';
        icon.style.color = '';
    }
}

async function saveCategory() {
    const id   = document.getElementById('catId').value;
    const name = document.getElementById('catName').value.trim();
    const type = document.getElementById('catType').value;
    const icon = document.getElementById('catIcon').value.trim() || 'fa-tag';
    if (!name) { alert('Nama kategori wajib diisi.'); return; }
    const res  = await fetch('/api/admin/categories.php', {
        method : 'POST',
        headers: {'Content-Type':'application/json'},
        body   : JSON.stringify({ action: id ? 'update' : 'create', id, name, type, icon })
    });
    const data = await res.json();
    if (data.success) { catModal.hide(); location.reload(); }
    else alert(data.message || 'Gagal.');
}

async function deleteCategory(id, name, usage, childCount) {
    let msg = `Hapus kategori "${name}"?`;
    if (usage > 0)      msg += `\n\n⚠️ Kategori ini (termasuk sub-kategorinya) dipakai ${usage} transaksi. Transaksi tersebut akan kehilangan kategorinya.`;
    if (childCount > 0) msg += `\n\n📂 Terdapat ${childCount} sub-kategori user yang berinduk ke kategori ini. Sub-kategori tersebut akan menjadi "tanpa induk" (tidak dihapus).`;
    if (!confirm(msg)) return;
    const res  = await fetch('/api/admin/categories.php', {
        method : 'POST',
        headers: {'Content-Type':'application/json'},
        body   : JSON.stringify({ action: 'delete', id })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
