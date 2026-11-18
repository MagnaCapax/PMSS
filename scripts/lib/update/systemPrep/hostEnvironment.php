<?php
/**
 * Host resource and cgroup convergence helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__, 2).'/runtime.php';
pmssRequireRelativeFiles(dirname(__DIR__), ['fstab.php', 'managedPath.php']);

/**
 * Return total system memory in MiB (rounded).
 */
function pmssTotalMemMiB(): int
{
    return pmssEnvReadDigits('PMSS_TOTAL_MEM_MIB') ?? pmssProcMeminfoTotalMiBRead();
}

/** MiB the virtio balloon holds now (inflated minus deflated 4 KiB pages since boot); 0 without one. */
function pmssBalloonHeldMiB(string $path = '/proc/vmstat'): int
{
    if (($override = pmssEnvReadDigits('PMSS_BALLOON_HELD_MIB')) !== null) {
        return $override;
    }
    $count = ['balloon_inflate' => 0, 'balloon_deflate' => 0];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) === 2 && isset($count[$parts[0]]) && ctype_digit($parts[1])) {
            $count[$parts[0]] = (int) $parts[1];
        }
    }
    return intdiv(max(0, $count['balloon_inflate'] - $count['balloon_deflate']) * 4, 1024);
}

/** Live vm.min_free_kbytes in MiB; 1024 (the PMSS floor) when it cannot be read. */
function pmssMinFreeMiB(string $path = '/proc/sys/vm/min_free_kbytes'): int
{
    if (($override = pmssEnvReadDigits('PMSS_MIN_FREE_KBYTES')) !== null) {
        return intdiv($override, 1024);
    }
    $value = trim((string) (pmssReadRegularFileContents($path) ?? ''));
    return ctype_digit($value) && (int) $value > 0 ? intdiv((int) $value, 1024) : 1024;
}

/**
 * Count logical-CPU fields without matching 'processor' inside model names.
 */
function pmssCpuInfoThreadCount(string $cpuinfo): int
{
    return (int) preg_match_all('/^processor\s*:/m', $cpuinfo);
}

/**
 * Return total logical CPU threads.
 */
function pmssTotalCpuThreads(): int
{
    if (($override = pmssEnvReadDigits('PMSS_TOTAL_CPU_THREADS')) !== null) {
        return (int) $override;
    }

    $count = pmssCpuInfoThreadCount(pmssReadRegularFileContents('/proc/cpuinfo') ?? '');
    return $count > 0 ? $count : max(0, (int) trim((string) @shell_exec('nproc')));
}

/**
 * Ensure the cgroup v1 mount is present in fstab before attempting a live mount.
 */
function pmssCgroupV1FstabMountEnsure(string $fstabPath, callable $log): ?bool
{
    $lines = pmssFstabLinesRead($fstabPath, $log, 'cgroup v1 mount configuration');
    if ($lines === null) {
        return null;
    }

    if (pmssFstabMountEntryRead($lines, '/sys/fs/cgroup', 'cgroup') !== null) {
        $log('[SKIP] cgroup v1 mount already present in '.$fstabPath);
        return false;
    }

    $lines[] = "cgroup\t/sys/fs/cgroup\tcgroup\tdefaults\t0\t0";
    if (!pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log, true)) {
        $log('[WARN] Unable to append cgroup mount to '.$fstabPath);
        return null;
    }

    $log('Appended cgroup mount configuration to '.$fstabPath.' (v1)');
    return true;
}

/**
 * Guarantee that cgroup mounts and PID limits are configured sanely.
 */
function pmssEnsureCgroupsConfigured(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    $mode = pmssCgroupMode();
    if ($mode === 'v1') {
        if (pmssCgroupV1FstabMountEnsure('/etc/fstab', $log) === true) {
            runStep('Mounting /sys/fs/cgroup (v1)', 'mount /sys/fs/cgroup');
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
        foreach (pmssTextLines(pmssReadRegularFileContents('/proc/self/mountinfo') ?? '') as $l) {
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
            $log('[INFO] cgroup v2 with /proc hidepid>0 detected. The per-user systemd manager cannot start here (systemd issue 12955); PMSS provisions rootless Docker manager-independently (su + XDG_RUNTIME_DIR) and runs it via nohup dockerd-rootless.sh, so this is expected and non-fatal. Do NOT remount /proc without hidepid: that removes cross-tenant process isolation (a privacy invariant). See ADR-0027.');
        }
    } catch (\Throwable $e) {
        // Best-effort advisory only.
    }
}
