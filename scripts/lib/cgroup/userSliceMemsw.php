<?php
/**
 * Keep the memory+swap limit of user.slice equal to its memory limit on cgroup v1 (ADR 0298).
 *
 * ADR 0296 bounds the tenants' memory with one MemoryMax on user.slice. On v1 systemd writes only
 * memory.limit_in_bytes, so the guest's own swap-out still lets a runaway tenant leave the slice's
 * RAM figure at the bound while its swapped part grows; the kernel then reclaims for minutes before
 * it kills. With memory.memsw.limit_in_bytes equal to the memory limit the kernel counts RAM plus
 * swap against the bound and kills at once (mm/memcontrol.c try_charge_memcg: a memsw overflow
 * reclaims without swap). systemd cannot express this on v1 (MemorySwapMax is v2 only).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/systemdSliceProperties.php';

const PMSS_USER_SLICE_MEMSW_CGROUP = '/sys/fs/cgroup/memory/user.slice';
/** The kernel reports "no limit" as a huge page-aligned value; anything from here up is no limit. */
const PMSS_USER_SLICE_MEMSW_UNLIMITED = 4611686018427387904;

/** Systemd's MemoryMax text as a byte limit: -1 for infinity, null when unreadable or zero. */
function pmssUserSliceMemswTarget(string $systemdValue): ?int
{
    $value = trim($systemdValue);
    if ($value === 'infinity') {
        return -1;
    }
    return preg_match('/^[0-9]{1,18}$/D', $value) === 1 && (int) $value > 0 ? (int) $value : null;
}

/**
 * The ordered writes that bring the memory and memsw limits of user.slice to $target (bytes or -1), as
 * ['writes' => [[file, value], ...], 'note' => string|null]. The kernel refuses a memory limit above the
 * memsw limit, so a raise writes memsw first and a lowering writes memory first. A memsw limit below what
 * the tenants hold as anonymous memory plus swap would make the kernel purge all page cache for nothing
 * (it fails after that), so it is not written; the next run retries.
 */
function pmssUserSliceMemswPlan(int $target, int $memory, int $memsw, int $anonPlusSwap): array
{
    $unlimited = static function (int $v): int { return $v >= PMSS_USER_SLICE_MEMSW_UNLIMITED ? PHP_INT_MAX : $v; };
    $want = $target < 0 ? PHP_INT_MAX : $target;
    $memory = $unlimited($memory);
    $memsw = $unlimited($memsw);
    $text = static function (int $v): string { return $v === PHP_INT_MAX ? '-1' : (string) $v; };
    $setMemory = ['memory.limit_in_bytes', $text($want)];
    $setMemsw = ['memory.memsw.limit_in_bytes', $text($want)];

    if ($want < $memsw && $anonPlusSwap > $want) {
        return ['writes' => [], 'note' => "tenants hold $anonPlusSwap bytes of anonymous memory plus swap, above the bound $want: memsw limit not written"];
    }
    $writes = $want >= $memory ? [$setMemsw, $setMemory] : [$setMemory, $setMemsw];
    $writes = array_values(array_filter($writes, static function (array $w) use ($memory, $memsw, $want): bool {
        return ($w[0] === 'memory.limit_in_bytes' ? $memory : $memsw) !== $want;
    }));
    return ['writes' => $writes, 'note' => null];
}

/** Anonymous memory, shared memory and swap held under the slice, from its memory.stat text. */
function pmssUserSliceMemswAnonPlusSwap(string $memoryStat): int
{
    $sum = 0;
    foreach (['total_rss', 'total_shmem', 'total_swap'] as $key) {
        $value = preg_match('/^'.$key.' ([0-9]+)$/m', $memoryStat, $m) === 1 ? (int) $m[1] : 0;
        // Saturate before addition so an oversized observation still blocks unsafe lowering.
        if ($value > PHP_INT_MAX - $sum) return PHP_INT_MAX;
        $sum += $value;
    }
    return $sum;
}

/** One sysfs write through timeout(1): lowering a limit below usage reclaims inside the write. */
function pmssUserSliceMemswWrite(string $file, string $value): bool
{
    $p = proc_open('timeout --kill-after=5s 30s tee '.escapeshellarg($file), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) {
        return false;
    }
    $input = $value."\n";
    try {
        $written = fwrite($pipes[0], $input) === strlen($input);
    } finally {
        // Attempt both cleanup steps even when writing or closing stdin throws.
        try {
            $closed = fclose($pipes[0]);
        } finally {
            $rc = proc_close($p);
        }
    }
    return $written && $closed && $rc === 0;
}
