<?php
/**
 * Lighttpd watchdog 502 page diagnosis probes.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssCommandPath') && is_file(__DIR__.'/../runtime.php')) {
    require_once __DIR__.'/../runtime.php';
}

/** Return true when quota output shows either block or inode exhaustion. */
function pmssLighttpdWatchdogQuotaOutputShowsExhaustion(string $output): bool
{
    if (trim($output) === '') {
        return false;
    }

    return preg_match('/^\s*\/\S+.*\*.*$/m', $output) === 1;
}

/** Parse the reported root inode-use percentage from `df -i` output. */
function pmssLighttpdWatchdogParseDfInodeUsePercent(string $output): ?int
{
    if (trim($output) === '') {
        return null;
    }

    foreach (preg_split('/\r?\n/', trim($output)) as $line) {
        if (stripos($line, 'iused') !== false || stripos($line, 'filesystem') !== false) {
            continue;
        }

        if (preg_match('/\s([0-9]{1,3})%\s+\S+\s*$/', $line, $matches) === 1) {
            return (int) $matches[1];
        }
    }

    return null;
}

/** Execute a watchdog probe command and capture its combined output and rc. */
function pmssLighttpdWatchdogCommandCapture(string $binary, string $arguments): ?array
{
    $path = function_exists('pmssCommandPath') ? pmssCommandPath($binary) : '';
    if ($path === '') {
        return null;
    }

    $lines = array();
    $exitCode = 0;
    @exec($path.' '.$arguments, $lines, $exitCode);
    return array('output' => $lines !== array() ? implode(PHP_EOL, $lines).PHP_EOL : '', 'exitCode' => $exitCode);
}

/** Inspect quota state live when possible, otherwise fall back to ~/.quota. */
function pmssLighttpdWatchdogQuotaExceeded(string $username, string $homeDir): bool
{
    $output = '';
    $quotaResult = pmssLighttpdWatchdogCommandCapture('quota', '-u '.escapeshellarg($username).' -s 2>/dev/null');
    if ($quotaResult !== null && $quotaResult['output'] !== '') {
        $output = $quotaResult['output'];
    }

    if ($output === '') {
        $quotaFile = rtrim($homeDir, '/').'/.quota';
        $snapshot = pmssReadRegularFileContents($quotaFile);
        if ($snapshot !== null) {
            $output = $snapshot;
        }
    }

    return pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output);
}

/** Return true when root inode usage is at or above the watchdog threshold. */
function pmssLighttpdWatchdogRootInodesExhausted(string $mountPath = '/'): bool
{
    $dfResult = pmssLighttpdWatchdogCommandCapture('df', '-i '.escapeshellarg($mountPath).' 2>/dev/null');
    if ($dfResult === null) {
        return false;
    }

    $usagePercent = pmssLighttpdWatchdogParseDfInodeUsePercent($dfResult['output']);

    return $usagePercent !== null && $usagePercent >= 95;
}

/** Return true when `lighttpd -t -f` reports a config problem. */
function pmssLighttpdWatchdogConfigInvalid(string $configPath): bool
{
    if (!file_exists($configPath)) {
        return true;
    }

    $lighttpdResult = pmssLighttpdWatchdogCommandCapture('lighttpd', '-t -f '.escapeshellarg($configPath).' 2>&1');
    if ($lighttpdResult === null) {
        return false;
    }

    return $lighttpdResult['exitCode'] !== 0;
}

/** Diagnose the most helpful 502 status page for an unhealthy user web stack. */
function pmssLighttpdWatchdogDetectReason(string $username, string $homeDir, string $configPath, bool $socketError): string
{
    if (is_dir(rtrim($homeDir, '/').'/www-disabled')) {
        return 'suspended';
    }

    if (pmssLighttpdWatchdogQuotaExceeded($username, $homeDir)) {
        return 'quota';
    }

    if (pmssLighttpdWatchdogRootInodesExhausted('/') || pmssLighttpdWatchdogRootInodesExhausted('/home')) {
        return 'inode';
    }

    if (pmssLighttpdWatchdogConfigInvalid($configPath)) {
        return 'config';
    }

    if ($socketError) {
        return 'php';
    }

    return 'restarting';
}
