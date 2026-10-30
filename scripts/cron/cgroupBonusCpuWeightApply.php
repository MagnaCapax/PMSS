#!/usr/bin/env php
<?php
/**
 * Raise each account's CPU priority (systemd CPUWeight on user-<uid>.slice) by its Free Bonus
 * Disk percent, read from the ~/.bonus marker the provisioning backend writes.
 *
 * Sibling of cgroupBfqWeightApply.php, which does the same for disk priority. The target is
 * max(base, min(10000, round(base * (1 + bonus/100)))), where base is the CPUWeight userConfig
 * applies (explicit CPUWeight in /etc/seedbox/config/users/<user>.json, else the RAM-derived
 * default). The marker is read exactly as the disk-priority cron reads it (#945 value clamp at the
 * 300% policy maximum); a missing or unreadable marker means bonus 0, which means the base weight:
 * no account ever drops below what its plan gives it.
 *
 * Only `systemctl set-property` is used, and only when the slice is active and its CPUWeight
 * differs. Nothing is restarted. userConfig runs set the base again; the next run here restores
 * the bonus. --dry-run prints the planned changes and writes nothing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

require_once __DIR__.'/../lib/cgroup/directApply.php';
require_once __DIR__.'/../lib/cgroup/bonusCpuWeight.php';

$USERS_DIR = '/etc/seedbox/config/users';
$DRY_RUN   = in_array('--dry-run', $argv ?? [], true);

if (!function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
    exit(pmssCliReturnWithStderr("FATAL: POSIX extension required to resolve managed user UIDs\n", 2));
}
if (posix_geteuid() !== 0) {
    exit(pmssCliReturnWithStderr("FATAL: must run as root (systemctl set-property)\n", 2));
}

openlog('pmss-bonus-cpu', LOG_PID, LOG_DAEMON);

$total = 0; $written = 0; $skippedNoSlice = 0; $errors = 0;

foreach (pmssCgroupDirectPlannedUsers($USERS_DIR, $total, $errors, function (string $user, array $json): ?int {
    return pmssBonusCpuBaseWeight($json);
}) as $entry) {
    list($user, $uid, $base) = $entry;
    $want  = pmssCpuApplyBonusWeight($base, pmssUserBonusPercentRead($user));
    $slice = 'user-'.$uid.'.slice';

    $out = [];
    $rc = 0;
    exec(pmssBuildCommand('systemctl', ['show', $slice, '-p', 'ActiveState', '-p', 'CPUWeight']).' 2>&1', $out, $rc);
    $state = $rc === 0 ? pmssBonusCpuSliceStateParse(implode("\n", $out)) : null;
    if ($state === null) {
        $errors++;
        syslog(LOG_WARNING, "systemctl show failed $user uid=$uid rc=$rc");
        continue;
    }
    if (!$state['active']) {
        $skippedNoSlice++;
        continue;
    }
    if ($state['weight'] === $want) {
        continue;
    }

    $from = $state['weight'] === null ? 'unset' : (string) $state['weight'];
    if ($DRY_RUN) {
        printf("[DRY-RUN] %-20s uid=%d %s -> %d\n", $user, $uid, $from, $want);
        $written++;
        continue;
    }

    $out = [];
    exec(pmssBuildCommand('systemctl', ['set-property', $slice, 'CPUWeight='.$want]).' 2>&1', $out, $rc);
    if ($rc !== 0) {
        $errors++;
        syslog(LOG_WARNING, "set-property failed $user uid=$uid desired=$want rc=$rc");
        continue;
    }
    $written++;
    syslog(LOG_INFO, "cpu weight $user uid=$uid: $from -> $want");
    echo date('Y-m-d H:i:s')." cpu weight $user uid=$uid: $from -> $want\n";
}

exit(pmssCgroupDirectFinishCycle($DRY_RUN, $total, $written, $skippedNoSlice, $errors));
