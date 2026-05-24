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
 * target, clamped to [1, 700] for customer-reachable plans. The
 * "bfq_addon": true JSON flag enables the [701, 1000] bonus band for
 * paid addons. Fallback formula round(3.535 * sqrt(ramMiB)) clamped
 * [1, 700] applies when JSON IOWeight is absent.
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

// Constants — tunable top-of-file per AGENTS.md doctrine.
$USERS_DIR  = '/etc/seedbox/config/users';
$CUST_MAX   = 700;       // customer-reachable ceiling
$KERN_MAX   = 1000;      // kernel BFQ absolute max (bonus-addon ceiling)
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
        $wRaw = pmssBfqFormulaWeight($ramMiB, (float) $FORMULA_K, (int) $CUST_MAX);
    }

    // bfq_addon flag unlocks the [CUST_MAX+1, KERN_MAX] bonus band.
    $bonus = !empty($json['bfq_addon']);
    $cap = $bonus ? $KERN_MAX : $CUST_MAX;
    $w = max(1, min($cap, $wRaw));

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
        echo sprintf("[DRY-RUN] %-20s uid=%d %d -> %d%s\n", $user, $uid, $cur, $w, $bonus ? ' (bonus)' : '');
        $written++;
        continue;
    }

    if (@file_put_contents($cgPath, (string) $w) === false) {
        syslog(LOG_WARNING, "write failed $user uid=$uid desired=$w");
        $errors++;
        continue;
    }
    $written++;
    syslog(LOG_INFO, "bfq $user uid=$uid: $cur -> $w".($bonus ? ' (bonus)' : ''));
}

$tag = $DRY_RUN ? 'DRY-RUN' : 'apply';
$msg = "$tag cycle total=$total written=$written skipped_no_slice=$skippedNoSlice errors=$errors";
syslog(LOG_INFO, $msg);
if ($DRY_RUN) {
    echo "$msg\n";
}
closelog();
exit($errors > 0 ? 1 : 0);
