<?php
// ============================================================
// KitaCatat — Dashboard: Pengingat & Transaksi Rutin
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Pengingat Rutin';
require_once __DIR__ . '/layout/header.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Ambil semua jadwal user
$schedules = $db->prepare(
    "SELECT s.*, c.name AS category_name, c.icon AS category_icon
     FROM scheduled_transactions s
     LEFT JOIN categories c ON c.id = s.category_id
     WHERE s.user_id = ?
     ORDER BY s.is_active DESC, s.next_run ASC"
);
$schedules->execute([$userId]);
$schedules = $schedules->fetchAll();

// Ambil kategori untuk dropdown
$cats = $db->prepare(
    "SELECT id, name, type, icon FROM categories
     WHERE user_id IS NULL OR user_id = ?
     ORDER BY type, name"
);
$cats->execute([$userId]);
$categories = $cats->fetchAll();

$incomeCategories  = array_filter($categories, fn($c) => $c['type'] === 'income');
$expenseCategories = array_filter($categories, fn($c) => $c['type'] === 'expense');

function nextRunLabel(string $date): string {
    $diff = (strtotime($date) - strtotime(date('Y-m-d'))) / 86400;
    if ($diff < 0)  return '<span style="color:#dc2626">Terlambat</span>';
    if ($diff == 0) return '<span style="color:#f59e0b">Hari ini</span>';
    if ($diff == 1) return '<span style="color:#f59e0b">Besok</span>';
    return date('d M Y', strtotime($date));
}

function freqLabel(string $freq): string {
    return match($freq) {
        'once'    => 'Sekali',
        'daily'   => 'Harian',
        'weekly'  => 'Mingguan',
        'monthly' => 'Bulanan',
        'yearly'  => 'Tahunan',
        default   => $freq,
    };
}

function modeLabel(string $mode): string {
    return match($mode) {
        'auto'       => '⚡ Otomatis',
        'confirm'    => '✅ Konfirmasi',
        'ask_amount' => '💬 Tanya nominal',
        default      => $mode,
    };
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Pengingat & Transaksi Rutin</h5>
        <p class="text-muted mb-0" style="font-size:13px">
            Atur pengingat otomatis untuk gaji, tagihan, dan transaksi rutin lainnya
        </p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openAddModal()">
        <i class="fa-solid fa-plus me-1"></i>Tambah Pengingat
    </button>
</div>

<?php if (empty($schedules)): ?>
<!-- Empty state -->
<div class="card">
    <div class="card-body text-center py-5">
        <div style="font-size:48px;margin-bottom:16px">⏰</div>
        <h6 class="fw-bold mb-2">Belum ada pengingat</h6>
        <p class="text-muted mb-4" style="font-size:13px;max-width:360px;margin:0 auto 16px">
            Tambah pengingat untuk gaji, tagihan listrik, wifi, cicilan, atau transaksi rutin lainnya.
            Bot WA akan mengingatkan Anda otomatis sesuai jadwal.
        </p>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()">
            <i class="fa-solid fa-plus me-1"></i>Tambah Pengingat Pertama
        </button>
    </div>
</div>

<?php else: ?>

<!-- Info box -->
<div class="alert mb-4" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;font-size:13px;color:#1e40af">
    <i class="fa-solid fa-circle-info me-2"></i>
    Bot WA akan mengirim pengingat sesuai jadwal. Balas <strong>ya</strong> untuk konfirmasi,
    <strong>tidak</strong> untuk skip, atau <strong>besok</strong> untuk tunda sehari.
</div>

<!-- Daftar jadwal -->
<div class="row g-3">
<?php foreach ($schedules as $s): ?>
<div class="col-12 col-md-6">
    <div class="card <?= $s['is_active'] ? '' : 'opacity-50' ?>" style="border-left:4px solid <?= $s['type']==='income'?'#16a34a':'#dc2626' ?>">
        <div class="card-body pb-3">
            <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex align-items-center gap-3 flex-fill">
                    <div style="width:40px;height:40px;background:<?= $s['type']==='income'?'#dcfce7':'#fee2e2' ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;color:<?= $s['type']==='income'?'#16a34a':'#dc2626' ?>">
                        <i class="fa-solid <?= htmlspecialchars($s['category_icon'] ?? 'fa-clock') ?>"></i>
                    </div>
                    <div class="flex-fill">
                        <div style="font-size:14px;font-weight:700"><?= htmlspecialchars($s['title']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted)">
                            <?= htmlspecialchars($s['category_name'] ?? 'Tanpa kategori') ?>
                            · <?= freqLabel($s['frequency']) ?>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                    <button class="btn btn-sm btn-outline-secondary" style="padding:3px 8px;font-size:11px"
                        onclick='openEditModal(<?= htmlspecialchars(json_encode($s)) ?>)'>
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="btn btn-sm <?= $s['is_active']?'btn-outline-warning':'btn-outline-success' ?>"
                        style="padding:3px 8px;font-size:11px"
                        onclick="toggleSchedule(<?= $s['id'] ?>, <?= $s['is_active'] ?>)"
                        title="<?= $s['is_active']?'Nonaktifkan':'Aktifkan' ?>">
                        <i class="fa-solid <?= $s['is_active']?'fa-pause':'fa-play' ?>"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" style="padding:3px 8px;font-size:11px"
                        onclick="deleteSchedule(<?= $s['id'] ?>, '<?= htmlspecialchars($s['title']) ?>')">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>

            <div class="mt-3 pt-3" style="border-top:1px solid var(--card-border)">
                <div class="row g-2" style="font-size:12px">
                    <div class="col-6">
                        <span class="text-muted">Nominal:</span><br>
                        <strong>
                            <?= $s['amount'] ? 'Rp '.number_format((int)$s['amount'],0,',','.') : '<span class="text-muted fst-italic">Tanya saat konfirmasi</span>' ?>
                        </strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Pengingat berikutnya:</span><br>
                        <strong><?= nextRunLabel($s['next_run']) ?></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Mode:</span><br>
                        <strong><?= modeLabel($s['mode']) ?></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted">Maks. pengingat:</span><br>
                        <strong><?= $s['reminder_max'] ?>x (interval <?= $s['reminder_interval'] ?> hari)</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ============================================================
     MODAL TAMBAH / EDIT
============================================================ -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:14px">
            <div class="modal-header border-bottom" style="padding:16px 20px">
                <h6 class="modal-title fw-bold" id="modalTitle">Tambah Pengingat</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <input type="hidden" id="schedId">

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Nama Pengingat</label>
                        <input type="text" id="schedTitle" class="form-control"
                               placeholder="Contoh: Gaji Bulanan, Tagihan Wifi, Cicilan Motor">
                    </div>

                    <div class="col-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Tipe</label>
                        <select id="schedType" class="form-select" onchange="updateCategoryDropdown()">
                            <option value="income">📈 Pemasukan</option>
                            <option value="expense">📉 Pengeluaran</option>
                        </select>
                    </div>

                    <div class="col-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Kategori</label>
                        <select id="schedCategory" class="form-select"></select>
                    </div>

                    <div class="col-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">
                            Nominal <span class="text-muted fw-normal">(kosongkan = tanya saat konfirmasi)</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text" style="font-size:13px">Rp</span>
                            <input type="number" id="schedAmount" class="form-control"
                                   placeholder="Opsional" min="0">
                        </div>
                    </div>

                    <div class="col-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Frekuensi</label>
                        <select id="schedFrequency" class="form-select" onchange="updateDayFields()">
                            <option value="monthly">Bulanan</option>
                            <option value="weekly">Mingguan</option>
                            <option value="yearly">Tahunan</option>
                            <option value="once">Sekali</option>
                            <option value="daily">Harian</option>
                        </select>
                    </div>

                    <div class="col-6" id="dayOfMonthField">
                        <label class="form-label" style="font-size:12px;font-weight:600">Tanggal (tiap bulan)</label>
                        <select id="schedDayOfMonth" class="form-select">
                            <?php for ($d=1; $d<=28; $d++): ?>
                            <option value="<?= $d ?>">Tanggal <?= $d ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-6" id="dayOfWeekField" style="display:none">
                        <label class="form-label" style="font-size:12px;font-weight:600">Hari (tiap minggu)</label>
                        <select id="schedDayOfWeek" class="form-select">
                            <option value="1">Senin</option>
                            <option value="2">Selasa</option>
                            <option value="3">Rabu</option>
                            <option value="4">Kamis</option>
                            <option value="5">Jumat</option>
                            <option value="6">Sabtu</option>
                            <option value="0">Minggu</option>
                        </select>
                    </div>

                    <div class="col-6" id="onceDateField" style="display:none">
                        <label class="form-label" style="font-size:12px;font-weight:600">Tanggal Pengingat</label>
                        <input type="date" id="schedOnceDate" class="form-control"
                               min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="col-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Mode Konfirmasi</label>
                        <select id="schedMode" class="form-select">
                            <option value="confirm">✅ Tanya konfirmasi dulu</option>
                            <option value="auto">⚡ Langsung catat otomatis</option>
                            <option value="ask_amount">💬 Tanya nominal saat konfirmasi</option>
                        </select>
                    </div>

                    <div class="col-3">
                        <label class="form-label" style="font-size:12px;font-weight:600">Maks. Pengingat</label>
                        <input type="number" id="schedReminderMax" class="form-control"
                               value="3" min="1" max="10">
                    </div>

                    <div class="col-3">
                        <label class="form-label" style="font-size:12px;font-weight:600">Interval (hari)</label>
                        <input type="number" id="schedReminderInterval" class="form-control"
                               value="1" min="1" max="7">
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top" style="padding:14px 20px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="saveSchedule()">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
$incomeCats  = json_encode(array_values($incomeCategories));
$expenseCats = json_encode(array_values($expenseCategories));
?>
<script>
const schedModal    = new bootstrap.Modal(document.getElementById('scheduleModal'));
const incomeCats    = <?= $incomeCats ?>;
const expenseCats   = <?= $expenseCats ?>;

function updateCategoryDropdown() {
    const type = document.getElementById('schedType').value;
    const cats = type === 'income' ? incomeCats : expenseCats;
    const sel  = document.getElementById('schedCategory');
    sel.innerHTML = cats.map(c =>
        `<option value="${c.id}">${c.name}</option>`
    ).join('');
}

function updateDayFields() {
    const freq = document.getElementById('schedFrequency').value;
    document.getElementById('dayOfMonthField').style.display = (freq === 'monthly' || freq === 'yearly') ? '' : 'none';
    document.getElementById('dayOfWeekField').style.display  = freq === 'weekly' ? '' : 'none';
    document.getElementById('onceDateField').style.display   = freq === 'once' ? '' : 'none';
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Tambah Pengingat';
    document.getElementById('schedId').value = '';
    document.getElementById('schedTitle').value = '';
    document.getElementById('schedType').value = 'income';
    document.getElementById('schedAmount').value = '';
    document.getElementById('schedFrequency').value = 'monthly';
    document.getElementById('schedMode').value = 'confirm';
    document.getElementById('schedReminderMax').value = 3;
    document.getElementById('schedReminderInterval').value = 1;
    document.getElementById('schedDayOfMonth').value = new Date().getDate();
    updateCategoryDropdown();
    updateDayFields();
    schedModal.show();
}

function openEditModal(s) {
    document.getElementById('modalTitle').textContent = 'Edit Pengingat';
    document.getElementById('schedId').value              = s.id;
    document.getElementById('schedTitle').value           = s.title;
    document.getElementById('schedType').value            = s.type;
    document.getElementById('schedFrequency').value       = s.frequency;
    document.getElementById('schedMode').value            = s.mode;
    document.getElementById('schedAmount').value          = s.amount || '';
    document.getElementById('schedReminderMax').value     = s.reminder_max;
    document.getElementById('schedReminderInterval').value = s.reminder_interval;
    if (s.day_of_month) document.getElementById('schedDayOfMonth').value = s.day_of_month;
    if (s.day_of_week !== null) document.getElementById('schedDayOfWeek').value = s.day_of_week;
    updateCategoryDropdown();
    // Set kategori setelah dropdown diisi
    setTimeout(() => {
        if (s.category_id) document.getElementById('schedCategory').value = s.category_id;
    }, 50);
    updateDayFields();
    schedModal.show();
}

async function saveSchedule() {
    const id       = document.getElementById('schedId').value;
    const title    = document.getElementById('schedTitle').value.trim();
    const type     = document.getElementById('schedType').value;
    const catId    = document.getElementById('schedCategory').value;
    const amount   = document.getElementById('schedAmount').value;
    const freq     = document.getElementById('schedFrequency').value;
    const mode     = document.getElementById('schedMode').value;
    const domVal   = document.getElementById('schedDayOfMonth').value;
    const dowVal   = document.getElementById('schedDayOfWeek').value;
    const onceDate = document.getElementById('schedOnceDate').value;
    const rMax     = document.getElementById('schedReminderMax').value;
    const rInt     = document.getElementById('schedReminderInterval').value;

    if (!title) { alert('Nama pengingat wajib diisi.'); return; }

    const payload = {
        action: id ? 'update' : 'create', id,
        title, type, category_id: catId,
        amount: amount ? parseInt(amount) : null,
        frequency: freq, mode,
        day_of_month: (freq==='monthly'||freq==='yearly') ? parseInt(domVal) : null,
        day_of_week:  freq==='weekly' ? parseInt(dowVal) : null,
        once_date:    freq==='once'   ? onceDate : null,
        reminder_max: parseInt(rMax),
        reminder_interval: parseInt(rInt),
    };

    const res  = await fetch('/api/scheduled.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) { schedModal.hide(); location.reload(); }
    else alert(data.message || 'Gagal menyimpan.');
}

async function toggleSchedule(id, isActive) {
    const res  = await fetch('/api/scheduled.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'toggle', id })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

async function deleteSchedule(id, title) {
    if (!confirm('Hapus pengingat "' + title + '"?')) return;
    const res  = await fetch('/api/scheduled.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'delete', id })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal.');
}

// Init dropdown
updateCategoryDropdown();
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>