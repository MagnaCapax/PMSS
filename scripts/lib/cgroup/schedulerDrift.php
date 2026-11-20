<?php
/**
 * Scheduler drift under a mount: the disks whose elevator the boot-tuning unit sets to bfq but that run something else.
 *
 * Every per-user control PMSS applies is re-applied by a cron, except the elevator the per-user bfq weights need: it is
 * written once at boot by pmss-boot-tuning.service (ADR 0064, the sole writer of per-disk queue knobs). A runtime write
 * of another scheduler to a member disk therefore switched the weights, the bonus and the paid I/O priority off until
 * the next boot. pmssCgroupPolicyMountBfqActive() answers "does bfq govern any leaf?"; this answers "which leaves left
 * bfq?", so one md member out of six is seen too.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../blockDeviceLeaves.php';
require_once __DIR__.'/policy.php';

/** Device-name prefixes the boot-tuning unit sets to bfq (template.pmss-boot-tuning.sh). */
const PMSS_BFQ_DEVICE_CLASS_RE = '/^(sd|vd|xvd|bcache|nvme)[a-z0-9]*$/';

/**
 * @return list<string>|null leaves of the mount's backing device that should run bfq and do not; null when the
 *                           topology cannot be read
 */
function pmssCgroupPolicyMountDriftedLeaves(string $mountPath = '/home', ?string $source = null, string $sysBlock = '/sys/block'): ?array
{
    // Reject raw corruption before trim/basename can hide it or mount lookup can run.
    if (($source === null && strpos($mountPath, "\0") !== false)
        || ($source !== null && strpos($source, "\0") !== false)) return null;
    $device = basename(trim($source ?? pmssCgroupPolicyMountSourceResolve($mountPath)));
    if ($device === '' || preg_match('/^[A-Za-z0-9_-]+$/', $device) !== 1) return null;

    $topology = pmssBlockDeviceLeaves($device, $sysBlock, static function (string $pattern) { return @glob($pattern); });
    if (!$topology['complete']) return null;
    $drifted = [];
    foreach ($topology['leaves'] as $leaf) {
        if (preg_match(PMSS_BFQ_DEVICE_CLASS_RE, $leaf) !== 1) continue;
        $scheduler = @file_get_contents($sysBlock.'/'.$leaf.'/queue/scheduler');
        // An empty or corrupt observation proves neither drift nor an active BFQ scheduler.
        if ($scheduler === false || strpos($scheduler, "\0") !== false || trim($scheduler) === '') return null;
        if (preg_match('/\[bfq\]/', $scheduler) !== 1) $drifted[] = $leaf;
    }
    return $drifted;
}
