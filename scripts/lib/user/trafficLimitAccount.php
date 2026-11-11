<?php
/** Traffic-setting CLI account resolution. Loaded by trafficLimitCli.php.
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
