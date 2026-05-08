<?php
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success'=>false,'message'=>'Unauthorized']));
}

$db      = getDB();
$adminId = (int)$_SESSION['admin_id'];
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $body['action'] ?? '';

function adminLog(PDO $db, int $adminId, string $action, string $target, string $note=''):void {
    $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId,$action,$target,$note,$_SERVER['REMOTE_ADDR']??'']);
}

// Toggle aktif/nonaktif user
if ($action === 'toggle') {
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));

    $stmt = $db->prepare("SELECT name, is_active FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) exit(json_encode(['success'=>false,'message'=>'User tidak ditemukan.']));

    $newStatus = $user['is_active'] ? 0 : 1;
    $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$newStatus, $userId]);

    $action_label = $newStatus ? 'user.activate' : 'user.deactivate';
    adminLog($db, $adminId, $action_label, "user#$userId ({$user['name']})", '');

    exit(json_encode(['success'=>true, 'is_active'=>$newStatus]));
}

// Reset password user
if ($action === 'reset_password') {
    $userId   = (int)($body['user_id']  ?? 0);
    $password = trim($body['password']  ?? '');

    if (!$userId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));
    if (strlen($password) < 6) exit(json_encode(['success'=>false,'message'=>'Password minimal 6 karakter.']));

    $stmt = $db->prepare("SELECT name FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) exit(json_encode(['success'=>false,'message'=>'User tidak ditemukan.']));

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?")->execute([$hash, $userId]);

    adminLog($db, $adminId, 'user.reset_password', "user#$userId ({$user['name']})", 'Password direset oleh admin');

    exit(json_encode(['success'=>true]));
}

http_response_code(400);
exit(json_encode(['success'=>false,'message'=>'Action tidak dikenal.']));
