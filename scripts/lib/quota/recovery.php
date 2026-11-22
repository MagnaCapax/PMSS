<?php
/** Quota recount planning with verified-off and file-header guards. @license GPL-3.0-only */
require_once __DIR__.'/state.php';

/**
 * Whether a quota file exists with a header quotacheck rejects (wrong magic, version above 1, short read).
 *
 * Missing files are created by quotacheck; unopenable files fail there.
 * Neither those nor failed reads or stream closes authorize a fresh quota file.
 */
function pmssQuotaFileHeaderInvalid(string $path, string $type): bool
{
    // quota-tools INITQMAGICS for the v2 tree format (vfsv0/vfsv1) as little-endian bytes, so 32-bit PHP compares the same.
    $magic = ['user' => '111fc0d9', 'group' => '2719c0d9'][$type] ?? null;
    if ($magic === null || !is_file($path) || ($handle = @fopen($path, 'rb')) === false) {
        return false;
    }
    try {
        $header = fread($handle, 8);
    } finally {
        // A read exception must release the stream before recovery unwinds.
        $closed = fclose($handle);
    }
    // An I/O failure is not evidence of a corrupt header; leave limits for quotacheck to read.
    if ($header === false || !$closed) return false;
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
    // A valid off line cannot certify a corrupted observation for a destructive recount.
    if (strpos($state['output'], "\0") !== false) return [];
    $commands = [];
    foreach ($types as $type) {
        // quotaon -p exits with the number of types on and prints "<type> quota on /home (<dev>) is off".
        // Contradictory on/off rows cannot prove that recounting this type is safe.
        if ($state['rc'] !== 0 || preg_match('#^'.$type.' quota on /home \(.*\) is off$#m', $state['output']) !== 1
            || preg_match('#^'.$type.' quota on /home \(.*\) is on$#m', $state['output']) === 1) {
            return [];
        }
        $fresh = pmssQuotaFileHeaderInvalid(($quotaFileDir ?? '/home').'/aquota.'.$type, $type) ? 'cb' : '';
        $commands[] = 'quotacheck -v'.$type[0].'mn'.$fresh.' /home';
    }

    return $commands;
}
