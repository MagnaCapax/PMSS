<?php
/**
 * Traffic limit helpers.
 *
 * Traffic limits in PMSS are stored as an integer monthly quota in GiB.
 *
 * Persistence paths (written by /scripts/util/userTrafficLimit.php):
 * - /etc/seedbox/runtime/trafficLimits/<user> (consumed by scripts/cron/trafficLimits.php)
 * - /home/<user>/.trafficLimit (user-visible; web UI reads this)
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/integerSetting.php';
require_once __DIR__.'/trafficThrottlePolicy.php';

if (!function_exists('pmssTrafficLimitParseGiB')) {
    /**
     * Parse a traffic limit value expressed as an integer GiB.
     *
     * Accepts:
     * - int (>= 0)
     * - numeric strings like "500"
     * - optional GiB suffix like "500GiB" (case-insensitive)
     *
     * @param mixed       $raw
     * @param string|null $error Set to a short reason on failure.
     *
     * @return int|null GiB value on success, null on failure.
     */
    function pmssTrafficLimitParseGiB($raw, ?string &$error = null): ?int
    {
        return pmssIntegerSettingParseNonNegative($raw, 'GiB', $error);
    }
}
if (!function_exists('pmssTrafficLimitReadGiBFile')) {
    /**
     * Read a persisted GiB quota file, returning 0 for missing or invalid data.
     */
    function pmssTrafficLimitReadGiBFile(string $path): int
    {
        return pmssIntegerSettingFileRead($path, 'pmssTrafficLimitParseGiB');
    }
}

if (!function_exists('pmssTrafficLimitStateRead')) {
    /** @return array{limitGiB:int,bonusGiB:int,effectiveLimitGiB:int} */
    function pmssTrafficLimitStateRead(string $limitPath, string $bonusPath = ''): array
    {
        $limitGiB = pmssTrafficLimitReadGiBFile($limitPath);
        $bonusGiB = ($bonusPath !== '') ? pmssTrafficLimitReadGiBFile($bonusPath) : 0;

        return ['limitGiB' => $limitGiB, 'bonusGiB' => $bonusGiB, 'effectiveLimitGiB' => ($limitGiB > 0) ? ($limitGiB + $bonusGiB) : 0];
    }
}

function pmssTrafficLimitCliUsernameNormalize(string $rawUserName): ?string
{
    if (function_exists('pmssUsernameNormalizeIfValid')) {
        return pmssUsernameNormalizeIfValid($rawUserName);
    }
    $normalized = function_exists('pmssNormalizeUsername')
        ? pmssNormalizeUsername($rawUserName)
        : strtolower(trim($rawUserName));
    return preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized) === 1 ? $normalized : null;
}

/** @param array<string,int> $targetModes */
function pmssTrafficLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool
{
    return pmssIntegerSettingTargetModesPersist($targetModes, $value, $error, 'invalid GiB value', true);
}

/**
 * Recreate the customer-visible home limit file from the root-side runtime file.
 */
function pmssTrafficLimitHomeArtifactReconcile(
    string $userName,
    ?string $homeDir = null,
    ?string $runtimeDir = null,
    ?callable $logger = null
): bool {
    $log = $logger ?: function (string $_message): void { };
    $normalized = pmssTrafficLimitCliUsernameNormalize($userName);
    if ($normalized === null || $normalized !== $userName) {
        $log('traffic limit home artifact skipped: invalid username');
        return false;
    }

    $homeLimitPath = pmssIntegerSettingUserHomePath($userName, '.trafficLimit', $homeDir);
    $runtimeLimitPath = pmssIntegerSettingRuntimeUserPath('trafficLimits', $userName, $runtimeDir);
    if (is_file($homeLimitPath) && !is_link($homeLimitPath)) {
        return true;
    }
    if (file_exists($homeLimitPath) || is_link($homeLimitPath)) {
        $log('traffic limit home artifact skipped: unsafe existing .trafficLimit target');
        return false;
    }
    if (!pmssRegularFilePathIsReadable($runtimeLimitPath)) {
        return true;
    }

    $raw = pmssReadRegularFileTrimmed($runtimeLimitPath);
    $error = null;
    $limitGiB = pmssTrafficLimitParseGiB($raw, $error);
    if ($limitGiB === null) {
        $log('traffic limit home artifact skipped: invalid runtime limit ('.($error ?: 'invalid').')');
        return false;
    }
    if (!pmssIntegerSettingFileWrite($homeLimitPath, $limitGiB)
        || !pmssIntegerSettingPathModeConverge($homeLimitPath, 0664)) {
        $log('traffic limit home artifact reconciliation failed');
        return false;
    }

    $log('traffic limit home artifact reconciled from runtime limit');
    return true;
}

require_once __DIR__.'/trafficLimitThrottle.php';
require_once __DIR__.'/trafficLimitCli.php';
require_once __DIR__.'/trafficLimitCommands.php';
