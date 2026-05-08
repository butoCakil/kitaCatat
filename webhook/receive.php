<?php
// ============================================================
// KitaCatat — Webhook Entry Point
// URL ini yang didaftarkan ke Fonnte → Dashboard → Devices → Webhook
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/WASender.php';
require_once __DIR__ . '/../core/NLPParser.php';
require_once __DIR__ . '/../core/CommandHandler.php';

header('Content-Type: application/json; charset=utf-8');

// ------------------------------------------------------------
// 0. Validasi token webhook
// ------------------------------------------------------------
$webhookToken = getAppSetting('webhook_secret', '');
$incomingToken = $_GET['token'] ?? '';

if (empty($webhookToken) || $incomingToken !== $webhookToken) {
    http_response_code(401);
    exit(json_encode(['status' => false, 'message' => 'Unauthorized']));
}

// ------------------------------------------------------------
// 1. Ambil payload dari Fonnte
// ------------------------------------------------------------
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Abaikan jika payload kosong atau bukan JSON valid
if (!$data) {
    http_response_code(400);
    exit(json_encode(['status' => false, 'message' => 'Invalid payload']));
}

// ------------------------------------------------------------
// 2. Ekstrak field dari payload Fonnte
// Referensi payload nyata:
// {"device":"6281249139594","pengirim":"6282241863393","sender":"6282241863393",
//  "message":"halo bos","pesan":"halo bos","name":"MasBen","type":"text", ...}
// ------------------------------------------------------------
$device   = $data['device']   ?? '';                          // nomor WA device kita sendiri
$waNumber = $data['pengirim'] ?? $data['sender'] ?? '';       // nomor WA pengirim (sudah format 628xxx)
$message  = trim($data['message'] ?? $data['pesan'] ?? '');   // isi pesan
$name     = $data['name']     ?? '';                          // nama kontak pengirim
$type     = $data['type']     ?? 'text';                      // tipe pesan: text, image, dll
$isGroup  = $data['isgroup']  ?? false;                       // true jika dari grup WA

// DEBUG SEMENTARA — hapus setelah selesai
// file_put_contents(__DIR__ . '/../logs/debug_payload.log', date('Y-m-d H:i:s') . ' | ' . $json . PHP_EOL, FILE_APPEND);

// DEBUG SEMENTARA — hapus setelah konfirmasi
// error_log('[DEBUG] device=' . $device . ' | waNumber=' . $waNumber . ' | msg=' . substr($message, 0, 50));

// Abaikan pesan dari grup WA (sistem hanya untuk chat personal)
if ($isGroup) {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Group message ignored']));
}

// Abaikan pesan bukan teks (gambar, video, dll) — belum didukung
if ($type !== 'text') {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Non-text message ignored']));
}

// Abaikan pesan non-teks (Fonnte mengganti isinya dengan "non-text message")
if ($message === 'non-text message') {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Non-text message ignored']));
}

// Abaikan pesan kosong atau nomor tidak valid
if (empty($message) || empty($waNumber)) {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Ignored']));
}

// Abaikan pesan yang hanya berisi emoji (tanpa angka)
// Cek: hapus semua emoji & whitespace, jika tidak tersisa teks bermakna → abaikan
$strippedEmoji = trim(preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE00}-\x{FEFF}]|[\x{1F300}-\x{1F9FF}]|\x{200D}|\x{FE0F}|[\x{1F1E0}-\x{1F1FF}]/u', '', $message));
$hasAngka = preg_match('/\d/', $strippedEmoji);

$konfirmasiWords = ['ya', 'yes', 'ok', 'iya', 'y', 'tidak', 'no', 'batal', 'n'];
$isKonfirmasi = in_array(strtolower(trim($strippedEmoji)), $konfirmasiWords);

if (!$isKonfirmasi && (empty($strippedEmoji) || (!$hasAngka && mb_strlen($strippedEmoji) <= 3))) {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Emoji-only or emoji without number ignored']));
}

// Abaikan pesan dari device sendiri (echo/self message)
if ($device === $waNumber) {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Self message ignored']));
}

// Abaikan pesan tanpa nama pengirim — biasanya Fonnte retry dari pesan keluar bot
if (empty($name)) {
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'Empty name ignored']));
}

// ------------------------------------------------------------
// 3. Log pesan masuk
// ------------------------------------------------------------
if (LOG_INCOMING) {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logLine = date('Y-m-d H:i:s') . " | FROM: $waNumber | NAME: $name | MSG: $message" . PHP_EOL;
    file_put_contents($logDir . '/incoming.log', $logLine, FILE_APPEND);
}

// ------------------------------------------------------------
// 4. Cek apakah nomor WA terdaftar
// ------------------------------------------------------------
$db   = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE wa_number = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$waNumber]);
$user = $stmt->fetch();

// if (!$user) {
//     // Nomor belum terdaftar — kirim pesan panduan
//     WASender::send($waNumber,
//         "Halo! 👋\n" .
//         "Nomor kamu belum terdaftar di *KitaCatat*.\n\n" .
//         "Silakan daftar terlebih dahulu melalui:\n" .
//         APP_URL . "/register.php\n\n" .
//         "Atau hubungi admin untuk aktivasi."
//     );
//     http_response_code(200);
//     exit(json_encode(['status' => true, 'message' => 'User not found']));
// }

if (!$user) {
    // Nomor belum terdaftar — abaikan tanpa respons
    http_response_code(200);
    exit(json_encode(['status' => true, 'message' => 'User not found, ignored']));
}

// ------------------------------------------------------------
// 5. Proses pesan melalui CommandHandler
// ------------------------------------------------------------
try {
    $handler = new CommandHandler($user, $waNumber, $message);
    $handler->handle();
    // error_log('[DEBUG] handle() selesai tanpa exception');
} catch (Throwable $e) {
    error_log('[DEBUG] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (DEBUG_MODE) {
        error_log('[KitaCatat] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    WASender::send($waNumber,
        "⚠️ Terjadi kesalahan sistem.\n" .
        "Silakan coba lagi atau hubungi admin."
    );
}

http_response_code(200);
echo json_encode(['status' => true]);
