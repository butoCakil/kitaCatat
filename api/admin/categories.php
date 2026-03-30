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

if ($action === 'create') {
    $name = trim($body['name'] ?? '');
    $type = $body['type'] ?? '';
    $icon = trim($body['icon'] ?? 'fa-tag');
    if (!$name) exit(json_encode(['success'=>false,'message'=>'Nama wajib diisi.']));
    if (!in_array($type,['income','expense'])) exit(json_encode(['success'=>false,'message'=>'Tipe tidak valid.']));

    $db->prepare("INSERT INTO categories (name,type,icon,is_default,user_id) VALUES (?,?,?,1,NULL)")
       ->execute([$name,$type,$icon]);
    adminLog($db,$adminId,'category.create',"$name ($type)",'');
    exit(json_encode(['success'=>true]));
}

if ($action === 'update') {
    $id   = (int)($body['id'] ?? 0);
    $name = trim($body['name'] ?? '');
    $type = $body['type'] ?? '';
    $icon = trim($body['icon'] ?? 'fa-tag');
    if (!$id || !$name) exit(json_encode(['success'=>false,'message'=>'Data tidak lengkap.']));

    $db->prepare("UPDATE categories SET name=?,type=?,icon=? WHERE id=? AND is_default=1 AND user_id IS NULL")
       ->execute([$name,$type,$icon,$id]);
    adminLog($db,$adminId,'category.update',"cat#$id → $name",'');
    exit(json_encode(['success'=>true]));
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) exit(json_encode(['success'=>false,'message'=>'ID tidak valid.']));

    $stmt = $db->prepare("SELECT name FROM categories WHERE id=? AND is_default=1 AND user_id IS NULL");
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat) exit(json_encode(['success'=>false,'message'=>'Kategori tidak ditemukan.']));

    // Lepas parent_id sub-kategori user yang mengacu ke kategori ini
    $db->prepare("UPDATE categories SET parent_id=NULL WHERE parent_id=?")->execute([$id]);

    // Set transaksi yang pakai kategori ini ke NULL
    $db->prepare("UPDATE transactions SET category_id=NULL WHERE category_id=?")->execute([$id]);
    $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    adminLog($db,$adminId,'category.delete',"cat#$id ({$cat['name']})",'');
    exit(json_encode(['success'=>true]));
}

http_response_code(400);
exit(json_encode(['success'=>false,'message'=>'Action tidak dikenal.']));
