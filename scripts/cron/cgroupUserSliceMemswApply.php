#!/usr/bin/env php
<?php
/**
 * Make the kernel count swap against the user.slice memory bound (cgroup v1, ADR 0298).
 *
 * Copies systemd's MemoryMax for user.slice (ADR 0296) to memory.memsw.limit_in_bytes, writing
 * memory.limit_in_bytes too when systemd's own write was refused because the memsw limit was lower.
 * An absent memsw file (cgroup v2 host, kernel without the v1 memory controller) is not an error.
 * Runs at boot and every five minutes from root cron, and once after the slice step of update.php.
 * --dry-run prints the planned writes and writes nothing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

declare(strict_types=1);

require_once __DIR__.'/../lib/cgroup/userSliceMemsw.php';
require_once __DIR__.'/../lib/runtime/cli.php';

$DRY_RUN = in_array('--dry-run', $argv ?? [], true);
$cg = PMSS_USER_SLICE_MEMSW_CGROUP;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    exit(pmssCliReturnWithStderr("FATAL: must run as root (writes to /sys/fs/cgroup/memory/)\n", 2));
}
if (!is_file($cg.'/memory.memsw.limit_in_bytes')) {
    echo "INFO: $cg/memory.memsw.limit_in_bytes absent (cgroup v2 or no v1 memory controller); nothing to do\n";
    exit(0);
}

$target = pmssUserSliceMemswTarget(pmssReadSystemdProperties('user.slice', ['MemoryMax'])['MemoryMax']);
$memory = pmssUserSliceMemswLimitParse((string) @file_get_contents($cg.'/memory.limit_in_bytes'));
$memsw = pmssUserSliceMemswLimitParse((string) @file_get_contents($cg.'/memory.memsw.limit_in_bytes'));
if ($target === null || $memory <= 0 || $memsw <= 0) {
    exit(pmssCliReturnWithStderr("ERROR: unreadable user.slice MemoryMax or limits (target=".var_export($target, true)." memory=$memory memsw=$memsw)\n", 1));
}

$memoryStat = @file_get_contents($cg.'/memory.stat');
// An unavailable usage observation cannot authorize a reclaiming memsw write.
$plan = pmssUserSliceMemswPlan($target, $memory, $memsw,
    $memoryStat === false ? PHP_INT_MAX : pmssUserSliceMemswAnonPlusSwap($memoryStat));
if ($plan['note'] !== null) {
    echo date('Y-m-d H:i:s').' '.$plan['note']."\n";
}
$errors = 0;
foreach ($plan['writes'] as [$file, $value]) {
    $line = "user.slice $file: ".($file === 'memory.limit_in_bytes' ? $memory : $memsw)." -> $value";
    if ($DRY_RUN) {
        echo "[DRY-RUN] $line\n";
    } elseif (pmssUserSliceMemswWrite($cg.'/'.$file, $value)) {
        echo date('Y-m-d H:i:s')." $line\n";
    } else {
        $errors++;
        echo date('Y-m-d H:i:s')." FAILED $line\n";
    }
}
exit($errors > 0 ? 1 : 0);
