<?php
// ============================================================
// KitaCatat — Password Hash Generator (Tools Sementara)
// Upload ke: /public_html/kitacatat.masbendz.com/tools/hash.php
// HAPUS setelah selesai dipakai
// ============================================================

// Proteksi sederhana: hanya bisa diakses dari IP Anda sendiri
// Kosongkan array untuk akses bebas (tidak disarankan)
$allowedIPs = []; // contoh: ['123.456.789.0']

if (!empty($allowedIPs) && !in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    http_response_code(403);
    die('Akses ditolak.');
}

$hash   = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (empty($password)) {
        $errors[] = 'Password tidak boleh kosong.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Generate Password Hash — KitaCatat</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container" style="max-width:500px; margin-top:60px">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <strong>🔐 Generate Password Hash</strong>
        </div>
        <div class="card-body">

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($hash): ?>
                <div class="alert alert-success">
                    <strong>Hash berhasil dibuat:</strong><br>
                    <code class="text-break"><?= htmlspecialchars($hash) ?></code>
                    <hr>
                    <small>Jalankan query ini di phpMyAdmin:</small><br>
                    <code class="text-break">
                        UPDATE `users` SET `password` = '<?= htmlspecialchars($hash) ?>'
                        WHERE `wa_number` = '628XXXXXXXXX';
                    </code>
                    <br><small class="text-muted">Ganti 628XXXXXXXXX dengan nomor WA Anda.</small>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Password Baru</label>
                    <input type="password" name="password" class="form-control"
                           placeholder="Minimal 6 karakter" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Konfirmasi Password</label>
                    <input type="password" name="confirm" class="form-control"
                           placeholder="Ulangi password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Generate Hash</button>
            </form>
        </div>
        <div class="card-footer text-muted small text-center">
            ⚠️ Hapus file ini setelah selesai digunakan
        </div>
    </div>
</div>
</body>
</html>
