<?php
/** Non-shell diagnostics for command launch and fork failures. */

/**
 * Emit a non-shell diagnostics snapshot for fork/proc exhaustion scenarios.
 */
function pmssDumpForkDiagnostics(string $context, ?callable $logger = null): void
{
    $now = microtime(true);
    $lastAt = $GLOBALS['PMSS_LAST_FORK_DIAG_AT'] ?? 0.0;
    $lastCtx = $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT'] ?? '';
    if ($context === $lastCtx && ($now - (float) $lastAt) < 1.0) {
        return;
    }
    $GLOBALS['PMSS_LAST_FORK_DIAG_AT'] = $now;
    $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT'] = $context;

    $log = $logger ?? 'logMessage';
    $prefix = '[FORK] diag: ';
    $pid = function_exists('getmypid') ? getmypid() : null;
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $uid = function_exists('posix_getuid') ? posix_getuid() : null;
    $line = $prefix.'context='.trim($context);
    if ($pid !== null) {
        $line .= ' pid='.$pid;
    }
    if ($euid !== null || $uid !== null) {
        $line .= sprintf(' uid=%s euid=%s', $uid !== null ? (string) $uid : 'n/a', $euid !== null ? (string) $euid : 'n/a');
    }
    $log($line);

    $readTrim = static function (string $path, int $maxBytes = 4096): ?string {
        if (!is_readable($path)) {
            return null;
        }
        $data = @file_get_contents($path, false, null, 0, $maxBytes);
        if ($data === false) {
            return null;
        }
        $data = trim((string) $data);
        return $data !== '' ? $data : null;
    };

    $procCount = null;
    $dir = @opendir('/proc');
    if ($dir !== false) {
        $count = 0;
        while (false !== ($entry = readdir($dir))) {
            if ($entry !== '.' && $entry !== '..' && ctype_digit($entry)) {
                $count++;
            }
        }
        closedir($dir);
        $procCount = $count;
    }
    $log($prefix.sprintf(
        'kernel procs=%s pid_max=%s threads_max=%s loadavg=%s',
        $procCount !== null ? (string) $procCount : 'n/a',
        $readTrim('/proc/sys/kernel/pid_max') ?? 'n/a',
        $readTrim('/proc/sys/kernel/threads-max') ?? 'n/a',
        $readTrim('/proc/loadavg') ?? 'n/a'
    ));

    $limitsRaw = $readTrim('/proc/self/limits', 16384);
    if ($limitsRaw !== null) {
        $limits = ['Max processes' => null, 'Max open files' => null];
        foreach (preg_split('/\r?\n/', $limitsRaw) ?: [] as $limitLine) {
            foreach ($limits as $label => $value) {
                if ($value === null && strpos($limitLine, $label) === 0) {
                    $limits[$label] = preg_replace('/\s+/', ' ', trim($limitLine));
                }
            }
        }
        $limits = array_filter($limits, static function ($value): bool { return $value !== null; });
        if ($limits !== []) $log($prefix.'rlimits | '.implode(' | ', $limits));
    }

    $meminfoRaw = $readTrim('/proc/meminfo', 16384);
    if ($meminfoRaw !== null) {
        // Read and render one field catalog in the established diagnostic order.
        $wanted = ['MemAvailable' => 'avail', 'MemTotal' => 'total', 'SwapFree' => 'swap_free',
            'SwapTotal' => 'swap_total', 'Committed_AS' => 'committed', 'CommitLimit' => 'commit_limit'];
        $vals = [];
        foreach ($wanted as $key => $label) {
            if (preg_match('/^'.$key.':\s+([0-9]+)\s+kB$/m', $meminfoRaw, $match)) {
                $vals[] = $label.'='.sprintf('%0.1fMiB', (int) $match[1] / 1024.0);
            }
        }
        if ($vals !== []) $log($prefix.'mem '.implode(' ', $vals));
    }

    $cgPath = pmssCgroupSelfPath();
    if ($cgPath === '') {
        return;
    }

    // Visit each ancestor once, including the root, without staging a second path list.
    for ($i = 0, $path = $cgPath; $i < 10 && $path !== null;
        $i++, $path = $path === '/' ? null : (dirname($path) === '.' ? '/' : dirname($path))) {
        $pickedDir = null;
        foreach (['/sys/fs/cgroup'.$path, '/sys/fs/cgroup/pids'.$path, '/sys/fs/cgroup/unified'.$path] as $dirPath) {
            if (is_dir($dirPath) && is_readable($dirPath.'/cgroup.procs')) {
                $pickedDir = $dirPath;
                break;
            }
        }
        if ($pickedDir === null) {
            continue;
        }

        $groups = [];
        foreach (['pids', 'memory'] as $group) {
            foreach (['max', 'current', 'events'] as $field) $groups[$group][$field] = $readTrim($pickedDir.'/'.$group.'.'.$field);
        }
        if ($groups['pids']['max'] === null && $groups['pids']['current'] === null
            && $groups['memory']['max'] === null && $groups['memory']['current'] === null) {
            continue;
        }

        $procsInCgroup = null;
        $procsRaw = $readTrim($pickedDir.'/cgroup.procs', 262144);
        if ($procsRaw !== null) {
            $trimmed = trim($procsRaw);
            $procsInCgroup = $trimmed === '' ? 0 : (substr_count($trimmed, "\n") + 1);
        }
        $fmtBytes = static function (?string $val): string {
            if ($val === null || $val === 'max' || !ctype_digit($val)) {
                return $val ?? 'n/a';
            }
            return sprintf('%0.1fMiB', ((float) $val) / 1048576.0);
        };

        $line = $prefix.'cgroup path='.$path.' dir='.$pickedDir.($procsInCgroup !== null ? ' procs='.$procsInCgroup : '');
        foreach (['pids' => ['pids', ['max']], 'memory' => ['mem', ['oom', 'oom_kill']]] as $group => [$label, $events]) {
            $values = $groups[$group];
            if ($values['current'] !== null || $values['max'] !== null) {
                $line .= sprintf(' %s=%s/%s', $label, $group === 'memory' ? $fmtBytes($values['current']) : ($values['current'] ?? 'n/a'), $group === 'memory' ? $fmtBytes($values['max']) : ($values['max'] ?? 'n/a'));
            }
            foreach ($events as $event) {
                if ($values['events'] !== null && preg_match('/^'.$event.'\s+([0-9]+)$/m', $values['events'], $m)) $line .= ' '.$label.'.events.'.$event.'='.$m[1];
            }
        }
        $log($line);
    }
}
