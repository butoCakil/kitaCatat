<?php
// ============================================================
// KitaCatat — TransactionManager
// Handle: simpan, edit, hapus transaksi
// ============================================================

class TransactionManager
{
    private array $user;
    private PDO   $db;

    public function __construct(array $user, PDO $db)
    {
        $this->user = $user;
        $this->db   = $db;
    }

    // ============================================================
    // SIMPAN transaksi baru
    // ============================================================
    public function save(array $parsed, string $rawMessage, ?int $targetGroupId = null): array
    {
        // Resolve category_id dari nama kategori
        $categoryId = $this->resolveCategoryId(
            $parsed['category_name'] ?? 'Lainnya',
            $parsed['type']
        );

        // Generate unique code
        $uniqueCode = $this->generateUniqueCode();

        try {
            $customDate = !empty($parsed['created_at']) ? $parsed['created_at'] : null;

            $stmt = $this->db->prepare(
                "INSERT INTO transactions
                    (unique_code, user_id, group_id, type, amount, description, category_id, raw_message, source, created_at)
                VALUES
                    (:unique_code, :user_id, :group_id, :type, :amount, :description, :category_id, :raw_message, 'wa',
                    COALESCE(:created_at, NOW()))"
            );
            $stmt->execute([
                ':unique_code'  => $uniqueCode,
                ':user_id'      => $this->user['id'],
                ':group_id'     => $targetGroupId,
                ':type'         => $parsed['type'],
                ':amount'       => $parsed['amount'],
                ':description'  => $parsed['description'],
                ':category_id'  => $categoryId,
                ':raw_message'  => $rawMessage,
                ':created_at'   => $customDate,
            ]);

            $transactionId = (int) $this->db->lastInsertId();

            // Ambil data lengkap termasuk nama kategori untuk pesan konfirmasi
            $trx = $this->getById($transactionId);

            // Simpan keyword → kategori untuk pembelajaran
            if (!empty($parsed['description']) && !empty($categoryId)) {
                $this->learnKeywords($parsed['description'], $parsed['type'], $categoryId);
            }

            return ['success' => true, 'transaction' => $trx];

        } catch (PDOException $e) {
            error_log('[TransactionManager] save() error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    // ============================================================
    // EDIT transaksi berdasarkan unique_code
    // ============================================================
    public function edit(string $uniqueCode, string $field, $value): array
    {
        // Pastikan transaksi milik user ini dan belum dihapus
        $trx = $this->getByUniqueCode($uniqueCode);

        if (!$trx) {
            return ['success' => false, 'message' => "Catatan [{$uniqueCode}] tidak ditemukan."];
        }

        if ((int)$trx['user_id'] !== (int)$this->user['id']) {
            return ['success' => false, 'message' => "Catatan [{$uniqueCode}] bukan milik kamu."];
        }

        // Validasi dan normalisasi field yang boleh diedit
        $allowedFields = ['amount', 'description', 'category_id', 'type'];

        switch ($field) {
            case 'amount':
                $value = $this->parseAmount($value);
                if ($value <= 0) {
                    return ['success' => false, 'message' => 'Nominal tidak valid.'];
                }
                $column = 'amount';
                break;

            case 'description':
            case 'catatan':
            case 'deskripsi':
                $value  = substr(trim($value), 0, 255);
                $column = 'description';
                break;

            case 'category':
            case 'kategori':
                $value  = $this->resolveCategoryId($value, $trx['type']);
                $column = 'category_id';
                break;

            case 'type':
            case 'tipe':
                $value = in_array(strtolower($value), ['income', 'expense'])
                    ? strtolower($value)
                    : null;
                if (!$value) {
                    return ['success' => false, 'message' => 'Tipe harus "income" atau "expense".'];
                }
                $column = 'type';
                break;

            default:
                return ['success' => false, 'message' => "Field '{$field}' tidak bisa diedit."];
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE transactions SET {$column} = :value, updated_at = NOW()
                 WHERE unique_code = :unique_code AND deleted_at IS NULL"
            );
            $stmt->execute([':value' => $value, ':unique_code' => $uniqueCode]);

            return ['success' => true];

        } catch (PDOException $e) {
            error_log('[TransactionManager] edit() error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    // ============================================================
    // SOFT DELETE transaksi
    // ============================================================
    public function softDelete(string $uniqueCode): array
    {
        $trx = $this->getByUniqueCode($uniqueCode);

        if (!$trx) {
            return ['success' => false, 'message' => "Catatan [{$uniqueCode}] tidak ditemukan."];
        }

        if ((int)$trx['user_id'] !== (int)$this->user['id']) {
            return ['success' => false, 'message' => "Catatan [{$uniqueCode}] bukan milik kamu."];
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE transactions SET deleted_at = NOW()
                 WHERE unique_code = :unique_code AND deleted_at IS NULL"
            );
            $stmt->execute([':unique_code' => $uniqueCode]);

            return ['success' => true];

        } catch (PDOException $e) {
            error_log('[TransactionManager] softDelete() error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    // ============================================================
    // HELPER: Ambil transaksi by ID
    // ============================================================
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, c.name AS category_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.id = ? AND t.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HELPER: Ambil transaksi by unique_code
    // ============================================================
    public function getByUniqueCode(string $uniqueCode): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, c.name AS category_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.unique_code = ? AND t.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$uniqueCode]);
        return $stmt->fetch() ?: null;
    }

    // ============================================================
    // HELPER: Simpan transaksi langsung dengan category_id (untuk scheduled)
    // ============================================================
    public function saveWithCategoryId(array $data, string $rawMessage): array
    {
        $uniqueCode  = $this->generateUniqueCode();
        $type        = $data['type'];
        $amount      = (int)$data['amount'];
        $description = $data['description'] ?? '';
        $categoryId  = $data['category_id'] ? (int)$data['category_id'] : null;

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO transactions
                    (unique_code, user_id, type, amount, description, category_id, raw_message, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')"
            );
            $stmt->execute([$uniqueCode, $this->user['id'], $type, $amount, $description, $categoryId, $rawMessage]);
            $trx = $this->getById((int)$this->db->lastInsertId());
            return ['success' => true, 'transaction' => $trx];
        } catch (\PDOException $e) {
            error_log('[TransactionManager] saveWithCategoryId error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    // ============================================================
    // HELPER: Simpan/update keyword → kategori untuk pembelajaran
    // ============================================================
    public function learnKeywords(string $description, string $type, int $categoryId): void
    {
        if (empty(trim($description)) || $categoryId <= 0) return;

        // Gunakan extractKeywords dari NLPParser
        $keywords = NLPParser::extractKeywords($description);
        if (empty($keywords)) return;

        foreach ($keywords as $keyword) {
            try {
                // Upsert: tambah baru atau increment hit_count jika sudah ada
                $stmt = $this->db->prepare(
                    "INSERT INTO user_keyword_categories
                        (user_id, keyword, category_id, type, hit_count)
                     VALUES (?, ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        category_id = VALUES(category_id),
                        hit_count   = hit_count + 1,
                        updated_at  = NOW()"
                );
                $stmt->execute([
                    $this->user['id'],
                    $keyword,
                    $categoryId,
                    $type,
                ]);
            } catch (PDOException $e) {
                // Tidak perlu fatal — pembelajaran gagal tidak mengganggu pencatatan
                error_log('[TransactionManager] learnKeywords error: ' . $e->getMessage());
            }
        }
    }

    // ============================================================
    // HELPER: Generate unique code TXN-YYYYMMDD-XXXX
    // ============================================================
    private function generateUniqueCode(): string
    {
        $date = date('Ymd');

        // Hitung transaksi hari ini untuk auto-increment
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM transactions WHERE unique_code LIKE :prefix"
        );
        $stmt->execute([':prefix' => "TXN-{$date}-%"]);
        $count = (int) $stmt->fetchColumn();

        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);

        return "TXN-{$date}-{$sequence}";
    }

    // ============================================================
    // HELPER: Resolve category_id dari nama kategori
    // Cari exact match dulu, lalu partial match, fallback ke "Lainnya"
    // ============================================================
    private function resolveCategoryId(string $categoryName, string $type): int
    {
        // 1. Exact match (case-insensitive), prioritaskan kategori default
        $stmt = $this->db->prepare(
            "SELECT id FROM categories
             WHERE LOWER(name) = LOWER(:name) AND type = :type
             AND (is_default = 1 OR user_id = :user_id)
             ORDER BY is_default DESC LIMIT 1"
        );
        $stmt->execute([
            ':name'    => $categoryName,
            ':type'    => $type,
            ':user_id' => $this->user['id'],
        ]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        // 2. Partial match
        $stmt = $this->db->prepare(
            "SELECT id FROM categories
             WHERE LOWER(name) LIKE LOWER(:name) AND type = :type
             AND (is_default = 1 OR user_id = :user_id)
             ORDER BY is_default DESC LIMIT 1"
        );
        $stmt->execute([
            ':name'    => '%' . $categoryName . '%',
            ':type'    => $type,
            ':user_id' => $this->user['id'],
        ]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        // 3. Fallback: "Lainnya" sesuai type
        $stmt = $this->db->prepare(
            "SELECT id FROM categories
             WHERE name = 'Lainnya' AND type = :type AND is_default = 1
             LIMIT 1"
        );
        $stmt->execute([':type' => $type]);
        $row = $stmt->fetch();

        return $row ? (int) $row['id'] : 1;
    }

    // ============================================================
    // HELPER: Parse nominal dari string (50rb, 2jt, 1.500.000, dll)
    // ============================================================
    private function parseAmount($value): int
    {
        if (is_numeric($value)) return (int) $value;

        $value = strtolower(trim((string) $value));

        // Hapus titik pemisah ribuan: 1.500.000 → 1500000
        $value = preg_replace('/\.(?=\d{3})/', '', $value);

        // Ganti koma desimal: 1,5 → 1.5
        $value = str_replace(',', '.', $value);

        // Konversi suffix
        if (preg_match('/^([\d.]+)\s*(jt|juta)/', $value, $m)) {
            return (int) round((float)$m[1] * 1_000_000);
        }
        if (preg_match('/^([\d.]+)\s*(rb|ribu|k)/', $value, $m)) {
            return (int) round((float)$m[1] * 1_000);
        }

        return (int) $value;
    }
}