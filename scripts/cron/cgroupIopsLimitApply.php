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

require_once __DIR__.'/../lib/cgroup/directApply.php';

const PMSS_IOPS_USERS_DIR = '/etc/seedbox/config/users';

$DRY_RUN = in_array('--dry-run', $argv ?? [], true);

pmssCgroupDirectRequireRuntime('INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); not applicable here');

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

/**
 * Parse an IOReadIOPS/IOWriteIOPS spec into its positive-integer value.
 * "/home:2000" -> 2000. "/dev/md0:2000" -> 2000. Empty/malformed -> null.
 */
function pmssIopsParseSpec($raw): ?int
{
    if (!is_string($raw) || $raw === '' || $raw === '0') {
        return null;
    }

    // Do not let an arbitrary "label:number" string trigger a /home throttle.
    if (preg_match('#^(?:/home|/dev/[^:\r\n\x00]+):([0-9]+)$#', $raw, $m) !== 1) {
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
    if (!pmssIopsMajorMinorValid($majMin)
        || $iops <= 0
        || !pmssCgroupDirectUserBlkioPathAllowed($cgPath, ['blkio.throttle.read_iops_device', 'blkio.throttle.write_iops_device'])
    ) {
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

foreach (pmssCgroupDirectPlannedUsers(PMSS_IOPS_USERS_DIR, $total, $errors, function (string $user, array $json): ?array {
    $readIops  = pmssIopsParseSpec($json['IOReadIOPS']  ?? null);
    $writeIops = pmssIopsParseSpec($json['IOWriteIOPS'] ?? null);
    if ($readIops === null && $writeIops === null) return null; // no caps configured for this user
    return [$readIops, $writeIops];
}) as $entry) {
    list($user, $uid, $limits) = $entry;

    $sliceDir = pmssCgroupDirectUserSliceDir($uid);
    if (!is_dir($sliceDir)) {
        $skippedNoSlice++;
        continue;
    }

    foreach ([
        ['read', $limits[0], pmssCgroupDirectUserBlkioFilePath($uid, 'blkio.throttle.read_iops_device')],
        ['write', $limits[1], pmssCgroupDirectUserBlkioFilePath($uid, 'blkio.throttle.write_iops_device')],
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

exit(pmssCgroupDirectFinishCycle($DRY_RUN, $total, $written, $skippedNoSlice, $errors));
