<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Broadcast WA';
require_once __DIR__ . '/layout/header.php';

$db      = getDB();
$adminId = (int)$_SESSION['admin_id'];

// Hitung jumlah user aktif
$totalActive = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

// Riwayat broadcast (dari admin_logs)
$logs = $db->query(
    "SELECT al.*, a.name AS admin_name FROM admin_logs al
     JOIN admins a ON a.id = al.admin_id
     WHERE al.action = 'broadcast.wa'
     ORDER BY al.created_at DESC LIMIT 10"
)->fetchAll();
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="mb-0 fw-bold">Broadcast WhatsApp</h5>
        <p class="text-muted mb-0" style="font-size:13px">Kirim pesan ke semua atau user tertentu</p>
    </div>
</div>

<div class="row g-3">
    <!-- Fungsi preview inline agar tersedia saat textarea diisi -->
<script>
function updatePreview(v) {
    var c = document.getElementById("charCount");
    var p = document.getElementById("msgPreview");
    if (c) c.textContent = (v ? v.length : 0) + " karakter";
    if (!p) return;
    if (!v || !v.trim()) { p.innerHTML = "<span style=\"color:#94a3b8\">&#8212;</span>"; return; }
    var h = v.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");
    h = h.replace(/\u002A([^\u002A\n]+)\u002A/g,"<strong>$1</strong>");
    h = h.replace(/\u005F([^\u005F\n]+)\u005F/g,"<em>$1</em>");
    h = h.replace(/\u007E([^\u007E\n]+)\u007E/g,"<del>$1</del>");
    h = h.replace(/\n/g,"<br>");
    p.innerHTML = h;
}
</script>

<!-- Form Broadcast -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-bullhorn me-2 text-muted"></i>Kirim Pesan</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Target Penerima</label>
                    <select id="targetType" class="form-select" onchange="toggleTargetInput()">
                        <option value="all">Semua User Aktif (<?= $totalActive ?> user)</option>
                        <option value="specific">Nomor WA Tertentu</option>
                    </select>
                </div>

                <div id="specificInput" style="display:none" class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Nomor WA Target</label>
                    <textarea id="specificNumbers" class="form-control form-control-sm" rows="3"
                              placeholder="Satu nomor per baris, format 628xxx&#10;6281234567890&#10;6289876543210"
                              style="font-family:var(--font-mono);font-size:12px"></textarea>
                    <div class="form-text">Satu nomor WA per baris. Hanya nomor yang terdaftar di KitaCatat yang akan menerima.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Pesan</label>
                    <textarea id="broadcastMsg" class="form-control" rows="6"
                              placeholder="Tulis pesan broadcast di sini...&#10;&#10;Mendukung format WA: *bold*, _italic_"
                              oninput="updatePreview(this.value)"></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <div class="form-text">Format WhatsApp didukung: *tebal*, _miring_</div>
                        <div class="form-text" id="charCount">0 karakter</div>
                    </div>
                </div>

                <div class="mb-4">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">Preview Pesan WA:</div>
                    <div style="background:#e5ddd5;background-image:url('data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23c9b99a' fill-opacity='0.15'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E');border-radius:12px;padding:16px;min-height:80px">
                        <div style="max-width:80%;background:#fff;border-radius:12px 12px 12px 4px;padding:10px 14px;box-shadow:0 1px 2px rgba(0,0,0,.1);display:inline-block;min-width:120px">
                            <div style="font-size:12px;color:#25D366;font-weight:700;margin-bottom:4px">
                                <i class="fa-solid fa-shield-halved me-1" style="font-size:10px"></i>Admin KitaCatat
                            </div>
                            <div id="msgPreview" style="font-size:13px;color:#1a1a1a;line-height:1.55;min-height:20px">
                                <span style="color:#94a3b8">—</span>
                            </div>
                            <div style="font-size:10px;color:#94a3b8;text-align:right;margin-top:4px">
                                <?= date('H:i') ?> ✓✓
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert" style="background:#fef9c3;border:1px solid #fef08a;border-radius:8px;font-size:13px;color:#854d0e">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    Broadcast akan dikirim satu per satu melalui Fonnte. Pastikan pesan sudah benar sebelum mengirim.
                </div>

                <button class="btn btn-danger w-100 fw-bold" onclick="sendBroadcast()">
                    <i class="fa-solid fa-paper-plane me-2"></i>Kirim Broadcast
                </button>
            </div>
        </div>
    </div>

    <!-- Riwayat Broadcast -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-clock-rotate-left me-2 text-muted"></i>Riwayat Broadcast</div>
            <div class="card-body p-0">
                <?php if (empty($logs)): ?>
                <div class="text-center text-muted py-4" style="font-size:13px">Belum ada broadcast</div>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <div class="px-4 py-3 border-bottom">
                    <div style="font-size:12.5px;font-weight:600"><?= htmlspecialchars($log['target'] ?? '') ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:2px;white-space:pre-line"><?= htmlspecialchars(substr($log['note'] ?? '', 0, 80)) ?><?= strlen($log['note']??'')>80?'...':'' ?></div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:4px">
                        <?= htmlspecialchars($log['admin_name']) ?> · <?= date('d M Y H:i', strtotime($log['created_at'])) ?>
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
<script>
function toggleTargetInput() {
    const t = document.getElementById('targetType').value;
    document.getElementById('specificInput').style.display = t === 'specific' ? '' : 'none';
}



async function sendBroadcast() {
    const targetType = document.getElementById('targetType').value;
    const message    = document.getElementById('broadcastMsg').value.trim();
    const specific   = document.getElementById('specificNumbers').value.trim();

    if (!message) { alert('Pesan tidak boleh kosong.'); return; }

    let targets = [];
    if (targetType === 'specific') {
        targets = specific.split('\n').map(s => s.trim()).filter(s => s.length > 0);
        if (targets.length === 0) { alert('Masukkan minimal satu nomor WA.'); return; }
    }

    const confirmMsg = targetType === 'all'
        ? 'Kirim broadcast ke SEMUA user aktif?'
        : 'Kirim broadcast ke ' + targets.length + ' nomor?';

    if (!confirm(confirmMsg + '\n\nPesan:\n' + message.substring(0, 100) + (message.length > 100 ? '...' : ''))) return;

    const btn = document.querySelector('button.btn-danger');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Mengirim...';

    const res  = await fetch('/api/admin/broadcast.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ target_type: targetType, targets, message })
    });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-2"></i>Kirim Broadcast';

    if (data.success) {
        alert('✅ Broadcast berhasil dikirim ke ' + (data.sent || 0) + ' user.');
        location.reload();
    } else {
        alert('❌ ' + (data.message || 'Gagal mengirim broadcast.'));
    }
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
