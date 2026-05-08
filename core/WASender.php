<?php
// ============================================================
// KitaCatat — WASender
// Kirim pesan WA keluar melalui Fonnte API
// ============================================================

class WASender
{
    /**
     * Kirim pesan teks ke nomor WA tertentu
     *
     * @param string $target   Nomor tujuan format 628xxx
     * @param string $message  Isi pesan
     * @return array           ['success' => bool, 'detail' => string]
     */
    public static function send(string $target, string $message): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => FONNTE_SEND_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'target'  => $target,
                'message' => $message,
            ],
            // CURLOPT_HTTPHEADER => [
            //     'Authorization: ' . FONNTE_TOKEN
            // ],
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . getAppSetting('fonnte_token', FONNTE_TOKEN)
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            error_log('[WASender] CURL ERROR: ' . $error);
            self::writeOutgoingLog($target, $message, false, 'CURL ERROR: ' . $error);
            return ['success' => false, 'detail' => $error];
        }

        curl_close($curl);

        // error_log('[DEBUG WASender] response: ' . $response);
        
        $json = json_decode($response, true);

        if (!$json) {
            error_log('[WASender] Invalid JSON response: ' . $response);
            self::writeOutgoingLog($target, $message, false, 'Invalid JSON response');
            return ['success' => false, 'detail' => 'Invalid response', 'raw' => $response];
        }

        $success = $json['status'] ?? false;
        self::writeOutgoingLog($target, $message, $success, $json['detail'] ?? null);

        return [
            'success' => $success,
            'detail'  => $json['detail'] ?? null,
            'raw'     => $response,
        ];
    }
    
    /**
     * Tulis log pesan keluar ke file
     */
    private static function writeOutgoingLog(string $target, string $message, bool $success, ?string $detail): void
    {
        if (!defined('LOG_OUTGOING') || !LOG_OUTGOING) return;

        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $status   = $success ? 'OK' : 'FAIL';
        $preview  = mb_substr(str_replace(["\n", "\r"], ' ', $message), 0, 80);
        $logLine  = date('Y-m-d H:i:s') . " | TO: $target | STATUS: $status";
        if ($detail) $logLine .= " | $detail";
        $logLine .= " | MSG: $preview" . PHP_EOL;

        file_put_contents($logDir . '/outgoing.log', $logLine, FILE_APPEND);
    }

    /**
     * Format angka Rupiah untuk pesan WA
     * Contoh: 50000 → "Rp50.000"
     */
    public static function formatRupiah(int $amount): string
    {
        return 'Rp' . number_format($amount, 0, ',', '.');
    }

    /**
     * Format pesan konfirmasi transaksi berhasil dicatat
     */
    public static function buildTransactionMessage(array $trx, array $sharedGroups = []): string
    {
        $icon   = $trx['type'] === 'income' ? '📈' : '📉';
        $label  = $trx['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran';
        $amount = self::formatRupiah($trx['amount']);

        $msg  = "✅ Tercatat!\n";
        $msg .= "[{$trx['unique_code']}]\n";
        $msg .= "$icon $label: $amount\n";
        $msg .= "📝 {$trx['description']}\n";
        $msg .= "🏷️ {$trx['category_name']}\n";

        if (!empty($sharedGroups)) {
            $groupNames = implode(', ', array_column($sharedGroups, 'name'));
            $msg .= "\nAkan di-share ke grup *{$groupNames}*.\n";
            $msg .= "Balas *NS* jika tidak ingin di-share.";
        }

        return $msg;
    }

    /**
     * Format pesan rekap keuangan
     */
    public static function buildRekapMessage(array $rekap, string $periode, string $scope): string
    {
        $saldo     = $rekap['total_income'] - $rekap['total_expense'];
        $saldoIcon = $saldo >= 0 ? '🟢' : '🔴';

        $msg  = "📊 *Rekap {$scope} — {$periode}*\n\n";
        $msg .= "Pemasukan  : " . self::formatRupiah($rekap['total_income']) . "\n";
        $msg .= "Pengeluaran: " . self::formatRupiah($rekap['total_expense']) . "\n";
        $msg .= "{$saldoIcon} Saldo     : " . self::formatRupiah(abs($saldo)) . ($saldo < 0 ? ' (minus)' : '') . "\n";

        if (!empty($rekap['top_expense'])) {
            $msg .= "\n*Top Pengeluaran:*\n";
            foreach ($rekap['top_expense'] as $i => $item) {
                $no = $i + 1;
                $msg .= "{$no}. {$item['category']}  " . self::formatRupiah($item['total']) . "\n";
            }
        }

        $msg .= "\nDetail lengkap: " . APP_URL . "/dashboard";

        return $msg;
    }
}