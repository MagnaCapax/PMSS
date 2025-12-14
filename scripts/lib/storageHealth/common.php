<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 */

require_once __DIR__.'/../runtime.php';

if (!function_exists('pmssStorageHealthDefaultJsonPath')) {
    function pmssStorageHealthDefaultJsonPath(): string
    {
        return '/var/log/pmss/storage-health.jsonl';
    }
}

if (!function_exists('pmssStorageHealthEnsureParentDir')) {
    function pmssStorageHealthEnsureParentDir(string $path): void
    {
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

if (!function_exists('pmssStorageHealthAppendJsonl')) {
    function pmssStorageHealthAppendJsonl(string $path, array $entry): void
    {
        @file_put_contents(
            $path,
            json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('pmssStorageHealthReadLastEntries')) {
    /**
     * Read the latest entry per (kind, device/array) key from a JSONL file.
     *
     * @return array<string, array<string, mixed>>
     */
    function pmssStorageHealthReadLastEntries(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'r');
        if (!$fh) {
            return [];
        }
        $last = [];
        while (($line = fgets($fh)) !== false) {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $kind = (string) ($j['kind'] ?? '');
            $id = (string) ($j['device'] ?? ($j['array'] ?? 'global'));
            $key = $kind.'::'.$id;
            $last[$key] = $j;
        }
        fclose($fh);
        return $last;
    }
}

if (!function_exists('pmssStorageHealthSeverityMax')) {
    function pmssStorageHealthSeverityMax(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        $ra = $rank[$a] ?? 1;
        $rb = $rank[$b] ?? 1;
        return ($rb > $ra) ? $b : $a;
    }
}

