<?php
// ============================================================
// KitaCatat — Register dengan OTP WA
// Flow: isi form → kirim OTP ke WA → verifikasi OTP → akun aktif
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/WASender.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: /dashboard/'); exit; }

$db    = getDB();
$step  = $_SESSION['reg_step'] ?? 1; // 1=form, 2=otp
$error = '';

// ============================================================
// POST — Step 1: Simpan data sementara + kirim OTP
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_otp') {
    $name     = trim($_POST['name']     ?? '');
    $waNumber = preg_replace('/[^0-9]/', '', $_POST['wa_number'] ?? '');
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (empty($name) || empty($waNumber) || empty($email) || empty($password)) {
        $error = 'Semua field wajib diisi.';
    } elseif (!str_starts_with($waNumber, '62') || strlen($waNumber) < 10) {
        $error = 'Nomor WA harus diawali 62 (contoh: 6281234567890).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        // Cek duplikat WA
        $chk = $db->prepare("SELECT id FROM users WHERE wa_number=?");
        $chk->execute([$waNumber]);
        if ($chk->fetch()) {
            $error = 'Nomor WA sudah terdaftar.';
        } else {
            // Cek duplikat email
            $chk2 = $db->prepare("SELECT id FROM users WHERE email=?");
            $chk2->execute([$email]);
            if ($chk2->fetch()) {
                $error = 'Email sudah terdaftar.';
            } else {
                // Generate OTP 6 digit
                $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                // Hapus OTP lama untuk nomor ini
                $db->prepare("DELETE FROM otp_register WHERE wa_number=?")->execute([$waNumber]);

                // Simpan OTP baru
                $db->prepare(
                    "INSERT INTO otp_register (wa_number, otp_code, expires_at) VALUES (?, ?, ?)"
                )->execute([$waNumber, $otp, $expires]);

                // Kirim OTP ke WA
                $otpMsg  = "🔐 *Verifikasi KitaCatat*\n\n";
                $otpMsg .= "Kode OTP Anda: *{$otp}*\n\n";
                $otpMsg .= "Masukkan kode ini di halaman registrasi.\n";
                $otpMsg .= "Berlaku selama *10 menit*.\n\n";
                $otpMsg .= "_Jika bukan Anda yang mendaftar, abaikan pesan ini._";

                $waResult = WASender::send($waNumber, $otpMsg);

                if ($waResult['success']) {
                    // Simpan data sementara di session
                    $_SESSION['reg_step']     = 2;
                    $_SESSION['reg_name']     = $name;
                    $_SESSION['reg_wa']       = $waNumber;
                    $_SESSION['reg_email']    = $email;
                    $_SESSION['reg_password'] = password_hash($password, PASSWORD_BCRYPT);
                    $step = 2;
                } else {
                    $error = 'Gagal mengirim OTP ke WhatsApp. Pastikan nomor WA benar dan aktif.';
                    $db->prepare("DELETE FROM otp_register WHERE wa_number=?")->execute([$waNumber]);
                }
            }
        }
    }
}

// ============================================================
// POST — Step 2: Verifikasi OTP + buat akun
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
    $inputOtp = trim($_POST['otp'] ?? '');
    $waNumber = $_SESSION['reg_wa'] ?? '';

    if (empty($inputOtp)) {
        $error = 'Masukkan kode OTP.';
    } elseif (empty($waNumber)) {
        $error = 'Sesi registrasi tidak valid. Ulangi dari awal.';
        $_SESSION['reg_step'] = 1;
        $step = 1;
    } else {
        // Cek OTP valid
        $stmt = $db->prepare(
            "SELECT * FROM otp_register
             WHERE wa_number=? AND otp_code=? AND is_used=0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$waNumber, $inputOtp]);
        $otpRow = $stmt->fetch();

        if (!$otpRow) {
            $error = 'Kode OTP salah atau sudah kadaluarsa.';
        } else {
            // OTP valid — buat akun
            $name  = $_SESSION['reg_name']     ?? '';
            $email = $_SESSION['reg_email']    ?? '';
            $hash  = $_SESSION['reg_password'] ?? '';

            $insertStmt = $db->prepare(
                "INSERT INTO users (wa_number, name, email, password, is_active) VALUES (?, ?, ?, ?, 1)"
            );
            $insertStmt->execute([$waNumber, $name, $email, $hash]);

            // Tandai OTP sudah dipakai
            $db->prepare("UPDATE otp_register SET is_used=1 WHERE id=?")->execute([$otpRow['id']]);

            // Kirim pesan sambutan
            $wm  = "Selamat datang di *KitaCatat*, {$name}!\n\n";
            $wm .= "Akun Anda sudah aktif. Mulai catat keuangan sekarang:\n\n";
            $wm .= "*Contoh mencatat:*\n";
            $wm .= "- Bensin 50rb\n";
            $wm .= "- Makan siang 25K\n";
            $wm .= "- Income gaji 5jt\n\n";
            $wm .= "*Rekap:* ketik Rekap bulan ini\n";
            $wm .= "*Bantuan:* ketik help\n\n";
            $wm .= "Dashboard: " . APP_URL;
            WASender::send($waNumber, $wm);

            // Hapus data session registrasi
            unset($_SESSION['reg_step'], $_SESSION['reg_name'], $_SESSION['reg_wa'],
                  $_SESSION['reg_email'], $_SESSION['reg_password']);

            // Redirect ke login dengan pesan sukses
            header('Location: /login.php?registered=1');
            exit;
        }
    }
}

// ============================================================
// POST — Kirim ulang OTP
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_otp') {
    $waNumber = $_SESSION['reg_wa'] ?? '';
    if ($waNumber) {
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $db->prepare("DELETE FROM otp_register WHERE wa_number=?")->execute([$waNumber]);
        $db->prepare(
            "INSERT INTO otp_register (wa_number, otp_code, expires_at) VALUES (?, ?, ?)"
        )->execute([$waNumber, $otp, $expires]);

        $otpMsg  = "Kode OTP baru Anda: *{$otp}*\n";
        $otpMsg .= "Berlaku 10 menit.";
        WASender::send($waNumber, $otpMsg);

        $step = 2;
        $error = ''; // bersihkan error
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar — KitaCatat</title>
    <link rel="icon" type="image/x-icon" href="/assets/img/icon/favicon.ico">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .reg-card { width: 100%; max-width: 420px; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 24px rgba(0,0,0,.06); overflow: hidden; }
        .reg-header { background: linear-gradient(135deg, #16a34a, #15803d); padding: 24px 28px 20px; }
        .reg-body { padding: 24px 28px 28px; }
        .form-label { font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .4px; }
        .form-control { border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; font-size: 14px; }
        .form-control:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
        .btn-green { background: #16a34a; border: none; color: #fff; border-radius: 8px; padding: 11px; font-size: 14px; font-weight: 700; width: 100%; }
        .btn-green:hover { background: #15803d; color: #fff; }
        .step-indicator { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
        .step-dot.active { background: #16a34a; color: #fff; }
        .step-dot.done   { background: #dcfce7; color: #16a34a; }
        .step-dot.next   { background: #f1f5f9; color: #94a3b8; }
        .step-line { flex: 1; height: 2px; background: #e2e8f0; }
        .step-line.done { background: #16a34a; }
        .otp-input { letter-spacing: 8px; font-size: 22px; font-weight: 700; text-align: center; font-family: monospace; }
    </style>
</head>
<body>
<div class="reg-card">
    <div class="reg-header">
        <div style="font-size:22px;font-weight:800;color:#fff;margin-bottom:4px">💬 KitaCatat</div>
        <div style="font-size:13px;color:rgba(255,255,255,.75)">Buat akun baru</div>
    </div>
    <div class="reg-body">

        <!-- Step indicator -->
        <div class="step-indicator">
            <div class="step-dot <?= $step>=1?'active':'next' ?>">1</div>
            <div class="step-line <?= $step>=2?'done':'' ?>"></div>
            <div class="step-dot <?= $step>=2?'active':'next' ?>">2</div>
            <div class="step-line"></div>
            <div class="step-dot next"><i class="fa-solid fa-check" style="font-size:10px"></i></div>
        </div>

        <?php if ($error): ?>
        <div class="alert mb-3" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#dc2626;padding:10px 14px">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- STEP 1: Form Data Diri -->
        <p style="font-size:13px;color:#64748b;margin-bottom:18px">Isi data diri Anda. Kode verifikasi akan dikirim ke WhatsApp.</p>
        <form method="POST">
            <input type="hidden" name="action" value="request_otp">
            <div class="mb-3">
                <label class="form-label">Nama Lengkap</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Nomor WhatsApp</label>
                <input type="text" name="wa_number" class="form-control"
                       placeholder="628xxxxxxxxxx"
                       value="<?= htmlspecialchars($_POST['wa_number'] ?? '') ?>" required>
                <div class="form-text" style="font-size:11px">Awali dengan 62, tanpa tanda + atau 0</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Minimal 6 karakter" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Konfirmasi Password</label>
                <input type="password" name="confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn-green">
                <i class="fa-brands fa-whatsapp me-2"></i>Kirim Kode Verifikasi
            </button>
        </form>

        <?php else: ?>
        <!-- STEP 2: Verifikasi OTP -->
        <?php $maskedWA = substr($_SESSION['reg_wa']??'', 0, 5) . '****' . substr($_SESSION['reg_wa']??'', -3); ?>
        <p style="font-size:13px;color:#64748b;margin-bottom:18px">
            Kode OTP 6 digit telah dikirim ke WhatsApp <strong><?= $maskedWA ?></strong>. Berlaku 10 menit.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="verify_otp">
            <div class="mb-4">
                <label class="form-label">Kode OTP</label>
                <input type="text" name="otp" class="form-control otp-input"
                       maxlength="6" placeholder="––––––"
                       inputmode="numeric" autocomplete="one-time-code"
                       autofocus required>
            </div>
            <button type="submit" class="btn-green mb-3">
                <i class="fa-solid fa-check me-2"></i>Verifikasi & Buat Akun
            </button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" class="btn btn-outline-secondary w-100" style="border-radius:8px;font-size:13px">
                <i class="fa-solid fa-rotate-right me-2"></i>Kirim ulang OTP
            </button>
        </form>
        <div class="mt-3 text-center">
            <a href="/register.php?reset=1" style="font-size:12px;color:#94a3b8">← Kembali isi data ulang</a>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:20px;font-size:13px;color:#94a3b8">
            Sudah punya akun? <a href="/login.php" style="color:#16a34a;font-weight:600">Masuk</a>
        </div>
    </div>
</div>
<?php
// Reset session registrasi jika user minta mulai ulang
if (isset($_GET['reset'])) {
    unset($_SESSION['reg_step'], $_SESSION['reg_name'], $_SESSION['reg_wa'],
          $_SESSION['reg_email'], $_SESSION['reg_password']);
    header('Location: /register.php');
    exit;
}
?>
</body>
</html>