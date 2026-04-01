<?php
/**
 * Optional mount hardening helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../fstab.php';
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
    $lines = pmssFstabLinesRead($fstabPath, $log, 'fstab hardening');
    $fstabChanged = false;
    // Each target is handled independently so one failure does not block the rest.
    foreach (['/tmp', '/dev/shm'] as $mountPoint) {
        // Fstab updates are best-effort and always backed up when changes occur.
        if ($lines !== null) {
            $entry = pmssFstabMountEntryRead($lines, $mountPoint);
            if ($entry === null) {
                $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstabPath.'; skipping fstab hardening');
            } else {
                $plan = pmssFstabMountOptionsPlan($entry['columns'], $required, $conflicts);
                if ($plan['added'] === [] && $plan['removed'] === []) {
                    $log('[SKIP] '.$mountPoint.' already hardened in '.$fstabPath);
                } else {
                    $lines[$entry['index']] = implode("\t", $plan['columns']);
                    $fstabChanged = true;
                    $msg = '[WARN] Updated '.$mountPoint.' mount options in '.$fstabPath;
                    if ($plan['added'] !== []) {
                        $msg .= ' (added '.implode(', ', $plan['added']).')';
                    }
                    if ($plan['removed'] !== []) {
                        $msg .= ' (removed '.implode(', ', $plan['removed']).')';
                    }
                    $log($msg);
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
    if ($fstabChanged) {
        pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log, true);
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

    $lines = pmssFstabLinesRead($fstabPath, $log, '/tmp tmpfs configuration');
    if ($lines === null) {
        return;
    }

    $changed = false;
    $added = false;
    $entry = pmssFstabMountEntryRead($lines, '/tmp');
    if ($entry !== null) {
        if ($entry['columns'][2] !== 'tmpfs') {
            $log('[WARN] /tmp is configured as non-tmpfs in '.$fstabPath.'; skipping tmpfs hardening');
            return;
        }

        $plan = pmssFstabMountOptionsPlan($entry['columns'], $required, $conflicts);
        $options = pmssFstabOptionsReplacePrefixedValue($plan['options'], 'size=', 'size='.$size, false);
        $updatedColumns = $entry['columns'];
        $updatedColumns[3] = implode(',', $options);
        if ($updatedColumns[3] === $entry['columns'][3]) {
            $log('[SKIP] /tmp tmpfs entry already up to date in '.$fstabPath);
        } else {
            $lines[$entry['index']] = implode("\t", $updatedColumns);
            $changed = true;
            $msg = '[WARN] Updated /tmp tmpfs options in '.$fstabPath;
            if ($plan['removed'] !== []) {
                $msg .= ' (removed '.implode(', ', $plan['removed']).')';
            }
            $log($msg);
        }
    } else {
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
