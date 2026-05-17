#!/usr/bin/env php
<?php
/**
 * Cron task: append a root-only process tree snapshot for postmortem analysis.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

const PMSS_PROCESS_SNAPSHOT_LOG_DEFAULT = '/var/log/pmss/process-snapshot.log';

function pmssProcessSnapshotRun(): int
{
    return pmssRunSnapshotLogTask(__FILE__, 'PMSS_PROCESS_SNAPSHOT_LOG', PMSS_PROCESS_SNAPSHOT_LOG_DEFAULT, static function ($fh, string $ts): int {
        if (($ps = pmssCommandPath('ps')) === '') {
            pmssSnapshotWriteWarn($fh, $ts, 'ps_missing');
            return 0;
        }

        $out = [];
        $rc = 0;
        @exec($ps.' auxfww 2>&1', $out, $rc);
        if ($rc !== 0) {
            pmssSnapshotWriteWarn($fh, $ts, 'ps_failed', ['rc' => $rc], $out);
            return 0;
        }

        pmssSnapshotWriteLine($fh, $ts.' SNAPSHOT_BEGIN');
        foreach ($out as $line) {
            pmssSnapshotWriteLine($fh, (string) $line);
        }
        pmssSnapshotWriteLine($fh, $ts.' SNAPSHOT_END');
        return 0;
    });
}

pmssRunCliEntrypoint(__FILE__, 'pmssProcessSnapshotRun');
