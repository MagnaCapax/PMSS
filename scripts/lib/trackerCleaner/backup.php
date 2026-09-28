<?php
/** Tracker-cleaner backup and guarded torrent replacement. @license GPL-3.0-only */

function pmssTrackerCleanerBackupFailedResult(string $reason, string $detail = '', string $prefix = ''): array { $suffix = $detail !== '' ? ' '.$detail : ''; return ['ok' => false, 'stop_reason' => 'backup_failed', 'verbose_log' => $prefix.pmssTrackerCleanerTimestamp()." torrent_skip reason={$reason}{$suffix}\n".pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n"]; }

function pmssTrackerCleanerBackupTorrentSourceIsSafe(string $torrentPath): bool
{
    $fileName = basename($torrentPath);
    return $fileName !== ''
        && substr($fileName, -8) === '.torrent'
        && pmssRegularFilePathIsReadable($torrentPath)
        && pmssPathTargetIsSafe($torrentPath, false);
}

function pmssTrackerCleanerBackupDestinationIsSafe(string $backupDir, string $backupsRoot): bool
{
    $dir = rtrim($backupDir, '/');
    $root = rtrim($backupsRoot, '/');
    if ($dir === '' || $root === '' || $root === '/') {
        return false;
    }

    return pmssPathSegmentsAreSafe($root, false, true, false, true)
        && pmssPathSegmentsAreSafe($dir, false, true, false, true)
        && ($dir === $root || strpos($dir, $root.'/') === 0);
}

/** @return array{ok:bool,stop_reason:string,verbose_log:string} */
function pmssTrackerCleanerBackupTorrent(string $username, string $torrentPath, string $backupDir, string $backupsRoot, string $removedList): array
{
    if (!pmssValidateUsername($username)) {
        pmssTrackerCleanerLog('ERR: Refusing tracker backup for invalid username.');
        return pmssTrackerCleanerBackupFailedResult('invalid_username', 'user='.pmssTrackerCleanerLogValue($username));
    }
    if (!pmssTrackerCleanerBackupTorrentSourceIsSafe($torrentPath)) {
        pmssTrackerCleanerLog("ERR: Refusing unsafe torrent backup source for user {$username}.");
        return pmssTrackerCleanerBackupFailedResult('torrent_path_unsafe', 'src='.pmssTrackerCleanerLogValue($torrentPath));
    }
    if (!pmssTrackerCleanerBackupDestinationIsSafe($backupDir, $backupsRoot)) {
        pmssTrackerCleanerLog("ERR: Backup path unsafe for user {$username} ({$backupDir}).");
        return pmssTrackerCleanerBackupFailedResult('backup_path_unsafe', 'backup_dir='.pmssTrackerCleanerLogValue($backupDir));
    }

    $sourcePerms = @fileperms($torrentPath);
    $sourceModeText = sprintf('%o', $sourcePerms === false ? 0640 : ($sourcePerms & 0777));
    $sourceSize = @filesize($torrentPath);
    $sourceSizeText = $sourceSize === false ? 'unknown' : (string) $sourceSize;

    if (!is_dir($backupDir)) {
        $prepareRc = pmssUserLifecycleStep('trackerCleaner', $username, 'prepare_backup_dir', pmssBuildUserShellCommand($username, 'mkdir -p '.escapeshellarg($backupDir).' && chmod 750 '.escapeshellarg($backupDir), '/bin/bash'), false);
        if ($prepareRc !== 0) {
            pmssTrackerCleanerLog("ERR: Failed to prepare backup dir for user {$username} (dir={$backupDir}, rc={$prepareRc}).");
            return pmssTrackerCleanerBackupFailedResult('backup_dir_prepare_failed', "rc={$prepareRc} backup_dir={$backupDir}");
        }
    }
    if (!pmssPathWithinRootIsSafe($backupDir, $backupsRoot, true)) {
        pmssTrackerCleanerLog("ERR: Backup path unsafe for user {$username} ({$backupDir}).");
        return pmssTrackerCleanerBackupFailedResult('backup_path_unsafe', "backup_dir={$backupDir}");
    }

    $backupTarget = $backupDir.'/'.basename($torrentPath);
    // Refuse occupied non-files and symlinks before cp can follow the target.
    if (!pmssPathTargetIsSafe($backupTarget, false, true)) {
        pmssTrackerCleanerLog("ERR: Backup target unsafe for user {$username} ({$backupTarget}).");
        return pmssTrackerCleanerBackupFailedResult('backup_path_unsafe', 'backup_target='.pmssTrackerCleanerLogValue($backupTarget));
    }
    $backupRc = pmssUserLifecycleStep('trackerCleaner', $username, 'backup_torrent', pmssBuildUserShellCommand($username, 'cp -p '.escapeshellarg($torrentPath).' '.escapeshellarg($backupTarget).' && chmod '.$sourceModeText.' '.escapeshellarg($backupTarget), '/bin/bash'), false);
    $backupSize = @filesize($backupTarget);
    $backupSizeText = $backupSize === false ? 'unknown' : (string) $backupSize;
    $backupOk = $backupRc === 0 && $sourceSize !== false && $backupSize !== false && $backupSize === $sourceSize
        && pmssRegularFilePathIsReadable($backupTarget) && pmssPathWithinRootIsSafe($backupTarget, $backupsRoot);
    $verbose = pmssTrackerCleanerTimestamp()." torrent_backup rc={$backupRc} src={$torrentPath} dst={$backupTarget}\n";
    if (!$backupOk) {
        pmssTrackerCleanerLog("ERR: Backup verification failed for user {$username} (file=".basename($torrentPath).", rc={$backupRc}, src_bytes={$sourceSizeText}, dst_bytes={$backupSizeText}).");
        return pmssTrackerCleanerBackupFailedResult('backup_failed', "rc={$backupRc} src={$torrentPath} dst={$backupTarget} src_bytes={$sourceSizeText} dst_bytes={$backupSizeText} removed_trackers={$removedList}", $verbose);
    }

    return ['ok' => true, 'stop_reason' => '', 'verbose_log' => $verbose];
}

/**
 * Atomically replace a cleaned torrent only when it is still inside its session root.
 *
 * @return int|false
 */
function pmssTrackerCleanerWriteCleanedTorrent(string $torrentPath, string $payload, string $sessionDir)
{
    if (!pmssRegularFilePathIsReadable($torrentPath) || !pmssPathWithinRootIsSafe($torrentPath, $sessionDir)) {
        return false;
    }

    return pmssReplaceUserFilePreservingMetadata($torrentPath, $payload, 0640) ? strlen($payload) : false;
}
