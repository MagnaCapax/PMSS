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

if (!function_exists('pmssTrafficLimitWriteGiBFile')) {
    /**
     * Persist a GiB quota file via the shared atomic file writer.
     */
    function pmssTrafficLimitWriteGiBFile(string $path, int $value): bool
    {
        return pmssIntegerSettingFileWrite($path, $value);
    }
}

if (!function_exists('pmssTrafficLimitEnsureStorageDir')) {
    /**
     * Ensure the runtime quota directory exists as a real directory.
     */
    function pmssTrafficLimitEnsureStorageDir(string $path): bool
    {
        return pmssIntegerSettingStorageDirEnsure($path, 0700);
    }
}

if (!function_exists('pmssTrafficLimitRemoveGiBFile')) {
    /**
     * Remove a persisted quota file when it is a real regular file.
     */
    function pmssTrafficLimitRemoveGiBFile(string $path): bool
    {
        return pmssIntegerSettingFileRemove($path);
    }
}

if (!function_exists('pmssTrafficLimitConvergeFileMode')) {
    /**
     * Apply the requested mode to a real file or directory, verifying fallback state.
     */
    function pmssTrafficLimitConvergeFileMode(string $path, int $mode): bool
    {
        return pmssIntegerSettingPathModeConverge($path, $mode);
    }
}

if (!function_exists('pmssTrafficLimitThrottleFileWrite')) {
    /**
     * Persist the active traffic throttle cap using the shared safe file writer.
     */
    function pmssTrafficLimitThrottleFileWrite(string $path, int $capMbit, ?string &$error = null): bool
    {
        $error = null;
        if ($capMbit < 0) {
            $error = 'invalid throttle cap';
            return false;
        }
        if (!function_exists('pmssUserFilePathIsSafe') || !pmssUserFilePathIsSafe($path)) {
            $error = 'unsafe throttle path';
            return false;
        }
        if (!pmssIntegerSettingFileWrite($path, $capMbit)) {
            $error = 'failed to write throttle file: '.$path;
            return false;
        }
        if (!pmssIntegerSettingPathModeConverge($path, 0644)) {
            $error = 'failed to secure throttle file: '.$path;
            return false;
        }

        return true;
    }
}

if (!function_exists('pmssTrafficLimitThrottleFileRemove')) {
    /**
     * Remove the active traffic throttle cap only after validating the target.
     */
    function pmssTrafficLimitThrottleFileRemove(string $path, ?string &$error = null): bool
    {
        $error = null;
        if (!function_exists('pmssUserFilePathIsSafe') || !pmssUserFilePathIsSafe($path)) {
            $error = 'unsafe throttle path';
            return false;
        }
        if (!pmssIntegerSettingFileRemove($path)) {
            $error = 'failed to remove throttle file: '.$path;
            return false;
        }

        return true;
    }
}

if (!function_exists('pmssTrafficLimitCliUsernameNormalize')) {
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
}

if (!function_exists('pmssTrafficLimitLog')) {
    function pmssTrafficLimitLog(string $user, string $message): void
    {
        echo date('Y-m-d H:i:s') . ": {$message}\n";
        if (function_exists('pmssUserLog') && ($normalizedUser = pmssTrafficLimitCliUsernameNormalize($user)) !== null) {
            pmssUserLog($normalizedUser, $message);
        }
    }
}

if (!function_exists('pmssTrafficLimitMarkerTouch')) {
    function pmssTrafficLimitMarkerTouch(string $user, string $path): bool
    {
        if (!@touch($path)) {
            pmssTrafficLimitLog($user, "traffic throttle marker touch failed ({$path})");
            return false;
        }
        if (!@chmod($path, 0600)) {
            pmssTrafficLimitLog($user, "traffic throttle marker chmod failed ({$path})");
        }
        return true;
    }
}

if (!function_exists('pmssTrafficLimitMarkerRemove')) {
    function pmssTrafficLimitMarkerRemove(string $user, string $path): bool
    {
        if (!file_exists($path) || @unlink($path) || !file_exists($path)) {
            return true;
        }
        pmssTrafficLimitLog($user, "traffic throttle marker removal failed ({$path})");
        return false;
    }
}

if (!function_exists('pmssTrafficLimitThrottleFilePath')) {
    function pmssTrafficLimitThrottleFilePath(string $user, string $homeRoot = '/home'): ?string
    {
        $user = pmssTrafficLimitCliUsernameNormalize($user) ?? '';
        if ($user === '') {
            return null;
        }
        $home = rtrim($homeRoot, '/').'/'.$user;
        if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
            return null;
        }
        $path = $home.'/.throttle';
        return (!function_exists('pmssUserFilePathIsSafe') || pmssUserFilePathIsSafe($path)) ? $path : null;
    }
}

if (!function_exists('pmssTrafficLimitThrottleApply')) {
    function pmssTrafficLimitThrottleApply(string $user, int $trafficCapMbit, bool $enable = true, string $homeRoot = '/home'): bool
    {
        $throttleFile = pmssTrafficLimitThrottleFilePath($user, $homeRoot);
        if ($throttleFile === null) {
            return false;
        }
        $error = null;
        if (!$enable) {
            if (!pmssTrafficLimitThrottleFileRemove($throttleFile, $error)) {
                pmssTrafficLimitLog($user, 'traffic throttle file removal failed ('.($error ?: $throttleFile).')');
                return false;
            }
            return true;
        }
        if (!pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)) {
            pmssTrafficLimitLog($user, 'traffic throttle file write failed ('.($error ?: $throttleFile).')');
            return false;
        }
        return true;
    }
}

if (!function_exists('pmssTrafficLimitPersistTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssTrafficLimitPersistTargetModes(array $targetModes, int $value, ?string &$error = null): bool
    {
        return pmssIntegerSettingTargetModesPersist($targetModes, $value, $error, 'invalid GiB value', true);
    }
}

if (!function_exists('pmssTrafficLimitResolveCliUserHome')) {
    /** @return array<string,mixed>|null */
    function pmssTrafficLimitCliUserAccountLookup(string $userName): ?array
    {
        if (function_exists('pmssUserAccountLookup')) {
            return pmssUserAccountLookup($userName);
        }
        if (function_exists('posix_getpwnam')) {
            $account = @posix_getpwnam($userName);
            return is_array($account) && isset($account['uid']) ? $account : null;
        }

        if (preg_match('/^[a-z][a-z0-9]{0,7}$/D', $userName) !== 1) {
            return null;
        }

        if (($parts = pmssColonRecordFieldsLookup('/etc/passwd', $userName, 7)) !== null) {
            return [
                'name' => (string) $parts[0],
                'uid' => (int) $parts[2],
                'gid' => (int) $parts[3],
                'dir' => (string) $parts[5],
            ];
        }

        return null;
    }

    /**
     * @param mixed $rawUserName
     * @return array{user:string,home:string}|null
     */
    function pmssTrafficLimitResolveCliUserHome($rawUserName, string $usage, ?int &$exitCode = null): ?array
    {
        $exitCode = null;
        $fail = static function (int $rc, string $message) use (&$exitCode): ?array { fwrite(STDERR, $message); $exitCode = $rc; return null; };
        $userName = pmssTrafficLimitCliUsernameNormalize((string) $rawUserName);
        $normalizedRawUserName = function_exists('pmssNormalizeUsername')
            ? pmssNormalizeUsername((string) $rawUserName)
            : strtolower(trim((string) $rawUserName));
        if ($userName === '') {
            return $fail(2, "Error: missing username.\n".$usage."\n");
        }
        if ($userName === null) {
            return $fail(2, "Error: invalid username: {$normalizedRawUserName}\n");
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return $fail(1, "Error: must run as root.\n");
        }
        $account = pmssTrafficLimitCliUserAccountLookup($userName);
        $homeDir = is_array($account) && isset($account['dir']) ? (string) $account['dir'] : "/home/{$userName}";
        if (!is_dir($homeDir) || is_link($homeDir)) {
            return $fail(3, "Error: no such user: {$userName}\n");
        }
        return ['user' => $userName, 'home' => $homeDir];
    }
}

if (!function_exists('pmssUserTrafficCliBootstrap')) {
    function pmssUserTrafficCliBootstrap(): bool
    {
        if (!function_exists('pmssRequireCli') && is_file(dirname(__DIR__).'/runtime.php')) {
            require_once dirname(__DIR__).'/runtime.php';
        }
        if (!function_exists('pmssRequireCli') || !pmssRequireCli('This script must be run from the command line.', null)) {
            return false;
        }

        $optionParser = dirname(__DIR__).'/cli/optionParser.php';
        if (!is_file($optionParser)) {
            fwrite(STDERR, "Error: missing CLI option parser.\n");
            return false;
        }
        require_once $optionParser;
        $userLogDependency = __DIR__.'/log.php';
        if (!function_exists('pmssUserLog') && is_file($userLogDependency)) {
            require_once $userLogDependency;
        }

        return true;
    }
}

if (!function_exists('pmssUserGiBSettingUsageText')) {
    /** Build the shared CLI usage text for per-user GiB quota commands. */
    function pmssUserGiBSettingUsageText(
        string $scriptName,
        string $valueOption,
        string $unitNote,
        string $removalNote
    ): string {
        return rtrim(<<<TEXT
Usage:
  ./{$scriptName} --user=<username> --{$valueOption}=<GiB>
  ./{$scriptName} --user=<username> --show
  ./{$scriptName} --user=<username> --unset
  ./{$scriptName} <username> <GiB>

Notes:
  - {$unitNote}
  - {$removalNote}
TEXT
        );
    }
}

if (!function_exists('pmssUserGiBSettingCli')) {
    /** @param array<string,mixed> $spec */
    function pmssUserGiBSettingCli(array $argv, array $spec): int
    {
        if (!pmssUserTrafficCliBootstrap()) return 1;
        $usage = isset($spec['usage']) && is_string($spec['usage']) ? $spec['usage'] : '';
        $parsed = pmssParseCliTokens($argv);
        if (pmssCliHelpTextEmitIfRequested($parsed, $usage."\n")) return 0;

        $userName = (string) pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
        $show = (pmssCliOption($parsed, 'show') === true);
        $unset = (pmssCliOption($parsed, 'unset') === true);
        $valueRaw = pmssCliOption($parsed, (string) $spec['valueOption'], (string) $spec['valueShortOption'], $parsed['arguments'][1] ?? null);

        $exitCode = null;
        $resolvedUser = pmssTrafficLimitResolveCliUserHome($userName, $usage, $exitCode);
        if ($resolvedUser === null) return $exitCode ?? 1;

        $userName = $resolvedUser['user'];
        $homeDir = $resolvedUser['home'];
        if ($show && $unset) {
            fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
            return 2;
        }

        $targetModes = call_user_func($spec['targetModesResolver'], $userName, $homeDir);

        if ($show) {
            echo sprintf("%s for %s: %d GiB\n", $spec['subjectLabel'], $userName, pmssTrafficLimitReadGiBFile((string) array_key_first($targetModes)));
            return 0;
        }

        if ($unset) $valueRaw = '0';

        $error = null;
        $value = pmssTrafficLimitParseGiB($valueRaw, $error);
        if ($value === null) {
            fwrite(STDERR, sprintf("Error: invalid %s value (expected integer GiB): %s\n", $spec['invalidOptionLabel'], $error ?: 'invalid'));
            return 2;
        }

        $prepareTargetModes = $spec['prepareTargetModes'] ?? null;
        if ($prepareTargetModes !== null && !call_user_func($prepareTargetModes, $targetModes)) {
            fwrite(STDERR, (string) ($spec['prepareError'] ?? 'Error: failed to prepare persisted targets')."\n");
            return 4;
        }

        $removingValue = ($value === 0);
        $persistError = null;
        if (!pmssTrafficLimitPersistTargetModes($targetModes, $value, $persistError)) {
            fwrite(STDERR, 'Error: '.($persistError ?: 'failed to persist targets')."\n");
            return 4;
        }

        if (function_exists('pmssUserLog')) {
            pmssUserLog($userName, $removingValue ? (string) $spec['unsetLogMessage'] : sprintf((string) $spec['setLogTemplate'], $value));
        }

        echo sprintf("%s for %s set %s %d GiB\n", $spec['subjectLabel'], $userName, $spec['setPreposition'], $value);
        return 0;
    }
}

if (!function_exists('pmssTrafficLimitCliTargetModes')) {
    /** @return array<string,int> */
    function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array
    {
        return [pmssIntegerSettingRuntimeUserPath('trafficLimits', $userName) => 0600, pmssIntegerSettingUserHomePath($userName, '.trafficLimit', $homeDir) => 0664];
    }
}

if (!function_exists('pmssTrafficLimitCliPrepareTargetModes')) {
    /** @param array<string,int> $targetModes */
    function pmssTrafficLimitCliPrepareTargetModes(array $targetModes): bool
    {
        $runtimePath = array_key_first($targetModes);
        return is_string($runtimePath) && $runtimePath !== ''
            && pmssTrafficLimitEnsureStorageDir(dirname($runtimePath));
    }
}

if (!function_exists('pmssUserTrafficLimitCli')) {
    function pmssUserTrafficLimitCli(array $argv, ?string $usage = null): int
    {
        if (!is_string($usage) || $usage === '') {
            $usage = pmssUserGiBSettingUsageText(
                'userTrafficLimit.php',
                'limit',
                'Limit unit is GiB (monthly quota).',
                'Use 0 (or --unset) to remove a limit.'
            );
        }
        return pmssUserGiBSettingCli($argv, [
            'usage'              => $usage,
            'valueOption'        => 'limit',
            'valueShortOption'   => 'l',
            'subjectLabel'       => 'Traffic limit',
            'setPreposition'     => 'at',
            'invalidOptionLabel' => '--limit',
            'setLogTemplate'     => 'traffic limit set to %d GiB (monthly quota)',
            'unsetLogMessage'    => 'traffic limit unset (GiB quota removed)',
            'targetModesResolver' => 'pmssTrafficLimitCliTargetModes',
            'prepareTargetModes' => 'pmssTrafficLimitCliPrepareTargetModes',
            'prepareError' => 'Error: failed to prepare /etc/seedbox/runtime/trafficLimits',
        ]);
    }
}

if (!function_exists('pmssUserBonusTrafficCli')) {
    /**
     * Reuse the shared GiB-setting CLI for per-user bonus traffic.
     */
    function pmssUserBonusTrafficCli(array $argv): int
    {
        return pmssUserGiBSettingCli($argv, [
            'usage'               => pmssUserGiBSettingUsageText(
                'userBonusTraffic.php',
                'bonus',
                'Bonus unit is GiB (monthly quota add-on).',
                'Use 0 (or --unset) to remove the bonus.'
            ),
            'valueOption'         => 'bonus',
            'valueShortOption'    => 'b',
            'subjectLabel'        => 'Bonus traffic',
            'setPreposition'      => 'to',
            'invalidOptionLabel'  => '--bonus',
            'setLogTemplate'      => 'bonus traffic set to %d GiB (monthly add-on)',
            'unsetLogMessage'     => 'bonus traffic unset (GiB add-on removed)',
            'targetModesResolver' => static function (string $userName, string $homeDir): array {
                return [$homeDir.'/.bonusTraffic' => 0664];
            },
        ]);
    }
}
