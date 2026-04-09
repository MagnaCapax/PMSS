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
    $usage = pmssUserGiBSettingUsageText(
        'userBonusTraffic.php',
        'bonus',
        'Bonus unit is GiB (monthly quota add-on).',
        'Use 0 (or --unset) to remove the bonus.'
    );
    // Shared helper keeps the missing username contract: "Error: missing username.\n".$usage."\n"

    return pmssUserGiBSettingCli($argv, [
        'usage'               => $usage,
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
