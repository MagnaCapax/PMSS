<?php
/**
 * Lighttpd watchdog quota and deleted-block diagnosis helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/userProcHeldBlocks.php';

/** Return true when quota output shows either block or inode exhaustion. */
function pmssLighttpdWatchdogQuotaOutputShowsExhaustion(string $output): bool
{
    return preg_match('/^\s*\/\S+.*\*.*$/m', $output) === 1;
}

/** Read quota state live when possible, otherwise fall back to ~/.quota. */
function pmssLighttpdWatchdogQuotaOutputRead(string $username, string $homeDir): ?string
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

    return $output !== '' ? $output : null;
}

/** Parse the charged, soft-limit, and exhaustion state from quota output. */
function pmssLighttpdWatchdogQuotaStateParse(string $output): ?array
{
    $fallback = null;
    foreach (preg_split('/\r?\n/', $output) as $line) {
        $tokens = preg_split('/\s+/', trim($line));
        if (!is_array($tokens) || count($tokens) < 4 || strpos($tokens[0], '/') !== 0) {
            continue;
        }

        $sizes = array();
        foreach (array_slice($tokens, 1, 3) as $token) {
            $bytes = pmssParseSizeToBytes(rtrim((string) $token, '*'));
            if ($bytes === null) {
                continue 2;
            }
            $sizes[] = (int) $bytes;
        }

        $state = array(
            'usedBytes' => $sizes[0],
            'softLimitBytes' => $sizes[1],
            'hardLimitBytes' => $sizes[2],
            'exceeded' => false,
        );
        if (strpos($tokens[1], '*') !== false || strpos($tokens[4] ?? '', '*') !== false) {
            $state['exceeded'] = true;
            return $state;
        }
        $fallback = $state;
    }

    return $fallback;
}

/** Read numeric quota state for the current unhealthy-user diagnosis. */
function pmssLighttpdWatchdogQuotaStateRead(string $username, string $homeDir): ?array
{
    $output = pmssLighttpdWatchdogQuotaOutputRead($username, $homeDir);
    if ($output === null) {
        return null;
    }

    $state = pmssLighttpdWatchdogQuotaStateParse($output);
    if ($state === null) {
        return null;
    }

    $state['exceeded'] = pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output);
    return $state;
}

/** Inspect quota state live when possible, otherwise fall back to ~/.quota. */
function pmssLighttpdWatchdogQuotaExceeded(string $username, string $homeDir): bool
{
    $state = pmssLighttpdWatchdogQuotaStateRead($username, $homeDir);
    return is_array($state) && !empty($state['exceeded']);
}

/** Aggregate deleted blocks for the account on its home filesystem. */
function pmssLighttpdWatchdogQuotaHeldBlocks(
    string $username,
    string $homeDir,
    string $procRoot = '/proc',
    ?callable $statReader = null
): ?int {
    if (!function_exists('posix_getpwnam') || !pmssValidateUsername($username)) {
        return null;
    }

    $userEntry = @posix_getpwnam($username);
    $homeStat = @stat($homeDir);
    if (!is_array($userEntry) || !isset($userEntry['uid']) || !is_array($homeStat) || !isset($homeStat['dev'])) {
        return null;
    }

    return pmssUserProcHeldBlocks((int) $userEntry['uid'], (int) $homeStat['dev'], $procRoot, $statReader);
}

/** Return true when held blocks cover the observed quota overage. */
function pmssLighttpdWatchdogQuotaDescriptorStateMatches(array $quotaState, ?int $heldBlocks): bool
{
    if (empty($quotaState['exceeded']) || $heldBlocks === null || $heldBlocks < 0) {
        return false;
    }
    if (!isset($quotaState['usedBytes'], $quotaState['softLimitBytes'])
        || !is_numeric($quotaState['usedBytes'])
        || !is_numeric($quotaState['softLimitBytes'])) {
        return false;
    }

    $overageBytes = max(0, (int) $quotaState['usedBytes'] - (int) $quotaState['softLimitBytes']);
    if ($overageBytes === 0) {
        return false;
    }
    $requiredBlocks = intdiv($overageBytes, 512) + ($overageBytes % 512 > 0 ? 1 : 0);
    return $heldBlocks >= $requiredBlocks;
}
