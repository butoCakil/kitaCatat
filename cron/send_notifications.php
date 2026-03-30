<?php
// ============================================================
// KitaCatat — Cron: Notifikasi Harian & Mingguan
// Setup di cPanel: Cron Jobs → setiap hari jam 07.00
// Command: php /home/dvttaulx/public_html/kitacatat.masbendz.com/cron/send_notifications.php
// ============================================================
define('CRON_RUN', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/WASender.php';

$db      = getDB();
$today   = date('Y-m-d');
$dayName = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][date('w')];
$isMonday = date('w') === '1'; // Senin = rekap mingguan

// Ambil semua user aktif
$users = $db->query("SELECT id, name, wa_number FROM users WHERE is_active=1")->fetchAll();

$sent = 0;
foreach ($users as $user) {
    $userId = (int)$user['id'];

    // ============================================================
    // NOTIFIKASI HARIAN — Ringkasan pengeluaran kemarin
    // ============================================================
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
            SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
            COUNT(*) AS trx_count
         FROM transactions
         WHERE user_id=? AND DATE(created_at)=? AND deleted_at IS NULL"
    );
    $stmt->execute([$userId, $yesterday]);
    $daily = $stmt->fetch();

    // Hanya kirim jika ada transaksi kemarin dengan nominal > 0
    if ((int)$daily['trx_count'] > 0 && ((int)$daily['total_expense'] > 0 || (int)$daily['total_income'] > 0)) {
        $expense = (int)$daily['total_expense'];
        $income  = (int)$daily['total_income'];

        $msg  = "📅 *Ringkasan " . date('d M Y', strtotime($yesterday)) . "*\n\n";
        if ($income > 0)  $msg .= "📈 Pemasukan  : " . formatRp($income)  . "\n";
        if ($expense > 0) $msg .= "📉 Pengeluaran: " . formatRp($expense) . "\n";
        $msg .= "📊 Transaksi  : {$daily['trx_count']} catatan\n\n";
        $msg .= "_KitaCatat — " . APP_URL . "/dashboard_";

        WASender::send($user['wa_number'], $msg);
        $sent++;
        usleep(300000); // 0.3 detik jeda antar user
    }

    // ============================================================
    // NOTIFIKASI MINGGUAN — Rekap 7 hari (hanya Senin)
    // ============================================================
    if ($isMonday) {
        $weekStart = date('Y-m-d', strtotime('last Monday', strtotime($today)));
        $weekEnd   = date('Y-m-d', strtotime('last Sunday', strtotime($today)));

        $stmtW = $db->prepare(
            "SELECT
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS total_expense,
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS total_income,
                COUNT(*) AS trx_count
             FROM transactions
             WHERE user_id=? AND DATE(created_at) BETWEEN ? AND ? AND deleted_at IS NULL"
        );
        $stmtW->execute([$userId, $weekStart, $weekEnd]);
        $weekly = $stmtW->fetch();

        if ((int)$weekly['trx_count'] > 0) {
            $expense = (int)$weekly['total_expense'];
            $income  = (int)$weekly['total_income'];
            $saldo   = $income - $expense;

            // Top 3 kategori pengeluaran minggu ini
            $topCat = $db->prepare(
                "SELECT c.name, SUM(t.amount) AS total
                 FROM transactions t
                 LEFT JOIN categories c ON c.id=t.category_id
                 WHERE t.user_id=? AND t.type='expense'
                   AND DATE(t.created_at) BETWEEN ? AND ?
                   AND t.deleted_at IS NULL
                 GROUP BY c.name ORDER BY total DESC LIMIT 3"
            );
            $topCat->execute([$userId, $weekStart, $weekEnd]);
            $tops = $topCat->fetchAll();

            $wStart = date('d M', strtotime($weekStart));
            $wEnd   = date('d M', strtotime($weekEnd));

            $msg  = "📊 *Rekap Mingguan ({$wStart} – {$wEnd})*\n\n";
            $msg .= "📈 Pemasukan  : " . formatRp($income)  . "\n";
            $msg .= "📉 Pengeluaran: " . formatRp($expense) . "\n";
            $msg .= ($saldo >= 0 ? "🟢" : "🔴") . " Saldo      : " . formatRp(abs($saldo)) . ($saldo < 0 ? " (defisit)" : "") . "\n";
            $msg .= "📋 Transaksi  : {$weekly['trx_count']} catatan\n";

            if (!empty($tops)) {
                $msg .= "\n*Top Pengeluaran:*\n";
                foreach ($tops as $i => $t) {
                    $msg .= ($i+1) . ". " . ($t['name'] ?? 'Lainnya') . " " . formatRp((int)$t['total']) . "\n";
                }
            }

            $msg .= "\n_Detail: " . APP_URL . "/dashboard_";

            WASender::send($user['wa_number'], $msg);
            $sent++;
            usleep(300000);
        }
    }
}

// ============================================================
// PENGINGAT TERJADWAL
// ============================================================
$today = date('Y-m-d');

// Ambil semua jadwal yang jatuh tempo hari ini atau sudah lewat
// dan belum ada log pending untuk tanggal ini
$schedules = $db->query(
    "SELECT s.*, u.wa_number, u.name AS user_name, c.name AS category_name
     FROM scheduled_transactions s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN categories c ON c.id = s.category_id
     WHERE s.is_active = 1
       AND s.next_run <= '{$today}'
       AND (s.end_date IS NULL OR s.end_date >= '{$today}')
       AND NOT EXISTS (
           SELECT 1 FROM scheduled_logs sl
           WHERE sl.scheduled_id = s.id
             AND sl.due_date = s.next_run
             AND sl.status IN ('pending','confirmed')
       )"
)->fetchAll();

foreach ($schedules as $sched) {
    $schedId = (int)$sched['id'];
    $userId  = (int)$sched['user_id'];
    $dueDate = $sched['next_run'];

    // Mode auto — langsung catat tanpa tanya
    if ($sched['mode'] === 'auto' && $sched['amount']) {
        // Simpan transaksi langsung
        $code = 'TXN-' . date('Ymd') . '-' . str_pad(
            $db->query("SELECT COUNT(*)+1 FROM transactions WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
            4, '0', STR_PAD_LEFT
        );
        $db->prepare(
            "INSERT INTO transactions (unique_code,user_id,type,amount,description,category_id,source,created_at)
             VALUES (?,?,?,?,?,?,'scheduled',NOW())"
        )->execute([
            $code, $userId, $sched['type'], $sched['amount'],
            $sched['title'], $sched['category_id']
        ]);
        $trxId = (int)$db->lastInsertId();

        // Log sebagai confirmed
        $db->prepare(
            "INSERT INTO scheduled_logs (scheduled_id,user_id,due_date,status,transaction_id,reminded_count,last_reminded)
             VALUES (?,?,?,'confirmed',?,1,NOW())"
        )->execute([$schedId, $userId, $dueDate, $trxId]);

        // Notif ke WA
        $icon = $sched['type'] === 'income' ? '📈' : '📉';
        $msg  = "⚡ *Transaksi Rutin Otomatis*

";
        $msg .= "[{$code}]
";
        $msg .= "{$icon} " . ($sched['type']==='income'?'Pemasukan':'Pengeluaran') . ": Rp " . number_format((int)$sched['amount'],0,',','.') . "
";
        $msg .= "📝 " . $sched['title'] . "
";
        $msg .= "🏷️ " . ($sched['category_name'] ?? 'Lainnya');
        WASender::send($sched['wa_number'], $msg);

    } else {
        // Mode confirm atau ask_amount — kirim pengingat, tunggu jawaban
        $db->prepare(
            "INSERT INTO scheduled_logs (scheduled_id,user_id,due_date,status,reminded_count,last_reminded,next_remind)
             VALUES (?,?,?,'pending',1,NOW(),?)"
        )->execute([$schedId, $userId, $dueDate, date('Y-m-d', strtotime('+' . $sched['reminder_interval'] . ' days'))]);

        $logId = (int)$db->lastInsertId();
        $icon  = $sched['type'] === 'income' ? '📈' : '📉';

        $msg  = "⏰ *Pengingat: " . $sched['title'] . "*

";
        if ($sched['mode'] === 'ask_amount') {
            $msg .= "{$icon} " . ($sched['type']==='income'?'Pemasukan':'Pengeluaran') . "
";
            $msg .= "🏷️ " . ($sched['category_name'] ?? 'Lainnya') . "

";
            $msg .= "Sudah " . ($sched['type']==='income'?'diterima':'dibayar') . "?
";
            $msg .= "Balas dengan nominal jika sudah (contoh: *5jt* atau *350rb*)
";
            $msg .= "Atau balas *belum* / *besok* untuk tunda.";
        } else {
            $nominal = $sched['amount'] ? "Rp " . number_format((int)$sched['amount'],0,',','.') : "—";
            $msg .= "{$icon} " . ($sched['type']==='income'?'Pemasukan':'Pengeluaran') . ": {$nominal}
";
            $msg .= "🏷️ " . ($sched['category_name'] ?? 'Lainnya') . "

";
            $msg .= "Sudah " . ($sched['type']==='income'?'diterima':'dibayar') . "?
";
            $msg .= "Balas *ya* untuk konfirmasi atau *belum* / *besok* untuk tunda.";
        }

        WASender::send($sched['wa_number'], $msg);
    }

    // Hitung next_run berikutnya
    $freq = $sched['frequency'];
    if ($freq === 'once') {
        // Nonaktifkan setelah selesai
        $db->prepare("UPDATE scheduled_transactions SET is_active=0 WHERE id=?")->execute([$schedId]);
    } elseif ($freq === 'daily') {
        $nextRun = date('Y-m-d', strtotime('+1 day'));
        $db->prepare("UPDATE scheduled_transactions SET next_run=? WHERE id=?")->execute([$nextRun, $schedId]);
    } elseif ($freq === 'weekly') {
        $nextRun = date('Y-m-d', strtotime('+7 days'));
        $db->prepare("UPDATE scheduled_transactions SET next_run=? WHERE id=?")->execute([$nextRun, $schedId]);
    } elseif ($freq === 'monthly') {
        $dom     = (int)$sched['day_of_month'];
        $nextRun = date('Y-m-', strtotime('first day of next month')) . str_pad($dom, 2, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE scheduled_transactions SET next_run=? WHERE id=?")->execute([$nextRun, $schedId]);
    } elseif ($freq === 'yearly') {
        $nextRun = (date('Y') + 1) . '-' . date('m-d', strtotime($dueDate));
        $db->prepare("UPDATE scheduled_transactions SET next_run=? WHERE id=?")->execute([$nextRun, $schedId]);
    }

    $sent++;
    usleep(300000);
}

// Kirim pengingat ulang untuk pending yang belum dijawab
$pendingReminders = $db->query(
    "SELECT sl.*, s.title, s.type, s.amount, s.mode, s.reminder_max,
            s.reminder_interval, s.category_id, c.name AS category_name,
            u.wa_number
     FROM scheduled_logs sl
     JOIN scheduled_transactions s ON s.id = sl.scheduled_id
     JOIN users u ON u.id = sl.user_id
     LEFT JOIN categories c ON c.id = s.category_id
     WHERE sl.status = 'pending'
       AND sl.next_remind <= '{$today}'
       AND sl.reminded_count < s.reminder_max"
)->fetchAll();

foreach ($pendingReminders as $rem) {
    $icon    = $rem['type'] === 'income' ? '📈' : '📉';
    $nominal = $rem['amount'] ? "Rp " . number_format((int)$rem['amount'],0,',','.') : "—";

    $msg  = "🔔 *Pengingat ke-" . ($rem['reminded_count']+1) . ": " . $rem['title'] . "*

";
    $msg .= "{$icon} " . ($rem['type']==='income'?'Pemasukan':'Pengeluaran');
    if ($rem['amount']) $msg .= ": {$nominal}";
    $msg .= "

";
    if ($rem['mode'] === 'ask_amount') {
        $msg .= "Balas dengan nominal jika sudah, atau *belum* / *besok* untuk tunda.";
    } else {
        $msg .= "Balas *ya* untuk konfirmasi atau *belum* / *besok* untuk tunda.";
    }

    WASender::send($rem['wa_number'], $msg);

    $newCount   = $rem['reminded_count'] + 1;
    $nextRemind = date('Y-m-d', strtotime('+' . $rem['reminder_interval'] . ' days'));

    $db->prepare(
        "UPDATE scheduled_logs SET reminded_count=?, last_reminded=NOW(), next_remind=? WHERE id=?"
    )->execute([$newCount, $nextRemind, $rem['id']]);

    $sent++;
    usleep(300000);
}

echo "✅ Notifikasi terkirim ke {$sent} user pada " . date('Y-m-d H:i:s') . "\n";
error_log("[KitaCatat Notif] Terkirim: {$sent} pada {$today}");

function formatRp(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}