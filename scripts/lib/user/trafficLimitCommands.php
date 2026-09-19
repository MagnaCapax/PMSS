<?php
/** Traffic quota command specifications and target preparation. Loaded by trafficLimit.php.
 * @license GPL-3.0-only
 */

if (!function_exists('pmssTrafficLimitCliTargetModes')) {
    /** @return array<string,int> */
    function pmssTrafficLimitCliTargetModes(string $userName, string $homeDir): array
    {
        return [pmssIntegerSettingRuntimeUserPath('trafficLimits', $userName) => 0600, pmssIntegerSettingUserHomePath($userName, '.trafficLimit', $homeDir) => 0664];
    }
}

/** @param array<string,int> $targetModes */
function pmssTrafficLimitCliPrepareTargetModes(array $targetModes): bool
{
    $runtimePath = array_key_first($targetModes);
    return is_string($runtimePath) && $runtimePath !== ''
        && pmssIntegerSettingStorageDirEnsure(dirname($runtimePath));
}

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
