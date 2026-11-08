<?php
/** Ordered artifact checks and publication for system AI launchers. @license GPL-3.0-only */

/** Compare content and optional metadata; only Gemini refreshes the stat cache. */
function pmssAiToolsFilesDiffer(array $files, ?int $owner = null, ?int $group = null, bool $clearStat = false): bool
{
    foreach ($files as [$path, $expected, $mode]) {
        if ($clearStat) clearstatcache(true, $path);
        if (!is_string($expected) || is_link($path) || @file_get_contents($path) !== $expected
            || ($owner !== null && @fileowner($path) !== $owner)
            || ($group !== null && @filegroup($path) !== $group)
            || ($mode !== null && (@fileperms($path) & 0777) !== $mode)) return true;
    }
    return false;
}

/** Publish in caller order and stop at the first failure, retaining its exact diagnostic. */
function pmssAiToolsFilesPublish(array $files, ?callable $logger = null): void
{
    foreach ($files as [$path, $content, $mode, $failure]) {
        if (!is_string($content) || !pmssAiToolsCodexAtomicWrite($path, $content, $mode)) {
            ($logger ?: 'logmsg')('[WARN] '.$failure.'; keeping existing command');
            return;
        }
    }
}
