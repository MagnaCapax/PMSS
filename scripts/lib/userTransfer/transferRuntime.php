<?php
/**
 * Local runtime helpers for user transfers.
 * Owns password prompting, generated file writes, pass sleeps, and cleanup.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';
require_once dirname(__DIR__).'/runtime.php';

function pmssUserTransferWriteFile(string $path, string $contents, int $mode): void
{
    if (pmssAtomicWriteFile($path, $contents, $mode)) {
        return;
    }

    throw new RuntimeException('Failed writing: '.$path, 1);
}

function pmssUserTransferSleep(int $min, int $max, string $reason): void
{
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN') || $max <= 0) {
        return;
    }

    try {
        $seconds = $max > $min ? random_int($min, $max) : $min;
    } catch (Throwable $e) {
        $seconds = $max > $min ? rand($min, $max) : $min;
    }

    logMessage(sprintf('[SLEEP] %s (%ds)', $reason, $seconds));
    sleep($seconds);
}

function pmssUserTransferRunPasses(
    string $description,
    string $expectPath,
    string $scriptPath,
    int $passes,
    int $sleepMin,
    int $sleepMax
): int {
    $lastRc = 0;
    for ($i = 1; $i <= $passes; $i++) {
        $lastRc = runStep(sprintf('%s (pass %d/%d)', $description, $i, $passes), pmssBuildCommand($expectPath, [$scriptPath]));
        if ($i < $passes) {
            pmssUserTransferSleep($sleepMin, $sleepMax, sprintf('Waiting before next %s pass', strtolower($description)));
        }
    }

    return $lastRc;
}

function pmssUserTransferResolvePassword(): string
{
    $fromEnv = getenv('PMSS_USER_TRANSFER_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    if (!pmssStreamIsTty(STDIN)) {
        throw new RuntimeException('Password missing (set PMSS_USER_TRANSFER_PASSWORD for non-interactive runs)', 1);
    }

    $mode = trim((string) @shell_exec('stty -g 2>/dev/null'));
    try {
        @shell_exec('stty -echo 2>/dev/null');
        echo 'Remote user password: ';
        $pass1 = (string) fgets(STDIN);
        echo PHP_EOL.'Re-type password: ';
        $pass2 = (string) fgets(STDIN);
        echo PHP_EOL;
    } finally {
        @shell_exec('stty '.($mode !== '' ? escapeshellarg($mode) : 'echo').' 2>/dev/null');
    }

    $pass1 = trim($pass1);
    if ($pass1 === '' || $pass1 !== trim($pass2)) {
        throw new RuntimeException('Password mismatch', 1);
    }
    return $pass1;
}

function pmssUserTransferCreateScratchRoot(): string
{
    try {
        $token = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $token = sha1(microtime(true).'-'.mt_rand());
    }
    $scratch = '/root/pmss-userTransfer-'.$token;
    if (!pmssDirEnsureExists($scratch, 0700)) {
        throw new RuntimeException('Failed to create scratch directory: '.$scratch, 1);
    }
    @chmod($scratch, 0700);
    return $scratch;
}

/**
 * Check generated cleanup targets before unlinking them.
 */
function pmssUserTransferScratchPathIsInsideRoot(string $path, string $scratch): bool
{
    $path = pmssUserTransferScratchPathNormalize($path);
    $scratch = pmssUserTransferScratchPathNormalize($scratch);
    if ($path === null || $scratch === null || $scratch === '/') {
        return false;
    }

    return strpos($path.'/', rtrim($scratch, '/').'/') === 0;
}

/**
 * Normalize absolute scratch paths without resolving symlink targets.
 */
function pmssUserTransferScratchPathNormalize(string $path): ?string
{
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false) {
        return null;
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return null;
        }
        $parts[] = $part;
    }

    return '/'.implode('/', $parts);
}

function pmssUserTransferScratchCleanup(string $scratch, array $scratchPaths): void
{
    foreach ($scratchPaths as $path) {
        if (!is_string($path) || !pmssUserTransferScratchPathIsInsideRoot($path, $scratch)) {
            logMessage('[WARN] Skipping unsafe user transfer scratch cleanup path');
            continue;
        }
        if (file_exists($path)) {
            @unlink($path);
        }
    }
    if (pmssUserTransferScratchPathIsInsideRoot($scratch, $scratch) && is_dir($scratch)) {
        @rmdir($scratch);
    }
}
