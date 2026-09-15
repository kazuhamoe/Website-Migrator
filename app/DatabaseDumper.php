<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * Streaming MySQL Database Dumper
 * Mengekspor skema dan data database MySQL secara streaming langsung ke file
 * tanpa membebani memori RAM server.
 */
class DatabaseDumper
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
            'chunk_size' => 200,
        ], $config);
    }

    /**
     * Menguji koneksi database.
     */
    public function testConnection(): array
    {
        try {
            $this->connect();
            return ['success' => true, 'message' => 'Koneksi database berhasil terhubung.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Gagal terhubung ke database: ' . $e->getMessage()];
        }
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

        // Support socket or custom host:port
        if (strpos($host, ':') !== false) {
            [$host, $customPort] = explode(':', $host, 2);
            $port = (int)$customPort;
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
        ];

        $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], $options);
        return $this->pdo;
    }

    /**
     * Melakukan export/dump seluruh database ke file SQL.
     *
     * @param string $outputFile Path tujuan file .sql
     * @param callable|null $progressCallback Callback function($stage, $tableName, $percent, $message)
     * @return array Hasil dump ['success' => bool, 'tables' => int, 'rows' => int, 'file_size' => int]
     */
    public function dump(string $outputFile, ?callable $progressCallback = null): array
    {
        $pdo = $this->connect();
        $fp = fopen($outputFile, 'w');
        if (!$fp) {
            throw new RuntimeException("Tidak dapat membuka file tujuan untuk menulis: {$outputFile}");
        }

        $report = function (string $stage, string $item, float $percent, string $msg) use ($progressCallback) {
            if ($progressCallback && is_callable($progressCallback)) {
                $progressCallback($stage, $item, $percent, $msg);
            }
        };

        $report('init', '', 0, 'Memulai proses dump database...');

        // Header SQL
        fwrite($fp, "-- ========================================================\n");
        fwrite($fp, "-- Website Migrator - Database Backup Dump\n");
        fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- Database: `{$this->config['database']}`\n");
        fwrite($fp, "-- Server: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");
        fwrite($fp, "-- ========================================================\n\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fp, "SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($fp, "SET AUTOCOMMIT=0;\n");
        fwrite($fp, "START TRANSACTION;\n");
        fwrite($fp, "SET time_zone = \"+00:00\";\n\n");

        // Dapatkan daftar tabel
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $totalTables = count($tables);
        $totalRowsExported = 0;

        foreach ($tables as $index => $table) {
            $percent = $totalTables > 0 ? round(($index / $totalTables) * 100, 1) : 0;
            $report('table_start', $table, $percent, "Mengekspor tabel: {$table} (" . ($index + 1) . "/{$totalTables})");

            // DROP TABLE IF EXISTS
            fwrite($fp, "\n-- --------------------------------------------------------\n");
            fwrite($fp, "-- Struktur Tabel untuk `{$table}`\n");
            fwrite($fp, "-- --------------------------------------------------------\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

            // CREATE TABLE DDL
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(PDO::FETCH_NUM);
            if (!empty($createRow[1])) {
                fwrite($fp, $createRow[1] . ";\n\n");
            }

            // Dump data baris per baris
            $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $tableRowCount = (int)$countStmt->fetchColumn();

            if ($tableRowCount > 0) {
                fwrite($fp, "-- Dumping data untuk tabel `{$table}` ({$tableRowCount} baris)\n");

                $selectStmt = $pdo->query("SELECT * FROM `{$table}`");
                $buffer = [];
                $chunkSize = (int)$this->config['chunk_size'];

                while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
                    $totalRowsExported++;
                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $values[] = $val;
                        } else {
                            $values[] = $pdo->quote($val);
                        }
                    }
                    $buffer[] = '(' . implode(', ', $values) . ')';

                    if (count($buffer) >= $chunkSize) {
                        fwrite($fp, "INSERT INTO `{$table}` VALUES \n" . implode(",\n", $buffer) . ";\n");
                        $buffer = [];
                    }
                }

                if (!empty($buffer)) {
                    fwrite($fp, "INSERT INTO `{$table}` VALUES \n" . implode(",\n", $buffer) . ";\n");
                    $buffer = [];
                }
                fwrite($fp, "\n");
            }
        }

        // Dapatkan views jika ada
        $viewsStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
        while ($vRow = $viewsStmt->fetch(PDO::FETCH_NUM)) {
            $viewName = $vRow[0];
            fwrite($fp, "\n-- Struktur View `{$viewName}`\n");
            fwrite($fp, "DROP VIEW IF EXISTS `{$viewName}`;\n");
            $cvStmt = $pdo->query("SHOW CREATE VIEW `{$viewName}`");
            $cvRow = $cvStmt->fetch(PDO::FETCH_NUM);
            if (!empty($cvRow[1])) {
                fwrite($fp, $cvRow[1] . ";\n\n");
            }
        }

        // Footer SQL
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($fp, "COMMIT;\n");
        fclose($fp);

        $report('finish', '', 100, "Dump database selesai! Total {$totalTables} tabel, {$totalRowsExported} baris data.");

        return [
            'success' => true,
            'tables' => $totalTables,
            'rows' => $totalRowsExported,
            'file_size' => file_exists($outputFile) ? filesize($outputFile) : 0,
            'file_path' => $outputFile
        ];
    }
}
