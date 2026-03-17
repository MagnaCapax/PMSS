<?php
/**
 * PMSS config backup helpers (critical service configs).
 *
 * Purpose:
 * - Create pre-change backups for critical configs (sshd/nginx/proftpd).
 * - Retain a bounded set (TTL + max count) to avoid disk growth.
 *
 * Design notes:
 * - Best-effort only: backup/prune must never be fatal to the caller.
 * - Backups live under /var/backups/pmss/config/<service>/ by default.
 * - Naming includes timestamp + source path key + optional PMSS version/correlation id.
 *
 * @license GPL-3.0-only
 */

/**
 * Create a best-effort backup of a file and prune older backups.
 *
 * @param string $service E.g. "sshd", "nginx", "proftpd".
 * @param string $sourcePath Absolute path to the file being mutated.
 * @param array<string,mixed> $options Optional overrides:
 *   - backupRoot: string base directory (default: /var/backups/pmss/config)
 *   - timestamp: string 14-digit YmdHis (default: date('YmdHis'))
 *   - pmssVersion: string|null version string (default: auto-detect when possible)
 *   - correlationId: string|null correlation id (default: getenv('PMSS_CORRELATION_ID'))
 *   - maxCount: int maximum backups to keep for this key (default: 10)
 *   - ttlSeconds: int maximum age to retain (default: 90 days)
 *   - nowTs: int epoch seconds override for prune tests (default: time())
 *   - logger: callable(string):void|null logger override
 *
 * @return string|null The backup path on success, null otherwise.
 */
function pmssBackupCriticalConfig(string $service, string $sourcePath, array $options = array()): ?string
{
    $log = $options['logger'] ?? (function_exists('logMessage')
        ? 'logMessage'
        : function (string $message): void {
            if (defined('STDERR')) {
                @fwrite(STDERR, $message.PHP_EOL);
                return;
            }

            error_log($message);
        });
    $sourcePath = trim($sourcePath);
    if (
        $service === ''
        || $sourcePath === ''
        || getenv('PMSS_DRY_RUN') === '1'
        || !is_file($sourcePath)
        || !is_readable($sourcePath)
    ) {
        return null;
    }

    $backupRoot = rtrim((string) ($options['backupRoot'] ?? '/var/backups/pmss/config'), '/') ?: '/var/backups/pmss/config';

    $timestamp = isset($options['timestamp']) ? (string) $options['timestamp'] : '';
    $timestamp = preg_match('/^[0-9]{14}$/', $timestamp) ? $timestamp : date('YmdHis');

    $key = pmssConfigBackupsPathKey($sourcePath);
    if (array_key_exists('pmssVersion', $options)) {
        $pmssVersion = (string) $options['pmssVersion'];
    } elseif (function_exists('getPmssVersion')) {
        $pmssVersion = (string) getPmssVersion();
    } else {
        $pmssVersion = 'unknown';
        foreach (array('/etc/seedbox/config/version', '/etc/seedbox/runtime/version') as $path) {
            $trimmed = trim((string) @file_get_contents($path));
            if ($trimmed !== '') {
                $pmssVersion = $trimmed;
                break;
            }
        }
    }
    $correlationId = array_key_exists('correlationId', $options) ? (string) $options['correlationId'] : (string) (getenv('PMSS_CORRELATION_ID') ?: '');

    $serviceDir = $backupRoot.'/'.$service;
    if (!is_dir($serviceDir)) {
        @mkdir($serviceDir, 0700, true);
    }
    @chmod($serviceDir, 0700);

    if (!is_dir($serviceDir)) {
        $log('[WARN] Unable to create config backup directory: '.$serviceDir);
        return null;
    }

    $name = $timestamp.'__'.$key;
    $versionLabel = pmssConfigBackupsSanitizeLabel($pmssVersion);
    if ($versionLabel !== '' && $versionLabel !== 'unknown') { $name .= '__v='.$versionLabel; }
    $cidLabel = pmssConfigBackupsSanitizeLabel($correlationId);
    if ($cidLabel !== '') { $name .= '__cid='.$cidLabel; }
    $backupPath = $serviceDir.'/'.$name.'.bak';

    if (!@copy($sourcePath, $backupPath)) {
        $log('[WARN] Failed to create config backup for '.$sourcePath.' at '.$backupPath);
        return null;
    }
    @chmod($backupPath, 0600);
    if (isset($options['logSuccess']) ? (bool) $options['logSuccess'] : function_exists('logMessage')) {
        $log('Backup written: '.$backupPath);
    }

    pmssPruneCriticalConfigBackups($service, $sourcePath, $options);
    return $backupPath;
}

/**
 * Prune config backups for a given source path (best-effort).
 *
 * @param string $service E.g. "sshd", "nginx", "proftpd".
 * @param string $sourcePath Absolute path to the file being mutated.
 * @param array<string,mixed> $options See pmssBackupCriticalConfig() for supported keys.
 */
function pmssPruneCriticalConfigBackups(string $service, string $sourcePath, array $options = array()): void
{
    $log = $options['logger'] ?? (function_exists('logMessage')
        ? 'logMessage'
        : function (string $message): void {
            if (defined('STDERR')) {
                @fwrite(STDERR, $message.PHP_EOL);
                return;
            }

            error_log($message);
        });
    if ($service === '' || ($sourcePath = trim($sourcePath)) === '' || getenv('PMSS_DRY_RUN') === '1') {
        return;
    }

    $backupRoot = rtrim((string) ($options['backupRoot'] ?? '/var/backups/pmss/config'), '/') ?: '/var/backups/pmss/config';

    $serviceDir = $backupRoot.'/'.$service;
    if (!is_dir($serviceDir)) {
        return;
    }

    $key = pmssConfigBackupsPathKey($sourcePath);
    $files = glob($serviceDir.'/*__'.$key.'*.bak');
    if ($files === false || $files === array()) {
        return;
    }

    $maxCount = isset($options['maxCount']) ? (int) $options['maxCount'] : 10;
    $ttlSeconds = isset($options['ttlSeconds']) ? (int) $options['ttlSeconds'] : (90 * 86400);
    $nowTs = isset($options['nowTs']) ? (int) $options['nowTs'] : time();

    // Sort by filename (timestamp prefix) descending so we keep the newest ones.
    rsort($files, SORT_STRING);

    $kept = $maxCount > 0 ? array_slice($files, 0, $maxCount) : $files;
    $keptMap = array_fill_keys($kept, true);

    $cutoff = $ttlSeconds > 0 ? ($nowTs - $ttlSeconds) : null;
    foreach ($files as $file) {
        $remove = !isset($keptMap[$file]);

        if (
            $cutoff !== null
            && preg_match('/^([0-9]{14})__/', basename($file), $matches)
            && ($dateTime = \DateTime::createFromFormat('YmdHis', $matches[1])) !== false
            && $dateTime->getTimestamp() < $cutoff
        ) {
            $remove = true;
        }

        if ($remove) {
            if (!@unlink($file)) {
                $log('[WARN] Unable to prune config backup: '.$file);
            }
        }
    }
}

/**
 * Convert a filesystem path into a stable, safe filename key.
 */
function pmssConfigBackupsPathKey(string $path): string
{
    $path = preg_replace('/\\s+/', ' ', trim($path));
    $path = ltrim(str_replace('/', '_', preg_replace('/[^A-Za-z0-9._\\/\\-]/', '_', $path)), '_');
    return $path !== '' ? $path : 'unknown_path';
}

/**
 * Sanitize a label for embedding in backup file names.
 */
function pmssConfigBackupsSanitizeLabel(string $label, int $maxLen = 80): string
{
    $label = trim(
        preg_replace('/[^A-Za-z0-9._\\-]+/', '_', preg_replace('/\\s+/', ' ', trim($label))),
        '_'
    );
    if ($label === '') {
        return '';
    }
    return strlen($label) > $maxLen ? substr($label, 0, $maxLen) : $label;
}
