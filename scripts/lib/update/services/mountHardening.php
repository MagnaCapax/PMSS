<?php
/**
 * Optional mount hardening helpers.
 */
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../../runtime.php';
if (!function_exists('pmssConfigureTempMountNoexec')) {
    /** Ensure /tmp and /dev/shm are mounted with noexec/nosuid/nodev when enabled. */
    function pmssConfigureTempMountNoexec(?callable $logger = null, ?string $fstabPath = null, ?string $mountsPath = null): void
    {
        $log = pmssSelectLogger($logger);
        // Opt-in only: default off to preserve legacy workloads that execute from /tmp.
        $flag = getenv('PMSS_HARDEN_TMP_NOEXEC');
        if ($flag === false) {
            $log('[SKIP] /tmp and /dev/shm noexec hardening disabled (PMSS_HARDEN_TMP_NOEXEC not set)');
            return;
        }
        $normalized = strtolower(trim($flag));
        if (in_array($normalized, ['', '0', 'false', 'no'], true)) {
            $log('[SKIP] /tmp and /dev/shm noexec hardening disabled via PMSS_HARDEN_TMP_NOEXEC');
            return;
        }
        // Resolve paths up front so tests can inject temp files.
        $fstabPath = $fstabPath ?? '/etc/fstab';
        $mountsPath = $mountsPath ?? pmssResolvePathFromEnv('PMSS_PROC_MOUNTS_PATH', '/proc/mounts');
        // Required hardening options; conflicts removed when present.
        $required = ['noexec', 'nosuid', 'nodev']; $conflicts = ['exec', 'suid', 'dev'];
        // Mountpoint -> option list map populated from /proc/mounts.
        $mounts = [];
        // Read mount options from /proc/mounts so we only remount when needed.
        if (is_readable($mountsPath)) {
            $lines = @file($mountsPath, FILE_IGNORE_NEW_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trim = trim($line);
                    if ($trim === '') {
                        continue;
                    }
                    $cols = preg_split('/\s+/', $trim);
                    if (count($cols) < 4) {
                        continue;
                    }
                    $mounts[$cols[1]] = array_filter(explode(',', $cols[3]), 'strlen');
                }
            }
        } else {
            $log('[WARN] '.$mountsPath.' not readable; skipping mount option checks');
        }
        // Fstab updates are best-effort and always backed up when changes occur.
        $updateFstab = static function (string $mountPoint) use ($log, $fstabPath, $required, $conflicts): void {
            if (!is_readable($fstabPath)) {
                $log('[WARN] '.$fstabPath.' not readable; skipping fstab hardening for '.$mountPoint);
                return;
            }
            $lines = file($fstabPath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $log('[WARN] Unable to read '.$fstabPath.'; skipping fstab hardening for '.$mountPoint);
                return;
            }
            $found = false; $changed = false;
            // Keep logs explicit for operator traceability.
            $missing = []; $removed = [];
            foreach ($lines as $idx => $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                $columns = preg_split('/\s+/', $trim);
                if (count($columns) < 4) {
                    continue;
                }
                if ($columns[1] !== $mountPoint) {
                    continue;
                }
                $found = true;
                $options = array_values(array_filter(explode(',', $columns[3]), 'strlen'));
                if ($options !== []) {
                    foreach ($conflicts as $conflict) {
                        $pos = array_search($conflict, $options, true);
                        if ($pos !== false) {
                            unset($options[$pos]);
                            $removed[] = $conflict;
                        }
                    }
                    $options = array_values($options);
                }
                foreach ($required as $opt) {
                    if (!in_array($opt, $options, true)) {
                        $options[] = $opt;
                        $missing[] = $opt;
                    }
                }
                if ($missing === [] && $removed === []) {
                    $log('[SKIP] '.$mountPoint.' already hardened in '.$fstabPath);
                    break;
                }
                $columns[3] = implode(',', $options);
                $lines[$idx] = implode("\t", $columns);
                $changed = true;
                $msg = '[WARN] Updated '.$mountPoint.' mount options in '.$fstabPath;
                if ($missing !== []) {
                    $msg .= ' (added '.implode(', ', $missing).')';
                }
                if ($removed !== []) {
                    $msg .= ' (removed '.implode(', ', $removed).')';
                }
                $log($msg);
                break;
            }
            if (!$found) {
                $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstabPath.'; skipping fstab hardening');
                return;
            }
            if ($changed) {
                $backup = $fstabPath.'.pmss-backup-'.date('YmdHis');
                if (!@copy($fstabPath, $backup)) {
                    $log('[WARN] Unable to create fstab backup at '.$backup);
                }
                if (@file_put_contents($fstabPath, implode(PHP_EOL, $lines).PHP_EOL) === false) {
                    $log('[WARN] Failed writing updated '.$fstabPath);
                } else {
                    $log('[WARN] Wrote updated '.$fstabPath.' (backup '.$backup.')');
                }
            }
        };
        // Each target is handled independently so one failure does not block the rest.
        $targets = ['/tmp', '/dev/shm'];
        foreach ($targets as $mountPoint) {
            $updateFstab($mountPoint);
            if (!isset($mounts[$mountPoint])) {
                // Skip remount if the mount is missing from /proc/mounts.
                $log('[WARN] '.$mountPoint.' not mounted; skipping remount');
                continue;
            }
            $current = $mounts[$mountPoint];
            $missing = array_diff($required, $current);
            if ($missing === []) {
                // Skip remount when options already applied.
                $log('[SKIP] '.$mountPoint.' already mounted with noexec,nosuid,nodev');
                continue;
            }
            // Remount with the hardening options, preserving other mount flags.
            $command = pmssBuildCommand('mount', ['-o', 'remount,noexec,nosuid,nodev', $mountPoint]);
            runStep('Remounting '.$mountPoint.' with noexec hardening', $command);
        }
    }
}
