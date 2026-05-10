<?php
// ============================================================
// KitaCatat — API: Support Chat
// GET  ?user_id=X&after=ID  → ambil pesan (polling)
// POST action=send           → kirim pesan (user atau admin)
// POST action=mark_read      → tandai pesan terbaca
// GET  ?action=inbox         → daftar percakapan (admin only)
// GET  ?action=unread_count  → jumlah pesan belum dibaca (user)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/WASender.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$db     = getDB();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($body['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Tentukan siapa yang request: user atau admin
$isAdmin  = isset($_SESSION['admin_id']);
$isUser   = isset($_SESSION['user_id']);

if (!$isAdmin && !$isUser) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$myUserId  = $isUser  ? (int)$_SESSION['user_id']  : 0;
$myAdminId = $isAdmin ? (int)$_SESSION['admin_id'] : 0;

// Jika request dari halaman user (tidak ada user_id di GET) → paksa mode user
// Ini menangani kasus login user + admin di sesi yang sama
if ($isUser && $isAdmin && !isset($_GET['user_id']) && !isset($body['user_id'])) {
    $isAdmin = false; // Treat as user request
}

// ============================================================
// GET — Ambil pesan (polling)
// ============================================================
if ($method === 'GET' && !$action) {
    $targetUserId = $isAdmin ? (int)($_GET['user_id'] ?? 0) : $myUserId;
    $afterId      = (int)($_GET['after'] ?? 0);

    if (!$targetUserId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));

    $stmt = $db->prepare(
        "SELECT * FROM support_messages
         WHERE user_id = ? AND id > ?
         ORDER BY created_at ASC LIMIT 50"
    );
    $stmt->execute([$targetUserId, $afterId]);
    $messages = $stmt->fetchAll();

    // Tandai pesan masuk sebagai terbaca otomatis
    if ($isUser && !empty($messages)) {
        $db->prepare(
            "UPDATE support_messages SET is_read=1
             WHERE user_id=? AND sender='admin' AND is_read=0"
        )->execute([$myUserId]);
    }
    if ($isAdmin && !empty($messages)) {
        $db->prepare(
            "UPDATE support_messages SET is_read=1
             WHERE user_id=? AND sender='user' AND is_read=0"
        )->execute([$targetUserId]);
    }

    exit(json_encode(['success' => true, 'data' => $messages]));
}

// ============================================================
// GET — Inbox semua percakapan (admin only)
// ============================================================
if ($method === 'GET' && $action === 'inbox') {
    if (!$isAdmin) exit(json_encode(['success'=>false,'message'=>'Unauthorized']));

    $stmt = $db->query(
        "SELECT u.id AS user_id, u.name, u.wa_number,
            (SELECT message FROM support_messages sm WHERE sm.user_id=u.id ORDER BY sm.created_at DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM support_messages sm WHERE sm.user_id=u.id ORDER BY sm.created_at DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM support_messages sm WHERE sm.user_id=u.id AND sm.sender='user' AND sm.is_read=0) AS unread_count
         FROM users u
         WHERE EXISTS (SELECT 1 FROM support_messages sm WHERE sm.user_id=u.id)
         ORDER BY last_at DESC"
    );
    exit(json_encode(['success' => true, 'data' => $stmt->fetchAll()]));
}

// ============================================================
// GET — Jumlah pesan belum dibaca (user)
// ============================================================
if ($method === 'GET' && $action === 'unread_count') {
    if (!$isUser) exit(json_encode(['success'=>false,'message'=>'Unauthorized']));

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM support_messages
         WHERE user_id=? AND sender='admin' AND is_read=0"
    );
    $stmt->execute([$myUserId]);
    exit(json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]));
}

// ============================================================
// GET — Cek status sesi support aktif (admin only)
// ============================================================
if ($method === 'GET' && $action === 'session_status') {
    if (!$isAdmin) exit(json_encode(['success'=>false,'message'=>'Unauthorized']));

    $targetUserId = (int)($_GET['user_id'] ?? 0);
    if (!$targetUserId) exit(json_encode(['success'=>false,'active'=>false]));

    $stmt = $db->prepare(
        "SELECT id FROM pending_shared
         WHERE user_id=? AND status='waiting' AND target_groups='__support_session__'
         LIMIT 1"
    );
    $stmt->execute([$targetUserId]);
    $active = (bool)$stmt->fetch();

    exit(json_encode(['success' => true, 'active' => $active]));
}

// ============================================================
// POST — Kirim pesan
// ============================================================
if ($method === 'POST' && ($action === 'send' || isset($body['message']))) {
    $message = trim($body['message'] ?? '');
    if (empty($message)) exit(json_encode(['success'=>false,'message'=>'Pesan tidak boleh kosong.']));

    if ($isUser) {
        // User kirim ke admin
        $stmt = $db->prepare(
            "INSERT INTO support_messages (user_id, sender, message) VALUES (?, 'user', ?)"
        );
        $stmt->execute([$myUserId, $message]);
        $newId = $db->lastInsertId();

        exit(json_encode(['success' => true, 'id' => $newId]));

    } elseif ($isAdmin) {
        // Admin balas ke user
        $targetUserId = (int)($body['user_id'] ?? 0);
        if (!$targetUserId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));

        // Simpan ke DB
        $stmt = $db->prepare(
            "INSERT INTO support_messages (user_id, sender, message) VALUES (?, 'admin', ?)"
        );
        $stmt->execute([$targetUserId, $message]);
        $newId = $db->lastInsertId();

        // Kirim ke WA user
        $userStmt = $db->prepare("SELECT wa_number, name FROM users WHERE id=?");
        $userStmt->execute([$targetUserId]);
        $user = $userStmt->fetch();

        $waResult = ['success' => false];
        if ($user) {
            $waMsg    = "💬 *Pesan dari Admin KitaCatat*\n\n" . $message;
            $waResult = WASender::send($user['wa_number'], $waMsg);
        }

        // Catat di admin_logs
        $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
           ->execute([
               $myAdminId, 'support.reply',
               "user#{$targetUserId} ({$user['name']})",
               substr($message, 0, 100),
               $_SERVER['REMOTE_ADDR'] ?? ''
           ]);

        exit(json_encode([
            'success'    => true,
            'id'         => $newId,
            'wa_sent'    => $waResult['success'],
        ]));
    }
}

// ============================================================
// POST — Admin tutup sesi support user
// ============================================================
if ($method === 'POST' && $action === 'close_session') {
    if (!$isAdmin) exit(json_encode(['success'=>false,'message'=>'Unauthorized']));

    $targetUserId = (int)($body['user_id'] ?? 0);
    if (!$targetUserId) exit(json_encode(['success'=>false,'message'=>'User tidak valid.']));

    $db->prepare(
        "UPDATE pending_shared SET status='confirmed', resolved_at=NOW()
         WHERE user_id=? AND status='waiting' AND target_groups='__support_session__'"
    )->execute([$targetUserId]);

    // Kirim notif WA ke user
    require_once __DIR__ . '/../core/WASender.php';
    $stmtUser = $db->prepare("SELECT wa_number FROM users WHERE id=?");
    $stmtUser->execute([$targetUserId]);
    $userRow = $stmtUser->fetch();
    if ($userRow) {
        WASender::send($userRow['wa_number'],
            "🔒 Sesi chat dengan admin telah ditutup oleh admin.\n" .
            "Kamu bisa mulai mencatat transaksi seperti biasa."
        );
    }

    exit(json_encode(['success' => true]));
}

http_response_code(405);
exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
