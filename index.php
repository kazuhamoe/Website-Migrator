<?php
/**
 * Website Migrator - Universal 1-Click Site Cloner & Packager
 *
 * @author kazuhamoe <kazuhamoe@users.noreply.github.com>
 * @license MIT
 */

define('MIGRATOR_INIT', true);
define('MIGRATOR_VERSION', '1.0.0');

require_once __DIR__ . '/app/SystemCheck.php';
require_once __DIR__ . '/app/AutoDetector.php';
require_once __DIR__ . '/app/DatabaseDumper.php';
require_once __DIR__ . '/app/DatabaseRestorer.php';
require_once __DIR__ . '/app/SerializedReplacer.php';
require_once __DIR__ . '/app/ArchiveManager.php';

SystemCheck::optimizeLimits();

$tempDir = __DIR__ . '/storage/temp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// -------------------------------------------------------------
// AJAX ROUTER & API HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. Tes Koneksi Database
        if ($action === 'test_db') {
            $dumper = new DatabaseDumper([
                'host' => trim($_POST['host'] ?? 'localhost'),
                'port' => (int)($_POST['port'] ?? 3306),
                'database' => trim($_POST['database'] ?? ''),
                'username' => trim($_POST['username'] ?? 'root'),
                'password' => $_POST['password'] ?? '',
            ]);
            echo json_encode($dumper->testConnection());
            exit;
        }

        // 2. Export Database ke SQL (Mode Export)
        if ($action === 'export_database') {
            $sqlFile = $tempDir . '/migrator_database.sql';
            $dumper = new DatabaseDumper([
                'host' => trim($_POST['host'] ?? 'localhost'),
                'port' => (int)($_POST['port'] ?? 3306),
                'database' => trim($_POST['database'] ?? ''),
                'username' => trim($_POST['username'] ?? 'root'),
                'password' => $_POST['password'] ?? '',
            ]);

            $result = $dumper->dump($sqlFile);
            echo json_encode($result);
            exit;
        }

        // 3. Buat Arsip ZIP (Mode Export)
        if ($action === 'create_zip') {
            $sourceDir = realpath($_POST['source_dir'] ?? __DIR__);
            if (!$sourceDir || !is_dir($sourceDir)) {
                throw new InvalidArgumentException("Direktori sumber tidak valid.");
            }

            $outputZip = $tempDir . '/migrator_package.zip';
            $sqlFile = $tempDir . '/migrator_database.sql';

            $extraFiles = [];
            if (file_exists($sqlFile)) {
                $extraFiles['migrator_database.sql'] = $sqlFile;
            }

            $customExcludes = ['storage/temp', 'storage/temp/*'];
            if (!empty($_POST['exclude_media']) && $_POST['exclude_media'] === '1') {
                $customExcludes[] = 'wp-content/uploads';
            }

            $res = ArchiveManager::createZip($sourceDir, $outputZip, $extraFiles, $customExcludes);
            echo json_encode($res);
            exit;
        }

        // 4. Upload Berkas ZIP / SQL (Mode Import)
        if ($action === 'upload_import_file') {
            if (empty($_FILES['package_file'])) {
                throw new InvalidArgumentException('Tidak ada berkas yang diunggah.');
            }

            $file = $_FILES['package_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Gagal mengunggah berkas (Kode error: ' . $file['error'] . ')');
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $targetPath = $tempDir . '/migrator_package.zip';
            } elseif ($ext === 'sql') {
                $targetPath = $tempDir . '/migrator_database.sql';
            } else {
                throw new InvalidArgumentException('Hanya berkas .zip dan .sql yang didukung.');
            }

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new RuntimeException('Gagal memindahkan berkas unggahan ke folder temp.');
            }

            echo json_encode([
                'success' => true,
                'type' => $ext,
                'file_name' => $file['name'],
                'file_size' => filesize($targetPath),
                'message' => "Berkas {$file['name']} berhasil diunggah."
            ]);
            exit;
        }

        // 5. Ekstrak ZIP Bertahap (Mode Import)
        if ($action === 'extract_import_chunk') {
            $zipFile = $tempDir . '/migrator_package.zip';
            if (!file_exists($zipFile) && file_exists(__DIR__ . '/migrator_package.zip')) {
                $zipFile = __DIR__ . '/migrator_package.zip';
            }

            $extractTo = realpath($_POST['target_dir'] ?? __DIR__);
            if (!$extractTo || !is_dir($extractTo)) {
                throw new InvalidArgumentException('Direktori tujuan ekstraksi tidak valid.');
            }

            $startIndex = (int)($_POST['start_index'] ?? 0);
            $res = ArchiveManager::extractChunk($zipFile, $extractTo, $startIndex, 250);
            echo json_encode($res);
            exit;
        }

        // 6. Impor SQL Bertahap (Mode Import)
        if ($action === 'import_sql_chunk') {
            $sqlFile = $tempDir . '/migrator_database.sql';
            if (!file_exists($sqlFile) && file_exists(__DIR__ . '/migrator_database.sql')) {
                $sqlFile = __DIR__ . '/migrator_database.sql';
            }

            $restorer = new DatabaseRestorer([
                'host' => trim($_POST['host'] ?? 'localhost'),
                'port' => (int)($_POST['port'] ?? 3306),
                'database' => trim($_POST['database'] ?? ''),
                'username' => trim($_POST['username'] ?? 'root'),
                'password' => $_POST['password'] ?? '',
            ]);

            $offset = (int)($_POST['offset'] ?? 0);
            $res = $restorer->importChunk($sqlFile, $offset);
            echo json_encode($res);
            exit;
        }

        // 7. Search and Replace Domain (Mode Import)
        if ($action === 'search_replace') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';
            $oldUrl = trim($_POST['old_url'] ?? '');
            $newUrl = trim($_POST['new_url'] ?? '');

            if (empty($oldUrl) || empty($newUrl) || $oldUrl === $newUrl) {
                echo json_encode(['success' => true, 'updated_rows' => 0, 'message' => 'URL sama atau kosong, penggantian dilewati.']);
                exit;
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $res = SerializedReplacer::replaceInDatabase($pdo, $oldUrl, $newUrl);
            echo json_encode([
                'success' => true,
                'updated_rows' => $res['updated_rows'],
                'message' => "Search & replace selesai! {$res['updated_rows']} baris data diperbarui ke URL baru."
            ]);
            exit;
        }

        throw new Exception("Action '{$action}' tidak dikenal.");
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// -------------------------------------------------------------
// DOWNLOAD HANDLER
// -------------------------------------------------------------
if (isset($_GET['download'])) {
    $target = $_GET['download'];
    if ($target === 'package') {
        $zipFile = $tempDir . '/migrator_package.zip';
        if (file_exists($zipFile)) {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="migrator_package_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile);
            exit;
        }
    } elseif ($target === 'installer') {
        $installerPath = __DIR__ . '/installer.php';
        if (file_exists($installerPath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="installer.php"');
            header('Content-Length: ' . filesize($installerPath));
            readfile($installerPath);
            exit;
        }
    }
    exit('File tidak ditemukan.');
}

// -------------------------------------------------------------
// LOAD PRE-FLIGHT CHECKS & AUTODETECT
// -------------------------------------------------------------
$sysCheck = SystemCheck::check(__DIR__);
$autoDetect = AutoDetector::detect(__DIR__);
$dbCreds = $autoDetect['credentials'] ?? [
    'host' => 'localhost',
    'database' => '',
    'username' => 'root',
    'password' => '',
];

$existingZip = file_exists($tempDir . '/migrator_package.zip') || file_exists(__DIR__ . '/migrator_package.zip');
$existingSql = file_exists($tempDir . '/migrator_database.sql') || file_exists(__DIR__ . '/migrator_database.sql');

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
    <title>Website Migrator &amp; Site Cloner</title>
    <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="container">
    <!-- Header -->
    <header class="app-header">
        <div class="logo-area">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/>
                </svg>
            </div>
            <div class="logo-title">
                <h1>Website Migrator</h1>
                <p>Universal 1-Click Site Cloner &amp; Hosting Migration Tool</p>
            </div>
        </div>
        <span class="badge-version">v<?php echo MIGRATOR_VERSION; ?></span>
    </header>

    <!-- Visual Workflow Explainer -->
    <div class="workflow-explainer">
        <div class="workflow-card active-flow">
            <span class="workflow-step-badge">Langkah 1 &bull; Server Asal / Localhost</span>
            <h3>📦 Bungkus Website (Export)</h3>
            <p>Jalankan <strong>Tab Ekspor</strong> untuk memindai CMS, streaming dump database MySQL, dan menghasilkan berkas <code>migrator_package.zip</code> + <code>installer.php</code>.</p>
        </div>
        <div class="workflow-card">
            <span class="workflow-step-badge">Langkah 2 &bull; Server Tujuan / cPanel Baru</span>
            <h3>🚀 Pasang &amp; Pulihkan (Import)</h3>
            <p>Upload paket ke hosting baru (folder <code>public_html</code>) lalu buka <code>installer.php</code> atau gunakan <strong>Tab Impor</strong> untuk auto-extract dan restore database.</p>
        </div>
    </div>

    <!-- Mode Switcher Tabs -->
    <div class="mode-tabs">
        <button type="button" class="mode-tab-btn active" id="tabBtnExport" onclick="switchMode('export')">
            📦 Mode 1: Buat Paket Migrasi (Export / Backup)
        </button>
        <button type="button" class="mode-tab-btn" id="tabBtnImport" onclick="switchMode('import')">
            🚀 Mode 2: Pasang / Pulihkan Website (Import &amp; Auto-Extract)
        </button>
    </div>

    <!-- ========================================================= -->
    <!-- TAB 1: MODE EXPORT / PACKAGER                             -->
    <!-- ========================================================= -->
    <div id="sectionExport">
        <!-- Pre-Flight System Requirements -->
        <div class="glass-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Diagnosa Sistem &amp; Kesiapan Server Asal
            </h2>
            <div class="req-grid">
                <?php foreach ($sysCheck['requirements'] as $req): ?>
                    <div class="req-item">
                        <div class="req-info">
                            <h4><?php echo htmlspecialchars($req['name']); ?></h4>
                            <p><?php echo htmlspecialchars($req['current']); ?></p>
                        </div>
                        <span class="req-badge <?php echo $req['passed'] ? 'pass' : 'fail'; ?>">
                            <?php echo $req['passed'] ? '✓ Siap' : '✗ Kurang'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- CMS Auto-Detection Card -->
        <div class="glass-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
                Target Website Terdeteksi
            </h2>
            <div class="alert alert-info">
                <?php if ($autoDetect['detected']): ?>
                    🎉 Sistem mendeteksi <strong><?php echo htmlspecialchars($autoDetect['name']); ?></strong> secara otomatis! Konfigurasi database telah diisi otomatis di bawah.
                <?php else: ?>
                    ℹ️ Mode <strong>PHP Native / Custom Website</strong>. Masukkan kredensial database MySQL Anda di bawah ini.
                <?php endif; ?>
            </div>

            <form id="formExport">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="sourceDir">Direktori Sumber Website yang Akan Dipaketkan:</label>
                        <input type="text" id="sourceDir" name="source_dir" class="form-control" value="<?php echo htmlspecialchars(__DIR__); ?>" required>
                    </div>
                </div>

                <h3 style="font-size: 1.05rem; margin: 1.5rem 0 1rem; color: #cbd5e1;">Koneksi Database MySQL (Sumber)</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="dbHost">MySQL Host</label>
                        <input type="text" id="dbHost" name="host" class="form-control" value="<?php echo htmlspecialchars($dbCreds['host'] ?? 'localhost'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="dbPort">Port</label>
                        <input type="number" id="dbPort" name="port" class="form-control" value="3306" required>
                    </div>
                    <div class="form-group">
                        <label for="dbName">Nama Database</label>
                        <input type="text" id="dbName" name="database" class="form-control" value="<?php echo htmlspecialchars($dbCreds['database'] ?? ''); ?>" placeholder="nama_db" required>
                    </div>
                    <div class="form-group">
                        <label for="dbUser">Username DB</label>
                        <input type="text" id="dbUser" name="username" class="form-control" value="<?php echo htmlspecialchars($dbCreds['username'] ?? 'root'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="dbPass">Password DB</label>
                        <input type="password" id="dbPass" name="password" class="form-control" value="<?php echo htmlspecialchars($dbCreds['password'] ?? ''); ?>" placeholder="(kosongkan jika tanpa password)">
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <button type="button" id="btnTestDb" class="btn btn-secondary">🔍 Uji Koneksi DB</button>
                </div>

                <h3 style="font-size: 1.05rem; margin: 1.5rem 0 1rem; color: #cbd5e1;">Pilihan Optimasi &amp; Pengecualian (Smart Exclude)</h3>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" id="excludeCache" checked disabled>
                        <span>Abaikan berkas sampah &amp; cache sistem (<code>.git</code>, <code>node_modules</code>, <code>cache</code>, log internal)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" id="excludeMedia">
                        <span>Abaikan folder upload media besar (opsional, backup cepat tanpa gambar upload)</span>
                    </label>
                </div>

                <button type="button" id="btnStartExport" class="btn btn-primary" style="font-size: 1.05rem; padding: 0.85rem 2rem;">
                    🚀 Mulai Pemaketan (Export)
                </button>
            </form>
        </div>

        <!-- Result / Download Card -->
        <div id="resultCard" class="glass-card" style="display: none; border-color: rgba(16, 185, 129, 0.4);">
            <h2 class="card-title" style="color: #34d399;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                Paket Migrasi Berhasil Dibuat!
            </h2>
            <div class="alert alert-success">
                Selamat! Seluruh berkas website dan database MySQL Anda telah berhasil dikemas ke dalam arsip migrasi.
            </div>
            <p style="margin-bottom: 1.25rem; color: #9ca3af;">
                Unduh kedua berkas di bawah ini dan unggah ke direktori website tujuan di hosting baru (misalnya <code>public_html</code>):
            </p>

            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.75rem;">
                <a href="index.php?download=package" id="downloadZipBtn" class="btn btn-success">
                    📦 Unduh migrator_package.zip
                </a>
                <a href="index.php?download=installer" id="downloadInstallerBtn" class="btn btn-primary">
                    ⚡ Unduh installer.php
                </a>
            </div>

            <div style="background: rgba(0,0,0,0.3); border-radius: 8px; padding: 1.25rem; border: 1px solid var(--border-color);">
                <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: #e2e8f0;">Panduan Pemasangan di Hosting Baru:</h4>
                <ol style="margin-left: 1.25rem; font-size: 0.88rem; color: #9ca3af; line-height: 1.8;">
                    <li>Upload <code>migrator_package.zip</code> dan <code>installer.php</code> ke folder root hosting tujuan (misal <code>public_html</code>).</li>
                    <li>Buka browser: <code>https://domain-baru-anda.com/installer.php</code>.</li>
                    <li>Masukkan kredensial database baru dan klik <strong>Mulai Pemulihan</strong>.</li>
                    <li>Setelah selesai, klik <strong>Hapus Berkas Migrator (Self-Destruct)</strong>.</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- TAB 2: MODE IMPORT / DEPLOYER (AUTO-EXTRACT & RESTORE)   -->
    <!-- ========================================================= -->
    <div id="sectionImport" style="display: none;">
        <div class="glass-card">
            <h2 class="card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Unggah &amp; Pasang Paket Migrasi (Auto-Extract ke Server Ini)
            </h2>
            <div class="alert alert-info">
                Gunakan menu ini jika Anda sudah memiliki berkas <code>migrator_package.zip</code> dan ingin langsung mengekstrak serta merestore database ke server/hosting ini.
            </div>

            <!-- Status Berkas yang Terdeteksi -->
            <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                <div class="req-item" style="flex: 1; min-width: 250px;">
                    <div>
                        <h4>Berkas ZIP Paket</h4>
                        <p id="zipFileStatus"><?php echo $existingZip ? 'migrator_package.zip (Siap Dipasang)' : 'Belum Ada (Silakan Upload)'; ?></p>
                    </div>
                    <span class="req-badge <?php echo $existingZip ? 'pass' : 'fail'; ?>" id="zipFileBadge">
                        <?php echo $existingZip ? '✓ Siap' : '✗ Belum'; ?>
                    </span>
                </div>
                <div class="req-item" style="flex: 1; min-width: 250px;">
                    <div>
                        <h4>Berkas Database SQL</h4>
                        <p id="sqlFileStatus"><?php echo $existingSql ? 'migrator_database.sql (Siap Diimpor)' : 'Termasuk dalam ZIP'; ?></p>
                    </div>
                    <span class="req-badge pass">✓ Otomatis</span>
                </div>
            </div>

            <!-- Upload Dropzone -->
            <div class="dropzone-box" id="dropzoneZip" onclick="document.getElementById('fileUploadInput').click();">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <div class="dropzone-title">Klik atau Seret Berkas ZIP / SQL ke Sini untuk Upload</div>
                <div class="dropzone-subtitle">Mendukung berkas <code>migrator_package.zip</code> atau backup <code>.sql</code></div>
                <input type="file" id="fileUploadInput" style="display: none;" accept=".zip,.sql">
            </div>

            <!-- Form Restore / Extract -->
            <form id="formImport">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="targetExtractDir">Direktori Tujuan Ekstraksi (misal folder website atau public_html):</label>
                        <input type="text" id="targetExtractDir" name="target_dir" class="form-control" value="<?php echo htmlspecialchars(__DIR__); ?>" required>
                    </div>
                </div>

                <h3 style="font-size: 1.05rem; margin: 1.5rem 0 1rem; color: #cbd5e1;">Koneksi Database MySQL Tujuan</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="importDbHost">MySQL Host</label>
                        <input type="text" id="importDbHost" name="host" class="form-control" value="localhost" required>
                    </div>
                    <div class="form-group">
                        <label for="importDbPort">Port</label>
                        <input type="number" id="importDbPort" name="port" class="form-control" value="3306" required>
                    </div>
                    <div class="form-group">
                        <label for="importDbName">Nama Database Baru</label>
                        <input type="text" id="importDbName" name="database" class="form-control" placeholder="nama_db" required>
                    </div>
                    <div class="form-group">
                        <label for="importDbUser">Username DB</label>
                        <input type="text" id="importDbUser" name="username" class="form-control" value="root" required>
                    </div>
                    <div class="form-group">
                        <label for="importDbPass">Password DB</label>
                        <input type="password" id="importDbPass" name="password" class="form-control" placeholder="(kosongkan jika tanpa password)">
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <button type="button" id="btnTestImportDb" class="btn btn-secondary">🔍 Uji Koneksi DB Tujuan</button>
                </div>

                <h3 style="font-size: 1.05rem; margin: 1.5rem 0 1rem; color: #cbd5e1;">Penyesuaian Domain / URL (Search &amp; Replace)</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="importOldUrl">Domain / URL Lama</label>
                        <input type="text" id="importOldUrl" name="old_url" class="form-control" placeholder="https://domain-lama.com">
                    </div>
                    <div class="form-group">
                        <label for="importNewUrl">Domain / URL Baru</label>
                        <input type="text" id="importNewUrl" name="new_url" class="form-control" value="<?php echo htmlspecialchars($currentSiteUrl); ?>" required>
                    </div>
                </div>

                <button type="button" id="btnStartImport" class="btn btn-success" style="font-size: 1.05rem; padding: 0.85rem 2rem;">
                    🚀 Ekstrak Berkas &amp; Restore Database Sekarang
                </button>
            </form>
        </div>
    </div>

    <!-- Live Progress Bar & Terminal Logs (Digunakan bersama oleh kedua mode) -->
    <div class="glass-card" style="padding-top: 1.5rem;">
        <h3 style="font-size: 1rem; margin-bottom: 0.5rem; color: #cbd5e1;">Status Eksekusi Realtime</h3>
        <div class="progress-container">
            <div class="progress-bar-bg">
                <div id="progressBar" class="progress-bar-fill"></div>
            </div>
            <div class="progress-label">
                <span id="progressStatus">Menunggu perintah eksekusi...</span>
                <span id="progressText">0%</span>
            </div>
        </div>

        <div class="terminal-box">
            <div class="terminal-header">
                <div class="terminal-dots">
                    <div class="dot dot-red"></div>
                    <div class="dot dot-yellow"></div>
                    <div class="dot dot-green"></div>
                </div>
                <div class="terminal-title">migrator-process.log</div>
                <div style="width: 40px;"></div>
            </div>
            <div id="terminalContent" class="terminal-content">
                <div class="log-line log-info"><span class="log-time">[Ready]</span> Website Migrator v<?php echo MIGRATOR_VERSION; ?> siap dijalankan.</div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="app-footer">
        <p>Website Migrator &amp; Site Cloner &bull; Open Source under <a href="LICENSE" target="_blank">MIT License</a></p>
        <p style="margin-top: 0.25rem; font-size: 0.78rem;">100% Bebas Backdoor &bull; Tanpa Telemetri &bull; Dibuat oleh <a href="https://github.com/kazuhamoe" target="_blank">@kazuhamoe</a></p>
    </footer>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
