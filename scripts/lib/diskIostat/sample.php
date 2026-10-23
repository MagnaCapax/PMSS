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

    // Column-name lookup keeps sysstat 12+ additions from shifting meanings;
    // legacy await/svctm names remain fallbacks for older hosts.
    $fields = [];
    foreach ([
        'iopsRead' => ['r/s'], 'iopsWrite' => ['w/s'],
        'throughputRead' => ['rMB/s'], 'throughputWrite' => ['wMB/s'],
        'diskAwait' => ['r_await', 'await'], 'diskServiceTime' => ['w_await', 'svctm'],
        'diskUtil' => ['%util'], 'avgQueueSize' => ['aqu-sz', 'avgqu-sz'],
    ] as $field => $colNames) {
        $fields[$field] = '0';
        foreach ($colNames as $name) {
            if (isset($colMap[$name])) {
                $fields[$field] = $values[$colMap[$name]] ?? '0';
                break;
            }
        }
    }
    return $fields;
}

