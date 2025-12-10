<?php
/**
 * Per-user action logging helper.
 *
 * Appends timestamped lines to /var/log/pmss/users/<username>.log and mirrors
 * entries into the consolidated users.log/users.jsonl stream when available.
 * Keep this helper dependency-free so it can be used from cron scripts easily.
 */

if (!function_exists('pmssUserLogFile')) {
    function pmssUserLogFile(string $user): string
    {
        $dir = '/var/log/pmss/users';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $user);
        return rtrim($dir, '/').'/'.$safe.'.log';
    }
}

if (!function_exists('pmssUserLog')) {
    function pmssUserLog(string $user, string $message): void
    {
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
