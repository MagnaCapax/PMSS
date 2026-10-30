<?php
/**
 * Safe mdadm checkarray selection for the root cron integrity check.
 *
 * The Debian checkarray helper accepts explicit md array arguments, so PMSS can
 * preserve the existing quarterly schedule while excluding arrays that have no
 * redundancy left to scrub.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/storageHealth.php';

const PMSS_MDADM_CHECKARRAY_BIN_DEFAULT = '/usr/share/mdadm/checkarray';
const PMSS_MDADM_CHECKARRAY_MDSTAT_DEFAULT = '/proc/mdstat';
const PMSS_MDADM_CHECKARRAY_SYS_BLOCK_DEFAULT = '/sys/block';
const PMSS_MDADM_CHECKARRAY_MIN_DAYS_DEFAULT = '/etc/seedbox/config/mdadmCheckarrayMinDays';
const PMSS_MDADM_CHECKARRAY_STATE_DEFAULT = '/var/lib/pmss/mdadm-checkarray-last';

/** Emit one cron-friendly status line with a stable prefix. */
function pmssMdadmCheckarrayLog(string $message): void { echo pmssLogFormatTimestampedLine($message, 'c', ' pmss-mdadm-checkarray: '); }

require_once __DIR__.'/mdadmCheckarray/plan.php';
require_once __DIR__.'/mdadmCheckarray/command.php';

/** Treat absent, malformed, or out-of-range file contents as unset. */
function pmssMdadmCheckarrayReadInteger(string $path): ?int
{
    // Keep invalid filesystem strings on the existing unset-value path.
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return null;
    $value = @file_get_contents($path);
    return is_string($value) ? pmssUnsignedDecimalIntParse(trim($value)) : null;
}

/** The host minimum applies only when both the setting and last start are valid. */
function pmssMdadmCheckarrayWithinMinDays(int $minDays, ?int $lastStarted, int $now): bool
{
    return $minDays > 0 && $lastStarted !== null && $now - $lastStarted < $minDays * 86400;
}

/** Cron entrypoint for the quarterly mdadm redundancy check. */
function pmssMdadmCheckarrayMain(array $argv): int
{
    if (in_array('--help', array_slice($argv, 1), true) || in_array('-h', array_slice($argv, 1), true)) {
        echo "Usage: mdadmCheckarray.php\n";
        echo "Runs Debian checkarray for non-degraded md arrays only.\n";
        return 0;
    }

    $binary = pmssEnvTrimmed('PMSS_MDADM_CHECKARRAY_BIN', PMSS_MDADM_CHECKARRAY_BIN_DEFAULT);
    if (!is_executable($binary)) {
        pmssMdadmCheckarrayLog('checkarray helper missing; skipping');
        return 0;
    }

    $minDays = pmssMdadmCheckarrayReadInteger(pmssEnvTrimmed(
        'PMSS_MDADM_CHECKARRAY_MIN_DAYS_PATH', PMSS_MDADM_CHECKARRAY_MIN_DAYS_DEFAULT
    )) ?? 0;
    $statePath = pmssEnvTrimmed('PMSS_MDADM_CHECKARRAY_STATE_PATH', PMSS_MDADM_CHECKARRAY_STATE_DEFAULT);
    $lastStarted = $minDays > 0 ? pmssMdadmCheckarrayReadInteger($statePath) : null;
    if (pmssMdadmCheckarrayWithinMinDays($minDays, $lastStarted, time())) {
        pmssMdadmCheckarrayLog('skipping: last check '.date('c', $lastStarted).' is within the '.$minDays.'-day host minimum');
        return 0;
    }

    $sysBlockRoot = pmssEnvTrimmed('PMSS_MDADM_CHECKARRAY_SYS_BLOCK_ROOT', PMSS_MDADM_CHECKARRAY_SYS_BLOCK_DEFAULT);
    $plan = pmssMdadmCheckarrayPlan(
        pmssEnvTrimmed('PMSS_MDADM_CHECKARRAY_MDSTAT_PATH', PMSS_MDADM_CHECKARRAY_MDSTAT_DEFAULT),
        $sysBlockRoot
    );

    if ($plan['fallback_all']) {
        pmssMdadmCheckarrayLog('unable to enumerate md arrays ('.$plan['reason'].'); preserving checkarray --all behavior');
    } else {
        foreach (['degraded' => 'degraded', 'unknown' => 'state unknown'] as $key => $label) {
            foreach ($plan[$key] as $array) {
                $idle = pmssMdadmCheckarrayRequestIdle($array, $sysBlockRoot) ? '; requested sync_action=idle' : '';
                pmssMdadmCheckarrayLog('skipping '.$array.' ('.$label.')'.$idle);
            }
        }

        if (empty($plan['healthy'])) {
            pmssMdadmCheckarrayLog(empty($plan['degraded']) && empty($plan['unknown']) ? 'no md arrays found' : 'no non-degraded md arrays to check');
            return 0;
        }
        pmssMdadmCheckarrayLog('checking non-degraded arrays: '.implode(' ', $plan['healthy']));
    }

    return pmssMdadmCheckarrayRunAndRemember($binary, $plan['healthy'], $statePath);
}
