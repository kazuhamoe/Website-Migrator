<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * Chunked & Resumable MySQL Database Restorer
 * Mengimpor berkas SQL secara bertahap (chunked) berbasis byte offset
 * untuk mencegah timeout `max_execution_time` di hosting shared/cPanel.
 */
class DatabaseRestorer
{
    private array $config;
    private ?PDO $pdo = null;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'chunk_statements' => 300,
            'chunk_time_limit' => 12, // Maksimal 12 detik per AJAX request
        ], $config);
    }

    /**
     * Membuat koneksi PDO ke MySQL.
     */
    private function connect(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->config['host'];
        $port = $this->config['port'] ?? 3306;
        $db = $this->config['database'];
        $charset = $this->config['charset'] ?? 'utf8mb4';

        if (strpos($host, ':') !== false) {
            [$host, $customPort] = explode(':', $host, 2);
            $port = (int)$customPort;
        }

        // Cek apakah database ada, jika belum coba buat
        $dsnNoDb = "mysql:host={$host};port={$port};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        try {
            $pdoRoot = new PDO($dsnNoDb, $this->config['username'], $this->config['password'], $options);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // User mungkin tidak punya hak CREATE DATABASE di cPanel, lanjutkan koneksi biasa
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
        $this->pdo->exec("SET NAMES {$charset}");
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->pdo->exec("SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\"");

        return $this->pdo;
    }

    /**
     * Mengimpor potongan SQL dari file berdasarkan byte offset.
     *
     * @param string $sqlFilePath Lokasi berkas SQL
     * @param int $offset Posisi byte pembacaan sebelumnya (0 jika baru mulai)
     * @return array Status eksekusi chunk
     */
    public function importChunk(string $sqlFilePath, int $offset = 0): array
    {
        if (!file_exists($sqlFilePath)) {
            throw new InvalidArgumentException("Berkas database SQL tidak ditemukan: {$sqlFilePath}");
        }

        $pdo = $this->connect();
        $fileSize = filesize($sqlFilePath);
        $fp = fopen($sqlFilePath, 'r');
        if (!$fp) {
            throw new RuntimeException("Gagal membuka berkas SQL.");
        }

        if ($offset > 0) {
            fseek($fp, $offset);
        }

        $startTime = microtime(true);
        $maxTime = (float)$this->config['chunk_time_limit'];
        $maxStatements = (int)$this->config['chunk_statements'];

        $statementCount = 0;
        $currentQuery = '';
        $inString = false;
        $stringChar = '';
        $isDone = false;

        while (!feof($fp)) {
            $line = fgets($fp);
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            // Lewati baris komentar jika tidak sedang di dalam string
            if (!$inString && (empty($trimmed) || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*'))) {
                continue;
            }

            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $char = $line[$i];
                $prevChar = ($i > 0) ? $line[$i - 1] : '';

                // Handle single / double quotes
                if (($char === "'" || $char === '"') && $prevChar !== '\\') {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $char;
                    } elseif ($char === $stringChar) {
                        $inString = false;
                    }
                }

                $currentQuery .= $char;

                // Akhir dari statement SQL (titik koma di luar string)
                if ($char === ';' && !$inString) {
                    $queryToRun = trim($currentQuery);
                    if (!empty($queryToRun)) {
                        try {
                            $pdo->exec($queryToRun);
                            $statementCount++;
                        } catch (Throwable $e) {
                            // Abaikan error drop non-fatal
                            if (stripos($queryToRun, 'DROP TABLE') === false && stripos($queryToRun, 'DROP VIEW') === false) {
                                // Lempar error jika query penting gagal
                                fclose($fp);
                                throw new RuntimeException("Query gagal di offset " . ftell($fp) . ": " . $e->getMessage() . "\nSQL: " . substr($queryToRun, 0, 150));
                            }
                        }
                    }
                    $currentQuery = '';

                    // Cek batas per chunk (jumlah query atau waktu)
                    if ($statementCount >= $maxStatements || (microtime(true) - $startTime) >= $maxTime) {
                        break 2;
                    }
                }
            }
        }

        $newOffset = ftell($fp);
        if (feof($fp) || $newOffset >= $fileSize) {
            $isDone = true;
            $newOffset = $fileSize;
            // Aktifkan kembali Foreign Key Checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        }

        fclose($fp);

        $percent = $fileSize > 0 ? round(($newOffset / $fileSize) * 100, 1) : 100;

        return [
            'success' => true,
            'offset' => $newOffset,
            'total_size' => $fileSize,
            'statements_executed' => $statementCount,
            'percent' => $percent,
            'done' => $isDone,
            'message' => $isDone
                ? "Restorasi database tuntas! 100% diproses."
                : "Memproses database... ({$percent}% - {$statementCount} query dijalankan)"
        ];
    }
}
