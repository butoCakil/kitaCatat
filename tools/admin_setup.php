<?php
// ============================================================
// KitaCatat — Admin Setup (Akun Admin Pertama)
// Upload ke: /public_html/kitacatat.masbendz.com/tools/admin_setup.php
// HAPUS setelah selesai digunakan
// ============================================================
require_once __DIR__ . '/../config/config.php';

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (empty($name) || empty($username) || empty($password) || empty($confirm)) {
        $error = 'Semua field wajib diisi.';
    } elseif (strlen($username) < 4) {
        $error = 'Username minimal 4 karakter.';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        $db = getDB();

        // Cek username sudah ada
        $chk = $db->prepare("SELECT id FROM admins WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'Username sudah digunakan.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare(
                "INSERT INTO admins (username, password, name, is_active) VALUES (?, ?, ?, 1)"
            );
            $stmt->execute([$username, $hash, $name]);
            $success = "Akun admin <strong>{$username}</strong> berhasil dibuat! Silakan hapus file ini sekarang.";
        }
    }
}

// Cek apakah sudah ada admin
$db       = getDB();
$existing = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Setup — KitaCatat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { border-radius: 16px; border: 1px solid #1e293b; background: #1e293b; max-width: 420px; width: 100%; }
        .card-header { background: #dc2626; border-radius: 14px 14px 0 0; padding: 20px 24px; }
        .form-control { background: #0f172a; border: 1.5px solid #334155; color: #fff; border-radius: 8px; }
        .form-control:focus { background: #0f172a; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.15); color: #fff; }
        .form-label { color: #94a3b8; font-size: 12px; font-weight: 600; }
        .btn-danger { border-radius: 8px; font-weight: 700; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h5 class="mb-0 text-white fw-bold">🔐 Setup Admin KitaCatat</h5>
        <small class="text-white" style="opacity:.7">Buat akun admin pertama</small>
    </div>
    <div class="card-body p-4">
        <?php if ($existing > 0): ?>
        <div class="alert alert-warning" style="font-size:13px">
            ⚠️ Sudah ada <strong><?= $existing ?></strong> akun admin. Hapus file ini jika tidak diperlukan lagi.
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success" style="font-size:13px">✅ <?= $success ?></div>
        <a href="/admin/login.php" class="btn btn-success w-100 mt-2">Pergi ke Login Admin</a>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger" style="font-size:13px">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nama Admin</label>
                <input type="text" name="name" class="form-control" placeholder="Nama lengkap"
                       value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" placeholder="Minimal 4 karakter"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Minimal 8 karakter" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Konfirmasi Password</label>
                <input type="password" name="confirm" class="form-control" placeholder="Ulangi password" required>
            </div>
            <button type="submit" class="btn btn-danger w-100 fw-bold">Buat Akun Admin</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-footer text-center" style="background:transparent;border-top:1px solid #334155;padding:12px">
        <small style="color:#475569">⚠️ Hapus file ini setelah selesai digunakan</small>
    </div>
</div>
</body>
</html>
