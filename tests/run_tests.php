<?php
/**
 * Website Migrator - Automated Test Suite
 * Run with: php tests/run_tests.php
 */

define('MIGRATOR_INIT', true);

require_once __DIR__ . '/../app/SystemCheck.php';
require_once __DIR__ . '/../app/AutoDetector.php';
require_once __DIR__ . '/../app/DatabaseDumper.php';
require_once __DIR__ . '/../app/DatabaseRestorer.php';
require_once __DIR__ . '/../app/SerializedReplacer.php';
require_once __DIR__ . '/../app/ArchiveManager.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function it(string $description, callable $test) {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    try {
        $test();
        $passedTests++;
        echo "  \033[32m✔\033[0m {$description}\n";
    } catch (Throwable $e) {
        $failedTests++;
        echo "  \033[31m✖\033[0m {$description}\n";
        echo "    \033[33mError: " . $e->getMessage() . "\033[0m\n";
    }
}

function assertEquals($expected, $actual, string $msg = '') {
    if ($expected !== $actual) {
        throw new Exception("Assertion failed: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". {$msg}");
    }
}

function assertTrue($condition, string $msg = '') {
    if ($condition !== true) {
        throw new Exception("Assertion failed: Expected true, got " . var_export($condition, true) . ". {$msg}");
    }
}

echo "\n\033[1;36m====================================================\033[0m\n";
echo "\033[1;36m   WEBSITE MIGRATOR - AUTOMATED TEST RUNNER        \033[0m\n";
echo "\033[1;36m====================================================\033[0m\n\n";

// -------------------------------------------------------------
// 1. SYSTEM CHECK & PRE-FLIGHT
// -------------------------------------------------------------
echo "\033[1;34m[1] SystemCheck Test Suite\033[0m\n";

it('memverifikasi kesiapan PHP dan ekstensi dasar', function() {
    $res = SystemCheck::check();
    assertTrue($res['success'], 'SystemCheck harus mengembalikan success');
    assertTrue(is_array($res['requirements']), 'Requirements harus bertipe array');
});

// -------------------------------------------------------------
// 2. AUTO-DETECTOR
// -------------------------------------------------------------
echo "\n\033[1;34m[2] AutoDetector Test Suite\033[0m\n";

it('mendeteksi direktori WordPress secara akurat', function() {
    $tempWp = sys_get_temp_dir() . '/wp_test_' . uniqid();
    @mkdir($tempWp, 0777, true);

    $wpConfig = "<?php\n"
              . "define('DB_NAME', 'wp_migrator_db');\n"
              . "define('DB_USER', 'wp_user');\n"
              . "define('DB_PASSWORD', 'wp_secret');\n"
              . "define('DB_HOST', '127.0.0.1:3306');\n"
              . "\$table_prefix = 'wp_mig_';\n";
    file_put_contents($tempWp . '/wp-config.php', $wpConfig);

    $detect = AutoDetector::detect($tempWp);
    assertEquals('wordpress', $detect['type']);
    assertEquals('wp_migrator_db', $detect['credentials']['database']);
    assertEquals('wp_user', $detect['credentials']['username']);
    assertEquals('wp_mig_', $detect['credentials']['prefix']);

    @unlink($tempWp . '/wp-config.php');
    @rmdir($tempWp);
});

it('mendeteksi konfigurasi .env proyek Laravel', function() {
    $tempLaravel = sys_get_temp_dir() . '/laravel_test_' . uniqid();
    @mkdir($tempLaravel, 0777, true);
    file_put_contents($tempLaravel . '/artisan', '#!/usr/bin/env php');
    $envContent = "APP_NAME=LaravelMigrate\n"
                . "APP_URL=http://old-laravel.test\n"
                . "DB_CONNECTION=mysql\n"
                . "DB_HOST=127.0.0.1\n"
                . "DB_PORT=3306\n"
                . "DB_DATABASE=laravel_prod\n"
                . "DB_USERNAME=forge\n"
                . "DB_PASSWORD=\"laravel_pass\"\n";
    file_put_contents($tempLaravel . '/.env', $envContent);

    $detect = AutoDetector::detect($tempLaravel);
    assertEquals('laravel', $detect['type']);
    assertEquals('laravel_prod', $detect['credentials']['database']);
    assertEquals('forge', $detect['credentials']['username']);
    assertEquals('laravel_pass', $detect['credentials']['password']);

    @unlink($tempLaravel . '/artisan');
    @unlink($tempLaravel . '/.env');
    @rmdir($tempLaravel);
});

// -------------------------------------------------------------
// 3. SERIALIZED REPLACER (CRITICAL WORDPRESS TEST)
// -------------------------------------------------------------
echo "\n\033[1;34m[3] SerializedReplacer Test Suite\033[0m\n";

it('mengganti string biasa tanpa korupsi', function() {
    $res = SerializedReplacer::replace('Hello from http://old.com/site', 'http://old.com', 'https://newdomain.com');
    assertEquals('Hello from https://newdomain.com/site', $res);
});

it('mengganti string ter-serialisasi PHP dan menghitung ulang byte length dengan tepat', function() {
    $data = [
        'site_url' => 'http://old.com',
        'admin_site' => 'http://old.com/author',
        'options' => [
            'home' => 'http://old.com/sub',
            'status' => 'active'
        ]
    ];
    $serialized = serialize($data);

    // http://old.com (length 14) diganti dengan https://verylongnewdomain.co.id/portal (length 38)
    $replaced = SerializedReplacer::replace($serialized, 'http://old.com', 'https://verylongnewdomain.co.id/portal');

    // Pastikan hasil adalah string serialized valid yang dapat di-unserialize kembali tanpa error!
    $unserialized = @unserialize($replaced);
    assertTrue($unserialized !== false, 'Hasil penggantian serialized tidak boleh rusak saat di-unserialize!');
    assertEquals('https://verylongnewdomain.co.id/portal', $unserialized['site_url']);
    assertEquals('https://verylongnewdomain.co.id/portal/sub', $unserialized['options']['home']);
    assertEquals('https://verylongnewdomain.co.id/portal/author', $unserialized['admin_site']);
});

it('mengganti nilai di dalam string JSON valid', function() {
    $json = json_encode(['theme_url' => 'http://old.com/assets', 'active' => true]);
    $replaced = SerializedReplacer::replace($json, 'http://old.com', 'https://newdomain.com');
    $decoded = json_decode($replaced, true);
    assertEquals('https://newdomain.com/assets', $decoded['theme_url']);
    assertEquals(true, $decoded['active']);
});

// -------------------------------------------------------------
// 4. ARCHIVE MANAGER (ZIP PACK & CHUNKED EXTRACT)
// -------------------------------------------------------------
echo "\n\033[1;34m[4] ArchiveManager Test Suite\033[0m\n";

it('mengompres direktori dengan mematuhi daftar pengecualian (exclude list)', function() {
    $sourceDir = sys_get_temp_dir() . '/zip_src_' . uniqid();
    @mkdir($sourceDir . '/sub', 0777, true);
    @mkdir($sourceDir . '/.git', 0777, true);
    @mkdir($sourceDir . '/node_modules', 0777, true);

    file_put_contents($sourceDir . '/index.html', '<h1>Test Site</h1>');
    file_put_contents($sourceDir . '/sub/test.txt', 'Content test');
    file_put_contents($sourceDir . '/.git/HEAD', 'ref: refs/heads/main');
    file_put_contents($sourceDir . '/node_modules/package.json', '{}');

    $zipFile = sys_get_temp_dir() . '/test_pack_' . uniqid() . '.zip';
    $res = ArchiveManager::createZip($sourceDir, $zipFile);

    assertTrue($res['success']);
    assertTrue(file_exists($zipFile));

    // Verifikasi bahwa .git dan node_modules tidak ada dalam ZIP
    $zip = new ZipArchive();
    $zip->open($zipFile);
    assertEquals(2, $zip->numFiles, 'Hanya 2 file non-excluded yang boleh masuk ke ZIP');
    $zip->close();

    // Uji ekstraksi bertahap (chunked extract)
    $extractDir = sys_get_temp_dir() . '/zip_out_' . uniqid();
    @mkdir($extractDir, 0777, true);

    $chunk1 = ArchiveManager::extractChunk($zipFile, $extractDir, 0, 1);
    assertEquals(1, $chunk1['extracted_chunk']);
    assertEquals(false, $chunk1['done']);

    $chunk2 = ArchiveManager::extractChunk($zipFile, $extractDir, 1, 10);
    assertEquals(1, $chunk2['extracted_chunk']);
    assertEquals(true, $chunk2['done']);

    assertTrue(file_exists($extractDir . '/index.html'));
    assertTrue(file_exists($extractDir . '/sub/test.txt'));

    // Cleanup
    @unlink($zipFile);
    @unlink($sourceDir . '/index.html');
    @unlink($sourceDir . '/sub/test.txt');
    @unlink($sourceDir . '/.git/HEAD');
    @unlink($sourceDir . '/node_modules/package.json');
    @rmdir($sourceDir . '/sub');
    @rmdir($sourceDir . '/.git');
    @rmdir($sourceDir . '/node_modules');
    @rmdir($sourceDir);
    @unlink($extractDir . '/index.html');
    @unlink($extractDir . '/sub/test.txt');
    @rmdir($extractDir . '/sub');
    @rmdir($extractDir);
});

// -------------------------------------------------------------
// 5. DATABASE DUMPER & RESTORER (MYSQL INTEGRATION TEST)
// -------------------------------------------------------------
echo "\n\033[1;34m[5] DatabaseDumper & DatabaseRestorer Integration Test\033[0m\n";

try {
    $pdoTest = new PDO('mysql:host=localhost;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoTest->exec("CREATE DATABASE IF NOT EXISTS `migrator_test_db` CHARACTER SET utf8mb4");
    $pdoTest->exec("USE `migrator_test_db`");

    // Buat tabel sample
    $pdoTest->exec("CREATE TABLE IF NOT EXISTS `sample_posts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `content` TEXT,
        `meta_serialized` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Insert dummy data dengan serialized string
    $dummySerialized = serialize(['author_url' => 'http://source-site.com', 'views' => 1250]);
    $insertStmt = $pdoTest->prepare("INSERT INTO `sample_posts` (`title`, `content`, `meta_serialized`) VALUES (?, ?, ?)");
    $insertStmt->execute(['Artikel Pertama', 'Kunjungi kami di http://source-site.com hari ini!', $dummySerialized]);
    $insertStmt->execute(['Artikel Kedua 🎉', 'Emoji test & symbols: <>&"', serialize(['link' => 'http://source-site.com/about'])]);

    it('mengekspor tabel dan data database secara streaming ke file SQL', function() {
        $dumper = new DatabaseDumper([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'migrator_test_db',
            'username' => 'root',
            'password' => ''
        ]);

        $sqlPath = sys_get_temp_dir() . '/test_dump_' . uniqid() . '.sql';
        $res = $dumper->dump($sqlPath);

        assertTrue($res['success']);
        assertTrue($res['tables'] >= 1);
        assertTrue($res['rows'] >= 2);
        assertTrue(file_exists($sqlPath));

        // Test Restorer
        $restorer = new DatabaseRestorer([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'migrator_test_db',
            'username' => 'root',
            'password' => ''
        ]);

        $importRes = $restorer->importChunk($sqlPath, 0);
        assertTrue($importRes['success']);
        assertTrue($importRes['done']);

        // Test SerializedReplacer di dalam database
        $pdo = new PDO('mysql:host=localhost;dbname=migrator_test_db;charset=utf8mb4', 'root', '');
        $srRes = SerializedReplacer::replaceInDatabase($pdo, 'http://source-site.com', 'https://target-domain.org');
        assertTrue($srRes['updated_rows'] > 0);

        // Verifikasi hasil penggantian di dalam DB
        $checkStmt = $pdo->query("SELECT * FROM `sample_posts` WHERE id = 1");
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
        assertTrue(str_contains($row['content'], 'https://target-domain.org'));

        $unser = unserialize($row['meta_serialized']);
        assertTrue($unser !== false, 'Serialized data di MySQL tidak boleh korup!');
        assertEquals('https://target-domain.org', $unser['author_url']);

        @unlink($sqlPath);
    });

    // Drop test database setelah selesai
    $pdoTest->exec("DROP DATABASE `migrator_test_db`");

} catch (Throwable $e) {
    echo "  \033[33m[Notice] MySQL integration test skipped or note: " . $e->getMessage() . "\033[0m\n";
}

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n\033[1;36m====================================================\033[0m\n";
echo "Hasil Pengujian: \033[32m{$passedTests} Lulus\033[0m, \033[" . ($failedTests > 0 ? "31" : "32") . "m{$failedTests} Gagal\033[0m dari {$totalTests} Pengujian.\n";
echo "\033[1;36m====================================================\033[0m\n\n";

exit($failedTests > 0 ? 1 : 0);
