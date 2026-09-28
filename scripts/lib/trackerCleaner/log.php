<?php
/** Tracker-cleaner operator and user log output. @license GPL-3.0-only */

function pmssTrackerCleanerTimestamp(): string { return '['.date('Y-m-d H:i:s').']'; }

function pmssTrackerCleanerLog(string $message): void { echo pmssTrackerCleanerTimestamp().' '.$message."\n"; }
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
/** Resolve the per-user tracker-cleaner change log path after boundary checks. */
function pmssTrackerCleanerUserChangeLogPath(string $username, string $homeRoot = '/home'): ?string
{
    if (!pmssValidateUsername($username)) {
        return null;
    }

    $homeRoot = rtrim($homeRoot, '/');
    if ($homeRoot === '' || !pmssPathAbsoluteStringIsSafe($homeRoot, ['allowRoot' => false])) {
        return null;
    }

    return $homeRoot.'/'.$username.'/.trackerCleaner.log';
}

function pmssTrackerCleanerAppendUserChangeLog(string $username, array $changes, string $homeRoot = '/home'): string
{
    if ($changes === []) return '';
    $log = pmssTrackerCleanerChangeLog($changes);
    $userLogPath = pmssTrackerCleanerUserChangeLogPath($username, $homeRoot);
    if ($userLogPath === null) {
        pmssTrackerCleanerLog('ERR: Refusing to write tracker change log for invalid user/home root.');
        return $log;
    }
    if (file_exists($userLogPath) && is_link($userLogPath)) {
        pmssTrackerCleanerLog("SKIP: refusing to write log; path is symlink for user {$username} ({$userLogPath}).");
        return $log;
    }
    if (!pmssUserFilePathIsSafe($userLogPath)) {
        pmssTrackerCleanerLog("WARN: User change log path is unsafe or missing for {$username} ({$userLogPath}); skipping write.");
        return $log;
    }

    $written = @file_put_contents($userLogPath, $log, FILE_APPEND | LOCK_EX);
    if ($written === false || $written !== strlen($log)) {
        pmssTrackerCleanerLog("WARN: Failed to append tracker change log for user {$username} ({$userLogPath}).");
        return $log;
    }
    $userHome = dirname($userLogPath);
    if (file_exists($userLogPath) && !is_link($userLogPath) && pmssPathWithinRootIsSafe($userLogPath, $userHome)) {
        pmssUserFileApplyOwnership($userLogPath, $username);
    }
    return $log;
}
