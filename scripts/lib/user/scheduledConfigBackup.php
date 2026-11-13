<?php
/**
 * Root-side orchestration for opt-in scheduled customer config backups.
 *
 * The archive writer lives in the customer tree and is executed as that UID.
 * Root only validates the opt-in state, verifies the returned archive path,
 * logs outcomes, and starts retention after a verified new backup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logger.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/scheduledConfigBackupCommand.php';
require_once __DIR__.'/scheduledConfigBackupAccount.php';

/** Isolate account failures and publish the queue summary after all outcomes are counted. */
function pmssScheduledConfigBackupRun(UserConfigStore $store, array $options = []): array
{
    $logger = is_callable($options['logger'] ?? null) ? $options['logger'] : null;
    $userLogger = is_callable($options['userLogger'] ?? null) ? $options['userLogger'] : 'pmssUserLog';
    $summary = ['timestamp' => date('c'), 'event' => 'scheduled_config_backup', 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($store->loadAll() as $user => $payload) {
        $summary['processed']++;
        try {
            $result = pmssScheduledConfigBackupRunUser((string) $user, is_array($payload) ? $payload : [], $options);
        } catch (Throwable $exception) {
            $result = pmssScheduledConfigBackupOutcome('failed', 'exception='.get_class($exception));
            if (UserValidator::isValidUsername((string) $user)) $userLogger((string) $user, 'scheduled config backup: failed reason='.$result['message']);
        }

        $key = $result['status'] === 'success' ? 'succeeded' : ($result['status'] === 'failed' ? 'failed' : 'skipped');
        $summary[$key]++;
        if ($logger !== null && ($result['status'] !== 'skipped' || !empty($result['enabled']))) {
            $logger('event=scheduled_config_backup user='.pmssScheduledConfigBackupMessageSafe((string) $user).' status='.$result['status'].' message='.$result['message'].' bytes='.(int) ($result['bytes'] ?? 0).' kept='.(int) ($result['keptCount'] ?? 0));
        }
    }

    $summary['level'] = $summary['failed'] > 0 ? 'warn' : 'info';
    if ($logger !== null) {
        $logger('event=scheduled_config_backup_summary processed='.$summary['processed'].' succeeded='.$summary['succeeded'].' failed='.$summary['failed'].' skipped='.$summary['skipped']);
    }
    pmssJsonLineAppend(pmssLogDir().'/scheduledConfigBackup.jsonl', $summary);
    return $summary;
}

/** Retain the cron help, root guard, lock and failure exit status. */
function pmssScheduledConfigBackupMain(array $argv): int
{
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        echo "Usage: /scripts/cron/scheduledConfigBackup.php\n";
        echo "Runs opt-in scheduled config backups for users with scheduledConfigBackup=true.\n";
        return 0;
    }

    requireRoot();
    $logger = new Logger('/scripts/cron/scheduledConfigBackup.php');
    $lock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-scheduledConfigBackup.lock'), true);
    if ($lock === false) {
        $logger->msg('scheduledConfigBackup already running; skipping');
        return 0;
    }

    $summary = pmssScheduledConfigBackupRun(new UserConfigStore(), ['logger' => [$logger, 'msg']]);
    return $summary['failed'] > 0 ? 1 : 0;
}
