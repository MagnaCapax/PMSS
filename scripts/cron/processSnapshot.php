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

function pmssProcessSnapshotNow(): string
{
    return date('Y-m-d\\TH:i:s');
}

function pmssProcessSnapshotAppend($fh, string $line): void
{
    @fwrite($fh, $line.PHP_EOL);
}

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

    $logPath = getenv('PMSS_PROCESS_SNAPSHOT_LOG') ?: PMSS_PROCESS_SNAPSHOT_LOG_DEFAULT;
    $logDir = dirname($logPath);
    $ts = pmssProcessSnapshotNow();

    $oldUmask = umask(0077);
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        umask($oldUmask);
        return 1;
    }

    $fh = @fopen($logPath, 'ab');
    if ($fh === false) {
        umask($oldUmask);
        return 1;
    }
    @chmod($logPath, 0600);
    if (function_exists('flock')) {
        @flock($fh, LOCK_EX);
    }

    $ps = trim((string) @shell_exec('command -v ps 2>/dev/null'));
    if ($ps === '') {
        pmssProcessSnapshotAppend($fh, $ts.' WARN ps_missing');
        @fclose($fh);
        umask($oldUmask);
        return 0;
    }

    // Use auxf to include user, cpu/mem, and the process tree. Add "ww" to avoid truncation.
    $cmd = $ps.' auxfww 2>&1';
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        $excerpt = trim(preg_replace('/\\s+/', ' ', implode(' ', array_slice($out, 0, 5))));
        pmssProcessSnapshotAppend($fh, $ts.' WARN ps_failed rc='.$rc.($excerpt !== '' ? ' msg='.substr($excerpt, 0, 300) : ''));
        @fclose($fh);
        umask($oldUmask);
        return 0;
    }

    pmssProcessSnapshotAppend($fh, $ts.' SNAPSHOT_BEGIN');
    foreach ($out as $line) {
        pmssProcessSnapshotAppend($fh, (string) $line);
    }
    pmssProcessSnapshotAppend($fh, $ts.' SNAPSHOT_END');

    @fclose($fh);
    umask($oldUmask);
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(pmssProcessSnapshotRun());
}

