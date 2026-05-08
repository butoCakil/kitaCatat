<?php
// ============================================================
// KitaCatat — API: Groups
// GET    ?id=X&action=members    → list anggota
// POST   (body)                  → buat grup baru
// POST   ?id=X&action=add_member → tambah anggota
// PUT    ?id=X                   → edit grup
// DELETE ?id=X                   → hapus grup (owner)
// DELETE ?id=X&action=leave      → keluar dari grup (member)
// DELETE ?id=X&action=remove_member → keluarkan anggota (owner)
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
$id     = isset($_GET['id'])     ? (int)$_GET['id']     : null;
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ============================================================
// GET — List anggota grup
// ============================================================
if ($method === 'GET' && $id && $action === 'members') {
    // Pastikan user adalah anggota grup ini
    $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Akses ditolak.']));

    // Ambil anggota + alias yang diberikan oleh user yang sedang login
    $stmt = $db->prepare(
        "SELECT u.id AS user_id, u.name, u.wa_number, gm.role,
                gma.alias AS my_alias
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         LEFT JOIN group_member_aliases gma
             ON gma.group_id = gm.group_id
             AND gma.giver_user_id = :my_id
             AND gma.target_user_id = gm.user_id
         WHERE gm.group_id = :group_id
         ORDER BY gm.role DESC, u.name ASC"
    );
    $stmt->execute([':my_id' => $userId, ':group_id' => $id]);
    exit(json_encode(['success' => true, 'data' => $stmt->fetchAll()]));
}

// ============================================================
// POST — Buat grup baru
// ============================================================
if ($method === 'POST' && !$action) {
    $name   = trim($body['name']  ?? '');
    $alias  = strtolower(trim($body['alias'] ?? ''));
    $shared = isset($body['is_shared']) ? (int)$body['is_shared'] : 0;

    if (empty($name)) exit(json_encode(['success'=>false,'message'=>'Nama grup wajib diisi.']));

    // Cek alias unik
    if ($alias) {
        $chk = $db->prepare("SELECT id FROM groups WHERE alias = ?");
        $chk->execute([$alias]);
        if ($chk->fetch()) exit(json_encode(['success'=>false,'message'=>'Alias sudah digunakan grup lain.']));
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO groups (name, alias, is_shared, created_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$name, $alias ?: null, $shared, $userId]);
        $groupId = (int)$db->lastInsertId();

        // Tambah user sebagai owner
        $stmt2 = $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')");
        $stmt2->execute([$groupId, $userId]);

        $db->commit();
        exit(json_encode(['success' => true, 'group_id' => $groupId]));
    } catch (Exception $e) {
        $db->rollBack();
        exit(json_encode(['success'=>false,'message'=>'Gagal membuat grup.']));
    }
}

// ============================================================
// POST — Tambah anggota
// ============================================================
if ($method === 'POST' && $id && $action === 'add_member') {
    // Pastikan user adalah owner
    $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND role='owner'");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Hanya owner yang bisa menambah anggota.']));

    $waNumber = trim($body['wa_number'] ?? '');
    $waNumber = preg_replace('/[^0-9]/', '', $waNumber);
    if (empty($waNumber)) exit(json_encode(['success'=>false,'message'=>'Nomor WA tidak valid.']));

    // Cari user
    $stmtUser = $db->prepare("SELECT id FROM users WHERE wa_number = ? AND is_active = 1");
    $stmtUser->execute([$waNumber]);
    $member = $stmtUser->fetch();
    if (!$member) exit(json_encode(['success'=>false,'message'=>'Nomor WA belum terdaftar di KitaCatat.']));

    // Cek sudah jadi anggota?
    $stmtChk = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $stmtChk->execute([$id, $member['id']]);
    if ($stmtChk->fetch()) exit(json_encode(['success'=>false,'message'=>'Nomor tersebut sudah menjadi anggota.']));

    $stmt = $db->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $stmt->execute([$id, $member['id']]);
    exit(json_encode(['success' => true]));
}

// ============================================================
// PUT — Edit grup
// ============================================================
if ($method === 'PUT' && $id && !$action) {
    $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND role='owner'");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Hanya owner yang bisa mengedit.']));

    $name   = trim($body['name']  ?? '');
    $alias  = strtolower(trim($body['alias'] ?? ''));
    $shared = isset($body['is_shared']) ? (int)$body['is_shared'] : 0;

    if (empty($name)) exit(json_encode(['success'=>false,'message'=>'Nama grup wajib diisi.']));

    // Cek alias unik (exclude grup ini sendiri)
    if ($alias) {
        $chk = $db->prepare("SELECT id FROM groups WHERE alias=? AND id != ?");
        $chk->execute([$alias, $id]);
        if ($chk->fetch()) exit(json_encode(['success'=>false,'message'=>'Alias sudah digunakan grup lain.']));
    }

    $stmt = $db->prepare(
        "UPDATE groups SET name=?, alias=?, is_shared=?, updated_at=NOW() WHERE id=?"
    );
    $stmt->execute([$name, $alias ?: null, $shared, $id]);
    exit(json_encode(['success' => true]));
}

// ============================================================
// DELETE — Hapus grup / keluar / keluarkan anggota
// ============================================================
if ($method === 'DELETE' && $id) {

    // Keluar dari grup (member)
    if ($action === 'leave') {
        $check = $db->prepare("SELECT role FROM group_members WHERE group_id=? AND user_id=?");
        $check->execute([$id, $userId]);
        $row = $check->fetch();
        if (!$row) exit(json_encode(['success'=>false,'message'=>'Anda bukan anggota grup ini.']));
        if ($row['role'] === 'owner') exit(json_encode(['success'=>false,'message'=>'Owner tidak bisa keluar. Hapus grup atau pindahkan ownership terlebih dahulu.']));

        $stmt = $db->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
        $stmt->execute([$id, $userId]);
        exit(json_encode(['success' => true]));
    }

    // Keluarkan anggota (owner)
    if ($action === 'remove_member') {
        $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND role='owner'");
        $check->execute([$id, $userId]);
        if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Hanya owner yang bisa mengeluarkan anggota.']));

        $targetId = (int)($body['user_id'] ?? 0);
        if ($targetId === $userId) exit(json_encode(['success'=>false,'message'=>'Tidak bisa mengeluarkan diri sendiri.']));

        $stmt = $db->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?");
        $stmt->execute([$id, $targetId]);
        exit(json_encode(['success' => true]));
    }

    // Hapus grup (owner)
    $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=? AND role='owner'");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Hanya owner yang bisa menghapus grup.']));

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM group_members WHERE group_id=?")->execute([$id]);
        $db->prepare("DELETE FROM groups WHERE id=?")->execute([$id]);
        $db->commit();
        exit(json_encode(['success' => true]));
    } catch (Exception $e) {
        $db->rollBack();
        exit(json_encode(['success'=>false,'message'=>'Gagal menghapus grup.']));
    }
}

// ============================================================
// PUT — Update alias personal (semua anggota bisa set alias untuk anggota lain)
// ============================================================
if ($method === 'PUT' && $id && $action === 'update_alias') {
    // Pastikan user adalah anggota grup ini
    $check = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $check->execute([$id, $userId]);
    if (!$check->fetch()) exit(json_encode(['success'=>false,'message'=>'Anda bukan anggota grup ini.']));

    $targetUserId = (int)($body['user_id'] ?? 0);
    $alias        = strtolower(trim($body['alias'] ?? ''));

    if (!$targetUserId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));
    if ($targetUserId === $userId) exit(json_encode(['success'=>false,'message'=>'Tidak bisa memberi alias untuk diri sendiri.']));

    // Pastikan target juga anggota grup ini
    $chkTarget = $db->prepare("SELECT id FROM group_members WHERE group_id=? AND user_id=?");
    $chkTarget->execute([$id, $targetUserId]);
    if (!$chkTarget->fetch()) exit(json_encode(['success'=>false,'message'=>'User target bukan anggota grup ini.']));

    if ($alias) {
        // Upsert: update jika sudah ada, insert jika belum
        $stmt = $db->prepare(
            "INSERT INTO group_member_aliases (group_id, giver_user_id, target_user_id, alias)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE alias = VALUES(alias), updated_at = NOW()"
        );
        $stmt->execute([$id, $userId, $targetUserId, $alias]);
    } else {
        // Alias dikosongkan → hapus record
        $stmt = $db->prepare(
            "DELETE FROM group_member_aliases
             WHERE group_id=? AND giver_user_id=? AND target_user_id=?"
        );
        $stmt->execute([$id, $userId, $targetUserId]);
    }

    exit(json_encode(['success' => true]));
}

http_response_code(405);
exit(json_encode(['success' => false, 'message' => 'Method not allowed']));