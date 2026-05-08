<?php
// ============================================================
// KitaCatat — NLPParser (Rule-Based, tanpa Claude API)
// Parsing pesan WA menggunakan regex + keyword matching
// Bisa diganti versi Claude API kapanpun dengan file yang sama
// ============================================================

class NLPParser
{
    const INTENT_CATATAN = 'catatan';
    const INTENT_REKAP   = 'rekap';
    const INTENT_EDIT    = 'edit';
    const INTENT_HAPUS   = 'hapus';
    const INTENT_NS      = 'ns';
    const INTENT_SALDO   = 'saldo_check';
    const INTENT_SUPPORT = 'support_message';
    const INTENT_HELP    = 'help';
    const INTENT_CARI    = 'cari';
    const INTENT_UNKNOWN = 'unknown';

    // ============================================================
    // ENTRY POINT — interface sama dengan versi Claude API
    // ============================================================
    public static function parse(string $message, array $categories = [], int $userId = 0, ?PDO $db = null): array
    {
        $msg = trim($message);

        // Guard: abaikan pesan panjang (bukan catatan keuangan)
        if (self::isTooLong($msg))  return ['intent' => self::INTENT_UNKNOWN, 'silent' => true];
        
        // Guard: abaikan link URL
        if (self::isLinkOnly($msg)) return ['intent' => self::INTENT_UNKNOWN, 'silent' => true];
        
        // Urutan deteksi: dari yang paling spesifik ke umum
        if (self::isNS($msg))      return ['intent' => self::INTENT_NS];
        if (self::isHapus($msg))   return self::parseHapus($msg);
        if (self::isEdit($msg))    return self::parseEdit($msg);
        if (self::isRekap($msg))   return self::parseRekap($msg);
        if (self::isCari($msg))            return self::parseCari($msg);
        if (self::isHelp($msg))           return ['intent' => self::INTENT_HELP];
        if (self::isSupportMessage($msg)) return self::parseSupportMessage($msg);
        if (self::isSaldoCheck($msg)) return self::parseSaldoCheck($msg);
        if (self::isCatatan($msg)) return self::parseCatatan($msg, $categories, $userId, $db);

        return ['intent' => self::INTENT_UNKNOWN];
    }

    // ============================================================
    // DETEKSI INTENT
    // ============================================================
    private static function isNS(string $msg): bool
    {
        $lower    = strtolower(trim($msg));
        $keywords = ['ns', 'notshared', 'not shared', 'tidak share',
                     'jangan share', 'no share', 'tdk share'];
        return in_array($lower, $keywords);
    }

    private static function isSaldoCheck(string $msg): bool
    {
        // Harus ada kata saldo/balance
        if (!preg_match('/\b(saldo|balance)\b/i', $msg)) {
            return false;
        }
        // Harus ada nominal angka di pesan
        if (!preg_match('/\d/', $msg)) {
            return false;
        }
        // Pastikan bukan catatan biasa
        $lower = strtolower($msg);
        $txnWords = ['bayar', 'beli', 'makan', 'bensin', 'transfer', 'kirim', 'terima'];
        foreach ($txnWords as $w) {
            if (str_contains($lower, $w)) return false;
        }
        return true;
    }

    private static function parseSaldoCheck(string $msg): array
    {
        $amount = self::extractAmount($msg);

        if ($amount <= 0) {
            return ['intent' => self::INTENT_UNKNOWN];
        }

        return [
            'intent' => self::INTENT_SALDO,
            'amount' => $amount,
        ];
    }

    private static function isCari(string $msg): bool
    {
        return (bool) preg_match('/^(cari|search|temukan|find)\s+.+/i', trim($msg));
    }

    private static function parseCari(string $msg): array
    {
        // Hapus kata perintah di depan
        $text = preg_replace('/^(cari|search|temukan|find)\s+/i', '', trim($msg));
        $text = trim($text);

        // Deteksi filter bulan
        $monthOnly = false;
        if (preg_match('/bulan ini/i', $text)) {
            $monthOnly = true;
            $text = trim(preg_replace('/bulan ini/i', '', $text));
        }

        // Bersihkan kata "kemarin", "tadi", "terbaru" dll
        $text = trim(preg_replace('/(kemarin|tadi|terbaru|terakhir|hari ini)/i', '', $text));

        if (empty($text)) return ['intent' => self::INTENT_UNKNOWN];

        return [
            'intent'     => self::INTENT_CARI,
            'keyword'    => $text,
            'month_only' => $monthOnly,
        ];
    }

    private static function isHelp(string $msg): bool
    {
        $lower = strtolower(trim($msg));
        $keywords = ['help', 'bantuan', 'panduan', 'cara', 'petunjuk', 'tutorial', '?'];
        return in_array($lower, $keywords);
    }

    private static function isSupportMessage(string $msg): bool
    {
        // Format: "admin: pesan" atau "admin : pesan"
        return (bool) preg_match('/^admin\s*:\s*.+/i', trim($msg));
    }

    private static function parseSupportMessage(string $msg): array
    {
        // Ambil teks setelah "admin:"
        $text = preg_replace('/^admin\s*:\s*/i', '', trim($msg));
        $text = trim($text);

        if (empty($text)) {
            return ['intent' => self::INTENT_UNKNOWN];
        }

        return [
            'intent'  => self::INTENT_SUPPORT,
            'message' => $text,
        ];
    }

    private static function isHapus(string $msg): bool
    {
        return preg_match('/\b(hapus|delete|batalkan|batal|cancel)\b/i', $msg)
            && preg_match('/\bTXN-\d{8}-\d{4}\b/i', $msg);
    }

    private static function isEdit(string $msg): bool
    {
        return preg_match('/\b(edit|ubah|ralat|koreksi|ganti|update)\b/i', $msg)
            && preg_match('/\bTXN-\d{8}-\d{4}\b/i', $msg);
    }

    private static function isRekap(string $msg): bool
    {
        return (bool) preg_match('/\b(rekap|laporan|summary|rangkuman|rekapan)\b/i', $msg);
    }

    private static function isCatatan(string $msg): bool
    {
        return self::extractAmount($msg) > 0;
    }

    // ============================================================
    // PARSER PER INTENT
    // ============================================================
    private static function parseHapus(string $msg): array
    {
        preg_match('/\b(TXN-\d{8}-\d{4})\b/i', $msg, $m);
        return [
            'intent'      => self::INTENT_HAPUS,
            'unique_code' => isset($m[1]) ? strtoupper($m[1]) : '',
        ];
    }

    private static function parseEdit(string $msg): array
    {
        preg_match('/\b(TXN-\d{8}-\d{4})\b/i', $msg, $mCode);
        $uniqueCode = isset($mCode[1]) ? strtoupper($mCode[1]) : '';

        $field = '';
        $value = '';

        if (preg_match('/\b(amount|nominal|harga|jumlah)\s+([^\s]+)/i', $msg, $m)) {
            $field = 'amount';
            $value = self::extractAmount($m[2]);
        } elseif (preg_match('/\b(catatan|deskripsi|keterangan|desc)\s+(.+)/i', $msg, $m)) {
            $field = 'description';
            $value = trim($m[2]);
        } elseif (preg_match('/\b(kategori|category|cat)\s+(.+)/i', $msg, $m)) {
            $field = 'category';
            $value = trim($m[2]);
        } elseif (preg_match('/\b(type|tipe|jenis)\s+(income|expense|pemasukan|pengeluaran)/i', $msg, $m)) {
            $field = 'type';
            $raw   = strtolower($m[2]);
            $value = in_array($raw, ['income', 'pemasukan']) ? 'income' : 'expense';
        }

        return [
            'intent'      => self::INTENT_EDIT,
            'unique_code' => $uniqueCode,
            'field'       => $field,
            'value'       => $value,
        ];
    }

    private static function parseRekap(string $msg): array
    {
        $lower = strtolower($msg);

        // --- Deteksi SCOPE ---
        $scope = 'personal';
        if (preg_match('/\b(keluarga|family|famili)\b/i', $msg)) {
            $scope = 'family';
        } elseif (preg_match('/\b(pribadi|personal|sendiri)\b/i', $msg)) {
            $scope = 'personal';
        } else {
            // Coba ambil nama/alias grup setelah kata "rekap"
            // Contoh: "rekap panitia bulan ini", "rekap hut agustus 2025"
            // Ambil kata setelah "rekap" yang bukan keyword periode
            $periodeKeywords = [
                'bulan','tahun','ini','lalu','kemarin','sebelumnya',
                'januari','februari','maret','april','mei','juni','juli',
                'agustus','september','oktober','november','desember',
                'jan','feb','mar','apr','jun','jul','agt','sep','okt','nov','des',
                'last','month','year','this','pribadi','personal','keluarga'
            ];
            // Hapus kata "rekap/laporan" dari awal, ambil sisa
            $rest = preg_replace('/^\s*(rekap|laporan|summary|rangkuman|rekapan)\s*/i', '', $msg);
            // Pecah kata-kata
            $words = preg_split('/\s+/', strtolower(trim($rest)));
            foreach ($words as $word) {
                $word = trim($word);
                if (!empty($word) && !in_array($word, $periodeKeywords) && !preg_match('/^\d+$/', $word)) {
                    // Kata ini kemungkinan nama/alias grup
                    $scope = 'group:' . $word;
                    break;
                }
            }
        }

        // --- Deteksi PERIODE — default bulan ini ---
        $period = 'this_month';

        $bulanMap = [
            'januari' => '01', 'jan' => '01',
            'februari'=> '02', 'feb' => '02',
            'maret'   => '03', 'mar' => '03',
            'april'   => '04', 'apr' => '04',
            'mei'     => '05',
            'juni'    => '06', 'jun' => '06',
            'juli'    => '07', 'jul' => '07',
            'agustus' => '08', 'agt' => '08', 'aug' => '08',
            'september'=>'09', 'sep' => '09',
            'oktober' => '10', 'okt' => '10', 'oct' => '10',
            'november'=> '11', 'nov' => '11',
            'desember'=> '12', 'des' => '12', 'dec' => '12',
        ];

        foreach ($bulanMap as $nama => $nomor) {
            if (str_contains($lower, $nama)) {
                preg_match('/\b(20\d{2})\b/', $msg, $mTahun);
                $tahun  = $mTahun[1] ?? date('Y');
                $period = "$tahun-$nomor";
                break;
            }
        }

        if (preg_match('/\b(bulan ini|this month|bulan sekarang)\b/i', $msg)) {
            $period = 'this_month';
        } elseif (preg_match('/\b(bulan lalu|bulan kemarin|last month|bulan sebelumnya)\b/i', $msg)) {
            $period = 'last_month';
        } elseif (preg_match('/\b(tahun ini|this year)\b/i', $msg)) {
            $period = 'this_year';
        }

        return [
            'intent' => self::INTENT_REKAP,
            'period' => $period,
            'scope'  => $scope,
        ];
    }

    private static function parseCatatan(string $msg, array $categories = [], int $userId = 0, ?PDO $db = null): array
    {
        $amount = self::extractAmount($msg);

        if ($amount <= 0) {
            return ['intent' => self::INTENT_UNKNOWN];
        }

        $type        = self::detectType($msg);
        $description = self::cleanDescription($msg);

        // Prioritas 1: Cek riwayat keyword user (paling relevan)
        $category = null;
        if ($userId > 0 && $db !== null) {
            $category = self::lookupUserKeyword($description, $type, $userId, $db);
        }

        // Prioritas 2: Fallback ke keyword default sistem
        if ($category === null) {
            $category = self::detectCategory($description, $type);
        }

        return [
            'intent'        => self::INTENT_CATATAN,
            'type'          => $type,
            'amount'        => $amount,
            'description'   => $description,
            'category_name' => $category,
        ];
    }

    // ============================================================
    // HELPER: Ekstrak nominal dari teks
    // Support: 50rb, 50k, 2jt, 1.5jt, 1,5jt, 1.500.000, 500ribu
    // ============================================================
    public static function extractAmount(string $msg): int
    {
        $msg = str_replace(',', '.', $msg);

        // Angka + juta/jt
        if (preg_match('/(\d+\.?\d*)\s*(juta|jt)\b/i', $msg, $m)) {
            return (int) round((float)$m[1] * 1_000_000);
        }
        // Angka + ribu/rb/k
        if (preg_match('/(\d+\.?\d*)\s*(ribu|rb|k)\b/i', $msg, $m)) {
            return (int) round((float)$m[1] * 1_000);
        }
        // Format 1.500.000
        if (preg_match('/\b(\d{1,3}(?:\.\d{3})+)\b/', $msg, $m)) {
            return (int) str_replace('.', '', $m[1]);
        }
        // Angka biasa >= 100
        if (preg_match('/\b(\d{3,})\b/', $msg, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    // ============================================================
    // HELPER: Deteksi income atau expense
    // ============================================================
    private static function detectType(string $msg): string
    {
        $lower = strtolower($msg);
        $incomeKeywords = [
            'income', 'pemasukan', 'dapat', 'terima', 'diterima',
            'gaji', 'thr', 'bonus', 'untung', 'hasil', 'bayaran',
            'transfer masuk', 'masuk', 'profit', 'pendapatan',
            'dibayar', 'fee', 'honor', 'komisi',
        ];
        foreach ($incomeKeywords as $kw) {
            if (str_contains($lower, $kw)) return 'income';
        }
        return 'expense';
    }

    // ============================================================
    // HELPER: Bersihkan description dari nominal dan keyword
    // ============================================================
    private static function cleanDescription(string $msg): string
    {
        $clean = $msg;
        $clean = preg_replace('/\d+\.?\d*\s*(juta|jt|ribu|rb|k)\b/i', '', $clean);
        $clean = preg_replace('/\b\d{1,3}(?:\.\d{3})+\b/', '', $clean);
        $clean = preg_replace('/\b\d+\b/', '', $clean);
        $clean = preg_replace('/\b(income|pemasukan|pengeluaran|expense)\b/i', '', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean, " \t\n\r:-");
        return $clean ?: $msg;
    }

    // ============================================================
    // HELPER: Cari kategori dari riwayat keyword user
    // Urutan: exact match → partial match → null (fallback ke default)
    // ============================================================
    private static function lookupUserKeyword(string $description, string $type, int $userId, PDO $db): ?string
    {
        if (empty(trim($description))) return null;

        $words = self::extractKeywords($description);
        if (empty($words)) return null;

        // Coba exact match per kata, prioritaskan yang hit_count tertinggi
        $placeholders = implode(',', array_fill(0, count($words), '?'));
        $stmt = $db->prepare(
            "SELECT ukc.keyword, c.name AS category_name, ukc.hit_count
             FROM user_keyword_categories ukc
             JOIN categories c ON c.id = ukc.category_id
             WHERE ukc.user_id = ?
               AND ukc.type = ?
               AND ukc.keyword IN ($placeholders)
             ORDER BY ukc.hit_count DESC
             LIMIT 1"
        );
        $params = array_merge([$userId, $type], $words);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ? $row['category_name'] : null;
    }

    // ============================================================
    // HELPER: Ekstrak kata kunci dari deskripsi (hapus stopword)
    // ============================================================
    public static function extractAmountFromText(string $text): int
    {
        return self::extractAmount($text);
    }

    public static function extractKeywords(string $description): array
    {
        $stopwords = [
            'ke', 'di', 'dari', 'untuk', 'dan', 'atau', 'yang', 'ini', 'itu',
            'dengan', 'pada', 'oleh', 'buat', 'beli', 'bayar', 'bisa', 'ada',
            'sudah', 'belum', 'saya', 'aku', 'kamu', 'nya', 'nya', 'juga',
            'tapi', 'kalau', 'jika', 'maka', 'agar', 'karena', 'saat',
        ];

        $lower = strtolower($description);
        // Hapus karakter non-alphanumeric kecuali spasi
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower);
        $words = preg_split('/\s+/', trim($clean));

        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 3 && !in_array($word, $stopwords)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    // ============================================================
    // HELPER: Deteksi kategori dari teks
    // ============================================================
    private static function detectCategory(string $description, string $type): string
    {
        $lower = strtolower($description);

        $expenseMap = [
            'Makanan & Minuman'    => ['makan', 'minum', 'nasi', 'ayam', 'soto', 'bakso',
                                       'mie', 'pizza', 'burger', 'kopi', 'teh', 'jus',
                                       'sarapan', 'snack', 'camilan', 'warung', 'restoran',
                                       'cafe', 'warteg', 'indomie', 'goreng', 'bakar', 'sate',
                                       'bubur', 'lauk', 'sayur',
                                       // tambahan
                                       'makan siang', 'makan malam', 'makan pagi', 'makan sore',
                                       'pecel', 'gado-gado', 'gado gado', 'ketoprak', 'lontong',
                                       'nasi padang', 'nasi uduk', 'nasi kuning', 'nasi box',
                                       'catering', 'katering', 'delivery', 'grabfood', 'gofood',
                                       'shopeefood', 'ojol', 'pesan makan', 'boba', 'minuman',
                                       'es', 'susu', 'roti', 'martabak', 'gorengan', 'kue',
                                       'dessert', 'jajan', 'cilok', 'dimsum', 'siomay',
                                       'seafood', 'ikan', 'udang', 'daging', 'sapi', 'kambing',
                                       'steak', 'mcdonald', 'kfc', 'mcD', 'jollibee'],
            
            'Transportasi'         => ['bensin', 'bbm', 'solar', 'pertamax', 'pertalite',
                                       'parkir', 'ojek', 'gojek', 'grab', 'taksi', 'taxi',
                                       'bus', 'kereta', 'tol', 'ganti oli', 'oli', 'ban',
                                       'bengkel', 'servis', 'service', 'sparepart', 'motor',
                                       'mobil', 'transport', 'ongkos', 'angkot',
                                       // tambahan
                                       'transjakarta', 'busway', 'mrt', 'lrt', 'krl',
                                       'commuter', 'damri', 'travel', 'shuttle', 'bis',
                                       'pesawat', 'tiket', 'bandara', 'airport', 'pelabuhan',
                                       'kapal', 'ferry', 'grab bike', 'grabcar', 'maxim',
                                       'inDriver', 'belts', 'helm', 'jas hujan', 'aki',
                                       'radiator', 'kopling', 'rem', 'lampu motor', 'knalpot',
                                       'bbm pertamina', 'shell', 'vivo', 'uang jalan',
                                       'perjalanan', 'tiket kereta', 'tiket pesawat'],
            
            'Belanja'              => ['belanja', 'beli', 'baju', 'sepatu', 'celana', 'kaos',
                                       'tas', 'elektronik', 'hp', 'handphone', 'laptop',
                                       'supermarket', 'minimarket', 'indomaret', 'alfamart',
                                       'shopee', 'tokopedia', 'lazada', 'toko', 'alfa',
                                       // tambahan
                                       'giant', 'hypermart', 'carrefour', 'lottemart', 'hero',
                                       'transmart', 'mall', 'plaza', 'jaket', 'kemeja', 'dress',
                                       'rok', 'dompet', 'kacamata', 'jam tangan', 'cincin',
                                       'perhiasan', 'gelang', 'kalung', 'aksesoris',
                                       'kosmetik', 'makeup', 'skincare', 'parfum', 'lotion',
                                       'charger', 'kabel', 'earphone', 'headset', 'speaker',
                                       'printer', 'monitor', 'keyboard', 'mouse', 'flash disk',
                                       'blibli', 'bukalapak', 'tiktok shop', 'cod', 'belanja online', 'lipstrik', 'tokped'],
            
            'Kesehatan'            => ['obat', 'dokter', 'klinik', 'rumah sakit', 'apotek',
                                       'vitamin', 'suplemen', 'periksa', 'bpjs', 'vaksin',
                                       // tambahan
                                       'rs', 'puskesmas', 'laboratorium', 'lab', 'rontgen',
                                       'usg', 'rawat inap', 'rawat jalan', 'operasi', 'gigi',
                                       'dokter gigi', 'optik', 'kacamata', 'lensa', 'kontak',
                                       'fisioterapi', 'terapi', 'pijat', 'spa', 'refleksi',
                                       'konsultasi', 'resep', 'antibiotik', 'parasetamol',
                                       'ibuprofen', 'masker', 'hansaplast', 'perban', 'bidan',
                                       'bersalin', 'imunisasi', 'cek darah', 'tensi', 'kontrol'],
            
            'Tagihan & Utilitas'   => ['listrik', 'pln', 'air', 'pdam', 'internet', 'wifi',
                                       'indihome', 'telpon', 'telepon', 'pulsa', 'kuota',
                                       'token', 'tagihan', 'iuran', 'cicilan',
                                       // tambahan
                                       'firstmedia', 'myrepublic', 'biznet', 'xl', 'telkomsel',
                                       'tri', 'three', 'axis', 'smartfren', 'by.u',
                                       'pascabayar', 'prabayar', 'angsuran', 'kredit',
                                       'kartu kredit', 'cicil', 'dp', 'uang muka',
                                       'tv kabel', 'main listrik', 'sambungan', 'berlangganan',
                                       'subscribe', 'subscription', 'renewal', 'perpanjang', 'langganan'],
            
            'Pendidikan'           => ['sekolah', 'kuliah', 'spp', 'ukt', 'buku', 'les',
                                       'kursus', 'seminar', 'pelatihan', 'training',
                                       // tambahan
                                       'alat tulis', 'pensil', 'pulpen', 'binder', 'buku tulis',
                                       'tas sekolah', 'seragam', 'daftar ulang', 'ujian',
                                       'wisuda', 'skripsi', 'tesis', 'fotokopi', 'print',
                                       'ruangguru', 'zenius', 'quipper', 'duolingo', 'udemy',
                                       'webinar', 'workshop', 'bootcamp', 'sertifikasi',
                                       'studi', 'pendaftaran', 'administrasi sekolah'],
            
            'Hiburan'              => ['bioskop', 'film', 'nonton', 'game', 'streaming',
                                       'netflix', 'spotify', 'liburan', 'wisata', 'karaoke',
                                       // tambahan
                                       'youtube premium', 'disney', 'vidio', 'viu', 'mola',
                                       'apple music', 'joox', 'resso', 'steam', 'playstation',
                                       'xbox', 'nintendo', 'mobile legend', 'freefire', 'ml',
                                       'main game', 'top up', 'diamond', 'voucher game',
                                       'konser', 'pertunjukan', 'tiket masuk', 'wahana',
                                       'pantai', 'gunung', 'hotel', 'penginapan', 'resort',
                                       'kolam renang', 'taman', 'rekreasi', 'piknik',
                                       'staycation', 'airbnb', 'booking', 'tiket wisata'],
            
            'Rumah Tangga'         => ['sabun', 'deterjen', 'shampo', 'pasta gigi', 'tisu',
                                       'sewa', 'kos', 'kontrakan', 'gas', 'lpg', 'dapur',
                                       'perabot', 'furniture',
                                       // tambahan
                                       'ipkl', 'ipl', 'service charge', 'kebersihan', 'sampah',
                                       'cat', 'renovasi', 'bangun', 'material', 'semen',
                                       'besi', 'pipa', 'keran', 'pompa', 'ac', 'servis ac',
                                       'cuci ac', 'kulkas', 'mesin cuci', 'dispenser',
                                       'kompor', 'magic com', 'rice cooker', 'wajan', 'panci',
                                       'baskom', 'ember', 'sapu', 'pel', 'sikat', 'pembersih',
                                       'pewangi', 'softener', 'pembasmi', 'racun serangga',
                                       'kontrak', 'deposit', 'uang kontrakan', 'uang kos',
                                       'kondisioner', 'handuk', 'sprei', 'bantal', 'kasur'],
            
            'Sosial & Donasi'      => ['sedekah', 'infaq', 'zakat', 'donasi', 'sumbangan',
                                       'hadiah', 'kado', 'arisan', 'kondoleansi',
                                       // tambahan
                                       'kolekte', 'wakaf', 'jariyah', 'fidyah', 'kafarat',
                                       'iuran warga', 'iuran rt', 'iuran rw', 'kas rt',
                                       'kas rw', 'patungan', 'urunan', 'nyumbang',
                                       'kondangan', 'pernikahan', 'nikahan', 'khitanan',
                                       'sunatan', 'aqiqah', 'ulang tahun', 'ultah', 'tengok bayi', 'tengok', 'anniversary',
                                       'parcel', 'bingkisan', 'hamper', 'angpao'],
        ];

        $incomeMap = [
            'Gaji'           => ['gaji', 'salary', 'upah', 'honor', 'tpp', 'tpg'],
            'Bonus & THR'    => ['thr', 'bonus', 'insentif', 'reward'],
            'Usaha'          => ['usaha', 'dagang', 'jualan', 'omset', 'profit', 'untung'],
            'Investasi'      => ['investasi', 'dividen', 'return', 'bunga', 'deposito', 'saham'],
            'Transfer Masuk' => ['transfer masuk', 'terima transfer', 'dikirim', 'transfer dari', 'terima'],
        ];

        $map = $type === 'income' ? $incomeMap : $expenseMap;

        foreach ($map as $categoryName => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) return $categoryName;
            }
        }

        return 'Lainnya';
    }

    // Alias untuk kompatibilitas dengan TransactionManager
    public static function parseAmount($value): int
    {
        return self::extractAmount((string)$value);
    }
    
    private static function isTooLong(string $msg): bool
    {
        // Pesan > 200 karakter kemungkinan bukan catatan keuangan
        // (undangan, promo, info dari platform, dll)
        if (mb_strlen($msg) <= 200) return false;
    
        // Tapi kalau mengandung kode TXN (edit/hapus), tetap proses
        if (preg_match('/\bTXN-\d{8}-\d{4}\b/i', $msg)) return false;
    
        // Kalau mengandung kata command jelas, tetap proses
        $commandWords = ['rekap', 'laporan', 'summary', 'hapus', 'edit', 'ubah', 'cari', 'saldo'];
        $lower = strtolower($msg);
        foreach ($commandWords as $word) {
            if (str_contains($lower, $word)) return false;
        }
    
        return true;
    }
    
    private static function isLinkOnly(string $msg): bool
    {
        // Deteksi link: http/https atau domain umum sosmed
        // Termasuk pesan yang isinya hanya atau didominasi link
        $cleaned = trim($msg);
    
        // Cek apakah pesan mengandung URL
        if (!preg_match('/https?:\/\/\S+/i', $cleaned)) {
            return false;
        }
    
        // Kalau ada URL, cek apakah sisa teks di luar URL sangat singkat
        // (artinya pesan memang cuma link, atau link + caption singkat)
        $tanpaUrl = trim(preg_replace('/https?:\/\/\S+/i', '', $cleaned));
        $tanpaUrl = trim(preg_replace('/\s+/', ' ', $tanpaUrl));
    
        // Jika sisa teks < 15 karakter setelah URL dihapus → abaikan
        if (mb_strlen($tanpaUrl) < 15) return true;
    
        // Jika domain sosmed terdeteksi → abaikan
        $sosmed = ['instagram.com', 'ig.me', 'youtube.com', 'youtu.be',
                   'facebook.com', 'fb.com', 'fb.watch', 'tiktok.com',
                   'twitter.com', 'x.com', 't.co', 'wa.me'];
        $lower = strtolower($cleaned);
        foreach ($sosmed as $domain) {
            if (str_contains($lower, $domain)) return true;
        }
    
        return false;
    }
}