<?php
/** Ordered resource metadata and persisted-field projections for account CLIs. @license GPL-3.0-only */

/** @return array<string,array<string,mixed>> Shared resource option specification. */
function pmssUserConfigCliResourceSpecs(string $field = ''): array
{
    $specs = [
        'trafficLimit' => ['addUserOption' => 'traffic-limit-gb', 'addUserLegacyIndex' => 5, 'userConfigIndex' => 4, 'usage' => '--traffic-limit-gb=GIB', 'parameter' => 'TRAFFIC_LIMIT_GB', 'description' => 'Monthly traffic quota in GiB.', 'parse' => 'int', 'default' => null, 'persist' => false],
        'iopsLimit' => ['addUserOption' => 'iops-limit', 'usage' => '--iops-limit=OPS', 'parameter' => 'IOPS_LIMIT', 'description' => 'Monthly combined read+write I/O operations budget.', 'parse' => 'int', 'default' => null, 'persist' => false],
        'trafficCapMbit' => ['addUserOption' => 'traffic-cap-mbit', 'addUserLegacyIndex' => 6, 'userConfigIndex' => 12, 'usage' => '--traffic-cap-mbit=MBIT', 'parameter' => 'TRAFFIC_CAP_MBIT', 'description' => 'Traffic shaper ceiling in Mbit/s; 0 disables shaping.', 'parse' => 'int', 'default' => 0, 'persist' => true],
        'CPUWeight' => ['addUserOption' => 'cpu-weight', 'addUserLegacyIndex' => 8, 'userConfigIndex' => 5, 'usage' => '--cpu-weight=WEIGHT', 'parameter' => 'CPUWEIGHT', 'description' => 'systemd CPUWeight; systemd expects 1-10000.', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--cpu-weight='],
        'IOWeight' => ['addUserOption' => 'io-weight', 'addUserLegacyIndex' => 9, 'userConfigIndex' => 6, 'usage' => '--io-weight=WEIGHT', 'parameter' => 'IOWEIGHT', 'description' => 'systemd IOWeight; systemd expects 1-10000.', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-weight='],
        'IOReadBW' => ['addUserOption' => 'io-read-bw', 'addUserLegacyIndex' => 10, 'userConfigIndex' => 7, 'usage' => '--io-read-bw=/dev/DEVICE:RATE', 'parameter' => 'IO_READ_BW', 'description' => 'Read bandwidth cap in /dev/DEVICE:RATE form.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-bw='],
        'IOWriteBW' => ['addUserOption' => 'io-write-bw', 'addUserLegacyIndex' => 11, 'userConfigIndex' => 8, 'usage' => '--io-write-bw=/dev/DEVICE:RATE', 'parameter' => 'IO_WRITE_BW', 'description' => 'Write bandwidth cap in /dev/DEVICE:RATE form.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-bw='],
        'IOReadIOPS' => ['addUserOption' => 'io-read-iops', 'addUserLegacyIndex' => 12, 'userConfigIndex' => 9, 'usage' => '--io-read-iops=/dev/DEVICE:IOPS', 'parameter' => 'IO_READ_IOPS', 'description' => 'Read IOPS cap in /dev/DEVICE:IOPS form.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-read-iops='],
        'IOWriteIOPS' => ['addUserOption' => 'io-write-iops', 'addUserLegacyIndex' => 13, 'userConfigIndex' => 10, 'usage' => '--io-write-iops=/dev/DEVICE:IOPS', 'parameter' => 'IO_WRITE_IOPS', 'description' => 'Write IOPS cap in /dev/DEVICE:IOPS form.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-write-iops='],
        'cpuQuotaPercent' => ['addUserOption' => 'cpu-quota-percent', 'addUserLegacyIndex' => 14, 'userConfigIndex' => 11, 'usage' => '--cpu-quota-percent=PERCENT|infinity', 'parameter' => 'CPU_QUOTA_PERCENT', 'description' => 'CPU quota percent; use infinity to remove the limit.', 'parse' => 'string', 'default' => 0, 'persist' => true],
        'ioLatencyMs' => ['addUserOption' => 'io-latency-ms', 'addUserLegacyIndex' => 15, 'userConfigIndex' => 13, 'usage' => '--io-latency-ms=MS', 'parameter' => 'IO_LATENCY_MS', 'description' => 'IODeviceLatencyTargetSec target in milliseconds; defaults to the /home backing device.', 'parse' => 'int', 'default' => 0, 'persist' => true, 'cgroupFlag' => '--io-latency-ms='],
        'ioCostQos' => ['addUserOption' => 'io-cost-qos', 'addUserLegacyIndex' => 16, 'userConfigIndex' => 14, 'usage' => '--io-cost-qos=SETTING', 'parameter' => 'IO_COST_QOS', 'description' => 'io.cost.qos nested keys; defaults to the /home backing device major:minor.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-cost-qos='],
        'ioCostModel' => ['addUserOption' => 'io-cost-model', 'addUserLegacyIndex' => 17, 'userConfigIndex' => 15, 'usage' => '--io-cost-model=SETTING', 'parameter' => 'IO_COST_MODEL', 'description' => 'io.cost.model nested keys; defaults to the /home backing device major:minor.', 'parse' => 'string', 'default' => null, 'persist' => true, 'cgroupFlag' => '--io-cost-model='],
    ];
    // Metadata roles share one ordered selection; the default returns the entire legacy schema.
    return $field === '' ? $specs : array_filter($specs, static function (array $spec) use ($field): bool { return !empty($spec[$field]); });
}

/** @return array<string,mixed> Copy persisted shared resources in specification order, including nulls. */
function pmssUserConfigCliPersistedStoredResources(array $payload): array
{
    $values = [];
    foreach (pmssUserConfigCliResourceSpecs('persist') as $key => $spec) {
        if (array_key_exists($key, $payload)) $values[$key] = $payload[$key];
    }
    return $values;
}

/** @return array<string,mixed> Apply only present resources through the same stored-field selection. */
function pmssUserConfigCliApplyPersistedResources(array $payload, array $user, array $presence): array
{
    return array_replace($payload, array_intersect_key(pmssUserConfigCliPersistedStoredResources($user), array_filter($presence)));
}
