<?php
/** Iostat sample parsing; acquisition stays in the facade. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/**
 * Project the latest iostat group row without filesystem or command probes.
 * diskAwait is r_await and diskServiceTime is w_await over /home leaf disks.
 * When /home cannot be resolved, the group row covers all discovered devices.
 *
 * @return array<string, string>
 */
function pmssDiskIostatParseSampleFields(string $iostatRaw): array
{
    $lines = explode("\n", $iostatRaw);
    $colMap = $values = null;
    // Each header starts a new sample; only its first group row is eligible.
    foreach (pmssConfigLines($lines, []) as $i => $line) {
        if (preg_match('/^Device\s/', $line)) {
            $colMap = array_flip(pmssConfigLineColumns($line, 1, []));
            $values = null;
        } elseif ($colMap !== null && $values === null && preg_match('/^\s*grp1\s/', $lines[$i])) {
            $values = pmssConfigLineColumns($lines[$i], 1, []);
        }
    }
    if ($colMap === null) {
        throw new RuntimeException('No iostat Device header found');
    }
    if ($values === null) {
        throw new RuntimeException('No grp1 line after iostat header');
    }

    $getAny = static function (array $colNames) use ($colMap, $values): string {
        foreach ($colNames as $name) {
            if (isset($colMap[$name])) {
                return $values[$colMap[$name]] ?? '0';
            }
        }
        return '0';
    };

    // Column-name lookup keeps sysstat 12+ additions from shifting meanings;
    // legacy await/svctm names remain fallbacks for older hosts.
    return [
        'iopsRead'        => $getAny(['r/s']),
        'iopsWrite'       => $getAny(['w/s']),
        'throughputRead'  => $getAny(['rMB/s']),
        'throughputWrite' => $getAny(['wMB/s']),
        'diskAwait'       => $getAny(['r_await', 'await']),
        'diskServiceTime' => $getAny(['w_await', 'svctm']),
        'diskUtil'        => $getAny(['%util']),
        'avgQueueSize'    => $getAny(['aqu-sz', 'avgqu-sz']),
    ];
}

