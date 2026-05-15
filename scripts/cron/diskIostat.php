#!/usr/bin/env php
<?php
/**
 * Cron task: disk Iostat.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
$debianVersion = file_get_contents('/etc/debian_version');
// Are we running debian 7/8 or Debian 10?
$debianVersion = $debianVersion[0] == 1 ? 10 : 8;

// Gather iostat information from disks
$iostatLogFile = '/var/run/pmss/iostat';

$devices = `ls /sys/block/|grep sd|grep -v loop|grep -v md`;
$devices = explode("\n", trim($devices));
$deviceList = implode(' ', $devices);
if (count($devices) == 0) die("No block devices detected\n");

// Get the iostats for past 2 minutes for parsing
// Depends on debian version, mapping is below
// Sample code:
// iostat -xm 1 2 -g grp1 sda sdb sdc sdd | awk '/grp1/ { print $4,$5,$6,$7,$10,$13,$14}'
// For debian 10 took r_await as that's what we are more interested, both read and write await is now exposed #TODO eventually this
// 2026-05-15 (verified Debian 12 / sysstat 12.6.1): %util column is at $23, not $16. Fixed last field only.
// The remaining fields ($2,$3,$4,$5,$10,$15) are still positionally wrong vs sysstat 12 layout
// (only $2=iopsRead happens to be right); diskAwait/diskServiceTime/throughputRead/Write/iopsWrite all
// pull shifted columns. Empirical fleet impact: serviceTimeWeek non-zero numbers are bogus shifted data
// (not actual ms), diskUtil was always 0 because $16=drqm/s. Oversale algo thresholds tuned against
// these are tuned against garbage.
// #TODO replace positional awk with header-row parse — read the first non-data line, build a
// name->index map (r/s, w/s, rMB/s, wMB/s, r_await, %util), then extract by name. Robust across
// sysstat versions and survives future column additions. Also: svctm was removed in sysstat 12,
// so diskServiceTime should be retired or aliased to r_await ms. Will require coordinated update
// of pmssCallbacks::nodeIostat field-name expectations and provisioningApi::updateNodeResources
// oversale thresholds (currently 25ms is SSD-scale on a field that wasn't actually ms).
$iostat = $debianVersion == 10
    ? `iostat -xm 120 2 -g grp1 {$deviceList} | awk '/grp1/ { print $2,$3,$4,$5,$10,$15,$23}'`
    : `iostat -xm 120 2 -g grp1 {$deviceList} | awk '/grp1/ { print $4,$5,$6,$7,$10,$13,$14}'`;

$iostatRaw = $iostat;

// Parse them! :)
$iostat = explode("\n", trim($iostat));
$iostat = $iostat[1];   // We are only interested in CURRENT load
$iostat = explode(' ', $iostat);    // empty space is the divider


$iostat = array(
    'iopsRead' => $iostat[0],
    'iopsWrite' => $iostat[1],
    'throughputRead' => $iostat[2],
    'throughputWrite' => $iostat[3],
    'diskAwait' => $iostat[4],
    'diskServiceTime' => $iostat[5],
    'diskUtil' => $iostat[6],
    'diskQuantity' => count($devices),
    'time' => time()
);

// Save the data
file_put_contents($iostatLogFile, serialize($iostat));
file_put_contents($iostatLogFile . '-history', date('Y-m-d H:i:s') .' || ' . serialize($iostat) . "\n", FILE_APPEND);
file_put_contents($iostatLogFile . '-history-raw', date('Y-m-d H:i:s') .' || ' . $iostatRaw . "\n---\n", FILE_APPEND);


// This way we can download it just via HTTP and easier view remotely
passthru("cp /var/run/pmss/iostat /var/www/iostat");
