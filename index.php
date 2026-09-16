<?php
define('MIGRATOR_INIT', true);
define('MIGRATOR_VERSION', '1.0.0');

require_once __DIR__ . '/app/SystemCheck.php';
require_once __DIR__ . '/app/AutoDetector.php';
require_once __DIR__ . '/app/DatabaseDumper.php';
require_once __DIR__ . '/app/ArchiveManager.php';

SystemCheck::optimizeLimits();

$tempDir = __DIR__ . '/storage/temp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// -------------------------------------------------------------
// AJAX ROUTER & DOWNLOAD HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
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

        if ($action === 'list_dirs') {
            $rawPath = trim($_POST['path'] ?? '');
            if (empty($rawPath)) {
                $rawPath = dirname(__DIR__);
            }
            $resolved = realpath($rawPath);
            if (!$resolved || !is_dir($resolved)) {
                $resolved = realpath(__DIR__);
            }

            $folders = [];
            $items = @scandir($resolved);
            if ($items) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    $full = $resolved . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($full)) {
                        $isWp = file_exists($full . '/wp-config.php');
                        $isLaravel = file_exists($full . '/artisan') && file_exists($full . '/.env');
                        $isMigrator = realpath($full) === realpath(__DIR__);
                        $folders[] = [
                            'name' => $item,
                            'path' => str_replace('\\', '/', $full),
                            'tag' => $isWp ? 'WordPress' : ($isLaravel ? 'Laravel' : ($isMigrator ? 'Migrator' : 'Folder')),
                            'tag_class' => $isWp ? 'wp' : ($isLaravel ? 'laravel' : ($isMigrator ? 'migrator' : 'folder')),
                            'is_migrator' => $isMigrator,
                        ];
                    }
                }
            }

            $parent = dirname($resolved);
            echo json_encode([
                'success' => true,
                'current_path' => str_replace('\\', '/', $resolved),
                'parent_path' => ($parent && $parent !== $resolved && is_dir($parent)) ? str_replace('\\', '/', $parent) : null,
                'folders' => $folders,
            ]);
            exit;
        }

        if ($action === 'detect_dir') {
            $rawPath = trim($_POST['path'] ?? __DIR__);
            $resolved = realpath($rawPath);
            if (!$resolved || !is_dir($resolved)) {
                throw new InvalidArgumentException("Direktori tidak valid atau tidak ditemukan di server: {$rawPath}");
            }

            $detect = AutoDetector::detect($resolved);
            $isMigrator = realpath($resolved) === realpath(__DIR__);
            echo json_encode([
                'success' => true,
                'path' => str_replace('\\', '/', $resolved),
                'name' => $detect['name'] ?? 'PHP Native / Custom Website',
                'type' => $detect['type'] ?? 'generic',
                'detected' => $detect['detected'] ?? false,
                'credentials' => $detect['credentials'] ?? null,
                'is_migrator' => $isMigrator,
            ]);
            exit;
        }

        if ($action === 'create_zip') {
            $rawDir = trim($_POST['source_dir'] ?? __DIR__);
            $sourceDir = realpath($rawDir);
            if (!$sourceDir || !is_dir($sourceDir)) {
                throw new InvalidArgumentException("Direktori sumber tidak valid atau tidak ditemukan: {$rawDir}");
            }

            $outputZip = $tempDir . '/migrator_package.zip';
            $sqlFile = $tempDir . '/migrator_database.sql';

            $includeDb = !isset($_POST['include_db']) || $_POST['include_db'] === '1' || $_POST['include_db'] === 'true';

            $extraFiles = [];
            if ($includeDb && file_exists($sqlFile)) {
                $extraFiles['migrator_database.sql'] = $sqlFile;
            }

            $customExcludes = ['storage/temp', 'storage/temp/*', 'bolt/project', 'bolt/node_modules'];
            // Jika memaketkan folder Migrator itu sendiri, abaikan file kerja internal migrator
            if (realpath($sourceDir) === realpath(__DIR__)) {
                $customExcludes = array_merge($customExcludes, ['bolt', 'tests', '.git', 'README*.md']);
            }
            if (!empty($_POST['exclude_media']) && $_POST['exclude_media'] === '1') {
                $customExcludes[] = 'wp-content/uploads';
            }

            $res = ArchiveManager::createZip($sourceDir, $outputZip, $extraFiles, $customExcludes);
            $res['include_db'] = $includeDb;
            echo json_encode($res);
            exit;
        }

        throw new Exception("Unknown action '{$action}'.");
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Handle Direct Downloads
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
    exit('File not found.');
}

// Pre-flight checks & Auto-detect
$baseDir = realpath(__DIR__);
$parentDir = dirname($baseDir);

$siblingFolders = [];
if ($parentDir && is_dir($parentDir) && is_readable($parentDir)) {
    $scannedItems = @scandir($parentDir);
    if ($scannedItems) {
        foreach ($scannedItems as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $parentDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $isCurrent = realpath($full) === realpath($baseDir);
                $isWp = file_exists($full . '/wp-config.php');
                $isLaravel = file_exists($full . '/artisan') && file_exists($full . '/.env');
                $siblingFolders[] = [
                    'name' => $item,
                    'path' => str_replace('\\', '/', $full),
                    'is_current' => $isCurrent,
                    'tag' => $isWp ? 'WordPress' : ($isLaravel ? 'Laravel' : 'Website Folder'),
                ];
            }
        }
    }
}

$sysCheck = SystemCheck::check($baseDir);
$autoDetect = AutoDetector::detect($baseDir);
$dbCreds = $autoDetect['credentials'] ?? [
    'host' => 'localhost',
    'database' => '',
    'username' => 'root',
    'password' => '',
];

$page = 'home';
require 'includes/header.php';
?>

<!-- HERO -->
<section class="hero">
    <div class="hero-badge">Open Source • MIT License</div>
    <h1>Universal 1-Click Site Cloner</h1>
    <p class="hero-subtitle">Package, migrate and restore your PHP website with a simple workflow.</p>

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
            <div class="label">PACKAGE</div>
        </div>
        <div class="flow-connector">
            <div class="line"></div>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
        </div>
        <div class="flow-node target">
            <div class="dot"></div>
            <div class="label">TARGET</div>
        </div>
    </div>
</section>

<!-- MODE SELECTOR -->
<div class="mode-selector">
    <div class="mode-grid">
        <a href="index.php" class="mode-card active">
            <div class="icon">📦</div>
            <div class="title">Create Migration Package</div>
            <div class="desc">Package your website and database into a portable migration bundle.</div>
            <div class="selected-tag">
                <div class="dot"></div>
                <span>Selected</span>
            </div>
        </a>
        <a href="import.php" class="mode-card">
            <div class="icon">🚀</div>
            <div class="title">Install &amp; Restore Website</div>
            <div class="desc">Upload a migration package and restore your website to this server.</div>
            <div class="selected-tag">
                <div class="dot"></div>
                <span>Selected</span>
            </div>
        </a>
    </div>
</div>

<!-- EXPORT CONFIG FORM CONTENT -->
<div class="page-content" id="exportConfig">

    <!-- HOW TO USE / WORKFLOW GUIDE -->
    <div class="section howto-section">
        <div class="section-header">
            <span class="section-number">💡</span>
            <span class="section-title">Panduan Alur Migrasi (How to Use)</span>
            <div class="section-subtitle">Alur 4 langkah mudah pindahan website ke hosting / cPanel baru tanpa ribet dump manual:</div>
        </div>
        <div class="howto-grid">
            <div class="howto-card">
                <div class="howto-badge-row">
                    <span class="howto-step-badge">Langkah 01</span>
                    <span class="howto-icon">📦</span>
                </div>
                <div class="howto-title">1. Buat Paket (Export)</div>
                <div class="howto-desc">
                    Di hosting/server asal (atau localhost), isi form database dan file di bawah lalu klik <strong>"Start Package Build"</strong>. Download berkas <code>migrator_package.zip</code> dan <code>installer.php</code>.
                </div>
            </div>
            <div class="howto-card">
                <div class="howto-badge-row">
                    <span class="howto-step-badge">Langkah 02</span>
                    <span class="howto-icon">📤</span>
                </div>
                <div class="howto-title">2. Upload ke Hosting Baru</div>
                <div class="howto-desc">
                    Buka <strong>cPanel File Manager</strong> di hosting tujuan &rarr; masuk ke folder <code>public_html</code>. Upload kedua berkas: <code>migrator_package.zip</code> dan <code>installer.php</code> (jangan diekstrak manual, biarkan zip utuh).
                </div>
            </div>
            <div class="howto-card">
                <div class="howto-badge-row">
                    <span class="howto-step-badge">Langkah 03</span>
                    <span class="howto-icon">🚀</span>
                </div>
                <div class="howto-title">3. Buka Installer di Browser</div>
                <div class="howto-desc">
                    Akses <code>domain-anda.com/installer.php</code> di browser. Masukkan database MySQL baru dari cPanel. Script akan <strong>otomatis mengekstrak file ke public_html, mengimpor database, dan memperbarui URL</strong>.
                </div>
            </div>
            <div class="howto-card">
                <div class="howto-badge-row">
                    <span class="howto-step-badge">Langkah 04</span>
                    <span class="howto-icon">🔒</span>
                </div>
                <div class="howto-title">4. Selesai &amp; Self-Clean</div>
                <div class="howto-desc">
                    Website Anda sudah aktif normal di hosting baru! Centang opsi <strong>"Self-destruct / Hapus file migrator"</strong> untuk membersihkan installer &amp; zip otomatis demi keamanan.
                </div>
            </div>
        </div>
        <div class="howto-tip-box">
            <span class="tip-icon">💡</span>
            <div>
                <strong>Tips Hosting cPanel:</strong> Sebelum menjalankan <code>installer.php</code> di langkah 3, pastikan Anda sudah membuat Database &amp; User MySQL baru di cPanel (menu <em>MySQL Databases</em>) serta memberikan hak akses <em>ALL PRIVILEGES</em>.
            </div>
        </div>
    </div>

    <!-- SECTION 01: System Diagnosis -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">01</span>
            <span class="section-title">System Diagnosis &amp; Server Readiness</span>
            <div class="section-subtitle">Check whether the server is ready to create a migration package.</div>
        </div>
        <div class="diag-grid">
            <div class="diag-card">
                <div class="diag-card-top">
                    <span class="diag-icon">🐘</span>
                    <span class="status-badge ready">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Ready
                    </span>
                </div>
                <div>
                    <div class="diag-label">PHP Version</div>
                    <div class="diag-value"><?php echo PHP_VERSION; ?></div>
                </div>
            </div>
            <div class="diag-card">
                <div class="diag-card-top">
                    <span class="diag-icon">📦</span>
                    <span class="status-badge ready">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Ready
                    </span>
                </div>
                <div>
                    <div class="diag-label">ZIP Extension</div>
                    <div class="diag-value"><?php echo extension_loaded('zip') ? 'ZipArchive Active' : 'Disabled'; ?></div>
                </div>
            </div>
            <div class="diag-card">
                <div class="diag-card-top">
                    <span class="diag-icon">🗄️</span>
                    <span class="status-badge ready">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Ready
                    </span>
                </div>
                <div>
                    <div class="diag-label">MySQL Extension</div>
                    <div class="diag-value"><?php echo extension_loaded('pdo_mysql') ? 'PDO MySQL Active' : (extension_loaded('mysqli') ? 'MySQLi Active' : 'Disabled'); ?></div>
                </div>
            </div>
            <div class="diag-card">
                <div class="diag-card-top">
                    <span class="diag-icon">{ }</span>
                    <span class="status-badge ready">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Ready
                    </span>
                </div>
                <div>
                    <div class="diag-label">JSON Extension</div>
                    <div class="diag-value">Active</div>
                </div>
            </div>
            <div class="diag-card">
                <div class="diag-card-top">
                    <span class="diag-icon">📁</span>
                    <span class="status-badge ready">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Ready
                    </span>
                </div>
                <div>
                    <div class="diag-label">Directory Permission</div>
                    <div class="diag-value"><?php echo is_writable($baseDir) ? 'Writable' : 'Read-Only'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 02: Pilihan Paket Ekspor (Export Scope) -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">02</span>
            <span class="section-title">Pilihan Paket Ekspor</span>
            <div class="section-subtitle">Tentukan apakah ingin mengekspor seluruh website beserta database atau hanya file saja.</div>
        </div>
        <div class="scope-grid">
            <label class="scope-card active" id="scopeCardFull">
                <input type="radio" name="exportScope" value="full" checked style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📦</span>
                    <span class="scope-badge">Rekomendasi</span>
                </div>
                <div class="scope-title">Paket Lengkap (File + Database)</div>
                <div class="scope-desc">Menyatukan seluruh berkas website dan auto dump database MySQL ke dalam 1 paket ZIP siap pindah.</div>
            </label>

            <label class="scope-card" id="scopeCardFilesOnly">
                <input type="radio" name="exportScope" value="files_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📁</span>
                    <span class="scope-badge info">Tanpa Database</span>
                </div>
                <div class="scope-title">Hanya Berkas Website (Tanpa DB)</div>
                <div class="scope-desc">Melewati MySQL database. Hanya mem-backup file website ke ZIP. Cocok jika DB sudah di-dump manual.</div>
            </label>

            <label class="scope-card" id="scopeCardDbOnly">
                <input type="radio" name="exportScope" value="db_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">🗄️</span>
                    <span class="scope-badge warning">SQL Saja</span>
                </div>
                <div class="scope-title">Hanya Database (.SQL Saja)</div>
                <div class="scope-desc">Hanya men-dump database MySQL ke file .sql tanpa memaketkan berkas website. Cepat &amp; ringan.</div>
            </label>
        </div>
    </div>

    <!-- SECTION 03: Source Website Directory -->
    <div class="section" id="sectionSourceDir">
        <div class="section-header">
            <span class="section-number">03</span>
            <span class="section-title">Pilih Direktori Website Sumber</span>
            <div class="section-subtitle">Pilih folder website yang ingin dimigrasikan menggunakan tombol cepat, daftar folder, atau penjelajah.</div>
        </div>
        <div class="card">
            <!-- Pilihan Cepat / Preset -->
            <div class="dir-preset-bar">
                <span class="dir-preset-label">Pilihan Cepat:</span>
                <button type="button" class="btn-preset active" id="btnPresetCurrent" data-path="<?php echo htmlspecialchars($baseDir); ?>" onclick="window.handlePresetClick && window.handlePresetClick(this.dataset.path, this)" title="Gunakan direktori kerja saat ini">
                    🌐 Folder Saat Ini
                </button>
                <?php if (!empty($parentDir) && $parentDir !== $baseDir): ?>
                <button type="button" class="btn-preset" id="btnPresetParent" data-path="<?php echo htmlspecialchars($parentDir); ?>" onclick="window.handlePresetClick && window.handlePresetClick(this.dataset.path, this)" title="Gunakan direktori induk (misal di cPanel public_html)">
                    ⬆️ Folder Induk (..)
                </button>
                <?php endif; ?>
                <button type="button" class="btn-preset" id="btnOpenFolderBrowser" title="Buka jendela visual untuk memilih folder">
                    📂 Jelajahi Folder di Server...
                </button>
            </div>

            <!-- Dropdown Folder Proyek yang Ditemukan di Server -->
            <?php if (!empty($siblingFolders)): ?>
            <div class="form-field" style="margin-bottom: 16px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Pilih dari Folder Website yang Terdeteksi di Server (htdocs):</span>
                    <span style="font-size:11px;color:var(--accent-bright);font-weight:600;"><?php echo count($siblingFolders); ?> Folder Ditemukan</span>
                </label>
                <select id="selectSiblingFolder" class="form-input mono" onchange="window.handleSiblingFolderChange && window.handleSiblingFolderChange(this.value)">
                    <option value="">-- Klik untuk memilih folder website lain di server --</option>
                    <?php foreach ($siblingFolders as $f): ?>
                        <option value="<?php echo htmlspecialchars($f['path']); ?>" <?php echo $f['is_current'] ? 'disabled' : ''; ?>>
                            📁 <?php echo htmlspecialchars($f['name']); ?> [<?php echo htmlspecialchars($f['tag']); ?>]<?php echo $f['is_current'] ? ' (Folder Migrator Ini)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Input Path Aktual -->
            <div class="form-field">
                <label class="form-label">Path Direktori Target (Source Path):</label>
                <div class="form-input-wrap">
                    <span class="form-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                        </svg>
                    </span>
                    <input type="text" id="exportSourceDir" class="form-input with-icon mono" value="<?php echo htmlspecialchars($baseDir); ?>" placeholder="<?php echo htmlspecialchars($baseDir); ?>">
                </div>
            </div>

            <!-- Status CMS Terdeteksi Realtime -->
            <div class="detected-status-bar" id="detectedStatusBar" style="margin-top: 14px;">
                <div class="info-badge" id="detectedCmsBadge">
                    <div class="dot"></div>
                    <span id="detectedCmsName"><?php echo htmlspecialchars($autoDetect['name'] ?? 'PHP Native / Custom Website'); ?></span>
                </div>
                <span id="detectedCmsDetails" style="font-size:12px;color:var(--text-secondary);font-family:var(--font-mono);">
                    Path: <strong id="detectedPathText"><?php echo htmlspecialchars($baseDir); ?></strong>
                </span>
            </div>
        </div>
    </div>

    <script>
    window.handleSiblingFolderChange = function(val) {
        if (!val) return;
        var input = document.getElementById('exportSourceDir');
        if (input) {
            input.value = val;
            input.style.borderColor = 'var(--accent)';
            setTimeout(function() { input.style.borderColor = ''; }, 800);
        }
        var pathText = document.getElementById('detectedPathText');
        if (pathText) pathText.textContent = val;
        document.querySelectorAll('.btn-preset').forEach(function(b) { b.classList.remove('active'); });
        if (window.detectDirectory) {
            window.detectDirectory(val);
        }
    };
    window.handlePresetClick = function(path, btnEl) {
        if (!path) return;
        document.querySelectorAll('.btn-preset').forEach(function(b) { b.classList.remove('active'); });
        if (btnEl) btnEl.classList.add('active');
        var input = document.getElementById('exportSourceDir');
        if (input) {
            input.value = path;
            input.style.borderColor = 'var(--accent)';
            setTimeout(function() { input.style.borderColor = ''; }, 800);
        }
        var select = document.getElementById('selectSiblingFolder');
        if (select) select.value = '';
        var pathText = document.getElementById('detectedPathText');
        if (pathText) pathText.textContent = path;
        if (window.detectDirectory) {
            window.detectDirectory(path);
        }
    };
    </script>

    <!-- SECTION 04: Source MySQL Connection -->
    <div class="section" id="sectionSourceDb">
        <div class="section-header">
            <span class="section-number">04</span>
            <span class="section-title">Koneksi Database MySQL Sumber</span>
            <span id="dbDimmedBadge" class="dimmed-badge-overlay" style="display:none;">Dilewati: Mode Tanpa Database</span>
            <div class="section-subtitle" id="dbSubtitle">Kredensial database MySQL untuk diekspor ke migrator_database.sql.</div>
        </div>
        <div class="card" id="dbCardContainer">
            <div class="form-grid">
                <div class="form-field">
                    <label class="form-label">MySQL Host</label>
                    <input type="text" id="exportDbHost" class="form-input mono" value="<?php echo htmlspecialchars($dbCreds['host'] ?? 'localhost'); ?>" placeholder="localhost">
                </div>
                <div class="form-field">
                    <label class="form-label">Port</label>
                    <input type="text" id="exportDbPort" class="form-input mono" value="3306" placeholder="3306">
                </div>
                <div class="form-field">
                    <label class="form-label">Database Name</label>
                    <input type="text" id="exportDbName" class="form-input mono" value="<?php echo htmlspecialchars($dbCreds['database'] ?? ''); ?>" placeholder="database_name">
                </div>
                <div class="form-field">
                    <label class="form-label">Database Username</label>
                    <input type="text" id="exportDbUser" class="form-input mono" value="<?php echo htmlspecialchars($dbCreds['username'] ?? 'root'); ?>" placeholder="root">
                </div>
                <div class="form-field" style="grid-column: 1 / -1;">
                    <label class="form-label">Database Password</label>
                    <div class="form-input-wrap">
                        <input type="password" class="form-input with-toggle mono" id="exportDbPass" value="<?php echo htmlspecialchars($dbCreds['password'] ?? ''); ?>" placeholder="••••••••">
                        <button type="button" class="toggle-btn" data-toggle="exportDbPass" aria-label="Toggle password visibility">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-show">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-hide" style="display:none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-actions" id="dbTestActions">
                <button type="button" id="btnTestExportDb" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                    </svg>
                    Test Connection
                </button>
            </div>
        </div>
    </div>

    <!-- SECTION 04: Smart Exclude -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">04</span>
            <span class="section-title">Smart Exclude</span>
            <div class="section-subtitle">Reduce package size by excluding unnecessary files.</div>
        </div>
        <div class="card">
            <div class="toggle-row">
                <div class="toggle-info">
                    <div class="toggle-title">Exclude cache &amp; system junk</div>
                    <div class="toggle-desc">Ignore .git, node_modules, cache directories and internal logs.</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="excludeCache" checked disabled>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div class="toggle-row">
                <div class="toggle-info">
                    <div class="toggle-title">Exclude uploaded media</div>
                    <div class="toggle-desc">Skip large upload folders for a faster migration.</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="excludeMedia">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div style="margin-top:14px;font-size:12px;color:var(--warning);display:flex;align-items:center;gap:6px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Excluded files will not be included in the migration package.
            </div>
        </div>
    </div>

    <!-- EXPORT ACTIONS -->
    <div class="form-actions" style="padding-top:8px;">
        <button type="button" id="btnStartExportReal" class="btn btn-primary btn-lg">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Create Migration Package
        </button>
        <button type="button" class="btn btn-secondary" onclick="location.reload();">Reset</button>
    </div>

    <!-- LIVE TERMINAL CONSOLE LOG (AT THE BOTTOM) -->
    <div class="terminal-section">
        <div class="terminal-section-header">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 13px; font-weight: 600; color: var(--text-primary); text-transform: uppercase; letter-spacing: 0.05em; font-family: var(--font-mono);">💻 Live Terminal Console &amp; Activity Log</span>
                <span class="status-badge ready" style="font-size: 10px; padding: 1px 6px;">Live</span>
            </div>
            <div class="terminal-actions">
                <button type="button" id="btnCopyTerminal" class="btn-terminal-action" title="Copy console log">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                    Copy
                </button>
                <button type="button" id="btnClearTerminal" class="btn-terminal-action" title="Clear console output">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                    Clear
                </button>
            </div>
        </div>
        <div class="terminal" id="mainLiveTerminal">
            <div class="terminal-header">
                <div style="display:flex;align-items:center;">
                    <div class="terminal-dots"><span></span><span></span><span></span></div>
                    <span class="terminal-filename">migrator-activity.log</span>
                </div>
                <div class="terminal-live">
                    <div class="dot"></div>
                    <span>LIVE CONSOLE</span>
                </div>
            </div>
            <div class="terminal-body" id="mainTerminalBody" style="max-height: 240px; min-height: 150px;">
                <div class="terminal-line"><span class="lnum">01</span><span class="tag ready">[Ready]</span><span class="text"> Website Migrator Engine v<?php echo MIGRATOR_VERSION; ?> online.</span></div>
                <div class="terminal-line"><span class="lnum">02</span><span class="tag system">[System]</span><span class="text"> Environment: PHP <?php echo PHP_VERSION; ?> (<?php echo PHP_INT_SIZE === 8 ? 'x64' : 'x86'; ?>) | Memory Limit: <?php echo ini_get('memory_limit'); ?> | Max Execution: <?php echo ini_get('max_execution_time'); ?>s</span></div>
                <div class="terminal-line"><span class="lnum">03</span><span class="tag system">[System]</span><span class="text"> Extensions: ZipArchive (<?php echo extension_loaded('zip') ? 'OK' : 'FAIL'; ?>) • PDO MySQL (<?php echo extension_loaded('pdo_mysql') ? 'OK' : 'FAIL'; ?>) • JSON (OK) • cURL (<?php echo extension_loaded('curl') ? 'OK' : 'N/A'; ?>)</span></div>
                <div class="terminal-line"><span class="lnum">04</span><span class="tag detector">[Detector]</span><span class="text"> Target Web Root: <?php echo htmlspecialchars(__DIR__); ?></span></div>
                <div class="terminal-line"><span class="lnum">05</span><span class="tag info">[Storage]</span><span class="text"> Temp Directory: storage/temp (<?php echo is_writable($tempDir) ? 'Writable' : 'Read-only'; ?>)</span></div>
                <div class="terminal-line"><span class="lnum">06</span><span class="tag ready">[Ready]</span><span class="text"> Ready for migration commands. Click "Test Connection" to check database or "Create Migration Package" to start.</span></div>
            </div>
        </div>
    </div>

</div>

<!-- EXPORT PROGRESS (Realtime Dynamic) -->
<div id="exportProgress" class="page-content" style="display:none;">
    <div class="pipeline">
        <div class="pipeline-steps" id="exportPipelineSteps">
            <div class="pipeline-step active" data-step="0">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Database</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="1">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Packaging</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="2">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Compressing</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="3">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Complete</span>
            </div>
        </div>

        <div class="progress-display">
            <div class="progress-percent" id="exportPercent">0%</div>
            <div class="progress-text" id="exportProgressText">Initializing package creation...</div>
            <div class="progress-sub" id="exportProgressSub">Scanning files &amp; database</div>
        </div>

        <div class="progress-bar-wrap">
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="exportProgressBar" style="width:0%;"></div>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;">
        <div class="terminal">
            <div class="terminal-header">
                <div style="display:flex;align-items:center;">
                    <div class="terminal-dots"><span></span><span></span><span></span></div>
                    <span class="terminal-filename">migrator-process.log</span>
                </div>
                <div class="terminal-live">
                    <div class="dot"></div>
                    <span>LIVE</span>
                </div>
            </div>
            <div class="terminal-body" id="exportTerminalBody">
                <div class="terminal-line"><span class="lnum">01</span><span class="tag ready">[Ready]</span><span class="text"> Website Migrator v<?php echo MIGRATOR_VERSION; ?> initialized.</span></div>
            </div>
        </div>
    </div>
</div>

<!-- EXPORT COMPLETE (Real Results) -->
<div id="exportComplete" style="display:none;">
    <div class="success-container">
        <div class="success-checkmark">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <div class="success-title">Migration Package Ready</div>
        <div class="success-desc">Your website and database have been packaged successfully into a clean bundle.</div>

        <div class="success-file">
            <div class="success-file-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                </svg>
            </div>
            <div class="success-file-info">
                <div class="success-file-name">migrator_package.zip</div>
                <div class="success-file-meta" id="packageZipMeta">Archive Ready</div>
            </div>
        </div>

        <div class="success-file">
            <div class="success-file-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
                </svg>
            </div>
            <div class="success-file-info">
                <div class="success-file-name">installer.php</div>
                <div class="success-file-meta">Standalone 1-File Restorer</div>
            </div>
        </div>

        <div class="success-actions">
            <a href="index.php?download=package" class="btn btn-primary btn-lg">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Download Package (.zip)
            </a>
            <a href="index.php?download=installer" class="btn btn-secondary">
                Download installer.php
            </a>
            <button type="button" class="btn btn-ghost" onclick="location.reload();">Create Another Package</button>
        </div>

        <div class="security-strip">100% Clean Code • Zero Telemetry • MIT License</div>
    </div>
<!-- FOLDER BROWSER MODAL -->
<div class="modal-backdrop" id="folderBrowserModal" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">📂</span>
                <div>
                    <div style="font-weight:600;font-size:15px;color:var(--text-primary);">Penjelajah Folder Server (Directory Browser)</div>
                    <div style="font-size:12px;color:var(--text-muted);">Pilih folder website yang ingin Anda migrasikan</div>
                </div>
            </div>
            <button type="button" class="modal-close" id="btnCloseFolderBrowser" aria-label="Tutup">&times;</button>
        </div>

        <div class="modal-breadcrumb" id="browserBreadcrumb">
            <span>Root Server</span> &rsaquo; <span class="crumb-active" id="browserCurrentPath"><?php echo htmlspecialchars($baseDir); ?></span>
        </div>

        <div class="modal-body" id="browserFolderList">
            <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Memuat direktori server...</div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" id="btnBrowserGoParent" disabled>
                ⬆️ Naik 1 Folder
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-ghost btn-sm" id="btnCancelFolderBrowser">Batal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSelectCurrentFolder">✓ Gunakan Folder Ini</button>
            </div>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>

