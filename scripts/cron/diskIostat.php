#!/usr/bin/env php
<?php
/**
 * Cron task: disk Iostat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$iostatLogFile = '/var/run/pmss/iostat';

$devices = `ls /sys/block/|grep sd|grep -v loop|grep -v md`;
$devices = explode("\n", trim($devices));
$deviceList = implode(' ', $devices);
if (count($devices) == 0) die("No block devices detected\n");

// 120s interval, 2 samples. First sample = since-boot (discard); second = 120-sec delta (use).
$iostatRaw = `iostat -xm 120 2 -g grp1 {$deviceList} 2>&1`;

// Header-row parser: robust across sysstat versions.
//
// Pre-2026-05-23 (commit e126beb): positional awk pulled fixed columns
// ($2,$3,$4,$5,$10,$15,$23). Sysstat 12+ added discard (d/s, dMB/s, ...) and
// flush (f/s, f_await) columns, shifting every position past r/s. Only $2=r/s
// and (post-2026-05-15 patch) $23=%util were correct. Field-name extraction
// below survives future column additions and works across sysstat 9/10/11/12+.
$lines = explode("\n", $iostatRaw);
$lastHeaderIdx = -1;
foreach ($lines as $i => $line) {
    if (preg_match('/^Device\s/', trim($line))) {
        $lastHeaderIdx = $i;
    }
}
if ($lastHeaderIdx === -1) die("No iostat Device header found\n");

$header = preg_split('/\s+/', trim($lines[$lastHeaderIdx]));
$colMap = array_flip($header);   // 'Device'=>0, 'r/s'=>1, 'rMB/s'=>2, ...

// grp1 data line after the last header (= the 2nd-sample 120-sec average).
$grp1Line = null;
for ($i = $lastHeaderIdx + 1; $i < count($lines); $i++) {
    if (preg_match('/^\s*grp1\s/', $lines[$i])) {
        $grp1Line = $lines[$i];
        break;
    }
}
if ($grp1Line === null) die("No grp1 line after iostat header\n");

$values = preg_split('/\s+/', trim($grp1Line));
// $values[0]='grp1'; $values[1..N] align with $header[1..N] (Device col is name).

$getAny = function (array $colNames) use ($colMap, $values) {
    foreach ($colNames as $name) {
        if (isset($colMap[$name])) {
            return $values[$colMap[$name]] ?? '0';
        }
    }
    return '0';
};

// Populate with semantically-correct values per sysstat-12 column naming.
//
// SEMANTIC CHANGES from the pre-2026-05-23 positional parser:
//   * `iopsWrite` was rMB/s (shifted); now reads `w/s` (real write IOPS).
//   * `throughputRead` was rrqm/s (shifted); now reads `rMB/s` (real read MB/s).
//   * `throughputWrite` was %rrqm (shifted); now reads `wMB/s` (real write MB/s).
//   * `diskAwait` was wrqm/s (shifted; magnitudes in the hundreds-to-thousands);
//     now reads `r_await` ms (real read latency) — matches the original
//     2014-era TODO intent ("took r_await as that's what we are more interested").
//     Fallback to legacy `await` for sysstat 9-11.
//   * `diskServiceTime` was dMB/s (always 0 on HDD without TRIM); repurposed to
//     `w_await` ms (real write latency). The original svctm column was removed
//     in sysstat 12. Fallback to legacy `svctm` for sysstat 9-11.
//   * `diskUtil` was already correct on post-2026-05-15 nodes (`%util`); unchanged.
//   * NEW: `avgQueueSize` reads `aqu-sz` — the RAID-correct saturation signal
//     per Gregg/Brooker/Percona. PowerAdmin rule of thumb: sustained
//     `aqu-sz > 2 per HDD` = queue-bound regardless of svctm.
//
// CASCADE WARNING: hallinta `serviceTime*` columns will drop ~100x in magnitude
// once this reaches the fleet (wrqm/s magnitudes were in the hundreds-to-thousands;
// real r_await is typically <50ms on healthy HDD RAID). Oversale-algo calibration
// bands need re-tuning against the corrected semantics. The new `avgQueueSize`
// field is the recommended primary saturation gate going forward.
$iostat = array(
    'iopsRead'        => $getAny(['r/s']),
    'iopsWrite'       => $getAny(['w/s']),
    'throughputRead'  => $getAny(['rMB/s']),
    'throughputWrite' => $getAny(['wMB/s']),
    'diskAwait'       => $getAny(['r_await', 'await']),
    'diskServiceTime' => $getAny(['w_await', 'svctm']),
    'diskUtil'        => $getAny(['%util']),
    'avgQueueSize'    => $getAny(['aqu-sz', 'avgqu-sz']),
    'diskQuantity'    => count($devices),
    'time'            => time(),
);

// Save the data
file_put_contents($iostatLogFile, serialize($iostat));
file_put_contents($iostatLogFile . '-history', date('Y-m-d H:i:s') . ' || ' . serialize($iostat) . "\n", FILE_APPEND);
file_put_contents($iostatLogFile . '-history-raw', date('Y-m-d H:i:s') . ' || ' . $iostatRaw . "\n---\n", FILE_APPEND);

// HTTP-readable copy for central admin (hallinta) fetch.
passthru("cp /var/run/pmss/iostat /var/www/iostat");
