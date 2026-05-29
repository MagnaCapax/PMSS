#!/usr/bin/env php
<?php
/**
 * Apply per-user cgroup I/O weights directly to kernel files, bypassing the
 * systemd v252+ cgroup_weight_io_to_blkio + BFQ_WEIGHT chain on cgroup-v1 BFQ.
 *
 * The chain caps any IOWeight >= 200 at kernel bfq.weight = 181 on
 * cgroup-v1 hosts, collapsing tier differentiation. Direct write to
 * /sys/fs/cgroup/blkio/user.slice/user-N.slice/blkio.bfq.weight restores
 * the full kernel 1..1000 range. On cgroup-v2 hosts, the same cron writes
 * user.slice/user-N.slice/io.bfq.weight when BFQ exposes it, falling back to
 * io.weight for generic v2 I/O weighting. The cron self-heals systemd
 * overwrites (daemon-reload, set-property) within the 5-minute cycle.
 *
 * Per-user "IOWeight" in /etc/seedbox/config/users/<user>.json is honored
 * as an explicit override (clamped to [1, 1000]). When absent, the
 * fallback formula round(3.535 * sqrt(ramMiB) * (1 + bonusPct/100))
 * applies. "bonusPct" in the same JSON (integer percent from the Free
 * Bonus Disk Policy, 0-300) multiplies the RAM-derived base so tenure
 * and spend carry customers into the [701, 1000] band by design.
 *
 * Idempotent: writes only when current kernel value differs from desired. Hard
 * fail on missing hierarchy prerequisites; v1 also requires an active BFQ
 * scheduler because the direct blkio.bfq.weight write is BFQ-specific.
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
require_once __DIR__.'/../lib/cgroup/bfqWeightTarget.php';
require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/update/systemPrep/hostEnvironment.php';

// Constants — tunable top-of-file per AGENTS.md doctrine.
$USERS_DIR  = '/etc/seedbox/config/users';
$CGROUP_DIR = '/sys/fs/cgroup';
$BLOCK_DIR  = '/sys/block';
$KERN_MAX   = 1000;      // kernel BFQ absolute max (the only artificial cap)
$FORMULA_K  = 3.535;     // round(K * sqrt(ramMiB)) — produces 640 at 32768 MiB
$DRY_RUN    = in_array('--dry-run', $argv ?? [], true);

// Pre-flight — fail loud rather than silent no-op on misconfig.
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "FATAL: must run as root (writes to /sys/fs/cgroup/)\n");
    exit(2);
}

$cgroupMode = pmssCgroupMode();
if (!pmssCgroupBfqWeightControllerReady($cgroupMode, $CGROUP_DIR)) {
    fwrite(STDERR, "FATAL: cgroup I/O controller unavailable for mode $cgroupMode\n");
    exit(2);
}

// v1 direct writes target BFQ-specific files, so require a BFQ-backed device.
if ($cgroupMode === 'v1' && !pmssCgroupBfqWeightBfqSchedulerActive($BLOCK_DIR)) {
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

    // Tenure/spend bonus percent (0-300). Applied uniformly to whichever base
    // is selected below — explicit JSON IOWeight or RAM-derived fallback. The
    // fallback path is rare in production (most users have an explicit tier-
    // assigned IOWeight at provisioning); applying bonus only in the fallback
    // would make the feature dead for the typical case.
    $bonusPct = isset($json['bonusPct']) && is_numeric($json['bonusPct']) ? (float) $json['bonusPct'] : 0.0;

    // Prefer explicit JSON IOWeight; fall back to ramMiB-derived formula.
    if (isset($json['IOWeight']) && is_numeric($json['IOWeight'])) {
        $wBase = (int) $json['IOWeight'];
    } else {
        $ramMiB = isset($json['ramMiB']) && is_numeric($json['ramMiB']) ? (int) $json['ramMiB'] : 0;
        $wBase = pmssBfqFormulaWeight($ramMiB, (float) $FORMULA_K, (int) $KERN_MAX);
    }

    // Bonus multiplier applied to the selected base, then kernel-clamped.
    $wRaw = (int) round($wBase * (1 + max(0.0, $bonusPct) / 100));
    $w = max(1, min($KERN_MAX, $wRaw));

    $cgPath = pmssCgroupBfqWeightTargetPath($cgroupMode, $uid, $CGROUP_DIR);
    if (!file_exists($cgPath)) {
        $skippedNoSlice++;
        continue;
    }

    $cur = pmssCgroupBfqWeightCurrentValue((string) @file_get_contents($cgPath));
    if ($cur === $w) {
        continue;
    }

    $bonusTag = ($bonusPct > 0) ? sprintf(' (+%.0f%%)', $bonusPct) : '';

    if ($DRY_RUN) {
        echo sprintf("[DRY-RUN] %-20s uid=%d %d -> %d%s\n", $user, $uid, $cur, $w, $bonusTag);
        $written++;
        continue;
    }

    if (@file_put_contents($cgPath, pmssCgroupBfqWeightWritePayload($cgroupMode, $w)) === false) {
        syslog(LOG_WARNING, "write failed $user uid=$uid desired=$w");
        $errors++;
        continue;
    }
    $written++;
    syslog(LOG_INFO, "bfq $user uid=$uid: $cur -> $w".$bonusTag);
}

$tag = $DRY_RUN ? 'DRY-RUN' : 'apply';
$msg = "$tag cycle total=$total written=$written skipped_no_slice=$skippedNoSlice errors=$errors";
syslog(LOG_INFO, $msg);
if ($DRY_RUN) {
    echo "$msg\n";
}
closelog();
exit($errors > 0 ? 1 : 0);
