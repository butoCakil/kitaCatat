<?php
// ============================================================
// KitaCatat — Admin Login
// ============================================================
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

$error = '';

// Rate limiting: maks 5 percobaan gagal dalam 10 menit
$maxAttempts  = 5;
$lockDuration = 600;

if (!isset($_SESSION['admin_login_attempts']))   $_SESSION['admin_login_attempts']   = 0;
if (!isset($_SESSION['admin_login_locked_until'])) $_SESSION['admin_login_locked_until'] = 0;

$isLocked = $_SESSION['admin_login_locked_until'] > time();
if ($isLocked) {
    $sisaDetik = $_SESSION['admin_login_locked_until'] - time();
    $sisaMenit = ceil($sisaDetik / 60);
    $error = "Terlalu banyak percobaan gagal. Coba lagi dalam {$sisaMenit} menit.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    csrfVerify();
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            
            $_SESSION['admin_id']   = $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_user'] = $admin['username'];
            
            $_SESSION['admin_login_attempts']    = 0;
            $_SESSION['admin_login_locked_until'] = 0; 

            // Update last_login
            $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")
               ->execute([$admin['id']]);

            // Catat login di audit log
            $db->prepare(
                "INSERT INTO admin_logs (admin_id, action, target, note, ip)
                 VALUES (?, 'admin.login', ?, 'Login berhasil', ?)"
            )->execute([
                $admin['id'],
                'admin @' . $admin['username'],
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            header('Location: /admin/index.php');
            exit;
        } else {
            $_SESSION['admin_login_attempts']++;
            if ($_SESSION['admin_login_attempts'] >= $maxAttempts) {
                $_SESSION['admin_login_locked_until'] = time() + $lockDuration;
                $_SESSION['admin_login_attempts']     = 0;
                $error = "Terlalu banyak percobaan gagal. Coba lagi dalam " . ceil($lockDuration/60) . " menit.";
            } else {
                $sisa = $maxAttempts - $_SESSION['admin_login_attempts'];
                $error = "Username atau password salah. Sisa percobaan: {$sisa}x.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login — KitaCatat</title>
    <link rel="icon" type="image/x-icon" href="/admin/assets/ico/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .login-card { width: 100%; max-width: 400px; background: #1e293b; border-radius: 16px; border: 1px solid #334155; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
        .login-header { background: linear-gradient(135deg, #dc2626, #991b1b); padding: 28px 32px 24px; text-align: center; }
        .brand-icon { width: 48px; height: 48px; background: rgba(255,255,255,.15); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 10px; }
        .login-body { padding: 28px 32px 32px; }
        .form-label { font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
        .form-control { background: #0f172a; border: 1.5px solid #334155; color: #fff; border-radius: 8px; padding: 10px 14px; font-size: 14px; }
        .form-control:focus { background: #0f172a; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.15); color: #fff; }
        .form-control::placeholder { color: #475569; }
        .input-group .btn-outline-secondary { border: 1.5px solid #334155; border-left: none; background: #0f172a; color: #64748b; border-radius: 0 8px 8px 0; }
        .btn-admin { background: #dc2626; border: none; color: #fff; border-radius: 8px; padding: 11px; font-size: 14px; font-weight: 700; width: 100%; }
        .btn-admin:hover { background: #b91c1c; color: #fff; }
        .login-footer { text-align: center; padding: 0 32px 20px; font-size: 12px; color: #475569; }
        .login-footer a { color: #64748b; text-decoration: none; }
        .login-footer a:hover { color: #94a3b8; }
    </style>
</head>
<body>
<div class="login-card" style="position:relative;">
    <a href="https://kitacatat.masbendz.com/" style="position:absolute;top:14px;left:14px;color:rgba(255,255,255,.4);font-size:28px;text-decoration:none;z-index:1;transition:color .15s;" onmouseover="this.style.color='rgba(255,255,255,.9)'" onmouseout="this.style.color='rgba(255,255,255,.4)'" title="Kembali ke halaman utama">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="login-header">
        <img src="/admin/assets/ico/android-chrome-192x192.png" alt="KitaCatat" style="width:100px;height:100px;border-radius:14px;margin-bottom:-20px;display:block;margin-left:auto;margin-right:auto;">
        <h1 style="font-size:18px;font-weight:700;color:#fff;margin:0 0 4px">Admin Panel</h1>
        <p style="font-size:12px;color:rgba(255,255,255,.6);margin:0">KitaCatat Management</p>
    </div>
    <div class="login-body">
        <?php if ($error): ?>
        <div class="alert mb-3" style="background:#450a0a;border:1px solid #991b1b;border-radius:8px;font-size:13px;color:#fca5a5;padding:10px 14px">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       autocomplete="username" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="passInput" class="form-control"
                           autocomplete="current-password" required>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePass()">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-admin">
                <i class="fa-solid fa-right-to-bracket me-2"></i>Masuk sebagai Admin
            </button>
        </form>
    </div>
    <div class="login-footer">
        <a href="/login.php"><i class="fa-solid fa-arrow-left me-1"></i>Kembali ke login user</a>
    </div>
</div>
<script>
function togglePass() {
    const i = document.getElementById('passInput');
    const e = document.getElementById('eyeIcon');
    i.type = i.type === 'password' ? 'text' : 'password';
    e.classList.toggle('fa-eye');
    e.classList.toggle('fa-eye-slash');
}
</script>
</body>
</html>