#!/usr/bin/env php
<?php
/**
 * Cron task: process snapshot.
 *
 * Append periodic process tree snapshots to a root-only log for postmortem
 * analysis (orphaned processes, zombies, watchdog blind spots).
 *
 * Output file (root-only): /var/log/pmss/process-snapshot.log (0600)
 *
 * Notes:
 * - Best-effort: failures are recorded in the log but exit 0 to avoid cron noise.
 * - No external transmission: the snapshot remains local to the host.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_PROCESS_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/process-snapshot.log';

/**
 * Capture one snapshot and append it to the log.
 *
 * @return int exit code
 */
function pmssProcessSnapshotRun(): int
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        fwrite(STDERR, "processSnapshot.php must be run as root.\n");
        return 1;
    }

    $logPath = (string) (getenv('PMSS_PROCESS_SNAPSHOT_LOG') ?: PMSS_PROCESS_SNAPSHOT_LOG_DEFAULT);
    $logDir = dirname($logPath);
    $ts = date('Y-m-d\\TH:i:s');

    $oldUmask = umask(0077);
    $fh = false;
    try {
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            return 1;
        }

        if (($fh = @fopen($logPath, 'ab')) === false) {
            return 1;
        }

        @chmod($logPath, 0600);
        if (function_exists('flock')) {
            @flock($fh, LOCK_EX);
        }

        $ps = trim((string) @shell_exec('command -v ps 2>/dev/null'));
        if ($ps === '') {
            @fwrite($fh, $ts.' WARN ps_missing'.PHP_EOL);
            return 0;
        }

        // Use auxf to include user, cpu/mem, and the process tree. Add "ww" to avoid truncation.
        $out = [];
        $rc = 0;
        @exec($ps.' auxfww 2>&1', $out, $rc);
        if ($rc !== 0) {
            $excerpt = trim(preg_replace('/\\s+/', ' ', implode(' ', array_slice($out, 0, 5))));
            @fwrite($fh, $ts.' WARN ps_failed rc='.$rc.($excerpt !== '' ? ' msg='.substr($excerpt, 0, 300) : '').PHP_EOL);
            return 0;
        }

        @fwrite($fh, $ts.' SNAPSHOT_BEGIN'.PHP_EOL);
        foreach ($out as $line) {
            @fwrite($fh, (string) $line.PHP_EOL);
        }
        @fwrite($fh, $ts.' SNAPSHOT_END'.PHP_EOL);
        return 0;
    } finally {
        if ($fh !== false) {
            @fclose($fh);
        }
        umask($oldUmask);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssProcessSnapshotRun());
}
