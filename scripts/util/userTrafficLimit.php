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

require_once __DIR__.'/../lib/runtime.php';

pmssRequireCli();

require_once '/scripts/lib/cli/optionParser.php';
foreach ([
    '/scripts/lib/user/trafficLimit.php',
    '/scripts/lib/user/directories.php',
    '/scripts/lib/user/log.php',
    '/scripts/lib/userLifecycle.php',
] as $dependency) {
    if (is_file($dependency)) {
        require_once $dependency;
    }
}

$usage = rtrim(<<<'TEXT'
Usage:
  ./userTrafficLimit.php --user=<username> --limit=<GiB>
  ./userTrafficLimit.php --user=<username> --show
  ./userTrafficLimit.php --user=<username> --unset
  ./userTrafficLimit.php <username> <GiB>

Notes:
  - Limit unit is GiB (monthly quota).
  - Use 0 (or --unset) to remove a limit.
TEXT
);
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

requireRoot();

// Check if user exists
$pw = pmssUserAccountLookup($userName);
$homeDir = $pw !== null && isset($pw['dir']) ? (string) $pw['dir'] : "/home/{$userName}";
if (!is_dir($homeDir) || is_link($homeDir)) {
    fwrite(STDERR, "Error: no such user: {$userName}\n");
    exit(3);
}

//Save the configured limit
$runtimeDir = '/etc/seedbox/runtime/trafficLimits';
$userTrafficFile = "{$runtimeDir}/{$userName}";
$userHomeFile = "{$homeDir}/.trafficLimit";
$targetModes = [$userTrafficFile => 0600, $userHomeFile => 0664];

if ($show && $unset) {
    fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
    exit(2);
}

if ($show) {
    $limit = function_exists('pmssTrafficLimitReadGiBFile')
        ? pmssTrafficLimitReadGiBFile($userTrafficFile)
        : 0;
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

if ($trafficLimit === 0) {
    foreach (array_keys($targetModes) as $target) {
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

foreach ($targetModes as $target => $mode) {
    if (!function_exists('pmssTrafficLimitWriteGiBFile') || !pmssTrafficLimitWriteGiBFile($target, $trafficLimit)) {
        fwrite(STDERR, "Error: failed to write {$target}\n");
        exit(4);
    }
    @chmod($target, $mode);
}

if (function_exists('pmssUserLog')) {
    pmssUserLog($userName, sprintf('traffic limit set to %d GiB (monthly quota)', $trafficLimit));
}

echo "Traffic limit for {$userName} set at {$trafficLimit} GiB\n";
