<?php
/** Ordered host and per-account diagnostic probe definitions. @license GPL-3.0-only */
declare(strict_types=1);

/**
 * Build the md write-path probe: per array stripe cache and inflight counters, plus each member's inflight.
 * Plain sysfs reads (the md show handlers take only spinlocks, so a stalled array cannot block them); an
 * absent attribute is null (raid0/1/10 have no stripe cache). One sample shows where writes queue; telling
 * a stall from a busy array needs two samples seconds apart, taken by the consumer. The walk root is
 * PMSS_AGENT_DIAGNOSTICS_SYSFS_BLOCK_PATH (default /sys/block), as the mdstat read has its own override.
 */
function pmssAgentDiagnosticsMdWritePathCommand(): string
{
    $probe = <<<'PHP'
$root = getenv("PMSS_AGENT_DIAGNOSTICS_SYSFS_BLOCK_PATH") ?: "/sys/block";
$count = static function ($path) {
    $raw = @file_get_contents($path);
    return is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : null;
};
$inflight = static function ($path) {
    $raw = @file_get_contents($path);
    return is_string($raw) && preg_match("/^\s*([0-9]+)\s+([0-9]+)\s*$/", $raw, $m) === 1
        ? ["read" => (int) $m[1], "write" => (int) $m[2]] : null;
};
$arrays = new stdClass();
foreach (glob($root."/md[0-9]*", GLOB_ONLYDIR) ?: [] as $md) {
    $members = new stdClass();
    foreach (glob($md."/slaves/*", GLOB_ONLYDIR) ?: [] as $member) {
        $members->{basename($member)} = $inflight($member."/inflight");
    }
    $arrays->{basename($md)} = ["stripe_cache_size" => $count($md."/md/stripe_cache_size"),
        "stripe_cache_active" => $count($md."/md/stripe_cache_active"),
        "inflight" => $inflight($md."/inflight"), "members" => $members];
}
echo json_encode($arrays), "\n";
PHP;
    return 'php -r '.escapeshellarg($probe);
}

/** Build the stable ordered section spec for the diagnostics payload. */
function pmssAgentDiagnosticsSectionSpecs(string $user = ''): array
{
    $sections = [
        'motd' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MOTD_PATH', 'path' => '/etc/motd', 'wrap' => 'raw'],
        'storage' => [
            'df' => ['type' => 'command', 'command' => 'df -h', 'format' => 'lines'],
            'df_inodes' => ['type' => 'command', 'command' => 'df -i', 'format' => 'lines'],
            'mdstat' => ['type' => 'file', 'env' => 'PMSS_AGENT_DIAGNOSTICS_MDSTAT_PATH', 'path' => '/proc/mdstat'],
            'md_write_path' => ['type' => 'command', 'command' => pmssAgentDiagnosticsMdWritePathCommand(), 'format' => 'json'],
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
        'io_sharing' => [
            // Static host probes only: no account input, names, PIDs or per-account output.
            'shadow_mode' => ['type' => 'command', 'command' => 'php -r \'require "/scripts/lib/cgroup/policy.php"; echo pmssCgroupPolicyLoad()["cgroup"]["dynamicIops"]["mode"] ?? "enforce";\' 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'local_policy_present' => ['type' => 'command', 'command' => 'if [ -f /etc/seedbox/config/cgroup.policy.local.php ]; then echo yes; else echo no; fi', 'format' => 'text', 'fallback' => 'unknown'],
            'shadow_log_age_seconds' => ['type' => 'command', 'command' => 'php -r \'$t=@filemtime("/var/log/pmss/dynamic-iops.jsonl"); if ($t !== false) echo max(0, time()-$t);\' 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'published_read_iops' => ['type' => 'command', 'command' => 'php -r \'$d=json_decode(@file_get_contents("/var/run/pmss/io-ceiling.json"),true); $v=$d["published"]["read_iops"] ?? null; if (is_numeric($v)) echo $v;\' 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'system_conf_bytes' => ['type' => 'command', 'command' => '(wc -c < /etc/systemd/system.conf) 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'default_io_accounting' => ['type' => 'command', 'command' => 'grep -hE "^[[:space:]]*DefaultIOAccounting=" /etc/systemd/system.conf /etc/systemd/system.conf.d/*.conf 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'slice_direct_tasks' => ['type' => 'command', 'command' => 'bash -o pipefail -c \'if [ -d /sys/fs/cgroup/blkio/user.slice ]; then n=$(find /sys/fs/cgroup/blkio/user.slice/user-*.slice -maxdepth 1 -name cgroup.procs -type f -exec cat {} + | wc -l) && echo "$n"; fi\' 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
            'account_tasks' => ['type' => 'command', 'command' => 'bash -o pipefail -c \'if [ -d /sys/fs/cgroup/blkio/user.slice ]; then n=$(find /sys/fs/cgroup/blkio/user.slice/user-*.slice -name cgroup.procs -type f -exec cat {} + | wc -l) && echo "$n"; fi\' 2>/dev/null', 'format' => 'text', 'fallback' => 'unknown'],
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
        // A one-shot relay sample: consumers derive CPU usage from successive tick counters.
        // Inline PHP keeps proc parsing and descriptor counting independent of the script-root guard.
        $relayProbe = <<<'PHP'
$uid = $argv[1] ?? "";
$hz = (int) ($argv[2] ?? 0);
$pageSize = (int) ($argv[3] ?? 0);
if (!ctype_digit($uid) || $hz <= 0 || $pageSize <= 0) exit(1);
$owned = static function ($path) use ($uid) {
    // Match the effective UID, as pgrep -u does; proc inode ownership can reflect dumpability.
    return preg_match("/^Uid:[ \t]+[0-9]+[ \t]+".$uid."[ \t]/m", (string) @file_get_contents($path."/status")) === 1;
};
$rows = [];
foreach (array_slice($argv, 4) as $pid) {
    if (!ctype_digit($pid)) continue;
    $path = "/proc/".$pid;
    if (!$owned($path)) continue;
    $stat = preg_split("/\s+/", trim((string) @file_get_contents($path."/stat")));
    $timestamp = microtime(true);
    if (count($stat) < 24 || $stat[1] !== "(slirp4netns)") continue;
    // Exact comm matching above makes positional stat parsing safe (no spaces in comm).
    foreach ([13, 14, 19, 21, 23] as $field) {
        if (!ctype_digit($stat[$field])) continue 2;
    }
    $fds = @scandir($path."/fd");
    $after = preg_split("/\s+/", trim((string) @file_get_contents($path."/stat")));
    // An exited or reused PID must not attribute another process's descriptors to this relay.
    if (count($after) < 24 || $after[1] !== "(slirp4netns)" || $after[21] !== $stat[21]
        || !$owned($path)) continue;
    $rows[] = ["pid" => (int) $pid, "threads" => (int) $stat[19],
        "rss_bytes" => (int) $stat[23] * $pageSize, "cpu_ticks" => (int) $stat[13] + (int) $stat[14],
        "clock_ticks_per_second" => $hz, "timestamp" => $timestamp, "start_ticks" => (int) $stat[21],
        "open_descriptors" => $fds === false ? null : count($fds) - 2];
}
echo json_encode($rows), "\n";
PHP;
        $sections['user_net_relay'] = ['type' => 'command', 'format' => 'json', 'command' =>
            'php -r '.escapeshellarg($relayProbe).' "$(id -u '.$userArg.' 2>/dev/null)" "$(getconf CLK_TCK)" "$(getconf PAGESIZE)" $(pgrep -u '.$userArg.' -x slirp4netns 2>/dev/null)'];
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
