<?php
// ============================================================
// KitaCatat — CommandHandler
// Router utama: menerima pesan, parsing, lalu jalankan aksi
// ============================================================

require_once __DIR__ . '/NLPParser.php';
require_once __DIR__ . '/WASender.php';
require_once __DIR__ . '/TransactionManager.php';
require_once __DIR__ . '/RekapBuilder.php';

class CommandHandler
{
    private array  $user;
    private string $waNumber;
    private string $message;
    private PDO    $db;

    // Key untuk session saldo check di tabel pending (reuse pending_shared)
    private const SALDO_PENDING_KEY = '__saldo_check__';

    public function __construct(array $user, string $waNumber, string $message)
    {
        $this->user      = $user;
        $this->waNumber  = $waNumber;
        $this->message   = $message;
        $this->db        = getDB();
    }

    public function handle(): void
    {
        // STEP 0: Cek apakah ada sesi support aktif
        $supportSession = $this->getActiveSupportSession();
        if ($supportSession) {
            $this->handleSupportSessionMessage();
            return;
        }
        // ------------------------------------------------------------
        // STEP 1a-000: Cek apakah ada pending deskripsi transaksi
        // ------------------------------------------------------------
        $pendingDesc = $this->getPendingDesc();
        if ($pendingDesc) {
            $msg = trim($this->message);
            $lower = strtolower($msg);

            if (in_array($lower, ['batal', 'cancel', 'tidak'])) {
                $this->db->prepare(
                    "UPDATE pending_shared SET status='cancelled', resolved_at=NOW() WHERE id=?"
                )->execute([$pendingDesc['id']]);
                WASender::send($this->waNumber, "❌ Pencatatan dibatalkan.");
                return;
            }

            // Gunakan pesan ini sebagai deskripsi
            $data = json_decode($pendingDesc['target_groups'], true);
            $this->db->prepare(
                "UPDATE pending_shared SET status='confirmed', resolved_at=NOW() WHERE id=?"
            )->execute([$pendingDesc['id']]);

            $manager = new TransactionManager($this->user, $this->db);
            $categories = $this->getUserCategories();
            $parsed = NLPParser::parse($msg . ' ' . $data['amount'], $categories, (int)$this->user['id'], $this->db);

            $result = $manager->save([
                'type'          => $data['type'],
                'amount'        => $data['amount'],
                'description'   => $msg,
                'category_name' => $parsed['category_name'] ?? 'Lainnya',
            ], $data['raw_message'], $data['group_id'] ?? null);

            if ($result['success']) {
                $trx = $result['transaction'];
                $sharedGroups = $data['group_id'] ? [] : $this->getSharedGroups();
                $replyMsg = WASender::buildTransactionMessage($trx, $sharedGroups);
                WASender::send($this->waNumber, $replyMsg);
                if (!empty($sharedGroups)) {
                    $this->createPendingShared($trx['id'], $trx['unique_code'], $sharedGroups);
                }
            } else {
                WASender::send($this->waNumber, "⚠️ Gagal menyimpan catatan.");
            }
            return;
        }

        // ------------------------------------------------------------
        // STEP 1a-00: Cek apakah ada scheduled log yang menunggu nominal
        // ------------------------------------------------------------
        $awaitingLog = $this->getAwaitingAmountLog();
        if ($awaitingLog) {
            $amount = NLPParser::extractAmountFromText($this->message);
            $msg    = strtolower(trim($this->message));

            if (in_array($msg, ['batal', 'cancel', 'tidak'])) {
                // Batalkan, kembalikan ke pending biasa
                $this->db->prepare(
                    "UPDATE scheduled_logs SET awaiting_amount = 0 WHERE id = ?"
                )->execute([$awaitingLog['id']]);
                WASender::send($this->waNumber, "❌ Input nominal dibatalkan. Pengingat *" . $awaitingLog['title'] . "* tetap pending.");
                return;
            }

            if ($amount > 0) {
                // Proses nominal
                $this->db->prepare(
                    "UPDATE scheduled_logs SET awaiting_amount = 0 WHERE id = ?"
                )->execute([$awaitingLog['id']]);
                $this->message = $this->message; // tetap asli
                $this->handleScheduledResponse($awaitingLog, $amount);
                return;
            }

            // Input tidak dikenali sebagai nominal
            WASender::send($this->waNumber,
                "⚠️ Nominal tidak terbaca untuk *" . $awaitingLog['title'] . "*.\n" .
                "Contoh: _50rb_ atau _350.000_\n" .
                "Atau balas *batal* untuk membatalkan."
            );
            return;
        }

        // ------------------------------------------------------------
        // STEP 1a-0: Cek apakah ada pending pengingat terjadwal
        // ------------------------------------------------------------
        $pendingScheduledLogs = $this->getPendingScheduledLogs();
        if (!empty($pendingScheduledLogs)) {
            $raw = trim($this->message);
            // Deteksi format: angka di awal diikuti spasi dan jawaban, contoh "2 ya" / "1 50rb"
            if (preg_match('/^(\d+)\s+(.+)$/s', $raw, $m)) {
                $no  = (int)$m[1];
                $ans = trim($m[2]);
                // Ambil log ke-N (1-based), fallback ke log pertama jika out of range
                // Cari log berdasarkan display_order yang dikunci saat kirim
                $log = null;
                foreach ($pendingScheduledLogs as $l) {
                    if ((int)$l['display_order'] === $no) {
                        $log = $l;
                        break;
                    }
                }
                // Fallback ke log pertama jika nomor tidak ditemukan
                if ($log === null) {
                    $log = $pendingScheduledLogs[0];
                }
                // Override message dengan jawaban saja (tanpa prefix angka)
                $this->message = $ans;
                $this->handleScheduledResponse($log);
                return;
            }
            // Format bebas → lanjut flow normal (catat transaksi, dll)
        }

        // ------------------------------------------------------------
        // STEP 1a: Cek apakah ada pending konfirmasi hapus
        // ------------------------------------------------------------
        $pendingHapus = $this->getPendingHapusKonfirmasi();
        if ($pendingHapus) {
            $jawaban = strtolower(trim($this->message));
            $code    = $pendingHapus['target_groups']; // reuse kolom untuk simpan unique_code

            // Hapus pending dulu
            $this->db->prepare(
                "UPDATE pending_shared SET status='confirmed', resolved_at=NOW() WHERE id=?"
            )->execute([$pendingHapus['id']]);

            if (in_array($jawaban, ['ya', 'yes', 'hapus', 'ok', 'iya'])) {
                $manager = new TransactionManager($this->user, $this->db);
                $result  = $manager->softDelete($code);
                if ($result['success']) {
                    WASender::send($this->waNumber, "🗑️ Catatan [{$code}] berhasil dihapus.");
                } else {
                    WASender::send($this->waNumber, "⚠️ " . ($result['message'] ?? 'Gagal menghapus.'));
                }
            } else {
                WASender::send($this->waNumber, "✅ Penghapusan [{$code}] dibatalkan.");
            }
            return;
        }

        // ------------------------------------------------------------
        // STEP 1b: Cek apakah ada pending saldo check yang menunggu deskripsi
        // ------------------------------------------------------------
        $pendingSaldo = $this->getPendingSaldoCheck();

        if ($pendingSaldo) {
            $msg = strtolower(trim($this->message));

            if ($msg === 'batal' || $msg === 'cancel' || $msg === 'tidak') {
                // Batalkan
                $this->db->prepare(
                    "UPDATE pending_shared SET status='cancelled', resolved_at=NOW() WHERE id=?"
                )->execute([$pendingSaldo['id']]);
                WASender::send($this->waNumber, "❌ Penyesuaian saldo dibatalkan.");
                return;
            }

            // Anggap pesan ini adalah deskripsi selisih
            $saldoData = json_decode($pendingSaldo['target_groups'], true);
            $selisih   = (int)($saldoData['selisih'] ?? 0);
            $desc      = trim($this->message);

            if ($selisih !== 0 && !empty($desc)) {
                $type   = $selisih > 0 ? 'income' : 'expense';
                $amount = abs($selisih);

                // Resolve kategori otomatis dari deskripsi
                $categories = $this->getUserCategories();
                $parsed = NLPParser::parse($desc . ' ' . $amount, $categories, (int)$this->user['id'], $this->db);
                $categoryName = $parsed['category_name'] ?? 'Lainnya';

                // Simpan transaksi penyesuaian
                $manager = new TransactionManager($this->user, $this->db);
                $trxDate = $saldoData['trx_date'] ?? null; // null = hari ini (saldo check biasa)
                $result  = $manager->save([
                    'type'          => $type,
                    'amount'        => $amount,
                    'description'   => 'Penyesuaian saldo: ' . $desc,
                    'category_name' => $categoryName,
                    'created_at'    => $trxDate,
                ], 'saldo_check: ' . $this->message);

                // Hapus pending
                $this->db->prepare(
                    "UPDATE pending_shared SET status='confirmed', resolved_at=NOW() WHERE id=?"
                )->execute([$pendingSaldo['id']]);

                if ($result['success']) {
                    $trx  = $result['transaction'];
                    $icon = $type === 'income' ? '📈' : '📉';
                    WASender::send($this->waNumber,
                        "✅ Saldo disesuaikan!
" .
                        "[{$trx['unique_code']}]
" .
                        "{$icon} " . ($type === 'income' ? 'Pemasukan' : 'Pengeluaran') . ": " .
                        WASender::formatRupiah($amount) . "
" .
                        "📝 Penyesuaian saldo: {$desc}
" .
                        "🏷️ {$trx['category_name']}"
                    );
                } else {
                    WASender::send($this->waNumber, "⚠️ Gagal menyimpan penyesuaian saldo.");
                }
                return;
            }
        }

        // STEP 1b: Cek apakah ada pending_shared yang menunggu konfirmasi
        // Jika ada, dan pesan ini bukan "NS" → konfirmasi dulu sebelum
        // memproses pesan baru
        // ------------------------------------------------------------
        $pending = $this->getPendingShared();

        if ($pending) {
            $isNS = $this->isNotSharedCommand($this->message);

            if ($isNS) {
                // User tidak mau share → batalkan
                $this->cancelPendingShared($pending['id']);
                WASender::send($this->waNumber,
                    "✅ Oke, catatan [{$pending['unique_code']}] tidak di-share ke grup."
                );
                return; // Selesai, tidak proses pesan lebih lanjut
            } else {
                // Pesan berikutnya masuk → anggap setuju, konfirmasi share
                $this->confirmPendingShared($pending);
            }
        }

        // ------------------------------------------------------------
        // STEP 2: Deteksi prefix alias grup
        // Contoh: "panitia: belanja hadiah 1.500.000"
        // ------------------------------------------------------------
        $targetGroupId = null;
        $cleanMessage  = $this->message;

        $aliasGroup = $this->detectGroupAlias($this->message);
        if ($aliasGroup) {
            $targetGroupId = $aliasGroup['id'];
            // Hapus prefix dari pesan sebelum di-parse
            $cleanMessage = preg_replace('/^[^:]+:\s*/', '', $this->message);
        }

        // ------------------------------------------------------------
        // STEP 3: Ambil kategori user untuk konteks NLP
        // ------------------------------------------------------------
        $categories = $this->getUserCategories();

        // ------------------------------------------------------------
        // STEP 4: Parse pesan via Claude API
        // ------------------------------------------------------------
        $parsed = NLPParser::parse($cleanMessage, $categories, (int)$this->user["id"], $this->db);

        // ------------------------------------------------------------
        // STEP 5: Jalankan aksi sesuai intent
        // ------------------------------------------------------------
        switch ($parsed['intent']) {

            case NLPParser::INTENT_CATATAN:
                $this->handleCatatan($parsed, $cleanMessage, $targetGroupId);
                break;

            case NLPParser::INTENT_REKAP:
                $this->handleRekap($parsed);
                break;

            case NLPParser::INTENT_EDIT:
                $this->handleEdit($parsed);
                break;

            case NLPParser::INTENT_CARI:
                $this->handleCari($parsed);
                break;

            case NLPParser::INTENT_HELP:
                $this->handleHelp();
                break;

            case NLPParser::INTENT_HAPUS:
                $this->handleHapusDenganKonfirmasi($parsed);
                break;

            case NLPParser::INTENT_NS:
                // NS tanpa pending → abaikan atau beri info
                WASender::send($this->waNumber,
                    "Tidak ada catatan yang menunggu konfirmasi share saat ini. 👍"
                );
                break;

            case NLPParser::INTENT_SUPPORT:
                $this->handleSupportMessage($parsed);
                break;

            case NLPParser::INTENT_SALDO:
                $this->handleSaldoCheck($parsed);
                break;

            case NLPParser::INTENT_SALDO_HISTORIS:
                $this->handleSaldoHistoris($parsed);
                break;

            default:
                if (!empty($parsed['silent'])) break;
                if (!empty($parsed['hint']) && $parsed['hint'] === 'saldo_bulan_ini') {
                    WASender::send($this->waNumber,
                        "ℹ️ Untuk menyesuaikan saldo *bulan ini*, gunakan:\n\n" .
                        "_Saldo sekarang [nominal]_\n\n" .
                        "Contoh: _Saldo sekarang 2jt_"
                    );
                    break;
                }
                if ($this->shouldSendUnknownReply()) {
                    WASender::send($this->waNumber,
                        "Pesan tidak dikenali 🤔\n\n" .
                        "Ketik *panduan* atau ketik *help* untuk cara penggunaan chat lengkap,\n" .
                        "atau akses: " . APP_URL . "/panduan.html"
                    );
                }
                break;
        }
    }

    private function getPendingDesc(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_shared
            WHERE user_id = ? AND status = 'waiting' 
            AND target_groups LIKE '{\"awaiting_desc\"%'
            ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HANDLER: Catat Transaksi
    // ============================================================
    private function handleCatatan(array $parsed, string $rawMessage, ?int $targetGroupId): void
    {
        if ($parsed['amount'] <= 0) {
            WASender::send($this->waNumber,
                "⚠️ Nominal tidak terbaca. Coba ulangi.\n" .
                "Contoh: _Bensin 50rb_ atau _Makan siang 25.000_"
            );
            return;
        }

        // Cek apakah deskripsi kosong atau hanya berisi nominal mentah
        $desc = trim($parsed['description'] ?? '');
        $isDescEmpty = empty($desc) || $desc === $rawMessage || 
                    NLPParser::extractAmount($desc) === $parsed['amount'];

        if ($isDescEmpty) {
            $this->db->prepare(
                "DELETE FROM pending_shared WHERE user_id = ? AND target_groups LIKE '{\"awaiting_desc\"%'"
            )->execute([$this->user['id']]);

            $this->db->prepare(
                "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status)
                VALUES (0, ?, ?, 'waiting')"
            )->execute([
                $this->user['id'],
                json_encode([
                    'awaiting_desc' => true,
                    'amount'        => $parsed['amount'],
                    'type'          => $parsed['type'],
                    'raw_message'   => $rawMessage,
                    'group_id'      => $targetGroupId,
                ])
            ]);

            WASender::send($this->waNumber,
                WASender::formatRupiah($parsed['amount']) . " untuk apa?\n" .
                "Contoh: _bensin_, _makan siang_, _bayar listrik_\n" .
                "Atau balas *batal* untuk membatalkan."
            );
            return;
        }

        $manager = new TransactionManager($this->user, $this->db);
        $result  = $manager->save($parsed, $rawMessage, $targetGroupId);

        if (!$result['success']) {
            WASender::send($this->waNumber, "⚠️ Gagal menyimpan catatan. Coba lagi.");
            return;
        }

        $trx = $result['transaction'];

        // Cek grup shared (hanya jika tidak ada target grup spesifik via alias)
        $sharedGroups = [];
        if ($targetGroupId === null) {
            $sharedGroups = $this->getSharedGroups();
        }

        // Kirim konfirmasi ke pencatat
        $msg = WASender::buildTransactionMessage($trx, $sharedGroups);
        WASender::send($this->waNumber, $msg);

        // Jika ada target grup via alias → langsung notif anggota grup
        if ($targetGroupId !== null) {
            $this->notifyGroupMembers($targetGroupId, $trx);
        }

        // Simpan pending_shared jika ada grup shared
        if (!empty($sharedGroups)) {
            $this->createPendingShared($trx['id'], $trx['unique_code'], $sharedGroups);
        }
    }

    // ============================================================
    // HANDLER: Rekap
    // ============================================================
    private function handleRekap(array $parsed): void
    {
        $builder = new RekapBuilder($this->user, $this->db);
        $rekap   = $builder->build($parsed['period'], $parsed['scope']);

        if (!$rekap) {
            WASender::send($this->waNumber, "📊 Tidak ada data transaksi untuk periode tersebut.");
            return;
        }

        $periodeLabel = $builder->getPeriodeLabel($parsed['period']);
        $scopeLabel   = $builder->getScopeLabel($parsed['scope']);
        $msg          = WASender::buildRekapMessage($rekap, $periodeLabel, $scopeLabel);

        WASender::send($this->waNumber, $msg);
    }

    // ============================================================
    // HANDLER: Edit Transaksi
    // ============================================================
    private function handleEdit(array $parsed): void
    {
        if (empty($parsed['unique_code'])) {
            WASender::send($this->waNumber,
                "⚠️ Kode transaksi tidak ditemukan.\n" .
                "Contoh: _Edit TXN-20250320-0001 amount 60rb_"
            );
            return;
        }

        $manager = new TransactionManager($this->user, $this->db);
        $result  = $manager->edit($parsed['unique_code'], $parsed['field'], $parsed['value']);

        if (!$result['success']) {
            WASender::send($this->waNumber, "⚠️ " . ($result['message'] ?? 'Gagal mengedit catatan.'));
            return;
        }

        WASender::send($this->waNumber,
            "✅ Catatan [{$parsed['unique_code']}] berhasil diperbarui."
        );
    }

    // ============================================================
    // HANDLER: Hapus Transaksi
    // ============================================================
    private function handleHapus(array $parsed): void
    {
        if (empty($parsed['unique_code'])) {
            WASender::send($this->waNumber,
                "⚠️ Kode transaksi tidak ditemukan.\n" .
                "Contoh: _Hapus TXN-20250320-0001_"
            );
            return;
        }

        $manager = new TransactionManager($this->user, $this->db);
        $result  = $manager->softDelete($parsed['unique_code']);

        if (!$result['success']) {
            WASender::send($this->waNumber, "⚠️ " . ($result['message'] ?? 'Gagal menghapus catatan.'));
            return;
        }

        WASender::send($this->waNumber,
            "🗑️ Catatan [{$parsed['unique_code']}] berhasil dihapus."
        );
    }

    // ============================================================
    // HELPER: Pending Shared
    // ============================================================
    private function getPendingSaldoCheck(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_shared
            WHERE user_id = ? AND status = 'waiting'
            AND (target_groups LIKE '{\"saldo_riil%' OR target_groups LIKE '{\"saldo_historis%')
            ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    private function getPendingShared(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ps.*, t.unique_code 
             FROM pending_shared ps
             JOIN transactions t ON t.id = ps.transaction_id
             WHERE ps.user_id = ? AND ps.status = 'waiting'
             ORDER BY ps.created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    private function confirmPendingShared(array $pending): void
    {
        // Update status pending
        $stmt = $this->db->prepare(
            "UPDATE pending_shared SET status = 'confirmed', resolved_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$pending['id']]);

        // Update transaksi: masukkan ke grup shared
        $groups = json_decode($pending['target_groups'], true);

        if (empty($groups) || !is_array($groups)) return;

        $groupId = isset($groups[0]['group_id']) ? (int)$groups[0]['group_id'] : 0;

        if ($groupId <= 0) return;

        $stmt = $this->db->prepare(
            "UPDATE transactions SET group_id = ? WHERE id = ?"
        );
        $stmt->execute([$groupId, $pending['transaction_id']]);

        // Ambil data transaksi untuk notifikasi
        $stmtTrx = $this->db->prepare(
            "SELECT t.*, c.name AS category_name FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmtTrx->execute([$pending['transaction_id']]);
        $trx = $stmtTrx->fetch();

        if ($trx) {
            $this->notifyGroupMembers($groupId, $trx);
        }
    }

    private function cancelPendingShared(int $pendingId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE pending_shared SET status = 'cancelled', resolved_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$pendingId]);
    }

    private function createPendingShared(int $transactionId, string $uniqueCode, array $sharedGroups): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status) VALUES (?, ?, ?, 'waiting')"
        );
        $stmt->execute([
            $transactionId,
            $this->user['id'],
            json_encode($sharedGroups),
        ]);
    }

    // ============================================================
    // HELPER: Cek apakah pesan adalah perintah "tidak share"
    // ============================================================
    private function isNotSharedCommand(string $message): bool
    {
        $msg = strtolower(trim($message));
        $keywords = ['ns', 'notshared', 'not shared', 'tidak share', 'jangan share', 'no share'];
        return in_array($msg, $keywords);
    }

    // ============================================================
    // HELPER: Deteksi prefix alias grup
    // ============================================================
    private function detectGroupAlias(string $message): ?array
    {
        // Format: "alias: pesan" — ambil bagian sebelum titik dua pertama
        if (!str_contains($message, ':')) return null;

        $parts = explode(':', $message, 2);
        $alias = strtolower(trim($parts[0]));

        if (empty($alias)) return null;

        $stmt = $this->db->prepare(
            "SELECT g.* FROM groups g
             JOIN group_members gm ON gm.group_id = g.id
             WHERE LOWER(g.alias) = ? AND gm.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$alias, $this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HELPER: Ambil grup shared milik user
    // ============================================================
    private function getSharedGroups(): array
    {
        $stmt = $this->db->prepare(
            "SELECT g.id, g.name FROM groups g
             JOIN group_members gm ON gm.group_id = g.id
             WHERE gm.user_id = ? AND g.is_shared = 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetchAll();
    }

    // ============================================================
    // HELPER: Ambil kategori user (default + custom)
    // ============================================================
    private function getUserCategories(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, type FROM categories
             WHERE is_default = 1 OR user_id = ?
             ORDER BY type, name"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetchAll();
    }

    // ============================================================
    // HELPER: Append sisa pending ke pesan balasan
    // ============================================================
    private function appendPendingReminder(string &$msg): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM scheduled_logs
            WHERE user_id = ? AND status = 'pending'"
        );
        $stmt->execute([$this->user['id']]);
        $sisa = (int)$stmt->fetchColumn();
        if ($sisa > 0) {
            $msg .= "\n\n📌 Masih ada *{$sisa} pengingat* lain yang menunggu responmu.";
        }
    }

    private function getAwaitingAmountLog(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT sl.*, s.title, s.type, s.amount AS sched_amount,
                    s.mode, s.category_id, s.frequency, s.reminder_interval
            FROM scheduled_logs sl
            JOIN scheduled_transactions s ON s.id = sl.scheduled_id
            WHERE sl.user_id = ? AND sl.awaiting_amount = 1
            ORDER BY sl.id ASC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HANDLER: Respons pengingat terjadwal (ya/belum/besok/nominal)
    // ============================================================
    private function getPendingScheduledLogs(): array
    {
        $stmt = $this->db->prepare(
            "SELECT sl.*, s.title, s.type, s.amount AS sched_amount,
                    s.mode, s.category_id, s.frequency, s.reminder_interval
            FROM scheduled_logs sl
            JOIN scheduled_transactions s ON s.id = sl.scheduled_id
            WHERE sl.user_id = ? AND sl.status = 'pending'
            ORDER BY sl.id ASC"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetchAll();
    }

    private function handleScheduledResponse(array $log, int $forcedAmount = 0): void
    {
        $msg   = strtolower(trim($this->message));
        $logId = (int)$log['id'];

        // Cek apakah pesan adalah nominal (angka)
        $amount = $forcedAmount > 0 ? $forcedAmount : NLPParser::extractAmountFromText($this->message);

        // Snooze: besok / lusa / tunda
        if (in_array($msg, ['besok', 'tunda', 'lusa', 'nanti'])) {
            $days       = $msg === 'lusa' ? 2 : 1;
            $nextRemind = date('Y-m-d', strtotime("+{$days} days"));
            $this->db->prepare(
                "UPDATE scheduled_logs SET status='snoozed', next_remind=?, resolved_at=NOW() WHERE id=?"
            )->execute([$nextRemind, $logId]);
            $this->db->prepare(
                "INSERT INTO scheduled_logs (scheduled_id,user_id,due_date,status,reminded_count,last_reminded,next_remind)
                 VALUES (?,?,?,'pending',0,NOW(),?)"
            )->execute([$log['scheduled_id'], $this->user['id'], $nextRemind, $nextRemind]);
            $replyMsg = "Oke, pengingat *" . $log['title'] . "* ditunda ke " .
                date('d M Y', strtotime($nextRemind)) . ".";
            $this->appendPendingReminder($replyMsg);
            WASender::send($this->waNumber, $replyMsg);
            return;
        }

        // Skip: tidak / skip / lewat
        if (in_array($msg, ['tidak', 'skip', 'lewat', 'batal', 'no'])) {
            $this->db->prepare(
                "UPDATE scheduled_logs SET status='skipped', resolved_at=NOW() WHERE id=?"
            )->execute([$logId]);
            $replyMsg = "Pengingat *" . $log['title'] . "* dilewati.";
            $this->appendPendingReminder($replyMsg);
            WASender::send($this->waNumber, $replyMsg);
            return;
        }

        // Konfirmasi: ya + nominal dari sched, atau pesan adalah nominal
        $confirmedAmount = null;
        if ($amount > 0) {
            $confirmedAmount = $amount;
        } elseif (in_array($msg, ['ya', 'yes', 'sudah', 'iya', 'ok'])) {
            if ($log['mode'] === 'ask_amount' || ($log['mode'] === 'confirm' && !$log['sched_amount'])) {
                // Mode ask_amount: "ya" tidak valid, minta nominal, set awaiting
                $this->db->prepare(
                    "UPDATE scheduled_logs SET awaiting_amount = 1 WHERE id = ?"
                )->execute([$logId]);
                WASender::send($this->waNumber,
                    "Berapa nominalnya untuk *" . $log['title'] . "*?\n" .
                    "Contoh: _53.280_ atau _53rb_\n" .
                    "Atau balas *batal* untuk membatalkan."
                );
                return;
            } elseif ($log['sched_amount']) {
                $confirmedAmount = (int)$log['sched_amount'];
            }
        }

        if ($confirmedAmount) {
            $manager = new TransactionManager($this->user, $this->db);
            $result  = $manager->saveWithCategoryId([
                'type'        => $log['type'],
                'amount'      => $confirmedAmount,
                'description' => $log['title'],
                'category_id' => $log['category_id'],
            ], 'scheduled: ' . $log['title']);

            if ($result['success']) {
                $trx  = $result['transaction'];
                $icon = $log['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran';
                $this->db->prepare(
                    "UPDATE scheduled_logs SET status='confirmed', transaction_id=?, resolved_at=NOW() WHERE id=?"
                )->execute([$trx['id'], $logId]);
                $replyMsg = "Tercatat!\n[{$trx['unique_code']}]\n" .
                    "{$icon}: " . WASender::formatRupiah($confirmedAmount) . "\n" .
                    "Catatan: " . $log['title'] . "\n" .
                    "Kategori: " . ($trx['category_name'] ?? 'Lainnya');
                $this->appendPendingReminder($replyMsg);
                WASender::send($this->waNumber, $replyMsg);
            } else {
                $replyMsg = "Gagal menyimpan transaksi.";
                $this->appendPendingReminder($replyMsg);
                WASender::send($this->waNumber, $replyMsg);
            }
            return;
        }

        // Belum: ingatkan lagi nanti
        if (in_array($msg, ['belum', 'blm'])) {
            $nextRemind = date('Y-m-d', strtotime('+' . $log['reminder_interval'] . ' days'));
            $this->db->prepare(
                "UPDATE scheduled_logs SET next_remind=?, reminded_count=reminded_count+1 WHERE id=?"
            )->execute([$nextRemind, $logId]);
            $replyMsg = "Oke, akan diingatkan lagi " . ($nextRemind === date('Y-m-d') ? 'hari ini' : 'besok') . ".";
            $this->appendPendingReminder($replyMsg);
            WASender::send($this->waNumber, $replyMsg);
            return;
        }

        // Jawaban tidak dikenali
        $replyMsg = "⚠️ Jawaban tidak dikenali.\n" .
            "Balas dengan format: *[nomor] [jawaban]*\n" .
            "Contoh: *1 ya* / *1 belum* / *1 besok* / *1 50rb*";
        WASender::send($this->waNumber, $replyMsg);
    }

    // ============================================================
    // HANDLER: Cari Transaksi
    // ============================================================
    private function handleCari(array $parsed): void
    {
        $keyword   = trim($parsed['keyword'] ?? '');
        $monthOnly = $parsed['month_only'] ?? false;

        if (empty($keyword)) {
            WASender::send($this->waNumber,
                "Contoh pencarian:
- Cari bensin
- Cari gaji bulan ini
- Cari makan kemarin"
            );
            return;
        }

        $params = ['%' . $keyword . '%', $this->user['id']];
        $dateFilter = '';

        if ($monthOnly) {
            $dateFilter = "AND MONTH(t.created_at)=MONTH(NOW()) AND YEAR(t.created_at)=YEAR(NOW())";
        }

        $stmt = $this->db->prepare(
            "SELECT t.unique_code, t.type, t.amount, t.description, t.created_at,
                    c.name AS category_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.description LIKE ?
               AND t.user_id = ?
               AND t.deleted_at IS NULL
               {$dateFilter}
             ORDER BY t.created_at DESC LIMIT 5"
        );
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        if (empty($results)) {
            WASender::send($this->waNumber,
                "Tidak ditemukan transaksi dengan kata kunci: " . $keyword
            );
            return;
        }

        $count = count($results);
        $msg   = "Hasil pencarian: " . $keyword . "
";
        $msg  .= "(" . $count . " transaksi terbaru)

";

        foreach ($results as $t) {
            $icon = $t['type'] === 'income' ? 'Masuk' : 'Keluar';
            $tgl  = date('d M Y', strtotime($t['created_at']));
            $msg .= "[" . $t['unique_code'] . "] " . $tgl . "
";
            $msg .= $icon . ": " . WASender::formatRupiah((int)$t['amount']);
            $msg .= " - " . ($t['description'] ?? '-') . "
";
            $msg .= "Kategori: " . ($t['category_name'] ?? 'Lainnya') . "

";
        }

        WASender::send($this->waNumber, rtrim($msg));
    }
    // ============================================================
    // HANDLER: Help
    // ============================================================
    private function handleHelp(): void
    {
        $msg  = "📖 *Panduan KitaCatat*

";

        $msg .= "*1. Mencatat Transaksi:*
";
        $msg .= "• _Bensin 50rb_ → pengeluaran
";
        $msg .= "• _Makan siang 25K_ → pengeluaran
";
        $msg .= "• _Bayar listrik 350rb_
";
        $msg .= "• _Income gaji 5jt_ → pemasukan
";
        $msg .= "• _THR 2,5jt_ → pemasukan

";

        $msg .= "*2. Format Nominal:*
";
        $msg .= "50rb • 50K • 2jt • 1,5jt • 350.000

";

        $msg .= "*3. Rekap:*
";
        $msg .= "• _Rekap_ / _Rekap bulan ini_
";
        $msg .= "• _Rekap bulan lalu_
";
        $msg .= "• _Rekap keluarga_
";
        $msg .= "• _Rekap agustus 2025_

";

        $msg .= "*4. Edit & Hapus:*
";
        $msg .= "• _Edit TXN-xxx amount 60rb_
";
        $msg .= "• _Edit TXN-xxx catatan bensin pertamax_
";
        $msg .= "• _Hapus TXN-xxx_ (akan minta konfirmasi)

";

        $msg .= "*5. Cek & Sesuaikan Saldo:*
";
        $msg .= "• _Saldo saya sekarang 4.500.000_
";
        $msg .= "Sistem hitung selisih & tanya deskripsinya

";

        $msg .= "*6. Pencarian Transaksi:*
";
        $msg .= "• _Cari bensin_ → 5 transaksi terakhir
";
        $msg .= "• _Cari gaji bulan ini_

";

        $msg .= "*7. Grup:*
";
        $msg .= "• _keluarga: makan 50rb_ → catat ke grup
";
        $msg .= "• _NS_ → tidak di-share ke grup

";

        $msg .= "*8. Pengingat Rutin:*
";
        $msg .= "Atur di dashboard → Pengingat Rutin
";
        $msg .= "Jawab pengingat dengan:
";
        $msg .= "• _ya_ → konfirmasi & catat
";
        $msg .= "• _belum_ → ingatkan lagi besok
";
        $msg .= "• _besok_ / _lusa_ → tunda
";
        $msg .= "• _tidak_ → lewati

";

        $msg .= "*9. Hubungi Admin:*
";
        $msg .= "• _admin: tulis pertanyaan Anda_

";

        $msg .= "📊 Dashboard: " . APP_URL;

        WASender::send($this->waNumber, $msg);
    }

    // ============================================================
    // HANDLER: Hapus dengan Konfirmasi
    // ============================================================
    private function handleHapusDenganKonfirmasi(array $parsed): void
    {
        $uniqueCode = $parsed['unique_code'] ?? '';
        if (empty($uniqueCode)) {
            WASender::send($this->waNumber,
                "⚠️ Kode transaksi tidak ditemukan.
" .
                "Contoh: _Hapus TXN-20260320-0001_"
            );
            return;
        }

        // Cek transaksi ada dan milik user ini
        $stmt = $this->db->prepare(
            "SELECT unique_code, amount, description, type FROM transactions
             WHERE unique_code=? AND user_id=? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$uniqueCode, $this->user['id']]);
        $trx = $stmt->fetch();

        if (!$trx) {
            WASender::send($this->waNumber,
                "⚠️ Catatan [{$uniqueCode}] tidak ditemukan atau bukan milik Anda."
            );
            return;
        }

        // Simpan pending konfirmasi hapus (reuse tabel pending_shared)
        // transaction_id=0 sebagai flag, target_groups diisi unique_code
        $this->db->prepare(
            "DELETE FROM pending_shared WHERE user_id=? AND transaction_id=0 AND target_groups NOT LIKE '{%'"
        )->execute([$this->user['id']]);

        $this->db->prepare(
            "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status)
             VALUES (0, ?, ?, 'waiting')"
        )->execute([$this->user['id'], $uniqueCode]);

        $icon   = $trx['type'] === 'income' ? '📈' : '📉';
        $amount = WASender::formatRupiah((int)$trx['amount']);

        WASender::send($this->waNumber,
            "🗑️ *Konfirmasi Hapus*

" .
            "[{$uniqueCode}]
" .
            "{$icon} " . ($trx['type']==='income'?'Pemasukan':'Pengeluaran') . ": {$amount}
" .
            "📝 " . ($trx['description'] ?? '—') . "

" .
            "Balas *ya* untuk menghapus.
" .
            "Balas *tidak* untuk membatalkan."
        );
    }

    // ============================================================
    // HELPER: Ambil pending konfirmasi hapus
    // ============================================================
    private function getPendingHapusKonfirmasi(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_shared
             WHERE user_id=? AND status='waiting' AND transaction_id=0
               AND target_groups NOT LIKE '{%'
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HANDLER: Pesan ke Admin (Support)
    // ============================================================
    private function handleSupportMessage(array $parsed): void
    {
        $message = trim($parsed['message'] ?? '');
        if (empty($message)) {
            WASender::send($this->waNumber,
                "⚠️ Pesan ke admin tidak boleh kosong.
" .
                "Format: _admin: tulis pesan Anda di sini_"
            );
            return;
        }

        // Simpan ke tabel support_messages
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO support_messages (user_id, sender, message) VALUES (?, 'user', ?)"
            );
            $stmt->execute([$this->user['id'], $message]);

            // Buka sesi support aktif
            $this->db->prepare(
                "DELETE FROM pending_shared
                WHERE user_id = ? AND target_groups = '__support_session__'"
            )->execute([$this->user['id']]);

            $this->db->prepare(
                "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status)
                VALUES (0, ?, '__support_session__', 'waiting')"
            )->execute([$this->user['id']]);

            WASender::send($this->waNumber,
                "✅ Pesan terkirim ke admin!\n\n" .
                "📝 " . $message . "\n\n" .
                "Sesi chat dengan admin kini *aktif*. Lanjutkan ketik pesan berikutnya langsung.\n" .
                "Ketik *selesai* untuk mengakhiri sesi.\n\n" .
                "Pantau balasan di: " . APP_URL . "/dashboard/support.php"
            );
        } catch (\PDOException $e) {
            error_log('[Support] DB error: ' . $e->getMessage());
            WASender::send($this->waNumber,
                "⚠️ Gagal mengirim pesan ke admin. Coba lagi."
            );
        }
    }

    // ============================================================
    // HANDLER: Deteksi Saldo
    // ============================================================
    private function handleSaldoCheck(array $parsed): void
    {
        $saldoRiil = (int)$parsed['amount'];

        // Hitung saldo sistem user (total income - total expense)
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
             FROM transactions
             WHERE user_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$this->user['id']]);
        $row = $stmt->fetch();

        $totalIncome  = (int)($row['total_income']  ?? 0);
        $totalExpense = (int)($row['total_expense'] ?? 0);
        $saldoSistem  = $totalIncome - $totalExpense;
        $selisih      = $saldoRiil - $saldoSistem;

        if ($selisih === 0) {
            WASender::send($this->waNumber,
                "✅ Saldo KitaCatat sudah cocok!
" .
                "Saldo sistem: " . WASender::formatRupiah($saldoSistem)
            );
            return;
        }

        $tipe      = $selisih > 0 ? 'pemasukan' : 'pengeluaran';
        $tipeIcon  = $selisih > 0 ? '📈' : '📉';
        $selisihAbs = abs($selisih);

        // Simpan pending saldo check ke session sementara di DB
        // Reuse tabel pending_shared dengan transaction_id = 0 sebagai flag
        $this->db->prepare(
            "DELETE FROM pending_shared WHERE user_id = ? AND target_groups = '__saldo__'"
        )->execute([$this->user['id']]);

        $this->db->prepare(
            "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status)
             VALUES (0, ?, ?, 'waiting')"
        )->execute([
            $this->user['id'],
            json_encode([
                'saldo_riil'   => $saldoRiil,
                'saldo_sistem' => $saldoSistem,
                'selisih'      => $selisih,
            ])
        ]);

        WASender::send($this->waNumber,
            "📊 *Cek Saldo*

" .
            "Saldo kamu       : " . WASender::formatRupiah($saldoRiil) . "
" .
            "Saldo KitaCatat  : " . WASender::formatRupiah($saldoSistem) . "
" .
            "Selisih          : {$tipeIcon} " . WASender::formatRupiah($selisihAbs) . "

" .
            "Selisih ini akan dicatat sebagai *{$tipe}*.
" .
            "Deskripsinya apa? (contoh: gaji, thr, potongan, transfer)

" .
            "Atau balas *batal* untuk membatalkan."
        );
    }

    // ============================================================
    // HANDLER: Saldo Historis (sisa bulan tertentu)
    // ============================================================
    private function handleSaldoHistoris(array $parsed): void
    {
        $saldoRiil = (int)$parsed['amount'];
        $year      = $parsed['year'];
        $month     = $parsed['month'];

        $dateStart = "{$year}-{$month}-01 00:00:00";
        $dateEnd   = date('Y-m-t 23:59:59', mktime(0, 0, 0, (int)$month, 1, (int)$year));

        // Saldo KitaCatat bulan tersebut (hanya bulan itu)
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense
            FROM transactions
            WHERE user_id = ? AND deleted_at IS NULL
            AND created_at BETWEEN ? AND ?"
        );
        $stmt->execute([$this->user['id'], $dateStart, $dateEnd]);
        $row = $stmt->fetch();
        $saldoBulanItu = (int)($row['total_income'] ?? 0) - (int)($row['total_expense'] ?? 0);

        // Carry-over sebelum bulan tersebut
        $stmtCo = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) -
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS carry_over
            FROM transactions
            WHERE user_id = ? AND deleted_at IS NULL AND created_at < ?"
        );
        $stmtCo->execute([$this->user['id'], $dateStart]);
        $carryOver   = (int)($stmtCo->fetchColumn() ?? 0);
        $saldoSistem = $saldoBulanItu + $carryOver;
        $selisih     = $saldoRiil - $saldoSistem;

        // Label bulan
        $bulanMap = [
            '01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
            '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
            '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'
        ];
        $labelBulan = ($bulanMap[$month] ?? $month) . ' ' . $year;

        if ($selisih === 0) {
            WASender::send($this->waNumber,
                "✅ Saldo KitaCatat {$labelBulan} sudah cocok!\n" .
                "Saldo sistem: " . WASender::formatRupiah($saldoSistem)
            );
            return;
        }

        $tipe      = $selisih > 0 ? 'pemasukan' : 'pengeluaran';
        $tipeIcon  = $selisih > 0 ? '📈' : '📉';
        $selisihAbs = abs($selisih);

        // Simpan pending dengan tanggal penyesuaian = akhir bulan tersebut
        $this->db->prepare(
            "DELETE FROM pending_shared WHERE user_id = ? AND target_groups LIKE '{\"saldo_historis%'"
        )->execute([$this->user['id']]);

        $this->db->prepare(
            "INSERT INTO pending_shared (transaction_id, user_id, target_groups, status)
            VALUES (0, ?, ?, 'waiting')"
        )->execute([
            $this->user['id'],
            json_encode([
                'saldo_historis' => true,
                'saldo_riil'     => $saldoRiil,
                'saldo_sistem'   => $saldoSistem,
                'selisih'        => $selisih,
                'trx_date'       => $dateEnd, // transaksi dicatat di akhir bulan tersebut
                'label_bulan'    => $labelBulan,
                'carry_over'     => $carryOver,
                'saldo_bulan'    => $saldoBulanItu,
            ])
        ]);

        $coSign = $carryOver >= 0 ? '＋' : '−';

        WASender::send($this->waNumber,
            "📊 *Saldo Sebelumnya — {$labelBulan}*\n\n" .
            "Saldo kamu          : " . WASender::formatRupiah($saldoRiil) . "\n" .
            "Saldo KitaCatat     : " . WASender::formatRupiah($saldoSistem) . "\n" .
            "  Bulan {$labelBulan} : " . WASender::formatRupiah(abs($saldoBulanItu)) . "\n" .
            "  {$coSign} Saldo sebelumnya : " . WASender::formatRupiah(abs($carryOver)) . "\n" .
            "Selisih             : {$tipeIcon} " . WASender::formatRupiah($selisihAbs) . "\n\n" .
            "Selisih ini akan dicatat sebagai *{$tipe}* di akhir {$labelBulan}.\n" .
            "Deskripsinya apa? (contoh: potongan, transfer, penyesuaian)\n\n" .
            "Atau balas *batal* untuk membatalkan."
        );
    }

    // ============================================================
    // NOTIFIKASI: Kirim notif ke seluruh anggota grup (kecuali pencatat)
    // ============================================================
    private function notifyGroupMembers(int $groupId, array $trx): void
    {
        // Ambil info grup
        $stmtGrp = $this->db->prepare("SELECT name FROM groups WHERE id = ?");
        $stmtGrp->execute([$groupId]);
        $group = $stmtGrp->fetch();
        if (!$group) return;

        // Ambil semua anggota aktif kecuali pencatat sendiri
        $stmt = $this->db->prepare(
            "SELECT u.wa_number, u.name
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ? AND gm.user_id != ? AND u.is_active = 1"
        );
        $stmt->execute([$groupId, $this->user['id']]);
        $members = $stmt->fetchAll();

        if (empty($members)) return;

        $icon      = $trx['type'] === 'income' ? '📈' : '📉';
        $label     = $trx['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $amount    = WASender::formatRupiah((int)$trx['amount']);
        $pencatat  = $this->user['name'] ?? $this->waNumber;
        $grupName  = $group['name'];

        $msg  = "🔔 *Notifikasi Grup {$grupName}*\n\n";
        $msg .= "{$pencatat} mencatat:\n";
        $msg .= "[{$trx['unique_code']}]\n";
        $msg .= "{$icon} {$label}: {$amount}\n";
        $msg .= "📝 " . ($trx['description'] ?? '-') . "\n";
        $msg .= "🏷️ " . ($trx['category_name'] ?? 'Lainnya');

        foreach ($members as $member) {
            WASender::send($member['wa_number'], $msg);
        }
    }
    
    // ============================================================
    // HELPER: Rate limit balasan "tidak dikenali" — max 1x per jam per user
    // ============================================================
    private function shouldSendUnknownReply(): bool
    {
        $cacheDir  = dirname(__DIR__) . '/logs/unknown_reply';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
    
        $cacheFile = $cacheDir . '/u' . (int)$this->user['id'] . '.txt';
        $cooldown  = 3600; // 1 jam dalam detik
    
        if (file_exists($cacheFile)) {
            $lastSent = (int)file_get_contents($cacheFile);
            if ((time() - $lastSent) < $cooldown) {
                return false; // masih dalam cooldown, abaikan
            }
        }
    
        file_put_contents($cacheFile, time());
        return true;
    }

    // ============================================================
    // HELPER: Cek apakah ada sesi support aktif
    // ============================================================
    private function getActiveSupportSession(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pending_shared
            WHERE user_id = ? AND status = 'waiting'
            AND target_groups = '__support_session__'
            ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$this->user['id']]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HANDLER: Pesan dalam sesi support aktif
    // ============================================================
    private function handleSupportSessionMessage(): void
    {
        $msg   = trim($this->message);
        $lower = strtolower($msg);

        // Perintah tutup sesi oleh user
        if (in_array($lower, ['selesai', 'stop', 'tutup', 'exit', 'keluar', 'end'])) {
            $this->closeSupportSession();
            WASender::send($this->waNumber,
                "✅ Sesi chat dengan admin telah ditutup.\n" .
                "Kamu bisa mulai mencatat transaksi atau gunakan perintah lain seperti biasa."
            );
            return;
        }

        // Forward pesan ke admin via support_messages
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO support_messages (user_id, sender, message) VALUES (?, 'user', ?)"
            );
            $stmt->execute([$this->user['id'], $msg]);

            WASender::send($this->waNumber,
                "📨 Pesan terkirim ke admin.\n" .
                "_(Ketik *selesai* untuk mengakhiri sesi chat)_"
            );
        } catch (\PDOException $e) {
            error_log('[Support Session] DB error: ' . $e->getMessage());
            WASender::send($this->waNumber, "⚠️ Gagal mengirim pesan. Coba lagi.");
        }
    }

    // ============================================================
    // HELPER: Tutup sesi support (dari user atau via API admin)
    // ============================================================
    private function closeSupportSession(): void
    {
        $this->db->prepare(
            "UPDATE pending_shared SET status = 'confirmed', resolved_at = NOW()
            WHERE user_id = ? AND status = 'waiting'
            AND target_groups = '__support_session__'"
        )->execute([$this->user['id']]);
    }
}