<?php
/** Guarded root cleanup execution; candidates are rechecked before removal.
 * @license GPL-3.0-only
 */

/** Run canonical commands first, then guarded file and scratch removals in discovery order. */
function pmssSafeRootCleanupApply(array $candidates, int $journalMb): array
{
    $freed = [];
    // Both canonical commands measure actual reclaimed bytes, independently of estimates.
    foreach ([
        'apt_cache' => ['/var/cache/apt/archives', 'apt-get clean 2>/dev/null'],
        'journald' => ['/var/log/journal', 'journalctl --vacuum-size=' . escapeshellarg($journalMb . 'M') . ' 2>/dev/null'],
    ] as $name => [$path, $command]) {
        $before = pmssSafeRootCleanupPathBytes($path);
        @shell_exec($command);
        $freed[$name] = max(0, $before - pmssSafeRootCleanupPathBytes($path));
    }
    // Rotated and atop logs share the per-file realpath/whitelist gate and unlink accounting.
    foreach (['rotated_logs', 'atop_logs'] as $name) {
        $freed[$name] = 0;
        foreach ($candidates[$name] as $path => $size) {
            if (pmssSafeRootCleanupIsDeletableFile($path) && @unlink($path)) {
                $freed[$name] += (int) $size;
            }
        }
    }
    // Scratch retains its separate prefix predicate and post-command existence check.
    $freed['distupgrade_tmp'] = 0;
    foreach ($candidates['distupgrade_tmp'] as $path => $size) {
        if (pmssSafeRootCleanupIsDistUpgradeTmp($path)) {
            @shell_exec('rm -rf ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!file_exists($path)) {
                $freed['distupgrade_tmp'] += (int) $size;
            }
        }
    }
    return $freed;
}
