<?php
/** Snapshot log primitives loaded by the shared runtime facade. */

/** Resolve env log path, stamp time once, and run a snapshot callback. */
function pmssRunSnapshotLogTask(string $scriptName, string $envKey, string $defaultLogPath, callable $callback): int
{
    $timestamp = date('Y-m-d\\TH:i:s'); $oldUmask = null; $handle = false;
    try {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return pmssCliReturnWithStderr(basename($scriptName)." must be run as root.\n");
        }
        $oldUmask = umask(0077);
        $logPath = pmssResolvePathFromEnv($envKey, $defaultLogPath);
        if (!pmssDirEnsureExists(dirname($logPath), 0755)) {
            return 1;
        }
        $handle = @fopen($logPath, 'ab');
        if ($handle === false) {
            return 1;
        }
        @chmod($logPath, 0600);
        if (function_exists('flock')) {
            @flock($handle, LOCK_EX);
        }
        return (int) $callback($handle, $timestamp);
    } finally {
        if ($handle !== false) @fclose($handle);
        if ($oldUmask !== null) umask($oldUmask);
    }
}

// Append one newline-terminated line to a snapshot log.
function pmssSnapshotWriteLine($handle, string $line): void
{
    @fwrite($handle, $line.PHP_EOL);
}

/** Keep warning codes and field keys as single log tokens. */
function pmssSnapshotWarnToken(string $value, string $fallback = 'field'): string
{
    $token = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', trim($value));
    $token = trim($token, '_');
    if ($token === '') {
        $token = $fallback;
    }

    return substr($token, 0, 64);
}

// Append a normalized warning line to a snapshot log.
function pmssSnapshotWriteWarn($handle, string $timestamp, string $code, array $fields = [], array $output = []): void
{
    if ($output !== []) {
        $excerpt = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_slice($output, 0, 5))));
        if ($excerpt !== '') {
            $fields['msg'] = substr($excerpt, 0, 300);
        }
    }

    $line = $timestamp.' WARN '.pmssSnapshotWarnToken($code, 'warn');
    foreach ($fields as $key => $value) {
        $value = substr(trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace('/[\r\n\0\t]+/', ' ', (string) $value))), 0, 300);
        if ($value === '') {
            continue;
        }

        $line .= ' '.pmssSnapshotWarnToken((string) $key).'='.$value;
    }

    pmssSnapshotWriteLine($handle, $line);
}
