<?php
/** File discovery for customer and operator scan scopes. @license GPL-3.0-only */

trait PmssCustomerContextFiles
{
    /** Recursive operator discovery alone excludes bundled helpers and tests; each list stays sorted. */
    private function files(string $dir, bool $recursive): array
    {
        if (!is_dir($dir)) return [];

        $files = [];
        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS))
            : new DirectoryIterator($dir);
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            if ($recursive && (strpos($path, '/tests/') !== false || strpos($path, '/devristo/') !== false)) continue;
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    /** List extensionless PHP commands directly inside one directory. */
    private function shebangPhpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) return $files;
        foreach (new DirectoryIterator($dir) as $file) {
            if (!$file->isFile()) continue;
            $handle = @fopen($file->getPathname(), 'r');
            if ($handle === false) continue;
            try {
                $first = fgets($handle);
            } finally {
                // Shebang inspection owns the stream even when reading throws.
                fclose($handle);
            }
            if (is_string($first) && preg_match('~^#!/usr/bin/(?:env )?php\s*$~', $first)) $files[] = $file->getPathname();
        }
        sort($files);
        return $files;
    }
}
