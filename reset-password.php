<?php
// ============================================================
// KitaCatat — Reset Password via Token
// ============================================================
require_once __DIR__ . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['user_id'])) { header('Location: /dashboard/'); exit; }

$db      = getDB();
$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';

// Validasi token
$tokenRow = null;
if ($token) {
    $stmt = $db->prepare(
        "SELECT pr.*, u.name, u.email, u.wa_number
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.is_used = 0 AND pr.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();
}

if (!$token || !$tokenRow) {
    $error = 'Link reset tidak valid atau sudah kadaluarsa. Silakan minta link baru.';
}

// Proses form ganti password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    csrfVerify();
    $newPass  = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($newPass) || empty($confirm)) {
        $error = 'Semua field wajib diisi.';
    } elseif (strlen($newPass) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($newPass !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        // Update password
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$hash, $tokenRow['user_id']]);

        // Tandai token sudah dipakai
        $db->prepare("UPDATE password_resets SET is_used = 1 WHERE token = ?")
           ->execute([$token]);

        $success = 'Password berhasil diubah. Silakan login dengan password baru.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — KitaCatat</title>
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
        .input-group .form-control { border-right: none; }
        .input-group .btn-outline-secondary {
            border: 1.5px solid #e2e8f0; border-left: none;
            border-radius: 0 8px 8px 0; color: #94a3b8; background: #fff;
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
    <div class="card-header-top">
        <div style="font-size:32px;margin-bottom:8px">🔑</div>
        <h1>Reset Password</h1>
        <p><?= $tokenRow ? 'Halo, ' . htmlspecialchars($tokenRow['name']) : 'Buat password baru' ?></p>
    </div>
    <div class="card-body-inner">
        <?php if ($error): ?>
        <div class="alert alert-danger mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
            <?php if (!$tokenRow): ?>
            <div class="mt-2">
                <a href="/forgot-password.php" style="color:#dc2626;font-weight:600">← Minta link baru</a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        </div>
        <a href="/login.php" class="btn-submit d-block text-center text-decoration-none mt-3"
           style="padding:11px;line-height:1.5">
            <i class="fa-solid fa-right-to-bracket me-2"></i>Ke Halaman Login
        </a>

        <?php elseif ($tokenRow): ?>
        <form method="POST">
            <?= csrfField(); ?>
            <div class="mb-3">
                <label class="form-label">Password Baru</label>
                <div class="input-group">
                    <input type="password" name="new_password" id="newPass"
                           class="form-control" placeholder="Minimal 6 karakter" required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePass('newPass','eye1')">
                        <i class="fa-regular fa-eye" id="eye1"></i>
                    </button>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Konfirmasi Password</label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confPass"
                           class="form-control" placeholder="Ulangi password baru" required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePass('confPass','eye2')">
                        <i class="fa-regular fa-eye" id="eye2"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-lock me-2"></i>Simpan Password Baru
            </button>
        </form>
        <?php endif; ?>

        <div class="text-center mt-4" style="font-size:12px;color:#94a3b8">
            <a href="/login.php" style="color:#16a34a;font-weight:600;text-decoration:none">← Kembali ke Login</a>
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
<script>
function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>