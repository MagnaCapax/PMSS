#!/usr/bin/env php
<?php
/**
 * Apply per-user blkio.bfq.weight directly to the cgroup-v1 kernel file,
 * bypassing the systemd v252+ cgroup_weight_io_to_blkio + BFQ_WEIGHT chain.
 *
 * The chain caps any IOWeight >= 200 at kernel bfq.weight = 181 on
 * cgroup-v1 hosts, collapsing tier differentiation. Direct write to
 * /sys/fs/cgroup/blkio/user.slice/user-N.slice/blkio.bfq.weight restores
 * the full kernel 1..1000 range. The cron self-heals systemd overwrites
 * (daemon-reload, set-property) within the 60-second interval.
 *
 * Per-user IOWeight in /etc/seedbox/config/users/<user>.json drives the
 * target, clamped to [1, 1000] (the kernel BFQ range) for every user.
 * Fallback formula round(3.535 * sqrt(ramMiB)) applies when JSON
 * IOWeight is absent. The Free Bonus Disk Policy percent from
 * /home/<user>/.bonus multiplies the selected base before the clamp.
 *
 * Idempotent: writes only when current kernel value differs from
 * desired. Hard fail on missing prerequisites (root, cgroup-v1, BFQ
 * scheduler) — no silent no-op.
 *
 * References upstream systemd issues #20522, #21187, #27622 (maintainer
 * acknowledgement that the cgroup-v1 BFQ chain is broken with no fix
 * queued).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

require_once __DIR__.'/../lib/cgroup/bfqFormula.php';
require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/user/identity.php';

// Constants — tunable top-of-file per AGENTS.md doctrine.
$USERS_DIR  = '/etc/seedbox/config/users';
$KERN_MAX   = 1000;      // kernel BFQ absolute max — single clamp for every user
$FORMULA_K  = 3.535;     // round(K * sqrt(ramMiB)) — produces 640 at 32768 MiB
$DRY_RUN    = in_array('--dry-run', $argv ?? [], true);

// Pre-flight — fail loud rather than silent no-op on misconfig.
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "FATAL: must run as root (writes to /sys/fs/cgroup/blkio/)\n");
    exit(2);
}

// cgroup-v2 unified hosts have no /sys/fs/cgroup/blkio — exit cleanly.
if (!is_dir('/sys/fs/cgroup/blkio')) {
    fwrite(STDERR, "INFO: /sys/fs/cgroup/blkio absent (cgroup-v2 host); script does not apply here\n");
    exit(0);
}

// BFQ scheduler must be the active elevator on at least one block device.
$bfqActive = false;
foreach (glob('/sys/block/sd*/queue/scheduler') ?: [] as $schedFile) {
    if (preg_match('/\[bfq\]/', (string) @file_get_contents($schedFile))) {
        $bfqActive = true;
        break;
    }
}
if (!$bfqActive) {
    fwrite(STDERR, "FATAL: BFQ scheduler not active on any sd* device; no work to do\n");
    exit(2);
}

function pmssBfqUserBonusPercentRead(string $user): int
{
    if (!pmssValidateUsername($user)) {
        return 0;
    }

    $path = '/home/'.$user.'/.bonus';
    $stat = @lstat($path);
    if (!is_array($stat)) {
        return 0;
    }

    // The bonus marker is a tiny scalar. Refuse links, devices, and bulky files.
    if ((($stat['mode'] ?? 0) & 0170000) !== 0100000 || (int) ($stat['size'] ?? 0) > 64) {
        return 0;
    }

    $raw = @file_get_contents($path, false, null, 0, 64);
    return is_string($raw) ? max(0, (int) trim($raw)) : 0;
}

openlog('pmss-bfq', LOG_PID, LOG_DAEMON);

$total = 0;
$written = 0;
$skippedNoSlice = 0;
$errors = 0;

// Root (uid 0) is intentionally NOT in /etc/seedbox/config/users/ — leave
// root.slice unmanaged. cgroupRootCheck.php enforces root's memory/tasks
// policy separately.

foreach (glob($USERS_DIR.'/*.json') ?: [] as $cfgPath) {
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
    $total++;

    $pwd = posix_getpwnam($user);
    if ($pwd === false) {
        $errors++;
        syslog(LOG_WARNING, "no passwd entry $user");
        continue;
    }
    $uid = (int) $pwd['uid'];

    // Prefer explicit JSON IOWeight; fall back to ramMiB-derived formula.
    if (isset($json['IOWeight']) && is_numeric($json['IOWeight'])) {
        $wRaw = (int) $json['IOWeight'];
    } else {
        $ramMiB = isset($json['ramMiB']) && is_numeric($json['ramMiB']) ? (int) $json['ramMiB'] : 0;
        $wRaw = pmssBfqFormulaWeight($ramMiB, (float) $FORMULA_K, (int) $KERN_MAX);
    }

    // Free Bonus Disk Policy: ~/.bonus holds tenure/spend bonus percent.
    $bonusPct = pmssBfqUserBonusPercentRead($user);
    $wRaw     = (int) round($wRaw * (1 + $bonusPct / 100));

    $w = max(1, min($KERN_MAX, $wRaw));

    $cgPath = '/sys/fs/cgroup/blkio/user.slice/user-'.$uid.'.slice/blkio.bfq.weight';
    if (!file_exists($cgPath)) {
        $skippedNoSlice++;
        continue;
    }

    $cur = (int) trim((string) @file_get_contents($cgPath));
    if ($cur === $w) {
        continue;
    }

    if ($DRY_RUN) {
        echo sprintf("[DRY-RUN] %-20s uid=%d %d -> %d\n", $user, $uid, $cur, $w);
        $written++;
        continue;
    }

    if (@file_put_contents($cgPath, (string) $w) === false) {
        syslog(LOG_WARNING, "write failed $user uid=$uid desired=$w");
        $errors++;
        continue;
    }
    $written++;
    syslog(LOG_INFO, "bfq $user uid=$uid: $cur -> $w");
}

$tag = $DRY_RUN ? 'DRY-RUN' : 'apply';
$msg = "$tag cycle total=$total written=$written skipped_no_slice=$skippedNoSlice errors=$errors";
syslog(LOG_INFO, $msg);
if ($DRY_RUN) {
    echo "$msg\n";
}
closelog();
exit($errors > 0 ? 1 : 0);
