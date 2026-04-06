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
    if ($flag === false) { $logger($notSetMessage); return false; }
    if (!pmssEnvValueIsFalsey($flag)) return true;
    $logger($disabledMessage);
    return false;
}

/** Read /proc/mounts style data into a mountpoint-indexed map. */
function pmssMountHardeningReadMounts(string $mountsPath): array
{
    $mounts = [];
    if (!is_readable($mountsPath) || ($lines = @file($mountsPath, FILE_IGNORE_NEW_LINES)) === false) return $mounts;
    foreach ($lines as $line) {
        $columns = pmssConfigLineColumns($line, 4, []);
        if ($columns === []) continue;
        $mounts[$columns[1]] = ['type' => $columns[2], 'options' => array_values(array_filter(explode(',', $columns[3]), 'strlen'))];
    }
    return $mounts;
}

/** Persist fstab mutations before any live remount is attempted. */
function pmssMountHardeningPersistFstabChanges(string $fstabPath, array $lines, callable $logger): bool
{
    if (pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $logger, true)) {
        return true;
    }

    $logger('[WARN] Skipping live mount hardening because '.$fstabPath.' could not be updated');

    return false;
}

/** Ensure /tmp and /dev/shm are mounted with noexec/nosuid/nodev when enabled. */
function pmssConfigureTempMountNoexec(?callable $logger = null, ?string $fstabPath = null, ?string $mountsPath = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssMountHardeningFlagEnabled('PMSS_HARDEN_TMP_NOEXEC', '[SKIP] /tmp and /dev/shm noexec hardening disabled (PMSS_HARDEN_TMP_NOEXEC not set)', '[SKIP] /tmp and /dev/shm noexec hardening disabled via PMSS_HARDEN_TMP_NOEXEC', $log)) return;

    $fstabPath = $fstabPath ?? '/etc/fstab';
    $mountsPath = $mountsPath ?? pmssResolvePathFromEnv('PMSS_PROC_MOUNTS_PATH', '/proc/mounts');
    $required = ['noexec', 'nosuid', 'nodev'];
    $conflicts = ['exec', 'suid', 'dev'];
    $mounts = pmssMountHardeningReadMounts($mountsPath);
    if ($mounts === [] && !is_readable($mountsPath)) $log('[WARN] '.$mountsPath.' not readable; skipping mount option checks');
    $lines = pmssFstabLinesRead($fstabPath, $log, 'fstab hardening');
    $fstabChanged = false;
    $remounts = [];
    foreach (['/tmp', '/dev/shm'] as $mountPoint) {
        if (is_array($lines)) {
            $plan = pmssFstabMountOptionsEnsure($lines, $mountPoint, $required, $conflicts);
            if ($plan === null) {
                $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstabPath.'; skipping fstab hardening');
            } elseif (!$plan['changed']) {
                $log('[SKIP] '.$mountPoint.' already hardened in '.$fstabPath);
            } else {
                $fstabChanged = true;
                $log('[WARN] Updated '.$mountPoint.' mount options in '.$fstabPath.pmssFstabPlanChangeSuffix($plan));
            }
        }
        if (!isset($mounts[$mountPoint])) { $log('[WARN] '.$mountPoint.' not mounted; skipping remount'); continue; }
        if (array_diff($required, $mounts[$mountPoint]['options']) === []) { $log('[SKIP] '.$mountPoint.' already mounted with noexec,nosuid,nodev'); continue; }
        $remounts[] = $mountPoint;
    }
    if ($fstabChanged && is_array($lines) && !pmssMountHardeningPersistFstabChanges($fstabPath, $lines, $log)) return;
    foreach ($remounts as $mountPoint) {
        runStep('Remounting '.$mountPoint.' with noexec hardening', pmssBuildCommand('mount', ['-o', 'remount,'.implode(',', $required), $mountPoint]));
    }
}

/** Ensure /tmp is mounted as tmpfs with hardened options when enabled. */
function pmssConfigureTempTmpfsMount(?callable $logger = null, ?string $fstabPath = null, ?string $mountsPath = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssMountHardeningFlagEnabled('PMSS_HARDEN_TMP_TMPFS', '[SKIP] /tmp tmpfs hardening disabled (PMSS_HARDEN_TMP_TMPFS not set)', '[SKIP] /tmp tmpfs hardening disabled via PMSS_HARDEN_TMP_TMPFS', $log)) return;

    $size = trim((string) getenv('PMSS_TMPFS_TMP_SIZE'));
    $size === '' && $size = '2G';
    if (!preg_match('/^[0-9]+[KMGTP]?$/i', $size)) { $log('[WARN] Invalid PMSS_TMPFS_TMP_SIZE value; defaulting to 2G'); $size = '2G'; }

    $fstabPath = $fstabPath ?? '/etc/fstab';
    $mountsPath = $mountsPath ?? pmssResolvePathFromEnv('PMSS_PROC_MOUNTS_PATH', '/proc/mounts');
    $required = ['noexec', 'nosuid', 'nodev'];
    $conflicts = ['exec', 'suid', 'dev'];
    $mounts = pmssMountHardeningReadMounts($mountsPath);
    if ($mounts === [] && !is_readable($mountsPath)) $log('[WARN] '.$mountsPath.' not readable; skipping /proc/mounts checks');
    $tmpMount = $mounts['/tmp'] ?? ['type' => null, 'options' => []];
    $lines = pmssFstabLinesRead($fstabPath, $log, '/tmp tmpfs configuration');
    if ($lines === null) return;

    $changed = false;
    $added = false;
    $entry = pmssFstabMountEntryRead($lines, '/tmp');
    if ($entry === null) {
        $lines[] = 'tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size='.$size.' 0 0';
        $changed = true;
        $added = true;
        $log('[WARN] Added /tmp tmpfs entry to '.$fstabPath.' (size='.$size.')');
    } else {
        if ($entry['columns'][2] !== 'tmpfs') { $log('[WARN] /tmp is configured as non-tmpfs in '.$fstabPath.'; skipping tmpfs hardening'); return; }
        $plan = pmssFstabMountOptionsEnsure($lines, '/tmp', $required, $conflicts, false, 'tmpfs', ['size=' => 'size='.$size], false);
        if (!$plan['changed']) {
            $log('[SKIP] /tmp tmpfs entry already up to date in '.$fstabPath);
        } else {
            $changed = true;
            $log('[WARN] Updated /tmp tmpfs options in '.$fstabPath.pmssFstabPlanChangeSuffix($plan));
        }
    }

    if ($changed && !pmssMountHardeningPersistFstabChanges($fstabPath, $lines, $log)) return;
    if (!is_dir('/tmp') || is_link('/tmp')) { $log('[WARN] /tmp is not a directory; skipping tmpfs mount'); return; }

    $needsMount = $added || ($tmpMount['type'] !== 'tmpfs');
    if (!$needsMount && array_diff($required, $tmpMount['options']) === []) { $log('[SKIP] /tmp already mounted as tmpfs with hardened options'); return; }
    if ($tmpMount['type'] === 'tmpfs') { runStep('Remounting /tmp tmpfs with hardened options', pmssBuildCommand('mount', ['-o', 'remount,'.implode(',', array_merge($required, ['size='.$size])), '/tmp'])); return; }
    runStep('Mounting /tmp tmpfs from fstab', pmssBuildCommand('mount', ['/tmp']));
}
