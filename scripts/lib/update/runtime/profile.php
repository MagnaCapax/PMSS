<?php
/** Stable updater profiling include and shared shell/callable capture.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../../runtime.php';
require_once __DIR__.'/stepPolicy.php';
require_once __DIR__.'/profileCallables.php';
require_once __DIR__.'/profileSummary.php';

/**
 * Track a single step execution in memory and JSON logs.
 */
function pmssRecordProfile(array $entry): void
{
    if (!is_array($GLOBALS['PMSS_PROFILE'] ?? null)) {
        $GLOBALS['PMSS_PROFILE'] = [];
    }
    $GLOBALS['PMSS_PROFILE'][] = $entry;
    pmssLogJson(['event' => 'step', 'data' => $entry]);
}

/** Record one command-style profile entry with the stable runStep fields. */
function pmssCommandProfileRecord(string $description, string $command, string $status, int $rc, float $duration, bool $dryRun, string $stdoutExcerpt = '', string $stderrExcerpt = ''): void
{
    pmssRecordProfile([
        'description'    => $description,
        'command'        => $command,
        'status'         => $status,
        'rc'             => $rc,
        'duration'       => round($duration, 4),
        'dry_run'        => $dryRun,
        'stdout_excerpt' => $stdoutExcerpt,
        'stderr_excerpt' => $stderrExcerpt,
    ]);
}

/**
 * Log a status line with duration/rc in the same format as runStep(), without executing a command.
 */
function pmssLogStatus(string $status, string $description, int $rc = 0, ?float $duration = null): void
{
    $dur = $duration ?? 0.0;
    $statusUpper = strtoupper($status);
    logMessage(sprintf('[%s %.3fs rc=%d] %s', $statusUpper, $dur, $rc, $description));
    pmssCommandProfileRecord($description, '', $statusUpper, $rc, $dur, false);
}
