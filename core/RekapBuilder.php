<?php
// ============================================================
// KitaCatat — RekapBuilder
// Hitung rekap keuangan berdasarkan periode dan scope
// ============================================================

class RekapBuilder
{
    private array $user;
    private PDO   $db;

    public function __construct(array $user, PDO $db)
    {
        $this->user = $user;
        $this->db   = $db;
    }

    // ============================================================
    // BUILD rekap utama
    // ============================================================
    public function build(string $period, string $scope): ?array
    {
        [$dateStart, $dateEnd] = $this->resolveDateRange($period);
        $userIds = $this->resolveUserIds($scope);

        if (empty($userIds)) return null;

        // Handle grup non-shared: filter by group_id bukan user_id
        if (isset($userIds['__group_id__'])) {
            $groupId = (int)$userIds['__group_id__'];
            $where   = "t.group_id = ? AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?";
            $params  = [$groupId, $dateStart, $dateEnd];
            return $this->runQuery($where, $params);
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $where  = "t.user_id IN ($placeholders) AND t.deleted_at IS NULL AND t.created_at BETWEEN ? AND ?";
        $params = [...$userIds, $dateStart, $dateEnd];
        return $this->runQuery($where, $params);
    }

    // ============================================================
    // QUERY: Jalankan kalkulasi rekap
    // ============================================================
    private function runQuery(string $where, array $params): ?array
    {
        // Summary
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN t.type = 'income'  THEN t.amount ELSE 0 END) AS total_income,
                SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END) AS total_expense,
                COUNT(*) AS total_transactions
             FROM transactions t
             WHERE $where"
        );
        $stmt->execute($params);
        $summary = $stmt->fetch();

        if (!$summary || (int)$summary['total_transactions'] === 0) {
            return null;
        }

        // Top 5 pengeluaran per kategori
        $stmt = $this->db->prepare(
            "SELECT c.name AS category, c.icon, SUM(t.amount) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE $where AND t.type = 'expense'
             GROUP BY t.category_id, c.name, c.icon
             ORDER BY total DESC LIMIT 5"
        );
        $stmt->execute($params);
        $topExpense = $stmt->fetchAll();

        // Top 3 pemasukan per kategori
        $stmt = $this->db->prepare(
            "SELECT c.name AS category, c.icon, SUM(t.amount) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE $where AND t.type = 'income'
             GROUP BY t.category_id, c.name, c.icon
             ORDER BY total DESC LIMIT 3"
        );
        $stmt->execute($params);
        $topIncome = $stmt->fetchAll();

        return [
            'total_income'       => (int)($summary['total_income']       ?? 0),
            'total_expense'      => (int)($summary['total_expense']      ?? 0),
            'total_transactions' => (int)($summary['total_transactions'] ?? 0),
            'top_expense'        => $topExpense,
            'top_income'         => $topIncome,
        ];
    }

    // ============================================================
    // HELPER: Resolve rentang tanggal
    // ============================================================
    public function resolveDateRange(string $period): array
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

        switch ($period) {
            case 'this_month':
                $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
                $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
                break;
            case 'last_month':
                $start = (clone $now)->modify('first day of last month')->setTime(0, 0, 0);
                $end   = (clone $now)->modify('last day of last month')->setTime(23, 59, 59);
                break;
            case 'this_year':
                $year  = $now->format('Y');
                $start = new DateTime("{$year}-01-01 00:00:00", new DateTimeZone('Asia/Jakarta'));
                $end   = new DateTime("{$year}-12-31 23:59:59", new DateTimeZone('Asia/Jakarta'));
                break;
            default:
                if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
                    $year  = $m[1];
                    $month = $m[2];
                    $last  = date('t', mktime(0, 0, 0, (int)$month, 1, (int)$year));
                    $start = new DateTime("{$year}-{$month}-01 00:00:00", new DateTimeZone('Asia/Jakarta'));
                    $end   = new DateTime("{$year}-{$month}-{$last} 23:59:59", new DateTimeZone('Asia/Jakarta'));
                } else {
                    $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
                    $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
                }
                break;
        }

        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }

    // ============================================================
    // HELPER: Resolve user IDs berdasarkan scope
    // ============================================================
    private function resolveUserIds(string $scope): array
    {
        if ($scope === 'personal') {
            return [$this->user['id']];
        }

        if (in_array(strtolower($scope), ['family', 'keluarga', 'famili'])) {
            return $this->getMemberIdsFromSharedGroups();
        }

        // Scope group:namagrup dari NLPParser
        $keyword = str_starts_with($scope, 'group:') ? substr($scope, 6) : $scope;

        // Cari grup berdasarkan nama atau alias
        $stmt = $this->db->prepare(
            "SELECT g.id, g.is_shared FROM groups g
             JOIN group_members gm ON gm.group_id = g.id
             WHERE gm.user_id = ?
               AND (LOWER(g.name) LIKE LOWER(?) OR LOWER(g.alias) = LOWER(?))
             LIMIT 1"
        );
        $stmt->execute([$this->user['id'], '%' . $keyword . '%', $keyword]);
        $group = $stmt->fetch();

        if ($group) {
            if ($group['is_shared']) {
                $stmt2 = $this->db->prepare(
                    "SELECT user_id FROM group_members WHERE group_id = ?"
                );
                $stmt2->execute([$group['id']]);
                return array_column($stmt2->fetchAll(), 'user_id');
            } else {
                return ['__group_id__' => $group['id']];
            }
        }

        return [$this->user['id']];
    }

    // ============================================================
    // HELPER: Ambil semua user_id dari grup shared
    // ============================================================
    private function getMemberIdsFromSharedGroups(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT gm2.user_id
             FROM group_members gm1
             JOIN groups g ON g.id = gm1.group_id AND g.is_shared = 1
             JOIN group_members gm2 ON gm2.group_id = g.id
             WHERE gm1.user_id = ?"
        );
        $stmt->execute([$this->user['id']]);
        $rows = $stmt->fetchAll();

        return empty($rows) ? [$this->user['id']] : array_column($rows, 'user_id');
    }

    // ============================================================
    // HELPER: Label periode untuk pesan WA
    // ============================================================
    public function getPeriodeLabel(string $period): string
    {
        $bulan = [
            '01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
            '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
            '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'
        ];

        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

        switch ($period) {
            case 'this_month':
                return $bulan[$now->format('m')] . ' ' . $now->format('Y');
            case 'last_month':
                $last = (clone $now)->modify('last month');
                return $bulan[$last->format('m')] . ' ' . $last->format('Y');
            case 'this_year':
                return 'Tahun ' . $now->format('Y');
            default:
                if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
                    return ($bulan[$m[2]] ?? $m[2]) . ' ' . $m[1];
                }
                return $period;
        }
    }

    // ============================================================
    // HELPER: Label scope untuk pesan WA
    // ============================================================
    public function getScopeLabel(string $scope): string
    {
        $map = [
            'personal' => 'Pribadi',
            'family'   => 'Keluarga',
            'keluarga' => 'Keluarga',
            'famili'   => 'Keluarga',
        ];

        if (isset($map[strtolower($scope)])) {
            return $map[strtolower($scope)];
        }

        // group:namagrup → ambil nama setelah "group:"
        if (str_starts_with($scope, 'group:')) {
            return ucfirst(substr($scope, 6));
        }

        return ucfirst($scope);
    }
}