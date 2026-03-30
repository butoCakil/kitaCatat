<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'System Check';
require_once __DIR__ . '/layout/header.php';

$rootDir = dirname(__DIR__);

// ============================================================
// Scan error_log files secara rekursif
// ============================================================
$errorLogFiles = [];
try {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && stripos($file->getFilename(), 'error_log') !== false) {
            $errorLogFiles[] = [
                'path'       => $file->getPathname(),
                'rel'        => str_replace($rootDir . '/', '', $file->getPathname()),
                'size'       => $file->getSize(),
                'mtime'      => $file->getMTime(),
                'hasContent' => $file->getSize() > 0,
            ];
        }
    }
    usort($errorLogFiles, fn($a, $b) => $b['mtime'] - $a['mtime']);
} catch (Exception $e) {}

$hasErrors = !empty(array_filter($errorLogFiles, fn($f) => $f['hasContent']));

// ============================================================
// Build folder tree sebagai string HTML
// ============================================================
function buildTree(string $dir, string $prefix = '', int $depth = 0): string {
    $out   = '';
    $files = @scandir($dir);
    if (!$files) return '';
    $files = array_values(array_diff($files, ['.', '..']));
    $last  = end($files);

    $extColors = [
        'php'      => '#93c5fd',
        'sql'      => '#6ee7b7',
        'html'     => '#fdba74',
        'js'       => '#fb923c',
        'css'      => '#c4b5fd',
        'htaccess' => '#fcd34d',
        'json'     => '#e2e8f0',
        'txt'      => '#e2e8f0',
        'md'       => '#e2e8f0',
        'png'      => '#bbf7d0',
        'jpg'      => '#bbf7d0',
        'jpeg'     => '#bbf7d0',
        'ico'      => '#bbf7d0',
        'svg'      => '#bbf7d0',
    ];

    foreach ($files as $file) {
        $path       = $dir . '/' . $file;
        $isLast     = ($file === $last);
        $connector  = $isLast ? '└── ' : '├── ';
        $newPrefix  = $isLast ? $prefix . '    ' : $prefix . '│   ';
        $isDir      = is_dir($path);
        $isErrLog   = !$isDir && stripos($file, 'error_log') !== false;
        $fileSize   = $isErrLog ? @filesize($path) : 0;
        $hasContent = $isErrLog && $fileSize > 0;

        $prefixHtml = htmlspecialchars($prefix . $connector);
        $fileHtml   = htmlspecialchars($file);

        if ($hasContent) {
            $sizeStr = $fileSize > 1024 ? round($fileSize / 1024, 1) . ' KB' : $fileSize . ' B';
            $out .= '<span style="color:#94a3b8">' . $prefixHtml . '</span>'
                  . '<span style="color:#f87171;font-weight:700">' . $fileHtml . '</span>'
                  . '<span style="color:#fca5a5;font-size:10.5px"> ⚠ ' . $sizeStr . '</span>' . "\n";
        } elseif ($isErrLog) {
            $out .= '<span style="color:#94a3b8">' . $prefixHtml . '</span>'
                  . '<span style="color:#94a3b8;font-style:italic">' . $fileHtml . ' (kosong)</span>' . "\n";
        } elseif ($isDir) {
            $out .= '<span style="color:#94a3b8">' . $prefixHtml . '</span>'
                  . '<span style="color:#fde68a;font-weight:600">' . $fileHtml . '</span>' . "\n";
            $out .= buildTree($path, $newPrefix, $depth + 1);
            continue;
        } else {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === '' && strtolower($file) === '.htaccess') $ext = 'htaccess';
            $fileColor = $extColors[$ext] ?? '#94a3b8';
            $out .= '<span style="color:#94a3b8">' . $prefixHtml . '</span>'
                  . '<span style="color:' . $fileColor . '">' . $fileHtml . '</span>' . "\n";
        }

        if ($isDir) {
            $out .= buildTree($path, $newPrefix, $depth + 1);
        }
    }
    return $out;
}

// ============================================================
// Info server
// ============================================================
$phpVersion  = phpversion();
$memLimit    = ini_get('memory_limit');
$uploadMax   = ini_get('upload_max_filesize');
$postMax     = ini_get('post_max_size');
$maxExec     = ini_get('max_execution_time');
$diskFree    = function_exists('disk_free_space')  ? disk_free_space($rootDir)  : null;
$diskTotal   = function_exists('disk_total_space') ? disk_total_space($rootDir) : null;

function fmtBytes(float $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 2)    . ' MB';
    return round($b / 1024, 2) . ' KB';
}
?>

<!-- Security Banner -->
<div class="alert mb-4" style="background:#fef9c3;border:1px solid #fef08a;border-radius:var(--radius);font-size:13px;color:#854d0e">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>
    <strong>File sensitif.</strong> Halaman ini menampilkan struktur folder, skema database, dan konfigurasi server.
    Pastikan hanya dapat diakses oleh admin dan pertimbangkan untuk menghapus file ini di production.
</div>

<?php if ($hasErrors): ?>
<!-- Error Alert -->
<div class="alert mb-4 d-flex align-items-center gap-3"
     style="background:#fef2f2;border:2px solid #f87171;border-radius:var(--radius);font-size:13px;color:#991b1b">
    <i class="fa-solid fa-circle-exclamation fa-lg" style="flex-shrink:0"></i>
    <div>
        <strong>Ditemukan <?= count(array_filter($errorLogFiles, fn($f) => $f['hasContent'])) ?> file error_log tidak kosong!</strong>
        <span class="ms-2">
            <a href="#section-errorlog" style="color:#b91c1c;font-weight:600">Lihat di bawah</a>
            &nbsp;·&nbsp;
            <a href="/admin/logs.php?tab=errorlog" style="color:#b91c1c;font-weight:600">Buka Error Log</a>
        </span>
    </div>
</div>
<?php endif; ?>

<div class="mb-4">
    <h5 class="mb-1 fw-bold">System Check</h5>
    <p class="text-muted mb-0" style="font-size:13px">Struktur folder, skema database, dan info server</p>
</div>

<!-- ============================================================
     Info Server
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header">
        <i class="fa-solid fa-server me-2 text-muted"></i>Info Server
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <tbody>
                <?php
                $debugMode = defined('DEBUG_MODE') ? DEBUG_MODE : null;
                $rows = [
                    ['PHP Version',    $phpVersion,                                    null],
                    ['Memory Limit',   $memLimit,                                      null],
                    ['Upload Max',     $uploadMax,                                     null],
                    ['Post Max',       $postMax,                                       null],
                    ['Max Exec Time',  $maxExec . 's',                                 null],
                    ['Disk Free',      $diskFree  ? fmtBytes($diskFree)  : '—',        null],
                    ['Disk Total',     $diskTotal ? fmtBytes($diskTotal) : '—',        null],
                    ['Root Dir',       $rootDir,                                       null],
                    ['APP_URL',        defined('APP_URL')  ? APP_URL  : '—',           null],
                    ['DB Host',        defined('DB_HOST')  ? DB_HOST  : '—',           null],
                    ['DB Name',        defined('DB_NAME')  ? DB_NAME  : '—',           null],
                    ['Timezone',       date_default_timezone_get(),                    null],
                    ['Debug Mode',     $debugMode === null ? '—' : ($debugMode ? 'TRUE ⚠' : 'false'),
                                       $debugMode ? '#dc2626' : '#16a34a'],
                ];
                foreach ($rows as [$label, $value, $color]): ?>
                <tr>
                    <td class="px-4 py-2" style="width:160px;font-size:12px;font-weight:600;color:var(--text-muted);white-space:nowrap">
                        <?= htmlspecialchars($label) ?>
                    </td>
                    <td class="py-2" style="font-family:var(--font-mono);font-size:12px<?= $color ? ';color:'.$color.';font-weight:700' : '' ?>">
                        <?= htmlspecialchars((string)$value) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     Error Log Files
     ============================================================ -->
<div class="card mb-4" id="section-errorlog">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-triangle-exclamation me-2 text-muted"></i>Error Log Files</span>
        <?php if ($hasErrors): ?>
        <span style="background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px">
            <?= count(array_filter($errorLogFiles, fn($f) => $f['hasContent'])) ?> berisi error
        </span>
        <?php else: ?>
        <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:2px 8px;border-radius:4px">
            <i class="fa-solid fa-circle-check me-1"></i>Bersih
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($errorLogFiles)): ?>
        <div class="text-center text-muted py-4" style="font-size:13px">
            <i class="fa-solid fa-circle-check me-2" style="color:#16a34a"></i>Tidak ada file error_log ditemukan
        </div>
        <?php else: ?>
        <?php foreach ($errorLogFiles as $ef): ?>
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom">
            <div class="d-flex align-items-center gap-3">
                <i class="fa-solid fa-file-lines fa-lg"
                   style="color:<?= $ef['hasContent'] ? '#f87171' : '#cbd5e1' ?>;flex-shrink:0"></i>
                <div>
                    <div style="font-family:var(--font-mono);font-size:12px;
                         font-weight:<?= $ef['hasContent'] ? '700' : '400' ?>;
                         color:<?= $ef['hasContent'] ? '#dc2626' : 'var(--text-muted)' ?>">
                        <?= htmlspecialchars($ef['rel']) ?>
                    </div>
                    <div style="font-size:11px;color:var(--text-muted)">
                        <?= $ef['size'] > 0 ? fmtBytes($ef['size']) : 'Kosong' ?>
                        &nbsp;·&nbsp; Diubah: <?= date('d M Y H:i', $ef['mtime']) ?>
                    </div>
                </div>
            </div>
            <?php if ($ef['hasContent']): ?>
            <a href="/admin/logs.php?tab=errorlog&errfile=<?= urlencode($ef['rel']) ?>"
               class="btn btn-sm btn-outline-danger" style="font-size:11px;padding:3px 10px;flex-shrink:0">
                <i class="fa-solid fa-eye me-1"></i>Lihat
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     Folder Tree
     ============================================================ -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-folder-tree me-2 text-muted"></i>Struktur Folder</span>
        <span style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)">
            <?= htmlspecialchars(basename($rootDir)) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div style="background:#0f172a;padding:16px 20px;overflow-x:auto;max-height:540px;overflow-y:auto;
                    border-radius:0 0 var(--radius) var(--radius)">
            <pre style="margin:0;font-family:var(--font-mono);font-size:12px;color:#94a3b8;line-height:1.65"><?php
echo '<span style="color:#e2e8f0;font-weight:700">' . htmlspecialchars(basename($rootDir)) . "</span>\n";
echo buildTree($rootDir);
?></pre>
        </div>
    </div>
</div>

<!-- ============================================================
     Skema Database
     ============================================================ -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-database me-2 text-muted"></i>Skema Database</span>
        <span style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)">
            <?= htmlspecialchars(DB_NAME) ?>
        </span>
    </div>
    <div class="card-body p-0">
        <div style="background:#0f172a;padding:16px 20px;overflow-x:auto;max-height:540px;overflow-y:auto;
                    border-radius:0 0 var(--radius) var(--radius)">
            <pre style="margin:0;font-family:var(--font-mono);font-size:12px;color:#94a3b8;line-height:1.65"><?php
try {
    $pdo    = getDB();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $total  = count($tables);

    echo '<span style="color:#6ee7b7;font-weight:700">' . htmlspecialchars(DB_NAME) . "</span>\n";

    foreach ($tables as $i => $table) {
        $isLast    = ($i === $total - 1);
        $tConn     = $isLast ? '└── ' : '├── ';
        $tPrefix   = $isLast ? '    ' : '│   ';

        echo '<span style="color:#93c5fd;font-weight:600">'
           . htmlspecialchars($tConn . $table)
           . "</span>\n";

        $cols     = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        $colTotal = count($cols);

        foreach ($cols as $j => $c) {
            $cLast  = ($j === $colTotal - 1);
            $cConn  = $tPrefix . ($cLast ? '└── ' : '├── ');

            // Warna per tipe kolom
            $type = $c['Type'];
            if      (str_contains($type, 'int'))      $tc = '#a5f3fc';
            elseif  (str_contains($type, 'varchar'))  $tc = '#bbf7d0';
            elseif  (str_contains($type, 'enum'))     $tc = '#ddd6fe';
            elseif  (str_contains($type, 'datetime')
                  || str_contains($type, 'date'))     $tc = '#fed7aa';
            elseif  (str_contains($type, 'text')
                  || str_contains($type, 'longtext')) $tc = '#bbf7d0';
            elseif  (str_contains($type, 'tinyint'))  $tc = '#a5f3fc';
            elseif  (str_contains($type, 'bigint'))   $tc = '#a5f3fc';
            else                                      $tc = '#fde68a';

            $badges = '';
            if ($c['Key'] === 'PRI') $badges .= ' <span style="color:#fbbf24;font-size:10px">PK</span>';
            if ($c['Key'] === 'MUL') $badges .= ' <span style="color:#94a3b8;font-size:10px">IDX</span>';
            if ($c['Key'] === 'UNI') $badges .= ' <span style="color:#c4b5fd;font-size:10px">UNI</span>';
            if ($c['Null'] === 'NO' && $c['Key'] !== 'PRI')
                                     $badges .= ' <span style="color:#f87171;font-size:10px">NN</span>';

            echo htmlspecialchars($cConn)
               . htmlspecialchars($c['Field'])
               . ' <span style="color:' . $tc . '">(' . htmlspecialchars($type) . ')</span>'
               . $badges . "\n";
        }
    }
} catch (Exception $e) {
    echo '<span style="color:#f87171">Error koneksi: ' . htmlspecialchars($e->getMessage()) . '</span>';
}
?></pre>
        </div>
    </div>
</div>

<?php
$extraScript = '';
require_once __DIR__ . '/layout/footer.php';
?>
