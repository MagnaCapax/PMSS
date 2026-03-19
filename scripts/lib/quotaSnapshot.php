<?php
/**
 * Helpers for user-facing quota snapshots.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
    $trimmed = trim($line);
    if ($trimmed === '') {
        return $line;
    }

    $tokens = preg_split('/\s+/', $trimmed);
    if (!is_array($tokens) || count($tokens) < 4 || strpos($tokens[0], '/') !== 0) {
        return $line;
    }

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
