<?php
require_once __DIR__ . '/../../config/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$db      = getDB();
$adminId = (int)$_SESSION['admin_id'];
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $body['action'] ?? '';

// ============================================================
// Action: clear_incoming
// ============================================================
if ($action === 'clear_incoming') {
    $logFile = __DIR__ . '/../../logs/incoming.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId, 'logs.clear_incoming', 'incoming.log', 'Log dibersihkan oleh admin', $_SERVER['REMOTE_ADDR'] ?? '']);
    exit(json_encode(['success' => true]));
}

// ============================================================
// Action: clear_outgoing
// ============================================================
if ($action === 'clear_outgoing') {
    $logFile = __DIR__ . '/../../logs/outgoing.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
    }
    $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId, 'logs.clear_outgoing', 'outgoing.log', 'Log dibersihkan oleh admin', $_SERVER['REMOTE_ADDR'] ?? '']);
    exit(json_encode(['success' => true]));
}

// ============================================================
// Action: clear_error_log
// ============================================================
if ($action === 'clear_error_log') {
    $relPath = $body['path'] ?? '';

    if ($relPath === '') {
        http_response_code(400);
        exit(json_encode(['success' => false, 'message' => 'Path tidak boleh kosong.']));
    }

    $rootDir    = realpath(__DIR__ . '/../../');
    $realTarget = realpath($rootDir . '/' . ltrim($relPath, '/'));

    // Validasi: harus berada di dalam root project dan nama file mengandung "error_log"
    $basename  = basename($realTarget);
    $extension = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
    $isAllowed = $extension === 'log' || stripos($basename, 'error_log') !== false;
    
    if (
        !$realTarget ||
        strpos($realTarget, $rootDir) !== 0 ||
        !$isAllowed ||
        !file_exists($realTarget)
    ) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'File tidak valid atau tidak ditemukan.']));
    }

    file_put_contents($realTarget, '');

    $db->prepare("INSERT INTO admin_logs (admin_id,action,target,note,ip) VALUES (?,?,?,?,?)")
       ->execute([$adminId, 'logs.clear_error_log', $relPath, 'Error log dibersihkan oleh admin', $_SERVER['REMOTE_ADDR'] ?? '']);

    exit(json_encode(['success' => true]));
}

// ============================================================
// Fallback
// ============================================================
http_response_code(400);
exit(json_encode(['success' => false, 'message' => 'Action tidak dikenal.']));
