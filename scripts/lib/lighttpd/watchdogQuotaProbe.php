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

/** Parse the charged, soft-limit, and exhaustion state from quota output. */
function pmssLighttpdWatchdogQuotaStateParse(string $output): ?array
{
    $fallback = null;
    foreach (preg_split('/\r?\n/', $output) as $line) {
        $tokens = pmssConfigLineColumns($line, 4, []);
        if ($tokens === [] || strpos($tokens[0], '/') !== 0) {
            continue;
        }

        $state = [];
        foreach ([1 => 'usedBytes', 2 => 'softLimitBytes', 3 => 'hardLimitBytes'] as $index => $field) {
            $bytes = pmssParseSizeToBytes(rtrim((string) $tokens[$index], '*'));
            if ($bytes === null) {
                continue 2;
            }
            $state[$field] = (int) $bytes;
        }

        // Prefer the first exhausted row; otherwise retain the last valid row.
        $state['exceeded'] = strpos($tokens[1], '*') !== false || strpos($tokens[4] ?? '', '*') !== false;
        if ($state['exceeded']) {
            return $state;
        }
        $fallback = $state;
    }

    return $fallback;
}

/** Read live quota state, falling back to ~/.quota only when output is empty. */
function pmssLighttpdWatchdogQuotaStateRead(string $username, string $homeDir): ?array
{
    $quotaResult = pmssLighttpdWatchdogCommandCapture('quota', '-u '.escapeshellarg($username).' -s 2>/dev/null');
    $output = $quotaResult['output'] ?? '';
    if ($output === '') {
        $output = pmssReadRegularFileContents(rtrim($homeDir, '/').'/.quota');
    }
    if ($output === null || $output === '') {
        return null;
    }

    $state = pmssLighttpdWatchdogQuotaStateParse($output);
    if ($state === null) {
        return null;
    }

    $state['exceeded'] = pmssLighttpdWatchdogQuotaOutputShowsExhaustion($output);
    return $state;
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
