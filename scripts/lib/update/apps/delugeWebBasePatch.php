<?php
/** Deluge WebUI base-path compatibility and shared guarded line-patch helpers. */

require_once dirname(__DIR__, 2).'/runtime/filesystem.php';

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

/** Twisted requires bytes for the legacy Deluge 2.0/2.1 base-path child. */
function pmssPatchDelugeWebBasePathBytes(string $path, bool $dryRun, callable $log): bool
{
    $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to read Deluge server.py for patching: ');
    if ($lines === null) return false;

    $before = "putChild(self.base.strip('/'), self.top_level)";
    $after = "putChild(self.base.strip('/').encode(), self.top_level)";
    $line = pmssDelugeLineSearch($lines, $before);
    if ($line === null) return pmssDelugeLineSearch($lines, $after) !== null;
    $lines[$line] = str_replace($before, $after, $lines[$line]);

    return pmssDelugeWritePatchedLines($path, $lines, $dryRun, $log,
        '[DRYRUN] Would patch Deluge WebUI base path bytes in ',
        '[WARN] Failed to write Deluge WebUI base path patch to ');
}

/** Do not publish a venv when a required base-path repair fails. */
function pmssDelugeWebBasePathPatchVenv(string $versionDir, callable $log, callable $patch): bool
{
    foreach (glob($versionDir.'/lib/python3*/site-packages/deluge/ui/web/server.py') ?: [] as $path) {
        $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to inspect Deluge WebUI base path in ');
        if ($lines === null) return false;
        // The patch's false result also means no change is needed in Deluge 2.2+.
        $needed = pmssDelugeLineSearch($lines, "putChild(self.base.strip('/'), self.top_level)") !== null;
        if (!$patch($path, false, $log) && $needed) return false;
    }
    return true;
}
