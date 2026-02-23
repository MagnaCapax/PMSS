<?php
/**
 * Per-user action logging helper.
 *
 * Appends timestamped lines to /var/log/pmss/users/<username>.log and mirrors
 * entries into the consolidated users.log/users.jsonl stream when available.
 * Keep this helper dependency-free so it can be used from cron scripts easily.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssUserLogAllowed')) {
    function pmssUserLogAllowed(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $uid = function_exists('posix_geteuid') ? @posix_geteuid() : null;
        if ($uid === null) {
            $status = @file_get_contents('/proc/self/status');
            if ($status !== false && preg_match('/^Uid:\\s+(\\d+)/m', $status, $matches)) {
                $uid = (int) $matches[1];
            }
        }

        return $cached = ($uid === 0);
    }
}

if (!function_exists('pmssUserLogFile')) {
    function pmssUserLogFile(string $user): string
    {
        $base = '/var/log/pmss';
        $dir = $base.'/users';
        $legacyDir = $base.'/user';
        if (!is_dir($dir)) {
            if (is_dir($legacyDir) && !file_exists($dir)) {
                @rename($legacyDir, $dir);
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                if (!is_dir($dir)) {
                    $dir = $legacyDir;
                    @mkdir($dir, 0755, true);
                }
            }
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $user);
        $path = rtrim($dir, '/').'/'.$safe.'.log';

        $legacyFlat = $base.'/user-'.$safe.'.log';
        if (!file_exists($path) && is_file($legacyFlat)) {
            @rename($legacyFlat, $path);
        }

        return $path;
    }
}

if (!function_exists('pmssUserLog')) {
    function pmssUserLog(string $user, string $message): void
    {
        if (!pmssUserLogAllowed()) {
            return;
        }
        $path = pmssUserLogFile($user);
        $ts   = date('[Y-m-d H:i:s] ');
        @file_put_contents($path, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);

        // Mirror per-user events into the shared users.log/users.jsonl stream
        // when the lifecycle helpers are available. Kept optional so this
        // helper remains usable in minimal bootstrap environments.
        if (function_exists('pmssUserWriteLogs') && function_exists('pmssUserBaseContext')) {
            $payload = pmssUserBaseContext(
                'runtime',
                'user_log',
                $user,
                [
                    'status'  => 'INFO',
                    'step'    => 'pmssUserLog',
                    'message' => $message,
                ]
            );
            pmssUserWriteLogs($payload);
        }
    }
}
