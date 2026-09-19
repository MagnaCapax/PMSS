<?php
/** Shared GiB CLI execution and account resolution. Loaded by trafficLimit.php.
 * @license GPL-3.0-only
 */

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

if (!function_exists('pmssTrafficLimitResolveCliUserHome')) {
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
        // Normalize returns a valid name or null, never '' — test the raw
        // input for emptiness or the missing-username branch is unreachable.
        if ($normalizedRawUserName === '') {
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

/** @param array<string,mixed> $spec */
function pmssUserGiBSettingCli(array $argv, array $spec): int
{
    if (!pmssUserTrafficCliBootstrap()) return 1;
    $usage = isset($spec['usage']) && is_string($spec['usage']) ? $spec['usage'] : '';
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage."\n")) === null) return 0;

    $userName = (string) pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
    $show = (pmssCliOption($parsed, 'show') === true); $unset = (pmssCliOption($parsed, 'unset') === true);
    $valueRaw = pmssCliOption($parsed, (string) $spec['valueOption'], (string) $spec['valueShortOption'], $parsed['arguments'][1] ?? null);

    $exitCode = null;
    $resolvedUser = pmssTrafficLimitResolveCliUserHome($userName, $usage, $exitCode);
    if ($resolvedUser === null) return $exitCode ?? 1;

    $userName = $resolvedUser['user'];
    $homeDir = $resolvedUser['home'];
    if (pmssCliRejectMutuallyExclusiveOptions($parsed, ['show', 'unset'], "Error: --show and --unset are mutually exclusive.\n", 'bare')) return 2;

    $targetModes = call_user_func($spec['targetModesResolver'], $userName, $homeDir);

    if ($show) {
        printf("%s for %s: %d GiB\n", $spec['subjectLabel'], $userName, pmssTrafficLimitReadGiBFile((string) array_key_first($targetModes)));
        return 0;
    }

    if ($unset) $valueRaw = '0';

    $error = null;
    $value = pmssTrafficLimitParseGiB($valueRaw, $error);
    if ($value === null) {
        return pmssCliReturnWithStderr(sprintf("Error: invalid %s value (expected integer GiB): %s\n", $spec['invalidOptionLabel'], $error ?: 'invalid'), 2);
    }

    $prepareTargetModes = $spec['prepareTargetModes'] ?? null;
    if ($prepareTargetModes !== null && !call_user_func($prepareTargetModes, $targetModes)) {
        return pmssCliReturnWithStderr((string) ($spec['prepareError'] ?? 'Error: failed to prepare persisted targets')."\n", 4);
    }

    $removingValue = ($value === 0);
    $persistError = null;
    if (!pmssTrafficLimitPersistTargetModes($targetModes, $value, $persistError)) {
        return pmssCliReturnWithStderr('Error: '.($persistError ?: 'failed to persist targets')."\n", 4);
    }

    if (function_exists('pmssUserLog')) {
        pmssUserLog($userName, $removingValue ? (string) $spec['unsetLogMessage'] : sprintf((string) $spec['setLogTemplate'], $value));
    }

    printf("%s for %s set %s %d GiB\n", $spec['subjectLabel'], $userName, $spec['setPreposition'], $value);
    return 0;
}
