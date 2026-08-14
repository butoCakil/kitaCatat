<?php
// ============================================================
// KitaCatat — Route Check (dipanggil kitacatat-bridge, BUKAN user)
// POST JSON: {"phone_number":"628xxx"}  + ?token=<webhook_secret>
// Respons  : {"success":true,"is_user":true|false}
//
// Dipakai WA Gateway untuk auto-detect: "nomor ini user kitaCatat?"
// Harus cepat — gateway hanya menunggu total 2 detik.
// Taruh file ini di folder webhook/ (sejajar receive.php).
// ============================================================
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Validasi token — sama seperti receive.php (reuse webhook_secret)
$webhookToken  = getAppSetting('webhook_secret', '');
$incomingToken = $_GET['token'] ?? '';
if (empty($webhookToken) || $incomingToken !== $webhookToken) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

$data  = json_decode(file_get_contents('php://input'), true) ?: [];
$phone = preg_replace('/\D/', '', (string)($data['phone_number'] ?? ''));

if ($phone === '') {
    exit(json_encode(['success' => true, 'is_user' => false]));
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT 1 FROM users WHERE wa_number = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$phone]);
    $isUser = (bool) $stmt->fetchColumn();
    exit(json_encode(['success' => true, 'is_user' => $isUser]));
} catch (Throwable $e) {
    error_log('[route_check] ' . $e->getMessage());
    // Saat error DB, jawab false — user akan diarahkan ke menu, bukan macet
    exit(json_encode(['success' => true, 'is_user' => false]));
}
