<?php
/**
 * Helpers for user-facing quota snapshots.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';

/** @return array<int,array<int,string>> Parse quota data rows for one filesystem prefix. */
function pmssQuotaSnapshotDataRows(string $content, string $pathPrefix = '/'): array
{
    $rows = []; foreach (preg_split('/\r?\n/', $content) ?: [] as $line) if (($columns = pmssConfigLineColumns($line, 4, [])) !== [] && strpos($columns[0], $pathPrefix) === 0) $rows[] = $columns;
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

    $normalized = implode(PHP_EOL, array_map('pmssQuotaSnapshotNormalizeHumanReadableLine', $lines));

    return ($content !== '' && substr($content, -1) === "\n" && substr($normalized, -1) !== "\n")
        ? $normalized.PHP_EOL
        : $normalized;
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
