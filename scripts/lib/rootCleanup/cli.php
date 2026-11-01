<?php
/** Root cleanup CLI control; dry-run remains the default.
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../runtime/cli.php';
require_once __DIR__.'/policy.php';
require_once __DIR__.'/collect.php';
require_once __DIR__.'/apply.php';
require_once __DIR__.'/report.php';

/** Preserve option parsing and the root-use gate before any candidate scan. */
function pmssSafeRootCleanupMain(array $argv): int
{
    $help = "\nSafe root-filesystem cleanup — reclaim REGENERABLE cruft ONLY (whitelist).\n"
        . "Usage: safeRootCleanup.php [--apply] [--min-pct N] [--log-days N] [--journal-mb N] [--force] [--json PATH] [--quiet]\n\n"
        . "  --apply         Actually free space (DEFAULT is dry-run: report only).\n"
        . "  --min-pct N     In --apply, refuse unless root use%% >= N (default 90). --force overrides.\n"
        . "  --log-days N    Delete rotated/atop logs older than N days (default 3).\n"
        . "  --journal-mb N  journald vacuum target size in MB (default 200).\n"
        . "  --force         Run --apply even below --min-pct (still whitelist-only).\n"
        . "  --json PATH     Per-run JSONL audit (default /var/log/pmss/safe-root-cleanup.jsonl).\n"
        . "  --quiet         Suppress the human summary (cron-friendly).\n\n"
        . "NEVER touches /home, /var/www, customer data, /etc, /root, /boot, or live logs.\n"
        . "Not a live-log-storm tool — a filling live syslog/kern.log is fixed by killing the source.\n\n";

    if (($parsed = pmssParseCliTokensOrHelp($argv, $help, ['min-pct', 'log-days', 'journal-mb', 'json'])) === null) {
        return 0;
    }

    $apply     = pmssCliOptionPresent($parsed, 'apply');
    $force     = pmssCliOptionPresent($parsed, 'force');
    $quiet     = pmssCliOptionPresent($parsed, 'quiet');
    $minPct    = pmssCliOptionInt($parsed, 'min-pct', null, 90);
    $logDays   = max(1, pmssCliOptionInt($parsed, 'log-days', null, 3));
    $journalMb = max(50, pmssCliOptionInt($parsed, 'journal-mb', null, 200));
    $jsonPath  = pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/safe-root-cleanup.jsonl');

    $now      = time();
    $rootFree = (int) @disk_free_space('/');
    $rootTot  = (int) @disk_total_space('/');
    $rootUsePct = $rootTot > 0 ? (int) round((($rootTot - $rootFree) / $rootTot) * 100) : 0;

    if ($apply && !$force && $rootUsePct < $minPct) {
        if (!$quiet) {
            echo "Root use {$rootUsePct}% < --min-pct {$minPct}%: nothing to do (use --force to override).\n";
        }
        return 0;
    }

    // Discover once in legacy order; the same candidates supply execution and audit counts.
    $candidates = [
        'rotated_logs' => pmssSafeRootCleanupCollectRotatedLogs($logDays, $now),
        'atop_logs' => pmssSafeRootCleanupCollectAtopLogs($logDays, $now),
        'distupgrade_tmp' => pmssSafeRootCleanupCollectDistUpgradeTmp(),
    ];
    $estimates = [
        'apt_cache' => pmssSafeRootCleanupPathBytes('/var/cache/apt/archives'),
        'journald' => max(0, pmssSafeRootCleanupPathBytes('/var/log/journal') - ($journalMb * 1048576)),
    ] + array_map('array_sum', $candidates);

    if (!$apply) {
        if (!$quiet) {
            echo "DRY-RUN — root use {$rootUsePct}% (" . pmssSafeRootCleanupHuman($rootFree) . " free). Freeable estimate:\n";
            pmssSafeRootCleanupPrintClasses($estimates);
            echo sprintf("  %-16s %10s\n", 'TOTAL', pmssSafeRootCleanupHuman(array_sum(array_map('intval', $estimates))));
            echo "Run with --apply to reclaim (whitelist-only).\n";
        }
        return 0;
    }

    $freed = pmssSafeRootCleanupApply($candidates, $journalMb);
    $rootFreeAfter = (int) @disk_free_space('/');
    $rootUsePctAfter = $rootTot > 0 ? (int) round((($rootTot - $rootFreeAfter) / $rootTot) * 100) : 0;
    pmssSafeRootCleanupReportApplied([
        'ts' => date('c'),
        'tool' => 'safeRootCleanup',
        'host' => gethostname(),
        'root_use_pct_before' => $rootUsePct,
        'root_use_pct_after' => $rootUsePctAfter,
        'freed_bytes' => $freed,
        'freed_total' => array_sum($freed),
        // Legacy field names count discovered candidates, including refused or failed removals.
        'rotated_files_removed' => count($candidates['rotated_logs']),
        'atop_files_removed' => count($candidates['atop_logs']),
        'log_days' => $logDays,
        'journal_mb' => $journalMb,
    ], $jsonPath, $quiet);
    return 0;
}
