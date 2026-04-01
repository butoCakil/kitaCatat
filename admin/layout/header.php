<?php
// ============================================================
// KitaCatat — Admin Layout: Header
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// Guard: hanya admin yang bisa akses
if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$adminName    = $_SESSION['admin_name'] ?? 'Admin';
$adminUser    = $_SESSION['admin_user'] ?? '';
$currentPage  = basename($_SERVER['PHP_SELF'], '.php');

function adminNavActive(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?? 'Admin' ?> — KitaCatat Admin</title>
    <link rel="icon" type="image/x-icon" href="assets/ico/favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --admin-primary:  #dc2626;
            --admin-dark:     #991b1b;
            --admin-light:    #fee2e2;
            --sidebar-bg:     #0f172a;
            --sidebar-width:  240px;
            --bg:             #f8fafc;
            --card-bg:        #ffffff;
            --card-border:    #e2e8f0;
            --text-primary:   #0f172a;
            --text-secondary: #64748b;
            --text-muted:     #94a3b8;
            --font-main:      'Plus Jakarta Sans', sans-serif;
            --font-mono:      'DM Mono', monospace;
            --radius:         12px;
            --radius-sm:      8px;
            --shadow:         0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
        }
        * { box-sizing: border-box; }
        body { font-family: var(--font-main); background: var(--bg); color: var(--text-primary); margin: 0; font-size: 14px; }

        /* Sidebar */
        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); display: flex; flex-direction: column; z-index: 100; transition: transform .3s; }
        .sidebar-brand { padding: 22px 20px 18px; border-bottom: 1px solid #1e293b; }
        .sidebar-brand .brand-icon { width: 36px; height: 36px; background: var(--admin-primary); border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; color: #fff; margin-bottom: 8px; }
        .sidebar-brand .brand-name { display: block; font-size: 15px; font-weight: 700; color: #fff; }
        .sidebar-brand .brand-sub  { display: block; font-size: 11px; color: #475569; margin-top: 2px; }
        /*.sidebar-nav { flex: 1; padding: 14px 12px; overflow-y: auto; }*/
        .sidebar-nav { flex: 1; padding: 14px 12px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #334155 transparent; }
        .nav-section-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #334155; padding: 8px 8px 5px; margin-top: 6px; }
        .sidebar-nav .nav-link { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: var(--radius-sm); color: #64748b; font-size: 13px; font-weight: 500; text-decoration: none; transition: all .15s; margin-bottom: 2px; }
        .sidebar-nav .nav-link i { width: 18px; text-align: center; font-size: 13px; flex-shrink: 0; }
        .sidebar-nav .nav-link:hover { background: #1e293b; color: #fff; }
        .sidebar-nav .nav-link.active { background: var(--admin-primary); color: #fff; }
        .sidebar-footer { padding: 14px 12px; border-top: 1px solid #1e293b; }
        .user-info { display: flex; align-items: center; gap: 10px; padding: 8px 10px; }
        .user-avatar { width: 32px; height: 32px; background: var(--admin-primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
        .user-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 11px; color: #475569; }
        .logout-btn { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); color: #64748b; text-decoration: none; transition: all .15s; margin-left: auto; flex-shrink: 0; }
        .logout-btn:hover { background: var(--admin-primary); color: #fff; }

        /* Main */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; display: flex; flex-direction: column; }
        .topbar { background: var(--card-bg); border-bottom: 1px solid var(--card-border); padding: 0 24px; height: 58px; display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .topbar .page-title { font-size: 15px; font-weight: 700; margin: 0; }
        .admin-badge { background: var(--admin-light); color: var(--admin-primary); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px; }
        .content-area { flex: 1; padding: 24px; }
        .sidebar-toggle { display: none; background: none; border: none; padding: 6px; cursor: pointer; color: var(--text-secondary); }

        /* Cards */
        .card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); box-shadow: var(--shadow); }
        .card-header { background: transparent; border-bottom: 1px solid var(--card-border); padding: 14px 20px; font-weight: 600; font-size: 14px; }
        .card-body { padding: 20px; }

        /* Stat cards */
        .stat-card { border-radius: var(--radius); padding: 18px; border: 1px solid var(--card-border); background: var(--card-bg); box-shadow: var(--shadow); display: flex; align-items: flex-start; gap: 14px; }
        .stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .stat-icon.red    { background: var(--admin-light); color: var(--admin-primary); }
        .stat-icon.green  { background: #dcfce7; color: #16a34a; }
        .stat-icon.blue   { background: #dbeafe; color: #2563eb; }
        .stat-icon.yellow { background: #fef9c3; color: #ca8a04; }
        .stat-label { font-size: 12px; color: var(--text-secondary); font-weight: 500; margin-bottom: 3px; }
        .stat-value { font-size: 20px; font-weight: 700; font-family: var(--font-mono); letter-spacing: -.5px; }
        .stat-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        /* Badges */
        .badge-active   { background: #dcfce7; color: #15803d; font-weight: 600; }
        .badge-inactive { background: #f1f5f9; color: #64748b; font-weight: 600; }
        .badge-admin    { background: var(--admin-light); color: var(--admin-primary); font-weight: 600; }
        .txn-code { font-family: var(--font-mono); font-size: 11px; color: var(--text-muted); background: var(--bg); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--card-border); }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 0 0 9999px rgba(0,0,0,.5); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .content-area { padding: 16px; }
        }
        
        /* Scrollbar sidebar */
        .sidebar-nav::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: #475569;
        }
    </style>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="/admin/assets/ico/android-chrome-192x192.png" alt="KitaCatat" style="width:36px;height:36px;border-radius:10px;margin-bottom:8px;display:block;">
        <span class="brand-name">Admin Panel</span>
        <span class="brand-sub">KitaCatat Management</span>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Overview</div>
        <a href="/admin/index.php" class="nav-link <?= adminNavActive('index', $currentPage) ?>">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="/admin/logs.php" class="nav-link <?= adminNavActive('logs', $currentPage) ?>">
            <i class="fa-solid fa-terminal"></i> System Logs
        </a>

        <div class="nav-section-label">Manajemen</div>
        <a href="/admin/users.php" class="nav-link <?= adminNavActive('users', $currentPage) ?>">
            <i class="fa-solid fa-users"></i> Users
        </a>
        <a href="/admin/transactions.php" class="nav-link <?= adminNavActive('transactions', $currentPage) ?>">
            <i class="fa-solid fa-receipt"></i> Transaksi
        </a>
        <a href="/admin/categories.php" class="nav-link <?= adminNavActive('categories', $currentPage) ?>">
            <i class="fa-solid fa-tags"></i> Kategori Global
        </a>

        <div class="nav-section-label">Komunikasi</div>
        <a href="/admin/support.php" class="nav-link <?= adminNavActive('support', $currentPage) ?>" id="supportNavLink">
            <i class="fa-solid fa-headset"></i> Support Chat
            <span id="supportBadge" style="display:none;margin-left:auto;background:#fbbf24;color:#000;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px">!</span>
        </a>
        <a href="/admin/broadcast.php" class="nav-link <?= adminNavActive('broadcast', $currentPage) ?>">
            <i class="fa-solid fa-bullhorn"></i> Broadcast WA
        </a>

        <div class="nav-section-label">Lainnya</div>
        <a href="/admin/cek.php" class="nav-link <?= adminNavActive('cek', $currentPage) ?>">
            <i class="fa-solid fa-list-check"></i> Cek Root
        </a>
        <a href="/admin/backup.php" class="nav-link <?= adminNavActive('backup', $currentPage) ?>">
            <i class="fa-solid fa-database"></i> Backup DB
        </a>
        
        <a href="/admin/app_settings.php" class="nav-link <?= adminNavActive('app_settings', $currentPage) ?>">
            <i class="fa-solid fa-sliders"></i> App Settings
        </a>
        <a href="/panduan_admin.html" class="nav-link" target="_blank">
            <i class="fa-solid fa-book"></i> Panduan Admin
        </a>
        
        <a href="/dashboard/index.php" class="nav-link" target="_blank">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat Dashboard User
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($adminName, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($adminName) ?></div>
                <!--<div class="user-role">@<?= htmlspecialchars($adminUser) ?></div>-->
            </div>
            <a href="/admin/logout.php" class="logout-btn" title="Keluar">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<div class="main-wrapper">
    <div class="topbar">
        <button class="sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fa-solid fa-bars fa-lg"></i>
        </button>
        <img src="/admin/assets/ico/android-chrome-192x192.png" alt="KitaCatat" style="width:28px;height:28px;border-radius:6px;">
        <h1 class="page-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
        <span class="admin-badge"><i class="fa-solid fa-shield-halved me-1"></i>Admin</span>
        <span class="ms-auto text-muted" style="font-size:12px">
            <i class="fa-regular fa-clock me-1"></i><?= date('d M Y, H:i') ?>
        </span>
    </div>
    <div class="content-area">