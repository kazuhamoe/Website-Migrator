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

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

define('INSTALLER_LOCK_FILE', __DIR__ . '/.migrator_lock.php');

function getSecurityLockData(): ?array {
    if (file_exists(INSTALLER_LOCK_FILE)) {
        $data = @include INSTALLER_LOCK_FILE;
        if (is_array($data) && !empty($data['hash'])) {
            return $data;
        }
    }
    return null;
}

function isInstallerAuthenticated(): bool {
    $lockData = getSecurityLockData();
    if ($lockData === null) {
        return true;
    }
    return !empty($_SESSION['installer_auth']) && $_SESSION['installer_auth'] === true;
}

// -------------------------------------------------------------
// AJAX CONTROLLER
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // A. Setup Password Keamanan Installer
        if ($action === 'set_security_pass') {
            $pass = trim($_POST['password'] ?? '');
            if (empty($pass)) {
                throw new InvalidArgumentException('Password keamanan tidak boleh kosong.');
            }
            if (strlen($pass) < 4) {
                throw new InvalidArgumentException('Password minimal 4 karakter demi keamanan.');
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $content = "<?php\n// Website Migrator Security Lock\ndefined('INSTALLER_VERSION') or exit('Direct access denied.');\nreturn " . var_export(['hash' => $hash, 'created_at' => date('c')], true) . ";\n";
            file_put_contents(INSTALLER_LOCK_FILE, $content);
            $_SESSION['installer_auth'] = true;
            echo json_encode(['success' => true, 'message' => 'Password proteksi installer berhasil diaktifkan!']);
            exit;
        }

        // B. Buka Kunci Installer (Unlock)
        if ($action === 'verify_security_pass') {
            $pass = trim($_POST['password'] ?? '');
            $lockData = getSecurityLockData();
            if ($lockData === null) {
                $_SESSION['installer_auth'] = true;
                echo json_encode(['success' => true, 'message' => 'Installer tidak terkunci.']);
                exit;
            }
            if (password_verify($pass, $lockData['hash'])) {
                $_SESSION['installer_auth'] = true;
                echo json_encode(['success' => true, 'message' => 'Kunci akses installer berhasil dibuka!']);
                exit;
            }
            throw new Exception('Password yang Anda masukkan tidak cocok.');
        }

        // Verifikasi apakah installer terkunci
        if (!isInstallerAuthenticated()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: Installer dalam status terkunci. Masukkan password terlebih dahulu.']);
            exit;
        }

        // C. Upload Berkas ZIP Langsung (Web Dropzone)
        if ($action === 'upload_package') {
            if (empty($_FILES['package_file'])) {
                throw new InvalidArgumentException('Tidak ada berkas yang diunggah.');
            }
            $file = $_FILES['package_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $maxUpload = ini_get('upload_max_filesize');
                throw new RuntimeException("Upload gagal (Error code: {$file['error']}). Batas upload hosting saat ini: {$maxUpload}.");
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                throw new InvalidArgumentException('Hanya berkas berformat .zip yang didukung.');
            }
            $destPath = __DIR__ . '/migrator_package.zip';
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new RuntimeException('Gagal menyimpan berkas ZIP ke direktori hosting.');
            }
            echo json_encode([
                'success' => true,
                'file_name' => 'migrator_package.zip',
                'file_size' => filesize($destPath),
                'formatted_size' => round(filesize($destPath) / 1024 / 1024, 2) . ' MB',
                'message' => 'Paket arsip ZIP berhasil diunggah ke server hosting!'
            ]);
            exit;
        }
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

            $selectedZip = trim($_POST['zip_file'] ?? '');
            $zipFile = '';
            if (!empty($selectedZip) && file_exists(__DIR__ . '/' . $selectedZip)) {
                $zipFile = __DIR__ . '/' . $selectedZip;
            } elseif (file_exists(__DIR__ . '/migrator_package.zip')) {
                $zipFile = __DIR__ . '/migrator_package.zip';
            } else {
                $zips = glob(__DIR__ . '/*.zip');
                if (!empty($zips)) {
                    $zipFile = $zips[0];
                }
            }

            if (empty($zipFile) || !file_exists($zipFile)) {
                throw new RuntimeException('Berkas ZIP website tidak ditemukan di direktori ini.');
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
                    // Cek apakah ada di dalam arsip ZIP
                    $zips = glob(__DIR__ . '/*.zip');
                    if (!empty($zips) && extension_loaded('zip')) {
                        $za = new ZipArchive();
                        if ($za->open($zips[0]) === true) {
                            if ($za->locateName('migrator_database.sql') !== false) {
                                $za->extractTo(__DIR__, ['migrator_database.sql']);
                                $sqlFile = __DIR__ . '/migrator_database.sql';
                            }
                            $za->close();
                        }
                    }
                }
            }
            if (!file_exists($sqlFile)) {
                echo json_encode(['success' => true, 'done' => true, 'message' => 'Tidak ada berkas database untuk diimpor.']);
                exit;
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
                            // Sanitasi collation MySQL 8 (utf8mb4_0900_ai_ci) agar kompatibel dengan MariaDB/MySQL 5.7 di hosting
                            if (strpos($q, 'utf8mb4_0900_ai_ci') !== false) {
                                $q = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $q);
                            }

                            try {
                                $pdo->exec($q);
                                $queryCount++;
                            } catch (Throwable $e) {
                                if (stripos($e->getMessage(), 'Unknown collation') !== false || stripos($e->getMessage(), '1273') !== false) {
                                    $fallbackQ = preg_replace('/COLLATE\s*=\s*utf8mb4_[a-z0-9_]+/i', 'COLLATE=utf8mb4_unicode_ci', $q);
                                    $fallbackQ = preg_replace('/COLLATE\s+utf8mb4_[a-z0-9_]+/i', 'COLLATE utf8mb4_unicode_ci', $fallbackQ);
                                    try {
                                        $pdo->exec($fallbackQ);
                                        $queryCount++;
                                    } catch (Throwable $e2) {
                                        // log minor
                                    }
                                } elseif (stripos($q, 'DROP TABLE') === false && stripos($q, 'DROP VIEW') === false) {
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

                // Auto-fix .htaccess & permalinks untuk WordPress
                $htaccessPath = __DIR__ . '/.htaccess';
                $needsHtaccess = false;
                if (!file_exists($htaccessPath)) {
                    $needsHtaccess = true;
                } else {
                    $htContent = file_get_contents($htaccessPath);
                    if (strpos($htContent, 'RewriteEngine') === false) {
                        $needsHtaccess = true;
                    }
                }
                if ($needsHtaccess) {
                    $wpRules = "# BEGIN WordPress\n"
                        . "<IfModule mod_rewrite.c>\n"
                        . "RewriteEngine On\n"
                        . "RewriteBase /\n"
                        . "RewriteRule ^index\\.php$ - [L]\n"
                        . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                        . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                        . "RewriteRule . /index.php [L]\n"
                        . "</IfModule>\n"
                        . "# END WordPress\n";
                    if (file_exists($htaccessPath)) {
                        file_put_contents($htaccessPath, $wpRules . "\n" . file_get_contents($htaccessPath));
                    } else {
                        file_put_contents($htaccessPath, $wpRules);
                    }
                    @chmod($htaccessPath, 0644);
                    $updatedConfigs[] = '.htaccess (WordPress Permalinks)';
                }
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
                INSTALLER_LOCK_FILE,
            ];

            foreach ($filesToDelete as $f) {
                if (file_exists($f)) {
                    @unlink($f);
                }
            }

            // Hapus installer.php itu sendiri
            @unlink(__FILE__);

            // Hancurkan session
            @session_destroy();

            echo json_encode([
                'success' => true,
                'message' => 'Berkas installer, lock proteksi, dan seluruh arsip paket migrasi telah dihapus permanen demi keamanan hosting Anda.'
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
$jsonOk = extension_loaded('json');
$writableOk = is_writable(__DIR__);
$allSystemOk = ($phpOk && $zipOk && $pdoOk && $jsonOk && $writableOk);

$foundZips = glob(__DIR__ . '/*.zip');
$packageExists = !empty($foundZips) || file_exists(__DIR__ . '/migrator_package.zip');
$firstZipName = !empty($foundZips) ? basename($foundZips[0]) : 'migrator_package.zip';
$firstZipSize = 'Ready';
if ($packageExists) {
    $zipPath = !empty($foundZips) ? $foundZips[0] : __DIR__ . '/migrator_package.zip';
    if (file_exists($zipPath)) {
        $firstZipSize = round(filesize($zipPath) / 1024 / 1024, 2) . ' MB';
    }
}

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
    <title>Website Migrator — Standalone Installer</title>
    <style>
        :root {
            --bg-base: #0a0b0d;
            --bg-surface: #111318;
            --bg-card: #181c24;
            --bg-card-hover: #1e2330;
            --bg-input: #0e1015;
            --bg-terminal: #080a0e;
            --border: #252a36;
            --border-hover: #3a4150;
            --border-accent: #2563eb;
            --accent: #3b82f6;
            --accent-bright: #60a5fa;
            --accent-glow: rgba(37, 99, 235, 0.25);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #475569;
            --text-mono: #7dd3fc;
            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.12);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.12);
            --error: #ef4444;
            --error-bg: rgba(239, 68, 68, 0.12);
            --info: #3b82f6;
            --info-bg: rgba(59, 130, 246, 0.12);
            --font-sans: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', system-ui, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', 'Cascadia Code', Consolas, monospace;
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 14px;
            --transition: 0.15s ease;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-base);
            background-image:
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(37, 99, 235, 0.12), transparent),
                radial-gradient(ellipse 60% 40% at 80% 100%, rgba(99, 102, 241, 0.08), transparent);
            background-attachment: fixed;
            color: var(--text-primary);
            font-family: var(--font-sans);
            -webkit-font-smoothing: antialiased;
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* Header */
        .header {
            background: rgba(17, 19, 24, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 60px;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .logo-icon {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 16px var(--accent-glow);
        }
        .logo-icon svg { width: 18px; height: 18px; color: #fff; }
        .logo-text {
            font-weight: 700;
            font-size: 16px;
            letter-spacing: -0.02em;
            color: #fff;
        }
        .logo-badge {
            font-size: 11px;
            font-family: var(--font-mono);
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(37, 99, 235, 0.3);
            padding: 2px 7px;
            border-radius: 9999px;
            font-weight: 600;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-secondary);
            background: var(--bg-surface);
            border: 1px solid var(--border);
            padding: 6px 14px;
            border-radius: 9999px;
        }
        .status-indicator .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 8px var(--success);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.85); }
        }

        /* Hero */
        .hero {
            text-align: center;
            padding: 44px 0 28px;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(37, 99, 235, 0.1);
            border: 1px solid rgba(37, 99, 235, 0.25);
            color: #93c5fd;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 16px;
        }
        .hero h1 {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #fff;
            margin-bottom: 8px;
        }
        .hero-subtitle {
            font-size: 15px;
            color: var(--text-secondary);
            max-width: 650px;
            margin: 0 auto 30px;
        }

        /* Flow Diagram */
        .flow {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin: 0 auto;
        }
        .flow-node {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .flow-node .dot { width: 8px; height: 8px; border-radius: 50%; }
        .flow-node.source .dot { background: #3b82f6; }
        .flow-node.package .dot { background: #8b5cf6; }
        .flow-node.target .dot { background: #10b981; }
        .flow-connector {
            display: flex;
            align-items: center;
            gap: 4px;
            color: var(--text-muted);
        }
        .flow-connector .line {
            width: 32px;
            height: 1px;
            background: var(--border);
        }
        .flow-connector svg { width: 14px; height: 14px; }

        /* Sections */
        .section {
            margin-bottom: 28px;
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            position: relative;
            flex-wrap: wrap;
        }
        .section-number {
            font-family: var(--font-mono);
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            background: rgba(37, 99, 235, 0.12);
            border: 1px solid rgba(37, 99, 235, 0.25);
            padding: 2px 8px;
            border-radius: 6px;
        }
        .section-title {
            font-size: 17px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.01em;
        }
        .section-subtitle {
            width: 100%;
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: -6px;
            margin-left: 44px;
        }
        .dimmed-badge-overlay {
            display: inline-flex;
            align-items: center;
            font-size: 11px;
            font-weight: 600;
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.25);
            padding: 2px 8px;
            border-radius: 6px;
            margin-left: auto;
        }

        /* Diagnostic Grid */
        .diag-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .diag-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            transition: var(--transition);
        }
        .diag-card:hover {
            border-color: var(--border-hover);
            background: var(--bg-card-hover);
        }
        .diag-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .diag-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .diag-icon.ready {
            background: var(--success-bg);
            color: var(--success);
        }
        .diag-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        .diag-icon.error {
            background: var(--error-bg);
            color: var(--error);
        }
        .diag-icon svg { width: 16px; height: 16px; }
        .diag-name {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .diag-desc {
            font-size: 11px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .status-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 7px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            flex-shrink: 0;
        }
        .status-badge.ready {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.25);
        }
        .status-badge.warning {
            background: var(--warning-bg);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.25);
        }
        .status-badge.error {
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.25);
        }

        /* Package Banner */
        .pkg-banner {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .pkg-banner.detected {
            border-color: rgba(34, 197, 94, 0.35);
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.05) 0%, var(--bg-card) 100%);
        }
        .pkg-banner.missing {
            border-color: rgba(245, 158, 11, 0.35);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, var(--bg-card) 100%);
        }
        .pkg-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .pkg-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pkg-icon.success {
            background: var(--success-bg);
            color: var(--success);
        }
        .pkg-icon.warning {
            background: var(--warning-bg);
            color: var(--warning);
        }
        .pkg-icon svg { width: 20px; height: 20px; }
        .pkg-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            font-family: var(--font-mono);
        }
        .pkg-meta {
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* Scope Selector Grid */
        .scope-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }
        .scope-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 18px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .scope-card:hover {
            border-color: var(--border-hover);
            background: var(--bg-card-hover);
            transform: translateY(-2px);
        }
        .scope-card.active {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, var(--bg-card) 100%);
            box-shadow: 0 0 20px var(--accent-glow);
        }
        .scope-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .scope-icon {
            font-size: 22px;
        }
        .scope-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 2px 7px;
            border-radius: 9999px;
            background: rgba(37, 99, 235, 0.15);
            color: #60a5fa;
            border: 1px solid rgba(37, 99, 235, 0.3);
        }
        .scope-badge.info {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border-color: rgba(59, 130, 246, 0.3);
        }
        .scope-badge.warning {
            background: rgba(245, 158, 11, 0.15);
            color: #fcd34d;
            border-color: rgba(245, 158, 11, 0.3);
        }
        .scope-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
        }
        .scope-desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* Card & Forms */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            transition: opacity 0.2s ease;
        }
        .card.is-dimmed {
            opacity: 0.35;
            pointer-events: none;
            filter: grayscale(0.6);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .form-control {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 9px 12px;
            font-size: 13px;
            color: var(--text-primary);
            font-family: inherit;
            transition: var(--transition);
            width: 100%;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        .input-with-action {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-with-action input {
            padding-right: 38px;
        }
        .btn-toggle-eye {
            position: absolute;
            right: 8px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        .btn-toggle-eye:hover {
            color: var(--text-primary);
        }
        .btn-toggle-eye svg { width: 16px; height: 16px; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            border-radius: var(--radius-sm);
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            border-color: #3b82f6;
            box-shadow: 0 4px 14px var(--accent-glow);
        }
        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .btn-secondary {
            background: var(--bg-surface);
            border-color: var(--border);
            color: var(--text-primary);
        }
        .btn-secondary:hover:not(:disabled) {
            background: var(--bg-card-hover);
            border-color: var(--border-hover);
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
        }
        .btn-success:hover:not(:disabled) {
            transform: translateY(-1px);
        }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.25);
        }
        .btn-danger:hover:not(:disabled) {
            transform: translateY(-1px);
        }
        .btn-lg {
            padding: 14px 28px;
            font-size: 15px;
            border-radius: var(--radius);
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* URL Preview Box */
        .url-preview-box {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: var(--font-mono);
            font-size: 12px;
            margin-top: 12px;
            overflow-x: auto;
        }
        .url-from { color: #f87171; word-break: break-all; }
        .url-arrow { color: var(--text-muted); flex-shrink: 0; }
        .url-to { color: #34d399; word-break: break-all; }
        .url-tip {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        /* Pipeline Steps */
        .pipeline-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin-top: 24px;
        }
        .pipeline-steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .step-item {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .step-item.active {
            border-color: var(--accent);
            color: #fff;
            background: rgba(37, 99, 235, 0.1);
        }
        .step-item.completed {
            border-color: var(--success);
            color: var(--success);
            background: var(--success-bg);
        }
        .step-num {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .step-item.active .step-num {
            background: var(--accent);
            color: #fff;
        }
        .step-item.completed .step-num {
            background: var(--success);
            color: #000;
        }
        .step-text {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Progress bar */
        .progress-bar-bg {
            width: 100%;
            height: 8px;
            background: var(--bg-input);
            border-radius: 9999px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #2563eb, #06b6d4, #10b981);
            border-radius: 9999px;
            transition: width 0.3s ease;
            box-shadow: 0 0 12px var(--accent-glow);
        }
        .progress-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        /* Live Terminal Console */
        .terminal-card {
            background: var(--bg-terminal);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-top: 24px;
        }
        .terminal-header {
            background: #0e1118;
            border-bottom: 1px solid var(--border);
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .terminal-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mac-dots {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .mac-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .mac-dot.red { background: #ef4444; }
        .mac-dot.yellow { background: #f59e0b; }
        .mac-dot.green { background: #10b981; }
        .terminal-title {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text-secondary);
        }
        .terminal-status-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--success);
            background: var(--success-bg);
            border: 1px solid rgba(34, 197, 94, 0.25);
            padding: 2px 8px;
            border-radius: 9999px;
            font-family: var(--font-mono);
        }
        .terminal-status-pill .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 6px var(--success);
            animation: pulse 1.8s infinite;
        }
        .terminal-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-term {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-family: var(--font-mono);
            transition: var(--transition);
        }
        .btn-term:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .terminal-body {
            padding: 14px 16px;
            font-family: var(--font-mono);
            font-size: 12px;
            height: 230px;
            overflow-y: auto;
            color: #cbd5e1;
            line-height: 1.7;
        }
        .log-line {
            display: flex;
            align-items: baseline;
            gap: 8px;
            word-break: break-all;
            margin-bottom: 2px;
        }
        .log-time {
            color: var(--text-muted);
            font-size: 11px;
            flex-shrink: 0;
        }
        .log-tag {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        .log-tag.info { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .log-tag.success { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .log-tag.warn { background: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .log-tag.error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .log-msg.info { color: #e2e8f0; }
        .log-msg.success { color: #86efac; }
        .log-msg.warn { color: #fef08a; }
        .log-msg.error { color: #fca5a5; }

        /* Finish Screen */
        .finish-card {
            display: none;
            background: var(--bg-card);
            border: 1px solid rgba(34, 197, 94, 0.4);
            border-radius: var(--radius-lg);
            padding: 36px 32px;
            margin-top: 28px;
            text-align: center;
            box-shadow: 0 0 30px rgba(34, 197, 94, 0.15);
        }
        .finish-icon-wrap {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--success-bg);
            border: 2px solid var(--success);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 0 24px rgba(34, 197, 94, 0.35);
        }
        .finish-icon-wrap svg { width: 30px; height: 30px; color: var(--success); }
        .finish-card h2 {
            font-size: 22px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 8px;
        }
        .finish-card p {
            color: var(--text-secondary);
            font-size: 13px;
            max-width: 580px;
            margin: 0 auto 24px;
        }
        .finish-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            max-width: 620px;
            margin: 0 auto 24px;
        }
        .finish-stat-box {
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px;
        }
        .finish-stat-val {
            font-family: var(--font-mono);
            font-size: 18px;
            font-weight: 700;
            color: var(--success);
        }
        .finish-stat-lbl {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 2px;
        }
        .finish-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Toast Container */
        #toastContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast {
            pointer-events: auto;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 12px 18px;
            font-size: 13px;
            color: #fff;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.25s ease;
            max-width: 380px;
        }
        .toast.success { border-color: var(--success); }
        .toast.error { border-color: var(--error); }
        .toast.info { border-color: var(--accent); }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 32px 0;
            color: var(--text-muted);
            font-size: 12px;
            border-top: 1px solid var(--border);
            margin-top: 48px;
        }
        .footer a {
            color: #818cf8;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .pipeline-steps { grid-template-columns: repeat(2, 1fr); }
            .hero h1 { font-size: 26px; }
            .flow { flex-wrap: wrap; }
        }

        /* Web Dropzone */
        .dropzone-box {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            background: rgba(14, 16, 21, 0.6);
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 14px;
        }
        .dropzone-box:hover, .dropzone-box.dragover {
            border-color: var(--accent);
            background: rgba(37, 99, 235, 0.08);
            box-shadow: 0 0 20px var(--accent-glow);
        }
        .dropzone-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.15);
            color: var(--accent-bright);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }
        .dropzone-icon svg { width: 22px; height: 22px; }
        .dropzone-title { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 4px; }
        .dropzone-desc { font-size: 12px; color: var(--text-secondary); }
        .dropzone-progress-wrap {
            margin-top: 14px;
            display: none;
            text-align: left;
        }

        /* Post Migration Checklist */
        .post-checklist {
            background: rgba(37, 99, 235, 0.05);
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: var(--radius);
            padding: 20px 24px;
            margin: 0 auto 24px;
            max-width: 620px;
            text-align: left;
        }
        .post-checklist-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .post-checklist-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .post-checklist-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .post-checklist-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .post-checklist-num.c1 { background: rgba(34, 197, 94, 0.2); color: var(--success); }
        .post-checklist-num.c2 { background: rgba(59, 130, 246, 0.2); color: var(--accent-bright); }
        .post-checklist-num.c3 { background: rgba(239, 68, 68, 0.2); color: var(--error); }
        .post-checklist-body strong { color: var(--text-primary); font-size: 13px; }
        .post-checklist-body div { color: var(--text-secondary); font-size: 12px; margin-top: 2px; }

        /* Security Lock Overlay */
        .lock-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(10, 11, 13, 0.94);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .lock-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 420px;
            padding: 32px 28px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.7);
            text-align: center;
        }
        .lock-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: var(--error);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .lock-icon-wrap svg { width: 28px; height: 28px; }

        /* Modal Backdrop & Dialog */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
            z-index: 9998;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 440px;
            padding: 28px 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .modal-title {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 20px;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        .modal-close-btn:hover { color: #fff; }
    </style>
</head>
<body>

<div id="toastContainer"></div>

<?php if (!isInstallerAuthenticated()): ?>
<!-- FULLSCREEN LOCK OVERLAY -->
<div class="lock-overlay" id="lockScreenOverlay">
    <div class="lock-card">
        <div class="lock-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 style="font-size: 20px; font-weight: 700; color: #fff; margin-bottom: 6px;">Website Migrator Terkunci</h2>
        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 20px;">
            Installer ini dilindungi oleh password keamanan. Masukkan password untuk membuka akses.
        </p>

        <form id="formUnlockInstaller">
            <div class="form-group" style="text-align: left; margin-bottom: 16px;">
                <label for="inputUnlockPass">Password Installer</label>
                <div class="input-with-action">
                    <input type="password" id="inputUnlockPass" class="form-control" placeholder="Masukkan password..." required autofocus>
                    <button type="button" class="btn-toggle-eye" id="btnToggleUnlockPass" title="Lihat password">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" id="btnSubmitUnlock" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px;">
                🔓 Buka Kunci Akses (Unlock)
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- SET / CHANGE PASSWORD MODAL -->
<div class="modal-overlay" id="modalSetPassword">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">
                <span>🛡️ Proteksi Password Installer</span>
            </div>
            <button type="button" class="modal-close-btn" id="btnCloseSetPasswordModal">&times;</button>
        </div>
        <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px;">
            Lindungi skrip <code>installer.php</code> ini dengan password agar tidak dapat diakses atau dijalankan oleh pihak lain yang tidak berkepentingan di hosting Anda.
        </p>
        <form id="formSetPassword">
            <div class="form-group" style="margin-bottom: 16px;">
                <label for="inputSetPasswordVal">Password Baru (Minimal 4 Karakter)</label>
                <input type="password" id="inputSetPasswordVal" class="form-control" placeholder="Tentukan password proteksi..." required minlength="4">
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnCancelSetPassword">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Top Sticky Header -->
<header class="header">
    <div class="container">
        <div class="header-inner">
            <div class="logo">
                <div class="logo-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </div>
                <span class="logo-text">Website Migrator</span>
                <span class="logo-badge">v<?php echo INSTALLER_VERSION; ?></span>
            </div>

            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnOpenSetPasswordModal" style="padding: 6px 12px; font-size: 12px;">
                    <?php if (getSecurityLockData() !== null): ?>
                        🔒 <span>Kunci Aktif (Ubah)</span>
                    <?php else: ?>
                        🛡️ <span>Kunci Password (Setup)</span>
                    <?php endif; ?>
                </button>
                <div class="status-indicator">
                    <div class="dot"></div>
                    <span>1-File Deploy Ready</span>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="container">
    <!-- Hero Section with Flow Diagram -->
    <section class="hero">
        <div class="hero-badge">⚡ Standalone Deployer • Zero Dependencies • 1-File Run</div>
        <h1>Standalone Website Installer</h1>
        <p class="hero-subtitle">Ekstraksi berkas, restorasi skema database MySQL, dan penyesuaian domain otomatis langsung di hosting tujuan.</p>

        <div class="flow">
            <div class="flow-node source">
                <div class="dot"></div>
                <div class="label">SOURCE</div>
            </div>
            <div class="flow-connector">
                <div class="line"></div>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                </svg>
            </div>
            <div class="flow-node package">
                <div class="dot"></div>
                <div class="label">PACKAGE (.ZIP)</div>
            </div>
            <div class="flow-connector">
                <div class="line"></div>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                </svg>
            </div>
            <div class="flow-node target">
                <div class="dot"></div>
                <div class="label">TARGET HOSTING</div>
            </div>
        </div>
    </section>

    <!-- SECTION 01: Host Diagnostics & Package Detected -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">01</span>
            <span class="section-title">Pemeriksaan Lingkungan Hosting &amp; Berkas</span>
            <div class="section-subtitle">Diagnostik kelayakan server hosting tujuan dan status berkas paket migrasi.</div>
        </div>

        <div class="diag-grid">
            <div class="diag-card">
                <div class="diag-left">
                    <div class="diag-icon <?php echo $phpOk ? 'ready' : 'error'; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="diag-name">PHP Engine</div>
                        <div class="diag-desc"><?php echo PHP_VERSION; ?></div>
                    </div>
                </div>
                <span class="status-badge <?php echo $phpOk ? 'ready' : 'error'; ?>">
                    <?php echo $phpOk ? 'Ready' : 'PHP < 7.4'; ?>
                </span>
            </div>

            <div class="diag-card">
                <div class="diag-left">
                    <div class="diag-icon <?php echo $zipOk ? 'ready' : 'error'; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="diag-name">ZipArchive</div>
                        <div class="diag-desc"><?php echo $zipOk ? 'Ekstensi Aktif' : 'Tidak Aktif'; ?></div>
                    </div>
                </div>
                <span class="status-badge <?php echo $zipOk ? 'ready' : 'error'; ?>">
                    <?php echo $zipOk ? 'Ready' : 'Disabled'; ?>
                </span>
            </div>

            <div class="diag-card">
                <div class="diag-left">
                    <div class="diag-icon <?php echo $pdoOk ? 'ready' : 'error'; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="diag-name">PDO MySQL</div>
                        <div class="diag-desc"><?php echo $pdoOk ? 'Driver Aktif' : 'Tidak Aktif'; ?></div>
                    </div>
                </div>
                <span class="status-badge <?php echo $pdoOk ? 'ready' : 'error'; ?>">
                    <?php echo $pdoOk ? 'Ready' : 'Disabled'; ?>
                </span>
            </div>

            <div class="diag-card">
                <div class="diag-left">
                    <div class="diag-icon <?php echo $jsonOk ? 'ready' : 'error'; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="diag-name">JSON Support</div>
                        <div class="diag-desc"><?php echo $jsonOk ? 'Tersedia' : 'Non-aktif'; ?></div>
                    </div>
                </div>
                <span class="status-badge <?php echo $jsonOk ? 'ready' : 'error'; ?>">
                    <?php echo $jsonOk ? 'Ready' : 'Disabled'; ?>
                </span>
            </div>

            <div class="diag-card">
                <div class="diag-left">
                    <div class="diag-icon <?php echo $writableOk ? 'ready' : 'warning'; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="diag-name">Folder Izin Tulis</div>
                        <div class="diag-desc"><?php echo $writableOk ? 'Writable' : 'Read-only'; ?></div>
                    </div>
                </div>
                <span class="status-badge <?php echo $writableOk ? 'ready' : 'warning'; ?>">
                    <?php echo $writableOk ? 'Ready' : 'Read-only'; ?>
                </span>
            </div>
        </div>

        <!-- Package Detected Banner -->
        <div id="packageDetectedBanner" class="pkg-banner detected" style="<?php echo $packageExists ? 'display:flex;' : 'display:none;'; ?>">
            <div class="pkg-info">
                <div class="pkg-icon success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div>
                    <div class="pkg-title" id="detectedZipName"><?php echo htmlspecialchars($firstZipName); ?></div>
                    <div class="pkg-meta"><span id="detectedZipSize"><?php echo $firstZipSize; ?></span> &bull; Berkas arsip ditemukan di server hosting &amp; siap dipulihkan</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnToggleUploadDropzone" style="font-size:12px;padding:6px 12px;">🔄 Ganti / Unggah ZIP</button>
                <span class="status-badge ready">✓ Package Ready</span>
            </div>
        </div>

        <div id="packageMissingBanner" class="pkg-banner missing" style="<?php echo !$packageExists ? 'display:flex;' : 'display:none;'; ?>">
            <div class="pkg-info">
                <div class="pkg-icon warning">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div>
                    <div class="pkg-title" style="color: #f59e0b;">Arsip ZIP Belum Terdeteksi</div>
                    <div class="pkg-meta">Unggah berkas ZIP website Anda melalui Web Dropzone di bawah ini atau via FTP / cPanel File Manager.</div>
                </div>
            </div>
            <span class="status-badge warning">Upload Needed</span>
        </div>

        <!-- Web Dropzone Area -->
        <div id="packageDropzoneArea" style="<?php echo !$packageExists ? 'display:block;' : 'display:none;'; ?>">
            <div class="dropzone-box" id="packageDropzone">
                <div class="dropzone-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </div>
                <div class="dropzone-title">Upload Paket ZIP Langsung dari Browser</div>
                <div class="dropzone-desc">Tarik dan lepas berkas <code>.zip</code> ke area ini, atau klik untuk memilih file dari komputer Anda (tanpa perlu buka File Manager hosting).</div>
                <input type="file" id="packageFileInput" accept=".zip" style="display:none;">
            </div>

            <div class="dropzone-progress-wrap" id="uploadProgressWrap">
                <div class="progress-bar-bg" style="height: 6px;">
                    <div id="uploadProgressBarFill" class="progress-bar-fill" style="width: 0%;"></div>
                </div>
                <div class="progress-meta" style="margin-top: 6px;">
                    <span id="uploadStatusText">Mengunggah paket ZIP...</span>
                    <span id="uploadPercentText" style="font-family: var(--font-mono); font-weight: 700; color: #fff;">0%</span>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 02: Pilihan Paket Pemulihan (Scope) -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">02</span>
            <span class="section-title">Pilihan Paket Pemulihan (Scope)</span>
            <div class="section-subtitle">Tentukan cakupan pemulihan: seluruh website beserta database atau hanya salah satu komponen.</div>
        </div>

        <div class="scope-grid">
            <label class="scope-card active" id="scopeCardFull">
                <input type="radio" name="installerScope" value="full" checked style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📦</span>
                    <span class="scope-badge">Rekomendasi</span>
                </div>
                <div class="scope-title">Restore Lengkap (File + DB + URL)</div>
                <div class="scope-desc">Ekstrak seluruh berkas website, impor skema database MySQL, sesuaikan domain URL, dan perbarui file konfigurasi.</div>
            </label>

            <label class="scope-card" id="scopeCardFilesOnly">
                <input type="radio" name="installerScope" value="files_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📁</span>
                    <span class="scope-badge info">Tanpa Database</span>
                </div>
                <div class="scope-title">Hanya Berkas Website (Tanpa DB)</div>
                <div class="scope-desc">Hanya mengekstrak seluruh berkas website ke direktori hosting ini. Cocok jika database diimpor manual via phpMyAdmin.</div>
            </label>

            <label class="scope-card" id="scopeCardDbOnly">
                <input type="radio" name="installerScope" value="db_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">🗄️</span>
                    <span class="scope-badge warning">SQL Saja</span>
                </div>
                <div class="scope-title">Hanya Database (.SQL Saja)</div>
                <div class="scope-desc">Hanya merestore dump database ke server MySQL baru dan menjalankan search &amp; replace domain URL tanpa ekstrak file.</div>
            </label>
        </div>
    </div>

    <!-- SECTION 03: Konfigurasi Database MySQL Baru -->
    <div class="section" id="sectionDatabase">
        <div class="section-header">
            <span class="section-number">03</span>
            <span class="section-title">Konfigurasi Database MySQL Baru</span>
            <span class="dimmed-badge-overlay" id="dbDimmedBadge" style="display:none;">Dilewati (Mode Hanya Berkas)</span>
            <div class="section-subtitle">Masukkan kredensial database yang telah dibuat di cPanel / server hosting baru Anda.</div>
        </div>

        <div class="card" id="cardDatabase">
            <div class="form-grid">
                <div class="form-group">
                    <label for="dbHost">Database Host</label>
                    <input type="text" id="dbHost" class="form-control" value="localhost" placeholder="localhost atau 127.0.0.1">
                </div>
                <div class="form-group">
                    <label for="dbPort">Port MySQL</label>
                    <input type="number" id="dbPort" class="form-control" value="3306" placeholder="3306">
                </div>
                <div class="form-group">
                    <label for="dbName">Nama Database Baru</label>
                    <input type="text" id="dbName" class="form-control" placeholder="contoh: user_namadb">
                </div>
                <div class="form-group">
                    <label for="dbUser">Username DB Baru</label>
                    <input type="text" id="dbUser" class="form-control" placeholder="contoh: user_dbuser">
                </div>
                <div class="form-group">
                    <label for="dbPass">Password DB Baru</label>
                    <div class="input-with-action">
                        <input type="password" id="dbPass" class="form-control" placeholder="(jika menggunakan password)">
                        <button type="button" class="btn-toggle-eye" id="btnToggleDbPass" title="Lihat password">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <button type="button" id="btnTestDb" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Uji Koneksi DB Baru
            </button>
        </div>
    </div>

    <!-- SECTION 04: Penyesuaian Domain / URL -->
    <div class="section" id="sectionUrl">
        <div class="section-header">
            <span class="section-number">04</span>
            <span class="section-title">Penyesuaian Domain / URL (Search &amp; Replace)</span>
            <span class="dimmed-badge-overlay" id="urlDimmedBadge" style="display:none;">Dilewati (Mode Hanya Berkas)</span>
            <div class="section-subtitle">Otomatis mengganti string URL lama ke URL hosting baru, termasuk deserialisasi data WordPress.</div>
        </div>

        <div class="card" id="cardUrl">
            <div class="form-grid">
                <div class="form-group">
                    <label for="oldUrl">Domain / URL Lama (Asal)</label>
                    <input type="text" id="oldUrl" class="form-control" placeholder="https://domain-lama.com">
                </div>
                <div class="form-group">
                    <label for="newUrl">Domain / URL Baru (Tujuan)</label>
                    <input type="text" id="newUrl" class="form-control" value="<?php echo htmlspecialchars($currentSiteUrl); ?>" placeholder="https://domain-baru.com">
                </div>
            </div>

            <!-- Live URL Preview -->
            <div class="url-preview-box">
                <span style="color: var(--text-muted);">Preview URL:</span>
                <span class="url-from" id="previewOldUrl">https://domain-lama.com</span>
                <span class="url-arrow">➔</span>
                <span class="url-to" id="previewNewUrl"><?php echo htmlspecialchars($currentSiteUrl); ?></span>
            </div>
            <div class="url-tip">
                ℹ️ Sistem otomatis menghitung ulang panjang byte serialized PHP (WordPress options, Elementor, dsb) secara aman tanpa merusak struktur database.
            </div>
        </div>
    </div>

    <!-- ACTION BUTTON -->
    <div style="margin: 32px 0 20px; text-align: center;">
        <button type="button" id="btnStartInstall" class="btn btn-primary btn-lg" style="width: 100%; max-width: 480px;" <?php echo !$packageExists ? 'disabled' : ''; ?>>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            Mulai Pemulihan Website (Start Restore)
        </button>
    </div>

    <!-- PIPELINE & PROGRESS -->
    <div class="pipeline-card" id="pipelineCard" style="display: none;">
        <div class="pipeline-steps">
            <div class="step-item" id="stepFiles">
                <span class="step-num">1</span>
                <span class="step-text">Ekstraksi Berkas</span>
            </div>
            <div class="step-item" id="stepDb">
                <span class="step-num">2</span>
                <span class="step-text">Impor Database</span>
            </div>
            <div class="step-item" id="stepUrl">
                <span class="step-num">3</span>
                <span class="step-text">Search &amp; Replace</span>
            </div>
            <div class="step-item" id="stepFinish">
                <span class="step-num">4</span>
                <span class="step-text">Selesai</span>
            </div>
        </div>

        <div class="progress-bar-bg">
            <div id="progressBar" class="progress-bar-fill"></div>
        </div>
        <div class="progress-meta">
            <span id="progressStatus">Menyiapkan tahapan instalasi...</span>
            <span id="progressText" style="font-family: var(--font-mono); font-weight: 700; color: #fff;">0%</span>
        </div>
    </div>

    <!-- LIVE TERMINAL CONSOLE -->
    <div class="terminal-card">
        <div class="terminal-header">
            <div class="terminal-left">
                <div class="mac-dots">
                    <span class="mac-dot red"></span>
                    <span class="mac-dot yellow"></span>
                    <span class="mac-dot green"></span>
                </div>
                <span class="terminal-title">Terminal Log Pemulihan (Live Console)</span>
                <div class="terminal-status-pill">
                    <div class="dot"></div>
                    <span>LIVE CONSOLE</span>
                </div>
            </div>
            <div class="terminal-actions">
                <button type="button" class="btn-term" id="btnCopyLogs" title="Salin seluruh log terminal">Copy Logs</button>
                <button type="button" class="btn-term" id="btnClearLogs" title="Bersihkan log">Clear</button>
            </div>
        </div>
        <div id="terminalContent" class="terminal-body">
            <div class="log-line">
                <span class="log-time">[<?php echo date('H:i:s'); ?>]</span>
                <span class="log-tag info">SYSTEM</span>
                <span class="log-msg info">Website Migrator Standalone Installer v<?php echo INSTALLER_VERSION; ?> diinisialisasi.</span>
            </div>
            <div class="log-line">
                <span class="log-time">[<?php echo date('H:i:s'); ?>]</span>
                <span class="log-tag info">HOST</span>
                <span class="log-msg info">Direktori target: <?php echo htmlspecialchars(str_replace('\\', '/', __DIR__)); ?></span>
            </div>
            <?php if ($packageExists): ?>
                <div class="log-line">
                    <span class="log-time">[<?php echo date('H:i:s'); ?>]</span>
                    <span class="log-tag success">PACKAGE</span>
                    <span class="log-msg success">Paket terdeteksi: <?php echo htmlspecialchars($firstZipName); ?> (<?php echo $firstZipSize; ?>). Siap dipulihkan.</span>
                </div>
            <?php else: ?>
                <div class="log-line">
                    <span class="log-time">[<?php echo date('H:i:s'); ?>]</span>
                    <span class="log-tag warn">WARNING</span>
                    <span class="log-msg warn">Berkas ZIP website belum terdeteksi. Silakan unggah berkas .zip ke folder ini.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FINAL FINISH CARD -->
    <div id="finishCard" class="finish-card">
        <div class="finish-icon-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <h2>Pemulihan Website Selesai 100%!</h2>
        <p>Seluruh berkas website dan database telah berhasil dipulihkan secara sempurna ke hosting baru ini.</p>

        <div class="finish-stats">
            <div class="finish-stat-box">
                <div class="finish-stat-val" id="statFilesCount">0</div>
                <div class="finish-stat-lbl">Berkas Diekstrak</div>
            </div>
            <div class="finish-stat-box">
                <div class="finish-stat-val" id="statQueriesCount">100%</div>
                <div class="finish-stat-lbl">Restorasi Database</div>
            </div>
            <div class="finish-stat-box">
                <div class="finish-stat-val" id="statUrlReplaced">0</div>
                <div class="finish-stat-lbl">Baris URL Disesuaikan</div>
            </div>
        </div>

        <!-- POST-MIGRATION CHECKLIST -->
        <div class="post-checklist">
            <div class="post-checklist-title">
                <span>📋 Panduan Pasca-Migrasi (Langkah Selanjutnya)</span>
            </div>
            <div class="post-checklist-list">
                <div class="post-checklist-item">
                    <span class="post-checklist-num c1">1</span>
                    <div class="post-checklist-body">
                        <strong>Uji Tampilan &amp; Navigasi Halaman</strong>
                        <div>Buka tautan website baru Anda di tab baru. Pastikan homepage, gambar, styling, dan navigasi menu termuat normal.</div>
                    </div>
                </div>
                <div class="post-checklist-item">
                    <span class="post-checklist-num c2">2</span>
                    <div class="post-checklist-body">
                        <strong>Simpan Ulang Permalink (Khusus WordPress)</strong>
                        <div>Masuk ke Dashboard Admin WordPress &rarr; <em>Settings &rarr; Permalinks</em>, lalu klik tombol <strong>Save Changes</strong> sekali untuk memastikan URL artikel tidak 404.</div>
                    </div>
                </div>
                <div class="post-checklist-item">
                    <span class="post-checklist-num c3">3</span>
                    <div class="post-checklist-body">
                        <strong>Hapus Berkas Migrator (Self-Destruct)</strong>
                        <div>Klik tombol merah di bawah untuk menghapus seluruh berkas <code>installer.php</code>, kunci proteksi, dan paket ZIP agar hosting Anda aman dan bersih.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="finish-actions">
            <a href="./" class="btn btn-success btn-lg" target="_blank">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                Buka Website Baru
            </a>
            <button type="button" id="btnSelfDestruct" class="btn btn-danger btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Hapus Berkas Migrator (Self-Destruct)
            </button>
        </div>
        <p style="font-size: 11px; color: var(--text-muted); margin-top: 16px;">
            * Fitur Self-Destruct akan menghapus berkas <code>installer.php</code> dan arsip <code>.zip</code> agar aman dari akses pihak lain.
        </p>
    </div>

    <!-- FOOTER -->
    <footer class="footer">
        <p>Website Migrator &bull; Standalone 1-Click Site Cloner &bull; <a href="https://github.com/kazuhamoe" target="_blank">@kazuhamoe</a></p>
    </footer>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Elements
    const scopeCards = document.querySelectorAll('.scope-card');
    const sectionDb = document.getElementById('sectionDatabase');
    const cardDb = document.getElementById('cardDatabase');
    const dbDimmedBadge = document.getElementById('dbDimmedBadge');
    const sectionUrl = document.getElementById('sectionUrl');
    const cardUrl = document.getElementById('cardUrl');
    const urlDimmedBadge = document.getElementById('urlDimmedBadge');
    const btnTestDb = document.getElementById('btnTestDb');
    const btnStartInstall = document.getElementById('btnStartInstall');
    const btnSelfDestruct = document.getElementById('btnSelfDestruct');
    const btnToggleDbPass = document.getElementById('btnToggleDbPass');
    const dbPassInput = document.getElementById('dbPass');
    const oldUrlInput = document.getElementById('oldUrl');
    const newUrlInput = document.getElementById('newUrl');
    const previewOldUrl = document.getElementById('previewOldUrl');
    const previewNewUrl = document.getElementById('previewNewUrl');
    const pipelineCard = document.getElementById('pipelineCard');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressStatus = document.getElementById('progressStatus');
    const terminal = document.getElementById('terminalContent');
    const btnCopyLogs = document.getElementById('btnCopyLogs');
    const btnClearLogs = document.getElementById('btnClearLogs');
    const finishCard = document.getElementById('finishCard');
    const toastContainer = document.getElementById('toastContainer');

    // DOM Elements - Security & Setup
    const lockScreenOverlay = document.getElementById('lockScreenOverlay');
    const formUnlockInstaller = document.getElementById('formUnlockInstaller');
    const inputUnlockPass = document.getElementById('inputUnlockPass');
    const btnSubmitUnlock = document.getElementById('btnSubmitUnlock');
    const btnToggleUnlockPass = document.getElementById('btnToggleUnlockPass');
    const btnOpenSetPasswordModal = document.getElementById('btnOpenSetPasswordModal');
    const modalSetPassword = document.getElementById('modalSetPassword');
    const formSetPassword = document.getElementById('formSetPassword');
    const inputSetPasswordVal = document.getElementById('inputSetPasswordVal');
    const btnCloseSetPasswordModal = document.getElementById('btnCloseSetPasswordModal');
    const btnCancelSetPassword = document.getElementById('btnCancelSetPassword');

    // DOM Elements - Dropzone Upload & Package
    const packageDetectedBanner = document.getElementById('packageDetectedBanner');
    const packageMissingBanner = document.getElementById('packageMissingBanner');
    const detectedZipName = document.getElementById('detectedZipName');
    const detectedZipSize = document.getElementById('detectedZipSize');
    const btnToggleUploadDropzone = document.getElementById('btnToggleUploadDropzone');
    const packageDropzoneArea = document.getElementById('packageDropzoneArea');
    const packageDropzone = document.getElementById('packageDropzone');
    const packageFileInput = document.getElementById('packageFileInput');
    const uploadProgressWrap = document.getElementById('uploadProgressWrap');
    const uploadProgressBarFill = document.getElementById('uploadProgressBarFill');
    const uploadStatusText = document.getElementById('uploadStatusText');
    const uploadPercentText = document.getElementById('uploadPercentText');

    // Stats
    const statFilesCount = document.getElementById('statFilesCount');
    const statQueriesCount = document.getElementById('statQueriesCount');
    const statUrlReplaced = document.getElementById('statUrlReplaced');

    // Step items
    const stepFiles = document.getElementById('stepFiles');
    const stepDb = document.getElementById('stepDb');
    const stepUrl = document.getElementById('stepUrl');
    const stepFinish = document.getElementById('stepFinish');

    let currentScope = 'full';
    let totalExtractedFiles = 0;
    let totalReplacedRows = 0;

    // Toast function
    function showToast(msg, type = 'info') {
        const t = document.createElement('div');
        t.className = `toast ${type}`;
        t.innerText = msg;
        toastContainer.appendChild(t);
        setTimeout(() => {
            t.style.opacity = '0';
            t.style.transform = 'translateX(100%)';
            t.style.transition = 'all 0.3s ease';
            setTimeout(() => t.remove(), 300);
        }, 4000);
    }

    // Logger
    function log(msg, type = 'info', tag = 'INFO') {
        const line = document.createElement('div');
        line.className = 'log-line';
        const now = new Date().toTimeString().split(' ')[0];
        line.innerHTML = `
            <span class="log-time">[${now}]</span>
            <span class="log-tag ${type}">${tag}</span>
            <span class="log-msg ${type}">${msg}</span>
        `;
        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;
    }

    function setProgress(pct, status) {
        if (progressBar) progressBar.style.width = pct + '%';
        if (progressText) progressText.innerText = pct + '%';
        if (progressStatus && status) progressStatus.innerText = status;
    }

    // --- SECURITY LOCK & SETUP ---
    if (formUnlockInstaller) {
        formUnlockInstaller.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pass = inputUnlockPass.value.trim();
            if (!pass) return;

            btnSubmitUnlock.disabled = true;
            btnSubmitUnlock.innerText = '⏳ Memverifikasi...';

            try {
                const fd = new FormData();
                fd.append('action', 'verify_security_pass');
                fd.append('password', pass);

                const res = await fetch('installer.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (json.success) {
                    showToast('🔓 ' + json.message, 'success');
                    lockScreenOverlay.style.opacity = '0';
                    lockScreenOverlay.style.transition = 'opacity 0.3s ease';
                    setTimeout(() => lockScreenOverlay.remove(), 300);
                } else {
                    showToast('❌ ' + json.message, 'error');
                }
            } catch (err) {
                showToast('❌ Gagal verifikasi: ' + err.message, 'error');
            } finally {
                btnSubmitUnlock.disabled = false;
                btnSubmitUnlock.innerText = '🔓 Buka Kunci Akses (Unlock)';
            }
        });
    }

    if (btnToggleUnlockPass && inputUnlockPass) {
        btnToggleUnlockPass.addEventListener('click', () => {
            const isPass = inputUnlockPass.type === 'password';
            inputUnlockPass.type = isPass ? 'text' : 'password';
        });
    }

    if (btnOpenSetPasswordModal) {
        btnOpenSetPasswordModal.addEventListener('click', () => {
            modalSetPassword.style.display = 'flex';
            inputSetPasswordVal.focus();
        });
    }
    const closeModal = () => { modalSetPassword.style.display = 'none'; };
    if (btnCloseSetPasswordModal) btnCloseSetPasswordModal.addEventListener('click', closeModal);
    if (btnCancelSetPassword) btnCancelSetPassword.addEventListener('click', closeModal);

    if (formSetPassword) {
        formSetPassword.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pass = inputSetPasswordVal.value.trim();
            if (pass.length < 4) {
                showToast('Password minimal 4 karakter!', 'error');
                return;
            }

            try {
                const fd = new FormData();
                fd.append('action', 'set_security_pass');
                fd.append('password', pass);

                const res = await fetch('installer.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success) {
                    showToast('🔒 ' + json.message, 'success');
                    closeModal();
                    btnOpenSetPasswordModal.innerHTML = '🔒 <span>Kunci Aktif (Ubah)</span>';
                    log('Password proteksi keamanan installer diaktifkan.', 'success', 'SECURITY');
                } else {
                    showToast('❌ ' + json.message, 'error');
                }
            } catch (err) {
                showToast('❌ Kesalahan: ' + err.message, 'error');
            }
        });
    }

    // --- DROPZONE WEB UPLOAD ---
    if (btnToggleUploadDropzone) {
        btnToggleUploadDropzone.addEventListener('click', () => {
            const isHidden = packageDropzoneArea.style.display === 'none';
            packageDropzoneArea.style.display = isHidden ? 'block' : 'none';
            btnToggleUploadDropzone.innerText = isHidden ? '✕ Batal Unggah' : '🔄 Unggah ZIP Baru';
        });
    }

    if (packageDropzone && packageFileInput) {
        packageDropzone.addEventListener('click', () => packageFileInput.click());

        packageDropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            packageDropzone.classList.add('dragover');
        });
        packageDropzone.addEventListener('dragleave', () => {
            packageDropzone.classList.remove('dragover');
        });
        packageDropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            packageDropzone.classList.remove('dragover');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handlePackageUpload(e.dataTransfer.files[0]);
            }
        });

        packageFileInput.addEventListener('change', () => {
            if (packageFileInput.files && packageFileInput.files.length > 0) {
                handlePackageUpload(packageFileInput.files[0]);
            }
        });
    }

    function handlePackageUpload(file) {
        if (!file.name.toLowerCase().endsWith('.zip')) {
            showToast('Hanya berkas format .zip yang diizinkan!', 'error');
            return;
        }

        uploadProgressWrap.style.display = 'block';
        uploadStatusText.innerText = `Mengunggah ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)...`;
        uploadPercentText.innerText = '0%';
        uploadProgressBarFill.style.width = '0%';

        const fd = new FormData();
        fd.append('action', 'upload_package');
        fd.append('package_file', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'installer.php', true);

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                uploadProgressBarFill.style.width = pct + '%';
                uploadPercentText.innerText = pct + '%';
            }
        };

        xhr.onload = () => {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.success) {
                    showToast('✅ ' + res.message, 'success');
                    uploadStatusText.innerText = 'Upload tuntas!';
                    uploadPercentText.innerText = '100%';

                    // Update Package Detected Banner
                    if (detectedZipName) detectedZipName.innerText = res.file_name;
                    if (detectedZipSize) detectedZipSize.innerText = res.formatted_size;
                    if (packageMissingBanner) packageMissingBanner.style.display = 'none';
                    packageDetectedBanner.style.display = 'flex';
                    packageDropzoneArea.style.display = 'none';
                    if (btnToggleUploadDropzone) btnToggleUploadDropzone.innerText = '🔄 Unggah ZIP Baru';

                    // Enable Start Button
                    btnStartInstall.disabled = false;
                    log(`Paket ZIP (${res.formatted_size}) berhasil diunggah langsung via browser.`, 'success', 'UPLOAD');
                } else {
                    showToast('❌ ' + res.message, 'error');
                    uploadStatusText.innerText = 'Upload gagal.';
                }
            } catch (err) {
                showToast('❌ Upload gagal: respon server tidak valid.', 'error');
            }
        };

        xhr.onerror = () => {
            showToast('❌ Terjadi kesalahan jaringan saat mengunggah.', 'error');
        };

        xhr.send(fd);
    }

    // --- SCOPE SELECTOR ---
    scopeCards.forEach(card => {
        card.addEventListener('click', () => {
            scopeCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');
            const radio = card.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            currentScope = radio ? radio.value : 'full';

            if (currentScope === 'files_only') {
                cardDb.classList.add('is-dimmed');
                cardUrl.classList.add('is-dimmed');
                dbDimmedBadge.style.display = 'inline-flex';
                urlDimmedBadge.style.display = 'inline-flex';
                log('Mode pemulihan diubah: Hanya Berkas Website (Database & URL dilewati).', 'info', 'SCOPE');
            } else {
                cardDb.classList.remove('is-dimmed');
                cardUrl.classList.remove('is-dimmed');
                dbDimmedBadge.style.display = 'none';
                urlDimmedBadge.style.display = 'none';
                if (currentScope === 'db_only') {
                    log('Mode pemulihan diubah: Hanya Database MySQL (.SQL Saja).', 'info', 'SCOPE');
                } else {
                    log('Mode pemulihan diubah: Restore Lengkap (Berkas + Database + URL).', 'info', 'SCOPE');
                }
            }
        });
    });

    // Password visibility toggle
    if (btnToggleDbPass && dbPassInput) {
        btnToggleDbPass.addEventListener('click', () => {
            const isPassword = dbPassInput.type === 'password';
            dbPassInput.type = isPassword ? 'text' : 'password';
            btnToggleDbPass.innerHTML = isPassword
                ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        });
    }

    // Live URL preview listener
    if (oldUrlInput && previewOldUrl) {
        oldUrlInput.addEventListener('input', () => {
            previewOldUrl.innerText = oldUrlInput.value.trim() || 'https://domain-lama.com';
        });
    }
    if (newUrlInput && previewNewUrl) {
        newUrlInput.addEventListener('input', () => {
            previewNewUrl.innerText = newUrlInput.value.trim() || 'https://domain-baru.com';
        });
    }

    // Copy & Clear terminal logs
    if (btnCopyLogs) {
        btnCopyLogs.addEventListener('click', () => {
            const text = terminal.innerText;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Log terminal berhasil disalin ke clipboard!', 'success');
            }).catch(() => {
                showToast('Gagal menyalin log.', 'error');
            });
        });
    }
    if (btnClearLogs) {
        btnClearLogs.addEventListener('click', () => {
            terminal.innerHTML = '';
            log('Log terminal dibersihkan.', 'info', 'CLEARED');
        });
    }

    // Uji Koneksi DB
    if (btnTestDb) {
        btnTestDb.addEventListener('click', async () => {
            const host = document.getElementById('dbHost').value.trim();
            const port = document.getElementById('dbPort').value.trim();
            const db   = document.getElementById('dbName').value.trim();
            const user = document.getElementById('dbUser').value.trim();
            const pass = document.getElementById('dbPass').value;

            if (!db) {
                showToast('Harap masukkan nama database terlebih dahulu.', 'error');
                return;
            }

            btnTestDb.disabled = true;
            btnTestDb.innerHTML = '⏳ Menguji Koneksi...';
            log(`Menguji koneksi ke database ${db}@${host}...`, 'info', 'DB TEST');

            try {
                const fd = new FormData();
                fd.append('action', 'test_db');
                fd.append('host', host);
                fd.append('port', port);
                fd.append('database', db);
                fd.append('username', user);
                fd.append('password', pass);

                const res = await fetch('installer.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success) {
                    log(json.message, 'success', 'DB SUCCESS');
                    showToast('✅ ' + json.message, 'success');
                } else {
                    log(json.message, 'error', 'DB ERROR');
                    showToast('❌ ' + json.message, 'error');
                }
            } catch (e) {
                log('Koneksi gagal: ' + e.message, 'error', 'DB ERROR');
                showToast('❌ Kesalahan koneksi: ' + e.message, 'error');
            } finally {
                btnTestDb.disabled = false;
                btnTestDb.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Uji Koneksi DB Baru';
            }
        });
    }

    // Jalankan Pemulihan (Restore Orchestrator)
    if (btnStartInstall) {
        btnStartInstall.addEventListener('click', async () => {
            const host = document.getElementById('dbHost').value.trim();
            const port = document.getElementById('dbPort').value.trim();
            const db   = document.getElementById('dbName').value.trim();
            const user = document.getElementById('dbUser').value.trim();
            const pass = document.getElementById('dbPass').value;
            const oldUrl = document.getElementById('oldUrl').value.trim();
            const newUrl = document.getElementById('newUrl').value.trim();

            if (currentScope !== 'files_only' && !db) {
                showToast('Harap isi nama database tujuan sebelum memulai pemulihan.', 'error');
                document.getElementById('dbName').focus();
                return;
            }

            if (!confirm(`Mulai pemulihan website dengan mode "${currentScope.toUpperCase()}"? Data yang sudah ada mungkin akan ditimpa.`)) {
                return;
            }

            btnStartInstall.disabled = true;
            btnStartInstall.innerHTML = '⏳ Memproses Pemulihan...';
            pipelineCard.style.display = 'block';
            pipelineCard.scrollIntoView({ behavior: 'smooth' });

            log('=== MEMULAI RESTORASI WEBSITE ===', 'info', 'START');
            log(`Scope mode: ${currentScope}`, 'info', 'CONFIG');

            totalExtractedFiles = 0;
            totalReplacedRows = 0;

            try {
                // TAHAP 1: Ekstraksi Berkas ZIP (jika mode full atau files_only)
                if (currentScope === 'full' || currentScope === 'files_only') {
                    stepFiles.classList.add('active');
                    log('Langkah 1: Mengekstrak berkas arsip website...', 'info', 'EXTRACT');
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
                        totalExtractedFiles = res.total_files || res.extracted || totalExtractedFiles;

                        const mult = (currentScope === 'files_only') ? 1.0 : 0.45;
                        const pct = Math.round(res.percent * mult);
                        setProgress(pct, res.message);
                        log(res.message, 'info', 'EXTRACT');
                    }

                    stepFiles.classList.remove('active');
                    stepFiles.classList.add('completed');
                    log('Arsip berkas ZIP berhasil diekstrak 100%!', 'success', 'EXTRACT');
                } else {
                    stepFiles.classList.add('completed');
                    log('Tahap ekstraksi berkas dilewati (Mode DB Saja).', 'info', 'SKIP');
                }

                // TAHAP 2: Impor Database SQL (jika mode full atau db_only)
                if (currentScope === 'full' || currentScope === 'db_only') {
                    stepDb.classList.add('active');
                    log('Langkah 2: Mengimpor skema & data database MySQL...', 'info', 'DATABASE');
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

                        const basePct = (currentScope === 'db_only') ? 0 : 45;
                        const mult = (currentScope === 'db_only') ? 0.65 : 0.35;
                        const pct = basePct + Math.round(res.percent * mult);
                        setProgress(pct, res.message);
                        log(res.message, 'info', 'DATABASE');
                    }

                    stepDb.classList.remove('active');
                    stepDb.classList.add('completed');
                    log('Restorasi struktur & data database selesai 100%!', 'success', 'DATABASE');

                    // TAHAP 3: Search & Replace URL & Update Config
                    stepUrl.classList.add('active');
                    log('Langkah 3: Menjalankan Search & Replace domain URL...', 'info', 'REPLACE');
                    setProgress(85, 'Menyesuaikan domain URL database...');

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
                    totalReplacedRows = srRes.updated_rows || 0;
                    log(srRes.message, 'success', 'REPLACE');

                    // Update Config
                    log('Memperbarui file konfigurasi (.env / wp-config.php)...', 'info', 'CONFIG');
                    setProgress(95, 'Memperbarui file konfigurasi & aturan .htaccess...');
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
                    log(cfgRes.message, 'success', 'CONFIG');

                    stepUrl.classList.remove('active');
                    stepUrl.classList.add('completed');
                } else {
                    stepDb.classList.add('completed');
                    stepUrl.classList.add('completed');
                    log('Tahap database dan URL dilewati (Mode Hanya Berkas).', 'info', 'SKIP');
                }

                // TAHAP 4: Selesai
                stepFinish.classList.add('completed');
                setProgress(100, 'Restorasi website selesai 100%!');
                log('🎉 PEMULIHAN WEBSITE SELESAI DENGAN SEMPURNA!', 'success', 'FINISH');

                // Update summary stats
                if (statFilesCount) statFilesCount.innerText = totalExtractedFiles || (currentScope === 'db_only' ? 'Dilewati' : 'Selesai');
                if (statQueriesCount) statQueriesCount.innerText = currentScope === 'files_only' ? 'Dilewati' : '100% Sukses';
                if (statUrlReplaced) statUrlReplaced.innerText = currentScope === 'files_only' ? '0' : totalReplacedRows;

                if (finishCard) {
                    finishCard.style.display = 'block';
                    finishCard.scrollIntoView({ behavior: 'smooth' });
                }
                showToast('🎉 Restorasi website berhasil tuntas!', 'success');

            } catch (err) {
                log('❌ Terjadi kesalahan pemulihan: ' + err.message, 'error', 'ERROR');
                setProgress(0, 'Restorasi gagal.');
                showToast('❌ Kesalahan: ' + err.message, 'error');
            } finally {
                btnStartInstall.disabled = false;
                btnStartInstall.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg> Mulai Pemulihan Website (Start Restore)';
            }
        });
    }

    // Self-Destruct
    if (btnSelfDestruct) {
        btnSelfDestruct.addEventListener('click', async () => {
            if (!confirm('Yakin ingin menghapus berkas installer.php, kunci akses, dan seluruh arsip migrasi sekarang? Tindakan ini tidak dapat dibatalkan demi keamanan hosting Anda.')) {
                return;
            }

            try {
                btnSelfDestruct.disabled = true;
                btnSelfDestruct.innerText = '⏳ Menghapus berkas...';
                log('Menghapus berkas installer.php, file kunci, dan paket migrasi...', 'warn', 'CLEANUP');

                const fd = new FormData();
                fd.append('action', 'self_destruct');
                const r = await fetch('installer.php', { method: 'POST', body: fd });
                const res = await r.json();

                if (res.success) {
                    alert('✅ ' + res.message);
                    window.location.href = './';
                } else {
                    alert('Peringatan: ' + res.message);
                    window.location.href = './';
                }
            } catch (e) {
                alert('Pembersihan selesai.');
                window.location.href = './';
            }
        });
    }
});
</script>
</body>
</html>
