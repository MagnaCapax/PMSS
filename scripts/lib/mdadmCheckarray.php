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

/** Emit one cron-friendly status line with a stable prefix. */
function pmssMdadmCheckarrayLog(string $message): void
{
    echo date('c').' pmss-mdadm-checkarray: '.$message."\n";
}

/** Return a non-empty environment override or the caller supplied default. */
function pmssMdadmCheckarrayEnvPath(string $key, string $default): string
{
    $value = getenv($key);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

/** Keep array names constrained before composing sysfs paths or shell args. */
function pmssMdadmCheckarrayArrayNameIsSafe(string $array): bool
{
    return preg_match('/^md\d+$/', $array) === 1;
}

/** Detect whether raw mdstat contains md array records at all. */
function pmssMdadmCheckarrayMdstatHasArrayLines(string $mdstat): bool
{
    return preg_match('/^md\d+\s*:/m', $mdstat) === 1;
}

/** Resolve degraded state from the existing mdstat map parser shape. */
function pmssMdadmCheckarrayMdstatEntryDegradedState(array $entry): ?bool
{
    $detail = (string) ($entry['detail'] ?? '');
    if (preg_match('/\[(\d+)\/(\d+)\]\s*\[([U_]+)\]/', $detail, $matches) !== 1) {
        return null;
    }

    return strpos($matches[3], '_') !== false || (int) $matches[1] !== (int) $matches[2];
}

/** Read `/sys/block/mdX/md/degraded`; null means this path cannot decide. */
function pmssMdadmCheckarraySysfsDegradedState(string $array, string $sysBlockRoot): ?bool
{
    if (!pmssMdadmCheckarrayArrayNameIsSafe($array)) {
        return null;
    }

    $raw = @file_get_contents(rtrim($sysBlockRoot, '/').'/'.$array.'/md/degraded');
    if (!is_string($raw) || !ctype_digit(trim($raw))) {
        return null;
    }

    return (int) trim($raw) > 0;
}

/** Prefer sysfs state, then fall back to the shared mdstat parser result. */
function pmssMdadmCheckarrayEntryDegradedState(array $entry, string $sysBlockRoot): ?bool
{
    $array = (string) ($entry['array'] ?? '');
    $sysfs = pmssMdadmCheckarraySysfsDegradedState($array, $sysBlockRoot);
    return $sysfs !== null ? $sysfs : pmssMdadmCheckarrayMdstatEntryDegradedState($entry);
}

/**
 * Build the array selection plan for one checkarray run.
 *
 * @return array{fallback_all:bool,reason:string,healthy:array<int,string>,degraded:array<int,string>,unknown:array<int,string>}
 */
function pmssMdadmCheckarrayPlan(string $mdstatPath, string $sysBlockRoot): array
{
    $plan = ['fallback_all' => false, 'reason' => '', 'healthy' => [], 'degraded' => [], 'unknown' => []];
    $mdstat = @file_get_contents($mdstatPath);
    if (!is_string($mdstat)) {
        $plan['fallback_all'] = true;
        $plan['reason'] = 'mdstat_unreadable';
        return $plan;
    }

    $entries = pmssStorageHealthRaidEntriesParse($mdstat, date('c'));
    if (empty($entries) && pmssMdadmCheckarrayMdstatHasArrayLines($mdstat)) {
        $plan['fallback_all'] = true;
        $plan['reason'] = 'mdstat_parse_empty';
        return $plan;
    }

    foreach ($entries as $entry) {
        $array = (string) ($entry['array'] ?? '');
        if (!pmssMdadmCheckarrayArrayNameIsSafe($array)) {
            continue;
        }

        $state = pmssMdadmCheckarrayEntryDegradedState($entry, $sysBlockRoot);
        if ($state === true) {
            $plan['degraded'][] = $array;
        } elseif ($state === false) {
            $plan['healthy'][] = $array;
        } else {
            $plan['unknown'][] = $array;
        }
    }

    return $plan;
}

/** Best-effort cancellation for a queued/interrupted check on skipped arrays. */
function pmssMdadmCheckarrayRequestIdle(string $array, string $sysBlockRoot): bool
{
    if (!pmssMdadmCheckarrayArrayNameIsSafe($array)) {
        return false;
    }

    $path = rtrim($sysBlockRoot, '/').'/'.$array.'/md/sync_action';
    return file_exists($path) && @file_put_contents($path, "idle\n") !== false;
}

/** Run Debian checkarray and preserve its stdout/stderr in the cron log. */
function pmssMdadmCheckarrayRunCommand(string $binary, array $arrays): int
{
    $args = array_merge(['--cron', '--idle', '--quiet'], empty($arrays) ? ['--all'] : array_values($arrays));
    $result = pmssCommandCapture(pmssBuildCommand($binary, $args), 120);
    if ((string) ($result['stdout'] ?? '') !== '') {
        echo (string) $result['stdout'];
    }
    if ((string) ($result['stderr'] ?? '') !== '') {
        fwrite(STDERR, (string) $result['stderr']);
    }

    return (int) ($result['rc'] ?? 1);
}

/** Cron entrypoint for the quarterly mdadm redundancy check. */
function pmssMdadmCheckarrayMain(array $argv): int
{
    if (in_array('--help', array_slice($argv, 1), true) || in_array('-h', array_slice($argv, 1), true)) {
        echo "Usage: mdadmCheckarray.php\n";
        echo "Runs Debian checkarray for non-degraded md arrays only.\n";
        return 0;
    }

    $binary = pmssMdadmCheckarrayEnvPath('PMSS_MDADM_CHECKARRAY_BIN', PMSS_MDADM_CHECKARRAY_BIN_DEFAULT);
    if (!is_executable($binary)) {
        pmssMdadmCheckarrayLog('checkarray helper missing; skipping');
        return 0;
    }

    $sysBlockRoot = pmssMdadmCheckarrayEnvPath('PMSS_MDADM_CHECKARRAY_SYS_BLOCK_ROOT', PMSS_MDADM_CHECKARRAY_SYS_BLOCK_DEFAULT);
    $plan = pmssMdadmCheckarrayPlan(
        pmssMdadmCheckarrayEnvPath('PMSS_MDADM_CHECKARRAY_MDSTAT_PATH', PMSS_MDADM_CHECKARRAY_MDSTAT_DEFAULT),
        $sysBlockRoot
    );

    if ($plan['fallback_all']) {
        pmssMdadmCheckarrayLog('unable to enumerate md arrays ('.$plan['reason'].'); preserving checkarray --all behavior');
        return pmssMdadmCheckarrayRunCommand($binary, []);
    }

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
    return pmssMdadmCheckarrayRunCommand($binary, $plan['healthy']);
}
