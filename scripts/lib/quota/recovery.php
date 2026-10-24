<?php
/** Quota recount planning with verified-off and file-header guards. @license GPL-3.0-only */
require_once __DIR__.'/state.php';

/**
 * Whether a quota file exists with a header quotacheck rejects (wrong magic, version above 1, short read).
 *
 * A missing or unopenable file returns false: quotacheck creates a missing file itself and
 * fails on one it cannot open, so neither needs a fresh file.
 */
function pmssQuotaFileHeaderInvalid(string $path, string $type): bool
{
    // quota-tools INITQMAGICS for the v2 tree format (vfsv0/vfsv1) as little-endian bytes, so 32-bit PHP compares the same.
    $magic = ['user' => '111fc0d9', 'group' => '2719c0d9'][$type] ?? null;
    if ($magic === null || !is_file($path) || ($handle = @fopen($path, 'rb')) === false) {
        return false;
    }
    $header = fread($handle, 8);
    fclose($handle);
    if (!is_string($header) || strlen($header) < 8) {
        return true;
    }
    $version = unpack('V', substr($header, 4, 4))[1];

    return bin2hex(substr($header, 0, 4)) !== $magic || !in_array($version, [0, 1], true);
}

/**
 * quotacheck commands quotaFix runs; an empty list means it must skip the recount.
 *
 * quotacheck must not run while quota is on. After a successful quotaoff the usual recount
 * runs on every filesystem. quotaoff also fails when a quota file is unreadable (for example
 * zero-filled by an interrupted quotacheck), which quotaFix could otherwise never repair. Then
 * /home is recounted only if `quotaon -p /home` exits 0 (no type on) and reports every type fstab
 * declares as off; any other answer or an unreadable fstab skips the recount (fail closed). The
 * recovery runs per type. Only a type whose file has a header quotacheck rejects gets -c (new
 * file; its unreadable limits are not read) and -b (quotacheck keeps the old file as name~); a
 * readable file keeps its limits.
 *
 * @param callable|null $runner       Test seam for pmssCommandCapture().
 * @param callable|null $logger       Test seam for logMessage().
 * @param string|null   $quotaFileDir Test seam; directory holding aquota.user/aquota.group (default /home).
 *
 * @return list<string>
 */
function pmssQuotaFixCheckCommands(bool $quotaOffOk, ?callable $runner = null, ?callable $logger = null, ?string $fstabPath = null, ?string $quotaFileDir = null): array
{
    // Flags: -a (all filesystems), -v (verbose), -u (user quotas),
    //        -g (group quotas), -m (don't remount ro), -n (use first found quota file)
    if ($quotaOffOk) {
        return ['quotacheck -avugmn'];
    }
    $types = pmssQuotaHomeDeclaredTypes($logger ?: 'logMessage', $fstabPath, 'quota recovery check.');
    if ($types === null || $types === []) {
        return [];
    }

    $state = pmssQuotaCommandRun('quotaon -p /home', $runner);
    $commands = [];
    foreach ($types as $type) {
        // quotaon -p exits with the number of types on and prints "<type> quota on /home (<dev>) is off".
        if ($state['rc'] !== 0 || preg_match('#^'.$type.' quota on /home \(.*\) is off$#m', $state['output']) !== 1) {
            return [];
        }
        $fresh = pmssQuotaFileHeaderInvalid(($quotaFileDir ?? '/home').'/aquota.'.$type, $type) ? 'cb' : '';
        $commands[] = 'quotacheck -v'.$type[0].'mn'.$fresh.' /home';
    }

    return $commands;
}
