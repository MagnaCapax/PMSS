<?php
/** Pure quota display rows and human-readable size normalization. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

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
    // The final empty split field already preserves each trailing newline.
    return implode(PHP_EOL, array_map('pmssQuotaSnapshotNormalizeHumanReadableLine', pmssTextLines($content)));
}

/**
 * Normalize the size columns in one human-readable quota data line.
 */
function pmssQuotaSnapshotNormalizeHumanReadableLine(string $line): string
{
    $tokens = pmssQuotaSnapshotDataRows($line)[0] ?? [];
    if ($tokens === []) return $line;

    // Only the three size columns gain units; preserve an unchanged line byte-for-byte.
    $sizes = preg_replace('/^([0-9]+)(\*)?$/', '$1K$2', array_slice($tokens, 1, 3), -1, $changed);
    if ($changed === 0) return $line;
    array_splice($tokens, 1, 3, $sizes);

    $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
    return $indent.implode(' ', $tokens);
}
