<?php
/**
 * Subordinate owner IDs used by the per-user ownership repair.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** @return array<int, array{0:int, 1:int}> */
function pmssSubordinateIdRanges(string $user, int $primaryId, string $kind): array
{
    if ($kind !== 'uid' && $kind !== 'gid') {
        return [];
    }

    $path = getenv($kind === 'uid' ? 'PMSS_SUBUID_PATH' : 'PMSS_SUBGID_PATH')
        ?: ($kind === 'uid' ? '/etc/subuid' : '/etc/subgid');
    try {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
    } catch (Throwable $e) {
        return [];
    }
    if ($lines === false) {
        return [];
    }

    $ranges = [];
    foreach ($lines as $line) {
        $parts = explode(':', $line);
        if (count($parts) !== 3 || ($parts[0] !== $user && $parts[0] !== (string) $primaryId)
            || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            continue;
        }

        // Keep find's exclusive upper bound representable as a PHP integer.
        $start = (int) $parts[1];
        $count = (int) $parts[2];
        if ($start < 100000 || $count <= 0 || $start > PHP_INT_MAX - $count
            || (string) $start !== ltrim($parts[1], '0')
            || (string) $count !== ltrim($parts[2], '0')) {
            continue;
        }
        $ranges[] = [$start, $count];
    }

    return $ranges;
}

/** Build a GNU find expression for the primary ID and its subordinate ranges. */
function pmssOwnerIdSetFindPredicate(string $testFlag, int $primaryId, array $ranges): string
{
    $primary = $testFlag.' '.$primaryId;
    if ($ranges === []) {
        return $primary;
    }

    $tests = [$primary];
    foreach ($ranges as $range) {
        $start = (int) $range[0];
        $end = $start + (int) $range[1];
        $tests[] = sprintf('\\( %s +%d %s -%d \\)', $testFlag, $start - 1, $testFlag, $end);
    }

    return '\\( '.implode(' -o ', $tests).' \\)';
}
