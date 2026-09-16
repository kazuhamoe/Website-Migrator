<?php
define('MIGRATOR_INIT', true);
define('MIGRATOR_VERSION', '1.0.0');

require_once __DIR__ . '/app/SystemCheck.php';
require_once __DIR__ . '/app/AutoDetector.php';
require_once __DIR__ . '/app/DatabaseRestorer.php';
require_once __DIR__ . '/app/SerializedReplacer.php';
require_once __DIR__ . '/app/ArchiveManager.php';

SystemCheck::optimizeLimits();

$tempDir = __DIR__ . '/storage/temp';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}

// -------------------------------------------------------------
// AJAX ROUTER & RESTORATION HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. Tes Koneksi Database Tujuan
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

            echo json_encode(['success' => true, 'message' => "Successfully connected to database `{$db}`!"]);
            exit;
        }

        // 2. Upload Berkas ZIP / SQL
        if ($action === 'upload_file') {
            if (empty($_FILES['package_file'])) {
                throw new InvalidArgumentException('No file was uploaded.');
            }

            $file = $_FILES['package_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed with error code: ' . $file['error']);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'sql'])) {
                throw new InvalidArgumentException('Only .zip and .sql files are supported.');
            }

            $destName = ($ext === 'zip') ? 'migrator_package.zip' : 'migrator_database.sql';
            $destPath = $tempDir . '/' . $destName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new RuntimeException('Failed to save uploaded file to storage.');
            }

            echo json_encode([
                'success' => true,
                'type' => $ext,
                'file_name' => $file['name'],
                'file_size' => filesize($destPath),
                'formatted_size' => round(filesize($destPath) / 1024 / 1024, 2) . ' MB',
                'message' => "File {$file['name']} uploaded successfully."
            ]);
            exit;
        }

        // 3. Ekstrak ZIP Bertahap ke Target Directory
        if ($action === 'extract_chunk') {
            $zipFile = $tempDir . '/migrator_package.zip';
            if (!file_exists($zipFile) && file_exists(__DIR__ . '/migrator_package.zip')) {
                $zipFile = __DIR__ . '/migrator_package.zip';
            } else {
                $zips = glob(__DIR__ . '/*.zip');
                if (!empty($zips)) $zipFile = $zips[0];
            }

            if (empty($zipFile) || !file_exists($zipFile)) {
                throw new RuntimeException('Migration ZIP package not found. Please upload your package first.');
            }

            $rawTarget = trim($_POST['target_dir'] ?? __DIR__);
            if (!is_dir($rawTarget)) {
                @mkdir($rawTarget, 0755, true);
            }
            $extractTo = realpath($rawTarget);
            if (!$extractTo || !is_dir($extractTo)) {
                throw new InvalidArgumentException("Invalid target extraction directory: {$rawTarget}");
            }

            $startIndex = (int)($_POST['start_index'] ?? 0);
            $res = ArchiveManager::extractChunk($zipFile, $extractTo, $startIndex, 300);
            echo json_encode($res);
            exit;
        }

        // 4. Impor Database SQL Bertahap
        if ($action === 'import_sql_chunk') {
            $sqlFile = $tempDir . '/migrator_database.sql';
            if (!file_exists($sqlFile) && file_exists(__DIR__ . '/migrator_database.sql')) {
                $sqlFile = __DIR__ . '/migrator_database.sql';
            } elseif (!file_exists($sqlFile)) {
                $sqls = glob(__DIR__ . '/*.sql');
                if (!empty($sqls)) $sqlFile = $sqls[0];
            }

            // Check if extracted to target directory
            if (!file_exists($sqlFile) && !empty($_POST['target_dir'])) {
                $targetDirCheck = realpath($_POST['target_dir']);
                if ($targetDirCheck && file_exists($targetDirCheck . '/migrator_database.sql')) {
                    $sqlFile = $targetDirCheck . '/migrator_database.sql';
                }
            }

            // If not found yet, try extracting from ZIP package
            if (!file_exists($sqlFile)) {
                $zipFile = $tempDir . '/migrator_package.zip';
                if (!file_exists($zipFile) && file_exists(__DIR__ . '/migrator_package.zip')) {
                    $zipFile = __DIR__ . '/migrator_package.zip';
                } else {
                    $zips = glob(__DIR__ . '/*.zip');
                    if (!empty($zips)) $zipFile = $zips[0];
                }
                if (!empty($zipFile) && file_exists($zipFile) && extension_loaded('zip')) {
                    $za = new ZipArchive();
                    if ($za->open($zipFile) === true) {
                        if ($za->locateName('migrator_database.sql') !== false) {
                            $za->extractTo($tempDir, ['migrator_database.sql']);
                            $sqlFile = $tempDir . '/migrator_database.sql';
                        }
                        $za->close();
                    }
                }
            }

            if (empty($sqlFile) || !file_exists($sqlFile)) {
                echo json_encode(['success' => true, 'done' => true, 'message' => 'No SQL database dump found. Database import skipped.']);
                exit;
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

        // Penjelajah Direktori Server untuk Target Folder
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
                        $isPublicHtml = ($item === 'public_html');
                        $isSubdomain = (strpos($item, '.') !== false && !str_starts_with($item, '.'));

                        $tag = 'Folder';
                        $tagClass = 'folder';
                        if ($isPublicHtml) {
                            $tag = 'Web Root';
                            $tagClass = 'wp';
                        } elseif ($isSubdomain) {
                            $tag = 'Subdomain';
                            $tagClass = 'wp';
                        } elseif ($isWp) {
                            $tag = 'WordPress';
                            $tagClass = 'wp';
                        } elseif ($isLaravel) {
                            $tag = 'Laravel';
                            $tagClass = 'laravel';
                        } elseif ($isMigrator) {
                            $tag = 'Migrator';
                            $tagClass = 'migrator';
                        }

                        $folders[] = [
                            'name' => $item,
                            'path' => str_replace('\\', '/', $full),
                            'tag' => $tag,
                            'tag_class' => $tagClass,
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

        // Deteksi CMS & Kredensial di Direktori Target
        if ($action === 'detect_dir') {
            $rawPath = trim($_POST['path'] ?? __DIR__);
            $resolved = realpath($rawPath);
            if (!$resolved || !is_dir($resolved)) {
                echo json_encode([
                    'success' => true,
                    'path' => str_replace('\\', '/', $rawPath),
                    'name' => 'Direktori Baru / Belum Dibuat',
                    'type' => 'generic',
                    'detected' => false,
                    'credentials' => null,
                    'is_migrator' => false,
                ]);
                exit;
            }

            $detect = AutoDetector::detect($resolved);
            $isMigrator = realpath($resolved) === realpath(__DIR__);
            echo json_encode([
                'success' => true,
                'path' => str_replace('\\', '/', $resolved),
                'name' => $detect['name'] ?? 'Folder Website Kustom',
                'type' => $detect['type'] ?? 'generic',
                'detected' => $detect['detected'] ?? false,
                'credentials' => $detect['credentials'] ?? null,
                'is_migrator' => $isMigrator,
            ]);
            exit;
        }

        // 5. Search & Replace Domain dan Auto-Update Konfigurasi
        if ($action === 'search_replace') {
            $host = trim($_POST['host'] ?? 'localhost');
            $port = (int)($_POST['port'] ?? 3306);
            $db   = trim($_POST['database'] ?? '');
            $user = trim($_POST['username'] ?? 'root');
            $pass = $_POST['password'] ?? '';
            $oldUrl = trim($_POST['old_url'] ?? '');
            $newUrl = trim($_POST['new_url'] ?? '');
            $targetDir = realpath($_POST['target_dir'] ?? __DIR__);

            $updatedRows = 0;
            if (!empty($oldUrl) && !empty($newUrl) && $oldUrl !== $newUrl) {
                if (strpos($host, ':') !== false) {
                    [$host, $customPort] = explode(':', $host, 2);
                    $port = (int)$customPort;
                }

                $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                $res = SerializedReplacer::replaceInDatabase($pdo, $oldUrl, $newUrl);
                $updatedRows = $res['updated_rows'];
            }

            // Auto-update wp-config.php / .env
            $updatedConfigs = [];
            $wpConfig = $targetDir . '/wp-config.php';
            if (file_exists($wpConfig)) {
                $c = file_get_contents($wpConfig);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_NAME', '{$db}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_USER', '{$user}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_PASSWORD', '{$pass}');", $c);
                $c = preg_replace('/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\);/', "define('DB_HOST', '{$host}');", $c);
                file_put_contents($wpConfig, $c);
                $updatedConfigs[] = 'wp-config.php';

                // Auto-fix .htaccess & permalinks untuk WordPress
                $htaccessPath = $targetDir . '/.htaccess';
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

            $envPath = $targetDir . '/.env';
            if (file_exists($envPath)) {
                $c = file_get_contents($envPath);
                $c = preg_replace('/^DB_HOST=.*$/m', "DB_HOST={$host}", $c);
                $c = preg_replace('/^DB_DATABASE=.*$/m', "DB_DATABASE={$db}", $c);
                $c = preg_replace('/^DB_USERNAME=.*$/m', "DB_USERNAME={$user}", $c);
                $c = preg_replace('/^DB_PASSWORD=.*$/m', "DB_PASSWORD=\"{$pass}\"", $c);
                if (!empty($newUrl)) {
                    $c = preg_replace('/^APP_URL=.*$/m', "APP_URL={$newUrl}", $c);
                }
                file_put_contents($envPath, $c);
                $updatedConfigs[] = '.env';
            }

            echo json_encode([
                'success' => true,
                'updated_rows' => $updatedRows,
                'updated_configs' => $updatedConfigs,
                'message' => "URL replacement complete! {$updatedRows} database rows updated." . (!empty($updatedConfigs) ? " Configuration (" . implode(', ', $updatedConfigs) . ") updated." : "")
            ]);
            exit;
        }

        // 6. Self-Destruct
        if ($action === 'self_destruct') {
            $filesToDelete = [
                $tempDir . '/migrator_package.zip',
                $tempDir . '/migrator_database.sql',
                __DIR__ . '/migrator_package.zip',
                __DIR__ . '/migrator_database.sql',
            ];
            foreach ($filesToDelete as $f) {
                if (file_exists($f)) @unlink($f);
            }
            echo json_encode(['success' => true, 'message' => 'Migration package and database dump deleted for security.']);
            exit;
        }

        throw new Exception("Unknown action '{$action}'.");
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Check existing packages in directory
$baseDir = realpath(__DIR__);
$parentDir = dirname($baseDir);
$docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : null;

// Smart Hosting Web Root / public_html detection
$publicHtmlPath = null;
if ($parentDir && basename($parentDir) === 'public_html') {
    $publicHtmlPath = $parentDir;
} elseif ($parentDir && is_dir($parentDir . '/public_html')) {
    $publicHtmlPath = realpath($parentDir . '/public_html');
} elseif ($docRoot && (basename($docRoot) === 'public_html' || is_dir($docRoot . '/public_html'))) {
    $publicHtmlPath = (basename($docRoot) === 'public_html') ? $docRoot : realpath($docRoot . '/public_html');
} elseif (basename($baseDir) === 'public_html') {
    $publicHtmlPath = $baseDir;
} elseif (function_exists('get_current_user') && is_dir('/home/' . get_current_user() . '/public_html')) {
    $publicHtmlPath = realpath('/home/' . get_current_user() . '/public_html');
}

// Default target folder: prioritize public_html on hosting, or parentDir, or current baseDir
$defaultTargetDir = $publicHtmlPath ?: ($parentDir && is_dir($parentDir) ? $parentDir : $baseDir);

// Scan for detected website folders / subdomains on server
$detectedFolders = [];
$scanSources = array_unique(array_filter([
    $parentDir,
    $publicHtmlPath,
    $docRoot,
    $defaultTargetDir
]));

foreach ($scanSources as $sDir) {
    if (!$sDir || !is_dir($sDir) || !is_readable($sDir)) continue;
    $items = @scandir($sDir);
    if (!$items) continue;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $full = realpath($sDir . DIRECTORY_SEPARATOR . $item);
        if (!$full || !is_dir($full)) continue;

        $lower = strtolower($item);
        if (in_array($lower, ['cgi-bin', '.well-known', '.cpanel', '.git', 'storage', 'bolt', 'vendor', 'node_modules', 'logs', 'ssl', 'mail'])) {
            continue;
        }

        $fullSlash = str_replace('\\', '/', $full);
        if (isset($detectedFolders[$fullSlash])) continue;

        $isWp = file_exists($full . '/wp-config.php');
        $isLaravel = file_exists($full . '/artisan') && file_exists($full . '/.env');
        $isSubdomain = (strpos($item, '.') !== false && !str_starts_with($item, '.'));
        $isPublicHtml = ($item === 'public_html');
        $isCurrent = (realpath($full) === realpath(__DIR__));

        $tag = 'Folder Website';
        if ($isPublicHtml) {
            $tag = 'Web Root (public_html)';
        } elseif ($isSubdomain) {
            $tag = 'Subdomain Folder';
        } elseif ($isWp) {
            $tag = 'WordPress';
        } elseif ($isLaravel) {
            $tag = 'Laravel';
        }

        $detectedFolders[$fullSlash] = [
            'name' => $item,
            'path' => $fullSlash,
            'tag' => $tag,
            'is_current' => $isCurrent,
            'is_public_html' => $isPublicHtml,
            'is_subdomain' => $isSubdomain,
        ];
    }
}

$existingZip = file_exists($tempDir . '/migrator_package.zip') || file_exists($baseDir . '/migrator_package.zip');
$existingZipName = 'migrator_package.zip';
$existingZipSize = 'Ready';

if (file_exists($tempDir . '/migrator_package.zip')) {
    $existingZipSize = round(filesize($tempDir . '/migrator_package.zip') / 1024 / 1024, 2) . ' MB';
} elseif (file_exists($baseDir . '/migrator_package.zip')) {
    $existingZipSize = round(filesize($baseDir . '/migrator_package.zip') / 1024 / 1024, 2) . ' MB';
} else {
    $zips = glob($baseDir . '/*.zip');
    if (!empty($zips)) {
        $existingZip = true;
        $existingZipName = basename($zips[0]);
        $existingZipSize = round(filesize($zips[0]) / 1024 / 1024, 2) . ' MB';
    }
}

// Auto-detect current URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$detectedHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentPath = dirname($_SERVER['SCRIPT_NAME']);
$currentSiteUrl = rtrim($protocol . $detectedHost . ($currentPath !== '/' && $currentPath !== '\\' ? $currentPath : ''), '/');

$page = 'import';
require 'includes/header.php';
?>

<!-- HERO -->
<section class="hero" style="padding-bottom:32px;">
    <div class="hero-badge">Open Source • MIT License</div>
    <h1>Install &amp; Restore Website</h1>
    <p class="hero-subtitle">Upload a migration package and restore it to this server.</p>

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
        <a href="index.php" class="mode-card">
            <div class="icon">📦</div>
            <div class="title">Create Migration Package</div>
            <div class="desc">Package your website and database into a portable migration bundle.</div>
            <div class="selected-tag">
                <div class="dot"></div>
                <span>Selected</span>
            </div>
        </a>
        <a href="import.php" class="mode-card active">
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

<!-- IMPORT CONFIG UI -->
<div class="page-content" id="importConfig">

    <!-- UPLOAD DROPZONE -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">01</span>
            <span class="section-title">Upload Migration Package</span>
        </div>

        <?php if ($existingZip): ?>
            <div class="upload-state" id="existingPackageBanner" style="margin-bottom:16px;">
                <div class="upload-state-icon success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div class="upload-state-info">
                    <div class="upload-state-name"><?php echo htmlspecialchars($existingZipName); ?></div>
                    <div class="upload-state-meta"><?php echo $existingZipSize; ?> • Package detected on server &amp; ready to install</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="dropzone" id="dropzoneReal" onclick="document.getElementById('realFileInput').click();">
            <div class="dropzone-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
            </div>
            <div class="dropzone-title">Drop your migration package here</div>
            <div class="dropzone-subtext">or click to browse files</div>
            <div class="dropzone-formats">
                <span class="dropzone-format">migrator_package.zip</span>
                <span class="dropzone-format">.sql</span>
            </div>
            <div class="dropzone-note">Maximum upload size depends on your server configuration.</div>
            <input type="file" id="realFileInput" style="display:none;" accept=".zip,.sql">
        </div>

        <!-- Upload states -->
        <div id="uploadUploading" style="display:none;margin-top:16px;">
            <div class="upload-state uploading">
                <div class="upload-state-icon uploading">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </div>
                <div class="upload-state-info">
                    <div class="upload-state-name" id="uploadingFileName">Uploading...</div>
                    <div class="upload-state-meta" id="uploadingFileMeta">Transferring file to server...</div>
                </div>
                <div class="progress-bar-track" style="width:120px;">
                    <div class="progress-bar-fill" id="uploadingProgressFill" style="width:50%;"></div>
                </div>
            </div>
        </div>

        <div id="uploadUploaded" style="display:none;margin-top:16px;">
            <div class="upload-state">
                <div class="upload-state-icon success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div class="upload-state-info">
                    <div class="upload-state-name" id="uploadedFileName">migrator_package.zip</div>
                    <div class="upload-state-meta" id="uploadedFileMeta">Uploaded successfully</div>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" onclick="resetUpload()">Upload Another</button>
            </div>
        </div>
    </div>

    <!-- SECTION 02: Pilihan Paket Impor (Import Scope) -->
    <div class="section">
        <div class="section-header">
            <span class="section-number">02</span>
            <span class="section-title">Pilihan Paket Impor</span>
            <div class="section-subtitle">Tentukan apakah ingin merestore seluruh website beserta database atau hanya salah satunya.</div>
        </div>
        <div class="scope-grid">
            <label class="scope-card import-scope-card active" id="importScopeCardFull">
                <input type="radio" name="importScope" value="full" checked style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📦</span>
                    <span class="scope-badge">Rekomendasi</span>
                </div>
                <div class="scope-title">Restore Lengkap (File + DB + URL)</div>
                <div class="scope-desc">Mengekstrak seluruh berkas website, merestore database MySQL, dan memperbarui URL domain otomatis.</div>
            </label>

            <label class="scope-card import-scope-card" id="importScopeCardFilesOnly">
                <input type="radio" name="importScope" value="files_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">📁</span>
                    <span class="scope-badge info">Tanpa Database</span>
                </div>
                <div class="scope-title">Hanya Berkas Website (Tanpa DB)</div>
                <div class="scope-desc">Hanya mengekstrak berkas website ke direktori tujuan tanpa database MySQL. Cocok jika DB diimpor manual.</div>
            </label>

            <label class="scope-card import-scope-card" id="importScopeCardDbOnly">
                <input type="radio" name="importScope" value="db_only" style="display:none;">
                <div class="scope-card-header">
                    <span class="scope-icon">🗄️</span>
                    <span class="scope-badge warning">SQL Saja</span>
                </div>
                <div class="scope-title">Hanya Database (.SQL Saja)</div>
                <div class="scope-desc">Hanya merestore dump database ke MySQL dan mengganti URL tanpa mengekstrak berkas website.</div>
            </label>
        </div>
    </div>

    <!-- SECTION 03: TARGET DIRECTORY -->
    <div class="section" id="sectionTargetDir">
        <div class="section-header">
            <span class="section-number">03</span>
            <span class="section-title">Direktori Target Instalasi</span>
            <span class="dimmed-badge-overlay" id="targetDirDimmedBadge" style="display:none;">Dilewati (Mode Database Saja)</span>
            <div class="section-subtitle">Tentukan lokasi folder tempat berkas website akan diekstrak di server hosting.</div>
        </div>
        <div class="card">
            <!-- Pilihan Cepat / Preset -->
            <div class="dir-preset-bar">
                <span class="dir-preset-label">Pilihan Cepat:</span>
                <?php if (!empty($publicHtmlPath)): ?>
                <button type="button" class="btn-preset active" id="btnPresetPublicHtml" data-path="<?php echo htmlspecialchars(str_replace('\\', '/', $publicHtmlPath)); ?>" onclick="window.handleImportPresetClick && window.handleImportPresetClick(this.dataset.path, this)" title="Gunakan folder utama public_html di hosting">
                    🌐 public_html (Web Root)
                </button>
                <?php endif; ?>
                <button type="button" class="btn-preset <?php echo empty($publicHtmlPath) ? 'active' : ''; ?>" id="btnPresetCurrentImport" data-path="<?php echo htmlspecialchars(str_replace('\\', '/', $baseDir)); ?>" onclick="window.handleImportPresetClick && window.handleImportPresetClick(this.dataset.path, this)" title="Gunakan direktori kerja migrator saat ini">
                    📁 Folder Saat Ini
                </button>
                <?php if (!empty($parentDir) && $parentDir !== $baseDir && $parentDir !== ($publicHtmlPath ?? '')): ?>
                <button type="button" class="btn-preset" id="btnPresetParentImport" data-path="<?php echo htmlspecialchars(str_replace('\\', '/', $parentDir)); ?>" onclick="window.handleImportPresetClick && window.handleImportPresetClick(this.dataset.path, this)" title="Gunakan direktori induk (..)">
                    ⬆️ Folder Induk (..)
                </button>
                <?php endif; ?>
                <button type="button" class="btn-preset" id="btnOpenFolderBrowserImport" title="Buka jendela visual untuk memilih folder di server">
                    📂 Jelajahi Folder di Server...
                </button>
            </div>

            <!-- Dropdown Folder Proyek / Subdomain yang Ditemukan di Server -->
            <?php if (!empty($detectedFolders)): ?>
            <div class="form-field" style="margin-bottom: 16px;">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>Pilih dari Folder Website / Subdomain yang Terdeteksi di Server:</span>
                    <span style="font-size:11px;color:var(--accent-bright);font-weight:600;"><?php echo count($detectedFolders); ?> Folder Ditemukan</span>
                </label>
                <select id="selectTargetSubfolder" class="form-input mono" onchange="window.handleTargetSubfolderChange && window.handleTargetSubfolderChange(this.value)">
                    <option value="">-- Klik untuk memilih folder target / subdomain lain di server --</option>
                    <?php foreach ($detectedFolders as $f): ?>
                        <option value="<?php echo htmlspecialchars($f['path']); ?>" <?php echo $f['path'] === str_replace('\\', '/', $defaultTargetDir) ? 'selected' : ''; ?>>
                            <?php echo $f['is_public_html'] ? '🌐' : ($f['is_subdomain'] ? '🌍' : '📁'); ?> <?php echo htmlspecialchars($f['name']); ?> [<?php echo htmlspecialchars($f['tag']); ?>]<?php echo $f['is_current'] ? ' (Folder Migrator Ini)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Input Path Aktual -->
            <div class="form-field">
                <label class="form-label">Path Direktori Tujuan di Server (Target Path):</label>
                <div class="form-input-wrap">
                    <span class="form-input-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                        </svg>
                    </span>
                    <input type="text" id="importTargetDir" class="form-input with-icon mono" value="<?php echo htmlspecialchars(str_replace('\\', '/', $defaultTargetDir)); ?>" placeholder="<?php echo htmlspecialchars(str_replace('\\', '/', $defaultTargetDir)); ?>">
                </div>
                <div class="form-helper">Berkas website dari ZIP akan diekstrak langsung ke dalam folder ini (misal: <code>public_html</code> atau folder subdomain).</div>
            </div>
        </div>
    </div>

    <!-- SECTION 04: TARGET DATABASE -->
    <div class="section" id="sectionTargetDb">
        <div class="section-header">
            <span class="section-number">04</span>
            <span class="section-title">Target Koneksi MySQL</span>
            <span class="dimmed-badge-overlay" id="importDbDimmedBadge" style="display:none;">Dilewati (Mode Tanpa Database)</span>
        </div>
        <div class="card">
            <div class="form-grid">
                <div class="form-field">
                    <label class="form-label">MySQL Host</label>
                    <input type="text" id="importDbHost" class="form-input mono" value="localhost" placeholder="localhost">
                </div>
                <div class="form-field">
                    <label class="form-label">Port</label>
                    <input type="text" id="importDbPort" class="form-input mono" value="3306" placeholder="3306">
                </div>
                <div class="form-field">
                    <label class="form-label">Nama Database Baru</label>
                    <input type="text" id="importDbName" class="form-input mono" placeholder="database_name" required>
                </div>
                <div class="form-field">
                    <label class="form-label">Username MySQL</label>
                    <input type="text" id="importDbUser" class="form-input mono" placeholder="db_user" required>
                </div>
                <div class="form-field" style="grid-column: 1 / -1;">
                    <label class="form-label">Password</label>
                    <div class="form-input-wrap">
                        <input type="password" class="form-input with-toggle mono" id="importDbPass" placeholder="••••••••">
                        <button type="button" class="toggle-btn" data-toggle="importDbPass" aria-label="Toggle password visibility">
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
            <div class="form-actions">
                <button type="button" id="btnTestImportDb" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                    </svg>
                    Test Connection
                </button>
            </div>
        </div>
    </div>

    <!-- SECTION 05: DOMAIN REPLACEMENT -->
    <div class="section" id="sectionDomainReplace">
        <div class="section-header">
            <span class="section-number">05</span>
            <span class="section-title">Domain / URL Replacement</span>
            <span class="dimmed-badge-overlay" id="urlReplaceDimmedBadge" style="display:none;">Dilewati (Mode Tanpa Database)</span>
            <div class="section-subtitle">Secara otomatis mengganti URL lama website di database dengan perhitungan ulang panjang serialized data.</div>
        </div>
        <div class="card">
            <div class="form-grid">
                <div class="form-field">
                    <label class="form-label">Domain / URL Lama</label>
                    <input type="text" id="importOldUrl" class="form-input mono" placeholder="https://old-domain.com">
                </div>
                <div class="form-field">
                    <label class="form-label">Domain / URL Baru</label>
                    <input type="text" id="importNewUrl" class="form-input mono" value="<?php echo htmlspecialchars($currentSiteUrl); ?>" placeholder="<?php echo htmlspecialchars($currentSiteUrl); ?>">
                </div>
            </div>

            <div class="url-preview">
                <div class="url-preview-item">
                    <div class="url old" id="urlPreviewOld">old-domain.com</div>
                </div>
                <div class="url-preview-arrow">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                </div>
                <div class="url-preview-item">
                    <div class="url new" id="urlPreviewNew"><?php echo htmlspecialchars($detectedHost); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- IMPORT ACTIONS -->
    <div class="form-actions" style="padding-top:8px;">
        <button type="button" id="btnStartImportReal" class="btn btn-primary btn-lg">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
            Install &amp; Restore
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
                    <span class="terminal-filename">migrator-import-activity.log</span>
                </div>
                <div class="terminal-live">
                    <div class="dot"></div>
                    <span>LIVE CONSOLE</span>
                </div>
            </div>
            <div class="terminal-body" id="mainTerminalBody" style="max-height: 240px; min-height: 150px;">
                <div class="terminal-line"><span class="lnum">01</span><span class="tag ready">[Ready]</span><span class="text"> Website Migrator Restoration Engine v<?php echo MIGRATOR_VERSION; ?> online.</span></div>
                <div class="terminal-line"><span class="lnum">02</span><span class="tag system">[System]</span><span class="text"> Server Target: <?php echo htmlspecialchars(str_replace('\\', '/', $defaultTargetDir)); ?> (<?php echo is_writable($defaultTargetDir) ? 'Writable' : 'Read-only'; ?>)</span></div>
                <div class="terminal-line"><span class="lnum">03</span><span class="tag detector">[Detector]</span><span class="text"> Web Root: <?php echo htmlspecialchars(str_replace('\\', '/', $publicHtmlPath ?? $defaultTargetDir)); ?><?php echo !empty($detectedFolders) ? ' • ' . count($detectedFolders) . ' subfolders/sites detected.' : ''; ?></span></div>
                <div class="terminal-line"><span class="lnum">04</span><span class="tag ready">[Ready]</span><span class="text"> Ready to restore. Select your Import Scope (Full Restore, Files Only, or DB Only) and click Install &amp; Restore.</span></div>
            </div>
        </div>
    </div>

</div>

<!-- IMPORT PROGRESS (Realtime Dynamic) -->
<div id="importProgress" class="page-content" style="display:none;">
    <div class="pipeline">
        <div class="pipeline-steps" id="importPipelineSteps">
            <div class="pipeline-step active" data-step="0">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Extracting</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="1">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Files</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="2">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Database</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="3">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">URL Replace</span>
            </div>
            <div class="pipeline-connector"></div>
            <div class="pipeline-step" data-step="4">
                <div class="pipeline-step-circle"><div class="inner"></div></div>
                <span class="pipeline-step-label">Finalizing</span>
            </div>
        </div>

        <div class="progress-display">
            <div class="progress-percent" id="importPercent">0%</div>
            <div class="progress-text" id="importProgressText">Preparing restoration...</div>
            <div class="progress-sub" id="importProgressSub">Connecting components</div>
        </div>

        <div class="progress-bar-wrap">
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="importProgressBar" style="width:0%;"></div>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;">
        <div class="terminal">
            <div class="terminal-header">
                <div style="display:flex;align-items:center;">
                    <div class="terminal-dots"><span></span><span></span><span></span></div>
                    <span class="terminal-filename">migrator-restore.log</span>
                </div>
                <div class="terminal-live">
                    <div class="dot"></div>
                    <span>LIVE</span>
                </div>
            </div>
            <div class="terminal-body" id="importTerminalBody">
                <div class="terminal-line"><span class="lnum">01</span><span class="tag ready">[Ready]</span><span class="text"> Website Migrator v<?php echo MIGRATOR_VERSION; ?> initialized.</span></div>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT COMPLETE (Real Results) -->
<div id="importComplete" style="display:none;">
    <div class="success-container">
        <div class="success-checkmark">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>
        <div class="success-title">Website Restored Successfully</div>
        <div class="success-desc">Your website migration has been completed without any timeout errors.</div>

        <div class="card" style="text-align:left;margin-bottom:24px;">
            <div class="success-info-row">
                <span class="label">Target Directory</span>
                <span class="value" id="resultTargetDir"><?php echo htmlspecialchars(str_replace('\\', '/', $defaultTargetDir)); ?></span>
            </div>
            <div class="success-info-row">
                <span class="label">Database</span>
                <span class="value" id="resultDatabaseStatus" style="color:var(--success);">Restored &amp; Verified</span>
            </div>
            <div class="success-info-row">
                <span class="label">Target URL</span>
                <span class="value" id="resultTargetUrl"><?php echo htmlspecialchars($currentSiteUrl); ?></span>
            </div>
        </div>

        <!-- POST-MIGRATION CHECKLIST -->
        <div class="card" style="text-align:left;margin-bottom:24px;border-color:rgba(37,99,235,0.3);background:rgba(37,99,235,0.04);">
            <div style="font-weight:700;font-size:14px;color:#fff;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <span>📋 Panduan Pasca-Migrasi (Langkah Selanjutnya)</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;">
                    <span style="width:22px;height:22px;border-radius:50%;background:rgba(34,197,94,0.15);color:var(--success);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0;">1</span>
                    <div>
                        <strong style="color:var(--text-primary);">Uji Tampilan &amp; Navigasi:</strong>
                        <div style="color:var(--text-secondary);font-size:12px;">Buka website baru Anda dan pastikan seluruh gambar, stylesheet, dan navigasi menu termuat sempurna.</div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;">
                    <span style="width:22px;height:22px;border-radius:50%;background:rgba(59,130,246,0.15);color:var(--accent-bright);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0;">2</span>
                    <div>
                        <strong style="color:var(--text-primary);">Simpan Ulang Permalink (Khusus WordPress):</strong>
                        <div style="color:var(--text-secondary);font-size:12px;">Buka Dashboard Admin WordPress &rarr; <em>Settings &rarr; Permalinks</em>, lalu klik tombol <strong>Save Changes</strong> untuk memastikan tidak ada URL artikel yang error 404.</div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px;">
                    <span style="width:22px;height:22px;border-radius:50%;background:rgba(239,68,68,0.15);color:var(--error);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0;">3</span>
                    <div>
                        <strong style="color:var(--text-primary);">Jalankan Self-Destruct (Keamanan Server):</strong>
                        <div style="color:var(--text-secondary);font-size:12px;">Hapus arsip paket ZIP dan dump database menggunakan tombol di bawah agar server Anda bersih dan aman dari akses luar.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="success-actions">
            <a href="<?php echo htmlspecialchars($currentSiteUrl); ?>" id="btnOpenWebsite" class="btn btn-primary btn-lg" target="_blank">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                Open Website
            </a>
            <button type="button" id="btnSelfDestructReal" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Delete Package (Self-Destruct)
            </button>
            <button type="button" class="btn btn-ghost" onclick="location.reload();">Run Another Migration</button>
        </div>
    </div>
</div>

<!-- FOLDER BROWSER MODAL -->
<div class="modal-backdrop" id="folderBrowserModal" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">📂</span>
                <div>
                    <div style="font-weight:600;font-size:15px;color:var(--text-primary);">Penjelajah Folder Server (Directory Browser)</div>
                    <div style="font-size:12px;color:var(--text-muted);">Pilih folder tujuan instalasi &amp; ekstraksi website di server</div>
                </div>
            </div>
            <button type="button" class="modal-close" id="btnCloseFolderBrowser" aria-label="Tutup">&times;</button>
        </div>

        <div class="modal-breadcrumb" id="browserBreadcrumb">
            <span>Root Server</span> &rsaquo; <span class="crumb-active" id="browserCurrentPath"><?php echo htmlspecialchars(str_replace('\\', '/', $defaultTargetDir)); ?></span>
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
