<?php
/**
 * Optional mount hardening helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../../runtime.php';

/** Check whether an opt-in hardening flag is enabled. */
function pmssMountHardeningFlagEnabled(string $envKey, string $notSetMessage, string $disabledMessage, callable $logger): bool
{
    $flag = getenv($envKey);
    if ($flag === false) {
        $logger($notSetMessage);
        return false;
    }
    if (!pmssEnvValueIsFalsey($flag)) return true;
    $logger($disabledMessage);
    return false;
}

/** Read /proc/mounts style data into a mountpoint-indexed map. */
function pmssMountHardeningReadMounts(string $mountsPath): array
{
    $mounts = [];
    if (!is_readable($mountsPath)) return $mounts;
    $lines = @file($mountsPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return $mounts;
    foreach ($lines as $line) {
        $columns = pmssConfigLineColumns($line, 4, []);
        if ($columns === []) continue;
        $mounts[$columns[1]] = ['type' => $columns[2], 'options' => array_values(array_filter(explode(',', $columns[3]), 'strlen'))];
    }
    return $mounts;
}

/** Ensure /tmp and /dev/shm are mounted with noexec/nosuid/nodev when enabled. */
function pmssConfigureTempMountNoexec(?callable $logger = null, ?string $fstabPath = null, ?string $mountsPath = null): void
{
    $log = $logger ?: 'logMessage';
    // Opt-in only: default off to preserve legacy workloads that execute from /tmp.
    if (!pmssMountHardeningFlagEnabled('PMSS_HARDEN_TMP_NOEXEC', '[SKIP] /tmp and /dev/shm noexec hardening disabled (PMSS_HARDEN_TMP_NOEXEC not set)', '[SKIP] /tmp and /dev/shm noexec hardening disabled via PMSS_HARDEN_TMP_NOEXEC', $log)) {
        return;
    }

    // Resolve paths up front so tests can inject temp files.
    $fstabPath = $fstabPath ?? '/etc/fstab';
    $mountsPath = $mountsPath ?? pmssResolvePathFromEnv('PMSS_PROC_MOUNTS_PATH', '/proc/mounts');
    // Required hardening options; conflicts removed when present.
    $required = ['noexec', 'nosuid', 'nodev'];
    $conflicts = ['exec', 'suid', 'dev'];
    // Mountpoint -> metadata map populated from /proc/mounts.
    $mounts = pmssMountHardeningReadMounts($mountsPath);
    if ($mounts === [] && !is_readable($mountsPath)) {
        $log('[WARN] '.$mountsPath.' not readable; skipping mount option checks');
    }
    // Each target is handled independently so one failure does not block the rest.
    foreach (['/tmp', '/dev/shm'] as $mountPoint) {
        // Fstab updates are best-effort and always backed up when changes occur.
        if (!is_readable($fstabPath)) {
            $log('[WARN] '.$fstabPath.' not readable; skipping fstab hardening for '.$mountPoint);
        } else {
            $lines = file($fstabPath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $log('[WARN] Unable to read '.$fstabPath.'; skipping fstab hardening for '.$mountPoint);
            } else {
                $found = false;
                $changed = false;
                // Keep logs explicit for operator traceability.
                $missing = [];
                $removed = [];
                foreach ($lines as $idx => $line) {
                    $columns = pmssConfigLineColumns($line, 4);
                    if ($columns === []) continue;
                    if ($columns[1] !== $mountPoint) {
                        continue;
                    }
                    $found = true;
                    $options = array_values(array_filter(explode(',', $columns[3]), 'strlen'));
                    foreach ($conflicts as $conflict) {
                        $pos = array_search($conflict, $options, true);
                        if ($pos === false) {
                            continue;
                        }
                        unset($options[$pos]);
                        $removed[] = $conflict;
                    }
                    $options = array_values($options);
                    $missing = array_values(array_diff($required, $options));
                    if ($missing === [] && $removed === []) {
                        $log('[SKIP] '.$mountPoint.' already hardened in '.$fstabPath);
                        break;
                    }
                    $columns[3] = implode(',', array_merge($options, $missing));
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
                } elseif ($changed) {
                    pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log, true);
                }
            }
        }
        if (!isset($mounts[$mountPoint])) {
            // Skip remount if the mount is missing from /proc/mounts.
            $log('[WARN] '.$mountPoint.' not mounted; skipping remount');
            continue;
        }
        $missing = array_diff($required, $mounts[$mountPoint]['options']);
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

/**
 * Ensure /tmp is mounted as tmpfs with hardened options when enabled.
 */
function pmssConfigureTempTmpfsMount(?callable $logger = null, ?string $fstabPath = null, ?string $mountsPath = null): void
{
    $log = $logger ?: 'logMessage';
    // Opt-in only: tmpfs overlay can evict existing /tmp contents.
    if (!pmssMountHardeningFlagEnabled('PMSS_HARDEN_TMP_TMPFS', '[SKIP] /tmp tmpfs hardening disabled (PMSS_HARDEN_TMP_TMPFS not set)', '[SKIP] /tmp tmpfs hardening disabled via PMSS_HARDEN_TMP_TMPFS', $log)) {
        return;
    }

    $size = trim((string) getenv('PMSS_TMPFS_TMP_SIZE'));
    if ($size === '') {
        $size = '2G';
    }
    if (!preg_match('/^[0-9]+[KMGTP]?$/i', $size)) {
        $log('[WARN] Invalid PMSS_TMPFS_TMP_SIZE value; defaulting to 2G');
        $size = '2G';
    }

    $fstabPath = $fstabPath ?? '/etc/fstab';
    $mountsPath = $mountsPath ?? pmssResolvePathFromEnv('PMSS_PROC_MOUNTS_PATH', '/proc/mounts');
    $required = ['noexec', 'nosuid', 'nodev'];
    $conflicts = ['exec', 'suid', 'dev'];

    $mounts = pmssMountHardeningReadMounts($mountsPath);
    if ($mounts === [] && !is_readable($mountsPath)) {
        $log('[WARN] '.$mountsPath.' not readable; skipping /proc/mounts checks');
    }
    $tmpMount = $mounts['/tmp'] ?? ['type' => null, 'options' => []];

    if (!is_readable($fstabPath)) {
        $log('[WARN] '.$fstabPath.' not readable; skipping /tmp tmpfs configuration');
        return;
    }
    $lines = file($fstabPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $log('[WARN] Unable to read '.$fstabPath.'; skipping /tmp tmpfs configuration');
        return;
    }

    $found = false;
    $changed = false;
    $added = false;
    foreach ($lines as $idx => $line) {
        $columns = pmssConfigLineColumns($line, 4);
        if ($columns === []) continue;
        if ($columns[1] !== '/tmp') {
            continue;
        }
        $found = true;
        if ($columns[2] !== 'tmpfs') {
            $log('[WARN] /tmp is configured as non-tmpfs in '.$fstabPath.'; skipping tmpfs hardening');
            return;
        }

        $options = array_values(array_filter(explode(',', $columns[3]), 'strlen'));
        $original = $options;
        $removed = [];

        foreach ($conflicts as $conflict) {
            $pos = array_search($conflict, $options, true);
            if ($pos !== false) {
                unset($options[$pos]);
                $removed[] = $conflict;
            }
        }
        $options = array_values($options);

        $sizeOption = 'size='.$size;
        $sizeIndex = null;
        foreach ($options as $i => $option) {
            if (strpos($option, 'size=') === 0) {
                $sizeIndex = $i;
                break;
            }
        }
        if ($sizeIndex === null) {
            $options[] = $sizeOption;
        } elseif ($options[$sizeIndex] !== $sizeOption) {
            $options[$sizeIndex] = $sizeOption;
        }

        $options = array_merge($options, array_values(array_diff($required, $options)));

        if ($options === $original) {
            $log('[SKIP] /tmp tmpfs entry already up to date in '.$fstabPath);
            break;
        }

        $columns[3] = implode(',', $options);
        $lines[$idx] = implode("\t", $columns);
        $changed = true;
        $msg = '[WARN] Updated /tmp tmpfs options in '.$fstabPath;
        if (!empty($removed)) {
            $msg .= ' (removed '.implode(', ', $removed).')';
        }
        $log($msg);
        break;
    }

    if (!$found) {
        $lines[] = 'tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size='.$size.' 0 0';
        $changed = true;
        $added = true;
        $log('[WARN] Added /tmp tmpfs entry to '.$fstabPath.' (size='.$size.')');
    }

    if ($changed) {
        pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log, true);
    }

    if (!is_dir('/tmp') || is_link('/tmp')) {
        $log('[WARN] /tmp is not a directory; skipping tmpfs mount');
        return;
    }

    $needsMount = $added || ($tmpMount['type'] !== 'tmpfs');
    $missingHardening = array_diff($required, $tmpMount['options']);
    if (!$needsMount && empty($missingHardening)) {
        $log('[SKIP] /tmp already mounted as tmpfs with hardened options');
        return;
    }

    if ($tmpMount['type'] === 'tmpfs') {
        $command = pmssBuildCommand('mount', ['-o', 'remount,noexec,nosuid,nodev,size='.$size, '/tmp']);
        runStep('Remounting /tmp tmpfs with hardened options', $command);
        return;
    }

    $command = pmssBuildCommand('mount', ['/tmp']);
    runStep('Mounting /tmp tmpfs from fstab', $command);
}
