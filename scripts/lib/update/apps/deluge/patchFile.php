<?php
/** Guarded Deluge patch I/O and bounded line search; no patch-specific policy. */
// @license GPL-3.0-only

require_once dirname(__DIR__, 3).'/runtime/filesystem.php';

/** Read a candidate patch target while refusing symlinked or unreadable paths. */
function pmssDelugeReadPatchLines(string $path, callable $log, string $readWarning): ?array
{
    if (!pmssRegularFilePathIsReadable($path) || !is_readable($path)) return null;
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { $log($readWarning.$path); return null; }
    return $lines;
}

/** Search a bounded line window without open-coding patch-specific loops. */
function pmssDelugeLineSearch(array $lines, string $needle, int $start = 0, ?int $end = null, int $step = 1, bool $regex = false): ?int
{
    if ($lines === [] || $step === 0) return null;
    $lastIndex = count($lines) - 1;
    $start = max(0, min($start, $lastIndex));
    $end = $end === null ? ($step > 0 ? $lastIndex : 0) : max(0, min($end, $lastIndex));

    for ($i = $start; $step > 0 ? $i <= $end : $i >= $end; $i += $step) {
        $matched = $regex ? preg_match($needle, $lines[$i]) === 1 : strpos($lines[$i], $needle) !== false;
        if ($matched) return $i;
    }
    return null;
}

/** Persist patched lines with the same newline and dry-run contract. */
function pmssDelugeWritePatchedLines(string $path, array $lines, bool $dryRun, callable $log, string $dryRunMessage, string $writeWarning, ?callable $writer = null): bool
{
    $newContent = implode("\n", $lines);
    if ($newContent !== '' && substr($newContent, -1) !== "\n") $newContent .= "\n";
    if ($dryRun) { $log($dryRunMessage.$path); return true; }
    // Recheck after the earlier read so a replaced target is rejected before writing.
    clearstatcache(true, $path);
    if (!pmssRegularFilePathIsReadable($path)) { $log($writeWarning.$path); return false; }
    if (!pmssFileWriteComplete($path, $newContent, 0, $writer)) { $log($writeWarning.$path); return false; }
    return true;
}

/** Apply once; labels are [source, dry-run change, write failure]; unchanged input is never rewritten. */
function pmssDelugePatchFile(string $path, bool $dryRun, callable $log, array $labels, callable $transform): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge '.$labels[0].' for patching: ');
    if ($lines === null) return false;
    $before = $lines;
    if (!$transform($lines)) return false;
    return $lines === $before || pmssDelugeWritePatchedLines($path, $lines, $dryRun, $log,
        '[DRYRUN] Would patch Deluge '.$labels[1].' in ', '[WARN] Failed to write Deluge '.$labels[2].' patch to ');
}
