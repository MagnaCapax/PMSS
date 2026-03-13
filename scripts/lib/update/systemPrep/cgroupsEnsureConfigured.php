<?php
/**
 * Cgroup configuration helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime/commands.php';
require_once __DIR__.'/cgroupModeDetect.php';

    /**
     * Guarantee that cgroup mounts and PID limits are configured sanely.
     */
    function pmssEnsureCgroupsConfigured(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
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

        // On v2, prefer TasksMax limits via slice; on v1, pids.max may be available.
        if ($mode === 'v1') {
            if (file_exists('/sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max')) {
                runStep('Raising PID limit for root user slice (v1)', "sh -c 'echo 100000 > /sys/fs/cgroup/pids/user.slice/user-0.slice/pids.max'");
            } else {
                $log('[SKIP] pids.max controller path missing (v1), relying on system defaults');
            }
        } else {
            $log('[SKIP] Using systemd TasksMax for PID limits (cgroup v2)');
        }

        // Advisory: hidepid=2 on /proc breaks rootless Docker under cgroup v2 on many hosts.
        // Emit a warning so operators can adjust policy if needed.
        try {
            $procMount = $hidepid = '';
            $mi = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
            if (is_array($mi)) {
                foreach ($mi as $l) {
                    // Identify the /proc mountpoint line
                    if (preg_match('#\s(/proc)\s#', $l)) { $procMount = $l; }
                }
            }
            if ($procMount !== '') {
                // Extract mount options field (4th field) and parse hidepid option
                $parts = explode(' ', $procMount);
                if (isset($parts[3])) {
                    foreach (explode(',', $parts[3]) as $o) {
                        if (strpos($o, 'hidepid=') === 0) { $hidepid = substr($o, 8); break; }
                    }
                }
            }
            if ($mode === 'v2' && $hidepid !== '' && (int)$hidepid > 0 && is_file('/usr/bin/dockerd-rootless-setuptool.sh')) {
                $log('[WARN] cgroup v2 with /proc hidepid>0 detected; rootless Docker may fail. Consider remounting /proc without hidepid or adjusting policy.');
            }
        } catch (\Throwable $e) {
            // Best-effort advisory only
        }
    }
