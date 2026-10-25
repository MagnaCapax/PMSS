<?php
/**
 * Helpers for user-facing quota snapshots.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/lighttpd/rootStage.php';

/** Publish the root-owned quota artifact with the account read group. */
function pmssQuotaSnapshotWrite(string $path, string $content, int $mode = 0640): bool
{
    $context = pmssRootStageContext($path);
    if ($context === null) return false;
    return pmssRootStageFileWrite($path, $content, $context[2], $mode);
}

/** @return array<int,array<int,string>> Parse quota data rows for one filesystem prefix. */
function pmssQuotaSnapshotDataRows(string $content, string $pathPrefix = '/'): array
{
    $rows = []; foreach (pmssConfigTextRows($content, 4, []) as $columns) if (strpos($columns[0], $pathPrefix) === 0) $rows[] = $columns;
    return $rows;
}

/**
 * Normalize `quota -s` output so UI consumers always receive unit-suffixed
 * size fields in the quota data row.
 */
function pmssQuotaSnapshotNormalizeHumanReadableOutput(string $content): string
{
    if (!is_array($lines = preg_split('/\r?\n/', $content))) {
        return $content;
    }

    // The final empty split field already preserves each trailing newline.
    return implode(PHP_EOL, array_map('pmssQuotaSnapshotNormalizeHumanReadableLine', $lines));
}

/**
 * Normalize the size columns in one human-readable quota data line.
 */
function pmssQuotaSnapshotNormalizeHumanReadableLine(string $line): string
{
    $tokens = pmssQuotaSnapshotDataRows($line)[0] ?? [];
    if ($tokens === []) return $line;

    $changed = false;
    for ($index = 1; $index <= 3; $index++) {
        if (preg_match('/^([0-9]+)(\*)?$/', $tokens[$index], $matches) !== 1) {
            continue;
        }

        $changed = true;
        $tokens[$index] = $matches[1].'K'.($matches[2] ?? '');
    }

    if (!$changed) {
        return $line;
    }

    $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
    return $indent.implode(' ', $tokens);
}
