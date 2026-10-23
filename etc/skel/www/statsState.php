<?php
/** Customer Info statistics: statsState. Loaded by statsHelpers.php. */

/**
 * Run a fail-soft shell command for the stats page.
 *
 * @return array{output:?string,error:?string}
 */
function pmssInfoShellExec($command, $label): array
{
    if (!pmssFrontendShellExecAvailable()) {
        return array('output' => null, 'error' => $label.' unavailable: shell_exec disabled');
    }

    return array('output' => pmssFrontendShellExec($command) ?? '', 'error' => null);
}

/**
 * Read one customer-visible serialized state file.
 *
 * @return array{data:?array,time:int|false|null,error:?string}
 */
function pmssStatsSerializedStateRead(string $path, string $invalidMessage): array
{
    if (!is_file($path) || is_link($path)) {
        return array('data' => null, 'time' => null, 'error' => null);
    }

    $time = @filemtime($path);
    $data = function_exists('pmssCustomerSerializedArrayFileRead')
        ? pmssCustomerSerializedArrayFileRead($path, 1048576) : null;
    return array('data' => $data, 'time' => $time, 'error' => $data === null ? $invalidMessage : null);
}

/**
 * Resolve the current account UID without depending on systemd status output.
 *
 * @return array{uid:?string,error:?string}
 */
function pmssStatsCurrentUidResolve(?callable $runner = null): array
{
    if ($runner === null && function_exists('posix_getuid')) {
        return array('uid' => (string) posix_getuid(), 'error' => null);
    }

    $uidResult = ($runner ?? 'pmssInfoShellExec')('/usr/bin/id -u', 'User ID');
    if ($uidResult['error'] !== null) {
        return array('uid' => null, 'error' => $uidResult['error']);
    }

    $uid = trim((string) $uidResult['output']);
    if ($uid === '' || !ctype_digit($uid)) {
        return array('uid' => null, 'error' => 'Error: Could not determine user ID.');
    }

    return array('uid' => $uid, 'error' => null);
}

/** Detect a readable cgroup directory for display purposes. */
function pmssStatsCgroupDirDetect(string $uid, array $overrides = []): string
{
    if (is_string($overrides['cgroup_dir'] ?? null) && $overrides['cgroup_dir'] !== '') {
        return rtrim($overrides['cgroup_dir'], '/');
    }

    $candidates = is_array($overrides['cgroup_dir_candidates'] ?? null)
        ? $overrides['cgroup_dir_candidates']
        : array('/sys/fs/cgroup/user.slice/user-'.$uid.'.slice', '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice', '/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice');
    foreach ($candidates as $candidate) {
        if (function_exists('pmssCustomerCgroupDirOwnsMemoryController')
            && pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
            return $candidate;
        }
    }

    $cgroupFile = (string) ($overrides['self_cgroup_file'] ?? '/proc/self/cgroup');
    $entries = function_exists('pmssCustomerCgroupSelfEntries') ? pmssCustomerCgroupSelfEntries($cgroupFile) : array();
    foreach ($entries as $entry) {
        $controllers = is_array($entry['controllers'] ?? null) ? $entry['controllers'] : array();
        $roots = in_array('memory', $controllers, true)
            ? array('/sys/fs/cgroup/memory', '/sys/fs/cgroup', '/sys/fs/cgroup/unified')
            : array('/sys/fs/cgroup', '/sys/fs/cgroup/unified');
        foreach ($roots as $root) {
            $candidate = $root.(string) ($entry['path'] ?? '');
            if (function_exists('pmssCustomerCgroupDirOwnsMemoryController')
                && pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
                return $candidate;
            }
        }
    }

    return '';
}

/**
 * Read user-slice counters directly from cgroupfs.
 *
 * @return array<string,int|null>
 */
function pmssStatsCgroupCountersRead(string $uid, string $cgroupDir): array
{
    if (!function_exists('pmssCustomerCgroupMemoryRead')) {
        return array_fill_keys(array('memory_current', 'memory_high', 'memory_max', 'tasks_current'), null);
    }
    return pmssCustomerCgroupMemoryRead($uid, $cgroupDir) + array(
        'tasks_current' => pmssCustomerCgroupCounterRead(pmssCustomerCgroupCounterPaths($uid, $cgroupDir, 'pids.current', 'pids', 'pids.current')),
    );
}

/** Format a cgroup counter for the base-resource text block. */
function pmssStatsCgroupCounterText($value, bool $bytes = false): string
{
    if ($value === null) {
        return 'n/a';
    }

    return $bytes && function_exists('pmssFormatBytes') ? pmssFormatBytes((float) $value) : (string) $value;
}

/**
 * Build the base-resource text from cgroupfs plus a bounded user process list.
 *
 * @return array{uid:?string,text:string}
 */
function pmssStatsBaseResourcesBuild(?callable $runner = null, array $overrides = []): array
{
    $commandRunner = $runner ?? 'pmssInfoShellExec';
    $uidState = pmssStatsCurrentUidResolve($runner);
    if ($uidState['error'] !== null) {
        return array('uid' => null, 'text' => $uidState['error']."\n");
    }

    $uid = (string) $uidState['uid'];
    $cgroupDir = pmssStatsCgroupDirDetect($uid, $overrides);
    $counters = pmssStatsCgroupCountersRead($uid, $cgroupDir);
    $processResult = $commandRunner('ps -u '.escapeshellarg($uid).' --forest -o pid=,ppid=,stat=,comm=,args= 2>&1', 'User process list');
    $processText = $processResult['error'] !== null
        ? $processResult['error']
        : trim((string) $processResult['output']);
    if ($processText === '') {
        $processText = 'No user processes found.';
    }

    $lines = array(
        'User slice: user-'.$uid.'.slice',
        'Cgroup path: '.($cgroupDir !== '' ? $cgroupDir : 'unavailable'),
        'Memory current: '.pmssStatsCgroupCounterText($counters['memory_current'], true),
        'Memory high: '.pmssStatsCgroupCounterText($counters['memory_high'], true),
        'Memory max: '.pmssStatsCgroupCounterText($counters['memory_max'], true),
        'Tasks current: '.pmssStatsCgroupCounterText($counters['tasks_current']),
        '',
        'Processes:',
        $processText,
    );

    return array('uid' => $uid, 'text' => implode("\n", $lines));
}

/** Build the server resource text block from bounded local reads. */
function pmssStatsServerResourceTextBuild(): string
{
    $uptimeResult = pmssInfoShellExec('uptime', 'Uptime');
    $text = $uptimeResult['error'] !== null
        ? $uptimeResult['error']."\n\n"
        : trim((string) $uptimeResult['output'])."\n\n";
    $text .= "Memory usage:\n";

    $meminfo = pmssCustomerFileRead('/proc/meminfo', true);
    if (!$meminfo || preg_match_all('/(\w+):\s+(\d+)/', $meminfo, $m) < 1) {
        return $text."Failed to read /proc/meminfo\n";
    }

    $info = array_combine($m[1], $m[2]);
    $fmt = static function (string $key) use ($info) { return $info[$key] ?? 0; };
    $text .= sprintf("Memory total:     %6s MiB\n", round($fmt('MemTotal') / 1024, 0));
    $text .= sprintf("Memory available: %6s MiB\n", round($fmt('MemAvailable') / 1024, 0));
    $text .= sprintf("Swap total:       %6s MiB\n", round($fmt('SwapTotal') / 1024, 0));
    $text .= sprintf("Swap free:        %6s MiB\n", round($fmt('SwapFree') / 1024, 0));

    $psi = pmssCustomerFileRead('/proc/pressure/memory', true);
    if ($psi && preg_match('/some avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $m) === 1) {
        $text .= sprintf("Memory pressure (some):  %s / %s / %s\n", $m[1], $m[2], $m[3]);
        if (preg_match('/full avg10=([0-9.]+) avg60=([0-9.]+) avg300=([0-9.]+)/', $psi, $f) === 1) {
            $text .= sprintf("Memory pressure (full):  %s / %s / %s\n", $f[1], $f[2], $f[3]);
        }
    } else {
        $text .= "Memory pressure: unavailable\n";
    }

    return $text;
}
