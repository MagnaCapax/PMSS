<?php
/** Tracker-cleaner operator and user log output. @license GPL-3.0-only */

require_once __DIR__.'/../user/watchdogTimeout.php';

function pmssTrackerCleanerTimestamp(): string { return '['.date('Y-m-d H:i:s').']'; }

function pmssTrackerCleanerLog(string $message): void { echo pmssLogFormatTimestampedLine($message, '[Y-m-d H:i:s]', ' '); }
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
    // Reject malformed account names before forming a home path or user command.
    if (!pmssValidateUsername($username) || pmssUsernameIsReserved($username)) {
        pmssTrackerCleanerLog('WARN: Refusing verbose log for invalid user.');
        return;
    }
    $userHome = "/home/{$username}";
    $userLogsDir = $userHome.'/.logs';
    $userLogFile = $userLogsDir.'/trackerCleaner.log';
    pmssUserLifecycleStep('trackerCleaner', $username, 'ensure_user_logs_dir',
        pmssBuildUserShellCommand($username, 'mkdir -p ~/.logs', '/bin/bash'), false);
    if (!pmssTrackerCleanerAccountLogAppend($username, $userHome, $userLogFile, $payload)) {
        pmssTrackerCleanerLog("WARN: Unable to append verbose log for {$username} ({$userLogFile}).");
    }
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

    $userHome = dirname($userLogPath);
    if (!pmssTrackerCleanerAccountLogAppend($username, $userHome, $userLogPath, $log)) {
        pmssTrackerCleanerLog("WARN: Failed to append tracker change log for user {$username} ({$userLogPath}).");
        return $log;
    }
    return $log;
}

/** A timed-out append backs off both account logs without delaying later accounts. */
function pmssTrackerCleanerAccountLogAppend(string $username, string $home, string $path, string $content): bool
{
    if (pmssUserWatchdogBackoffActive($username, 'tracker-cleaner')) return false;
    $timedOut = false;
    $ok = pmssAppendAccountFile($username, $home, $path, $content, 0640,
        pmssUserWatchdogTimeoutSeconds(15), $timedOut);
    if ($timedOut) pmssUserWatchdogBackoffRecord($username, 'tracker-cleaner');
    return $ok;
}
