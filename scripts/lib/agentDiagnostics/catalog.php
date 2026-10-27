<?php
/** Ordered host and per-account diagnostic probe definitions. @license GPL-3.0-only */
declare(strict_types=1);

/** Build the stable ordered section spec for the diagnostics payload. */
function pmssAgentDiagnosticsSectionSpecs(string $user = ''): array
{
    $sections = [
        'motd' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH', 'path' => '/etc/motd', 'wrap' => 'raw'],
        'storage' => [
            'df' => ['type' => 'command', 'command' => 'df -h', 'format' => 'lines'],
            'df_inodes' => ['type' => 'command', 'command' => 'df -i', 'format' => 'lines'],
            'mdstat' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH', 'path' => '/proc/mdstat'],
            'fstab' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_FSTAB_PATH', 'path' => '/etc/fstab'],
        ],
        'services' => [
            'nginx' => ['type' => 'command', 'command' => 'systemctl is-active nginx 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'proftpd' => ['type' => 'command', 'command' => 'systemctl is-active proftpd 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'cron' => ['type' => 'command', 'command' => 'systemctl is-active cron 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'ssh' => ['type' => 'command', 'command' => 'systemctl is-active ssh 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            // pgrep -cx misses PMSS rtorrent (its comm is "rtorrent main", not exact "rtorrent"), returning 0
            // on healthy hosts. Count via ps comm-name prefix instead (verified reliable 2026-07-11 + 2026-08-13).
            'rtorrent_count' => ['type' => 'command', 'command' => 'ps -eo comm= 2>/dev/null | grep -c ^rtorrent', 'format' => 'int'],
            'lighttpd_count' => ['type' => 'command', 'command' => 'pgrep -cx lighttpd 2>/dev/null', 'format' => 'int'],
        ],
        'system' => [
            // Host-level saturation signals. These are plain command reads (NOT the php-script path), so they
            // survive even when the util sub-scripts fail the script-root guard — the single most important
            // signal (is the host saturated?) must never depend on systemTest.php succeeding.
            'loadavg' => ['type' => 'command', 'command' => 'cat /proc/loadavg 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'psi_io' => ['type' => 'command', 'command' => 'cat /proc/pressure/io 2>/dev/null', 'format' => 'lines'],
            'psi_cpu' => ['type' => 'command', 'command' => 'cat /proc/pressure/cpu 2>/dev/null', 'format' => 'lines'],
            'stats_log_tail' => ['type' => 'command', 'command' => 'tail -6 /var/log/pmss/system-stats.log 2>/dev/null', 'format' => 'lines'],
        ],
        'cgroup' => [
            // Read-only cgroup-hierarchy + per-user-isolation posture probe (cgroup-v2 viability canary, GH #648).
            'mode' => ['type' => 'command', 'command' => 'stat -fc %T /sys/fs/cgroup 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'hierarchy_cmdline' => ['type' => 'command', 'command' => "grep -o 'systemd.unified_cgroup_hierarchy=[01]' /proc/cmdline 2>/dev/null", 'format' => 'text', 'fallback' => 'unset'],
            'proc_hidepid' => ['type' => 'command', 'command' => 'grep -w hidepid /proc/mounts 2>/dev/null', 'format' => 'lines'],
            // Functional hidepid=2 tenant-isolation probe (not just the mount option): as a sample user,
            // confirm /proc PID visibility is restricted to own procs and root PID 1 is hidden. Output
            // "root_pids=N sample_user=U user_pids=M pid1=hidden|readable"; ENFORCED when M<<N AND pid1=hidden.
            // pid1=readable (or M~=N) means hidepid is off/bypassed (the gid=proc hole class) — a privacy regression.
            'hidepid_functional' => [
                'type' => 'command',
                'command' => 'r=$(ls -d /proc/[0-9]* 2>/dev/null | wc -l); u=$(php /scripts/listUsers.php 2>/dev/null | head -1); if [ -n "$u" ]; then up=$(su -s /bin/bash "$u" -c "ls -d /proc/[0-9]* 2>/dev/null | wc -l"); p1=$(su -s /bin/bash "$u" -c "cat /proc/1/comm >/dev/null 2>&1 && echo readable || echo hidden"); echo "root_pids=$r sample_user=$u user_pids=$up pid1=$p1"; else echo no-users; fi',
                'format' => 'text',
                'fallback' => 'unknown',
            ],
            'user_managers_active' => ['type' => 'command', 'command' => "systemctl list-units 'user@*.service' --state=active --no-legend --no-pager 2>/dev/null | wc -l", 'format' => 'int'],
            'user_managers_failed' => ['type' => 'command', 'command' => "systemctl list-units 'user@*.service' --state=failed --no-legend --no-pager 2>/dev/null | wc -l", 'format' => 'int'],
            'user_io_stat_sample' => ['type' => 'command', 'command' => 'cat /sys/fs/cgroup/user.slice/user-*.slice/io.stat 2>/dev/null | head -2', 'format' => 'lines'],
            'user_cpu_pressure_sample' => ['type' => 'command', 'command' => 'cat /sys/fs/cgroup/user.slice/user-*.slice/cpu.pressure 2>/dev/null | head -2', 'format' => 'lines'],
        ],
        'system_test' => ['type' => 'php', 'path' => 'util/systemTest.php', 'args' => ['--json'], 'format' => 'json'],
        'users' => [
            'list' => ['type' => 'php', 'path' => 'listUsers.php', 'format' => 'lines'],
            'consistency' => ['type' => 'php', 'path' => 'util/checkUsers.php', 'args' => ['--json'], 'format' => 'json'],
        ],
        'resources' => ['type' => 'php', 'path' => 'util/userResourcesList.php', 'args' => ['--full', '--json'], 'format' => 'json'],
        'traffic' => ['type' => 'php', 'path' => 'showTraffic.php', 'args' => ['--json'], 'format' => 'json'],
    ];
    if ($user !== '') {
        $userArg = escapeshellarg($user);
        $sections['user_settings'] = ['type' => 'php', 'path' => 'userSetting.php', 'args' => ['view', $user], 'format' => 'json'];
        $sections['user_processes'] = ['type' => 'command', 'command' => 'pgrep -u '.$userArg.' -a 2>/dev/null', 'format' => 'lines'];
        // Latest per-user resource-metrics snapshot (cpu-throttle / blkio / pids / memory-pressure).
        // metricsLog.php (root.cron, */5) persists pmssUserMetricsCollect() output as JSONL here; the
        // last line surfaces this account's resource envelope — including memory.failcnt, max_usage vs
        // limit, and oom_kill — which is the per-account memory-exhaustion signal the host-level probes
        // above miss (GH #114824 misdiagnosis). A plain `tail` (not a util sub-script) so it survives the
        // production script-root guard failure (GH #722) that breaks the php-type sections. Absent file
        // (new user / no cycle yet) => tail rc!=0 => a benign error stub, not a crash.
        $sections['user_metrics_latest'] = ['type' => 'command', 'command' => 'tail -1 '.escapeshellarg('/var/log/pmss/metrics/'.$user).' 2>/dev/null', 'format' => 'json'];
        // One sample about 24 hours back: 288 five-minute intervals plus the current line.
        // The newest rotation is archive/<user>.1 and remains plain text via delaycompress.
        // With shorter history, tail/head return the oldest available line; its ts gives the real window.
        $sections['user_metrics_24h_ago'] = ['type' => 'command', 'command' => 'cat '.escapeshellarg('/var/log/pmss/metrics/archive/'.$user.'.1').' '.escapeshellarg('/var/log/pmss/metrics/'.$user).' 2>/dev/null | tail -n 289 | head -1', 'format' => 'json'];
        // End-to-end per-user responsiveness: a timed local HTTP GET to the user's panel path
        // (nginx -> per-user lighttpd). A healthy account answers fast with 401/200; under I/O
        // saturation the listener still reads `systemctl is-active` while the actual request hangs
        // — this probe captures that (listener-liveness != responds-under-load), the signal the
        // host-level service/process/PSI probes above cannot show. Bounded -m 10, read-only GET,
        // fail-benign (timeout => "timeout-or-unreachable", never a crash).
        foreach ([
            'user_identity' => 'id '.$userArg,
            'user_quota' => 'quota -u '.$userArg.' 2>/dev/null',
            'user_disk' => 'du -sBG '.escapeshellarg('/home/'.$user).' 2>/dev/null',
            'user_http_responsiveness' => 'curl -sS -m 10 -o /dev/null -w "%{http_code} %{time_total}s" '.escapeshellarg('http://localhost/user-'.$user.'/').' 2>/dev/null || echo timeout-or-unreachable',
        ] as $name => $command) {
            $sections[$name] = ['type' => 'command', 'command' => $command, 'wrap' => 'raw'];
        }
    }

    return $sections;
}
