<?php
// ============================================================
// KitaCatat — Account Deleted
// Ditampilkan setelah user menonaktifkan akunnya sendiri,
// atau saat user nonaktif mencoba login.
// ============================================================
require_once __DIR__ . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Pastikan user tidak sedang login aktif
if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/index.php');
    exit;
}

$adminWa  = getAppSetting('admin_wa_number', '');
$waLink   = $adminWa ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $adminWa) : '';
$botName  = getAppSetting('bot_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akun Dinonaktifkan — <?= htmlspecialchars($botName) ?></title>
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
            max-width: 460px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,.05), 0 20px 60px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .card-top {
            background: #0f172a;
            padding: 36px 32px 28px;
            text-align: center;
        }
        .icon-wrap {
            width: 60px; height: 60px;
            background: #fee2e2;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #dc2626;
            margin-bottom: 14px;
        }
        .card-top h1 { font-size: 18px; font-weight: 700; color: #fff; margin: 0 0 6px; }
        .card-top p  { font-size: 13px; color: #64748b; margin: 0; }
        .card-body-wrap { padding: 28px 32px 32px; }
        .info-item {
            display: flex;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
            color: #374151;
            line-height: 1.6;
        }
        .info-item:last-child { border-bottom: none; }
        .info-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .info-icon.yellow { background: #fef9c3; color: #ca8a04; }
        .info-icon.blue   { background: #dbeafe; color: #2563eb; }
        .info-icon.green  { background: #dcfce7; color: #16a34a; }
        .btn-wa {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #25D366;
            color: #fff;
            padding: 11px 24px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            text-decoration: none;
            width: 100%;
            justify-content: center;
            transition: background .15s;
            margin-bottom: 10px;
        }
        .btn-wa:hover { background: #1ebe57; color: #fff; }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            color: #475569;
            padding: 11px 24px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            width: 100%;
            justify-content: center;
            transition: background .15s;
        }
        .btn-home:hover { background: #e2e8f0; color: #1e293b; }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="card-top">
        <div class="icon-wrap"><i class="fa-solid fa-user-slash"></i></div>
        <h1>Akun Dinonaktifkan</h1>
        <p>Akun Anda saat ini tidak aktif</p>
    </div>
    <div class="card-body-wrap">

        <div class="info-item">
            <div class="info-icon yellow"><i class="fa-solid fa-circle-info"></i></div>
            <div>Akun Anda telah dinonaktifkan. Anda tidak dapat login atau menggunakan layanan <?= htmlspecialchars($botName) ?> sampai akun diaktifkan kembali.</div>
        </div>

        <div class="info-item">
            <div class="info-icon blue"><i class="fa-solid fa-database"></i></div>
            <div><strong>Data Anda aman.</strong> Seluruh catatan transaksi, laporan, dan riwayat keuangan Anda tetap tersimpan dan tidak dihapus.</div>
        </div>

        <div class="info-item">
            <div class="info-icon green"><i class="fa-solid fa-rotate-right"></i></div>
            <div>Untuk <strong>mengaktifkan kembali</strong> akun Anda, hubungi admin melalui tombol di bawah. Admin akan memverifikasi dan mengaktifkan akun Anda.</div>
        </div>

        <div style="margin-top:24px">
            <?php if ($waLink): ?>
            <a href="<?= htmlspecialchars($waLink) ?>?text=Halo+Admin%2C+saya+ingin+mengaktifkan+kembali+akun+<?= htmlspecialchars($botName) ?>+saya."
               target="_blank"
               class="btn-wa">
                <i class="fa-brands fa-whatsapp fa-lg"></i>
                Hubungi Admin via WhatsApp
            </a>
            <?php endif; ?>
            <a href="/" class="btn-home">
                <i class="fa-solid fa-arrow-left"></i>
                Kembali ke Halaman Utama
            </a>
        </div>

        <div style="text-align:center;margin-top:20px;font-size:11px;color:#94a3b8">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($botName) ?>
        </div>
    </div>
</div>
</body>
</html>