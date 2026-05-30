<?php
/**
 * Host resource and cgroup convergence helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

/** Read a non-negative integer override from the environment. */
function pmssSystemPrepReadDigitEnv(string $key): ?int
{
    return (($override = getenv($key)) !== false && ctype_digit($override)) ? (int) $override : null;
}

/**
 * Return total system memory in MiB (rounded).
 */
function pmssTotalMemMiB(): int
{
    if (($override = pmssSystemPrepReadDigitEnv('PMSS_TOTAL_MEM_MIB')) !== null) {
        return (int) $override;
    }

    return pmssProcMeminfoTotalMiBRead();
}

/**
 * Return total logical CPU threads.
 */
function pmssTotalCpuThreads(): int
{
    if (($override = pmssSystemPrepReadDigitEnv('PMSS_TOTAL_CPU_THREADS')) !== null) {
        return (int) $override;
    }

    $count = substr_count(pmssReadRegularFileContents('/proc/cpuinfo') ?? '', 'processor');
    return $count > 0 ? $count : max(0, (int) trim((string) @shell_exec('nproc')));
}

/**
 * Detect cgroup mode: 'v2', 'v1', or 'unknown'.
 */
function pmssCgroupMode(): string
{
    if (($override = getenv('PMSS_CGROUP_MODE')) === 'v1' || $override === 'v2') {
        return $override;
    }

    if (is_file('/sys/fs/cgroup/cgroup.controllers')
        || strpos(pmssReadRegularFileContents('/proc/self/mountinfo') ?? '', ' - cgroup2 ') !== false) {
        return 'v2';
    }

    if (strpos(pmssReadRegularFileContents('/proc/cmdline') ?? '', 'systemd.unified_cgroup_hierarchy=0') !== false) {
        return 'v1';
    }

    foreach (glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) !== 'unified') {
            return 'v1';
        }
    }

    return 'unknown';
}

/**
 * Guarantee that cgroup mounts and PID limits are configured sanely.
 */
function pmssEnsureCgroupsConfigured(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $mode = pmssCgroupMode();
    if ($mode === 'v1') {
        $fstab = @file_get_contents('/etc/fstab');
        if ($fstab === false || strpos($fstab, ' /sys/fs/cgroup ') === false) {
            $mountLine = "\ncgroup  /sys/fs/cgroup  cgroup  defaults  0   0\n";
            if (@file_put_contents('/etc/fstab', $mountLine, FILE_APPEND) === false) {
                $log('[WARN] Unable to append cgroup mount to /etc/fstab');
            } else {
                $log('Appended cgroup mount configuration to /etc/fstab (v1)');
            }
            runStep('Mounting /sys/fs/cgroup (v1)', 'mount /sys/fs/cgroup');
        } else {
            $log('[SKIP] cgroup v1 mount already present in /etc/fstab');
        }
    } elseif ($mode === 'v2') {
        $log('[SKIP] cgroup v2 detected; no fstab mount or cgroup-bin needed');
    } else {
        $log('[WARN] cgroup mode unknown; leaving mounts untouched');
    }

    if ($mode === 'v1') {
        if (file_exists('/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max')) {
            runStep('Raising PID limit for root user slice (v1)', "sh -c 'echo 100000 > /sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max'");
        } else {
            $log('[SKIP] pids.max controller path missing (v1), relying on system defaults');
        }
    } else {
        $log('[SKIP] Using systemd TasksMax for PID limits (cgroup v2)');
    }

    try {
        $procMount = $hidepid = '';
        foreach (preg_split('/\r?\n/', pmssReadRegularFileContents('/proc/self/mountinfo') ?? '') ?: [] as $l) {
            if (preg_match('#\s(/proc)\s#', $l)) {
                $procMount = $l;
            }
        }
        if ($procMount !== '') {
            $parts = explode(' ', $procMount);
            if (isset($parts[3])) {
                foreach (explode(',', $parts[3]) as $o) {
                    if (strpos($o, 'hidepid=') === 0) {
                        $hidepid = substr($o, 8);
                        break;
                    }
                }
            }
        }
        if ($mode === 'v2' && $hidepid !== '' && (int) $hidepid > 0 && is_file('/usr/bin/dockerd-rootless-setuptool.sh')) {
            $log('[WARN] cgroup v2 with /proc hidepid>0 detected; rootless Docker may fail. Consider remounting /proc without hidepid or adjusting policy.');
        }
    } catch (\Throwable $e) {
        // Best-effort advisory only.
    }
}
