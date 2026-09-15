<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * System Compatibility & Environment Checker
 * Memeriksa kesiapan lingkungan hosting untuk proses pemaketan dan instalasi.
 */
class SystemCheck
{
    /**
     * Menjalankan diagnosa lengkap sistem server.
     */
    public static function check(string $targetDir = __DIR__ . '/..'): array
    {
        $requirements = [];
        $allPassed = true;

        // 1. PHP Version >= 7.4
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '7.4.0', '>=');
        $requirements[] = [
            'name' => 'Versi PHP',
            'required' => '>= 7.4.0',
            'current' => $phpVersion,
            'passed' => $phpOk,
            'critical' => true,
            'message' => $phpOk ? 'Versi PHP kompatibel.' : 'Disarankan upgrade ke PHP 7.4 atau lebih tinggi.'
        ];
        if (!$phpOk) $allPassed = false;

        // 2. Ekstensi ZIP
        $zipOk = extension_loaded('zip');
        $requirements[] = [
            'name' => 'Ekstensi ZIP (ZipArchive)',
            'required' => 'Aktif',
            'current' => $zipOk ? 'Aktif' : 'Tidak Aktif',
            'passed' => $zipOk,
            'critical' => true,
            'message' => $zipOk ? 'Ekstensi ZIP siap digunakan.' : 'Ekstensi PHP zip wajib aktif untuk mengompres dan mengekstrak berkas.'
        ];
        if (!$zipOk) $allPassed = false;

        // 3. Ekstensi Database (PDO MySQL / MySQLi)
        $pdoOk = extension_loaded('pdo_mysql');
        $mysqliOk = extension_loaded('mysqli');
        $dbExtOk = $pdoOk || $mysqliOk;
        $requirements[] = [
            'name' => 'Ekstensi MySQL (PDO / MySQLi)',
            'required' => 'Salah satu aktif',
            'current' => $pdoOk ? 'PDO MySQL Aktif' : ($mysqliOk ? 'MySQLi Aktif' : 'Tidak Aktif'),
            'passed' => $dbExtOk,
            'critical' => true,
            'message' => $dbExtOk ? 'Ekstensi database tersedia.' : 'Wajib mengaktifkan pdo_mysql atau mysqli untuk backup database.'
        ];
        if (!$dbExtOk) $allPassed = false;

        // 4. Ekstensi JSON
        $jsonOk = extension_loaded('json');
        $requirements[] = [
            'name' => 'Ekstensi JSON',
            'required' => 'Aktif',
            'current' => $jsonOk ? 'Aktif' : 'Tidak Aktif',
            'passed' => $jsonOk,
            'critical' => true,
            'message' => $jsonOk ? 'JSON siap digunakan.' : 'Ekstensi json wajib aktif untuk respon API.'
        ];
        if (!$jsonOk) $allPassed = false;

        // 5. Izin Tulis Direktori
        $isWritable = is_writable($targetDir);
        $requirements[] = [
            'name' => 'Izin Tulis Direktori',
            'required' => 'Writable (0755 / 0777)',
            'current' => $isWritable ? 'Dapat Ditulis (Writable)' : 'Hanya Baca (Read-Only)',
            'passed' => $isWritable,
            'critical' => true,
            'message' => $isWritable ? 'Direktori dapat ditulis.' : 'Pastikan direktori memiliki izin tulis (chmod 0755/0777).'
        ];
        if (!$isWritable) $allPassed = false;

        // 6. Memory Limit & Max Execution Time
        $memoryLimit = ini_get('memory_limit') ?: 'N/A';
        $maxTime = ini_get('max_execution_time') ?: 'N/A';

        return [
            'success' => $allPassed,
            'php_version' => $phpVersion,
            'memory_limit' => $memoryLimit,
            'max_execution_time' => $maxTime,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown Web Server',
            'requirements' => $requirements
        ];
    }

    /**
     * Mencoba menaikkan limit runtime untuk operasi besar.
     */
    public static function optimizeLimits(): void
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }
}
