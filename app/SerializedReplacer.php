<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * SerializedReplacer: Deep Recursive Search-and-Replace with Serialized Data Support
 * Mengganti domain/URL dan string secara aman pada data string biasa, array, JSON,
 * maupun objek ter-serialisasi PHP (menghitung ulang panjang string `s:len:"value";` agar tidak korup).
 */
class SerializedReplacer
{
    /**
     * Mengganti $search dengan $replace pada data apapun (string, array, objek, serialized string, JSON).
     *
     * @param mixed $data
     * @param string $search
     * @param string $replace
     * @return mixed
     */
    public static function replace($data, string $search, string $replace)
    {
        if (empty($search) || $search === $replace) {
            return $data;
        }

        if (is_array($data)) {
            $newArr = [];
            foreach ($data as $key => $value) {
                $newKey = is_string($key) ? self::replace($key, $search, $replace) : $key;
                $newArr[$newKey] = self::replace($value, $search, $replace);
            }
            return $newArr;
        }

        if (is_object($data)) {
            $class = get_class($data);
            $props = get_object_vars($data);
            foreach ($props as $key => $value) {
                $data->$key = self::replace($value, $search, $replace);
            }
            return $data;
        }

        if (is_string($data)) {
            // 1. Cek apakah string adalah serialized PHP data
            if (self::isSerialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced = self::replace($unserialized, $search, $replace);
                    return serialize($replaced);
                } else {
                    // Fallback regex jika unserialize gagal
                    return self::fixSerializedRegex($data, $search, $replace);
                }
            }

            // 2. Cek apakah string adalah JSON valid
            if (self::isJson($data)) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $replaced = self::replace($decoded, $search, $replace);
                    return json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            // 3. String biasa
            return str_replace($search, $replace, $data);
        }

        return $data;
    }

    /**
     * Memeriksa apakah string adalah serialized data PHP.
     */
    public static function isSerialized(string $str): bool
    {
        $str = trim($str);
        if ($str === 'N;') return true;
        if (strlen($str) < 4) return false;
        if ($str[1] !== ':') return false;

        $badrun = false;
        $firstChar = $str[0];

        switch ($firstChar) {
            case 's':
                if (substr($str, -2) !== '";') return false;
                break;
            case 'a':
            case 'O':
            case 'C':
                if (substr($str, -1) !== '}') return false;
                break;
            case 'b':
            case 'i':
            case 'd':
                if (substr($str, -1) !== ';') return false;
                break;
            default:
                return false;
        }

        return @unserialize($str) !== false || $str === 'b:0;';
    }

    /**
     * Memeriksa apakah string adalah JSON valid.
     */
    public static function isJson(string $str): bool
    {
        $str = trim($str);
        if (empty($str)) return false;
        if ($str[0] !== '{' && $str[0] !== '[') return false;

        json_decode($str);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Fallback Regex untuk memperbaiki panjang byte string serialized jika unserialize gagal.
     */
    private static function fixSerializedRegex(string $serialized, string $search, string $replace): string
    {
        $pattern = '/s:(\d+):"(.*?)";/s';
        return preg_replace_callback($pattern, function ($matches) use ($search, $replace) {
            $stringVal = $matches[2];
            $stringVal = str_replace($search, $replace, $stringVal);
            $newLen = strlen($stringVal);
            return 's:' . $newLen . ':"' . $stringVal . '";';
        }, $serialized);
    }

    /**
     * Melakukan deep search-and-replace langsung di dalam database MySQL yang telah diimpor.
     *
     * @param PDO $pdo
     * @param string $search
     * @param string $replace
     * @param callable|null $progressCallback
     * @return array
     */
    public static function replaceInDatabase(PDO $pdo, string $search, string $replace, ?callable $progressCallback = null): array
    {
        if (empty($search) || $search === $replace) {
            return ['updated_rows' => 0, 'tables_scanned' => 0];
        }

        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $totalTables = count($tables);
        $totalRowsUpdated = 0;

        foreach ($tables as $index => $table) {
            if ($progressCallback) {
                $percent = $totalTables > 0 ? round(($index / $totalTables) * 100, 1) : 0;
                $progressCallback($table, $percent, "Memindai tabel {$table}...");
            }

            // Dapatkan kolom teks / string dan primary key
            $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

            $primaryKeys = [];
            $textColumns = [];

            foreach ($columns as $col) {
                if ($col['Key'] === 'PRI') {
                    $primaryKeys[] = $col['Field'];
                }
                $type = strtolower($col['Type']);
                if (strpos($type, 'char') !== false ||
                    strpos($type, 'text') !== false ||
                    strpos($type, 'blob') !== false) {
                    $textColumns[] = $col['Field'];
                }
            }

            if (empty($textColumns) || empty($primaryKeys)) {
                continue;
            }

            // Cari baris yang mengandung $search
            $whereParts = [];
            foreach ($textColumns as $col) {
                $whereParts[] = "`{$col}` LIKE " . $pdo->quote('%' . $search . '%');
            }
            $whereSql = implode(' OR ', $whereParts);

            $selectSql = "SELECT " . implode(', ', array_merge($primaryKeys, $textColumns)) . " FROM `{$table}` WHERE {$whereSql}";
            $queryStmt = $pdo->query($selectSql);

            while ($row = $queryStmt->fetch(PDO::FETCH_ASSOC)) {
                $updates = [];
                $needsUpdate = false;

                foreach ($textColumns as $col) {
                    $val = $row[$col];
                    if ($val !== null && is_string($val) && strpos($val, $search) !== false) {
                        $newVal = self::replace($val, $search, $replace);
                        if ($newVal !== $val) {
                            $updates[$col] = $newVal;
                            $needsUpdate = true;
                        }
                    }
                }

                if ($needsUpdate && !empty($updates)) {
                    $setParts = [];
                    $binds = [];
                    foreach ($updates as $col => $newVal) {
                        $setParts[] = "`{$col}` = ?";
                        $binds[] = $newVal;
                    }

                    $pkParts = [];
                    foreach ($primaryKeys as $pk) {
                        $pkParts[] = "`{$pk}` = ?";
                        $binds[] = $row[$pk];
                    }

                    $updateSql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $pkParts);
                    $upStmt = $pdo->prepare($updateSql);
                    $upStmt->execute($binds);
                    $totalRowsUpdated++;
                }
            }
        }

        if ($progressCallback) {
            $progressCallback('', 100, "Selesai! Berhasil memperbarui {$totalRowsUpdated} baris data.");
        }

        return [
            'updated_rows' => $totalRowsUpdated,
            'tables_scanned' => $totalTables
        ];
    }
}
