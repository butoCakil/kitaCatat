<?php
// ============================================================
// KitaCatat — Lupa Password
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/WASender.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: /dashboard/'); exit; }

$db      = getDB();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $input = trim($_POST['input'] ?? '');

    if (empty($input)) {
        $error = 'Email atau nomor WA wajib diisi.';
    } else {
        // Cari user berdasarkan email atau nomor WA
        $waNumber = preg_replace('/[^0-9]/', '', $input);
        $stmt = $db->prepare(
            "SELECT * FROM users
             WHERE (email = ? OR wa_number = ?) AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$input, $waNumber]);
        $user = $stmt->fetch();

        // Selalu tampilkan pesan sukses meski user tidak ditemukan
        // (mencegah enumerasi akun)
        if ($user) {
            // Rate limit: cek apakah sudah kirim reset dalam 2 menit terakhir
            $stmtCheck = $db->prepare(
                "SELECT created_at FROM password_resets
                 WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmtCheck->execute([$user['id']]);
            $lastReset = $stmtCheck->fetch();

            if (!$lastReset) {
                // Hapus token lama
                $db->prepare("DELETE FROM password_resets WHERE user_id = ?")
                   ->execute([$user['id']]);

                // Generate token
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                $db->prepare(
                    "INSERT INTO password_resets (user_id, token, expires_at)
                     VALUES (?, ?, ?)"
                )->execute([$user['id'], $token, $expires]);

                $resetUrl = APP_URL . '/reset-password.php?token=' . $token;
                $sent     = false;

                // Kirim via WA (prioritas utama)
                if (!empty($user['wa_number'])) {
                    $waMsg  = "🔐 *Reset Password KitaCatat*\n\n";
                    $waMsg .= "Halo {$user['name']},\n\n";
                    $waMsg .= "Klik link berikut untuk reset password Anda:\n";
                    $waMsg .= $resetUrl . "\n\n";
                    $waMsg .= "Link berlaku selama *30 menit*.\n\n";
                    $waMsg .= "_Jika bukan Anda yang meminta, abaikan pesan ini._";
                    $waResult = WASender::send($user['wa_number'], $waMsg);
                    if ($waResult['success']) $sent = true;
                }

                // Kirim via Email (fallback jika WA gagal)
                if (!$sent && !empty($user['email'])) {
                    $subject = 'Reset Password KitaCatat';
                    $body    = "Halo {$user['name']},\n\n"
                             . "Klik link berikut untuk reset password Anda:\n"
                             . $resetUrl . "\n\n"
                             . "Link berlaku selama 30 menit.\n\n"
                             . "Jika bukan Anda yang meminta, abaikan email ini.\n\n"
                             . "— KitaCatat";
                    $headers = "From: noreply@" . parse_url(APP_URL, PHP_URL_HOST) . "\r\n"
                             . "Content-Type: text/plain; charset=UTF-8\r\n";
                    mail($user['email'], $subject, $body, $headers);
                }
            }
        }

        $success = 'Jika akun Anda terdaftar, link reset password telah dikirim via WhatsApp atau email. Berlaku 30 menit.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password — KitaCatat</title>
    <link rel="icon" type="image/x-icon" href="/assets/img/icon/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #16a34a; --primary-dark: #15803d; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card-wrap {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,.05), 0 20px 60px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .card-header-top {
            background: #0f172a;
            padding: 28px 32px 24px;
            text-align: center;
        }
        .card-header-top h1 { font-size: 18px; font-weight: 700; color: #fff; margin: 0 0 4px; }
        .card-header-top p  { font-size: 13px; color: #64748b; margin: 0; }
        .card-body-inner { padding: 28px 32px 32px; }
        .form-label { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .form-control {
            border-radius: 8px; border: 1.5px solid #e2e8f0;
            padding: 10px 14px; font-size: 14px;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22,163,74,.12);
        }
        .btn-submit {
            background: var(--primary); border: none; color: #fff;
            border-radius: 8px; padding: 11px; font-size: 14px;
            font-weight: 600; width: 100%;
        }
        .btn-submit:hover { background: var(--primary-dark); color: #fff; }
    </style>
</head>
<body>
<div class="card-wrap">
    <a href="/login.php" style="position:absolute;top:14px;left:14px;color:rgba(255,255,255,.4);font-size:22px;text-decoration:none;z-index:1;transition:color .15s"
       onmouseover="this.style.color='rgba(255,255,255,.9)'"
       onmouseout="this.style.color='rgba(255,255,255,.4)'"
       title="Kembali ke login">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="card-header-top" style="position:relative">
        <div style="font-size:32px;margin-bottom:8px">🔐</div>
        <h1>Lupa Password</h1>
        <p>Masukkan email atau nomor WA terdaftar</p>
    </div>
    <div class="card-body-inner">
        <?php if ($error): ?>
        <div class="alert alert-danger mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
        <?php if (!$success): ?>
        <form method="POST">
            <?= csrfField(); ?>
            <div class="mb-4">
                <label class="form-label">Email atau Nomor WA</label>
                <input type="text" name="input" class="form-control"
                       placeholder="email@anda.com atau 628xxx"
                       value="<?= htmlspecialchars($_POST['input'] ?? '') ?>"
                       autofocus required>
                <div class="form-text" style="font-size:11.5px">
                    Link reset akan dikirim via WhatsApp atau email.
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-paper-plane me-2"></i>Kirim Link Reset
            </button>
        </form>
        <?php endif; ?>
        <div class="text-center mt-4" style="font-size:12px;color:#94a3b8">
            Ingat password? <a href="/login.php" style="color:#16a34a;font-weight:600;text-decoration:none">Kembali login</a>
        </div>
    </div>
    <div style="text-align:center;padding:10px 0 16px;font-size:12px;color:#94a3b8">
        <a href="/privacy-policy.html" style="color:#94a3b8;text-decoration:none"
           onmouseover="this.style.color='#16a34a'" onmouseout="this.style.color='#94a3b8'">Kebijakan Privasi</a>
        &nbsp;·&nbsp;
        <a href="/terms-of-service.html" style="color:#94a3b8;text-decoration:none"
           onmouseover="this.style.color='#16a34a'" onmouseout="this.style.color='#94a3b8'">Syarat &amp; Ketentuan</a>
    </div>
</div>
</body>
</html>