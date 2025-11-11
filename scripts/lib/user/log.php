<?php
/**
 * Per-user action logging helper.
 *
 * Appends timestamped lines to /var/log/pmss/user-<username>.log.
 * Keep this helper dependency-free so it can be used from cron scripts easily.
 */

if (!function_exists('pmssUserLogFile')) {
    function pmssUserLogFile(string $user): string
    {
        $dir = '/var/log/pmss';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $user);
        return rtrim($dir, '/').'/user-'.$safe.'.log';
    }
}

if (!function_exists('pmssUserLog')) {
    function pmssUserLog(string $user, string $message): void
    {
        $path = pmssUserLogFile($user);
        $ts   = date('[Y-m-d H:i:s] ');
        @file_put_contents($path, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

