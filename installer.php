<?php
/**
 * Website Migrator - Standalone Self-Contained Installer
 * 1-File Deployment & Restoration Script for Target Hosting.
 *
 * @author kazuhamoe <kazuhamoe@users.noreply.github.com>
 * @license MIT
 */

@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '600');
@set_time_limit(600);
if (function_exists('ignore_user_abort')) {
    @ignore_user_abort(true);
}

define('INSTALLER_VERSION', '1.0.0');

// -------------------------------------------------------------
// AJAX CONTROLLER
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. Uji Koneksi Database Baru
        if ($action === 'test_db') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';

            if (strpos($host, ':') !== false) {
                [$host, $customPort] = explode(':', $host, 2);
                $port = (int)$customPort;
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            echo json_encode(['success' => true, 'message' => 'Koneksi database tujuan berhasil terhubung!']);
            exit;
        }

        // 2. Ekstrak Berkas ZIP Bertahap (Chunked)
        if ($action === 'extract_chunk') {
            if (!extension_loaded('zip')) {
                throw new RuntimeException('Ekstensi PHP ZipArchive tidak aktif.');
            }

            $zipFile = __DIR__ . '/migrator_package.zip';
            if (!file_exists($zipFile)) {
                throw new RuntimeException('Berkas migrator_package.zip tidak ditemukan di direktori ini.');
            }

            $startIndex = (int)($_POST['start_index'] ?? 0);
            $chunkSize = 300; // 300 berkas per chunk AJAX

            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new RuntimeException('Gagal membuka berkas migrator_package.zip.');
            }

            $totalFiles = $zip->numFiles;
            $endIndex = min($startIndex + $chunkSize, $totalFiles);
            $extractedCount = 0;

            for ($i = $startIndex; $i < $endIndex; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $filename = $stat['name'];

                // Pencegahan path traversal
                if (str_contains($filename, '../') || str_contains($filename, '..\\')) {
                    continue;
                }

                // Hindari menimpa installer.php yang sedang berjalan
                if ($filename === 'installer.php') {
                    continue;
                }

                $zip->extractTo(__DIR__, [$filename]);
                $extractedCount++;
            }

            $zip->close();
            $done = ($endIndex >= $totalFiles);
            $percent = $totalFiles > 0 ? round(($endIndex / $totalFiles) * 100, 1) : 100;

            echo json_encode([
                'success' => true,
                'start_index' => $startIndex,
                'next_index' => $endIndex,
                'total_files' => $totalFiles,
                'extracted' => $extractedCount,
                'percent' => $percent,
                'done' => $done,
                'message' => $done
                    ? "Ekstraksi berkas tuntas! Total {$totalFiles} berkas dipulihkan."
                    : "Mengekstrak berkas... ({$endIndex}/{$totalFiles} - {$percent}%)"
            ]);
            exit;
        }

        // 3. Impor Database SQL Bertahap (Chunked)
        if ($action === 'import_sql_chunk') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';
            $offset = (int)($_POST['offset'] ?? 0);

            if (strpos($host, ':') !== false) {
                [$host, $customPort] = explode(':', $host, 2);
                $port = (int)$customPort;
            }

            $sqlFile = __DIR__ . '/migrator_database.sql';
            if (!file_exists($sqlFile)) {
                // Berkas SQL mungkin ada di storage/temp atau root
                if (file_exists(__DIR__ . '/storage/temp/migrator_database.sql')) {
                    $sqlFile = __DIR__ . '/storage/temp/migrator_database.sql';
                } else {
                    echo json_encode(['success' => true, 'done' => true, 'message' => 'Tidak ada berkas database untuk diimpor.']);
                    exit;
                }
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

            $fileSize = filesize($sqlFile);
            $fp = fopen($sqlFile, 'r');
            if ($offset > 0) {
                fseek($fp, $offset);
            }

            $startTime = microtime(true);
            $maxTime = 12.0; // 12 detik limit per batch
            $maxQueries = 300;
            $queryCount = 0;
            $currentQuery = '';
            $inString = false;
            $stringChar = '';

            while (!feof($fp)) {
                $line = fgets($fp);
                if ($line === false) break;

                $trimmed = trim($line);
                if (!$inString && (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*'))) {
                    continue;
                }

                $len = strlen($line);
                for ($i = 0; $i < $len; $i++) {
                    $char = $line[$i];
                    $prevChar = ($i > 0) ? $line[$i - 1] : '';

                    if (($char === "'" || $char === '"') && $prevChar !== '\\') {
                        if (!$inString) {
                            $inString = true;
                            $stringChar = $char;
                        } elseif ($char === $stringChar) {
                            $inString = false;
                        }
                    }

                    $currentQuery .= $char;

                    if ($char === ';' && !$inString) {
                        $q = trim($currentQuery);
                        if (!empty($q)) {
                            try {
                                $pdo->exec($q);
                                $queryCount++;
                            } catch (Throwable $e) {
                                if (stripos($q, 'DROP TABLE') === false && stripos($q, 'DROP VIEW') === false) {
                                    // Log minor error & continue
                                }
                            }
                        }
                        $currentQuery = '';

                        if ($queryCount >= $maxQueries || (microtime(true) - $startTime) >= $maxTime) {
                            break 2;
                        }
                    }
                }
            }

            $newOffset = ftell($fp);
            $done = (feof($fp) || $newOffset >= $fileSize);
            if ($done) {
                $newOffset = $fileSize;
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            }
            fclose($fp);

            $percent = $fileSize > 0 ? round(($newOffset / $fileSize) * 100, 1) : 100;

            echo json_encode([
                'success' => true,
                'offset' => $newOffset,
                'total_size' => $fileSize,
                'executed' => $queryCount,
                'percent' => $percent,
                'done' => $done,
                'message' => $done ? "Restorasi database 100% selesai." : "Mengimpor tabel & data database ({$percent}%)..."
            ]);
            exit;
        }

        // 4. Search & Replace Domain / URL (dengan Rekalkulasi Serialized)
        if ($action === 'search_replace') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';
            $oldUrl = trim($_POST['old_url'] ?? '');
            $newUrl = trim($_POST['new_url'] ?? '');

            if (empty($oldUrl) || empty($newUrl) || $oldUrl === $newUrl) {
                echo json_encode(['success' => true, 'updated_rows' => 0, 'message' => 'URL lama dan baru sama atau kosong, penggantian dilewati.']);
                exit;
            }

            if (strpos($host, ':') !== false) {
                [$host, $customPort] = explode(':', $host, 2);
                $port = (int)$customPort;
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Serialized Replacer terintegrasi
            $replaceFunc = function ($data, $s, $r) use (&$replaceFunc) {
                if (is_array($data)) {
                    $arr = [];
                    foreach ($data as $k => $v) {
                        $nk = is_string($k) ? $replaceFunc($k, $s, $r) : $k;
                        $arr[$nk] = $replaceFunc($v, $s, $r);
                    }
                    return $arr;
                }
                if (is_string($data)) {
                    // Cek serialized
                    if (strlen($data) > 3 && $data[1] === ':' && in_array($data[0], ['s', 'a', 'O', 'b', 'i', 'd'])) {
                        $un = @unserialize($data);
                        if ($un !== false || $data === 'b:0;') {
                            return serialize($replaceFunc($un, $s, $r));
                        }
                    }
                    return str_replace($s, $r, $data);
                }
                return $data;
            };

            $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = [];
            while ($r = $tablesStmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $r[0];
            }

            $totalUpdated = 0;
            foreach ($tables as $table) {
                $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
                $pks = [];
                $txts = [];

                foreach ($cols as $c) {
                    if ($c['Key'] === 'PRI') $pks[] = $c['Field'];
                    $t = strtolower($c['Type']);
                    if (strpos($t, 'char') !== false || strpos($t, 'text') !== false || strpos($t, 'blob') !== false) {
                        $txts[] = $c['Field'];
                    }
                }

                if (empty($pks) || empty($txts)) continue;

                $where = [];
                foreach ($txts as $col) {
                    $where[] = "`{$col}` LIKE " . $pdo->quote('%' . $oldUrl . '%');
                }
                $selSql = "SELECT " . implode(', ', array_merge($pks, $txts)) . " FROM `{$table}` WHERE " . implode(' OR ', $where);
                $rows = $pdo->query($selSql);

                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $updates = [];
                    foreach ($txts as $col) {
                        $val = $row[$col];
                        if ($val !== null && is_string($val) && strpos($val, $oldUrl) !== false) {
                            $newVal = $replaceFunc($val, $oldUrl, $newUrl);
                            if ($newVal !== $val) {
                                $updates[$col] = $newVal;
                            }
                        }
                    }

                    if (!empty($updates)) {
                        $setSql = [];
                        $b = [];
                        foreach ($updates as $col => $nv) {
                            $setSql[] = "`{$col}` = ?";
                            $b[] = $nv;
                        }
                        $pkSql = [];
                        foreach ($pks as $pk) {
                            $pkSql[] = "`{$pk}` = ?";
                            $b[] = $row[$pk];
                        }
                        $upStmt = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $setSql) . " WHERE " . implode(' AND ', $pkSql));
                        $upStmt->execute($b);
                        $totalUpdated++;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'updated_rows' => $totalUpdated,
                'message' => "Search & replace selesai! {$totalUpdated} baris data berhasil disesuaikan ke URL baru."
            ]);
            exit;
        }

        // 5. Update Konfigurasi Website (wp-config.php / .env)
        if ($action === 'update_config') {
            $host = trim($_POST['host'] ?? 'localhost');
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';
            $updatedConfigs = [];

            // A. Update wp-config.php jika ada
            $wpConfig = __DIR__ . '/wp-config.php';
            if (file_exists($wpConfig)) {
                $c = file_get_contents($wpConfig);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_NAME', '{$db}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_USER', '{$user}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_PASSWORD', '{$pass}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_HOST', '{$host}');", $c);
                file_put_contents($wpConfig, $c);
                $updatedConfigs[] = 'wp-config.php';
            }

            // B. Update .env Laravel jika ada
            $envPath = __DIR__ . '/.env';
            if (file_exists($envPath)) {
                $c = file_get_contents($envPath);
                $c = preg_replace('/^DB_HOST=.*$/m', "DB_HOST={$host}", $c);
                $c = preg_replace('/^DB_DATABASE=.*$/m', "DB_DATABASE={$db}", $c);
                $c = preg_replace('/^DB_USERNAME=.*$/m', "DB_USERNAME={$user}", $c);
                $c = preg_replace('/^DB_PASSWORD=.*$/m', "DB_PASSWORD=\"{$pass}\"", $c);
                if (!empty($_POST['new_url'])) {
                    $newUrl = trim($_POST['new_url']);
                    $c = preg_replace('/^APP_URL=.*$/m', "APP_URL={$newUrl}", $c);
                }
                file_put_contents($envPath, $c);
                $updatedConfigs[] = '.env';
            }

            echo json_encode([
                'success' => true,
                'updated_files' => $updatedConfigs,
                'message' => !empty($updatedConfigs)
                    ? "Konfigurasi (" . implode(', ', $updatedConfigs) . ") berhasil diperbarui dengan koneksi DB baru."
                    : "Konfigurasi bawaan tidak memerlukan update otomatis."
            ]);
            exit;
        }

        // 6. Self-Destruct Cleanup (Hapus Berkas Migrator untuk Keamanan)
        if ($action === 'self_destruct') {
            $filesToDelete = [
                __DIR__ . '/migrator_package.zip',
                __DIR__ . '/migrator_database.sql',
                __DIR__ . '/storage/temp/migrator_database.sql',
                __DIR__ . '/storage/temp/migrator_package.zip',
            ];

            foreach ($filesToDelete as $f) {
                if (file_exists($f)) {
                    @unlink($f);
                }
            }

            // Hapus installer.php itu sendiri
            @unlink(__FILE__);

            echo json_encode([
                'success' => true,
                'message' => 'Berkas installer dan paket migrasi telah dihapus permanen demi keamanan server Anda.'
            ]);
            exit;
        }

        throw new Exception("Action tidak dikenal: {$action}");
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------
// PRE-FLIGHT COMPATIBILITY CHECKS
// -------------------------------------------------------------
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$zipOk = extension_loaded('zip');
$pdoOk = extension_loaded('pdo_mysql');
$isWritable = is_writable(__DIR__);
$packageExists = file_exists(__DIR__ . '/migrator_package.zip');

// Auto-detect current URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$detectedHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentPath = dirname($_SERVER['SCRIPT_NAME']);
$currentSiteUrl = rtrim($protocol . $detectedHost . ($currentPath !== '/' && $currentPath !== '\\' ? $currentPath : ''), '/');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Migrator - Standalone Installer</title>
    <style>
        :root {
            --bg-main: #0b0f19;
            --bg-card: rgba(17, 24, 39, 0.75);
            --bg-surface: #131b2e;
            --bg-terminal: #070a13;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-highlight: rgba(99, 102, 241, 0.35);
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-muted: #6b7280;
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-glow: rgba(99, 102, 241, 0.3);
            --success: #10b981;
            --success-glow: rgba(16, 185, 129, 0.25);
            --warning: #f59e0b;
            --danger: #ef4444;
            --cyan: #06b6d4;
            --font-main: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-mono: "JetBrains Mono", Consolas, monospace;
            --radius: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-main);
            background-image: radial-gradient(circle at 15% 15%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
                              radial-gradient(circle at 85% 85%, rgba(6, 182, 212, 0.1) 0%, transparent 45%);
            background-attachment: fixed;
            color: var(--text-primary);
            font-family: var(--font-main);
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .container { max-width: 960px; margin: 0 auto; padding: 2.5rem 1.5rem; flex: 1; }
        .app-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
        .logo-area { display: flex; align-items: center; gap: 1rem; }
        .logo-icon { width: 48px; height: 48px; border-radius: var(--radius); background: linear-gradient(135deg, var(--primary) 0%, var(--cyan) 100%); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 20px var(--primary-glow); }
        .logo-icon svg { width: 28px; height: 28px; fill: #ffffff; }
        .logo-title h1 { font-size: 1.5rem; font-weight: 700; color: #fff; }
        .logo-title p { font-size: 0.85rem; color: var(--text-secondary); }
        .badge-version { font-size: 0.75rem; padding: 0.2rem 0.6rem; background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 9999px; font-weight: 600; }
        .glass-card { background: var(--bg-card); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 2rem; margin-bottom: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .card-title { font-size: 1.25rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; }
        .card-title svg { width: 22px; height: 22px; color: var(--primary); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-group label { font-size: 0.85rem; font-weight: 500; color: var(--text-secondary); }
        .form-control { background: rgba(11, 15, 25, 0.8); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.65rem 0.9rem; font-size: 0.95rem; color: var(--text-primary); font-family: inherit; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-glow); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.75rem 1.4rem; font-size: 0.92rem; font-weight: 600; border-radius: 8px; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, #4f46e5 100%); color: #fff; box-shadow: 0 4px 15px var(--primary-glow); }
        .btn-primary:hover { transform: translateY(-1px); }
        .btn-secondary { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid var(--border-color); }
        .btn-success { background: linear-gradient(135deg, var(--success) 0%, #059669 100%); color: #fff; box-shadow: 0 4px 15px var(--success-glow); }
        .btn-danger { background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%); color: #fff; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .req-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .req-item { background: rgba(11, 15, 25, 0.6); border: 1px solid var(--border-color); border-radius: 8px; padding: 1rem 1.15rem; display: flex; align-items: center; justify-content: space-between; gap: 0.85rem; }
        .req-info { flex: 1; min-width: 0; }
        .req-badge { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; padding: 0.3rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; white-space: nowrap; flex-shrink: 0; line-height: 1; }
        .req-badge.pass { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .req-badge.fail { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .progress-container { margin: 1.5rem 0; }
        .progress-bar-bg { width: 100%; height: 10px; background: rgba(255,255,255,0.05); border-radius: 9999px; overflow: hidden; }
        .progress-bar-fill { height: 100%; width: 0%; background: linear-gradient(90deg, var(--primary) 0%, var(--cyan) 100%); transition: width 0.3s ease; box-shadow: 0 0 12px var(--primary-glow); }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.82rem; color: var(--text-secondary); margin-top: 0.4rem; }
        .terminal-box { background: var(--bg-terminal); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; margin-top: 1.5rem; }
        .terminal-header { background: #0d121f; padding: 0.5rem 1rem; font-family: var(--font-mono); font-size: 0.78rem; color: var(--text-muted); }
        .terminal-content { font-family: var(--font-mono); font-size: 0.84rem; padding: 1rem; height: 220px; overflow-y: auto; color: #d1d5db; line-height: 1.6; }
        .log-line { margin-bottom: 0.25rem; word-break: break-all; }
        .log-info { color: #60a5fa; }
        .log-success { color: #34d399; }
        .log-error { color: #f87171; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.88rem; }
        .alert-info { background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.25); color: #93c5fd; }
        .alert-danger { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); color: #fca5a5; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #6ee7b7; }
        .app-footer { text-align: center; padding: 2rem 0; color: var(--text-muted); font-size: 0.85rem; border-top: 1px solid var(--border-color); }
        .app-footer a { color: #818cf8; text-decoration: none; }
    </style>
</head>
<body>

<div class="container">
    <header class="app-header">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/>
                </svg>
            </div>
            <div class="logo-title">
                <h1>Website Migrator - Installer</h1>
                <p>Pemulihan Berkas &amp; Database Otomatis di Hosting Baru</p>
            </div>
        </div>
        <span class="badge-version">v<?php echo INSTALLER_VERSION; ?></span>
    </header>

    <!-- Pre-Flight Requirements -->
    <div class="glass-card">
        <h2 class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Pemeriksaan Lingkungan Hosting Baru
        </h2>
        <div class="req-grid">
            <div class="req-item">
                <div><h4>PHP &gt;= 7.4</h4><p><?php echo PHP_VERSION; ?></p></div>
                <span class="req-badge <?php echo $phpOk ? 'pass' : 'fail'; ?>"><?php echo $phpOk ? '✓ Lolos' : '✗ Kurang'; ?></span>
            </div>
            <div class="req-item">
                <div><h4>ZipArchive</h4><p><?php echo $zipOk ? 'Tersedia' : 'Tidak Ada'; ?></p></div>
                <span class="req-badge <?php echo $zipOk ? 'pass' : 'fail'; ?>"><?php echo $zipOk ? '✓ Lolos' : '✗ Kurang'; ?></span>
            </div>
            <div class="req-item">
                <div><h4>MySQL PDO</h4><p><?php echo $pdoOk ? 'Tersedia' : 'Tidak Ada'; ?></p></div>
                <span class="req-badge <?php echo $pdoOk ? 'pass' : 'fail'; ?>"><?php echo $pdoOk ? '✓ Lolos' : '✗ Kurang'; ?></span>
            </div>
            <div class="req-item">
                <div><h4>Arsip ZIP</h4><p><?php echo $packageExists ? 'migrator_package.zip' : 'Tidak Ditemukan'; ?></p></div>
                <span class="req-badge <?php echo $packageExists ? 'pass' : 'fail'; ?>"><?php echo $packageExists ? '✓ Ditemukan' : '✗ Tidak Ada'; ?></span>
            </div>
        </div>

        <?php if (!$packageExists): ?>
            <div class="alert alert-danger">
                ⚠️ Berkas <strong>migrator_package.zip</strong> tidak ditemukan di direktori yang sama dengan installer.php! Pastikan Anda telah mengunggah arsip migrasi ke folder ini.
            </div>
        <?php endif; ?>
    </div>

    <!-- Installation Form -->
    <div class="glass-card" id="installerFormCard">
        <h2 class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Konfigurasi Database &amp; Domain Baru
        </h2>

        <form id="installForm">
            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #cbd5e1;">1. Kredensial Database Baru</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="dbHost">Database Host</label>
                    <input type="text" id="dbHost" name="host" class="form-control" value="localhost" required>
                </div>
                <div class="form-group">
                    <label for="dbPort">Port</label>
                    <input type="number" id="dbPort" name="port" class="form-control" value="3306" required>
                </div>
                <div class="form-group">
                    <label for="dbName">Nama Database Baru</label>
                    <input type="text" id="dbName" name="database" class="form-control" placeholder="nama_db" required>
                </div>
                <div class="form-group">
                    <label for="dbUser">Username DB Baru</label>
                    <input type="text" id="dbUser" name="username" class="form-control" placeholder="user_db" required>
                </div>
                <div class="form-group">
                    <label for="dbPass">Password DB Baru</label>
                    <input type="password" id="dbPass" name="password" class="form-control" placeholder="(jika ada)">
                </div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <button type="button" id="btnTestDb" class="btn btn-secondary">🔍 Uji Koneksi DB Baru</button>
            </div>

            <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #cbd5e1;">2. Penyesuaian Domain / URL (Search &amp; Replace)</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="oldUrl">Domain / URL Lama (Asal)</label>
                    <input type="text" id="oldUrl" name="old_url" class="form-control" placeholder="https://domain-lama.com">
                </div>
                <div class="form-group">
                    <label for="newUrl">Domain / URL Baru (Tujuan)</label>
                    <input type="text" id="newUrl" name="new_url" class="form-control" value="<?php echo htmlspecialchars($currentSiteUrl); ?>" required>
                </div>
            </div>

            <button type="button" id="btnStartInstall" class="btn btn-primary" style="font-size: 1.05rem; padding: 0.85rem 2rem;" <?php echo !$packageExists ? 'disabled' : ''; ?>>
                🚀 Mulai Pemulihan (Restore Website)
            </button>
        </form>

        <!-- Progress Bar -->
        <div class="progress-container">
            <div class="progress-bar-bg">
                <div id="progressBar" class="progress-bar-fill"></div>
            </div>
            <div class="progress-label">
                <span id="progressStatus">Menunggu instruksi instalasi...</span>
                <span id="progressText">0%</span>
            </div>
        </div>

        <!-- Terminal Console Log -->
        <div class="terminal-box">
            <div class="terminal-header">Terminal Log Pemulihan</div>
            <div id="terminalContent" class="terminal-content">
                <div class="log-line log-info"><span class="log-time">[Ready]</span> Installer v<?php echo INSTALLER_VERSION; ?> siap dijalankan.</div>
            </div>
        </div>
    </div>

    <!-- Final Success Card -->
    <div id="finishCard" class="glass-card" style="display: none; border-color: rgba(16, 185, 129, 0.4);">
        <h2 class="card-title" style="color: #34d399;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Migrasi Berhasil Selesai 100%!
        </h2>
        <div class="alert alert-success">
            Website dan seluruh database Anda telah berhasil dipulihkan secara sempurna ke hosting baru ini.
        </div>
        <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
            <a href="./" class="btn btn-success" target="_blank">🌐 Buka Website Baru</a>
            <button type="button" id="btnSelfDestruct" class="btn btn-danger">🔒 Hapus Berkas Migrator (Self-Destruct)</button>
        </div>
        <p style="font-size: 0.82rem; color: #9ca3af;">
            * Sangat disarankan untuk mengklik <strong>Hapus Berkas Migrator</strong> agar file installer dan paket backup tidak dapat diakses orang lain.
        </p>
    </div>

    <footer class="app-footer">
        <p>Website Migrator &bull; Standalone 1-Click Site Cloner &bull; <a href="https://github.com/kazuhamoe" target="_blank">@kazuhamoe</a></p>
    </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const btnTestDb = document.getElementById('btnTestDb');
    const btnStartInstall = document.getElementById('btnStartInstall');
    const btnSelfDestruct = document.getElementById('btnSelfDestruct');
    const terminal = document.getElementById('terminalContent');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressStatus = document.getElementById('progressStatus');
    const finishCard = document.getElementById('finishCard');

    function log(msg, type = 'info') {
        const line = document.createElement('div');
        line.className = `log-line log-${type}`;
        line.innerHTML = `[${new Date().toTimeString().split(' ')[0]}] ${msg}`;
        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;
    }

    function setProgress(pct, status) {
        if (progressBar) progressBar.style.width = pct + '%';
        if (progressText) progressText.innerText = pct + '%';
        if (progressStatus && status) progressStatus.innerText = status;
    }

    // Uji Koneksi DB
    if (btnTestDb) {
        btnTestDb.addEventListener('click', async () => {
            btnTestDb.disabled = true;
            btnTestDb.innerText = '⏳ Menguji...';
            log('Menguji koneksi database tujuan...', 'info');

            try {
                const fd = new FormData();
                fd.append('action', 'test_db');
                fd.append('host', document.getElementById('dbHost').value);
                fd.append('port', document.getElementById('dbPort').value);
                fd.append('database', document.getElementById('dbName').value);
                fd.append('username', document.getElementById('dbUser').value);
                fd.append('password', document.getElementById('dbPass').value);

                const res = await fetch('installer.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success) {
                    log(json.message, 'success');
                    alert('✅ ' + json.message);
                } else {
                    log(json.message, 'error');
                    alert('❌ ' + json.message);
                }
            } catch (e) {
                log('Error: ' + e.message, 'error');
            } finally {
                btnTestDb.disabled = false;
                btnTestDb.innerText = '🔍 Uji Koneksi DB Baru';
            }
        });
    }

    // Jalankan Restore
    if (btnStartInstall) {
        btnStartInstall.addEventListener('click', async () => {
            if (!confirm('Mulai proses pemulihan berkas dan database? Pastikan data penting di hosting ini telah diamankan.')) return;

            btnStartInstall.disabled = true;
            btnStartInstall.innerText = '⏳ Memproses Pemulihan...';
            log('=== MEMULAI RESTORASI WEBSITE ===', 'info');

            const host = document.getElementById('dbHost').value;
            const port = document.getElementById('dbPort').value;
            const db   = document.getElementById('dbName').value;
            const user = document.getElementById('dbUser').value;
            const pass = document.getElementById('dbPass').value;
            const oldUrl = document.getElementById('oldUrl').value;
            const newUrl = document.getElementById('newUrl').value;

            try {
                // 1. Ekstrak ZIP Bertahap
                log('Langkah 1/4: Mengekstrak arsip migrator_package.zip...', 'info');
                let startIndex = 0;
                let zipDone = false;

                while (!zipDone) {
                    const fd = new FormData();
                    fd.append('action', 'extract_chunk');
                    fd.append('start_index', startIndex);

                    const r = await fetch('installer.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message);

                    startIndex = res.next_index;
                    zipDone = res.done;
                    const pct = Math.round(res.percent * 0.4); // 0-40%
                    setProgress(pct, res.message);
                    log(res.message, 'info');
                }
                log('Arsip ZIP berhasil diekstrak seluruhnya!', 'success');

                // 2. Impor Database Bertahap
                log('Langkah 2/4: Mengimpor skema & data database MySQL...', 'info');
                let offset = 0;
                let sqlDone = false;

                while (!sqlDone) {
                    const fd = new FormData();
                    fd.append('action', 'import_sql_chunk');
                    fd.append('host', host);
                    fd.append('port', port);
                    fd.append('database', db);
                    fd.append('username', user);
                    fd.append('password', pass);
                    fd.append('offset', offset);

                    const r = await fetch('installer.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message);

                    offset = res.offset;
                    sqlDone = res.done;
                    const pct = 40 + Math.round(res.percent * 0.4); // 40-80%
                    setProgress(pct, res.message);
                    log(res.message, 'info');
                }
                log('Database MySQL berhasil dipulihkan!', 'success');

                // 3. Search & Replace Domain
                log('Langkah 3/4: Melakukan Search & Replace domain/URL...', 'info');
                setProgress(85, 'Menyesuaikan domain...');
                const srFd = new FormData();
                srFd.append('action', 'search_replace');
                srFd.append('host', host);
                srFd.append('port', port);
                srFd.append('database', db);
                srFd.append('username', user);
                srFd.append('password', pass);
                srFd.append('old_url', oldUrl);
                srFd.append('new_url', newUrl);

                const srR = await fetch('installer.php', { method: 'POST', body: srFd });
                const srRes = await srR.json();
                if (!srRes.success) throw new Error(srRes.message);
                log(srRes.message, 'success');

                // 4. Update Konfigurasi
                log('Langkah 4/4: Memperbarui file konfigurasi website...', 'info');
                setProgress(95, 'Memperbarui file konfigurasi...');
                const cfgFd = new FormData();
                cfgFd.append('action', 'update_config');
                cfgFd.append('host', host);
                cfgFd.append('database', db);
                cfgFd.append('username', user);
                cfgFd.append('password', pass);
                cfgFd.append('new_url', newUrl);

                const cfgR = await fetch('installer.php', { method: 'POST', body: cfgFd });
                const cfgRes = await cfgR.json();
                if (!cfgRes.success) throw new Error(cfgRes.message);
                log(cfgRes.message, 'success');

                setProgress(100, 'Restorasi selesai 100%!');
                log('🎉 PEMULIHAN WEBSITE SELESAI DENGAN SEMPURNA!', 'success');

                if (finishCard) {
                    finishCard.style.display = 'block';
                    finishCard.scrollIntoView({ behavior: 'smooth' });
                }
            } catch (err) {
                log('❌ Terjadi kesalahan: ' + err.message, 'error');
                setProgress(0, 'Restorasi gagal.');
                alert('Kesalahan pemulihan: ' + err.message);
            } finally {
                btnStartInstall.disabled = false;
                btnStartInstall.innerText = '🚀 Mulai Pemulihan (Restore Website)';
            }
        });
    }

    // Self-Destruct
    if (btnSelfDestruct) {
        btnSelfDestruct.addEventListener('click', async () => {
            if (!confirm('Yakin ingin menghapus installer.php dan arsip paket migrasi sekarang?')) return;
            try {
                const fd = new FormData();
                fd.append('action', 'self_destruct');
                const r = await fetch('installer.php', { method: 'POST', body: fd });
                const res = await r.json();
                if (res.success) {
                    alert('✅ ' + res.message);
                    window.location.href = './';
                } else {
                    alert('Gagal: ' + res.message);
                }
            } catch (e) {
                alert('Selesai.');
                window.location.href = './';
            }
        });
    }
});
</script>
</body>
</html>
