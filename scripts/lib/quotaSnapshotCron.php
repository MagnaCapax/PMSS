<?php
/** Daily quota snapshot parsing and declared-mount checks. */

require_once __DIR__.'/quota/state.php';

/** Return true only when the target fstab entry declares journaled quotas. */
function pmssQuotaSnapshotMountDeclaresJournaledQuota(string $mountPath, string $fstabPath = '/etc/fstab'): bool
{
    return (pmssQuotaMountDeclaredTypes($mountPath, static function (string $warning): void {}, $fstabPath, 'quota snapshot', true) ?? []) !== [];
}

/**
 * Parse `repquota -u -n` output into stable numeric rows.
 *
 * @return array<int, array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>
 */
function pmssQuotaSnapshotParseRepquotaUserRows(array $lines): array
{
    $rows = [];
    foreach (pmssConfigRows($lines, 2, []) as $tokens) {
        if (preg_match('/^#?([0-9]+)$/', $tokens[0], $m) !== 1) {
            continue;
        }

        $numbers = array_values(array_filter(array_slice($tokens, 1), 'ctype_digit'));
        if (count($numbers) < 6) {
            continue;
        }

        $rows[] = array_merge([$m[1]], array_slice($numbers, 0, 6));
    }

    return $rows;
}
