<?php
/** Shared tracker-cleaner policy and in-memory torrent mutation helpers. */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/lighttpd/userFileWrite.php';

function pmssTrackerCleanerTimestamp(): string { return '['.date('Y-m-d H:i:s').']'; }

function pmssTrackerCleanerLog(string $message): void { echo pmssTrackerCleanerTimestamp().' '.$message."\n"; }

/** @return array{contains:array<int,string>,domains:array<int,string>} */
function pmssTrackerCleanerBlockRules(): array
{
    return [
        'contains' => ['udp://public.popcorn-tracker.org:6969/announce', 'http://sub4all.org', 'udp://tracker.openbittorrent.com:80/announce', 'udp://tracker.publicbt.com', 'udp://tracker.ccc.de', 'http://tracker.tntvillage.scambioetico.org', 'http://exodus.desync.com', 'http://tracker.ftfansub.net', 'http://nyaa.tracker.wf', 'udp://tracker.istole.it', 'udp://mgtracker.org'],
        'domains' => ['legittorrents.info', 'tracker.openbittorrent.com', 'tracker.leechers-paradise.org', 'tracker.coppersurfer.tk', '9.rarbg.', '10.rarbg.', 'tracker.eddie4.nl', 'tracker.supertracker.net', 'concen.org', 'tracker.tfile.me', 'tracker.cyberia.is'],
    ];
}

function pmssTrackerCleanerShouldScrubTracker(string $trackerUrl, array $blockRules): bool
{
    foreach ($blockRules['contains'] ?? [] as $filter) {
        if ($filter !== '' && strpos($trackerUrl, $filter) !== false) return true;
    }
    foreach ($blockRules['domains'] ?? [] as $domain) {
        if ($domain !== '' && stripos($trackerUrl, $domain) !== false) return true;
    }
    return false;
}

function pmssTrackerCleanerLogValue($value): string { return str_replace(["\r", "\n"], ' ', (string) $value); }

function pmssTrackerCleanerChangeLog(array $changes, ?string $timestamp = null): string
{
    $timestamp = $timestamp ?? pmssTrackerCleanerTimestamp();
    $log = '';
    foreach ($changes as $infoHash => $name) $log .= $timestamp.' Changed '.$name.' ('.$infoHash.")\n";
    return $log;
}

function pmssTrackerCleanerUserSummary(int $processed, int $private, int $changed, string $stopReason = ''): string
{
    return sprintf('tracker cleaner: processed=%d private=%d changed=%d%s', $processed, $private, $changed, $stopReason !== '' ? ' stop_reason='.$stopReason : '');
}

function pmssTrackerCleanerRunOutcomeLogLine(string $stopReason, bool $anyWork, bool $anyChanges): string
{
    if ($stopReason === 'runtime_limit') return 'WARN: runtime limit reached; stopping early.';
    if ($stopReason === 'backup_failed') return 'ERR: backup verification failed; stopping early.';
    if ($stopReason === 'modify_limit') return 'WARN: modification limit reached; stopping early.';
    if (!$anyWork) return 'SKIP: no eligible torrents processed this run.';
    return $anyChanges ? 'OK: run complete; tracker changes applied.' : 'OK: run complete; no tracker changes needed.';
}

function pmssTrackerCleanerWriteUserVerboseLog(string $username, string $payload): void
{
    if ($payload === '') {
        return;
    }
    $userHome = "/home/{$username}";
    $userLogsDir = $userHome.'/.logs';
    $userLogFile = $userLogsDir.'/trackerCleaner.log';
    $tmpLogPath = pmssCreatePrivateTempFile('pmss-trackerCleaner-');
    if ($tmpLogPath === null || @file_put_contents($tmpLogPath, $payload) === false) {
        if (is_string($tmpLogPath)) @unlink($tmpLogPath);
        return;
    }
    if (!@chown($tmpLogPath, $username)) {
        pmssTrackerCleanerLog("WARN: Unable to chown temp log {$tmpLogPath} for user {$username}; skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }
    @chgrp($tmpLogPath, $username); @chmod($tmpLogPath, 0640);

    pmssUserLifecycleStep('trackerCleaner', $username, 'ensure_user_logs_dir', pmssBuildUserShellCommand($username, 'mkdir -p ~/.logs', '/bin/bash'), false);
    if (!is_dir($userLogsDir) || !pmssPathWithinRootIsSafe($userLogsDir, $userHome, true)) {
        pmssTrackerCleanerLog("WARN: User log directory is unsafe or missing for {$username} ({$userLogsDir}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }
    if (file_exists($userLogFile) && is_link($userLogFile)) {
        pmssTrackerCleanerLog("WARN: User log file is symlink for {$username} ({$userLogFile}); skipping per-user verbose log.");
        @unlink($tmpLogPath);
        return;
    }

    pmssUserLifecycleStep('trackerCleaner', $username, 'append_user_verbose_log', pmssBuildUserShellCommand($username, 'cat '.escapeshellarg($tmpLogPath).' >> ~/.logs/trackerCleaner.log', '/bin/bash'), false);
    if (file_exists($userLogFile) && !is_link($userLogFile) && pmssPathWithinRootIsSafe($userLogFile, $userHome)) {
        pmssUserFileApplyOwnership($userLogFile, $username);
    }
    @unlink($tmpLogPath);
}

/** @return array{skip:bool,reason:string,message:string,session_dir:string,backups_dir:string} */
function pmssTrackerCleanerUserSessionPlan(string $username): array
{
    $sessionDir = "/home/{$username}/session";
    $backupsDir = $sessionDir.'/backups';

    if (pmssUserWebRootUnavailable($username)) return ['skip' => true, 'reason' => 'suspended', 'message' => "User {$username} is suspended; skipping.", 'session_dir' => $sessionDir, 'backups_dir' => $backupsDir];
    if (file_exists("/home/{$username}/.trackerCleanerDisable")) return ['skip' => true, 'reason' => 'disabled', 'message' => '', 'session_dir' => $sessionDir, 'backups_dir' => $backupsDir];
    if (!pmssPathWithinRootIsSafe($sessionDir, $sessionDir, true)) return ['skip' => true, 'reason' => 'session_path_unsafe', 'message' => "SKIP: refusing to operate; session path unsafe for user {$username} ({$sessionDir}).", 'session_dir' => $sessionDir, 'backups_dir' => $backupsDir];
    if (file_exists($backupsDir) && (!is_dir($backupsDir) || is_link($backupsDir))) return ['skip' => true, 'reason' => 'backups_path_unsafe', 'message' => "SKIP: refusing to operate; backups path unsafe for user {$username} ({$backupsDir}).", 'session_dir' => $sessionDir, 'backups_dir' => $backupsDir];

    return ['skip' => false, 'reason' => '', 'message' => '', 'session_dir' => $sessionDir, 'backups_dir' => $backupsDir];
}

function pmssTrackerCleanerTorrentCandidates(string $sessionDir, int $maxTorrents): array { $torrents = glob($sessionDir.'/*.torrent'); if (!is_array($torrents) || $torrents === []) return []; shuffle($torrents); return count($torrents) > $maxTorrents ? array_slice($torrents, 0, $maxTorrents) : $torrents; }

/** @return array{ok:bool,stop_reason:string,verbose_log:string} */
function pmssTrackerCleanerBackupTorrent(string $username, string $torrentPath, string $backupDir, string $backupsRoot, string $removedList): array
{
    $sourcePerms = @fileperms($torrentPath);
    $sourceModeText = sprintf('%o', $sourcePerms === false ? 0640 : ($sourcePerms & 0777));
    $sourceSize = @filesize($torrentPath);
    $sourceSizeText = $sourceSize === false ? 'unknown' : (string) $sourceSize;

    if (!is_dir($backupDir)) {
        $prepareRc = pmssUserLifecycleStep('trackerCleaner', $username, 'prepare_backup_dir', pmssBuildUserShellCommand($username, 'mkdir -p '.escapeshellarg($backupDir).' && chmod 750 '.escapeshellarg($backupDir), '/bin/bash'), false);
        if ($prepareRc !== 0) {
            pmssTrackerCleanerLog("ERR: Failed to prepare backup dir for user {$username} (dir={$backupDir}, rc={$prepareRc}).");
            return ['ok' => false, 'stop_reason' => 'backup_failed', 'verbose_log' => pmssTrackerCleanerTimestamp()." torrent_skip reason=backup_dir_prepare_failed rc={$prepareRc} backup_dir={$backupDir}\n".pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n"];
        }
    }
    if (!pmssPathWithinRootIsSafe($backupDir, $backupsRoot, true)) {
        pmssTrackerCleanerLog("ERR: Backup path unsafe for user {$username} ({$backupDir}).");
        return ['ok' => false, 'stop_reason' => 'backup_failed', 'verbose_log' => pmssTrackerCleanerTimestamp()." torrent_skip reason=backup_path_unsafe backup_dir={$backupDir}\n".pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n"];
    }

    $backupTarget = $backupDir.'/'.basename($torrentPath);
    $backupRc = pmssUserLifecycleStep('trackerCleaner', $username, 'backup_torrent', pmssBuildUserShellCommand($username, 'cp -p '.escapeshellarg($torrentPath).' '.escapeshellarg($backupTarget).' && chmod '.$sourceModeText.' '.escapeshellarg($backupTarget), '/bin/bash'), false);
    $backupSize = @filesize($backupTarget);
    $backupSizeText = $backupSize === false ? 'unknown' : (string) $backupSize;
    $backupOk = $backupRc === 0 && $sourceSize !== false && $backupSize !== false && $backupSize === $sourceSize
        && is_file($backupTarget) && !is_link($backupTarget) && pmssPathWithinRootIsSafe($backupTarget, $backupsRoot);
    $verbose = pmssTrackerCleanerTimestamp()." torrent_backup rc={$backupRc} src={$torrentPath} dst={$backupTarget}\n";
    if (!$backupOk) {
        pmssTrackerCleanerLog("ERR: Backup verification failed for user {$username} (file=".basename($torrentPath).", rc={$backupRc}, src_bytes={$sourceSizeText}, dst_bytes={$backupSizeText}).");
        $verbose .= pmssTrackerCleanerTimestamp()." torrent_skip reason=backup_failed rc={$backupRc} src={$torrentPath} dst={$backupTarget} src_bytes={$sourceSizeText} dst_bytes={$backupSizeText} removed_trackers={$removedList}\n".pmssTrackerCleanerTimestamp()." run_stop reason=backup_failed\n";
        return ['ok' => false, 'stop_reason' => 'backup_failed', 'verbose_log' => $verbose];
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
    if (!is_file($torrentPath) || is_link($torrentPath) || !pmssPathWithinRootIsSafe($torrentPath, $sessionDir)) {
        return false;
    }

    if (!pmssReplaceUserFilePreservingMetadata($torrentPath, $payload, 0640)) {
        return false;
    }

    return strlen($payload);
}

function pmssTrackerCleanerAppendUserChangeLog(string $username, array $changes): string
{
    if ($changes === []) return '';
    $log = pmssTrackerCleanerChangeLog($changes);
    $userLogPath = "/home/{$username}/.trackerCleaner.log";
    if (file_exists($userLogPath) && is_link($userLogPath)) {
        pmssTrackerCleanerLog("SKIP: refusing to write log; path is symlink for user {$username} ({$userLogPath}).");
        return $log;
    }

    file_put_contents($userLogPath, $log, FILE_APPEND);
    if (file_exists($userLogPath) && !is_link($userLogPath) && pmssPathWithinRootIsSafe($userLogPath, "/home/{$username}")) {
        pmssUserFileApplyOwnership($userLogPath, $username);
    }
    return $log;
}

/**
 * Remove blocked trackers from the announce-list tiers while preserving order.
 *
 * @return array{changed:bool,announce_list:array<int,array<int,string>>,removed_trackers:array<int,string>,remaining_trackers:array<int,string>}
 */
function pmssTrackerCleanerPruneAnnounceList(array $announceList, array $blockRules): array
{
    $newList = [];
    $removed = [];
    $remaining = [];
    $changed = false;

    foreach ($announceList as $tier) {
        if (!is_array($tier)) continue;
        $tierNew = [];
        foreach ($tier as $trackerUrl) {
            if (!is_string($trackerUrl)) continue;
            if (pmssTrackerCleanerShouldScrubTracker($trackerUrl, $blockRules)) {
                $removed[$trackerUrl] = true;
                $changed = true;
                continue;
            }
            $tierNew[] = $trackerUrl;
            $remaining[$trackerUrl] = true;
        }
        if ($tierNew !== []) $newList[] = $tierNew;
    }

    return ['changed' => $changed, 'announce_list' => $newList, 'removed_trackers' => array_keys($removed), 'remaining_trackers' => array_keys($remaining)];
}

function pmssTrackerCleanerFirstAnnounceReplacement(array $announceList): ?string { foreach ($announceList as $tier) { if (isset($tier[0]) && is_string($tier[0]) && $tier[0] !== '') return $tier[0]; } return null; }

/**
 * Apply tracker cleanup to a torrent-like object and return the intended result.
 *
 * @return array{changed:bool,would_trackerless:bool,removed_trackers:array<int,string>,remaining_trackers:array<int,string>,events:array<int,string>}
 */
function pmssTrackerCleanerScrubTorrent($torrent, array $blockRules): array
{
    $list = pmssTrackerCleanerPruneAnnounceList($torrent->getAnnounceList(), $blockRules);
    if ($list['changed']) $torrent->setAnnounceList($list['announce_list']);
    $changed = $list['changed'];
    $removed = array_fill_keys($list['removed_trackers'], true);
    $remaining = array_fill_keys($list['remaining_trackers'], true);
    $events = [];
    $announce = $torrent->getAnnounce();
    if (is_string($announce) && $announce !== '' && pmssTrackerCleanerShouldScrubTracker($announce, $blockRules)) {
        $removed[$announce] = true;
        $replacement = pmssTrackerCleanerFirstAnnounceReplacement($list['announce_list']);
        if ($replacement !== null) {
            $torrent->setAnnounce($replacement);
            $changed = true;
            $remaining[$replacement] = true;
            $events[] = 'announce_replaced from='.$announce.' to='.$replacement;
        } else {
            $events[] = 'announce_scrubbed_no_replacement announce='.$announce;
        }
    } elseif (is_string($announce) && $announce !== '') {
        $remaining[$announce] = true;
    }
    return ['changed' => $changed, 'would_trackerless' => $changed && count($remaining) === 0, 'removed_trackers' => array_keys($removed), 'remaining_trackers' => array_keys($remaining), 'events' => $events];
}

function pmssTrackerCleanerCommentWithMarker(string $comment): string
{
    $marker = 'Trackers cleaned by PMSS tracker cleaner';
    if (strpos($comment, $marker) !== false) return $comment;
    return $comment.'; '.$marker.' (https://github.com/MagnaCapax/PMSS/blob/main/docs/tracker-cleaner.md)';
}
