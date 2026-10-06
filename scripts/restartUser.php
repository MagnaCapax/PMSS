#!/usr/bin/env php
<?php
/** Root CLI: restart one account while preserving rebuild and restart locks. */
declare(strict_types=1);

require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/user/restart.php';
require_once __DIR__.'/lib/user/restartLog.php';

$startedAt = microtime(true);
$user = $argc === 2 ? pmssUsernameNormalizeIfValid((string) $argv[1]) : null;
$record = [
    'ts' => date('c'), 'user' => $user ?? '', 'outcome' => 'refused',
    'signalled_term' => 0, 'signalled_kill' => 0,
    'started' => [], 'failed' => [], 'duration_ms' => 0,
];
$message = '';
$exitCode = 1;
$locks = null;

try {
    [$message, $exitCode] = pmssRestartUserRun($argc, $user, $record, $locks);
} catch (Throwable $error) {
    $message = 'restart failed unexpectedly';
    $record['outcome'] = 'failed';
    $record['failed'][] = 'internal';
} finally {
    $record['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    if ($message !== '' && $record['outcome'] !== 'success') $record['reason'] = $message;
    if (!pmssRestartUserLogWrite($record)) {
        if ($exitCode === 0) $message = 'restart audit log unavailable';
        $exitCode = 1;
    }
    if ($user !== null && function_exists('pmssUserLog')) {
        pmssUserLog($user, 'restartUser: '.$record['outcome'].($record['failed'] ? ' ('.implode(', ', $record['failed']).')' : ''));
    }
    if ($locks !== null) {
        fclose($locks['restart']);
        fclose($locks['recreate']);
        putenv(PMSS_UPDATE_LOCK_FDS_ENV);
    }
}

fwrite($exitCode === 0 ? STDOUT : STDERR, $message."\n");
exit($exitCode);
