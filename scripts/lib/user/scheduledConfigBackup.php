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

const PMSS_SCHEDULED_CONFIG_BACKUP_KEY = 'scheduledConfigBackup';
const PMSS_SCHEDULED_CONFIG_BACKUP_RETENTION_DEFAULT = 7;

function pmssScheduledConfigBackupMessageSafe(string $message): string
{
    $message = trim((string) preg_replace('/[\r\n\0\t ]+/', ' ', $message));
    return strlen($message) > 300 ? substr($message, 0, 300).'...' : $message;
}

function pmssScheduledConfigBackupOutcome(string $status, string $message, array $extra = []): array
{
    return array_merge(['status' => $status, 'message' => pmssScheduledConfigBackupMessageSafe($message)], $extra);
}

function pmssScheduledConfigBackupCustomerPhpCode(): string
{
    return <<<'PHP'
require_once $argv[1];
$home = isset($argv[2]) ? $argv[2] : '';
$action = isset($argv[3]) ? $argv[3] : '';
$keep = isset($argv[4]) ? (int) $argv[4] : 7;
$result = array('ok' => false, 'message' => 'Unknown scheduled backup action.', 'bytes' => 0, 'path' => '');
if ($action === 'create') {
    $result = pmssCustomerBackupFileCreate($home);
} elseif ($action === 'prune') {
    $result = pmssCustomerBackupRetentionPrune($home, $keep);
}
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
echo (is_string($encoded) ? $encoded : '{"ok":false,"message":"Could not encode scheduled backup result.","bytes":0,"path":""}').PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
PHP;
}

function pmssScheduledConfigBackupPhpBinary(): string
{
    if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' && is_executable(PHP_BINARY)) return PHP_BINARY;
    return is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
}

function pmssScheduledConfigBackupCustomerIncludePath(string $home): string
{
    $path = rtrim($home, '/').'/www/scriptsInc.php';
    return is_file($path) && !is_link($path) && pmssPathWithinResolvedRoot($path, $home) ? $path : '';
}

function pmssScheduledConfigBackupCommandBuild(string $user, string $home, string $action, int $retention): string
{
    if (!UserValidator::isValidUsername($user)) return '';
    $include = pmssScheduledConfigBackupCustomerIncludePath($home);
    if ($include === '' || !in_array($action, ['create', 'prune'], true)) return '';

    $inner = pmssCommandArgvShellQuote([
        pmssScheduledConfigBackupPhpBinary(),
        '-r',
        pmssScheduledConfigBackupCustomerPhpCode(),
        '--',
        $include,
        $home,
        $action,
        (string) $retention,
    ]);
    return pmssBuildUserShellCommand($user, $inner, '/bin/bash');
}

function pmssScheduledConfigBackupCommandRun(string $command): array
{
    return pmssCommandPipedCapture(
        pmssCommandBashInvocation($command),
        $command,
        pmssCommandTimeoutSeconds($command),
        1048576,
        false,
        'scheduled config backup command launch failed',
        1,
        true
    );
}

function pmssScheduledConfigBackupCustomerResult(array $process): array
{
    $rc = isset($process['rc']) && is_numeric($process['rc']) ? (int) $process['rc'] : 1;
    $stdout = trim((string) ($process['stdout'] ?? ''));
    $jsonLine = '';
    foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
        if (trim($line) !== '') $jsonLine = trim($line);
    }

    $payload = $jsonLine !== '' ? pmssJsonDecodeAssoc($jsonLine) : null;
    if (!is_array($payload)) {
        $stderr = pmssScheduledConfigBackupMessageSafe((string) ($process['stderr'] ?? ''));
        return ['ok' => false, 'message' => 'scheduled backup helper returned invalid output'.($stderr !== '' ? ': '.$stderr : ''), 'bytes' => 0, 'path' => ''];
    }
    if ($rc !== 0) $payload['ok'] = false;

    return [
        'ok' => !empty($payload['ok']),
        'message' => pmssScheduledConfigBackupMessageSafe((string) ($payload['message'] ?? '')),
        'bytes' => max(0, (int) ($payload['bytes'] ?? 0)),
        'path' => (string) ($payload['path'] ?? ''),
        'keptCount' => max(0, (int) ($payload['keptCount'] ?? 0)),
        'deletedCount' => max(0, (int) ($payload['deletedCount'] ?? 0)),
    ];
}

function pmssScheduledConfigBackupCustomerAction(string $user, string $home, string $action, int $retention, ?callable $runner = null): array
{
    $command = pmssScheduledConfigBackupCommandBuild($user, $home, $action, $retention);
    if ($command === '') return ['ok' => false, 'message' => 'scheduled backup helper command could not be built', 'bytes' => 0, 'path' => ''];
    $process = $runner === null
        ? pmssScheduledConfigBackupCommandRun($command)
        : $runner($command, ['user' => $user, 'home' => $home, 'action' => $action, 'retention' => $retention]);
    return pmssScheduledConfigBackupCustomerResult(is_array($process) ? $process : []);
}

function pmssScheduledConfigBackupArchiveVerified(string $home, string $path): int
{
    if ($path === '' || $path[0] !== '/' || !is_file($path) || is_link($path) || !pmssPathWithinResolvedRoot($path, $home)) return 0;
    if (preg_match('#/\.pmss-backups/config-[0-9]{8}-[0-9]{6}(?:-[0-9]{2})?\.tar\.gz\z#', $path) !== 1) return 0;
    $size = @filesize($path);
    return is_numeric($size) && (int) $size > 0 ? (int) $size : 0;
}

function pmssScheduledConfigBackupRunUser(string $user, array $payload, array $options = []): array
{
    $homeRoot = rtrim((string) ($options['homeRoot'] ?? pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')), '/');
    $runner = isset($options['runner']) && is_callable($options['runner']) ? $options['runner'] : null;
    $userLogger = isset($options['userLogger']) && is_callable($options['userLogger']) ? $options['userLogger'] : 'pmssUserLog';
    $retention = max(1, (int) ($options['retention'] ?? PMSS_SCHEDULED_CONFIG_BACKUP_RETENTION_DEFAULT));

    if (!pmssUserConfigNormaliseToggleValue($payload, PMSS_SCHEDULED_CONFIG_BACKUP_KEY, false)) return pmssScheduledConfigBackupOutcome('skipped', 'toggle off', ['enabled' => false]);
    if (!UserValidator::isValidUsername($user)) return pmssScheduledConfigBackupOutcome('skipped', 'invalid username', ['enabled' => true]);

    $home = $homeRoot.'/'.$user;
    if (!is_dir($home) || is_link($home)) {
        $userLogger($user, 'scheduled config backup: skipped reason=home_missing');
        return pmssScheduledConfigBackupOutcome('skipped', 'home missing', ['enabled' => true]);
    }

    $created = pmssScheduledConfigBackupCustomerAction($user, $home, 'create', $retention, $runner);
    if (!empty($created['ok']) && $created['path'] === '' && $created['message'] === 'nothing to back up') {
        $userLogger($user, 'scheduled config backup: skipped reason=nothing_to_back_up');
        return pmssScheduledConfigBackupOutcome('skipped', 'nothing to back up', ['enabled' => true, 'bytes' => 0]);
    }
    if (empty($created['ok']) || $created['path'] === '') {
        $message = $created['message'] !== '' ? $created['message'] : 'backup failed';
        $userLogger($user, 'scheduled config backup: failed reason='.$message);
        return pmssScheduledConfigBackupOutcome('failed', $message, ['bytes' => (int) $created['bytes']]);
    }

    $bytes = pmssScheduledConfigBackupArchiveVerified($home, $created['path']);
    if ($bytes <= 0) {
        $userLogger($user, 'scheduled config backup: failed reason=archive_verification_failed');
        return pmssScheduledConfigBackupOutcome('failed', 'archive verification failed');
    }

    $pruned = pmssScheduledConfigBackupCustomerAction($user, $home, 'prune', $retention, $runner);
    if (empty($pruned['ok'])) {
        $message = $pruned['message'] !== '' ? $pruned['message'] : 'retention failed';
        $userLogger($user, 'scheduled config backup: failed reason='.$message.' bytes='.$bytes);
        return pmssScheduledConfigBackupOutcome('failed', $message, ['bytes' => $bytes, 'path' => $created['path']]);
    }

    $userLogger($user, 'scheduled config backup: success bytes='.$bytes.' kept='.$pruned['keptCount'].' path='.basename(dirname($created['path'])).'/'.basename($created['path']));
    return pmssScheduledConfigBackupOutcome('success', 'created config backup', ['bytes' => $bytes, 'path' => $created['path'], 'keptCount' => (int) $pruned['keptCount']]);
}

function pmssScheduledConfigBackupRun(UserConfigStore $store, array $options = []): array
{
    $logger = isset($options['logger']) && is_callable($options['logger']) ? $options['logger'] : null;
    $userLogger = isset($options['userLogger']) && is_callable($options['userLogger']) ? $options['userLogger'] : 'pmssUserLog';
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
