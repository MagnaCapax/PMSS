<?php
/**
 * Bonus traffic helpers and CLI handling.
 *
 * Bonus traffic is stored as an integer GiB in /home/<user>/.bonusTraffic.
 * A value of 0 (or missing file) means no bonus.
 *
 * @license GPL-3.0-only
 */

foreach ([__DIR__.'/trafficLimit.php', dirname(__DIR__).'/lighttpd/userFileWrite.php'] as $dependency) {
    if (is_file($dependency)) {
        require_once $dependency;
    }
}

/**
 * Read bonus traffic GiB from a file, returning 0 when missing/invalid.
 */
function pmssBonusTrafficReadGiB(string $path): int
{
    if (!is_file($path) || is_link($path)) {
        return 0;
    }
    $raw = trim((string) @file_get_contents($path));
    if ($raw === '') {
        return 0;
    }
    $err = null;
    $parsed = function_exists('pmssTrafficLimitParseGiB')
        ? pmssTrafficLimitParseGiB($raw, $err)
        : (is_numeric($raw) ? (int) $raw : null);
    return ($parsed !== null && $parsed > 0) ? (int) $parsed : 0;
}

/**
 * Atomically write bonus traffic GiB to a file.
 */
function pmssBonusTrafficWriteGiB(string $path, int $value): bool
{
    return $value >= 0
        && function_exists('pmssAtomicWriteFile')
        && pmssAtomicWriteFile($path, (string) $value);
}

/**
 * Remove the bonus traffic file when safe to do so.
 */
function pmssBonusTrafficRemove(string $path): bool
{
    return !file_exists($path)
        || (is_file($path) && !is_link($path) && @unlink($path));
}

/**
 * Execute the bonus traffic CLI flow. Returns an exit code.
 */
function pmssUserBonusTrafficCli(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This script must be run from the command line.\n");
        return 1;
    }

    if (!is_file('/scripts/lib/cli/optionParser.php')) {
        fwrite(STDERR, "Error: missing CLI option parser.\n");
        return 1;
    }
    require_once '/scripts/lib/cli/optionParser.php';

    foreach (['/scripts/lib/userLifecycle.php', '/scripts/lib/user/log.php'] as $dependency) {
        if (is_file($dependency)) {
            require_once $dependency;
        }
    }

    $parsed = pmssParseCliTokens($argv);
    $usage = rtrim(<<<'TEXT'
Usage:
  ./userBonusTraffic.php --user=<username> --bonus=<GiB>
  ./userBonusTraffic.php --user=<username> --show
  ./userBonusTraffic.php --user=<username> --unset
  ./userBonusTraffic.php <username> <GiB>

Notes:
  - Bonus unit is GiB (monthly quota add-on).
  - Use 0 (or --unset) to remove the bonus.
TEXT
    );
    if (pmssCliOption($parsed, 'help', 'h')) {
        echo $usage."\n";
        return 0;
    }

    $userName = (string) pmssCliOption($parsed, 'user', 'u', $parsed['arguments'][0] ?? '');
    $show = (pmssCliOption($parsed, 'show') === true);
    $unset = (pmssCliOption($parsed, 'unset') === true);
    $bonusRaw = pmssCliOption($parsed, 'bonus', 'b', $parsed['arguments'][1] ?? null);

    if ($userName === '') {
        fwrite(STDERR, "Error: missing username.\n".$usage."\n");
        return 2;
    }

    $userName = function_exists('pmssNormalizeUsername')
        ? pmssNormalizeUsername($userName)
        : strtolower(trim($userName));

    if (function_exists('pmssValidateUsername') && !pmssValidateUsername($userName)) {
        fwrite(STDERR, "Error: invalid username: {$userName}\n");
        return 2;
    }

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "Error: must run as root.\n");
        return 1;
    }

    $pw = function_exists('posix_getpwnam') ? @posix_getpwnam($userName) : false;
    $homeDir = is_array($pw) && isset($pw['dir']) ? (string) $pw['dir'] : "/home/{$userName}";
    if (!is_dir($homeDir) || is_link($homeDir)) {
        fwrite(STDERR, "Error: no such user: {$userName}\n");
        return 3;
    }

    if ($show && $unset) {
        fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
        return 2;
    }

    $bonusFile = $homeDir.'/.bonusTraffic';

    if ($show) {
        $bonus = pmssBonusTrafficReadGiB($bonusFile);
        echo "Bonus traffic for {$userName}: {$bonus} GiB\n";
        return 0;
    }

    if ($unset) {
        $bonusRaw = '0';
    }

    if (!function_exists('pmssTrafficLimitParseGiB')) {
        fwrite(STDERR, "Error: missing bonus traffic parser helper.\n");
        return 1;
    }
    $err = null;
    $bonusTraffic = pmssTrafficLimitParseGiB($bonusRaw, $err);
    if ($bonusTraffic === null) {
        fwrite(STDERR, "Error: invalid --bonus value (expected integer GiB): ".($err ?: 'invalid')."\n");
        return 2;
    }

    if ($bonusTraffic === 0) {
        if (!pmssBonusTrafficRemove($bonusFile)) {
            fwrite(STDERR, "Error: refusing to remove non-file/symlink: {$bonusFile}\n");
            return 4;
        }
        if (function_exists('pmssUserLog')) {
            pmssUserLog($userName, 'bonus traffic unset (GiB add-on removed)');
        }
        echo "Bonus traffic for {$userName} set to 0 GiB\n";
        return 0;
    }

    if (!pmssBonusTrafficWriteGiB($bonusFile, (int) $bonusTraffic)) {
        fwrite(STDERR, "Error: failed to write {$bonusFile}\n");
        return 4;
    }
    @chmod($bonusFile, 0664);

    if (function_exists('pmssUserLog')) {
        pmssUserLog($userName, sprintf('bonus traffic set to %d GiB (monthly add-on)', $bonusTraffic));
    }

    echo "Bonus traffic for {$userName} set to {$bonusTraffic} GiB\n";
    return 0;
}
