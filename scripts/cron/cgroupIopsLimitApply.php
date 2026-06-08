#!/usr/bin/env php
<?php
/**
 * Apply per-user blkio.throttle.{read,write}_iops_device directly to the
 * cgroup-v1 kernel files, bypassing systemd set-property which is a no-op
 * for IOReadIOPSMax / IOWriteIOPSMax on cgroup-v1 hybrid hosts.
 *
 * Empirically verified 2026-06-01 on heshtok (cgroup-v1, kernel 6.1):
 * `systemctl set-property user-N.slice IOReadIOPSMax="/dev/md5 2000"`
 * succeeds, `systemctl show` reports the property as set, but
 * /sys/fs/cgroup/blkio/user.slice/user-N.slice/blkio.throttle.read_iops_device
 * remains empty. Mirrors the same class as the BFQ-weight chain bug
 * (cgroupBfqWeightApply.php) where systemd cannot reliably push v1
 * blkio properties.
 *
 * Per-user IOReadIOPS / IOWriteIOPS in /etc/seedbox/config/users/<user>.json
 * drive the targets. Format expected: "/home:N" or "/dev/DEVICE:N".
 * "/home:" prefix is resolved at apply-time to the actual backing
 * device's major:minor. Empty value = no-op (no clear-on-empty; see
 * GH#620 for the downgrade-clear-on-zero patch).
 *
 * Idempotent: writes only when current kernel value differs from
 * desired. Skips users whose slice doesn't exist yet (new accounts
 * not yet started).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/user/identity.php';

const PMSS_IOPS_USERS_DIR = '/etc/seedbox/config/users';

$DRY_RUN = in_array('--dry-run', $argv ?? [], true);

if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
    fwrite(STDERR, "FATAL: POSIX extension required to resolve managed user UIDs\n");
    exit(2);
}
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "FATAL: must run as root (writes to /sys/fs/cgroup/blkio/)\n");
    exit(2);
}
if (!is_dir('/sys/fs/cgroup/blkio')) {
    // v2 unified host — systemd IOReadIOPSMax already maps to io.max, no direct-write needed.
    fwrite(STDERR, "INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); not applicable here\n");
    exit(0);
}

/**
 * Resolve /home backing device to "major:minor" decimal string.
 * Returns null if /home isn't on a /dev/ block device or major:minor can't be read.
 */
function pmssIopsResolveHomeMajorMinor(): ?string
{
    $devPath = trim((string) @shell_exec('findmnt -no SOURCE /home 2>/dev/null'));
    if ($devPath === '' || strncmp($devPath, '/dev/', 5) !== 0) {
        return null;
    }
    $devName = basename($devPath);
    $devFile = '/sys/block/'.$devName.'/dev';
    if (!is_file($devFile)) {
        return null;
    }
    $majMin = trim((string) @file_get_contents($devFile));
    return pmssIopsMajorMinorValid($majMin) ? $majMin : null;
}

/** Accept only kernel major:minor tokens before composing cgroup writes. */
function pmssIopsMajorMinorValid(string $majMin): bool
{
    return preg_match('/^[0-9]+:[0-9]+$/', $majMin) === 1;
}

/** Keep direct cgroup writes scoped to the expected per-user blkio throttle files. */
function pmssIopsThrottlePathAllowed(string $cgPath): bool
{
    return preg_match('#^/sys/fs/cgroup/blkio/user\.slice/user-[1-9][0-9]*\.slice/blkio\.throttle\.(read|write)_iops_device$#', $cgPath) === 1;
}

/**
 * Parse an IOReadIOPS/IOWriteIOPS spec into its positive-integer value.
 * "/home:2000" -> 2000. "/dev/md0:2000" -> 2000. Empty/malformed -> null.
 */
function pmssIopsParseSpec($raw): ?int
{
    if (!is_string($raw) || $raw === '' || $raw === '0') {
        return null;
    }
    if (preg_match('/:([0-9]+)$/', $raw, $m) !== 1) {
        return null;
    }
    $n = (int) $m[1];
    return $n > 0 ? $n : null;
}

/**
 * Write "MAJ:MIN VALUE" to the cgroup blkio.throttle file IF current state differs.
 * Returns true if written or already correct; false on error.
 */
function pmssIopsWriteThrottle(string $cgPath, string $majMin, int $iops, bool $dryRun): array
{
    if (!pmssIopsMajorMinorValid($majMin) || $iops <= 0 || !pmssIopsThrottlePathAllowed($cgPath)) {
        return ['ok' => false, 'reason' => 'invalid-target', 'cur' => null];
    }
    if (!is_file($cgPath) || !is_writable($cgPath)) {
        return ['ok' => false, 'reason' => 'unwritable', 'cur' => null];
    }
    $cur = trim((string) @file_get_contents($cgPath));
    $desired = $majMin.' '.$iops;
    // Current may contain other-device entries; check whether OUR device line matches.
    $hasMatch = false;
    foreach (preg_split('/\r?\n/', $cur) ?: [] as $line) {
        if (trim($line) === $desired) { $hasMatch = true; break; }
    }
    if ($hasMatch) {
        return ['ok' => true, 'reason' => 'already-set', 'cur' => $cur];
    }
    if ($dryRun) {
        return ['ok' => true, 'reason' => 'dry-run', 'cur' => $cur];
    }
    if (@file_put_contents($cgPath, $desired) === false) {
        return ['ok' => false, 'reason' => 'write-failed', 'cur' => $cur];
    }
    return ['ok' => true, 'reason' => 'written', 'cur' => $cur];
}

$majMin = pmssIopsResolveHomeMajorMinor();
if ($majMin === null) {
    fwrite(STDERR, "FATAL: unable to resolve /home major:minor\n");
    exit(2);
}

openlog('pmss-iops', LOG_PID, LOG_DAEMON);

$total = 0; $written = 0; $skippedNoSlice = 0; $errors = 0;

foreach (glob(PMSS_IOPS_USERS_DIR.'/*.json') ?: [] as $cfgPath) {
    $user = basename($cfgPath, '.json');
    if (!pmssValidateUsername($user)) {
        $errors++;
        syslog(LOG_WARNING, "invalid username config ".str_replace(["\r", "\n", "\0"], '?', $user));
        continue;
    }

    $json = pmssJsonFileReadAssoc($cfgPath);
    if (!is_array($json)) {
        $errors++;
        syslog(LOG_WARNING, "bad json $user");
        continue;
    }

    $readIops  = pmssIopsParseSpec($json['IOReadIOPS']  ?? null);
    $writeIops = pmssIopsParseSpec($json['IOWriteIOPS'] ?? null);
    if ($readIops === null && $writeIops === null) {
        continue; // no caps configured for this user
    }
    $total++;

    $pwd = posix_getpwnam($user);
    if ($pwd === false) {
        $errors++;
        syslog(LOG_WARNING, "no passwd entry $user");
        continue;
    }
    $uid = pmssPasswdEntryPositiveUid($pwd);
    if ($uid === null) {
        $errors++;
        syslog(LOG_WARNING, "unsafe passwd uid $user");
        continue;
    }

    $sliceDir = '/sys/fs/cgroup/blkio/user.slice/user-'.$uid.'.slice';
    if (!is_dir($sliceDir)) {
        $skippedNoSlice++;
        continue;
    }

    foreach ([
        ['read', $readIops, $sliceDir.'/blkio.throttle.read_iops_device'],
        ['write', $writeIops, $sliceDir.'/blkio.throttle.write_iops_device'],
    ] as $entry) {
        list($dir, $iops, $cgPath) = $entry;
        if ($iops === null) {
            continue;
        }
        $res = pmssIopsWriteThrottle($cgPath, $majMin, $iops, $DRY_RUN);
        if (!$res['ok']) {
            $errors++;
            syslog(LOG_WARNING, "$dir-iops $user uid=$uid: ".$res['reason']);
            continue;
        }
        if ($res['reason'] === 'written') {
            $written++;
            syslog(LOG_INFO, "$dir-iops $user uid=$uid: $majMin $iops");
        } elseif ($res['reason'] === 'dry-run' && $DRY_RUN) {
            echo sprintf("[DRY-RUN] %-20s uid=%d %s: %s %d\n", $user, $uid, $dir, $majMin, $iops);
        }
    }
}

$tag = $DRY_RUN ? 'DRY-RUN' : 'apply';
$msg = "$tag cycle total=$total written=$written skipped_no_slice=$skippedNoSlice errors=$errors";
syslog(LOG_INFO, $msg);
if ($DRY_RUN) {
    echo "$msg\n";
}
closelog();
exit($errors > 0 ? 1 : 0);
