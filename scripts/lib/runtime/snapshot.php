<?php
/** Snapshot log primitives loaded by the shared runtime facade. */

/**
 * Open a root-only append log for snapshot-style cron jobs.
 *
 * @return resource|false
 */
function pmssSnapshotLogOpen(string $scriptName, string $logPath, ?int &$oldUmask)
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, basename($scriptName)." must be run as root.\n");
        return false;
    }
    $oldUmask = umask(0077);
    if (!pmssDirEnsureExists(dirname($logPath), 0755)) {
        return false;
    }
    $handle = @fopen($logPath, 'ab');
    if ($handle === false) {
        return false;
    }
    @chmod($logPath, 0600);
    if (function_exists('flock')) {
        @flock($handle, LOCK_EX);
    }
    return $handle;
}

/** Resolve env log path, stamp time once, and run a snapshot callback. */
function pmssRunSnapshotLogTask(string $scriptName, string $envKey, string $defaultLogPath, callable $callback): int
{
    $timestamp = date('Y-m-d\\TH:i:s'); $oldUmask = null; $handle = false;
    try {
        $handle = pmssSnapshotLogOpen($scriptName, pmssResolvePathFromEnv($envKey, $defaultLogPath), $oldUmask);
        if ($handle === false) {
            return 1;
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

/** Keep warning field values single-line and bounded without changing normal text. */
function pmssSnapshotWarnValue($value): string { $normalized = (string) preg_replace('/[\r\n\0\t]+/', ' ', (string) $value); $normalized = trim((string) preg_replace('/ {2,}/', ' ', $normalized)); return strlen($normalized) > 300 ? substr($normalized, 0, 300) : $normalized; }

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
        $value = pmssSnapshotWarnValue($value);
        if ($value === '') {
            continue;
        }

        $line .= ' '.pmssSnapshotWarnToken((string) $key).'='.$value;
    }

    pmssSnapshotWriteLine($handle, $line);
}
