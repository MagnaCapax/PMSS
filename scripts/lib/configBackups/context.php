<?php
/** Critical-config backup target preparation and filename labels. @license GPL-3.0-only */

require_once dirname(__DIR__).'/runtime.php';

$GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER'] = $GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER']
    ?? function (string $message): void {
        if (defined('STDERR')) {
            @fwrite(STDERR, $message.PHP_EOL);
            return;
        }
        error_log($message);
    };

/** @param array<string,mixed> $options @return array<string,mixed>|null */
function pmssConfigBackupsPrepareContext(string $service, string $sourcePath, array $options, bool $requireReadableSource, string $invalidServiceMessage): ?array
{
    $log = $options['logger'] ?? (function_exists('logMessage')
        ? 'logMessage'
        : $GLOBALS['PMSS_CONFIG_BACKUPS_FALLBACK_LOGGER']);
    // Reject malformed paths before trim() can select a different backup/prune target.
    if (pmssFilesystemPathHasNulByte($sourcePath)) {
        $log('[WARN] Refusing config backup for unsafe source path');
        return null;
    }
    $service = pmssConfigBackupsNormalizeService($service);
    $sourcePath = trim($sourcePath);
    if ($service === '' || $sourcePath === '' || pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        if ($service === '') {
            $log($invalidServiceMessage);
        }
        return null;
    }
    if (strpos($sourcePath, '/') !== 0) {
        $log('[WARN] Refusing config backup for non-absolute source path: '.$sourcePath);
        return null;
    }
    if (is_link($sourcePath)) {
        $log('[WARN] Refusing config backup for unsafe source path: '.$sourcePath);
        return null;
    }
    if ($requireReadableSource && (!is_file($sourcePath) || !is_readable($sourcePath))) {
        return null;
    }
    $backupRoot = rtrim((string) ($options['backupRoot'] ?? '/var/backups/pmss/config'), '/') ?: '/var/backups/pmss/config';
    if (strpos($backupRoot, '/') !== 0) {
        $log('[WARN] Refusing config backup with non-absolute backup root: '.$backupRoot);
        return null;
    }
    $serviceDir = $backupRoot.'/'.$service;
    foreach (['backup root' => $backupRoot, 'service directory' => $serviceDir] as $label => $directory) {
        if (strpos($directory, "\0") !== false || is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            $log('[WARN] Refusing config backup with unsafe '.$label.': '.$directory);
            return null;
        }
    }
    return array(
        'key' => pmssConfigBackupsPathKey($sourcePath),
        'log' => $log,
        'service' => $service,
        'serviceDir' => $serviceDir,
        'sourcePath' => $sourcePath,
    );
}

/**
 * Convert a filesystem path into a stable, safe filename key.
 */
function pmssConfigBackupsPathKey(string $path): string
{
    $path = preg_replace('/\\s+/', ' ', trim($path));
    if (preg_match('#/(etc|home|opt|srv|usr|var)(?:/|$)#', $path, $matches, PREG_OFFSET_CAPTURE) === 1) {
        $path = substr($path, $matches[0][1]);
    }
    return ($path = ltrim(str_replace('/', '_', preg_replace('/[^A-Za-z0-9._\\/\\-]/', '_', $path)), '_')) !== '' ? $path : 'unknown_path';
}

/**
 * Normalize a service key used as the backup directory name.
 * Reject dot-directory components before backup or prune can select an ancestor.
 */
function pmssConfigBackupsNormalizeService(string $service): string
{
    // NUL is not whitespace, even when it appears at either end of the key.
    if (pmssFilesystemPathHasNulByte($service)) {
        return '';
    }
    $service = trim($service);
    if ($service === '.' || $service === '..') {
        return '';
    }
    return preg_match('/^[A-Za-z0-9._-]+$/', $service) === 1 ? $service : '';
}

/**
 * Sanitize a label for embedding in backup file names.
 */
function pmssConfigBackupsSanitizeLabel(string $label, int $maxLen = 80): string
{
    $label = trim(preg_replace('/[^A-Za-z0-9._\\-]+/', '_', preg_replace('/\\s+/', ' ', trim($label))), '_');
    return $label === '' ? '' : substr($label, 0, $maxLen);
}
