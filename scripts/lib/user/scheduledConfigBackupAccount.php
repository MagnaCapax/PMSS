<?php
/** Scheduled backup account policy and archive verification. @license GPL-3.0-only */

const PMSS_SCHEDULED_CONFIG_BACKUP_KEY = 'scheduledConfigBackup';
const PMSS_SCHEDULED_CONFIG_BACKUP_RETENTION_DEFAULT = 7;

/** Only a readable, named, nonempty archive inside this home can authorize retention. */
function pmssScheduledConfigBackupArchiveVerified(string $home, string $path): int
{
    if ($path === '' || $path[0] !== '/' || !pmssRegularFilePathIsReadable($path) || !pmssPathWithinResolvedRoot($path, $home)) return 0;
    if (preg_match('#/\.pmss-backups/config-[0-9]{8}-[0-9]{6}(?:-[0-9]{2})?\.tar\.gz\z#', $path) !== 1) return 0;
    $size = @filesize($path);
    return is_numeric($size) && (int) $size > 0 ? (int) $size : 0;
}

/** Run create, verify and prune in order; an earlier refusal never starts retention. */
function pmssScheduledConfigBackupRunUser(string $user, array $payload, array $options = []): array
{
    $homeRoot = rtrim((string) ($options['homeRoot'] ?? pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')), '/');
    $runner = is_callable($options['runner'] ?? null) ? $options['runner'] : null;
    $userLogger = is_callable($options['userLogger'] ?? null) ? $options['userLogger'] : 'pmssUserLog';
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
