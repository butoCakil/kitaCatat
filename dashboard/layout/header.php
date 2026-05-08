<?php
// ============================================================
// KitaCatat — Layout: Header & Navigasi
// Include di setiap halaman dashboard:
// require_once __DIR__ . '/../layout/header.php';
// ============================================================

// Pastikan session aktif
if (session_status() === PHP_SESSION_NONE) session_start();

// Paksa login jika belum
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Auto-logout jika tidak aktif melebihi SESSION_TIMEOUT
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: /login.php?reason=timeout');
        exit;
    }
}
$_SESSION['last_activity'] = time();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$userName    = $_SESSION['user_name'] ?? 'User';

function navActive(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrfToken() ?>">
    <title><?= $pageTitle ?? 'Dashboard' ?> — KitaCatat</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/icon/favicon.ico">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">

    <style>
        /* ======================================================
           CSS Variables & Base
        ====================================================== */
        :root {
            --primary:        #16a34a;
            --primary-light:  #dcfce7;
            --primary-dark:   #15803d;
            --danger:         #dc2626;
            --danger-light:   #fee2e2;
            --warning:        #d97706;
            --warning-light:  #fef3c7;
            --info:           #0891b2;
            --info-light:     #cffafe;

            --bg:             #f8fafc;
            --sidebar-bg:     #0f172a;
            --sidebar-text:   #94a3b8;
            --sidebar-active: #ffffff;
            --sidebar-hover:  #1e293b;
            --sidebar-accent: #16a34a;
            --sidebar-width:  240px;

            --card-bg:        #ffffff;
            --card-border:    #e2e8f0;
            --text-primary:   #0f172a;
            --text-secondary: #64748b;
            --text-muted:     #94a3b8;

            --font-main:  'Plus Jakarta Sans', sans-serif;
            --font-mono:  'DM Mono', monospace;
            --radius:     12px;
            --radius-sm:  8px;
            --shadow:     0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
            --shadow-md:  0 4px 6px rgba(0,0,0,.07), 0 10px 30px rgba(0,0,0,.06);
        }

        * { box-sizing: border-box; }

        body {
            font-family: var(--font-main);
            background: var(--bg);
            color: var(--text-primary);
            margin: 0;
            font-size: 14px;
        }

        /* ======================================================
           Sidebar
        ====================================================== */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            padding: 24px 20px 20px;
            border-bottom: 1px solid #1e293b;
        }

        .sidebar-brand .brand-icon {
            width: 36px; height: 36px;
            background: var(--sidebar-accent);
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #fff;
            margin-bottom: 10px;
        }

        .sidebar-brand .brand-name {
            display: block;
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -.3px;
        }

        .sidebar-brand .brand-sub {
            display: block;
            font-size: 11px;
            color: var(--sidebar-text);
            margin-top: 2px;
        }

        /*.sidebar-nav {*/
        /*    flex: 1;*/
        /*    padding: 16px 12px;*/
        /*    overflow-y: auto;*/
        /*}*/
        
        .sidebar-nav { flex: 1; padding: 14px 12px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #334155 transparent; }
        
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

        .nav-section-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #334155;
            padding: 8px 8px 6px;
            margin-top: 8px;
        }

        .sidebar-nav .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius-sm);
            color: var(--sidebar-text);
            font-size: 13.5px;
            font-weight: 500;
            text-decoration: none;
            transition: all .15s ease;
            margin-bottom: 2px;
        }

        .sidebar-nav .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar-nav .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-nav .nav-link.active {
            background: var(--sidebar-accent);
            color: #fff;
        }

        .sidebar-nav .nav-link .badge-count {
            margin-left: auto;
            background: #1e293b;
            color: var(--sidebar-text);
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 20px;
            font-family: var(--font-mono);
        }

        .sidebar-nav .nav-link.active .badge-count {
            background: rgba(255,255,255,.2);
            color: #fff;
        }

        .sidebar-footer {
            padding: 16px 12px;
            border-top: 1px solid #1e293b;
        }

        .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: var(--radius-sm);
        }

        .sidebar-footer .user-avatar {
            width: 32px; height: 32px;
            background: var(--sidebar-accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar-footer .user-name {
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer .user-role {
            font-size: 11px;
            color: var(--sidebar-text);
        }

        .sidebar-footer .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: var(--radius-sm);
            color: var(--sidebar-text);
            text-decoration: none;
            transition: all .15s;
            flex-shrink: 0;
            margin-left: auto;
        }

        .sidebar-footer .logout-btn:hover {
            background: var(--danger);
            color: #fff;
        }

        /* ======================================================
           Main Content
        ====================================================== */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            background: var(--card-bg);
            border-bottom: 1px solid var(--card-border);
            padding: 0 24px;
            height: 60px;
            display: flex;
            align-items: center;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar .page-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .topbar .breadcrumb {
            margin: 0;
            font-size: 12px;
        }

        .topbar .breadcrumb-item a {
            color: var(--text-secondary);
            text-decoration: none;
        }

        .topbar .breadcrumb-item.active {
            color: var(--text-muted);
        }

        .topbar .ms-auto {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            padding: 6px;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .content-area {
            flex: 1;
            padding: 24px;
        }

        /* ======================================================
           Cards
        ====================================================== */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 16px 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .card-body { padding: 20px; }

        /* ======================================================
           Stat Cards
        ====================================================== */
        .stat-card {
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            box-shadow: var(--shadow);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            height: 100%;
        }

        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-icon.income  { background: var(--primary-light);  color: var(--primary); }
        .stat-icon.expense { background: var(--danger-light);   color: var(--danger); }
        .stat-icon.balance { background: var(--info-light);     color: var(--info); }
        .stat-icon.total   { background: var(--warning-light);  color: var(--warning); }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            font-family: var(--font-mono);
            letter-spacing: -.5px;
        }

        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* ======================================================
           Badge & Pills
        ====================================================== */
        .badge-income  { background: var(--primary-light); color: var(--primary);  font-weight: 600; }
        .badge-expense { background: var(--danger-light);  color: var(--danger);   font-weight: 600; }

        .txn-code {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg);
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid var(--card-border);
        }

        /* ======================================================
           Responsive
        ====================================================== */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.open {
                transform: translateX(0);
                box-shadow: 0 0 0 9999px rgba(0,0,0,.4);
            }
            .main-wrapper {
                margin-left: 0;
            }
            .sidebar-toggle {
                display: block;
            }
            .content-area {
                padding: 16px;
            }
            /* Stat cards di mobile */
            .stat-card {
                padding: 14px;
                gap: 10px;
            }
            .stat-icon {
                width: 36px; height: 36px;
                font-size: 15px;
                flex-shrink: 0;
            }
            .stat-value {
                font-size: 14px;
                letter-spacing: -.3px;
                word-break: break-all;
            }
            .stat-label {
                font-size: 11px;
            }
        }
    </style>

    <?php if (isset($extraHead)) echo $extraHead; ?>
<script>
// Cek unread support message setiap 15 detik
async function checkSupportUnread() {
    try {
        const res  = await fetch('/api/support.php?action=unread_count');
        const data = await res.json();
        const badge = document.getElementById('supportBadge');
        if (badge) {
            badge.style.display = data.count > 0 ? 'inline-block' : 'none';
        }
    } catch(e) {}
}
document.addEventListener('DOMContentLoaded', function() {
    checkSupportUnread();
    setInterval(checkSupportUnread, 15000);
});
</script>
</head>
<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="/assets/img/icon/android-chrome-192x192.png" alt="KitaCatat" style="width:36px;height:36px;border-radius:10px;margin-bottom:10px;display:block;">
        <span class="brand-name">KitaCatat</span>
        <span class="brand-sub">Pencatatan Keuangan WA</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu Utama</div>

        <a href="/dashboard/index.php" class="nav-link <?= navActive('index', $currentPage) ?>">
            <i class="fa-solid fa-chart-pie"></i>
            Dashboard
        </a>
        <a href="/dashboard/transactions.php" class="nav-link <?= navActive('transactions', $currentPage) ?>">
            <i class="fa-solid fa-list-ul"></i>
            Transaksi
        </a>
        <a href="/dashboard/report.php" class="nav-link <?= navActive('report', $currentPage) ?>">
            <i class="fa-solid fa-chart-bar"></i>
            Laporan
        </a>
        
        
        <div class="nav-section-label">Grup</div>
        <a href="/dashboard/groups.php" class="nav-link <?= navActive('groups', $currentPage) ?>">
            <i class="fa-solid fa-users"></i>
            Grup
        </a>
        <a href="/dashboard/rekap_grup.php" class="nav-link <?= navActive('rekap_grup', $currentPage) ?>">
            <i class="fa-solid fa-users-rectangle"></i>
            Rekap Grup
        </a>
        

        <div class="nav-section-label">Pengaturan</div>
      
      
        <a href="/dashboard/scheduled.php" class="nav-link <?= navActive('scheduled', $currentPage) ?>">
            <i class="fa-solid fa-bell"></i>
            Pengingat Rutin
        </a>
        <a href="/dashboard/categories.php" class="nav-link <?= navActive('categories', $currentPage) ?>">
            <i class="fa-solid fa-tags"></i>
            Kategori
        </a>
        <a href="/dashboard/settings.php" class="nav-link <?= navActive('settings', $currentPage) ?>">
            <i class="fa-solid fa-gear"></i>
            Pengaturan Akun
        </a>
        
        <div class="nav-section-label">Komunikasi</div>
        <a href="/dashboard/support.php" class="nav-link <?= navActive('support', $currentPage) ?>" id="supportNavLink">
            <i class="fa-solid fa-headset"></i>
            Hubungi Admin
            <span id="supportBadge" style="display:none;margin-left:auto;background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px">!</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($userName, 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($userName) ?></div>
                <div class="user-role">Personal</div>
            </div>
            <a href="/logout.php" class="logout-btn" title="Keluar">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

<!-- ============================================================
     MAIN WRAPPER
============================================================ -->
<div class="main-wrapper">

    <!-- Topbar -->
    <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars fa-lg"></i>
        </button>
        <img src="/assets/img/icon/android-chrome-192x192.png" alt="KitaCatat" style="width:28px;height:28px;border-radius:6px;">
        <div>
            <h1 class="page-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
        </div>
        <div class="ms-auto">
            <span class="text-muted" style="font-size:12px">
                <i class="fa-regular fa-clock me-1"></i>
                <?= date('d M Y') ?>
            </span>
        </div>
    </div>

    <!-- Content Area -->
    <div class="content-area">