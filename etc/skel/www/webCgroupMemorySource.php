<?php
/** Customer-readable cgroup discovery, memory-stat parsing and v1 OOM acquisition.
 * @license GPL-3.0-only
 */

/** Return whether a v2 cgroup exposes the memory controller. */
function pmssWebCgroupMemoryStatusV2MemoryControllerAvailable($cgroupDir)
{
    if (!is_string($cgroupDir) || $cgroupDir === '') {
        return false;
    }

    $controllersPath = rtrim($cgroupDir, '/').'/cgroup.controllers';
    return is_file($controllersPath)
        && pmssCustomerCgroupDirOwnsMemoryController($cgroupDir);
}

/** Detect the readable user.slice directory for the current account. */
function pmssWebCgroupMemoryStatusDetectDir(array $overrides = [])
{
    if (is_string($overrides['cgroup_dir'] ?? null) && $overrides['cgroup_dir'] !== '') {
        return $overrides['cgroup_dir'];
    }

    $uid = $overrides['uid'] ?? (function_exists('posix_getuid') ? posix_getuid() : null);
    if (is_int($uid) && $uid >= 0) {
        $candidates = is_array($overrides['cgroup_dir_candidates'] ?? null)
            ? $overrides['cgroup_dir_candidates']
            : [
                '/sys/fs/cgroup/user.slice/user-'.$uid.'.slice',
                '/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice',
                '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice',
            ];
        foreach ($candidates as $candidate) {
            if (pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
                return $candidate;
            }
        }
    }

    $cgroupFile = (string) ($overrides['self_cgroup_file'] ?? '/proc/self/cgroup');
    foreach (pmssCustomerCgroupSelfEntries($cgroupFile) as $entry) {
        foreach (['/sys/fs/cgroup', '/sys/fs/cgroup/memory', '/sys/fs/cgroup/unified'] as $root) {
            $candidate = $root.$entry['path'];
            if (pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
                return $candidate;
            }
        }
    }

    return '';
}

/** Parse cgroup v1/v2 memory.stat anon+file counters into byte values. */
function pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw)
{
    $breakdown = [];
    if (!is_string($raw) || trim($raw) === '') {
        return $breakdown;
    }

    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        // Digit-only input can still overflow; keep it out of pressure arithmetic.
        if (count($parts = preg_split('/\s+/', trim($line), 2)) !== 2
            || !ctype_digit($parts[1]) || !is_finite((float) $parts[1])) {
            continue;
        }
        $field = ['anon' => 'anon', 'total_rss' => 'anon', 'file' => 'file', 'total_cache' => 'file'][$parts[0]] ?? null;
        if ($field !== null) $breakdown[$field] = (float) $parts[1];
    }

    return $breakdown;
}

/** Read candidates in order until all requested fields exist; retain the last parsed fallback. */
function pmssWebCgroupMemoryStatusMemoryStatBreakdownRead(array $paths, array $required)
{
    $breakdown = [];
    $required = array_fill_keys($required, true);
    foreach ($paths as $path) {
        $raw = pmssCustomerFileRead($path, true);
        if (!is_string($raw) || trim($raw) === '') continue;
        $breakdown = pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw);
        if (count(array_intersect_key($breakdown, $required)) === count($required)) break;
    }
    return $breakdown;
}

/**
 * cgroup-v1 fallback: read the real OOM-kill count from memory.oom_control on the account's
 * slice and every descendant, including session/run scopes. V1 counters are local to each
 * memcg, so sum them without double-counting. Read directly from the customer tree without
 * /scripts traversal (ADR-0016/0017); disappearing scopes are skipped without warnings.
 * memory.events (the v2 oom_kill source used by the status reader) does not exist on the
 * v1-pinned fleet; a REAL OOM kill is the ONLY sound per-account memory-pressure signal there
 * (failcnt false-HIGHs on page-cache reclaim and is deliberately NOT read). Returns 0 on v2.
 */
function pmssWebCgroupMemoryStatusV1OomKillRead($cgroupDir, $uid)
{
    if (!is_string($cgroupDir) || $cgroupDir === '') {
        return 0;
    }
    $directories = [rtrim($cgroupDir, '/')];
    $total = 0;
    while ($directories !== []) {
        $directory = array_pop($directories);
        if (is_link($directory) || !is_dir($directory)) continue;
        $path = $directory.'/memory.oom_control';
        $value = is_link($path) ? null
            : pmssCustomerUnsignedIntegerValue((pmssCustomerKeyValueFileRead($path))['oom_kill'] ?? null);
        // Preserve integer output even if an aggregate exceeds the representable range.
        if ($value !== null) $total += min($value, PHP_INT_MAX - $total);
        if (is_int($uid) && $uid >= 0) {
            foreach (@scandir($directory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_dir($directory.'/'.$entry)) {
                    $directories[] = $directory.'/'.$entry;
                }
            }
        }
    }
    return $total;
}
