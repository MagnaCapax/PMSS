<?php
/**
 * Repair stale per-user systemd slice drop-ins that lost memory units.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/identity.php';
require_once dirname(__DIR__).'/update/managedPath.php';

/**
 * Return true for bare MemoryMax values that systemd would read as sub-MiB bytes.
 */
function pmssUserCgroupSliceBareMemoryMaxNeedsSuffix(string $value): bool
{
    if (!ctype_digit($value) || strlen($value) > 7) {
        return false;
    }

    $bytes = (int) $value;
    return $bytes > 0 && $bytes < 1048576;
}

/** True when the legacy cgroup-v1 sibling proves the bare value was intended as MiB. */
function pmssUserCgroupSliceDropinHasMatchingMemoryLimitUnit(string $content, string $value): bool
{
    return preg_match(
        '/^[ \t]*MemoryLimit[ \t]*=[ \t]*'.preg_quote($value, '/').'[KMGTP][ \t]*(?:[#;].*)?$/mi',
        $content
    ) === 1;
}

/**
 * Append PMSS's MiB suffix to stale bare MemoryMax values.
 *
 * @return array{changed:bool,content:string,values:array<int,string>}
 */
function pmssUserCgroupSliceDropinContentRepairBareMemoryMax(string $content): array
{
    $values = [];
    $updated = preg_replace_callback(
        '/^([ \t]*MemoryMax[ \t]*=[ \t]*)([0-9]+)([ \t]*(?:[#;].*)?)$/m',
        static function (array $matches) use (&$values, $content): string {
            if (
                !pmssUserCgroupSliceBareMemoryMaxNeedsSuffix($matches[2])
                || !pmssUserCgroupSliceDropinHasMatchingMemoryLimitUnit($content, $matches[2])
            ) {
                return $matches[0];
            }

            $values[] = $matches[2];
            return $matches[1].$matches[2].'M'.$matches[3];
        },
        $content
    );

    if (!is_string($updated)) {
        return ['changed' => false, 'content' => $content, 'values' => []];
    }

    return ['changed' => $updated !== $content, 'content' => $updated, 'values' => $values];
}

/** Repair one drop-in file when it contains stale bare MemoryMax values. */
function pmssUserCgroupSliceDropinFileRepairBareMemoryMax(string $file, callable $logger): bool
{
    if (is_link($file) || !is_file($file)) {
        $logger('[WARN] Skipping unsafe user slice drop-in target: '.$file);
        return false;
    }

    $content = @file_get_contents($file);
    if (!is_string($content)) {
        $logger('[WARN] Unable to read user slice drop-in: '.$file);
        return false;
    }

    $plan = pmssUserCgroupSliceDropinContentRepairBareMemoryMax($content);
    if (!$plan['changed']) {
        return false;
    }

    $backup = pmssCreateManagedPathBackup($file, 'user slice drop-in', $logger, date('YmdHis'));
    if (!pmssWriteManagedPathFile(
        $file,
        $plan['content'],
        'user slice drop-in',
        $logger,
        null,
        null,
        0644,
        '[WARN] Failed to repair user slice drop-in '.$file
    )) {
        return false;
    }

    $logger(sprintf(
        '[WARN] Repaired bare MemoryMax in %s%s (values: %s)',
        $file,
        $backup === '' ? '' : ' (backup '.$backup.')',
        implode(', ', array_unique($plan['values']))
    ));
    return true;
}

/** Repair all drop-ins in a per-user systemd slice directory. */
function pmssUserCgroupSliceDropinDirectoryRepairBareMemoryMax(string $sliceDir, callable $logger): bool
{
    if (!is_dir($sliceDir) || is_link($sliceDir)) {
        return false;
    }

    $changed = false;
    foreach (glob(rtrim($sliceDir, '/').'/*.conf') ?: [] as $file) {
        $changed = pmssUserCgroupSliceDropinFileRepairBareMemoryMax($file, $logger) || $changed;
    }
    return $changed;
}

/** Repair stale bare MemoryMax drop-ins for one managed account. */
function pmssUserCgroupSliceRepairLegacyBareMemoryMaxForUser(string $username, callable $logger): bool
{
    if (!pmssValidateUsername($username)) {
        return false;
    }

    $uid = pmssPasswdEntryPositiveUid(pmssUserAccountLookup($username)) ?? 0;
    return $uid > 0
        ? pmssUserCgroupSliceDropinDirectoryRepairBareMemoryMax('/etc/systemd/system/user-'.$uid.'.slice.d', $logger)
        : false;
}
