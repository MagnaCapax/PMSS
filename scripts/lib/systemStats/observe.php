<?php
/** Host-statistics observations; parsers and log publication stay separate. @license GPL-3.0-only */
require_once __DIR__.'/parse.php';
require_once __DIR__.'/jbd2.php';

/**
 * Collect the bounded top-memory process summary while checking command rc.
 */
function pmssSystemStatsTopMemoryProcesses(?callable $runner = null): string
{
    $runner = $runner ?? static function (array &$output, int &$rc): void {
        exec('/bin/bash -o pipefail -c '.escapeshellarg('ps -eo comm=,rss= --sort=-rss | head -n 3'), $output, $rc);
    };

    $output = []; $rc = 1;
    try {
        $runner($output, $rc);
    } catch (Throwable $exception) {
        return 'na';
    }

    return $rc === 0 ? pmssSystemStatsTopMemoryFromPsRows($output) : 'na';
}

/** Read one PSI file and degrade missing/unreadable hosts to `na`. */
function pmssSystemStatsPsiRead(string $path): string { return is_readable($path) ? pmssSystemStatsPsiFromRaw(pmssReadRegularFileContents($path)) : 'na'; }

/** Format optional representative ioping latency for the stats log. */
function pmssSystemStatsIopingMs(string $path): string { $value = is_dir($path) ? pmssIopingMedianMs($path, true) : null; return $value === null ? 'na' : number_format($value, 1, '.', '').'ms'; }

/**
 * Read the jbd2 journal counters of the filesystem mounted at /home as the `jbd2_home` field.
 *
 * Resolves /home through mountinfo's major:minor to the kernel block name (the journal's
 * device name, which differs from the md/dm/nvme name shown by mount) and reads the single
 * matching /proc/fs/jbd2 directory. Not a separate ext3/ext4 mount, no journal directory, or any
 * unreadable step degrades to `na`. Paths are parameters only so tests stay hermetic.
 */
function pmssSystemStatsJbd2HomeRead(string $mountinfo = '/proc/self/mountinfo', string $sysDevBlock = '/sys/dev/block', string $jbd2Root = '/proc/fs/jbd2'): string
{
    // Suppression cannot turn malformed-path exceptions into the unavailable result.
    foreach ([$mountinfo, $sysDevBlock, $jbd2Root] as $path) {
        if (pmssFilesystemPathHasNulByte($path)) return 'na';
    }
    $majorMinor = pmssSystemStatsJbd2HomeDeviceFromMountinfo(pmssReadRegularFileContents($mountinfo));
    $link = $majorMinor === null ? false : @readlink($sysDevBlock.'/'.$majorMinor);
    $entries = is_string($link) ? @scandir($jbd2Root) : false;
    $directory = is_array($entries) ? pmssSystemStatsJbd2DirectoryFor(basename($link), $entries) : null;
    return $directory === null ? 'na' : pmssSystemStatsJbd2FromRaw(pmssReadRegularFileContents($jbd2Root.'/'.$directory.'/info'));
}
