#!/usr/bin/env php
<?php
/**
 * Configure per-user traffic limits from the command line.
 *
 * Traffic limit semantics:
 * - The limit is a monthly traffic quota stored as an integer GiB.
 * - `scripts/cron/trafficLimits.php` consumes /etc/seedbox/runtime/trafficLimits/<user>.
 * - The user-facing web UI reads /home/<user>/.trafficLimit.
 *
 * Usage:
 *   ./userTrafficLimit.php --user=<username> --limit=<GiB>
 *   ./userTrafficLimit.php --user=<username> --show
 *   ./userTrafficLimit.php --user=<username> --unset
 *   ./userTrafficLimit.php <username> <GiB>
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once '/scripts/lib/cli/optionParser.php';
$trafficLimitLib = '/scripts/lib/user/trafficLimit.php';
if (is_file($trafficLimitLib)) {
    require_once $trafficLimitLib;
}
$dirHelper = '/scripts/lib/user/directories.php';
if (is_file($dirHelper)) {
    require_once $dirHelper;
}
$pmssUserLogPath = '/scripts/lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
$userLifecycle = '/scripts/lib/userLifecycle.php';
if (is_file($userLifecycle)) {
    require_once $userLifecycle;
}

function pmssTrafficLimitCliUsage(): string
{
    return implode(
        "\n",
        array(
            'Usage:',
            '  ./userTrafficLimit.php --user=<username> --limit=<GiB>',
            '  ./userTrafficLimit.php --user=<username> --show',
            '  ./userTrafficLimit.php --user=<username> --unset',
            '  ./userTrafficLimit.php <username> <GiB>',
            '',
            'Notes:',
            '  - Limit unit is GiB (monthly quota).',
            '  - Use 0 (or --unset) to remove a limit.',
        )
    );
}

$usage = pmssTrafficLimitCliUsage();
$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));

if (pmssCliOption($parsed, 'help', 'h')) {
    echo $usage."\n";
    exit(0);
}

$userName = (string)pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
$show = (pmssCliOption($parsed, 'show') === true);
$unset = (pmssCliOption($parsed, 'unset') === true);
$limitRaw = pmssCliOption($parsed, 'limit', 'l', $parsed['arguments'][1] ?? null);

if ($userName === '') {
    fwrite(STDERR, "Error: missing username.\n".$usage."\n");
    exit(2);
}

$userName = function_exists('pmssNormalizeUsername')
    ? pmssNormalizeUsername($userName)
    : strtolower(trim($userName));

if (function_exists('pmssValidateUsername') && !pmssValidateUsername($userName)) {
    fwrite(STDERR, "Error: invalid username: {$userName}\n");
    exit(2);
}

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Error: must run as root.\n");
    exit(1);
}

// Check if user exists
$pw = function_exists('posix_getpwnam') ? @posix_getpwnam($userName) : false;
$homeDir = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : "/home/{$userName}";
if (!is_dir($homeDir) || is_link($homeDir)) {
    fwrite(STDERR, "Error: no such user: {$userName}\n");
    exit(3);
}

//Save the configured limit
$runtimeDir = '/etc/seedbox/runtime/trafficLimits';
$userTrafficFile = "{$runtimeDir}/{$userName}";
$userHomeFile = "{$homeDir}/.trafficLimit";

if ($show && $unset) {
    fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
    exit(2);
}

if ($show) {
    $limit = 0;
    if (is_file($userTrafficFile) && !is_link($userTrafficFile)) {
        $raw = trim((string) @file_get_contents($userTrafficFile));
        $err = null;
        if (function_exists('pmssTrafficLimitParseGiB')) {
            $parsedLimit = pmssTrafficLimitParseGiB($raw, $err);
            if ($parsedLimit !== null) {
                $limit = $parsedLimit;
            }
        }
    }
    echo "Traffic limit for {$userName}: {$limit} GiB\n";
    exit(0);
}

if ($unset) {
    $limitRaw = '0';
}

$err = null;
if (!function_exists('pmssTrafficLimitParseGiB')) {
    fwrite(STDERR, "Error: missing traffic limit parser helper.\n");
    exit(1);
}
$trafficLimit = pmssTrafficLimitParseGiB($limitRaw, $err);
if ($trafficLimit === null) {
    fwrite(STDERR, "Error: invalid --limit value (expected integer GiB): ".($err ?: 'invalid')."\n");
    exit(2);
}

if (function_exists('pmssEnsureDir')) {
    pmssEnsureDir($runtimeDir, 0700, 'root', 'root');
} elseif (!is_dir($runtimeDir)) {
    @mkdir($runtimeDir, 0755, true);
    @chmod($runtimeDir, 0700);
}

$writeFileAtomic = static function (string $path, string $content): bool {
    if (strpos($path, "\0") !== false) {
        return false;
    }
    if (file_exists($path) && !is_file($path)) {
        return false;
    }
    if (is_link($path) || is_link(dirname($path))) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $tmp = @tempnam($dir, basename($path).'.pmss-tmp-');
    if ($tmp === false) {
        return false;
    }
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
};

if ($trafficLimit === 0) {
    foreach ([$userTrafficFile, $userHomeFile] as $target) {
        if (file_exists($target)) {
            if (!is_file($target) || is_link($target)) {
                fwrite(STDERR, "Error: refusing to remove non-file/symlink: {$target}\n");
                exit(4);
            }
            @unlink($target);
        }
    }
    if (function_exists('pmssUserLog')) {
        pmssUserLog($userName, 'traffic limit unset (GiB quota removed)');
    }
    echo "Traffic limit for {$userName} set at 0 GiB\n";
    exit(0);
}

if (file_exists($userTrafficFile)) {
    // noop: retained for back-compat; file written below
}

if (!$writeFileAtomic($userTrafficFile, (string) $trafficLimit)) {
    fwrite(STDERR, "Error: failed to write {$userTrafficFile}\n");
    exit(4);
}
@chmod($userTrafficFile, 0600);

if (!$writeFileAtomic($userHomeFile, (string) $trafficLimit)) {
    fwrite(STDERR, "Error: failed to write {$userHomeFile}\n");
    exit(4);
}
@chmod($userHomeFile, 0664);

if (function_exists('pmssUserLog')) {
    pmssUserLog($userName, sprintf('traffic limit set to %d GiB (monthly quota)', $trafficLimit));
}

echo "Traffic limit for {$userName} set at {$trafficLimit} GiB\n";
