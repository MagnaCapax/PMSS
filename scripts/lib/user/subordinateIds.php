<?php
/**
 * Subordinate owner IDs used by the per-user ownership repair.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

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
        if (count($parts) !== 3 || ($parts[0] !== $user && $parts[0] !== (string) $primaryId)) {
            continue;
        }

        // Keep find's exclusive upper bound representable as a PHP integer.
        $start = pmssUnsignedDecimalIntParse($parts[1]);
        $count = pmssUnsignedDecimalIntParse($parts[2]);
        if ($start === null || $count === null || $start < 100000 || $count <= 0
            || $start > PHP_INT_MAX - $count) {
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
