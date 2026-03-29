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

/** @return array<string,array<string,mixed>> Shared resource option specification. */
function pmssUserConfigCliResourceSpecs(): array
{
    return [
        'trafficLimit' => ['addUserOption' => 'traffic-limit-gb', 'addUserLegacyIndex' => 5, 'userConfigIndex' => 4, 'usage' => '--traffic-limit-gb=GIB', 'parse' => 'int', 'default' => null, 'persist' => false],
        'trafficCapMbit' => ['addUserOption' => 'traffic-cap-mbit', 'addUserLegacyIndex' => 6, 'userConfigIndex' => 12, 'usage' => '--traffic-cap-mbit=MBIT', 'parse' => 'int', 'default' => 0, 'persist' => true],
        'CPUWeight' => ['addUserOption' => 'cpu-weight', 'addUserLegacyIndex' => 8, 'userConfigIndex' => 5, 'usage' => '--cpu-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--cpu-weight='],
        'IOWeight' => ['addUserOption' => 'io-weight', 'addUserLegacyIndex' => 9, 'userConfigIndex' => 6, 'usage' => '--io-weight=WEIGHT', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-weight='],
        'IOReadBW' => ['addUserOption' => 'io-read-bw', 'addUserLegacyIndex' => 10, 'userConfigIndex' => 7, 'usage' => '--io-read-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-bw='],
        'IOWriteBW' => ['addUserOption' => 'io-write-bw', 'addUserLegacyIndex' => 11, 'userConfigIndex' => 8, 'usage' => '--io-write-bw=/dev/DEVICE:RATE', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-bw='],
        'IOReadIOPS' => ['addUserOption' => 'io-read-iops', 'addUserLegacyIndex' => 12, 'userConfigIndex' => 9, 'usage' => '--io-read-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-iops='],
        'IOWriteIOPS' => ['addUserOption' => 'io-write-iops', 'addUserLegacyIndex' => 13, 'userConfigIndex' => 10, 'usage' => '--io-write-iops=/dev/DEVICE:IOPS', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-iops='],
        'cpuQuotaPercent' => ['addUserOption' => 'cpu-quota-percent', 'addUserLegacyIndex' => 14, 'userConfigIndex' => 11, 'usage' => '--cpu-quota-percent=PERCENT|infinity', 'parse' => 'string', 'default' => 0, 'persist' => true],
    ];
}
