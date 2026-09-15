<?php
if (!defined('MIGRATOR_INIT')) {
    http_response_code(403);
    exit('Direct access denied.');
}

/**
 * ArchiveManager: ZIP Packaging & Chunked Resumable Extraction
 * Mengompres berkas website dengan filter pengecualian pintar,
 * serta mengekstrak arsip secara bertahap (chunked) untuk mencegah timeout di shared hosting.
 */
class ArchiveManager
{
    /**
     * Daftar direktori & berkas bawaan yang diabaikan agar ukuran arsip hemat & bersih.
     */
    public const DEFAULT_EXCLUDES = [
        // Version Control & Editor
        '.git',
        '.svn',
        '.hg',
        '.idea',
        '.vscode',
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',

        // Dependencies & Cache
        'node_modules',
        'wp-content/cache',
        'wp-content/uploads/cache',
        'wp-content/updraft',
        'wp-content/backups',
        'storage/logs',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'var/cache',
        'cache',

        // Migrator internal files
        'storage/temp',
        'migrator_package.zip',
        'installer.php',
    ];

    /**
     * Mengumpulkan daftar berkas yang akan dimasukkan ke dalam arsip.
     */
    public static function scanFiles(string $sourceDir, array $customExcludes = []): array
    {
        $sourceDir = rtrim(str_replace('\\', '/', realpath($sourceDir)), '/');
        $excludes = array_unique(array_merge(self::DEFAULT_EXCLUDES, $customExcludes));

        $fileList = [];
        $totalBytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            $relPath = ltrim(substr($path, strlen($sourceDir)), '/');

            // Cek apakah relPath cocok dengan salah satu exclude
            $isExcluded = false;
            foreach ($excludes as $exc) {
                $exc = trim($exc, '/');
                if ($relPath === $exc || str_starts_with($relPath, $exc . '/') || fnmatch($exc, basename($relPath))) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                continue;
            }

            if ($item->isFile()) {
                $size = $item->getSize();
                $fileList[] = [
                    'full_path' => $path,
                    'relative_path' => $relPath,
                    'size' => $size,
                ];
                $totalBytes += $size;
            }
        }

        return [
            'files' => $fileList,
            'total_files' => count($fileList),
            'total_bytes' => $totalBytes
        ];
    }

    /**
     * Mengompres seluruh berkas web ke dalam berkas ZIP.
     *
     * @param string $sourceDir Direktori root website
     * @param string $outputZipFile Path berkas ZIP tujuan
     * @param array $extraFiles Berkas tambahan (misalnya dump database.sql) [ 'sql/db.sql' => '/full/path/dump.sql' ]
     * @param array $customExcludes Daftar exclude tambahan
     * @param callable|null $progressCallback Callback function($currentFile, $percent, $message)
     * @return array
     */
    public static function createZip(
        string $sourceDir,
        string $outputZipFile,
        array $extraFiles = [],
        array $customExcludes = [],
        ?callable $progressCallback = null
    ): array {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('Ekstensi PHP ZipArchive tidak aktif.');
        }

        $scan = self::scanFiles($sourceDir, $customExcludes);
        $files = $scan['files'];
        $totalFiles = count($files) + count($extraFiles);

        $zip = new ZipArchive();
        $res = $zip->open($outputZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new RuntimeException("Gagal membuat arsip ZIP: Kode error {$res}");
        }

        $processed = 0;

        // Tambahkan file website
        foreach ($files as $fileInfo) {
            $zip->addFile($fileInfo['full_path'], $fileInfo['relative_path']);
            $processed++;

            if ($progressCallback && ($processed % 100 === 0 || $processed === $totalFiles)) {
                $pct = round(($processed / $totalFiles) * 100, 1);
                $progressCallback($fileInfo['relative_path'], $pct, "Mengompres berkas ({$processed}/{$totalFiles})...");
            }
        }

        // Tambahkan extra files (database dump, config migrator dsb)
        foreach ($extraFiles as $zipPath => $fullPath) {
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, $zipPath);
                $processed++;
                if ($progressCallback) {
                    $pct = round(($processed / $totalFiles) * 100, 1);
                    $progressCallback($zipPath, $pct, "Menyematkan database dump ke arsip...");
                }
            }
        }

        $zip->close();

        return [
            'success' => true,
            'total_files' => $processed,
            'zip_file' => $outputZipFile,
            'file_size' => file_exists($outputZipFile) ? filesize($outputZipFile) : 0
        ];
    }

    /**
     * Mengekstrak arsip ZIP secara bertahap (chunked) untuk AJAX.
     *
     * @param string $zipFile Path ke berkas arsip ZIP
     * @param string $extractTo Direktori tujuan ekstraksi
     * @param int $startIndex Indeks berkas awal dalam ZIP
     * @param int $chunkSize Jumlah berkas yang diekstrak dalam 1 langkah
     * @return array Status ekstraksi
     */
    public static function extractChunk(
        string $zipFile,
        string $extractTo,
        int $startIndex = 0,
        int $chunkSize = 250
    ): array {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('Ekstensi PHP ZipArchive tidak aktif.');
        }

        if (!file_exists($zipFile)) {
            throw new InvalidArgumentException("Berkas arsip ZIP tidak ditemukan: {$zipFile}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException("Gagal membuka arsip ZIP.");
        }

        $totalFiles = $zip->numFiles;
        $endIndex = min($startIndex + $chunkSize, $totalFiles);
        $extractedCount = 0;

        for ($i = $startIndex; $i < $endIndex; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) continue;

            $filename = $stat['name'];

            // Lewati berkas migrator berbahaya jika ada path traversal
            if (str_contains($filename, '../') || str_contains($filename, '..\\')) {
                continue;
            }

            // Ekstrak berkas tunggal
            $zip->extractTo($extractTo, [$filename]);
            $extractedCount++;
        }

        $zip->close();

        $isDone = ($endIndex >= $totalFiles);
        $percent = $totalFiles > 0 ? round(($endIndex / $totalFiles) * 100, 1) : 100;

        return [
            'success' => true,
            'start_index' => $startIndex,
            'next_index' => $endIndex,
            'total_files' => $totalFiles,
            'extracted_chunk' => $extractedCount,
            'percent' => $percent,
            'done' => $isDone,
            'message' => $isDone
                ? "Ekstraksi arsip selesai! Total {$totalFiles} berkas dipulihkan."
                : "Mengekstrak berkas... ({$endIndex}/{$totalFiles} - {$percent}%)"
        ];
    }
}
