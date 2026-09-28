<?php
/** Bounded per-user tracker-cleaner run flow. @license GPL-3.0-only */

function pmssTrackerCleanerTorrentCandidates(string $sessionDir, int $maxTorrents): array { $torrents = glob($sessionDir.'/*.torrent'); if (!is_array($torrents) || $torrents === []) return []; shuffle($torrents); return count($torrents) > $maxTorrents ? array_slice($torrents, 0, $maxTorrents) : $torrents; }

function pmssTrackerCleanerStopReason(int $runDeadline, int $modifiedCount, int $maxModifiedTorrents): string { if (time() >= $runDeadline) return 'runtime_limit'; return $modifiedCount >= $maxModifiedTorrents ? 'modify_limit' : ''; }

function pmssTrackerCleanerRunUser(
    string $username,
    array $blockRules,
    int $runDeadline,
    int $maxTorrentsPerUser,
    int $maxModifiedTorrents,
    int &$modifiedCount,
    bool &$anyWork,
    bool &$anyChanges,
    &$summary = ''
): string {
    $summary = '';
    $userVerboseLog = pmssTrackerCleanerTimestamp()." run_start user={$username}\n";
    $sessionDir = "/home/{$username}/session";
    $backupsDir = $sessionDir.'/backups';
    // Check in priority order; later path checks must not run for suspended users.
    if (pmssUserWebRootUnavailable($username)) $skip = ['suspended', "User {$username} is suspended; skipping.", ''];
    elseif (file_exists("/home/{$username}/.trackerCleanerDisable")) $skip = ['disabled', '', ''];
    elseif (!pmssPathWithinRootIsSafe($sessionDir, $sessionDir, true)) $skip = ['session_path_unsafe', "SKIP: refusing to operate; session path unsafe for user {$username} ({$sessionDir}).", ' session='.$sessionDir];
    elseif (file_exists($backupsDir) && (!is_dir($backupsDir) || is_link($backupsDir))) $skip = ['backups_path_unsafe', "SKIP: refusing to operate; backups path unsafe for user {$username} ({$backupsDir}).", ' backups='.$backupsDir];
    if (isset($skip)) {
        if ($skip[1] !== '') pmssTrackerCleanerLog($skip[1]);
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason={$skip[0]}{$skip[2]}\n";
        pmssTrackerCleanerWriteUserVerboseLog($username, $userVerboseLog);
        return '';
    }

    $torrents = pmssTrackerCleanerTorrentCandidates($sessionDir, $maxTorrentsPerUser);
    if ($torrents === []) {
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_skip reason=no_session_torrents session={$sessionDir}\n";
        pmssTrackerCleanerWriteUserVerboseLog($username, $userVerboseLog);
        return '';
    }

    $changes = [];
    $backupDirectory = $backupsDir.'/'.date('Y-m-d_Hi');
    $processed = $private = $changed = 0;
    $stopReason = '';

    pmssTrackerCleanerLog("User {$username}, ".count($torrents).' torrents to be checked.');
    $userVerboseLog .= pmssTrackerCleanerTimestamp()." user_selected candidates=".count($torrents)." backups_dir={$backupDirectory}\n";

    foreach ($torrents as $torrentPath) {
        $stopReason = pmssTrackerCleanerStopReason($runDeadline, $modifiedCount, $maxModifiedTorrents);
        if ($stopReason !== '') { $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_stop reason={$stopReason}\n"; break; }

        $torrentPath = trim($torrentPath);
        if (!pmssRegularFilePathIsReadable($torrentPath) || !pmssPathWithinRootIsSafe($torrentPath, $sessionDir)) {
            continue;
        }
        try {
            $torrent = \Devristo\Torrent\Torrent::fromFile($torrentPath);
        } catch (\Throwable $e) {
            $message = str_replace("\n", ' ', $e->getMessage());
            pmssTrackerCleanerLog("WARN: Failed to parse torrent for user {$username} (file=".basename($torrentPath)."): {$message}");
            $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_skip reason=parse_error file=".basename($torrentPath)." message=".$message."\n";
            continue;
        }

        $processed++;
        $anyWork = true;
        if ($torrent->isPrivate()) {
            $private++;
            $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_skip reason=private_flag_present file=".basename($torrentPath)
                ." infohash=".$torrent->getInfoHash(false)
                ." name=".pmssTrackerCleanerLogValue($torrent->getName())."\n";
            continue;
        }

        $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_check public=1 file=".basename($torrentPath)
            ." infohash=".$torrent->getInfoHash(false)
            ." name=".pmssTrackerCleanerLogValue($torrent->getName())."\n";
        $scrub = pmssTrackerCleanerScrubTorrent($torrent, $blockRules);
        foreach ($scrub['events'] as $event) {
            $userVerboseLog .= pmssTrackerCleanerTimestamp().' '.$event."\n";
        }
        if ($scrub['would_trackerless']) {
            $warning = 'Would leave completely trackerless, no tracker cleaning done';
            pmssTrackerCleanerLog("WARN: {$warning} (user={$username} file=".basename($torrentPath).")");
            $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_skip reason=trackerless warning=".$warning."\n";
            continue;
        }
        if (!$scrub['changed']) {
            $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_ok no_changes=1\n";
            continue;
        }

        $removedList = $scrub['removed_trackers'] === [] ? '(unknown)' : implode(', ', $scrub['removed_trackers']);
        $backup = pmssTrackerCleanerBackupTorrent($username, $torrentPath, $backupDirectory, $backupsDir, $removedList);
        $userVerboseLog .= $backup['verbose_log'];
        if (!$backup['ok']) { $stopReason = $backup['stop_reason']; break; }
        $comment = (string) $torrent->getComment();
        $markedComment = pmssTrackerCleanerCommentWithMarker($comment);
        if ($markedComment !== $comment) $torrent->setComment($markedComment);
        $written = pmssTrackerCleanerWriteCleanedTorrent($torrentPath, $torrent->serialize(), $sessionDir);
        $writtenBytes = $written === false ? -1 : (int) $written;
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_write bytes={$writtenBytes} file={$torrentPath}\n";
        if ($written === false) {
            pmssTrackerCleanerLog("WARN: Failed to write cleaned torrent for user {$username} (file=".basename($torrentPath).").");
            $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_skip reason=write_failed removed_trackers=".$removedList."\n";
            continue;
        }

        $changes[$torrent->getInfoHash(false)] = $torrent->getName();
        $changed++;
        $anyChanges = true;
        $modifiedCount++;
        $userVerboseLog .= pmssTrackerCleanerTimestamp()." torrent_change removed_trackers=".$removedList."\n";
        // Throttle only after successful public-torrent modifications.
        usleep(25000);
    }

    echo pmssTrackerCleanerAppendUserChangeLog($username, $changes);
    $runSuffix = $stopReason !== '' ? " reason={$stopReason}" : '';
    $userVerboseLog .= pmssTrackerCleanerTimestamp()." run_end user={$username} processed={$processed} private={$private} changed={$changed}{$runSuffix}\n";
    pmssTrackerCleanerWriteUserVerboseLog($username, $userVerboseLog);
    $summary = pmssTrackerCleanerUserSummary($processed, $private, $changed, $stopReason);

    return $stopReason;
}
