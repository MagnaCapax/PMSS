<?php
/** Host-statistics observations; parsers and log publication stay separate. @license GPL-3.0-only */
require_once __DIR__.'/parse.php';

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
function pmssSystemStatsIopingMs(string $path): string { $value = is_dir($path) ? pmssIopingMedianMs($path) : null; return $value === null ? 'na' : number_format($value, 1, '.', '').'ms'; }
