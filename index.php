<?php
// KitaCatat — Root Index
// Sudah login → dashboard | Belum login → landing page
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: /dashboard/index.php');
} else {
    header('Location: /landing.html');
}
exit;