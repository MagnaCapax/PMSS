<?php
/**
 * Bonus traffic helpers and CLI handling.
 *
 * Bonus traffic is stored as an integer GiB in /home/<user>/.bonusTraffic.
 * A value of 0 (or missing file) means no bonus.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/trafficLimit.php';

/**
 * Execute the bonus traffic CLI flow. Returns an exit code.
 */
function pmssUserBonusTrafficCli(array $argv): int
{
    if (!pmssUserTrafficCliBootstrap()) {
        return 1;
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

    // Shared helper keeps the missing username contract: "Error: missing username.\n".$usage."\n"
    $exitCode = null;
    $resolvedUser = pmssTrafficLimitResolveCliUserHome($userName, $usage, $exitCode);
    if ($resolvedUser === null) {
        return $exitCode ?? 1;
    }
    $userName = $resolvedUser['user'];
    $homeDir = $resolvedUser['home'];

    if ($show && $unset) {
        fwrite(STDERR, "Error: --show and --unset are mutually exclusive.\n");
        return 2;
    }

    $bonusFile = $homeDir.'/.bonusTraffic';

    if ($show) {
        $bonus = pmssTrafficLimitReadGiBFile($bonusFile);
        echo "Bonus traffic for {$userName}: {$bonus} GiB\n";
        return 0;
    }

    if ($unset) { $bonusRaw = '0'; }

    $err = null;
    $bonusTraffic = pmssTrafficLimitParseGiB($bonusRaw, $err);
    if ($bonusTraffic === null) {
        fwrite(STDERR, "Error: invalid --bonus value (expected integer GiB): ".($err ?: 'invalid')."\n");
        return 2;
    }

    if ($bonusTraffic === 0) {
        if (!pmssTrafficLimitRemoveGiBFile($bonusFile)) {
            fwrite(STDERR, "Error: refusing to remove non-file/symlink: {$bonusFile}\n");
            return 4;
        }
        pmssUserLog($userName, 'bonus traffic unset (GiB add-on removed)');
        echo "Bonus traffic for {$userName} set to 0 GiB\n";
        return 0;
    }

    if (!pmssTrafficLimitWriteGiBFile($bonusFile, (int) $bonusTraffic)) {
        fwrite(STDERR, "Error: failed to write {$bonusFile}\n");
        return 4;
    }
    @chmod($bonusFile, 0664);

    pmssUserLog($userName, sprintf('bonus traffic set to %d GiB (monthly add-on)', $bonusTraffic));

    echo "Bonus traffic for {$userName} set to {$bonusTraffic} GiB\n";
    return 0;
}
