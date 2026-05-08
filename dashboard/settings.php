<?php
// ============================================================
// KitaCatat — Dashboard: Pengaturan Akun
// ============================================================
require_once __DIR__ . '/../config/config.php';

// Mulai session sebelum apapun
if (session_status() === PHP_SESSION_NONE) session_start();

// Guard login
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Ambil data user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$success = '';
$error   = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    // ── Hapus akun (harus sebelum output apapun) ─────────────
    if ($action === 'delete_account') {
        $confirmPass = $_POST['confirm_password_delete'] ?? '';

        if (empty($confirmPass)) {
            $error = 'Masukkan password untuk konfirmasi.';
        } elseif (!password_verify($confirmPass, $user['password'] ?? '')) {
            $error = 'Password tidak tepat.';
        } else {
            $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?")
               ->execute([$userId]);
            session_unset();
            session_destroy();
            header('Location: /account-deleted.php');
            exit;
        }
    }

    // ── Update profil ────────────────────────────────────────
    if ($action === 'profile') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name)) {
            $error = 'Nama wajib diisi.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email tidak valid.';
        } else {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) {
                $error = 'Email sudah digunakan akun lain.';
            } else {
                $emailChanged = ($email !== ($user['email'] ?? ''));
                $db->prepare("UPDATE users SET name = ?, email = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$name, $email, $userId]);
                $_SESSION['user_name'] = $name;
                $success = 'Profil berhasil diperbarui.';

                // Notif jika email berubah
                if ($emailChanged) {
                    sendSecurityNotif($user, 'email_changed', ['new_email' => $email]);
                }

                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            }
        }
    }

    // ── Update password ──────────────────────────────────────
    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            $error = 'Semua field password wajib diisi.';
        } elseif (strlen($new) < 6) {
            $error = 'Password baru minimal 6 karakter.';
        } elseif ($new !== $confirm) {
            $error = 'Konfirmasi password tidak cocok.';
        } elseif (!password_verify($current, $user['password'] ?? '')) {
            $error = 'Password saat ini tidak tepat.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$hash, $userId]);
            $success = 'Password berhasil diperbarui.';
            sendSecurityNotif($user, 'password_changed');
        }
    }
}

// ── Statistik user ───────────────────────────────────────────
$stmtStats = $db->prepare(
    "SELECT
        COUNT(*) AS total_trx,
        SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
        MIN(created_at) AS first_trx
     FROM transactions
     WHERE user_id = ? AND deleted_at IS NULL"
);
$stmtStats->execute([$userId]);
$stats = $stmtStats->fetch();

// ── App settings ─────────────────────────────────────────────
$botWaNumber = getAppSetting('bot_wa_number', '');
$botName     = getAppSetting('bot_name', APP_NAME);
$botWaLink   = $botWaNumber
    ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $botWaNumber)
    : '';

function formatRp(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}

// Error dari delete_account — tandai agar form terbuka kembali
$deleteError = in_array($error, ['Masukkan password untuk konfirmasi.', 'Password tidak tepat.']);

$pageTitle = 'Pengaturan Akun';
require_once __DIR__ . '/layout/header.php';
?>

<?php if ($success): ?>
<div class="alert mb-4" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius);font-size:13px">
    <i class="fa-solid fa-circle-check me-2" style="color:var(--primary)"></i><?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert mb-4" style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius);font-size:13px">
    <i class="fa-solid fa-circle-exclamation me-2 text-danger"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- Kartu Profil -->
    <div class="col-lg-4">
        <div class="card text-center p-4 mb-3">
            <div style="width:72px;height:72px;background:var(--primary);border-radius:50%;
                display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;
                color:#fff;margin:0 auto 12px">
                <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
            </div>
            <div style="font-size:16px;font-weight:700"><?= htmlspecialchars($user['name'] ?? '') ?></div>
            <div style="font-size:12px;color:var(--text-muted);font-family:var(--font-mono)">
                <?= htmlspecialchars($user['wa_number'] ?? '') ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($user['email'] ?? '') ?></div>

            <hr style="border-color:var(--card-border)">

            <div class="row g-2 text-center">
                <div class="col-4">
                    <div style="font-size:18px;font-weight:700;color:var(--text-primary)"><?= (int)($stats['total_trx'] ?? 0) ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">Transaksi</div>
                </div>
                <div class="col-4">
                    <div style="font-size:13px;font-weight:700;color:var(--primary)"><?= formatRp((int)($stats['total_income'] ?? 0)) ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">Total Masuk</div>
                </div>
                <div class="col-4">
                    <div style="font-size:13px;font-weight:700;color:var(--danger)"><?= formatRp((int)($stats['total_expense'] ?? 0)) ?></div>
                    <div style="font-size:10px;color:var(--text-muted)">Total Keluar</div>
                </div>
            </div>

            <?php if ($stats['first_trx']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:12px">
                Pengguna sejak <?= date('d M Y', strtotime($stats['first_trx'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Nomor WA Bot -->
        <?php if ($botWaNumber): ?>
        <div class="card p-3">
            <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">
                <i class="fa-brands fa-whatsapp me-1" style="color:#25D366"></i> Nomor Bot WhatsApp
            </div>
            <a href="<?= htmlspecialchars($botWaLink) ?>"
               target="_blank"
               style="display:inline-flex;align-items:center;gap:7px;background:#25D366;color:#fff;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;width:100%;justify-content:center;transition:background .15s"
               onmouseover="this.style.background='#1ebe57'" onmouseout="this.style.background='#25D366'">
                <i class="fa-brands fa-whatsapp fa-lg"></i>
                Chat ke <?= htmlspecialchars($botName) ?>
            </a>
            <div style="font-size:11px;color:var(--text-muted);margin-top:8px;text-align:center">
                Simpan nomor ini di kontak HP Anda
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Form Kanan -->
    <div class="col-lg-8">

        <!-- Edit Profil -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-user me-2 text-muted"></i>Edit Profil</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:12px;font-weight:600">Nama</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:12px;font-weight:600">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-size:12px;font-weight:600">Nomor WA</label>
                            <input type="text" 
                               class="form-control" 
                               value="<?= htmlspecialchars($user['wa_number'] ?? '') ?>" 
                               readonly 
                               style="background:#f8fafc; font-family:var(--font-family-mono); cursor:not-allowed;">
                            <div class="form-text">Nomor WA tidak bisa diubah melalui dashboard</div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Simpan Profil
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Ganti Password -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-lock me-2 text-muted"></i>Ganti Password</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="row g-3">
                        <div class="col-12">
                        <label class="form-label" style="font-size:12px;font-weight:600">Password Saat Ini</label>
                        <div class="input-group">
                            <input type="password" name="current_password" id="currentPassword" class="form-control"
                                   placeholder="Masukkan password saat ini" autocomplete="current-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePass('currentPassword','eyeCurrent')">
                                <i class="fa-regular fa-eye" id="eyeCurrent"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Password Baru</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="newPassword" class="form-control"
                                   placeholder="Minimal 6 karakter" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePass('newPassword','eyeNew')">
                                <i class="fa-regular fa-eye" id="eyeNew"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" style="font-size:12px;font-weight:600">Konfirmasi Password Baru</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-control"
                                   placeholder="Ulangi password baru" autocomplete="new-password">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePass('confirmPassword','eyeConfirm')">
                                <i class="fa-regular fa-eye" id="eyeConfirm"></i>
                            </button>
                        </div>
                    </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-key me-1"></i>Ganti Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Hapus Akun -->
        <div class="card" style="border-color:#fecaca">
            <div class="card-header" style="border-color:#fecaca;color:#dc2626">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>Zona Berbahaya
            </div>
            <div class="card-body">
                <div style="font-size:13.5px;font-weight:600;margin-bottom:4px">Nonaktifkan Akun</div>
                <div style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;line-height:1.6">
                    Akun Anda akan dinonaktifkan. Data transaksi tetap tersimpan dan dapat dipulihkan
                    oleh admin jika Anda ingin mengaktifkan kembali di kemudian hari.
                </div>

                <button type="button" id="btnShowDelete"
                        style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;font-size:13px;font-weight:600;padding:7px 16px;<?= $deleteError ? 'display:none' : '' ?>"
                        onclick="document.getElementById('deleteAccountForm').style.display='block';this.style.display='none'">
                    <i class="fa-solid fa-user-slash me-2"></i>Nonaktifkan Akun Saya
                </button>

                <div id="deleteAccountForm" style="<?= $deleteError ? '' : 'display:none;' ?>margin-top:16px;padding-top:16px;border-top:1px solid #fecaca">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_account">
                        <label class="form-label" style="font-size:12px;font-weight:600">
                            Masukkan password Anda untuk konfirmasi
                        </label>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <input type="password"
                                       name="confirm_password_delete"
                                       id="confirmPasswordDelete"
                                       class="form-control"
                                       placeholder="Password Anda"
                                       autocomplete="current-password"
                                       required>
                            </div>
                            <div class="col-auto">
                                <button type="button"
                                        onclick="handleDeleteSubmit()"
                                        class="btn btn-sm btn-danger"
                                        style="border-radius:8px;font-size:13px;font-weight:600;padding:8px 16px">
                                    <i class="fa-solid fa-user-slash me-1"></i>Ya, Nonaktifkan
                                </button>
                            </div>
                            <div class="col-auto">
                                <button type="button"
                                        onclick="document.getElementById('deleteAccountForm').style.display='none';document.getElementById('btnShowDelete').style.display=''"
                                        class="btn btn-sm btn-outline-secondary"
                                        style="border-radius:8px;font-size:13px;padding:8px 14px">
                                    Batal
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function handleDeleteSubmit() {
    const pass = document.getElementById('confirmPasswordDelete').value.trim();
    if (!pass) {
        alert('Masukkan password terlebih dahulu.');
        return;
    }
    if (!confirm('Anda yakin ingin menonaktifkan akun?\n\nAkun dapat diaktifkan kembali dengan menghubungi admin.')) {
        return;
    }
    document.getElementById('confirmPasswordDelete').closest('form').submit();
}

function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}
</script>

<?php require_once __DIR__ . '/layout/footer.php'; ?>