<?php
// ============================================================
// KitaCatat — API: Categories
// ============================================================
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// POST — Tambah kategori custom
if ($method === 'POST') {
    $name     = trim($body['name'] ?? '');
    $type     = $body['type'] ?? '';
    $icon     = trim($body['icon'] ?? 'fa-tag');
    $parentId = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;

    if (empty($name)) exit(json_encode(['success'=>false,'message'=>'Nama wajib diisi.']));
    if (!in_array($type, ['income','expense'])) exit(json_encode(['success'=>false,'message'=>'Tipe tidak valid.']));

    // Validasi parent_id: harus kategori global (is_default=1, user_id IS NULL)
    if ($parentId !== null) {
        $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND is_default=1 AND user_id IS NULL");
        $chk->execute([$parentId]);
        if (!$chk->fetch()) $parentId = null;
    }

    $stmt = $db->prepare(
        "INSERT INTO categories (name, type, icon, is_default, user_id, parent_id) VALUES (?, ?, ?, 0, ?, ?)"
    );
    $stmt->execute([$name, $type, $icon, $userId, $parentId]);
    exit(json_encode(['success' => true]));
}

// PUT — Edit kategori custom milik user
if ($method === 'PUT' && $id) {
    $check = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Kategori tidak ditemukan atau bukan milik Anda.']));

    $name     = trim($body['name'] ?? '');
    $type     = $body['type'] ?? '';
    $icon     = trim($body['icon'] ?? 'fa-tag');
    $parentId = !empty($body['parent_id']) ? (int)$body['parent_id'] : null;

    if (empty($name)) exit(json_encode(['success'=>false,'message'=>'Nama wajib diisi.']));
    if (!in_array($type, ['income','expense'])) exit(json_encode(['success'=>false,'message'=>'Tipe tidak valid.']));

    // Validasi parent_id
    if ($parentId !== null) {
        $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND is_default=1 AND user_id IS NULL");
        $chk->execute([$parentId]);
        if (!$chk->fetch()) $parentId = null;
    }

    $stmt = $db->prepare("UPDATE categories SET name=?, type=?, icon=?, parent_id=? WHERE id=? AND user_id=?");
    $stmt->execute([$name, $type, $icon, $parentId, $id, $userId]);
    exit(json_encode(['success' => true]));
}

// DELETE — Hapus kategori custom milik user
if ($method === 'DELETE' && $id) {
    $check = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=? AND is_default=0");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Kategori tidak bisa dihapus.']));

    // Set transaksi yang pakai kategori ini ke NULL
    $db->prepare("UPDATE transactions SET category_id=NULL WHERE category_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id, $userId]);
    exit(json_encode(['success' => true]));
}

http_response_code(405);
exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
