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
    $colMap = $values = null;
    // Each header starts a new sample; only its first group row is eligible.
    foreach (explode("\n", $iostatRaw) as $line) {
        if (preg_match('/^Device\s/', trim($line))) {
            $colMap = array_flip(pmssConfigLineColumns($line, 1, []));
            $values = null;
        } elseif ($colMap !== null && $values === null && preg_match('/^\s*grp1\s/', $line)) {
            $values = pmssConfigLineColumns($line, 1, []);
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
    return array_map(static function (?int $column) use ($values): string {
        return $column === null ? '0' : ($values[$column] ?? '0');
    }, [
        'iopsRead' => $colMap['r/s'] ?? null, 'iopsWrite' => $colMap['w/s'] ?? null,
        'throughputRead' => $colMap['rMB/s'] ?? null, 'throughputWrite' => $colMap['wMB/s'] ?? null,
        'diskAwait' => $colMap['r_await'] ?? $colMap['await'] ?? null,
        'diskServiceTime' => $colMap['w_await'] ?? $colMap['svctm'] ?? null,
        'diskUtil' => $colMap['%util'] ?? null, 'avgQueueSize' => $colMap['aqu-sz'] ?? $colMap['avgqu-sz'] ?? null,
    ]);
}

/** Parse the last avg-cpu report by name; an incomplete final report is no signal. */
function pmssDiskIostatParseCpuFields(string $iostatRaw): array
{
    $columns = $values = null;
    foreach (explode("\n", $iostatRaw) as $line) {
        if (preg_match('/^avg-cpu:/', trim($line))) {
            $header = pmssConfigLineColumns($line, 1, []);
            array_shift($header);
            $columns = strpos($line, "\0") === false ? array_flip($header) : [];
            $values = null;
        } elseif ($columns !== null && $values === null && trim($line) !== '') {
            $values = strpos($line, "\0") === false ? pmssConfigLineColumns($line, 1, []) : [];
        }
    }
    $result = ['cpuIowaitPct' => null, 'cpuBusyPct' => null, 'cpuStealPct' => null, 'cpuIdlePct' => null];
    $cpu = [];
    foreach (['%user', '%nice', '%system', '%iowait', '%steal', '%idle'] as $name) {
        $raw = isset($columns[$name]) ? ($values[$columns[$name]] ?? '') : '';
        if (!preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $raw)
            || ($value = pmssFiniteNonNegativeFloat($raw)) === null || $value > 100) return $result;
        $cpu[$name] = $value;
    }
    $busy = $cpu['%user'] + $cpu['%nice'] + $cpu['%system'];
    return ['cpuIowaitPct' => $cpu['%iowait'], 'cpuBusyPct' => $busy <= 100 ? $busy : null,
        'cpuStealPct' => $cpu['%steal'], 'cpuIdlePct' => $cpu['%idle']];
}

