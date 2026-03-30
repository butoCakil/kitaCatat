<?php
if (session_status() === PHP_SESSION_NONE) session_start();
unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_user']);
session_destroy();
header('Location: /admin/login.php');
exit;
