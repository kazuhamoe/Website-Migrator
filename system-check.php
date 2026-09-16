<?php
define('MIGRATOR_INIT', true);
require_once __DIR__ . '/app/SystemCheck.php';

SystemCheck::optimizeLimits();
$sysCheck = SystemCheck::check(__DIR__);

$page = 'system';
require 'includes/header.php';
?>

<div class="system-check-hero">
    <h1>System Readiness</h1>
    <p>Realtime diagnostic of the server environment required by Website Migrator.</p>
    <div class="system-status-banner">
        <div class="dot" style="<?php echo !$sysCheck['success'] ? 'background:#ef4444;box-shadow:0 0 8px rgba(239,68,68,0.5);' : ''; ?>"></div>
        <span><?php echo $sysCheck['success'] ? 'System Ready' : 'Attention Required'; ?></span>
        <span class="sub">— <?php echo $sysCheck['success'] ? 'All required components are available.' : 'Some requirements need your attention.'; ?></span>
    </div>
</div>

<div class="page-content">
    <div class="check-list">
        <!-- PHP Version -->
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon ready">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <div>
                    <div class="check-item-label">PHP Version</div>
                    <div class="check-item-value"><?php echo PHP_VERSION; ?> (Required >= 7.4.0)</div>
                </div>
            </div>
            <span class="status-badge ready">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Ready
            </span>
        </div>

        <!-- ZIP Extension -->
        <?php $zipOk = extension_loaded('zip'); ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon <?php echo $zipOk ? 'ready' : 'warning'; ?>">
                    <?php if ($zipOk): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="check-item-label">ZIP / ZipArchive</div>
                    <div class="check-item-value"><?php echo $zipOk ? 'Extension loaded & ready' : 'Extension not loaded'; ?></div>
                </div>
            </div>
            <span class="status-badge <?php echo $zipOk ? 'ready' : 'warning'; ?>">
                <?php if ($zipOk): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Ready
                <?php else: ?>
                    Disabled
                <?php endif; ?>
            </span>
        </div>

        <!-- PDO MySQL -->
        <?php $pdoOk = extension_loaded('pdo_mysql'); ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon <?php echo $pdoOk ? 'ready' : 'warning'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">PDO MySQL</div>
                    <div class="check-item-value"><?php echo $pdoOk ? 'Driver available & active' : 'Driver unavailable'; ?></div>
                </div>
            </div>
            <span class="status-badge <?php echo $pdoOk ? 'ready' : 'warning'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo $pdoOk ? 'Ready' : 'Disabled'; ?>
            </span>
        </div>

        <!-- MySQLi -->
        <?php $mysqliOk = extension_loaded('mysqli'); ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon <?php echo $mysqliOk ? 'ready' : 'warning'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">MySQLi</div>
                    <div class="check-item-value"><?php echo $mysqliOk ? 'Extension loaded' : 'Extension not loaded'; ?></div>
                </div>
            </div>
            <span class="status-badge <?php echo $mysqliOk ? 'ready' : 'warning'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo $mysqliOk ? 'Ready' : 'Disabled'; ?>
            </span>
        </div>

        <!-- JSON -->
        <?php $jsonOk = extension_loaded('json'); ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon <?php echo $jsonOk ? 'ready' : 'warning'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">JSON Extension</div>
                    <div class="check-item-value"><?php echo $jsonOk ? 'Extension loaded' : 'Disabled'; ?></div>
                </div>
            </div>
            <span class="status-badge <?php echo $jsonOk ? 'ready' : 'warning'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Ready
            </span>
        </div>

        <!-- File Permissions -->
        <?php $writable = is_writable(__DIR__); ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon <?php echo $writable ? 'ready' : 'warning'; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">File Permissions</div>
                    <div class="check-item-value"><?php echo $writable ? 'Target directory writable (0755 / 0777)' : 'Read-only directory'; ?></div>
                </div>
            </div>
            <span class="status-badge <?php echo $writable ? 'ready' : 'warning'; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo $writable ? 'Ready' : 'Read-Only'; ?>
            </span>
        </div>

        <!-- Max Upload Size -->
        <?php $uploadMax = ini_get('upload_max_filesize') ?: 'N/A'; $postMax = ini_get('post_max_size') ?: 'N/A'; ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon ready">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">Maximum Upload Size</div>
                    <div class="check-item-value">Upload: <?php echo $uploadMax; ?> • POST: <?php echo $postMax; ?></div>
                </div>
            </div>
            <span class="status-badge ready">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Ready
            </span>
        </div>

        <!-- Max Execution Time -->
        <?php $maxTime = ini_get('max_execution_time') ?: 'N/A'; ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon ready">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">Maximum Execution Time</div>
                    <div class="check-item-value"><?php echo $maxTime; ?> seconds (AJAX batching active)</div>
                </div>
            </div>
            <span class="status-badge ready">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Ready
            </span>
        </div>

        <!-- Memory Limit -->
        <?php $memLimit = ini_get('memory_limit') ?: 'N/A'; ?>
        <div class="check-item">
            <div class="check-item-info">
                <div class="check-item-icon ready">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="check-item-label">Memory Limit</div>
                    <div class="check-item-value"><?php echo $memLimit; ?></div>
                </div>
            </div>
            <span class="status-badge ready">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Ready
            </span>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
