<?php
// ============================================================
// KitaCatat — Login
// ============================================================
require_once __DIR__ . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Sudah login → redirect ke dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = trim($_POST['login']    ?? '');  // email atau nomor WA
    $password = trim($_POST['password'] ?? '');

    if (empty($login) || empty($password)) {
        $error = 'Email/nomor WA dan password wajib diisi.';
    } else {
        $db = getDB();

        // Cari user berdasarkan email atau nomor WA
        $stmt = $db->prepare(
            "SELECT * FROM users
             WHERE (email = :email OR wa_number = :wa_number)
             LIMIT 1"
        );
        $stmt->execute([':email' => $login, ':wa_number' => $login]);
        $user = $stmt->fetch();
        
        // Akun ditemukan tapi nonaktif
        if ($user && !$user['is_active']) {
            header('Location: /account-deleted.php');
            exit;
        }
        
        if ($user && $user['password'] && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['wa_number'] = $user['wa_number'];

            header('Location: /dashboard/index.php');
            exit;
        } else {
            $error = 'Email/nomor WA atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — KitaCatat</title>
    <link rel="icon" type="image/x-icon" href="/assets/img/icon/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #16a34a;
            --primary-dark: #15803d;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,.05), 0 20px 60px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .login-header {
            background: #0f172a;
            padding: 32px 32px 28px;
            text-align: center;
        }
        .login-header .brand-icon {
            width: 48px; height: 48px;
            background: var(--primary);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
            margin-bottom: 12px;
        }
        .login-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            margin: 0 0 4px;
        }
        .login-header p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .login-body {
            padding: 28px 32px 32px;
        }
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-control {
            border-radius: 8px;
            border: 1.5px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 14px;
            transition: border-color .15s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22,163,74,.12);
        }
        .input-group .form-control { border-right: none; }
        .input-group .btn-outline-secondary {
            border: 1.5px solid #e2e8f0;
            border-left: none;
            border-radius: 0 8px 8px 0;
            color: #94a3b8;
            background: #fff;
        }
        .btn-login {
            background: var(--primary);
            border: none;
            color: #fff;
            border-radius: 8px;
            padding: 11px;
            font-size: 14px;
            font-weight: 600;
            width: 100%;
            transition: background .15s;
        }
        .btn-login:hover { background: var(--primary-dark); }
        .alert-danger {
            border-radius: 8px;
            font-size: 13px;
            padding: 10px 14px;
        }
        .login-footer {
            text-align: center;
            padding: 0 32px 24px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
<div class="login-card" style="position:relative;">
    <a href="https://kitacatat.masbendz.com/" style="position:absolute;top:14px;left:14px;color:rgba(255,255,255,.4);font-size:28px;text-decoration:none;z-index:1;transition:color .15s;" onmouseover="this.style.color='rgba(255,255,255,.9)'" onmouseout="this.style.color='rgba(255,255,255,.4)'" title="Kembali ke halaman utama">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="login-header">
        <img src="/assets/img/icon/android-chrome-192x192.png" alt="KitaCatat" style="width:100px;height:100px;border-radius:14px;margin-bottom:-10px;display:block;margin-left:auto;margin-right:auto;">
        <h1>KitaCatat</h1>
        <p>Pencatatan keuangan via WhatsApp</p>
    </div>
    <div class="login-body">
        <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-circle-check me-2"></i>Akun berhasil dibuat! Silakan login.
        </div>
        <?php endif; ?>
        <?php if (isset($_GET['reason']) && $_GET['reason']==='timeout'): ?>
        <div class="alert alert-warning mb-3" style="font-size:13px;border-radius:8px">
            <i class="fa-solid fa-clock me-2"></i>Sesi Anda berakhir karena tidak aktif. Silakan login kembali.
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger mb-3">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Email atau Nomor WA</label>
                <input type="text" name="login" class="form-control"
                       placeholder="email@anda.com atau 628xxx"
                       value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                       autocomplete="username" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="passwordInput"
                           class="form-control" placeholder="Password Anda"
                           autocomplete="current-password" required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePassword()">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fa-solid fa-right-to-bracket me-2"></i>Masuk
            </button>
        </form>
        <hr class="my-3" style="border-color:#1e293b">
        <div class="text-center" style="font-size:12px;color:#475569">
            Belum punya akun?
            <a href="/register.php" style="color:#4ade80;font-weight:600;text-decoration:none">Daftar sekarang</a>
        </div>
    </div>
    <div class="login-footer">
        &copy; <?= date('Y') ?> KitaCatat
    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
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