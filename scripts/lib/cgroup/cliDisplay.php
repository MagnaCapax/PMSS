<?php
/**
 * Cgroup CLI display of configuration, counters, and plans.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

/** Keep legacy headings and property order for operators and automation. */
function pmssCgroupCliPrintPlan(array $props, array $ioPairs, array $ioCostWrites): void
{
    if (!empty($props)) {
        echo "\n[Planned properties]\n";
        foreach ($props as $key => $value) echo $key.'='.$value."\n";
    }
    if (!empty($ioPairs)) {
        echo "[Planned IO properties]\n";
        foreach ($ioPairs as $pair) echo $pair."\n";
    }
    if (!empty($ioCostWrites)) {
        echo "[Planned io.cost writes]\n";
        foreach ($ioCostWrites as $write) echo $write['path'].' <= '.$write['value']."\n";
    }
}

/** Display the existing systemd property selection without reordering it. */
function pmssCgroupCliShowConfig(SystemInterface $sys, string $slice): void
{
    echo "\n[Config] $slice\n";
    $props = ['CPUWeight','IOWeight','IODeviceLatencyTargetSec','MemoryAccounting','CPUAccounting','IOAccounting','MemoryHigh','MemoryMax','TasksMax','CPUQuotaPerSecUSec','CPUQuotaPeriodUSec'];
    $out = $sys->execute(\pmssBuildSystemdShowCommand($slice, $props));
    echo $out !== null ? trim($out)."\n" : "(no data)\n";
}

/** Build help independently of passwd lookup and system probes. */
function pmssCgroupCliUsageText(): string
{
    $useColor = \pmssCliHelpSupportsColor();
    $derivedDefault = \pmssCliHelpDim(' (default: derive from MemoryHigh when omitted)', $useColor);
    return \pmssCliHelpSectionText([
        'Usage' => [
            '  /scripts/util/userConfigCgroup.php USERNAME [--status] [--config]',
            '  /scripts/util/userConfigCgroup.php USERNAME --apply [--dry-run] [--defaults] [--respect-existing] [--cpu-weight=N] [--io-weight=N] [--tasks-max=N] [--memory-high=MiB] [--memory-max=MiB] [--cpu-quota-percent=N|infinity] [--io-latency-ms=MS] [--io-cost-qos=SETTING] [--io-cost-model=SETTING] [--device=/dev/DEV|/home] [--io-profile=hdd|nvme|bulk] [--io-read-bw=/dev/DEV:RATE] [--io-write-bw=/dev/DEV:RATE] [--io-read-iops=/dev/DEV:IOPS] [--io-write-iops=/dev/DEV:IOPS] [--wipe]',
        ],
        'Actions' => [
            \pmssCliHelpLine('--status', 'Show live slice counters from cgroupfs.'),
            \pmssCliHelpLine('--config', 'Show the current systemd slice properties.'),
            \pmssCliHelpLine('--apply', 'Apply the requested plan to the user slice.'),
            \pmssCliHelpLine('--dry-run', 'Print the planned properties without changing the system.'),
            \pmssCliHelpLine('--wipe', 'Reset the slice back to the PMSS baseline.'),
        ],
        'Resource Options' => [
            \pmssCliHelpLine('--memory-high=MiB', 'MemoryHigh target in MiB; effective minimum is 250 MiB.'),
            \pmssCliHelpLine('--memory-max=MiB', 'MemoryMax target in MiB; capped to High + 2048 MiB.'),
            \pmssCliHelpLine('--cpu-weight=N', 'systemd CPUWeight; systemd expects 1-10000.'.$derivedDefault),
            \pmssCliHelpLine('--io-weight=N', 'systemd IOWeight; systemd expects 1-10000.'.$derivedDefault),
            \pmssCliHelpLine('--tasks-max=N', 'Process limit for the user slice; use a positive integer.'),
            \pmssCliHelpLine('--cpu-quota-percent=N|infinity', 'CPU quota percent; use 0 or infinity to remove the cap.'),
            \pmssCliHelpLine('--io-latency-ms=MS', 'IODeviceLatencyTargetSec target in milliseconds for the selected device or the /home backing device.'),
            \pmssCliHelpLine('--io-cost-qos=SETTING', 'io.cost.qos nested keys; defaults to the /home backing device major:minor.'),
            \pmssCliHelpLine('--io-cost-model=SETTING', 'io.cost.model nested keys; defaults to the /home backing device major:minor.'),
            \pmssCliHelpLine('--device=/dev/DEV|/home', 'Device selector for IO profiles and shorthand resolution.'),
            \pmssCliHelpLine('--io-profile=hdd|nvme|bulk', 'Apply a named IO profile to the selected device.'),
            \pmssCliHelpLine('--io-read-bw=/dev/DEV:RATE', 'Explicit read bandwidth cap, e.g. /dev/sda:20M.'),
            \pmssCliHelpLine('--io-write-bw=/dev/DEV:RATE', 'Explicit write bandwidth cap, e.g. /dev/sda:20M.'),
            \pmssCliHelpLine('--io-read-iops=/dev/DEV:IOPS', 'Explicit read IOPS cap, e.g. /dev/sda:500.'),
            \pmssCliHelpLine('--io-write-iops=/dev/DEV:IOPS', 'Explicit write IOPS cap, e.g. /dev/sda:500.'),
        ],
        'Profiles' => [
            \pmssCliHelpLine('--defaults', 'Load PMSS policy defaults before applying explicit overrides.'),
            \pmssCliHelpLine('--respect-existing', 'Keep live properties when neither flags nor defaults set them.'),
            \pmssCliHelpLine('--cpu-profile=<name>', 'Apply a named CPU profile from cgroup.policy.php.'),
            \pmssCliHelpLine('--mem-profile=<name>', 'Apply a named memory profile from cgroup.policy.php.'),
            \pmssCliHelpLine('--tasks-profile=<name>', 'Apply a named TasksMax profile from cgroup.policy.php.'),
            \pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        ],
        'Examples' => [
            '  /scripts/util/userConfigCgroup.php alice --status --config',
            '  /scripts/util/userConfigCgroup.php alice --apply --dry-run --memory-high=1024 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=125 --io-latency-ms=50 --io-cost-qos="enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00"',
            '  /scripts/util/userConfigCgroup.php alice --apply --defaults --device=/home --io-profile=hdd',
        ],
        'Notes' => [
            '  - Help is available without needing a real user lookup; normal runs still require an existing passwd entry.',
            '  - MemoryHigh below 250 MiB is raised to the PMSS floor before applying properties.',
            '  - io.cost writes are skipped when BFQ is active on any block scheduler queue.',
        ],
    ], $useColor);
}

/** Read each mode's counter paths through one ordered display loop. */
function pmssCgroupCliShowStatus(SystemInterface $sys, string $slice, int $uid): void
{
    $mode = $sys->getCgroupMode();
    echo "\n[Status] $slice (mode=$mode)\n";
    if ($mode === 'v2') {
        $base = "/sys/fs/cgroup/user.slice/user-".$uid.".slice";
        $pairs = [];
        foreach (['pids.current', 'memory.current', 'memory.high', 'memory.max', 'io.stat', 'io.latency', 'io.cost.weight', 'io.cost.qos', 'io.cost.model', 'cpu.weight'] as $field) {
            $pairs[$field] = $base.'/'.$field;
        }
        foreach (['io.cost.qos', 'io.cost.model'] as $field) {
            if ($sys->readFile($pairs[$field]) === null) $pairs[$field] = '/sys/fs/cgroup/'.$field;
        }
    } else {
        $pairs = [];
        foreach (['pids.current' => 'pids', 'memory.limit_in_bytes' => 'memory', 'memory.usage_in_bytes' => 'memory'] as $field => $controller) {
            $pairs[$field] = '/sys/fs/cgroup/'.$controller.'/user.slice/user-'.$uid.'.slice/'.$field;
        }
    }
    foreach ($pairs as $label => $path) {
        $val = $sys->readFile($path);
        echo $label.': '.($val === null ? '(unavailable)' : trim($val))."\n";
    }
}
