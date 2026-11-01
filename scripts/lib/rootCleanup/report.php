<?php
/** Root cleanup audit and terminal output using the existing payload.
 * @license GPL-3.0-only
 */

/** Print the ordered byte table used by estimates and measured results. */
function pmssSafeRootCleanupPrintClasses(array $classes): void
{
    foreach ($classes as $name => $bytes) {
        echo sprintf("  %-16s %10s\n", $name, pmssSafeRootCleanupHuman((int) $bytes));
    }
}

/** Publish the legacy JSONL audit before the optional human summary. */
function pmssSafeRootCleanupReportApplied(array $entry, string $jsonPath, bool $quiet): void
{
    $logDirError = null;
    if (pmssLogWriteDirectoryPrepare(dirname($jsonPath), 0755, $logDirError)
        && pmssLogWritePathIsSafe($jsonPath)) {
        pmssJsonLineAppend($jsonPath, $entry);
    }
    $freed = $entry['freed_bytes'];
    $before = $entry['root_use_pct_before'];
    $after = $entry['root_use_pct_after'];
    if (!$quiet) {
        echo "Reclaimed " . pmssSafeRootCleanupHuman($entry['freed_total'])
            . " — root {$before}% -> {$after}%.\n";
        pmssSafeRootCleanupPrintClasses($freed);
    }
}

/** Compact human size. */
function pmssSafeRootCleanupHuman(int $bytes): string
{
    $u = ['B', 'K', 'M', 'G', 'T'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : sprintf('%.1f', $n)) . $u[$i];
}
