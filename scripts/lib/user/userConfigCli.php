<?php
/**
 * Canonical CLI metadata for per-user configuration resources.
 *
 * addUser.php and userConfig.php share the same resource knobs but expose them
 * through different legacy positional layouts. Keep the mapping in one place so
 * wrappers and reconfiguration stay in lockstep.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../cli/helpText.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/userConfigResourceSpecs.php';
require_once __DIR__.'/userConfigCgroup.php';
require_once __DIR__.'/userConfigParse.php';

/** @return array<int,string> Stable resource-key groups for CLI help rendering. */
function pmssUserConfigCliResourceGroupKeys(string $group): array
{
    $specs = pmssUserConfigCliResourceSpecs();
    if ($group === 'addUserPositionals') {
        return array_keys(array_filter($specs, static function (array $spec): bool {
            return isset($spec['addUserLegacyIndex']) && $spec['addUserLegacyIndex'] <= 6;
        }));
    }
    if ($group === 'addUserPrimaryOptions' || $group === 'addUserAdvancedOptions') {
        return array_slice(array_keys($specs), $group === 'addUserPrimaryOptions' ? 0 : 3, $group === 'addUserPrimaryOptions' ? 3 : null);
    }
    if ($group !== 'userConfigPositionals' && $group !== 'userConfigNamedOptions') return [];
    $positionals = pmssUserConfigCliResourceSpecs('userConfigIndex');
    uasort($positionals, static function (array $left, array $right): int { return $left['userConfigIndex'] <=> $right['userConfigIndex']; });
    $keys = array_keys($positionals);
    if ($group === 'userConfigNamedOptions') array_splice($keys, 1, 0, ['iopsLimit']);
    return $keys;
}

/** @return array<int,string> Render resource help lines from the shared spec table. */
function pmssUserConfigCliResourceHelpLines(string $group, string $labelKey, array $descriptionSuffixes = []): array
{
    $lines = []; $specs = pmssUserConfigCliResourceSpecs(); foreach (pmssUserConfigCliResourceGroupKeys($group) as $key) { isset($specs[$key][$labelKey]) && $lines[] = pmssCliHelpLine($specs[$key][$labelKey], $specs[$key]['description'].($descriptionSuffixes[$key] ?? '')); } return $lines;
}

/** Render the canonical userConfig.php help output. */
function pmssUserConfigCliUsage(): string
{
    $useColor = pmssCliHelpSupportsColor();
    $derivedDefault = pmssCliHelpDim(' (default: auto-derived from RAM when omitted)', $useColor);
    $unchangedDefault = pmssCliHelpDim(' (default: leave current slice policy unchanged)', $useColor);
    $resourceDescriptionSuffixes = [
        'CPUWeight' => $derivedDefault,
        'IOWeight' => $derivedDefault,
        'cpuQuotaPercent' => $unchangedDefault,
    ];
    return pmssCliHelpSectionText([
        'Usage' => [
            '  ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT] [IO_LATENCY_MS] [IO_COST_QOS] [IO_COST_MODEL]',
            '  ./userConfig.php USERNAME [RESOURCE_OPTIONS]',
            '  ./userConfig.php USERNAME --welcome-message=HTML',
        ],
        'Positional Parameters' => array_merge([
            pmssCliHelpLine('USERNAME', 'Existing PMSS username; lowercase [a-z][a-z0-9]{2,7}.'),
            pmssCliHelpLine('RAM_MiB', 'Account RAM target in MiB; forwarded as MemoryHigh with a 250 MiB floor.'),
            pmssCliHelpLine('DISK_QUOTA_GiB', 'Disk quota in GiB.'),
        ], pmssUserConfigCliResourceHelpLines('userConfigPositionals', 'parameter', $resourceDescriptionSuffixes)),
        'Named Options' => array_merge(pmssUserConfigCliResourceHelpLines('userConfigNamedOptions', 'usage', $resourceDescriptionSuffixes), [
            pmssCliHelpLine('--upload-throttle-kib=KIB', 'Persist torrent upload throttle in KiB/s; 0 removes it.'),
            pmssCliHelpLine('--welcome-message=HTML', 'Set or clear ~/.config/welcome-message.html.'),
            pmssCliHelpLine('--docker-enabled=true|false', 'Persist the rootless Docker policy for this user.'),
            pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        ]),
        'Examples' => [
            '  /scripts/util/userConfig.php alice 1024 200',
            '  /scripts/util/userConfig.php alice --io-weight=300',
            '  /scripts/util/userConfig.php alice 2048 500 750 300 300 /dev/sda:20M /dev/sda:20M /dev/sda:500 /dev/sda:500 125 150 50 "enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00" "ctrl=user model=linear rbps=834913556 rseqiops=93622 rrandiops=102913 wbps=618985353 wseqiops=72325 wrandiops=71025" --upload-throttle-kib=2048 --docker-enabled=true',
            '  /scripts/util/userConfig.php alice --welcome-message=<p>Planned maintenance tonight.</p>',
        ],
        'Notes' => [
            '  - Named resource options override legacy positional values, and USERNAME with named options reuses the stored RAM/quota baseline.',
            '  - RAM_MiB is applied through userConfigCgroup.php as MemoryHigh; PMSS clamps the effective floor to 250 MiB and derives MemoryMax at roughly 1.25x with at most 2048 MiB of headroom.',
            '  - If RAM_MiB is below 245 MiB, PMSS persists dockerEnabled=false for safety.',
            '  - For targeted slice-only edits, use /scripts/util/userConfigCgroup.php directly.',
        ],
    ], $useColor);
}
