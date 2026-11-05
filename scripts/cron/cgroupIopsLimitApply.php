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
require_once __DIR__.'/../lib/cgroup/policy.php';
require_once __DIR__.'/../lib/cgroup/ioCeilingHistory.php';
require_once __DIR__.'/../lib/cgroup/iopsCensus.php';

const PMSS_IOPS_USERS_DIR = '/etc/seedbox/config/users';

$DRY_RUN = in_array('--dry-run', $argv ?? [], true);
$CENSUS = in_array('--census', $argv ?? [], true);

pmssCgroupDirectRequireRuntime('INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); not applicable here');

$homeDevice = pmssCgroupPolicyMountSourceResolve('/home');
$majMin = $homeDevice !== '' ? pmssCgroupPolicyDeviceMajorMinorResolve($homeDevice) : null;
if ($majMin === null) {
    exit(pmssCliReturnWithStderr("FATAL: unable to resolve /home major:minor\n", 2));
}

openlog('pmss-iops', LOG_PID, LOG_DAEMON);

$total = 0; $written = 0; $skippedNoSlice = 0; $errors = 0;
$policy = pmssCgroupPolicyLoad();
$userConfigs = [];
foreach (pmssCgroupDirectUserConfigs(PMSS_IOPS_USERS_DIR, $errors) as $configEntry) {
    list($configUser, $configPayload) = $configEntry;
    $userConfigs[$configUser] = $configPayload;
}

$hostReadIops = pmssIoCeilingPublishedReadIops(pmssResolvePathFromEnv('PMSS_IO_CEILING_STATE_PATH', PMSS_IO_CEILING_STATE_PATH_DEFAULT));
$homeMdBacked = $homeDevice !== '' && pmssCgroupPolicyHomeDeviceIsMdBacked($homeDevice);
$homeClass = $homeMdBacked ? null : pmssCgroupPolicyHomeStorageClassResolve($homeDevice);
$classFloor = pmssCgroupPolicyReadIopsClassFloor($homeClass, $policy);
$weightSum = pmssCgroupPolicyUserIoWeightSum($userConfigs, $policy);
$derivationAllowed = !$homeMdBacked && pmssCgroupPolicyReadIopsDerivationAllowed(
    $hostReadIops,
    $classFloor,
    count($userConfigs),
    static function (string $message): void { syslog(LOG_WARNING, $message); }
);

// Only this cron owns dynamic throttle writes; BFQ continues to own shadow cycles.
$dynamicEnforce = ($policy['cgroup']['dynamicIops']['mode'] ?? null) === 'enforce';
$dynamicCaps = null;
if ($dynamicEnforce) {
    $DRY_RUN = $DRY_RUN || pmssEnvFlagEnabled('PMSS_DRY_RUN');
    $allocation = null;
    try {
        require_once __DIR__.'/../lib/cgroup/dynamicIopsShadow.php';
        $users = [];
        foreach ($userConfigs as $user => $json) $users[$user] = ['source' => $json, 'weight' => pmssBfqUserWeight($json)];
        if (pmssDynamicIopsShadowCycle($users, $policy, $allocation, $DRY_RUN || $CENSUS, $errors === 0)) {
            $dynamicCaps = $allocation['read_caps'] ?? null;
        } else {
            $errors++;
            syslog(LOG_WARNING, 'dynamic-iops enforce allocation or log failed; keeping kernel read caps');
        }
    } catch (Throwable $error) {
        $errors++;
        syslog(LOG_WARNING, 'dynamic-iops enforce cycle failed; keeping kernel read caps');
    }
    if ($dynamicCaps === null) {
        syslog(LOG_WARNING, 'dynamic-iops enforce read_action=keep-kernel fallback='.($allocation['fallback'] ?? 'cycle-failed'));
    }
}

if ($CENSUS) {
    fwrite(STDERR, "Census uses slice-local telemetry: child I/O may be missing; low P95 values do not prove rollout safety.\n");
    echo "user\tuid\tread_cap\twrite_cap\tread_p95_24h\tintervals\tblkio_children\n";
}
foreach ($userConfigs as $user => $json) {
    $readIops  = pmssIopsParseSpec($json['IOReadIOPS']  ?? null);
    $writeIops = pmssIopsParseSpec($json['IOWriteIOPS'] ?? null);
    if ($dynamicEnforce) $readIops = $dynamicCaps[$user] ?? null;
    if (!$dynamicEnforce && $readIops === null) {
        $readIops = pmssCgroupPolicyDerivedReadIopsCap(
            $json,
            $policy,
            $hostReadIops,
            $weightSum,
            count($userConfigs),
            $homeClass,
            $derivationAllowed
        );
    }
    if ($readIops === null && $writeIops === null && !$CENSUS) {
        continue; // no caps configured or derived for this user
    }
    $total++;
    if (($uid = pmssCgroupDirectUserUidOrError($user, $errors)) === null) {
        continue;
    }
    $limits = [$readIops, $writeIops];

    $sliceDir = pmssCgroupDirectUserSliceDir($uid);
    if ($CENSUS) {
        pmssCgroupIopsCensusPrint($user, $uid, $readIops, $writeIops, $sliceDir);
        continue; // Census never reaches the throttle writer, even with --dry-run.
    }
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
            pmssCgroupDirectAudit($user, $uid, $dir.'_iops',
                pmssCgroupDirectDeviceValueParse($res['cur'], $majMin), $iops,
                $dynamicEnforce && $dir === 'read' ? 'dynamic-iops' : __FILE__);
        } elseif ($res['reason'] === 'dry-run' && $DRY_RUN) {
            printf("[DRY-RUN] %-20s uid=%d %s: %s %d\n", $user, $uid, $dir, $majMin, $iops);
        }
    }
}

if ($CENSUS) { closelog(); exit($errors > 0 ? 1 : 0); }
exit(pmssCgroupDirectFinishCycle($DRY_RUN, $total, $written, $skippedNoSlice, $errors));
