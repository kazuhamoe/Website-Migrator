<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * AutoDetector: Deteksi Otomatis CMS & Framework
 * Memindai direktori sumber untuk membaca konfigurasi database secara otomatis.
 */
class AutoDetector
{
    /**
     * Memindai direktori dan mengembalikan konfigurasi proyek yang terdeteksi.
     */
    public static function detect(string $dir): array
    {
        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir)) {
            return [
                'type' => 'unknown',
                'name' => 'Direktori Tidak Valid',
                'detected' => false,
                'credentials' => null,
                'config_file' => null
            ];
        }

        // 1. Cek WordPress (wp-config.php)
        $wpConfig = $dir . '/wp-config.php';
        if (!file_exists($wpConfig) && file_exists(dirname($dir) . '/wp-config.php') && file_exists($dir . '/wp-settings.php')) {
            $wpConfig = dirname($dir) . '/wp-config.php';
        }

        if (file_exists($wpConfig)) {
            $creds = self::parseWordPressConfig($wpConfig);
            if ($creds) {
                return [
                    'type' => 'wordpress',
                    'name' => 'WordPress',
                    'icon' => 'wordpress',
                    'detected' => true,
                    'credentials' => $creds,
                    'config_file' => $wpConfig
                ];
            }
        }

        // 2. Cek Laravel (.env dan artisan)
        $laravelEnv = $dir . '/.env';
        $laravelArtisan = $dir . '/artisan';
        if (file_exists($laravelEnv) && file_exists($laravelArtisan)) {
            $creds = self::parseLaravelEnv($laravelEnv);
            if ($creds) {
                return [
                    'type' => 'laravel',
                    'name' => 'Laravel Framework',
                    'icon' => 'laravel',
                    'detected' => true,
                    'credentials' => $creds,
                    'config_file' => $laravelEnv
                ];
            }
        }

        // 3. Cek CodeIgniter (CI 4: app/Config/Database.php atau CI 3: application/config/database.php)
        $ci4Config = $dir . '/app/Config/Database.php';
        $ci3Config = $dir . '/application/config/database.php';
        if (file_exists($ci4Config)) {
            $creds = self::parseCodeIgniter4($ci4Config, $dir . '/.env');
            return [
                'type' => 'codeigniter4',
                'name' => 'CodeIgniter 4',
                'icon' => 'codeigniter',
                'detected' => true,
                'credentials' => $creds,
                'config_file' => $ci4Config
            ];
        } elseif (file_exists($ci3Config)) {
            $creds = self::parseCodeIgniter3($ci3Config);
            return [
                'type' => 'codeigniter3',
                'name' => 'CodeIgniter 3',
                'icon' => 'codeigniter',
                'detected' => true,
                'credentials' => $creds,
                'config_file' => $ci3Config
            ];
        }

        // 4. Default Generic PHP Website
        return [
            'type' => 'generic',
            'name' => 'PHP Native / Custom Website',
            'icon' => 'php',
            'detected' => false,
            'credentials' => [
                'host' => 'localhost',
                'database' => '',
                'username' => 'root',
                'password' => '',
                'prefix' => '',
                'site_url' => ''
            ],
            'config_file' => null
        ];
    }

    /**
     * Mem-parsing konstanta DB dari file wp-config.php tanpa mengeksekusinya.
     */
    private static function parseWordPressConfig(string $filePath): ?array
    {
        $content = @file_get_contents($filePath);
        if (!$content) return null;

        $dbName = self::extractPhpDefine($content, 'DB_NAME');
        $dbUser = self::extractPhpDefine($content, 'DB_USER');
        $dbPass = self::extractPhpDefine($content, 'DB_PASSWORD');
        $dbHost = self::extractPhpDefine($content, 'DB_HOST') ?: 'localhost';

        // Prefix tabel ($table_prefix = 'wp_';)
        $prefix = 'wp_';
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
            $prefix = $m[1];
        }

        // Site URL jika didefinisikan (WP_SITEURL atau WP_HOME)
        $siteUrl = self::extractPhpDefine($content, 'WP_SITEURL') ?: self::extractPhpDefine($content, 'WP_HOME');

        if ($dbName !== null) {
            return [
                'host' => $dbHost,
                'database' => $dbName,
                'username' => $dbUser ?? 'root',
                'password' => $dbPass ?? '',
                'prefix' => $prefix,
                'site_url' => $siteUrl ?? ''
            ];
        }

        return null;
    }

    /**
     * Mem-parsing variabel lingkungan dari file .env Laravel.
     */
    private static function parseLaravelEnv(string $filePath): ?array
    {
        $content = @file_get_contents($filePath);
        if (!$content) return null;

        $lines = explode("\n", $content);
        $env = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            if (strpos($line, '=') !== false) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // Bersihkan tanda kutip
                $val = trim($val, "'\"");
                $env[$key] = $val;
            }
        }

        return [
            'host' => $env['DB_HOST'] ?? 'localhost',
            'port' => $env['DB_PORT'] ?? '3306',
            'database' => $env['DB_DATABASE'] ?? '',
            'username' => $env['DB_USERNAME'] ?? 'root',
            'password' => $env['DB_PASSWORD'] ?? '',
            'prefix' => '',
            'site_url' => $env['APP_URL'] ?? ''
        ];
    }

    /**
     * Mem-parsing konfigurasi database CodeIgniter 3.
     */
    private static function parseCodeIgniter3(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        $host = 'localhost';
        $user = 'root';
        $pass = '';
        $db = '';
        $prefix = '';

        if (preg_match('/[\'"]hostname[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $host = $m[1];
        if (preg_match('/[\'"]username[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $user = $m[1];
        if (preg_match('/[\'"]password[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $pass = $m[1];
        if (preg_match('/[\'"]database[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $db = $m[1];
        if (preg_match('/[\'"]dbprefix[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $prefix = $m[1];

        return [
            'host' => $host,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix' => $prefix,
            'site_url' => ''
        ];
    }

    /**
     * Mem-parsing konfigurasi database CodeIgniter 4.
     */
    private static function parseCodeIgniter4(string $dbPath, string $envPath): array
    {
        // Cek .env terlebih dahulu jika ada
        if (file_exists($envPath)) {
            $content = @file_get_contents($envPath);
            if ($content) {
                $creds = [];
                if (preg_match('/database\.default\.hostname\s*=\s*([^\r\n]+)/i', $content, $m)) $creds['host'] = trim($m[1], "'\" ");
                if (preg_match('/database\.default\.database\s*=\s*([^\r\n]+)/i', $content, $m)) $creds['database'] = trim($m[1], "'\" ");
                if (preg_match('/database\.default\.username\s*=\s*([^\r\n]+)/i', $content, $m)) $creds['username'] = trim($m[1], "'\" ");
                if (preg_match('/database\.default\.password\s*=\s*([^\r\n]+)/i', $content, $m)) $creds['password'] = trim($m[1], "'\" ");
                if (!empty($creds['database'])) {
                    return array_merge([
                        'host' => 'localhost',
                        'database' => '',
                        'username' => 'root',
                        'password' => '',
                        'prefix' => '',
                        'site_url' => ''
                    ], $creds);
                }
            }
        }

        // Fallback baca dari Database.php
        $content = @file_get_contents($dbPath);
        $host = 'localhost';
        $user = 'root';
        $pass = '';
        $db = '';

        if (preg_match('/[\'"]hostname[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $host = $m[1];
        if (preg_match('/[\'"]username[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $user = $m[1];
        if (preg_match('/[\'"]password[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $pass = $m[1];
        if (preg_match('/[\'"]database[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $db = $m[1];

        return [
            'host' => $host,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix' => '',
            'site_url' => ''
        ];
    }

    /**
     * Mengekstrak nilai define('KEY', 'VALUE') dari string kode PHP.
     */
    private static function extractPhpDefine(string $content, string $key): ?string
    {
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)\s*;/i';
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
