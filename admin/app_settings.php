<?php
// ============================================================
// KitaCatat — Admin: App Settings
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'App Settings';
require_once __DIR__ . '/layout/header.php';

$db      = getDB();
$message = '';
$msgType = '';

// ── Handle POST: simpan setting ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings'])) {
    foreach ($_POST['settings'] as $key => $value) {
        $key   = preg_replace('/[^a-z0-9_]/', '', $key); // sanitasi key
        $value = trim($value);

        // Jika field secret dikosongkan, skip (jangan overwrite dengan kosong)
        $checkSecret = $db->prepare("SELECT is_secret FROM app_settings WHERE `key` = ?");
        $checkSecret->execute([$key]);
        $row = $checkSecret->fetch();
        if ($row && $row['is_secret'] && $value === '') continue;

        $db->prepare(
            "UPDATE app_settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?"
        )->execute([$value, $key]);
    }

    // Log aksi admin
    $db->prepare(
        "INSERT INTO admin_logs (admin_id, action, target, note, ip)
         VALUES (?, 'settings.update', 'app_settings', 'App settings diperbarui', ?)"
    )->execute([$_SESSION['admin_id'], $_SERVER['REMOTE_ADDR'] ?? '']);

    $message = 'Pengaturan berhasil disimpan.';
    $msgType = 'success';
}

// ── Ambil semua setting ──────────────────────────────────────
$settings = $db->query("SELECT * FROM app_settings ORDER BY id ASC")->fetchAll();
?>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-4" role="alert" style="font-size:13px;border-radius:10px">
    <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-sliders me-2 text-muted"></i>Pengaturan Aplikasi
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php foreach ($settings as $s): ?>
                    <div class="mb-4">
                        <label class="form-label" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">
                            <?= htmlspecialchars($s['label']) ?>
                        </label>

                        <?php if ($s['is_secret']): ?>
                        <!-- Secret field: tampil sebagai password, ada toggle show/hide -->
                        <div class="input-group">
                            <input type="password"
                                   name="settings[<?= htmlspecialchars($s['key']) ?>]"
                                   id="field_<?= htmlspecialchars($s['key']) ?>"
                                   class="form-control"
                                   style="font-family:var(--font-mono);font-size:13px"
                                   placeholder="Kosongkan untuk tidak mengubah"
                                   autocomplete="off">
                            <button type="button"
                                    class="btn btn-outline-secondary"
                                    onclick="toggleSecret('<?= htmlspecialchars($s['key']) ?>')"
                                    style="font-size:12px;border-radius:0 8px 8px 0">
                                <i class="fa-regular fa-eye" id="eye_<?= htmlspecialchars($s['key']) ?>"></i>
                            </button>
                        </div>
                        <div style="font-size:11px;color:#94a3b8;margin-top:4px">
                            <i class="fa-solid fa-lock me-1"></i>
                            Nilai tersimpan tidak ditampilkan. Kosongkan field untuk tidak mengubah.
                        </div>
                        <?php else: ?>
                        <input type="text"
                               name="settings[<?= htmlspecialchars($s['key']) ?>]"
                               class="form-control"
                               style="font-size:13px"
                               value="<?= htmlspecialchars($s['value'] ?? '') ?>">
                        <?php endif; ?>

                        <?php if ($s['description']): ?>
                        <div class="form-text" style="font-size:11.5px">
                            <?= htmlspecialchars($s['description']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($s['updated_at']): ?>
                        <div style="font-size:10.5px;color:#94a3b8;margin-top:3px">
                            <i class="fa-regular fa-clock me-1"></i>
                            Terakhir diubah: <?= date('d M Y, H:i', strtotime($s['updated_at'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <hr style="border-color:var(--card-border)">
                    <button type="submit" class="btn btn-sm" style="background:var(--admin-primary);color:#fff;border:none;border-radius:8px;padding:8px 20px;font-size:13px;font-weight:600">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Simpan Pengaturan
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Info panel kanan -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-circle-info me-2 text-muted"></i>Info</div>
            <div class="card-body" style="font-size:13px;color:var(--text-secondary);line-height:1.7">
                <p class="mb-2">Pengaturan ini berlaku untuk seluruh sistem.</p>
                <p class="mb-2"><strong>bot_wa_number</strong> akan ditampilkan ke user di halaman Pengaturan Akun sebagai tombol WhatsApp.</p>
                <p class="mb-2"><strong>fonnte_token</strong> digunakan untuk mengirim pesan WA. Perubahan langsung berlaku tanpa restart.</p>
                <p class="mb-0">Field bertanda <i class="fa-solid fa-lock" style="font-size:11px"></i> tidak menampilkan nilai tersimpan demi keamanan.</p>
            </div>
        </div>

        <?php
        $webhookSecret  = getAppSetting('webhook_secret', '');
        $webhookBaseUrl = APP_URL . '/webhook/receive.php';
        $webhookFullUrl = $webhookBaseUrl . ($webhookSecret ? '?token=' . $webhookSecret : '');
        ?>
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-link me-2 text-muted"></i>Webhook URL</div>
            <div class="card-body">
                <div style="font-family:var(--font-mono);font-size:11.5px;background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid var(--card-border);word-break:break-all;color:var(--text-secondary)">
                    <?= htmlspecialchars($webhookBaseUrl) ?>
                    <?php if ($webhookSecret): ?>
                    <span style="color:#94a3b8">?token=</span><span style="color:#f87171;filter:blur(4px);user-select:none;transition:filter .2s" id="tokenBlur"><?= htmlspecialchars($webhookSecret) ?></span>
                    <button type="button" onclick="toggleToken()" style="background:none;border:none;cursor:pointer;padding:0 4px;font-size:11px;color:#94a3b8" title="Tampilkan/sembunyikan token">
                        <i class="fa-regular fa-eye" id="eyeToken"></i>
                    </button>
                    <?php else: ?>
                    <span style="color:#f87171"> ⚠ token belum diset</span>
                    <?php endif; ?>
                </div>
                <div class="form-text mt-2" style="font-size:11px">
                    Daftarkan URL ini di Fonnte → Dashboard → Devices → Webhook
                </div>
                <button type="button"
                        onclick="copyWebhook()"
                        class="btn btn-sm mt-2"
                        style="background:#f1f5f9;color:var(--text-secondary);border:none;border-radius:6px;font-size:12px;padding:5px 12px">
                    <i class="fa-regular fa-copy me-1"></i><span id="copyLabel">Salin URL</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSecret(key) {
    const input = document.getElementById('field_' + key);
    const eye   = document.getElementById('eye_' + key);
    if (input.type === 'password') {
        input.type = 'text';
        eye.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        eye.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function copyWebhook() {
    const url   = '<?= htmlspecialchars($webhookFullUrl, ENT_QUOTES) ?>';
    const label = document.getElementById('copyLabel');
    navigator.clipboard.writeText(url).then(() => {
        label.textContent = 'Tersalin!';
        setTimeout(() => label.textContent = 'Salin URL', 2000);
    });
}

function toggleToken() {
    const el  = document.getElementById('tokenBlur');
    const eye = document.getElementById('eyeToken');
    if (!el) return;
    if (el.style.filter === 'none') {
        el.style.filter = 'blur(4px)';
        eye.classList.replace('fa-eye-slash', 'fa-eye');
    } else {
        el.style.filter = 'none';
        eye.classList.replace('fa-eye', 'fa-eye-slash');
    }
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>